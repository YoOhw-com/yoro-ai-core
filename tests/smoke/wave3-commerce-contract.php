<?php
/**
 * YoRo wave-three commerce intelligence and return request smoke contract.
 *
 * Run through WordPress with wp eval-file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( ! class_exists( 'YoRo_Order_Return_Service' ) ) {
	YoRo_AI_Core::init();
}

$failures = [];
$assert = static function( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$product_id = 0;
$order_id   = 0;
$original_manage_stock = get_option( 'woocommerce_manage_stock', 'yes' );

try {
	update_option( 'woocommerce_manage_stock', 'yes' );
	$product = new WC_Product_Simple();
	$product->set_name( 'YoRo wave three return product' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '40' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 0 );
	$product->set_stock_status( 'outofstock' );
	$product_id = $product->save();

	$order = wc_create_order();
	$order_item_id = $order->add_product( $product, 2 );
	$order->set_status( 'processing' );
	$order->set_billing_email( 'wave3-customer@example.test' );
	$order->calculate_totals();
	$order->save();
	$order_id = $order->get_id();
	$params = [
		'order_id'       => $order_id,
		'type'           => 'exchange',
		'line_items'     => [
			[ 'order_item_id' => $order_item_id, 'quantity' => 1 ],
		],
		'reason'         => '包装在运输途中损坏',
		'resolution'     => 'replacement',
		'idempotency_key'=> 'wave3-return-' . $order_id,
	];
	$preview = YoRo_Order_Return_Service::preview_create_return_request( $params );
	$created = YoRo_Order_Return_Service::create_return_request( $params );
	$replay  = YoRo_Order_Return_Service::create_return_request( $params );
	$order   = wc_get_order( $order_id );
	$records = (array) $order->get_meta( YoRo_Order_Return_Service::REQUESTS_META_KEY, true );

	$assert( false === ( $preview['will_execute'] ?? true ), 'Return preview must never execute directly.' );
	$assert( true === ( $preview['requires_confirmation'] ?? false ), 'Return request must require confirmation.' );
	$assert( false === ( $preview['financial_effect'] ?? true ), 'Return request preview must disclose no financial effect.' );
	$assert( ! is_wp_error( $created ) && ! empty( $created['success'] ), 'Return request must be recorded.' );
	$assert( true === ( $replay['idempotent_replay'] ?? false ), 'Exact return retry must be idempotent.' );
	$assert( 1 === count( $records ), 'Exact return retry must not add a duplicate record.' );
	$assert( ! array_key_exists( 'idempotency_key', $records[0] ?? [] ), 'Plaintext idempotency key must never be stored.' );
	$assert( 64 === strlen( (string) ( $records[0]['idempotency_hash'] ?? '' ) ), 'Only a cryptographic idempotency hash may be stored.' );
	$assert( '包装在运输途中损坏' === ( $records[0]['reason'] ?? '' ), 'Unicode user-literal reason must be preserved.' );

	$return_report = YoRo_Commerce_Intelligence_Service::get_returns_intelligence( [
		'time_window' => [ 'canonical_key' => 'today' ],
	] );
	$customer_report = YoRo_Commerce_Intelligence_Service::get_customer_lifecycle( [
		'time_window' => [ 'canonical_key' => 'today' ],
	] );
	$inventory_report = YoRo_Commerce_Intelligence_Service::get_inventory_risk( [ 'limit' => 20 ] );
	$return_ids = array_column( is_array( $return_report ) ? ( $return_report['requests'] ?? [] ) : [], 'request_id' );
	$risk_ids   = array_map( 'absint', array_column( is_array( $inventory_report ) ? ( $inventory_report['risks'] ?? [] ) : [], 'product_id' ) );

	$assert( ! is_wp_error( $return_report ) && in_array( $created['request_id'], $return_ids, true ), 'Returns intelligence must include the newly recorded request.' );
	$assert( ! is_wp_error( $customer_report ) && (int) ( $customer_report['metrics']['customer_identities'] ?? 0 ) >= 1, 'Customer lifecycle must use canonical time-window order data.' );
	$assert( false === ( $customer_report['classification_contract']['new_customer_claimed'] ?? true ), 'Customer lifecycle must not overclaim first-ever customer state.' );
	$assert( ! is_wp_error( $inventory_report ) && in_array( $product_id, $risk_ids, true ), 'Inventory risk must include the live out-of-stock product.' );
	$assert( false === ( $inventory_report['source']['forecasted'] ?? true ), 'Inventory risk must label threshold evidence as non-forecasted.' );

	$invalid_quantity = YoRo_Order_Return_Service::create_return_request( array_replace( $params, [
		'line_items' => [ [ 'order_item_id' => $order_item_id, 'quantity' => 3 ] ],
		'idempotency_key' => 'wave3-invalid-' . $order_id,
	] ) );
	$assert( is_wp_error( $invalid_quantity ) && 'return_quantity_exceeded' === $invalid_quantity->get_error_code(), 'Return quantity above the live ordered quantity must fail closed.' );
} finally {
	update_option( 'woocommerce_manage_stock', $original_manage_stock );

	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$order->delete( true );
		}
	}

	if ( $product_id > 0 ) {
		$product = wc_get_product( $product_id );

		if ( $product ) {
			$product->delete( true );
		}
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo wave-three commerce contract passed.\n";
