<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SummaryStatus;
use App\Http\Requests\SummaryRequest;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SummaryController extends Controller
{
    /**
     * The application's only screen.
     *
     * Split across a POST that creates the summary and a GET that shows it, so the
     * result lives at a url of its own: refreshing keeps it, the link can be shared, and
     * the browser never offers to resubmit anything. The GET is also what the page polls
     * while the job runs.
     */
    public function index(Request $request): Response
    {
        $videoId = $this->requestedVideoId($request);

        $summary = $videoId === null
            ? null
            : Summary::query()->firstWhere('video_id', $videoId);

        return Inertia::render('home', [
            /*
             * Present whenever the query string held a well formed id, even when nothing
             * has been summarised for it. That prefills the field, so a link to a video
             * whose summary has since been removed is one keystroke from working again.
             */
            'videoId' => $videoId,
            'summary' => $summary instanceof Summary ? [
                'status' => $summary->status,
                'body' => $summary->body,
            ] : null,
        ]);
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

        return redirect()->route('home', ['v' => $videoId]);
    }

    /**
     * The video id in the query string, or null when there isn't a usable one.
     *
     * A malformed id is treated as no id rather than as an error. It can only come from a
     * hand edited or truncated url, and an empty page is a better answer to that than a
     * validation message about a field the visitor never filled in.
     */
    private function requestedVideoId(Request $request): ?string
    {
        $videoId = $request->query('v');

        if (! is_string($videoId) || preg_match(Summary::VIDEO_ID_PATTERN, $videoId) !== 1) {
            return null;
        }

        return $videoId;
    }
}
