<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    config(['auth.enabled' => false]);
});

/*
 * The shared prop assertion is what pins the middleware order. assertAuthenticatedAs
 * reads the guard after the response, so it passes either way; only the page's own
 * copy of the user proves AuthenticateAsFirstUser ran before HandleInertiaRequests
 * shared it. Drop the prependToPriorityList call in bootstrap/app.php and this is the
 * assertion that fails.
 */
test('a visitor is signed in as the only user', function (): void {
    $user = User::factory()->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('auth.user.name', $user->name),
        );

    $this->assertAuthenticatedAs($user);
});

test('the first user created wins when several exist', function (): void {
    $first = User::factory()->create();
    User::factory()->count(2)->create();

    $this->get(route('home'))->assertOk();

    $this->assertAuthenticatedAs($first);
});

test('an already authenticated visitor is left alone', function (): void {
    User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('home'))->assertOk();

    $this->assertAuthenticatedAs($other);
});

test('a visitor is sent to the setup form when there are no users', function (): void {
    $this->get(route('home'))->assertRedirect(route('first-user.create'));

    $this->assertGuest();
});

test('the setup form is rendered', function (): void {
    $this->get(route('first-user.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('auth/first-user'),
        );
});

test('submitting the setup form creates the user and signs them in', function (): void {
    $response = $this->post(route('first-user.store'), [
        'name' => 'Aaa Guy',
        'email' => 'an@email.com',
    ]);

    $response->assertRedirect(route('home'));

    $this->assertDatabaseHas('users', [
        'name' => 'Aaa Guy',
        'email' => 'an@email.com',
    ]);

    $this->assertAuthenticatedAs(User::firstWhere('email', 'an@email.com'));
});

test('submitting the setup form regenerates the session id', function (): void {
    $this->startSession();

    $sessionIdBefore = session()->getId();

    $this->post(route('first-user.store'), [
        'name' => 'Aaa Guy',
        'email' => 'an@email.com',
    ])->assertRedirect(route('home'));

    expect(session()->getId())->not->toBe($sessionIdBefore);
});

test('the setup form rejects a missing name and a malformed email', function (): void {
    $this->from(route('first-user.create'))
        ->post(route('first-user.store'), [
            'name' => '',
            'email' => 'not-an-email',
        ])
        ->assertRedirect(route('first-user.create'))
        ->assertSessionHasErrors(['name', 'email']);

    expect(User::count())->toBe(0);
});

/*
 * Tighter than the rest of the guest group because this route creates an account.
 * Driven with an invalid payload so the limiter is what stops it, not the controller's
 * refusal to make a second user.
 */
test('the setup form submission is rate limited', function (): void {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->post(route('first-user.store'), [])->assertRedirect();
    }

    $this->post(route('first-user.store'), [])->assertTooManyRequests();

    expect(User::count())->toBe(0);
});

test('the setup form is gone once a user exists', function (): void {
    User::factory()->create();

    $this->get(route('first-user.create'))->assertNotFound();
});

test('the setup form cannot be submitted once a user exists', function (): void {
    User::factory()->create();

    $this->post(route('first-user.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
    ])->assertNotFound();

    expect(User::count())->toBe(1);
});

test('the home page reports that authentication is off', function (): void {
    User::factory()->create();

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('home')
            ->where('auth.enabled', false),
        );
});

test('the setup form does not exist while authentication is enabled', function (): void {
    config(['auth.enabled' => true]);

    $this->get(route('first-user.create'))->assertNotFound();

    $this->post(route('first-user.store'), [
        'name' => 'Aaa Guy',
        'email' => 'an@email.com',
    ])->assertNotFound();

    expect(User::count())->toBe(0);
});
