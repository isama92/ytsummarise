---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Use Illuminate\Support\Sleep, never sleep()
A literal sleep() charges its seconds to the suite, whether the job runs inline or a test calls handle() directly. Sleep::for(...)->seconds() behaves identically at runtime and disappears under Sleep::fake(), which also lets a test assert the duration with Sleep::assertSlept().

Whether a job runs inline under test depends on the job, not only on phpunit.xml. QUEUE_CONNECTION=sync makes that the default, but a job naming its own connection - SummariseVideo calls onConnection('summaries') for its retry_after - overrides it, so dispatching that one under test queues it rather than running it. Do not write a test that expects a dispatched job to have finished by the time the request comes back without checking which applies; call handle() directly, or fake the queue and assert what was pushed.

Jobs here also implement ShouldBeUnique keyed on the thing being worked on, because the work will be a paid model call: firstOrCreate guards the row but two requests arriving together both find nothing and both dispatch. Always set $uniqueFor, or a worker killed mid job holds the lock forever.

## Claim the row, do not trust the lock
ShouldBeUnique stops a duplicate being queued; it does not stop one being worked twice. The lock's TTL starts when a job is dispatched, not when a worker picks it up, so a job that waits in a queue and then runs can outlive its own lock while still running - and with paid work that means paying twice.

Where the work costs money or must not be repeated, open handle() with a conditional update that claims the row, and return if it affects nothing:

    $claimed = Summary::query()->whereKey($this->summary->getKey())
        ->whereNull('started_at')->update(['started_at' => Date::now()]);

    if ($claimed === 0) { return; }

Two consequences worth keeping: whatever resets the row for a retry must clear the claim too, or it is unclaimable forever and every later job returns having done nothing.

And keep a runtime budget separate from a liveness horizon. The budget belongs to the work and runs from the claim - that is SUMMARY_TIMEOUT, and the worker enforces it. The horizon asks whether an attempt is still alive at all, runs from when it was asked for, and is deliberately blunt: it will write off a job still queued behind a long enough backlog, so it is sized so that is rare rather than impossible, and the job re-reads the status before doing anything so nothing is paid for either way.
