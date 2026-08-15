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
    | 'not_found'
    | 'unreachable'
    | 'no_transcript'
    | 'unavailable'
    | 'timed_out'
    | 'unknown';

/**
 * Mirrors App\Services\Ai\Data\SummarySections: one language's worth of summary.
 *
 * The lists are filtered server-side before they are stored, so every entry is a non-empty
 * string, but neither is guaranteed to have the length the agent was asked for - a model that
 * returns nine points has still written a usable summary.
 */
export type SummarySections = {
    headline: string;
    points: string[];
    takeaways: string[];
};

/**
 * Mirrors App\Services\Ai\Data\SummaryOutline: everything written about one video.
 *
 * `english` is null for a video that was in English already, which is the ordinary case. It is
 * a real absence rather than a copy of `original`, so whether there is a second version to show
 * is answered by asking whether this is there.
 */
export type SummaryOutline = {
    /** The primary subtag of the language the video was in: `en`, `nl`, `pt`. */
    language: string;
    original: SummarySections;
    english: SummarySections | null;
};

export type Summary = {
    status: SummaryStatus;

    /**
     * The video's own title, written by the job together with the summary.
     *
     * Null while an attempt is still running, and null afterwards when the lookup found the
     * video but was not allowed to name it, so nothing here may assume it is there.
     */
    title: string | null;

    /**
     * The summary itself, or null until the job has written one.
     *
     * The transcript it was made from is deliberately not sent: it is the raw material rather
     * than the answer, and it would travel with every poll while the page waits.
     */
    outline: SummaryOutline | null;

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

    /**
     * Where the video's cover image is, or null when there is none to show.
     *
     * The server checks the disk before sending this, so a url here means there really is a
     * file behind it. Null covers three different things and the page treats them alike: an
     * older row created before covers existed, a thumbnail that could not be fetched, and an
     * attempt that has not got past its first step yet.
     */
    coverUrl: string | null;
};
