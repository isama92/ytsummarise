<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

use Spatie\LaravelData\Data;

/**
 * Everything written about one video, in every language it was written in.
 *
 * One object holding both versions rather than two columns or two rows, because they are one
 * answer about one video written twice. A video that was not in English is summarised in its own
 * language first - which is where the words came from, and where they read best - and that
 * summary is then translated, so the two are a pair by construction and nothing should be able
 * to have one without the other.
 *
 * english is null for a video that was in English already. That is the ordinary case, and it is
 * a genuine absence rather than a copy of the original: the page decides whether to show a
 * second version by asking whether there is one, so filling it in with a duplicate would put the
 * same summary on screen twice.
 *
 * This is what the outline column holds, and what SummaryController hands the page. Read back
 * out of the database as a plain array rather than as one of these, so an outline written by an
 * older release than the one reading it stays readable; see the cast on the model.
 */
final class SummaryOutline extends Data
{
    public function __construct(
        /**
         * The primary subtag of whatever language the transcript was in - `en`, `nl`, `pt`.
         *
         * Kept because nothing downstream can work it out by looking at the words, and because
         * it is what says the summary above is in a language somebody may not read, which is the
         * whole reason there is a second one below it.
         */
        public string $language,
        public SummarySections $original,
        public ?SummarySections $english = null,
    ) {}
}
