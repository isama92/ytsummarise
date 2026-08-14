---
paths:
  - 'tests/**'
---

# Tests

## A test that really dispatches must fake the queue
phpunit.xml pins REDIS_HOST to `redis.invalid`, as `<server>` and forced `<env>` both, so nothing in the suite can reach a real Redis. QUEUE_CONNECTION=sync does not save you: SummariseVideo names its own `summaries` connection, which is a redis one, so `->onQueue()->execute()` under test opens a connection and 500s rather than running inline. Call Queue::fake(), or run a whole attempt by hand with the summariseVideo() helper in tests/Helpers/Functions.php. The pin is deliberate - a suite that quietly passed against whatever a developer had on 6379 would fail on a machine with nothing.

## Test helpers live in tests/Helpers, fixtures in tests/Support
Global test functions are in `tests/Helpers/Functions.php`, not `tests/Pest.php`, which is left to what configures the suite. Nothing requires it and nothing needs to: `Pest\Bootstrappers\BootFiles` include_once's `Expectations`, `Expectations.php`, `Helpers`, `Helpers.php` and `Pest.php` under the test directory, recursing into the two that are directories. So a file dropped into `tests/Helpers` is loaded before the first test - and is loaded whether anything uses it or not, which is why it suits functions rather than anything costly to declare. It loads *before* `Pest.php`, so nothing in it may depend on what that file sets up.

Class fixtures are different and belong in `tests/Support`, where the PSR-4 entry in composer.json finds them by name.
