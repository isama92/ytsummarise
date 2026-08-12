<?php

declare(strict_types=1);

use App\Enums\SummaryError;
use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use App\Services\Ai\Agents\CreateSummary;
use App\Services\Ai\Agents\ExtractIdeas;
use App\Services\Ai\Agents\TranslateSummary;
use App\Services\YouTube\Requests\OembedRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
 * A note on what stands in for "nothing was paid for", which used to be Sleep::assertNeverSlept().
 *
 * The work is now three real collaborators, so the tests about a job that must not do any of it
 * fake none of them and assert on that directly. An unfaked agent resolves the configured
 * provider and sends a real request, which the suite's Http guard turns into a failure naming the
 * url - so reaching a prompt from one of those tests is loud rather than quiet.
 */

test('the job writes a summary and marks it ready', function (): void {
    fakeSummarisableVideo('Never Gonna Give You Up');

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->outline['original']['headline'])->toBe('The whole video in one sentence')
        ->and($summary->outline['original']['points'])->toHaveCount(2)
        ->and($summary->outline['language'])->toBe('en')
        /* Nothing to translate, and a real absence rather than the same summary twice. */
        ->and($summary->outline['english'])->toBeNull()
        /* The title arrives with the summary rather than ahead of it. */
        ->and($summary->title)->toBe('Never Gonna Give You Up')
        ->and($summary->error)->toBeNull()
        /* And records when it began, which is what the timeout is measured against. */
        ->and($summary->started_at)->not->toBeNull();
});

test('a video in another language is summarised in it and translated', function (): void {
    fakeSummarisableVideo(language: 'nl');

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->outline['language'])->toBe('nl')
        ->and($summary->outline['original']['headline'])->toBe('The whole video in one sentence')
        ->and($summary->outline['english']['headline'])->toBe('The whole video in one English sentence')
        ->and($summary->transcript_language)->toBe('nl');
});

/*
 * A video that does not exist, which is the whole point of looking one up: without this the
 * job cheerfully summarises eleven characters nobody can watch.
 */
test('a video that does not exist is failed rather than summarised', function (): void {
    Log::spy();
    Process::fake();

    withoutYouTubeKey();

    Saloon::fake([OembedRequest::class => MockResponse::make(status: 404)]);

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->error)->toBe(SummaryError::NotFound)
        ->and($summary->outline)->toBeNull();

    /*
     * And nothing was spent, which is why the lookup comes first: no transcript fetched and no
     * model asked. The agents are deliberately not faked, so a prompt would fail rather than
     * pass unnoticed.
     */
    Process::assertNothingRan();
    ExtractIdeas::assertNeverPrompted();
});

/*
 * Told apart from the case above on purpose. Not knowing whether a video exists is not the
 * same as knowing it does not, and only one of the two is worth submitting again.
 */
test('a video nobody could be asked about is failed as unreachable', function (): void {
    Process::fake();

    withoutYouTubeKey();

    Saloon::fake([OembedRequest::class => youTubeUnreachable()]);

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->error)->toBe(SummaryError::Unreachable)
        ->and($summary->outline)->toBeNull();

    Process::assertNothingRan();
});

/*
 * The captions are what gets summarised, so a video without any has nothing to summarise however
 * capable the model is. A permanent answer, and the message for it does not invite another go.
 */
test('a video with no subtitles is failed rather than summarised', function (): void {
    Log::spy();
    fakeYouTube();

    Process::fake(fn () => Process::result((string) json_encode([
        'language' => 'en',
        'subtitles' => [],
        'automatic_captions' => [],
    ])));

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->error)->toBe(SummaryError::NoTranscript)
        ->and($summary->outline)->toBeNull()
        ->and($summary->transcript)->toBeNull();

    /* The second of the two cheap refusals: still nothing asked of a model. */
    ExtractIdeas::assertNeverPrompted();
});

/*
 * Told apart from having no subtitles for the same reason not_found is told apart from
 * unreachable: one of them is worth another attempt and the other never is.
 */
test('a video whose subtitles could not be fetched is failed as unavailable', function (): void {
    Log::spy();
    fakeYouTube();
    ytDlpFails();

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->error)->toBe(SummaryError::Unavailable)
        ->and($summary->outline)->toBeNull();

    ExtractIdeas::assertNeverPrompted();
});

/*
 * Written before the model is asked rather than with the summary, which is the whole reason for
 * keeping it: the fetch and the model call fail independently, and a failure of the second kind
 * should leave the words behind for the retry.
 */
test('the transcript is stored before the model is asked about it', function (): void {
    fakeYouTube();
    fakeTranscript('We are no strangers to love.');

    ExtractIdeas::fake(function () use (&$storedMidway): string {
        $storedMidway = Summary::sole()->transcript;

        return 'An idea';
    });

    CreateSummary::fake(fn (): array => [
        'headline' => 'A headline',
        'points' => [],
        'takeaways' => [],
    ]);

    work(new SummariseVideo(Summary::factory()->pending()->create()->id));

    expect($storedMidway)->toBe('We are no strangers to love.');
});

/*
 * The payoff for storing it. A retry after the model failed re-runs the model and nothing else:
 * no second process, no second request to YouTube, and the new attempt reads exactly the words
 * the failed one did rather than whatever the captions say today.
 *
 * The row is pending with a transcript on it, which is what SummaryController leaves behind when
 * somebody submits a failed video again - it clears the outline and the reason and leaves the
 * transcript alone.
 */
test('a retry after a failed model call does not fetch the transcript again', function (): void {
    fakeYouTube();
    fakeSummariser();
    Process::fake();

    $summary = Summary::factory()->pending()->create([
        'transcript' => 'The words from the attempt before.',
        'transcript_language' => 'nl',
    ]);

    work(new SummariseVideo($summary->id));

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Ready);

    Process::assertNothingRan();
    Http::assertNothingSent();

    /* And read as Dutch, so it is still translated rather than taken for English. */
    ExtractIdeas::assertPrompted('The words from the attempt before.');
    TranslateSummary::assertPrompted(fn (): bool => true);
});

/*
 * Both columns or neither. A row holding a transcript without its language cannot be told whether
 * it needs translating, so it is fetched again rather than guessed at.
 */
test('a transcript without its language is fetched again', function (): void {
    fakeSummarisableVideo();

    $summary = Summary::factory()->pending()->create([
        'transcript' => 'The words from the attempt before.',
        'transcript_language' => null,
    ]);

    work(new SummariseVideo($summary->id));

    Process::assertRan(fn (): bool => true);

    expect($summary->fresh()?->transcript)->toBe('We are no strangers to love.');
});

/*
 * A video that exists but will not be named. Worth summarising anyway, so the heading is what
 * is missing rather than the summary.
 */
test('a video the lookup will not name is still summarised', function (): void {
    withoutYouTubeKey();

    Saloon::fake([OembedRequest::class => MockResponse::make(status: 401)]);

    fakeTranscript();
    fakeSummariser();

    $summary = Summary::factory()->pending()->create();

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->outline)->not->toBeNull()
        ->and($summary->title)->toBeNull()
        ->and($summary->error)->toBeNull();
});

/*
 * The expiry command's horizon is deliberately blunt, so it can write off an attempt whose
 * worker is alive and part way through. That job then finishes and writes its summary, and the
 * reason left behind has to go with it: a ready summary carrying "this took too long" is a trap
 * for anything that reads the column, starting with the page.
 */
test('a summary that finishes after being written off does not keep the reason', function (): void {
    fakeYouTube();
    fakeTranscript();

    $summary = Summary::factory()->pending()->create();

    /*
     * Written off during the model call. The faked agent is the seam for that now - it is called
     * while the job is part way through, which is the only moment this can happen in.
     */
    ExtractIdeas::fake(function () use ($summary): string {
        Summary::query()
            ->whereKey($summary->getKey())
            ->update([
                'status' => SummaryStatus::Failed,
                'error' => SummaryError::TimedOut,
            ]);

        return 'An idea';
    });

    CreateSummary::fake(fn (): array => [
        'headline' => 'A headline',
        'points' => [],
        'takeaways' => [],
    ]);

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->outline)->not->toBeNull()
        ->and($summary->error)->toBeNull();
});

/*
 * The guarantee, and the reason it is a conditional update rather than a lock: two jobs for
 * the same video can exist however carefully the lock is sized, because its TTL starts when a
 * job is dispatched and a job that waited in a queue can outlive it. Only one of them may
 * pay for the model call.
 */
test('a second job for a video somebody is already working on does nothing', function (): void {
    Process::fake();

    $claimedAt = Date::now()->subMinute();
    $summary = Summary::factory()->processing()->create(['started_at' => $claimedAt]);

    /*
     * Nothing else is faked on purpose. A job that bounces off the claim must not have looked
     * anything up, fetched anything or prompted anything, and the suite's stray request guards
     * are what say so: reaching any of them from here throws rather than passing quietly.
     */
    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->outline)->toBeNull()
        /* Not re-stamped either: the claim belongs to whoever took it. */
        ->and($summary->started_at?->timestamp)->toBe($claimedAt->timestamp);

    Process::assertNothingRan();
});

/*
 * Two jobs overlapping on one row, which is the case worth pinning: only one may pay.
 *
 * The overlap is the whole test and has to be arranged deliberately. Run one after the other,
 * the row is already ready by the time the second loads it, so it stops at the status guard
 * and never reaches the claim at all - which is how two earlier versions of this test both
 * managed to pass with the claim deleted outright. Running the second inside the first's model
 * call is the only arrangement where the claim is what answers.
 */
test('the first of two jobs pays and the second does not', function (): void {
    fakeYouTube();
    fakeTranscript();

    $summary = Summary::factory()->pending()->create();

    $first = new SummariseVideo($summary->id);
    $second = new SummariseVideo($summary->id);

    /*
     * Once, or a second job that got past the claim would prompt, re-enter here and recurse
     * rather than failing. The guard costs nothing when the claim works, because the second
     * job returns without prompting and this never fires twice anyway.
     */
    $overlapped = false;
    $prompts = 0;

    ExtractIdeas::fake(function () use ($second, &$overlapped, &$prompts): string {
        $prompts++;

        if (! $overlapped) {
            $overlapped = true;

            work($second);
        }

        return 'An idea';
    });

    CreateSummary::fake(fn (): array => [
        'headline' => 'A headline',
        'points' => [],
        'takeaways' => [],
    ]);

    work($first);

    /* One pass through the model stands for one summary paid for. */
    expect($overlapped)->toBeTrue()
        ->and($prompts)->toBe(1)
        ->and($summary->fresh()?->status)->toBe(SummaryStatus::Ready);
});

/*
 * The other half of the status guard, and what keeps one blunt horizon from costing anything.
 * summaries:expire does not ask whether a worker ever picked a row up, so a job queued behind
 * a long enough backlog is written off while it is still perfectly alive. This is where that
 * stops: the job re-reads the status it was handed and leaves a written-off attempt alone
 * rather than paying for a summary the page has already offered to try again.
 */
test('a job whose attempt was given up on does nothing', function (): void {
    Process::fake();

    /* As summaries:expire leaves a row nothing ever started: failed, and never claimed. */
    $summary = Summary::factory()->failed()->create(['started_at' => null, 'transcript' => null]);

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->outline)->toBeNull()
        /* And not claimed on the way past, which would make the retry unworkable. */
        ->and($summary->started_at)->toBeNull();

    Process::assertNothingRan();
});

/*
 * The window between reading the status and claiming the row, which is why the claim checks
 * the status again rather than trusting the read. summaries:expire can write the attempt off
 * in between, and claiming it then pays for a summary the page has already offered to retry.
 */
test('a job whose attempt is given up on while it reads the row does not claim it', function (): void {
    Process::fake();

    $summary = Summary::factory()->pending()->create();
    $raced = false;

    /*
     * Write the attempt off the moment the job has read it. The event fires once the rows
     * have been fetched, so a listener on the job's only select over this table lands the
     * change between that read and the claim.
     */
    DB::listen(function (QueryExecuted $query) use ($summary, &$raced): void {
        if ($raced || ! str_contains($query->sql, 'select')) {
            return;
        }

        $raced = true;

        Summary::query()
            ->whereKey($summary->getKey())
            ->update([
                'status' => SummaryStatus::Failed,
            ]);
    });

    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($raced)->toBeTrue()
        ->and($summary->status)->toBe(SummaryStatus::Failed)
        /* Not claimed on the way past, which would make the retry unworkable. */
        ->and($summary->started_at)->toBeNull();

    Process::assertNothingRan();
});

test('a job that gives up records the failure, so the page stops waiting', function (): void {
    Log::spy();

    $summary = Summary::factory()->pending()->create();

    (new SummariseVideo($summary->id))->failed(new RuntimeException('the model refused'));

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
 * The transcript survives a failure at the model, which is what makes the retry cheap. Clearing
 * it here alongside the outline would throw away the only reason for storing it.
 */
test('a failure at the model keeps the transcript for the retry', function (): void {
    Log::spy();

    $summary = Summary::factory()->processing()->create([
        'transcript' => 'The words this attempt read.',
        'transcript_language' => 'en',
    ]);

    (new SummariseVideo($summary->id))->failed(new RuntimeException('the model refused'));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->transcript)->toBe('The words this attempt read.')
        ->and($summary->transcript_language)->toBe('en');
});

/*
 * The first explanation wins. A row written off by summaries:expire and then thrown on by the
 * job it was waiting for is still, most usefully, a row that took too long.
 */
test('a failure does not overwrite a reason the row already had', function (): void {
    Log::spy();

    $summary = Summary::factory()->failed()->create(['error' => SummaryError::TimedOut]);

    (new SummariseVideo($summary->id))->failed(new RuntimeException('the model refused'));

    expect($summary->fresh()?->error)->toBe(SummaryError::TimedOut);

    Log::shouldHaveReceived('error')->once();
});

/*
 * handle() can succeed and the worker still die before it deletes the job, leaving a
 * later attempt free to throw. Marking the row failed then would hide a finished summary
 * behind a "did not work" message.
 */
test('a late failure does not throw away a summary that already finished', function (): void {
    Log::spy();

    $summary = Summary::factory()->create();
    $outline = $summary->outline;

    (new SummariseVideo($summary->id))->failed(new RuntimeException('worker died after writing'));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->outline)->toBe($outline);

    Log::shouldHaveReceived('error')->once();
});

/*
 * Reading the configured timeout rather than setting one. Pinning it made this pass for a
 * value nobody deploys: with SUMMARY_TIMEOUT=3600 the real retry_after was below the real
 * timeout and the assertion still held, which is the paid double-summarisation this test
 * exists to prevent.
 */
test('the queue cannot reserve the job again while it is still running', function (): void {
    $job = new SummariseVideo(Summary::factory()->pending()->create()->id);
    $timeout = config()->integer('summaries.timeout');

    expect($job->timeout)->toBe($timeout)
        ->and($job->connection)->toBe('summaries')
        ->and(config()->integer('queue.connections.summaries.retry_after'))
        ->toBeGreaterThan($timeout)
        /*
         * And the default connection is left where Laravel puts it, so a future job does
         * not silently inherit half an hour of stall after a worker dies.
         */
        ->and(config()->integer('queue.connections.database.retry_after'))
        ->toBe(90);
});

/*
 * The budgets inside the job have to fit inside the job's own, or the thing that gives up first
 * is the worker - which stops the job mid-write and leaves the failure handler guessing at
 * "unknown" instead of a reason somebody can act on.
 */
test('no single step may outlast the job that runs it', function (): void {
    $timeout = config()->integer('summaries.timeout');

    expect(config()->integer('summaries.model_timeout'))->toBeLessThanOrEqual($timeout)
        ->and(config()->integer('summaries.transcript.timeout'))->toBeLessThanOrEqual($timeout);
});

/*
 * One attempt is what keeps the lock, the timeout and the expiry horizon equal. More
 * attempts and the worst case life of a job is tries × timeout plus backoff, which
 * outlasts the lock, and a submission in that window queues a second paid summary of the
 * same video.
 */
test('the uniqueness lock lasts exactly as long as the one attempt it guards', function (): void {
    $job = new SummariseVideo(Summary::factory()->pending()->create()->id);

    expect($job->tries)->toBe(1)
        ->and($job->uniqueFor)->toBe($job->timeout)
        ->and($job->uniqueFor)->toBe(config()->integer('summaries.timeout'));
});

/*
 * A worker killed between finishing and deleting the job leaves it to be reserved again.
 * Running it twice would pay for the model call twice and rewrite a summary somebody is
 * already reading.
 */
test('a job delivered twice does not summarise twice', function (): void {
    Process::fake();

    $summary = Summary::factory()->create();
    $outline = $summary->outline;

    work(new SummariseVideo($summary->id));

    expect($summary->fresh()?->outline)->toBe($outline);

    Process::assertNothingRan();
});

/*
 * Keyed on the row rather than on the video code, which is only safe because they are the
 * same key under two names. The second half of this is what makes the first half true, so it
 * is asserted rather than trusted: lose the unique index on video_id and a video can have two
 * rows, two ids, and two jobs paying for it at once.
 */
test('one job is in flight per video, not per request', function (): void {
    $summary = Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']);

    expect((new SummariseVideo($summary->id))->uniqueId())->toBe((string) $summary->id)
        ->and(fn (): Summary => Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']))->toThrow(QueryException::class);
});
