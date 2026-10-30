<?php
/** Pure source-loaded normalization; no WordPress database, credentials or network. */
define('ABSPATH', __DIR__ . '/');
define('YORO_AI_CORE_VERSION', 'source-test');
function wp_parse_url($url) { return parse_url($url); }
function untrailingslashit($value) { return rtrim($value, '/'); }
function sanitize_text_field($value) { return $value; }
function home_url() { return 'https://source-store.invalid'; }
function get_option($name, $default = '') { return $name === 'yoro_ai_installation_uuid' ? 'source-installation' : $default; }
require dirname(__DIR__, 2) . '/includes/ai/class-yoro-ai-settings.php';
$expires = gmdate('c', time() + 240);
$projection = ['status' => 'available', 'source' => 'api_handshake', 'site_id' => 1, 'installation_uuid' => 'source-installation',
    'connection_healthy' => true, 'compatible' => true, 'checked_at' => gmdate('c'), 'expires_at' => $expires,
    'future_endpoint' => 'https://source-api.invalid/mcp/early-access', 'runtime_enabled' => true,
    'server_url' => 'https://source-api.invalid/mcp/early-access', 'grant_status' => 'granted',
    'public_mcp' => ['version' => 1, 'resource' => 'https://source-api.invalid/mcp', 'manifest_version' => 1,
        'runtime_enabled' => true, 'confirmed_commerce_writes_enabled' => false]];
$context = ['site' => ['id' => 1, 'url' => home_url()], 'account_connection' => ['connected' => true],
    'connection' => ['id' => 2, 'status' => 'active', 'installation_uuid' => 'source-installation', 'plugin_version' => YORO_AI_CORE_VERSION],
    'credential' => ['id' => 3, 'type' => 'wordpress_connection', 'site_connection_id' => 2],
    'connector_runtime' => ['connector_version' => YORO_AI_CORE_VERSION, 'protocol_version' => 1,
        'capability_manifest' => ['current' => true, 'manifest_id' => 'source-manifest', 'revision' => 1, 'expires_at' => $expires]],
    'early_access_credential_fingerprint' => hash('sha256', 'source-test-key')];
$normalize = static fn($input, $facts = null) => YoRo_AI_Settings::normalize_early_access_onboarding($input, $facts ?? $context, 'https://source-api.invalid/api/v1', 'source-test-key');
$assert = static function($condition, $message) { if (!$condition) { throw new RuntimeException($message); } };
$valid = $normalize($projection);
$assert($valid === $projection, 'Exact EA and public projections must coexist.');
$legacy = $projection; unset($legacy['public_mcp']);
$assert($normalize($legacy) === $legacy, 'Historical EA projection remains byte-compatible.');
$cases = [['resource', 'https://source-api.invalid/mcp/early-access'], ['resource', 'https://foreign.invalid/mcp'],
    ['resource', 'https://source-api.invalid:8443/mcp'], ['version', 2], ['manifest_version', 2], ['runtime_enabled', 'true'], ['extra', true]];
foreach ($cases as [$key, $value]) {
    $bad = $projection; $bad['public_mcp'][$key] = $value;
    $result = $normalize($bad);
    $assert(!isset($result['public_mcp']) && $result['server_url'] === $legacy['server_url'], 'Invalid public projection cannot affect EA or acquire authority: '.$key);
}
$bad = $projection; $bad['public_mcp']['runtime_enabled'] = false; $bad['public_mcp']['confirmed_commerce_writes_enabled'] = true;
$assert(!isset($normalize($bad)['public_mcp']), 'Disabled public reads cannot enable writes.');
$off = $projection; $off['public_mcp']['runtime_enabled'] = false;
$assert($normalize($off)['public_mcp']['runtime_enabled'] === false, 'Public rollback retains independent EA projection.');
foreach (['refresh_needed', 'reconnect_required', 'revoked'] as $state) {
    $display = $projection; $display['grant_status'] = $state;
    $normalized = $normalize($display);
    $assert($normalized['grant_status'] === $state && $normalized['public_mcp'] === $projection['public_mcp'], 'Merged EA credential display facts preserve independent public resource facts: '.$state);
}
$ea_off = $projection; $ea_off['runtime_enabled'] = false; $ea_off['server_url'] = ''; $ea_off['grant_status'] = 'unknown';
$assert($normalize($ea_off) === $ea_off, 'A public runtime projection cannot turn a dark EA resource into a connected EA display.');
foreach (['site', 'key', 'expiry', 'connection', 'manifest'] as $drift) {
    $facts = $context; $input = $projection;
    if ($drift === 'site') { $facts['site']['id'] = 9; }
    if ($drift === 'key') { $facts['early_access_credential_fingerprint'] = 'foreign'; }
    if ($drift === 'expiry') { $input['expires_at'] = gmdate('c', time() - 1); }
    if ($drift === 'connection') { $facts['connection']['status'] = 'disconnected'; }
    if ($drift === 'manifest') { $facts['connector_runtime']['capability_manifest']['current'] = false; }
    $assert(!isset($normalize($input, $facts)['public_mcp']), 'Current context must bind public projection: '.$drift);
}
echo "Public MCP normalization passed (coexistence, legacy, 7 malformed, rollback, 4 merged display cases, 5 authority drifts).\n";
