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
        if (p.has_details) {
            const publicOutput = publicReport(p, p.language === 'vi' ? 'en_US' : 'vi');
            const rows = (p.report.breakdowns || []).reduce((total, group) => total + (group.items || []).length, 0);
            assert.equal((publicOutput.match(/class="yoro-report-breakdown-item"/g) || []).length, rows);
            for (const group of p.report.breakdowns || []) {
                if (!group.items?.length) continue;
                assert.ok(publicOutput.includes(escapeHtml(group.label)), group.label);
                if (group.description) assert.ok(publicOutput.includes(escapeHtml(group.description)));
                for (const item of group.items) {
                    for (const value of [item.label, item.value, item.unit, item.subvalue]) {
                        if (value !== undefined && value !== null && value !== '') assert.ok(publicOutput.includes(escapeHtml(value)), String(value));
                    }
                }
            }
            if ((p.report.breakdowns || []).some(group => group.items?.some(item => item.label === 'Áo YoRo'))) {
                const vi = p.language === 'vi';
                for (const copy of [p, JSON.parse(JSON.stringify(p))]) {
                    const localized = publicReport(copy, vi ? 'en_US' : 'vi');
                    for (const label of vi ? ['Sản phẩm bán chạy', 'Danh mục bán chạy', 'Phân bố trạng thái đơn hàng', 'Hàng đợi cần chú ý', 'Cần chú ý', 'mục', 'đơn hàng', 'Đang xử lý']
                        : ['Top products', 'Top categories', 'Order status mix', 'Attention queues', 'Needs attention', 'items', 'orders', 'Processing']) assert.ok(localized.includes(label), label);
                    assert.ok(localized.includes('Áo YoRo') && localized.includes('120.00 USD') && localized.includes('10.0%'));
                    assert.doesNotMatch(localized, /Hidden inventory item/);
                    assertLocalDetailsToggle(copy, localized, rows);
                }
            }
        }
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

// F-001: public dispatch must expose every row already present in the accepted payload.
function publicReport(p, siteLocale = 'en_US') {
    return renderer.renderResult({ success: true, stage: 'done', conversation_id: p.conversation_id,
        parsed: { language: siteLocale }, result: { success: true, locale: siteLocale, action: p.action,
            display_type: 'report', report_presentation: p } });
}
function assertLocalDetailsToggle(p, output, expectedRows) {
    const target = output.match(/aria-controls="([^"]+)"/)[1];
    const localAttrs = { 'aria-controls': target, 'aria-expanded': String(p.expanded),
        'data-open-label': p.open_label, 'data-close-label': p.close_label };
    const savedHtml = output.substring(output.indexOf('<div class="yoro-report-details"'));
    const localDetails = { id: target, hidden: !p.expanded, innerHTML: savedHtml };
    const localCard = { querySelector: () => localDetails,
        getAttribute: key => key === 'data-conversation-id' ? p.conversation_id : p.result_id };
    const localButton = { isConnected: true, closest: () => localCard,
        getAttribute: key => localAttrs[key], setAttribute: (key, value) => { localAttrs[key] = value; } };
    const click = { target: { closest: selector => selector === '.yoro-report-toggle' ? localButton : null }, preventDefault() {} };
    for (let step = 0; step < 3; step++) {
        ui.handleClick(click);
        assert.equal(localDetails.hidden, step % 2 === 0 ? p.expanded : !p.expanded);
        assert.equal(localAttrs['aria-expanded'], String(!localDetails.hidden));
        assert.equal(localDetails.innerHTML, savedHtml, 'Opening/collapsing never replaces the accepted details');
        assert.equal((localDetails.innerHTML.match(/class="yoro-report-breakdown-item"/g) || []).length, expectedRows);
        assert.equal(localCard.getAttribute('data-report-result-id'), p.result_id);
    }
    assert.equal(calls, 0, 'Reading every accepted row remains local');
}
conversation = 'conversation-one';
for (const rowCount of [5, 6, 10]) {
    for (const mode of ['summary_first', 'full']) {
        const report = { ...base.report, type: 'store_insights', breakdowns: Array.from({ length: 4 }, (_, group) => ({
            key: 'group_' + group, domain: 'products', label: 'Accepted group ' + group, priority: group,
            items: Array.from({ length: rowCount }, (_, row) => ({ label: 'Áo YoRo ' + group + '/' + row,
                value: row + 1, unit: 'USD' }))
        })), alerts: Array.from({ length: 6 }, (_, i) => ({ topic: 'products', title: 'Accepted alert ' + i, message: 'Evidence ' + i })) };
        const p = { ...base, language: 'en', mode, expanded: mode === 'full', report };
        const original = JSON.stringify(p);
        for (const output of [render(p), publicReport(p)]) {
            assert.equal((output.match(/class="yoro-report-breakdown-item"/g) || []).length, rowCount * 4);
            for (const group of report.breakdowns) for (const item of group.items) assert.ok(output.includes(escapeHtml(item.label)));
            for (const warning of report.alerts) assert.ok(output.includes(warning.title));
            assert.match(output, new RegExp('aria-expanded="' + p.expanded + '"'));
            assertLocalDetailsToggle(p, output, rowCount * 4);
        }
        assert.equal(JSON.stringify(p), original, 'Rendering and toggling preserve accepted payload values and identity');
    }
}

// F-002: UI chrome uses API conversation language even when the surrounding site differs.
for (const language of ['vi', 'en']) {
    const vi = language === 'vi';
    const projected = { ...base.report, type: 'store_insights', health: 'risk',
        metrics: [{ key: 'items_sold', domain: 'products', label: vi ? 'Số sản phẩm đã bán' : 'Items sold', value: 10, unit: vi ? 'sản phẩm' : 'products', trend: 'up' }],
        metric_groups: [{ domain: 'products', label: vi ? 'Sản phẩm' : 'Products', description: vi ? 'Nhu cầu sản phẩm trong kỳ.' : 'Product demand in this window.' }],
        breakdowns: [{ key: 'top_products', domain: 'products', label: vi ? 'Sản phẩm hàng đầu' : 'Top products',
            description: vi ? 'Sản phẩm theo số lượng đã bán.' : 'Products by quantity sold.', items: [{ label: 'Áo YoRo', value: 10, unit: vi ? 'sản phẩm' : 'products', share: '25%' }] }],
        insights: [{ topic: 'products', title: vi ? 'Tín hiệu đã chấp nhận' : 'Accepted signal', message: 'Áo YoRo 10 USD' }],
        alerts: [{ topic: 'inventory', title: vi ? 'Cần kiểm tra tồn kho' : 'Inventory needs review', message: '4' }],
        applicability: { inventory: { applicable: true } } };
    for (const mode of ['summary_first', 'full']) {
        const p = { ...base, language, mode, expanded: mode === 'full', report: projected };
        for (const copy of [p, JSON.parse(JSON.stringify(p))]) {
            const output = publicReport(copy, vi ? 'en_US' : 'vi');
            for (const label of vi ? ['Điểm chính', 'Cần chú ý', 'Báo cáo cửa hàng', 'Rủi ro', 'Sản phẩm', 'Tăng', 'tỷ trọng']
                : ['Key takeaways', 'Needs attention', 'Store report', 'Risk', 'Products', 'Up', 'share']) assert.ok(output.includes(label), label);
            for (const label of vi ? ['Key takeaways', 'Needs attention', 'Store report', '>Risk<', '>Products<', '>Up<', ' share']
                : ['Điểm chính', 'Cần chú ý', 'Báo cáo cửa hàng', '>Rủi ro<', '>Sản phẩm<', '>Tăng<', 'tỷ trọng']) assert.ok(!output.includes(label), label);
            assert.ok(output.includes(escapeHtml(projected.breakdowns[0].label)));
            assert.ok(output.includes(escapeHtml(projected.metric_groups[0].description)));
            assert.ok(output.includes(escapeHtml(projected.breakdowns[0].description)));
            assert.ok(output.includes('Áo YoRo') && output.includes('10 USD') && output.includes('25%'));
            assertLocalDetailsToggle(copy, output, 1);
        }
        const inapplicable = { ...p, report: { ...projected, applicability: { inventory: { applicable: false } } } };
        assert.doesNotMatch(publicReport(inapplicable, vi ? 'en_US' : 'vi'), /Cần kiểm tra tồn kho|Inventory needs review/);
    }
}
assert.equal(calls, 0);
console.log('PASS full accepted rows and conversation-localized public report rendering/hydration');

// The global display formatter must not reinterpret names/values in an accepted report.
conversation = 'conversation-one';
for (const language of ['vi', 'en']) {
    const names = ['YoRo_blue-tee', 'Áo onbackorder', '<img src=x onerror=alert(1)>', 'physical_goods'];
    const p = { ...base, language, mode: 'full', expanded: true, report: { ...base.report,
        metrics: [{ key: 'accepted_value', label: 'YoRo_metric', value: 'physical_goods', delta: 'Up' }],
        breakdowns: [{ key: 'top_products', domain: 'products', label: 'API_copy', description: 'Accepted_description',
            items: names.map(label => ({ label, value: 'physical_goods', subvalue: 'SKU_ABC-123', unit: 'USD' })) }] } };
    const output = publicReport(p, language === 'vi' ? 'en_US' : 'vi');
    for (const value of [...names, 'physical_goods', 'SKU_ABC-123', 'YoRo_metric', 'API_copy', 'Accepted_description']) assert.ok(output.includes(escapeHtml(value)), value);
    assert.doesNotMatch(output, /Yo Ro Blue Tee|Áo On backorder|<img|onerror="/);
    assertLocalDetailsToggle(p, output, names.length);
}
console.log('PASS accepted names and values bypass global display token formatting');
