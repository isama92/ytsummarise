import { createInertiaApp } from '@inertiajs/react';
import { initializeTheme } from '@/hooks/use-appearance';
import AuthLayout from '@/layouts/auth-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => (name.startsWith('auth/') ? AuthLayout : null),
    strictMode: true,
    progress: {
        // Catppuccin Latte rosewater. A static string set once at boot, so it cannot
        // follow the flavour; this one mid-tone reads against both `base` values.
        color: '#dc8a78',
    },
});

// This will set light / dark mode on load...
initializeTheme();

/*
 * Production only, and not out of caution: a worker sitting in front of the Vite dev
 * server intercepts its requests and breaks hot reloading. It also has nothing to do
 * there, since the assets it caches only exist in a build.
 *
 * After load rather than immediately, so registering never competes with the first paint.
 */
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        void navigator.serviceWorker.register('/sw.js');
    });
}
