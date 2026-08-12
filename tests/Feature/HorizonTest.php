<?php

declare(strict_types=1);

use App\Enums\Queue;
use App\Models\User;

/*
 * Horizon shows every job the application has ever run, lets anyone looking at it retry or
 * delete them, and puts the queue's whole internals on one page. It ships wide open - the
 * stock gate compares an email against an empty list, which denies everybody, and the moment
 * somebody edits that to something plausible it stops denying anybody by accident.
 *
 * So the three states are asserted rather than assumed. All three go through the real route,
 * because the gate is only half of it: Horizon's own Authenticate middleware is what consults
 * it, and a middleware that stopped being applied would leave a passing gate test in front of
 * an open dashboard.
 */
test('the first user can open the dashboard', function (): void {
    $user = User::factory()->create();

    expect($user->id)->toBe(1);

    $this->actingAs($user)
        ->get(route('horizon.index'))
        ->assertOk();
});

test('a second user cannot', function (): void {
    User::factory()->create();
    $second = User::factory()->create();

    expect($second->id)->toBe(2);

    $this->actingAs($second)
        ->get(route('horizon.index'))
        ->assertForbidden();
});

/*
 * 403 rather than a redirect to the sign in page, because Horizon guards its own routes and
 * does not use Laravel's `auth` middleware. Worth pinning: it is the difference between a
 * stranger being told to sign in and being told no.
 */
test('a guest cannot', function (): void {
    $this->get(route('horizon.index'))
        ->assertForbidden();
});

/*
 * The api routes are the ones that would actually hand over data, and they are a separate
 * route registration from the dashboard the three tests above cover.
 */
test('the api is behind the same gate', function (): void {
    User::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('horizon.stats.index'))
        ->assertForbidden();
});

/*
 * A summary costs a model call per attempt and can run for an hour. Two workers on that queue
 * is two attempts at the same backlog competing for one machine, and the reason the queue has
 * its own supervisor at all is so that cannot happen - so the number is asserted rather than
 * left to whoever next edits the file.
 */
test('summarising is worked one job at a time', function (): void {
    $supervisor = config()->array('horizon.defaults.supervisor-summaries');

    expect($supervisor['maxProcesses'])->toBe(1)
        ->and($supervisor['minProcesses'])->toBe(1)
        ->and($supervisor['queue'])->toBe([Queue::Summaries->value])
        ->and($supervisor['connection'])->toBe('summaries')
        /* auto would scale it back up, which is the whole thing being prevented. */
        ->and($supervisor['balance'])->toBeFalse()
        ->and($supervisor['tries'])->toBe(1);

    foreach (config()->array('horizon.environments') as $environment) {
        expect($environment['supervisor-summaries']['maxProcesses'])->toBe(1);
    }
});

/*
 * `high` only means anything because balance is false: with `auto` Horizon ignores the order
 * of this array entirely and allocates by load, so the names would still read as priorities
 * and stop behaving as any. Both halves are asserted because either one alone is meaningless.
 */
test('the general purpose queues are worked in priority order', function (): void {
    $supervisor = config()->array('horizon.defaults.supervisor-default');

    expect($supervisor['queue'])->toBe([
        Queue::High->value,
        Queue::Default->value,
        Queue::Low->value,
    ])
        ->and($supervisor['balance'])->toBeFalse()
        ->and($supervisor['connection'])->toBe('redis');
});

/*
 * A job dispatched with no ->onQueue() goes onto the connection's default queue, and if that
 * name is not one a supervisor works the job does not fail - it queues perfectly well and is
 * never run again. Nothing dispatches there yet, which is exactly when this is worth pinning.
 */
test('a job with no queue lands somewhere that is worked', function (): void {
    expect(config()->string('queue.connections.redis.queue'))
        ->toBe(Queue::Default->value)
        ->toBeIn(config()->array('horizon.defaults.supervisor-default.queue'));
});
