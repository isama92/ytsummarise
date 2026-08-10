---
paths:
  - 'app/Http/Controllers/Auth/**'
---

# Auth

## Users are linked to Authentik by email, so the email claim is the identity
The callback does User::updateOrCreate(['email' => ...], ['name' => ...]). There is no oidc_sub column: this was a deliberate call, accepting that an email change in Authentik produces a second user row and that the email claim is trusted as the sole identity.

Name falls back getName() -> getNickname() -> the email local part; Authentik sends no separate given/family name worth splitting on.

Every failure (Socialite throwing, or a missing email claim) redirects to /login with one generic 'oidc' error and a Log::warning. Keep the user-facing message generic so IdP details do not leak.

## email_verified is deliberately not checked on the OIDC callback
The callback trusts the email claim without reading email_verified. That is safe only while the Authentik tenant provisions accounts centrally and forbids self-registration, which is the case today.

If self-registration is ever enabled, this becomes an account-takeover path: an attacker registers an unverified address matching an existing user and updateOrCreate hands them that account. Add the email_verified check at the same time as enabling self-registration, not after.

The email is lowercased before lookup because it is the identity and the unique index is case sensitive; without it one person arriving with different capitalisation becomes two accounts.
