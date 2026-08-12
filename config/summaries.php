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
    | One value doing two jobs on purpose, so they cannot disagree:
    |
    |   - the queue worker's timeout for SummariseVideo
    |   - the lifetime of the lock that lets a second person asking for the same
    |     video join the job already running instead of starting another.
    |
    | Nothing to keep in step by hand: the `summaries` connection in
    | config/queue.php derives its own retry_after from this value, because a
    | retry_after below a job's timeout has the queue hand the job to a second
    | worker while the first is still running.
    |
    | Floored at a minute rather than trusted blindly, so an empty or
    | unparseable value cannot leave the work with no time to run in.
    |
    */

    'timeout' => $timeout,

    /*
    |--------------------------------------------------------------------------
    | Stale After
    |--------------------------------------------------------------------------
    |
    | How long a summary may stay pending, in seconds, before it is given up on
    | and marked failed.
    |
    | Measured from requested_at, which is set every time an attempt starts, so
    | it is the age of the attempt in flight rather than of the row.
    |
    | Nothing is queued again as a result. A summary written off here stays
    | written off until somebody asks for that video again, which is the whole
    | policy: the page says it did not work and offers to try once more, and a
    | person deciding to is cheaper and more honest than a command guessing on
    | their behalf.
    |
    | Deliberately blunt about what it cannot see. It does not ask whether a
    | worker ever picked the row up, so a job queued behind a backlog longer
    | than this is written off while it is still alive. Sized generously so that
    | stays rare: six hours is far longer than any real backlog, while still
    | being an answer rather than a spinner somebody watches all week.
    |
    | Two different things happen when it is wrong, and only one of them is
    | free. A job that has not started yet meets the status guard in
    | SummariseVideo and stops, so nothing is paid for. A job already running is
    | past that guard and finishes regardless - it writes its summary and the
    | row goes ready, which is the right outcome, though a page that stopped
    | polling on the failure needs a reload to see it. What that case does cost
    | is the retry: resubmitting clears a claim a worker is still holding, and a
    | second job can then pay for the same video.
    |
    | Floored at twice the timeout, not at the timeout. The two clocks start at
    | different moments - this one when the attempt was asked for, the worker's
    | budget when the work began - so equal values expire the horizon while the
    | work is still legally running for every row that waited in a queue at all.
    | Doubling is the smallest floor that leaves room for both the work and some
    | queueing.
    |
    */

    'stale_after' => max($timeout * 2, (int) env('SUMMARY_STALE_AFTER', 21600)),

];
