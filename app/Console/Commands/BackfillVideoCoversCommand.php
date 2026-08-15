<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Summary;
use App\Services\YouTube\Actions\FetchCover;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * Fetches the cover images for videos summarised before there were any.
 *
 * Temporary, and meant to be. App\Actions\Summarising\FindVideo fetches a cover for every
 * video it looks up, so from the release that added it onwards there is nothing for this to
 * do; what it exists for is the rows that were already in the database on the day. Run it
 * once per installation after deploying, then delete this file.
 *
 * Deliberately not scheduled. A command whose whole purpose is to run once should not be in
 * routes/console.php, where it would go on asking YouTube about the same rows nightly for as
 * long as nobody noticed.
 *
 * Safe to run again, and to interrupt. Whether a row needs anything is answered by looking on
 * the disk rather than by a column or a marker, so a run that stopped half way picks up where
 * it left off and a second run finds nothing to do.
 */
#[Signature('summaries:backfill-covers')]
#[Description('Fetch cover images for summaries that do not have one yet')]
class BackfillVideoCoversCommand extends Command
{
    /**
     * How long to wait between downloads, in milliseconds.
     *
     * Illuminate\Support\Sleep rather than sleep(), for the reason .ai/rules/actions.md gives:
     * a literal charges its seconds to the test suite, while this one disappears under
     * Sleep::fake() and can be asserted on.
     *
     * There to keep a few thousand rows from arriving at YouTube as one burst. Small enough
     * that a realistic backlog still finishes inside a deploy window, and this is a cdn
     * serving images rather than an api with a quota.
     */
    private const int PAUSE_MILLISECONDS = 200;

    public function handle(FetchCover $fetchCover): int
    {
        $total = Summary::count();

        if ($total === 0) {
            $this->components->info('There are no summaries to fetch covers for.');

            return self::SUCCESS;
        }

        $disk = Storage::disk(FetchCover::DISK);

        $fetched = 0;
        $present = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        /*
         * chunkById rather than chunk, and a select of the two columns that are used. The
         * transcript column is the reason: it holds an entire video's words, and hydrating a
         * thousand of them to read a uuid would be tens of megabytes for nothing.
         */
        Summary::query()
            ->select(['id', 'uuid', 'video_id'])
            ->chunkById(100, function (Collection $summaries) use ($disk, $fetchCover, $bar, &$fetched, &$present, &$failed): void {
                foreach ($summaries as $summary) {
                    if ($disk->exists($summary->file_name)) {
                        $present++;
                        $bar->advance();

                        continue;
                    }

                    if ($fetchCover->execute($summary)) {
                        $fetched++;
                    } else {
                        $failed++;
                    }

                    $bar->advance();

                    Sleep::for(self::PAUSE_MILLISECONDS)->milliseconds();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->components->info(sprintf(
            'Fetched %d, already had %d, could not fetch %d.',
            $fetched,
            $present,
            $failed,
        ));

        /*
         * Successful even when some covers could not be fetched, because none of them is worth
         * failing a deploy over: a video that has been taken down since it was summarised has
         * no thumbnail to get, and the summary of it is still perfectly readable. FetchCover
         * has logged each one with the video it was for.
         */
        return self::SUCCESS;
    }
}
