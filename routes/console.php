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
 * Every minute, because the cost is one indexed query and the point is that a page
 * waiting on a job that died stops waiting shortly after the timeout rather than at some
 * arbitrary later point. withoutOverlapping is belt and braces: the query is idempotent,
 * but there is no reason for two of these to run at once.
 */
Schedule::command(ExpireStalledSummaries::class)
    ->everyMinute()
    /*
     * Two minutes, not the default day. The mutex lives in the cache, which here is the
     * database, so it survives the restart that killed a run holding it - and at the
     * default a container going down mid-run would skip this command for twenty-four
     * hours, which is exactly as long as every summary whose job vanished would spin.
     * The run itself takes about one indexed query.
     */
    ->withoutOverlapping(2);
