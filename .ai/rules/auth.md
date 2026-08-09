---
paths:
  - 'app/Http/Controllers/Auth/**'
---

# Auth

## Users are linked to Authentik by email, so the email claim is the identity
The callback does User::updateOrCreate(['email' => ...], ['name' => ...]). There is no oidc_sub column: this was a deliberate call, accepting that an email change in Authentik produces a second user row and that the email claim is trusted as the sole identity.

Name falls back getName() -> getNickname() -> the email local part; Authentik sends no separate given/family name worth splitting on.

Every failure (Socialite throwing, or a missing email claim) redirects to /login with one generic 'oidc' error and a Log::warning. Keep the user-facing message generic so IdP details do not leak.
