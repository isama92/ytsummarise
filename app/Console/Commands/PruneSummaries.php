<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Summary;
use App\Services\YouTube\Actions\FetchCover;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes summaries old enough that nobody is coming back for them.
 *
 * The summary is not really what this is about. Beside it sits the transcript it was made from,
 * which is a recording of somebody speaking written down: other people's words, held by us,
 * and nobody asked them. Keeping that indefinitely because there was never a moment where
 * deleting it was anybody's job is exactly the storage limitation the AVG is about, so this
 * runs on a schedule rather than waiting to be remembered.
 *
 * Measured from requested_at, which is when the video was last asked for rather than when the
 * row was first created. So this deletes what nobody has asked for in a week, and a video
 * people keep coming back to keeps earning its place. Retries renew it, which follows from the
 * same idea: asking again is asking.
 *
 * Deletes rather than nulling the transcript. A summary of a video nobody has asked for in a
 * week is not worth keeping either, the row is cheap to recreate, and half-emptied rows would
 * be a third state for everything downstream to understand.
 *
 * The video's cover image goes with it, which is the one part of a summary that does not live
 * in the row. Nothing else would ever remove it: a file is named for its row's uuid, so a row
 * deleted without its image leaves a file nothing can identify as unreachable, and a directory
 * that only grows.
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

        /*
         * Switched off, and said out loud rather than passed over quietly. A scheduled command
         * that runs nightly and deletes nothing looks identical to one that is working, so the
         * one run where somebody checks why nothing is being pruned should answer the question
         * without them having to go and read the configuration.
         */
        if ($days === 0) {
            $this->components->warn(
                'Retention is switched off, so summaries and their transcripts are kept indefinitely.',
            );

            return;
        }

        $disk = Storage::disk(FetchCover::DISK);

        $deleted = 0;

        /*
         * A chunk at a time rather than one mass delete, which is what taking the cover images
         * along costs. A file is named for its row's uuid, so once the row is gone there is
         * nothing left that says which file belonged to it: the image has to go first, or it
         * never goes at all.
         *
         * The chunk is deleted immediately after its files, so a run interrupted half way has
         * removed whole rows rather than leaving some without their images. chunkById is not
         * disturbed by that: it walks forward from the last id it saw, and every row behind it
         * is already gone.
         *
         * Only the two columns that are needed, because the transcript is one of the others and
         * hydrating a week of them to read uuids would be the largest thing this command does.
         */
        Summary::query()
            ->where('requested_at', '<=', Date::now()->subDays($days))
            ->select(['id', 'uuid'])
            ->chunkById(500, function (Collection $summaries) use ($disk, &$deleted): void {
                $disk->delete($summaries->map->file_name->all());

                $deleted += Summary::whereKey($summaries->modelKeys())->delete();
            });

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
