---
paths:
  - 'app/Services/**'
---

# Services

## Saloon has its own sender, so Http::fake() does not touch it
The YouTube lookup goes through Saloon, which does not use Illuminate's HTTP client at all. `Http::fake()` and `Http::preventStrayRequests()` see nothing a connector sends, and Saloon's guard sees nothing `Http::` sends, so `tests/Pest.php` arms both: `Http::preventStrayRequests()` and `Saloon\Config::preventStrayRequests()`. Fake with `Saloon::fake([RequestClass => MockResponse::make(...)])` - keyed by request class, not by url pattern. The Laravel plugin destroys the global mock client on boot, so tests need no teardown.

Two Saloon gotchas found the hard way: a fake built with `->throw()` (how a connection failure is simulated - see `youTubeUnreachable()` in Pest.php) is never recorded as a sent response, so `assertSentCount()` reads zero for it; use `assertNotSent(SomeRequest::class)` to say "this endpoint was left alone". And the closure form of `assertSent()` receives `(Request $request, Response $response)`, not a PendingRequest - reach the real url via `$response->getPendingRequest()->getUri()`.

Two connectors because two hosts. Saloon v4 made absolute-url endpoints opt-in behind `$allowBaseUrlOverride`, a guard against a url deciding where a request goes, and there is no reason to opt out: oEmbed and the Data API are separate APIs and get a connector each.

Each request reads its own response through `createDtoFromResponse()`, and the action only combines the answers. That method cannot be redeclared abstract on a shared parent - Saloon provides a concrete one via the `CreatesDtoFromResponse` trait and PHP forbids making an inherited concrete method abstract. `dto()` works on failed responses, which is what the 404 and 401 arms depend on, so do not reach for `dtoOrFail()` or `AlwaysThrowOnErrors`. `dto()` is typed `mixed`; an `assert($result instanceof ...)` is what keeps phpstan happy without a branch no test can reach.

A status code is not the only way a response can be useless: `Response::json()` decodes with `JSON_THROW_ON_ERROR`, so a 2xx carrying html - a captive portal, a proxy with opinions - throws a `JsonException` out of `createDtoFromResponse()` and past anything catching only `FatalRequestException`. A class promising that every fault comes back as a value has to catch both.

## Connectors read config at construction, so pin their env in phpunit.xml
`DataApiConnector` reads `services.youtube.key` in its constructor and exposes `isConfigured()`, so whether the Data API gets asked at all is decided by config at the moment the connector is resolved. That makes the test suite sensitive to a developer's own `.env`: with a real `YOUTUBE_API_KEY` set, `isConfigured()` returns true and every test that fakes only the oEmbed answer goes on to ask the Data API and dies with "unable to guess a mock response". Twelve tests broke exactly this way the first time somebody added a key.

`phpunit.xml` pins `YOUTUBE_API_KEY` to the fake `test-api-key` for that reason, so the suite exercises the fuller two-endpoint configuration by default and never a developer's real key. A test wanting the keyless path calls `withoutYouTubeKey()` (`tests/Pest.php`), which sets the config to **null** and not to an empty string: `config/services.php` is what turns an empty environment value into null, and setting the config directly goes around it, so `''` would read as a key somebody meant and the Data API would be asked with `key=` on the end.

Pin a *named* value rather than an empty one. With `value=""` a broken pin is invisible - empty and null both give the keyless path, so the suite passes either way and the only symptom is a dozen unrelated failures the day somebody has a key. With a name, `LookupVideoTest` can assert the value that arrives, and a broken pin fails one test that says why.

**Pinning a credential takes both a `<server>` and a forced `<env>` entry**, and one alone silently does not work. Unforced, phpunit skips any variable already in the environment. Forced, it sets `putenv` and `$_ENV` but not `$_SERVER` (`PhpHandler::handleEnvVariables`) - and phpdotenv reads `ServerConstAdapter` before `EnvConstAdapter`, so a key exported from a shell profile still wins. Only `<server>` closes that, and it is applied unconditionally. Verified by running the suite with `YOUTUBE_API_KEY=x php artisan test`, which fails with `<env force>` alone and passes with both. Every other pin in that file has the same hole; the api key is the one value anybody plausibly exports.

Consequence worth knowing: with a key pinned, the empty-string branch of the normalisation in `config/services.php` is no longer reachable from a test. It stays because it is what production needs for `YOUTUBE_API_KEY=`, and `config/` is outside the coverage scope.

Also: **a failed Saloon request puts the full url, key included, in its exception message**, so anything logging `$exception->getMessage()` writes the credential to the application log at warning level, and a mock-not-found failure prints it into test output and CI logs. Guzzle looks like it handles this - the variable it builds is called `$redactedUriString` - but `Psr7\Utils::redactUserInfo` only masks a password in `user:pass@host` and leaves the query string alone. `LookupVideo::send()` trims the message at `' for '`, which is where the sender appends the url, and `LookupVideoTest` asserts a secret never reaches the log context.

The key itself lives on the connector rather than on `VideosRequest`, applied through `defaultAuth()` with a `QueryAuthenticator`, because it authenticates the API rather than belonging to one question. `config/services.php` normalises an absent or empty key to null so nothing downstream repeats that check.
