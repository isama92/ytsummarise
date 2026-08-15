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
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Socialite;
use Throwable;

/**
 * Signs the user in from Authentik's callback, which is the second half of the handshake.
 *
 * Its own invokable controller for the same reason the redirect above it is: the two steps are
 * named things rather than REST verbs, and the class name is the better place to say which is
 * which. The two private helpers below came with it because nothing else ever used them.
 *
 * Users are linked to Authentik by email, so the email claim is the identity. There is no
 * oidc_sub column: that was a deliberate call, accepting that an email change at the provider
 * produces a second user row.
 *
 * email_verified is deliberately not checked. That is safe only while the Authentik tenant
 * provisions accounts centrally and forbids self-registration, which is the case today. If
 * self-registration is ever enabled this becomes an account-takeover path - an attacker
 * registers an unverified address matching an existing user and updateOrCreate hands them that
 * account - so the check goes in at the same time as that, not after. See .ai/rules/auth.md.
 */
class OidcCallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
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
