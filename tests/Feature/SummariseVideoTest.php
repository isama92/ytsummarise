<?php

declare(strict_types=1);

use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

test('the job writes a summary and marks it ready', function (): void {
    Sleep::fake();

    $summary = Summary::factory()->pending()->create();

    (new SummariseVideo($summary))->handle();

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->body)->not->toBeEmpty()
        /* And records when it began, which is what the timeout is measured against. */
        ->and($summary->started_at)->not->toBeNull();
});

/*
 * The guarantee, and the reason it is a conditional update rather than a lock: two jobs for
 * the same video can exist however carefully the lock is sized, because its TTL starts when a
 * job is dispatched and a job that waited in a queue can outlive it. Only one of them may
 * pay for the model call.
 */
test('a second job for a video somebody is already working on does nothing', function (): void {
    Sleep::fake();

    $claimedAt = Date::now()->subMinute();
    $summary = Summary::factory()->processing()->create(['started_at' => $claimedAt]);

    (new SummariseVideo($summary))->handle();

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->body)->toBeNull()
        /* Not re-stamped either: the claim belongs to whoever took it. */
        ->and($summary->started_at?->timestamp)->toBe($claimedAt->timestamp);

    Sleep::assertNeverSlept();
});

/*
 * Two jobs handed the row as a worker would find it, which is the case worth pinning: two
 * separate model instances, each loaded before either ran, exactly as two workers
 * deserialising the same payload would have them.
 *
 * The first version of this test reused one instance for both calls. The first handle()
 * mutated it to ready in memory, so the second returned at the already-ready guard and never
 * reached the claim at all - it passed with the claim deleted outright, which makes it worse
 * than no test.
 */
test('the first of two jobs pays and the second does not', function (): void {
    Sleep::fake();

    $summary = Summary::factory()->pending()->create();

    $first = new SummariseVideo(Summary::query()->findOrFail($summary->getKey()));
    $second = new SummariseVideo(Summary::query()->findOrFail($summary->getKey()));

    $first->handle();
    $second->handle();

    /* One sleep stands for one model call, so one summary was paid for. */
    Sleep::assertSleptTimes(1);

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Ready);
});

test('the job stands in for the latency of the model call', function (): void {
    Sleep::fake();

    (new SummariseVideo(Summary::factory()->pending()->create()))->handle();

    Sleep::assertSlept(
        fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 3,
    );
});

test('a job that gives up records the failure, so the page stops waiting', function (): void {
    Log::spy();

    $summary = Summary::factory()->pending()->create();

    (new SummariseVideo($summary))->failed(new RuntimeException('no transcript'));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->body)->toBeNull();

    Log::shouldHaveReceived('error')->once();
});

/*
 * handle() can succeed and the worker still die before it deletes the job, leaving a
 * later attempt free to throw. Marking the row failed then would hide a finished summary
 * behind a "did not work" message.
 */
test('a late failure does not throw away a summary that already finished', function (): void {
    Log::spy();

    $summary = Summary::factory()->create(['body' => 'The finished summary.']);

    (new SummariseVideo($summary))->failed(new RuntimeException('worker died after writing'));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->body)->toBe('The finished summary.');

    Log::shouldHaveReceived('error')->once();
});

/*
 * Reading the configured timeout rather than setting one. Pinning it made this pass for a
 * value nobody deploys: with SUMMARY_TIMEOUT=3600 the real retry_after was below the real
 * timeout and the assertion still held, which is the paid double-summarisation this test
 * exists to prevent.
 */
test('the queue cannot reserve the job again while it is still running', function (): void {
    $job = new SummariseVideo(Summary::factory()->pending()->create());
    $timeout = config()->integer('summaries.timeout');

    expect($job->timeout)->toBe($timeout)
        ->and($job->connection)->toBe('summaries')
        ->and(config()->integer('queue.connections.summaries.retry_after'))
        ->toBeGreaterThan($timeout)
        /*
         * And the default connection is left where Laravel puts it, so a future job does
         * not silently inherit half an hour of stall after a worker dies.
         */
        ->and(config()->integer('queue.connections.database.retry_after'))
        ->toBe(90);
});

/*
 * One attempt is what keeps the lock, the timeout and the expiry horizon equal. More
 * attempts and the worst case life of a job is tries × timeout plus backoff, which
 * outlasts the lock, and a submission in that window queues a second paid summary of the
 * same video.
 */
test('the uniqueness lock lasts exactly as long as the one attempt it guards', function (): void {
    $job = new SummariseVideo(Summary::factory()->pending()->create());

    expect($job->tries)->toBe(1)
        ->and($job->uniqueFor)->toBe($job->timeout)
        ->and($job->uniqueFor)->toBe(config()->integer('summaries.timeout'));
});

/*
 * A worker killed between finishing and deleting the job leaves it to be reserved again.
 * Running it twice would pay for the model call twice and rewrite a summary somebody is
 * already reading.
 */
test('a job delivered twice does not summarise twice', function (): void {
    Sleep::fake();

    $summary = Summary::factory()->create(['body' => 'The finished summary.']);

    (new SummariseVideo($summary))->handle();

    expect($summary->fresh()?->body)->toBe('The finished summary.');

    Sleep::assertNeverSlept();
});

test('one job is in flight per video, not per request', function (): void {
    $summary = Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']);

    expect((new SummariseVideo($summary))->uniqueId())->toBe('dQw4w9WgXcQ');
});
