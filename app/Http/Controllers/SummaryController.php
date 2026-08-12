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

        $summary = Summary::firstOrCreate(
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
         * Whether this request starts an attempt, answered before anything is written.
         *
         * Two ways it does: this request created the row, or the last attempt on it failed
         * and somebody is asking again. Everything else is an attempt already under way, and
         * this request joins it rather than starting a second - the page picks up the clock
         * already running and says whether a worker has reached it yet.
         *
         * A pending row is left exactly as it is, however long it has been pending.
         * Restarting its clock would mislead whoever is already waiting on it, and queueing
         * a second job would be paying twice for one video. When nothing is going to come of
         * it, summaries:expire says so, and asking again after that is a retry.
         */
        $startsAttempt = $summary->wasRecentlyCreated
            || $summary->status === SummaryStatus::Failed;

        /*
         * A retry is a new attempt, so its clock starts again - which also puts it back at
         * the beginning of the horizon summaries:expire measures.
         *
         * The claim goes with it. Leaving started_at set would make the row unclaimable, and
         * every job queued for it from then on would find somebody else apparently working
         * on it and return having done nothing at all.
         */
        if ($summary->status === SummaryStatus::Failed) {
            $summary->update([
                'status' => SummaryStatus::Pending,
                'outline' => null,

                /*
                 * The transcript stays. It belongs to the video rather than to the attempt, and
                 * leaving it is what lets a retry after a failed model call skip yt-dlp
                 * entirely and re-read exactly the words the failed attempt did. The job picks
                 * it up if it is there; see SummariseVideo::transcriptFor().
                 */

                /*
                 * The reason goes with it, for the same reason the body does: it explains an
                 * attempt that is over, and leaving it would have the page explaining why the
                 * attempt now running has failed while it is still running.
                 */
                'error' => null,
                'requested_at' => Date::now(),
                'started_at' => null,
            ]);
        }

        /*
         * The only place a job is queued, and only for a request that started an attempt.
         *
         * Two people asking for the same new video in the same instant both reach
         * firstOrCreate and one of them loses to the unique index on video_id, so only the
         * one that created the row dispatches. Two retrying the same failed row both do, and
         * the uniqueness lock drops the second before it reaches the queue.
         */
        if ($startsAttempt) {
            SummariseVideo::dispatch($summary->id);
        }

        return redirect()->route('summaries.show', $summary);
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

                /*
                 * The summary itself: a language, the version written in it, and an English
                 * translation of that where the language was not English already.
                 *
                 * Handed over as it is stored rather than rebuilt into a data object first. The
                 * page is the only thing that reads it, it reads every part, and putting a
                 * transformation in between would be one more place for the shape to disagree
                 * with resources/js/types/summary.ts.
                 *
                 * The transcript is deliberately not here. It is the raw material rather than
                 * the answer, it runs to tens of thousands of words, and every one of those
                 * would travel with every poll while the page waits.
                 */
                'outline' => $summary->outline,

                /*
                 * Why a failed attempt failed, as a code the page turns into a sentence of
                 * its own; see lang/en/summaries.php. Null for anything that has not failed.
                 */
                'error' => $summary->error,

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
