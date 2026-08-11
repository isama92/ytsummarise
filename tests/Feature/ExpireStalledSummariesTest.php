<?php

declare(strict_types=1);

use App\Console\Commands\ExpireStalledSummaries;
use App\Enums\SummaryStatus;
use App\Models\Summary;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * The backstop for every way a job can stop existing without failing: a worker killed
 * mid job, a flushed queue table, a dispatch dropped because the uniqueness lock was
 * still held by a job that had already died. A job that never runs never calls failed(),
 * so without this the row stays pending and the page waits on it forever.
 */
test('a summary pending longer than a video is given is written off', function (): void {
    Log::spy();

    $stalled = Summary::factory()->stalled()->create();
    $waiting = Summary::factory()->pending()->create();
    $finished = Summary::factory()->create();

    $this->artisan('summaries:expire-stalled')->assertSuccessful();

    expect($stalled->fresh()?->status)->toBe(SummaryStatus::Failed)
        ->and($waiting->fresh()?->status)->toBe(SummaryStatus::Pending)
        ->and($finished->fresh()?->status)->toBe(SummaryStatus::Ready);

    Log::shouldHaveReceived('warning')->once();
});

test('a summary that has only just been asked for is left alone', function (): void {
    $summary = Summary::factory()->pending()->create();

    $this->artisan('summaries:expire-stalled')
        ->expectsOutputToContain('No stalled summaries.')
        ->assertSuccessful();

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Pending);
});

test('the timeout is what decides, so shortening it expires more', function (): void {
    Log::spy();

    $summary = Summary::factory()->pending()->create([
        'requested_at' => now()->subMinutes(2),
    ]);

    $this->artisan('summaries:expire-stalled')->assertSuccessful();

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Pending);

    config(['summaries.timeout' => 60]);

    $this->artisan('summaries:expire-stalled')->assertSuccessful();

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Failed);
});

/*
 * The window between deciding a row is stalled and writing it off. Simulated by finishing
 * the row after the command has read it, which is what the guard on the update covers:
 * without it the row ends up failed with a finished summary still attached, and the page
 * says "did not work" over an answer that exists.
 */
test('a summary that finishes while being written off keeps its summary', function (): void {
    Log::spy();

    $summary = Summary::factory()->stalled()->create();
    $raced = false;

    /*
     * Finish the row the moment the command has read it, which lands the change in the
     * window between its select and its update - the only place this can go wrong. The
     * flag keeps the listener from reacting to its own queries.
     */
    DB::listen(function (QueryExecuted $query) use ($summary, &$raced): void {
        if ($raced || ! str_starts_with(strtolower(trim($query->sql)), 'select')) {
            return;
        }

        if (! str_contains($query->sql, 'summaries')) {
            return;
        }

        $raced = true;

        Summary::query()->whereKey($summary->getKey())->update([
            'status' => SummaryStatus::Ready,
            'body' => 'Arrived at the last moment.',
        ]);
    });

    $this->artisan('summaries:expire-stalled')->assertSuccessful();

    expect($raced)->toBeTrue()
        ->and($summary->fresh()?->status)->toBe(SummaryStatus::Ready)
        ->and($summary->fresh()?->body)->toBe('Arrived at the last moment.');
});

/*
 * The command only helps if something runs it.
 */
test('the command is scheduled', function (): void {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn (object $event): string => (string) $event->command);

    expect($commands->filter(fn (string $command): bool => str_contains($command, 'summaries:expire-stalled')))
        ->not->toBeEmpty();
});

test('the expiry command has a signature that matches what is scheduled', function (): void {
    expect((new ExpireStalledSummaries)->getName())->toBe('summaries:expire-stalled');
});
