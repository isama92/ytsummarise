<?php

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
     * opinion from the Data API when that did not settle whether the video exists. See
     * App\Services\YouTube\VideoLookup, which reads it with is_string() rather than
     * config()->string() for exactly that reason.
     */
    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

];
