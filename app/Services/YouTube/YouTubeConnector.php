<?php

declare(strict_types=1);

namespace App\Services\YouTube;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * What the two YouTube connectors have in common, which is how long they wait.
 *
 * Two connectors and not one because a connector stands for an API, and oEmbed and the Data API
 * are two: different hosts, different credentials, different shapes of answer. Saloon would let
 * one request point at another host by setting $allowBaseUrlOverride, but that flag exists to
 * stop a url deciding where a request goes, and there is no reason to opt out of it here.
 */
abstract class YouTubeConnector extends Connector
{
    use HasTimeout;

    /**
     * Seconds to wait for an answer, and seconds to wait for the connection.
     *
     * Both deliberately short. The job that sends these has SUMMARY_TIMEOUT (1800 seconds by
     * default) to play with, so they are nowhere near being the binding constraint, and a lookup
     * that has not answered in ten seconds is not going to.
     */
    protected int $requestTimeout = 10;

    protected int $connectTimeout = 5;
}
