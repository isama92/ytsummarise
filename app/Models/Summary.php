<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SummaryStatus;
use Database\Factories\SummaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * One row per video, not one per request. See the unique index on video_id.
 *
 * @property int $id
 * @property string $video_id
 * @property SummaryStatus $status
 * @property string|null $body
 */
#[Fillable(['video_id', 'status', 'body'])]
class Summary extends Model
{
    /** @use HasFactory<SummaryFactory> */
    use HasFactory;

    /**
     * The shape of a YouTube video id.
     *
     * Lives here so the form request and the controller validate against one pattern
     * rather than two that drift apart. The frontend has its own copy in
     * resources/js/lib/youtube.ts, which cannot share this one; it decides what to
     * send, this decides what to accept.
     */
    public const string VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => SummaryStatus::class,
        ];
    }
}
