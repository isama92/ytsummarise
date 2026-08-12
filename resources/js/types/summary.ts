/**
 * Mirrors App\Enums\SummaryStatus.
 */
export type SummaryStatus = 'pending' | 'ready' | 'failed';

/**
 * Mirrors App\Enums\SummaryError.
 *
 * Each value is also a key under `summaries.errors` in lang/en/summaries.php, which is what
 * lets a reason from the database become a sentence without a lookup table in between.
 */
export type SummaryError =
    'not_found' | 'unreachable' | 'timed_out' | 'unknown';

export type Summary = {
    status: SummaryStatus;

    /**
     * The video's own title, written by the job together with the summary.
     *
     * Null while an attempt is still running, and null afterwards when the lookup found the
     * video but was not allowed to name it, so nothing here may assume it is there.
     */
    title: string | null;

    /** Null until the job has written one. */
    body: string | null;

    /**
     * Why the attempt failed, and null for every attempt that has not.
     *
     * A code and not a sentence: the wording lives in lang/en/summaries.php so it can be
     * changed without a migration, and so the page can do more with a reason than print it.
     */
    error: SummaryError | null;

    /**
     * ISO 8601, when the attempt currently in flight was asked for.
     *
     * Not when this summary was first created: somebody joining a job already running
     * counts up from the moment the person before them asked, not from zero.
     */
    requestedAt: string;

    /**
     * ISO 8601, when a worker began, or null while it is still waiting its turn.
     *
     * The difference between queued and being worked on, which `status` cannot express:
     * both are pending.
     */
    startedAt: string | null;
};
