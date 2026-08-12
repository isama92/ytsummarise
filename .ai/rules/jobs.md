---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Use Illuminate\Support\Sleep, never sleep()
phpunit.xml sets QUEUE_CONNECTION=sync, so every job runs inline inside the test that dispatched it. A literal sleep() therefore charges its seconds to the suite; Sleep::for(...)->seconds() behaves identically at runtime and disappears under Sleep::fake(), which also lets a test assert the duration with Sleep::assertSlept().

Jobs here also implement ShouldBeUnique keyed on the thing being worked on, because the work will be a paid model call: firstOrCreate guards the row but two requests arriving together both find nothing and both dispatch. Always set $uniqueFor, or a worker killed mid job holds the lock forever.

## Claim the row, do not trust the lock
ShouldBeUnique stops a duplicate being queued; it does not stop one being worked twice. The lock's TTL starts when a job is dispatched, not when a worker picks it up, so a job that waits in a queue and then runs can outlive its own lock while still running - and with paid work that means paying twice.

Where the work costs money or must not be repeated, open handle() with a conditional update that claims the row, and return if it affects nothing:

    $claimed = Summary::query()->whereKey($this->summary->getKey())
        ->whereNull('started_at')->update(['started_at' => Date::now()]);

    if ($claimed === 0) { return; }

Two consequences worth keeping: whatever resets the row for a retry must clear the claim too, or it is unclaimable forever and every later job returns having done nothing. And a timeout has to be measured from the claim, never from when the row was created - comparing a runtime budget against an enqueue time writes rows off while their jobs are still queued.
