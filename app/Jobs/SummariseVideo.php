<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SummaryStatus;
use App\Models\Summary;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
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

    /**
     * One attempt, deliberately.
     *
     * A failure marks the row and the page offers to submit again, so retrying is a
     * decision rather than an automatic second charge for a call that may well fail the
     * same way twice.
     */
    public int $tries = 1;

    /**
     * How long this job may run before the worker kills it.
     */
    public int $timeout;

    /**
     * How long the uniqueness lock survives.
     *
     * While it is held, a second person asking for the same video joins this job rather
     * than starting another, which is the point of it.
     *
     * It is not what makes summarising twice impossible, and it was a mistake to treat it
     * as though it were. The TTL starts when the job is dispatched, not when a worker picks
     * it up, so a job that waits in a queue and then runs can outlive its own lock. The
     * claim in handle() is the guarantee; this is the optimisation that usually saves us
     * needing it.
     */
    public int $uniqueFor;

    /**
     * Stands in for the summary until the model call exists. Deliberately obvious as
     * placeholder text, so nobody mistakes a wiring bug for a bad summary.
     */
    private const string PLACEHOLDER_BODY = <<<'TEXT'
        Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

        Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.

        Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo.
        TEXT;

    public function __construct(public Summary $summary)
    {
        $this->timeout = config()->integer('summaries.timeout');
        $this->uniqueFor = $this->timeout;

        /*
         * Its own connection, which is where its retry_after lives; see config/queue.php.
         * Set here rather than as a property because Queueable already declares an
         * untyped $connection that a typed override is not allowed to narrow.
         */
        $this->onConnection('summaries');
    }

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
     * sleep(), because the tests call this method directly and would otherwise pay three
     * seconds each time; Sleep::fake() removes them.
     *
     * They call it directly because naming a connection above overrides the sync default in
     * phpunit.xml, so dispatching this under test queues it rather than running it. Worth
     * knowing before writing a test that expects a submitted video to be summarised by the
     * time the request comes back: it will not be.
     *
     * When the real call replaces the placeholder, check the timeout: a model call can
     * outlast the default 60 seconds, and the connection's retry_after must stay larger
     * than the timeout or the queue hands the job to a second worker while the first still
     * runs. The claim below makes that safe rather than expensive, but it is still waste.
     */
    public function handle(): void
    {
        /*
         * A job can be delivered twice however careful the configuration is: a worker
         * killed between finishing and deleting the job leaves it to be reserved again.
         * Without this the model call is paid for a second time and a summary somebody is
         * already reading is rewritten. failed() guards the same path from the other side.
         */
        if ($this->summary->status === SummaryStatus::Ready) {
            return;
        }

        /*
         * Claim the row before doing anything that costs money.
         *
         * Conditional on started_at still being null, so of any number of jobs for this
         * video exactly one update affects a row and the rest return having done nothing.
         * The database decides, which is what makes it a guarantee: the uniqueness lock
         * cannot provide one, because its TTL starts at dispatch and a job that waited in a
         * queue can outlive it while still running.
         *
         * It also records when the work actually began, which is the only honest thing to
         * measure the timeout against.
         */
        $claimed = Summary::query()
            ->whereKey($this->summary->getKey())
            ->whereNull('started_at')
            ->update(['started_at' => Date::now()]);

        if ($claimed === 0) {
            return;
        }

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
     *
     * Guarded, because this can fire on a row that already holds a finished summary.
     * handle() can succeed and the worker still die before it deletes the job, and the
     * attempt after that one is free to throw. Marking the row failed then would hide a
     * perfectly good summary behind a "did not work" message. The failure is still
     * logged; only the row is left alone.
     */
    public function failed(?Throwable $exception): void
    {
        $ready = $this->summary->status === SummaryStatus::Ready;

        if (! $ready) {
            $this->summary->update([
                'status' => SummaryStatus::Failed,
                'body' => null,
            ]);
        }

        Log::error('Summarising a video failed', [
            'video_id' => $this->summary->video_id,
            'already_summarised' => $ready,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
