<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Summary Timeout
    |--------------------------------------------------------------------------
    |
    | How long the work itself gets, in seconds. A budget for doing it, never
    | for waiting to be started: a job sits in the queue behind every job ahead
    | of it, and none of that time is counted here.
    |
    | One value doing three jobs on purpose, so they cannot disagree:
    |
    |   - the queue worker's timeout for SummariseVideo
    |   - the lifetime of the lock that lets a second person asking for the same
    |     video join the job already running instead of starting another
    |   - how long after a worker *started* a summary it is written off as
    |     failed, so a page waiting on a worker that died stops waiting.
    |     Measured from started_at, and only ever applied to a row some worker
    |     claimed; one nobody has started yet is queued again instead, however
    |     long it has been waiting.
    |
    | Nothing to keep in step by hand: the `summaries` connection in
    | config/queue.php derives its own retry_after from this value, because a
    | retry_after below a job's timeout has the queue hand the job to a second
    | worker while the first is still running.
    |
    | Floored at a minute rather than trusted blindly: a zero here, from an
    | empty or unparseable value, would write off every summary the instant a
    | worker picked it up.
    |
    */

    'timeout' => max(60, (int) env('SUMMARY_TIMEOUT', 1800)),

];
