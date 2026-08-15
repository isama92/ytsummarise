<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Summarising\ComposeSummary;
use App\Actions\Summarising\DraftIdeas;
use App\Actions\Summarising\FetchCaptions;
use App\Actions\Summarising\FindVideo;
use App\Actions\Summarising\TranslateOutline;
use App\Enums\Queue;
use App\Enums\SummaryError;
use App\Enums\SummaryStatus;
use App\Jobs\ActionJob;
use App\Models\Summary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\QueueableAction\QueueableAction;
use Throwable;

/**
 * Starts the summary for one video, and does none of it.
 *
 * Here rather than under either service because the sequence spans both: looking a video up and
 * fetching its captions belong to App\Services\YouTube, summarising them belongs to
 * App\Services\Ai, and the order they run in belongs to neither.
 *
 * What this now does is claim the row and dispatch a named batch holding a chain of five steps,
 * which live in App\Actions\Summarising. It used to do all five itself, in one job, which worked
 * and told nobody anything: a video was either running or not for up to an hour, and the Horizon
 * dashboard could not say which of the five it was on or how much was left to pay for.
 *
 * A batch holding one chain, rather than a chain on its own, because only a batch has a name and
 * a count. Laravel treats a nested array inside Bus::batch() as a chain - Batch::add() splits it
 * with prepareBatchedChain() and adds every job in it to totalJobs - so this is one thing on the
 * Batches tab, counting one to five, running strictly in order.
 *
 * This action stays keyed and the steps deliberately are not, which is worth understanding
 * before changing either. The lock is taken here, at an ordinary dispatch, so two people
 * retrying one failed video in the same instant queue one batch between them. It could not be
 * taken on the steps: a batch reaches the queue through Queue::bulk(), which never consults
 * Illuminate\Bus\UniqueLock, while a chain continuation goes through a PendingDispatch, which
 * does - so a keyed step could be swallowed mid-chain and leave a batch that never finishes.
 *
 * Which leaves the lock covering only the moment of dispatch, because it is released as soon as
 * this returns rather than when the batch finishes. That is what the claim below is for, and it
 * is the guarantee rather than the optimisation: it is decided by the database, it has no TTL,
 * and every one of the five steps writes conditionally on it.
 *
 * Takes a row id and nothing else, and hands the steps that id and the claim. Not for the
 * payload's sake - SerializesModels already reduced a model parameter to a class and a key - but
 * so that there is one way the row is obtained rather than two. A restored job re-queried it
 * while one run in process kept whatever instance it was handed, and a test that reused one
 * instance for two runs passed with the claim deleted outright because the first call had
 * already mutated it to ready in memory.
 */
class SummariseVideo
{
    use QueueableAction;

    /**
     * Its own connection, which is where its retry_after lives; see config/queue.php.
     *
     * A plain property, unlike the onConnection() call this needed as a job: that was a way
     * around Illuminate\Foundation\Queue\Queueable declaring an untyped $connection a typed
     * override could not narrow, and an action uses none of it. ActionJob's parent copies
     * this onto the job at dispatch.
     */
    public ?string $connection = 'summaries';

    /**
     * One attempt, deliberately.
     *
     * A failure marks the row and the page offers to submit again, so retrying is a
     * decision rather than an automatic second charge for a call that may well fail the
     * same way twice.
     */
    public int $tries = 1;

    /**
     * How long this may run before the worker kills it.
     *
     * A step's budget rather than an attempt's, and generous even so: all this does is one read,
     * one conditional update and a batch dispatch. The five steps behind it each get the same
     * budget, and none of them is this one.
     */
    public int $timeout;

    /**
     * How long the uniqueness lock survives.
     *
     * Only ever a backstop now. Laravel releases the lock when this action finishes, which is
     * long before the batch it dispatched does, so this number is what bounds a lock left behind
     * by a worker that died between taking it and returning. The whole attempt's budget, because
     * that is the longest anything about this video is legitimately in flight.
     *
     * It is not what makes summarising twice impossible, and it was a mistake to treat it as
     * though it were even when this was one job. The claim in execute() is the guarantee.
     */
    public int $uniqueFor;

    public function __construct()
    {
        $this->timeout = config()->integer('summaries.step_timeout');
        $this->uniqueFor = config()->integer('summaries.timeout');
    }

    /**
     * One batch in flight per video.
     *
     * Keyed on the row and not on the video code, which is the same key under another name:
     * video_id carries a unique index, so a row is a video and there can never be a second
     * row to key a second batch on. The row's id is the one the caller already holds without
     * going to the database to ask for it.
     *
     * ActionJob qualifies this with the action's own name before it becomes a lock key, so two
     * actions keyed on the same row do not collide.
     */
    public function uniqueId(int $summaryId): string
    {
        return (string) $summaryId;
    }

    /**
     * What this looks like on the Horizon dashboard.
     *
     * Both names for the same thing, because the two questions asked of that dashboard are
     * asked in different currencies: an id is what a log line or a support message carries,
     * and a video code is what somebody watching the queue actually recognises. The five steps
     * tag themselves the same way, so one tag search finds the whole attempt.
     *
     * The argument is required, which is only safe because App\Jobs\ActionJob hands its parent a
     * class name rather than this instance: Spatie's own constructor asks an action for its tags
     * before it knows what the action is being run over, and it is that call - the one that never
     * happens here - which would need a default to survive.
     *
     * @return string[]
     */
    public function tags(int $summaryId): array
    {
        $tags = [self::class, 'summary:'.$summaryId];

        $videoId = Summary::whereKey($summaryId)->value('video_id');

        /* Absent only if the row has gone between dispatch and here, which is not worth failing over. */
        if (is_string($videoId)) {
            $tags[] = 'video:'.$videoId;
        }

        return $tags;
    }

    /**
     * Claim the attempt and queue the five steps that make up a summary.
     *
     * Nothing is paid for here and nothing takes any time, which is the shape of it: everything
     * expensive is in the batch, so this returns almost immediately and the worker moves on to
     * the first step.
     */
    public function execute(int $summaryId): void
    {
        /*
         * Loaded here rather than carried, so what this reads is what is in the database at
         * the moment it runs rather than whatever was true when the job was queued. findOrFail
         * because a summary is never deleted by anything but retention: if one has been, that is
         * worth a failure and a log line rather than a job that quietly does nothing.
         */
        $summary = Summary::findOrFail($summaryId);

        /*
         * Anything but pending and there is nothing to do here.
         *
         * Ready covers a job delivered twice, which happens however careful the configuration
         * is: a worker killed between finishing and deleting the job leaves it to be reserved
         * again. Without this a second batch is queued for a summary somebody is already
         * reading, and every model call in it is paid for.
         *
         * Failed covers the expiry command having given up on this attempt while the job sat in
         * the queue. Nothing is paid for a summary the page has already said did not work and
         * offered to try again; whoever asks again starts a fresh attempt.
         */
        if ($summary->status !== SummaryStatus::Pending) {
            return;
        }

        /*
         * Claim the row before queueing anything that costs money.
         *
         * Conditional on started_at still being null, so of any number of dispatches for this
         * video exactly one update affects a row and the rest return having done nothing. The
         * database decides, which is what makes it the guarantee: the uniqueness lock cannot be
         * one, because it is released when this action returns rather than when its batch
         * finishes, and because its TTL starts at dispatch rather than when a worker picks the
         * job up.
         *
         * That is not hypothetical. summaries:expire writes an attempt off after a horizon far
         * longer than the lock's lifetime, and asking again after that dispatches a second job
         * while the first batch may still be running - by which point the first lock lapsed
         * hours ago. This is what stops both of them paying.
         *
         * The status is checked again as part of the same update rather than trusted from the
         * read above, because the two are not one statement: summaries:expire can write this
         * attempt off in between, and claiming it then pays for a summary the page has already
         * said did not work.
         *
         * Two columns, doing two different jobs. started_at records when the work began, which is
         * what the page counts up from and what tells a queued attempt from a running one. The
         * claim identifies the attempt, and it is a token rather than that moment because a
         * timestamp cannot do it: the column is a timestamp(0) and the query grammar binds dates
         * to the second on both the write and the comparison, so two attempts inside one second
         * would be indistinguishable and the older one's steps would write over the newer one's
         * work. Every step is handed the token and writes conditionally on it.
         */
        $claim = (string) Str::ulid();

        $claimed = Summary::query()
            ->whereKey($summaryId)
            ->where('status', SummaryStatus::Pending)
            ->whereNull('started_at')
            ->update(['started_at' => Date::now(), 'claim' => $claim]);

        if ($claimed === 0) {
            /*
             * Two ways to be here and neither is a problem: somebody else holds the claim, or
             * the attempt was written off between the read above and this update. Debug rather
             * than a warning, because a duplicate bouncing off a live claim is the mechanism
             * working.
             *
             * Logged at all because this return is otherwise indistinguishable from success in a
             * worker log, and a row left holding a stale claim would look exactly the same while
             * never being summarised.
             */
            Log::debug('Left a video alone, already claimed or given up on', [
                'video_id' => $summary->video_id,
            ]);

            return;
        }

        /*
         * One chain, wrapped in an array so that Bus::batch() reads it as a chain rather than as
         * five jobs to run at once. Ordering is the whole point: every step reads what the one
         * before it wrote.
         *
         * The name is what makes the batch findable by somebody looking into a particular video,
         * so it carries both the code and the row id. job_batches.name is a plain string column
         * and this cannot approach any sensible length limit.
         *
         * The connection and queue are named on the batch rather than left to the steps. The
         * steps declare a connection of their own and Batch::add() would honour it, but the
         * batch's options are what the chain's continuations inherit, so saying it once here is
         * what stops step two landing on the default queue where nothing works it.
         */
        Bus::batch([$this->chainActions($summaryId, $claim, $summary->video_id)])
            ->name("Summarise {$summary->video_id} ({$summaryId})")
            ->onConnection('summaries')
            ->onQueue(Queue::Summaries->value)
            ->dispatch();
    }

    /**
     * Handle a failure of the dispatch itself.
     *
     * Small window, and an expensive one to leave open. Everything between claiming the row and
     * the batch reaching Redis happens here: if the dispatch throws - Redis unreachable, the
     * job_batches insert refused - the row is left pending with a claim on it and no batch behind
     * it. The controller reads a pending row as an attempt already under way and queues nothing
     * for whoever asks again, and the claim turns away any dispatch that does get through, so
     * without this the video is stuck until summaries:expire notices hours later, with nothing in
     * the log to say why.
     *
     * This action lost its handler when it stopped doing the work, and the steps that inherited
     * one cannot cover this: the failure is before any of them exists.
     *
     * Guarded against a finished summary the same way a step's is, and for the same reason: this
     * can fire on a redelivery of a dispatch that already succeeded.
     */
    public function failed(?Throwable $exception, int $summaryId): void
    {
        $summary = Summary::find($summaryId);

        $ready = $summary?->status === SummaryStatus::Ready;

        if ($summary instanceof Summary && ! $ready) {
            $summary->update([
                'status' => SummaryStatus::Failed,

                /*
                 * The first explanation wins, as everywhere else: a row already carrying a reason
                 * was written off by summaries:expire, and that tells whoever reads it more than
                 * a dispatch that then threw.
                 */
                'error' => $summary->error ?? SummaryError::Unknown,
            ]);
        }

        Log::error('Queueing a summary failed', [
            'summary_id' => $summaryId,
            'video_id' => $summary?->video_id,
            'already_summarised' => $ready,
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * The five steps, in the order they have to run.
     *
     * Every one takes the same three arguments. The id says which row and the token says which
     * attempt on it, and both are read by every step. The video code is only ever wanted for the
     * Horizon tag, which is why no step's execute() declares it: passing it costs nothing and
     * saves five identical reads of an immutable column while this chain is being built. See App\Actions\Summarising\SummarisingStep for what a
     * step does with them.
     *
     * TranslateOutline is in the list for every video, including the English ones it has nothing
     * to translate. A step left out for some videos would make the batch four jobs for one and
     * five for another, so the number on the dashboard would mean a different thing per video.
     *
     * @return list<ShouldQueue>
     */
    private function chainActions(int $summaryId, string $claim, string $videoId): array
    {
        return [
            new ActionJob(FindVideo::class, [$summaryId, $claim, $videoId]),
            new ActionJob(FetchCaptions::class, [$summaryId, $claim, $videoId]),
            new ActionJob(DraftIdeas::class, [$summaryId, $claim, $videoId]),
            new ActionJob(ComposeSummary::class, [$summaryId, $claim, $videoId]),
            new ActionJob(TranslateOutline::class, [$summaryId, $claim, $videoId]),
        ];
    }
}
