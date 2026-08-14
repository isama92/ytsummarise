<?php

declare(strict_types=1);

namespace App\Actions\Summarising;

use App\Models\Summary;
use App\Services\Ai\Agents\ExtractIdeas;
use UnexpectedValueException;

/**
 * Step three: what the video actually says.
 *
 * The first of the three model passes, and the first step that costs money. Two passes rather
 * than one because a transcript is an hour of speech with the shape taken out, and asking one
 * prompt to both find what matters and phrase it well summarises the opening five minutes.
 *
 * The ideas are written to the row rather than returned, which is the one thing splitting the
 * summariser into steps actually required: they used to be a local variable handed straight to
 * the next call. A column also buys the retry the same economy the transcript already had - a
 * row that holds ideas skips this pass, so an attempt that failed while writing the summary
 * costs one model call to repeat rather than two, and the second pass reads exactly the ideas
 * the first one wrote rather than whatever the model says about the transcript today.
 */
class DraftIdeas extends SummarisingStep
{
    public function execute(int $summaryId, string $claim): void
    {
        $summary = $this->claimed($summaryId, $claim);

        /*
         * Blank rather than null, which is not pedantry. An empty string here would satisfy a
         * null check for good: it would be stored, ComposeSummary would prompt a model with
         * nothing and produce a summary about nothing, and every retry from then on would skip
         * this pass and do it again. There is no way back from that without editing the row.
         */
        if (! $summary instanceof Summary || $summary->ideas !== null && $summary->ideas !== '') {
            return;
        }

        /*
         * Asserted rather than guarded. FetchCaptions either wrote both columns or gave up and
         * cancelled the batch, so a claimed row reaching here without a transcript is a broken
         * chain rather than an outcome, and a step that quietly prompted a model with an empty
         * string would pay for the answer.
         */
        assert($summary->transcript !== null);

        $ideas = trim((new ExtractIdeas)
            ->prompt($summary->transcript, timeout: config()->integer('summaries.model_timeout'))
            ->text);

        /*
         * Thrown rather than stored, and rather than returned as an outcome. A model that answers
         * with nothing is the feature being broken rather than something true about the video -
         * which is the same line App\Services\Ai\Data\SummarySections::parse draws when a
         * structured response comes back without a headline. Failing here marks the row, the page
         * offers to try again, and the retry actually re-runs this pass; storing the empty answer
         * would make it permanent instead.
         */
        if ($ideas === '') {
            throw new UnexpectedValueException('The model returned no ideas to summarise.');
        }

        $this->write($summaryId, $claim, ['ideas' => $ideas]);
    }
}
