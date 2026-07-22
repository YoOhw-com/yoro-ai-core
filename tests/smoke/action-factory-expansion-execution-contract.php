<?php
/**
 * YoRo action-factory commerce execution smoke contract.
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

if ( ! class_exists( 'YoRo_Fulfillment_Lifecycle_Service' ) ) {
	YoRo_AI_Core::init();
}

if ( 0 === count( YoRo_Action_Registry::all() ) ) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

$failures = [];
$assert   = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$admin_id  = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;
$customer_id = 0;
$order_id    = 0;
$coupon_id   = 0;

try {
	$assert( $admin_id > 0, 'An administrator is required for expansion execution smoke tests.' );

	if ( $admin_id > 0 ) {
		wp_set_current_user( $admin_id );
	}

	$stamp       = strtolower( wp_generate_password( 10, false, false ) );
	$customer_id = wp_insert_user(
		[
			'user_login'   => 'yoro-wave4-' . $stamp,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'user_email'   => 'yoro-wave4-' . $stamp . '@example.com',
			'display_name' => 'عميل đa ngôn ngữ 客户',
			'role'         => 'customer',
		]
	);

	$assert( ! is_wp_error( $customer_id ) && absint( $customer_id ) > 0, 'A customer fixture must be created.' );
	$customer_id = is_wp_error( $customer_id ) ? 0 : absint( $customer_id );

	$order = wc_create_order( [ 'customer_id' => $customer_id ] );
	$assert( $order instanceof WC_Order, 'An order fixture must be created.' );

	if ( $order instanceof WC_Order ) {
		$order->set_status( 'processing' );
		$order->set_billing_email( 'yoro-wave4-' . $stamp . '@example.com' );
		$order->set_payment_method( 'cod' );
		$order->set_total( 125 );
		$order_id = $order->save();
	}

	$fulfillment_params = [
		'order_id' => $order_id,
		'status'   => 'packed',
	];
	$fulfillment_confirmation = YoRo_Action_Executor::execute( 'orders.update_fulfillment', $fulfillment_params, false, false );
	$fulfillment_result       = YoRo_Action_Executor::execute( 'orders.update_fulfillment', $fulfillment_params, true, false );
	$fulfillment_read         = YoRo_Action_Executor::execute( 'orders.get_fulfillment', [ 'order_id' => $order_id ], false, false );
	$order                     = wc_get_order( $order_id );

	$assert( ! empty( $fulfillment_confirmation['requires_confirmation'] ), 'Fulfillment lifecycle updates must require confirmation.' );
	$assert( ! empty( $fulfillment_result['success'] ), 'Confirmed fulfillment lifecycle update must succeed.' );
	$assert( 'packed' === ( $fulfillment_read['fulfillment']['status'] ?? '' ), 'Fulfillment read must return the persisted lifecycle status.' );
	$assert( $order instanceof WC_Order && 'processing' === $order->get_status(), 'Fulfillment lifecycle updates must not change WooCommerce order status.' );
	$assert( '' === ( $fulfillment_read['fulfillment']['tracking_number'] ?? '' ), 'Fulfillment lifecycle updates must not invent tracking evidence.' );

	$task_params = [
		'customer_id'     => $customer_id,
		'instruction'     => 'اتصل لاحقًا — kiểm tra đơn hàng — 確認する',
		'due_at'          => gmdate( DATE_ATOM, time() + DAY_IN_SECONDS ),
		'idempotency_key' => 'wave4-' . $stamp,
	];
	$task_confirmation = YoRo_Action_Executor::execute( 'customers.create_followup_task', $task_params, false, false );
	$task_result       = YoRo_Action_Executor::execute( 'customers.create_followup_task', $task_params, true, false );
	$task_replay       = YoRo_Customer_Lifecycle_Action_Service::create_followup_task( $task_params );

	$assert( ! empty( $task_confirmation['requires_confirmation'] ), 'Customer follow-up tasks must require confirmation.' );
	$assert( ! empty( $task_result['success'] ), 'Confirmed customer follow-up task must succeed.' );
	$assert( true === ( $task_replay['idempotent'] ?? false ), 'Customer follow-up replay must return the existing task.' );
	$assert( 1 === count( (array) get_user_meta( $customer_id, YoRo_Customer_Lifecycle_Action_Service::FOLLOWUP_META, true ) ), 'Idempotency must prevent duplicate customer follow-up tasks.' );

	$segments = YoRo_Customer_Lifecycle_Action_Service::get_segments(
		[
			'window'         => 'last_30_days',
			'high_value_min' => 100,
		]
	);
	$assert( ! is_wp_error( $segments ) && ! empty( $segments['success'] ), 'Customer segmentation must execute against order evidence.' );
	$assert( false === ( $segments['classification_contract']['lifetime_claims'] ?? true ), 'Customer segmentation must not make lifetime claims.' );
	$assert( ! empty( $segments['segments']['high_value_in_window'] ), 'Window-scoped high-value segment must include the fixture customer.' );

	$coupon_code = 'YORO-WAVE4-' . strtoupper( $stamp );
	$coupon_params = [
		'coupon_code'          => $coupon_code,
		'discount_type'       => 'percent',
		'discount_amount'     => 12,
		'minimum_amount'      => 50,
		'maximum_amount'      => 500,
		'exclude_sale_items'  => true,
		'product_ids'         => [],
		'excluded_product_ids'=> [],
	];
	$invalid_coupon = YoRo_Coupons_Service::preview_create_coupon(
		array_replace(
			$coupon_params,
			[
				'minimum_amount' => 500,
				'maximum_amount' => 50,
			]
		)
	);
	$coupon_result = YoRo_Action_Executor::execute( 'coupons.create', $coupon_params, true, false );
	$coupon_id     = absint( $coupon_result['coupon_id'] ?? 0 );
	$promotions    = YoRo_Coupons_Service::get_promotions_summary( [ 'limit' => 100 ] );
	$coupon        = $coupon_id > 0 ? new WC_Coupon( $coupon_id ) : null;

	$assert( 'invalid_coupon_amount_range' === ( $invalid_coupon['block_reason'] ?? '' ), 'Promotion validation must reject maximum spend below minimum spend.' );
	$assert( ! empty( $coupon_result['success'] ) && $coupon_id > 0, 'Confirmed promotion creation must succeed.' );
	$assert( $coupon instanceof WC_Coupon && 50.0 === (float) $coupon->get_minimum_amount(), 'Promotion minimum spend must persist.' );
	$assert( $coupon instanceof WC_Coupon && 500.0 === (float) $coupon->get_maximum_amount(), 'Promotion maximum spend must persist.' );
	$assert( $coupon instanceof WC_Coupon && $coupon->get_exclude_sale_items(), 'Promotion sale-item exclusion must persist.' );
	$assert( ! is_wp_error( $promotions ) && ! empty( $promotions['success'] ), 'Promotion summary must execute as a read-only action.' );

	$finance = YoRo_Finance_Reconciliation_Service::get_summary( [ 'window' => 'last_30_days' ] );
	$assert( ! is_wp_error( $finance ) && ! empty( $finance['success'] ), 'Finance reconciliation must execute against the WooCommerce order ledger.' );
	$assert( 'woocommerce_order_ledger' === ( $finance['coverage_contract']['evidence_mode'] ?? '' ), 'Finance reconciliation must disclose order-ledger evidence.' );
	$assert( false === ( $finance['payouts']['available'] ?? true ), 'Payouts must remain unavailable without a registered payout adapter.' );
	$assert( (float) ( $finance['totals']['order_total'] ?? 0 ) >= 125.0, 'Finance reconciliation must include the fixture order total.' );
} finally {
	if ( $coupon_id > 0 ) {
		wp_delete_post( $coupon_id, true );
	}

	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
	}

	if ( $customer_id > 0 ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		wp_delete_user( $customer_id );
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo action factory expansion execution contract passed.\n";
