<?php

declare(strict_types=1);

namespace App\Services\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The third pass, and only for a video that was not in English.
 *
 * The finished summary is translated rather than the transcript, which is the cheaper order and
 * also the better one. Translating an hour of speech to summarise it would put a translation
 * between the model and the words it is meant to be reading, and everything lost in it would be
 * lost before anybody decided what mattered. Summarising first means the judgements are made
 * against what was actually said, and only the result crosses languages.
 *
 * The same schema in and out, so the page lays both versions out with the same code. Structured
 * rather than free text for the same reason as CreateSummary, plus one of its own: a translation
 * prompt is unusually prone to answering with a note about the translation, and there is nowhere
 * in this schema to put one.
 *
 * See ExtractIdeas for why there is no provider or model attribute, and why the instructions live
 * in code rather than in lang/en.
 */
class TranslateSummary implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'TEXT'
            You are given a summary of a video as json, in a language that is not English.

            Translate it into English. Return the same three parts - headline, points, takeaways -
            with the same number of entries in the same order, each one translated.

            Translate only. Do not summarise further, do not merge or drop entries, do not add
            any, and do not improve on the original: if a line is clumsy, it is clumsy in English
            too.

            Keep names, quoted phrases, product names and technical terms as they are wherever
            translating them would lose the meaning. Where a term has a settled English
            equivalent, use it.

            Return nothing but the translation. No notes about the translation, no alternatives,
            and no mention of the original language.
            TEXT;
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * The same shape CreateSummary asks for, described in terms of what this pass does with it
     * rather than in terms of the video, so a model reading only the schema is not being told to
     * write a summary it has already been given.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()
                ->description('The headline, translated into English.')
                ->required(),

            'points' => $schema->array()
                ->items($schema->string())
                ->description('The points, translated into English, in the same order and the same number.')
                ->required(),

            'takeaways' => $schema->array()
                ->items($schema->string())
                ->description('The takeaways, translated into English, in the same order and the same number.')
                ->required(),
        ];
    }
}
