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
 */
#[Fillable(['video_id', 'status', 'title', 'body', 'requested_at', 'started_at'])]
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
     * Ordinary for as long as a job is queued, and indistinguishable from a job that no
     * longer exists - flushed with its queue, or dropped because the uniqueness lock was
     * still held by a job that had already died. Rather than guess which, the recovery
     * command queues these again: with the claim in place a duplicate dispatch is harmless,
     * so nothing is lost by being wrong about it.
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
}
