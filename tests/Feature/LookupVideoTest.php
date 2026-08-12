<?php

declare(strict_types=1);

use App\Services\YouTube\Actions\LookupVideo;
use App\Services\YouTube\DataApiConnector;
use App\Services\YouTube\Enums\VideoPresence;
use App\Services\YouTube\Requests\OembedRequest;
use App\Services\YouTube\Requests\VideosRequest;
use Illuminate\Support\Facades\Log;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Laravel\Facades\Saloon;

/**
 * A Data API answer with one video in it.
 *
 * @return array<string, mixed>
 */
function dataApiVideo(string $title): array
{
    return ['items' => [['snippet' => ['title' => $title]]]];
}

/**
 * The lookup, resolved with both its connectors.
 */
function lookup(): LookupVideo
{
    return app(LookupVideo::class);
}

/*
 * The ordinary case, and the one that costs nothing: the keyless endpoint answers with the
 * name of the video, and nothing else is asked.
 */
test('the keyless endpoint names the video', function (): void {
    config()->set('services.youtube.key', 'a-key-that-is-not-needed');

    Saloon::fake([
        OembedRequest::class => MockResponse::make(['title' => 'Never Gonna Give You Up']),
        VideosRequest::class => MockResponse::make(dataApiVideo('Should not be asked for')),
    ]);

    $result = lookup()->execute('dQw4w9WgXcQ');

    expect($result->presence)->toBe(VideoPresence::Found)
        ->and($result->title)->toBe('Never Gonna Give You Up');

    /* A title is the whole answer, so the quota is left alone even though a key exists. */
    Saloon::assertNotSent(VideosRequest::class);
});

test('the video it asks about is the one it was given', function (): void {
    Saloon::fake([OembedRequest::class => MockResponse::make(['title' => 'A video'])]);

    lookup()->execute('dQw4w9WgXcQ');

    /*
     * Read off the url that really went out rather than off the request object, because the id
     * reaches oEmbed inside a watch url that the request builds rather than as a parameter of
     * its own.
     */
    Saloon::assertSent(fn (Request $request, Response $response): bool => str_contains(
        (string) $response->getPendingRequest()->getUri(),
        'dQw4w9WgXcQ',
    ));
});

test('a video that is not there is reported missing', function (int $status): void {
    Saloon::fake([OembedRequest::class => MockResponse::make(status: $status)]);

    expect(lookup()->execute('dQw4w9WgXcQ')->presence)->toBe(VideoPresence::Missing);
})->with([
    'no such video' => 404,
    /* oEmbed's answer to a url it will not parse, which a real video cannot produce. */
    'a url it refuses' => 400,
]);

/*
 * A video whose owner disabled embedding. It exists - the refusal proves it - and it is worth
 * summarising, so the only thing missing is the heading.
 */
test('a video that will not be embedded is found without a title', function (int $status): void {
    Saloon::fake([OembedRequest::class => MockResponse::make(status: $status)]);

    $result = lookup()->execute('dQw4w9WgXcQ');

    expect($result->presence)->toBe(VideoPresence::Found)
        ->and($result->title)->toBeNull();
})->with([
    'unauthorised' => 401,
    'forbidden' => 403,
]);

test('an answer with no usable title is found without one', function (mixed $title): void {
    Saloon::fake([OembedRequest::class => MockResponse::make(['title' => $title])]);

    $result = lookup()->execute('dQw4w9WgXcQ');

    expect($result->presence)->toBe(VideoPresence::Found)
        ->and($result->title)->toBeNull();
})->with([
    'empty' => '',
    'absent' => null,
    'not a string' => 12,
]);

test('a lookup nobody answers establishes nothing', function (): void {
    Saloon::fake([OembedRequest::class => youTubeUnreachable()]);

    expect(lookup()->execute('dQw4w9WgXcQ')->presence)->toBe(VideoPresence::Unknown);
});

test('an answer that makes no sense establishes nothing', function (): void {
    Log::spy();

    Saloon::fake([OembedRequest::class => MockResponse::make(status: 500)]);

    expect(lookup()->execute('dQw4w9WgXcQ')->presence)->toBe(VideoPresence::Unknown);

    Log::shouldHaveReceived('warning')->once();
});

/*
 * The second opinion, which only exists when somebody configured a key. Without one the
 * keyless answer stands, whatever it was, and no quota is spent finding out.
 */
test('without a key there is nothing to ask twice', function (): void {
    config()->set('services.youtube.key');

    Saloon::fake([OembedRequest::class => MockResponse::make(status: 404)]);

    expect(lookup()->execute('dQw4w9WgXcQ')->presence)->toBe(VideoPresence::Missing);

    Saloon::assertNotSent(VideosRequest::class);
});

/*
 * The same rule, checked where it now lives. `YOUTUBE_API_KEY=` reads back from an env file as an
 * empty string rather than as nothing, and config/services.php is what turns that into null, so
 * the action is entitled to treat any string it is given as a key somebody meant.
 *
 * Deliberately not asserted against a particular environment value, which .ai/rules/config.md
 * warns off: a developer with a real key configured and CI with none both satisfy this, and both
 * would catch the config file going back to a bare env() call.
 */
test('an empty key is no key at all', function (): void {
    expect(config('services.youtube.key'))->not->toBe('');
});

/*
 * The action never asks an unconfigured connector anything, so this is the connector's own
 * contract rather than the lookup's: asked without a key, it must not invent one. A trailing
 * `key=` is a request the Data API refuses rather than one it ignores, and the difference between
 * that and a refused key is a confusing hour for whoever reads the log.
 */
test('an unconfigured connector sends no key at all', function (): void {
    config()->set('services.youtube.key');

    Saloon::fake([VideosRequest::class => MockResponse::make(['items' => []])]);

    app(DataApiConnector::class)->send(new VideosRequest('dQw4w9WgXcQ'));

    Saloon::assertSent(fn (Request $request, Response $response): bool => ! str_contains(
        (string) $response->getPendingRequest()->getUri(),
        'key=',
    ));
});

test('a key rescues a lookup the keyless endpoint could not answer', function (mixed $oembed): void {
    config()->set('services.youtube.key', 'a-key');

    Saloon::fake([
        OembedRequest::class => $oembed,
        VideosRequest::class => MockResponse::make(dataApiVideo('Never Gonna Give You Up')),
    ]);

    $result = lookup()->execute('dQw4w9WgXcQ');

    expect($result->presence)->toBe(VideoPresence::Found)
        ->and($result->title)->toBe('Never Gonna Give You Up');

    /* And the key really goes out on the wire, rather than being read and dropped. */
    Saloon::assertSent(fn (Request $request, Response $response): bool => str_contains(
        (string) $response->getPendingRequest()->getUri(),
        'key=a-key',
    ));
})->with([
    'a video it said was missing' => fn () => MockResponse::make(status: 404),
    'a lookup that never answered' => fn (): MockResponse => youTubeUnreachable(),
    /* Including the embedding refusal, where the video was never in doubt but the name was. */
    'a video it would not name' => fn () => MockResponse::make(status: 401),
]);

test('a video neither endpoint has is missing', function (): void {
    config()->set('services.youtube.key', 'a-key');

    Saloon::fake([
        OembedRequest::class => MockResponse::make(status: 404),
        VideosRequest::class => MockResponse::make(['items' => []]),
    ]);

    expect(lookup()->execute('dQw4w9WgXcQ')->presence)->toBe(VideoPresence::Missing);
});

/*
 * The Data API leaving a video out of its list is not proof of anything when the other
 * endpoint has already refused to embed it: that refusal only happens for a video that is
 * there. Reporting it missing would tell somebody their link is wrong about a video they can
 * watch.
 */
test('a listing that omits a video does not unprove a video that answered', function (): void {
    config()->set('services.youtube.key', 'a-key');

    Saloon::fake([
        OembedRequest::class => MockResponse::make(status: 401),
        VideosRequest::class => MockResponse::make(['items' => []]),
    ]);

    $result = lookup()->execute('dQw4w9WgXcQ');

    expect($result->presence)->toBe(VideoPresence::Found)
        ->and($result->title)->toBeNull();
});

/*
 * The other way round: the keyless endpoint established nothing, so the Data API having no
 * such video is the only answer anybody has, and it is a definitive one.
 */
test('a listing that omits a video settles it when nothing else could', function (): void {
    config()->set('services.youtube.key', 'a-key');

    Saloon::fake([
        OembedRequest::class => youTubeUnreachable(),
        VideosRequest::class => MockResponse::make(['items' => []]),
    ]);

    expect(lookup()->execute('dQw4w9WgXcQ')->presence)->toBe(VideoPresence::Missing);
});

test('a refused or unusable second opinion establishes nothing', function (mixed $dataApi): void {
    Log::spy();

    config()->set('services.youtube.key', 'a-key');

    Saloon::fake([
        OembedRequest::class => youTubeUnreachable(),
        VideosRequest::class => $dataApi,
    ]);

    expect(lookup()->execute('dQw4w9WgXcQ')->presence)->toBe(VideoPresence::Unknown);
})->with([
    /* Which is what a spent quota looks like. */
    'refused' => fn () => MockResponse::make(status: 403),
    'never answered' => fn (): MockResponse => youTubeUnreachable(),
    'answered without a listing' => fn () => MockResponse::make(['error' => 'nothing useful here']),
]);
