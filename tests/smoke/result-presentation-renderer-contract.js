#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const pluginRoot = path.resolve(__dirname, '..', '..');
const adminCode = fs.readFileSync(path.join(pluginRoot, 'assets/js/yoro-ai-admin.js'), 'utf8');
const failures = [];

function assert(condition, message) {
	if (!condition) {
		failures.push(message);
	}
}

function escapeHtml(value) {
	return String(value === null || typeof value === 'undefined' ? '' : value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
}

const sandbox = {
	window: {},
	console
};

vm.createContext(sandbox);

[
	'assets/js/yoro-ai-display-labels.js',
	'assets/js/yoro-ai-order-ui.js',
	'assets/js/yoro-ai-result-renderers.js',
	'assets/js/yoro-ai-workflow-renderers.js'
].forEach((relativePath) => {
	const absolutePath = path.join(pluginRoot, relativePath);
	vm.runInContext(fs.readFileSync(absolutePath, 'utf8'), sandbox, { filename: absolutePath });
});

const orderUI = sandbox.window.YoRoOrderUI.init({
	escapeHtml,
	formatDateTime: (timestamp, value) => String(value || timestamp || ''),
	shouldShowDebugJson: () => false,
	renderDebugJson: () => ''
});
const resultUI = sandbox.window.YoRoResultRenderers.init({
	escapeHtml,
	shouldShowDebugJson: () => false,
	renderOrderDetailsResult: orderUI.renderOrderDetailsResult,
	renderRefundPreviewResult: orderUI.renderRefundPreviewResult
});
const multiActionBindingStart = adminCode.indexOf('renderMultiActionResult: window.YoRoWorkflowRenderers');
const multiActionBindingEnd = adminCode.indexOf('renderOrderDetailsResult:', multiActionBindingStart);
const multiActionBinding = multiActionBindingStart >= 0 && multiActionBindingEnd > multiActionBindingStart
	? adminCode.slice(multiActionBindingStart, multiActionBindingEnd)
	: '';

assert(multiActionBinding.includes('renderStepResult:'), 'Admin runtime must bind the shared step renderer to multi-action results.');
assert(multiActionBinding.includes('resultUI.renderEmbeddedResult'), 'Multi-action binding must delegate every unregistered workflow step to the result registry.');

const exactSku = 'YORO-CHAT-E2E-SKU-20260616095512';
const productHtml = resultUI.renderResult({
	success: true,
	stage: 'done',
	parsed: { action: 'products.get_details' },
	result: {
		success: true,
		action: 'products.get_details',
		summary: 'Found product QA product (#41).',
		product: {
			product_id: 41,
			name: 'QA product',
			sku: exactSku,
			type: 'simple',
			status: 'publish',
			catalog_visibility: 'visible',
			price: '19.00',
			regular_price: '25.00',
			currency: 'USD',
			stock_quantity: 7,
			stock_status: 'instock',
			edit_url: '/wp-admin/post.php?post=41&action=edit'
		},
		result_type: 'product',
		display_type: 'product',
		presentation: {
			contract_type: 'yoro.presentation.result',
			schema_version: 1,
			renderer_key: 'product'
		}
	}
});

assert(productHtml.includes('Current product snapshot'), 'Product results must use the official product renderer.');
assert(productHtml.includes(exactSku), 'Product renderer must preserve opaque SKU values exactly.');
assert(!productHtml.includes('Yoro Chat E2e Sku'), 'Product renderer must never title-case or rewrite an SKU.');
assert(productHtml.includes('19.00 USD'), 'Product renderer must include the current price and currency.');

const fulfillmentHtml = resultUI.renderResult({
	success: true,
	stage: 'done',
	parsed: { action: 'orders.get_fulfillment' },
	result: {
		success: true,
		action: 'orders.get_fulfillment',
		summary: 'Order #123 fulfillment is shipped.',
		order: {
			id: 123,
			number: '123',
			status: 'processing',
			status_label: 'Processing',
			edit_url: '/wp-admin/post.php?post=123&action=edit'
		},
		fulfillment: {
			status: 'shipped',
			shipping_method: 'Express',
			carrier: 'DHL',
			tracking_number: 'JD0146000062818281',
			tracking_url: 'https://example.com/track/JD0146000062818281',
			shipped_at: '2026-07-24T10:00:00+07:00',
			history: [
				{ from: 'packed', to: 'shipped', occurred_at: '2026-07-24T10:00:00+07:00' }
			]
		},
		result_type: 'fulfillment_lifecycle',
		display_type: 'fulfillment_lifecycle'
	}
});

assert(fulfillmentHtml.includes('Fulfillment and shipment snapshot'), 'Fulfillment results must use the official lifecycle renderer.');
assert(fulfillmentHtml.includes('JD0146000062818281'), 'Fulfillment renderer must expose the exact tracking number.');
assert(fulfillmentHtml.includes('Lifecycle history'), 'Fulfillment renderer must expose lifecycle history when available.');

const refundHtml = resultUI.renderResult({
	success: true,
	stage: 'done',
	parsed: { action: 'orders.refund_preview' },
	result: {
		success: true,
		action: 'orders.refund_preview',
		summary: 'Previewed a 25.00 USD refund for order #123 without issuing it.',
		order: {
			id: 123,
			number: '123',
			status: 'processing',
			status_label: 'Processing',
			currency: 'USD',
			total: '100.00',
			total_refunded: '0.00',
			remaining: '100.00'
		},
		totals: {},
		items: [],
		refunds: [],
		requested_refund: {
			amount: '25.00',
			currency: 'USD',
			remaining_after: '75.00',
			will_execute: false
		},
		can_refund: true,
		result_type: 'refund',
		display_type: 'refund_preview'
	}
});

assert(refundHtml.includes('Requested refund preview'), 'Amount-specific refund previews must render the requested amount section.');
assert(refundHtml.includes('25.00 USD'), 'Refund preview must display the requested amount.');
assert(refundHtml.includes('No refund will be issued'), 'Refund preview must state that no financial action was executed.');

const unknownHtml = resultUI.renderEmbeddedResult({
	success: true,
	stage: 'done',
	parsed: { action: 'future.connector.action' },
	result: {
		success: true,
		summary: 'Future connector result.',
		external_reference: 'REF-9_A:42',
		state: 'ready',
		result_type: 'future_result',
		display_type: 'generic'
	}
});

assert(unknownHtml.includes('Future connector result.'), 'Unknown action results must retain their grounded summary.');
assert(unknownHtml.includes('REF-9_A:42'), 'Structured fallback must preserve opaque identifiers exactly.');
assert(!unknownHtml.includes('<pre>'), 'Structured fallback must not expose raw JSON outside debug mode.');

[
	'Tiếng Việt và SKU ' + exactSku + ' phải được giữ nguyên.',
	'العربية: يجب إبقاء الرمز REF-9_A:42 كما هو.',
	'Polski opis: identyfikator JD0146000062818281 pozostaje bez zmian.',
	'Code-switch: giữ nguyên customer@example.com và ' + exactSku + '.'
].forEach((message) => {
	const html = resultUI.renderAssistantText(message);

	assert(html.includes(message), 'Free-form multilingual prose and identifiers must be preserved exactly: ' + message + ' | HTML: ' + html);
});

const workflowHtml = sandbox.window.YoRoWorkflowRenderers.renderMultiActionResult({
	success: true,
	stage: 'done',
	result: {
		success: true,
		display_type: 'multi_action',
		workflow: {
			definition_id: 'connector_result_acceptance',
			status: 'completed'
		},
		actions: [
			{
				action: 'products.get_details',
				result: {
					success: true,
					summary: 'Product loaded.',
					product: {
						product_id: 41,
						name: 'QA product',
						sku: exactSku,
						type: 'simple',
						status: 'publish',
						stock_status: 'instock'
					},
					result_type: 'product',
					display_type: 'product'
				}
			},
			{
				action: 'orders.get_fulfillment',
				result: {
					success: true,
					summary: 'Fulfillment loaded.',
					order: { id: 123, number: '123', status_label: 'Processing' },
					fulfillment: {
						status: 'packed',
						carrier: 'DHL',
						tracking_number: 'JD0146000062818281'
					},
					result_type: 'fulfillment_lifecycle',
					display_type: 'fulfillment_lifecycle'
				}
			},
			{
				action: 'future.connector.action',
				result: {
					success: true,
					summary: 'Future step completed.',
					external_reference: 'REF-9_A:42',
					result_type: 'future_result',
					display_type: 'generic'
				}
			}
		]
	}
}, {
	escapeHtml,
	shouldShowDebugJson: () => false,
	renderDebugJson: () => '',
	renderStepResult: (action, stepResult) => resultUI.renderEmbeddedResult({
		success: stepResult.success !== false,
		stage: 'done',
		parsed: { action },
		result: Object.assign({ action }, stepResult)
	})
});

assert(workflowHtml.includes(exactSku), 'Compound workflow must render the product step through the shared registry.');
assert(workflowHtml.includes('JD0146000062818281'), 'Compound workflow must render the fulfillment step through the shared registry.');
assert(workflowHtml.includes('Future step completed.'), 'Compound workflow must render unknown actions through the structured fallback.');
assert(!workflowHtml.includes('<pre>'), 'Compound workflow must not fall back to raw JSON outside debug mode.');

const safetyHtml = resultUI.renderResult({
	success: true,
	stage: 'confirm',
	requires_confirmation: true,
	pending_operation_active: true,
	pending_operation_token: 'safety-token',
	direct_confirmation_allowed: true,
	parsed: {
		action: 'orders.update_status',
		params: {
			order_id: 123,
			status: 'processing'
		}
	},
	result: {
		display_type: 'confirmation',
		action: 'orders.update_status',
		safety: {
			approval_groups: [
				{
					id: 'store_mutation',
					label: 'Store data changes',
					target_count: 2,
					target_touch_count: 2,
					unique_target_count: 1,
					operation_count: 2,
					operation_ids: ['op_status', 'op_note']
				}
			]
		}
	}
});

assert(safetyHtml.includes('2 changes on 1 target'), 'Safety view must distinguish multiple operations from one unique target.');
assert(!safetyHtml.includes('(2 targets)'), 'Safety view must not describe target touches as unique targets.');

if (failures.length) {
	failures.forEach((failure) => console.error('FAIL:', failure));
	process.exit(1);
}

console.log('YoRo result presentation renderer contract passed.');
