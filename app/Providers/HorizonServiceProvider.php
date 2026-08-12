<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Decide who may open the Horizon dashboard.
     *
     * The first user in the database and nobody else. There is no roles table here and no
     * admin column to read, and inventing one to answer this question would be building the
     * larger thing sideways: whoever set this application up is user 1, the dashboard shows
     * queue internals rather than anybody's data, and a single self hosted operator is the
     * whole intended audience. When roles do arrive this is the line that changes.
     *
     * Two things about when it is consulted at all:
     *
     * Horizon's own callback lets APP_ENV=local straight through regardless of what this
     * returns (see HorizonApplicationServiceProvider::authorization), so local development
     * never reaches here and a broken gate would not show up there.
     *
     * With AUTH_ENABLED=false it passes for everyone, because AuthenticateAsFirstUser signs
     * every visitor in as user 1 - which is the point of that switch and not an accident here.
     * config/auth.php is plain that the mode means "anyone who can open the site is that first
     * user", so it is only safe behind a private network, and this dashboard is inside that
     * same "anyone". Nothing narrower would be honest: a gate cannot tell a stranger from the
     * owner when the application has already decided not to ask.
     *
     * A refusal aborts 403, and AppServiceProvider::configureInertia already handles 403, so
     * a turned-away visitor gets this application's error page rather than Laravel's.
     */
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user?->id === 1);
    }
}
