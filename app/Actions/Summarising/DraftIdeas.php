<?php

declare(strict_types=1);

namespace App\Actions\Summarising;

use App\Models\Summary;
use App\Services\Ai\Agents\ExtractIdeas;
use Carbon\CarbonImmutable;

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
    public function execute(int $summaryId, CarbonImmutable $claimedAt): void
    {
        $summary = $this->claimed($summaryId, $claimedAt);

        if (! $summary instanceof Summary || $summary->ideas !== null) {
            return;
        }

        /*
         * Asserted rather than guarded. FetchCaptions either wrote both columns or gave up and
         * cancelled the batch, so a claimed row reaching here without a transcript is a broken
         * chain rather than an outcome, and a step that quietly prompted a model with an empty
         * string would pay for the answer.
         */
        assert($summary->transcript !== null);

        $ideas = (new ExtractIdeas)
            ->prompt($summary->transcript, timeout: config()->integer('summaries.model_timeout'))
            ->text;

        $this->write($summaryId, $claimedAt, ['ideas' => $ideas]);
    }
}
