import type { Translations } from '@/types/lang';

/**
 * The string at a dotted key, with any `:placeholder` filled in.
 *
 * Returns the key itself when there is no string there, which is what Laravel's own `__()`
 * does and is deliberate: a missing translation shows up on the page as
 * `summaries.errors.not_found` rather than as a blank space, so it is noticed while somebody is
 * looking at it rather than reported later as an empty paragraph.
 *
 * Nothing here throws on a key that walks into a group instead of a string. `summaries.errors`
 * is an object, not a sentence, and asking for it is a mistake worth showing rather than
 * crashing the page over.
 */
export function translate(
    translations: Translations,
    key: string,
    replacements: Record<string, string | number> = {},
): string {
    let line: string | Translations | undefined = translations;

    for (const segment of key.split('.')) {
        if (line === undefined || typeof line === 'string') {
            return key;
        }

        line = line[segment];
    }

    if (typeof line !== 'string') {
        return key;
    }

    return Object.entries(replacements).reduce(
        (filled, [name, value]) => filled.replaceAll(`:${name}`, String(value)),
        line,
    );
}
