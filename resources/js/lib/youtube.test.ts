import { describe, expect, it } from 'vitest';
import { extractVideoId, watchUrl } from './youtube';

/*
 * This function alone decides which video the backend is asked about. The server checks
 * the shape of what it receives, but it cannot catch a mis-extraction: eleven characters
 * taken from the wrong part of a url are still eleven valid characters. So the patterns
 * are worth pinning one by one.
 */
const ID = 'dQw4w9WgXcQ';

describe('extractVideoId', () => {
    it.each([
        ['a watch url', `https://www.youtube.com/watch?v=${ID}`],
        ['no scheme', `youtube.com/watch?v=${ID}`],
        ['the mobile host', `https://m.youtube.com/watch?v=${ID}`],
        ['the no-cookie host', `https://www.youtube-nocookie.com/embed/${ID}`],
        ['a trailing timestamp', `https://www.youtube.com/watch?v=${ID}&t=42s`],
        [
            'v after another parameter',
            `https://www.youtube.com/watch?app=desktop&v=${ID}`,
        ],
        ['a short url', `https://youtu.be/${ID}`],
        [
            'a short url with a tracking parameter',
            `https://youtu.be/${ID}?si=AbCdEf`,
        ],
        ['a short', `https://www.youtube.com/shorts/${ID}`],
        ['an embed', `https://www.youtube.com/embed/${ID}`],
        ['a live url', `https://www.youtube.com/live/${ID}`],
        ['the old /v/ form', `https://www.youtube.com/v/${ID}`],
        ['a bare id', ID],
        ['surrounding whitespace', `  https://youtu.be/${ID}  `],
        ['a bare id with whitespace', `\t${ID}\n`],
    ])('takes the id from %s', (_label, input) => {
        expect(extractVideoId(input)).toBe(ID);
    });

    it.each([
        ['empty', ''],
        ['whitespace only', '   '],
        ['a different site', 'https://vimeo.com/123456789'],
        ['a channel url', 'https://www.youtube.com/@someone'],
        [
            'a playlist with no video',
            'https://www.youtube.com/playlist?list=PL1234567890a',
        ],
        ['ten characters', 'dQw4w9WgXc'],
        ['a word', 'summarise'],
    ])('returns null for %s', (_label, input) => {
        expect(extractVideoId(input)).toBeNull();
    });

    /*
     * The boundary check earns its keep here. Without it each of these would match its
     * first eleven characters and confidently summarise a video nobody asked for, which
     * is worse than refusing.
     */
    it.each([
        [
            'a twelve character v parameter',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQQ',
        ],
        ['a twelve character short url', 'https://youtu.be/dQw4w9WgXcQQ'],
        ['a twelve character bare id', 'dQw4w9WgXcQQ'],
    ])('refuses to truncate %s', (_label, input) => {
        expect(extractVideoId(input)).toBeNull();
    });

    /*
     * These sit exactly where an id sits and are exactly eleven legal characters, so no
     * amount of pattern anchoring rejects them and the server cannot either. Nothing but
     * knowing they are words catches them.
     */
    it.each([
        [
            'a playlist embed',
            'https://www.youtube.com/embed/videoseries?list=PLabcdefghij',
        ],
        [
            'a channel live embed',
            'https://www.youtube.com/embed/live_stream?channel=UCabcdefghijkl',
        ],
    ])('refuses %s, which is a word where an id goes', (_label, input) => {
        expect(extractVideoId(input)).toBeNull();
    });

    /*
     * But only where the word is all there is: a real id elsewhere in the same url still
     * wins, because the v parameter is looked at first.
     */
    it('still finds a real id alongside one of those words', () => {
        expect(
            extractVideoId(
                `https://www.youtube.com/embed/videoseries?list=PLabcdefghij&v=${ID}`,
            ),
        ).toBe(ID);
    });

    it('keeps the id exactly, including its case and punctuation', () => {
        expect(extractVideoId('https://youtu.be/aB3-_dEfGh1')).toBe(
            'aB3-_dEfGh1',
        );
    });
});

describe('watchUrl', () => {
    it('builds the canonical watch url for an id', () => {
        expect(watchUrl(ID)).toBe(`https://youtube.com/watch?v=${ID}`);
    });

    /*
     * The two halves of this file are each other's inverse for anything the page renders a
     * link for, so a change to either that broke that pairing would be worth knowing about.
     */
    it('produces something extractVideoId reads back as the same id', () => {
        expect(extractVideoId(watchUrl(ID))).toBe(ID);
    });

    it('does not mangle the punctuation in an id', () => {
        expect(watchUrl('aB3-_dEfGh1')).toBe(
            'https://youtube.com/watch?v=aB3-_dEfGh1',
        );
    });
});
