<?php

declare(strict_types=1);

use App\Console\Commands\ExpireStalledSummaries;
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
 * What that costs is precision at the far end: a stale attempt is written off somewhere
 * between the horizon and an hour after it, so a page waiting on one can sit there that long
 * before it says so. Against a horizon of hours, an hour of slack is not worth more.
 *
 * Resubmitting does not shorten it either, which is worth being plain about. A pending row
 * is joined rather than restarted, so until this has run there is nothing for a resubmit to
 * do. The wait is real and this is what ends it, so the cadence is the whole answer rather
 * than a fallback somebody can reach for.
 *
 * No withoutOverlapping. An hour apart and a run of one indexed query means two of these can
 * hardly meet, and if they did the write-off re-checks its own conditions in the update, so
 * the worst of it is that one run reports what the other did. What a mutex would add is a way
 * to fail - it lives in the cache, which here is the database, so a run killed while holding
 * it wedges the backstop until it expires, and the default expiry is a day. A guard that
 * cannot plausibly be needed is not worth a failure mode that can.
 */
Schedule::command(ExpireStalledSummaries::class)->hourly();
