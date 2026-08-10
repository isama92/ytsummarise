<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

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
     * Send the user to the identity provider.
     */
    public function redirect(): SymfonyRedirectResponse|RedirectResponse
    {
        return Socialite::driver('authentik')->redirect();
    }

    /**
     * Sign the user in from the identity provider's callback.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $providerUser = Socialite::driver('authentik')->user();
        } catch (Throwable $e) {
            return $this->failed('The identity provider rejected the sign in attempt.', $e);
        }

        $email = $providerUser->getEmail();

        if (blank($email)) {
            return $this->failed('The identity provider returned no email address.');
        }

        /*
         * Lowercased because the email is the identity here and the unique index is
         * case sensitive on both Postgres and SQLite: without this, the same person
         * arriving once as Name@example.com and once as name@example.com becomes two
         * accounts. Fortify did this through its lowercase_usernames option.
         */
        $email = Str::lower($email);

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $this->resolveName($providerUser, $email)],
        );

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->intended(route('home'));
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

    /**
     * Abandon the sign in attempt, telling the user no more than they need to know.
     */
    private function failed(string $reason, ?Throwable $e = null): RedirectResponse
    {
        Log::warning('Authentik sign in failed.', [
            'reason' => $reason,
            'exception' => $e?->getMessage(),
        ]);

        return redirect()
            ->route('login')
            ->withErrors(['oidc' => __('Sign in failed. Please try again.')]);
    }

    /**
     * Work out a display name from whichever claims the provider filled in.
     *
     * Emptiness is what counts, not nullness: an Authentik account with no first or
     * last name set sends "name": "", and coalescing on null alone would store that
     * empty string and greet the user by nothing at all.
     */
    private function resolveName(ProviderUser $providerUser, string $email): string
    {
        $candidates = [
            $providerUser->getName(),
            $providerUser->getNickname(),
            Str::before($email, '@'),
        ];

        return (string) collect($candidates)->first(fn (?string $candidate): bool => filled($candidate));
    }
}
