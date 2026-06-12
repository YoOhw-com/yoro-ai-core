#!/usr/bin/env node
/**
 * YoRo renderer confirmation-state smoke contract.
 *
 * Run from the plugin root with:
 * node tests/smoke/renderer-confirmation-contract.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const pluginRoot = path.resolve(__dirname, '..', '..');
const rendererPath = path.join(pluginRoot, 'assets/js/yoro-ai-workflow-renderers.js');
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

const sandbox = {
	window: {},
	console
};

vm.createContext(sandbox);
vm.runInContext(code, sandbox, { filename: rendererPath });

const renderers = sandbox.window.YoRoWorkflowRenderers || {};

assert(typeof renderers.renderWorkflowPreview === 'function', 'Workflow preview renderer must be exported.');
assert(typeof renderers.renderMultiActionResult === 'function', 'Multi-action result renderer must be exported.');

if (typeof renderers.renderWorkflowPreview === 'function') {
	const confirmHtml = renderers.renderWorkflowPreview(
		{
			stage: 'confirm',
			confirm_required: true,
			parsed: {
				workflow_label: 'Update order and add note'
			},
			plan: {
				steps: [
					{
						action: 'orders.update_status',
						params: {
							order_ids: [7188],
							status: 'processing'
						}
					},
					{
						action: 'orders.add_note',
						params: {
							order_ids: [7188],
							note: 'Shipment was delayed'
						}
					}
				],
				workflow: {
					status: 'confirm_required',
					step_count: 2
				}
			},
			result: {
				requires_confirmation: true
			}
		},
		{ escapeHtml }
	);

	assert(confirmHtml.includes('Needs your approval'), 'Confirm preview must show approval state copy.');
	assert(confirmHtml.includes('Nothing has changed yet'), 'Confirm preview must show no-change safety copy.');
	assert(confirmHtml.includes('yoro-confirm-actions'), 'Confirm preview must render confirmation actions.');
	assert(confirmHtml.includes('yoro-confirm-run'), 'Confirm preview must render approve button class.');
	assert(confirmHtml.includes('Approve changes'), 'Confirm preview must label the approve button.');
	assert(confirmHtml.includes('yoro-confirm-cancel'), 'Confirm preview must render cancel button class.');
	assert(confirmHtml.includes('Update order status'), 'Confirm preview must render update status step.');
	assert(confirmHtml.includes('Add internal note'), 'Confirm preview must render add note step.');

	const readOnlyPreviewHtml = renderers.renderWorkflowPreview(
		{
			stage: 'preview',
			confirm_required: false,
			plan: {
				steps: [
					{
						action: 'orders.get_details',
						params: {
							order_ids: [7188]
						}
					}
				]
			},
			result: {
				requires_confirmation: false
			}
		},
		{ escapeHtml }
	);

	assert(!readOnlyPreviewHtml.includes('yoro-confirm-actions'), 'Read-only preview must not render confirmation actions.');
	assert(readOnlyPreviewHtml.includes('Plan ready'), 'Read-only preview must show plan-ready state.');
}

if (typeof renderers.renderMultiActionResult === 'function') {
	const doneHtml = renderers.renderMultiActionResult(
		{
			stage: 'done',
			result: {
				success: true,
				workflow: {
					status: 'completed',
					step_count: 2
				},
				actions: [
					{
						action: 'orders.update_status',
						status: 'done',
						result: {
							success: true,
							processed: [7188],
							order_ids: [7188],
							status: 'processing',
							status_label: 'Processing',
							message: 'Updated 1 order.'
						}
					},
					{
						action: 'orders.add_note',
						status: 'done',
						result: {
							success: true,
							processed: [7188],
							order_ids: [7188],
							note: 'Shipment was delayed',
							message: 'Added note.'
						}
					}
				]
			}
		},
		{ escapeHtml }
	);

	assert(doneHtml.includes('Completed'), 'Done renderer must show completed state.');
	assert(doneHtml.includes('I finished those steps.'), 'Done renderer must show executed-state copy.');
	assert(!doneHtml.includes('Needs your approval'), 'Done renderer must not show approval copy.');
	assert(!doneHtml.includes('Nothing has changed yet'), 'Done renderer must not show no-change copy.');
	assert(!doneHtml.includes('yoro-confirm-actions'), 'Done renderer must not render approval buttons.');
}

if (failures.length) {
	failures.forEach((failure) => {
		console.error(`FAIL: ${failure}`);
	});
	process.exit(1);
}

console.log('YoRo renderer confirmation-state smoke contract passed.');
