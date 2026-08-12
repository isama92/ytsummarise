<?php

$timeout = max(60, (int) env('SUMMARY_TIMEOUT', 1800));

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

    'timeout' => $timeout,

    /*
    |--------------------------------------------------------------------------
    | Abandon After
    |--------------------------------------------------------------------------
    |
    | How long a summary may wait for a worker to start it at all, in seconds,
    | before it is written off.
    |
    | A backstop and nothing else. Waiting is ordinary - a job sits behind every
    | job ahead of it - so the recovery command queues a waiting summary again
    | rather than giving up on it, and this is only the point at which that
    | stops being a backlog and starts being a worker that is not running. A day
    | of a queue never once starting a job is not a busy queue.
    |
    | Which is why it can be generous where the timeout above cannot. That one
    | has to be short enough to notice a dead worker; this one only has to be
    | longer than any real backlog, and being wrong about it costs a page that
    | waits too long rather than a summary written off mid flight.
    |
    | Floored at the timeout, because a summary cannot be given up on for never
    | starting sooner than one that started would be given up on for stopping.
    |
    */

    'abandon_after' => max($timeout, (int) env('SUMMARY_ABANDON_AFTER', 86400)),

];
