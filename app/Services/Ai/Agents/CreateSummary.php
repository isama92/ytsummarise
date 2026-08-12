<?php

declare(strict_types=1);

namespace App\Services\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The second pass: the ideas from the first one, shaped into something worth reading.
 *
 * Given the extracted ideas rather than the transcript, so the sifting has already happened and
 * this prompt only has to choose and phrase. See ExtractIdeas for why that is two passes.
 *
 * Structured rather than prose, because the page lays the parts out rather than printing them.
 * That also removes the failure mode a text response has here: a model that decides to open with
 * "Here is a summary of the video:" has produced something with the wrong words in it, and there
 * is no way to strip that off afterwards that does not also strip a real first line one day.
 *
 * See ExtractIdeas for why there is no provider or model attribute, and why the instructions live
 * in code rather than in lang/en.
 */
class CreateSummary implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'TEXT'
            You are given a list of the ideas found in a video, one per line.

            Write a summary of that video with three parts:

            - headline: the whole video in one sentence of no more than twenty words.
            - points: the ten things the video actually covers, in the order it covers them,
              each one a line of no more than sixteen words.
            - takeaways: the five things worth remembering a week later, each one a line of no
              more than sixteen words. These are conclusions rather than a shorter list of the
              points above, so do not repeat them.

            Write in the same language as the ideas you were given. Do not translate anything.

            Every line is a plain statement. No numbering, no bullet characters, no headings, and
            nothing that refers to the video or the summary from outside - not "the speaker
            explains that", not "this video covers", just what is the case.

            Report only what the ideas say. Do not add anything from your own knowledge.
            TEXT;
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * The counts are asked for in the instructions rather than enforced here with min and max.
     * A model that returns nine points has written a usable summary and a schema violation, and
     * failing the whole video over that would be choosing the shape of the answer over the
     * answer. SummarySections drops whatever is unusable and keeps the rest.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()
                ->description('The whole video in one sentence of no more than twenty words.')
                ->required(),

            'points' => $schema->array()
                ->items($schema->string())
                ->description('The ten things the video covers, in order, each of no more than sixteen words.')
                ->required(),

            'takeaways' => $schema->array()
                ->items($schema->string())
                ->description('The five things worth remembering a week later, each of no more than sixteen words.')
                ->required(),
        ];
    }
}
