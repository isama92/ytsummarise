<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SummaryStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SummaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Override;

/**
 * One row per video, not one per request. See the unique index on video_id.
 *
 * Addressed publicly by uuid rather than by video id; the migration says why.
 *
 * @property int $id
 * @property string $uuid
 * @property string $video_id
 * @property SummaryStatus $status
 * @property string|null $title
 * @property string|null $body
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $requeued_at
 */
#[Fillable(['video_id', 'status', 'title', 'body', 'requested_at', 'started_at', 'requeued_at'])]
#[RouteKey('uuid')]
class Summary extends Model
{
    /** @use HasFactory<SummaryFactory> */
    use HasFactory, HasUuids;

    /**
     * The shape of a YouTube video id.
     *
     * Lives here so SummaryRequest validates against one pattern rather than a second
     * copy that drifts from it. The frontend has its own in resources/js/lib/youtube.ts,
     * which cannot share this one; that decides what to send, this decides what to
     * accept.
     */
    public const string VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /**
     * Generate the uuid into its own column and leave the primary key alone.
     *
     * HasUuids fills the primary key by default. Naming a different column here is what
     * keeps `id` an auto-incrementing integer: getIncrementing() and getKeyType() both
     * ask whether the *key name* appears in this list, and it does not.
     *
     * Deliberately absent from the Fillable attribute above. setUniqueIds() assigns the
     * attribute directly rather than through fill(), so nothing is lost by leaving it
     * out, and making it fillable would let a request choose its own url.
     *
     * @return array<int, string>
     */
    #[Override]
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => SummaryStatus::class,
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'requeued_at' => 'immutable_datetime',
        ];
    }

    /**
     * The moment before which a worker that started has been at it too long.
     *
     * Compared against started_at and never against requested_at: the timeout is a budget
     * for doing the work, and a job can sit in a queue for as long as the jobs ahead of it
     * take. Comparing it to when somebody asked wrote summaries off mid-flight.
     *
     * CarbonInterface rather than CarbonImmutable because the concrete class is whatever
     * Date::use() was given in AppServiceProvider, and the facade is typed for the
     * mutable one.
     */
    public static function stalledBefore(): CarbonInterface
    {
        return Date::now()->subSeconds(config()->integer('summaries.timeout'));
    }

    /**
     * The moment before which a summary nothing has started is never going to be started.
     *
     * Compared against requested_at, which is the opposite of the horizon above and right
     * for the opposite reason: nothing here has a started_at to measure from, and the
     * question is how long somebody has been waiting rather than how long the work has run.
     *
     * Far more generous than the timeout, because waiting is ordinary and being wrong about
     * it costs a page that waits too long rather than a summary written off mid flight.
     */
    public static function abandonedBefore(): CarbonInterface
    {
        return Date::now()->subSeconds(config()->integer('summaries.abandon_after'));
    }

    /**
     * The moment before which a summary already queued again may be queued again.
     *
     * Compared against requeued_at, and only ever reached by a row that has one: a row
     * nobody has requeued is requeued at once, so this spaces out the repetition rather
     * than delaying the repair.
     */
    public static function requeueableBefore(): CarbonInterface
    {
        return Date::now()->subSeconds(config()->integer('summaries.requeue_after'));
    }

    /**
     * Summaries a worker began and did not finish.
     *
     * Its own timeout should have killed it and failed the row, so anything here lost its
     * worker outright - killed rather than stopped. These are written off.
     *
     * @param  Builder<Summary>  $query
     */
    #[Scope]
    protected function stalled(Builder $query): void
    {
        $query->where('status', SummaryStatus::Pending)
            ->whereNotNull('started_at')
            ->where('started_at', '<=', self::stalledBefore());
    }

    /**
     * Summaries no worker has started.
     *
     * Half a question on purpose, and not one the recovery command asks: "nobody started
     * this" says nothing about whether anybody still might, and the two answers want
     * opposite treatment. The two scopes below are those answers, and they split this set
     * between them at the horizon above so that no row can ever be in both. Reach for one
     * of those rather than this one, or a row gets queued again and written off in the
     * same breath.
     *
     * @param  Builder<Summary>  $query
     */
    #[Scope]
    protected function unclaimed(Builder $query): void
    {
        $query->where('status', SummaryStatus::Pending)
            ->whereNull('started_at');
    }

    /**
     * Summaries no worker has started, that a worker may still plausibly get to.
     *
     * Ordinary for as long as a job is queued, and indistinguishable from a job that no
     * longer exists - flushed with its queue, or dropped because the uniqueness lock was
     * still held by a job that had already died. Rather than guess which, the recovery
     * command queues these again: with the claim in place a duplicate dispatch is harmless,
     * so nothing is lost by being wrong about it.
     *
     * @param  Builder<Summary>  $query
     */
    #[Scope]
    protected function awaitingWorker(Builder $query): void
    {
        $query->unclaimed()
            ->where('requested_at', '>', self::abandonedBefore());
    }

    /**
     * Summaries nothing ever started, for long enough that nothing ever will.
     *
     * The bound on the scope above and its exact complement. Queueing a waiting summary
     * again is right for as long as there is any reason to think a worker will get to it,
     * and this is where that stops: a queue that has not once started this job in a day is
     * not busy, it is not running. These are written off.
     *
     * @param  Builder<Summary>  $query
     */
    #[Scope]
    protected function neverStarted(Builder $query): void
    {
        $query->unclaimed()
            ->where('requested_at', '<=', self::abandonedBefore());
    }

    /**
     * Summaries worth queueing a job for again on this run in particular.
     *
     * Narrower than awaitingWorker and deliberately built on top of it rather than folded
     * into it. Which of the two horizons a row falls on decides its fate, and those two
     * scopes divide every unclaimed row between them so that none can be queued again and
     * written off by the same run. This only decides whether a row that is going to be
     * queued again eventually is queued again now, so it must not join that split - a row
     * held back here is still awaiting a worker, and still not one to give up on.
     *
     * Null requeued_at passes, which is what keeps the first requeue prompt.
     *
     * @param  Builder<Summary>  $query
     */
    #[Scope]
    protected function dueForRequeue(Builder $query): void
    {
        $query->awaitingWorker()
            ->where(fn (Builder $requeued): Builder => $requeued
                ->whereNull('requeued_at')
                ->orWhere('requeued_at', '<=', self::requeueableBefore()));
    }

    /**
     * Whether the worker on this row in particular has gone.
     *
     * Shares its horizon with the stalled scope on purpose: the controller has to agree
     * with the recovery command, or one starts an attempt the other writes off.
     */
    public function isStalled(): bool
    {
        return $this->status === SummaryStatus::Pending
            && $this->started_at !== null
            && $this->started_at <= self::stalledBefore();
    }

    /**
     * Whether this row in particular has waited so long that nothing is going to start it.
     *
     * The counterpart of isStalled for the other way an attempt ends, and it shares the
     * neverStarted horizon for the same reason: the controller has to agree with the
     * recovery command, or one starts an attempt the other writes off.
     *
     * Deliberately not "has it been pending a while". A row a worker is holding is excluded
     * by the null check, and answering yes for one of those would have the controller clear
     * a live claim and let a second job summarise the same video.
     */
    public function hasWaitedTooLong(): bool
    {
        return $this->status === SummaryStatus::Pending
            && $this->started_at === null
            && $this->requested_at <= self::abandonedBefore();
    }
}
