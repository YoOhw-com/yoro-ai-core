#!/usr/bin/env node
/**
 * YoRo WordPress operator access-gate smoke contract.
 *
 * Run from the plugin root with:
 * node tests/smoke/operator-access-gate-contract.js
 */

const fs = require('fs');
const path = require('path');

const pluginRoot = path.resolve(__dirname, '..', '..');
const adminScriptPath = path.join(pluginRoot, 'assets/js/yoro-ai-admin.js');
const code = fs.readFileSync(adminScriptPath, 'utf8');
const failures = [];

function assert(condition, message) {
	if (!condition) {
		failures.push(message);
	}
}

function functionSource(startMarker, endMarker) {
	const start = code.indexOf(startMarker);
	const end = code.indexOf(endMarker, start + startMarker.length);

	assert(start >= 0, `Missing function marker: ${startMarker}`);
	assert(end > start, `Missing function boundary: ${endMarker}`);

	return start >= 0 && end > start ? code.slice(start, end) : '';
}

const accessGateSource = functionSource('function renderAccountAccessGate()', 'function accessGateLink');
const primaryControlsSource = functionSource('function registerPrimaryControls()', 'commandField.addEventListener');
const statusSource = functionSource('function renderAiApiStatus(data)', 'function getConversationDashboardUrl');
const hydrationSource = functionSource('function hydrateStoredConversation()', 'function addLoadingMessage');

assert(accessGateSource.includes("actorAccessState.status === 'seat_required'"), 'Access gate must handle a pending operator request explicitly.');
assert(accessGateSource.includes('Access request sent'), 'Pending access must confirm that the request was sent.');
assert(accessGateSource.includes('Waiting for Organization approval'), 'Pending access must explain that Organization approval is required.');
assert(accessGateSource.includes('No separate YoRo sign-in is required.'), 'Pending access must explain that a separate YoRo login is unnecessary.');
assert(accessGateSource.includes("accessGateCheckAction('Request access'"), 'Unrecognized WordPress users must be able to request access.');
assert(!accessGateSource.includes('Open YoRo account'), 'WordPress operator access gate must not redirect users to a YoRo account login.');
assert(primaryControlsSource.includes('[data-yoro-operator-access-check]'), 'Operator access action must trigger an in-plugin access check.');
assert(primaryControlsSource.includes('loadAiApiStatus(true)'), 'Operator access action must refresh the signed WordPress actor handshake.');
assert(statusSource.includes('hydrateStoredConversation();'), 'Conversation hydration must retry after signed operator access becomes active.');
assert(hydrationSource.includes('conversationHydrationAttempted'), 'Conversation hydration must be guarded against duplicate requests.');
assert(hydrationSource.includes('conversationHydrationAttempted = true;'), 'Conversation hydration must mark its first authenticated attempt.');

if (failures.length) {
	failures.forEach((failure) => {
		console.error(`FAIL: ${failure}`);
	});
	process.exit(1);
}

console.log('YoRo WordPress operator access-gate smoke contract passed.');
