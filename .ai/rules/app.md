---
paths:
  - 'app/**'
---

# App

## Authentication is Authentik OIDC only, with no local passwords
Sign-in goes through Authentik via laravel/socialite + socialiteproviders/authentik. Fortify was removed deliberately: there is no password column, no password_reset_tokens table, no email verification and no registration flow. Do not reintroduce password auth, Password::defaults() or a password broker.

The community Authentik driver is not auto-discovered. AppServiceProvider::configureSocialite() registers it via Event::listen(SocialiteWasCalled::class, ...); without that, Socialite::driver('authentik') throws "Driver [authentik] not supported".

The config key in config/services.php must stay "authentik" (Socialite resolves credentials by driver name). Its extra "name" key is only the label on the sign-in button.
