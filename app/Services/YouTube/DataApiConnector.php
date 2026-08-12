<?php

declare(strict_types=1);

namespace App\Services\YouTube;

use Override;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\QueryAuthenticator;

/**
 * The YouTube Data API, asked only as a second opinion and only when a key is configured.
 *
 * The key belongs here rather than to any one request: it is how this API is authenticated, not
 * something a particular question needs, and a second request added later would otherwise have
 * to remember to carry it. Every request sent through this connector is authenticated by it.
 *
 * Every request also spends quota, so whether it is worth asking at all is still the action's
 * decision - which is what isConfigured is for.
 */
class DataApiConnector extends YouTubeConnector
{
    /**
     * The key, or null when the application has not been given one.
     *
     * Read from config rather than injected because there is nothing to choose between: this
     * connector is the Data API, and the Data API's key lives at one config path.
     * config/services.php has already turned an absent or empty YOUTUBE_API_KEY into null, so
     * the type check here is only what phpstan needs to see a ?string through config().
     */
    private readonly ?string $apiKey;

    public function __construct()
    {
        $key = config('services.youtube.key');

        $this->apiKey = is_string($key) ? $key : null;
    }

    #[Override]
    public function resolveBaseUrl(): string
    {
        return 'https://www.googleapis.com/youtube/v3';
    }

    /**
     * Whether there is any point asking this connector anything.
     *
     * The application is expected to run without a key, so an unconfigured Data API is an
     * ordinary state rather than a fault, and asking it without one would spend a request to be
     * told the request was bad.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== null;
    }

    /**
     * A query parameter rather than a header, which is how this API takes its key.
     */
    #[Override]
    protected function defaultAuth(): ?Authenticator
    {
        if ($this->apiKey === null) {
            return null;
        }

        return new QueryAuthenticator('key', $this->apiKey);
    }
}
