<?php

declare(strict_types=1);

namespace App\Services\YouTube\Enums;

/**
 * What a lookup managed to establish about a video.
 *
 * Three states and not two: "we asked and it is not there" and "we could not ask" mean
 * different things to whoever has to explain it, and collapsing them would have the page
 * telling somebody their link is wrong because YouTube was rate limiting us.
 *
 * Backed, because LookupResult is a laravel-data object and laravel-data transforms an enum
 * through its value. Nothing persists these values - App\Enums\SummaryError is the vocabulary
 * that reaches the database and the page, and the action is what translates between the two.
 */
enum VideoPresence: string
{
    /**
     * The video is there. The title may still be absent; see LookupResult.
     */
    case Found = 'found';

    /**
     * Definitively not there: no such id, or one only its owner can see.
     */
    case Missing = 'missing';

    /**
     * YouTube did not answer usefully, so nothing was established either way.
     */
    case Unknown = 'unknown';
}
