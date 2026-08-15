<?php

declare(strict_types=1);

use App\Actions\Summarising\ComposeSummary;
use App\Actions\Summarising\DraftIdeas;
use App\Actions\Summarising\FetchCaptions;
use App\Actions\Summarising\FindVideo;
use App\Actions\Summarising\SummarisingStep;
use App\Actions\Summarising\TranslateOutline;
use App\Models\Summary;
use App\Services\Ai\Agents\CreateSummary;
use App\Services\Ai\Agents\ExtractIdeas;
use App\Services\Ai\Agents\TranslateSummary;
use App\Services\YouTube\Requests\OembedRequest;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
| Here rather than in tests/Pest.php, which is left to the two things that configure the suite:
| what a test case is, and what expect() can do. Nothing requires this file and nothing needs to.
| Pest\Bootstrappers\BootFiles include_once's four names under the test directory - Expectations,
| Expectations.php, Helpers, Helpers.php and Pest.php - and it recurses into the two that are
| directories, so any .php file dropped in here is loaded before the first test runs. Which is
| also the catch: a file in here is loaded whether anything uses it or not, so this is for
| functions rather than for anything with a cost to declaring it.
|
| It loads before Pest.php, so nothing here may depend on what that file sets up.
|
| Class fixtures do not belong here. They live in tests/Support and are found by the PSR-4 entry
| in composer.json, which is what lets them be imported by name.
|
*/

/**
 * The five steps of summarising, in the order App\Actions\SummariseVideo chains them.
 *
 * One list, so a test that walks the chain and the action that queues it cannot disagree about
 * what the chain is. A step added to one and not the other is what this exists to prevent.
 *
 * @return list<class-string<SummarisingStep>>
 */
function summarisingSteps(): array
{
    return [FindVideo::class, FetchCaptions::class, DraftIdeas::class, ComposeSummary::class, TranslateOutline::class];
}

/**
 * Take the claim the way SummariseVideo does, and hand back the token it claimed with.
 *
 * Every step reads and writes conditionally on this value, so a test running steps by hand needs
 * the same one the entry action would have written. A token rather than the moment, because the
 * moment cannot tell two attempts apart inside one second - see the migration that added it.
 */
function claimSummary(int $summaryId): string
{
    $claim = (string) Str::ulid();

    Summary::query()
        ->whereKey($summaryId)
        ->whereNull('started_at')
        ->update(['started_at' => Date::now(), 'claim' => $claim]);

    return $claim;
}

/**
 * Summarise a video the way a worker would: the whole chain, in order, in one process.
 *
 * These tests run the steps directly rather than dispatching, because the steps name their own
 * queue connection and that overrides the sync default phpunit.xml sets - dispatching queues them
 * instead of running them. Going through the container is what supplies the collaborators each
 * constructor takes, exactly as the queue worker does when it resolves an action.
 *
 * Resolved on every call rather than once, so each step holds nothing of the last one's state,
 * which is the whole condition a chain runs under.
 *
 * A step that gives up part way stops the rest without any batch to cancel: giving up writes the
 * row off, and every step after it opens by asking for a row that is still pending and claimed by
 * this attempt. Cancelling the batch is the belt to that braces, and is what a test wanting to
 * pin it should assert on directly.
 */
function summariseVideo(int $summaryId, ?string $claim = null): void
{
    $claim ??= claimSummary($summaryId);

    foreach (summarisingSteps() as $step) {
        app($step)->execute($summaryId, $claim);
    }
}

/**
 * The bytes a faked cover image answers with.
 *
 * A real jpeg, if a very short one: the start of image marker, a JFIF header and the end of
 * image marker, which is what `file` reads to call something a JPEG. Nothing under test looks
 * inside an image, but a fixture that is honestly shaped costs nothing and means a test that
 * writes one to disk has written something openable.
 */
const COVER_BYTES = "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xd9";

/**
 * A YouTube that has a cover image, or does not have one at any size.
 *
 * Illuminate's http client rather than Saloon, because that is what FetchCover uses; see
 * .ai/rules/services.md for why the two fakes cannot see each other's requests.
 *
 * The size is which rung of the ladder answers. maxresdefault by default, so a test about
 * something else spends one request rather than three, and null for a video that has no
 * thumbnail at all. Which rung answered is FetchCoverTest's business and it arranges its own.
 *
 * The specific stub is registered before the catch-all, and that order is what makes this work:
 * Http::fake accumulates stubs and the first pattern that matches a url wins.
 */
function fakeCover(?string $size = 'maxresdefault'): void
{
    if ($size !== null) {
        Http::fake(["i.ytimg.com/vi/*/{$size}.jpg" => Http::response(COVER_BYTES)]);
    }

    Http::fake(['i.ytimg.com/*' => Http::response(status: 404)]);
}

/**
 * A YouTube that answers, for the tests that are about something else.
 *
 * Only the keyless endpoint, because a title is the whole answer and the lookup asks nothing
 * further once it has one. What the lookup does with every other answer is
 * tests/Feature/LookupVideoTest.php's business, not every job test's.
 *
 * Keyed by request class rather than by url, which is how Saloon's fakes work. The plugin
 * destroys the global mock client when the application boots, so each test starts clean without
 * any teardown here.
 *
 * The cover is faked here as well, because FindVideo fetches one immediately after the lookup
 * and the suite forbids a stray request. A test wanting the no-cover path passes
 * `coverSize: null` rather than calling fakeCover() afterwards: stubs accumulate in the order
 * they are registered and the first match wins, so a later call could not take this one back.
 */
function fakeYouTube(string $title = 'Never Gonna Give You Up', ?string $coverSize = 'maxresdefault'): void
{
    Saloon::fake([
        OembedRequest::class => MockResponse::make(['title' => $title]),
    ]);

    fakeCover($coverSize);
}

/**
 * The application as it runs without a Data API key, which is a configuration it supports.
 *
 * phpunit.xml pins a fake key, so the suite exercises the fuller two-endpoint configuration by
 * default and a test wanting the keyless path opts into it here. Null rather than an empty
 * string: config/services.php is what turns an empty environment value into null, and setting
 * the config directly goes around it, so an empty string here would read as a key somebody meant
 * and the Data API would be asked with `key=` on the end.
 */
function withoutYouTubeKey(): void
{
    config()->set('services.youtube.key');
}

/**
 * A YouTube that never answers at all.
 *
 * Saloon's equivalent of a connection error, which is a thrown FatalRequestException rather than
 * a response with a status. The closure form is used because the exception has to carry the
 * pending request that failed, and only Saloon knows that at the point of sending.
 */
function youTubeUnreachable(): MockResponse
{
    return MockResponse::make()->throw(
        fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(
            new RuntimeException('Could not resolve host'),
            $pendingRequest,
        ),
    );
}

/**
 * Re-read a config file with some environment variables overridden.
 *
 * For the handful of values that are derived as a config file is read, where the derivation is
 * the thing worth testing and the container's copy is only ever one sample of it.
 *
 * Every layer is written, and that is the whole point of this helper rather than a putenv call.
 * env() answers from $_SERVER first, then $_ENV, and only then from anything putenv set - so a
 * bare putenv works on a machine whose .env omits the key and is silently ignored on one whose
 * .env has it. That is a test that passes locally and fails in CI, which is exactly what
 * happened: CI builds its .env from .env.example, which sets both SUMMARY_MODEL_TIMEOUT and
 * SUMMARY_RETENTION_DAYS, and three tests written with putenv went green here and red there.
 *
 * The same trap phpunit.xml works around by pinning credentials as both a <server> and a forced
 * <env> entry; see .ai/rules/config.md and .ai/rules/services.md.
 *
 * A null override removes the variable from all three, which is how "nobody set this" is tested.
 * Everything is put back afterwards, including the difference between a variable that was empty
 * and one that was not there at all.
 *
 * @param  array<string, string|null>  $overrides
 * @return array<string, mixed>
 */
function configWithEnv(string $file, array $overrides): array
{
    $restore = [];

    foreach ($overrides as $key => $value) {
        $restore[$key] = [
            $_SERVER[$key] ?? null,
            $_ENV[$key] ?? null,
            getenv($key),
        ];

        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            continue;
        }

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key.'='.$value);
    }

    try {
        return require config_path($file.'.php');
    } finally {
        foreach ($restore as $key => [$server, $env, $put]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }

            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }

            if ($put === false) {
                putenv($key);
            } else {
                putenv($key.'='.$put);
            }
        }
    }
}

/**
 * The url every faked caption track is served from.
 *
 * A real one carries a signature and an expiry and runs to several hundred characters. Only the
 * path matters to anything being tested, and this is what the Http fake below is keyed on.
 */
const CAPTION_URL = 'https://www.youtube.com/api/timedtext?fake=1';

/**
 * A video with captions, for the tests that are about something else.
 *
 * Fakes both halves of the fetch, because it is two steps: yt-dlp is asked to describe the video,
 * and the track it names is fetched over http. Faking one without the other gets a stray request
 * failure from whichever guard the other half trips.
 *
 * The metadata is the shape yt-dlp really produces, cut down to the keys FetchTranscript reads.
 * A manually written track rather than an automatic one, because that is the branch a test about
 * something else should not have to think about; the automatic ones, and the hundred and fifty
 * machine translations sitting beside them, are FetchTranscriptTest's business.
 */
function fakeTranscript(string $text = 'We are no strangers to love.', string $language = 'en'): void
{
    Process::fake(fn (): FakeProcessResult => Process::result(
        (string) json_encode([
            'language' => $language,
            'subtitles' => [
                $language => [
                    ['ext' => 'json3', 'url' => CAPTION_URL],
                ],
            ],
            'automatic_captions' => [],
        ]),
    ));

    Http::fake([
        'www.youtube.com/api/timedtext*' => Http::response([
            'events' => [
                ['tStartMs' => 0, 'dDurationMs' => 2000, 'segs' => [['utf8' => $text]]],
            ],
        ]),
    ]);
}

/**
 * A yt-dlp that is there and will not answer, for the tests about what that costs.
 *
 * Faked as a non-zero exit rather than as a thrown start failure, because the two arrive at the
 * same branch and only this one can be arranged without reaching into the process factory.
 */
function ytDlpFails(): void
{
    Process::fake(fn (): FakeProcessResult => Process::result(
        errorOutput: 'ERROR: [youtube] Video unavailable',
        exitCode: 1,
    ));
}

/**
 * A model that answers, for the tests that are about something else.
 *
 * Closures rather than arrays of responses, deliberately. An array is indexed and runs out: a
 * second prompt past the end of it is not an error but a response invented from the schema, so a
 * test asserting on a summary would quietly start asserting on generated noise. A closure answers
 * the same way however many times it is asked.
 *
 * The three sets of words are deliberately different from each other, so a test can tell which
 * pass produced what and a bug that renders one version twice cannot pass unnoticed.
 */
function fakeSummariser(): void
{
    ExtractIdeas::fake(fn (): string => "An idea from the video\nAnother idea from the video");

    CreateSummary::fake(fn (): array => [
        'headline' => 'The whole video in one sentence',
        'points' => ['The first thing it covers', 'The second thing it covers'],
        'takeaways' => ['The thing worth remembering'],
    ]);

    TranslateSummary::fake(fn (): array => [
        'headline' => 'The whole video in one English sentence',
        'points' => ['The first thing, in English', 'The second thing, in English'],
        'takeaways' => ['The thing worth remembering, in English'],
    ]);
}

/**
 * A video that can be summarised end to end: it exists, it has captions, and a model will answer.
 */
function fakeSummarisableVideo(string $title = 'Never Gonna Give You Up', string $language = 'en'): void
{
    fakeYouTube($title);
    fakeTranscript(language: $language);
    fakeSummariser();
}

/**
 * The command yt-dlp was run with, as one string.
 *
 * Process::fake() records the pending process rather than a command line, and the command is
 * given as an array here so that no shell is involved. Joining it is what lets a test assert on
 * the arguments without caring how they were quoted.
 */
function ytDlpCommand(PendingProcess $process): string
{
    return is_array($process->command)
        ? implode(' ', array_map(strval(...), $process->command))
        : (string) $process->command;
}
