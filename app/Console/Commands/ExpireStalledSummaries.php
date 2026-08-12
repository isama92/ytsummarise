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
 * Ends the attempts nothing is ever going to finish.
 *
 * A job that never runs never calls failed(), so without something like this a row stays
 * pending and the page waits on it forever. Whether that is a worker that died holding the
 * job or a job that stopped existing before any worker saw it makes no difference from
 * here, and this deliberately does not try to tell them apart: both are an attempt that is
 * not going to produce anything, and both get the same answer.
 *
 * Nothing is queued again. A summary written off here stays written off until somebody asks
 * for that video again, because a person deciding to try once more is cheaper and more
 * honest than a command guessing on their behalf - and guessing is what it would be, since
 * a job waiting its turn behind a long one looks exactly like a job that no longer exists.
 */
#[Signature('summaries:expire')]
#[Description('Fail summaries that have been pending too long')]
class ExpireStalledSummaries extends Command
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
        $rows = Summary::query()->stale();

        $videoIds = $rows->pluck('video_id', 'id');

        if ($videoIds->isEmpty()) {
            $this->components->info('Nothing to expire.');

            return;
        }

        /*
         * The same conditions again, checked by the update rather than trusted from the
         * select a moment earlier. A row can finish in between, and writing it off then
         * would leave it failed with a summary still attached, which the page renders as
         * "did not work" over an answer that exists.
         *
         * Re-applying the scope rather than re-checking status by hand, so this cannot drift
         * from what was selected. Its horizon is the one captured when the scope was built,
         * so the update can only ever narrow the set, never widen it.
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
            $this->components->info('Nothing to expire.');

            return;
        }

        /*
         * A warning rather than a note. Every row here is a job that stopped existing without
         * failing, and a steady trickle of them is a problem with the workers or the queue
         * rather than with any one video.
         */
        Log::warning('Failed summaries that had been pending too long', [
            'failed' => $failed,
            'video_ids' => $videoIds->values()->take(self::VIDEO_IDS_LOGGED)->all(),
            'video_ids_truncated' => $failed > self::VIDEO_IDS_LOGGED,
        ]);

        $this->components->warn(sprintf(
            'Failed %d stale %s.',
            $failed,
            str('summary')->plural($failed),
        ));
    }
}
