<?php

declare(strict_types=1);

use App\Services\YouTube\Requests\OembedRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
