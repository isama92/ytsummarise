<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Summary Timeout
    |--------------------------------------------------------------------------
    |
    | How long a video gets to be summarised, in seconds. One value doing three
    | jobs on purpose, so they can never disagree with each other:
    |
    |   - the queue worker's timeout for SummariseVideo
    |   - the lifetime of the lock that stops one video being summarised twice
    |     at once, which is also what lets a second person asking for the same
    |     video join the job already running instead of starting another
    |   - the age at which a summary still pending is written off as failed, so
    |     a page waiting on a job that died stops waiting
    |
    | Nothing to keep in step by hand: the `summaries` connection in
    | config/queue.php derives its own retry_after from this value, because a
    | retry_after below a job's timeout has the worker start a second copy of a
    | job that is still running.
    |
    | Floored at a minute rather than trusted blindly: a zero here, from an
    | empty or unparseable value, would expire every summary the instant it was
    | asked for and no summary would ever be produced again.
    |
    */

    'timeout' => max(60, (int) env('SUMMARY_TIMEOUT', 1800)),

];
