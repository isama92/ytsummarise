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
         * A failed row starts over, and its clock with it. A pending one is left exactly
         * as it is: whoever asked first is already waiting, and resetting requested_at
         * would restart their clock and push back the moment this gets written off.
         */
        if ($summary->status === SummaryStatus::Failed) {
            $summary->update([
                'status' => SummaryStatus::Pending,
                'body' => null,
                'requested_at' => Date::now(),
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
         * Dispatched for anything not ready, including a row already pending. That is not
         * a duplicate: the job is unique per video, so while one is in flight this is
         * dropped and the browser simply joins the job already running. Once the lock has
         * lapsed - which it cannot outlive the timeout - the same call is what starts the
         * replacement attempt.
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
     * The video id goes back to the browser so the field can show what was extracted
     * from whatever was pasted.
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
            ] : null,
        ]);
    }
}
