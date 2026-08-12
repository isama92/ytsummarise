<?php

declare(strict_types=1);

use App\Console\Commands\PruneSummaries;
use App\Models\Summary;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * A summary last asked for a given number of days ago.
 *
 * requested_at rather than created_at, which is what the window is measured from: the question
 * is when somebody last wanted this video, not how long the row has existed.
 */
function summaryRequestedDaysAgo(int $days): Summary
{
    return Summary::factory()->create(['requested_at' => Date::now()->subDays($days)]);
}

test('a summary past the retention window is deleted', function (): void {
    Log::spy();

    $old = summaryRequestedDaysAgo(config()->integer('summaries.retention_days') + 1);
    $recent = summaryRequestedDaysAgo(1);

    $this->artisan('summaries:prune')
        ->expectsOutputToContain('Pruned 1 summary')
        ->assertSuccessful();

    expect(Summary::query()->find($old->id))->toBeNull()
        ->and(Summary::query()->find($recent->id))->not->toBeNull();

    Log::shouldHaveReceived('info')->once();
});

/*
 * The transcript is the reason this command exists: a recording of somebody speaking, written
 * down and kept by us. Asserted separately from the row so that nulling the column and leaving
 * the row would not pass this by accident.
 */
test('the transcript goes with it', function (): void {
    summaryRequestedDaysAgo(config()->integer('summaries.retention_days') + 1);

    expect(Summary::query()->whereNotNull('transcript')->count())->toBe(1);

    $this->artisan('summaries:prune')->assertSuccessful();

    expect(Summary::query()->whereNotNull('transcript')->count())->toBe(0);
});

/*
 * The boundary, both sides. A summary exactly at the window is old enough - a week is a week -
 * and one an hour inside it is not.
 */
test('the window is where it says it is', function (int $days, bool $deleted): void {
    summaryRequestedDaysAgo($days);

    $this->artisan('summaries:prune')->assertSuccessful();

    expect(Summary::query()->count())->toBe($deleted ? 0 : 1);
})->with([
    'a day old' => [1, false],
    'a day inside the window' => [6, false],
    'exactly at the window' => [7, true],
    'well past it' => [30, true],
]);

/*
 * The window asks when a video was last wanted rather than how long its row has existed, so
 * asking for it again renews it. Somebody who comes back to a summary after six days keeps it
 * for another week, which is the point of measuring from requested_at.
 */
test('asking for a video again renews its retention', function (): void {
    $summary = summaryRequestedDaysAgo(config()->integer('summaries.retention_days') + 1);

    /* As SummaryController does when a failed summary is submitted a second time. */
    $summary->update(['requested_at' => Date::now()]);

    $this->artisan('summaries:prune')->assertSuccessful();

    expect(Summary::query()->find($summary->id))->not->toBeNull();
});

/*
 * Nothing is exempt, including a row still pending. One old enough to be caught here was written
 * off by summaries:expire hours ago - its horizon is a fraction of this one - so a pending row
 * this old is one nothing was ever going to finish.
 */
test('an ancient pending summary is not kept out of politeness', function (): void {
    Summary::factory()->pending()->create([
        'requested_at' => Date::now()->subDays(config()->integer('summaries.retention_days') + 1),
    ]);

    $this->artisan('summaries:prune')->assertSuccessful();

    expect(Summary::query()->count())->toBe(0);
});

test('a run with nothing to prune says so and logs nothing', function (): void {
    Log::spy();

    summaryRequestedDaysAgo(1);

    $this->artisan('summaries:prune')
        ->expectsOutputToContain('Nothing to prune.')
        ->assertSuccessful();

    expect(Summary::query()->count())->toBe(1);

    Log::shouldNotHaveReceived('info');
});

/*
 * The log line counts rather than lists. This runs unattended and daily, and a line naming every
 * video anybody watched would be its own small version of the problem the command exists for.
 */
test('the log records how many went, not which', function (): void {
    Log::spy();

    $summary = summaryRequestedDaysAgo(config()->integer('summaries.retention_days') + 1);

    $this->artisan('summaries:prune')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(
        function (string $message, array $context) use ($summary): bool {
            expect(json_encode($context))->not->toContain($summary->video_id);

            return $context['deleted'] === 1
                && $context['retention_days'] === config()->integer('summaries.retention_days');
        },
    )->once();
});

/*
 * A retention window nobody enforces is a sentence in a README, so the schedule is the load
 * bearing half of this and is pinned rather than assumed.
 */
test('pruning is scheduled daily', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'summaries:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()?->expression)->toBe('0 3 * * *');
});

/*
 * Zero keeps everything, and says so. A scheduled command that runs nightly and deletes nothing
 * looks exactly like one that is working, so the run where somebody wonders why nothing is being
 * pruned should answer the question rather than send them to the configuration.
 */
test('zero switches retention off', function (): void {
    Log::spy();

    config()->set('summaries.retention_days', 0);

    summaryRequestedDaysAgo(3650);

    $this->artisan('summaries:prune')
        ->expectsOutputToContain('Retention is switched off')
        ->assertSuccessful();

    expect(Summary::query()->count())->toBe(1);

    Log::shouldNotHaveReceived('info');
});

test('zero survives the config as zero rather than being floored', function (): void {
    putenv('SUMMARY_RETENTION_DAYS=0');

    try {
        $summaries = require config_path('summaries.php');
    } finally {
        putenv('SUMMARY_RETENTION_DAYS');
    }

    expect($summaries['retention_days'])->toBe(0);
});

/* A negative value is nonsense rather than a shorter window, so it reads as off too. */
test('a negative retention window reads as off', function (): void {
    putenv('SUMMARY_RETENTION_DAYS=-5');

    try {
        $summaries = require config_path('summaries.php');
    } finally {
        putenv('SUMMARY_RETENTION_DAYS');
    }

    expect($summaries['retention_days'])->toBe(0);
});

test('the command is summaries:prune', function (): void {
    expect((new PruneSummaries)->getName())->toBe('summaries:prune');
});
