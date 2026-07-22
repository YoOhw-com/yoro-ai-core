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
	$stock_before_lifecycle = (int) wc_get_product( $product_id )->get_stock_quantity();

	$lifecycle_params = [
		'order_id'        => $order_id,
		'request_id'      => $created['request_id'],
		'status'          => 'approved',
		'note'            => 'تمت المراجعة بواسطة فريق الدعم',
		'idempotency_key' => 'wave3-approve-' . $order_id,
	];
	$lifecycle_preview = YoRo_Order_Return_Service::preview_update_return_request_status( $lifecycle_params );
	$approved = YoRo_Order_Return_Service::update_return_request_status( $lifecycle_params );
	$approved_replay = YoRo_Order_Return_Service::update_return_request_status( $lifecycle_params );
	$lifecycle_conflict = YoRo_Order_Return_Service::update_return_request_status( array_replace( $lifecycle_params, [ 'status' => 'completed' ] ) );
	$completed = YoRo_Order_Return_Service::update_return_request_status( array_replace( $lifecycle_params, [
		'status'          => 'completed',
		'idempotency_key' => 'wave3-complete-' . $order_id,
	] ) );
	$terminal_transition = YoRo_Order_Return_Service::update_return_request_status( array_replace( $lifecycle_params, [
		'status'          => 'cancelled',
		'idempotency_key' => 'wave3-cancel-completed-' . $order_id,
	] ) );
	$order = wc_get_order( $order_id );
	$records = (array) $order->get_meta( YoRo_Order_Return_Service::REQUESTS_META_KEY, true );

	$assert( false === ( $lifecycle_preview['will_execute'] ?? true ) && true === ( $lifecycle_preview['requires_confirmation'] ?? false ), 'Return lifecycle preview must require confirmation without execution.' );
	$assert( ! is_wp_error( $approved ) && 'approved' === ( $approved['status'] ?? '' ), 'Requested return must transition to approved.' );
	$assert( true === ( $approved_replay['idempotent_replay'] ?? false ), 'Lifecycle retry must replay without duplicating an event.' );
	$assert( is_wp_error( $lifecycle_conflict ) && 'return_lifecycle_idempotency_conflict' === $lifecycle_conflict->get_error_code(), 'Reusing a lifecycle idempotency key with a different decision must fail closed.' );
	$assert( ! is_wp_error( $completed ) && 'completed' === ( $completed['status'] ?? '' ), 'Approved return must transition to completed.' );
	$assert( is_wp_error( $terminal_transition ) && 'invalid_return_request_transition' === $terminal_transition->get_error_code(), 'Completed return lifecycle must reject further transitions.' );
	$assert( 'completed' === ( $records[0]['status'] ?? '' ) && 3 === count( (array) ( $records[0]['events'] ?? [] ) ), 'Return request must preserve a complete immutable lifecycle event history.' );
	$assert( 0.0 === (float) $order->get_total_refunded(), 'Return lifecycle updates must not create a refund.' );
	$assert( $stock_before_lifecycle === (int) wc_get_product( $product_id )->get_stock_quantity(), 'Return lifecycle updates must not change stock.' );

	$return_report = YoRo_Commerce_Intelligence_Service::get_returns_intelligence( [
		'time_window' => [ 'canonical_key' => 'today' ],
	] );
	$customer_report = YoRo_Commerce_Intelligence_Service::get_customer_lifecycle( [
		'time_window' => [ 'canonical_key' => 'today' ],
	] );
	$inventory_report = YoRo_Commerce_Intelligence_Service::get_inventory_risk( [ 'limit' => 20 ] );
	$return_ids = array_column( is_array( $return_report ) ? ( $return_report['requests'] ?? [] ) : [], 'request_id' );
	$reported_request = null;

	foreach ( (array) ( is_array( $return_report ) ? ( $return_report['requests'] ?? [] ) : [] ) as $candidate_request ) {
		if ( ( $candidate_request['request_id'] ?? '' ) === $created['request_id'] ) {
			$reported_request = $candidate_request;
			break;
		}
	}
	$risk_ids   = array_map( 'absint', array_column( is_array( $inventory_report ) ? ( $inventory_report['risks'] ?? [] ) : [], 'product_id' ) );

	$assert( ! is_wp_error( $return_report ) && in_array( $created['request_id'], $return_ids, true ), 'Returns intelligence must include the newly recorded request.' );
	$assert( ! is_wp_error( $customer_report ) && (int) ( $customer_report['metrics']['customer_identities'] ?? 0 ) >= 1, 'Customer lifecycle must use canonical time-window order data.' );
	$assert( false === ( $customer_report['classification_contract']['new_customer_claimed'] ?? true ), 'Customer lifecycle must not overclaim first-ever customer state.' );
	$assert( ! is_wp_error( $inventory_report ) && in_array( $product_id, $risk_ids, true ), 'Inventory risk must include the live out-of-stock product.' );
	$assert( false === ( $inventory_report['source']['forecasted'] ?? true ), 'Inventory risk must label threshold evidence as non-forecasted.' );
	$assert( 'bounded_history' === ( $return_report['coverage_contract']['coverage_mode'] ?? '' ), 'Returns reporting must declare bounded historical coverage.' );
	$assert( 'return_request_events' === ( $return_report['coverage_contract']['evidence_mode'] ?? '' ), 'Returns reporting must declare lifecycle events as evidence.' );
	$assert( 'completed' === ( $reported_request['status'] ?? '' ), 'Returns reporting must expose the current lifecycle status.' );
	$assert( 3 === (int) ( $reported_request['event_count'] ?? 0 ), 'Returns reporting must expose lifecycle event coverage for the request.' );
	$assert( 3 <= (int) ( $return_report['metrics']['event_count'] ?? 0 ), 'Returns reporting must count lifecycle events.' );
	$assert( 'bounded_history' === ( $customer_report['coverage_contract']['coverage_mode'] ?? '' ), 'Customer reporting must declare bounded historical coverage.' );
	$assert( 'live_snapshot' === ( $inventory_report['coverage_contract']['coverage_mode'] ?? '' ), 'Inventory reporting must declare live snapshot coverage.' );

	$historical_inventory = YoRo_Commerce_Intelligence_Service::get_inventory_risk( [
		'time_window' => [
			'canonical_key' => 'last_30_days',
			'source_text'   => '過去30日間',
		],
	] );
	$assert( is_wp_error( $historical_inventory ) && 'historical_inventory_risk_unsupported' === $historical_inventory->get_error_code(), 'Live inventory provider must reject an explicit historical period.' );

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
