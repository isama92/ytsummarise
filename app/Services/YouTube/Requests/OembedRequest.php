<?php

declare(strict_types=1);

namespace App\Services\YouTube\Requests;

use App\Services\YouTube\Data\LookupResult;
use Override;
use Saloon\Http\Response;

/**
 * The keyless lookup, which is also the one that names the video.
 */
final class OembedRequest extends YouTubeRequest
{
    #[Override]
    public function resolveEndpoint(): string
    {
        return '/oembed';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            /*
             * oEmbed takes the watch url rather than the id, so the id is put back into one.
             * Nothing else about the url matters to it.
             */
            'url' => "https://www.youtube.com/watch?v={$this->videoId}",
            'format' => 'json',
        ];
    }

    /**
     * 401 and 403 mean embedding is disabled rather than that the video is gone, which is why
     * they land on Found with no title. A 400 is oEmbed's answer to a url it will not parse and
     * is treated the same as a 404; neither can be produced by an id of the right shape that
     * exists.
     */
    #[Override]
    public function createDtoFromResponse(Response $response): LookupResult
    {
        return match (true) {
            $response->successful() => LookupResult::found($response->json('title')),
            in_array($response->status(), [400, 404], true) => LookupResult::missing(),
            in_array($response->status(), [401, 403], true) => LookupResult::found(),
            default => $this->unusable('YouTube oEmbed answered unusably', $response->status()),
        };
    }
}
