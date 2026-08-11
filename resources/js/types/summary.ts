/**
 * Mirrors App\Enums\SummaryStatus.
 */
export type SummaryStatus = 'pending' | 'ready' | 'failed';

export type Summary = {
    status: SummaryStatus;

    /** Null until the job has written one. */
    body: string | null;

    /**
     * ISO 8601, when the attempt currently in flight was asked for.
     *
     * Not when this summary was first created: somebody joining a job already running
     * counts up from the moment the person before them asked, not from zero.
     */
    requestedAt: string;
};
