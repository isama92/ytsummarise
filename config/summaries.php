<?php

use App\Support\SummaryBudget;

/*
 * The job's budget has to cover the budgets of everything inside it, or the worker is what
 * gives up first - and that is the one outcome none of these numbers wants. A step that runs
 * out of time stops and records a reason somebody can read; a worker that runs out of patience
 * kills the job mid-write and leaves the failure handler guessing at "unknown".
 *
 * The derivation is in App\Support\SummaryBudget rather than here because config/queue.php and
 * config/horizon.php have to agree with it and neither can read a config value. That class is
 * where the reasoning lives, including why SUMMARY_TIMEOUT is a floor rather than the value.
 */
$modelTimeout = SummaryBudget::modelSeconds(env('SUMMARY_MODEL_TIMEOUT'));

$transcriptTimeout = SummaryBudget::transcriptSeconds(env('SUMMARY_TRANSCRIPT_TIMEOUT'));

$timeout = SummaryBudget::seconds(
    env('SUMMARY_MODEL_TIMEOUT'),
    env('SUMMARY_TRANSCRIPT_TIMEOUT'),
    env('SUMMARY_TIMEOUT'),
);

/*
 * Only a value that is unambiguously a number of days is believed, and everything else falls
 * back to the default rather than to zero.
 *
 * Because zero switches retention off, and a cast alone makes it the answer to every value that
 * is not a number: `SUMMARY_RETENTION_DAYS=` reads as an empty string and `(int) ''` is 0, so a
 * blank line in an env file would quietly stop deleting anything and the only sign would be a
 * console warning on an unattended nightly run. So would a typo, and so would a negative.
 *
 * This is the fail-safe rule .ai/rules/config.md records for AUTH_ENABLED, applied to the other
 * guard in this application whose failure mode is silent: there the permissive failure is an
 * application open to everyone, here it is holding other people's speech with no end date.
 * Switching retention off is a decision, so it takes a deliberate zero.
 */
$retention = env('SUMMARY_RETENTION_DAYS', 7);

$retentionDays = is_numeric($retention) && (int) $retention >= 0 ? (int) $retention : 7;

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
    |   - the lifetime of the lock that drops the second of two attempts started
    |     for one video in the same moment.
    |
    | Not what joins somebody to a summary already being produced, which the lock
    | never sees: the controller answers a pending row with the attempt already in
    | flight and dispatches nothing at all.
    |
    | Nothing to keep in step by hand: the `summaries` connection in
    | config/queue.php derives its own retry_after from this value, because a
    | retry_after below a job's timeout has the queue hand the job to a second
    | worker while the first is still running.
    |
    | SUMMARY_TIMEOUT is a floor rather than the value. What is actually used is
    | whichever is larger: what was asked for, or what the steps inside the job
    | are between them allowed to take. See the derivation at the top of this
    | file for why that way round.
    |
    | Also floored at a minute, so an empty or unparseable value cannot leave
    | the work with no time to run in.
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

    /*
    |--------------------------------------------------------------------------
    | Model Timeout
    |--------------------------------------------------------------------------
    |
    | How long one prompt gets, in seconds. Three of them run for a video in a
    | language that is not English, so this is not the budget for the whole job:
    | summaries.timeout above is.
    |
    | It has to be set at all because the SDK's own default is sixty seconds,
    | which is fine for a hosted model answering a short question and nowhere
    | near enough for a local one working through an hour of transcript. The
    | value is passed at prompt time rather than declared with the #[Timeout]
    | attribute, which takes a literal and cannot read configuration.
    |
    | Ten minutes by default, which is slow for a hosted provider and unhurried
    | for a model on somebody's own hardware. Floored at thirty seconds so an
    | unparseable value cannot leave a prompt no time to answer in.
    |
    | Not capped. Raising this raises the job's own timeout to match rather than
    | being quietly reduced to fit inside it, which is the whole point of
    | deriving that one from these.
    |
    */

    'model_timeout' => $modelTimeout,

    /*
    |--------------------------------------------------------------------------
    | Transcript
    |--------------------------------------------------------------------------
    |
    | Where yt-dlp is and how long it gets. The binary is looked up on PATH by
    | default, which is what a development machine wants; a queue worker running
    | somewhere without one takes an absolute path here.
    |
    | The timeout covers asking yt-dlp about a video and fetching the caption
    | track it names, separately rather than between them, so a slow answer to
    | one does not spend the other's budget. Neither should be anywhere near it:
    | both are small requests, and the ceiling is there for a yt-dlp that has
    | hung rather than as a target.
    |
    | Both of those are counted into the job's timeout, so a transcript that
    | never arrives fails as a transcript that never arrived rather than as a
    | worker killing the job around it.
    |
    */

    'transcript' => [
        'binary' => env('YT_DLP_BINARY', 'yt-dlp'),
        'timeout' => $transcriptTimeout,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many days a summary is kept before summaries:prune deletes it, counted
    | from requested_at - when it was last asked for, rather than when the row
    | was first created. So the window is "nobody has asked for this in a week"
    | rather than "this is a week old", and a video somebody comes back to keeps
    | earning its place. Retries renew it for the same reason, since asking again
    | is asking.
    |
    | This exists because of what is stored beside the summary rather than
    | because of the summary. A transcript is a recording of somebody speaking,
    | written down - other people's words, kept by us, and nobody asked them.
    | Keeping it forever because there was no reason to delete it is exactly the
    | storage limitation the AVG is about, so the window is short by default and
    | the deletion runs whether or not anybody remembers to ask for it.
    |
    | Zero switches it off and keeps everything indefinitely. Worth being plain
    | about what that means rather than hiding it behind a floor: it is a
    | decision to hold other people's speech with no end date, which needs a
    | reason that is not "the setting was there".
    |
    | Which is why it takes a deliberate zero. A blank, a typo or a negative
    | falls back to the default rather than reading as off - see the note above
    | the derivation at the top of this file.
    |
    | Deleting a summary is not destructive in the way it sounds: asking for the
    | same video again produces a new one. What it costs is the time to make it.
    |
    */

    'retention_days' => $retentionDays,

];
