<?php

declare(strict_types=1);

namespace App\Services\YouTube\Data;

use App\Services\YouTube\Enums\VideoPresence;
use Spatie\LaravelData\Data;

/**
 * What one lookup came back with.
 *
 * Found with a null title is a real combination rather than an oversight: a video whose owner
 * disabled embedding answers the oEmbed endpoint with a 401, which proves the video exists
 * while refusing to name it. That video is still worth summarising, so the presence and the
 * title are two separate answers here instead of one nullable string standing for both.
 */
final class LookupResult extends Data
{
    public function __construct(
        public VideoPresence $presence,
        public ?string $title = null,
    ) {}

    /**
     * The video is there, with a title only if there is really one there.
     *
     * Both endpoints hand back json this code did not write, so this is the one place that
     * decides a missing or oddly typed title is no title, rather than a type error surfacing
     * deep inside the job. Called with no argument for a video that answered without naming
     * itself.
     */
    public static function found(mixed $title = null): self
    {
        return new self(
            VideoPresence::Found,
            is_string($title) && $title !== '' ? $title : null,
        );
    }

    /**
     * No such video, and something authoritative said so.
     */
    public static function missing(): self
    {
        return new self(VideoPresence::Missing);
    }

    /**
     * Nothing was established, which is not the same as the video not being there.
     */
    public static function unknown(): self
    {
        return new self(VideoPresence::Unknown);
    }
}
