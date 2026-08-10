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
