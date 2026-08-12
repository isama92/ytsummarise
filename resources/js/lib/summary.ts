import type { Summary } from '@/types';

/**
 * Where a summary has got to, as the key of a word for it.
 *
 * Queued and processing are both `pending` on the server, which is right - they are the same
 * state of the same row - so the difference is whether a worker has claimed it, and saying so
 * is what explains a long wait when the queue is backed up.
 *
 * A key rather than the word itself, so the wording stays in lang/en/summaries.php with every
 * other string. See .ai/rules/i18n.md.
 */
export function stageKeyOf(
    summary: Summary,
    justFinished: boolean,
): string | null {
    /*
     * Ready is an announcement, not a label, so it is only worth making to whoever watched the
     * wait. Somebody opening a finished summary can see that it is finished, and a page that
     * says so anyway is captioning the obvious.
     */
    if (summary.status === 'ready') {
        return justFinished ? 'summaries.stage.ready' : null;
    }

    /*
     * Nothing for a failure. The message below it already says what went wrong and what to do
     * about it, and a one word label above that only says it twice.
     */
    if (summary.status === 'failed') {
        return null;
    }

    return summary.startedAt === null
        ? 'summaries.stage.queued'
        : 'summaries.stage.processing';
}

/**
 * Why a failed attempt failed, as the key of a sentence explaining it.
 *
 * Falls back to `unknown` for a row that failed without recording a reason, which is what
 * every row that failed before the column existed looks like.
 */
export function errorKeyOf(summary: Summary): string {
    return `summaries.errors.${summary.error ?? 'unknown'}`;
}
