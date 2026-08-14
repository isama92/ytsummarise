<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How long summarising gets, in one place, for the three config files that need it.
 *
 * It lives here rather than in config/summaries.php because two other files have to agree with
 * it and neither can read it: a config file cannot call config(), since the repository is only
 * bound once every file has been read. That constraint is about config() alone - the autoloader
 * is already running, which is why config/queue.php can name App\Enums\Queue. So a class is
 * reachable from all three where a config value is not.
 *
 * The three that have to agree, and the order they have to hold in:
 *
 *     longest step  <  supervisor timeout  <  connection retry_after
 *      (config/summaries)   (config/horizon)     (config/queue)
 *
 * Out of order in either direction, the queue hands a still-running job to a second worker and
 * the same video is summarised twice, at a model call each. This was written out three times
 * before the class existed, with a test to notice when only one copy was changed; one function
 * removes the drift rather than detecting it.
 *
 * The step rather than the whole attempt, since summarising became a chain of five: no single
 * job runs for the whole budget any more, so measuring retry_after against the sum would leave
 * a worker that died holding a job for the better part of an hour before the queue took it back.
 * Which leaves a second, looser ordering, measured from when a video was asked for rather than
 * from when a worker picked a step up:
 *
 *     whole attempt  <=  stale_after
 *      (seconds())        (config/summaries)
 *
 * Values are passed in rather than read here, and that is deliberate rather than ceremony.
 * env() outside the config directory returns null the moment the configuration is cached, so a
 * class that read its own environment would work perfectly until the first `config:cache` and
 * then quietly floor every budget. Taking them as arguments means it cannot: it is arithmetic
 * over whatever it was given, correct wherever it is called from.
 */
final class SummaryBudget
{
    /**
     * What each value falls back to when nothing is set, and the floor it cannot go under.
     *
     * The floors are what stop an empty or unparseable value leaving a step no time to run in:
     * a blank env var casts to zero, and zero seconds is not a budget.
     */
    private const int MODEL_DEFAULT = 600;

    private const int MODEL_FLOOR = 30;

    private const int TRANSCRIPT_DEFAULT = 120;

    private const int TRANSCRIPT_FLOOR = 15;

    private const int TIMEOUT_DEFAULT = 3600;

    private const int TIMEOUT_FLOOR = 60;

    /**
     * How long one prompt gets. Three run for a video that was not in English.
     */
    public static function modelSeconds(mixed $model): int
    {
        return max(self::MODEL_FLOOR, (int) ($model ?? self::MODEL_DEFAULT));
    }

    /**
     * How long yt-dlp gets to answer, and separately how long the track it names gets to arrive.
     */
    public static function transcriptSeconds(mixed $transcript): int
    {
        return max(self::TRANSCRIPT_FLOOR, (int) ($transcript ?? self::TRANSCRIPT_DEFAULT));
    }

    /**
     * The longest any one step of the chain may run.
     *
     * Summarising is five queued steps rather than one job, so this is what the worker enforces
     * and what the supervisor and retry_after have to stay above. The whole attempt is still
     * seconds() below, but nothing measures a single job against it any more.
     *
     * The worst step is whichever is larger of a pair of transcript calls - asking yt-dlp, then
     * fetching the track it names, which happen in one step - and one model pass, which is what
     * each of the three summarising steps costs. The minute on the end covers the reads and
     * writes around whichever it turns out to be.
     */
    public static function stepSeconds(mixed $model, mixed $transcript): int
    {
        return max(
            2 * self::transcriptSeconds($transcript),
            self::modelSeconds($model),
        ) + 60;
    }

    /**
     * The whole attempt's budget: whichever is larger of what was asked for and what the steps
     * inside it are between them allowed to take.
     *
     * The worst case is one video: two transcript steps (asking yt-dlp, then fetching the track
     * it names) and three prompts (the ideas, the summary, and translating it). The minute on
     * the end covers the lookup and the writes around the work.
     *
     * Derived this way round rather than capping the steps to fit, so raising a step budget
     * raises this instead of being silently reduced - somebody asking for a ten minute model
     * budget should not quietly get eight. SUMMARY_TIMEOUT is therefore a floor, not the value.
     */
    public static function seconds(mixed $model, mixed $transcript, mixed $timeout): int
    {
        $steps = (2 * self::transcriptSeconds($transcript))
            + (3 * self::modelSeconds($model))
            + 60;

        return max($steps, max(self::TIMEOUT_FLOOR, (int) ($timeout ?? self::TIMEOUT_DEFAULT)));
    }
}
