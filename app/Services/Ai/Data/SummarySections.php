<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

use Spatie\LaravelData\Data;
use UnexpectedValueException;

/**
 * One language's worth of summary: the whole thing in a sentence, then the detail.
 *
 * Three parts rather than a block of prose, because they are read differently. The headline is
 * for deciding whether to read the rest, the points are the video in order, and the takeaways
 * are what somebody would repeat afterwards. The page lays each out in its own way, which it
 * could not do with paragraphs.
 */
final class SummarySections extends Data
{
    /**
     * @param  array<int, string>  $points
     * @param  array<int, string>  $takeaways
     */
    public function __construct(
        public string $headline,
        public array $points,
        public array $takeaways,
    ) {}

    /**
     * One of these out of whatever a model actually returned.
     *
     * Named parse() rather than fromModel(), which is the obvious name and a trap. laravel-data
     * registers every public static method beginning with "from" as a magic creation method, so
     * a fromModel(array) becomes what SummarySections::from(array) quietly resolves to - and
     * hydrating a stored outline would then run it through the tolerance below and throw on one
     * whose headline had gone missing, which is the opposite of what reading a row should do.
     * Verified rather than assumed: under that name,
     * SummarySections::from(['headline' => '', ...]) threw out of here.
     *
     * Defensive on purpose, and not because the schema is optional. The structured output
     * contract is declared and hosted providers honour it, but this application is pointed at
     * whatever endpoint AI_PROVIDER names - which may be a local model behind a gateway that
     * passes the schema through loosely or not at all. Treating the response as json somebody
     * else wrote is the same stance LookupResult takes towards YouTube's.
     *
     * The lists are filtered rather than validated: a model that returns nine points instead of
     * ten has still done the job, and one that puts a null in the array has not made the other
     * nine worthless. A missing headline is different - a summary whose one-line version is
     * absent is not a thin summary, it is a failed one - so that throws, the job's failure
     * handler records it, and the page offers to try again.
     *
     * @param  array<array-key, mixed>  $response
     */
    public static function parse(array $response): self
    {
        $headline = $response['headline'] ?? null;
        $headline = is_string($headline) ? trim($headline) : '';

        if ($headline === '') {
            throw new UnexpectedValueException('The model returned a summary with no headline.');
        }

        return new self(
            $headline,
            self::lines($response['points'] ?? null),
            self::lines($response['takeaways'] ?? null),
        );
    }

    /**
     * The usable strings out of something that should have been a list of them.
     *
     * array_values because the filtering leaves holes, and a list with holes encodes as a json
     * object rather than an array - which would reach the page as something it cannot map over.
     *
     * @return array<int, string>
     */
    private static function lines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        $trimmed = array_map(
            fn (mixed $line): string => is_string($line) ? trim($line) : '',
            $lines,
        );

        return array_values(array_filter($trimmed, fn (string $line): bool => $line !== ''));
    }
}
