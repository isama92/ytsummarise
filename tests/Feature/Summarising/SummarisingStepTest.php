<?php

declare(strict_types=1);

use App\Actions\Summarising\ComposeSummary;
use App\Actions\Summarising\DraftIdeas;
use App\Actions\Summarising\FetchCaptions;
use App\Actions\Summarising\FindVideo;
use App\Enums\SummaryError;
use App\Enums\SummaryStatus;
use App\Jobs\ActionJob;
use App\Models\Summary;
use App\Services\Ai\Agents\ExtractIdeas;
use App\Services\YouTube\Requests\OembedRequest;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
 * What the five steps share, tested once rather than five times.
 *
 * The claim guard and the failure handler used to belong to one job and are asserted here on one
 * step, because they are inherited rather than written per step. What each step actually does
 * with them is its own file's business.
 */

/*
 * A step that gives up has to stop the four behind it, and returning does not do that: a chained
 * job dispatches the next one whenever it neither failed nor was released, so a video with no
 * captions would go on to be asked about by a model three times over. Cancelling the batch is
 * what SkipIfBatchCancelled then acts on.
 */
test('a step that gives up cancels the batch behind it', function (): void {
    Process::fake();
    Log::spy();
    withoutYouTubeKey();

    Saloon::fake([OembedRequest::class => MockResponse::make(status: 404)]);

    $summary = Summary::factory()->pending()->create();
    $claim = claimSummary($summary->id);

    $batch = Bus::batch([new ActionJob(FindVideo::class, [$summary->id, $claim, $summary->video_id])])
        ->name('cancel me')
        ->dispatch();

    $step = app(FindVideo::class);
    $step->actionJob = new ActionJob($step, [$summary->id, $claim, $summary->video_id]);
    $step->actionJob->withBatchId($batch->id);

    $step->execute($summary->id, $claim);

    expect($summary->fresh()?->error)->toBe(SummaryError::NotFound)
        ->and(Bus::findBatch($batch->id)?->cancelled())->toBeTrue();
});

/*
 * Running as something other than a job is the ordinary case under test, and a step reaching for
 * a batch it has not got must not be the thing that fails.
 */
test('a step with no job behind it gives up without reaching for a batch', function (): void {
    Process::fake();
    Log::spy();
    withoutYouTubeKey();

    Saloon::fake([OembedRequest::class => MockResponse::make(status: 404)]);

    $summary = Summary::factory()->pending()->create();
    $claim = claimSummary($summary->id);

    expect(fn () => app(FindVideo::class)->execute($summary->id, $claim))->not->toThrow(Throwable::class)
        ->and($summary->fresh()?->error)->toBe(SummaryError::NotFound);
});

/*
 * The claim is what tells one attempt from another, and it is the only thing that can: every step
 * of every attempt for a video carries the same row id. A step holding a claim the row has moved
 * past belongs to an attempt that was written off and replaced, and it must do nothing at all
 * rather than write an older summary over a newer one.
 */
test('a step whose claim no longer matches the row does nothing', function (): void {
    Process::fake();

    $summary = Summary::factory()->pending()->create([
        'started_at' => Date::now(),
        'claim' => (string) Str::ulid(),
        'transcript' => 'The words a replaced attempt read.',
        'transcript_language' => 'en',
    ]);

    /* A step from the attempt this row's claim replaced. */
    app(DraftIdeas::class)->execute($summary->id, (string) Str::ulid());

    expect($summary->fresh()?->ideas)->toBeNull();

    ExtractIdeas::assertNeverPrompted();
});

/*
 * The other two ways a step has nothing to do, neither of which is a fault: the row was pruned
 * out from under it, or the attempt is over.
 */
test('a step whose row is gone or finished does nothing', function (): void {
    Process::fake();

    $ready = Summary::factory()->create(['started_at' => Date::now(), 'claim' => (string) Str::ulid()]);

    app(DraftIdeas::class)->execute($ready->id, (string) $ready->claim);
    app(DraftIdeas::class)->execute(404, (string) Str::ulid());

    expect($ready->fresh()?->ideas)->toBeNull();

    ExtractIdeas::assertNeverPrompted();
});

/*
 * No step keys its own lock, and this is the test that says so.
 *
 * A batch reaches the queue through Queue::bulk(), which never consults UniqueLock, but every
 * chain continuation goes through a PendingDispatch, which does. A step that declared uniqueId()
 * could therefore be swallowed mid-chain by a lock an earlier attempt left behind - and a
 * swallowed step is a batch that never finishes and a row that stays pending for good.
 *
 * Two jobs for one step and one row wanting different keys is what proves none is declared.
 */
test('no step keys its own lock', function (): void {
    $summary = Summary::factory()->pending()->create();
    $claim = claimSummary($summary->id);

    foreach (summarisingSteps() as $step) {
        $action = app($step);

        $first = new ActionJob($action, [$summary->id, $claim, $summary->video_id]);
        $second = new ActionJob($action, [$summary->id, $claim, $summary->video_id]);

        expect($first->uniqueId())
            ->toStartWith($step.':')
            ->not->toBe($second->uniqueId());
    }
});

/*
 * Recording a failure on the row is what lets the page stop asking for an answer that is not
 * coming. Whichever step threw is the one that writes it, which is why this lives on the base.
 */
test('a step that throws records the failure, so the page stops waiting', function (): void {
    Log::spy();

    $summary = Summary::factory()->pending()->create();
    $claim = claimSummary($summary->id);

    app(ComposeSummary::class)->failed(new RuntimeException('the model refused'), $summary->id, $claim);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->outline)->toBeNull()
        /*
         * Unknown rather than anything more specific. Whatever threw is in the log; what the
         * page needs is a sentence, and guessing a better one from an exception message would
         * put something in front of somebody that was written for a developer.
         */
        ->and($summary->error)->toBe(SummaryError::Unknown);

    Log::shouldHaveReceived('error')->once();
});

/*
 * The transcript and the ideas survive a failure at the model, which is what makes the retry
 * cheap. Clearing them alongside the outline would throw away the only reason for storing them:
 * a retry then re-runs the pass that failed and nothing before it.
 */
test('a failure at the model keeps the transcript and the ideas for the retry', function (): void {
    Log::spy();

    $claim = (string) Str::ulid();

    $summary = Summary::factory()->processing()->create([
        'claim' => $claim,
        'transcript' => 'The words this attempt read.',
        'transcript_language' => 'en',
        'ideas' => 'What this attempt made of them.',
    ]);

    app(ComposeSummary::class)->failed(new RuntimeException('the model refused'), $summary->id, $claim);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->transcript)->toBe('The words this attempt read.')
        ->and($summary->transcript_language)->toBe('en')
        ->and($summary->ideas)->toBe('What this attempt made of them.');
});

/*
 * The first explanation wins. A row written off by summaries:expire and then thrown on by the
 * step it was waiting for is still, most usefully, a row that took too long.
 */
test('a failure does not overwrite a reason the row already had', function (): void {
    Log::spy();

    $claim = (string) Str::ulid();

    $summary = Summary::factory()->failed()->create(['error' => SummaryError::TimedOut, 'claim' => $claim]);

    app(FetchCaptions::class)->failed(new RuntimeException('the model refused'), $summary->id, $claim);

    expect($summary->fresh()?->error)->toBe(SummaryError::TimedOut);

    Log::shouldHaveReceived('error')->once();
});

/*
 * A step can succeed and the worker still die before it deletes the job, leaving a later attempt
 * free to throw. Marking the row failed then would hide a finished summary behind a "did not
 * work" message.
 */
test('a late failure does not throw away a summary that already finished', function (): void {
    Log::spy();

    $claim = (string) Str::ulid();

    $summary = Summary::factory()->create(['claim' => $claim]);
    $outline = $summary->outline;

    app(ComposeSummary::class)->failed(new RuntimeException('worker died after writing'), $summary->id, $claim);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->outline)->toBe($outline);

    Log::shouldHaveReceived('error')->once();
});

/*
 * The one that costs the most to get wrong.
 *
 * A step can throw ten minutes after it started - a model call is most of that - and by then the
 * attempt it belongs to may have been written off and replaced. Writing the failure
 * unconditionally would mark that live, half-paid-for attempt as failed, and its remaining steps
 * would then find the row no longer pending and quietly do nothing: a summary nobody is going to
 * get, with the model calls already paid for.
 */
test('a step from a replaced attempt does not fail the one that replaced it', function (): void {
    Log::spy();

    $summary = Summary::factory()->pending()->create();

    /* The attempt that is live now. */
    $live = claimSummary($summary->id);

    /* A step of the attempt it replaced, arriving late and throwing. */
    app(ComposeSummary::class)->failed(new RuntimeException('the model refused'), $summary->id, (string) Str::ulid());

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->error)->toBeNull()
        ->and($summary->claim)->toBe($live);

    /* Still logged, because a failure nobody recorded is the one worth being able to find. */
    Log::shouldHaveReceived('error')->once();
});

/*
 * The same window, reached from the other side. Giving up used to write through the model
 * instance, which is an unqualified update by id: a step that spent four minutes in yt-dlp and
 * then found no captions would write that verdict onto whatever attempt owned the row by then.
 *
 * Replaced while yt-dlp is being asked, which is the only moment this can happen in - after
 * claimed() said yes and before giveUp() writes.
 */
test('a step from a replaced attempt does not give up on the one that replaced it', function (): void {
    Log::spy();
    fakeYouTube();

    $summary = Summary::factory()->pending()->create();
    $stale = claimSummary($summary->id);

    Process::fake(function () use ($summary): FakeProcessResult {
        Summary::query()->whereKey($summary->getKey())->update([
            'started_at' => Date::now(),
            'claim' => 'the-attempt-that-replaced-it',
        ]);

        /* No captions, so this step is about to give up on an attempt that is already over. */
        return Process::result((string) json_encode([
            'language' => 'en',
            'subtitles' => [],
            'automatic_captions' => [],
        ]));
    });

    app(FetchCaptions::class)->execute($summary->id, $stale);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->error)->toBeNull()
        ->and($summary->claim)->toBe('the-attempt-that-replaced-it');
});
