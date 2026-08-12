import { describe, expect, it } from 'vitest';
import { elapsedSince } from './elapsed';

const START = '2026-08-12T09:00:00+00:00';
const at = (seconds: number): number => Date.parse(START) + seconds * 1000;

describe('elapsedSince', () => {
    it.each([
        ['the moment it was asked for', 0, '0:00'],
        ['a second', 1, '0:01'],
        ['under a minute', 42, '0:42'],
        ['exactly a minute', 60, '1:00'],
        ['minutes and seconds', 3 * 60 + 7, '3:07'],
        ['a minute short of an hour', 59 * 60 + 59, '59:59'],
    ])('shows %s without an hours part', (_label, seconds, expected) => {
        expect(elapsedSince(START, at(seconds))).toBe(expected);
    });

    /*
     * A wait here is not bounded by the job's own timeout: a job waits behind every job
     * ahead of it, and the command that gives up on abandoned ones runs hourly. Without the
     * rollover a week-old row rendered as 10081:31.
     */
    it.each([
        ['exactly an hour', 3600, '1:00:00'],
        ['an hour and change', 3600 + 8 * 60 + 5, '1:08:05'],
        ['most of a day', 23 * 3600 + 59 * 60 + 59, '23:59:59'],
        ['a week', 7 * 24 * 3600, '168:00:00'],
    ])('rolls over into hours for %s', (_label, seconds, expected) => {
        expect(elapsedSince(START, at(seconds))).toBe(expected);
    });

    /*
     * The browser's clock can sit behind the server that stamped the row, and "asked for in
     * half a second" is worse than "just now".
     */
    it('clamps a clock that is behind the server', () => {
        expect(elapsedSince(START, at(-30))).toBe('0:00');
    });

    it('counts whole seconds only, so it never shows a fraction', () => {
        expect(elapsedSince(START, at(1) + 999)).toBe('0:01');
    });
});
