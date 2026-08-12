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
     * The moment before which an attempt still pending has been pending too long.
     *
     * Compared against requested_at, which is set every time an attempt starts, so this
     * measures the attempt in flight rather than the age of the row: a video summarised
     * yesterday and asked for again a minute ago has a minute on the clock, not a day.
     *
     * A liveness horizon and not a budget for the work. What the work itself gets is
     * summaries.timeout, which the worker enforces; this only asks whether anything is
     * still going to happen, and it is generous because being wrong costs somebody a
     * summary they have to ask for twice.
     *
     * CarbonInterface rather than CarbonImmutable because the concrete class is whatever
     * Date::use() was given in AppServiceProvider, and the facade is typed for the
     * mutable one.
     */
    public static function staleBefore(): CarbonInterface
    {
        return Date::now()->subSeconds(config()->integer('summaries.stale_after'));
    }

    /**
     * Summaries whose attempt has been pending long enough to give up on.
     *
     * The only set the expiry command works from, and deliberately blunt: it does not ask
     * whether a worker ever picked the row up. A job queued behind a long enough backlog is
     * in here while it is still perfectly alive, and will be written off and then stop at
     * the status guard in the job when a worker finally reaches it. That is the cost of one
     * horizon instead of two, and the horizon is sized so it is rare rather than impossible.
     *
     * @param  Builder<Summary>  $query
     */
    #[Scope]
    protected function stale(Builder $query): void
    {
        $query->where('status', SummaryStatus::Pending)
            ->where('requested_at', '<=', self::staleBefore());
    }
}
