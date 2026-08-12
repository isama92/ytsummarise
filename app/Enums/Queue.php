<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The queues Horizon works, named once so a dispatch site and a supervisor cannot disagree.
 *
 * A queue name is a string on both sides of a gap nothing checks: a job goes onto whatever
 * ->onQueue() was told, and a worker reads whatever config/horizon.php lists. Get one of them
 * wrong and there is no error anywhere - the job queues perfectly well onto a queue nobody
 * works, and the only symptom is something that never happens. compose.yml carried a comment
 * about exactly that for as long as there was one worker and no dashboard.
 *
 * Referenced from config/horizon.php and config/queue.php rather than only from application
 * code, which is the point: those two files and the dispatch sites are the ends that have to
 * match, so the constant has to reach all of them.
 */
enum Queue: string
{
    /**
     * Ahead of everything else. For work someone is waiting on with a page open.
     */
    case High = 'high';

    /**
     * Where a job with no ->onQueue() lands, which is why it is worked rather than left as a
     * name in a config file. REDIS_QUEUE agrees with this in config/queue.php.
     */
    case Default = 'default';

    /**
     * Behind the other two. For work whose lateness nobody would notice.
     */
    case Low = 'low';

    /**
     * Summarising, on its own connection and its own supervisor, one job at a time.
     *
     * Separate from the three above rather than a priority within them: the work takes up to
     * an hour and costs money per attempt, so it needs a retry_after the others would be
     * absurd under, and a worker count that cannot scale up. See config/queue.php.
     */
    case Summaries = 'summaries';
}
