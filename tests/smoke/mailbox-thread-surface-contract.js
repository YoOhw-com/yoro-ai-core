#!/usr/bin/env node
/**
 * YoRo mailbox thread surface smoke contract.
 *
 * Run from the plugin root with:
 * node tests/smoke/mailbox-thread-surface-contract.js
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

function assertFocusedThreadSurface(source, label) {
	assert(source.includes('mailboxMessageBodyHtml(message)'), `${label} must render the customer message timeline.`);
	assert(source.includes('reply-input'), `${label} must render the reply composer.`);
	assert(!source.includes('renderMailboxThreadIntelligence('), `${label} must not render the intelligence panel.`);
	assert(!source.includes('renderMailboxThreadWorkflow('), `${label} must not render the workflow panel.`);
	assert(!source.includes('renderMailboxInternalNotes('), `${label} must not render internal notes.`);
	assert(!source.includes('renderMailboxReplyHistory('), `${label} must not render reply history.`);
}

assertFocusedThreadSurface(
	functionSource('function renderQuickInboxThread(data)', 'function sendMailboxThreadReply'),
	'Quick inbox thread'
);
assertFocusedThreadSurface(
	functionSource('function renderMailboxSettingsThread(settingsRoot, data)', 'function renderMailboxSettingsError'),
	'Mailbox settings thread'
);

if (failures.length) {
	failures.forEach((failure) => {
		console.error(`FAIL: ${failure}`);
	});
	process.exit(1);
}

console.log('YoRo mailbox thread surface smoke contract passed.');
