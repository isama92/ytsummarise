<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SummariseVideo;
use App\Enums\SummaryStatus;
use App\Http\Requests\SummaryRequest;
use App\Models\Summary;
use App\Services\YouTube\Actions\FetchCover;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * The video's cover image, straight off the disk.
     *
     * Streamed through the application rather than served as a static file, which is the whole
     * reason the video-covers disk has no url of its own: this route is inside the auth group,
     * so an image is exactly as reachable as the summary it belongs to and no more.
     *
     * A row with no cover 404s rather than answering with a placeholder. Nothing asks for one
     * blindly - the page is told whether there is an image before it renders an img at all -
     * so reaching here for a file that is not there means a cover deleted underneath a page
     * that was already open, and a 404 is what that is.
     */
    public function cover(Summary $summary): StreamedResponse
    {
        $disk = Storage::disk(FetchCover::DISK);

        abort_unless($disk->exists($summary->file_name), 404);

        return $disk->response($summary->file_name, headers: [
            /*
             * Private because the route is behind authentication and a shared cache must not
             * hold it, and long because the content behind this url cannot change: the uuid
             * names one row, a row names one video, and a video's cover is fetched once.
             */
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
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
         * The claim goes with it, both halves. Leaving started_at set would make the row
         * unclaimable, and every job queued for it from then on would find somebody else
         * apparently working on it and return having done nothing at all. Leaving the token
         * behind would be worse and quieter: the old attempt's steps are still out there, and a
         * token they still match is a licence to write their older summary over whatever the new
         * attempt produces.
         */
        if ($summary->status === SummaryStatus::Failed) {
            $summary->update([
                'status' => SummaryStatus::Pending,
                'outline' => null,

                /*
                 * The transcript stays. It belongs to the video rather than to the attempt, and
                 * leaving it is what lets a retry after a failed model call skip yt-dlp
                 * entirely and re-read exactly the words the failed attempt did. The step picks
                 * it up if it is there; see App\Actions\Summarising\FetchCaptions. The ideas
                 * the first model pass produced stay for the same reason, and are skipped the
                 * same way by App\Actions\Summarising\DraftIdeas.
                 */

                /*
                 * The reason goes with it, for the same reason the body does: it explains an
                 * attempt that is over, and leaving it would have the page explaining why the
                 * attempt now running has failed while it is still running.
                 */
                'error' => null,
                'requested_at' => Date::now(),
                'started_at' => null,
                'claim' => null,
            ]);
        }

        /*
         * The only place a job is queued, and only for a request that started an attempt.
         *
         * Two people asking for the same new video in the same instant both reach
         * firstOrCreate and one of them loses to the unique index on video_id, so only the
         * one that created the row dispatches. Two retrying the same failed row both do, and
         * the uniqueness lock drops the second before it reaches the queue.
         *
         * ->onQueue() is not decoration and not a place to name a queue - the action's
         * connection already answers that. It is what makes this a dispatch at all: the bare
         * ->execute() below it would summarise the video in this request, an hour of it, while
         * somebody waits for a redirect.
         *
         * Resolved here rather than injected into the method, so the two Saloon connectors and
         * the summariser behind them are not built for every request that dispatches nothing.
         */
        if ($startsAttempt) {
            app(SummariseVideo::class)->onQueue()->execute($summary->id);
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

                /*
                 * Where the video's cover image is, or null when there is not one to show:
                 * an older row nothing has backfilled, a video whose thumbnail could not be
                 * fetched, or an attempt that has not got past step one yet.
                 *
                 * Asked of the disk rather than assumed from the status, because none of the
                 * three cases above is visible in a column and a url handed over for a file
                 * that is not there is a broken image on the page. A local stat is cheap
                 * enough for the two second poll.
                 *
                 * A resolved url rather than a flag the page turns into one, which would mean
                 * sending the uuid as a prop of its own for no other purpose than to rebuild
                 * what the server already knows.
                 */
                'coverUrl' => Storage::disk(FetchCover::DISK)->exists($summary->file_name)
                    ? route('summaries.cover', $summary)
                    : null,
            ] : null,
        ]);
    }
}
