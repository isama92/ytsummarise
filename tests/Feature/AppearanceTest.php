<?php

declare(strict_types=1);

test('the dark theme is painted server side when the appearance cookie says so', function (): void {
    $this->withUnencryptedCookie('appearance', 'dark')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('class="dark"', escape: false);
});

test('the light theme is painted server side when the appearance cookie says so', function (): void {
    $this->withUnencryptedCookie('appearance', 'light')
        ->get(route('login'))
        ->assertOk()
        ->assertDontSee('class="dark"', escape: false);
});

test('no appearance cookie leaves the choice to the browser', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('class="dark"', escape: false)
        ->assertSee('prefers-color-scheme: dark', escape: false);
});
