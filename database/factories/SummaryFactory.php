<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SummaryStatus;
use App\Models\Summary;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * @extends Factory<Summary>
 */
class SummaryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Str::random gives eleven alphanumeric characters, which is a subset of what a real
     * YouTube id can contain, so a generated id always satisfies the validation rules.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_id' => Str::random(11),
            'status' => SummaryStatus::Ready,
            /* Titles do not end in a full stop, and sentence() is the typed generator. */
            'title' => rtrim(fake()->sentence(5), '.'),
            'body' => fake()->paragraphs(3, true),
            'requested_at' => Date::now(),
        ];
    }

    /**
     * A summary whose job stopped existing without failing.
     */
    public function stalled(): static
    {
        return $this->pending()->state(fn (): array => [
            'requested_at' => Date::now()->subSeconds(config()->integer('summaries.timeout') + 1),
        ]);
    }

    /**
     * A summary whose job has not run yet.
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => SummaryStatus::Pending,
            'body' => null,
        ]);
    }

    /**
     * A summary whose job gave up.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => SummaryStatus::Failed,
            'body' => null,
        ]);
    }
}
