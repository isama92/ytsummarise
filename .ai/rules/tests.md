---
paths:
  - 'tests/**'
---

# Tests

## A test that really dispatches must fake the queue
phpunit.xml pins REDIS_HOST to `redis.invalid`, as `<server>` and forced `<env>` both, so nothing in the suite can reach a real Redis. QUEUE_CONNECTION=sync does not save you: SummariseVideo names its own `summaries` connection, which is a redis one, so `->onQueue()->execute()` under test opens a connection and 500s rather than running inline. Call Queue::fake(), or run the action by hand with the summariseVideo() helper in tests/Pest.php. The pin is deliberate - a suite that quietly passed against whatever a developer had on 6379 would fail on a machine with nothing.
