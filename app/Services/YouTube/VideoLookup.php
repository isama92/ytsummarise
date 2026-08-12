<?php

declare(strict_types=1);

namespace App\Services\YouTube;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asks YouTube whether a video exists and what it is called.
 *
 * Two endpoints, in order of what they cost. oEmbed needs no key, no quota and no account, so
 * it answers almost every lookup on its own. The Data API is a second opinion, used only when
 * oEmbed did not settle the question and only when a key has been configured, which it need
 * not be: the application works without one.
 *
 * Nothing here throws. Every fault, from a timeout to a quota refusal, comes back as
 * VideoPresence::Unknown, because what a fault should cost somebody is a decision for the
 * caller and not for the transport. The job is what turns these answers into a status and a
 * SummaryError.
 */
class VideoLookup
{
    /**
     * Seconds to wait for an answer, and seconds to wait for the connection.
     *
     * Constants rather than configuration: the job that calls this has SUMMARY_TIMEOUT
     * (1800 seconds by default) to play with, so these are nowhere near being the binding
     * constraint, and there is nothing here for an operator to tune. Both are deliberately
     * short - a lookup that has not answered in ten seconds is not going to.
     */
    private const int TIMEOUT = 10;

    private const int CONNECT_TIMEOUT = 5;

    private const string OEMBED_URL = 'https://www.youtube.com/oembed';

    private const string DATA_API_URL = 'https://www.googleapis.com/youtube/v3/videos';

    /**
     * Everything known about one video id.
     */
    public function find(string $videoId): LookupResult
    {
        $oembed = $this->viaOembed($videoId);

        /*
         * A title is the whole answer, so there is nothing left to ask anybody.
         */
        if ($oembed->presence === VideoPresence::Found && $oembed->title !== null) {
            return $oembed;
        }

        $key = $this->apiKey();

        if ($key === null) {
            return $oembed;
        }

        $second = $this->viaDataApi($videoId, $key);

        if ($second->presence === VideoPresence::Found) {
            return $second;
        }

        /*
         * Missing from the Data API only settles it when oEmbed could not tell us anything.
         * A 401 from oEmbed has already proved the video exists, and one endpoint declining
         * to list it does not unprove that - the Data API omits videos in ways that have
         * nothing to do with whether they are there.
         */
        if ($second->presence === VideoPresence::Missing && $oembed->presence === VideoPresence::Unknown) {
            return new LookupResult(VideoPresence::Missing);
        }

        return $oembed;
    }

    /**
     * The keyless lookup, which is also the one that names the video.
     *
     * 401 and 403 mean embedding is disabled rather than that the video is gone, which is why
     * they land on Found with no title. A 400 is oEmbed's answer to a url it will not parse
     * and is treated the same as a 404; neither can be produced by an id of the right shape
     * that exists.
     */
    private function viaOembed(string $videoId): LookupResult
    {
        $response = $this->get(self::OEMBED_URL, [
            'url' => "https://www.youtube.com/watch?v={$videoId}",
            'format' => 'json',
        ]);

        if (! $response instanceof Response) {
            return new LookupResult(VideoPresence::Unknown);
        }

        return match (true) {
            $response->successful() => new LookupResult(
                VideoPresence::Found,
                $this->stringOrNull($response->json('title')),
            ),
            in_array($response->status(), [400, 404], true) => new LookupResult(VideoPresence::Missing),
            in_array($response->status(), [401, 403], true) => new LookupResult(VideoPresence::Found),
            default => $this->unknown('YouTube oEmbed answered unusably', $videoId, $response->status()),
        };
    }

    /**
     * The keyed lookup, asked only when oEmbed left something open.
     *
     * An empty items array is the Data API saying it has no such video, which is the one
     * definitive answer it gives. Everything else, quota refusals included, is a fault.
     */
    private function viaDataApi(string $videoId, string $key): LookupResult
    {
        $response = $this->get(self::DATA_API_URL, [
            'part' => 'snippet',
            'id' => $videoId,
            'key' => $key,
        ]);

        if (! $response instanceof Response) {
            return new LookupResult(VideoPresence::Unknown);
        }

        if (! $response->successful()) {
            return $this->unknown('YouTube Data API answered unusably', $videoId, $response->status());
        }

        $items = $response->json('items');

        if (! is_array($items)) {
            return $this->unknown('YouTube Data API answered without items', $videoId, $response->status());
        }

        if ($items === []) {
            return new LookupResult(VideoPresence::Missing);
        }

        return new LookupResult(
            VideoPresence::Found,
            $this->stringOrNull($response->json('items.0.snippet.title')),
        );
    }

    /**
     * One request, or null when there was no answer to have.
     *
     * @param  array<string, string>  $query
     */
    private function get(string $url, array $query): ?Response
    {
        try {
            return Http::timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->get($url, $query);
        } catch (ConnectionException $exception) {
            Log::warning('Could not reach YouTube', [
                'url' => $url,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * An unusable answer, logged on the way past.
     *
     * Worth a warning rather than a note: a steady trickle of these is a key, a quota or a
     * blocked egress rather than anything to do with the video somebody asked for.
     */
    private function unknown(string $message, string $videoId, int $status): LookupResult
    {
        Log::warning($message, [
            'video_id' => $videoId,
            'status' => $status,
        ]);

        return new LookupResult(VideoPresence::Unknown);
    }

    /**
     * A title only if there is really one there.
     *
     * Both endpoints hand back json this code did not write, so a missing or oddly typed
     * title becomes no title rather than a type error deep inside the job.
     */
    private function stringOrNull(mixed $title): ?string
    {
        return is_string($title) && $title !== '' ? $title : null;
    }

    /**
     * The Data API key, or null when the application has not been given one.
     *
     * Read defensively instead of through config()->string(), which throws on a null: not
     * having a key is the ordinary case, not a misconfiguration.
     */
    private function apiKey(): ?string
    {
        $key = config('services.youtube.key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
