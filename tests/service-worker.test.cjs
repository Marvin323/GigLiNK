const { beforeEach, test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');
const path = require('node:path');

const origin = 'https://giglink.example';
const current = 'giglink-static-v3';
let handlers, stores, offline, requests;
const key = (request) => new URL(request.url ?? request, origin).href;
const scope = (name) => ({
    put: async (request, response) => stores.get(name).set(key(request), response.clone()),
    match: async (request) => stores.get(name).get(key(request))?.clone(),
});
const caches = {
    open: async (name) => {
        if (!stores.has(name)) stores.set(name, new Map());
        return scope(name);
    },
    keys: async () => [...stores.keys()],
    delete: async (name) => stores.delete(name),
};

function lifecycle(name) {
    return new Promise((resolve) => handlers[name]({ waitUntil: resolve }));
}

function request(pathname, mode = 'navigate') {
    return new Promise((resolve) => handlers.fetch({
        request: { url: origin + '/giglink/' + pathname, method: 'GET', mode },
        respondWith: resolve,
    }));
}

beforeEach(async () => {
    handlers = {};
    stores = new Map();
    offline = false;
    requests = [];
    runInNewContext(readFileSync(path.join(__dirname, '../sw.js'), 'utf8'), {
        Request, Response, URL, Set, caches,
        fetch: async (req, options) => {
            requests.push([req, options]);
            if (offline) throw new Error('offline');
            if (req.url.endsWith('/offline.php')) return new Response('Offline fallback');
            if (req.url.endsWith('/manifest.json')) return new Response('Session content', {
                headers: { 'Cache-Control': 'private, no-store' },
            });
            return new Response(req.url.endsWith('.css') ? 'static CSS' : 'Online content');
        },
        self: {
            location: { origin },
            addEventListener: (type, callback) => { handlers[type] = callback; },
            skipWaiting: async () => {},
            clients: { claim: async () => {} },
        },
    });
    await lifecycle('install');
});

test('precache uses anonymous requests and rejects private responses', async () => {
    assert.ok(requests.every(([req]) => req.credentials === 'omit' && req.redirect === 'error'));
    assert.equal(await scope(current).match('/giglink/manifest.json'), undefined);
});

test('static stylesheet remains available offline', async () => {
    offline = true;
    assert.equal(await (await request('assets/style.css', 'cors')).text(), 'static CSS');
});

test('private pages and API responses are never saved', async () => {
    for (const url of ['youth/dashboard.php', 'employer/applicants.php?job_id=1', 'messages.php',
        'admin/index.php']) {
        assert.equal(await (await request(url)).text(), 'Online content');
        assert.equal(requests.at(-1)[1].cache, 'no-store');
        assert.equal(await scope(current).match('/giglink/' + url), undefined);
    }
    await request('api/me.php', 'cors');
    assert.equal(requests.at(-1)[1].cache, 'no-store');
    assert.equal(await scope(current).match('/giglink/api/me.php'), undefined);
});

test('activation removes old GigLink caches and preserves unrelated caches', async () => {
    await caches.open('giglink-pages-v2');
    await caches.open('giglink-static-v2');
    await caches.open('unrelated-cache');
    await lifecycle('activate');
    assert.deepEqual(await caches.keys(), [current, 'unrelated-cache']);
});

test('offline navigation after account switching cannot read old dashboard', async () => {
    const old = await caches.open('giglink-pages-v2');
    await old.put('/giglink/youth/dashboard.php', new Response('Other account data'));
    await request('youth/dashboard.php');
    await lifecycle('activate');
    offline = true;
    assert.equal(await (await request('youth/dashboard.php')).text(), 'Offline fallback');
    await assert.rejects(request('api/me.php', 'cors'), /offline/);
});

