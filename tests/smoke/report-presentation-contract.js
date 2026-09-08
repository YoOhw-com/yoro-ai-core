#!/usr/bin/env node
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const sandbox = { window: {}, console };
vm.createContext(sandbox);
for (const file of ['yoro-ai-order-ui.js', 'yoro-ai-result-renderers.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, 'assets/js', file), 'utf8'), sandbox, { filename: file });
}
const escapeHtml = value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
let conversation = 'conversation-one';
let calls = 0;
const helpers = {
    escapeHtml, getConversationState: () => ({ conversation_id: conversation }),
    formatDateTime: x => x, renderDebugJson: () => '', shouldShowDebugJson: () => false,
    executeActionDirect: () => { calls++; }, addAssistantMessage: () => { calls++; },
    updateConversationState: () => { calls++; }, openModal: () => { calls++; }
};
const ui = sandbox.window.YoRoOrderUI.init(helpers);
const renderer = sandbox.window.YoRoResultRenderers.init({ ...helpers, renderReportResult: ui.renderReportResult });
const base = {
    version: 1, conversation_id: conversation, action_event_id: 42, result_id: conversation + ':42',
    action: 'store.get_business_summary', operation_id: 'op_report', mode: 'summary_first', state: 'complete',
    summary: 'Revenue 1200 USD. Orders 0.', has_details: true, expanded: false,
    open_label: 'View full report', close_label: 'Collapse report',
    report: { type: 'sales_report', headline: 'Report', window: { key: 'today', label: 'Today', timezone: 'UTC' },
        metrics: [{ key: 'gross_sales', label: 'Gross sales', value: '1200', unit: 'USD' }, { key: 'orders', label: 'Orders', value: '0' }], alerts: [] }
};
function render(p = base) {
    return ui.renderReportResult({ conversation_id: p.conversation_id, result: { action: p.action, report_presentation: p } });
}
const html = render();
assert.equal((html.match(/class="button yoro-report-toggle"/g) || []).length, 1);
assert.match(html, /type="button"[^>]+aria-expanded="false"/);
assert.match(html, /class="yoro-report-details"[^>]+ hidden/);
assert.match(html, /1200/);
const id = html.match(/aria-controls="([^"]+)"/)[1];
assert.match(html, new RegExp('id="' + id + '"'));
assert.notEqual(render().match(/aria-controls="([^"]+)"/)[1], id, 'Hydrated copies have unique DOM ids');
assert.match(render({ ...base, mode: 'full', expanded: true }), /aria-expanded="true"/);
for (const mode of ['summary_only', 'direct_metric']) {
    assert.doesNotMatch(render({ ...base, mode, has_details: false }), /yoro-report-toggle|yoro-report-details/);
}
assert.match(render({ ...base, mode: 'direct_list', expanded: true }), /aria-expanded="true"/);
assert.doesNotMatch(render({ ...base, state: 'error', has_details: false, report: {} }), /yoro-report-toggle|yoro-report-details/);
assert.doesNotMatch(render({ ...base, result_id: 'wrong:42' }), /Revenue|1200|yoro-report-toggle/);
assert.doesNotMatch(render({ ...base, action: 'orders.update_status' }), /Revenue|1200|yoro-report-toggle/);
const hostile = render({ ...base, summary: '<img src=x onerror=alert(1)>', open_label: '" onclick="alert(1)', report: { ...base.report, headline: '<script>alert(1)</script>' } });
assert.doesNotMatch(hostile, /<img|<script| onclick="/);
assert.match(hostile, /&lt;img/);

// Exercise the exported click path: native buttons supply Enter/Space activation as click events.
const attrs = { 'aria-controls': id, 'aria-expanded': 'false', 'data-open-label': base.open_label, 'data-close-label': base.close_label };
const details = { id, hidden: true };
const card = { querySelector: () => details, getAttribute: key => key === 'data-conversation-id' ? 'conversation-one' : base.result_id };
const button = { isConnected: true, textContent: base.open_label, closest: () => card, getAttribute: key => attrs[key], setAttribute: (key, value) => { attrs[key] = value; } };
const event = { target: { closest: selector => selector === '.yoro-report-toggle' ? button : null }, preventDefault() {} };
for (let i = 0; i < 20; i++) {
    assert.equal(ui.handleClick(event), true);
    assert.equal(details.hidden, i % 2 !== 0);
    assert.equal(attrs['aria-expanded'], String(i % 2 === 0));
}
assert.equal(calls, 0, 'Toggle/collapse/repeated clicks never execute, append messages or update server state');
conversation = 'other-conversation';
ui.handleClick(event);
assert.equal(details.hidden, true);
assert.doesNotMatch(render(), /Revenue|1200|yoro-report-toggle/);
conversation = '';
ui.handleClick(event);
assert.equal(details.hidden, true, 'Cleared state after a site/history switch rejects stale DOM');
conversation = 'conversation-one';
button.isConnected = false;
ui.handleClick(event);
assert.equal(details.hidden, true, 'Detached nodes cannot toggle');
button.isConnected = true;
attrs['aria-controls'] = 'another-result';
ui.handleClick(event);
assert.equal(details.hidden, true, 'Mismatched target cannot toggle');
const refreshed = render({ ...base, action_event_id: 43, result_id: 'conversation-one:43', summary: 'Revenue 1300 USD.' });
assert.match(refreshed, /conversation-one:43/);
assert.doesNotMatch(refreshed, /Revenue 1200/);
const integrated = renderer.renderResult({ success: true, stage: 'done', conversation_id: conversation, result: { success: true, action: base.action, display_type: 'report', summary: base.summary, report_presentation: base } });
assert.equal((integrated.match(/Revenue 1200 USD/g) || []).length, 1, 'One summary across renderer dispatch');
if (process.env.YORO_REPORT_PRESENTATION_FIXTURE) {
    const pair = JSON.parse(fs.readFileSync(process.env.YORO_REPORT_PRESENTATION_FIXTURE, 'utf8'));
    for (const response of pair.responses) {
        const p = response.report_presentation;
        conversation = p.conversation_id;
        const output = render(p);
        assert.match(output, /yoro-report-summary/);
        assert.ok(output.includes(escapeHtml(p.summary)));
        assert.equal((output.match(/class="button yoro-report-toggle"/g) || []).length, p.has_details ? 1 : 0);
    }
}
assert.equal(calls, 0);
console.log('PASS report-presentation-contract: renderer, identity, toggle, stale state, XSS and zero calls');

let residual = null;
const compoundRenderer = sandbox.window.YoRoResultRenderers.init({ ...helpers, renderReportResult: ui.renderReportResult, renderMultiActionResult: data => { residual = data.result.actions; return '<div>NON_REPORT_RESULT</div>'; } });
conversation = 'conversation-one';
const mutationStep = { action: 'orders.add_note', result: { success: true, summary: 'Note added', read_only: false } };
const reportStep = { action: base.action, result: { success: true, action: base.action, report_presentation: base } };
const compoundInput = { parsed: { assistant: { message: 'Unaccepted planning summary' } }, success: true, stage: 'done', conversation_id: conversation, result: { display_type: 'multi_action', multi_action: true, actions: [mutationStep, reportStep] } };
const compound = compoundRenderer.renderResult(compoundInput);
assert.match(compound, /NON_REPORT_RESULT/);
assert.equal((compound.match(/class="button yoro-report-toggle"/g) || []).length, 1);
assert.equal(residual.length, 1);
assert.equal(residual[0], mutationStep);
assert.equal(compoundInput.result.actions.length, 2, 'Compound projection must not mutate execution results');
console.log('PASS compound report renderer preserves non-report result path');

assert.match(render({ ...base, language: 'vi', expanded: true }), /Hôm nay|hôm nay/);
assert.match(render({ ...base, language: 'en', expanded: true }), /Today|today/);

assert.doesNotMatch(compound, /Unaccepted planning summary/);
