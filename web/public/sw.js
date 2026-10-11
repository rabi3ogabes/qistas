// The web app's service worker (Win Plan PL04), registered for /app only. It keeps the app's own files so the app opens
// fast, and an offline page for when there is no connection. It never keeps a page or an answer from the server:
// business data is always fetched fresh, and nothing about a business is left on the device.

const VERSION = 'qistas-shell-1';
const KEEP = ['/build/', '/brand/'];
const OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll([OFFLINE, '/brand/app-icon-192.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(names.filter((name) => name !== VERSION).map((name) => caches.delete(name))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // A page: always from the network; without one, the offline page. Pages are never kept.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
        return;
    }

    // The app's own files: built files have their content's hash in the name, so a kept copy is always right.
    if (KEEP.some((prefix) => url.pathname.startsWith(prefix))) {
        event.respondWith(
            caches.open(VERSION).then((cache) => cache.match(request).then((kept) => kept || fetch(request).then((response) => {
                if (response.ok) cache.put(request, response.clone());

                return response;
            }))),
        );
    }
    // Everything else goes to the network as if there were no service worker.
});
