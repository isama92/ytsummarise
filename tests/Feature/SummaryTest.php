<?php

declare(strict_types=1);

use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
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

    $summary = Summary::factory()->failed()->create(['video_id' => 'dQw4w9WgXcQ']);

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('summaries.show', $summary));

    Queue::assertPushed(SummariseVideo::class);

    expect(Summary::query()->count())->toBe(1)
        ->and($summary->fresh()?->status)->toBe(SummaryStatus::Pending);
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
