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
