<?php

declare(strict_types=1);

namespace App\Actions\Summarising;

use App\Enums\SummaryError;
use App\Models\Summary;
use App\Services\YouTube\Actions\LookupVideo;
use App\Services\YouTube\Enums\VideoPresence;
use Carbon\CarbonImmutable;

/**
 * Step one: what the video actually is.
 *
 * First because it is the cheapest way to find out that there is nothing to do. A video that
 * does not exist should not have a transcript fetched for it, and neither should have a model
 * asked about it, so the two refusals that cost nothing come before the three that cost money.
 *
 * The title is written here rather than carried to the end. As one job it was held in a local
 * variable until the final write, which kept the page from showing a heading over a skeleton;
 * across a chain there is nothing to hold it in, and a title on screen while the summary is
 * still being written is a better wait than a spinner. Null when the lookup found the video but
 * was not allowed to name it.
 */
class FindVideo extends SummarisingStep
{
    public function __construct(private readonly LookupVideo $lookupVideo)
    {
        parent::__construct();
    }

    public function execute(int $summaryId, CarbonImmutable $claimedAt): void
    {
        $summary = $this->claimed($summaryId, $claimedAt);

        if (! $summary instanceof Summary) {
            return;
        }

        $video = $this->lookupVideo->execute($summary->video_id);

        $error = match ($video->presence) {
            VideoPresence::Missing => SummaryError::NotFound,
            VideoPresence::Unknown => SummaryError::Unreachable,
            VideoPresence::Found => null,
        };

        /*
         * Written off here rather than by throwing, for two reasons. A video that does not exist
         * is an ordinary outcome and does not deserve a stack trace in the log, and giving up
         * cancels the batch, which is what stops a model being asked about a video nobody can
         * watch.
         */
        if ($error instanceof SummaryError) {
            $this->giveUp($summary, $error);

            return;
        }

        $this->write($summaryId, $claimedAt, ['title' => $video->title]);
    }
}
