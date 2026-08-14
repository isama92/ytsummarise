<?php

declare(strict_types=1);

namespace App\Actions\Summarising;

use App\Enums\SummaryError;
use App\Enums\SummaryStatus;
use App\Jobs\ActionJob;
use App\Models\Summary;
use App\Services\Ai\Data\SummarySections;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\QueueableAction\QueueableAction;
use Throwable;

/**
 * One step of summarising a video, and everything the five of them share.
 *
 * Summarising is a chain of five steps inside one named batch, dispatched by
 * App\Actions\SummariseVideo. Each step reads the row, does one thing, and writes what it
 * produced; the next step reads that. Nothing is carried in memory between them, because there
 * is no memory between them - see the ideas column for the one place that was not already true.
 *
 * Every step takes the same two arguments and they are not interchangeable. The id says which
 * row; the claim says which *attempt* on that row, and it is what makes a step safe to run late.
 * summaries:expire can write an attempt off while its steps are still queued, somebody can ask
 * again, and the controller then clears started_at so a second attempt can claim it - at which
 * point the first attempt's remaining steps are still out there, holding a claim that no longer
 * matches. Every read and every write below is conditional on it, so those steps do nothing at
 * all rather than writing an older summary over a newer attempt.
 *
 * Every step is also handed the video code, which none of their execute() methods declares.
 * That is on purpose rather than an oversight: it is only ever wanted for the Horizon tag, and
 * looking it up in tags() instead meant five identical reads of one immutable column while the
 * chain was being assembled, in the web request. PHP drops arguments a userland method does not
 * declare, so a step takes the two it works with and tags() takes all three.
 *
 * No step keys its own lock, and that is not an oversight to tidy up. A batch and a chain reach
 * the queue by different routes: Batch::add() pushes the first job through Queue::bulk(), which
 * never consults Illuminate\Bus\UniqueLock, while every continuation goes through
 * dispatchNextJobInChain() and therefore through a PendingDispatch, which does. A step that
 * declared uniqueId() could be swallowed mid-chain by a lock left behind by an earlier attempt,
 * and a swallowed step is a batch that never finishes and a row that stays pending for good.
 * With none declared, App\Jobs\ActionJob gives each dispatch a ULID key nothing can collide
 * with. The guard against two attempts is the claim above, taken once, before the batch exists.
 */
abstract class SummarisingStep
{
    use QueueableAction;

    /**
     * Its own connection, which is where retry_after lives; see config/queue.php.
     */
    public ?string $connection = 'summaries';

    /**
     * One attempt, deliberately, and the same reasoning as when this was one job: a failure
     * marks the row and the page offers to submit again, so retrying is a decision rather than
     * an automatic second charge for a call that may well fail the same way twice.
     *
     * It matters more in a chain than it did in a job. A step that is retried is retried alone,
     * while the steps behind it wait, so a retry loop here stalls a batch rather than a job.
     */
    public int $tries = 1;

    /**
     * How long this step may run before the worker kills it.
     *
     * The step's budget rather than the attempt's, which is the whole point of splitting: the
     * supervisor and retry_after are ordered against this, so a worker that dies is now taken
     * back in minutes rather than at the end of an hour nobody was using.
     */
    public int $timeout;

    /**
     * The job this step is running as, when it is running as one.
     *
     * Set by App\Jobs\ActionJob::handle(), which looks for exactly this property. It is here for
     * one reason: a step that gives up has to stop the steps queued behind it, and the only
     * handle on those is the batch. Null when a test calls execute() directly, which is why
     * every use of it is null-safe.
     */
    public ?ActionJob $actionJob = null;

    public function __construct()
    {
        $this->timeout = config()->integer('summaries.step_timeout');
    }

    /**
     * What this step looks like on the Horizon dashboard.
     *
     * The step's own class first, so the five are told apart at a glance, then both names for
     * the video: an id is what a log line carries and a code is what somebody watching the queue
     * recognises. The same shape the single job used, so a tag search still finds everything for
     * one video - now five jobs rather than one.
     *
     * @return string[]
     */
    public function tags(int $summaryId, string $claim, string $videoId): array
    {
        return [static::class, 'summary:'.$summaryId, 'video:'.$videoId];
    }

    /**
     * Handle a failure of this step.
     *
     * Recording it on the row is what lets the page stop asking for an answer that is not
     * coming, and it is the same handler the single job had, moved here so that whichever step
     * threw writes it.
     *
     * Nothing cancels the batch here and nothing needs to. A chain stops on its own when a job
     * fails - CallQueuedHandler only dispatches the next one if the job neither failed nor was
     * released - and a batch that is not allowFailures() cancels itself on the first failure.
     *
     * Guarded, because this can fire on a row that already holds a finished summary. A step can
     * succeed and the worker still die before it deletes the job, and the attempt after that one
     * is free to throw. Marking the row failed then would hide a perfectly good summary behind a
     * "did not work" message. The failure is still logged; only the row is left alone.
     *
     * find rather than findOrFail. One reason this runs at all is that a step threw on a row
     * that is not there, and throwing again from the handler for that would lose the exception
     * that explains it.
     */
    public function failed(?Throwable $exception, int $summaryId, string $claim): void
    {
        $summary = Summary::find($summaryId);

        $ready = $summary?->status === SummaryStatus::Ready;

        /*
         * Conditional on the claim for the same reason every other write here is, and this is
         * the one where getting it wrong costs the most. A step can throw ten minutes after it
         * started - a model call is most of that - and by then summaries:expire may have written
         * the attempt off and a resubmission may have started another one. Writing unconditionally
         * would mark that live, half-paid-for attempt as failed, and its remaining steps would
         * then find the row no longer pending and quietly do nothing.
         */
        $ours = $summary?->claim === $claim;

        if ($summary instanceof Summary && $ours && ! $ready) {
            $summary->update([
                'status' => SummaryStatus::Failed,
                'outline' => null,

                /*
                 * The transcript and the ideas are deliberately not cleared alongside it. A step
                 * that threw at the model has both on the row, and leaving them is what lets the
                 * retry be the model call that failed and nothing before it.
                 */

                /*
                 * The first explanation wins. A row already carrying a reason was written off by
                 * summaries:expire, and "it took too long" tells whoever reads it more than the
                 * throw that followed - which is in the log below either way.
                 */
                'error' => $summary->error ?? SummaryError::Unknown,
            ]);
        }

        Log::error('A step of summarising a video failed', [
            'step' => static::class,
            'summary_id' => $summaryId,
            'video_id' => $summary?->video_id,
            'claim' => $claim,
            /* Logged either way: a failure nobody recorded is the one worth being able to find. */
            'recorded' => $ours && ! $ready,
            'already_summarised' => $ready,
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * Ask a model for a structured answer, and turn it into sections.
     *
     * The three lines this replaces were written out in both steps that prompt for one, and a
     * third time in the class they were lifted from - which nothing called, so the only copy with
     * a test on it was the one that never ran. What a structured pass is here is one decision:
     * which timeout it gets, that the SDK really did return a structured response, and that a
     * model's answer is read through parse() rather than from(), because from() is what hydrates
     * a stored row and must not apply the tolerance meant for a model.
     *
     * The timeout is read here rather than passed in, so no caller can forget it. It is the
     * per-prompt budget rather than the step's: three of these fit inside one attempt, and the
     * step's own budget is what the worker enforces around whichever is running.
     */
    protected function sections(Agent&HasStructuredOutput $agent, string $prompt): SummarySections
    {
        $response = $agent->prompt($prompt, timeout: config()->integer('summaries.model_timeout'));

        assert($response instanceof StructuredAgentResponse);

        return SummarySections::parse($response->toArray());
    }

    /**
     * The row this step is working on, or null when it has nothing to do.
     *
     * Three ways to have nothing to do, and none of them is a fault. The row is gone, which
     * summaries:prune can do at any time. The attempt is no longer pending, because something
     * wrote it off or another attempt finished it. Or the claim has moved on, which means these
     * steps belong to an attempt that has been replaced - the case the whole claim exists for.
     *
     * findOrFail is deliberately not used, unlike the single job that came before. A step of a
     * chain that throws takes the batch down with it, and a summary deleted mid-chain is an
     * ordinary outcome of a retention window rather than something worth a stack trace.
     */
    protected function claimed(int $summaryId, string $claim): ?Summary
    {
        $summary = Summary::query()
            ->whereKey($summaryId)
            ->where('status', SummaryStatus::Pending)
            ->where('claim', $claim)
            ->first();

        if (! $summary instanceof Summary) {
            Log::debug('Left a summarising step alone, the attempt it belongs to is over', [
                'step' => static::class,
                'summary_id' => $summaryId,
                'claim' => $claim,
            ]);
        }

        return $summary;
    }

    /**
     * Write what this step produced, if the attempt is still this one.
     *
     * By key and conditional on the claim rather than through the model instance, and both
     * halves matter. Eloquent writes only what it believes has changed, so assigning a column
     * the value the instance already holds - 'error' => null on a row whose error was null when
     * it was read - is left out of the statement entirely, and a reason another process stamped
     * on the row in the meantime survives into a ready summary. This form has no opinion about
     * what the row used to hold: it writes every column named here, once.
     *
     * Conditional, because between this step reading the row and writing it, summaries:expire
     * can write the attempt off and a resubmission can start another one. Naming the claim makes
     * that write affect nothing at all, which is what it should do.
     *
     * @param  array<string, mixed>  $values
     */
    protected function write(int $summaryId, string $claim, array $values): void
    {
        $written = Summary::query()
            ->whereKey($summaryId)
            ->where('claim', $claim)
            ->update($values);

        if ($written === 0) {
            /*
             * The work was done and thrown away, which is worth a line: it is the only way to
             * tell this apart from an ordinary success in a worker log, and a steady trickle of
             * it means attempts are being written off while their workers are still alive -
             * which is a horizon that wants lengthening rather than one video that went wrong.
             */
            Log::warning('A summarising step finished work that had already been superseded', [
                'step' => static::class,
                'summary_id' => $summaryId,
                'claim' => $claim,
            ]);
        }
    }

    /**
     * Stop, with a reason somebody can read, and take the rest of the chain with it.
     *
     * Cancelling is the new half and it is not optional. Returning from a step does not stop the
     * chain: CallQueuedHandler dispatches the next job whenever the current one neither failed
     * nor was released, so a video with no captions would go on to be asked about by a model
     * three times. Cancelling the batch marks it, and SkipIfBatchCancelled - added to every
     * action by App\Jobs\ActionJob - is what makes the remaining steps look before they run.
     *
     * Through the instance rather than by key, unlike write() above: every column named here
     * differs from what that instance holds, so Eloquent has no chance to decide nothing changed.
     *
     * It does overwrite a reason another process may have recorded, which is the opposite of what
     * failed() does, and that asymmetry is deliberate. summaries:expire can write a row off as
     * timed_out while a step is still working, and "that video has no subtitles" is both truer
     * and more useful than "this took too long". The step has looked; the horizon only guessed.
     */
    protected function giveUp(Summary $summary, string $claim, SummaryError $error): void
    {
        /*
         * Through write() rather than through the instance, which is a change from the single job
         * this came from. There, giving up happened moments after the claim was checked; here a
         * step can spend four minutes in yt-dlp before deciding, and an attempt written off and
         * replaced in that window would otherwise be written off again on the new attempt's row.
         *
         * It does still overwrite a reason another process recorded, which is the opposite of what
         * failed() does and is deliberate: summaries:expire can write a row off as timed_out while
         * a step is still working, and "that video has no subtitles" is both truer and more useful
         * than "this took too long". The step has looked; the horizon only guessed. Overwriting is
         * safe precisely because the claim still matches - the row is this attempt's.
         */
        $this->write($summary->id, $claim, [
            'status' => SummaryStatus::Failed,
            'error' => $error,
        ]);

        /*
         * Cancelled whether or not that write landed. The batch is this attempt's either way, and
         * stopping its remaining steps is right even when the row has moved on without them.
         */
        $this->actionJob?->batch()?->cancel();

        Log::info('Gave up on a video part way through summarising it', [
            'step' => static::class,
            'video_id' => $summary->video_id,
            'error' => $error->value,
        ]);
    }
}
