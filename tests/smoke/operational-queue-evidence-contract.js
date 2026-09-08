#!/usr/bin/env node
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const sandbox = { window: {}, console };
vm.createContext(sandbox);
for (const file of ['yoro-ai-display-labels.js', 'yoro-ai-order-ui.js', 'yoro-ai-result-renderers.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, 'assets/js', file), 'utf8'), sandbox, { filename: file });
}
const escapeHtml = value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
let calls = 0;
let conversation = 'queue-fixture';
const helpers = { escapeHtml, getConversationState: () => ({ conversation_id: conversation }), formatDateTime: x => x, renderDebugJson: () => '', shouldShowDebugJson: () => false, renderAiBoundaryNotice: () => '', executeActionDirect: () => { calls++; }, openModal: () => { calls++; } };
const ui = sandbox.window.YoRoOrderUI.init(helpers);
const renderer = sandbox.window.YoRoResultRenderers.init({ ...helpers, ...ui });
const captured = new Date(Date.now() - 1000).toISOString().replace(/\.\d{3}Z$/, '+00:00');
const created = Math.floor(Date.parse(captured) / 1000) - 80 * 3600;
const row = { id: 900001, number: '900001', customer_name: '<img src=x onerror=alert(1)>', date_created_ts: created, age_hours: 80, extensions: { 'woocommerce.queue_evidence': { age_basis: 'date_created', status_duration: 'unknown', captured_at: captured } } };
const observation = { captured_at: captured, count_basis: 'sample_matches', match_count: 50, visible_memberships: 1, limit: 1, sample_size: 50, candidate_limit: 50, complete: false, visible_truncated: true };
const raw = { success: true, action: 'operations.get_attention_queue', display_type: 'operations_attention_queue', count: 50, visible_case_count: 2, orders: [row], queues: [{ key: 'pending_payment_older_than_24h', label: 'Pending', count: 50, orders: [row] }], meta: { queue_evidence: { version: 1, age_basis: 'date_created', status_duration: 'unknown', captured_at: captured, queues: { pending_payment_older_than_24h: observation } } } };
const render = (result, language = 'en') => renderer.renderResult({ success: true, stage: 'done', parsed: { language }, conversation_id: conversation, result });
const assertProviderLiterals = (result, output) => {
    const cells = [...output.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map(match => match[1]);
    for (const queue of result.queues || []) {
        for (const order of (queue.orders || []).slice(0, 5)) {
            const values = result.action === 'operations.get_fulfillment_queue'
                ? [order.shipping_method, order.shipping_city, order.shipping_country]
                : [order.customer_name || order.billing_email];
            for (const value of values.filter(Boolean)) {
                assert.ok(cells.some(cell => cell.includes(escapeHtml(String(value)))), `${result.action}: provider/accepted literal ${value}`);
            }
        }
    }
};
const html = render(raw);
assert.match(html, /Age since creation|age.*creation/i);
assert.match(html, /time in the current status is unknown/);
assert.match(html, /Candidate sample: 50\/50/);
assert.match(html, /full total unknown/);
assert.match(html, /&lt;img/);
assert.doesNotMatch(html, /<img|onerror="|Preview shipping delay email|Write email for order/);
assert.match(render(raw, 'vi'), /Tuổi.*ngày tạo|tuổi.*ngày tạo/);
const literalValues = ['YoRo_blue-tee', 'Áo onbackorder', 'physical_goods', 'john_doe@example.test', '<img src=x onerror="alert(1)">_blue-tee'];
const queueKinds = [
    ['operations.get_attention_queue', 'operations_attention_queue'],
    ['operations.get_fulfillment_queue', 'fulfillment_queue'],
    ['customers.get_service_queue', 'customer_service_queue'],
];
for (const [action, display_type] of queueKinds) {
    for (const language of ['en', 'vi']) {
        for (const literal of literalValues) {
            for (const emailFallback of [false, true]) {
                const value = JSON.parse(JSON.stringify(raw));
                Object.assign(value, { action, display_type });
                const namedRow = { ...value.queues[0].orders[0], number: literal,
                    customer_name: emailFallback ? '' : literal, billing_email: literal,
                    shipping_method: literal, shipping_city: literal, shipping_country: literal,
                    fulfillment_reason: literal, followup_reason: literal };
                value.orders = [namedRow];
                value.queues[0].orders = [namedRow];
                const before = JSON.stringify(value);
                // Recreate the accepted-history value without a new provider/model call.
                for (const input of [value, JSON.parse(before)]) {
                    const output = render(input, language);
                    const table = output.slice(output.indexOf('<tbody>'), output.indexOf('</tbody>'));
                    const caseCell = [...table.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)][1]?.[1] || '';
                    const expectedOccurrences = display_type === 'fulfillment_queue' ? 4 : (display_type === 'customer_service_queue' ? 2 : 1);
                    assert.equal(caseCell.split(escapeHtml(literal)).length - 1, expectedOccurrences, `${action} ${language} ${literal}: preserve every literal case value, not only the order number`);
                    assert.doesNotMatch(output, /<img|<script|onerror="/);
                }
                assert.equal(JSON.stringify(value), before, 'Rendering must not rewrite accepted values.');
            }
        }
    }
}
for (const age of [null, undefined, -1, '0']) {
    const bad = JSON.parse(JSON.stringify(raw));
    bad.queues[0].orders[0].age_hours = age;
    assert.match(render(bad), /Unknown/);
}
const legacy = JSON.parse(JSON.stringify(raw));
delete legacy.meta;
delete legacy.queues[0].orders[0].extensions;
legacy.queues[0].orders[0].age_hours = 0;
legacy.queues[0].orders[0].date_created_ts = 0;
assert.match(render(legacy), /legacy results are not re-certified/);
assert.match(render(legacy), /Unknown/);
const failed = render({ ...raw, success: false, summary: 'Untrusted healthy zero', errors: [{ code: 'yoro_queue_age_unavailable' }] });
assert.doesNotMatch(failed, /yoro-queue-count|yoro-direct-action|yoro-report-toggle|Untrusted healthy zero/);
const future = JSON.parse(JSON.stringify(raw));
future.meta.queue_evidence.captured_at = '2099-01-01T00:00:00Z';
assert.doesNotMatch(render(future), /Observed at: 2099|Count complete within query scope/);
const contradictory = JSON.parse(JSON.stringify(raw));
contradictory.meta.queue_evidence.queues.pending_payment_older_than_24h.complete = true;
assert.doesNotMatch(render(contradictory), /Count complete within query scope/);
assert.match(render(contradictory), /scope unknown/);
const compoundRenderer = sandbox.window.YoRoResultRenderers.init({ ...helpers, renderMultiActionResult: () => 'MULTI_ACTION_RECEIPTS' });
assert.match(compoundRenderer.renderResult({ success: true, stage: 'done', result: { success: false, multi_action: true, display_type: 'multi_action', actions: [], undo_plan: [{ action: 'orders.update_status' }] } }), /MULTI_ACTION_RECEIPTS/);
if (process.env.YORO_QUEUE_FIXTURE) {
    const source = JSON.parse(fs.readFileSync(process.env.YORO_QUEUE_FIXTURE, 'utf8'));
    for (const [action, provider] of Object.entries(source.providers)) {
        if (!provider.raw || !provider.raw.queues) continue;
        const output = render(provider.raw);
        assert.match(output, /Observed at:/, action);
        assert.match(output, /time in the current status is unknown/, action);
        assert.doesNotMatch(output, /Preview shipping delay email|Write email for order|<script>/, action);
        for (const order of provider.raw.orders) assert.ok(output.includes(escapeHtml(order.number || order.id)), action);
        assertProviderLiterals(provider.raw, output);
    }
}
if (process.env.YORO_QUEUE_PRESENTATION) {
    const pair = JSON.parse(fs.readFileSync(process.env.YORO_QUEUE_PRESENTATION, 'utf8'));
    for (const response of pair.responses) {
        const p = response.report_presentation;
        conversation = p.conversation_id;
        const output = ui.renderReportResult({ conversation_id: conversation, result: { action: p.action, report_presentation: p } });
        assert.ok(output.includes(escapeHtml(p.summary)));
        assert.equal((output.match(/class="button yoro-report-toggle"/g) || []).length, p.has_details ? 1 : 0);
        if (p.state === 'error') assert.doesNotMatch(output, /yoro-report-toggle|yoro-report-details/);
    }
}
if (process.env.YORO_QUEUE_EVENTS) {
    const accepted = JSON.parse(fs.readFileSync(process.env.YORO_QUEUE_EVENTS, 'utf8'));
    for (const event of accepted.events) {
        conversation = event.conversation_id;
        const extension = event.result.extensions['wordpress.execution'];
        const result = { ...event.result, ...event.result.data, ...extension.legacy_data, meta: extension.meta };
        const output = render(result);
        assert.match(output, /Observed at:/);
        assert.doesNotMatch(output, /yoro-report-toggle/);
        assert.ok(output.includes(escapeHtml(extension.meta.queue_evidence.captured_at)));
        assertProviderLiterals(result, output);
    }
}
assert.equal(calls, 0, 'Read/render/hydration must not execute or invoke a model.');
console.log('PASS operational-queue-evidence-contract: public renderer, age provenance, sample scope, errors, VI/EN, XSS, no execution');
