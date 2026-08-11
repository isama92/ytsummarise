<?php

declare(strict_types=1);

use App\Enums\SummaryStatus;
use App\Models\Summary;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('the home page opens with nothing to show', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('videoId', null)
            ->where('summary', null),
        );
});

test('the home page names the signed in user', function (): void {
    $user = User::factory()->create(['name' => 'Stefano Borzoni']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('auth.user.name', 'Stefano Borzoni')
            ->where('auth.user.email', $user->email),
        );
});

test('the home page reports that authentication is on', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('auth.enabled', true),
        );
});

test('the user payload shared with the page hides the remember token', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->missing('auth.user.remember_token'),
        );
});

test('a video in the query string arrives with its finished summary', function (): void {
    Summary::factory()->create([
        'video_id' => 'dQw4w9WgXcQ',
        'body' => 'A short summary.',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('home', ['v' => 'dQw4w9WgXcQ']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('videoId', 'dQw4w9WgXcQ')
            ->where('summary.status', SummaryStatus::Ready->value)
            ->where('summary.body', 'A short summary.'),
        );
});

test('a summary still being produced says so, which is what the page polls on', function (): void {
    Summary::factory()->pending()->create(['video_id' => 'dQw4w9WgXcQ']);

    $this->actingAs(User::factory()->create())
        ->get(route('home', ['v' => 'dQw4w9WgXcQ']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('summary.status', SummaryStatus::Pending->value)
            ->where('summary.body', null),
        );
});

test('a video nobody has summarised still fills the field', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home', ['v' => 'dQw4w9WgXcQ']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('videoId', 'dQw4w9WgXcQ')
            ->where('summary', null),
        );
});

test('a video id that is not one is treated as no video id at all', function (string $videoId): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home', ['v' => $videoId]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('videoId', null)
            ->where('summary', null),
        );
})->with([
    'truncated' => 'dQw4w9WgXc',
    'overlong' => 'dQw4w9WgXcQQ',
    'a whole url' => 'https://youtu.be/dQw4w9WgXcQ',
    'punctuation' => 'dQw4w9WgX!Q',
    'empty' => '',
]);
