<?php

declare(strict_types=1);

namespace App\Services\YouTube\Actions;

use App\Services\YouTube\Data\LookupResult;
use App\Services\YouTube\DataApiConnector;
use App\Services\YouTube\Enums\VideoPresence;
use App\Services\YouTube\OembedConnector;
use App\Services\YouTube\Requests\OembedRequest;
use App\Services\YouTube\Requests\VideosRequest;
use App\Services\YouTube\Requests\YouTubeRequest;
use App\Services\YouTube\YouTubeConnector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;

/**
 * Asks YouTube whether a video exists and what it is called.
 *
 * Two endpoints, in order of what they cost. oEmbed needs no key, no quota and no account, so it
 * answers almost every lookup on its own. The Data API is a second opinion, used only when oEmbed
 * did not settle the question and only when a key has been configured, which it need not be: the
 * application works without one.
 *
 * Nothing here throws. Every fault, from a timeout to a quota refusal, comes back as
 * VideoPresence::Unknown, because what a fault should cost somebody is a decision for the caller
 * and not for the transport. The job is what turns these answers into a status and a
 * SummaryError.
 */
class LookupVideo
{
    public function __construct(
        private readonly OembedConnector $oembed,
        private readonly DataApiConnector $dataApi,
    ) {}

    /**
     * Everything known about one video id.
     */
    public function execute(string $videoId): LookupResult
    {
        $oembedLookupResultData = $this->send($this->oembed, new OembedRequest($videoId));

        /*
         * A title is the whole answer, so there is nothing left to ask anybody.
         */
        if ($oembedLookupResultData->presence === VideoPresence::Found && $oembedLookupResultData->title !== null) {
            return $oembedLookupResultData;
        }

        /*
         * No key, so there is no second opinion to be had and nothing to spend finding that out.
         * Asked of the connector rather than of config, because the key is the connector's own
         * business: it is what authenticates everything sent through it, and nothing here needs
         * to see it to decide whether asking is possible.
         */
        if (! $this->dataApi->isConfigured()) {
            return $oembedLookupResultData;
        }

        $dataApiLookupResultData = $this->send($this->dataApi, new VideosRequest($videoId));

        if ($dataApiLookupResultData->presence === VideoPresence::Found) {
            return $dataApiLookupResultData;
        }

        /*
         * Missing from the Data API only settles it when oEmbed could not tell us anything.
         * A 401 from oEmbed has already proved the video exists, and one endpoint declining
         * to list it does not unprove that - the Data API omits videos in ways that have
         * nothing to do with whether they are there.
         */
        if ($dataApiLookupResultData->presence === VideoPresence::Missing && $oembedLookupResultData->presence === VideoPresence::Unknown) {
            return LookupResult::missing();
        }

        return $oembedLookupResultData;
    }

    /**
     * One request, and its answer, or Unknown when there was no answer to have.
     *
     * A failed status code does not throw in Saloon, which is what lets each request read a 404
     * or a 401 as an answer rather than an error. The two exceptions caught here are the failures
     * that leave nothing for a request to read at all: nothing came back, or what came back was
     * not json. Both are faults rather than answers, and this class promises to return them as
     * Unknown rather than to throw.
     */
    private function send(YouTubeConnector $connector, YouTubeRequest $request): LookupResult
    {
        try {
            $result = $connector->send($request)->dto();
        } catch (FatalRequestException $exception) {
            Log::warning('Could not reach YouTube', [
                'url' => $connector->resolveBaseUrl(),

                /*
                 * Everything from " for " onwards is thrown away, because that is where the
                 * sender puts the url it was trying: a cURL failure reads "cURL error 6: Could
                 * not resolve host: … (see …) for <the whole request uri>", and for the Data API
                 * that uri carries the key. Guzzle looks like it handles this - the variable it
                 * builds is called $redactedUriString - but its redaction only masks a password
                 * in user:pass@host and leaves the query string alone.
                 *
                 * Nothing of diagnostic value is lost. The error itself is the part worth having,
                 * and the host is recorded above without a query.
                 */
                'exception' => Str::before($exception->getMessage(), ' for '),
            ]);

            return LookupResult::unknown();
        } catch (JsonException $exception) {
            /*
             * A 2xx carrying something that is not json at all, which is what a captive portal
             * or an interpolating proxy answers with. Saloon decodes with JSON_THROW_ON_ERROR,
             * so this arrives from inside the request's own reading of the response.
             *
             * Told apart from the case above because it is a different thing to go and look at:
             * YouTube was reached and said something unusable, rather than never answering. The
             * message is json_decode's own ("Syntax error") and carries no url.
             */
            Log::warning('YouTube answered with something that is not json', [
                'url' => $connector->resolveBaseUrl(),
                'exception' => $exception->getMessage(),
            ]);

            return LookupResult::unknown();
        }

        /*
         * Saloon types dto() as mixed, since a request may map its response to anything. Every
         * YouTubeRequest maps to a LookupResult and says so, so this asserts what the type
         * system cannot see through rather than branching on something no test could reach.
         */
        assert($result instanceof LookupResult);

        return $result;
    }
}
