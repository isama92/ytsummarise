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
 * Unique per video because the work behind this will be a paid model call. Two people
 * retrying the same failed video in the same instant both find it failed, both start an
 * attempt and both dispatch; the lock drops the second before it reaches the queue.
 *
 * Not the concurrent-create case, which never reaches here: video_id carries a unique
 * index, so of two requests for a video nobody has asked for yet only one creates the row,
 * and only the one that created it dispatches.
 *
 * Carries a row id and nothing else. Not for the payload's sake - SerializesModels already
 * reduced a model property to a class and a key - but so that there is one way the row is
 * obtained rather than two. A restored job re-queried it while a job built in process kept
 * whatever instance it was handed, and a test that reused one instance for two jobs passed
 * with the claim deleted outright because the first call had already mutated it to ready in
 * memory. Loading it here means every caller gets what a worker would get.
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
     * While it is held, a second attempt started for the same video in the same moment is
     * dropped rather than queued, which is the point of it. Somebody asking for a video that
     * is already pending never gets this far: the controller joins them to the attempt in
     * flight without dispatching anything.
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

    public function __construct(public int $summaryId)
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
     *
     * Keyed on the row and not on the video code, which is the same key under another name:
     * video_id carries a unique index, so a row is a video and there can never be a second
     * row to key a second job on. The row's id is the one the job already holds without
     * going to the database to ask for it.
     */
    public function uniqueId(): string
    {
        return (string) $this->summaryId;
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
         * Loaded here rather than carried, so what this reads is what is in the database at
         * the moment it runs rather than whatever was true when the job was queued. findOrFail
         * because a summary is never deleted: if one has been, that is worth a failure and a
         * log line rather than a job that quietly does nothing.
         */
        $summary = Summary::query()->findOrFail($this->summaryId);

        /*
         * Anything but pending and there is nothing to do here.
         *
         * Ready covers a job delivered twice, which happens however careful the
         * configuration is: a worker killed between finishing and deleting the job leaves it
         * to be reserved again. Without this the model call is paid for a second time and a
         * summary somebody is already reading is rewritten. failed() guards it from the
         * other side.
         *
         * Failed covers the expiry command having given up on this attempt while the job sat
         * in the queue. Nothing is paid for a summary the page has already said did not work
         * and offered to try again; whoever asks again starts a fresh attempt.
         *
         * Only reachable before the work starts, which is the whole extent of what this
         * guard buys. A job already past it when the command runs finishes and writes its
         * summary regardless - see the stale scope on the model for what that costs.
         */
        if ($summary->status !== SummaryStatus::Pending) {
            return;
        }

        /*
         * Claim the row before doing anything that costs money.
         *
         * Conditional on started_at still being null, so of any number of jobs for this
         * video exactly one update affects a row and the rest return having done nothing.
         * The database decides, which is what makes it a guarantee: the uniqueness lock
         * cannot provide one, because its TTL starts at dispatch rather than when a worker
         * picks the job up, so a job queued for longer than that is running without one.
         *
         * That is not hypothetical here. summaries:expire writes an attempt off after a
         * horizon twelve times the lock's lifetime, and asking again after that dispatches a
         * second job while the first may still be queued or running - by which point the
         * first job's lock lapsed hours ago. This is what stops both of them paying.
         *
         * The status is checked again as part of the same update rather than trusted from
         * the read above, because the two are not one statement: summaries:expire can write
         * this attempt off in between, and claiming it then pays for a summary the page has
         * already said did not work.
         *
         * It also records when the work actually began, which is the only honest thing to
         * measure the timeout against.
         */
        $claimed = Summary::query()
            ->whereKey($this->summaryId)
            ->where('status', SummaryStatus::Pending)
            ->whereNull('started_at')
            ->update(['started_at' => Date::now()]);

        if ($claimed === 0) {
            /*
             * Two ways to be here and neither is a problem: somebody else holds the claim,
             * or the attempt was written off between the read above and this update. Debug
             * rather than a warning, because a duplicate bouncing off a live claim is the
             * mechanism working.
             *
             * Logged at all because this return is otherwise indistinguishable from success
             * in a worker log, and a row left holding a stale claim would look exactly the
             * same while never being summarised.
             */
            Log::debug('Left a video alone, already claimed or given up on', [
                'video_id' => $summary->video_id,
            ]);

            return;
        }

        Sleep::for(3)->seconds();

        $summary->update([
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
     *
     * find rather than findOrFail, unlike handle(). One reason this runs at all is that
     * handle() threw on a row that is not there, and throwing again from the handler for
     * that would lose the exception that explains it. The log line carries the id either
     * way, which is the only thing left to go on when the row is gone.
     */
    public function failed(?Throwable $exception): void
    {
        $summary = Summary::query()->find($this->summaryId);

        $ready = $summary?->status === SummaryStatus::Ready;

        if ($summary instanceof Summary && ! $ready) {
            $summary->update([
                'status' => SummaryStatus::Failed,
                'body' => null,
            ]);
        }

        Log::error('Summarising a video failed', [
            'summary_id' => $this->summaryId,
            'video_id' => $summary?->video_id,
            'already_summarised' => $ready,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
