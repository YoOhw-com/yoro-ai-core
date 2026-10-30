<?php
/** Source-loaded gate locally; actual fenced runner/journal in the existing disposable WP/Woo CI. */
if (!defined('ABSPATH')) {
define('ABSPATH', __DIR__ . '/');
class YoRo_AI_Settings {
    public static array $projection = [];
    public static function get_early_access_onboarding() { return self::$projection; }
}
require dirname(__DIR__, 2) . '/includes/commerce/class-yoro-connector-runtime.php';
$gate = new ReflectionMethod(YoRo_Connector_Runtime::class, 'ordinary_resource_allowed');
$assert = static function($condition, $message) { if (!$condition) { throw new RuntimeException($message); } };
$ea = ['profile' => 'early_access', 'binding_version' => 2, 'resource' => 'https://source-api.invalid/mcp/early-access'];
$public = [...$ea, 'resource' => 'https://source-api.invalid/mcp'];
$allows = static fn($facts, $write = false) => $gate->invoke(null, $facts, $write);
$assert(!$allows($public) && !$allows($ea), 'Default off grants no transport authority.');
YoRo_AI_Settings::$projection = ['runtime_enabled' => true, 'server_url' => $ea['resource'], 'confirmed_commerce_writes_enabled' => true];
$assert($allows($ea, true) && !$allows($public), 'EA projection cannot admit public provenance.');
YoRo_AI_Settings::$projection['public_mcp'] = ['version' => 1, 'resource' => $public['resource'], 'runtime_enabled' => true, 'confirmed_commerce_writes_enabled' => false];
$assert($allows($public) && !$allows($public, true) && $allows($ea, true), 'Read-only public projection cannot gain EA write authority.');
YoRo_AI_Settings::$projection['public_mcp']['confirmed_commerce_writes_enabled'] = true;
$assert($allows($public, true), 'Exact separately enabled public write provenance is supported.');
foreach ([['profile', 'review_frozen'], ['binding_version', 1], ['resource', 'https://foreign.invalid/mcp'], ['resource', $public['resource'].'/'], ['resource', 'https://source-api.invalid:8443/mcp']] as [$key, $value]) {
    $assert(!$allows([...$public, $key => $value], true), 'Foreign/malformed binding is denied: '.$key);
}
YoRo_AI_Settings::$projection['public_mcp']['runtime_enabled'] = false;
$assert(!$allows($public) && !$allows($public, true) && $allows($ea, true), 'Public rollback is rechecked and preserves EA.');
YoRo_AI_Settings::$projection['runtime_enabled'] = false;
YoRo_AI_Settings::$projection['public_mcp']['runtime_enabled'] = true;
$assert($allows($public, true) && !$allows($ea), 'Public enablement cannot bypass EA rollback.');
echo "Public MCP exact transport gate passed (default off, channel isolation, separate writes, drift and rollback).\n";

} else {
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $fixture_root = realpath(ABSPATH);
    $runner_root = realpath((string) getenv('RUNNER_TEMP'));
    $ci = getenv('GITHUB_ACTIONS') === 'true' && in_array($host, ['yoro-plugin-smoke.test', 'yoro-wordpress-smoke.test', 'yoro-wordpress-exact-pair.test', 'yoro-woocommerce-exact-pair.test'], true)
        && $fixture_root !== false && $runner_root !== false && str_starts_with($fixture_root, rtrim($runner_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    if (!str_ends_with($host, '.invalid') && !$ci) { throw new RuntimeException('Only an exact disposable SOURCE_TEST installation is permitted.'); }
    if (!class_exists('YoRo_Connector_Runtime')) { YoRo_AI_Core::init(); }
    if (!class_exists('WooCommerce')) { echo "Public WooCommerce runner witness NOT_RUN in the WordPress-only phase.\n"; return; }
    require_once __DIR__.'/../contracts/helpers/native-write-runtime.php';
    $assert = static function($condition, $message) { if (!$condition) { throw new RuntimeException($message); } };
    $original = get_current_user_id(); global $wpdb; $wpdb->query('START TRANSACTION'); $lock = null;
    $block = static function() { throw new RuntimeException('The public source fixture cannot make network requests.'); };
    add_filter('pre_http_request', $block); add_filter('pre_wp_mail', '__return_true');
    $stock_effects = 0; $effect = static function() use (&$stock_effects) { ++$stock_effects; };
    $acquire = new ReflectionMethod(YoRo_Connector_Runtime::class, 'acquire_lock');
    $release = new ReflectionMethod(YoRo_Connector_Runtime::class, 'release_lock');
    try {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        $assert(count($admins) === 1, 'A disposable administrator fixture is required.');
        wp_set_current_user((int) $admins[0]);
        YoRo_WordPress_Actor::record_access(['assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => ['yoro_view_store_data', 'yoro_execute_actions']]);
        yoro_native_write_fixture_setup();
        $status = YoRo_AI_Settings::get_ai_api_status();
        $status['early_access_onboarding']['public_mcp'] = ['version' => 1, 'resource' => 'https://r2-api.yorohq.com/mcp', 'manifest_version' => 1, 'runtime_enabled' => true, 'confirmed_commerce_writes_enabled' => true];
        YoRo_AI_Settings::update_ai_api_status($status);
        $projection = YoRo_AI_Settings::get_early_access_onboarding();
        $assert($projection['server_url'] === 'https://r2-api.yorohq.com/mcp/early-access' && $projection['public_mcp']['resource'] === 'https://r2-api.yorohq.com/mcp', 'Both exact projections are retained.');
        $manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest(['connector_key' => 'woocommerce', 'connector_connection_id' => 'confirmed-commerce-fixture', 'execution_mode' => 'remote_agent', 'protocol_version' => 1], 1);
        $assert(($manifest['extensions']['yoro.public_mcp'] ?? null) === ['version' => 1, 'profile' => 'early_access', 'binding_version' => 2, 'resource_path' => '/mcp', 'shared_ordinary_delivery' => true], 'The actual Core manifest explicitly negotiates exact public support.');
        $product = new WC_Product_Simple(); $product->set_name('Disposable public MCP stock'); $product->set_status('publish'); $product->set_manage_stock(true); $product->set_stock_quantity(5); $product->save();
        add_action('woocommerce_product_set_stock', $effect);
        $client = yoro_native_write_fixture_client();
        $client->operation = yoro_native_write_fixture_envelope('products.update_stock', ['product_id' => $product->get_id(), 'stock_quantity' => 7, 'expected_version' => YoRo_Commerce_Discovery_Service::product_version(wc_get_product($product->get_id()))]);
        $client->operation['extensions']['yoro.mcp_early_access_commerce_write']['resource'] = 'https://r2-api.yorohq.com/mcp';
        $runtime = new YoRo_Connector_Runtime($client); $lock = $acquire->invoke(null);
        $assert($lock !== null && !YoRo_Connector_Runtime::live_cutover_allowed(), 'The ordinary runner lock is required and generic cutover remains off.');
        $first_effects = null;
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $result = $runtime->run_early_access_write_once();
            $assert(!is_wp_error($result) && ($result['success'] ?? false) === true && end($client->results)['status'] === 'executed', 'The public write must execute and replay its exact durable result.');
            if ($attempt === 0) { $first_effects = $stock_effects; $assert($first_effects > 0, 'The first native write must execute its stock hooks.'); }
        }
        $assert($stock_effects === $first_effects && wc_get_product($product->get_id())->get_stock_quantity() === 7, 'Replay cannot repeat native mutation or hooks.');
        $status['early_access_onboarding']['public_mcp']['runtime_enabled'] = false;
        $status['early_access_onboarding']['public_mcp']['confirmed_commerce_writes_enabled'] = false;
        YoRo_AI_Settings::update_ai_api_status($status);
        $denied = $runtime->run_early_access_write_once();
        $assert(is_wp_error($denied) && $stock_effects === $first_effects, 'Public rollback blocks a claimed public envelope before an effect.');
        $client->operation = yoro_native_write_fixture_envelope('products.update_stock', ['product_id' => $product->get_id(), 'stock_quantity' => 8, 'expected_version' => YoRo_Commerce_Discovery_Service::product_version(wc_get_product($product->get_id()))]);
        $result = $runtime->run_early_access_write_once();
        $assert(!is_wp_error($result) && end($client->results)['status'] === 'executed' && $stock_effects === 2 * $first_effects, 'EA delivery still works after public rollback.');
        echo "Public MCP actual fenced runner, manifest negotiation, one-effect journal replay and EA coexistence passed.\n";
    } finally {
        if ($lock !== null) { $release->invoke(null, $lock); }
        remove_action('woocommerce_product_set_stock', $effect);
        remove_filter('pre_http_request', $block); remove_filter('pre_wp_mail', '__return_true');
        $wpdb->query('ROLLBACK'); wp_cache_flush(); wp_set_current_user($original);
    }
}
