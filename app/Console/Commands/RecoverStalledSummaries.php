<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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
     * Execute the console command.
     */
    public function handle(): void
    {
        $dispatched = $this->requeueUnstarted();
        $failed = $this->failAbandoned();

        if ($dispatched === 0 && $failed === 0) {
            $this->components->info('Nothing to recover.');
        }
    }

    /**
     * Queue a job again for every summary no worker has started.
     *
     * Most of these are simply waiting their turn and the uniqueness lock will drop the
     * duplicate, which is why this is not a warning: it is the ordinary state of a queue
     * with anything in it.
     */
    private function requeueUnstarted(): int
    {
        $unstarted = Summary::query()->unclaimed()->get();

        if ($unstarted->isEmpty()) {
            return 0;
        }

        $unstarted->each(fn (Summary $summary): mixed => SummariseVideo::dispatch($summary));

        Log::debug('Dispatched summaries again that no worker had started', [
            'video_ids' => $unstarted->pluck('video_id')->all(),
        ]);

        /*
         * "Dispatched" and not "queued": whether the queue keeps any one of them is the
         * uniqueness lock's business, and there is no honest way to count that from here.
         */
        $this->components->info(sprintf(
            'Dispatched a job for %d unstarted %s.',
            $unstarted->count(),
            str('summary')->plural($unstarted->count()),
        ));

        return $unstarted->count();
    }

    /**
     * Write off every summary a worker began and abandoned.
     */
    private function failAbandoned(): int
    {
        $videoIds = Summary::query()->stalled()->pluck('video_id', 'id');

        if ($videoIds->isEmpty()) {
            return 0;
        }

        /*
         * Still pending, checked again here rather than trusted from the query above. A
         * row can finish in the moment between the two, and writing it off then would
         * leave it failed with a finished summary still attached, which the page renders
         * as "did not work" over an answer that exists.
         */
        $failed = Summary::query()
            ->whereKey($videoIds->keys())
            ->where('status', SummaryStatus::Pending)
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
         * A warning where the requeue above is a debug line: every row here is a worker
         * that died holding a job, and a steady trickle of them is a problem with the
         * workers rather than with any one video.
         */
        Log::warning('Failed summaries a worker abandoned', [
            'failed' => $failed,
            'candidates' => $videoIds->values()->all(),
        ]);

        $this->components->warn(sprintf(
            'Failed %d abandoned %s.',
            $failed,
            str('summary')->plural($failed),
        ));

        return $failed;
    }
}
