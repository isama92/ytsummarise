<?php

declare(strict_types=1);

use App\Enums\SummaryStatus;
use App\Jobs\SummariseVideo;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('submitting a video queues the work and hands the browser its url', function (): void {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('home', ['v' => 'dQw4w9WgXcQ']));

    $summary = Summary::query()->sole();

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
        ->assertRedirect(route('home', ['v' => 'dQw4w9WgXcQ']));

    Queue::assertNothingPushed();

    expect(Summary::query()->count())->toBe(1)
        ->and($summary->fresh()?->status)->toBe(SummaryStatus::Ready);
});

test('submitting a video whose summary failed is how you retry it', function (): void {
    Queue::fake();

    $summary = Summary::factory()->failed()->create(['video_id' => 'dQw4w9WgXcQ']);

    $this->actingAs(User::factory()->create())
        ->post(route('summaries.store'), ['video_id' => 'dQw4w9WgXcQ'])
        ->assertRedirect(route('home', ['v' => 'dQw4w9WgXcQ']));

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
