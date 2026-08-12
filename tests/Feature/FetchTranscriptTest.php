<?php

declare(strict_types=1);

use App\Services\YouTube\Actions\FetchTranscript;
use App\Services\YouTube\Enums\TranscriptPresence;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

/**
 * yt-dlp metadata, cut down to the keys the action reads.
 *
 * @param  array<string, mixed>  $subtitles
 * @param  array<string, mixed>  $automatic
 */
function metadata(?string $language, array $subtitles = [], array $automatic = []): string
{
    return (string) json_encode([
        'language' => $language,
        'subtitles' => $subtitles,
        'automatic_captions' => $automatic,
    ]);
}

/**
 * One caption track as yt-dlp lists it: the same words in seven formats, only one of them json3.
 *
 * @return array<int, array<string, string>>
 */
function track(string $url): array
{
    return [
        ['ext' => 'srv1', 'url' => $url.'&fmt=srv1'],
        ['ext' => 'vtt', 'url' => $url.'&fmt=vtt'],
        ['ext' => 'json3', 'url' => $url],
    ];
}

/**
 * A caption track's own answer, in the shape YouTube really returns.
 *
 * @param  array<int, array<string, mixed>>  $events
 */
function captions(array $events): void
{
    Http::fake(['www.youtube.com/api/timedtext*' => Http::response(['events' => $events])]);
}

function transcript(): FetchTranscript
{
    return app(FetchTranscript::class);
}

test('a video with subtitles is transcribed', function (): void {
    fakeTranscript('We are no strangers to love.');

    $result = transcript()->execute('dQw4w9WgXcQ');

    expect($result->presence)->toBe(TranscriptPresence::Found)
        ->and($result->text)->toBe('We are no strangers to love.')
        ->and($result->language)->toBe('en');
});

test('the right video is asked about, without a shell', function (): void {
    fakeTranscript();

    transcript()->execute('dQw4w9WgXcQ');

    Process::assertRan(function (PendingProcess $process): bool {
        /*
         * An array command and not a string, which is what keeps a video id out of a shell.
         * Asserted rather than assumed: the id reaches here from a request, and the day
         * somebody interpolates it into a string this is what says so.
         */
        expect($process->command)->toBeArray();

        $command = ytDlpCommand($process);

        return str_contains($command, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            && str_contains($command, '--dump-single-json')
            && str_contains($command, '--skip-download')
            /* Or a link that happens to name a playlist fetches every video in it. */
            && str_contains($command, '--no-playlist');
    });
});

test('the binary is the configured one', function (): void {
    fakeTranscript();

    config()->set('summaries.transcript.binary', '/opt/bin/yt-dlp');

    transcript()->execute('dQw4w9WgXcQ');

    Process::assertRan(fn (PendingProcess $process): bool => str_starts_with(ytDlpCommand($process), '/opt/bin/yt-dlp '));
});

/*
 * A written track is punctuated and spelled; an automatic one is a wall of lowercase guesses.
 * Both are there for a lot of videos, so which one is taken is worth pinning.
 */
test('a track somebody wrote is preferred to one YouTube guessed', function (): void {
    Process::fake(fn () => Process::result(metadata(
        'en',
        subtitles: ['en' => track('https://www.youtube.com/api/timedtext?written=1')],
        automatic: ['en' => track('https://www.youtube.com/api/timedtext?guessed=1')],
    )));

    captions([['segs' => [['utf8' => 'The written one.']]]]);

    transcript()->execute('dQw4w9WgXcQ');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'written=1'));
});

test('a video with only automatic captions is still transcribed', function (): void {
    Process::fake(fn () => Process::result(metadata(
        'en',
        automatic: ['en' => track('https://www.youtube.com/api/timedtext?guessed=1')],
    )));

    captions([['segs' => [['utf8' => 'The guessed one.']]]]);

    $result = transcript()->execute('dQw4w9WgXcQ');

    expect($result->presence)->toBe(TranscriptPresence::Found)
        ->and($result->text)->toBe('The guessed one.');
});

/*
 * The trap in this data, and the reason the track is chosen by language rather than by taking
 * whatever is first. automatic_captions holds YouTube's transcription of the audio *and* a
 * machine translation of it into every language it supports - a hundred and fifty-seven of them,
 * alphabetically, so the first is Abkhazian. Taking that would summarise an English video from
 * its Abkhazian translation, and nothing downstream could tell.
 */
test('a machine translation is not mistaken for the transcription', function (): void {
    Process::fake(fn () => Process::result(metadata(
        'en',
        automatic: [
            'ab' => track('https://www.youtube.com/api/timedtext?lang=ab'),
            'aa' => track('https://www.youtube.com/api/timedtext?lang=aa'),
            'en-orig' => track('https://www.youtube.com/api/timedtext?lang=en-orig'),
            'nl' => track('https://www.youtube.com/api/timedtext?lang=nl'),
        ],
    )));

    captions([['segs' => [['utf8' => 'The English one.']]]]);

    $result = transcript()->execute('dQw4w9WgXcQ');

    expect($result->language)->toBe('en');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'lang=en-orig'));
});

/*
 * The same trap from the other side: `-orig` is how YouTube labels its transcription of the
 * original audio, so where both are present it is the one that is not a translation.
 */
test('the original-language track wins over a translation into the same language', function (): void {
    Process::fake(fn () => Process::result(metadata(
        'nl',
        automatic: [
            'nl' => track('https://www.youtube.com/api/timedtext?lang=nl'),
            'nl-orig' => track('https://www.youtube.com/api/timedtext?lang=nl-orig'),
        ],
    )));

    captions([['segs' => [['utf8' => 'De originele.']]]]);

    transcript()->execute('dQw4w9WgXcQ');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'lang=nl-orig'));
});

/*
 * The language decides whether the summary gets translated afterwards, so a video in Dutch has
 * to arrive as Dutch rather than as whatever tag YouTube happened to write.
 */
test('the language comes back as a primary subtag', function (string $tag, string $expected): void {
    Process::fake(fn () => Process::result(metadata(
        $tag,
        subtitles: [$tag => track('https://www.youtube.com/api/timedtext?tagged=1')],
    )));

    captions([['segs' => [['utf8' => 'Some words.']]]]);

    expect(transcript()->execute('dQw4w9WgXcQ')->language)->toBe($expected);
})->with([
    'plain' => ['nl', 'nl'],
    'regional' => ['pt-BR', 'pt'],
    'numeric region' => ['es-419', 'es'],
    'uppercase' => ['EN', 'en'],
]);

/*
 * Without a language there is no way to tell the transcription from the translations, so the
 * `-orig` suffix is the fallback: the key carrying it names the language the video was in.
 */
test('a video yt-dlp will not name the language of falls back to the original track', function (): void {
    Process::fake(fn () => Process::result(metadata(
        null,
        automatic: [
            'ab' => track('https://www.youtube.com/api/timedtext?lang=ab'),
            'de-orig' => track('https://www.youtube.com/api/timedtext?lang=de-orig'),
        ],
    )));

    captions([['segs' => [['utf8' => 'Die Worte.']]]]);

    $result = transcript()->execute('dQw4w9WgXcQ');

    expect($result->presence)->toBe(TranscriptPresence::Found)
        ->and($result->language)->toBe('de');
});

test('a video with no captions at all has none rather than a fault', function (): void {
    Process::fake(fn () => Process::result(metadata('en')));

    $result = transcript()->execute('dQw4w9WgXcQ');

    expect($result->presence)->toBe(TranscriptPresence::Missing)
        ->and($result->text)->toBeNull();

    /* And nothing was fetched, because there was nothing to fetch. */
    Http::assertNothingSent();
});

/*
 * Told apart from having no captions, because only one of the two is worth asking again about.
 */
test('a yt-dlp that will not answer leaves the transcript unavailable', function (): void {
    Log::spy();

    ytDlpFails();

    expect(transcript()->execute('dQw4w9WgXcQ')->presence)->toBe(TranscriptPresence::Unavailable);

    Http::assertNothingSent();

    Log::shouldHaveReceived('warning')->once();
});

test('a yt-dlp that is not installed leaves the transcript unavailable', function (): void {
    Log::spy();

    Process::fake(fn () => throw new ProcessStartFailedException(
        new Symfony\Component\Process\Process(['test-yt-dlp']),
        'The command could not be found.',
    ));

    expect(transcript()->execute('dQw4w9WgXcQ')->presence)->toBe(TranscriptPresence::Unavailable);

    Log::shouldHaveReceived('warning')->once();
});

test('a yt-dlp that answers with something that is not json leaves the transcript unavailable', function (): void {
    Log::spy();

    Process::fake(fn () => Process::result('<html>a proxy with opinions</html>'));

    expect(transcript()->execute('dQw4w9WgXcQ')->presence)->toBe(TranscriptPresence::Unavailable);

    Log::shouldHaveReceived('warning')->once();
});

test('a caption track that does not arrive leaves the transcript unavailable', function (): void {
    Log::spy();

    Process::fake(fn () => Process::result(metadata(
        'en',
        subtitles: ['en' => track(CAPTION_URL)],
    )));

    Http::fake(['www.youtube.com/api/timedtext*' => Http::response(status: 503)]);

    expect(transcript()->execute('dQw4w9WgXcQ')->presence)->toBe(TranscriptPresence::Unavailable);

    Log::shouldHaveReceived('warning')->once();
});

/*
 * A 2xx carrying html rather than json, which is what a captive portal answers with. Response
 * json() decodes without throwing and hands back null, so this is a value rather than an
 * exception - but it is still nothing to summarise.
 */
test('a caption track answering with something unusable leaves the transcript unavailable', function (): void {
    Process::fake(fn () => Process::result(metadata(
        'en',
        subtitles: ['en' => track(CAPTION_URL)],
    )));

    Http::fake(['www.youtube.com/api/timedtext*' => Http::response('<html>sign in to continue</html>')]);

    expect(transcript()->execute('dQw4w9WgXcQ')->presence)->toBe(TranscriptPresence::Unavailable);
});

/*
 * A track offered in every format but the one that is data. Missing rather than unavailable:
 * the captions are there and this cannot read them, which asking again will not change.
 */
test('a track offered in no usable format counts as having none', function (): void {
    Process::fake(fn () => Process::result(metadata(
        'en',
        subtitles: ['en' => [['ext' => 'vtt', 'url' => CAPTION_URL]]],
    )));

    expect(transcript()->execute('dQw4w9WgXcQ')->presence)->toBe(TranscriptPresence::Missing);

    Http::assertNothingSent();
});

/*
 * The reason json3 is asked for by name. YouTube emits the rolling scroll as its own event for
 * every line - "keep the previous line up" - carrying a newline rather than words, and a naive
 * read of a subtitle format re-emits every sentence several times. Here it is a skipped key.
 */
test('the rolling scroll of an automatic track is not read as words', function (): void {
    Process::fake(fn () => Process::result(metadata(
        'en',
        automatic: ['en' => track(CAPTION_URL)],
    )));

    /* Exactly the shape a real automatic track has: a window, then lines with appends between. */
    captions([
        ['tStartMs' => 0, 'dDurationMs' => 211879, 'id' => 1],
        ['tStartMs' => 320, 'segs' => [['utf8' => '[Music]']]],
        ['tStartMs' => 18790, 'aAppend' => 1, 'segs' => [['utf8' => "\n"]]],
        ['tStartMs' => 18800, 'segs' => [['utf8' => 'We are'], ['utf8' => ' no'], ['utf8' => ' strangers']]],
        ['tStartMs' => 21790, 'aAppend' => 1, 'segs' => [['utf8' => "\n"]]],
        ['tStartMs' => 21800, 'segs' => [['utf8' => 'to'], ['utf8' => ' love.']]],
    ]);

    expect(transcript()->execute('dQw4w9WgXcQ')->text)
        ->toBe('[Music] We are no strangers to love.');
});

/*
 * Music videos do this: every event is a sound-effect caption, which reads as a transcript and
 * summarises as nothing. Missing rather than unavailable, because asking again gets the same
 * track back.
 */
test('a track carrying no words at all counts as having none', function (): void {
    Process::fake(fn () => Process::result(metadata(
        'en',
        subtitles: ['en' => track(CAPTION_URL)],
    )));

    captions([
        ['tStartMs' => 0, 'segs' => [['utf8' => '   ']]],
        ['tStartMs' => 100, 'aAppend' => 1, 'segs' => [['utf8' => "\n"]]],
    ]);

    expect(transcript()->execute('dQw4w9WgXcQ')->presence)->toBe(TranscriptPresence::Missing);
});

/*
 * Every one of these is somebody else's json rather than something this application wrote, so
 * a key of the wrong type has to be an absence rather than a type error inside a queued job.
 */
test('metadata of the wrong shape is survived', function (mixed $subtitles, mixed $automatic): void {
    Process::fake(fn (): FakeProcessResult => Process::result((string) json_encode([
        'language' => 'en',
        'subtitles' => $subtitles,
        'automatic_captions' => $automatic,
    ])));

    expect(transcript()->execute('dQw4w9WgXcQ')->presence)->toBe(TranscriptPresence::Missing);
})->with([
    'both a string' => ['nonsense', 'nonsense'],
    'both null' => [null, null],
    'entries not lists' => [['en' => 'nonsense'], ['en' => 'nonsense']],
    'entries without a url' => [['en' => [['ext' => 'json3']]], []],
    'a url of the wrong type' => [['en' => [['ext' => 'json3', 'url' => 12]]], []],
]);
