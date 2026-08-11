/**
 * Mirrors App\Enums\SummaryStatus.
 */
export type SummaryStatus = 'pending' | 'ready' | 'failed';

export type Summary = {
    status: SummaryStatus;

    /** Null until the job has written one. */
    body: string | null;
};
