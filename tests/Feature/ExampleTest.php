<?php

use Inertia\Testing\AssertableInertia;

test('the homepage renders the welcome page for guests', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('welcome')
        ->where('auth.user', null)
    );
});
