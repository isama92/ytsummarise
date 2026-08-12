import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import { translate } from '@/lib/lang';

export type Translate = (
    key: string,
    replacements?: Record<string, string | number>,
) => string;

/**
 * The application's own `t()`, over the translations shared with every response.
 *
 * A hook rather than a module-level function because the strings arrive as a page prop, and
 * reading them through usePage is what keeps a component honest about where they came from.
 * Memoised on that prop so a page holding `t` in a dependency array does not re-run on every
 * render.
 */
export function useTranslate(): Translate {
    const { lang } = usePage().props;

    return useCallback<Translate>(
        (key, replacements) => translate(lang, key, replacements),
        [lang],
    );
}
