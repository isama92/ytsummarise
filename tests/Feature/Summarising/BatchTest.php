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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

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
 * the token says which attempt on it. A step given only the id could not tell an attempt that was
 * written off and replaced from the one it belongs to.
 */
test('every step is handed the row and the token that was just claimed', function (): void {
    Bus::fake();

    $summary = Summary::factory()->pending()->create();

    app(SummariseVideo::class)->execute($summary->id);

    $summary->refresh();

    expect($summary->claim)->not->toBeNull()
        /* And the clock started with it, which is what the page counts up from. */
        ->and($summary->started_at)->not->toBeNull();

    Bus::assertBatched(function (PendingBatch $batch) use ($summary): bool {
        foreach ($batch->jobs->first() as $job) {
            expect($job->parameters())->toBe([$summary->id, $summary->claim, $summary->video_id]);
        }

        return true;
    });
});

/*
 * The claim has to survive the queue, because every step compares it against the row on the other
 * side of a serialisation.
 *
 * It is a token rather than the moment work began precisely so the comparison cannot go soft.
 * started_at is a timestamp(0), and the query grammar binds dates to the second on both the write
 * and the comparison, so two attempts inside one second were indistinguishable and the older
 * one's steps could write over the newer one's work. A ULID has no such resolution to lose.
 */
test('the claim still matches the row after a real round trip', function (): void {
    $summary = Summary::factory()->pending()->create();
    $claim = claimSummary($summary->id);

    $restored = unserialize(serialize(new ActionJob(app(FindVideo::class), [$summary->id, $claim, $summary->video_id])));

    [, $restoredClaim] = $restored->parameters();

    expect($restoredClaim)->toBe($claim)
        ->and(Summary::query()->whereKey($summary->id)->where('claim', $restoredClaim)->exists())->toBeTrue();
});

/*
 * And two attempts on one row never share a token, which a second-resolution timestamp could not
 * promise. This is the failure it replaced.
 */
test('two attempts on one row never share a claim', function (): void {
    $summary = Summary::factory()->pending()->create();

    $first = claimSummary($summary->id);

    $summary->update(['started_at' => null, 'claim' => null]);

    expect(claimSummary($summary->id))->not->toBe($first);
});

/*
 * Tagging happens in App\Jobs\ActionJob's constructor, so all five steps are tagged while the
 * chain is being assembled - one after another, in the web request that submitted the video.
 *
 * Looked up rather than handed over, that was five identical selects of a column that cannot
 * change: video_id carries a unique index and nothing writes it after the row is created. The
 * entry action holds the row already, so it passes the code down and the steps ask for nothing.
 */
test('building the chain does not read the video code at all', function (): void {
    Bus::fake();

    $summary = Summary::factory()->pending()->create();

    $reads = 0;

    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        if (str_contains($query->sql, 'select "video_id"')) {
            $reads++;
        }
    });

    app(SummariseVideo::class)->execute($summary->id);

    expect($reads)->toBe(0);
});

/*
 * And the code still reaches the tag, which is the thing those reads were for. One search for a
 * video finds the whole attempt: the entry job and all five of its steps.
 */
test('every step is tagged with the video code without asking for it', function (): void {
    Bus::fake();

    $summary = Summary::factory()->pending()->create(['video_id' => 'dQw4w9WgXcQ']);

    app(SummariseVideo::class)->execute($summary->id);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        foreach ($batch->jobs->first() as $job) {
            expect($job->tags())->toContain('video:dQw4w9WgXcQ');
        }

        return true;
    });
});
