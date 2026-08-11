<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SummaryStatus;
use App\Http\Requests\SummaryRequest;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use Illuminate\Http\RedirectResponse;
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
            ['status' => SummaryStatus::Pending],
        );

        /*
         * A video somebody already summarised costs nothing: the row is answered as it
         * stands. A previous failure is the one case worth redoing, which also makes
         * submitting the same video again the retry mechanism.
         */
        if ($summary->wasRecentlyCreated || $summary->status === SummaryStatus::Failed) {
            $summary->update(['status' => SummaryStatus::Pending, 'body' => null]);

            SummariseVideo::dispatch($summary);
        }

        return redirect()->route('summaries.show', $summary);
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
                'body' => $summary->body,
            ] : null,
        ]);
    }
}
