<?php

declare(strict_types=1);

namespace App\Services\YouTube\Actions;

use App\Services\YouTube\Data\TranscriptResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;

/**
 * Gets the words out of a video, which is the thing that actually gets summarised.
 *
 * Two steps. yt-dlp is asked to describe the video, which comes back naming every caption track
 * it has and where each one lives; the chosen track is then fetched over http. That is a
 * deliberate split rather than letting yt-dlp download the subtitles itself: writing files means
 * a temporary directory, a cleanup path that has to survive a failure halfway through, and
 * leftovers on disk when it does not. Nothing here touches the filesystem, and both steps are
 * ordinary fakes in a test - Process::fake() and Http::fake() - so no test needs a real binary
 * or the network.
 *
 * Nothing throws, in the same way and for the same reason as LookupVideo next door. Every fault
 * comes back as a value, because what a fault should cost somebody is the job's decision rather
 * than the transport's.
 */
class FetchTranscript
{
    /**
     * The format asked for by name, out of the seven YouTube offers per track.
     *
     * json3 is the only one of them that is data rather than a subtitle file. The alternatives -
     * vtt, srt, ttml - are formats for putting words on a screen, and reading an automatic
     * caption track out of one means undoing YouTube's rolling scroll by hand: each line is
     * re-emitted with the next one appended, so a naive read produces every sentence several
     * times. json3 keeps the same information as discrete events with the scroll marked, which
     * makes the whole problem a skipped array key. See toText() below.
     */
    private const string CAPTION_FORMAT = 'json3';

    /**
     * Everything worth having about one video's captions.
     */
    public function execute(string $videoId): TranscriptResult
    {
        $metadata = $this->describe($videoId);

        if ($metadata === null) {
            return TranscriptResult::unavailable();
        }

        $track = $this->chooseTrack($metadata);

        /*
         * Nothing to fetch, and that is an answer about the video rather than a failure of
         * ours: a video with no captions has nothing to summarise, however many times it is
         * asked for.
         */
        if ($track === null) {
            return TranscriptResult::missing();
        }

        $events = $this->fetchTrack($track['url']);

        if ($events === null) {
            return TranscriptResult::unavailable();
        }

        $text = $this->toText($events);

        /*
         * A track that exists and carries no words. Music videos do this: the whole track is
         * [Music] and sound-effect captions, which read as a transcript and summarise as
         * nothing. Missing rather than Unavailable, because asking again gets the same track.
         */
        return $text === ''
            ? TranscriptResult::missing()
            : TranscriptResult::found($text, $track['language']);
    }

    /**
     * What yt-dlp knows about a video, or null if it could not be asked.
     *
     * The arguments go as an array, so no shell is involved and the video id cannot be anything
     * but one argument however it is spelled. It has already been through
     * Summary::VIDEO_ID_PATTERN by the time it reaches here, which makes this belt as well as
     * braces rather than the only guard.
     *
     * @return array<string, mixed>|null
     */
    private function describe(string $videoId): ?array
    {
        $binary = config()->string('summaries.transcript.binary');

        try {
            $result = Process::timeout(config()->integer('summaries.transcript.timeout'))
                ->run([
                    $binary,

                    /*
                     * So the command means the same thing on every machine. yt-dlp reads
                     * /etc/yt-dlp.conf, ~/.config/yt-dlp/config and a portable config before it
                     * reads any of these arguments, and an option in one of those - a progress
                     * format, --write-info-json, anything that writes to stdout - puts text
                     * around the json below and every video comes back unavailable, blaming the
                     * json rather than the file that broke it.
                     */
                    '--ignore-config',
                    '--dump-single-json',
                    '--skip-download',
                    '--no-playlist',
                    '--no-warnings',
                    'https://www.youtube.com/watch?v='.$videoId,
                ]);
        } catch (ProcessRuntimeException $exception) {
            /*
             * One catch for two faults, because both extend this and both mean the same thing
             * here: yt-dlp is not installed at all, so the process never started, or it started
             * and hung until the timeout above cut it off. Neither says anything about the
             * video, and the job treats them the same way.
             */
            Log::warning('Could not run yt-dlp', [
                'binary' => $binary,
                'video_id' => $videoId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($result->failed()) {
            Log::warning('yt-dlp would not describe a video', [
                'video_id' => $videoId,
                'exit_code' => $result->exitCode(),

                /*
                 * Trimmed because this is somebody else's output and it can run long - a stack
                 * trace, or a page of extractor warnings. The first line is the one that says
                 * what went wrong.
                 */
                'error_output' => Str::limit(trim($result->errorOutput()), 500),
            ]);

            return null;
        }

        try {
            $metadata = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Log::warning('yt-dlp described a video with something that is not json', [
                'video_id' => $videoId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        return is_array($metadata) ? $metadata : null;
    }

    /**
     * Which caption track to fetch, and what language it is in.
     *
     * Language-directed rather than "whichever is there", which is the trap in this data.
     * `automatic_captions` holds YouTube's transcription of the audio *and* a machine
     * translation of that into every language it supports - a hundred and fifty-seven of them
     * for an ordinary video, in no useful order. Taking the first would summarise an English
     * video from its Abkhazian translation.
     *
     * So: the language the video is in, then a track somebody wrote in that language, then
     * YouTube's own transcription of it. A human-written track is preferred because it is
     * punctuated and spelled, and an automatic one is a wall of lowercase guesses.
     *
     * Every candidate is carried through to a usable url rather than stopping at the first one
     * that merely exists. "There is a manual track" and "there is a manual track I can read"
     * are different questions: a track offered in every format but json3 used to end the search
     * and report the video as having no captions at all, while its automatic transcription sat
     * unread beside it - and no_transcript is permanent and does not invite another attempt.
     *
     * Likewise the language is a preference rather than a requirement. yt-dlp reports whatever
     * the uploader set, which is occasionally wrong, and a video whose audio is declared English
     * while its only tracks are Dutch is still a video with a transcript.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{url: string, language: string}|null
     */
    private function chooseTrack(array $metadata): ?array
    {
        $manual = $this->tracks($metadata, 'subtitles');
        $automatic = $this->tracks($metadata, 'automatic_captions');

        foreach ($this->languages($metadata, $automatic) as $language) {
            foreach ([$manual, $automatic] as $tracks) {
                $url = $this->captionUrl($this->trackIn($tracks, $language));

                if ($url !== null) {
                    return ['url' => $url, 'language' => $language];
                }
            }
        }

        return $this->anyManualTrack($manual);
    }

    /**
     * The last resort: a track somebody uploaded, when nothing said what language the video is in.
     *
     * Reached only when yt-dlp named no language and there is no `-orig` key to infer one from,
     * which leaves no way to tell a transcription from a translation. Refusing at that point
     * reported videos with real, human-written subtitles as having none at all - permanently,
     * since no_transcript does not invite another attempt.
     *
     * Only `subtitles`, and never `automatic_captions`. That distinction is the whole safety of
     * this: the automatic map is one transcription filed among a hundred and fifty machine
     * translations of it, so picking blindly there is how an English video gets summarised from
     * Abkhazian. The manual map is what the uploader uploaded - every entry in it is a real
     * track, and the worst case is summarising a translation somebody wrote on purpose.
     *
     * The first usable one, which is a guess where a video carries several. YouTube lists the
     * original first often enough for that to be the better guess, and the alternative is
     * refusing a video that plainly has subtitles.
     *
     * @param  array<array-key, mixed>  $manual
     * @return array{url: string, language: string}|null
     */
    private function anyManualTrack(array $manual): ?array
    {
        foreach ($manual as $key => $entries) {
            if (! is_string($key)) {
                continue;
            }
            if (! is_array($entries)) {
                continue;
            }
            $url = $this->captionUrl(array_values($entries));

            if ($url !== null) {
                return ['url' => $url, 'language' => TranscriptResult::primaryLanguage($key)];
            }
        }

        return null;
    }

    /**
     * The languages to look for a track in, best first.
     *
     * The `-orig` suffix comes first, and the order matters more than it looks. That suffix is
     * how YouTube labels its transcription of the original audio, so it is evidence of what was
     * actually spoken; the `language` field is whatever the uploader typed, and uploaders are
     * sometimes wrong.
     *
     * Reading the declared tag first walked straight back into the trap the language filter
     * exists to prevent, from the other side. Every video with automatic captions carries a
     * machine translation into every supported language, so a video declared English with Dutch
     * audio finds an English *translation* on the first pass and never reaches `nl-orig`. The
     * summary is then built from a machine translation, `isEnglish()` is true so it is never
     * translated, and the outline records the language as English. Nothing anywhere says the
     * words came second-hand.
     *
     * The declared tag stays as the fallback, because most videos have no automatic captions at
     * all and it is the only thing left to go on.
     *
     * Both may be absent, and then this is empty: without either there is no way to tell a
     * transcription from a translation, and chooseTrack falls back to what the uploader uploaded
     * rather than guessing inside the automatic map.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<array-key, mixed>  $automatic
     * @return array<int, string>
     */
    private function languages(array $metadata, array $automatic): array
    {
        $languages = [];

        foreach (array_keys($automatic) as $key) {
            if (is_string($key) && str_ends_with($key, '-orig')) {
                $languages[] = TranscriptResult::primaryLanguage($key);

                break;
            }
        }

        $declared = $metadata['language'] ?? null;

        if (is_string($declared) && $declared !== '') {
            $languages[] = TranscriptResult::primaryLanguage($declared);
        }

        return array_values(array_unique($languages));
    }

    /**
     * Every entry for one language, out of a set of tracks, matched on the primary subtag.
     *
     * Matched that way rather than by exact key because the same language is keyed several
     * ways: `en`, `en-GB` and `en-orig` are all English, and which of them a video carries is
     * not something to depend on.
     *
     * All of them and not the first, which is the same mistake as stopping at a track that
     * merely exists, one level down. Keeping only the first matching key meant that a video
     * listing `en` with nothing usable in it and `en-GB` with a readable track was reported as
     * having no subtitles: the empty key won the race and the readable one was never looked at.
     * Collecting them lets captionUrl decide, which is the only place that knows what usable
     * means.
     *
     * `-orig` entries lead, because among automatic tracks that key is the transcription and
     * the others are translations of it. Within a group the order is whatever yt-dlp gave.
     *
     * @param  array<array-key, mixed>  $tracks
     * @return array<int, mixed>
     */
    private function trackIn(array $tracks, string $language): array
    {
        $original = [];
        $translated = [];

        foreach ($tracks as $key => $entries) {
            if (! is_string($key)) {
                continue;
            }
            if (! is_array($entries)) {
                continue;
            }
            if (TranscriptResult::primaryLanguage($key) !== $language) {
                continue;
            }

            if (str_ends_with($key, '-orig')) {
                $original = array_merge($original, array_values($entries));

                continue;
            }

            $translated = array_merge($translated, array_values($entries));
        }

        return array_merge($original, $translated);
    }

    /**
     * Where to fetch json3 out of a set of track entries, or null if none of them offers it.
     *
     * An empty set is an ordinary input rather than a special case, because "no track in this
     * language" and "a track I cannot read" lead to exactly the same place: try the next
     * candidate.
     *
     * @param  array<int, mixed>  $entries
     */
    private function captionUrl(array $entries): ?string
    {
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['ext'] ?? null) !== self::CAPTION_FORMAT) {
                continue;
            }
            $url = $entry['url'] ?? null;

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * One caption track's events, or null if it could not be fetched or made sense of.
     *
     * The url is one yt-dlp handed over a moment ago and is signed and short lived, which is
     * why this is not retried: a track that did not arrive will not arrive from the same url
     * later, and the whole attempt is the thing worth trying again.
     *
     * @return array<int, mixed>|null
     */
    private function fetchTrack(string $url): ?array
    {
        try {
            $response = Http::timeout(config()->integer('summaries.transcript.timeout'))->get($url);
        } catch (ConnectionException $exception) {
            /*
             * The host, without the query. A caption url carries a signature and an expiry and
             * runs to several hundred characters, none of which helps anybody reading a log.
             */
            Log::warning('Could not reach a caption track', [
                'host' => parse_url($url, PHP_URL_HOST),

                /*
                 * Trimmed at " for ", which is where the client appends the url it was trying.
                 * The host above is deliberately taken without its query, and logging the raw
                 * message would put the whole thing back: a caption url carries a signature and
                 * an expiry and runs to several hundred characters.
                 *
                 * The same trim LookupVideo does, for the same reason - see .ai/rules/services.md
                 * - except that this is Illuminate's client rather than Saloon's sender. Both
                 * append the url the same way, and neither redacts a query string.
                 */
                'exception' => Str::before($exception->getMessage(), ' for '),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('A caption track answered unusably', [
                'host' => parse_url($url, PHP_URL_HOST),
                'status' => $response->status(),
            ]);

            return null;
        }

        /*
         * json() decodes without throwing and hands back null for anything it could not read,
         * so a 2xx carrying html - a captive portal, a proxy with opinions - arrives here as a
         * null rather than as an exception.
         */
        $events = $response->json('events');

        return is_array($events) ? $events : null;
    }

    /**
     * The words, with the timings and the scroll taken out.
     *
     * Two things are dropped. Events carrying `aAppend` are the rolling scroll: YouTube emits
     * one for every line to say "and keep the previous line on screen", and its segments hold a
     * newline rather than any words. Events with no segments at all are window definitions,
     * which is styling.
     *
     * What is left is one event per spoken line, whose segments are individual words with their
     * spacing already in them, so they concatenate rather than join. Squished at the end because
     * caption text carries newlines of its own inside a line.
     *
     * @param  array<int, mixed>  $events
     */
    private function toText(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            if (isset($event['aAppend'])) {
                continue;
            }
            $segments = $event['segs'] ?? null;

            if (! is_array($segments)) {
                continue;
            }

            $line = '';

            foreach ($segments as $segment) {
                $words = is_array($segment) ? ($segment['utf8'] ?? null) : null;

                if (is_string($words)) {
                    $line .= $words;
                }
            }

            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }

        return Str::squish(implode(' ', $lines));
    }

    /**
     * One of the two caption maps, or an empty one when it is absent or not a map.
     *
     * Keyed by array-key rather than by string, which is not pedantry: json_decode turns a
     * numeric-looking object key into an integer, so a caption map with a language tag of `419`
     * arrives here keyed by an int. That is why the loops below check the key's type before
     * treating it as a language.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function tracks(array $metadata, string $key): array
    {
        $tracks = $metadata[$key] ?? null;

        return is_array($tracks) ? $tracks : [];
    }
}
