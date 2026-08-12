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

    /*
    |--------------------------------------------------------------------------
    | Requeue After
    |--------------------------------------------------------------------------
    |
    | How long the recovery command leaves a summary alone, in seconds, after
    | queueing a job for it again.
    |
    | Only ever about the second requeue and the ones after it. A summary that
    | has never been requeued is requeued at the next run whatever this says,
    | so a job lost with its queue is repaired within the hour.
    |
    | What this bounds is the repetition. The command cannot tell a job waiting
    | its turn from one that no longer exists, so it queues again and lets the
    | claim make a duplicate harmless - but running hourly against a lock that
    | lapses in half an hour, it did that to every waiting summary every hour,
    | and an outage lasting until the abandon horizon left each one with a day's
    | worth of duplicates for the workers to drain on their way back.
    |
    | Six hours against a day of waiting is four attempts rather than
    | twenty-four, which is the trade: a second requeue only helps in the
    | unlikely case that the first was lost as well, so they are worth spacing
    | out, and anything still unstarted at the end is written off and said so
    | rather than retried in silence forever.
    |
    | Floored at the timeout, because requeueing while the previous dispatch's
    | uniqueness lock is still held only records a requeue that never happened.
    |
    */

    'requeue_after' => max($timeout, (int) env('SUMMARY_REQUEUE_AFTER', 21600)),

];
