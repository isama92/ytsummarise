<?php

declare(strict_types=1);

use App\Enums\SummaryError;
use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use Carbon\CarbonInterval;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

test('the job writes a summary and marks it ready', function (): void {
    Sleep::fake();
    fakeYouTube('Never Gonna Give You Up');

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->body)->not->toBeEmpty()
        /* The title arrives with the summary rather than ahead of it. */
        ->and($summary->title)->toBe('Never Gonna Give You Up')
        ->and($summary->error)->toBeNull()
        /* And records when it began, which is what the timeout is measured against. */
        ->and($summary->started_at)->not->toBeNull();
});

/*
 * A video that does not exist, which is the whole point of looking one up: without this the
 * job cheerfully summarises eleven characters nobody can watch.
 */
test('a video that does not exist is failed rather than summarised', function (): void {
    Sleep::fake();
    Log::spy();

    Http::fake(['https://www.youtube.com/oembed*' => Http::response(status: 404)]);

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->error)->toBe(SummaryError::NotFound)
        ->and($summary->body)->toBeNull();

    /* And nothing was paid for, which is why the lookup comes before the work. */
    Sleep::assertNeverSlept();

    Log::shouldHaveReceived('info')->once();
});

/*
 * Told apart from the case above on purpose. Not knowing whether a video exists is not the
 * same as knowing it does not, and only one of the two is worth submitting again.
 */
test('a video nobody could be asked about is failed as unreachable', function (): void {
    Sleep::fake();
    Log::spy();

    Http::fake(['https://www.youtube.com/oembed*' => Http::failedConnection('timed out')]);

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->error)->toBe(SummaryError::Unreachable)
        ->and($summary->body)->toBeNull();

    Sleep::assertNeverSlept();
});

/*
 * A video that exists but will not be named. Worth summarising anyway, so the heading is what
 * is missing rather than the summary.
 */
test('a video the lookup will not name is still summarised', function (): void {
    Sleep::fake();

    Http::fake(['https://www.youtube.com/oembed*' => Http::response(status: 401)]);

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->body)->not->toBeEmpty()
        ->and($summary->title)->toBeNull()
        ->and($summary->error)->toBeNull();
});

/*
 * The expiry command's horizon is deliberately blunt, so it can write off an attempt whose
 * worker is alive and part way through. That job then finishes and writes its summary, and the
 * reason left behind has to go with it: a ready summary carrying "this took too long" is a trap
 * for anything that reads the column, starting with the page.
 */
test('a summary that finishes after being written off does not keep the reason', function (): void {
    Sleep::fake();
    fakeYouTube();

    $summary = Summary::factory()->pending()->create();

    /* Written off during the model call, which is the only moment this can happen in. */
    Sleep::whenFakingSleep(function () use ($summary): void {
        Summary::query()->whereKey($summary->getKey())->update([
            'status' => SummaryStatus::Failed,
            'error' => SummaryError::TimedOut,
        ]);
    });

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->body)->not->toBeEmpty()
        ->and($summary->error)->toBeNull();
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

    /*
     * No YouTube is faked on purpose. A job that bounces off the claim must not have looked
     * anything up, and the suite's stray request guard is what says so: reaching the lookup
     * from here throws rather than passing quietly.
     */
    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->body)->toBeNull()
        /* Not re-stamped either: the claim belongs to whoever took it. */
        ->and($summary->started_at?->timestamp)->toBe($claimedAt->timestamp);

    Sleep::assertNeverSlept();
});

/*
 * Two jobs overlapping on one row, which is the case worth pinning: only one may pay.
 *
 * The overlap is the whole test and has to be arranged deliberately. Run one after the other,
 * the row is already ready by the time the second loads it, so it stops at the status guard
 * and never reaches the claim at all - which is how the previous two versions of this test
 * both managed to pass with the claim deleted outright. Running the second inside the first's
 * model call is the only arrangement where the claim is what answers.
 */
test('the first of two jobs pays and the second does not', function (): void {
    Sleep::fake();
    fakeYouTube();

    $summary = Summary::factory()->pending()->create();

    $first = new SummariseVideo($summary->id);
    $second = new SummariseVideo($summary->id);

    /*
     * Once, or a second job that got past the claim would sleep, re-enter here and recurse
     * rather than failing. The guard costs nothing when the claim works, because the second
     * job returns without sleeping and this never fires twice anyway.
     */
    $overlapped = false;

    Sleep::whenFakingSleep(function () use ($second, &$overlapped): void {
        if ($overlapped) {
            return;
        }

        $overlapped = true;

        work($second);
    });

    work($first);

    /* One sleep stands for one model call, so one summary was paid for. */
    expect($overlapped)->toBeTrue();

    Sleep::assertSleptTimes(1);

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Ready);
});

/*
 * The other half of the status guard, and what keeps one blunt horizon from costing anything.
 * summaries:expire does not ask whether a worker ever picked a row up, so a job queued behind
 * a long enough backlog is written off while it is still perfectly alive. This is where that
 * stops: the job re-reads the status it was handed and leaves a written-off attempt alone
 * rather than paying for a summary the page has already offered to try again.
 */
test('a job whose attempt was given up on does nothing', function (): void {
    Sleep::fake();

    /* As summaries:expire leaves a row nothing ever started: failed, and never claimed. */
    $summary = Summary::factory()->failed()->create(['started_at' => null]);

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->body)->toBeNull()
        /* And not claimed on the way past, which would make the retry unworkable. */
        ->and($summary->started_at)->toBeNull();

    Sleep::assertNeverSlept();
});

/*
 * The window between reading the status and claiming the row, which is why the claim checks
 * the status again rather than trusting the read. summaries:expire can write the attempt off
 * in between, and claiming it then pays for a summary the page has already offered to retry.
 */
test('a job whose attempt is given up on while it reads the row does not claim it', function (): void {
    Sleep::fake();

    $summary = Summary::factory()->pending()->create();
    $raced = false;

    /*
     * Write the attempt off the moment the job has read it. The event fires once the rows
     * have been fetched, so a listener on the job's only select over this table lands the
     * change between that read and the claim.
     */
    DB::listen(function (QueryExecuted $query) use ($summary, &$raced): void {
        if ($raced || ! str_contains($query->sql, 'select')) {
            return;
        }

        $raced = true;

        Summary::query()->whereKey($summary->getKey())->update([
            'status' => SummaryStatus::Failed,
        ]);
    });

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($raced)->toBeTrue()
        ->and($summary->status)->toBe(SummaryStatus::Failed)
        /* Not claimed on the way past, which would make the retry unworkable. */
        ->and($summary->started_at)->toBeNull();

    Sleep::assertNeverSlept();
});

test('the job stands in for the latency of the model call', function (): void {
    Sleep::fake();
    fakeYouTube();

    work(new SummariseVideo(Summary::factory()->pending()->create()->id));

    Sleep::assertSlept(
        fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 3,
    );
});

test('a job that gives up records the failure, so the page stops waiting', function (): void {
    Log::spy();

    $summary = Summary::factory()->pending()->create();

    (new SummariseVideo($summary->id))->failed(new RuntimeException('no transcript'));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->body)->toBeNull()
        /*
         * Unknown rather than anything more specific. Whatever threw is in the log; what the
         * page needs is a sentence, and guessing a better one from an exception message would
         * put something in front of somebody that was written for a developer.
         */
        ->and($summary->error)->toBe(SummaryError::Unknown);

    Log::shouldHaveReceived('error')->once();
});

/*
 * The first explanation wins. A row written off by summaries:expire and then thrown on by the
 * job it was waiting for is still, most usefully, a row that took too long.
 */
test('a failure does not overwrite a reason the row already had', function (): void {
    Log::spy();

    $summary = Summary::factory()->failed()->create(['error' => SummaryError::TimedOut]);

    (new SummariseVideo($summary->id))->failed(new RuntimeException('no transcript'));

    expect($summary->fresh()?->error)->toBe(SummaryError::TimedOut);

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

    (new SummariseVideo($summary->id))->failed(new RuntimeException('worker died after writing'));

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
    $job = new SummariseVideo(Summary::factory()->pending()->create()->id);
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
    $job = new SummariseVideo(Summary::factory()->pending()->create()->id);

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

    work(new SummariseVideo($summary->id));

    expect($summary->fresh()?->body)->toBe('The finished summary.');

    Sleep::assertNeverSlept();
});

/*
 * Keyed on the row rather than on the video code, which is only safe because they are the
 * same key under two names. The second half of this is what makes the first half true, so it
 * is asserted rather than trusted: lose the unique index on video_id and a video can have two
 * rows, two ids, and two jobs paying for it at once.
 */
test('one job is in flight per video, not per request', function (): void {
    $summary = Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']);

    expect((new SummariseVideo($summary->id))->uniqueId())->toBe((string) $summary->id)
        ->and(fn (): Summary => Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']))->toThrow(QueryException::class);
});
