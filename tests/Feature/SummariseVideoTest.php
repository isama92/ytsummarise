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

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Failed);

    Log::shouldHaveReceived('error')->once();
});

test('one job is in flight per video, not per request', function (): void {
    $summary = Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']);

    expect((new SummariseVideo($summary))->uniqueId())->toBe('dQw4w9WgXcQ');
});
