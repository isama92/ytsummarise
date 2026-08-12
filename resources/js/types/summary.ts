/**
 * Mirrors App\Enums\SummaryStatus.
 */
export type SummaryStatus = 'pending' | 'ready' | 'failed';

export type Summary = {
    status: SummaryStatus;

    /**
     * The video's own title, known before the summary is.
     *
     * Null when the lookup could not tell us, which a video is still worth summarising
     * without, so nothing here may assume it is there.
     */
    title: string | null;

    /** Null until the job has written one. */
    body: string | null;

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
