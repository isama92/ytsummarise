<?php

declare(strict_types=1);

use App\Console\Commands\RecoverStalledSummaries;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Hourly. This is a backstop for something rare - a job that stopped existing without
 * failing - so it does not need to wake a process every minute for the rest of the
 * application's life.
 *
 * What that costs is precision at the far end: a row a worker abandoned is written off
 * somewhere between the timeout and an hour after it, so a page waiting on one can sit there
 * that long before it says so.
 *
 * Resubmitting does not shorten that, which is worth being plain about. Until the timeout
 * has passed the row does not count as abandoned, so a resubmit neither resets it nor gets a
 * job through - the dispatch meets the lock, or the job meets the claim. The wait is real
 * and this is what ends it, so the cadence is the whole answer rather than a fallback.
 *
 * No withoutOverlapping. An hour apart and a run of two indexed queries means two of these
 * can hardly meet, and if they did both halves are safe: the write-off is guarded on the
 * status it read, and a duplicate dispatch is settled by the claim in the job. What a mutex
 * would add is a way to fail - it lives in the cache, which here is the database, so a run
 * killed while holding it wedges the backstop until it expires, and the default expiry is a
 * day. A guard that cannot plausibly be needed is not worth a failure mode that can.
 */
Schedule::command(RecoverStalledSummaries::class)->hourly();
