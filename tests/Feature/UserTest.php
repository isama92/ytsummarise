<?php

declare(strict_types=1);

use App\Models\User;

/*
 * Since Laravel 13.39 SessionGuard::login() asks every user for a password hash, and
 * the sign-in tests in Auth/ cover that path. On an older framework nothing calls
 * getAuthPassword() during sign in, so this is the test that notices the override
 * going missing before the next framework update does. fresh() matters: only a user
 * read back from the database lacks the password attribute.
 */
test('a stored user reports that it has no password', function (): void {
    $user = User::factory()->create()->fresh();

    expect($user->getAuthPassword())->toBeEmpty();
});
