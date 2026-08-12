<?php

declare(strict_types=1);

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

/*
 * Summaries used to live at /?v=VIDEO_ID and now have a route of their own, so the home
 * page takes no parameters at all. Anything left in the query string is ignored rather
 * than honoured, which is what stops the old guessable url quietly still working.
 */
test('the home page ignores a video id in the query string', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home', ['v' => 'dQw4w9WgXcQ']))
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
