import { describe, expect, it } from 'vitest';
import type { Translations } from '@/types/lang';
import { translate } from './lang';

const LANG: Translations = {
    app: {
        logout: 'Log out',
    },
    summaries: {
        page_title: 'Home',
        stage: {
            queued: 'Queued',
        },
        errors: {
            not_found: 'That video does not exist, or it is private.',
        },
        greeting: 'Hello :name, you have :count waiting',
    },
};

describe('translate', () => {
    it.each([
        ['a group and a key', 'app.logout', 'Log out'],
        ['a key two levels down', 'summaries.stage.queued', 'Queued'],
        [
            'a key three levels down',
            'summaries.errors.not_found',
            'That video does not exist, or it is private.',
        ],
    ])('finds a string by %s', (_label, key, expected) => {
        expect(translate(LANG, key)).toBe(expected);
    });

    it('fills in every placeholder it is given', () => {
        expect(
            translate(LANG, 'summaries.greeting', { name: 'Sam', count: 3 }),
        ).toBe('Hello Sam, you have 3 waiting');
    });

    it('leaves a placeholder alone when nothing was given for it', () => {
        expect(translate(LANG, 'summaries.greeting', { name: 'Sam' })).toBe(
            'Hello Sam, you have :count waiting',
        );
    });

    /*
     * The same thing Laravel's own __() does, and deliberate: a gap this way shows up on the
     * page as the key that is missing rather than as an empty space nobody notices until it is
     * reported as a blank paragraph.
     */
    it.each([
        ['a group that was never shared', 'auth.failed'],
        ['a key that is not in its group', 'app.signout'],
        ['a key below a string', 'app.logout.deeper'],
        ['a key that lands on a group rather than a string', 'summaries.stage'],
        ['nothing at all', ''],
    ])('hands back the key itself for %s', (_label, key) => {
        expect(translate(LANG, key)).toBe(key);
    });
});
