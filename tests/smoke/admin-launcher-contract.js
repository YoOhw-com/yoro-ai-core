'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/yoro-admin-launcher.js'), 'utf8');

class Events {
    constructor() { this.events = new Map(); }
    addEventListener(name, callback, options = {}) {
        this.events.set(name, [...(this.events.get(name) || []), { callback, once: !!options.once }]);
    }
    removeEventListener(name, callback) {
        this.events.set(name, (this.events.get(name) || []).filter(item => item.callback !== callback));
    }
    emit(name, event = {}) {
        for (const item of [...(this.events.get(name) || [])]) {
            if (item.once) this.removeEventListener(name, item.callback);
            item.callback(event);
        }
    }
    count(name) { return (this.events.get(name) || []).length; }
}
class Element extends Events {
    constructor(text = '') { super(); this.text = text; this.children = []; this.attrs = {}; this.isConnected = false; this.style = {}; }
    get textContent() { return this.children.length ? this.children.map(child => child.textContent).join('') : this.text; }
    set textContent(value) { this.text = value; this.children = []; }
    setAttribute(name, value) { this.attrs[name] = value; }
    getAttribute(name) { return this.attrs[name] ?? null; }
    removeAttribute(name) { delete this.attrs[name]; }
    contains(element) { return element === this || this.children.some(child => child.contains(element)); }
    remove() { this.removed = true; }
    focus() { this.emit('focus'); }
    replaceChildren(...children) { this.children = children; this.text = ''; }
}
function fixture({ readyState = 'loading', reduced = false, connected = false, animation = 'yoro-launcher-speak', missing = false, storage = new Map(), now = 1000000000, storageFailure = '', site = 'site-a', user = 1, image = false, createFails = false } = {}) {
    const win = new Events();
    const motion = new Events(); motion.matches = reduced;
    const fullText = 'Hi, I’m YoRo! Let’s connect your account and get started.';
    const tasks = new Map(); let nextId = 0; let removed = missing; let active = null; let appends = 0; let hint = null;
    const storageKey = 'yoro-launcher-dismiss:' + site + ':' + user;
    let message, dismiss, icon, img;
    const requests = [], created = [], revoked = [];
    class ImageURL extends URL {
        static createObjectURL(blob) { if (createFails) throw Error('blocked object URL'); const url = 'blob:fixture/' + (created.length + 1); created.push({ url, blob }); return url; }
        static revokeObjectURL(url) { revoked.push(url); }
    }
    const template = { remove() { removed = true; }, content: { cloneNode() {
        const root = new Element();
        hint = connected ? null : new Element(fullText);
        message = connected ? null : new Element();
        dismiss = connected ? null : new Element(); icon = new Element(); icon.focused = false;
        icon.focus = () => { icon.focused = true; document.activeElement = icon; };
        if (message) { message.setAttribute('data-dismiss-key', storageKey); message.children = [hint, dismiss]; }
        img = image ? new Element() : null;
        if (img) { img.setAttribute('src', 'http://fixture.test/assets/yoro-c-64-static.svg'); img.setAttribute('data-animation-src', 'http://fixture.test/assets/yoro-c-64-2x-3-cycles.svg'); }
        root.querySelector = selector => ({
            '.yoro-admin-launcher__icon img': img,
            '.yoro-admin-launcher__message': message,
            '.yoro-admin-launcher__hint': message && !message.removed ? hint : null,
            '.yoro-admin-launcher__dismiss': message && !message.removed ? dismiss : null,
            '.yoro-admin-launcher__icon': icon
        })[selector] || null;
        return { root, querySelector: selector => selector === '#yoro-admin-launcher' ? root : null };
    } } };
    const document = {
        readyState, baseURI: 'http://fixture.test/wp-admin/',
        getElementById: id => id === 'yoro-admin-launcher-template' ? (removed ? null : template) : active,
        createElement: () => new Element(),
        body: { appendChild(fragment) { active = fragment.root; active.isConnected = true; ++appends; } }
    };
    Object.assign(win, {
        location: { origin: 'http://fixture.test' }, URL: ImageURL, AbortController,
        fetch(url, options) {
            return new Promise((resolve, reject) => {
                const request = { url, options, succeed(blob = { type: 'image/svg+xml' }) { resolve({ ok: true, blob: async () => blob }); }, fail() { reject(Error('offline')); } };
                options.signal.addEventListener('abort', () => reject(Error('aborted')), { once: true });
                requests.push(request);
            });
        },
        localStorage: {
            getItem(key) { if (storageFailure === 'read') throw Error('blocked'); return storage.get(key) ?? null; },
            setItem(key, value) { if (storageFailure === 'write') throw Error('quota'); storage.set(key, value); },
            removeItem(key) { storage.delete(key); }
        },
        matchMedia: () => motion,
        getComputedStyle: () => ({ animationName: animation }),
        setTimeout(callback, delay) { const id = ++nextId; tasks.set(id, { callback, delay }); return id; },
        clearTimeout: id => tasks.delete(id)
    });
    const context = { window: win, document, Date: { now: () => now }, fetch() { throw new Error('Launcher must not fetch or poll.'); } };
    const run = () => vm.runInNewContext(source, context);
    const load = () => { document.readyState = 'complete'; win.emit('load'); };
    const endAnimation = () => hint.emit('animationend', { target: hint, animationName: 'yoro-launcher-speak' });
    const step = () => { const [id, task] = tasks.entries().next().value; tasks.delete(id); task.callback(); return task.delay; };
    const drain = () => { let limit = 200; while (tasks.size && --limit) step(); assert.ok(limit, 'Typing must terminate.'); };
    return { run, load, endAnimation, step, drain, win, motion, tasks, fullText, storage, storageKey, requests, created, revoked,
        get image() { return img; },
        dismissMessage() {
            document.activeElement = dismiss;
            const event = { prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; } };
            dismiss.emit('click', event); return event;
        },
        get visibleMessage() { return !!message && !message.removed; }, get icon() { return icon; },
        get appends() { return appends; }, get root() { return active; }, get hint() { return hint; },
        get typed() { return hint.children.find(child => child.className === 'yoro-admin-launcher__typed'); } };
}

const early = fixture(); early.run(); early.run();
assert.equal(early.appends, 0, 'No active launcher/image nodes before full page load.');
early.load(); assert.equal(early.appends, 1, 'Duplicate scripts must initialize once.');
assert.equal(early.win.count('load'), 0);
assert.equal(early.typed.textContent, '');
assert.equal(early.hint.attrs['aria-label'], early.fullText);
assert.ok(early.hint.children.every(child => child.attrs['aria-hidden'] === 'true'), 'Do not announce individual typed characters.');
early.hint.emit('animationend', { target: {}, animationName: 'yoro-launcher-speak' });
early.hint.emit('animationend', { target: early.hint, animationName: 'foreign' });
assert.equal(early.tasks.size, 0, 'Wait for this bubble’s own entrance.');
early.endAnimation(); assert.equal(early.typed.textContent, 'H');
early.endAnimation(); assert.equal(early.tasks.size, 1, 'Repeated animation events cannot restart typing.');
while (early.typed.textContent !== 'Hi,') early.step();
assert.ok([...early.tasks.values()][0].delay >= 150, 'Punctuation should pause noticeably longer than ordinary characters.');
early.drain(); assert.equal(early.typed.textContent, early.fullText); assert.equal(early.win.count('pagehide'), 0);
early.run(); assert.equal(early.appends, 1);

const late = fixture({ readyState: 'complete' }); late.run();
assert.equal(late.appends, 1); assert.equal(late.win.count('load'), 0);
late.hint.emit('focus'); assert.equal(late.typed.textContent, late.fullText); assert.equal(late.tasks.size, 0);
late.endAnimation(); assert.equal(late.tasks.size, 0, 'Focus completion must not restart on animation end.');

const reduce = fixture({ reduced: true }); reduce.run(); assert.equal(reduce.appends, 0); reduce.load();
assert.equal(reduce.hint.textContent, reduce.fullText); assert.equal(reduce.tasks.size, 0);
const noCss = fixture({ readyState: 'complete', animation: 'none' }); noCss.run();
assert.equal(noCss.hint.textContent, noCss.fullText, 'Missing/disabled CSS must leave the full CTA usable.');
const account = fixture({ connected: true }); account.run(); account.load(); assert.equal(account.appends, 1); assert.equal(account.hint, null);
const absent = fixture({ missing: true }); absent.run(); absent.load(); assert.equal(absent.appends, 0);

for (const action of ['navigation', 'pagehide', 'motion', 'detached']) {
    const current = fixture(); current.run(); current.load(); current.endAnimation();
    if (action === 'navigation') current.root.emit('click');
    if (action === 'pagehide') current.win.emit('pagehide');
    if (action === 'motion') current.motion.emit('change', { matches: true });
    if (action === 'detached') { current.root.isConnected = false; current.step(); }
    assert.equal(current.tasks.size, 0, 'Cancel pending work: ' + action);
    assert.equal(current.typed.textContent, current.fullText, 'Restore complete copy for return/navigation: ' + action);
    assert.equal(current.win.count('pagehide'), 0); assert.equal(current.motion.count('change'), 0);
}
console.log('YoRo launcher load/late-load/deduplication/typing/focus/reduced-motion/cleanup JS contract passed.');

const sharedStorage = new Map();
const dismissing = fixture({ storage: sharedStorage }); dismissing.run(); dismissing.load(); dismissing.endAnimation();
assert.equal(dismissing.tasks.size, 1);
const click = dismissing.dismissMessage();
assert.ok(click.prevented && click.stopped, 'Dismiss must not navigate or bubble to other launcher click handling.');
assert.equal(dismissing.visibleMessage, false); assert.ok(dismissing.icon.focused, 'Move keyboard focus to the remaining icon.');
assert.equal(dismissing.tasks.size, 0); assert.equal(dismissing.win.count('pagehide'), 0); assert.equal(dismissing.motion.count('change'), 0);
assert.equal(sharedStorage.get(dismissing.storageKey), String(1000000000 + 3600000), 'Persist exactly one hour, without message/account content.');
for (const elapsed of [0, 3599999]) {
    const reload = fixture({ storage: sharedStorage, now: 1000000000 + elapsed }); reload.run(); reload.load();
    assert.equal(reload.appends, 1, 'Keep the icon even while dismissed.');
    assert.equal(reload.visibleMessage, false, 'Remain dismissed across navigation until exact expiry.'); assert.equal(reload.tasks.size, 0);
}
for (const options of [{ site: 'site-b' }, { user: 2 }]) {
    const isolated = fixture({ storage: sharedStorage, ...options }); isolated.run(); isolated.load();
    assert.equal(isolated.visibleMessage, true, 'Do not dismiss another site or account.');
}
const expired = fixture({ storage: sharedStorage, now: 1000000000 + 3600000 }); expired.run(); expired.load();
assert.equal(expired.visibleMessage, true, 'At exact expiry show normal bubble on initialization.'); assert.equal(sharedStorage.has(expired.storageKey), false);
for (const bad of ['', 'NaN', 'Infinity', '-1', '1000000000.5', '99999999999999999999999', '1003600001', '{}']) {
    const invalid = fixture(); invalid.storage.set(invalid.storageKey, bad); invalid.run(); invalid.load();
    assert.equal(invalid.visibleMessage, true, 'Invalid/expired/out-of-range expiry must not hide the message: ' + bad);
}
for (const storageFailure of ['read', 'write']) {
    const blocked = fixture({ storageFailure }); blocked.run(); blocked.load(); blocked.endAnimation(); blocked.dismissMessage();
    assert.equal(blocked.visibleMessage, false, 'Storage failure still allows current-page dismissal.'); assert.equal(blocked.tasks.size, 0);
}
for (const options of [{ reduced: true }, { animation: 'none' }]) {
    const accessible = fixture(options); accessible.run(); accessible.load(); accessible.dismissMessage();
    assert.equal(accessible.visibleMessage, false, 'Dismiss also works with reduced motion or no entrance CSS.');
}
console.log('YoRo one-hour dismiss, exact expiry, site/user isolation, invalid/blocked storage, focus and typing cleanup passed.');

(async function iconContracts() {
    const settle = async () => { for (let i = 0; i < 5; i++) await Promise.resolve(); };
    const initial = fixture({ image: true }); initial.run();
    assert.equal(initial.requests.length, 0, 'No asset preparation before full window load.');
    initial.load(); assert.equal(initial.requests.length, 1); assert.equal(initial.image.getAttribute('src'), null);
    assert.equal(initial.image.style.visibility, 'hidden', 'Do not show a reused decoded animation before the fresh playback is ready.');
    const request = initial.requests[0];
    assert.equal(request.options.cache, 'force-cache'); assert.equal(request.options.mode, 'same-origin'); assert.equal(request.options.redirect, 'error');
    request.succeed(); await settle();
    assert.equal(initial.image.getAttribute('src'), 'blob:fixture/1'); assert.equal(initial.tasks.size, 0);
    initial.image.emit('load'); assert.equal(initial.image.style.visibility, '');
    initial.win.emit('pageshow', { persisted: false }); assert.equal(initial.created.length, 1, 'Initial pageshow cannot restart a playback.');
    initial.win.emit('pagehide', { persisted: true }); assert.deepEqual(initial.revoked, ['blob:fixture/1']);
    initial.storage.set(initial.storageKey, String(1000000000 + 3600000));
    initial.win.emit('pageshow', { persisted: true }); await settle();
    assert.equal(initial.requests.length, 1, 'Back/Forward reuses SVG bytes, not a completed image document.');
    assert.equal(initial.image.getAttribute('src'), 'blob:fixture/2'); assert.equal(initial.visibleMessage, false, 'A BFCache-restored bubble must obey a dismissal made on another page.');
    initial.image.emit('load');
    initial.motion.matches = true; initial.motion.emit('change', { matches: true });
    assert.equal(initial.image.getAttribute('src'), 'http://fixture.test/assets/yoro-c-64-static.svg');
    assert.ok(initial.revoked.includes('blob:fixture/2'));
    initial.motion.matches = false; initial.motion.emit('change', { matches: false }); await settle();
    assert.equal(initial.image.getAttribute('src'), 'blob:fixture/3'); assert.equal(initial.requests.length, 1);
    initial.win.emit('pagehide', { persisted: false });
    assert.ok(initial.revoked.includes('blob:fixture/3')); assert.equal(initial.win.count('pageshow'), 0); assert.equal(initial.win.count('pagehide'), 0); assert.equal(initial.motion.count('change'), 0); assert.equal(initial.tasks.size, 0);

    const reducedIcon = fixture({ image: true, reduced: true }); reducedIcon.run(); reducedIcon.load();
    assert.equal(reducedIcon.requests.length, 0); assert.equal(reducedIcon.image.getAttribute('src'), 'http://fixture.test/assets/yoro-c-64-static.svg');
    for (const options of [{ connected: true }, { storage: new Map([['yoro-launcher-dismiss:site-a:1', '1003600000']]) }]) {
        const withoutBubble = fixture({ image: true, ...options }); withoutBubble.run(); withoutBubble.load(); withoutBubble.requests[0].succeed(); await settle();
        assert.equal(withoutBubble.image.getAttribute('src'), 'blob:fixture/1', 'Icon playback cannot depend on the bubble being present.');
    }
    const stale = fixture({ image: true }); stale.run(); stale.load(); stale.win.emit('pagehide', { persisted: true });
    stale.requests[0].succeed(); await settle(); assert.equal(stale.created.length, 0, 'Late preparation cannot mutate a hidden/disposed page.');
    stale.win.emit('pageshow', { persisted: true }); assert.equal(stale.requests.length, 2); stale.requests[1].succeed(); await settle();
    assert.equal(stale.created.length, 1);
    for (const failure of ['fetch', 'timeout', 'object-url', 'image-csp']) {
        const fallback = fixture({ image: true, createFails: failure === 'object-url' }); fallback.run(); fallback.load();
        if (failure === 'fetch') fallback.requests[0].fail();
        else if (failure === 'timeout') fallback.step();
        else fallback.requests[0].succeed();
        await settle();
        if (failure === 'image-csp') fallback.image.emit('error');
        assert.match(fallback.image.getAttribute('src'), /^http:\/\/fixture.test\/assets\/yoro-c-64-2x-3-cycles\.svg\?yoro_play=/, 'Same-origin fresh playback fallback: ' + failure);
        fallback.image.emit('error'); assert.equal(fallback.image.getAttribute('src'), 'http://fixture.test/assets/yoro-c-64-static.svg', 'If the animation itself is unavailable, keep a usable static icon.');
        fallback.image.emit('load'); assert.equal(fallback.image.style.visibility, '');
        fallback.win.emit('pagehide', { persisted: false }); assert.equal(fallback.tasks.size, 0);
    }
    console.log('YoRo cached-SVG fresh playback, BFCache, motion changes, abort/timeout/CSP fallbacks, object URL cleanup and independent icon JS contracts passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
