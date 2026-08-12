<?php

declare(strict_types=1);

namespace App\Services\YouTube;

/**
 * What one lookup came back with.
 *
 * Found with a null title is a real combination rather than an oversight: a video whose owner
 * disabled embedding answers the oEmbed endpoint with a 401, which proves the video exists
 * while refusing to name it. That video is still worth summarising, so the presence and the
 * title are two separate answers here instead of one nullable string standing for both.
 */
final readonly class LookupResult
{
    public function __construct(
        public VideoPresence $presence,
        public ?string $title = null,
    ) {}
}
