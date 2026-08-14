---
paths:
  - 'app/Jobs/**'
---

# Jobs

This directory holds one class, and it is not where the work lives. Queueable actions are in
`app/Actions/**` - see `.ai/rules/actions.md`, which is where the rules about doing the work
went when `SummariseVideo` stopped being a job.

## What ActionJob puts back, and why each gap is silent
App\Jobs\ActionJob and Spatie\QueueableAction\ActionJob have the same short name, and ours extends theirs. The parent is aliased to BaseActionJob at the top of the file; anywhere else, `use ActionJob` means ours, and prose that means theirs says "Spatie's". Worth checking which one a snippet is about before copying it.

Every queueable action is dispatched as App\Jobs\ActionJob, named in config/queuableaction.php. Note the upstream misspelling in that filename - no hyphen - and the key it reads, config('queuableaction.job_class'). Renaming that config file to the package's correctly spelled name would leave the key unread and Spatie's ActionJob in place, which fails silently: everything would queue perfectly well, without a lock and without a tag.

The job class is a single global value; the package has no per-action selection. That is why the name is the plain one and there is no second class beside it: uniqueness is opted into per action by declaring uniqueId(), and an action wanting none is unaffected - see the ULID fallback below.

Four things about Spatie's ActionJob drive everything in ours. Each failure is silent, which is why they are pinned by ActionJobTest rather than left to be noticed.

1. tags() is called in Spatie's constructor, at dispatch, with no parameters, so nothing an action tags itself with could depend on what it is being run over. Ours never lets that call happen - it hands the parent a class name, so the `if (is_object($action))` branch never runs - and asks for tags itself with the action's arguments. An action's tags() may therefore require its parameters; SummariseVideo::tags(int $summaryId) does, which is what keeps this true if anyone changes it back.

2. resolveQueueableProperties() copies only connection, queue, chainConnection, chainQueue, delay, chained, tries, timeout, maxExceptions and retryUntil, read off app($actionClass) as properties rather than methods. So declare `public int $timeout` on an action, never a timeout() method - the method form is dropped in silence. uniqueFor is not on that list and ShouldBeUnique is not implemented at all, which is the whole reason this class exists.

3. failed() would be captured as [$action, 'failed'], and SerializesModels walks every property when the payload is written - so the action, and everything its constructor was given, rides into Redis. Here that is two Saloon connectors and a summariser on every dispatch, and a Guzzle sender is one closure away from making the write fail outright. Ours hands its parent a class name even when it has the instance, so there is no path that can capture it, and redoes the four `if (is_object($action))` branches itself: tags, middleware, backoff and retryUntil. failed() is overridden to resolve the action and forward the parameters, which is how it knows which row.

4. The action is built once, by the caller, and everything is read off that one instance. Keep an action's constructor dependencies cheap to build all the same - it is built in the web request.

5. Neither Illuminate\Bus\Queueable nor Spatie's ActionJob declares $tries, $timeout or $maxExceptions, so assigning them creates dynamic properties - deprecated on PHP 8.5, fatal on PHP 9, and dropped from the payload, because SerializesModels enumerates declared properties only. Ours declares all three. Anything else copied off an action needs the same treatment.

6. resolveQueueableProperties() is overridden to do nothing, and ours copies the same list from the instance the caller built. The parent resolves the action from the container once per property it finds, which is three extra builds of the whole dependency graph inside the web request - and worse, it reads those settings off a different instance than uniqueId(), tags(), middleware() and backoff() come from, so a caller setting one by hand gets it honoured for some and dropped for others.

Set $this->backoff only when an action actually asked for one. The trait's backoff() returns [] rather than null, and an empty array is not "no opinion" by the time it reaches the queue: it is imploded to "", exploded back to [''] and cast to 0, silently overriding the --backoff the worker was started with.

An action keys its lock with either a uniqueId() method or a $uniqueId property, because Laravel's UniqueLock reads both and honouring only one leaves the other silently never deduplicated. An action that declares neither gets a per-dispatch ULID key and a bounded TTL: the lock is taken and released but never refuses anybody. Do not "simplify" that to an empty key - it would serialise every dispatch of that action against every other. An action that does key itself must also declare $uniqueFor, and is told so with a LogicException at dispatch rather than quietly given the unkeyed default, which for a real key would be a real hour of refusing every re-dispatch. And do not make the key lazy: Laravel calls uniqueId() to take the lock at dispatch and again to release it after the worker is done, either side of a serialisation, so a value rebuilt the second time releases a key nothing ever held.
