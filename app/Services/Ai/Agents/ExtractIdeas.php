<?php

declare(strict_types=1);

namespace App\Services\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * The first of the two passes: what was worth saying, before anything is shaped into a summary.
 *
 * Separating this from writing the summary is what the fabric pipeline this was modelled on does,
 * and the reason holds. A transcript is an hour of speech with the shape taken out of it - no
 * paragraphs, no headings, and every aside at the same volume as the argument. Asking one prompt
 * to both find what matters in that and phrase it well gets a summary of the opening five
 * minutes, because that is where the model was still reading carefully. Finding first and
 * phrasing second gives the second pass something already sifted and short enough to hold.
 *
 * No #[Provider] or #[Model] attribute here or on either of the other agents, deliberately: the
 * provider comes from ai.default and the model from that provider's own configuration, so moving
 * this application onto a different model is an .env change. Nor #[UseCheapestModel], which would
 * quietly pick a different model per provider and make two installations behave differently for
 * no reason anybody could see from here.
 *
 * The instructions are in code rather than in lang/en, unlike every string in this application
 * that a person reads. These are addressed to a model rather than to anybody: they are what this
 * class does, not what it says, and translating them would change the summary rather than the
 * language it is presented in. What a person reads of this pass is nothing at all - its output is
 * input to the next one.
 */
class ExtractIdeas implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'TEXT'
            You are given the transcript of a video. It is speech, so it rambles, repeats itself
            and has no punctuation you can trust.

            Extract the ideas that are actually in it: the claims, the arguments, the surprising
            details, the things somebody would remember afterwards. Aim for twenty to fifty of
            them. Cover the whole transcript rather than the beginning of it.

            Write each idea as a single terse line of about fifteen words. One idea per line. No
            numbering, no bullet characters, no headings, no preamble and no closing remarks.

            Write them in the same language as the transcript. Do not translate anything.

            Report only what the transcript says. Do not add facts from your own knowledge, and do
            not soften or correct claims you disagree with - if the speaker says it, it is an idea
            in this video.
            TEXT;
    }
}
