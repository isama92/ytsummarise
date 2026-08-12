<?php

declare(strict_types=1);

use App\Services\YouTube\VideoLookup;
use App\Services\YouTube\VideoPresence;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Wildcards rather than exact urls, because both endpoints carry a query string and one of
 * them carries a key nobody wants written down twice.
 */
const OEMBED_ENDPOINT = 'https://www.youtube.com/oembed*';

const DATA_API_ENDPOINT = 'https://www.googleapis.com/youtube/v3/videos*';

/**
 * A Data API answer with one video in it.
 *
 * @return array<string, mixed>
 */
function dataApiVideo(string $title): array
{
    return ['items' => [['snippet' => ['title' => $title]]]];
}

/*
 * The ordinary case, and the one that costs nothing: the keyless endpoint answers with the
 * name of the video, and nothing else is asked.
 */
test('the keyless endpoint names the video', function (): void {
    config()->set('services.youtube.key', 'a-key-that-is-not-needed');

    Http::fake([
        OEMBED_ENDPOINT => Http::response(['title' => 'Never Gonna Give You Up']),
        DATA_API_ENDPOINT => Http::response(dataApiVideo('Should not be asked for')),
    ]);

    $result = app(VideoLookup::class)->find('dQw4w9WgXcQ');

    expect($result->presence)->toBe(VideoPresence::Found)
        ->and($result->title)->toBe('Never Gonna Give You Up');

    /* A title is the whole answer, so the quota is left alone even though a key exists. */
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis'));
});

test('the video it asks about is the one it was given', function (): void {
    Http::fake([OEMBED_ENDPOINT => Http::response(['title' => 'A video'])]);

    app(VideoLookup::class)->find('dQw4w9WgXcQ');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'dQw4w9WgXcQ'));
});

test('a video that is not there is reported missing', function (int $status): void {
    Http::fake([OEMBED_ENDPOINT => Http::response(status: $status)]);

    expect(app(VideoLookup::class)->find('dQw4w9WgXcQ')->presence)
        ->toBe(VideoPresence::Missing);
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
    Http::fake([OEMBED_ENDPOINT => Http::response(status: $status)]);

    $result = app(VideoLookup::class)->find('dQw4w9WgXcQ');

    expect($result->presence)->toBe(VideoPresence::Found)
        ->and($result->title)->toBeNull();
})->with([
    'unauthorised' => 401,
    'forbidden' => 403,
]);

test('an answer with no usable title is found without one', function (mixed $title): void {
    Http::fake([OEMBED_ENDPOINT => Http::response(['title' => $title])]);

    $result = app(VideoLookup::class)->find('dQw4w9WgXcQ');

    expect($result->presence)->toBe(VideoPresence::Found)
        ->and($result->title)->toBeNull();
})->with([
    'empty' => '',
    'absent' => null,
    'not a string' => 12,
]);

test('a lookup nobody answers establishes nothing', function (): void {
    Http::fake([OEMBED_ENDPOINT => Http::failedConnection('timed out')]);

    expect(app(VideoLookup::class)->find('dQw4w9WgXcQ')->presence)
        ->toBe(VideoPresence::Unknown);
});

test('an answer that makes no sense establishes nothing', function (): void {
    Log::spy();

    Http::fake([OEMBED_ENDPOINT => Http::response(status: 500)]);

    expect(app(VideoLookup::class)->find('dQw4w9WgXcQ')->presence)
        ->toBe(VideoPresence::Unknown);

    Log::shouldHaveReceived('warning')->once();
});

/*
 * The second opinion, which only exists when somebody configured a key. Without one the
 * keyless answer stands, whatever it was, and no quota is spent finding out.
 */
test('without a key there is nothing to ask twice', function (): void {
    config()->set('services.youtube.key');

    Http::fake([OEMBED_ENDPOINT => Http::response(status: 404)]);

    expect(app(VideoLookup::class)->find('dQw4w9WgXcQ')->presence)
        ->toBe(VideoPresence::Missing);

    Http::assertSentCount(1);
});

test('an empty key is no key at all', function (): void {
    config()->set('services.youtube.key', '');

    Http::fake([OEMBED_ENDPOINT => Http::failedConnection('timed out')]);

    expect(app(VideoLookup::class)->find('dQw4w9WgXcQ')->presence)
        ->toBe(VideoPresence::Unknown);

    Http::assertSentCount(1);
});

test('a key rescues a lookup the keyless endpoint could not answer', function (mixed $oembed): void {
    config()->set('services.youtube.key', 'a-key');

    Http::fake([
        OEMBED_ENDPOINT => $oembed,
        DATA_API_ENDPOINT => Http::response(dataApiVideo('Never Gonna Give You Up')),
    ]);

    $result = app(VideoLookup::class)->find('dQw4w9WgXcQ');

    expect($result->presence)->toBe(VideoPresence::Found)
        ->and($result->title)->toBe('Never Gonna Give You Up');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'key=a-key'));
})->with([
    'a video it said was missing' => fn () => Http::response(status: 404),
    'a lookup that never answered' => fn () => Http::failedConnection('timed out'),
    /* Including the embedding refusal, where the video was never in doubt but the name was. */
    'a video it would not name' => fn () => Http::response(status: 401),
]);

test('a video neither endpoint has is missing', function (): void {
    config()->set('services.youtube.key', 'a-key');

    Http::fake([
        OEMBED_ENDPOINT => Http::response(status: 404),
        DATA_API_ENDPOINT => Http::response(['items' => []]),
    ]);

    expect(app(VideoLookup::class)->find('dQw4w9WgXcQ')->presence)
        ->toBe(VideoPresence::Missing);
});

/*
 * The Data API leaving a video out of its list is not proof of anything when the other
 * endpoint has already refused to embed it: that refusal only happens for a video that is
 * there. Reporting it missing would tell somebody their link is wrong about a video they can
 * watch.
 */
test('a listing that omits a video does not unprove a video that answered', function (): void {
    config()->set('services.youtube.key', 'a-key');

    Http::fake([
        OEMBED_ENDPOINT => Http::response(status: 401),
        DATA_API_ENDPOINT => Http::response(['items' => []]),
    ]);

    $result = app(VideoLookup::class)->find('dQw4w9WgXcQ');

    expect($result->presence)->toBe(VideoPresence::Found)
        ->and($result->title)->toBeNull();
});

/*
 * The other way round: the keyless endpoint established nothing, so the Data API having no
 * such video is the only answer anybody has, and it is a definitive one.
 */
test('a listing that omits a video settles it when nothing else could', function (): void {
    config()->set('services.youtube.key', 'a-key');

    Http::fake([
        OEMBED_ENDPOINT => Http::failedConnection('timed out'),
        DATA_API_ENDPOINT => Http::response(['items' => []]),
    ]);

    expect(app(VideoLookup::class)->find('dQw4w9WgXcQ')->presence)
        ->toBe(VideoPresence::Missing);
});

test('a refused or unusable second opinion establishes nothing', function (mixed $dataApi): void {
    Log::spy();

    config()->set('services.youtube.key', 'a-key');

    Http::fake([
        OEMBED_ENDPOINT => Http::failedConnection('timed out'),
        DATA_API_ENDPOINT => $dataApi,
    ]);

    expect(app(VideoLookup::class)->find('dQw4w9WgXcQ')->presence)
        ->toBe(VideoPresence::Unknown);
})->with([
    /* Which is what a spent quota looks like. */
    'refused' => fn () => Http::response(status: 403),
    'never answered' => fn () => Http::failedConnection('timed out'),
    'answered without a listing' => fn () => Http::response(['error' => 'nothing useful here']),
]);
