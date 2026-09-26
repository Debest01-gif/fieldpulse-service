/**
 * FieldPulse Kenya — Service Worker
 * Enables offline caching & PWA install capabilities
 */

const CACHE_NAME = 'fieldpulse-v2';
const OFFLINE_URL = '/login.php';

// Static assets to pre-cache
const PRECACHE_ASSETS = [
    '/login.php',
    '/assets/css/style.css',
    '/assets/icons/icon-192.png',
    '/assets/icons/icon-512.png',
    'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap',
    'https://unpkg.com/lucide@latest'
];

// Install: Pre-cache key assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS).catch(() => {});
        }).then(() => self.skipWaiting())
    );
});

// Activate: Clean up old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch: Network-first for PHP pages, cache-first for assets
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Skip non-GET and POST requests (forms)
    if (event.request.method !== 'GET') return;

    // PHP pages: network-first with offline fallback
    if (url.pathname.endsWith('.php') || url.pathname === '/') {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    // Cache successful responses
                    if (response && response.status === 200) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                    }
                    return response;
                })
                .catch(() => {
                    // Offline: serve cached version or login page
                    return caches.match(event.request)
                        || caches.match(OFFLINE_URL)
                        || new Response('<h1 style="font-family:sans-serif;text-align:center;margin-top:80px;">You are offline. Please reconnect to continue.</h1>', {
                            headers: { 'Content-Type': 'text/html' }
                        });
                })
        );
        return;
    }

    // Static assets: cache-first
    event.respondWith(
        caches.match(event.request).then((cached) => {
            return cached || fetch(event.request).then((response) => {
                if (response && response.status === 200) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                }
                return response;
            });
        })
    );
});
