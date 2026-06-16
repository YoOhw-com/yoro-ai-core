#!/usr/bin/env node
/**
 * YoRo natural assistant-response renderer smoke contract.
 *
 * Run from the plugin root with:
 * node tests/smoke/result-renderer-natural-response-contract.js
 */

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

const sandbox = {
	window: {},
	console
};

vm.createContext(sandbox);
vm.runInContext(code, sandbox, { filename: rendererPath });

const resultRenderers = sandbox.window.YoRoResultRenderers || {};
const instance = typeof resultRenderers.init === 'function'
	? resultRenderers.init({
		escapeHtml,
		shouldShowDebugJson: () => false
	})
	: {};

assert(typeof instance.renderResult === 'function', 'Result renderer must expose renderResult.');
assert(typeof instance.renderAssistantText === 'function', 'Result renderer must expose renderAssistantText.');

const vietnameseJoke = [
	'Dưới đây là một câu chuyện cười ngắn gọn',
	'',
	'ÔNG CHỦ QUÁN CÀ PHÊ HỎI KHÁCH HÀNG: "Anh uống gì ạ?"',
	'KHÁCH: "Em muốn một ly cà phê đen không đường và không sữa."',
	'NHÂN VIÊN: "Dạ anh có muốn thay bằng nước lọc không ạ?"',
	'KHÁCH: "Không sao đâu để em thử xem sao."'
].join('\n');

if (typeof instance.renderResult === 'function') {
	const naturalHtml = instance.renderResult({
		success: true,
		stage: 'done',
		message: vietnameseJoke,
		result: {
			success: true,
			read_only: true,
			result_type: 'assistant_message',
			display_type: 'assistant_message',
			summary: vietnameseJoke
		}
	});

	assert(naturalHtml.includes('ÔNG CHỦ QUÁN CÀ PHÊ HỎI KHÁCH HÀNG'), 'Natural response must preserve Vietnamese speaker label.');
	assert(naturalHtml.includes('NHÂN VIÊN'), 'Natural response must preserve short speaker label.');
	assert(!naturalHtml.includes('yoro-assistant-fact-grid'), 'Natural response must not render dialogue as a fact grid.');
	assert(!naturalHtml.includes('yoro-assistant-fact-card'), 'Natural response must not render dialogue as fact cards.');

	const operationalHtml = instance.renderResult({
		success: true,
		stage: 'done',
		message: 'Store snapshot',
		result: {
			success: true,
			read_only: true,
			result_type: 'assistant_message',
			display_type: 'assistant_message',
			summary: [
				'Store snapshot',
				'Open orders: 12',
				'Revenue: $240.00',
				'Stock status: in_stock, outofstock'
			].join('\n')
		}
	});

	assert(operationalHtml.includes('yoro-assistant-fact-grid'), 'Operational key-value response must still render a fact grid.');
	assert(operationalHtml.includes('Open Orders'), 'Operational fact grid must include formatted labels.');
	assert(operationalHtml.includes('$240.00'), 'Operational fact grid must include metric values.');
}

if (typeof instance.renderAssistantText === 'function') {
	const hydratedHtml = instance.renderAssistantText(vietnameseJoke);

	assert(!hydratedHtml.includes('yoro-assistant-fact-grid'), 'Hydrated natural text must not render dialogue as a fact grid.');
	assert(hydratedHtml.includes('KHÁCH'), 'Hydrated natural text must preserve dialogue labels.');
}

if (failures.length) {
	failures.forEach((failure) => {
		console.error(`FAIL: ${failure}`);
	});
	process.exit(1);
}

console.log('YoRo natural assistant-response renderer smoke contract passed.');
