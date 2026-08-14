<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Saloon\Config;
use Tests\TestCase;

pest()->printer()->compact();

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    /*
     * Nothing in the suite is allowed to reach the network. Summarising a video looks a
     * video up over http, so without this a test that forgets to fake it passes on a
     * developer's machine, hits YouTube from CI, and fails whenever YouTube feels like it.
     * A stray request throws instead, and says which url it was.
     *
     * Both clients, because they are genuinely separate: Saloon does not go through
     * Illuminate's http client at all - it has its own sender - so Http::fake() and
     * Http::preventStrayRequests() see nothing a connector sends, and Saloon's own guard sees
     * nothing Http:: sends. The lookup uses Saloon; anything else added later may not.
     */
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        Config::preventStrayRequests();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));
