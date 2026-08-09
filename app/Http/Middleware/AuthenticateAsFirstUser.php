<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs every visitor in as the first user while authentication is disabled.
 *
 * This has to run before HandleInertiaRequests. That middleware shares the authenticated
 * user before passing the request on, so signing in afterwards would leave the page
 * rendering as a guest while the session says otherwise.
 */
class AuthenticateAsFirstUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('auth.enabled') || Auth::check() || $request->routeIs('first-user.*')) {
            return $next($request);
        }

        $user = User::query()->oldest('id')->first();

        if (! $user instanceof User) {
            return redirect()->route('first-user.create');
        }

        Auth::login($user);

        return $next($request);
    }
}
