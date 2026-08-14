<?php

declare(strict_types=1);

use App\Actions\SummariseVideo;
use App\Actions\Summarising\DraftIdeas;
use App\Enums\SummaryError;
use App\Enums\SummaryStatus;
use App\Jobs\ActionJob;
use App\Models\Summary;
use App\Services\YouTube\OembedConnector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\QueueableAction\QueueableAction;
use Tests\Support\ActionWithBrokenFailureHandler;
use Tests\Support\SubclassedActionJob;
use Tests\Support\UnkeyedAction;

/*
 * The job every action is dispatched as, and the three things it exists to put back.
 *
 * Spatie's ActionJob is what config/queuableaction.php would otherwise name, and on its own it
 * would leave this application without a uniqueness lock, without a tag that says which video a
 * job is for, and with two Saloon connectors in every queue payload. None of those failures is
 * loud: each one queues perfectly well and goes wrong later, which is why they are asserted here
 * rather than left to be noticed.
 */

test('the job tags itself with the video and the row, not just the action', function (): void {
    $summary = Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']);

    $job = new ActionJob(app(SummariseVideo::class), [$summary->id]);

    expect($job->tags())->toBe([
        SummariseVideo::class,
        'summary:'.$summary->id,
        'video:dQw4w9WgXcQ',
    ]);
});

/*
 * The parent asks an action for its tags in its own constructor, before it has been told what the
 * action is being run over - so an action that leans on that call can only ever tag its own class
 * name, which every job on the dashboard already shares.
 *
 * This is the test that says the call never happens. SummariseVideo::tags() takes a required
 * argument, so the moment anything asks it for tags without them, dispatch is an
 * ArgumentCountError rather than a passing test with a poorer dashboard.
 */
test('an action whose tags() needs its parameters can still be dispatched', function (): void {
    Queue::fake();

    $summary = Summary::factory()->pending()->create(['video_id' => 'dQw4w9WgXcQ']);

    app(SummariseVideo::class)->onQueue()->execute($summary->id);

    Queue::assertPushed(
        ActionJob::class,
        fn (ActionJob $job): bool => $job->tags() === [
            SummariseVideo::class,
            'summary:'.$summary->id,
            'video:dQw4w9WgXcQ',
        ],
    );
});

/*
 * A row deleted between the dispatch and the tag being built is not worth failing a request
 * over, and the id is still worth having.
 */
test('a video code nobody can look up is left off rather than fatal', function (): void {
    $job = new ActionJob(app(SummariseVideo::class), [404]);

    expect($job->tags())->toBe([SummariseVideo::class, 'summary:404']);
});

/*
 * The lock is what stops two people retrying one failed video queueing two paid summaries, and
 * it is the half of ShouldBeUnique the package does not implement at all.
 */
test('two jobs for one row want the same lock and two rows do not', function (): void {
    $action = app(SummariseVideo::class);

    $first = new ActionJob($action, [7]);
    $second = new ActionJob($action, [7]);
    $other = new ActionJob($action, [8]);

    expect($first->uniqueId())->toBe($second->uniqueId())
        ->and($first->uniqueId())->not->toBe($other->uniqueId())
        /* Qualified by the action, so two actions keyed on one row do not share a lock. */
        ->and($first->uniqueId())->toStartWith(SummariseVideo::class.':');
});

/*
 * And the lock is actually applied, which is the assertion the rest of this file leans on and the
 * one thing nothing else would notice going missing: every other test here reads uniqueId() and
 * uniqueFor off the job, so all of them pass just as well with `implements ShouldBeUnique`
 * deleted. This is the one that fails.
 *
 * Two dispatches for one row, and only the first reaches the queue. Nothing processes them under
 * Queue::fake(), so the lock the first took is still held when the second is built.
 */
test('a second job for a row already queued never reaches the queue', function (): void {
    Queue::fake();

    $summary = Summary::factory()->pending()->create();

    app(SummariseVideo::class)->onQueue()->execute($summary->id);
    app(SummariseVideo::class)->onQueue()->execute($summary->id);

    Queue::assertPushed(ActionJob::class, 1);
});

/*
 * And a different row is a different lock, so the guard above cannot pass by refusing everything.
 */
test('a job for another row is not caught by that lock', function (): void {
    Queue::fake();

    $first = Summary::factory()->pending()->create();
    $second = Summary::factory()->pending()->create();

    app(SummariseVideo::class)->onQueue()->execute($first->id);
    app(SummariseVideo::class)->onQueue()->execute($second->id);

    Queue::assertPushed(ActionJob::class, 2);
});

/*
 * An action that keys itself is asking to refuse duplicates, so how long to refuse them for is a
 * real question it has to answer. Left to the unkeyed default it would hold a real key for a real
 * hour; left at zero, a worker killed mid attempt would hold it for good.
 */
test('an action that keys itself must say how long to hold the lock', function (): void {
    $keyed = new class
    {
        use QueueableAction;

        public function uniqueId(int $id): string
        {
            return (string) $id;
        }

        public function execute(int $id): void
        {
            //
        }
    };

    expect(fn (): ActionJob => new ActionJob($keyed, [7]))
        ->toThrow(LogicException::class, 'never says how long to hold it');
});

/*
 * Laravel's own UniqueLock reads uniqueId() if there is one and the $uniqueId property otherwise.
 * Honouring only the method would leave an action written the property way silently never
 * deduplicated - which is the failure this whole class exists to prevent, one level up.
 */
test('a lock keyed by property is honoured as well as one keyed by method', function (): void {
    $keyed = new class
    {
        use QueueableAction;

        public string $uniqueId = 'video:dQw4w9WgXcQ';

        public int $uniqueFor = 60;

        public function execute(): void
        {
            //
        }
    };

    $job = new ActionJob($keyed);

    expect($job->uniqueId())->toEndWith(':video:dQw4w9WgXcQ')
        ->and($job->uniqueFor)->toBe(60);
});

/*
 * The other side of that. One job class serves every action, so an action with no opinion about
 * uniqueness must not inherit one: keyed on the class alone it would be serialised against every
 * other dispatch of itself, one at a time, for no reason anybody asked for.
 */
test('an action that does not key itself is never refused a dispatch', function (): void {
    $action = new UnkeyedAction;

    $first = new ActionJob($action);
    $second = new ActionJob($action);

    expect($first->uniqueId())->not->toBe($second->uniqueId())
        ->and($first->uniqueId())->toStartWith(UnkeyedAction::class.':')
        /*
         * And its lock still expires. Zero means no expiry at all, so a worker killed before
         * Laravel could release this would leave the key behind for good.
         */
        ->and($first->uniqueFor)->toBeGreaterThan(0);
});

/*
 * The failure that costs money quietly rather than loudly.
 *
 * The package captures [$action, 'failed'] onto the job, and SerializesModels walks every
 * property when the payload is written - so the action rides into Redis with everything its
 * constructor was given. Here that is two Saloon connectors and a summariser, on every dispatch.
 */
test('the action is not serialised into the payload', function (): void {
    $payload = serialize(new ActionJob(app(SummariseVideo::class), [1]));

    expect($payload)->not->toContain(OembedConnector::class)
        /* The class name is still there, because that is what the worker resolves from. */
        ->and($payload)->toContain(SummariseVideo::class);
});

/*
 * The key has to survive the round trip, because the two calls that matter happen either side of
 * it: Laravel takes the lock as the job is dispatched and releases it after the worker is done,
 * and a key rebuilt on the way out would release one nothing ever held and leave the real one to
 * expire on its own.
 *
 * Worth an assertion rather than an assumption. The property is readonly, and SerializesModels
 * restores every property through the Reflection API rather than through the constructor.
 */
test('the lock key survives being queued', function (): void {
    $job = new ActionJob(app(SummariseVideo::class), [7]);

    $restored = unserialize(serialize($job));

    expect($restored)->toBeInstanceOf(ActionJob::class)
        ->and($restored->uniqueId())->toBe($job->uniqueId())
        ->and($restored->uniqueFor)->toBe($job->uniqueFor)
        ->and($restored->tags())->toBe($job->tags())
        ->and($restored->parameters())->toBe([7])
        /*
         * tries and timeout with them. Neither Illuminate\Bus\Queueable nor Spatie's ActionJob
         * declares either, so assigning them creates dynamic properties - deprecated on PHP 8.5,
         * fatal on PHP 9, and invisible to SerializesModels, which enumerates declared properties
         * only. They arrived MISSING here until this class declared them.
         */
        ->and($restored->tries)->toBe(1)
        ->and($restored->timeout)->toBe(config()->integer('summaries.step_timeout'));
});

/*
 * And nothing is assigned that was never declared, which is what the round trip above is really
 * protecting. A deprecation notice is easy to miss in a worker log and becomes a fatal on PHP 9.
 */
test('the job creates no dynamic properties', function (): void {
    $notices = [];

    set_error_handler(function (int $number, string $message) use (&$notices): bool {
        if (str_contains($message, 'dynamic property')) {
            $notices[] = $message;
        }

        return true;
    }, E_ALL);

    try {
        new ActionJob(app(SummariseVideo::class), [7]);
    } finally {
        restore_error_handler();
    }

    expect($notices)->toBeEmpty();
});

/*
 * A subclass has to survive the queue too, and the way it would fail is nasty: the work is done,
 * and only then does releasing the lock throw.
 *
 * ReflectionClass::getProperties() does not report a parent's private properties, so with
 * $uniqueKey private SerializesModels leaves it out of the payload of any subclass and the
 * restored job finds it uninitialised. The base class is unaffected, which is exactly why the
 * round trip above passed while this did not exist.
 */
test('a subclass survives the queue as well as this class does', function (): void {
    $job = new SubclassedActionJob(app(SummariseVideo::class), [7]);

    $restored = unserialize(serialize($job));

    expect($restored->uniqueId())->toBe($job->uniqueId());
});

/*
 * And dropping that callback has to leave failed() working, which is the point of overriding it:
 * the package hands its handler the exception alone, and the action needs to know which row.
 */
test('a failure reaches the action with the row it was working on', function (): void {
    Log::spy();

    $summary = Summary::factory()->pending()->create();
    $claim = claimSummary($summary->id);

    $job = new ActionJob(app(DraftIdeas::class), [$summary->id, $claim, $summary->video_id]);

    $job->failed(new RuntimeException('the model refused'));

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Failed)
        ->and($summary->fresh()?->error)->toBe(SummaryError::Unknown);
});

/*
 * Laravel calls failed() with null when a payload cannot be unserialised at all, which the
 * package's own non-nullable signature would reject.
 */
test('a failure with no exception to report is still recorded', function (): void {
    Log::spy();

    $summary = Summary::factory()->pending()->create();
    $claim = claimSummary($summary->id);

    (new ActionJob(app(DraftIdeas::class), [$summary->id, $claim, $summary->video_id]))->failed(null);

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Failed);
});

/*
 * The handler that fails while handling a failure.
 *
 * Job::fail() wraps this call in try/finally with no catch, and it is already dealing with an
 * exception by the time it gets here. A throw would escape into Worker::handleJobException, the
 * row would never be written off, and the page would wait for an answer nobody is going to send
 * until summaries:expire gives up hours later.
 */
test('a failure handler that throws does not take the job down with it', function (): void {
    Log::spy();

    $job = new ActionJob(new ActionWithBrokenFailureHandler);

    expect(fn () => $job->failed(new RuntimeException('the original failure')))
        ->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'A failed job could not record its own failure'
            && $context['action'] === ActionWithBrokenFailureHandler::class
            /* Both exceptions, because either one alone explains half of it. */
            && $context['failure'] === 'the original failure'
            && $context['unrecorded_because'] === 'the failure handler is broken too');
});

/*
 * The same when the action cannot be built at all, which is the likelier of the two: resolving one
 * builds everything its constructor asks for, and for SummariseVideo that is two Saloon connectors
 * and a summariser, none of which has anything to do with marking a row.
 */
test('an action that cannot be built does not take the job down either', function (): void {
    Log::spy();

    $summary = Summary::factory()->pending()->create();

    /* Built while the container still can, the way a dispatch would. */
    $job = new ActionJob(app(SummariseVideo::class), [$summary->id]);

    app()->bind(SummariseVideo::class, function (): never {
        throw new RuntimeException('config is missing on this deploy');
    });

    expect(fn () => $job->failed(new RuntimeException('the original failure')))
        ->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'A failed job could not record its own failure'
            /* The id is what says which row went unmarked. */
            && $context['parameters'] === [$summary->id]);
});

/*
 * And a parameter that is not a scalar is described rather than dumped. A model here would put a
 * whole row in the log, and a transcript is other people's words.
 */
test('the log line names the type of anything that is not a scalar', function (): void {
    Log::spy();

    $job = new ActionJob(new ActionWithBrokenFailureHandler, [new Summary, 7]);

    $job->failed(null);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['parameters'] === [Summary::class, 7]);
});

/*
 * An action with no failed() at all must not be an error on the way out.
 */
test('an action with nothing to say about failure is left alone', function (): void {
    $job = new ActionJob(new UnkeyedAction);

    expect(fn () => $job->failed(new RuntimeException('anything')))->not->toThrow(Throwable::class);
});
