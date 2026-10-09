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
$additional_product_ids = [];
$fixture_output = null;

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

	$filtered_draft = YoRo_Commerce_Discovery_Service::search_products( [ 'sku' => $sku, 'status' => 'publish', 'type' => 'simple', 'limit' => 1 ] );
	$assert( 0 === ( $filtered_draft['count'] ?? -1 ), 'An explicit published SKU filter must exclude a draft product.' );

	$published = new WC_Product_Simple();
	$published->set_name( 'YR0163 safe published product' );
	$published->set_sku( 'YR0163.SAFE-1' );
	$published->set_status( 'publish' );
	$published->set_regular_price( '12.30' );
	$published->set_manage_stock( true );
	$published->set_stock_quantity( 4 );
	$published->set_stock_status( 'instock' );
	$additional_product_ids[] = $published->save();
	$positive = YoRo_Commerce_Discovery_Service::search_products( [ 'sku' => 'YR0163.SAFE-1', 'status' => 'publish', 'type' => 'simple', 'limit' => 1 ] );
	$assert( 1 === ( $positive['count'] ?? 0 ), 'Published simple SKU must yield one real Woo product.' );
	$assert( 'YR0163.SAFE-1' === ( $positive['products'][0]['sku'] ?? '' ), 'Positive SKU must match byte-for-byte.' );
	$wrong_stock = YoRo_Commerce_Discovery_Service::search_products( [ 'sku' => 'YR0163.SAFE-1', 'stock_status' => 'outofstock' ] );
	$assert( 0 === ( $wrong_stock['count'] ?? -1 ), 'An explicit SKU stock filter must be enforced.' );
	$missing = YoRo_Commerce_Discovery_Service::search_products( [ 'sku' => 'YR0163.ABSENT-' . $fixture_key, 'status' => 'publish', 'type' => 'simple' ] );
	$assert( 0 === ( $missing['count'] ?? -1 ), 'Absent SKU must return a valid empty result.' );

	$variable = new WC_Product_Variable();
	$variable->set_name( 'YR0163 variable fixture' );
	$variable->set_sku( 'YR0163.VARIABLE' );
	$variable->set_status( 'publish' );
	$additional_product_ids[] = $variable->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable->get_id() );
	$variation->set_sku( 'YR0163.VARIATION' );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( '9' );
	$additional_product_ids[] = $variation->save();
	foreach ( [ 'YR0163.VARIABLE', 'YR0163.VARIATION' ] as $other_sku ) {
		$legacy = YoRo_Commerce_Discovery_Service::search_products( [ 'sku' => $other_sku ] );
		$assert( 1 === ( $legacy['count'] ?? 0 ), 'Unfiltered SKU variation/variable compatibility must remain available.' );
		$filtered = YoRo_Commerce_Discovery_Service::search_products( [ 'sku' => $other_sku, 'status' => 'publish', 'type' => 'simple' ] );
		$assert( 0 === ( $filtered['count'] ?? -1 ), 'Explicit simple SKU filter must exclude variable and variation products.' );
	}

	$registered = YoRo_Action_Registry::get( 'products.search' );
	$assert( false === $registered['write_action'] && 'yoro_view_store_data' === $registered['permission'], 'Effective Core registry must retain normalized read-only product permission.' );
	$authorized_read = YoRo_Action_Executor::execute( 'products.search', [ 'sku' => 'YR0163.SAFE-1', 'status' => 'publish', 'type' => 'simple', 'limit' => 1 ] );
	$assert( is_array( $authorized_read ) && true === $authorized_read['success'], 'The actual read-only executor must accept the fixture administrator.' );
	$original_user_id = get_current_user_id();
	wp_set_current_user( 0 );
	$denied_read = YoRo_Action_Executor::execute( 'products.search', [ 'sku' => 'YR0163.SAFE-1' ] );
	wp_set_current_user( $original_user_id );
	$assert( is_wp_error( $denied_read ) && 'yoro_permission_denied' === $denied_read->get_error_code(), 'Anonymous fixture callers must fail the unchanged product read permission.' );
	$canonical_positive = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $positive, 'products.search' );
	$assert( true === $canonical_positive['success'] && true === $canonical_positive['read_only'], 'Real Woo product must have a canonical read result.' );
	$fixture_output = [
		'core_head' => trim( (string) shell_exec( 'git -C ' . escapeshellarg( $plugin_root ) . ' rev-parse HEAD' ) ),
		'wordpress_version' => get_bloginfo( 'version' ), 'woocommerce_version' => WC_VERSION,
		'manifest' => YoRo_Commerce_Contract_Builder::build_capability_manifest( [ 'connector_key' => 'woocommerce', 'connector_connection_id' => 'yr0163_fixture_connection', 'execution_mode' => 'remote_agent', 'protocol_version' => 1 ], 1 ),
		'product_result' => $canonical_positive,
	];


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
		$assert( array_keys( $payload['data'] ) === ( $schema['properties']['data']['required'] ?? [] ), sprintf( '%s must match its exact canonical data roots.', $action ) );
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

	foreach ( $additional_product_ids as $additional_id ) {
		clean_post_cache( $additional_id );
	}
	wp_cache_flush();
	foreach ( array_merge( [ $product_id ], $additional_product_ids ) as $rolled_back_id ) {
		$assert( null === get_post( $rolled_back_id ), 'Every product fixture must be rolled back.' );
	}
}

$output_path = getenv( 'YR0163_CORE_FIXTURE_OUTPUT' );
if ( is_string( $output_path ) && '' !== $output_path && is_array( $fixture_output ) ) {
	file_put_contents( $output_path, wp_json_encode( $fixture_output, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
}
echo "YoRo commerce discovery: PASS\n";
