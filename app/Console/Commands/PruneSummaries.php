<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Summary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Deletes summaries old enough that nobody is coming back for them.
 *
 * The summary is not really what this is about. Beside it sits the transcript it was made from,
 * which is a recording of somebody speaking written down: other people's words, held by us,
 * and nobody asked them. Keeping that indefinitely because there was never a moment where
 * deleting it was anybody's job is exactly the storage limitation the AVG is about, so this
 * runs on a schedule rather than waiting to be remembered.
 *
 * Measured from created_at, which is when the video was first asked for, and not from
 * requested_at - that resets on every retry, so a video somebody keeps failing to summarise
 * would keep renewing its own retention. The age of the row is the honest question here.
 *
 * Deletes rather than nulling the transcript. A summary of a video nobody has looked at for a
 * week is not worth keeping either, the row is cheap to recreate, and half-emptied rows would
 * be a third state for everything downstream to understand.
 *
 * Nothing is exempt, including a row still pending. One old enough to be caught here was
 * written off by summaries:expire hours ago - its horizon is a fraction of this one - so a
 * pending row this old is one nothing is ever going to finish.
 */
#[Signature('summaries:prune')]
#[Description('Delete summaries, and the transcripts with them, past their retention window')]
class PruneSummaries extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $days = config()->integer('summaries.retention_days');

        $deleted = Summary::query()
            ->where('created_at', '<=', Date::now()->subDays($days))
            ->delete();

        if ($deleted === 0) {
            $this->components->info('Nothing to prune.');

            return;
        }

        /*
         * Counted rather than listed. The video ids would say which summaries went, but this
         * runs unattended and daily, and a log line naming everything anybody watched is its
         * own small version of the problem the command exists to solve.
         */
        Log::info('Pruned summaries past their retention window', [
            'deleted' => $deleted,
            'retention_days' => $days,
        ]);

        $this->components->info(sprintf(
            'Pruned %d %s older than %d %s.',
            $deleted,
            str('summary')->plural($deleted),
            $days,
            str('day')->plural($days),
        ));
    }
}
