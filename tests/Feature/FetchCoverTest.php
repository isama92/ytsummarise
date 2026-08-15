<?php

declare(strict_types=1);

use App\Models\Summary;
use App\Services\YouTube\Actions\FetchCover;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * Which size YouTube is asked for, in what order, and what happens when none of them answers.
 *
 * The ladder is the whole of this class. YouTube publishes a video's thumbnail at several sizes
 * and only guarantees the smallest, so asking for the largest and working down is what gets a
 * 720p image where there is one without giving up on the videos where there is not.
 */

/**
 * A row to fetch a cover for, with a video code a test can put into a url.
 */
function summaryForCover(string $videoId = 'dQw4w9WgXcQ'): Summary
{
    return Summary::factory()->create(['video_id' => $videoId]);
}

test('the largest size is asked for first and nothing else is asked for', function (): void {
    fakeCover();

    $summary = summaryForCover();

    expect(app(FetchCover::class)->execute($summary))->toBeTrue();

    Storage::disk(FetchCover::DISK)->assertExists($summary->file_name);

    /*
     * One request, not three. A video that has a full size thumbnail should cost one round trip,
     * and the count is what says the loop stops rather than merely preferring the first answer.
     */
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === "https://i.ytimg.com/vi/{$summary->video_id}/maxresdefault.jpg");
});

test('the file is named for the row rather than for the video', function (): void {
    fakeCover();

    $summary = summaryForCover();

    app(FetchCover::class)->execute($summary);

    /*
     * The uuid, which is what the url serving it is keyed on and what summaries:prune has left
     * to work from once the row is gone. The video code deliberately appears nowhere in it.
     */
    Storage::disk(FetchCover::DISK)->assertExists("{$summary->uuid}.jpg");
    Storage::disk(FetchCover::DISK)->assertMissing("{$summary->video_id}.jpg");

    expect(Storage::disk(FetchCover::DISK)->get($summary->file_name))->toBe(COVER_BYTES);
});

/*
 * The ordinary reason the ladder exists. An older upload was never given a 1280x720 thumbnail,
 * so the size this really wants is a 404 and the next one down is the answer.
 */
test('a missing size falls through to the next one down', function (string $size, int $expectedRequests): void {
    fakeCover($size);

    $summary = summaryForCover();

    expect(app(FetchCover::class)->execute($summary))->toBeTrue();

    Storage::disk(FetchCover::DISK)->assertExists($summary->file_name);

    Http::assertSentCount($expectedRequests);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/{$size}.jpg"));
})->with([
    'the largest' => ['maxresdefault', 1],
    'the middle one' => ['sddefault', 2],
    'the one YouTube always has' => ['hqdefault', 3],
]);

test('a video with no thumbnail at any size writes nothing and says so', function (): void {
    Log::spy();

    fakeCover(null);

    $summary = summaryForCover();

    expect(app(FetchCover::class)->execute($summary))->toBeFalse();

    Storage::disk(FetchCover::DISK)->assertMissing($summary->file_name);

    /* Every rung tried before giving up, so a video really has nothing rather than one size. */
    Http::assertSentCount(3);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'no cover image'));
});

/*
 * A 200 carrying nothing is not a cover, and this is the case worth being strict about.
 *
 * Writing it would leave a zero byte file, and the guard in FindVideo asks whether a file is
 * there rather than whether it is any good - so that file would be taken for a cover already
 * fetched by every later run, and the page would show a broken image for good. There is no
 * retry that recovers from it, which is why the body is checked and not only the status.
 */
test('an empty body is not taken for a cover', function (): void {
    Http::fake([
        'i.ytimg.com/vi/*/maxresdefault.jpg' => Http::response(''),
        'i.ytimg.com/vi/*/sddefault.jpg' => Http::response(COVER_BYTES),
        'i.ytimg.com/*' => Http::response(status: 404),
    ]);

    $summary = summaryForCover();

    expect(app(FetchCover::class)->execute($summary))->toBeTrue()
        ->and(Storage::disk(FetchCover::DISK)->get($summary->file_name))->toBe(COVER_BYTES);
});

/*
 * Nothing throws out of here, in the same way and for the same reason as LookupVideo next door:
 * every fault comes back as a value, because what a missing cover should cost is the caller's
 * decision rather than the transport's.
 */
test('a host that will not answer is reported rather than thrown', function (): void {
    Log::spy();

    Http::fake(['i.ytimg.com/*' => Http::failedConnection('Could not resolve host')]);

    $summary = summaryForCover();

    expect(app(FetchCover::class)->execute($summary))->toBeFalse();

    Storage::disk(FetchCover::DISK)->assertMissing($summary->file_name);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'Could not reach YouTube'));
});

/*
 * Every size is on one host, so a host that is not answering will not answer the other two
 * either. Trying them anyway would spend three timeouts of step one's budget to learn the same
 * thing once, which is why the catch is around the whole ladder rather than around each rung.
 */
test('a host that will not answer is not asked for the smaller sizes', function (): void {
    Http::fake(['i.ytimg.com/*' => Http::failedConnection()]);

    app(FetchCover::class)->execute(summaryForCover());

    Http::assertSentCount(1);
});
