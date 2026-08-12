<?php

declare(strict_types=1);

namespace App\Services\YouTube\Requests;

use App\Services\YouTube\Data\LookupResult;
use Override;
use Saloon\Http\Response;

/**
 * The keyed lookup, asked only when oEmbed left something open.
 */
final class VideosRequest extends YouTubeRequest
{
    #[Override]
    public function resolveEndpoint(): string
    {
        return '/videos';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            /* snippet is the cheapest part that carries a title. */
            'part' => 'snippet',
            'id' => $this->videoId,

            /* The key is not here: DataApiConnector authenticates everything it sends. */
        ];
    }

    /**
     * An empty items array is the Data API saying it has no such video, which is the one
     * definitive answer it gives. Everything else, quota refusals included, is a fault.
     */
    #[Override]
    public function createDtoFromResponse(Response $response): LookupResult
    {
        if (! $response->successful()) {
            return $this->unusable('YouTube Data API answered unusably', $response->status());
        }

        $items = $response->json('items');

        if (! is_array($items)) {
            return $this->unusable('YouTube Data API answered without items', $response->status());
        }

        if ($items === []) {
            return LookupResult::missing();
        }

        return LookupResult::found($response->json('items.0.snippet.title'));
    }
}
