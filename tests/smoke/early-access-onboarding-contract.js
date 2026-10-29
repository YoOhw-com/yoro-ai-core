'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/yoro-onboarding.js'), 'utf8');
class Element {
    constructor(attrs = {}) { this.attrs = attrs; this.events = {}; this.dataset = {}; this.hidden = false; this.disabled = false; this.textContent = ''; }
    getAttribute(name) { return this.attrs[name]; }
    setAttribute(name, value) { this.attrs[name] = value; }
    addEventListener(name, callback) { this.events[name] = callback; }
    focus() { this.focused = true; }
    select() { this.selected = true; }
    closest() { return this.notice; }
    remove() { this.removed = true; }
}
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
function fixture(initial = 'granted', configOverrides = {}) {
    let now = Date.parse('2026-10-29T00:00:00Z'); let timerId = 0; let clipboard = true; let copied; let pendingWrites;
    const timers = new Map(); const calls = []; const replies = [];
    const projection = (grant = 'granted', overrides = {}) => ({ status: 'available', grant_status: grant, runtime_enabled: true,
        connection_healthy: true, compatible: true, checked_at: new Date(now).toISOString(), expires_at: new Date(now + 300000).toISOString(), ...overrides });
    const labels = { checking: 'Checking', unverified: 'Unverified; retrying', unavailable: 'Unavailable; retrying', wordpress: 'Repair WordPress connection',
        granted: 'Connected', not_granted: 'Sign in from ChatGPT', revoked: 'Revoked; reconnect from ChatGPT', unknown: 'Not yet verified', copied: 'Copied!', copy: 'Select and copy' };
    const config = { url: '/wp-json/yoro-ai-core/v1/ai-api/onboarding-status', nonce: 'fixture-nonce', context: 'fixture-context', labels,
        projection: initial === null ? {} : projection(initial), ...configOverrides };
    const grant = new Element(); const feedback = new Element(); const signIn = new Element(); const store = new Element();
    const copy = new Element(); copy.textContent = 'Copy URL'; const input = new Element(); input.value = 'https://fixture-api.test/mcp/early-access';
    const tabs = ['chatgpt', 'early-access'].map(name => new Element({ 'aria-controls': `panel-${name}` }));
    const panels = Object.fromEntries(tabs.map(tab => [`#${tab.getAttribute('aria-controls')}`, new Element()]));
    const notice = new Element(); const dismiss = new Element(); dismiss.notice = notice;
    const root = { dataset: { refreshContext: config.context },
        querySelectorAll: selector => ({ '[data-yoro-tab]': tabs, '[data-yoro-notice-dismiss]': [dismiss] })[selector] || [],
        querySelector: selector => selector === '[data-yoro-tab][aria-selected="true"]' ? tabs.find(tab => tab.attrs['aria-selected'] === 'true') : ({ ...panels,
            '[data-yoro-refresh-config]': { textContent: JSON.stringify(config) }, '[data-yoro-feedback]': feedback, '[data-yoro-copy-url]': copy,
            '#yoro-server-url': input, '[data-yoro-grant-status]': grant, '[data-yoro-sign-in]': signIn, '[data-yoro-store-unverified]': store })[selector] };
    const doc = { hidden: false, events: {}, querySelector: () => root, addEventListener(name, cb) { this.events[name] = cb; } };
    const fetch = (url, options) => {
        calls.push({ url, options, at: now });
        const reply = replies.length ? replies.shift() : {};
        if (reply.pending) return new Promise(resolve => { reply.resolve = resolve; });
        if (reply.error) return Promise.reject(new Error('Offline'));
        const data = reply.data || { state: 'unverified', context: config.context, projection: projection(reply.grant || 'granted', reply.overrides) };
        return Promise.resolve({ ok: !reply.code || reply.code < 400, status: reply.code || 200, json: async () => data });
    };
    vm.runInNewContext(source, { document: doc, navigator: { clipboard: { writeText: async value => { if (!clipboard) throw new Error('Clipboard unavailable'); if (pendingWrites) await new Promise((resolve, reject) => pendingWrites.push({ resolve, reject })); copied = value; } } },
        window: { setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, { callback, at: now + delay }); return id; }, clearTimeout: id => timers.delete(id) },
        fetch, AbortController, Date: class extends Date { static now() { return now; } }, Number });
    const advance = async (ms) => {
        const target = now + ms; let count = 0;
        while (true) {
            await flush();
            const next = [...timers.entries()].sort((a,b) => a[1].at - b[1].at)[0];
            if (!next || next[1].at > target) break;
            assert.ok(++count < 1000, 'No timer storm'); now = next[1].at; timers.delete(next[0]); next[1].callback();
        }
        now = target; await flush();
    };
    return { advance, calls, replies, projection, labels, grant, feedback, signIn, copy, input, tabs, panels, dismiss, notice, root,
        hide: () => { doc.hidden = true; doc.events.visibilitychange(); }, show: () => { doc.hidden = false; doc.events.visibilitychange(); },
        copied: () => copied, clipboard: value => { clipboard = value; }, pendingWrites: value => { pendingWrites = value; }, timers };
}
(async () => {
    const f = fixture();
    assert.equal(f.grant.textContent, 'Connected'); assert.equal(f.signIn.hidden, true); assert.equal(f.calls.length, 0);
    await f.advance(270000); assert.equal(f.calls.length, 1); assert.equal(f.grant.textContent, 'Connected');
    await f.advance(270000); assert.equal(f.calls.length, 2); assert.equal(f.grant.textContent, 'Connected');
    await f.advance(270000); assert.equal(f.calls.length, 3); assert.equal(f.signIn.hidden, true);
    assert.equal(f.calls[0].options.credentials, 'same-origin'); assert.equal(f.calls[0].options.headers['X-WP-Nonce'], 'fixture-nonce');
    assert.equal(JSON.parse(f.calls[0].options.body).context, 'fixture-context');
    for (const state of ['not_granted', 'revoked', 'unknown']) {
        f.replies.push({ grant: state }); await f.advance(270000);
        assert.equal(f.grant.textContent, f.labels[state]); assert.equal(f.signIn.hidden, state === 'unknown');
    }
    f.replies.push({ grant: 'granted' }); await f.advance(270000); assert.equal(f.grant.textContent, 'Connected');
    let prevented = false; f.tabs[1].events.keydown({ key: 'ArrowLeft', preventDefault() { prevented = true; } });
    assert.equal(prevented, true); assert.equal(f.tabs[0].attrs['aria-selected'], 'true'); assert.equal(f.tabs[0].focused, true);
    f.tabs[0].events.keydown({ key: 'End', preventDefault() {} }); assert.equal(f.tabs[1].attrs['aria-selected'], 'true');
    f.dismiss.events.click(); assert.equal(f.notice.removed, true); assert.equal(f.tabs[1].focused, true);
    await f.copy.events.click(); assert.equal(f.copied(), f.input.value); await f.advance(2000); assert.equal(f.copy.textContent, 'Copy URL');
    f.clipboard(false); await f.copy.events.click(); assert.equal(f.input.selected, true); assert.equal(f.copy.textContent, 'Select and copy');
    f.clipboard(true); const writes = []; f.pendingWrites(writes);
    const firstCopy = f.copy.events.click(); const secondCopy = f.copy.events.click();
    writes[0].resolve(); await firstCopy; assert.equal(f.copy.textContent, 'Copy URL');
    writes[1].resolve(); await secondCopy; assert.equal(f.copy.textContent, 'Copied!');
    await f.advance(2000); const races = []; f.pendingWrites(races);
    const staleCopy = f.copy.events.click(); const failedCopy = f.copy.events.click();
    races[1].reject(new Error('Clipboard unavailable')); await failedCopy;
    races[0].resolve(); await staleCopy; assert.equal(f.copy.textContent, 'Select and copy');
    await f.advance(2000); assert.equal(f.copy.textContent, 'Select and copy', 'Stale copy cannot create a reset timer');
    f.input.value = ''; const noURL = f.copied(); await f.copy.events.click(); assert.equal(f.copied(), noURL);
    const missing = fixture(null); assert.equal(missing.calls.length, 1); await flush(); assert.equal(missing.grant.textContent, 'Connected');
    const stale = fixture('granted', { projection: { expires_at: 'yesterday' } }); await flush(); assert.equal(stale.calls.length, 1);
    const noConfig = fixture(null, { url: '' }); await noConfig.advance(100000); assert.equal(noConfig.calls.length, 0); assert.equal(noConfig.timers.size, 0);
    for (const reply of [{ error: true }, ...[401,403,429,500,503].map(code => ({ code })),
        { overrides: { expires_at: 'malformed' } }, { overrides: { runtime_enabled: false } }, { overrides: { connection_healthy: false } },
        { overrides: { compatible: false } }, { overrides: { grant_status: 'forged' } }]) {
        const fail = fixture(); fail.replies.push(reply); await fail.advance(270000);
        assert.notEqual(fail.grant.textContent, 'Connected'); assert.equal(fail.signIn.hidden, true);
        await fail.copy.events.click(); assert.equal(fail.copied(), fail.input.value); assert.equal(fail.copy.disabled, false);
        await fail.advance(30000); assert.equal(fail.grant.textContent, 'Connected');
    }
    const dark = fixture(); dark.replies.push({ grant: 'unknown', overrides: { runtime_enabled: false } }); await dark.advance(270000);
    assert.equal(dark.grant.textContent, 'Not yet verified'); assert.equal(dark.signIn.hidden, true);
    const hidden = fixture(); hidden.hide(); await hidden.advance(600000); assert.equal(hidden.calls.length, 0);
    hidden.show(); assert.equal(hidden.calls.length, 1); assert.notEqual(hidden.grant.textContent, 'Connected'); await flush(); assert.equal(hidden.grant.textContent, 'Connected');
    const pending = fixture(); const late = { pending: true }; pending.replies.push(late); await pending.advance(270000);
    pending.show(); pending.show(); assert.equal(pending.calls.length, 1); await pending.advance(20000);
    assert.equal(pending.calls[0].options.signal.aborted, true); assert.equal(pending.signIn.hidden, true); assert.notEqual(pending.grant.textContent, 'Connected');
    await pending.advance(30000); assert.equal(pending.calls.length, 2); assert.equal(pending.grant.textContent, 'Connected');
    late.resolve({ ok: true, json: async () => ({ state: 'unverified', context: 'fixture-context', projection: pending.projection('revoked') }) });
    await flush(); assert.equal(pending.grant.textContent, 'Connected', 'Late timed-out response cannot supersede newer result');
    const backoff = fixture(); for (let i = 0; i < 6; i++) backoff.replies.push({ code: 503 });
    await backoff.advance(270000 + 30000 + 60000 + 120000 * 3);
    assert.deepEqual(backoff.calls.slice(1).map((call,index) => call.at - backoff.calls[index].at), [30000,60000,120000,120000,120000]);
    const changed = fixture(); const flight = { pending: true }; changed.replies.push(flight); await changed.advance(270000);
    changed.root.dataset.refreshContext = 'new-connection'; flight.resolve({ ok: true, json: async () => ({ context: 'fixture-context', state: 'unverified', projection: changed.projection() }) });
    await flush(); assert.equal(changed.grant.textContent, changed.labels.wordpress); await changed.advance(600000); assert.equal(changed.calls.length, 1);
    const serverChange = fixture(); serverChange.replies.push({ data: { context: 'new-context', state: 'connection_changed', projection: {} } });
    await serverChange.advance(270000); assert.equal(serverChange.grant.textContent, serverChange.labels.wordpress); assert.equal(serverChange.signIn.hidden, true);
    const older = fixture(); older.replies.push({ overrides: { checked_at: '2026-10-28T23:59:59Z', expires_at: '2026-10-29T00:04:59Z' }, grant: 'revoked' });
    await older.advance(270000); assert.equal(older.grant.textContent, 'Connected', 'Older API response cannot overwrite newer current projection');
    console.log('YoRo source-loaded expiry cycles, grant states, failures, timeout/backoff, visibility, concurrency, context, keyboard and Copy URL contracts passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
