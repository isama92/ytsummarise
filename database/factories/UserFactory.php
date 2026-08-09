<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     *
     * A deliberate no-op, and the only honest implementation right now: the users table
     * has no two_factor_* columns and config/fortify.php does not enable the feature, so
     * there is no state to set. It exists because tests/Feature/Auth/AuthenticationTest
     * calls it, and those tests skip themselves while the feature is off.
     *
     * Shipped with an empty body, which typed `static` but returned null - calling it
     * would have fatalled on the ->create() that follows. Give this a real state when
     * two-factor authentication is turned on.
     */
    public function withTwoFactor(): static
    {
        return $this;
    }
}
