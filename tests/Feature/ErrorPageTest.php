<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('a dead link gets a page that looks like the application', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('error')
            ->where('status', 404),
        );
});

/*
 * The callback behind that page is registered through Handler::respondUsing, which runs
 * after the handler has already chosen between an HTML and a JSON body. Without a guard it
 * replaces the finished JsonResponse with an HTML document, and the shouldRenderJsonWhen()
 * rule in bootstrap/app.php quietly stops meaning anything.
 */
test('a client asking for json still gets json', function (string $path): void {
    $response = $this->actingAs(User::factory()->create())->getJson($path);

    $response->assertNotFound()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonStructure(['message']);

    expect($response->content())->not->toContain('<!DOCTYPE html>');
})->with([
    'a missing page' => '/does-not-exist',
    'a missing api route' => '/api/summaries',
]);

test('the shared props the page needs survive a url that matches no route', function (): void {
    /*
     * A 404 for an unmatched url never reaches the routes, so HandleInertiaRequests never
     * runs of its own accord. withSharedData() is what puts these back, and without it the
     * page renders with no name and no auth.
     */
    $this->actingAs(User::factory()->create())
        ->get('/does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('error')
            ->has('auth')
            ->has('name'),
        );
});

/*
 * A missing image, a stray source map, a bot sweeping for php files: none of them wanted a
 * page, and rendering one for them costs a React shell plus the database query and session
 * write that come with sharing props.
 */
test('a request that did not ask for a page gets laravel own 404', function (string $accept): void {
    $response = $this->actingAs(User::factory()->create())
        ->get('/does-not-exist', ['Accept' => $accept]);

    $response->assertNotFound();

    expect($response->content())->not->toContain('"component":"error"');
})->with([
    'an image' => 'image/avif,image/webp,*/*;q=0.8',
    'a stylesheet' => 'text/css,*/*;q=0.1',
    'a script' => 'application/javascript',
]);

test('a status the application does not explain is left to laravel', function (): void {
    /*
     * 405 is not in HANDLED_STATUSES, so the callback returns null and Laravel renders it.
     * Guards against the list quietly growing to cover everything.
     */
    $response = $this->actingAs(User::factory()->create())->put(route('home'));

    $response->assertMethodNotAllowed();

    expect($response->content())->not->toContain('"component":"error"');
});
