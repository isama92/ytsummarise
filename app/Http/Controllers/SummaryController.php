<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SummaryStatus;
use App\Http\Requests\SummaryRequest;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

class SummaryController extends Controller
{
    /**
     * The summariser with nothing summarised.
     */
    public function index(): Response
    {
        return $this->page(null);
    }

    /**
     * One summary, at a url that cannot be guessed or enumerated.
     *
     * Resolved by uuid through the RouteKey attribute on the model. A uuid that is not
     * one never reaches the database: HasUniqueStringIds checks the format while
     * resolving the binding and throws ModelNotFoundException itself, so no route
     * constraint is needed to keep junk out of the query.
     *
     * This is also what the page polls while a summary is being produced.
     */
    public function show(Summary $summary): Response
    {
        return $this->page($summary);
    }

    /**
     * Queue the work for one video and hand the browser its url.
     */
    public function store(SummaryRequest $request): RedirectResponse
    {
        $videoId = $request->string('video_id')->toString();

        $summary = Summary::query()->firstOrCreate(
            ['video_id' => $videoId],
            ['status' => SummaryStatus::Pending, 'requested_at' => Date::now()],
        );

        /*
         * A video somebody already summarised costs nothing and is answered as it stands.
         */
        if ($summary->status === SummaryStatus::Ready) {
            return redirect()->route('summaries.show', $summary);
        }

        /*
         * Three ways the attempt on this row is over: it failed, its worker went missing
         * mid job, or nothing ever picked it up and enough time has passed to stop
         * expecting anything to. Each starts over, and the clock restarts with them.
         *
         * So does the claim: leaving started_at set would make the row unclaimable, and
         * every job queued for it from then on would find somebody else apparently working
         * and return having done nothing.
         *
         * A row somebody is working on, or one still plausibly waiting its turn, is left
         * exactly as it is. Whoever asked first is already waiting, and restarting their
         * clock would mislead them; the dispatch below is what joins them to it.
         */
        if (
            $summary->status === SummaryStatus::Failed
            || $summary->isStalled()
            || $summary->hasWaitedTooLong()
        ) {
            $summary->update([
                'status' => SummaryStatus::Pending,
                'body' => null,
                'requested_at' => Date::now(),
                'started_at' => null,
            ]);
        }

        /*
         * Before the job, not inside it, so the page has a heading to show for the whole
         * wait instead of an anonymous skeleton. Only when it is missing: the title of a
         * video does not change between attempts, and once this is a real lookup a
         * resubmit should not pay for it again.
         */
        if ($summary->title === null) {
            $summary->update(['title' => $this->titleFor($videoId)]);
        }

        /*
         * Dispatched for anything not ready, including a row already pending. That is not a
         * duplicate: the job is unique per video, so while one is in flight this is dropped
         * and the browser simply joins it. Should the lock have lapsed and two end up
         * queued, the claim in the job settles which one does the work.
         *
         * Asking again for a row that failed always reaches the queue, which is less obvious
         * than it looks. A job that failed by throwing releases the lock on its way out. A
         * job whose worker was killed releases nothing, but its lock runs from when it was
         * dispatched while the row is only written off a whole timeout after the work
         * started - and work starts no earlier than dispatch, so by the time anybody can see
         * a failure to resubmit, the lock has already lapsed.
         */
        SummariseVideo::dispatch($summary);

        return redirect()->route('summaries.show', $summary);
    }

    /**
     * The video's title.
     *
     * Stands in for a lookup against YouTube, which is why it is resolved here in the
     * request rather than in the job: the point of having a title is to show it while the
     * summary is still being produced. When the real lookup replaces this, it belongs in a
     * class of its own with a timeout and a failure path that returns null - a slow or
     * broken YouTube must not stop a video being queued.
     */
    private function titleFor(string $videoId): string
    {
        return "Placeholder title for {$videoId}";
    }

    /**
     * The one screen this application has, with or without something on it.
     *
     * videoId is not for the field, which is deliberately always empty: the page keys the
     * summary on it so that asking for a different video replays the fade in rather than
     * swapping the text underneath it. Deleting it as unused would quietly kill that.
     */
    private function page(?Summary $summary): Response
    {
        return Inertia::render('home', [
            'videoId' => $summary?->video_id,
            'summary' => $summary instanceof Summary ? [
                'status' => $summary->status,
                'title' => $summary->title,
                'body' => $summary->body,

                /*
                 * What the page counts up from while it waits. Somebody who joins a job
                 * already running sees the time it has really taken so far rather than
                 * starting from zero.
                 */
                'requestedAt' => $summary->requested_at->toIso8601String(),

                /*
                 * Null until a worker begins, which is the difference between waiting in a
                 * queue and being worked on. The page says which; the wording lives there.
                 */
                'startedAt' => $summary->started_at?->toIso8601String(),
            ] : null,
        ]);
    }
}
