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

            /* A finished summary was necessarily claimed by the worker that finished it. */
            'started_at' => Date::now(),
        ];
    }

    /**
     * A summary waiting its turn in the queue, which no worker has claimed.
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => SummaryStatus::Pending,
            'body' => null,
            'started_at' => null,
        ]);
    }

    /**
     * A summary a worker claimed and is still working on.
     */
    public function processing(): static
    {
        return $this->pending()->state(fn (): array => [
            'started_at' => Date::now(),
        ]);
    }

    /**
     * A summary whose attempt has been pending long enough to give up on.
     *
     * The age is on requested_at and started_at is left null, which is the ordinary shape of
     * one: a job that stopped existing before any worker reached it. A row a worker did claim
     * and then abandoned is the same set as far as the expiry command is concerned, so pass
     * a started_at when a test needs one rather than making a second state for it.
     */
    public function stale(): static
    {
        return $this->pending()->state(fn (): array => [
            'requested_at' => Date::now()->subSeconds(config()->integer('summaries.stale_after') + 1),
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
