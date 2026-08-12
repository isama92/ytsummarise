/**
 * How long since a moment, as a clock.
 *
 * Hours appear only once there are any, so an ordinary wait reads `0:42` rather than
 * `0:00:42`. They have to appear at all because a wait here is not bounded by the job's own
 * timeout: a job waits its turn behind every job ahead of it, and the command that gives up
 * on abandoned ones runs hourly. Without the rollover a long wait rendered as `10081:31`,
 * which is not a time.
 *
 * Negative input clamps to zero. A clock in the browser can sit a little behind the server
 * that stamped the row, and "asked for in half a second" is worse than "just now".
 */
export function elapsedSince(since: string, now: number): string {
    const total = Math.max(0, Math.floor((now - Date.parse(since)) / 1000));

    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;

    const padded = String(seconds).padStart(2, '0');

    if (hours === 0) {
        return `${minutes}:${padded}`;
    }

    return `${hours}:${String(minutes).padStart(2, '0')}:${padded}`;
}
