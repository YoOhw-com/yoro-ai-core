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

assert(accessGateSource.includes("actorAccessState.status === 'seat_required'"), 'Access gate must handle a pending operator request explicitly.');
assert(accessGateSource.includes('Access request sent'), 'Pending access must confirm that the request was sent.');
assert(accessGateSource.includes('Waiting for Organization approval'), 'Pending access must explain that Organization approval is required.');
assert(accessGateSource.includes('No separate YoRo sign-in is required.'), 'Pending access must explain that a separate YoRo login is unnecessary.');
assert(accessGateSource.includes("accessGateCheckAction('Request access'"), 'Unrecognized WordPress users must be able to request access.');
assert(!accessGateSource.includes('Open YoRo account'), 'WordPress operator access gate must not redirect users to a YoRo account login.');
assert(primaryControlsSource.includes('[data-yoro-operator-access-check]'), 'Operator access action must trigger an in-plugin access check.');
assert(primaryControlsSource.includes('loadAiApiStatus(true)'), 'Operator access action must refresh the signed WordPress actor handshake.');

const onboardingSource = functionSource('function renderEarlyAccessOnboarding(onboarding)', 'function getConversationDashboardUrl');
const renderOnboarding = new Function('onboarding', 'YoRoAIAdmin', 'escapeHtml', onboardingSource + '\nreturn renderEarlyAccessOnboarding(onboarding);');
const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
for (const status of ['unknown', 'available', 'not_available', 'requested_pending', 'withdrawn']) {
	const html = renderOnboarding({ status, runtime_enabled: false, future_endpoint: 'https://api.example/mcp/early-access' }, { aiApiUrl: 'https://api.example/api/v1' }, escapeHtml);
	assert(html.includes('currently unavailable'), `Missing unavailable notice: ${status}`);
	assert(!html.includes('https://api.example/mcp/early-access'), `Disabled runtime must not show a usable URL: ${status}`);
	assert(!html.includes('href='), 'Future endpoint must not become a connect link.');
}
const readyProjection = { status: 'available', runtime_enabled: true, connection_healthy: true, compatible: true,
    expires_at: new Date(Date.now() + 300000).toISOString(),
    server_url: 'https://api.example/mcp/early-access', future_endpoint: 'https://api.example/mcp/early-access' };
for (const status of ['available', 'requested_pending', 'withdrawn']) {
    const html = renderOnboarding({ ...readyProjection, status }, { aiApiUrl: 'https://api.example/api/v1' }, escapeHtml);
    assert(html.includes('MCP Server URL:') && html.includes(readyProjection.server_url), `Ready URL missing: ${status}`);
    assert(html.includes('No separate Early Access registration or approval is required.'), 'Registration exemption is missing.');
    assert(!html.includes('Request pending') && !html.includes('Runtime disabled'), 'Legacy enrollment copy contradicts live runtime.');
}
for (const drift of [
    { server_url: 'https://evil.test/mcp/early-access' },
    { server_url: 'https://api.example/mcp' },
    { server_url: 'https://api.example/mcp/early-access?token=secret' },
    { future_endpoint: 'https://evil.test/mcp/early-access' },
    { expires_at: new Date(Date.now() - 1000).toISOString() },
    { runtime_enabled: 'true' }, { connection_healthy: false }, { compatible: false }, { status: 'forged' },
]) {
    const html = renderOnboarding({ ...readyProjection, ...drift }, { aiApiUrl: 'https://api.example/api/v1' }, escapeHtml);
    assert(!html.includes('MCP Server URL:'), 'Unsafe, stale or unready projection reached the UI.');
}
for (const future_endpoint of ['javascript:alert(1)', 'https://evil.test/mcp/early-access', 'https://api.example/mcp/early-access?token=secret', '<img src=x onerror=alert(1)>']) {
	const html = renderOnboarding({ status: 'requested_pending', runtime_enabled: false, future_endpoint }, { aiApiUrl: 'https://api.example/api/v1' }, escapeHtml);
	assert(!html.includes(future_endpoint), 'Unsafe endpoint reached UI.');
}
assert(renderOnboarding({ status: '<img>', runtime_enabled: true }, {}, escapeHtml).includes('Unknown'), 'Unknown status or activation must fail closed.');
assert(renderOnboarding(null, {}, escapeHtml) === '', 'Omitted REST enrollment field must stay omitted in UI.');
assert(code.includes('renderEarlyAccessOnboarding(data && data.early_access_onboarding)'), 'Status refresh does not consume the real REST projection.');

if (failures.length) {
	failures.forEach((failure) => {
		console.error(`FAIL: ${failure}`);
	});
	process.exit(1);
}

console.log('YoRo WordPress operator access-gate smoke contract passed.');
