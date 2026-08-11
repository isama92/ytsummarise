<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SummaryStatus;
use App\Models\Summary;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Produces the summary for one video.
 *
 * Unique per video because the work behind this will be a paid model call: the row is
 * guarded by a unique index, but two requests arriving in the same instant would both
 * find no row and both dispatch. The lock covers that gap.
 */
class SummariseVideo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15];

    /**
     * A worker killed mid job would otherwise hold the lock forever, and this video
     * could never be summarised again. Generous enough for a slow model call.
     */
    public int $uniqueFor = 600;

    /**
     * Stands in for the summary until the model call exists. Deliberately obvious as
     * placeholder text, so nobody mistakes a wiring bug for a bad summary.
     */
    private const string PLACEHOLDER_BODY = <<<'TEXT'
        Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

        Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.

        Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo.
        TEXT;

    public function __construct(public Summary $summary) {}

    /**
     * One job in flight per video.
     */
    public function uniqueId(): string
    {
        return $this->summary->video_id;
    }

    /**
     * Execute the job.
     *
     * The sleep stands in for the latency of the model call, so the pending state on the
     * page is actually visible while developing. Illuminate\Support\Sleep rather than
     * sleep(), because the test suite runs the queue synchronously and would otherwise
     * pay these seconds on every test that submits a video; Sleep::fake() removes them.
     *
     * When the real call replaces the placeholder, check the job timeout: a model call
     * can outlast the default 60 seconds, and queue.php's retry_after must stay larger
     * than the timeout or the worker starts a second copy while the first still runs.
     */
    public function handle(): void
    {
        Sleep::for(3)->seconds();

        $this->summary->update([
            'status' => SummaryStatus::Ready,
            'body' => self::PLACEHOLDER_BODY,
        ]);
    }

    /**
     * Handle a job failure.
     *
     * Recording the failure on the row is what lets the page stop asking for an answer
     * that is not coming.
     */
    public function failed(?Throwable $exception): void
    {
        $this->summary->update(['status' => SummaryStatus::Failed]);

        Log::error('Summarising a video failed', [
            'video_id' => $this->summary->video_id,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
