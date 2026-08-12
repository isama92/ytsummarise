<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Puts right the summaries whose jobs stopped existing.
 *
 * A job that never runs never calls failed(), so without something like this a row stays
 * pending and the page waits on it forever. There are two ways that happens and they want
 * opposite treatment, which is why this does two things:
 *
 * A worker began and did not finish. Its own timeout should have killed it and failed the
 * row, so the worker itself is gone - killed rather than stopped. Nothing is coming: write
 * the row off so the page stops waiting and offers to try again.
 *
 * No worker ever began. Ordinary while a job waits its turn, and identical from here to a
 * job that no longer exists. Rather than guess which, queue it again: the job claims its row
 * before doing any work, so a duplicate does nothing, and being wrong costs a dispatch.
 */
#[Signature('summaries:recover')]
#[Description('Requeue summaries no worker started, and fail those a worker abandoned')]
class RecoverStalledSummaries extends Command
{
    /**
     * How many video ids a single log entry will carry.
     */
    private const int VIDEO_IDS_LOGGED = 20;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $dispatched = $this->requeueUnstarted();

        $failed = $this->failAbandoned()
            + $this->failNeverStarted();

        if ($dispatched === 0 && $failed === 0) {
            $this->components->info('Nothing to recover.');
        }
    }

    /**
     * Queue a job again for every summary no worker has started and one still might.
     *
     * Most of these are simply waiting their turn and the uniqueness lock will drop the
     * duplicate, which is why this is not a warning: it is the ordinary state of a queue
     * with anything in it.
     *
     * Not unclaimed, and the difference is the whole reason those are separate scopes.
     * Unclaimed includes the rows failNeverStarted is about to write off, so queueing from
     * it dispatched a job for a row this same run then failed - and since the write-off
     * leaves started_at null, that job went on to claim the row it had just given up on and
     * finish it, resurrecting a summary the command had deliberately abandoned.
     *
     * Not awaitingWorker either, which is that set without the spacing: this runs hourly
     * against a lock that lapses in half an hour, so queueing every waiting summary every
     * time left an outage's worth of duplicates for the workers to drain on their way back.
     * Harmless individually, since each bounces off the claim, and a great deal of them.
     */
    private function requeueUnstarted(): int
    {
        $dispatched = 0;
        $videoIds = [];

        /*
         * Streamed rather than fetched. The set is the queue's backlog, so it is normally a
         * handful and occasionally everything submitted during an outage; loading all of it
         * to dispatch one job at a time buys nothing and has no ceiling.
         */
        foreach (Summary::query()->dueForRequeue()->cursor() as $summary) {
            SummariseVideo::dispatch($summary);

            /*
             * Recorded a row at a time and after the dispatch rather than in one update at
             * the end, so a run that dies partway through has told the truth about what it
             * did. The other order suppresses the next requeue for a dispatch that never
             * happened, which is the more expensive way to be wrong: this one costs a
             * duplicate the claim will bounce, that one costs a summary nobody ever makes.
             */
            $summary->update(['requeued_at' => Date::now()]);

            $dispatched++;

            /* Enough to diagnose with, without putting an outage's worth in one line. */
            if (count($videoIds) < self::VIDEO_IDS_LOGGED) {
                $videoIds[] = $summary->video_id;
            }
        }

        if ($dispatched === 0) {
            return 0;
        }

        Log::debug('Dispatched summaries again that no worker had started', [
            'dispatched' => $dispatched,
            'video_ids' => $videoIds,
            'video_ids_truncated' => $dispatched > self::VIDEO_IDS_LOGGED,
        ]);

        /*
         * "Dispatched" and not "queued": whether the queue keeps any one of them is the
         * uniqueness lock's business, and there is no honest way to count that from here.
         */
        $this->components->info(sprintf(
            'Dispatched a job for %d unstarted %s.',
            $dispatched,
            str('summary')->plural($dispatched),
        ));

        return $dispatched;
    }

    /**
     * Write off every summary a worker began and abandoned.
     */
    private function failAbandoned(): int
    {
        return $this->writeOff(
            Summary::query()->stalled(),
            'Failed summaries a worker abandoned',
            'abandoned',
        );
    }

    /**
     * Write off every summary nothing ever started, for long enough that nothing will.
     *
     * The backstop to requeueUnstarted above. Queueing a waiting summary again is right for
     * as long as there is any reason to think a worker will get to it, and this is where
     * that stops: a queue that has not once started this job in a day is not busy.
     */
    private function failNeverStarted(): int
    {
        return $this->writeOff(
            Summary::query()->neverStarted(),
            'Failed summaries nothing ever started',
            'never started',
        );
    }

    /**
     * Mark a set of pending summaries failed, and say so.
     *
     * @param  Builder<Summary>  $rows
     */
    private function writeOff(Builder $rows, string $message, string $describes): int
    {
        $videoIds = $rows->pluck('video_id', 'id');

        if ($videoIds->isEmpty()) {
            return 0;
        }

        /*
         * The same conditions again, checked by the update rather than trusted from the
         * select a moment earlier, because a row can stop qualifying in between and writing
         * it off then is wrong in both directions. One can finish, leaving it failed with a
         * summary still attached and the page saying "did not work" over an answer that
         * exists. One nothing had started can be claimed by a worker, and failing that row
         * hands the video back to the controller to reset and dispatch again while the
         * first worker is still running - two paid calls for one video, which is the thing
         * the claim exists to prevent.
         *
         * Re-applying the whole scope rather than re-checking status covers both without
         * either path having to know which of them it is. The scope's horizon is the one
         * captured when it was built, so this can only ever narrow what was selected.
         */
        $failed = $rows->clone()
            ->whereKey($videoIds->keys())
            ->update(['status' => SummaryStatus::Failed]);

        /*
         * Counted from what the update changed rather than from what was selected a moment
         * earlier, because those differ whenever a row finishes in between: reporting the
         * selection would claim rows this run deliberately left alone.
         */
        if ($failed === 0) {
            return 0;
        }

        /*
         * A warning where the requeue is a debug line. Every row here is either a worker
         * that died holding a job or a queue that never picked one up, and a steady trickle
         * of either is a problem with the workers rather than with any one video.
         */
        Log::warning($message, [
            'failed' => $failed,
            'video_ids' => $videoIds->values()->take(self::VIDEO_IDS_LOGGED)->all(),
            'video_ids_truncated' => $failed > self::VIDEO_IDS_LOGGED,
        ]);

        $this->components->warn(sprintf(
            'Failed %d %s %s.',
            $failed,
            $describes,
            str('summary')->plural($failed),
        ));

        return $failed;
    }
}
