<?php

declare(strict_types=1);

namespace App\Actions\Summarising;

use App\Enums\SummaryError;
use App\Models\Summary;
use App\Services\YouTube\Actions\FetchTranscript;
use App\Services\YouTube\Enums\TranscriptPresence;
use Carbon\CarbonImmutable;

/**
 * Step two: the words to summarise.
 *
 * The second and last refusal that costs nothing. A video with no captions has nothing to
 * summarise however capable the model is, and the difference between that and not having been
 * able to fetch them is the difference between a message that invites another attempt and one
 * that does not.
 *
 * A row that already holds a transcript keeps it and does no work at all. That row is one whose
 * last attempt got past here and then failed further down, and the retry that produced this step
 * left the transcript alone precisely so it could be picked up again: no second subprocess, no
 * second request to YouTube, and the new attempt reads exactly the words the failed one did
 * rather than whatever the captions say today.
 *
 * Both columns or neither. They are written in one statement below, so a row holding one without
 * the other is not something that happens; the check is what makes the language safe to hand on
 * as a string rather than something to re-derive.
 */
class FetchCaptions extends SummarisingStep
{
    public function __construct(private readonly FetchTranscript $fetchTranscript)
    {
        parent::__construct();
    }

    public function execute(int $summaryId, CarbonImmutable $claimedAt): void
    {
        $summary = $this->claimed($summaryId, $claimedAt);

        if (! $summary instanceof Summary) {
            return;
        }

        if ($summary->transcript !== null && $summary->transcript_language !== null) {
            return;
        }

        $transcript = $this->fetchTranscript->execute($summary->video_id);

        $error = match ($transcript->presence) {
            TranscriptPresence::Missing => SummaryError::NoTranscript,
            TranscriptPresence::Unavailable => SummaryError::Unavailable,
            TranscriptPresence::Found => null,
        };

        if ($error instanceof SummaryError) {
            $this->giveUp($summary, $error);

            return;
        }

        $this->write($summaryId, $claimedAt, [
            'transcript' => $transcript->text,
            'transcript_language' => $transcript->language,
        ]);
    }
}
