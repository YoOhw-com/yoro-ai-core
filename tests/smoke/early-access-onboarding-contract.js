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
    let now = Date.now(); let copied = ''; let timer; let clipboardAvailable = true; let copyReset; let pendingWrites;
    const tabs = ['chatgpt', 'early-access'].map(name => new Element({ 'aria-controls': `panel-${name}` }));
    const panels = Object.fromEntries(tabs.map(tab => [`#${tab.getAttribute('aria-controls')}`, new Element()]));
    const grant = new Element(); grant.textContent = 'ChatGPT connection: Connected'; grant.dataset = { expires: new Date(now + 1000).toISOString() };
    const feedback = new Element(); const copy = new Element();
    copy.textContent = 'Copy URL';
    const input = new Element(); input.value = 'https://fixture-api.test/mcp/early-access';
    const root = { querySelectorAll: () => tabs, querySelector: selector => ({ ...panels, '[data-yoro-feedback]': feedback, '[data-yoro-copy-url]': copy, '#yoro-server-url': input, '[data-yoro-grant-status]': grant })[selector] };
    const context = { document: { querySelector: () => root }, navigator: { clipboard: { writeText: async value => {
        if (!clipboardAvailable) throw new Error('Clipboard unavailable');
        if (pendingWrites) await new Promise((resolve, reject) => { pendingWrites.push({ resolve, reject }); });
        copied = value;
    } } },
        window: { setInterval: callback => { timer = callback; }, setTimeout: (callback, delay) => { assert.equal(delay, 2000); copyReset = callback; return 1; }, clearTimeout: () => { copyReset = undefined; } },
        fetch: async () => { throw new Error('The public URL UI must not require a network refresh.'); },
        Date: class extends Date { static now() { return now; } }, Number };
    vm.runInNewContext(source, context);
    let prevented = false;
    tabs[1].events.keydown({ key: 'ArrowLeft', preventDefault() { prevented = true; } });
    assert.equal(prevented, true); assert.equal(tabs[0].attrs['aria-selected'], 'true'); assert.equal(tabs[0].focused, true); assert.equal(panels['#panel-early-access'].hidden, true);
    tabs[0].events.keydown({ key: 'End', preventDefault() {} });
    assert.equal(tabs[1].attrs['aria-selected'], 'true'); assert.equal(tabs[0].tabIndex, -1);
    await copy.events.click(); assert.equal(copied, input.value); assert.equal(copy.textContent, 'Copied!');
    copyReset(); assert.equal(copy.textContent, 'Copy URL');
    now += 2000; timer(); assert.equal(input.value, 'https://fixture-api.test/mcp/early-access'); assert.equal(copy.disabled, false); assert.equal(grant.textContent, 'ChatGPT connection: Not yet verified');
    copied = ''; await copy.events.click(); assert.equal(copied, input.value);
    grant.dataset.expires = 'malformed'; grant.textContent = 'ChatGPT connection: Connected'; timer();
    assert.equal(grant.textContent, 'ChatGPT connection: Not yet verified');
    assert.equal(input.value, copied);
    clipboardAvailable = false; await copy.events.click(); assert.equal(input.focused, true); assert.equal(input.selected, true);
    assert.equal(copy.textContent, 'Copy URL'); assert.equal(copyReset, undefined);
    clipboardAvailable = true; pendingWrites = [];
    const firstClick = copy.events.click(); const secondClick = copy.events.click();
    pendingWrites[0].resolve(); await firstClick;
    assert.equal(copy.textContent, 'Copy URL'); assert.equal(copyReset, undefined);
    pendingWrites[1].resolve(); await secondClick;
    assert.equal(copy.textContent, 'Copied!'); copyReset(); assert.equal(copy.textContent, 'Copy URL');
    pendingWrites = [];
    const staleClick = copy.events.click(); const failedClick = copy.events.click();
    pendingWrites[1].reject(new Error('Clipboard unavailable')); await failedClick;
    pendingWrites[0].resolve(); await staleClick;
    assert.equal(copy.textContent, 'Copy URL'); assert.equal(copyReset, undefined);
    input.value = ''; copied = ''; await copy.events.click(); assert.equal(copied, '');
    console.log('YoRo Early Access keyboard/public URL copy/status expiry JS contract passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
