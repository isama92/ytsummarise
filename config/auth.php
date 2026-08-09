<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Enabled
    |--------------------------------------------------------------------------
    |
    | When this is false the application asks nobody to sign in: every visitor
    | is authenticated as the first user in the database, and when there is no
    | user yet they are asked for a name and an email to create one. It is how
    | you run this application locally without an Authentik client, and how a
    | single user can self host it without an identity provider at all.
    |
    | This is honoured in every environment, production included. Turning it
    | off on a reachable deployment means anyone who can open the site is that
    | first user, so only do it behind a private network, a VPN, or a proxy
    | that authenticates on the application's behalf.
    |
    | Only a literal AUTH_ENABLED=false turns authentication off. An empty,
    | misspelled or otherwise unparseable value leaves it on, so a typo in a
    | deployed .env can never be what opens the application up.
    |
    */

    'enabled' => env('AUTH_ENABLED', true) !== false,

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" for your
    | application. You may change this value as required, but it's a
    | perfect start for most applications.
    |
    | There is no password reset "broker" here: the application authenticates
    | through Authentik OIDC only, so it stores no passwords to reset.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

];
