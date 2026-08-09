<?php

use App\Http\Middleware\AuthenticateAsFirstUser;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Traefik terminates TLS and reaches the container over plain HTTP, so without
         * this Laravel reads the request as insecure: route() and asset() emit http://
         * URLs and cookies marked secure never come back. Trusting every proxy is safe
         * here because nothing but Traefik can reach the container's port.
         */
        $middleware->trustProxies(at: '*');

        /*
         * The appearance cookie is read by an inline script in app.blade.php before any
         * JavaScript bundle runs, so it has to stay readable outside of PHP.
         */
        $middleware->encryptCookies(except: ['appearance']);

        /*
         * AuthenticateAsFirstUser has to come before HandleInertiaRequests: the Inertia
         * middleware shares the authenticated user before handing the request on, so
         * signing in after it would render the page as a guest.
         */
        $middleware->web(append: [
            AuthenticateAsFirstUser::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
         * Listing it in the web group is not enough. SortedMiddleware hoists every
         * middleware named in the priority map, so the route's `auth` middleware would
         * otherwise be pulled up to just after ShareErrorsFromSession and run first,
         * bouncing the visitor to /login before anyone had been signed in. Naming ours
         * in the map immediately before it is what pins the order.
         */
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: AuthenticateAsFirstUser::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
