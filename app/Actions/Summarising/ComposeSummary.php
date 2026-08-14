<?php

declare(strict_types=1);

namespace App\Actions\Summarising;

use App\Models\Summary;
use App\Services\Ai\Agents\CreateSummary;
use App\Services\Ai\Data\SummaryOutline;

/**
 * Step four: the summary itself.
 *
 * The second model pass, reading the ideas the third step wrote rather than the transcript, so
 * that this prompt is asked to phrase what matters rather than to find it as well.
 *
 * The outline is written with the original sections and no translation. TranslateOutline fills
 * the English half in when there is one to fill, and it is the step that flips the row to ready
 * - so a row can hold a complete outline and still be pending, which is the difference between
 * "the summary exists" and "the summary is finished".
 *
 * The status is deliberately not touched here for that reason. Flipping it now would put a
 * summary on screen in a language nobody asked for and then change it under them a model call
 * later.
 */
class ComposeSummary extends SummarisingStep
{
    public function execute(int $summaryId, string $claim): void
    {
        $summary = $this->claimed($summaryId, $claim);

        if (! $summary instanceof Summary) {
            return;
        }

        /*
         * Already composed, so this is a redelivery rather than a first run: a worker can write
         * the outline and then be killed before it deletes the job, and retry_after hands the same
         * job to the next worker. Nothing else would notice - only TranslateOutline moves the row
         * off pending - so without this the model is prompted a second time at full price. The two
         * steps before this one skip on the same reasoning.
         *
         * Safe against a genuine retry rather than a redelivery, because the controller clears the
         * outline when it resets a failed row: an outline on a pending row is always this
         * attempt's.
         */
        if ($summary->outline !== null) {
            return;
        }

        /* Both written by the two steps before this one, or the batch was cancelled. */
        assert($summary->ideas !== null && $summary->transcript_language !== null);

        $outline = new SummaryOutline(
            $summary->transcript_language,
            $this->sections(new CreateSummary, $summary->ideas),
        );

        $this->write($summaryId, $claim, ['outline' => $outline->toArray()]);
    }
}
