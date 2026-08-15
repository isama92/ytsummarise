<?php

declare(strict_types=1);

use App\Actions\SummariseVideo;
use App\Actions\Summarising\FindVideo;
use App\Enums\SummaryError;
use App\Enums\SummaryStatus;
use App\Jobs\ActionJob;
use App\Models\Summary;
use App\Services\Ai\Agents\CreateSummary;
use App\Services\Ai\Agents\ExtractIdeas;
use App\Services\Ai\Agents\TranslateSummary;
use App\Services\YouTube\Requests\OembedRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
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

    summariseVideo($summary->id);

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

    summariseVideo($summary->id);

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

    summariseVideo($summary->id);

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

    /*
     * And it says so once in the log, which is the only trace a refusal leaves for anybody
     * looking at a worker rather than at the page. Asserted here because giveUp() is shared by
     * every cheap refusal, so this covers the reason lines for all of them.
     */
    Log::shouldHaveReceived('info')->once();
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

    summariseVideo($summary->id);

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

    summariseVideo($summary->id);

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

    summariseVideo($summary->id);

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

    summariseVideo(Summary::factory()->pending()->create()->id);

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

    summariseVideo($summary->id);

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Ready);

    Process::assertNothingRan();

    /*
     * The caption endpoint specifically, rather than assertNothingSent(). Step one fetches the
     * video's cover over the same client, and it does so on a retry too - the row is new here,
     * so there is no image on the disk to skip. What this test is about is the transcript, and
     * naming it is what keeps the assertion about that rather than about how many other things
     * happen to use http.
     */
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'timedtext'));

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

    summariseVideo($summary->id);

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

    /*
     * Arranged by hand rather than through fakeYouTube(), because the whole point of this test
     * is an oEmbed answer that helper does not give. The cover still has to be faked: a video
     * nobody is allowed to name still has a thumbnail, and the suite forbids a stray request.
     */
    fakeCover();

    fakeTranscript();
    fakeSummariser();

    $summary = Summary::factory()->pending()->create();

    summariseVideo($summary->id);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->outline)->not->toBeNull()
        ->and($summary->title)->toBeNull()
        ->and($summary->error)->toBeNull();
});

/*
 * An attempt written off part way through stops at the next step, and does not pay again.
 *
 * This is the one thing the split changed on purpose, so it is worth being plain about. As one
 * job, an attempt that got past the status guard finished regardless: summaries:expire could
 * write it off mid-flight and the job would still write its summary and clear the reason, which
 * was the right outcome because the model calls had been paid for as one unit either way.
 *
 * Split into five, "finish regardless" would mean paying for the calls *after* the attempt was
 * declared dead - two more model passes on a row the page has already offered to retry. So every
 * step re-reads the status, and a written-off attempt stops at the next boundary instead.
 *
 * What that costs is the work already done, and the retry gets most of it back: the transcript
 * and the ideas are both still on the row, so asking again resumes rather than restarts.
 */
test('an attempt written off part way through stops rather than finishing', function (): void {
    fakeYouTube();
    fakeTranscript();

    $summary = Summary::factory()->pending()->create();

    /*
     * Written off during the first model call. The faked agent is the seam for that - it is
     * called while the chain is part way through, which is the only moment this can happen in.
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

    /* Deliberately not faked: reaching it would be a second model call nobody should pay for. */
    summariseVideo($summary->id);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        /* The reason the row was written off with, not one invented by a step that came later. */
        ->and($summary->error)->toBe(SummaryError::TimedOut)
        ->and($summary->outline)->toBeNull()
        /*
         * And the work already done is kept, so asking again is the passes that did not run
         * rather than all of them.
         */
        ->and($summary->transcript)->not->toBeNull()
        ->and($summary->ideas)->toBe('An idea');
});

/*
 * The guarantee, and the reason it is a conditional update rather than a lock: two jobs for
 * the same video can exist however carefully the lock is sized, because its TTL starts when a
 * job is dispatched and a job that waited in a queue can outlive it. Only one of them may
 * pay for the model call.
 */
test('a second job for a video somebody is already working on does nothing', function (): void {
    Process::fake();

    $claim = Date::now()->subMinute();
    $summary = Summary::factory()->processing()->create(['started_at' => $claim]);

    /*
     * Nothing else is faked on purpose. A job that bounces off the claim must not have looked
     * anything up, fetched anything or prompted anything, and the suite's stray request guards
     * are what say so: reaching any of them from here throws rather than passing quietly.
     */
    summariseVideo($summary->id);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->outline)->toBeNull()
        /* Not re-stamped either: the claim belongs to whoever took it. */
        ->and($summary->started_at?->timestamp)->toBe($claim->timestamp);

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
test('a second dispatch for a video already being summarised queues nothing', function (): void {
    Bus::fake();

    $summary = Summary::factory()->pending()->create();

    app(SummariseVideo::class)->execute($summary->id);
    app(SummariseVideo::class)->execute($summary->id);

    /*
     * One batch between them, and the claim is what decided it rather than the lock. The lock
     * is released the moment the first of these returns - long before its batch finishes - so
     * by the time a second attempt is dispatched there is nothing in the cache to refuse it.
     * started_at is not null any more, which is what does.
     */
    Bus::assertBatchCount(1);

    expect($summary->fresh()?->started_at)->not->toBeNull();
});

/*
 * The older of two attempts must not land last.
 *
 * summaries:expire writes off a job that has been queued longer than its horizon even though
 * that job is alive and working - the horizon is deliberately blunt about the difference. The
 * page then offers to try again, somebody does, the controller clears the claim, and a second
 * job claims the row and starts. When the first one finishes it holds a summary of the same
 * video from an attempt nobody is waiting on any more, and writing it puts a finished summary
 * on screen while the job that replaced it is still running - which then overwrites it.
 *
 * Naming the moment it claimed is what makes that write affect nothing.
 */
test('a job whose attempt was superseded does not write its summary', function (): void {
    Log::spy();
    fakeYouTube();
    fakeTranscript();

    $summary = Summary::factory()->pending()->create();

    /*
     * Written off and asked for again while the model is working, which is the only window this
     * can happen in. The claim moving is what the controller does on a retry.
     */
    ExtractIdeas::fake(function () use ($summary): string {
        Summary::query()->whereKey($summary->getKey())->update([
            'status' => SummaryStatus::Pending,
            'started_at' => Date::now()->addMinute(),
            'claim' => 'the-attempt-that-replaced-it',
        ]);

        return 'An idea';
    });

    CreateSummary::fake(fn (): array => [
        'headline' => 'The summary nobody is waiting for',
        'points' => [],
        'takeaways' => [],
    ]);

    summariseVideo($summary->id);

    $summary->refresh();

    /* Left exactly as the newer attempt has it: still pending, and still holding its claim. */
    expect($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->claim)->toBe('the-attempt-that-replaced-it')
        ->and($summary->outline)->toBeNull();

    /* And said so, because otherwise it reads as an ordinary success in a worker log. */
    Log::shouldHaveReceived('warning')->once();
});

/*
 * The other half of the status guard, and what keeps one blunt horizon from costing anything.
 * summaries:expire does not ask whether a worker ever picked a row up, so a job queued behind
 * a long enough backlog is written off while it is still perfectly alive. This is where that
 * stops: the job re-reads the status it was handed and leaves a written-off attempt alone
 * rather than paying for a summary the page has already offered to try again.
 */
test('an attempt that was given up on is never queued', function (): void {
    Bus::fake();

    /* As summaries:expire leaves a row nothing ever started: failed, and never claimed. */
    $summary = Summary::factory()->failed()->create(['started_at' => null, 'transcript' => null]);

    app(SummariseVideo::class)->execute($summary->id);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->outline)->toBeNull()
        /* And not claimed on the way past, which would make the retry unworkable. */
        ->and($summary->started_at)->toBeNull();

    /* Nothing queued at all, so none of the five steps ever costs anything. */
    Bus::assertNothingBatched();
});

/*
 * The window between reading the status and claiming the row, which is why the claim checks
 * the status again rather than trusting the read. summaries:expire can write the attempt off
 * in between, and claiming it then pays for a summary the page has already offered to retry.
 */
test('an attempt given up on while the row is read is never claimed', function (): void {
    Bus::fake();

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

    app(SummariseVideo::class)->execute($summary->id);

    $summary->refresh();

    expect($raced)->toBeTrue()
        ->and($summary->status)->toBe(SummaryStatus::Failed)
        /* Not claimed on the way past, which would make the retry unworkable. */
        ->and($summary->started_at)->toBeNull();

    Bus::assertNothingBatched();
});

/*
 * Reading the configured timeout rather than setting one. Pinning it made this pass for a
 * value nobody deploys: with SUMMARY_TIMEOUT=3600 the real retry_after was below the real
 * timeout and the assertion still held, which is the paid double-summarisation this test
 * exists to prevent.
 *
 * Three files derive that timeout independently, because none of them can call config():
 * config/summaries.php has the original, config/queue.php needs it for retry_after and
 * config/horizon.php for the supervisor between them. This is what notices when only one of
 * the three is changed. The order it asserts is the one that matters:
 *
 *     longest step  <  supervisor timeout  <  connection retry_after
 *
 * The step rather than the whole attempt, since summarising became a chain of five: no single
 * job runs for the whole budget any more, and measuring retry_after against the sum would leave
 * a dead worker's job unreserved for the better part of an hour.
 *
 * Get it wrong in either direction and a worker is still running a job the queue has already
 * handed to somebody else.
 */
test('the queue cannot reserve a step again while it is still running', function (): void {
    $action = app(FindVideo::class);
    $timeout = config()->integer('summaries.step_timeout');

    expect($action->timeout)->toBe($timeout)
        ->and($action->connection)->toBe('summaries')
        /*
         * And both reach the job, which is not a given: the package copies a fixed list of
         * properties off the action at dispatch and reads them as properties rather than as
         * methods, so a timeout expressed any other way would be dropped in silence.
         */
        ->and(new ActionJob($action, [1, 'a-claim', 'dQw4w9WgXcQ']))
        ->toHaveProperty('timeout', $timeout)
        ->toHaveProperty('connection', 'summaries')
        ->and(config()->integer('horizon.defaults.supervisor-summaries.timeout'))
        ->toBeGreaterThan($timeout)
        ->and(config()->integer('queue.connections.summaries.retry_after'))
        ->toBeGreaterThan(config()->integer('horizon.defaults.supervisor-summaries.timeout'))
        /*
         * And the general-purpose connection is left where Laravel puts it, so a future job
         * does not silently inherit half an hour of stall after a worker dies.
         */
        ->and(config()->integer('queue.connections.redis.retry_after'))
        ->toBe(90)
        /*
         * Which the supervisor working it has to stay under, for the same reason as above.
         */
        ->and(config()->integer('horizon.defaults.supervisor-default.timeout'))
        ->toBeLessThan(90);
});

/*
 * The budgets inside the job have to fit inside the job's own *together*, or the thing that
 * gives up first is the worker - which stops the job mid-write and leaves the failure handler
 * guessing at "unknown" instead of a reason somebody can act on.
 *
 * Together, and not each on its own, which is the version of this test that shipped first and
 * passed while the sum overflowed by four minutes. One video can run two transcript steps
 * (asking yt-dlp, then fetching the track it names) and three prompts (the ideas, the summary,
 * and translating it), and with the defaults at the time that came to 2040 seconds against an
 * 1800 second job.
 */
test('the job budget covers every step it runs, added up', function (): void {
    $worstCase = (2 * config()->integer('summaries.transcript.timeout'))
        + (3 * config()->integer('summaries.model_timeout'));

    expect(config()->integer('summaries.timeout'))->toBeGreaterThan($worstCase);
});

/*
 * And it goes on holding when somebody changes one, which is the property that makes the
 * assertion above more than a snapshot of today's numbers. The config file is re-evaluated
 * rather than the container's copy read, because the derivation happens as the file is read.
 *
 * Through configWithEnv rather than putenv: env() reads $_SERVER before it reads anything
 * putenv set, so a bare putenv is honoured on a machine whose .env omits the key and ignored on
 * one whose .env has it. .env.example sets this one, so the putenv version of this test passed
 * here and failed in CI. See the helper in tests/Pest.php.
 */
test('raising a step budget raises the job budget with it', function (): void {
    $summaries = configWithEnv('summaries', ['SUMMARY_MODEL_TIMEOUT' => '1800']);

    expect($summaries['model_timeout'])->toBe(1800)
        ->and($summaries['timeout'])->toBeGreaterThan(
            (2 * $summaries['transcript']['timeout']) + (3 * $summaries['model_timeout']),
        );
});

/*
 * And the two files that copy that derivation move with it, which is the only thing making
 * three copies of one sum survivable.
 *
 * The same override through all three, re-reading each file rather than the container's copy,
 * because that is when the arithmetic happens. Somebody who raises a step budget in
 * config/summaries.php and nowhere else gets a job whose timeout has grown past the
 * retry_after guarding it - the worker is still summarising when the queue hands the same
 * video to the next one, and both of them pay for it. This is what says so.
 */
test('raising a step budget carries the queue and the supervisor with it', function (): void {
    $override = ['SUMMARY_MODEL_TIMEOUT' => '1800'];

    $summaries = configWithEnv('summaries', $override);
    $supervisor = configWithEnv('horizon', $override)['defaults']['supervisor-summaries']['timeout'];
    $retryAfter = configWithEnv('queue', $override)['connections']['summaries']['retry_after'];

    expect($summaries['step_timeout'])->toBeGreaterThan(1800)
        ->and($supervisor)->toBeGreaterThan($summaries['step_timeout'])
        ->and($retryAfter)->toBeGreaterThan($supervisor)
        /*
         * And the whole attempt still fits inside the horizon that gives up on one, which is the
         * second ordering and the one measured from when a video was asked for rather than from
         * when a worker picked a step up.
         */
        ->and($summaries['timeout'])->toBeLessThanOrEqual($summaries['stale_after']);
});

/*
 * The lock outlives the action that holds it, and no longer matches its timeout.
 *
 * This action only claims the row and queues a batch, so its own budget is a step's. What the
 * lock has to cover is the longest anything about this video is legitimately in flight, which is
 * the whole attempt - and only as a backstop, because Laravel releases it when this action
 * returns rather than when its batch finishes. The claim is the guarantee; see the action.
 */
test('the uniqueness lock outlasts the action and covers a whole attempt', function (): void {
    $action = app(SummariseVideo::class);

    expect($action->tries)->toBe(1)
        ->and($action->timeout)->toBe(config()->integer('summaries.step_timeout'))
        ->and($action->uniqueFor)->toBe(config()->integer('summaries.timeout'))
        ->and($action->uniqueFor)->toBeGreaterThan($action->timeout)
        /*
         * And the job carries it, which takes a deliberate read: uniqueFor is not on the list
         * of properties the package copies off an action, so without ActionJob doing it
         * the lock would be taken with no expiry at all and a worker killed mid attempt would
         * hold it forever.
         */
        ->and(new ActionJob($action, [1]))
        ->toHaveProperty('uniqueFor', $action->uniqueFor)
        ->toHaveProperty('tries', 1);
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

    summariseVideo($summary->id);

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

    $action = app(SummariseVideo::class);

    expect($action->uniqueId($summary->id))->toBe((string) $summary->id)
        /*
         * And the key the lock is actually taken on is that, qualified by the action, so a
         * second action keyed on the same row would not silently share this one's lock.
         */
        ->and((new ActionJob($action, [$summary->id]))->uniqueId())
        ->toBe(SummariseVideo::class.':'.$summary->id)
        ->and(fn (): Summary => Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']))->toThrow(QueryException::class);
});
