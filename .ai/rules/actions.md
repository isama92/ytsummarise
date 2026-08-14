---
paths:
  - 'app/Actions/**'
---

# Actions

## Claim the row, do not trust the lock
Queueable actions live here, not in app/Jobs - that directory now holds only ActionJob, the job class every action is dispatched as.

ShouldBeUnique stops a duplicate being queued; it does not stop one being worked twice. The lock's TTL starts when a job is dispatched, not when a worker picks it up, so an action that waits in a queue and then runs can outlive its own lock while still running - and with paid work that means paying twice.

Where the work costs money or must not be repeated, open execute() with a conditional update that claims the row, and return if it affects nothing:

    $claimed = Summary::query()->whereKey($summaryId)
        ->where('status', SummaryStatus::Pending)
        ->whereNull('started_at')
        ->update(['started_at' => Date::now()]);

    if ($claimed === 0) { return; }

Every condition the action already checked goes in here as well, not just the claim itself. Reading the status and then claiming are two statements, and whatever writes rows off can land between them.

Take a row id rather than a model. A restored job re-queries it while one run in process keeps whatever instance it was handed, and a test that runs two actions from one instance is testing neither.

Whatever resets the row for a retry must clear the claim too, or it is unclaimable forever and every later job returns having done nothing.

Keep a runtime budget separate from a liveness horizon. The budget belongs to the work and runs from the claim - SUMMARY_TIMEOUT, enforced by the worker. The horizon asks whether an attempt is alive at all, runs from when it was asked for, and is deliberately blunt.

Use Illuminate\Support\Sleep, never sleep(). A literal sleep() charges its seconds to the suite whether the action runs inline or a test calls execute() directly. Sleep::for(...)->seconds() behaves identically at runtime and disappears under Sleep::fake(), which also lets a test assert the duration with Sleep::assertSlept().

## ->onQueue() is what makes it a dispatch, and it overrides QUEUE_CONNECTION=sync
A bare ->execute() runs the action in the calling process. Only $action->onQueue()->execute(...) queues one, and the empty argument is right: the connection on the action already decides where it lands.

Whether it then runs inline under test depends on the action, not only on phpunit.xml. QUEUE_CONNECTION=sync makes inline the default, but an action naming its own connection - SummariseVideo declares `public ?string $connection = 'summaries'` for its retry_after - overrides it, so dispatching that one under test queues it rather than running it. Call execute() directly (the summariseVideo() helper in tests/Pest.php does), or fake the queue and assert what was pushed.

Two ways to assert a dispatch, and they answer different questions. QueueableActionFake::assertPushed(SomeAction::class) says an action was queued, matching on the job's displayName(). What it was queued *with* lives on the job, so that takes Queue::assertPushed(ActionJob::class, fn ($job) => $job->parameters() === [...]).

## Chains and batches: what a step may not do
Summarising is one named batch holding one chain of five steps, dispatched by App\Actions\SummariseVideo and living in app/Actions/Summarising. The house shape is Datahub's: a private chainActions(): list<ShouldQueue> helper, and Bus::batch([$chain])->name(...)->onConnection(...)->onQueue(...)->dispatch().

A nested array inside Bus::batch() is a chain. Batch::add() splits it with prepareBatchedChain() and counts every job in it towards totalJobs, so Bus::batch([[$a, $b, $c]]) is one named batch running in order with progress advancing per step. Passing the jobs unnested runs them all at once instead, which for a chain is silently wrong rather than an error.

A step must NOT declare uniqueId(). A batch reaches the queue through Queue::bulk(), which never consults Illuminate\Bus\UniqueLock, but every chain continuation goes through dispatchNextJobInChain() and therefore a PendingDispatch, which does. A keyed step can be swallowed mid-chain by a lock an earlier attempt left behind, and a swallowed step is a batch that never finishes and a row pending for good. Only the entry action is keyed, and its lock covers the moment of dispatch and nothing more - it is released when that action returns, long before its batch finishes.

So the guard against two attempts is the claim, not the lock. The entry action claims started_at with a conditional update and hands every step both the row id and that claim; each step reads and writes conditional on it. A step given only the id could not tell an attempt that was written off and replaced from the one it belongs to.

Returning from a step does not stop the chain. CallQueuedHandler dispatches the next job whenever the current one neither failed nor was released, so a step that gives up early must call $this->actionJob?->batch()?->cancel(). Throwing is different: a failed job stops its chain, and a batch without allowFailures() cancels itself on the first failure.

Every step re-reads the status, so an attempt written off part way through stops at the next boundary rather than finishing. That is deliberate and differs from the single job it replaced: finishing regardless would now mean paying for model calls after the attempt was declared dead.

Name batches "<Verb> <subject> (<id>)" so one is findable from the thing it is about.
