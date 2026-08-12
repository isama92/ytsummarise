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
    | than this is written off while it is still alive - and when a worker
    | reaches it, the status guard in SummariseVideo stops it before anything is
    | paid for. Sized generously so that stays rare: six hours is far longer
    | than any real backlog, while still being an answer rather than a spinner
    | somebody watches all week.
    |
    | Floored at the timeout, because a summary cannot be given up on sooner
    | than the work is allowed to take.
    |
    */

    'stale_after' => max($timeout, (int) env('SUMMARY_STALE_AFTER', 21600)),

];
