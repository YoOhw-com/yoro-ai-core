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
    constructor(text = '') { super(); this.text = text; this.children = []; this.attrs = {}; this.isConnected = false; }
    get textContent() { return this.children.length ? this.children.map(child => child.textContent).join('') : this.text; }
    set textContent(value) { this.text = value; this.children = []; }
    setAttribute(name, value) { this.attrs[name] = value; }
    getAttribute(name) { return this.attrs[name] ?? null; }
    contains(element) { return element === this || this.children.some(child => child.contains(element)); }
    remove() { this.removed = true; }
    focus() { this.emit('focus'); }
    replaceChildren(...children) { this.children = children; this.text = ''; }
}
function fixture({ readyState = 'loading', reduced = false, connected = false, animation = 'yoro-launcher-speak', missing = false, storage = new Map(), now = 1000000000, storageFailure = '', site = 'site-a', user = 1 } = {}) {
    const win = new Events();
    const motion = new Events(); motion.matches = reduced;
    const fullText = 'Hi, I’m YoRo! Let’s connect your account and get started.';
    const tasks = new Map(); let nextId = 0; let removed = missing; let active = null; let appends = 0; let hint = null;
    const storageKey = 'yoro-launcher-dismiss:' + site + ':' + user;
    let message, dismiss, icon;
    const template = { remove() { removed = true; }, content: { cloneNode() {
        const root = new Element();
        hint = connected ? null : new Element(fullText);
        message = connected ? null : new Element();
        dismiss = connected ? null : new Element(); icon = new Element(); icon.focused = false;
        icon.focus = () => { icon.focused = true; document.activeElement = icon; };
        if (message) { message.setAttribute('data-dismiss-key', storageKey); message.children = [hint, dismiss]; }
        root.querySelector = selector => ({
            '.yoro-admin-launcher__message': message,
            '.yoro-admin-launcher__hint': message && !message.removed ? hint : null,
            '.yoro-admin-launcher__dismiss': message && !message.removed ? dismiss : null,
            '.yoro-admin-launcher__icon': icon
        })[selector] || null;
        return { root, querySelector: selector => selector === '#yoro-admin-launcher' ? root : null };
    } } };
    const document = {
        readyState,
        getElementById: id => id === 'yoro-admin-launcher-template' ? (removed ? null : template) : active,
        createElement: () => new Element(),
        body: { appendChild(fragment) { active = fragment.root; active.isConnected = true; ++appends; } }
    };
    Object.assign(win, {
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
    return { run, load, endAnimation, step, drain, win, motion, tasks, fullText, storage, storageKey,
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
