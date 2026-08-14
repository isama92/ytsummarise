<?php

declare(strict_types=1);

use App\Actions\SummariseVideo;
use App\Enums\SummaryError;
use App\Enums\SummaryStatus;
use App\Jobs\ActionJob;
use App\Models\Summary;
use App\Services\YouTube\OembedConnector;
use Illuminate\Support\Facades\Log;
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
 * The parent asks an action for its tags in its own constructor, before it has been told what
 * the action is being run over. Anything that leans on that alone can only ever tag the class
 * name, which every job on the dashboard already shares.
 */
test('the parameters reach tags(), which the package alone would not manage', function (): void {
    $summary = Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']);

    $action = app(SummariseVideo::class);

    expect($action->tags())->toBe([SummariseVideo::class])
        ->and(new ActionJob($action, [$summary->id]))
        ->tags()->toContain('video:dQw4w9WgXcQ');
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
        ->and($restored->parameters())->toBe([7]);
});

/*
 * And dropping that callback has to leave failed() working, which is the point of overriding it:
 * the package hands its handler the exception alone, and the action needs to know which row.
 */
test('a failure reaches the action with the row it was working on', function (): void {
    Log::spy();

    $summary = Summary::factory()->pending()->create();

    $job = new ActionJob(app(SummariseVideo::class), [$summary->id]);

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

    (new ActionJob(app(SummariseVideo::class), [$summary->id]))->failed(null);

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Failed);
});

/*
 * An action with no failed() at all must not be an error on the way out.
 */
test('an action with nothing to say about failure is left alone', function (): void {
    $job = new ActionJob(new UnkeyedAction);

    expect(fn () => $job->failed(new RuntimeException('anything')))->not->toThrow(Throwable::class);
});
