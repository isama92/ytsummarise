<?php

declare(strict_types=1);

use App\Services\Ai\Agents\CreateSummary;
use App\Services\Ai\Agents\ExtractIdeas;
use App\Services\Ai\Agents\TranslateSummary;
use App\Services\YouTube\Requests\OembedRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Config;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    /*
     * Nothing in the suite is allowed to reach the network. Summarising a video looks a
     * video up over http, so without this a test that forgets to fake it passes on a
     * developer's machine, hits YouTube from CI, and fails whenever YouTube feels like it.
     * A stray request throws instead, and says which url it was.
     *
     * Both clients, because they are genuinely separate: Saloon does not go through
     * Illuminate's http client at all - it has its own sender - so Http::fake() and
     * Http::preventStrayRequests() see nothing a connector sends, and Saloon's own guard sees
     * nothing Http:: sends. The lookup uses Saloon; anything else added later may not.
     */
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        Config::preventStrayRequests();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Run a job the way a worker would, resolving whatever its handle() asks for.
 *
 * These tests call handle() directly rather than dispatching, because the job names its own
 * queue connection and that overrides the sync default phpunit.xml sets - dispatching it
 * queues it instead of running it. Going through the container is what supplies the
 * dependencies handle() takes by method injection, exactly as the queue worker does.
 */
function work(object $job): void
{
    app()->call([$job, 'handle']);
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
 */
function fakeYouTube(string $title = 'Never Gonna Give You Up'): void
{
    Saloon::fake([
        OembedRequest::class => MockResponse::make(['title' => $title]),
    ]);
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
