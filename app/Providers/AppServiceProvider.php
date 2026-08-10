<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
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
        $this->configureModels();
        $this->configureSocialite();
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
