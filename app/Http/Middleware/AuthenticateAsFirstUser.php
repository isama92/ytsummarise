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
 * This has to run before both the `auth` middleware and HandleInertiaRequests, the
 * latter because Inertia shares the authenticated user before passing the request on,
 * so signing in afterwards leaves the page rendering as a guest while the session says
 * otherwise. Its position comes from the priority map in bootstrap/app.php, not from
 * where it sits in the web group.
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
        /*
         * The manifest is exempt for the same reason it is in neither middleware group:
         * the browser fetches it on its own and an installable application cannot depend
         * on who is signed in. Without this it answered 302 on a fresh self hosted install
         * - authentication off, users table still empty - and the application was silently
         * not installable there. ManifestTest covers that state.
         */
        if (config('auth.enabled') || Auth::check() || $request->routeIs('first-user.*', 'manifest')) {
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
