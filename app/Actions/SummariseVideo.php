<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SummaryError;
use App\Enums\SummaryStatus;
use App\Models\Summary;
use App\Services\Ai\Actions\SummariseTranscript;
use App\Services\YouTube\Actions\FetchTranscript;
use App\Services\YouTube\Actions\LookupVideo;
use App\Services\YouTube\Data\TranscriptResult;
use App\Services\YouTube\Enums\TranscriptPresence;
use App\Services\YouTube\Enums\VideoPresence;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Spatie\QueueableAction\QueueableAction;
use Throwable;

/**
 * Produces the summary for one video.
 *
 * Here rather than under either service because it is the one piece of work that spans both:
 * looking a video up and fetching its captions belong to App\Services\YouTube, summarising them
 * belongs to App\Services\Ai, and the order the three run in belongs to neither.
 *
 * Queued through App\Jobs\ActionJob, which is what makes it unique per video. The work
 * behind this is a paid model call, so two people retrying the same failed video in the same
 * instant both find it failed, both start an attempt and both dispatch; the lock drops the
 * second before it reaches the queue.
 *
 * Not the concurrent-create case, which never reaches here: video_id carries a unique index,
 * so of two requests for a video nobody has asked for yet only one creates the row, and only
 * the one that created it dispatches.
 *
 * Takes a row id and nothing else. Not for the payload's sake - SerializesModels already reduced
 * a model parameter to a class and a key - but so that there is one way the row is obtained
 * rather than two. A restored job re-queried it while one run in process kept whatever instance
 * it was handed, and a test that reused one instance for two runs passed with the claim deleted
 * outright because the first call had already mutated it to ready in memory. Loading it in
 * execute() means every caller gets what a worker would get.
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
     *
     * No queue named alongside it, because the connection already answers that: it defaults to
     * Queue::Summaries, which is the one supervisor-summaries works.
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
     * claim in execute() is the guarantee; this is the optimisation that usually saves us
     * needing it.
     */
    public int $uniqueFor;

    /**
     * The collaborators, taken here rather than by the method that uses them.
     *
     * Nothing about them reaches the queue payload either way, which was the reason the job
     * this replaced took them as arguments to handle(): the worker resolves the action from
     * the container before calling execute(), so the constructor is the container's business
     * and not the payload's. A test can still swap any of them in the container.
     *
     * Worth knowing when adding another: the package resolves the action once per queueable
     * property it copies, so this runs a handful of times per dispatch, in the web request.
     * Cheap while every dependency is cheap to build, and only that while.
     */
    public function __construct(
        private readonly LookupVideo $lookupVideo,
        private readonly FetchTranscript $fetchTranscript,
        private readonly SummariseTranscript $summariseTranscript,
    ) {
        $this->timeout = config()->integer('summaries.timeout');
        $this->uniqueFor = $this->timeout;
    }

    /**
     * One job in flight per video.
     *
     * Keyed on the row and not on the video code, which is the same key under another name:
     * video_id carries a unique index, so a row is a video and there can never be a second
     * row to key a second job on. The row's id is the one the caller already holds without
     * going to the database to ask for it.
     *
     * ActionJob qualifies this with the action's own name before it becomes a lock key,
     * so two actions keyed on the same row do not collide.
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
     * and a video code is what somebody watching the queue actually recognises.
     *
     * The code is looked up rather than carried, so execute() keeps the single parameter it
     * needs and nothing is passed along purely to be printed. One indexed read at dispatch.
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

        $videoId = Summary::query()->whereKey($summaryId)->value('video_id');

        /* Absent only if the row has gone between dispatch and here, which is not worth failing over. */
        if (is_string($videoId)) {
            $tags[] = 'video:'.$videoId;
        }

        return $tags;
    }

    /**
     * Summarise the video.
     *
     * Three things happen here in an order chosen by what each one costs. The video is looked
     * up, because a video that does not exist should not have a transcript fetched for it. The
     * transcript is fetched, because a video with no captions should not have a model asked
     * about it. Only then is anything summarised. Each step can write the row off on its own,
     * and the two before the last are the cheap ones on purpose.
     *
     * Every timeout involved is set rather than inherited. The prompts get
     * summaries.model_timeout, well past the SDK's sixty second default; the whole job gets
     * summaries.timeout; and the connection's retry_after is derived from that in config/queue.php
     * so the queue cannot hand this to a second worker while the first is still running. The claim
     * below makes that safe in any case, but a second worker producing a summary nobody reads is
     * still waste.
     *
     * The tests call this method directly rather than dispatching, because naming a connection
     * above overrides the sync default in phpunit.xml - ->onQueue()->execute() is queued rather
     * than run. Worth knowing before writing a test that expects a submitted video to be
     * summarised by the time the request comes back: it will not be.
     */
    public function execute(int $summaryId): void
    {
        /*
         * Loaded here rather than carried, so what this reads is what is in the database at
         * the moment it runs rather than whatever was true when the job was queued. findOrFail
         * because a summary is never deleted: if one has been, that is worth a failure and a
         * log line rather than a job that quietly does nothing.
         */
        $summary = Summary::findOrFail($summaryId);

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
         * measure the timeout against - and the moment is kept, because the final write names
         * it to prove the claim is still this job's. See the end of this method.
         */
        $claimedAt = Date::now();

        $claimed = Summary::query()
            ->whereKey($summaryId)
            ->where('status', SummaryStatus::Pending)
            ->whereNull('started_at')
            ->update(['started_at' => $claimedAt]);

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

        /*
         * What the video actually is, asked before anything is paid for.
         *
         * After the claim, not before it: an unclaimed job has no business spending anybody's
         * rate limit, and every duplicate would otherwise make the same two requests before
         * discovering it had nothing to do.
         */
        $video = $this->lookupVideo->execute($summary->video_id);

        $error = match ($video->presence) {
            VideoPresence::Missing => SummaryError::NotFound,
            VideoPresence::Unknown => SummaryError::Unreachable,
            VideoPresence::Found => null,
        };

        /*
         * Written off here rather than by throwing, for two reasons. A video that does not
         * exist is an ordinary outcome and does not deserve a stack trace in the log, and
         * everything below it is never reached - which is what stops a model being asked about
         * a video nobody can watch.
         */
        if ($error instanceof SummaryError) {
            $this->giveUp($summary, $error);

            return;
        }

        $transcript = $this->transcriptFor($summary);

        $transcriptError = match ($transcript->presence) {
            TranscriptPresence::Missing => SummaryError::NoTranscript,
            TranscriptPresence::Unavailable => SummaryError::Unavailable,
            TranscriptPresence::Found => null,
        };

        /*
         * The second of the two cheap refusals, and the last chance to make one. A video with
         * no captions has nothing to summarise however capable the model is, and the difference
         * between that and not having been able to fetch them is the difference between a
         * message that invites another attempt and one that does not.
         */
        if ($transcriptError instanceof SummaryError) {
            $this->giveUp($summary, $transcriptError);

            return;
        }

        /*
         * Stored before the model is asked anything, which is the point of storing it at all.
         * The two expensive steps fail independently: fetching this is what YouTube can refuse,
         * and summarising it is what can come back unusable. Written now rather than with the
         * summary means a failure of the second kind leaves the transcript behind, and the
         * retry re-runs only the model over exactly the words this attempt read.
         *
         * The language goes with it because nothing can recover it by looking at the text, and
         * without it a reused transcript could not be told whether it needs translating.
         */
        $summary->update([
            'transcript' => $transcript->text,
            'transcript_language' => $transcript->language,
        ]);

        $outline = $this->summariseTranscript->execute($transcript);

        /*
         * By key, and not $summary->update() like the write fifteen lines above it. The
         * difference is 'error' => null, and it is not a style choice.
         *
         * Eloquent writes only what it believes has changed, and this instance last saw the row
         * before the work started, when the error column was already null. Assigning null to it
         * is therefore not a change, so Eloquent leaves the column out of the statement
         * entirely. Meanwhile summaries:expire can have stamped a reason on the row while this
         * job was legitimately working - its horizon is deliberately blunt - and the result is a
         * ready summary that still says why it failed. The assignment looks clean and does
         * nothing, which is the worst shape a bug can have.
         *
         * This form has no opinion about what the row used to hold: it writes every column named
         * here, once, whatever anybody else did in between. The write above can be an ordinary
         * model update because nothing else writes those two columns, so there is no value it
         * could be asked to clear back to what it already believes.
         *
         * "a summary that finishes after being written off does not keep the reason" is the test
         * that fails if this is simplified.
         *
         * Conditional on the claim, though, which is the one thing it does have an opinion
         * about. Without that this job writes its summary whatever has happened to the row since
         * it started, and there is a way for that to be the wrong summary: summaries:expire
         * writes a long-queued attempt off, somebody submits the video again, the controller
         * clears started_at and a second job claims the row and starts work - and then this one
         * finishes and stamps its own older outline over an attempt that replaced it, with the
         * newer job still running behind it. Naming the moment this job claimed makes that write
         * affect nothing at all, which is what it should do.
         */
        $written = Summary::query()
            ->whereKey($summaryId)
            ->where('started_at', $claimedAt)
            ->update([
                'status' => SummaryStatus::Ready,

                /*
                 * Written with the summary rather than before it, so the page shows a heading and
                 * the text it belongs to at the same moment instead of a title sitting over a
                 * skeleton. Null when the lookup found the video but was not allowed to name it.
                 */
                'title' => $video->title,
                'outline' => $outline->toArray(),

                /* Cleared rather than left alone, for the reason above. */
                'error' => null,
            ]);

        if ($written === 0) {
            /*
             * The work was done and thrown away, which is worth a line: it is the only way to
             * tell this apart from an ordinary success in a worker log, and a steady trickle of
             * it means attempts are being written off while their workers are still alive -
             * which is a horizon that wants lengthening rather than one video that went wrong.
             */
            Log::warning('Finished a summary that had already been superseded', [
                'video_id' => $summary->video_id,
                'claimed_at' => $claimedAt->toIso8601String(),
            ]);
        }
    }

    /**
     * Handle a failure.
     *
     * Recording it on the row is what lets the page stop asking for an answer that is not
     * coming.
     *
     * The id arrives as an argument rather than off the instance, and that is the whole reason
     * ActionJob overrides failed(): the package hands its own handler the exception and
     * nothing else, having captured a callback bound to the action - which puts the action, and
     * everything it was built with, into the queue payload. Resolving it fresh and passing the
     * parameters back is what keeps the payload to an id.
     *
     * Guarded, because this can fire on a row that already holds a finished summary.
     * execute() can succeed and the worker still die before it deletes the job, and the
     * attempt after that one is free to throw. Marking the row failed then would hide a
     * perfectly good summary behind a "did not work" message. The failure is still
     * logged; only the row is left alone.
     *
     * find rather than findOrFail, unlike execute(). One reason this runs at all is that
     * execute() threw on a row that is not there, and throwing again from the handler for
     * that would lose the exception that explains it. The log line carries the id either
     * way, which is the only thing left to go on when the row is gone.
     */
    public function failed(?Throwable $exception, int $summaryId): void
    {
        $summary = Summary::find($summaryId);

        $ready = $summary?->status === SummaryStatus::Ready;

        if ($summary instanceof Summary && ! $ready) {
            $summary->update([
                'status' => SummaryStatus::Failed,
                'outline' => null,

                /*
                 * The transcript is deliberately not cleared alongside it. A job that threw at
                 * the model has one on the row, and leaving it is what lets the retry be a model
                 * call and nothing more; see transcriptFor().
                 */

                /*
                 * The first explanation wins. A row already carrying a reason was written off
                 * by summaries:expire, and "it took too long" tells whoever reads it more than
                 * the throw that followed - which is in the log below either way.
                 */
                'error' => $summary->error ?? SummaryError::Unknown,
            ]);
        }

        Log::error('Summarising a video failed', [
            'summary_id' => $summaryId,
            'video_id' => $summary?->video_id,
            'already_summarised' => $ready,
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * The words to summarise, fetched or remembered.
     *
     * A row that already holds one is one whose last attempt got this far and then failed at the
     * model, and the retry that produced this job left the transcript alone precisely so it
     * could be picked up again. Reusing it means the retry is a model call and nothing else: no
     * second process, no second request to YouTube, and the new attempt reads exactly the words
     * the failed one did rather than whatever the captions say today.
     *
     * Both columns or neither. They are written in one statement above, so a row holding one
     * without the other is not something that happens; the check is what makes the language safe
     * to hand over as a string rather than something to re-derive.
     */
    private function transcriptFor(Summary $summary): TranscriptResult
    {
        if ($summary->transcript !== null && $summary->transcript_language !== null) {
            Log::debug('Summarising a video from the transcript already on the row', [
                'video_id' => $summary->video_id,
            ]);

            return TranscriptResult::found($summary->transcript, $summary->transcript_language);
        }

        return $this->fetchTranscript->execute($summary->video_id);
    }

    /**
     * Stop, with a reason somebody can read.
     *
     * Through the instance rather than by key, unlike the final write: every column named here
     * differs from what that instance holds, so Eloquent has no chance to decide nothing
     * changed. Status goes from pending to failed and the reason from null to something, both
     * on every path that reaches this.
     *
     * It does overwrite a reason another process may have recorded, which is the opposite of
     * what failed() does, and that asymmetry is deliberate. summaries:expire can write a row
     * off as timed_out while this job is still working - the transcript branch above reaches
     * here after a subprocess and an http request, so the window is real - and "that video has
     * no subtitles" is both truer and more useful than "this took too long". The job has
     * looked; the horizon only guessed. failed() keeps the first reason instead because a job
     * that threw has nothing better to offer than "unknown".
     */
    private function giveUp(Summary $summary, SummaryError $error): void
    {
        $summary->update([
            'status' => SummaryStatus::Failed,
            'error' => $error,
        ]);

        Log::info('Gave up on a video before summarising it', [
            'video_id' => $summary->video_id,
            'error' => $error->value,
        ]);
    }
}
