<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /*
         * With AUTH_ENABLED left on, the email has to match an account in Authentik for
         * this user to be reachable: sign in links on email, so seeding one Authentik
         * does not know about creates a row nobody can ever log in as.
         *
         * With AUTH_ENABLED off this is simply who you are, and seeding it means the
         * first-user setup form only appears after a migrate:fresh without --seed.
         */
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
