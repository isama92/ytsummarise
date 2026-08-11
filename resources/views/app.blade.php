<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? null) === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- No cookie means the visitor has never picked a theme, so follow the system --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "" }}';

                if (! appearance && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        {{-- Paints the page before app.css is fetched, so there is no flash. These are
             Catppuccin Latte `base` and Frappe `base` and MUST match --ctp-base in
             resources/css/app.css; AppearanceTest guards that they still do.

             color-scheme is set here too because initializeTheme() only runs once the
             bundle has parsed, so without it the native scrollbars and form control
             chrome render light on a dark page until hydration. --}}
        <style>
            html {
                background-color: #eff1f5;
                color-scheme: light;
            }

            html.dark {
                background-color: #303446;
                color-scheme: dark;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Reachable signed in and signed out both; see the comment on the route. --}}
        <link rel="manifest" href="{{ route('manifest') }}">

        {{-- The browser chrome around the page, and the status bar once installed. The
             same two Catppuccin `base` values as the first paint above, so these are a
             third and fourth copy of them and AppearanceTest guards all four.

             These follow the system where the rest of the theme follows a cookie, so
             somebody who picked dark on a light system gets a light bar around a dark
             page. Deliberate: a single meta could be rendered from the cookie, but it
             would then be wrong for everyone who has never touched the toggle, which is
             most people. --}}
        <meta name="theme-color" content="#eff1f5" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#303446" media="(prefers-color-scheme: dark)">

        {{-- The standard spelling, which Chrome asks for by name: it logs a deprecation
             warning for the apple-prefixed one below unless this is here too. --}}
        <meta name="mobile-web-app-capable" content="yes">

        {{-- iOS read these long before it read manifests, and still honours them on the
             versions that do. The capable one is only load bearing below 16.4, where the
             manifest's display mode is not read at all; kept for those. `default` keeps
             the content below the status bar rather than sliding underneath it. --}}
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Laravel') }}">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
