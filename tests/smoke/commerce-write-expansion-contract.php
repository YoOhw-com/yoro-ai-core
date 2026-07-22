<?php
/**
 * YoRo fulfillment and refund write smoke contract.
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

if ( ! class_exists( 'YoRo_Order_Refund_Service' ) ) {
	YoRo_AI_Core::init();
}

if ( 0 === count( YoRo_Action_Registry::all() ) ) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

$failures = [];
$assert = static function( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;
$order_id = 0;

try {
	$assert( $admin_id > 0, 'An administrator is required for write-action smoke tests.' );

	if ( $admin_id > 0 ) {
		wp_set_current_user( $admin_id );
	}

	$order = wc_create_order();
	$order->set_status( 'processing' );
	$order->set_currency( get_woocommerce_currency() );
	$order->set_total( 100 );
	$order->set_payment_method( 'cod' );
	$order->save();
	$order_id = $order->get_id();

	$unknown_adapter = YoRo_Fulfillment_Write_Service::preview_add_tracking(
		[
			'order_id'        => $order_id,
			'tracking_number' => 'YORO-TRACK-001',
			'adapter_key'     => 'missing.adapter',
		]
	);
	$assert( ! empty( $unknown_adapter['blocked'] ), 'Unknown tracking adapters must block during preview.' );
	$assert( 'yoro_integration_adapter_unavailable' === ( $unknown_adapter['block_reason'] ?? '' ), 'Unknown tracking adapters must expose the adapter-unavailable reason.' );

	$tracking_params = [
		'order_id'        => $order_id,
		'tracking_number' => 'YORO-TRACK-001',
		'carrier'        => 'YoRo Carrier',
		'tracking_url'   => 'https://example.com/track/YORO-TRACK-001',
		'shipped_at'     => '2026-07-22',
	];
	$tracking_preview = YoRo_Fulfillment_Write_Service::preview_add_tracking( $tracking_params );
	$tracking_confirmation = YoRo_Action_Executor::execute( 'orders.add_tracking', $tracking_params, false, false );
	$tracking_result = YoRo_Action_Executor::execute( 'orders.add_tracking', $tracking_params, true, false );
	$order = wc_get_order( $order_id );
	$presented = YoRo_Order_Presenter::format_order_details( $order );

	$assert( empty( $tracking_preview['blocked'] ), 'Valid core tracking preview must be executable after approval.' );
	$assert( ! empty( $tracking_confirmation['requires_confirmation'] ), 'Tracking write must require confirmation.' );
	$assert( ! empty( $tracking_result['success'] ), 'Confirmed tracking write must succeed.' );
	$assert( 'YORO-TRACK-001' === $order->get_meta( '_yoro_tracking_number', true ), 'Tracking write must persist only the namespaced YoRo tracking record.' );
	$assert( ! empty( $presented['tracking_detected'] ), 'Order presenter must detect the namespaced tracking record.' );

	$duplicate_tracking = YoRo_Fulfillment_Write_Service::preview_add_tracking( $tracking_params );
	$assert( 'tracking_already_exists' === ( $duplicate_tracking['block_reason'] ?? '' ), 'Duplicate tracking must block before execution.' );

	$missing_payment_choice = YoRo_Order_Refund_Service::preview_create_refund(
		[
			'order_id' => $order_id,
			'amount'   => 25,
		]
	);
	$excess_refund = YoRo_Order_Refund_Service::preview_create_refund(
		[
			'order_id'       => $order_id,
			'amount'         => 125,
			'refund_payment' => false,
		]
	);
	$automatic_refund_without_transaction = YoRo_Order_Refund_Service::preview_create_refund(
		[
			'order_id'       => $order_id,
			'amount'         => 25,
			'refund_payment' => true,
		]
	);
	$refund_params = [
		'order_id'       => $order_id,
		'amount'         => 25,
		'reason'         => 'YoRo smoke manual refund',
		'refund_payment' => false,
	];
	$refund_preview = YoRo_Order_Refund_Service::preview_create_refund( $refund_params );
	$refund_runtime = YoRo_Runtime_Guard::prepare(
		[
			'type'   => 'single',
			'action' => 'orders.create_refund',
			'params' => $refund_params,
		],
		[ 'source' => 'smoke', 'preview' => false, 'confirm' => false ]
	);
	$refund_result = YoRo_Action_Executor::execute( 'orders.create_refund', $refund_params, true, false );
	$order = wc_get_order( $order_id );

	$assert( 'refund_payment_choice_required' === ( $missing_payment_choice['block_reason'] ?? '' ), 'Refund preview must require an explicit gateway-payment choice.' );
	$assert( 'refund_amount_exceeds_remaining' === ( $excess_refund['block_reason'] ?? '' ), 'Refund preview must block amounts above the remaining refundable total.' );
	$assert( 'refund_transaction_missing' === ( $automatic_refund_without_transaction['block_reason'] ?? '' ), 'Automatic refunds must fail closed when the order has no transaction ID.' );
	$assert( empty( $refund_preview['blocked'] ), 'Valid manual refund preview must be executable after approval.' );
	$assert( false === (bool) ( $refund_preview['refund_payment'] ?? true ), 'Manual refund preview must preserve refund_payment=false.' );
	$assert( ! is_wp_error( $refund_runtime ) && 'high' === ( $refund_runtime['risk']['risk_level'] ?? '' ), 'Refund runtime must classify execution as high risk.' );
	$assert( ! empty( $refund_runtime['requires_confirmation'] ), 'Refund runtime must require confirmation.' );
	$assert( ! empty( $refund_result['success'] ), 'Confirmed manual refund must succeed.' );
	$assert( ! empty( $refund_result['refund_id'] ), 'Confirmed refund must return the WooCommerce refund ID.' );
	$assert( 25.0 === (float) $order->get_total_refunded(), 'Confirmed manual refund must update the order refunded total.' );
} finally {
	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$order->delete( true );
		}
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo commerce write expansion smoke contract passed.\n";
