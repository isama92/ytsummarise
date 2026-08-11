<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SummaryStatus;
use App\Models\Summary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Writes off summaries that have been pending longer than a video is given.
 *
 * The backstop for every way a job can stop existing without failing: a worker killed
 * between reserving the job and finishing it, a queue table flushed, a dispatch dropped
 * because the uniqueness lock was still held by a job that had already died. In all of
 * them the row sits pending forever and the page waits on it forever, because a job that
 * never runs never calls failed().
 *
 * Marking them failed is what lets the page stop polling and offer to try again, since
 * resubmitting a failed video is already the retry.
 */
#[Signature('summaries:expire-stalled')]
#[Description('Mark summaries that have been pending too long as failed')]
class ExpireStalledSummaries extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $videoIds = Summary::query()->stalled()->pluck('video_id', 'id');

        if ($videoIds->isEmpty()) {
            $this->components->info('No stalled summaries.');

            return;
        }

        Summary::query()
            ->whereKey($videoIds->keys())
            ->update(['status' => SummaryStatus::Failed]);

        /*
         * Worth a log line rather than silence: every row here is a job that vanished,
         * and a steady trickle of them means something is wrong with the workers rather
         * than with any one video.
         */
        Log::warning('Expired stalled summaries', ['video_ids' => $videoIds->values()->all()]);

        $this->components->warn(sprintf('Expired %d stalled %s.', $videoIds->count(), str('summary')->plural($videoIds->count())));
    }
}
