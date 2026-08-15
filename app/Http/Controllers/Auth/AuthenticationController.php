<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The two ends of a local session: the page that offers to start one, and ending one.
 *
 * The handshake with Authentik that happens in between is not here. It is two named steps
 * rather than REST verbs, so each is its own invokable controller beside this one; see
 * OidcRedirectController and OidcCallbackController.
 */
class AuthenticationController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/login', [
            'providerName' => config('services.authentik.name'),
        ]);
    }

    /**
     * Sign the user out of this application.
     *
     * The session at the identity provider is deliberately left alone: signing out here
     * only ends the local session, so signing back in will not prompt for credentials.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
