<?php

declare(strict_types=1);

namespace App\Actions\Summarising;

use App\Models\Summary;
use App\Services\Ai\Agents\CreateSummary;
use App\Services\Ai\Data\SummaryOutline;
use App\Services\Ai\Data\SummarySections;
use Carbon\CarbonImmutable;
use Laravel\Ai\Responses\StructuredAgentResponse;

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
    public function execute(int $summaryId, CarbonImmutable $claimedAt): void
    {
        $summary = $this->claimed($summaryId, $claimedAt);

        if (! $summary instanceof Summary) {
            return;
        }

        /* Both written by the two steps before this one, or the batch was cancelled. */
        assert($summary->ideas !== null && $summary->transcript_language !== null);

        $response = (new CreateSummary)
            ->prompt($summary->ideas, timeout: config()->integer('summaries.model_timeout'));

        assert($response instanceof StructuredAgentResponse);

        $outline = new SummaryOutline(
            $summary->transcript_language,
            SummarySections::parse($response->toArray()),
        );

        $this->write($summaryId, $claimedAt, ['outline' => $outline->toArray()]);
    }
}
