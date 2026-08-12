<?php

declare(strict_types=1);

namespace App\Services\YouTube\Requests;

use App\Services\YouTube\Data\LookupResult;
use Illuminate\Support\Facades\Log;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * One question about one video, asked of whichever endpoint can answer it.
 *
 * Every YouTube request reads its own answer: the class that knows the endpoint is the class that
 * knows what its status codes and its json mean, and the action is left with nothing to do but
 * combine two results. Each one narrows createDtoFromResponse to LookupResult, which Saloon types
 * as mixed.
 *
 * That method cannot be redeclared abstract here, tempting as it is - Saloon provides a concrete
 * one through the CreatesDtoFromResponse trait, and a subclass may not make an inherited concrete
 * method abstract. Missing it out in a new request would mean a request that returns null and
 * falls through to the connector rather than one that fails to compile, so add it.
 */
abstract class YouTubeRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected readonly string $videoId) {}

    /**
     * An answer nothing can be made of, logged on the way past.
     *
     * A warning rather than a note: a steady trickle of these is a key, a quota or a blocked
     * egress rather than anything to do with the video somebody asked for. The status is the
     * part worth having in the log, which is why this lives with the request that knows it
     * rather than with the action that only sees the result.
     */
    protected function unusable(string $message, int $status): LookupResult
    {
        Log::warning($message, [
            'video_id' => $this->videoId,
            'status' => $status,
        ]);

        return LookupResult::unknown();
    }
}
