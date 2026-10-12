'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/yoro-onboarding.js'), 'utf8');
class Element {
    constructor(attrs = {}) { this.attrs = attrs; this.events = {}; this.hidden = false; this.disabled = false; this.tabIndex = 0; this.textContent = ''; }
    getAttribute(name) { return this.attrs[name]; }
    setAttribute(name, value) { this.attrs[name] = value; }
    addEventListener(name, callback) { this.events[name] = callback; }
    focus() { this.focused = true; }
    select() { this.selected = true; }
}
(async () => {
    let now = Date.now(); let copied = ''; let reloaded = false; let timer;
    const tabs = ['chatgpt', 'early-access'].map(name => new Element({ 'aria-controls': `panel-${name}` }));
    const panels = Object.fromEntries(tabs.map(tab => [`#${tab.getAttribute('aria-controls')}`, new Element()]));
    const feedback = new Element(); const copy = new Element(); const refresh = new Element();
    const input = new Element(); input.value = 'https://fixture-api.test/mcp/early-access';
    const box = { dataset: { expires: new Date(now + 1000).toISOString() }, querySelector: selector => selector === 'input' ? input : copy };
    const root = { querySelectorAll: () => tabs, querySelector: selector => ({ ...panels, '[data-yoro-feedback]': feedback, '[data-yoro-copy-url]': copy, '[data-yoro-refresh]': refresh, '#yoro-server-url': input, '[data-yoro-server-url]': box })[selector] };
    const context = { document: { querySelector: () => root }, navigator: { clipboard: { writeText: async value => { copied = value; } } },
        window: { setInterval: callback => { timer = callback; }, location: { reload: () => { reloaded = true; } } },
        YoRoOnboarding: { handshakeUrl: '/fixture/handshake', nonce: 'fixture-nonce' },
        fetch: async (_url, args) => { assert.equal(args.headers['X-WP-Nonce'], 'fixture-nonce'); return { ok: false }; },
        Date: class extends Date { static now() { return now; } }, Number };
    vm.runInNewContext(source, context);
    let prevented = false;
    tabs[1].events.keydown({ key: 'ArrowLeft', preventDefault() { prevented = true; } });
    assert.equal(prevented, true); assert.equal(tabs[0].attrs['aria-selected'], 'true'); assert.equal(tabs[0].focused, true); assert.equal(panels['#panel-early-access'].hidden, true);
    tabs[0].events.keydown({ key: 'End', preventDefault() {} });
    assert.equal(tabs[1].attrs['aria-selected'], 'true'); assert.equal(tabs[0].tabIndex, -1);
    await copy.events.click(); assert.equal(copied, input.value);
    await refresh.events.click({ currentTarget: refresh }); assert.equal(input.value, ''); assert.equal(copy.disabled, true); assert.equal(refresh.disabled, false); assert.equal(reloaded, false);
    input.value = 'https://fixture-api.test/mcp/early-access'; copy.disabled = false;
    now += 2000; timer(); assert.equal(input.value, ''); assert.equal(copy.disabled, true);
    copied = ''; await copy.events.click(); assert.equal(copied, '');
    input.value = 'forged'; box.dataset.expires = 'malformed'; timer(); assert.equal(input.value, '');
    console.log('YoRo Early Access keyboard/copy/expiry/refresh JS contract passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
