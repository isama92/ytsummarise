<?php

declare(strict_types=1);

namespace App\Services\YouTube\Data;

use App\Services\YouTube\Enums\TranscriptPresence;
use Spatie\LaravelData\Data;

/**
 * What one attempt to fetch a transcript came back with.
 *
 * The language travels with the text because it decides what happens next: a summary of a video
 * that was not in English is translated afterwards, and nothing downstream can work out which
 * language it was reading by looking at it.
 *
 * Found always carries both, unlike LookupResult next door, where a video that exists without a
 * usable title is an ordinary outcome. There is no such thing here: a track that came back empty
 * is Missing, because a transcript of nothing is not something to summarise.
 */
final class TranscriptResult extends Data
{
    public function __construct(
        public TranscriptPresence $presence,
        public ?string $text = null,
        public ?string $language = null,
    ) {}

    /**
     * A transcript, in whichever language the video was in.
     *
     * The language is normalised to its primary subtag, so `en-GB`, `en-US` and the `en-orig`
     * that YouTube labels an original-language automatic track with all arrive as `en`. That is
     * the only distinction anything downstream draws, and drawing it in one place means the
     * translation step does not have to know the shapes a YouTube language tag comes in.
     */
    public static function found(string $text, string $language): self
    {
        return new self(
            TranscriptPresence::Found,
            $text,
            self::primaryLanguage($language),
        );
    }

    /**
     * The video is real and has nothing to summarise.
     */
    public static function missing(): self
    {
        return new self(TranscriptPresence::Missing);
    }

    /**
     * Something got in the way, which says nothing about the video.
     */
    public static function unavailable(): self
    {
        return new self(TranscriptPresence::Unavailable);
    }

    /**
     * Whether this transcript needs translating afterwards.
     *
     * Asked of the result rather than worked out by the caller, so that what counts as English
     * is decided once. A transcript that is not there is not English and not anything else; the
     * caller never reaches this with one.
     */
    public function isEnglish(): bool
    {
        return $this->language === 'en';
    }

    /**
     * The part of a language tag before the first separator, lowercased.
     *
     * YouTube writes them several ways - `nl`, `pt-BR`, `es-419`, `en-orig` - and everything
     * after that first subtag is about region or provenance rather than language.
     *
     * Public because FetchTranscript matches caption track keys with it while deciding which
     * track to take, and the two must agree: a track chosen as Dutch that arrived here as
     * something else would be summarised in one language and translated as though it were
     * another. One implementation is what makes that impossible rather than unlikely.
     */
    public static function primaryLanguage(string $language): string
    {
        $primary = preg_split('/[-_]/', $language, 2)[0] ?? $language;

        return mb_strtolower($primary);
    }
}
