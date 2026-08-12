<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SummaryError;
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
            'transcript' => fake()->paragraphs(3, true),
            'transcript_language' => 'en',

            /*
             * English by default, which is the shape with nothing to translate: one summary
             * and a null second half. The translated() state below is the other shape.
             */
            'outline' => [
                'language' => 'en',
                'original' => $this->sections(),
                'english' => null,
            ],
            'requested_at' => Date::now(),

            /* A finished summary was necessarily claimed by the worker that finished it. */
            'started_at' => Date::now(),
        ];
    }

    /**
     * A summary of a video that was not in English, so it was translated as well.
     *
     * Both halves are filled, which is what the page lays out one above the other. The two
     * are deliberately different text: a state where they matched would let a bug that
     * renders one of them twice pass unnoticed.
     */
    public function translated(): static
    {
        return $this->state(fn (): array => [
            'transcript_language' => 'nl',
            'outline' => [
                'language' => 'nl',
                'original' => $this->sections(),
                'english' => $this->sections(),
            ],
        ]);
    }

    /**
     * One language's worth of summary, in the shape App\Services\Ai\Data\SummarySections holds.
     *
     * Ten points and five takeaways, which is what the agent is asked for, so a page laying a
     * real one out is laying out something the same size.
     *
     * @return array<string, mixed>
     */
    private function sections(): array
    {
        return [
            'headline' => rtrim(fake()->sentence(12), '.'),
            'points' => $this->lines(10),
            'takeaways' => $this->lines(5),
        ];
    }

    /**
     * A list of the terse one-liners a summary is made of.
     *
     * Built one sentence at a time rather than with sentences(), which is typed as returning
     * either a list or one joined string depending on an argument, and so cannot be mapped over
     * without the shape being asserted somewhere.
     *
     * @return array<int, string>
     */
    private function lines(int $count): array
    {
        return array_map(
            fn (): string => rtrim(fake()->sentence(8), '.'),
            range(1, $count),
        );
    }

    /**
     * A summary waiting its turn in the queue, which no worker has claimed.
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => SummaryStatus::Pending,
            'outline' => null,

            /*
             * Nor a transcript. It is fetched by the job that summarises, so a row waiting
             * its turn has not been anywhere near YouTube for one.
             */
            'transcript' => null,
            'transcript_language' => null,

            /*
             * No title either, which is the shape of a real one: the job looks the video up
             * and writes both together, so a row that has not been summarised has neither.
             */
            'title' => null,
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
     *
     * With a reason, because every route to a failed row records one; pass a different
     * SummaryError where the reason is what a test is about. Unknown is the one a job that
     * threw leaves behind, which is the most ordinary of them.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => SummaryStatus::Failed,
            'outline' => null,
            'title' => null,

            /*
             * The transcript is left as it is rather than nulled, because a failure after one
             * was fetched keeps it: that is the whole point of storing it, and a retry that
             * only re-runs the model is the case worth having a state for. Tests about a
             * failure before the transcript arrived pass transcript: null themselves.
             */
            'error' => SummaryError::Unknown,
        ]);
    }
}
