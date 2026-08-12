<?php

declare(strict_types=1);

use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Facades\Date;
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
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('summaries.show', $summary));

    Queue::assertPushed(SummariseVideo::class);

    $summary->refresh();

    expect(Summary::query()->count())->toBe(1)
        ->and($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->body)->toBeNull()
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
 * Before the job rather than inside it, because the point of having a title is to show it
 * while the summary is still being produced.
 */
test('the video is titled before the job is queued', function (): void {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ']);

    expect(Summary::query()->sole()->title)->not->toBeNull();

    Queue::assertPushed(SummariseVideo::class,
        /* The job is handed a row that already knows what the video is called. */
        fn (SummariseVideo $job): bool => $job->summary->title !== null);
});

test('a title already known is not looked up again', function (): void {
    Queue::fake();

    $summary = Summary::factory()->failed()->create([
        'video_id' => 'dQw4w9WgXcQ',
        'title' => 'The title we already had',
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ']);

    expect($summary->fresh()?->title)->toBe('The title we already had');
});

test('the page is told the title, and keeps it while the summary is still coming', function (): void {
    $summary = Summary::factory()->pending()->create([
        'title' => 'How to summarise a video',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('summaries.show', $summary))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('summary.status', SummaryStatus::Pending->value)
            ->where('summary.title', 'How to summarise a video')
            ->where('summary.body', null),
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
 * The other half of joining a job already running: once the row has been pending longer
 * than a video is given, the job it was waiting on is gone, so this really is a new
 * attempt and its clock has to start again. Leaving the old requested_at in place had the
 * expiry command write the new attempt off within the minute.
 */
test('resubmitting a video whose worker went missing starts a new attempt', function (): void {
    Queue::fake();

    $summary = Summary::factory()->stalled()->create([
        'video_id' => 'dQw4w9WgXcQ',
        'requested_at' => Date::now()->subHour(),
    ]);

    $abandonedAt = $summary->requested_at;

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('summaries.show', $summary));

    Queue::assertPushed(SummariseVideo::class);

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Pending)
        ->and($summary->requested_at->greaterThan($abandonedAt))->toBeTrue()
        /*
         * The claim is released with the clock. Leaving started_at set would make the row
         * unclaimable, and every job queued for it from then on would find somebody else
         * apparently working on it and return having done nothing at all.
         */
        ->and($summary->started_at)->toBeNull()
        /* And it is no longer a candidate for the command that would have killed it. */
        ->and(Summary::query()->stalled()->count())->toBe(0);
});

/*
 * The whole way round, because the claim is a return-early and a stale one would make a row
 * unworkable without saying so: abandoned by its worker, written off by the command, asked
 * for again, and actually summarised. If the reset ever stops clearing the claim, the job
 * below finds somebody else apparently working and returns having done nothing, and this is
 * what notices.
 */
test('a summary written off and asked for again is really summarised', function (): void {
    Sleep::fake();

    $summary = Summary::factory()->stalled()->create(['video_id' => 'dQw4w9WgXcQ']);

    $this->artisan('summaries:recover')->assertSuccessful();

    expect($summary->fresh()?->status)->toBe(SummaryStatus::Failed);

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('summaries.show', $summary));

    /*
     * Run by hand rather than inline. The job names its own connection, which overrides the
     * sync default phpunit.xml sets, so dispatching it under test queues it rather than
     * running it. What matters here is that handle() can claim the row it was given.
     */
    (new SummariseVideo($summary->fresh()))->handle();

    $summary->refresh();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->body)->not->toBeEmpty()
        ->and($summary->started_at)->not->toBeNull();
});

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
