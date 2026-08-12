<?php

declare(strict_types=1);

namespace App\Services\Ai\Actions;

use App\Services\Ai\Agents\CreateSummary;
use App\Services\Ai\Agents\ExtractIdeas;
use App\Services\Ai\Agents\TranslateSummary;
use App\Services\Ai\Data\SummaryOutline;
use App\Services\Ai\Data\SummarySections;
use App\Services\YouTube\Data\TranscriptResult;
use App\Services\YouTube\Enums\TranscriptPresence;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Turns a transcript into the summary the page shows, in two passes or three.
 *
 * Two for a video in English: find the ideas, then write the summary. Three for anything else,
 * the last one translating what was written. The order is deliberate and explained on
 * TranslateSummary.
 *
 * Everything here throws rather than returning a failure value, which is the opposite of the two
 * actions in app/Services/YouTube. The difference is what the caller can do about it. A video
 * that does not exist or has no captions is an ordinary outcome with its own sentence on the
 * page, so it comes back as a value; a model that cannot be reached or answers with something
 * unusable is not an outcome, it is the feature being broken, and the job's failure handler is
 * where that belongs - it logs the exception and records the one reason a person can act on.
 */
class SummariseTranscript
{
    /**
     * Summarise one transcript.
     *
     * Takes the result rather than a string and a language code so that what counts as English
     * is decided in one place, by the object that knows what language it is holding.
     *
     * Only ever called with a found transcript: the job maps the other two presences onto a
     * reason and returns before reaching here. Asserted rather than branched on, because a
     * branch for it would be code no test could reach through the job.
     */
    public function execute(TranscriptResult $transcript): SummaryOutline
    {
        assert($transcript->presence === TranscriptPresence::Found);
        assert($transcript->text !== null && $transcript->language !== null);

        /*
         * Read once and passed to every prompt. The SDK's own default is sixty seconds, which is
         * fine for a hosted model answering a short question and nowhere near enough for a local
         * one reading an hour of transcript. It cannot be declared with the #[Timeout] attribute
         * on the agents, which takes a literal and cannot read configuration.
         */
        $timeout = config()->integer('summaries.model_timeout');

        $ideas = (new ExtractIdeas)->prompt($transcript->text, timeout: $timeout)->text;

        $original = $this->sections(new CreateSummary, $ideas, $timeout);

        if ($transcript->isEnglish()) {
            return new SummaryOutline($transcript->language, $original);
        }

        /*
         * The summary as json, which is the shape the instructions say it arrives in and the
         * shape it has to come back in. Handing over the object rather than re-describing it in
         * prose also means the two passes cannot drift apart.
         */
        $english = $this->sections(new TranslateSummary, $original->toJson(), $timeout);

        return new SummaryOutline($transcript->language, $original, $english);
    }

    /**
     * One structured pass, as sections.
     *
     * The agents are constructed here rather than injected because they have nothing to be given:
     * no configuration, no collaborators, no state. Faking one in a test is done on the class
     * rather than through the container, so nothing is lost by not resolving them.
     *
     * prompt() is typed as returning the base response, since most agents are not structured, so
     * the assert is what tells static analysis what declaring HasStructuredOutput guarantees. The
     * same shape as the dto() assert in LookupVideo, and for the same reason: a branch here would
     * be unreachable rather than defensive.
     */
    private function sections(Agent&HasStructuredOutput $agent, string $prompt, int $timeout): SummarySections
    {
        $response = $agent->prompt($prompt, timeout: $timeout);

        assert($response instanceof StructuredAgentResponse);

        return SummarySections::parse($response->toArray());
    }
}
