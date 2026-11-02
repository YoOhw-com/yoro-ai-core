'use strict';
// Unit DOM protocol harness: no browser engine, rendering, network, provider, storage or site runtime.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
class Element {
    constructor(tag) { this.tagName = tag; this.children = []; this.listeners = {}; this.dataset = {}; this.attrs = {}; this.value = ''; this.textContent = ''; this.hidden = false; this.disabled = false; }
    append(...nodes) { nodes.forEach(node => { node.parent = this; this.children.push(node); }); }
    replaceChildren(...nodes) { this.children.forEach(node => { node.parent = null; }); this.children = []; this.append(...nodes); }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter(node => node !== this); this.parent = null; }
    get isConnected() { return this === root || Boolean(this.parent && this.parent.isConnected); }
    get parentElement() { return this.parent; }
    get firstElementChild() { return this.children[0]; }
    setAttribute(name, value) { this.attrs[name] = value; }
    getAttribute(name) { return this.attrs[name]; }
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
    async event(name, extra = {}) { for (const fn of this.listeners[name] || []) await fn({preventDefault() {}, ...extra}); await flush(); }
    focus() { document.activeElement = this; }
    getClientRects() { return this.hidden ? [] : [{}]; }
    matches(selector) {
        if (selector[0] === '.') return (this.className || '').split(' ').includes(selector.slice(1));
        const attr = selector.match(/^\[([^=\]]+)(?:=['"]?([^'"\]]+)['"]?)?\]$/);
        if (attr) { const key = attr[1].replace(/^data-/, '').replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()); const value = attr[1].startsWith('data-') ? this.dataset[key] : (this.attrs[attr[1]] ?? this[attr[1]]); return value !== undefined && (attr[2] === undefined || String(value) === attr[2]); }
        return this.tagName === selector;
    }
    querySelectorAll(selector) { const selectors = selector.split(','); return this.children.flatMap(child => [...(selectors.some(s => child.matches(s)) ? [child] : []), ...child.querySelectorAll(selector)]); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}
const el = (tag, className = '', attrs = {}) => { const node = new Element(tag); node.className = className; Object.assign(node.attrs, attrs); return node; };
const root = el('div'); root.dataset.endpoint = 'https://fixture.invalid/support/';
const launch = el('button', 'yoro-support-launch'), panel = el('section'), composer = el('form', 'yoro-support-composer'), input = el('textarea'), send = el('button', '', {type: 'submit'}), retry = el('button'); retry.dataset.retry = '';
panel.hidden = true; composer.append(input, send, retry); const log = el('div', '', {role: 'log'}), status = el('p'); status.dataset.status = '';
const area = el('div', 'yoro-support-context'), bar = el('div', 'yoro-support-verified'), label = el('span'); label.dataset.verifiedLabel = '';
const end = el('button'); end.dataset.endPrivate = ''; bar.append(label, end);
const reset = el('button'); reset.dataset.reset = ''; const close = el('button'); close.dataset.close = '';
const quote = el('button'); quote.dataset.starter = 'quote'; panel.append(quote);
const orders = el('button'); orders.dataset.starter = 'orders'; panel.append(composer, log, status, area, bar, reset, close, orders); root.append(launch, panel);
const iconHolder = el('span'), iconImage = el('img'), iconFallback = el('span');
iconImage.dataset.supportIcon = ''; iconImage.dataset.static = '/fixed-static.svg'; iconImage.setAttribute('src', '/fixed-3-cycles.svg'); iconFallback.dataset.iconFallback = ''; iconFallback.hidden = true; iconHolder.append(iconImage, iconFallback); launch.append(iconHolder);
let iconMotionChanged;
const documentEvents = {};
const document = {activeElement: null, documentElement: {lang: 'en'}, hidden: false, getElementById: id => id === 'yoro-support' ? root : null, createElement: tag => el(tag), addEventListener(name, callback) { (documentEvents[name] ||= []).push(callback); }, async event(name) { for (const callback of documentEvents[name] || []) callback(); await flush(); }};
const calls = [], responses = [], timers = new Map(); let timerId = 0, uuid = 0;
const flush = () => new Promise(resolve => setImmediate(resolve));
const sandbox = {document, navigator: {language: 'en'}, window: {location: {origin: 'https://fixture.invalid'}, matchMedia: () => ({matches: false, addEventListener(type, fn) { iconMotionChanged = fn; }}), confirm: () => true, addEventListener() {}},
    crypto: {randomUUID: () => 'synthetic-turn-' + (++uuid)}, URL, AbortController, Date, Map, console,
    setTimeout(fn, delay) { const id = ++timerId; timers.set(id, {fn, delay}); if (delay === 0) setImmediate(() => { if (timers.has(id)) { timers.delete(id); fn(); } }); return id; }, clearTimeout(id) { timers.delete(id); },
    fetch: async (url, options) => { const body = JSON.parse(options.body); calls.push({url, options, body}); const next = responses.shift(); assert.ok(next, 'Unexpected protocol request'); if (next.error) throw new Error('Synthetic offline'); if (next.wait) await next.wait; return {ok: next.ok !== false, json: async () => next.body}; }};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../../assets/js/yoro-support.js'), 'utf8'), sandbox);
let checks = 0; const verify = (condition, message) => { assert.ok(condition, message); checks++; };
(async () => {
    iconMotionChanged({matches: true}); verify(iconImage.getAttribute('src') === '/fixed-static.svg', 'Frontend motion preference uses static');
    iconMotionChanged({matches: false}); verify(iconImage.getAttribute('src') === '/fixed-static.svg', 'Frontend does not restart animation');
    iconImage.setAttribute('src', '/fixed-3-cycles.svg'); await iconImage.event('error'); verify(iconImage.getAttribute('src') === '/fixed-static.svg', 'Frontend image failure uses fixed static');
    await iconImage.event('error'); verify(iconImage.hidden && !iconFallback.hidden && calls.length === 0, 'Failed static falls back without protocol or AI');

    responses.push({body: {csrf: 'synthetic-csrf', protocol: 2, capabilities: {content: true, products: true, quote: true, coupons: false, payments: true, orders: true}, verification: {verified: false}}});
    await launch.event('click');
    verify(calls.length === 1 && calls[0].url.endsWith('/session'), 'Opening only establishes session, no AI');
    input.value = 'Public question'; responses.push({error: true}); await composer.event('submit');
    const original = JSON.stringify(calls.at(-1).body); responses.push({error: true}); await retry.event('click'); responses.push({error: true}); await retry.event('click'); await retry.event('click');
    verify(calls.filter(call => call.url.endsWith('/turn')).length === 3, 'Original + at most two explicit retries');
    verify(calls.filter(call => call.url.endsWith('/turn')).every(call => JSON.stringify(call.body) === original), 'Retry preserves exact UUID and input');
    verify(retry.hidden === true, 'Retry hidden after finite budget');
    await orders.event('click'); verify(area.querySelector('[name=email]') !== null, 'Orders open dedicated email form');
    area.querySelector('[name=email]').value = 'buyer@example.test'; responses.push({body: {challenge_id: 'synthetic-challenge', email_hint: 'b***@example.test', resend_seconds: 60}}); await area.querySelector('form').event('submit');
    verify(calls.at(-1).url.endsWith('/verification') && calls.at(-1).body.email === 'buyer@example.test', 'Email only sent to verification route');
    area.querySelector('[name=code]').value = '123456'; responses.push({body: {verified: true, expires_at: new Date(Date.now() + 900000).toISOString(), email_hint: 'b***@example.test'}}, {body: {orders: [], has_next: false}}); await area.querySelector('form').event('submit');
    verify(calls.at(-2).body.code === '123456' && calls.at(-1).body.kind === 'orders', 'Confirm then read scoped order list');
    verify(calls.filter(call => call.url.endsWith('/turn')).length === 3, 'Email/code/order list cause zero AI turns');
    verify(!JSON.stringify(calls.filter(call => call.url.endsWith('/turn'))).includes('buyer@example.test') && !JSON.stringify(calls.filter(call => call.url.endsWith('/turn'))).includes('123456'), 'Verification secrets excluded from turn payloads');

    const orderList = {orders: [{reference: 'opaque-native-order', number: '1024', date: '2026-11-01', status: 'Processing', total: '54.00', currency: 'USD'}], has_next: false};
    const selectedOrder = {context_token: 'synthetic-private-context', private: true, expires_at: new Date(Date.now() + 300000).toISOString(), card: {kind: 'order', title: 'Order #1024', data: {status: 'Processing', payment: 'Paid', items: [], total: '54.00', currency: 'USD', refund: '0.00', tracking: []}}};
    responses.push({body: orderList}); await orders.event('click'); responses.push({body: selectedOrder});
    await area.querySelectorAll('button').find(button => button.textContent === 'Select order').event('click');
    verify(calls.filter(call => call.url.endsWith('/turn')).length === 3, 'Nonempty static order selection performs no AI turn under browser-like macrotask scheduling');
    responses.push({body: {status: 'completed', private: true, answer: {text: 'Explicit order answer.', sources: []}}});
    await area.querySelectorAll('button').find(button => button.textContent === 'Ask about this order').event('click');
    verify(calls.filter(call => call.url.endsWith('/turn')).length === 4 && calls.at(-1).body.context_token === selectedOrder.context_token, 'Explicit ask creates exactly one selected-order AI turn');
    input.value = 'Where is my delivery?'; responses.push({body: {status: 'context', context: 'orders'}}, {body: orderList}); await composer.event('submit');
    const beforeResume = calls.filter(call => call.url.endsWith('/turn')).length;
    responses.push({body: selectedOrder}, {body: {status: 'completed', private: true, answer: {text: 'Resumed order answer.', sources: []}}});
    await area.querySelectorAll('button').find(button => button.textContent === 'Select order').event('click');
    verify(calls.filter(call => call.url.endsWith('/turn')).length === beforeResume + 1 && calls.at(-1).body.message === 'Where is my delivery?', 'Only a real pending customer question automatically resumes once');

    input.value = 'Question awaiting a different order'; responses.push({body: {status: 'context', context: 'orders'}}, {body: orderList}); await composer.event('submit');
    const beforeExpiredResume = calls.filter(call => call.url.endsWith('/turn')).length; responses.push({body: selectedOrder});
    await area.querySelectorAll('button').find(button => button.textContent === 'Select order').listeners.click[0]();
    [...timers.values()].find(timer => timer.delay >= 290000 && timer.delay <= 300000).fn(); await flush();
    verify(calls.filter(call => call.url.endsWith('/turn')).length === beforeExpiredResume, 'An expired or revoked selection cannot dispatch a previously scheduled pending-question resume');
    responses.push({body: {verified: false, expires_at: null, email_hint: null}}); await document.event('visibilitychange');
    verify(bar.hidden && log.querySelectorAll('[data-private]').length === 0 && area.children.length === 0, 'Current readiness-loss status clears private transcript and selected state');
    const privateEntry = el('div'); privateEntry.dataset.private = 'true'; log.append(privateEntry);
    let release; const wait = new Promise(resolve => { release = resolve; }); input.value = 'Pending private question';
    responses.push({wait, body: {status: 'completed', private: true, answer: {text: 'STALE_PRIVATE_RESPONSE', sources: []}}});
    const inFlight = composer.event('submit'); await flush();
    responses.push({body: {verified: false}}); await end.event('click'); release(); await inFlight;
    verify(!log.children.some(entry => entry.children.some(child => child.textContent === 'STALE_PRIVATE_RESPONSE')), 'In-flight response cannot restore private data after revoke');
    verify(log.querySelectorAll('[data-private]').length === 0 && area.children.length === 0 && bar.hidden, 'Revoke clears private transcript/cards/forms');
    verify(status.textContent.includes('Private access ended'), 'Revoke status remains visible');
    verify(calls.every(call => call.options.cache === 'no-store' && call.options.credentials === 'same-origin'), 'Every request same-origin and no-store');
    verify(calls.slice(1).every(call => call.options.headers['X-YoRo-Support-CSRF'] === 'synthetic-csrf'), 'Every post-session request carries CSRF');
    input.value = 'A fresh public question'; responses.push({body: {status: 'completed', answer: {text: 'Current approved answer.', sources: [], products: [{id: 'product:47', title: '<Safe product title>', url: 'https://fixture.invalid/product/daypack', price: '49.00', currency: 'USD', stock: 'instock', sku: 'PACK47', options: ['Size: S']}, {title: 'External forged card', url: 'https://evil.invalid/item'}]}}}); await composer.event('submit');
    verify(status.textContent === '', 'Completed answer clears the thinking state');

    verify(log.querySelectorAll('.yoro-support-product').length === 1 && log.querySelector('.yoro-support-product').querySelector('a').textContent === '<Safe product title>', 'Public product DTO renders a safe bounded structured card; foreign card URL is discarded');
    const beforeQuoteTurns = calls.filter(call => call.url.endsWith('/turn')).length;
    const beforeQuote = calls.length; await quote.event('click');
    verify(calls.length === beforeQuote && area.querySelector('[name=coupon]').disabled, 'Quote opens a static form and disabled coupon capability hides coupon input');
    area.querySelector('[name=query]').value = 'Trail daypack';
    responses.push({body: {products: [{id: 47, name: 'Trail daypack', kind: 'simple', price: '49 USD', options: []}]}}); await area.querySelectorAll('form')[0].event('submit');
    await area.querySelectorAll('button').find(button => button.textContent.startsWith('Trail daypack')).event('click');
    for (const [name, value] of Object.entries({country: 'US', state: 'CA', postcode: '94105'})) area.querySelector('[name=' + name + ']').value = value;
    const quoteCard = {kind: 'quote', title: 'Current estimate', data: {items: [{name: 'Trail daypack', quantity: 1}], subtotal: '49.00', discount: '0.00', shipping: '5.00', tax: '0.00', total: '54.00', currency: 'USD', quoted_at: new Date().toISOString(), coupon: {state: 'none', message: ''}, payment_methods: ['Bank transfer'], shipping_rates: []}};
    responses.push({body: {context_token: 'synthetic-quote-context', private: false, expires_at: new Date(Date.now() + 300000).toISOString(), card: quoteCard}}); await area.querySelectorAll('form')[1].event('submit');
    verify(calls.at(-1).body.kind === 'quote' && calls.at(-1).body.params.items[0].product_id === 47 && calls.at(-1).body.params.destination.postcode === '94105', 'Quote uses explicit product quantity and destination inputs');
    verify(calls.filter(call => call.url.endsWith('/turn')).length === beforeQuoteTurns, 'Product selection and quote preview do not infer');
    responses.push({body: {status: 'completed', answer: {text: 'Estimate explanation.', sources: []}}}); await area.querySelectorAll('button').find(button => button.textContent === 'Explain this estimate').event('click');
    verify(calls.at(-1).url.endsWith('/turn') && calls.at(-1).body.context_token === 'synthetic-quote-context', 'Explicit explanation binds the selected context token');
    let releaseExpired; const delayed = new Promise(resolve => { releaseExpired = resolve; });
    responses.push({wait: delayed, body: {status: 'completed', answer: {text: 'LATE_EXPIRED_QUOTE', sources: []}}});
    const explaining = area.querySelectorAll('button').find(button => button.textContent === 'Explain this estimate').event('click'); await flush();
    const expiry = [...timers.values()].find(timer => timer.delay >= 290000 && timer.delay <= 300000); assert.ok(expiry); expiry.fn(); releaseExpired(); await explaining;
    verify(!log.children.some(entry => entry.children.some(child => child.textContent === 'LATE_EXPIRED_QUOTE')) && retry.hidden, 'Expired selection drops a late AI response and its retry input');
    async function primePrivate() {
        responses.push({body: {verified: false}}); await end.event('click');
        await orders.event('click');
        area.querySelector('[name=email]').value = 'buyer@example.test';
        responses.push({body: {challenge_id: 'synthetic-loss-challenge', email_hint: 'b***@example.test', resend_seconds: 60}}); await area.querySelector('form').event('submit');
        area.querySelector('[name=code]').value = '123456';
        responses.push({body: {verified: true, expires_at: new Date(Date.now() + 900000).toISOString(), email_hint: 'b***@example.test'}}, {body: orderList}); await area.querySelector('form').event('submit');
        responses.push({body: selectedOrder}); await area.querySelectorAll('button').find(button => button.textContent === 'Select order').event('click');
        responses.push({body: {status: 'completed', private: true, answer: {text: 'Synthetic private answer before invalidation.', sources: []}}});
        await area.querySelectorAll('button').find(button => button.textContent === 'Ask about this order').event('click');
        verify(!bar.hidden && log.querySelectorAll('[data-private]').length > 0 && area.children.length > 0, 'Private precondition is rendered through actual verification/order/turn controls');
    }
    for (const code of ['support_session_inactive', 'support_forbidden', 'support_verification_required']) {
        await primePrivate(); const before = calls.length;
        responses.push({ok: false, body: {code}}); await document.event('visibilitychange');
        const safeArea = code === 'support_verification_required' ? area.querySelector('[name=email]')?.value === '' && area.querySelectorAll('[data-private]').length === 0 : area.children.length === 0;
        verify(bar.hidden && log.querySelectorAll('[data-private]').length === 0 && safeArea && retry.hidden, 'Definitive ' + code + ' current-session denial clears private state; only a fresh public verification form may remain');
        verify(calls.length === before + 1, 'Definitive loss adds no automatic admission/email/inference request');
        input.value = 'Fresh public request after denial'; responses.push({body: {status: 'completed', answer: {text: 'Synthetic public answer.', sources: []}}}); await composer.event('submit');
        verify(calls.at(-1).body.context_token === '', 'A later explicit request cannot reuse the invalidated private token');
    }
    for (const loss of [{ok: false, body: {code: 'support_session_inactive'}}, {body: {verified: false, expires_at: null, email_hint: null}}, {reset: true}]) {
        await primePrivate(); let releaseOrder;
        const heldOrder = new Promise(resolve => { releaseOrder = resolve; });
        responses.push({wait: heldOrder, body: orderList}); await orders.event('click');
        if (loss.reset) { responses.push({body: {csrf: 'synthetic-reset-csrf'}}); await reset.event('click'); }
        else { responses.push(loss); await document.event('visibilitychange'); }
        releaseOrder(); await flush(); await flush();
        verify(bar.hidden && area.children.length === 0 && log.querySelectorAll('[data-private]').length === 0, 'A delayed private order response cannot restore UI after definitive error, verified:false status or explicit reset');
    }
    for (const route of ['orders', 'turn']) {
        await primePrivate(); const before = calls.length;
        responses.push({ok: false, body: {code: 'support_session_inactive'}});
        if (route === 'orders') await orders.event('click');
        else { input.value = 'Private request denied after settings changed'; await composer.event('submit'); }
        verify(bar.hidden && log.querySelectorAll('[data-private]').length === 0 && area.children.length === 0 && retry.hidden, 'Actual ' + route + ' denial clears private state even outside the visibility handler');
        verify(calls.length === before + 1, 'A denied ' + route + ' request creates no automatic inference or recovery');
    }
    await primePrivate(); input.value = 'Pending private question before invalidation';
    responses.push({body: {status: 'context', context: 'orders'}}, {body: orderList}); await composer.event('submit');
    const beforeLostResume = calls.filter(call => call.url.endsWith('/turn')).length;
    responses.push({body: selectedOrder}); await area.querySelectorAll('button').find(button => button.textContent === 'Select order').listeners.click[0]();
    responses.push({ok: false, body: {code: 'support_session_inactive'}}); await document.event('visibilitychange'); await flush();
    verify(calls.filter(call => call.url.endsWith('/turn')).length === beforeLostResume && retry.hidden, 'Definitive loss invalidates a scheduled pending-question resume without inference');
    for (const failure of [{ok: false, body: {code: 'support_unavailable'}}, {error: true}]) {
        await primePrivate(); const before = calls.length, privateCount = log.querySelectorAll('[data-private]').length;
        responses.push(failure); await document.event('visibilitychange');
        verify(!bar.hidden && log.querySelectorAll('[data-private]').length === privateCount && area.children.length > 0, 'A transient current-session outage alone does not declare private authorization revoked');
        verify(calls.length === before + 1, 'Transient status failure creates no automatic retry or admission');
    }
    responses.push({body: {verified: false}}); await end.event('click');
    let releasePublic; const heldPublic = new Promise(resolve => { releasePublic = resolve; });
    input.value = 'Ordinary public question during visibility change';
    responses.push({wait: heldPublic, body: {status: 'completed', answer: {text: 'PUBLIC_RESPONSE_REMAINS_USABLE', sources: []}}});
    const publicInFlight = composer.event('submit'); await flush(); const beforePublicVisibility = calls.length;
    await document.event('visibilitychange');
    verify(calls.length === beforePublicVisibility, 'An unverified public turn keeps the existing busy guard against concurrent status requests');
    releasePublic(); await publicInFlight;
    verify(log.children.some(entry => entry.children.some(child => child.textContent === 'PUBLIC_RESPONSE_REMAINS_USABLE')) && !send.disabled && retry.hidden, 'Ordinary public response is not silently discarded by visibility handling');
    responses.push({body: {verified: false, expires_at: null, email_hint: null}}); await document.event('visibilitychange');
    input.value = 'Ordinary public question after unverified status'; responses.push({body: {status: 'completed', answer: {text: 'PUBLIC_AFTER_UNVERIFIED_STATUS', sources: []}}}); await composer.event('submit');
    verify(calls.at(-1).body.context_token === '' && log.children.some(entry => entry.children.some(child => child.textContent === 'PUBLIC_AFTER_UNVERIFIED_STATUS')) && !send.disabled, 'Successful unverified status leaves ordinary explicit public questions usable');
    console.log(JSON.stringify({result: 'passed', suite: 'widget-unit-protocol', assertions: checks, browser_rendering: 'NOT_EVALUATED', network: 0, provider: 0}));
})().catch(error => { console.error(error); process.exitCode = 1; });
