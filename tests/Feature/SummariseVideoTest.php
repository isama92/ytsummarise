<?php

declare(strict_types=1);

use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

test('the job writes a summary and marks it ready', function (): void {
    Sleep::fake();

    $summary = Summary::factory()->pending()->create();

    (new SummariseVideo($summary))->handle();

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->body)->not->toBeEmpty();
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

test('the job cannot outlive the timeout, and neither can its lock', function (): void {
    config(['summaries.timeout' => 1800]);

    $job = new SummariseVideo(Summary::factory()->pending()->create());

    expect($job->timeout)->toBe(1800)
        ->and($job->uniqueFor)->toBe(1800)
        /*
         * A retry_after below the job's timeout has the worker reserve the job again while
         * the first copy is still running, and summarising a video twice is a paid mistake.
         */
        ->and(config()->integer('queue.connections.database.retry_after'))
        ->toBeGreaterThan($job->timeout);
});

test('one job is in flight per video, not per request', function (): void {
    $summary = Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']);

    expect((new SummariseVideo($summary))->uniqueId())->toBe('dQw4w9WgXcQ');
});
