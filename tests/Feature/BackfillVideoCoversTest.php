<?php

declare(strict_types=1);

use App\Models\Summary;
use App\Services\YouTube\Actions\FetchCover;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * The one-off command that catches the rows summarised before covers existed.
 *
 * Temporary by design, so what is worth pinning is the part that makes it safe to run on a live
 * installation: it works out what to do by looking on the disk, it does not fail a deploy over a
 * thumbnail, and running it twice is not running it twice.
 */

beforeEach(function (): void {
    /*
     * The command's own pause between downloads, which is real time and would otherwise be
     * charged to the suite: three rows is over half a second of it. Faked here rather than in
     * each test because every test in this file runs the command.
     */
    Sleep::fake();
});

test('a summary with no cover gets one', function (): void {
    fakeCover();

    $summary = Summary::factory()->create();

    $this->artisan('summaries:backfill-covers')
        ->expectsOutputToContain('Fetched 1')
        ->assertSuccessful();

    Storage::disk(FetchCover::DISK)->assertExists($summary->file_name);
});

/*
 * What makes this safe to run again, and safe to interrupt. Whether a row needs anything is
 * answered by looking on the disk rather than by a column or a marker, so a run that stopped
 * half way picks up where it left off.
 */
test('a summary that already has a cover is left alone', function (): void {
    fakeCover();

    $summary = Summary::factory()->create();

    Storage::disk(FetchCover::DISK)->put($summary->file_name, 'the cover it already had');

    $this->artisan('summaries:backfill-covers')
        ->expectsOutputToContain('already had 1')
        ->assertSuccessful();

    Http::assertNothingSent();

    expect(Storage::disk(FetchCover::DISK)->get($summary->file_name))
        ->toBe('the cover it already had');
});

test('running it a second time fetches nothing', function (): void {
    fakeCover();

    Summary::factory()->count(3)->create();

    $this->artisan('summaries:backfill-covers')->assertSuccessful();

    Http::assertSentCount(3);

    $this->artisan('summaries:backfill-covers')
        ->expectsOutputToContain('Fetched 0, already had 3')
        ->assertSuccessful();

    /* Still three: the second run asked YouTube nothing at all. */
    Http::assertSentCount(3);
});

/*
 * A video taken down since it was summarised has no thumbnail left to fetch, and the summary of
 * it is still perfectly readable. Failing the command would fail a deploy over that.
 */
test('a cover that cannot be fetched is counted rather than fatal', function (): void {
    fakeCover(null);

    Summary::factory()->create();

    $this->artisan('summaries:backfill-covers')
        ->expectsOutputToContain('could not fetch 1')
        ->assertSuccessful();
});

test('a mix of rows is reported as one line', function (): void {
    fakeCover();

    $already = Summary::factory()->create();
    Summary::factory()->count(2)->create();

    Storage::disk(FetchCover::DISK)->put($already->file_name, 'the cover it already had');

    $this->artisan('summaries:backfill-covers')
        ->expectsOutputToContain('Fetched 2, already had 1, could not fetch 0.')
        ->assertSuccessful();
});

test('an empty database is said out loud rather than passed over', function (): void {
    $this->artisan('summaries:backfill-covers')
        ->expectsOutputToContain('no summaries')
        ->assertSuccessful();

    Http::assertNothingSent();
});

/*
 * Deliberately not scheduled. A command whose whole purpose is to run once should not be in
 * routes/console.php, where it would go on asking YouTube about the same rows nightly for as
 * long as nobody noticed.
 */
test('the backfill is not scheduled', function (): void {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn (Event $event): string => $event->command ?? '');

    expect($commands->filter(fn (string $command): bool => str_contains($command, 'backfill-covers')))
        ->toBeEmpty();
});
