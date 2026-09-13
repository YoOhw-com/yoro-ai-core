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

global $wpdb;

$wpdb->query( 'START TRANSACTION' );
$product_id = 0;
$order_id = 0;

try {
	$fixture_key = strtolower( wp_generate_uuid4() );
	$product = new WC_Product_Simple();
	$product->set_name( 'YoRo discovery smoke ' . $fixture_key );
	$product->set_sku( 'yoro-discovery-' . $fixture_key );
	$product->set_status( 'draft' );
	$product->set_regular_price( '10' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 4 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	$order = wc_create_order( [ 'status' => 'processing', 'customer_id' => 0 ] );
	$assert( $order instanceof WC_Order, 'The discovery smoke must create an order fixture.' );
	$order_id = $order->get_id();
	$order->add_order_note( 'YoRo discovery internal note.', false );
	$order->add_order_note( 'YoRo discovery customer note.', true );
	$order->update_meta_data(
		'_wc_shipment_tracking_items',
		[
			[
				'tracking_number'  => 'YORO-' . substr( str_replace( '-', '', $fixture_key ), 0, 12 ),
				'tracking_provider' => 'YoRo Smoke Carrier',
				'custom_tracking_link' => 'https://example.test/tracking',
			],
		]
	);
	$order->save();

	$product = wc_get_product( $product_id );
	$assert( $product instanceof WC_Product, 'The discovery smoke must create a product fixture.' );
	$sku = (string) $product->get_sku();

	$search = YoRo_Commerce_Discovery_Service::search_products( [ 'sku' => $sku, 'limit' => 1 ] );
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
	$assert( is_array( $tracking ) && 'available' === ( $tracking['status'] ?? '' ), 'Tracking reads must return the stored fixture.' );
	$assert( 1 === ( $tracking['count'] ?? 0 ), 'Tracking reads must return exactly one self-contained fixture.' );

	foreach ( [
		'products.search' => $search,
		'products.get_details' => $details,
		'orders.get_timeline' => $timeline,
		'fulfillment.get_tracking' => $tracking,
	] as $action => $result ) {
		$payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, $action );
		$schema = YoRo_Commerce_Contract_Builder::execution_output_schema( $action );
		$expected_roots = $schema['properties']['data']['required'] ?? [];
		if ( 'fulfillment.get_tracking' === $action ) {
			$expected_roots = array_merge( $expected_roots, [ 'native_status', 'native_fulfillments' ] );
		}
		$assert( array_keys( $payload['data'] ) === $expected_roots, sprintf( '%s must match its exact canonical data roots.', $action ) );
	}

	$missing_product = YoRo_Commerce_Discovery_Service::get_product_details( [ 'product_ids' => [] ] );
	$missing_order = YoRo_Commerce_Discovery_Service::get_order_timeline( [ 'order_id' => 0 ] );
	$assert( is_wp_error( $missing_product ), 'Missing product targets must fail closed.' );
	$assert( is_wp_error( $missing_order ), 'Missing order targets must fail closed.' );
} finally {
	$wpdb->query( 'ROLLBACK' );

	if ( $product_id > 0 ) {
		clean_post_cache( $product_id );
	}

	if ( $order_id > 0 ) {
		clean_post_cache( $order_id );
	}

	wp_cache_flush();
}

echo "YoRo commerce discovery: PASS\n";
