<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as ProviderUser;

test('guests visiting the home page are redirected to the login page', function (): void {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('the login page shows the configured provider name', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('auth/login')
            ->where('providerName', 'Authentik'),
        );
});

test('authenticated users visiting the login page are redirected home', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('home'));
});

test('the redirect route sends the user to the configured provider', function (): void {
    $response = $this->get(route('auth.redirect'));

    $response->assertRedirectContains('https://authentik.test/application/o/authorize/');
    $response->assertRedirectContains('client_id=test-client-id');
});

test('the callback creates and authenticates a user the application has not seen', function (): void {
    Socialite::fake('authentik', ProviderUser::fake([
        'name' => 'Aaa Guy',
        'email' => 'an@email.com',
    ]));

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    $this->assertDatabaseHas('users', [
        'name' => 'Aaa Guy',
        'email' => 'an@email.com',
    ]);

    $this->assertAuthenticatedAs(User::firstWhere('email', 'an@email.com'));
});

test('the callback matches an existing user on email and refreshes their name', function (): void {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'an@email.com',
    ]);

    Socialite::fake('authentik', ProviderUser::fake([
        'name' => 'New Name',
        'email' => 'an@email.com',
    ]));

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    expect(User::count())->toBe(1)
        ->and($user->refresh()->name)->toBe('New Name');

    $this->assertAuthenticatedAs($user);
});

/*
 * An Authentik account with no first or last name set sends an empty string rather
 * than omitting the claim, so both shapes of "no name" have to fall through.
 */
test('the callback falls back to the nickname when the provider sends no name', function (mixed $name): void {
    Socialite::fake('authentik', ProviderUser::fake([
        'name' => $name,
        'nickname' => 'aguy',
        'email' => 'an@email.com',
    ]));

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    $this->assertDatabaseHas('users', [
        'name' => 'aguy',
        'email' => 'an@email.com',
    ]);
})->with([
    'null name' => null,
    'empty name' => '',
]);

test('the callback falls back to the email local part when the provider sends neither', function (mixed $name, mixed $nickname): void {
    Socialite::fake('authentik', ProviderUser::fake([
        'name' => $name,
        'nickname' => $nickname,
        'email' => 'an@email.com',
    ]));

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    $this->assertDatabaseHas('users', [
        'name' => 'an',
        'email' => 'an@email.com',
    ]);
})->with([
    'both null' => [null, null],
    'both empty' => ['', ''],
]);

/*
 * The email is the identity and the unique index is case sensitive, so the same
 * person arriving with different capitalisation must not become a second account.
 */
test('the callback lowercases the email before matching', function (): void {
    $user = User::factory()->create(['email' => 'an@email.com']);

    Socialite::fake('authentik', ProviderUser::fake([
        'name' => 'Aaa Guy',
        'email' => 'an@email.com',
    ]));

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    expect(User::count())->toBe(1)
        ->and($user->refresh()->email)->toBe('an@email.com');

    $this->assertAuthenticatedAs($user);
});

test('the callback regenerates the session id', function (): void {
    Socialite::fake('authentik', ProviderUser::fake([
        'email' => 'an@email.com',
    ]));

    $this->startSession();

    $sessionIdBefore = session()->getId();

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    expect(session()->getId())->not->toBe($sessionIdBefore);
});

/*
 * Primed through the session rather than by visiting a guarded page, because the
 * only guarded page is / - which is also the fallback, so priming with it would
 * pass just as well with redirect()->intended() replaced by a plain redirect.
 */
test('the callback honours the url the user was originally heading for', function (): void {
    Socialite::fake('authentik', ProviderUser::fake([
        'email' => 'an@email.com',
    ]));

    $this->withSession(['url.intended' => url('/somewhere-else')])
        ->get(route('auth.callback'))
        ->assertRedirect(url('/somewhere-else'));
});

test('the callback sends the user back to login when the provider rejects the attempt', function (): void {
    Log::spy();

    Socialite::fake('authentik', function (): never {
        throw new InvalidStateException;
    });

    $this->get(route('auth.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('oidc');

    $this->assertGuest();
    expect(User::count())->toBe(0);

    Log::shouldHaveReceived('warning')->once();
});

test('the callback sends the user back to login when the provider returns no email', function (): void {
    Log::spy();

    Socialite::fake('authentik', ProviderUser::fake([
        'name' => 'Aaa Guy',
        'email' => null,
    ]));

    $this->get(route('auth.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('oidc');

    $this->assertGuest();
    expect(User::count())->toBe(0);

    Log::shouldHaveReceived('warning')->once();
});

test('users can log out', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('guests cannot reach the logout route', function (): void {
    $this->post(route('logout'))->assertRedirect(route('login'));
});

/*
 * Guards the throttle on the route that writes a session row per request. Everything
 * else in the guest group shares the same limiter definition.
 */
test('the redirect route is rate limited', function (): void {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->get(route('auth.redirect'))->assertRedirect();
    }

    $this->get(route('auth.redirect'))->assertTooManyRequests();
});
