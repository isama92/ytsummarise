<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Override;
use SocialiteProviders\Authentik\AuthentikExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDevCommands();
        $this->configureInertia();
        $this->configureModels();
        $this->configureSocialite();
    }

    /**
     * The statuses answered with a page that looks like the rest of the application.
     *
     * Only ones the application itself provokes and can explain. 419 is a session that
     * expired while a tab sat open, and 429 is the throttle on POST /summaries; both are
     * reachable by someone doing nothing unusual, and without them an Inertia visit gets
     * a non-Inertia body and the client's raw error modal.
     *
     * 500 and 503 are deliberately absent. Laravel's own pages are fine, and rendering
     * ours means running withSharedData() -> HandleInertiaRequests::share() ->
     * $request->user(), which is a database query. The likeliest cause of a 500 is that
     * the database is unavailable, so that query throws a second time from inside the
     * renderer, past the point anything can be rendered, and the visitor gets a bare
     * fatal error instead of a 500 page. Handling them would be strictly worse.
     */
    private const array HANDLED_STATUSES = [403, 404, 419, 429];

    /**
     * Answer errors with a page that looks like the rest of the application.
     *
     * withSharedData() is load bearing rather than decorative. A 404 for a url that
     * matches no route never reaches the routes, so HandleInertiaRequests never runs and
     * the page would otherwise render with no shared props and no root view.
     *
     * Returning null falls through to Laravel's own rendering.
     */
    protected function configureInertia(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?ExceptionResponse {
            if (! in_array($response->statusCode(), self::HANDLED_STATUSES, true)) {
                return null;
            }

            /*
             * This callback is registered through Handler::respondUsing, which runs in
             * finalizeRenderedResponse - after the handler has already decided between an
             * HTML and a JSON body. So without this guard it replaces a finished
             * JsonResponse with an HTML document, and the shouldRenderJsonWhen() rule in
             * bootstrap/app.php silently stops meaning anything for these statuses.
             *
             * Testing the built response rather than re-reading the request keeps that
             * rule in one place: whatever bootstrap/app.php decides, this follows.
             */
            if ($response->response instanceof JsonResponse) {
                return null;
            }

            /*
             * And only for something that actually asked for a page. Every 404 was
             * rendering a React shell and running share() - a database query and a session
             * write - for a missing icon, a stray source map request or a bot sweeping for
             * /wp-login.php.
             *
             * text/html by name rather than acceptsHtml(), which is satisfied by a wildcard
             * and so was true of every one of those: a browser fetching an image asks for
             * avif and webp and then a wildcard, and a bot usually sends nothing but one.
             * A navigation always names text/html, and so does an Inertia visit.
             */
            if (! in_array('text/html', $response->request->getAcceptableContentTypes(), true)) {
                return null;
            }

            return $response
                ->render('error', ['status' => $response->statusCode()])
                ->withSharedData();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );
    }

    /**
     * Give `composer dev` a queue tab that works this application's queues.
     *
     * Laravel's own default registers `queue:listen`, which works the DEFAULT connection -
     * so on this application it has never once picked up a summary, and the README had to
     * tell you to run a second worker by hand next to it. Horizon reads config/horizon.php
     * and works every queue there is, which is the whole reason it is here.
     *
     * This replaces that tab rather than adding a fifth: commands are keyed by name, and a
     * registration from application code outranks one from registerDefaults(), so reusing
     * the name "queue" is what does the swapping. Registering under any other name would
     * leave both running.
     *
     * Needs the local Redis from the top of compose.yml, as the rest of the application now
     * does.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::artisan('horizon', 'queue');
    }

    /**
     * Turn Eloquent's quiet failure modes into loud ones.
     *
     * Strictness is off in production because lazy loading and discarded fill attempts
     * should degrade rather than 500 in front of a user. Missing attributes are the
     * exception and stay guarded everywhere: reading one that was never selected
     * silently yields null, which is how a partial select turns into wrong output
     * instead of an error.
     */
    protected function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->environment('production'));
        Model::preventAccessingMissingAttributes();
    }

    /**
     * Register the Authentik driver with Socialite.
     *
     * Community providers are not auto-discovered the way Socialite's built-in drivers
     * are, so without this listener Socialite::driver('authentik') throws
     * "Driver [authentik] not supported."
     */
    protected function configureSocialite(): void
    {
        Event::listen(SocialiteWasCalled::class, [AuthentikExtendSocialite::class, 'handle']);
    }
}
