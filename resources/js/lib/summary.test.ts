import { describe, expect, it } from 'vitest';
import type { Summary, SummaryError, SummaryStatus } from '@/types/summary';
import { errorKeyOf, stageKeyOf } from './summary';

const summary = (attributes: Partial<Summary> = {}): Summary => ({
    status: 'pending',
    title: null,
    body: null,
    error: null,
    requestedAt: '2026-08-12T09:00:00+00:00',
    startedAt: null,
    ...attributes,
});

describe('stageKeyOf', () => {
    /*
     * Queued and processing are one status on the server, so whether a worker has claimed the
     * row is the only thing that tells them apart - and it is what explains a long wait when
     * the queue is backed up.
     */
    it('tells waiting in the queue apart from being worked on', () => {
        expect(stageKeyOf(summary(), false)).toBe('summaries.stage.queued');

        expect(
            stageKeyOf(
                summary({ startedAt: '2026-08-12T09:00:04+00:00' }),
                false,
            ),
        ).toBe('summaries.stage.processing');
    });

    /*
     * Ready is worth saying to whoever watched the wait and to nobody else. Opening a summary
     * that was already there is not an event, and captioning it "Ready" says nothing the
     * summary underneath does not.
     */
    it('announces a summary that finished in front of somebody', () => {
        expect(stageKeyOf(summary({ status: 'ready' }), true)).toBe(
            'summaries.stage.ready',
        );
    });

    it('says nothing about a summary that was already there', () => {
        expect(stageKeyOf(summary({ status: 'ready' }), false)).toBeNull();
    });

    /*
     * Nothing for a failure: the reason shown below it already says what went wrong and what to
     * do about it, and a one word label above that only says it twice.
     */
    it('has nothing to say about a failure', () => {
        expect(stageKeyOf(summary({ status: 'failed' }), false)).toBeNull();
        expect(stageKeyOf(summary({ status: 'failed' }), true)).toBeNull();
    });
});

describe('errorKeyOf', () => {
    it.each<SummaryError>(['not_found', 'unreachable', 'timed_out', 'unknown'])(
        'turns %s into the key of a sentence',
        (error) => {
            expect(errorKeyOf(summary({ status: 'failed', error }))).toBe(
                `summaries.errors.${error}`,
            );
        },
    );

    /*
     * Which is what every row that failed before the column existed looks like, and what a
     * failure recorded by something that did not know a reason would look like.
     */
    it('falls back to unknown for a failure with no reason recorded', () => {
        expect(errorKeyOf(summary({ status: 'failed' }))).toBe(
            'summaries.errors.unknown',
        );
    });

    it.each<SummaryStatus>(['pending', 'ready'])(
        'is not asked about a %s summary, but answers safely if it is',
        (status) => {
            expect(errorKeyOf(summary({ status }))).toBe(
                'summaries.errors.unknown',
            );
        },
    );
});
