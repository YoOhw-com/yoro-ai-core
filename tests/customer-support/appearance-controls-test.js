'use strict';
// Full admin script in a small DOM harness, not browser rendering or native-picker UI proof.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict'), path = require('node:path');
class Node {
    constructor(value = '') { this.value = value; this.dataset = {}; this.attrs = {}; this.listeners = {}; this.hidden = false; this.textContent = ''; this.classList = {toggle() {}}; this.style = {setProperty: (key, value) => { this.accent = value; }}; }
    addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
    event(type, extra = {}) { for (const fn of this.listeners[type] || []) fn({target: this, preventDefault() {}, ...extra}); }
    setAttribute(key, value) { this.attrs[key] = value; }
    getAttribute(key) { return this.attrs[key]; }
    setCustomValidity(value) { this.validityMessage = value; }
    matches() { return false; }
    querySelector(selector) { return this.nodes[selector]; }
    querySelectorAll(selector) { return this.lists?.[selector] || []; }
    showModal() { this.open = true; }
    close() { this.open = false; }
}
const make = value => new Node(value), form = make(), preview = make(), picker = make('#2b3a20'), hex = make('#2b3a20');
const name = make('YoRo'), welcome = make('How can we help?'), launcher = make('Chat support'), icon = make('yoro-animated');
form.elements = {'appearance[color]': hex, 'appearance[name]': name, 'appearance[welcome]': welcome, 'appearance[launcher]': launcher, 'appearance[icon]': icon};
form.nodes = {'[data-source-search]': make(), '[data-selected-sources]': make(), '[data-preview]': make()};
preview.nodes = {'[data-preview-name]': make(), '[data-preview-welcome]': make(), '[data-preview-launcher]': make(), '[data-preview-close]': make()};
const choices = ['yoro-animated', 'yoro-static', 'chat'].map(key => { const node = make(); node.dataset.previewIcon = key; return node; });
function image(src) { const node = make(); node.setAttribute('src', src); node.dataset.static = '/fixed-static.svg'; const fallback = make(); fallback.hidden = true; node.parentElement = {querySelector: () => fallback}; return node; }
const animated = image('/fixed-3-cycles.svg'), staticImage = image('/fixed-static.svg');
preview.lists = {'[data-preview-icon]': choices, '[data-support-icon]': [animated, staticImage]};
let motionChanged; const motion = {matches: false, addEventListener(type, fn) { motionChanged = fn; }};
const sandbox = {document: {getElementById: id => id === 'yoro-support-settings' ? form : picker, querySelector: () => preview, querySelectorAll: () => []}, window: {addEventListener() {}, matchMedia: () => motion}, console, URLSearchParams, clearTimeout() {}, setTimeout() { throw new Error('Unexpected deferred action'); }, fetch() { throw new Error('Appearance must not fetch'); }};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../assets/js/yoro-support-admin.js'), 'utf8'), sandbox);
let checks = 0; function verify(condition, message) { assert.ok(condition, message); checks++; }
verify(preview.accent === '#2b3a20' && !choices[0].hidden && choices[1].hidden && choices[2].hidden, 'Default preview matches defaults');
picker.value = '#123456'; picker.event('input'); verify(hex.value === '#123456' && preview.accent === '#123456', 'Picker input updates hex and preview');
picker.value = '#345678'; picker.event('change'); verify(hex.value === '#345678', 'Picker change updates hex');
hex.value = '#Ab12CD'; hex.event('input'); verify(picker.value === '#Ab12CD' && preview.accent === '#Ab12CD' && hex.validityMessage === '', 'Valid hex input updates picker');
hex.value = '#102030'; hex.event('change'); verify(picker.value === '#102030' && preview.accent === '#102030', 'Keyboard hex change updates picker');
for (const invalid of ['', '#', '#12', '#12345g', '#12345678', '#ffffff;display:none']) {
    hex.value = invalid; hex.event('input'); verify(picker.value === '#102030' && preview.accent === '#102030' && hex.attrs['aria-invalid'] === 'true' && hex.validityMessage !== '', 'Invalid/partial hex leaves last valid preview intact');
}
hex.value = '#ffffff'; hex.event('change'); verify(picker.value === '#ffffff' && preview.accent === '#2b3a20' && hex.attrs['aria-invalid'] === 'false', 'Light custom color is preserved, effective preview uses accessible fallback');
name.value = '<img onerror=evil>'; welcome.value = 'Custom welcome'; launcher.value = 'Help me'; form.event('input');
verify(preview.nodes['[data-preview-name]'].textContent === name.value && preview.nodes['[data-preview-welcome]'].textContent === welcome.value && preview.nodes['[data-preview-launcher]'].textContent === launcher.value, 'User strings are assigned as text');
for (const choice of choices) { icon.value = choice.dataset.previewIcon; form.event('change'); verify(choices.filter(item => !item.hidden).length === 1 && !choice.hidden, 'Live closed icon selection'); }
form.nodes['[data-preview]'].event('click'); verify(preview.open === true, 'Preview opens without request'); preview.nodes['[data-preview-close]'].event('click'); verify(preview.open === false, 'Preview closes');
anotherFailure();
function anotherFailure() { animated.event('error'); verify(animated.getAttribute('src') === '/fixed-static.svg' && !animated.hidden, 'Animated load error falls back to static'); animated.event('error'); verify(animated.hidden && !animated.parentElement.querySelector().hidden, 'Static load error exposes safe chat fallback'); staticImage.event('error'); verify(staticImage.hidden && !staticImage.parentElement.querySelector().hidden, 'Static selected icon failure exposes fallback'); }
animated.setAttribute('src', '/fixed-3-cycles.svg'); motionChanged({matches: true}); verify(animated.getAttribute('src') === '/fixed-static.svg', 'Motion preference change stops animated asset'); motionChanged({matches: false}); verify(animated.getAttribute('src') === '/fixed-static.svg', 'Returning motion does not restart animation');
animated.setAttribute('src', '/fixed-3-cycles.svg');
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../assets/js/yoro-support-admin.js'), 'utf8'), {...sandbox, window: {addEventListener() {}, matchMedia: () => ({matches: true})}});
verify(animated.getAttribute('src') === '/fixed-static.svg', 'Initial reduced motion never leaves animated asset active');
console.log(JSON.stringify({suite: 'appearance-controls', result: 'passed', assertions: checks, browser_rendering: 'NOT_EVALUATED', network: 0, provider: 0}));
