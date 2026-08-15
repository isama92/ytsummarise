<?php

declare(strict_types=1);

namespace App\Actions\Summarising;

use App\Enums\SummaryError;
use App\Models\Summary;
use App\Services\YouTube\Actions\FetchCover;
use App\Services\YouTube\Actions\LookupVideo;
use App\Services\YouTube\Enums\VideoPresence;
use Illuminate\Support\Facades\Storage;

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
 *
 * The cover image is fetched here for the same reason the title is written here: it is a fact
 * about the video rather than about the summary, this is the step that has just established the
 * video exists, and neither of the two paid steps that follow should run for a video that turns
 * out not to be there.
 */
class FindVideo extends SummarisingStep
{
    public function __construct(
        private readonly LookupVideo $lookupVideo,
        private readonly FetchCover $fetchCover,
    ) {
        parent::__construct();
    }

    public function execute(int $summaryId, string $claim): void
    {
        $summary = $this->claimed($summaryId, $claim);

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
            $this->giveUp($summary, $claim, $error);

            return;
        }

        $this->write($summaryId, $claim, ['title' => $video->title]);

        /*
         * Best effort, deliberately, and the only thing in this chain that is. A cover is
         * decoration beside a summary somebody is waiting for, so a thumbnail that will not
         * download is not worth writing off an attempt over - and giving up here would cancel
         * the batch before the transcript had even been fetched, trading the whole answer for
         * a picture. FetchCover records its own failure and the page renders without one.
         *
         * Guarded on the file rather than on a column, because the file is what this produces.
         * .ai/rules/actions.md asks every step to return early when its work is already there,
         * for redelivery rather than for retries: a worker can finish a step and be killed
         * before it deletes the job, and retry_after then hands the same job to somebody else.
         *
         * Not conditional on the claim, unlike every database write in this class, and that is
         * not an oversight. There is nothing to race for: the path comes from the row's uuid,
         * which no attempt can change, so two attempts on one row write identical bytes to one
         * path. A superseded attempt leaving a cover behind has left the right cover on the
         * right row, which is why this sits outside write() rather than inside it.
         */
        if (! Storage::disk(FetchCover::DISK)->exists($summary->file_name)) {
            $this->fetchCover->execute($summary);
        }
    }
}
