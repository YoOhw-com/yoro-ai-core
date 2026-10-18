<?php
/**
 * Versioned operational write and bounded recovery smoke contract.
 *
 * Run through WordPress with the plugin worktree loaded. All fixtures are
 * created inside a database transaction and rolled back.
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
$assert( ! empty( $admin_ids ), 'An administrator is required for operational write smokes.' );
wp_set_current_user( absint( $admin_ids[0] ) );

if ( class_exists( 'YoRo_Action_Registry' ) && 0 === count( YoRo_Action_Registry::all() ) ) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

$registry = YoRo_Action_Registry::all();

foreach ( [
	'orders.update_status' => 'per_item_optimistic_version',
	'products.update_stock' => 'optimistic_version',
	'products.update_stock_status' => 'optimistic_version',
] as $action_name => $concurrency_strategy ) {
	$action = $registry[ $action_name ] ?? null;
	$assert( is_array( $action ), sprintf( '%s must be registered.', $action_name ) );
	$assert( ! empty( $action['write_action'] ), sprintf( '%s must be classified as a write.', $action_name ) );
	$assert( ! empty( $action['requires_confirmation'] ), sprintf( '%s must require confirmation.', $action_name ) );
	$assert( 'operation_journal' === ( $action['idempotency_strategy'] ?? '' ), sprintf( '%s must use the operation journal.', $action_name ) );
	$assert( $concurrency_strategy === ( $action['concurrency_strategy'] ?? '' ), sprintf( '%s must publish its version strategy.', $action_name ) );
}

$catalog = YoRo_Action_Catalog_Builder::build();
$catalog_by_name = [];

foreach ( $catalog as $item ) {
	if ( is_array( $item ) && ! empty( $item['name'] ) ) {
		$catalog_by_name[ $item['name'] ] = $item;
	}
}

foreach ( [ 'orders.update_status', 'products.update_stock', 'products.update_stock_status' ] as $action_name ) {
	$assert( isset( $catalog_by_name[ $action_name ] ), sprintf( '%s must be published to Chat only after its safety contract is complete.', $action_name ) );
}

$assert( ! isset( $catalog_by_name['orders.restore_statuses'] ), 'Order recovery must remain hidden from AI selection.' );
$assert( ! isset( $catalog_by_name['products.restore_stock_state'] ), 'Product recovery must remain hidden from AI selection.' );

global $wpdb;

$wpdb->query( 'START TRANSACTION' );
$original_manage_stock = get_option( 'woocommerce_manage_stock', '__yoro_missing__' );
$product_id = 0;
$order_id = 0;

try {
	update_option( 'woocommerce_manage_stock', 'yes', false );

	$product = new WC_Product_Simple();
	$product->set_name( 'YoRo versioned write smoke ' . wp_generate_uuid4() );
	$product->set_status( 'draft' );
	$product->set_regular_price( '10' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 4 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();
	$product = wc_get_product( $product_id );
	$product_version = YoRo_Commerce_Discovery_Service::product_version( $product );

	$stale_product = YoRo_Store_Inventory_Service::update_product_stock(
		[
			'product_id'       => $product_id,
			'stock_quantity'   => 9,
			'expected_version' => str_repeat( '0', 64 ),
		]
	);
	$assert( is_wp_error( $stale_product ) && 'product_version_conflict' === $stale_product->get_error_code(), 'A stale product version must fail before mutation.' );
	$assert( 4 === (int) wc_get_product( $product_id )->get_stock_quantity(), 'A stale product write must not change stock.' );

	$product_save_count = 0;
	$count_product_save = static function ( $saved_product ) use ( &$product_save_count, $product_id ) {
		if ( $saved_product instanceof WC_Product && $product_id === $saved_product->get_id() ) {
			++$product_save_count;
		}
	};
	add_action( 'woocommerce_after_product_object_save', $count_product_save, 10, 1 );
	$product_result = YoRo_Store_Inventory_Service::update_product_stock(
		[
			'product_id'       => $product_id,
			'stock_quantity'   => 9,
			'expected_version' => $product_version,
		]
	);
	remove_action( 'woocommerce_after_product_object_save', $count_product_save, 10 );
	$assert( is_array( $product_result ) && ! empty( $product_result['success'] ), 'A current product version must allow the write.' );
	$assert( 1 === $product_save_count, 'A successful product write must save the product exactly once.' );
	$post_write_product = wc_get_product( $product_id );
	$assert( $post_write_product instanceof WC_Product, 'The persisted post-write product must be readable.' );
	$assert( 9 === (int) $post_write_product->get_stock_quantity(), 'The current product write must update stock.' );
	$post_write_version = YoRo_Commerce_Discovery_Service::product_version( $post_write_product );
	$assert( $post_write_version === ( $product_result['current_version'] ?? '' ), 'The product write must return the persisted post-write version.' );
	$product_undo = $product_result['undo_plan'][0] ?? [];
	$assert( 'products.restore_stock_state' === ( $product_undo['action'] ?? '' ), 'Product writes must return a bounded hidden recovery action.' );
	$assert( $post_write_version === ( $product_undo['params']['expected_version'] ?? '' ), 'Product recovery must bind to the persisted post-write version.' );
	$assert( $post_write_version === YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $product_id ) ), 'Repeated reads of unchanged persisted product state must return a stable version.' );
	$product_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $product_result, 'products.update_stock' );
	$product_schema = YoRo_Commerce_Contract_Builder::execution_output_schema( 'products.update_stock' );
	$assert( array_keys( $product_payload['data'] ) === $product_schema['properties']['data']['required'], 'Product writes must match the exact canonical output data schema.' );

	$product_save_count = 0;
	add_action( 'woocommerce_after_product_object_save', $count_product_save, 10, 1 );
	$product_recovery = YoRo_Store_Inventory_Service::restore_product_stock_state( $product_undo['params'] ?? [] );
	remove_action( 'woocommerce_after_product_object_save', $count_product_save, 10 );
	$product_recovery_error = is_wp_error( $product_recovery )
		? $product_recovery->get_error_code() . ':' . wp_json_encode( $product_recovery->get_error_data() )
		: gettype( $product_recovery );
	$assert( is_array( $product_recovery ) && ! empty( $product_recovery['success'] ), 'Product recovery must succeed against the exact post-write version. Received ' . $product_recovery_error );
	$assert( 1 === $product_save_count, 'A successful product recovery must save the product exactly once.' );
	$recovered_product = wc_get_product( $product_id );
	$assert( $recovered_product instanceof WC_Product, 'The persisted recovered product must be readable.' );
	$assert( true === $recovered_product->get_manage_stock(), 'Product recovery must restore the previous stock-management flag.' );
	$assert( 4 === (int) $recovered_product->get_stock_quantity(), 'Product recovery must restore the previous stock quantity.' );
	$assert( 'instock' === $recovered_product->get_stock_status(), 'Product recovery must restore the previous stock status.' );
	$recovered_version = YoRo_Commerce_Discovery_Service::product_version( $recovered_product );
	$assert( $recovered_version === ( $product_recovery['current_version'] ?? '' ), 'Product recovery must return the persisted recovered version.' );
	$product_save_count = 0;
	add_action( 'woocommerce_after_product_object_save', $count_product_save, 10, 1 );
	$replayed_product_recovery = YoRo_Store_Inventory_Service::restore_product_stock_state( $product_undo['params'] ?? [] );
	remove_action( 'woocommerce_after_product_object_save', $count_product_save, 10 );
	$assert( is_wp_error( $replayed_product_recovery ) && 'product_version_conflict' === $replayed_product_recovery->get_error_code(), 'An already-consumed recovery token must fail closed.' );
	$assert( 0 === $product_save_count, 'An already-consumed recovery token must not trigger a second product save.' );
	$assert( $recovered_version === YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $product_id ) ), 'Rejected recovery replay must leave persisted product state unchanged.' );

	$second_product_result = YoRo_Store_Inventory_Service::update_product_stock(
		[
			'product_id'       => $product_id,
			'stock_quantity'   => 11,
			'expected_version' => $recovered_version,
		]
	);
	$assert( is_array( $second_product_result ) && ! empty( $second_product_result['success'] ), 'A second current product version must allow the write.' );
	$stale_product_undo = $second_product_result['undo_plan'][0] ?? [];
	$intervening_product = wc_get_product( $product_id );
	$assert( $intervening_product instanceof WC_Product, 'The product must remain readable before an intervening mutation.' );
	$intervening_product->set_stock_quantity( 6 );
	$intervening_product->set_stock_status( 'instock' );
	$intervening_product->save();
	$intervening_product = wc_get_product( $product_id );
	$intervening_version = YoRo_Commerce_Discovery_Service::product_version( $intervening_product );
	$assert( $intervening_version !== ( $stale_product_undo['params']['expected_version'] ?? '' ), 'An intervening mutation must supersede the earlier recovery version.' );

	$product_save_count = 0;
	add_action( 'woocommerce_after_product_object_save', $count_product_save, 10, 1 );
	$stale_product_recovery = YoRo_Store_Inventory_Service::restore_product_stock_state( $stale_product_undo['params'] ?? [] );
	remove_action( 'woocommerce_after_product_object_save', $count_product_save, 10 );
	$assert( is_wp_error( $stale_product_recovery ) && 'product_version_conflict' === $stale_product_recovery->get_error_code(), 'Recovery must reject a stale token after an intervening mutation.' );
	$assert( 0 === $product_save_count, 'Stale recovery must fail before triggering a product save.' );
	$post_conflict_product = wc_get_product( $product_id );
	$assert( 6 === (int) $post_conflict_product->get_stock_quantity(), 'Stale recovery must not overwrite the intervening product state.' );
	$assert( $intervening_version === YoRo_Commerce_Discovery_Service::product_version( $post_conflict_product ), 'Stale recovery must leave the intervening persisted version unchanged.' );

	$order = wc_create_order( [ 'status' => 'processing', 'customer_id' => 0 ] );
	$assert( $order instanceof WC_Order, 'A WooCommerce order fixture is required.' );
	$order_id = $order->get_id();
	$order = wc_get_order( $order_id );
	// Timestamp equality must not hide edits made during the same second.
	$modified_at = $order->get_date_modified();
	foreach ( [ 'billing_email' => 'version-test@example.invalid', 'shipping_address_1' => 'Changed same-second address', 'customer_note' => 'Changed customer instruction' ] as $field => $value ) {
		$before = YoRo_Order_Presenter::order_version( $order );
		$order->{ 'set_' . $field }( $value );
		$order->set_date_modified( $modified_at );
		$assert( $before !== YoRo_Order_Presenter::order_version( $order ), 'Order versions must bind ' . $field . ' without relying on timestamps.' );
	}
	$before_tracking = YoRo_Order_Presenter::order_version( $order );
	$order->update_meta_data( '_wc_shipment_tracking_items', [ [ 'tracking_number' => 'YR0178-VERSION', 'tracking_provider' => 'dhl' ] ] );
	$assert( $before_tracking !== YoRo_Order_Presenter::order_version( $order ), 'Order versions must bind stored tracking data.' );
	$order->save();
	$order = wc_get_order( $order_id );
	$order_version = YoRo_Order_Presenter::order_version( $order );

	$stale_order = YoRo_Orders_Write_Service::update_order_status(
		[
			'order_ids' => [ $order_id ],
			'status' => 'completed',
			'expected_versions' => [
				[ 'order_id' => $order_id, 'expected_version' => str_repeat( '0', 64 ) ],
			],
		]
	);
	$assert( is_wp_error( $stale_order ) && 'order_version_conflict' === $stale_order->get_error_code(), 'A stale order version must fail before mutation.' );
	$assert( 'processing' === wc_get_order( $order_id )->get_status(), 'A stale order write must not change status.' );

	$order_result = YoRo_Orders_Write_Service::update_order_status(
		[
			'order_ids' => [ $order_id ],
			'status' => 'completed',
			'expected_versions' => [
				[ 'order_id' => $order_id, 'expected_version' => $order_version ],
			],
		]
	);
	$assert(
		is_array( $order_result ) && ! empty( $order_result['success'] ),
		is_wp_error( $order_result )
			? sprintf( 'A current order version must allow the write; got %s.', $order_result->get_error_code() )
			: 'A current order version must allow the write.'
	);
	$assert( 'completed' === wc_get_order( $order_id )->get_status(), 'The current order write must update status.' );
	$order_undo = $order_result['undo_plan'][0] ?? [];
	$assert( 'orders.restore_statuses' === ( $order_undo['action'] ?? '' ), 'Order writes must return a bounded hidden recovery action.' );
	$assert( ( $order_result['current_versions'] ?? [] ) === ( $order_undo['params']['expected_versions'] ?? [] ), 'Order recovery must bind to post-write versions.' );
	$order_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $order_result, 'orders.update_status' );
	$order_schema = YoRo_Commerce_Contract_Builder::execution_output_schema( 'orders.update_status' );
	$assert( array_keys( $order_payload['data'] ) === $order_schema['properties']['data']['required'], 'Order writes must match the exact canonical output data schema.' );

	$order_recovery = YoRo_Orders_Write_Service::restore_order_statuses( $order_undo['params'] ?? [] );
	$assert( is_array( $order_recovery ) && ! empty( $order_recovery['success'] ), 'Order recovery must succeed against exact post-write versions.' );
	$assert( 'processing' === wc_get_order( $order_id )->get_status(), 'Order recovery must restore the previous status.' );
} finally {
	$wpdb->query( 'ROLLBACK' );

	if ( '__yoro_missing__' === $original_manage_stock ) {
		delete_option( 'woocommerce_manage_stock' );
	} else {
		update_option( 'woocommerce_manage_stock', $original_manage_stock, false );
	}

	if ( $product_id > 0 ) {
		clean_post_cache( $product_id );
	}

	if ( $order_id > 0 ) {
		clean_post_cache( $order_id );
	}

	wp_cache_flush();
}

echo "YoRo versioned operational writes: PASS\n";
