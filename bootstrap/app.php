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
         * AuthenticateAsFirstUser is listed first for readability only. Its real
         * position is decided by the priority map below, which hoists it above
         * everything here whatever order this list is in.
         */
        $middleware->web(append: [
            AuthenticateAsFirstUser::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
         * This, not the list above, is what orders AuthenticateAsFirstUser.
         *
         * SortedMiddleware hoists every middleware named in the priority map. The
         * route's `auth` middleware is in it, so without this it gets pulled up to
         * just after ShareErrorsFromSession and runs first, bouncing the visitor to
         * /login before anyone has been signed in. Naming ours immediately before it
         * puts it in the same map, which also lands it ahead of HandleInertiaRequests
         * - required, because Inertia shares the authenticated user before passing the
         * request on, and signing in after that renders the page as a guest.
         *
         * tests/Feature/Auth/DisabledAuthenticationTest guards both halves.
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
