<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('the home page greets the authenticated user by name', function (): void {
    $user = User::factory()->create(['name' => 'Stefano Borzoni']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('welcome')
            ->where('auth.user.name', 'Stefano Borzoni')
            ->where('auth.user.email', $user->email),
        );
});

test('the home page reports that authentication is on', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('welcome')
            ->where('auth.enabled', true),
        );
});

test('the user payload shared with the page hides the remember token', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('welcome')
            ->missing('auth.user.remember_token'),
        );
});
