<?php

declare(strict_types=1);

use App\Console\Commands\ExpireStalledSummaries;
use App\Enums\SummaryStatus;
use App\Models\Summary;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * A job that never runs never calls failed(), so without this a row stays pending and the
 * page waits on it forever.
 */
test('a summary pending too long is written off', function (): void {
    Log::spy();

    $stale = Summary::factory()->stale()->create();
    $waiting = Summary::factory()->pending()->create();
    $finished = Summary::factory()->create();

    $this->artisan('summaries:expire')->assertSuccessful();

    expect($stale->fresh()?->status)->toBe(SummaryStatus::Failed)
        ->and($waiting->fresh()?->status)->toBe(SummaryStatus::Pending)
        ->and($finished->fresh()?->status)->toBe(SummaryStatus::Ready);

    Log::shouldHaveReceived('warning')->once();
});

/*
 * And nothing is queued again on the way out. The page says it did not work and offers to
 * try once more, and it is a person who decides to - a command guessing on their behalf is
 * what this whole thing used to be, and every guess it made was between a job waiting its
 * turn and a job that no longer existed, which look identical from here.
 */
test('a summary written off is not queued again', function (): void {
    Log::spy();
    Queue::fake();

    Summary::factory()->stale()->create();

    $this->artisan('summaries:expire')->assertSuccessful();

    Queue::assertNothingPushed();
});

/*
 * The horizon runs from when the attempt was asked for, and every attempt sets that again,
 * so a row is only ever measured on the attempt in flight.
 */
test('a summary still inside the horizon is left alone', function (string $age): void {
    $waiting = Summary::factory()->pending()->create([
        'requested_at' => Date::now()->sub($age),
    ]);

    $this->artisan('summaries:expire')
        ->expectsOutputToContain('Nothing to expire.')
        ->assertSuccessful();

    expect($waiting->fresh()?->status)->toBe(SummaryStatus::Pending);
})->with([
    'asked for a moment ago' => '5 seconds',
    /* Past the time the work itself is given, which is a different question entirely. */
    'asked for longer than the work gets' => '31 minutes',
    'asked for most of the horizon' => '5 hours',
]);

test('a summary a worker is working on inside the horizon is left alone', function (): void {
    $working = Summary::factory()->processing()->create();

    $this->artisan('summaries:expire')
        ->expectsOutputToContain('Nothing to expire.')
        ->assertSuccessful();

    expect($working->fresh()?->status)->toBe(SummaryStatus::Pending)
        ->and($working->fresh()?->started_at)->not->toBeNull();
});

/*
 * Deliberately blunt about a row a worker did claim: the horizon asks whether the attempt is
 * alive, not whether anybody ever picked it up, and a claim made five hours into a six hour
 * wait does not buy the attempt more time. What stops that costing anything is the status
 * guard in the job, which is covered in SummariseVideoTest.
 */
test('a claim does not exempt an attempt that has been pending too long', function (): void {
    Log::spy();

    $claimed = Summary::factory()->stale()->create([
        'started_at' => Date::now()->subMinute(),
    ]);

    $this->artisan('summaries:expire')->assertSuccessful();

    expect($claimed->fresh()?->status)->toBe(SummaryStatus::Failed)
        /* Left where it was: this only changes the status. */
        ->and($claimed->fresh()?->started_at)->not->toBeNull();
});

test('a summary that already failed is not written off again', function (): void {
    Log::spy();

    Summary::factory()->failed()->create([
        'requested_at' => Date::now()->subDay(),
    ]);

    $this->artisan('summaries:expire')
        ->expectsOutputToContain('Nothing to expire.')
        ->assertSuccessful();

    Log::shouldNotHaveReceived('warning');
});

/*
 * The window between deciding a row is stale and writing it off. Simulated by finishing the
 * row after the command has read it, which is what re-applying the scope in the update
 * covers: without it the row ends up failed with a finished summary still attached, and the
 * page says "did not work" over an answer that exists.
 */
test('a summary that finishes while being written off keeps its summary', function (): void {
    Log::spy();

    $summary = Summary::factory()->stale()->create();
    $raced = false;

    /*
     * Finish the row the moment the command has read it. The event fires once the rows have
     * been fetched, so a listener on the command's only select over this table lands the
     * change in the window between its select and its update, which is the one place this
     * can go wrong. The flag keeps the listener from reacting to its own queries.
     */
    DB::listen(function (QueryExecuted $query) use ($summary, &$raced): void {
        if ($raced || ! str_contains($query->sql, 'video_id')) {
            return;
        }

        $raced = true;

        Summary::query()->whereKey($summary->getKey())->update([
            'status' => SummaryStatus::Ready,
            'body' => 'Arrived at the last moment.',
        ]);
    });

    /*
     * And it says so. The count comes from what the update changed rather than from what was
     * selected a moment earlier, so a run that leaves everything alone reports nothing rather
     * than claiming rows it deliberately did not touch.
     */
    $this->artisan('summaries:expire')
        ->expectsOutputToContain('Nothing to expire.')
        ->assertSuccessful();

    expect($raced)->toBeTrue()
        ->and($summary->fresh()?->status)->toBe(SummaryStatus::Ready)
        ->and($summary->fresh()?->body)->toBe('Arrived at the last moment.');

    Log::shouldNotHaveReceived('warning');
});

/*
 * The command only helps if something runs it, and how often it runs is how long a page
 * waiting on a dead job keeps waiting. Hourly means up to the horizon plus an hour, which is
 * a deliberate trade against waking a process every minute forever; changing the cadence
 * changes that, so it is pinned rather than left to a comment.
 */
test('the command is scheduled hourly', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (object $event): bool => str_contains((string) $event->command, 'summaries:expire'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});

test('the command has a signature that matches what is scheduled', function (): void {
    expect((new ExpireStalledSummaries)->getName())->toBe('summaries:expire');
});

/*
 * Two horizons doing different jobs, and the reason they are separate values. How long the
 * work itself gets says nothing about how long an attempt may sit waiting for a worker, and
 * an attempt cannot be given up on sooner than the work is allowed to take.
 */
test('the horizon is longer than the time the work itself gets', function (): void {
    expect(config()->integer('summaries.stale_after'))
        ->toBeGreaterThan(config()->integer('summaries.timeout'));
});
