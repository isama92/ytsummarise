<?php

declare(strict_types=1);

use App\Console\Commands\RecoverStalledSummaries;
use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * A job that never runs never calls failed(), so without this a row stays pending and the
 * page waits on it forever. Two ways that happens, wanting opposite treatment.
 */
test('a summary a worker abandoned is written off', function (): void {
    Log::spy();
    Queue::fake();

    $abandoned = Summary::factory()->stalled()->create();
    $working = Summary::factory()->processing()->create();
    $finished = Summary::factory()->create();

    $this->artisan('summaries:recover')->assertSuccessful();

    expect($abandoned->fresh()?->status)->toBe(SummaryStatus::Failed)
        ->and($working->fresh()?->status)->toBe(SummaryStatus::Pending)
        ->and($finished->fresh()?->status)->toBe(SummaryStatus::Ready);

    Log::shouldHaveReceived('warning')->once();
});

/*
 * The bug this whole change exists for. A row whose job is queued behind a long one has no
 * started_at and any age at all, and used to be written off for it - telling somebody it did
 * not work while its job sat in the queue about to run.
 */
test('a summary waiting its turn is queued again rather than written off', function (string $age): void {
    Queue::fake();

    $waiting = Summary::factory()->pending()->create([
        'requested_at' => Date::now()->sub($age),
    ]);

    $this->artisan('summaries:recover')->assertSuccessful();

    expect($waiting->fresh()?->status)->toBe(SummaryStatus::Pending);

    Queue::assertPushed(SummariseVideo::class, 1);
})->with([
    /*
     * All far past the timeout, which is what the old horizon compared against and what made
     * every one of these a write-off, and all inside the far longer window a queue is given
     * to start something at all.
     */
    'asked for a moment ago' => '5 seconds',
    'asked for an hour ago' => '1 hour',
    'asked for most of a day' => '20 hours',
]);

/*
 * The bound on the rule above. Queueing a waiting summary again is right for as long as
 * there is reason to think a worker will get to it, and a day of a queue never once starting
 * this job is not a busy queue - it is one that is not running, and somebody should be told
 * rather than left watching a spinner all week.
 */
test('a summary nothing ever started is eventually written off', function (): void {
    Log::spy();
    Queue::fake();

    $waiting = Summary::factory()->pending()->create([
        'requested_at' => Date::now()->subSeconds(config()->integer('summaries.abandon_after') + 1),
    ]);

    $this->artisan('summaries:recover')->assertSuccessful();

    expect($waiting->fresh()?->status)->toBe(SummaryStatus::Failed);

    /* And not queued again on the way out. */
    Queue::assertNothingPushed();

    Log::shouldHaveReceived('warning')->once();
});

test('the two horizons are separate, and waiting is given far longer than working', function (): void {
    expect(config()->integer('summaries.abandon_after'))
        ->toBeGreaterThan(config()->integer('summaries.timeout'));

    Queue::fake();

    /*
     * Past the working horizon but nowhere near the waiting one: still queued again, because
     * how long a job may take says nothing about how long it may wait for a worker.
     */
    $waiting = Summary::factory()->pending()->create([
        'requested_at' => Date::now()->subSeconds(config()->integer('summaries.timeout') + 1),
    ]);

    $this->artisan('summaries:recover')->assertSuccessful();

    expect($waiting->fresh()?->status)->toBe(SummaryStatus::Pending);

    Queue::assertPushed(SummariseVideo::class, 1);
});

test('a summary a worker is still working on is left alone entirely', function (): void {
    Queue::fake();

    $working = Summary::factory()->processing()->create();

    $this->artisan('summaries:recover')
        ->expectsOutputToContain('Nothing to recover.')
        ->assertSuccessful();

    expect($working->fresh()?->status)->toBe(SummaryStatus::Pending);

    /* Not queued again: somebody has it, and a second job would only bounce off the claim. */
    Queue::assertNothingPushed();
});

/*
 * The horizon runs from when the work started, not from when it was asked for. A row asked
 * for long ago but claimed a moment ago is being worked on right now.
 */
test('the horizon is measured from when the work started', function (): void {
    Log::spy();
    Queue::fake();

    $summary = Summary::factory()->processing()->create([
        'requested_at' => Date::now()->subWeek(),
        'started_at' => Date::now(),
    ]);

    $this->artisan('summaries:recover')->assertSuccessful();

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Pending);

    /* And once the work itself has run long, it is written off. */
    config(['summaries.timeout' => 0]);

    $this->artisan('summaries:recover')->assertSuccessful();

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Failed);
});

/*
 * The window between deciding a row is abandoned and writing it off. Simulated by finishing
 * the row after the command has read it, which is what the guard on the update covers:
 * without it the row ends up failed with a finished summary still attached, and the page
 * says "did not work" over an answer that exists.
 */
test('a summary that finishes while being written off keeps its summary', function (): void {
    Log::spy();
    Queue::fake();

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

    /*
     * And it says so. The count comes from what the update changed rather than from what
     * was selected a moment earlier, so a run that leaves everything alone reports nothing
     * rather than claiming rows it deliberately did not touch.
     */
    $this->artisan('summaries:recover')
        ->expectsOutputToContain('Nothing to recover.')
        ->assertSuccessful();

    expect($raced)->toBeTrue()
        ->and($summary->fresh()?->status)->toBe(SummaryStatus::Ready)
        ->and($summary->fresh()?->body)->toBe('Arrived at the last moment.');

    Log::shouldNotHaveReceived('warning');
});

/*
 * The command only helps if something runs it, and how often it runs is how long a page
 * waiting on an abandoned job keeps waiting. Hourly means up to the timeout plus an hour,
 * which is a deliberate trade against waking a process every minute forever; changing the
 * cadence changes that, so it is pinned rather than left to a comment.
 */
test('the command is scheduled hourly', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (object $event): bool => str_contains((string) $event->command, 'summaries:recover'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});

test('the command has a signature that matches what is scheduled', function (): void {
    expect((new RecoverStalledSummaries)->getName())->toBe('summaries:recover');
});
