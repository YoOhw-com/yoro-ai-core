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
	'orders.update_addresses' => 'optimistic_version',
	'orders.update_status' => 'per_item_optimistic_version',
	'products.update_content' => 'optimistic_version',
	'products.create_draft' => 'create_only',
		'products.update_pricing' => 'optimistic_version',
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

	foreach ( [ 'orders.update_addresses', 'orders.update_status', 'products.create_draft', 'products.update_content', 'products.update_pricing', 'products.update_stock', 'products.update_stock_status' ] as $action_name ) {
	$assert( isset( $catalog_by_name[ $action_name ] ), sprintf( '%s must be published to Chat only after its safety contract is complete.', $action_name ) );
}

$assert( ! isset( $catalog_by_name['orders.restore_statuses'] ), 'Order recovery must remain hidden from AI selection.' );
	$assert( ! isset( $catalog_by_name['products.restore_stock_state'] ), 'Product recovery must remain hidden from AI selection.' );
	$assert( ! isset( $catalog_by_name['products.restore_pricing_state'] ), 'Pricing recovery must remain hidden from AI selection.' );
	$assert( ! isset( $catalog_by_name['products.restore_content_state'] ), 'Content recovery must remain hidden from AI selection.' );

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
	$product->set_name( $product->get_name( 'edit' ) . ' changed' );
	$product->save();
	$name_version = YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $product_id ) );
	$assert( $name_version !== $product_version, 'Raw product name must participate in product version.' );
	$product = wc_get_product( $product_id );
	$product->set_description( '<p>Versioned description</p>' );
	$product->save();
	$description_version = YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $product_id ) );
	$assert( $description_version !== $name_version, 'Raw product description must participate in product version.' );
	$product = wc_get_product( $product_id );
	$product->set_short_description( '<p>Versioned short description</p>' );
	$product->save();
	$product = wc_get_product( $product_id );
	$product_version = YoRo_Commerce_Discovery_Service::product_version( $product );
	$assert( $product_version !== $description_version, 'Raw product short description must participate in product version.' );

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
	// B3 fixtures share the disposable transaction; no existing customer/order is mutated.
	$customer_id = wp_insert_user( [ 'user_login' => 'yoro_b3_' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'customer' ] );
	$assert( ! is_wp_error( $customer_id ), 'The disposable registered customer must exist.' );
	$customer = new WC_Customer( $customer_id );
	$customer->set_shipping_city( 'Saved profile city' );
	$customer->save();
	$order = wc_get_order( $order_id );
	$order->set_customer_id( $customer_id );
	$order->set_shipping_first_name( 'Fixture' );
	$order->set_shipping_last_name( 'Recipient' );
	$order->set_shipping_company( 'Company' );
	$order->set_shipping_address_1( '1 Old Street' );
	$order->set_shipping_address_2( 'Unit 2' );
	$order->set_shipping_city( 'Old city' );
	$order->set_shipping_state( 'CA' );
	$order->set_shipping_postcode( '90001' );
	$order->set_shipping_country( 'US' );
	$order->set_billing_city( 'Billing city' );
	$order->set_payment_method( 'bacs' );
	$shipping_line = new WC_Order_Item_Shipping();
	$shipping_line->set_method_title( 'Fixture shipping' );
	$shipping_line->set_method_id( 'flat_rate' );
	$shipping_line->set_total( '5.00' );
	$order->add_item( $shipping_line );
	$tax_line = new WC_Order_Item_Tax();
	$tax_line->set_label( 'Fixture tax' );
	$tax_line->set_tax_total( '2.00' );
	$order->add_item( $tax_line );
	$order->set_shipping_total( '5.00' );
	$order->set_cart_tax( '2.00' );
	$order->set_total( '17.00' );
	$order->save();
	$snapshot = static function ( $id ) {
		$current = wc_get_order( $id );
		$data = $current->get_data();
		unset( $data['shipping'], $data['date_modified'], $data['version'] );
		$data['meta_data'] = array_values( array_filter( $data['meta_data'], static function ( $item ) {
			return '_shipping_address_index' !== $item->key;
		} ) );
		foreach ( [ 'line_items', 'tax_lines', 'shipping_lines', 'fee_lines', 'coupon_lines' ] as $key ) {
			$data[ $key ] = array_map( static function ( $item ) { return $item->get_data(); }, $data[ $key ] );
		}
		return wp_json_encode( $data );
	};
	$commercial_before = $snapshot( $order_id );
	$profile_before = ( new WC_Customer( $customer_id ) )->get_shipping();
	$order = wc_get_order( $order_id );
	$version = YoRo_Order_Presenter::order_version( $order );
	$address_params = [ 'order_id' => $order_id, 'expected_version' => $version, 'shipping' => [ 'city' => 'New city', 'company' => '', 'address_2' => '', 'state' => '', 'postcode' => '', 'country' => 'us' ] ];
	$address_save_count = 0;
	$count_address_save = static function ( $saved ) use ( &$address_save_count, $order_id ) {
		if ( $saved instanceof WC_Order && $saved->get_id() === $order_id ) { ++$address_save_count; }
	};
	add_action( 'woocommerce_after_order_object_save', $count_address_save, 10, 1 );
	$preview = YoRo_Orders_Write_Service::preview_update_order_addresses( $address_params );
	$assert( false === $preview['blocked'] && 0 === $address_save_count, 'Valid address preview is read-only.' );
	$assert( ! isset( $preview['changes']['country'] ) && 5 === count( $preview['changes'] ), 'Preview contains only normalized material deltas.' );
	$assert( [ 'before' => 'Company', 'after' => '' ] === $preview['changes']['company'], 'Preview preserves an explicit clear.' );
	$assert( false !== strpos( $preview['message'], 'order #' . $order_id ) && false !== strpos( $preview['message'], 'city: "Old city" → "New city"' ) && false !== strpos( $preview['message'], 'company: "Company" → ""' ), 'The existing confirmation renderer receives visible target and exact deltas, including clears.' );
	$assert( false === strpos( wp_json_encode( $preview ), 'Billing city' ), 'Preview must not disclose unrelated billing data.' );
	$assert( $version === $preview['expected_version'] && $version === $preview['current_version'], 'Preview binds the current read version.' );
	$negative_patches = [ [], [ 'billing' => 'bad' ], [ 'city' => '' ], [ 'first_name' => '' ], [ 'last_name' => '' ], [ 'address_1' => ' ' ], [ 'country' => '' ], [ 'country' => 'ZZ' ], [ 'city' => null ], [ 'city' => 123 ], [ 'city' => [ 'nested' ] ], [ 'city' => str_repeat( 'x', 201 ) ], [ 'city' => "bad\0value" ], [ 'city' => 'Old city' ] ];
	foreach ( $negative_patches as $patch ) {
		$invalid = array_replace( $address_params, [ 'shipping' => $patch ] );
		$blocked = YoRo_Orders_Write_Service::preview_update_order_addresses( $invalid );
		$assert( true === $blocked['blocked'] && false === $blocked['will_execute'], 'Invalid/no-op address preview must not offer execution.' );
		$assert( is_wp_error( YoRo_Orders_Write_Service::update_order_addresses( $invalid ) ), 'Invalid/no-op address execution fails closed.' );
	}
	foreach ( [ [ 'order_ids' => [ $order_id ] ], [ 'billing' => [ 'city' => 'bad' ] ], [ 'customer_id' => $customer_id ], [ 'expected_version' => str_repeat( '0', 64 ) ], [ 'order_id' => (string) $order_id ] ] as $override ) {
		$assert( is_wp_error( YoRo_Orders_Write_Service::update_order_addresses( array_replace( $address_params, $override ) ) ), 'Unknown, bulk, untyped target and stale requests fail closed.' );
	}
	wp_set_current_user( $customer_id );
	$assert( true === YoRo_Orders_Write_Service::preview_update_order_addresses( $address_params )['blocked'], 'Customer role cannot preview a store write.' );
	$assert( is_wp_error( YoRo_Orders_Write_Service::update_order_addresses( $address_params ) ), 'Customer role cannot execute a store write.' );
	wp_set_current_user( absint( $admin_ids[0] ) );
	$assert( 0 === $address_save_count && $commercial_before === $snapshot( $order_id ), 'All rejected requests must leave the order unchanged.' );
	$unconfirmed = YoRo_Action_Executor::execute( 'orders.update_addresses', $address_params, false );
	$assert( true === $unconfirmed['requires_confirmation'] && 0 === $address_save_count, 'Executor requires confirmation before invoking B3.' );
	$address_result = YoRo_Action_Executor::execute( 'orders.update_addresses', $address_params, true );
	$assert( is_array( $address_result ) && true === $address_result['success'], 'Confirmed current address write succeeds.' );
	$assert( 1 === $address_save_count, 'Address mutation saves exactly once.' );
	$assert( $commercial_before === $snapshot( $order_id ), 'Totals, tax/shipping line identities, billing, payment and other order data remain unchanged.' );
	$assert( $profile_before === ( new WC_Customer( $customer_id ) )->get_shipping(), 'Registered customer shipping profile remains unchanged.' );
	$after = wc_get_order( $order_id );
	$assert( 'New city' === $after->get_shipping_city() && '' === $after->get_shipping_company() && '1 Old Street' === $after->get_shipping_address_1(), 'Only explicit shipping deltas persist.' );
	$assert( $version !== $address_result['current_version'] && YoRo_Order_Presenter::order_version( $after ) === $address_result['current_version'], 'Result reports the persisted current version.' );
	$replay = YoRo_Orders_Write_Service::update_order_addresses( $address_params );
	$assert( is_wp_error( $replay ) && 'order_version_conflict' === $replay->get_error_code() && 1 === $address_save_count, 'Consumed address version cannot save again.' );
	remove_action( 'woocommerce_after_order_object_save', $count_address_save, 10 );

	// Bind all shipping fields even when the timestamp and every commercial fact are identical.
	foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ] as $field ) {
		$copy = clone $after;
		$copy->{ 'set_shipping_' . $field }( 'Changed' );
		$assert( YoRo_Order_Presenter::order_version( $copy ) !== YoRo_Order_Presenter::order_version( $after ), 'Same-second shipping change must invalidate the shared version: ' . $field );
	}
	$prior_version = YoRo_Order_Presenter::order_version( $after );
	$after->set_shipping_city( '<img src=x onerror=alert(1)> & Old city' );
	$after->save();
	$stale_preview = YoRo_Orders_Write_Service::preview_update_order_addresses( [ 'order_id' => $order_id, 'expected_version' => $prior_version, 'shipping' => [ 'city' => 'Next city' ] ] );
	$assert( true === $stale_preview['blocked'] && 'order_version_conflict' === $stale_preview['block_reason'], 'Intervening address write blocks confirmation of an old preview.' );
	$xss_preview = YoRo_Orders_Write_Service::preview_update_order_addresses( [ 'order_id' => $order_id, 'expected_version' => YoRo_Order_Presenter::order_version( wc_get_order( $order_id ) ), 'shipping' => [ 'city' => 'Next <b>city</b> & data' ] ] );
	$assert( false === $xss_preview['blocked'] && false === strpos( wp_json_encode( $xss_preview['changes'] ), '<' ), 'Provider-authored preview values are escaped as data.' );
	$assert( 'Next city &amp; data' === $xss_preview['changes']['city']['after'], 'Preview uses the exact sanitized value with presentation escaping.' );
	$unknown_save_count = 0;
	$unknown_save_hook = static function ( $saved ) use ( $order_id, &$unknown_save_count ) {
		if ( $saved instanceof WC_Order && $saved->get_id() === $order_id ) {
			++$unknown_save_count;
			throw new Error( 'Disposable post-save failure; do not expose this detail.' );
		}
	};
	add_action( 'woocommerce_after_order_object_save', $unknown_save_hook, 10, 1 );
	try {
		$unknown = YoRo_Orders_Write_Service::update_order_addresses( [ 'order_id' => $order_id, 'expected_version' => YoRo_Order_Presenter::order_version( wc_get_order( $order_id ) ), 'shipping' => [ 'city' => 'Reconcile this state' ] ] );
		$assert( is_wp_error( $unknown ) && 'shipping_save_outcome_unknown' === $unknown->get_error_code(), 'Unknown post-save outcome requires reconciliation: ' . ( is_wp_error( $unknown ) ? $unknown->get_error_code() : wp_json_encode( $unknown ) ) );
		$assert( 1 === $unknown_save_count && false === strpos( $unknown->get_error_message(), 'Disposable' ), 'Unknown outcome is never retried or exposed as a raw exception.' );
	} finally {
		remove_action( 'woocommerce_after_order_object_save', $unknown_save_hook, 10 );
	}


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
