<?php

declare(strict_types=1);

namespace App\Services\YouTube;

/**
 * What a lookup managed to establish about a video.
 *
 * Three states and not two: "we asked and it is not there" and "we could not ask" mean
 * different things to whoever has to explain it, and collapsing them would have the page
 * telling somebody their link is wrong because YouTube was rate limiting us.
 *
 * Unbacked, because nothing persists this. App\Enums\SummaryError is the vocabulary that
 * reaches the database and the page; this is the transport's own answer, and the job is what
 * translates between them.
 */
enum VideoPresence
{
    /**
     * The video is there. The title may still be absent; see LookupResult.
     */
    case Found;

    /**
     * Definitively not there: no such id, or one only its owner can see.
     */
    case Missing;

    /**
     * YouTube did not answer usefully, so nothing was established either way.
     */
    case Unknown;
}
