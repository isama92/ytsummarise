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
 * No withoutOverlapping. An hour apart and a run of one indexed query means two of these
 * can hardly meet, and if they did the command is safe: its update is guarded on the status
 * it read, so the second run changes nothing. What a mutex would add is a way to fail - it
 * lives in the cache, which here is the database, so a run killed while holding it wedges
 * the backstop until it expires, and the default expiry is a day. A guard that cannot
 * plausibly be needed is not worth a failure mode that can.
 */
Schedule::command(ExpireStalledSummaries::class)->hourly();
