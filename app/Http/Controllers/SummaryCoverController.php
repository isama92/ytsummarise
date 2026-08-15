<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Summary;
use App\Services\YouTube\Actions\FetchCover;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The video's cover image, straight off the disk.
 *
 * Its own controller rather than a fifth method on SummaryController, because it answers with
 * bytes rather than with a page. Every other action over there renders the one screen this
 * application has; this one has no Inertia response, no props and nothing to do with what the
 * page shows. Invokable, so the class name is the whole description of what it does.
 *
 * Streamed through the application rather than served as a static file, which is the whole
 * reason the video-covers disk has no url of its own: this route is inside the auth group, so
 * an image is exactly as reachable as the summary it belongs to and no more.
 *
 * Resolved by uuid through the RouteKey attribute on the model, the same as the summary itself.
 * A uuid that is not one never reaches the database or the disk: HasUniqueStringIds checks the
 * format while resolving the binding and throws ModelNotFoundException itself.
 */
class SummaryCoverController extends Controller
{
    /**
     * A row with no cover 404s rather than answering with a placeholder.
     *
     * Nothing asks for one blindly - the page is told whether there is an image before it
     * renders an img at all - so reaching here for a file that is not there means a cover
     * deleted underneath a page that was already open, and a 404 is what that is.
     */
    public function __invoke(Summary $summary): StreamedResponse
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
}
