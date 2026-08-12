<?php

declare(strict_types=1);

use App\Enums\SummaryError;
use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/*
 * The uuid is the public handle and the integer id is still the identity. HasUuids fills
 * the primary key by default, so this pins the uniqueIds() override on the model: get it
 * wrong and the key silently becomes a string, taking every future foreign key with it.
 */
test('a summary is addressed by uuid and keyed by an integer', function (): void {
    $summary = Summary::factory()->create();

    expect($summary->uuid)->toBeUuid()
        ->and($summary->id)->toBeInt()
        ->and($summary->getRouteKeyName())->toBe('uuid')
        ->and($summary->getIncrementing())->toBeTrue()
        ->and(route('summaries.show', $summary))->toContain($summary->uuid)
        ->and(route('summaries.show', $summary))->not->toContain($summary->video_id);
});

test('submitting a video queues the work and hands the browser its url', function (): void {
    Queue::fake();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ']);

    $summary = Summary::query()->sole();

    $response->assertRedirect(route('summaries.show', $summary));

    expect($summary->video_id)->toBe('dQw4w9WgXcQ')
        ->and($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->body)->toBeNull();

    Queue::assertPushed(SummariseVideo::class);
});

test('a video somebody already summarised is not summarised again', function (): void {
    Queue::fake();

    $summary = Summary::factory()->create(['video_id' => 'dQw4w9WgXcQ']);

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('summaries.show', $summary));

    Queue::assertNothingPushed();

    expect(Summary::query()->count())->toBe(1)
        ->and($summary->fresh()?->status)->toBe(SummaryStatus::Ready);
});

test('submitting a video whose summary failed is how you retry it', function (): void {
    Queue::fake();

    $summary = Summary::factory()->failed()->create([
        'video_id' => 'dQw4w9WgXcQ',
        'requested_at' => Date::now()->subHour(),
        'error' => SummaryError::Unreachable,
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('summaries.show', $summary));

    Queue::assertPushed(SummariseVideo::class);

    $summary->refresh();

    expect(Summary::query()->count())->toBe(1)
        ->and($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->body)->toBeNull()
        /*
         * And the reason the last attempt failed goes with it. Left behind, the page would
         * explain why the attempt currently running has failed while it is still running.
         */
        ->and($summary->error)->toBeNull()
        /* A retry is a new attempt, so its clock starts now rather than an hour ago. */
        ->and($summary->requested_at->diffInSeconds(Date::now(), true))->toBeLessThan(5);
});

/*
 * Not a duplicate dispatch. The job is unique per video, so while one is in flight this
 * one is dropped and the browser simply joins the job already running. Once the lock has
 * lapsed, the same call is what starts the replacement attempt.
 */
test('resubmitting a video already being summarised joins it without restarting its clock', function (): void {
    Queue::fake();

    $askedAt = Date::now()->subMinutes(4);
    $summary = Summary::factory()->pending()->create([
        'video_id' => 'dQw4w9WgXcQ',
        'requested_at' => $askedAt,
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('summaries.show', $summary));

    expect(Summary::query()->count())->toBe(1)
        ->and($summary->fresh()?->requested_at->timestamp)->toBe($askedAt->timestamp);
});

/*
 * Nothing in the request path talks to YouTube. Submitting has to stay fast and has to keep
 * working while YouTube does not, so both the existence check and the title belong to the job,
 * which is allowed to be slow and allowed to fail. The suite's stray request guard is what
 * enforces this: a lookup from here throws.
 */
test('submitting a video does not wait on YouTube', function (): void {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ']);

    $summary = Summary::query()->sole();

    expect($summary->title)->toBeNull()
        ->and($summary->error)->toBeNull();

    /*
     * The job carries an id and loads the row when it runs, so what it is handed cannot be
     * asserted from the payload any more - only that it is queued for this row.
     */
    Queue::assertPushed(SummariseVideo::class,
        fn (SummariseVideo $job): bool => $job->summaryId === $summary->id);
});

test('the page is told the title once the summary is there', function (): void {
    $summary = Summary::factory()->create([
        'title' => 'How to summarise a video',
        'body' => 'A short summary.',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('summaries.show', $summary))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('summary.status', SummaryStatus::Ready->value)
            ->where('summary.title', 'How to summarise a video')
            ->where('summary.body', 'A short summary.'),
        );
});

/*
 * The reason reaches the page as the code it is stored as, not as a sentence: the wording lives
 * in lang/en/summaries.php so it can be changed without a migration. See .ai/rules/i18n.md.
 */
test('the page is told why an attempt failed', function (): void {
    $summary = Summary::factory()->failed()->create(['error' => SummaryError::NotFound]);

    $this->actingAs(User::factory()->create())
        ->get(route('summaries.show', $summary))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('summary.status', SummaryStatus::Failed->value)
            ->where('summary.error', SummaryError::NotFound->value),
        );
});

/*
 * Every string the page shows comes from lang/en, so the page is useless without them. A
 * missing group renders as raw keys rather than as nothing, which is deliberate, but it is
 * still not something to ship. See .ai/rules/i18n.md.
 */
test('the page is given the words it renders', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->has('lang.summaries.errors.not_found')
            ->has('lang.summaries.stage.queued')
            ->has('lang.app.logout'),
        );
});

/*
 * A video is worth summarising whether or not anything could tell us its name, so a
 * failed lookup must not become a failed summary.
 */
test('a video with no title still has a summary', function (): void {
    $summary = Summary::factory()->create(['title' => null]);

    $this->actingAs(User::factory()->create())
        ->get(route('summaries.show', $summary))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('summary.title', null)
            ->where('summary.status', SummaryStatus::Ready->value),
        );
});

/*
 * A pending row is joined and never restarted, however old it is. Restarting its clock would
 * mislead whoever is already waiting on it, and a second job for it would be a second paid
 * summary of one video. Nothing here is the controller's to judge: while the row says pending
 * the attempt is somebody's to finish, and when it is not, summaries:expire says so and this
 * becomes a retry.
 */
test('a pending video is joined rather than restarted, however long it has been pending', function (string $age): void {
    Queue::fake();

    $askedAt = Date::now()->sub($age);

    $summary = Summary::factory()->pending()->create([
        'video_id' => 'dQw4w9WgXcQ',
        'requested_at' => $askedAt,
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('summaries.show', $summary));

    /* Not a second job for a video somebody is already summarising. */
    Queue::assertNothingPushed();

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->requested_at->timestamp)->toBe($askedAt->timestamp)
        ->and($summary->started_at)->toBeNull();
})->with([
    'asked for four minutes ago' => '4 minutes',
    /* Past the time the work itself gets, which says nothing about how long it may wait. */
    'asked for longer than the work gets' => '31 minutes',
    /* And past the horizon, which is the expiry command's business rather than this one's. */
    'asked for longer than the horizon' => '7 hours',
]);

/*
 * The whole way round, because the claim is a return-early and a stale one would make a row
 * unworkable without saying so: failed while holding a claim, asked for again, and actually
 * summarised. If the reset ever stops clearing the claim, the job below finds somebody else
 * apparently working and returns having done nothing, and this is what notices.
 *
 * Both routes to a failed row that still holds one, because they are reached differently and
 * the claim outlives each of them.
 */
test('a summary that failed holding a claim is really summarised when asked for again', function (string $route): void {
    Sleep::fake();
    Log::spy();
    fakeYouTube();

    $summary = Summary::factory()->stale()->create([
        'video_id' => 'dQw4w9WgXcQ',
        /* Claimed by a worker that then went missing, so the row is stale and holds one. */
        'started_at' => Date::now()->subMinutes(5),
    ]);

    match ($route) {
        /* The command's write-off leaves the claim where it was: it only changes status. */
        'command' => $this->artisan('summaries:expire')->assertSuccessful(),
        /* And the job failing on its own is the same shape reached from the other side. */
        'job' => (new SummariseVideo($summary->id))->failed(new RuntimeException('no transcript')),
    };

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        /* The point of the exercise: it is failed and still claimed. */
        ->and($summary->started_at)->not->toBeNull();

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('summaries.show', $summary));

    /*
     * Run by hand rather than inline. The job names its own connection, which overrides the
     * sync default phpunit.xml sets, so dispatching it under test queues it rather than
     * running it. What matters here is that handle() can claim the row it was given.
     */
    work(new SummariseVideo($summary->id));

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->body)->not->toBeEmpty()
        ->and($summary->started_at)->not->toBeNull();
})->with([
    'written off by the expiry command' => 'command',
    'failed by the job itself' => 'job',
]);

test('a video somebody is already working on is joined rather than restarted', function (): void {
    Queue::fake();

    $claimedAt = Date::now()->subMinutes(2);
    $summary = Summary::factory()->processing()->create([
        'video_id' => 'dQw4w9WgXcQ',
        'started_at' => $claimedAt,
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('summaries.show', $summary));

    /* Untouched: the work is under way and this request is simply watching it. */
    expect($summary->fresh()?->started_at?->timestamp)->toBe($claimedAt->timestamp);
});

test('a brand new submission starts its clock straight away', function (): void {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ']);

    expect(Summary::query()->sole()->requested_at->diffInSeconds(Date::now(), true))
        ->toBeLessThan(5);
});

test('a video id that is not one is refused', function (string $videoId): void {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => $videoId])
        ->assertSessionHasErrors('video_id');

    Queue::assertNothingPushed();

    expect(Summary::query()->count())->toBe(0);
})->with([
    'truncated' => 'dQw4w9WgXc',
    'overlong' => 'dQw4w9WgXcQQ',
    'a whole url' => 'https://youtu.be/dQw4w9WgXcQ',
    'punctuation' => 'dQw4w9WgX!Q',
    'a space' => 'dQw4w9WgX Q',
    'empty' => '',
]);

test('guests cannot submit a video', function (): void {
    Queue::fake();

    $this->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('login'));

    Queue::assertNothingPushed();

    expect(Summary::query()->count())->toBe(0);
});

test('a finished summary is shown at its own url', function (): void {
    $summary = Summary::factory()->create([
        'video_id' => 'dQw4w9WgXcQ',
        'body' => 'A short summary.',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('summaries.show', $summary))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('videoId', 'dQw4w9WgXcQ')
            ->where('summary.status', SummaryStatus::Ready->value)
            ->where('summary.body', 'A short summary.'),
        );
});

test('a summary still being produced says so, which is what the page polls on', function (): void {
    $summary = Summary::factory()->pending()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('summaries.show', $summary))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('summary.status', SummaryStatus::Pending->value)
            ->where('summary.body', null),
        );
});

/*
 * What the page counts up from. Somebody joining a job already running has to see the
 * time it has really taken rather than starting from zero, so this is the row's own
 * requested_at rather than anything derived from the request.
 */
test('the page is told when the summary was asked for', function (): void {
    $summary = Summary::factory()->pending()->create([
        'requested_at' => Date::now()->subMinutes(3),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('summaries.show', $summary))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('summary.requestedAt', $summary->requested_at->toIso8601String()),
        );
});

/*
 * Queued and processing are the same status on this side, so whether a worker has started is
 * the only thing that tells them apart, and the page needs it to say which.
 */
test('the page is told whether a worker has started', function (): void {
    $waiting = Summary::factory()->pending()->create();
    $working = Summary::factory()->processing()->create();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('summaries.show', $waiting))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('summary.startedAt', null),
        );

    $this->actingAs($user)
        ->get(route('summaries.show', $working))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('summary.startedAt', $working->started_at?->toIso8601String()),
        );
});

test('tripping the throttle is explained rather than dumped', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->actingAs($user)
            ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
            ->assertRedirect();
    }

    $this->actingAs($user)
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertTooManyRequests()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('error')
            ->where('status', 429),
        );
});

test('a uuid nobody has a summary for is not found', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('summaries.show', '019ff220-905c-73c9-8165-5b10a2b9dd0e'))
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('error')
            ->where('status', 404),
        );
});

/*
 * Refused by HasUniqueStringIds while resolving the binding, before any query runs, which
 * is why the route carries no uuid constraint of its own.
 */
test('a uuid that is not a uuid is not found', function (string $uuid): void {
    $this->actingAs(User::factory()->create())
        ->get(route('summaries.show', $uuid))
        ->assertNotFound();
})->with([
    'a video id' => 'dQw4w9WgXcQ',
    'a number' => '1',
    'nonsense' => 'nope',
]);

test('guests cannot read a summary', function (): void {
    $summary = Summary::factory()->create();

    $this->get(route('summaries.show', $summary))
        ->assertRedirect(route('login'));
});
