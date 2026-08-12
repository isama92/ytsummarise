<?php

/*
 * An absent key and an empty one are the same thing, and this is where they become the same
 * value. `YOUTUBE_API_KEY=` in an env file reads back as an empty string rather than as nothing,
 * so without this every consumer would have to know that and check for it - and the one that
 * forgot would ask the Data API with `key=` and be told the request was bad.
 *
 * is_string as well as the emptiness, because env values arrive as whatever the environment
 * holds: a bare `YOUTUBE_API_KEY=null` is the string "null" to a shell but null to Laravel's env
 * reader, and phpunit.xml can hand over a real boolean. See .ai/rules/config.md.
 */
$youtubeKey = env('YOUTUBE_API_KEY');

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
     * The application's only authentication method. The key must stay "authentik":
     * Socialite resolves driver configuration by driver name, so renaming it here
     * makes Socialite::driver('authentik') fail with a missing credentials error.
     *
     * "name" is the label shown on the sign in button, not something Socialite reads.
     */
    'authentik' => [
        'name' => env('AUTHENTIK_NAME', 'Authentik'),
        'base_url' => env('AUTHENTIK_BASE_URL'),
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
        'redirect' => env('AUTHENTIK_REDIRECT_URI', '/auth/callback'),
    ],

    /*
     * Optional, and null is the ordinary case rather than a misconfiguration. Looking a
     * video up goes to the keyless oEmbed endpoint first; this key only buys a second
     * opinion from the Data API when that did not settle whether the video exists.
     *
     * Null or a key somebody meant, never an empty string - normalised above, so nothing
     * downstream has to ask the question twice.
     */
    'youtube' => [
        'key' => is_string($youtubeKey) && $youtubeKey !== '' ? $youtubeKey : null,
    ],

];
