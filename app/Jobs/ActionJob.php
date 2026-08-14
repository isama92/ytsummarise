<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Str;
use LogicException;
use Override;
use Spatie\QueueableAction\ActionJob as BaseActionJob;
use Throwable;

/**
 * The job every queueable action is dispatched as.
 *
 * config/queuableaction.php names this class, which is the only way to choose one: the package
 * reads a single global key rather than asking the action, so everything dispatched with
 * ->onQueue()->execute() arrives here. There is deliberately no second job class beside it, which
 * is why the name is the plain one - uniqueness is opted into per action by declaring uniqueId(),
 * and an action that declares none is unaffected for the reason under $uniqueFor below.
 *
 * It exists at all because three things an ordinary job gets for free do not survive Spatie's own
 * ActionJob: a uniqueness lock, a tag that says which video a job is for, and a failure handler
 * that knows which row it was working on. None of the three fails loudly, which is why each is
 * pinned by ActionJobTest rather than left to be noticed.
 *
 * The constructor takes them in the order the parent leaves them, and the trick that makes the
 * third one structural rather than corrective is at the top of it.
 *
 * One consequence of the name worth knowing before importing anything: this class and its parent
 * are both called ActionJob. The parent is aliased to BaseActionJob above, and any `use
 * ActionJob` elsewhere means this one. Prose that means the other says "Spatie's".
 */
class ActionJob extends BaseActionJob implements ShouldBeUnique
{
    /**
     * How long a lock nobody asked for is allowed to sit in the cache.
     *
     * Only ever the TTL of a key built from a fresh ULID, which by definition no second dispatch
     * can collide with, so the value cannot block anything and is not a policy about anything.
     * It is here because the alternative is zero, and zero means a lock with no expiry at all -
     * one cache key per dispatch, kept forever, for a job whose worker died before Laravel could
     * release it.
     *
     * An action that keys itself never reaches this. It is made to declare its own $uniqueFor,
     * because for a real key this number would be a real hour of refusing every re-dispatch.
     */
    private const int UNKEYED_LOCK_SECONDS = 3600;

    /**
     * What the parent would have copied off the action, copied here instead.
     *
     * The list is the parent's, verbatim. What differs is where the values come from and how
     * many times the action is built to get them; see resolveQueueableProperties() below.
     *
     * @var string[]
     */
    private const array QUEUEABLE_PROPERTIES = [
        'connection',
        'queue',
        'chainConnection',
        'chainQueue',
        'delay',
        'chained',
        'tries',
        'timeout',
        'maxExceptions',
        'retryUntil',
    ];

    /**
     * How long the uniqueness lock survives, taken from the action when it declares one.
     *
     * Declared rather than left to be created on assignment, which is the point of the property.
     * uniqueFor is not on the parent's list at all, so an action setting one would otherwise get
     * a lock with no expiry.
     */
    public int $uniqueFor = 0;

    /**
     * The three settings Laravel expects on a job and nothing in the chain above declares.
     *
     * Neither Illuminate\Bus\Queueable nor Spatie's ActionJob has them, so assigning them creates
     * dynamic properties: deprecated on PHP 8.5, fatal on PHP 9, and - because
     * SerializesModels::__serialize() enumerates declared properties only - dropped from the
     * payload on the way to the worker. Laravel snapshots maxTries and timeout into the payload
     * envelope at push time, so nothing misbehaves today; that is luck, not design.
     */
    public ?int $tries = null;

    public ?int $timeout = null;

    public ?int $maxExceptions = null;

    /**
     * The lock's key, decided once at dispatch and carried in the payload.
     *
     * Not recomputed on demand, and that is load-bearing. Laravel calls uniqueId() twice - once
     * to take the lock as the job is dispatched, once to release it after the worker is done -
     * and the two calls happen either side of a serialisation. Anything derived freshly the
     * second time releases a key nothing ever held and leaves the real one in place until it
     * expires, which for a keyed action is its whole timeout.
     *
     * Protected rather than private, and that is not a style choice either.
     * ReflectionClass::getProperties() does not report a parent's private properties, so on any
     * subclass of this one SerializesModels would leave this out of the payload entirely and the
     * restored job would find it uninitialised - throwing from uniqueId() after the work had
     * already been done, while still holding the lock.
     */
    protected readonly string $uniqueKey;

    /**
     * @param  class-string|object  $action
     * @param  mixed[]  $parameters
     */
    public function __construct(string|object $action, array $parameters = [])
    {
        /*
         * A class name, even when the caller handed us the instance, and that is the whole trick
         * rather than an economy.
         *
         * Everything the parent does only "if (is_object($action))" is redone below, and one of
         * those things is a bug here: it stores [$action, 'failed'] on the job, and
         * SerializesModels walks every property when the payload is written - so the action goes
         * into the queue, and with it everything its constructor was given. For this application
         * that is two Saloon connectors and a summariser on every dispatch, and a Guzzle sender
         * is one closure away from making the write fail outright rather than merely be wasteful.
         *
         * Clearing it afterwards would work as well and say less. Never handing the instance over
         * makes it structural: there is no path through this constructor that can capture it.
         */
        parent::__construct(is_object($action) ? $action::class : $action, $parameters);

        /*
         * The instance the caller built, or one from the container when a class name was given.
         * Both paths need one, because the parent has now been told nothing about either - and
         * this is the only one anything below reads, so every setting on the job comes from the
         * same object.
         */
        $resolved = is_object($action) ? $action : app($action);

        assert(is_object($resolved));

        $this->copyQueueableProperties($resolved);

        /*
         * Middleware, which the parent would have copied off the instance. Worth knowing when
         * reading Laravel rather than this class: the inherited middleware() returns an empty
         * array and this property is what actually carries an action's middleware, because
         * CallQueuedHandler merges the method and the property.
         */
        if (method_exists($resolved, 'middleware')) {
            $middleware = $resolved->middleware();

            assert(is_array($middleware));

            $this->middleware = $middleware;
        }

        /*
         * Backoff, but only when the action asked for one.
         *
         * The trait's default returns [] rather than null, and an empty array is not "no
         * opinion" by the time it reaches the queue: Queue::getJobBackoff() implodes it to "",
         * the worker explodes that back to [''] and casts it to 0, and the --backoff the worker
         * was started with is silently overridden with no delay at all. Inert while an action
         * runs once, wrong for the first one that retries.
         */
        if (method_exists($resolved, 'backoff')) {
            $backoff = $resolved->backoff();

            if (! in_array($backoff, [null, [], ''], true)) {
                $this->backoff = $backoff;
            }
        }

        if (method_exists($resolved, 'retryUntil')) {
            $this->retryUntil = $resolved->retryUntil();
        }

        $this->uniqueKey = $this->lockKeyFor($resolved, $parameters);

        /*
         * Tags, with the arguments the action was actually given.
         *
         * The parent asks an action for its tags in its own constructor, before it knows what the
         * action is being run over - so nothing it tags itself with could depend on its own
         * parameters. It never gets that far here, because it is handed a class name, which means
         * this is the only call and an action's tags() is free to require its arguments.
         */
        if (method_exists($resolved, 'tags')) {
            $tags = $resolved->tags(...$parameters);

            assert(is_array($tags));

            $this->tags = $tags;
        }
    }

    public function uniqueId(): string
    {
        return $this->uniqueKey;
    }

    /**
     * Hand a failure back to the action, with what it was working on.
     *
     * The parent passes the exception and nothing else, because the callback it captured was
     * bound to the action instance and expected to have the answer already. Resolving the action
     * here instead means nothing was serialised to keep, and the parameters are what tell it
     * which piece of work has just failed.
     *
     * Nullable where the parent is not: Laravel calls this with null when a payload cannot be
     * unserialised at all, and widening a parameter is the one direction an override may go.
     */
    #[Override]
    public function failed(?Throwable $exception): void
    {
        $action = app($this->actionClass);

        if (is_object($action) && method_exists($action, 'failed')) {
            $action->failed($exception, ...$this->parameters);
        }
    }

    /**
     * Deliberately nothing. copyQueueableProperties() does this job instead.
     *
     * The parent calls this from its constructor with a class name and then resolves the action
     * from the container once per property it finds - three more builds, of an action the caller
     * has already built, inside the web request. Worse than the waste: those values then come
     * from a different instance than uniqueId(), tags(), middleware() and backoff() are read
     * from, so a caller setting $action->timeout by hand gets it honoured for one and dropped
     * for the other, and a lock can end up outliving or underliving the work it guards.
     */
    #[Override]
    protected function resolveQueueableProperties(mixed $action): void
    {
        //
    }

    /**
     * Copy the parent's list of settings off the one instance everything else reads.
     */
    private function copyQueueableProperties(object $action): void
    {
        foreach (self::QUEUEABLE_PROPERTIES as $property) {
            if (property_exists($action, $property)) {
                $this->{$property} = $action->{$property};
            }
        }
    }

    /**
     * The key this job's lock is taken on, and the TTL that goes with it.
     *
     * Both decisions at once, because they are one decision. An action that keys itself is asking
     * to refuse duplicates, and how long to refuse them for is then a real question it has to
     * answer; an action that does not is asking for nothing, and gets a key no second dispatch
     * can collide with so that Laravel's lock is taken and released without ever refusing
     * anybody.
     *
     * Both forms of key are read, because Laravel reads both: Illuminate\Bus\UniqueLock takes
     * uniqueId() if there is one and the $uniqueId property otherwise. Honouring only the method
     * would leave an action written the property way silently never deduplicated - the exact
     * silent gap this class exists to close.
     *
     * @param  mixed[]  $parameters
     */
    private function lockKeyFor(object $action, array $parameters): string
    {
        $key = match (true) {
            method_exists($action, 'uniqueId') => $action->uniqueId(...$parameters),
            property_exists($action, 'uniqueId') => $action->uniqueId,
            default => null,
        };

        if ($key === null) {
            $this->uniqueFor = self::UNKEYED_LOCK_SECONDS;

            return $this->actionClass.':'.Str::ulid()->toString();
        }

        assert(is_string($key));

        if (! property_exists($action, 'uniqueFor') || ! is_int($action->uniqueFor)) {
            throw new LogicException(sprintf(
                '%s keys its own lock but never says how long to hold it. Declare `public int $uniqueFor` on it: '
                .'left to a default it would refuse every re-dispatch of that key for %d seconds, and left at zero '
                .'a worker killed mid attempt would hold the lock for good.',
                $this->actionClass,
                self::UNKEYED_LOCK_SECONDS,
            ));
        }

        $this->uniqueFor = $action->uniqueFor;

        /* Qualified, so two actions keyed on the same row do not share one lock. */
        return $this->actionClass.':'.$key;
    }
}
