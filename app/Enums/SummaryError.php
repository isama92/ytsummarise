<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an attempt produced nothing, for the page to explain in its own words.
 *
 * Codes rather than sentences, deliberately: the wording lives in lang/en/summaries.php
 * under keys matching these values, so rewording a message is not a migration and does not
 * leave older rows saying something the current release no longer says. It also lets the
 * page treat the cases differently rather than only printing a different string - a video
 * that does not exist is not worth submitting again, and that message does not invite it.
 *
 * See .ai/rules/i18n.md.
 */
enum SummaryError: string
{
    /**
     * No such video, or one nobody but its owner can see.
     */
    case NotFound = 'not_found';

    /**
     * YouTube could not be reached, so whether the video exists is still unknown.
     */
    case Unreachable = 'unreachable';

    /**
     * The video is real and has no captions of any kind, not even automatic ones.
     *
     * A permanent answer rather than a fault, and the message says so: there is nothing to
     * summarise and asking again produces the same nothing. Told apart from Unavailable
     * because that one is worth another attempt and this one never is.
     */
    case NoTranscript = 'no_transcript';

    /**
     * The transcript could not be fetched, which is not the same as there not being one.
     *
     * yt-dlp missing, failing or hanging, or a caption track that did not arrive. Worth
     * trying again, because none of those says anything about the video.
     */
    case Unavailable = 'unavailable';

    /**
     * Nothing was ever going to finish this one; summaries:expire wrote it off.
     */
    case TimedOut = 'timed_out';

    /**
     * The job threw. Whatever went wrong is in the log rather than in front of the user.
     */
    case Unknown = 'unknown';
}
