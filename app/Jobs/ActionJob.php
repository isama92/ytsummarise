<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Str;
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
     */
    private const int UNKEYED_LOCK_SECONDS = 3600;

    /**
     * How long the uniqueness lock survives, taken from the action when it declares one.
     *
     * Read here rather than by the parent, which is the point of the property. Its
     * resolveQueueableProperties() copies connection, queue, delay, tries, timeout,
     * maxExceptions and retryUntil off the action and stops there - uniqueFor is not on
     * that list, so an action that sets one would silently get a lock with no expiry.
     */
    public int $uniqueFor = 0;

    /**
     * The lock's key, decided once at dispatch and carried in the payload.
     *
     * Not recomputed on demand, and that is load-bearing. Laravel calls uniqueId() twice - once
     * to take the lock as the job is dispatched, once to release it after the worker is done -
     * and the two calls happen either side of a serialisation. Anything derived freshly the
     * second time releases a key nothing ever held and leaves the real one in place until it
     * expires, which for a keyed action is its whole timeout.
     */
    private readonly string $uniqueKey;

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
         * Both paths need one, because the parent has now been told nothing about either.
         */
        $resolved = is_object($action) ? $action : app($action);

        assert(is_object($resolved));

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

        /* And the two retry hooks, on the same terms. */
        if (method_exists($resolved, 'backoff')) {
            $this->backoff = $resolved->backoff();
        }

        if (method_exists($resolved, 'retryUntil')) {
            $this->retryUntil = $resolved->retryUntil();
        }

        $this->uniqueFor = property_exists($resolved, 'uniqueFor') && is_int($resolved->uniqueFor)
            ? $resolved->uniqueFor
            : self::UNKEYED_LOCK_SECONDS;

        /*
         * An action that does not key itself gets a key nothing else can hold, rather than no
         * key at all. The lock is still taken and still released; it simply never refuses
         * anybody, which is what "this action is not unique" has to mean when the job class is
         * chosen globally and every action comes through here.
         */
        $key = method_exists($resolved, 'uniqueId')
            ? $resolved->uniqueId(...$parameters)
            : Str::ulid()->toString();

        assert(is_string($key));

        /* Qualified, so two actions keyed on the same row do not share one lock. */
        $this->uniqueKey = $this->actionClass.':'.$key;

        /*
         * Tags, again, with the arguments the action was actually given.
         *
         * The parent asks for them in its own constructor, before it knows what the action is
         * being run over, so nothing an action tags itself with can depend on its own
         * parameters. Passing them here is safe whatever the action declares: PHP ignores extra
         * positional arguments to a userland method, so one using the trait's zero-argument
         * tags() is unaffected.
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
}
