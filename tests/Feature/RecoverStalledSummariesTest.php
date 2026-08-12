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

    $waiting = Summary::factory()->neverStarted()->create();

    $this->artisan('summaries:recover')->assertSuccessful();

    expect($waiting->fresh()?->status)->toBe(SummaryStatus::Failed);

    /*
     * And not queued again on the way out. Both halves of this run over rows nothing has
     * started, so a set that did not exclude these dispatched a job for the row it was
     * about to fail - and because the write-off leaves started_at null, that job went on
     * to claim the row and finish it, undoing the write-off within the minute.
     */
    Queue::assertNothingPushed();

    Log::shouldHaveReceived('warning')->once();
});

/*
 * Which is guaranteed by the sets rather than by the order the command works through them:
 * the two are complements, so no row can be in both however they are visited.
 */
test('no summary is both worth queueing again and past waiting for', function (): void {
    Summary::factory()->pending()->create(['requested_at' => Date::now()]);
    Summary::factory()->neverStarted()->create();
    Summary::factory()->processing()->create();
    Summary::factory()->stalled()->create();
    Summary::factory()->create();

    $queueAgain = Summary::query()->awaitingWorker()->pluck('id');
    $giveUpOn = Summary::query()->neverStarted()->pluck('id');

    expect($queueAgain)->toHaveCount(1)
        ->and($giveUpOn)->toHaveCount(1)
        ->and($queueAgain->intersect($giveUpOn))->toBeEmpty()
        /* And between them they are the whole unclaimed set: the split loses nobody. */
        ->and($queueAgain->merge($giveUpOn)->sort()->values()->all())
        ->toBe(Summary::query()->unclaimed()->pluck('id')->sort()->values()->all());
});

/*
 * Which the spacing on the requeue must not disturb, and is why it is a scope on top rather
 * than a condition folded into awaitingWorker. Holding a row back for an hour says nothing
 * about whether it is one to give up on, and a row that fell out of both sets would wait
 * forever without anybody ever being told.
 */
test('holding a summary back from the queue does not make it one to give up on', function (): void {
    $requeued = Summary::factory()->requeued()->create();

    expect(Summary::query()->dueForRequeue()->count())->toBe(0)
        ->and(Summary::query()->awaitingWorker()->pluck('id')->all())->toBe([$requeued->id])
        ->and(Summary::query()->neverStarted()->count())->toBe(0);
});

/*
 * The command cannot tell a job waiting its turn from one that no longer exists, so it
 * queues again and lets the claim make a duplicate harmless. Doing that every hour to every
 * waiting summary is what made an outage expensive to come back from: hourly runs against a
 * lock lapsing in half an hour left a day's worth of duplicates per row to drain.
 */
test('a summary queued again recently is left alone until the spacing has passed', function (): void {
    Queue::fake();

    $requeued = Summary::factory()->requeued()->create();

    $this->artisan('summaries:recover')
        ->expectsOutputToContain('Nothing to recover.')
        ->assertSuccessful();

    Queue::assertNothingPushed();

    /* Still waiting, and still nobody's problem to write off. */
    expect($requeued->fresh()?->status)->toBe(SummaryStatus::Pending);
});

test('a summary queued again long enough ago is queued again', function (): void {
    Queue::fake();

    $requeued = Summary::factory()->requeued()->create([
        'requeued_at' => Date::now()->subSeconds(config()->integer('summaries.requeue_after') + 1),
    ]);

    $this->artisan('summaries:recover')->assertSuccessful();

    Queue::assertPushed(SummariseVideo::class, 1);

    /* And the clock on the spacing starts again with it. */
    expect($requeued->fresh()?->requeued_at?->diffInSeconds(Date::now(), true))->toBeLessThan(5);
});

/*
 * The first one is prompt, whatever the spacing says: a job lost with its queue is repaired
 * at the next run rather than hours later, and only the repetition after that is spaced out.
 */
test('a summary nobody has queued again yet is queued again at once, and it is recorded', function (): void {
    Queue::fake();

    $waiting = Summary::factory()->pending()->create();

    expect($waiting->requeued_at)->toBeNull();

    $this->artisan('summaries:recover')->assertSuccessful();

    Queue::assertPushed(SummariseVideo::class, 1);

    expect($waiting->fresh()?->requeued_at)->not->toBeNull();
});

/*
 * The other side of the same window as the test further down, and the more expensive one to
 * get wrong. A row nothing had started when the command read it can be claimed by a worker
 * a moment later, and failing it then tells the page "did not work" while a paid call is
 * running - then the page offers a retry, the controller clears the claim because the row
 * is failed, and a second job summarises a video already being summarised.
 */
test('a summary claimed while being written off is left to the worker that claimed it', function (): void {
    Log::spy();
    Queue::fake();

    $summary = Summary::factory()->neverStarted()->create();
    $raced = false;

    /* The select that reads video_id for rows nothing has started; see the note below. */
    DB::listen(function (QueryExecuted $query) use ($summary, &$raced): void {
        if ($raced || ! str_contains($query->sql, 'video_id') || ! str_contains($query->sql, 'started_at" is null')) {
            return;
        }

        $raced = true;

        Summary::query()->whereKey($summary->getKey())->update(['started_at' => Date::now()]);
    });

    $this->artisan('summaries:recover')
        ->expectsOutputToContain('Nothing to recover.')
        ->assertSuccessful();

    $summary->refresh();

    expect($raced)->toBeTrue()
        ->and($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->started_at)->not->toBeNull();

    Log::shouldNotHaveReceived('warning');
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
     * event fires once the rows have been fetched, so a listener on the right select is
     * exactly that window rather than an approximation of it.
     *
     * The right select is the one that reads video_id for rows a worker abandoned, which
     * is what the started_at test picks out: the command runs three queries over this
     * table, and reacting to the first of them would land the change before the write-off
     * had even read the row, leaving the guard on the update untested.
     */
    DB::listen(function (QueryExecuted $query) use ($summary, &$raced): void {
        if ($raced || ! str_contains($query->sql, 'video_id') || ! str_contains($query->sql, 'started_at" is not null')) {
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
