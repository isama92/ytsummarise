<?php

declare(strict_types=1);

namespace App\Actions\Summarising;

use App\Enums\SummaryStatus;
use App\Models\Summary;
use App\Services\Ai\Agents\TranslateSummary;
use App\Services\Ai\Data\SummaryOutline;
use App\Services\YouTube\Data\TranscriptResult;

/**
 * Step five: the English version, when there is one to make, and the end of the attempt either
 * way.
 *
 * Queued for every video, including the English ones it has no translating to do for, and that
 * is the point rather than an oversight. A step left out of the chain for some videos would make
 * the batch four jobs for one and five for another, so the progress on the dashboard would mean
 * a different thing per video and would jump from 3/4 to done. Queued always, it costs one
 * status write for an English video and the count is the same for everybody.
 *
 * It is also the step that finishes the attempt. Nothing before it flips the row to ready, so a
 * chain that stops early leaves a pending row for summaries:expire rather than a half-translated
 * summary somebody is reading.
 *
 * The finished summary is translated rather than the transcript, so the judgements are made
 * against what was actually said. Both versions are kept; the page shows the original above.
 */
class TranslateOutline extends SummarisingStep
{
    public function execute(int $summaryId, string $claim): void
    {
        $summary = $this->claimed($summaryId, $claim);

        if (! $summary instanceof Summary) {
            return;
        }

        /* ComposeSummary wrote this, or the batch was cancelled before reaching here. */
        assert(is_array($summary->outline));

        $outline = SummaryOutline::from($summary->outline);

        if (! TranscriptResult::isEnglishLanguage($outline->language)) {
            $outline = new SummaryOutline(
                $outline->language,
                $outline->original,
                $this->sections(new TranslateSummary, $outline->original->toJson()),
            );
        }

        /*
         * The outline goes back with the status even when nothing translated it, so this is one
         * statement rather than two paths. 'error' => null is the reason it is written by key
         * through the parent rather than through the model: a reason summaries:expire stamped on
         * the row while this attempt was legitimately working would otherwise survive into a
         * ready summary, because Eloquent leaves a column out of the statement when it believes
         * the value has not changed.
         */
        $this->write($summaryId, $claim, [
            'status' => SummaryStatus::Ready,
            'outline' => $outline->toArray(),
            'error' => null,
        ]);
    }
}
