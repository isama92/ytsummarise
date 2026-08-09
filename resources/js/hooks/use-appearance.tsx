import { useSyncExternalStore } from 'react';

export type Appearance = 'light' | 'dark';

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly updateAppearance: (mode: Appearance) => void;
    readonly toggleAppearance: () => void;
};

const listeners = new Set<() => void>();
let currentAppearance: Appearance = 'light';

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

/**
 * Only an explicit choice is stored, so a visitor who has never touched the toggle
 * keeps following their operating system rather than being pinned to a default.
 */
const getStoredAppearance = (): Appearance | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    const stored = localStorage.getItem('appearance');

    return stored === 'light' || stored === 'dark' ? stored : null;
};

const applyTheme = (appearance: Appearance): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const isDark = appearance === 'dark';

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    currentAppearance =
        getStoredAppearance() ?? (prefersDark() ? 'dark' : 'light');

    applyTheme(currentAppearance);
}

export function useAppearance(): UseAppearanceReturn {
    const appearance: Appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => 'light' as Appearance,
    );

    const updateAppearance = (mode: Appearance): void => {
        currentAppearance = mode;

        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', mode);

        // Store in cookie so the server can paint the first frame correctly...
        setCookie('appearance', mode);

        applyTheme(mode);
        notify();
    };

    const toggleAppearance = (): void => {
        updateAppearance(appearance === 'dark' ? 'light' : 'dark');
    };

    return { appearance, updateAppearance, toggleAppearance } as const;
}
