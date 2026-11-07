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
const orders = el('button'); orders.dataset.starter = 'orders'; const body = el('div', 'yoro-support-body'); body.append(log, area); panel.append(composer, body, status, bar, reset, close, orders);
Object.defineProperty(body, 'scrollHeight', {get: () => 500 + log.querySelectorAll('p').length * 80 + log.querySelectorAll('.yoro-support-product').length * 300});
body.clientHeight = 200; const scrollWrites = []; let bodyTop = 0; Object.defineProperty(body, 'scrollTop', {get: () => bodyTop, set: value => {bodyTop = Math.min(value, body.scrollHeight - body.clientHeight); scrollWrites.push({value, cards: log.querySelectorAll('.yoro-support-product').length});}}); root.append(launch, panel);
const iconHolder = el('span'), iconImage = el('img'), iconFallback = el('span');
iconImage.dataset.supportIcon = ''; iconImage.dataset.static = '/fixed-static.svg'; iconImage.setAttribute('src', '/fixed-3-cycles.svg'); iconFallback.dataset.iconFallback = ''; iconFallback.hidden = true; iconHolder.append(iconImage, iconFallback); launch.append(iconHolder);
let iconMotionChanged;
const documentEvents = {};
const document = {activeElement: null, documentElement: {lang: 'en'}, hidden: false, getElementById: id => id === 'yoro-support' ? root : null, createElement: tag => el(tag), addEventListener(name, callback) { (documentEvents[name] ||= []).push(callback); }, async event(name) { for (const callback of documentEvents[name] || []) callback(); await flush(); }};
const r3Committed = new Map();
let sessionNeedsRecovery = false;
const recoveredSession = () => ({body: {csrf: 'synthetic-recovered-csrf', protocol: 2, capabilities: {content: true, products: true, quote: false, coupons: false, payments: false, orders: false}, verification: {verified: false}}});
const calls = [], responses = [], timers = new Map(); let timerId = 0, uuid = 0;
const flush = () => new Promise(resolve => setImmediate(resolve));
let clockOffset = 0;
class FixtureDate extends Date { static now() { return Date.now() + clockOffset; } }
const sandbox = {document, navigator: {language: 'en'}, window: {location: {origin: 'https://fixture.invalid'}, matchMedia: () => ({matches: false, addEventListener(type, fn) { iconMotionChanged = fn; }}), confirm: () => true, addEventListener() {}},
    crypto: {randomUUID: () => 'synthetic-turn-' + (++uuid)}, URL, AbortController, Date: FixtureDate, Map, console,
    setTimeout(fn, delay) { const id = ++timerId; timers.set(id, {fn, delay}); if (delay === 0) setImmediate(() => { if (timers.has(id)) { timers.delete(id); fn(); } }); return id; }, clearTimeout(id) { timers.delete(id); },
    fetch: async (url, options) => { const body = JSON.parse(options.body); calls.push({url, options, body}); const next = responses.shift(); assert.ok(next, 'Unexpected protocol request'); if (next.commit) { assert.ok(url.endsWith('/turn')); const held = r3Committed.get(body.turn_id); if (held) assert.deepEqual(held, body); else r3Committed.set(body.turn_id, JSON.parse(JSON.stringify(body))); } if (next.body?.code === 'support_session_inactive') sessionNeedsRecovery = true;
        if (url.endsWith('/session') && next.body?.csrf) sessionNeedsRecovery = false;
        if (next.error) throw new Error('Synthetic offline'); if (next.wait) await next.wait; return {ok: next.ok !== false, json: async () => next.body}; }};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../../assets/js/yoro-support.js'), 'utf8'), sandbox);
let checks = 0; const verify = (condition, message) => { assert.ok(condition, message); checks++; };
(async () => {
    iconMotionChanged({matches: true}); verify(iconImage.getAttribute('src') === '/fixed-static.svg', 'Frontend motion preference uses static');
    iconMotionChanged({matches: false}); verify(iconImage.getAttribute('src') === '/fixed-static.svg', 'Frontend does not restart animation');
    iconImage.setAttribute('src', '/fixed-3-cycles.svg'); await iconImage.event('error'); verify(iconImage.getAttribute('src') === '/fixed-static.svg', 'Frontend image failure uses fixed static');
    await iconImage.event('error'); verify(iconImage.hidden && !iconFallback.hidden && calls.length === 0, 'Failed static falls back without protocol or AI');

    responses.push({body: {csrf: 'synthetic-csrf', protocol: 2, capabilities: {content: true, products: true, quote: false, coupons: false, payments: false, orders: false}, verification: {verified: false}}});
    await launch.event('click');
    verify(calls.length === 1 && calls[0].url.endsWith('/session'), 'Opening only establishes session, no AI');
    input.value = 'Public question'; responses.push({error: true}); await composer.event('submit');
    const original = JSON.stringify(calls.at(-1).body); responses.push({error: true}); await retry.event('click'); responses.push({error: true}); await retry.event('click'); await retry.event('click');
    verify(calls.filter(call => call.url.endsWith('/turn')).length === 3, 'Original + at most two explicit retries');
    verify(calls.filter(call => call.url.endsWith('/turn')).every(call => JSON.stringify(call.body) === original), 'Retry preserves exact UUID and input');
    verify(retry.hidden === true, 'Retry hidden after finite budget');
    verify(quote.hidden && orders.hidden, 'Deferred quote/private starters hidden for public-only capabilities');
    verify(calls.every(call => call.options.cache === 'no-store' && call.options.credentials === 'same-origin'), 'Every request same-origin no-store');
    verify(calls.slice(1).every(call => call.options.headers['X-YoRo-Support-CSRF'] === 'synthetic-csrf'), 'Post-session requests carry CSRF');
    input.value = 'A fresh public question'; responses.push({body: {status: 'completed', answer: {text: 'Current approved answer.', sources: [], products: [{id: 'product:47', title: '<Safe product title>', url: 'https://fixture.invalid/product/daypack', price: '49.00', currency: 'USD', stock: 'instock', sku: 'PACK47', options: ['Size: S']}, {title: 'External forged card', url: 'https://evil.invalid/item'}]}}}); await composer.event('submit');
    verify(status.textContent === '', 'Completed answer clears the thinking state');

    verify(log.querySelectorAll('.yoro-support-product').length === 1 && log.querySelector('.yoro-support-product').querySelector('a').textContent === '<Safe product title>', 'Public product DTO renders a safe bounded structured card; foreign card URL is discarded');
    verify(scrollWrites.some(write => write.cards === 0), 'Append scrolls the actual body before cards');
    verify(scrollWrites.at(-1).cards === 1 && body.scrollHeight - body.clientHeight - body.scrollTop === 0, 'Final scroll occurs after complete product card insertion');
    verify(log.scrollTop === undefined, 'Log is not the scroll target');
    const beforeManual = scrollWrites.length; body.scrollTop = 25; await flush(); verify(body.scrollTop === 25 && scrollWrites.length === beforeManual + 1, 'Manual scroll persists without append');
    console.log(JSON.stringify({result:'passed',suite:'G040-public-ux-widget-protocol',assertions:checks,network:0,provider:0,browser_rendering:'NOT_EVALUATED',private_quote_positive_journeys:'EXCLUDED'}));
})().catch(error => {console.error(error);process.exitCode=1;});
