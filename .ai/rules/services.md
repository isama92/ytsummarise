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

## Connectors read config at construction, so pin their env in phpunit.xml
`DataApiConnector` reads `services.youtube.key` in its constructor and exposes `isConfigured()`, so whether the Data API gets asked at all is decided by config at the moment the connector is resolved. That makes the test suite sensitive to a developer's own `.env`: with a real `YOUTUBE_API_KEY` set, `isConfigured()` returns true and every test that fakes only the oEmbed answer goes on to ask the Data API and dies with "unable to guess a mock response". Twelve tests broke exactly this way the first time somebody added a key.

`phpunit.xml` pins `YOUTUBE_API_KEY` empty for that reason, next to `DB_QUEUE_RETRY_AFTER` which is pinned for the same class of reason. Any future connector credential needs the same treatment. Tests that want a key set it with `config()->set()` before resolving the action.

Also: a failed Saloon request reports the full url, key included, in its exception message - so a mock-not-found failure prints the API key into the test output and would put it in CI logs. Nothing redacts it.

The key itself lives on the connector rather than on `VideosRequest`, applied through `defaultAuth()` with a `QueryAuthenticator`, because it authenticates the API rather than belonging to one question. `config/services.php` normalises an absent or empty key to null so nothing downstream repeats that check.
