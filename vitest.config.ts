import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/*
 * Separate from vite.config.ts deliberately, and vitest reads this file in preference to
 * it.
 *
 * Vitest starts Vite in serve mode, and laravel-vite-plugin refuses to run in serve mode
 * whenever CI is set: "You should not run the Vite HMR server in CI environments". So
 * loading the application's own config here fails every run on CI, which is exactly what
 * happened when the vitest run joined ci:check.
 *
 * None of those plugins have anything to do with the tests in any case. There is no Blade
 * to inject tags into, no wayfinder functions to generate, no fonts to fetch and no
 * Tailwind to compile - only TypeScript to import.
 */
export default defineConfig({
    resolve: {
        /*
         * The same mapping as tsconfig.json, so a test can import through @/ the way the
         * application does rather than counting ../ up from wherever it sits.
         */
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.{ts,tsx}'],

        /*
         * Node, because nothing here touches the DOM. A test that renders a component
         * needs an environment of jsdom or happy-dom, and the react plugin, adding here.
         */
        environment: 'node',
    },
});
