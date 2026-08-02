<?php
/**
 * Read-only commerce discovery and tracking smoke contract.
 *
 * Run from WordPress with the plugin worktree loaded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( class_exists( 'YoRo_AI_Core' ) && function_exists( 'yoro_ai_core_has_woocommerce' ) && yoro_ai_core_has_woocommerce() ) {
	YoRo_AI_Core::init();
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admin_ids ), 'An administrator is required for the read-only discovery smoke.' );
wp_set_current_user( absint( $admin_ids[0] ) );

$product_ids = function_exists( 'wc_get_products' ) ? wc_get_products( [ 'limit' => 1, 'return' => 'ids' ] ) : [];
$order_ids = function_exists( 'wc_get_orders' ) ? wc_get_orders( [ 'limit' => 1, 'return' => 'ids' ] ) : [];

$assert( ! empty( $product_ids ), 'A WooCommerce product fixture is required.' );
$assert( ! empty( $order_ids ), 'A WooCommerce order fixture is required.' );

$product_id = absint( $product_ids[0] );
$order_id = absint( $order_ids[0] );
$product = wc_get_product( $product_id );
$sku = $product instanceof WC_Product ? (string) $product->get_sku() : '';

$search = YoRo_Commerce_Discovery_Service::search_products( '' !== $sku ? [ 'sku' => $sku, 'limit' => 1 ] : [ 'limit' => 1 ] );
$assert( is_array( $search ) && ! empty( $search['success'] ), 'Product search must execute as a read.' );
$assert( ! empty( $search['products'][0]['version'] ), 'Product search must return an optimistic version token.' );

$details = YoRo_Commerce_Discovery_Service::get_product_details( [ 'product_ids' => [ $product_id ] ] );
$assert( is_array( $details ) && $product_id === ( $details['products'][0]['id'] ?? 0 ), 'Product details must return the requested product.' );

$timeline = YoRo_Commerce_Discovery_Service::get_order_timeline( [ 'order_id' => $order_id, 'limit' => 20 ] );
$assert( is_array( $timeline ) && ! empty( $timeline['success'] ), 'Order timeline must execute as a read.' );

foreach ( (array) ( $timeline['events'] ?? [] ) as $event ) {
	$assert( in_array( $event['visibility'] ?? '', [ 'system', 'internal', 'customer' ], true ), 'Timeline notes must have an explicit visibility boundary.' );
}

$tracking = YoRo_Commerce_Discovery_Service::get_fulfillment_tracking( [ 'order_id' => $order_id ] );
$assert( is_array( $tracking ) && in_array( $tracking['status'] ?? '', [ 'available', 'unavailable' ], true ), 'Tracking reads must return an explicit availability state.' );

foreach ( [
	'products.search' => $search,
	'products.get_details' => $details,
	'orders.get_timeline' => $timeline,
	'fulfillment.get_tracking' => $tracking,
] as $action => $result ) {
	$payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, $action );
	$schema = YoRo_Commerce_Contract_Builder::execution_output_schema( $action );
	$assert( array_keys( $payload['data'] ) === ( $schema['properties']['data']['required'] ?? [] ), sprintf( '%s must match its exact canonical data roots.', $action ) );
}

$missing_product = YoRo_Commerce_Discovery_Service::get_product_details( [ 'product_ids' => [] ] );
$missing_order = YoRo_Commerce_Discovery_Service::get_order_timeline( [ 'order_id' => 0 ] );
$assert( is_wp_error( $missing_product ), 'Missing product targets must fail closed.' );
$assert( is_wp_error( $missing_order ), 'Missing order targets must fail closed.' );

echo "YoRo commerce discovery: PASS\n";
