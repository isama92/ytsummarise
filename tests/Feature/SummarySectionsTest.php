<?php

declare(strict_types=1);

use App\Actions\Summarising\ComposeSummary;
use App\Actions\Summarising\DraftIdeas;
use App\Actions\Summarising\TranslateOutline;
use App\Models\Summary;
use App\Services\Ai\Agents\CreateSummary;
use App\Services\Ai\Agents\ExtractIdeas;
use App\Services\Ai\Agents\TranslateSummary;
use App\Services\Ai\Data\SummarySections;
use Laravel\Ai\Prompts\AgentPrompt;

/*
 * What a model's answer becomes by the time it is on the row.
 *
 * These moved here when App\Services\Ai\Actions\SummariseTranscript was deleted. Their subject was
 * never that class: it is SummarySections, which reads a model's json, and the agents, which are
 * pointed at whatever AI_PROVIDER names. So they are driven through a step that prompts for one
 * rather than through the orchestrator that no longer exists.
 *
 * The tests about the *order* of the passes went the other way, into
 * tests/Feature/Summarising/StepsTest.php, because order is now what the chain decides rather than
 * what one method does.
 */

/**
 * A claimed row with ideas on it, ready for the step that composes a summary from them.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Summary, 1: string}
 */
function composable(array $attributes = []): array
{
    $summary = Summary::factory()->pending()->create([
        'transcript' => 'We are no strangers to love.',
        'transcript_language' => 'en',
        'ideas' => 'What the video says.',
        ...$attributes,
    ]);

    return [$summary, claimSummary($summary->id)];
}

/*
 * The response is json somebody else wrote, and this application is pointed at whatever endpoint
 * AI_PROVIDER names - which may be a local model behind a gateway that honours a json schema
 * loosely. A summary missing nine of its ten points is thin; one missing its headline is not a
 * summary at all, and only the second is worth failing the video over.
 */
test('a summary with no headline is a failure rather than a thin summary', function (mixed $headline): void {
    CreateSummary::fake(fn (): array => [
        'headline' => $headline,
        'points' => ['A point'],
        'takeaways' => ['A takeaway'],
    ]);

    [$summary, $claim] = composable();

    expect(fn () => app(ComposeSummary::class)->execute($summary->id, $claim))
        ->toThrow(UnexpectedValueException::class)
        ->and($summary->fresh()?->outline)->toBeNull();
})->with([
    'absent' => [null],
    'empty' => [''],
    'only whitespace' => ['   '],
    'the wrong type' => [42],
]);

test('a summary missing some of its lines keeps the rest', function (): void {
    CreateSummary::fake(fn (): array => [
        'headline' => 'A headline',
        'points' => ['A point', '', null, '  Another point  ', 42],
        'takeaways' => 'not a list at all',
    ]);

    [$summary, $claim] = composable();

    app(ComposeSummary::class)->execute($summary->id, $claim);

    $original = $summary->fresh()?->outline['original'];

    expect($original['points'])->toBe(['A point', 'Another point'])
        ->and($original['takeaways'])->toBeEmpty();
});

/*
 * A list with holes in it encodes as a json object rather than as an array, which reaches the
 * page as something it cannot map over. Filtering has to renumber as well as remove.
 */
test('a filtered list is still a list once it has been stored', function (): void {
    CreateSummary::fake(fn (): array => [
        'headline' => 'A headline',
        'points' => ['', 'The only real point', ''],
        'takeaways' => [],
    ]);

    [$summary, $claim] = composable();

    app(ComposeSummary::class)->execute($summary->id, $claim);

    expect((string) json_encode($summary->fresh()?->outline))
        ->toContain('"points":["The only real point"]');
});

/*
 * The tolerance is for reading a model's answer, not for reading a row back.
 *
 * laravel-data registers every public static method beginning with "from" as a magic creation
 * method, so calling this one fromModel() made SummarySections::from() resolve to it - and
 * hydrating a stored outline would run through the filtering and throw on one whose headline
 * had gone missing, which is the opposite of what reading a row should do. Renaming it to
 * parse() is what keeps the two apart, and this is what notices if it is ever renamed back.
 */
test('hydrating a stored summary does not run the model tolerance', function (): void {
    $sections = SummarySections::from([
        'headline' => '',
        'points' => ['A point'],
        'takeaways' => [],
    ]);

    expect($sections->headline)->toBeEmpty()
        /* And the method that does apply it is still there, under a name laravel-data ignores. */
        ->and(fn (): SummarySections => SummarySections::parse(['headline' => '', 'points' => [], 'takeaways' => []]))
        ->toThrow(UnexpectedValueException::class);
});

/*
 * Every prompt gets the configured budget rather than the SDK's own sixty seconds, which is not
 * nearly enough for a local model reading an hour of transcript. Asserted because the failure is
 * invisible until a long video times out halfway through summarising.
 *
 * All three passes, because each is now a separate step and each reads the budget for itself -
 * one that forgot would be a step running on sixty seconds while the other two are not.
 */
test('every pass is given the configured timeout', function (): void {
    fakeSummariser();

    config()->set('summaries.model_timeout', 900);

    [$summary, $claim] = composable(['transcript_language' => 'nl', 'ideas' => null]);

    app(DraftIdeas::class)->execute($summary->id, $claim);
    app(ComposeSummary::class)->execute($summary->id, $claim);
    app(TranslateOutline::class)->execute($summary->id, $claim);

    foreach ([ExtractIdeas::class, CreateSummary::class, TranslateSummary::class] as $agent) {
        $agent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->timeout === 900);
    }
});

/*
 * The agents name neither, so a summary produced against a provider nobody configured would be a
 * silent fallback to whatever the SDK defaults to. What phpunit.xml pins is what should arrive.
 */
test('the provider and model come from configuration rather than from the agents', function (): void {
    fakeSummariser();

    [$summary, $claim] = composable();

    app(ComposeSummary::class)->execute($summary->id, $claim);

    CreateSummary::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->model === 'test-model'
            && $prompt->provider->name() === 'openai-compatible',
    );
});
