<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticationController;
use App\Http\Controllers\Auth\FirstUserController;
use App\Http\Controllers\Auth\OidcCallbackController;
use App\Http\Controllers\Auth\OidcRedirectController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\SummaryCoverController;
use Illuminate\Support\Facades\Route;

/*
 * In neither group below, which is most of the point of it being up here.
 *
 * Behind `auth` the browser's own request for the manifest answers 302 to /login, and the
 * application is quietly not installable with nothing to see in any log. Inside `guest`
 * the same thing happens the other way round to anybody signed in. It says nothing about
 * anybody, so it is reachable by everybody.
 *
 * Being outside both is necessary and not sufficient: AuthenticateAsFirstUser runs in the
 * web group ahead of all of this and had to be told about it separately, or a self hosted
 * install with authentication off and no user yet answered 302 here too.
 */
Route::get('manifest.webmanifest', ManifestController::class)->name('manifest');

Route::middleware('auth')->group(function (): void {
    Route::get('/', [SummaryController::class, 'index'])->name('home');

    /*
     * Deliberately not throttled, unlike the POST below. The page polls this route every
     * two seconds while a summary is being produced, which is thirty requests a minute:
     * the same throttle:30,1 would start answering 429 partway through generating one.
     */
    Route::get('summaries/{summary}', [SummaryController::class, 'show'])->name('summaries.show');

    /*
     * The video's cover image, kept on a disk with no url of its own so that this is the
     * only way to one; see config/filesystems.php. Inside the auth group because the image
     * says which video somebody summarised just as plainly as the summary does, and a page
     * behind a sign-in whose pictures are not is not behind a sign-in.
     *
     * Not throttled, for the same reason the route above is not: the page asks for it.
     */
    Route::get('summaries/{summary}/cover', SummaryCoverController::class)->name('summaries.cover');

    /*
     * Throttled even though it sits behind authentication: it queues work that will be a
     * paid model call, so an accidental loop in the frontend should cost a 429 rather
     * than a bill. Signed in people do not submit videos thirty times a minute.
     */
    Route::post('summaries', [SummaryController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('summaries.store');

    Route::post('logout', [AuthenticationController::class, 'destroy'])->name('logout');
});

/*
 * Everything here is reachable by anonymous clients, so everything here is throttled.
 * Fortify used to rate limit the login POST and took its limiter with it when it went.
 * auth/redirect is the one that would hurt most unthrottled: it writes a fresh OAuth
 * state into the session on every hit, which with SESSION_DRIVER=database is a row per
 * request. The limit is per route and per IP, so one noisy client cannot exhaust
 * anybody else's budget, and 30 a minute is far above what a person does by hand.
 */
Route::middleware('guest')->group(function (): void {
    Route::middleware('throttle:30,1')->group(function (): void {
        Route::get('login', [AuthenticationController::class, 'create'])->name('login');

        Route::get('auth/redirect', OidcRedirectController::class)->name('auth.redirect');
        Route::get('auth/callback', OidcCallbackController::class)->name('auth.callback');

        /*
         * Only reachable while AUTH_ENABLED is false and no user exists; the controller
         * answers 404 otherwise. Registered in both modes so the route table, and the
         * redirect AuthenticateAsFirstUser sends guests to, never depend on configuration.
         */
        Route::get('first-user', [FirstUserController::class, 'create'])->name('first-user.create');
    });

    /*
     * Deliberately outside the group rather than carrying a second, tighter throttle on
     * top of it: two throttle middleware on one route derive the same key from the route
     * and the IP, so both increment the same counter and the real limit is half the
     * smaller number. This route creates an account and is used once in the lifetime of
     * an installation, so it gets its own budget instead.
     */
    Route::post('first-user', [FirstUserController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('first-user.store');
});
