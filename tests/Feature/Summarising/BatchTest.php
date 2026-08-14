<?php

declare(strict_types=1);

use App\Actions\SummariseVideo;
use App\Actions\Summarising\ComposeSummary;
use App\Actions\Summarising\DraftIdeas;
use App\Actions\Summarising\FetchCaptions;
use App\Actions\Summarising\FindVideo;
use App\Actions\Summarising\TranslateOutline;
use App\Enums\Queue;
use App\Jobs\ActionJob;
use App\Models\Summary;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;

/*
 * The shape of what gets queued, which is the whole of what this change is for.
 *
 * A batch rather than a bare chain because only a batch has a name and a count, and those two
 * are the progress: without them the Horizon dashboard can say a video is being summarised and
 * nothing else. A chain rather than five loose jobs because every step reads what the one before
 * it wrote.
 */

test('one video is one named batch holding one chain of five', function (): void {
    Bus::fake();

    $summary = Summary::factory()->pending()->create(['video_id' => 'dQw4w9WgXcQ']);

    app(SummariseVideo::class)->execute($summary->id);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        /*
         * One entry, and that entry an array. Laravel reads a nested array inside a batch as a
         * chain - Batch::add() splits it with prepareBatchedChain() and counts every job in it
         * towards totalJobs - so this is what "runs in order, counts to five" is made of. Five
         * entries at the top level instead would run all five at once, against a row four of
         * them have nothing to read yet.
         */
        expect($batch->jobs)->toHaveCount(1)
            ->and($batch->jobs->first())->toBeArray()->toHaveCount(5);

        return true;
    });
});

test('the chain is the five steps, in the order each one can read the last', function (): void {
    Bus::fake();

    $summary = Summary::factory()->pending()->create();

    app(SummariseVideo::class)->execute($summary->id);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        $chain = $batch->jobs->first();

        expect(array_map(fn (ActionJob $job): string => $job->displayName(), $chain))->toBe([
            FindVideo::class,
            FetchCaptions::class,
            DraftIdeas::class,
            ComposeSummary::class,
            TranslateOutline::class,
        ]);

        return true;
    });
});

/*
 * The name is what makes a batch findable by somebody looking into a particular video, so it
 * carries both the code they would have pasted and the id a log line would quote.
 */
test('the batch is named for the video and the row', function (): void {
    Bus::fake();

    $summary = Summary::factory()->pending()->create(['video_id' => 'dQw4w9WgXcQ']);

    app(SummariseVideo::class)->execute($summary->id);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->name === "Summarise dQw4w9WgXcQ ({$summary->id})");
});

/*
 * Named on the batch rather than left to the steps, and that is not belt and braces: the batch's
 * options are what a chain's continuations inherit, so without this step two lands on the default
 * queue, which supervisor-summaries does not work and nothing else works either.
 */
test('the batch names the connection and queue its steps are worked on', function (): void {
    Bus::fake();

    $summary = Summary::factory()->pending()->create();

    app(SummariseVideo::class)->execute($summary->id);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->connection() === 'summaries'
        && $batch->queue() === Queue::Summaries->value);
});

/*
 * Every step is handed the claim as well as the row, and both are needed: the id says which row,
 * the claim says which attempt on it. A step given only the id could not tell an attempt that was
 * written off and replaced from the one it belongs to.
 */
test('every step is handed the row and the claim that was just taken', function (): void {
    Bus::fake();

    $summary = Summary::factory()->pending()->create();

    app(SummariseVideo::class)->execute($summary->id);

    $claimedAt = $summary->fresh()?->started_at;

    expect($claimedAt)->not->toBeNull();

    Bus::assertBatched(function (PendingBatch $batch) use ($summary, $claimedAt): bool {
        foreach ($batch->jobs->first() as $job) {
            [$summaryId, $stepClaimedAt] = $job->parameters();

            expect($summaryId)->toBe($summary->id)
                ->and($stepClaimedAt->timestamp)->toBe($claimedAt->timestamp);
        }

        return true;
    });
});

/*
 * The claim has to survive the queue, because the steps are compared against a timestamp(0)
 * column on the other side of a serialisation. It matches at all because the query grammar
 * formats to the second on both the write and the comparison; a step that rebuilt it, or a
 * column with sub-second precision, would silently stop matching and every step would decide the
 * attempt had been replaced.
 */
test('the claim still matches the row after a real round trip', function (): void {
    $summary = Summary::factory()->pending()->create();
    $claimedAt = claimSummary($summary->id);

    $restored = unserialize(serialize(new ActionJob(app(FindVideo::class), [$summary->id, $claimedAt])));

    [, $restoredClaimedAt] = $restored->parameters();

    expect(
        Summary::query()->whereKey($summary->id)->where('started_at', $restoredClaimedAt)->exists()
    )->toBeTrue();
});
