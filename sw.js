const CACHE = 'giglink-static-v3';
const OFFLINE = '/giglink/offline.php';
const ASSETS = new Set([
    OFFLINE,
    '/giglink/assets/style.css',
    '/giglink/assets/workflows.js',
    '/giglink/assets/logo.svg',
    '/giglink/assets/logo.png',
    '/giglink/assets/icon-maskable.svg',
    '/giglink/assets/icon-192.svg',
    '/giglink/assets/icon-512.svg',
    '/giglink/manifest.json',
]);

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        for (const path of ASSETS) {
            const request = new Request(new URL(path, self.location.origin), {
                credentials: 'omit', cache: 'reload', redirect: 'error',
            });
            const response = await fetch(request);
            if (!response.ok || /private|no-store/i.test(response.headers.get('Cache-Control') || '')
                || /cookie|authorization/i.test(response.headers.get('Vary') || '')) continue;
            await cache.put(request, response);
        }
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (key.startsWith('giglink-') && key !== CACHE) await caches.delete(key);
        }
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin
        || !url.pathname.startsWith('/giglink/')) return;
    if (ASSETS.has(url.pathname) && !url.search) {
        event.respondWith(caches.open(CACHE).then(async (cache) =>
            (await cache.match(request)) || fetch(request, { credentials: 'omit' })));
    } else if (request.mode === 'navigate') {
        event.respondWith(fetch(request, { cache: 'no-store' }).catch(async () =>
            (await (await caches.open(CACHE)).match(OFFLINE)) ||
                new Response('GigLink is offline. Reconnect and try again.', { status: 503 })));
    } else {
        event.respondWith(fetch(request, { cache: 'no-store' }));
    }
});
