<?php

declare(strict_types=1);

use App\Services\Ai\Actions\SummariseTranscript;
use App\Services\Ai\Agents\CreateSummary;
use App\Services\Ai\Agents\ExtractIdeas;
use App\Services\Ai\Agents\TranslateSummary;
use App\Services\Ai\Data\SummaryOutline;
use App\Services\YouTube\Data\TranscriptResult;
use Laravel\Ai\Prompts\AgentPrompt;

function summarise(string $text = 'Some words that were said.', string $language = 'en'): SummaryOutline
{
    return app(SummariseTranscript::class)->execute(TranscriptResult::found($text, $language));
}

test('a transcript becomes a summary', function (): void {
    fakeSummariser();

    $outline = summarise();

    expect($outline->language)->toBe('en')
        ->and($outline->original->headline)->toBe('The whole video in one sentence')
        ->and($outline->original->points)->toBe([
            'The first thing it covers',
            'The second thing it covers',
        ])
        ->and($outline->original->takeaways)->toBe(['The thing worth remembering']);
});

/*
 * The two passes exist because they do different jobs, so what each is given is the point of
 * having them. The first reads the transcript; the second reads what the first found, and never
 * the transcript - asking one prompt to both sift an hour of speech and phrase it well is what
 * this arrangement is avoiding.
 */
test('the passes run in order, and the second reads the first', function (): void {
    fakeSummariser();

    summarise('The transcript itself, at length.');

    ExtractIdeas::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->prompt === 'The transcript itself, at length.',
    );

    CreateSummary::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->prompt === "An idea from the video\nAnother idea from the video",
    );

    CreateSummary::assertNotPrompted(
        fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'The transcript itself'),
    );
});

/*
 * The whole reason there is a third pass, and the reason it is skipped: a summary of an English
 * video translated into English is the same summary twice, on the page and in the database.
 */
test('a video already in English is not translated', function (string $language): void {
    fakeSummariser();

    expect(summarise(language: $language)->english)->toBeNull();

    TranslateSummary::assertNeverPrompted();
})->with([
    'plain' => 'en',
    /* Both of these arrive as `en` from TranscriptResult, which is where that is decided. */
    'regional' => 'en-GB',
    'the original-language automatic track' => 'en-orig',
]);

test('a video in another language is summarised in it and then translated', function (): void {
    fakeSummariser();

    $outline = summarise(language: 'nl');

    expect($outline->language)->toBe('nl')
        /* The summary of what was actually said stays in the language it was said in. */
        ->and($outline->original->headline)->toBe('The whole video in one sentence')
        ->and($outline->english?->headline)->toBe('The whole video in one English sentence')
        ->and($outline->english?->points)->toBe([
            'The first thing, in English',
            'The second thing, in English',
        ]);
});

/*
 * The translation reads the finished summary rather than the transcript, which is the cheaper
 * order and the better one: everything lost in a translation of an hour of speech would be lost
 * before anybody decided what mattered.
 */
test('the translation is given the summary rather than the transcript', function (): void {
    fakeSummariser();

    summarise('The transcript itself, at length.', 'nl');

    TranslateSummary::assertPrompted(function (AgentPrompt $prompt): bool {
        expect($prompt->prompt)->not->toContain('The transcript itself');

        /* As json, which is the shape the instructions say it arrives in. */
        return json_decode($prompt->prompt, true)['headline'] === 'The whole video in one sentence';
    });
});

/*
 * Every prompt gets the configured budget rather than the SDK's own sixty seconds, which is not
 * nearly enough for a local model reading an hour of transcript. Asserted because the failure is
 * invisible until a long video times out halfway through summarising.
 */
test('every pass is given the configured timeout', function (): void {
    fakeSummariser();

    config()->set('summaries.model_timeout', 900);

    summarise(language: 'nl');

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

    summarise();

    CreateSummary::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->model === 'test-model'
            && $prompt->provider->name() === 'openai-compatible',
    );
});

/*
 * The response is json somebody else wrote, and this application is pointed at whatever endpoint
 * AI_PROVIDER names - which may be a local model behind a gateway that honours a json schema
 * loosely. A summary missing nine of its ten points is thin; one missing its headline is not a
 * summary at all, and only the second is worth failing the video over.
 */
test('a summary with no headline is a failure rather than a thin summary', function (mixed $headline): void {
    ExtractIdeas::fake(fn (): string => 'An idea');
    CreateSummary::fake(fn (): array => [
        'headline' => $headline,
        'points' => ['A point'],
        'takeaways' => ['A takeaway'],
    ]);

    expect(fn (): SummaryOutline => summarise())->toThrow(UnexpectedValueException::class);
})->with([
    'absent' => [null],
    'empty' => [''],
    'only whitespace' => ['   '],
    'the wrong type' => [42],
]);

test('a summary missing some of its lines keeps the rest', function (): void {
    ExtractIdeas::fake(fn (): string => 'An idea');
    CreateSummary::fake(fn (): array => [
        'headline' => 'A headline',
        'points' => ['A point', '', null, '  Another point  ', 42],
        'takeaways' => 'not a list at all',
    ]);

    $sections = summarise()->original;

    expect($sections->points)->toBe(['A point', 'Another point'])
        ->and($sections->takeaways)->toBeEmpty();
});

/*
 * A list with holes in it encodes as a json object rather than as an array, which reaches the
 * page as something it cannot map over. Filtering has to renumber as well as remove.
 */
test('a filtered list is still a list once it has been stored', function (): void {
    ExtractIdeas::fake(fn (): string => 'An idea');
    CreateSummary::fake(fn (): array => [
        'headline' => 'A headline',
        'points' => ['', 'The only real point', ''],
        'takeaways' => [],
    ]);

    $json = (string) json_encode(summarise()->toArray());

    expect($json)->toContain('"points":["The only real point"]');
});
