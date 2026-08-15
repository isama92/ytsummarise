<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Hands the browser over to Authentik, which is the first half of the OIDC handshake.
 *
 * Its own invokable controller rather than a method on AuthenticationController, because the
 * handshake is two named steps and neither is one of the REST verbs a controller action is
 * expected to be. Splitting them says what each one is in its class name instead.
 *
 * Whatever routes here must be reached by a full page navigation and never by an Inertia visit:
 * the response is a cross-origin 302, and an XHR either trips CORS or receives HTML it refuses
 * to parse. See .ai/rules/pages-auth.md for the sign-in control that depends on this.
 *
 * The driver is registered by hand in AppServiceProvider::configureSocialite(); the community
 * Authentik provider is not auto-discovered, and without that registration this throws
 * "Driver [authentik] not supported".
 */
class OidcRedirectController extends Controller
{
    public function __invoke(): SymfonyRedirectResponse|RedirectResponse
    {
        return Socialite::driver('authentik')->redirect();
    }
}
