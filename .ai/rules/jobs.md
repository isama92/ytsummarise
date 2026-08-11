---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Use Illuminate\Support\Sleep, never sleep()
phpunit.xml sets QUEUE_CONNECTION=sync, so every job runs inline inside the test that dispatched it. A literal sleep() therefore charges its seconds to the suite; Sleep::for(...)->seconds() behaves identically at runtime and disappears under Sleep::fake(), which also lets a test assert the duration with Sleep::assertSlept().

Jobs here also implement ShouldBeUnique keyed on the thing being worked on, because the work will be a paid model call: firstOrCreate guards the row but two requests arriving together both find nothing and both dispatch. Always set $uniqueFor, or a worker killed mid job holds the lock forever.
