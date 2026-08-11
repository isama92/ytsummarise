<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SummaryStatus;
use Carbon\CarbonImmutable;
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
 */
#[Fillable(['video_id', 'status', 'title', 'body', 'requested_at'])]
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
        ];
    }

    /**
     * Summaries that have been pending longer than a video is given.
     *
     * The job may have been killed, never reserved, or lost with the queue it sat in;
     * from here they look the same and all end up written off the same way.
     *
     * @param  Builder<Summary>  $query
     */
    #[Scope]
    protected function stalled(Builder $query): void
    {
        $query->where('status', SummaryStatus::Pending)
            ->where('requested_at', '<=', Date::now()->subSeconds(config()->integer('summaries.timeout')));
    }
}
