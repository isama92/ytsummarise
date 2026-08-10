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

/*
 * The layout paints a background before app.css is fetched, so the two files each
 * carry their own copy of the Catppuccin `base` colours. Nothing but this test stops
 * them drifting, and the symptom of drift is a colour flash on every cold load.
 */
test('the first paint colours in the layout match the theme stylesheet', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));
    $blade = (string) file_get_contents(resource_path('views/app.blade.php'));

    preg_match('/:root\s*\{[^}]*--ctp-base:\s*(#[0-9a-f]{6})/i', $css, $latte);
    preg_match('/\.dark\s*\{[^}]*--ctp-base:\s*(#[0-9a-f]{6})/i', $css, $frappe);

    expect($latte)->toHaveKey(1)
        ->and($frappe)->toHaveKey(1)
        ->and($blade)->toContain("background-color: {$latte[1]};")
        ->toContain("background-color: {$frappe[1]};");
});
