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
 * What that costs is precision at the far end: a row is written off somewhere between the
 * timeout and an hour after it, so a page waiting on a job that vanished can sit there for
 * up to summaries.timeout plus an hour before it says so. It is not the only way out,
 * which is what makes the trade affordable: submitting the same video again recovers a
 * stalled row immediately, because the controller resets it and queues a fresh attempt
 * without waiting for this command. This is the path for the tab nobody is watching.
 *
 * withoutOverlapping is belt and braces at this cadence: the query is idempotent and a run
 * takes about as long as one indexed query, so two could hardly meet.
 */
Schedule::command(ExpireStalledSummaries::class)
    ->hourly()
    /*
     * Two minutes, not the default day. The mutex lives in the cache, which here is the
     * database, so it survives the restart that killed a run holding it, and at the default
     * a container going down mid-run would skip this command for twenty-four hours. Well
     * inside the hour between runs, so a killed run costs one skipped hour at most.
     */
    ->withoutOverlapping(2);
