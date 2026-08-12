<?php

declare(strict_types=1);

use App\Console\Commands\PruneSummaries;
use App\Models\Summary;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * A summary created a given number of days ago.
 *
 * created_at is written by the framework, so it is set afterwards rather than passed to the
 * factory: an attribute named in create() is overwritten by the timestamps the insert applies.
 */
function summaryCreatedDaysAgo(int $days): Summary
{
    $summary = Summary::factory()->create();

    $summary->forceFill(['created_at' => Date::now()->subDays($days)])->saveQuietly();

    return $summary->fresh() ?? $summary;
}

test('a summary past the retention window is deleted', function (): void {
    Log::spy();

    $old = summaryCreatedDaysAgo(config()->integer('summaries.retention_days') + 1);
    $recent = summaryCreatedDaysAgo(1);

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
    summaryCreatedDaysAgo(config()->integer('summaries.retention_days') + 1);

    expect(Summary::query()->whereNotNull('transcript')->count())->toBe(1);

    $this->artisan('summaries:prune')->assertSuccessful();

    expect(Summary::query()->whereNotNull('transcript')->count())->toBe(0);
});

/*
 * The boundary, both sides. A summary exactly at the window is old enough - a week is a week -
 * and one an hour inside it is not.
 */
test('the window is where it says it is', function (int $days, bool $deleted): void {
    summaryCreatedDaysAgo($days);

    $this->artisan('summaries:prune')->assertSuccessful();

    expect(Summary::query()->count())->toBe($deleted ? 0 : 1);
})->with([
    'a day old' => [1, false],
    'a day inside the window' => [6, false],
    'exactly at the window' => [7, true],
    'well past it' => [30, true],
]);

/*
 * Measured from created_at rather than from requested_at, which resets on every retry: a video
 * somebody keeps failing to summarise would otherwise keep renewing its own retention, which is
 * the one row where the words have been sitting there longest.
 */
test('a retry does not renew a summary retention', function (): void {
    $summary = summaryCreatedDaysAgo(config()->integer('summaries.retention_days') + 1);

    $summary->update(['requested_at' => Date::now()]);

    $this->artisan('summaries:prune')->assertSuccessful();

    expect(Summary::query()->find($summary->id))->toBeNull();
});

/*
 * Nothing is exempt, including a row still pending. One old enough to be caught here was written
 * off by summaries:expire hours ago - its horizon is a fraction of this one - so a pending row
 * this old is one nothing was ever going to finish.
 */
test('an ancient pending summary is not kept out of politeness', function (): void {
    $summary = Summary::factory()->pending()->create();

    $summary->forceFill([
        'created_at' => Date::now()->subDays(config()->integer('summaries.retention_days') + 1),
    ])->saveQuietly();

    $this->artisan('summaries:prune')->assertSuccessful();

    expect(Summary::query()->count())->toBe(0);
});

test('a run with nothing to prune says so and logs nothing', function (): void {
    Log::spy();

    summaryCreatedDaysAgo(1);

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

    $summary = summaryCreatedDaysAgo(config()->integer('summaries.retention_days') + 1);

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
 * Deliberately not switchable off: zero is the setting everybody reaches for the first time a
 * summary they wanted disappears, and it is the one value that turns this into a note.
 */
test('the retention window cannot be turned off', function (): void {
    putenv('SUMMARY_RETENTION_DAYS=0');

    try {
        $summaries = require config_path('summaries.php');
    } finally {
        putenv('SUMMARY_RETENTION_DAYS');
    }

    expect($summaries['retention_days'])->toBe(1);
});

test('the command is summaries:prune', function (): void {
    expect((new PruneSummaries)->getName())->toBe('summaries:prune');
});
