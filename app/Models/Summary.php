<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SummaryError;
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
 * @property string|null $transcript
 * @property string|null $transcript_language
 * @property string|null $ideas
 * @property array<string, mixed>|null $outline
 * @property SummaryError|null $error
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $started_at
 * @property string|null $claim
 */
#[Fillable(['video_id', 'status', 'title', 'transcript', 'transcript_language', 'ideas', 'outline', 'error', 'requested_at', 'started_at', 'claim'])]
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
            'error' => SummaryError::class,

            /*
             * A plain array rather than a cast to SummaryOutline, because nothing here would
             * gain anything from the object. One place builds an outline - SummariseTranscript,
             * with a constructor - and one place reads it, the page, which receives it as json
             * either way. Casting would put a hydration step between the two whose only job is
             * to be kept in step with both.
             *
             * It is not a promise that an outline of any other shape will be handled. The page
             * reads the keys it expects, so the shape written and the shape rendered have to
             * agree; there is simply no third party in between to disagree with them.
             */
            'outline' => 'array',
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
     * whether a worker ever picked the row up. Anything alive and slow enough is in here,
     * and the horizon is sized so that is rare rather than impossible.
     *
     * Being wrong about a job that has not started costs nothing: it meets the status guard
     * in SummariseVideo when a worker finally reaches it and stops before paying.
     *
     * Being wrong about one already running is not free, and it is worth knowing rather than
     * discovering. That job is past the guard and finishes anyway, which is the right
     * outcome for the summary itself. The cost is the retry: resubmitting clears a claim a
     * live worker is still holding, and a second job can then pay for the same video.
     * Narrowing this to unclaimed rows would trade that for a row whose worker died never
     * being written off at all, which is the second horizon this deliberately does without.
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
