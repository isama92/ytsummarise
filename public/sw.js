/*
 * The service worker.
 *
 * Every screen in this application needs the server, so this is not here to work
 * offline. It is here for two narrower reasons: Chrome still requires a fetch handler
 * before it will offer the install prompt, and an installed application that shows the
 * browser's own network error does not feel installed.
 *
 * What it deliberately does not do is cache anything about anybody. A summary sitting in
 * the Cache API is readable by whoever has the device, so pages and XHR responses are
 * never written here - only files that are the same for every visitor.
 *
 * Not linted or formatted with the rest of the frontend: eslint and prettier are both
 * scoped away from public/, because this runs in a worker rather than in the bundle.
 */

/* Bump to evict everything: old caches are dropped on activate. */
const VERSION = 'v1';

const SHELL = `shell-${VERSION}`;
const ASSETS = `assets-${VERSION}`;

const OFFLINE = '/offline.html';

/*
 * The offline page and the one image it uses. Reloaded rather than taken from the HTTP
 * cache, so a worker installing after a deploy does not store a stale copy.
 */
const SHELL_FILES = [OFFLINE, '/favicon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL)
            .then((cache) =>
                cache.addAll(
                    SHELL_FILES.map(
                        (file) => new Request(file, { cache: 'reload' }),
                    ),
                ),
            )
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((names) =>
                Promise.all(
                    names
                        .filter((name) => name !== SHELL && name !== ASSETS)
                        .map((name) => caches.delete(name)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    /*
     * Anything that is not a plain same-origin read is left entirely alone. That rules
     * out every POST, so submitting a video and signing out can never be served from or
     * written to a cache.
     */
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    /*
     * Vite content-hashes these filenames, which is what makes cache-first safe: a new
     * build is a new url, so a cached one can never be stale. It is also why there is no
     * list of files to precache and no build step generating one. The self-hosted
     * Instrument Sans files live here too.
     */
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(cacheFirst(request));

        return;
    }

    /*
     * Network first so a changed favicon is never stale, cache second so the offline page
     * can actually draw its logo. Without this branch the image request falls through to
     * the network-only case below and the offline page renders with a broken image - the
     * one situation it exists for.
     */
    if (SHELL_FILES.includes(url.pathname)) {
        event.respondWith(networkThenCache(request));

        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkThenOffline(request));

        return;
    }

    /*
     * Everything left is answered by the browser as though this worker did not exist,
     * which is deliberate rather than lazy: not calling respondWith avoids the round trip
     * through here, and it is the branch every Inertia request takes, so no page and no
     * shared prop is ever written to a cache.
     */
});

async function cacheFirst(request) {
    const cache = await caches.open(ASSETS);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        await cache.put(request, response.clone());
    }

    return response;
}

async function networkThenCache(request) {
    const cache = await caches.open(SHELL);

    try {
        const response = await fetch(request);

        if (response.ok) {
            await cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await cache.match(request);

        if (cached) {
            return cached;
        }

        throw error;
    }
}

async function networkThenOffline(request) {
    try {
        return await fetch(request);
    } catch {
        const cache = await caches.open(SHELL);
        const offline = await cache.match(OFFLINE);

        /*
         * Response.error() only if the worker somehow has no offline page, in which case
         * the browser shows what it would have shown anyway.
         */
        return offline ?? Response.error();
    }
}
