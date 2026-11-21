/*
 * RuteStrip service worker: makes the site installable and shows an offline
 * page when the network is down. It is deliberately conservative:
 * - HTML pages are always fetched from the network and never stored, so a
 *   logged-in (or logged-out) page can never be served stale;
 * - only GET requests for a fixed list of static files on this origin are cached;
 * - POST, auth, API, admin and cross-origin requests are not touched at all.
 * Bump VERSION when the cached files change; older caches are deleted on activate.
 */
const VERSION = 'rutestrip-v2';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [
    OFFLINE_URL,
    '/images/logo.svg',
    '/images/favicon.svg',
    '/images/icons/icon-192.png',
    '/images/icons/icon-512.png',
    '/manifest.webmanifest',
];
const STATIC_PATH = /^\/images\/(logo\.svg|favicon\.svg|icons\/[\w.-]+\.png)$/;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Pages: network only; the offline page is the fallback when there is no connection.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    // Icons and logo: cache first (versioned above).
    if (STATIC_PATH.test(url.pathname)) {
        event.respondWith(caches.match(request).then((hit) => hit || fetch(request)));
    }
    // Everything else (API, conditions JSON, auth, uploads, CSS/JS/fonts): browser default.
});
