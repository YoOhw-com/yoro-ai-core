#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const pluginRoot = path.resolve(__dirname, '..', '..');
const rendererPath = path.join(pluginRoot, 'assets/js/yoro-ai-result-renderers.js');
const code = fs.readFileSync(rendererPath, 'utf8');
const failures = [];

function assert(condition, message) {
	if (!condition) {
		failures.push(message);
	}
}

function escapeHtml(value) {
	return String(value === null || value === undefined ? '' : value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
}

const sandbox = { window: {}, console };
vm.createContext(sandbox);
vm.runInContext(code, sandbox, { filename: rendererPath });

const instance = sandbox.window.YoRoResultRenderers.init({
	escapeHtml,
	shouldShowDebugJson: () => false
});

const confirmationHtml = instance.renderResult({
	success: true,
	stage: 'confirm',
	requires_confirmation: true,
	pending_operation_active: true,
	pending_operation_token: 'wave2-batch-token',
	direct_confirmation_allowed: true,
	parsed: {
		action: 'products.update_catalog_batch',
		params: {
			idempotency_key: 'opaque-key',
			items: []
		}
	},
	result: {
		display_type: 'confirmation',
		action: 'products.update_catalog_batch',
		blocked: false,
		items: [
			{ index: 0, product_id: 41, changed_fields: ['regular_price'] },
			{ index: 1, product_id: 42, changed_fields: ['catalog_visibility'] }
		]
	}
});

assert(confirmationHtml.includes('Product #41: will update (regular_price).'), 'Batch confirmation must render the first per-target patch.');
assert(confirmationHtml.includes('Product #42: will update (catalog_visibility).'), 'Batch confirmation must render the second per-target patch.');
assert(confirmationHtml.includes('Approve change'), 'Batch confirmation must render an approval action.');

const completedHtml = instance.renderResult({
	success: true,
	stage: 'done',
	parsed: { action: 'products.update_catalog_batch', params: {} },
	result: {
		success: true,
		action: 'products.update_catalog_batch',
		summary: 'Catalog batch finished with 2 updated and 0 failed products.',
		results: [
			{ index: 0, product_id: 41, success: true, changed_fields: ['regular_price'] },
			{ index: 1, product_id: 42, success: true, changed_fields: ['catalog_visibility'] }
		],
		count: 2,
		result_type: 'products',
		display_type: 'generic'
	}
});

assert(completedHtml.includes('Catalog batch completed'), 'Batch success renderer must use the batch completion title.');
assert(completedHtml.includes('Catalog batch finished with 2 updated and 0 failed products.'), 'Batch success renderer must preserve the result summary.');

if (failures.length) {
	failures.forEach((failure) => console.error('FAIL:', failure));
	process.exit(1);
}

console.log('YoRo wave-two renderer contract passed.');
