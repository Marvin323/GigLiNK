const { spawn, execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const { createHmac } = require('node:crypto');
const { mkdtemp, readFile } = require('node:fs/promises');
const { tmpdir } = require('node:os');
const path = require('node:path');

const base = process.env.GIGLINK_TEST_URL || 'http://127.0.0.1:33719/giglink';
const edge = process.env.GIGLINK_TEST_EDGE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function until(check, label) {
    for (let i = 0; i < 100; ++i) {
        const result = await check();
        if (result) return result;
        await sleep(100);
    }
    throw new Error('Timed out waiting for ' + label);
}

async function main() {
    const profile = await mkdtemp(path.join(tmpdir(), 'giglink-edge-'));
    const child = spawn(edge, ['--headless=new', '--disable-gpu', '--no-first-run',
        '--disable-background-networking', '--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1',
        '--user-data-dir=' + profile, 'about:blank'], { stdio: 'ignore', windowsHide: true });
    let ws;
    let failures = 0;
    let passed = 0;
    try {
        const port = await until(async () => {
            try {
                return (await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0];
            } catch { return null; }
        }, 'Edge DevTools');
        const targets = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
        ws = new WebSocket(targets.find((target) => target.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve, reject) => {
            ws.addEventListener('open', resolve, { once: true });
            ws.addEventListener('error', reject, { once: true });
        });

        let sequence = 0;
        const pending = new Map();
        ws.addEventListener('message', ({ data }) => {
            const msg = JSON.parse(data);
            if (!pending.has(msg.id)) return;
            const { resolve, reject } = pending.get(msg.id);
            pending.delete(msg.id);
            if (msg.error) reject(new Error(msg.error.message));
            else resolve(msg.result);
        });
        const cdp = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            pending.set(id, { resolve, reject });
            ws.send(JSON.stringify({ id, method, params }));
        });
        const evaluate = async (expression) => {
            const response = await cdp('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            if (response.exceptionDetails) throw new Error(response.exceptionDetails.exception?.description || 'Browser evaluation error');
            return response.result.value;
        };
        const visit = async (route, marker) => {
            const url = base + route;
            await cdp('Page.navigate', { url });
            await until(() => evaluate(`location.href === ${JSON.stringify(url)} && document.readyState !== 'loading'
                && document.body?.innerText.includes(${JSON.stringify(marker)})`), marker);
        };
        const login = async (username, expected) => {
            await visit('/login.php', 'Welcome back');
            await evaluate(`(() => {
                const form = document.querySelector('form.form');
                form.elements.username.value = ${JSON.stringify(username)};
                form.elements.password.value = 'password';
                setTimeout(() => form.requestSubmit(), 0);
            })()`);
            await until(() => evaluate(`location.pathname.endsWith(${JSON.stringify(expected)})
                && document.readyState !== 'loading' && Boolean(document.querySelector('main h1'))`), expected);
        };
        const logout = async () => {
            await evaluate(`setTimeout(() => document.querySelector('form[action$="/logout.php"]').requestSubmit(), 0); undefined`);
            await until(() => evaluate(`location.pathname.endsWith('/giglink/') && document.readyState !== 'loading'
                && !document.querySelector('form[action$="/logout.php"]')`), 'signed-out landing');
        };
        const viewport = (width) => cdp('Emulation.setDeviceMetricsOverride',
            { width, height: 900, deviceScaleFactor: 1, mobile: false });
        async function check(name, run) {
            try {
                await run();
                ++passed;
                console.log('PASS ' + name);
            } catch (error) {
                ++failures;
                console.error('FAIL ' + name + ': ' + error.message);
            }
        }
        await cdp('Page.enable');
        await cdp('Network.enable');

        for (const width of [320, 375, 768, 1366, 1920]) {
            await check('public discovery at ' + width + 'px', async () => {
                await viewport(width);
                await visit('/jobs.php', 'Browse jobs');
                const layout = await evaluate(`(async () => {
                    await document.fonts.ready;
                    const card = document.querySelector('.discovery-card');
                    return { overflow: document.documentElement.scrollWidth - innerWidth,
                        buttonHeight: card?.querySelector('.btn').getBoundingClientRect().height,
                        slots: card?.innerText.includes('Slots open') };
                })()`);
                assert.ok(layout.overflow <= 1, JSON.stringify(layout));
                assert.ok(layout.buttonHeight >= 44 && layout.slots, JSON.stringify(layout));
            });
        }
        await check('filter chips, web/API parity and an actionable empty state', async () => {
            await visit('/jobs.php?sort=pay&minpay=5000', 'Browse jobs');
            const result = await evaluate(`(async () => ({
                label: document.querySelector('.filter-chips')?.innerText,
                web: [...document.querySelectorAll('.discovery-card h2')].map((item) => item.textContent),
                api: (await (await fetch('api/jobs.php?sort=pay&minpay=5000')).json()).jobs.map((job) => job.title)
            }))()`);
            assert.match(result.label, /Highest listed pay/);
            assert.deepEqual(result.web, result.api);
            await visit('/jobs.php?q=Z%.2BNoMatchingListings', 'No jobs match these filters');
            assert.equal(await evaluate('document.querySelector(".filter-chips [aria-label^=Remove]") !== null'), true);
        });
        await check('work ID is public, printable, and omits private fields', async () => {
            await viewport(320);
            await visit('/profile_qr.php?id=2', 'GigLink Work ID');
            const card = await evaluate(`(() => {
                const card = document.querySelector('.work-id');
                return { text: card.innerText, html: card.innerHTML, overflow: document.documentElement.scrollWidth - innerWidth };
            })()`);
            assert.match(card.text, /GL-000002/);
            assert.match(card.text, /Completed gigs/);
            assert.match(card.text, /Employer reviews/);
            assert.match(card.html, /profile_qr.php(?:%3F|\?)id(?:%3D|=)2/);
            assert.doesNotMatch(card.text, /Verified Worker|UGX|@giglink\.test|\+256/i);
            assert.ok(card.overflow <= 1);
        });
        await check('employer dashboard prioritizes actionable cards', async () => {
            await login('mama', '/employer/dashboard.php');
            const heading = await evaluate('document.querySelector("main h1")?.innerText');
            assert.ok(heading.length > 0);
            assert.equal(await evaluate(`document.querySelector('.workflow-card')?.innerText.includes('positions filled')`), true);
            assert.ok(await evaluate('document.documentElement.scrollWidth - innerWidth <= 1'));
            await logout();
        });
        await check('worker dashboard state and responsive navigation', async () => {
            await login('alice', '/youth/dashboard.php');
            assert.equal(await evaluate(`document.querySelector('[aria-label="Application timeline"]') !== null`), true);
            assert.ok(await evaluate('document.documentElement.scrollWidth - innerWidth <= 1'));
            await logout();
        });
        await check('HTTP workflow from API application to worker-confirmed payment', async () => {
            const post = async (selector, fields = {}) => evaluate(`(async () => {
                const form = document.querySelector(${JSON.stringify(selector)});
                if (!form) throw new Error('Expected form is missing');
                const data = new FormData(form);
                for (const [key, value] of Object.entries(${JSON.stringify(fields)})) data.set(key, value);
                const response = await fetch(form.action, { method: 'POST', body: data });
                const html = new DOMParser().parseFromString(await response.text(), 'text/html');
                return { status: response.status, success: html.querySelector('.toast-ok')?.textContent,
                    error: html.querySelector('.toast-err')?.textContent };
            })()`);
            const succeeded = (result, message) => assert.match(result.success || result.error || '', message);
            await login('alice', '/youth/dashboard.php');
            const api = await evaluate(`(async () => {
                const readJson = async (response, operation) => {
                    const raw = await response.text();
                    try { return JSON.parse(raw); } catch { throw new Error(operation + ' HTTP ' + response.status
                        + ': ' + raw.replace(/<[^>]*>/g, '').trim().slice(0, 350)); }
                };
                const login = await readJson(await fetch('/giglink/api/login.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username: 'alice', password: 'password' })
                }), 'API login');
                const options = {
                    method: 'POST',
                    headers: { Authorization: 'Bearer ' + login.token, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ job_id: 1, message: 'I am available for the browser QA gig.' })
                };
                const first = await readJson(await fetch('/giglink/api/apply.php', options), 'API application');
                const retry = await readJson(await fetch('/giglink/api/apply.php', options), 'API application retry');
                return { first, retry };
            })()`);
            assert.deepEqual(api, { first: { ok: true }, retry: { ok: true } });
            await visit('/youth/dashboard.php', 'Applications awaiting a decision');
            await logout();

            await login('mama', '/employer/dashboard.php');
            const applicants = '/employer/applicants.php?job_id=1';
            await visit(applicants, 'I am available for the browser QA gig.');
            const emptyCsrf = await evaluate(`(async () => (await fetch('/giglink/actions/accept_applicant.php',
                { method: 'POST', body: new URLSearchParams({ job_id: '1' }) })).status)()`);
            assert.equal(emptyCsrf, 400);
            succeeded(await post('form[action$="/accept_applicant.php"]'), /Applicant accepted/);
            await logout();

            await login('alice', '/youth/dashboard.php');
            await visit('/youth/dashboard.php', 'Upcoming accepted gigs');
            succeeded(await post('form[action$="/work_status.php"]:has(input[value="in_progress"])'), /Work status updated/);
            await logout();

            await login('mama', '/employer/dashboard.php');
            await visit(applicants, 'Mark work complete');
            succeeded(await post('form[action$="/work_status.php"]:has(input[value="completed"])'), /Work status updated/);
            await visit(applicants, 'Payment outstanding');
            succeeded(await post('form[action$="/mark_paid.php"]', { amount: '60000', method: 'cash' }), /Payment recorded/);
            await logout();

            await login('alice', '/youth/dashboard.php');
            await visit('/youth/dashboard.php', 'Payments awaiting confirmation');
            succeeded(await post('form[action$="/payment_receipt.php"]:has(input[value="confirmed"])'), /Worker confirmed receipt/);
            await visit('/youth/dashboard.php', 'Worker confirmed receipt');
            await logout();
        });
        await check('private mobile disputes, evidence upload and role boundaries', async () => {
            const target = '/disputes.php?job=QA%20staff%20review';
            await viewport(320);
            await login('alice', '/youth/dashboard.php');
            await visit(target, 'QA staff review');
            const detail = await evaluate("document.querySelector('.workflow-card a').getAttribute('href').slice('/giglink'.length)");
            await visit(detail, 'Statements and evidence');
            assert.ok(await evaluate('document.documentElement.scrollWidth - innerWidth <= 1'));
            assert.equal(await evaluate('document.querySelector("input[value=decision]") !== null'), false);
            const evidence = await evaluate(`(async () => {
                const form = document.querySelector('form[enctype]');
                const data = new FormData(form);
                data.set('evidence', new File(['<svg></svg>'], 'spoofed.png', { type:'image/png' }));
                const bad = await (await fetch(location.href,{ method:'POST',body:data })).text();
                const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC'), (c) => c.charCodeAt(0));
                data.set('evidence',new File([png],'evidence.png',{ type:'image/png' }));
                data.set('statement','Browser evidence submitted by worker.');
                const ok = await (await fetch(location.href,{ method:'POST',body:data })).text();
                const parsed = new DOMParser().parseFromString(ok,'text/html');
                const link = [...parsed.querySelectorAll('a[href*="dispute_evidence.php"]')].at(-1).getAttribute('href');
                const download = await fetch(link);
                return { bad: bad.includes('Use a valid JPEG or PNG'), saved: ok.includes('Browser evidence submitted by worker.'),
                    link, type:download.headers.get('content-type'), cache:download.headers.get('cache-control'),
                    disposition:download.headers.get('content-disposition') };
            })()`);
            assert.ok(evidence.bad && evidence.saved);
            assert.equal(evidence.type,'image/png');
            assert.match(evidence.cache,/no-store/);
            assert.match(evidence.disposition,/attachment/);
            await logout();

            await login('shopowner', '/employer/dashboard.php');
            const privacy = await evaluate(`(async () => {
                const result = await fetch('/giglink' + ${JSON.stringify(detail)});
                const evidence = await fetch(${JSON.stringify(evidence.link)});
                const staff = await fetch('/giglink/admin/reconcile.php');
                return [result.status,evidence.status,staff.status];
            })()`);
            assert.deepEqual(privacy,[404,404,403]);
            await visit('/trust.php','Private account history');
            assert.ok(await evaluate("!document.body.innerText.includes('QA historical match')"));
            await logout();

            await login('admin','/giglink/');
            await visit('/disputes.php?worker=alice&employer=mama&job=QA%20staff%20review&status=open','QA staff review');
            await visit(detail,'Record staff decision');
            const decided = await evaluate(`(async () => {
                const form = document.querySelector('input[value=decision]').form;
                const data = new FormData(form);
                data.set('next','resolved');
                data.set('reason','Browser staff reviewed both submissions.');
                const response = await fetch(location.href,{ method:'POST',body:data });
                return (await response.text()).includes('Decision recorded.');
            })()`);
            assert.ok(decided);
            await visit('/admin/reconcile.php','Payment reconciliation');
            assert.ok(await evaluate('document.documentElement.scrollWidth - innerWidth <= 1'));
            const reconcile = await evaluate("[...document.querySelectorAll('.workflow-card')].find(card => card.innerText.includes('QA browser source')).querySelector('a').href.split('/giglink')[1]");
            await visit(reconcile,'Suggested applications');
            assert.ok(await evaluate('document.documentElement.scrollWidth - innerWidth <= 1'));
            const linked = await evaluate(`(async () => {
                const card = [...document.querySelectorAll('.workflow-card')].find(card => card.innerText.includes('QA browser reconciliation'));
                const data = new FormData(card.querySelector('form'));
                data.set('reason','Browser staff matched parties and ledger.');
                return (await (await fetch(location.href,{ method:'POST',body:data })).text()).includes('Linked to application');
            })()`);
            assert.ok(linked);
            await logout();

            await login('mama','/employer/dashboard.php');
            await visit(detail,'Browser staff reviewed both submissions.');
            assert.equal(await evaluate('document.querySelector("input[value=decision]") !== null'), false);
            await visit('/trust.php','Hiring and obligations');
            assert.ok(await evaluate("document.body.innerText.includes('QA historical match')"));
            await logout();
        });
        await check('finance screens and staff permissions across five widths', async () => {
            await login('admin','/giglink/');
            for (const width of [320,375,768,1366,1920]) {
                await viewport(width);
                await visit('/admin/finance.php','Provider transaction history');
                assert.ok(await evaluate('document.documentElement.scrollWidth - innerWidth <= 1'),'Finance overflow at ' + width);
                await visit('/admin/staff.php','Staff permissions');
                assert.ok(await evaluate('document.documentElement.scrollWidth - innerWidth <= 1'),'Staff overflow at ' + width);
                await visit('/admin/mobile_money.php','Isolated EUR test cases');
                const mobileLayout = await evaluate(`(async () => {
                    await document.fonts.ready;
                    const overflow = document.documentElement.scrollWidth - innerWidth;
                    const elements = [];
                    if (overflow > 1) {
                        const walker = document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);
                        const range = document.createRange();
                        for (let node; (node=walker.nextNode());) {
                            if (!node.textContent.trim()) continue;
                            range.selectNodeContents(node);
                            if (range.getBoundingClientRect().right > innerWidth+1) elements.push(node.textContent.trim().slice(0,90));
                        }
                    }
                    return {overflow,elements:elements.slice(0,10)};
                })()`);
                assert.ok(mobileLayout.overflow <= 1,'Mobile Money overflow at ' + width + ' ' + JSON.stringify(mobileLayout));
                assert.equal(await evaluate('document.querySelector("form input[name=product]") !== null'),false);
                await visit('/admin/payment_queue.php','Payment operations');
                assert.ok(await evaluate('document.documentElement.scrollWidth - innerWidth <= 1'),'Queue overflow at ' + width);
                await visit('/admin/corrections.php','Reconciliation corrections');
                assert.ok(await evaluate('document.documentElement.scrollWidth - innerWidth <= 1'),'Correction overflow at ' + width);
            }
            await visit('/admin/payment_queue.php','Queue a local mock payment');
            const target = await evaluate(`(async () => {
                const form = document.querySelector('form[method=post] input[name=key]').form;
                const data = new FormData(form);
                const choice = [...form.elements.application.options].find((opt)=>opt.text.includes('QA queue browser'));
                data.set('application',choice.value);
                const response = await fetch(location.href,{method:'POST',body:data});
                return new URL(response.url).search;
            })()`);
            assert.match(target,/attempt=\d+/);
            const work = () => execFileSync(process.env.GIGLINK_TEST_PHP,
                [path.resolve(__dirname,'../payment-worker.php'),'--once'],{encoding:'utf8',windowsHide:true});
            assert.match(work(),/processed [1-9]/);
            await visit('/admin/payment_queue.php' + target,'Verified event ledger');
            const txn = await evaluate("document.querySelector('a[href^=\"finance.php?id=\"]').getAttribute('href')");
            await visit('/admin/' + txn,'Settlement ledger');
            const reference = await evaluate("document.querySelector('.workflow-card').innerText.match(/mock_[a-f0-9]{32}/)?.[0]");
            assert.match(reference,/^mock_/);
            const deliver = async (id,type,valid) => {
                const raw = JSON.stringify({id,reference,type,amount_minor:49000,currency:'UGX'});
                const timestamp = String(Math.floor(Date.now()/1000));
                const signature = valid ? createHmac('sha256',process.env.GIGLINK_MOCK_WEBHOOK_SECRET)
                    .update(timestamp + '.' + raw).digest('hex') : '0'.repeat(64);
                const result = await fetch(base + '/api/payment_webhook.php',{method:'POST',body:raw,
                    headers:{'X-Mock-Timestamp':timestamp,'X-Mock-Signature':signature}});
                return [result.status,(await result.json()).status];
            };
            assert.deepEqual(await deliver('browser_auth','confirmed',false),[401,'authentication_failed']);
            assert.deepEqual(await deliver('browser_settle','settled',true),[202,'queued']);
            work();
            assert.deepEqual(await deliver('browser_confirm','confirmed',true),[202,'queued']);
            work();
            assert.deepEqual(await deliver('browser_confirm','confirmed',true),[200,'applied']);
            assert.deepEqual(await deliver('browser_received','received',true),[202,'queued']);
            work();
            await visit('/admin/finance.php?q=' + reference + '&status=settled',reference);
            const detail = await evaluate("document.querySelector('.workflow-card a[href^=\"?id=\"]').getAttribute('href')");
            await visit('/admin/finance.php' + detail,'Settlement ledger');
            await logout();

            await login('qa_auditor','/giglink/');
            await visit('/admin/finance.php','Provider transaction history');
            assert.ok(await evaluate("!document.querySelector('form input[name=key]')"));
            const auditor = await evaluate(`(async () => Promise.all([
                fetch('/giglink/admin/payment_queue.php',{method:'POST'}),
                fetch('/giglink/admin/staff.php'),fetch('/giglink/admin/audit.php')
            ]).then((responses) => responses.map((response)=>response.status)))()`);
            assert.deepEqual(auditor,[403,403,200]);
            await logout();

            await login('qa_support','/giglink/');
            const support = await evaluate(`(async () => Promise.all([
                fetch('/giglink/admin/finance.php'),fetch('/giglink/admin/reports.php')
            ]).then((responses) => responses.map((response)=>response.status)))()`);
            assert.deepEqual(support,[403,200]);
            await logout();
        });
        await check('actual service worker stores no dashboard after logout', async () => {
            await until(() => evaluate('Boolean(navigator.serviceWorker.controller)'), 'service worker control');
            const keys = await evaluate(`(async () => {
                const result = [];
                for (const name of await caches.keys()) {
                    const cache = await caches.open(name);
                    result.push(...(await cache.keys()).map((req) => new URL(req.url).pathname));
                }
                return result;
            })()`);
            assert.ok(keys.includes('/giglink/assets/style.css'));
            assert.ok(!keys.some((key) => /dashboard|applicants|messages|logout/.test(key)));
            const testPort = new URL(base).port;
            const temporaryServer = Number(process.env.GIGLINK_TEST_WEB_PID);
            if (!temporaryServer || !testPort || new URL(base).hostname !== '127.0.0.1') {
                throw new Error('Run through tests/run-xampp.ps1 to stop the test-only origin for the offline check.');
            }
            process.kill(temporaryServer);
            await visit('/youth/dashboard.php', "You're offline");
            assert.equal(await evaluate('document.querySelector(".workflow-card") === null'), true);
        });
    } finally {
        ws?.close();
        child.kill();
    }
    console.log(passed + ' passed, ' + failures + ' failed');
    if (failures) process.exitCode = 1;
}

main().catch((error) => { console.error(error); process.exitCode = 1; });

