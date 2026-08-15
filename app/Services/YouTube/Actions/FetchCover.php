<?php

declare(strict_types=1);

namespace App\Services\YouTube\Actions;

use App\Models\Summary;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Gets the picture YouTube shows for a video, so a summary is not the only thing on the page
 * that says what it is about.
 *
 * Fetched once and kept, rather than pointed at from the page. Hot linking i.ytimg.com would
 * mean every reader's browser announcing to YouTube which videos this installation summarises,
 * and a page that quietly stops having pictures the day that host decides otherwise.
 *
 * Illuminate's http client rather than a Saloon connector, which is the same call FetchTranscript
 * makes next door and for the same reason. The two connectors in this namespace exist because
 * oEmbed and the Data API are json apis that answer questions and each needs its own base url,
 * authentication and dto; this is a jpeg on a cdn, and none of that machinery has anything to do.
 *
 * Nothing throws, in the same way and for the same reason as both of its neighbours: every fault
 * comes back as a value, because what a missing cover should cost is the caller's decision. Here
 * the answer is always "nothing" - see App\Actions\Summarising\FindVideo - but that is the step's
 * call to make rather than this class's to assume.
 */
class FetchCover
{
    /**
     * Where covers are kept. Named here so nothing has to spell the disk twice; see
     * config/filesystems.php for why it is not the public one.
     */
    public const string DISK = 'video-covers';

    /**
     * The sizes YouTube publishes, largest first, and the order they are asked for in.
     *
     * Only the last of these is guaranteed. maxresdefault is 1280x720 and is what this is
     * really after, but it exists only for videos uploaded with a thumbnail that large, so
     * for an older or smaller upload it answers 404 - an ordinary outcome rather than a
     * fault. sddefault is 640x480 and hqdefault, at 480x360, is the one YouTube has always
     * generated for everything.
     *
     * Asked for by name rather than looked up through the Data API, which does report which
     * sizes a video really has. That would be one request instead of up to three, but it needs
     * a key the application deliberately works without, and LookupVideo returns before ever
     * reaching the Data API whenever oEmbed named the video - which is almost always. Buying
     * one saved 404 with a keyed api call on every summary is the wrong way round.
     *
     * @var list<string>
     */
    private const array SIZES = ['maxresdefault', 'sddefault', 'hqdefault'];

    /**
     * Put this summary's cover on the disk, and say whether there is now one there.
     *
     * The first size that answers with something wins, so a video with a full size thumbnail
     * costs one request and one that has never had one costs three.
     */
    public function execute(Summary $summary): bool
    {
        try {
            foreach (self::SIZES as $size) {
                $url = "https://i.ytimg.com/vi/{$summary->video_id}/{$size}.jpg";

                $response = Http::timeout(config()->integer('summaries.cover.timeout'))->get($url);

                $image = $response->body();

                /*
                 * The body is checked as well as the status, because a 200 carrying nothing is
                 * not a cover. Writing it would leave a zero byte file that every later run
                 * treats as a cover already fetched, and a broken image on the page for good:
                 * the guard in FindVideo asks whether a file is there, not whether it is any
                 * good, and nothing would ever come back to look again.
                 */
                if (! $response->successful() || $image === '') {
                    continue;
                }

                Storage::disk(self::DISK)->put($summary->file_name, $image);

                return true;
            }
        } catch (ConnectionException $exception) {
            /*
             * One catch around the whole ladder rather than one per request, and that is what
             * decides what happens next: every size is on the same host, so a host that is not
             * answering will not answer the other two either. Trying them anyway would spend
             * three timeouts of step one's budget to learn the same thing once.
             *
             * The message is logged whole, unlike the trims in LookupVideo and FetchTranscript.
             * Those trim at " for " because the url the client appends there carries an api key
             * or a signed expiry; a thumbnail url is a video code and a size, and the size is
             * the useful half of knowing which request it was.
             */
            Log::warning('Could not reach YouTube for a video cover', [
                'video_id' => $summary->video_id,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        /*
         * Every size refused, which for a video the lookup has just found is unusual enough to
         * be worth a line. The ordinary reason is a video removed between step one's lookup and
         * this request; the reason worth finding out about is YouTube having moved the naming
         * scheme above out from under us, which would look exactly like this on every video.
         */
        Log::warning('YouTube offered no cover image for a video', [
            'video_id' => $summary->video_id,
        ]);

        return false;
    }
}
