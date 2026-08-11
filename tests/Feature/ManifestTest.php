<?php

declare(strict_types=1);

use App\Models\User;

/*
 * A manifest that answers 302 makes the application quietly uninstallable, with nothing
 * in any log to say so. It has to be reachable signed in and signed out both, which is
 * why it sits in neither middleware group, and these two are what stop it being moved
 * into one of them.
 */
test('the manifest is reachable by a guest', function (): void {
    $this->get(route('manifest'))
        ->assertOk()
        ->assertHeader('content-type', 'application/manifest+json');
});

test('the manifest is reachable by somebody signed in', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('manifest'))
        ->assertOk()
        ->assertHeader('content-type', 'application/manifest+json');
});

/*
 * name or short_name, icons at 192 and 512, start_url and display are what Chromium
 * documents as required to install. Everything else in the manifest only improves the
 * install sheet, so this asserts the floor rather than the whole file.
 */
test('the manifest carries what a browser needs to install it', function (): void {
    $manifest = $this->get(route('manifest'))->json();

    expect($manifest['name'])->toBe(config('app.name'))
        ->and($manifest['short_name'])->toBe(config('app.name'))
        ->and($manifest['start_url'])->toBe('/')
        ->and($manifest['display'])->toBe('standalone')
        /* Present and true is the one value that turns installability off. */
        ->and($manifest)->not->toHaveKey('prefer_related_applications');

    $sizes = array_column($manifest['icons'], 'sizes');

    expect($sizes)->toContain('192x192')
        ->and($sizes)->toContain('512x512')
        ->and(array_column($manifest['icons'], 'purpose'))->toContain('maskable');
});

/*
 * Renaming an icon should break a test rather than an install: nothing else in the
 * application references these three files, so nothing else would notice.
 */
test('every icon the manifest promises exists', function (): void {
    $icons = $this->get(route('manifest'))->json('icons');

    expect($icons)->not->toBeEmpty();

    foreach ($icons as $icon) {
        expect(public_path(ltrim((string) $icon['src'], '/')))->toBeFile();
    }
});

test('the page tells the browser where the manifest is', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('rel="manifest"', escape: false)
        ->assertSee(route('manifest'), escape: false);
});

/*
 * The worker is what Chrome still wants before it will offer to install, and the page it
 * falls back to is no use if it is not there to be cached.
 */
test('the service worker and its offline page are served from the root', function (string $path): void {
    expect(public_path($path))->toBeFile();
})->with(['sw.js', 'offline.html']);
