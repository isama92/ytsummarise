<?php

declare(strict_types=1);

use App\Actions\Summarising\ComposeSummary;
use App\Actions\Summarising\DraftIdeas;
use App\Actions\Summarising\FetchCaptions;
use App\Actions\Summarising\FindVideo;
use App\Actions\Summarising\TranslateOutline;
use App\Enums\SummaryStatus;
use App\Models\Summary;
use App\Services\Ai\Agents\CreateSummary;
use App\Services\Ai\Agents\ExtractIdeas;
use App\Services\Ai\Agents\TranslateSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Prompts\AgentPrompt;

/*
 * What each step does that the others do not.
 *
 * The outcomes a whole attempt produces are pinned end to end in tests/Feature/SummariseVideoTest;
 * what is here is the part that only shows up once the work is five jobs - which step writes what,
 * and which of them is allowed to say the summary is finished.
 */

/**
 * A row claimed by an attempt, ready for the step under test to be handed the claim.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Summary, 1: CarbonImmutable}
 */
function claimedSummary(array $attributes = []): array
{
    $summary = Summary::factory()->pending()->create($attributes);

    return [$summary, claimSummary($summary->id)];
}

test('finding the video writes the title and nothing else', function (): void {
    fakeYouTube('Never Gonna Give You Up');

    [$summary, $claim] = claimedSummary();

    app(FindVideo::class)->execute($summary->id, $claim);

    $summary->refresh();

    expect($summary->title)->toBe('Never Gonna Give You Up')
        /*
         * Written here rather than carried to the end, which is a change from the single job and
         * an improvement: a heading on screen while the summary is still being written is a
         * better wait than a spinner. Nothing else is touched, and the row is still pending.
         */
        ->and($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->transcript)->toBeNull()
        ->and($summary->outline)->toBeNull();
});

test('fetching the captions writes the transcript and its language together', function (): void {
    fakeTranscript('We are no strangers to love.', 'nl');

    [$summary, $claim] = claimedSummary();

    app(FetchCaptions::class)->execute($summary->id, $claim);

    $summary->refresh();

    expect($summary->transcript)->toBe('We are no strangers to love.')
        ->and($summary->transcript_language)->toBe('nl')
        ->and($summary->status)->toBe(SummaryStatus::Pending);
});

/*
 * The payoff for storing the transcript. A retry that got this far last time asks nothing of
 * YouTube at all, and reads exactly the words the failed attempt did rather than whatever the
 * captions say today.
 */
test('captions already on the row are not fetched again', function (): void {
    Process::fake();

    [$summary, $claim] = claimedSummary([
        'transcript' => 'The words from the attempt before.',
        'transcript_language' => 'en',
    ]);

    app(FetchCaptions::class)->execute($summary->id, $claim);

    expect($summary->fresh()?->transcript)->toBe('The words from the attempt before.');

    Process::assertNothingRan();
});

test('drafting the ideas reads the transcript and writes what it made of it', function (): void {
    ExtractIdeas::fake(fn (): string => "An idea from the video\nAnother idea");

    [$summary, $claim] = claimedSummary([
        'transcript' => 'The transcript itself, at length.',
        'transcript_language' => 'en',
    ]);

    app(DraftIdeas::class)->execute($summary->id, $claim);

    expect($summary->fresh()?->ideas)->toBe("An idea from the video\nAnother idea");

    /* The first pass is the only one the transcript itself is given to. */
    ExtractIdeas::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'The transcript itself, at length.');
});

/*
 * The same economy the transcript buys, one step further along: an attempt that failed while
 * writing the summary costs one model call to repeat rather than two.
 */
test('ideas already on the row are not drafted again', function (): void {
    [$summary, $claim] = claimedSummary([
        'transcript' => 'We are no strangers to love.',
        'transcript_language' => 'en',
        'ideas' => 'What the attempt before made of them.',
    ]);

    app(DraftIdeas::class)->execute($summary->id, $claim);

    expect($summary->fresh()?->ideas)->toBe('What the attempt before made of them.');

    /* Deliberately unfaked, so a second pass would fail rather than pass unnoticed. */
    ExtractIdeas::assertNeverPrompted();
});

/*
 * Composing reads the ideas rather than the transcript, which is the whole reason there are two
 * passes: one prompt asked to both find what matters and phrase it well summarises the opening
 * five minutes.
 */
test('composing the summary reads the ideas, not the transcript', function (): void {
    CreateSummary::fake(fn (): array => [
        'headline' => 'The whole video in one sentence',
        'points' => ['The first thing'],
        'takeaways' => ['The thing worth remembering'],
    ]);

    [$summary, $claim] = claimedSummary([
        'transcript' => 'We are no strangers to love.',
        'transcript_language' => 'en',
        'ideas' => 'What the video says.',
    ]);

    app(ComposeSummary::class)->execute($summary->id, $claim);

    $summary->refresh();

    expect($summary->outline['original']['headline'])->toBe('The whole video in one sentence')
        ->and($summary->outline['language'])->toBe('en')
        ->and($summary->outline['english'])->toBeNull()
        /*
         * And it is still pending, which is the difference between "the summary exists" and
         * "the summary is finished". Flipping it here would put a summary on screen in a
         * language nobody asked for and change it under them a model call later.
         */
        ->and($summary->status)->toBe(SummaryStatus::Pending);

    CreateSummary::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'What the video says.');
});

/*
 * The last step always runs, including for the English videos it has nothing to translate. A step
 * left out for some videos would make the batch four jobs for one and five for another, so the
 * number on the dashboard would mean a different thing per video and would jump from 3/4 to done.
 */
test('an English video still runs the last step, which finishes it', function (): void {
    [$summary, $claim] = claimedSummary([
        'transcript' => 'We are no strangers to love.',
        'transcript_language' => 'en',
        'ideas' => 'What the video says.',
        'outline' => [
            'language' => 'en',
            'original' => ['headline' => 'A headline', 'points' => [], 'takeaways' => []],
            'english' => null,
        ],
    ]);

    app(TranslateOutline::class)->execute($summary->id, $claim);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->outline['english'])->toBeNull()
        ->and($summary->error)->toBeNull();

    /* Unfaked on purpose: a third pass for a video already in English is a paid mistake. */
    TranslateSummary::assertNeverPrompted();
});

test('a video in another language is translated before it is finished', function (): void {
    TranslateSummary::fake(fn (): array => [
        'headline' => 'The whole video in one English sentence',
        'points' => [],
        'takeaways' => [],
    ]);

    [$summary, $claim] = claimedSummary([
        'transcript' => 'Wij zijn geen vreemden voor de liefde.',
        'transcript_language' => 'nl',
        'ideas' => 'Wat de video zegt.',
        'outline' => [
            'language' => 'nl',
            'original' => ['headline' => 'Een kop', 'points' => [], 'takeaways' => []],
            'english' => null,
        ],
    ]);

    app(TranslateOutline::class)->execute($summary->id, $claim);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->outline['language'])->toBe('nl')
        /* Both kept, and the original is what the page shows above the translation. */
        ->and($summary->outline['original']['headline'])->toBe('Een kop')
        ->and($summary->outline['english']['headline'])->toBe('The whole video in one English sentence');
});

/*
 * Nothing before the last step says a summary is finished, so a chain that stops early leaves a
 * pending row for summaries:expire rather than a half-written summary somebody is reading.
 */
test('no step but the last one marks a summary ready', function (): void {
    fakeSummarisableVideo();

    [$summary, $claim] = claimedSummary();

    foreach ([FindVideo::class, FetchCaptions::class, DraftIdeas::class, ComposeSummary::class] as $step) {
        app($step)->execute($summary->id, $claim);

        expect($summary->fresh()?->status)->toBe(SummaryStatus::Pending);
    }

    app(TranslateOutline::class)->execute($summary->id, $claim);

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Ready);
});

/*
 * A worker can write the outline and then be killed before it deletes the job - out of memory, a
 * signal, maxTime - and retry_after hands the same job to the next worker. Nothing else would
 * notice, because only TranslateOutline moves the row off pending, so without a guard here the
 * model is prompted a second time at full price.
 */
test('a redelivered compose step does not pay for a second summary', function (): void {
    [$summary, $claim] = claimedSummary([
        'transcript' => 'We are no strangers to love.',
        'transcript_language' => 'en',
        'ideas' => 'What the video says.',
        'outline' => [
            'language' => 'en',
            'original' => ['headline' => 'The summary the first delivery wrote', 'points' => [], 'takeaways' => []],
            'english' => null,
        ],
    ]);

    app(ComposeSummary::class)->execute($summary->id, $claim);

    expect($summary->fresh()?->outline['original']['headline'])->toBe('The summary the first delivery wrote');

    /* Deliberately unfaked, so a second pass would fail rather than pass unnoticed. */
    CreateSummary::assertNeverPrompted();
});

/*
 * An empty answer is the feature being broken rather than something true about the video, so it
 * fails rather than being stored.
 *
 * Storing it would be permanent: the skip guard would match forever, ComposeSummary would prompt
 * a model with nothing, and no retry could ever recover the video without editing the row by hand.
 */
test('a model that returns no ideas fails rather than storing nothing', function (): void {
    ExtractIdeas::fake(fn (): string => '   ');

    [$summary, $claim] = claimedSummary([
        'transcript' => 'We are no strangers to love.',
        'transcript_language' => 'en',
    ]);

    expect(fn () => app(DraftIdeas::class)->execute($summary->id, $claim))
        ->toThrow(UnexpectedValueException::class)
        ->and($summary->fresh()?->ideas)->toBeNull();
});

/*
 * And an empty string that somehow reached the column is not read as "already drafted", so a row
 * left that way by an older deploy can still be recovered by asking again.
 */
test('ideas stored as an empty string are drafted again', function (): void {
    ExtractIdeas::fake(fn (): string => 'What the video says.');

    [$summary, $claim] = claimedSummary([
        'transcript' => 'We are no strangers to love.',
        'transcript_language' => 'en',
        'ideas' => '',
    ]);

    app(DraftIdeas::class)->execute($summary->id, $claim);

    expect($summary->fresh()?->ideas)->toBe('What the video says.');
});

/*
 * The translation reads the finished summary rather than the transcript, which is the cheaper
 * order and the better one: everything lost in translating an hour of speech would be lost before
 * anybody had decided what mattered.
 *
 * Worth pinning on this step rather than trusting the chain, because the step is handed a row
 * that holds both and could read either.
 */
test('the translation is given the summary rather than the transcript', function (): void {
    TranslateSummary::fake(fn (): array => [
        'headline' => 'The whole video in one English sentence',
        'points' => [],
        'takeaways' => [],
    ]);

    [$summary, $claim] = claimedSummary([
        'transcript' => 'The transcript itself, at length.',
        'transcript_language' => 'nl',
        'ideas' => 'Wat de video zegt.',
        'outline' => [
            'language' => 'nl',
            'original' => ['headline' => 'Een kop', 'points' => [], 'takeaways' => []],
            'english' => null,
        ],
    ]);

    app(TranslateOutline::class)->execute($summary->id, $claim);

    TranslateSummary::assertPrompted(function (AgentPrompt $prompt): bool {
        expect($prompt->prompt)->not->toContain('The transcript itself');

        /* As json, which is the shape the instructions say it arrives in. */
        return json_decode($prompt->prompt, true)['headline'] === 'Een kop';
    });
});
