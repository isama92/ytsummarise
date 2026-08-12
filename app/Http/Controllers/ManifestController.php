<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ManifestController extends Controller
{
    /**
     * The web app manifest, which is what makes this installable.
     *
     * A route rather than a file in public/ so the name and the start url come from
     * config instead of a second copy that drifts from it.
     *
     * name, short_name, icons at both 192 and 512, start_url and display are what
     * Chromium documents as required; the rest only improves the install sheet.
     */
    public function __invoke(): JsonResponse
    {
        $name = config()->string('app.name');

        return response()
            ->json([
                'name' => $name,
                'short_name' => $name,
                'description' => 'Paste a YouTube link and get a short summary of the video.',

                /*
                 * Signed out this lands on /login and signed in it lands on the
                 * summariser, which is the same thing the icon in a browser tab does.
                 */
                'start_url' => '/',
                'scope' => '/',

                'display' => 'standalone',
                'orientation' => 'any',

                /*
                 * A manifest gets one colour and cannot follow the flavour, so both are
                 * Catppuccin Latte `base`: the light theme is what a visitor who has
                 * never touched the toggle sees. background_color is the splash screen
                 * while the application boots, so matching the first paint in
                 * app.blade.php is what stops that flashing.
                 */
                'theme_color' => '#eff1f5',
                'background_color' => '#eff1f5',

                /*
                 * The maskable entry is the one Android crops to its own shape; see the
                 * comment in public/favicon.svg for why it is drawn differently.
                 */
                'icons' => [
                    [
                        'src' => '/icon-192.png',
                        'sizes' => '192x192',
                        'type' => 'image/png',
                        'purpose' => 'any',
                    ],
                    [
                        'src' => '/icon-512.png',
                        'sizes' => '512x512',
                        'type' => 'image/png',
                        'purpose' => 'any',
                    ],
                    [
                        'src' => '/icon-maskable-512.png',
                        'sizes' => '512x512',
                        'type' => 'image/png',
                        'purpose' => 'maskable',
                    ],
                ],
            ])
            /*
             * Not application/json. Browsers accept both, but the registered type is what
             * says this is a manifest rather than an api response.
             */
            ->header('Content-Type', 'application/manifest+json');
    }
}
