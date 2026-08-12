<?php

declare(strict_types=1);

namespace App\Services\YouTube\Enums;

/**
 * What an attempt to fetch a transcript came back with.
 *
 * Three states for the same reason VideoPresence has three: "this video has no captions" and
 * "we could not go and get them" are different answers, and only one of them is worth asking
 * about again.
 *
 * The third case is named Unavailable rather than Unknown, unlike its neighbour, because it is
 * answering a different question. A lookup that fails leaves the video's existence genuinely
 * unknown; a transcript fetch that fails leaves the transcript unavailable, which is the whole
 * of what the caller needed to know either way.
 *
 * Backed, because TranscriptResult is a laravel-data object and laravel-data transforms an enum
 * through its value. Nothing persists these values - App\Enums\SummaryError is the vocabulary
 * that reaches the database and the page, and the job is what translates between the two.
 */
enum TranscriptPresence: string
{
    /**
     * There is a transcript and this is it.
     */
    case Found = 'found';

    /**
     * The video has no captions of any kind, not even automatic ones.
     *
     * A permanent answer about the video rather than a fault of ours, which is what makes it
     * worth telling somebody plainly instead of inviting them to try again.
     */
    case Missing = 'missing';

    /**
     * There may well be a transcript; we could not get at it.
     *
     * yt-dlp missing, failing or hung, or a caption track that did not arrive.
     */
    case Unavailable = 'unavailable';
}
