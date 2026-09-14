<?php
/** Disposable WooCommerce owner execution with an isolated fake gateway; never calls a provider. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Order' ) ) { throw new RuntimeException( 'WooCommerce fixture required.' ); }
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
class YoRo_Refund_Contract_Fake_Gateway extends WC_Payment_Gateway {
	public static $calls = [];
	public static $mode = 'success';
	public function __construct() { $this->id = 'yoro_refund_fixture'; $this->supports = [ 'refunds' ]; }
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		self::$calls[] = [ $order_id, $amount, $reason ];
		if ( 'throw' === self::$mode ) { throw new RuntimeException( 'Injected ambiguous provider timeout; no provider contacted.' ); }
		if ( 'error' === self::$mode ) { return new WP_Error( 'injected_gateway_error', 'Injected uncertain outcome.' ); }
		return true;
	}
}
$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admin ), 'Fixture admin required.' );
$previous_user = get_current_user_id(); wp_set_current_user( (int) $admin[0] );
$gateway = new YoRo_Refund_Contract_Fake_Gateway();
$gateways = WC()->payment_gateways()->payment_gateways();
// WooCommerce resolves gateways from its initialized owner registry.
$reflection = new ReflectionProperty( WC_Payment_Gateways::class, 'payment_gateways' );
$reflection->setAccessible( true ); $reflection->setValue( WC()->payment_gateways(), $gateways + [ $gateway->id => $gateway ] );
$http_count = 0; $mail_count = 0; $restock_count = 0; $args_seen = [];
$no_http = static function () use ( &$http_count ) { ++$http_count; throw new RuntimeException( 'No HTTP is allowed in refund fixture.' ); };
$no_mail = static function () use ( &$mail_count ) { ++$mail_count; return true; };
$restock = static function () use ( &$restock_count ) { ++$restock_count; };
$observe = static function ( $refund, $args ) use ( &$args_seen, $assert ) {
	if ( 'yoro_refund_fixture' !== wc_get_order( $args['order_id'] )->get_payment_method() ) { throw new RuntimeException( 'Unexpected fixture gateway.' ); }
	$args_seen[] = $args;
	$assert( true === $args['refund_payment'] && false === $args['restock_items'] && [] === $args['line_items'], 'Exact owner payment/no-restock/no-items arguments.' );
};
add_filter( 'pre_http_request', $no_http, 999 ); add_filter( 'pre_wp_mail', $no_mail, 999 );
add_action( 'woocommerce_restock_refunded_item', $restock ); add_action( 'woocommerce_create_refund', $observe, 10, 2 );
$orders = [];
$make = static function () use ( &$orders, $gateway ) {
	$order = wc_create_order(); $order->set_currency( 'USD' ); $order->set_status( 'processing' );
	$order->set_payment_method( $gateway ); $order->set_transaction_id( 'fixture-opaque-transaction' );
	$order->set_total( '10.00' ); $order->save(); $orders[] = $order->get_id(); return $order->get_id();
};
$params_for = static function ( $id ) use ( $assert ) {
	$state = YoRo_Orders_Read_Service::refund_execution_state( $id );
	$assert( true === $state['guard']['eligible'], 'Fresh eligible owner guard: ' . wp_json_encode( $state['guard'] ) );
	$assert( false === strpos( wp_json_encode( $state['guard'] ), 'fixture-opaque' ), 'Guard cannot expose transaction.' );
	return [ 'order_id' => $id, 'amount' => '1.25', 'expected_version' => $state['guard']['current_version'], 'expected_refund_state' => $state['guard']['state_token'], 'reason' => 'Fixture refund' ];
};
$claim = static function ( $approved ) {
	$used = false;
	return static function ( $action, $params ) use ( $approved, &$used ) {
		if ( $used || 'orders.refund' !== $action || $params !== $approved ) { return false; }
		$used = true; return true;
	};
};
try {
	$id = $make(); $params = $params_for( $id );
	$bare = YoRo_Action_Executor::execute( 'orders.refund', $params, true );
	$assert( is_wp_error( $bare ) && 'refund_execution_permit_required' === $bare->get_error_code(), 'Bare confirmation must fail.' );
	$captured = YoRo_Action_Executor::execute( 'orders.refund', $params, true, false, [ 'connector_remote_agent' => true, 'workflow_id' => 'captured', 'operation_id' => 'captured' ] );
	$assert( is_wp_error( $captured ), 'Captured remote context must fail.' );
	$sequence = YoRo_Plan_Executor::execute( [ 'type' => 'sequence', 'steps' => [ [ 'action' => 'orders.refund', 'params' => $params, 'retry' => [ 'max_attempts' => 9 ] ] ] ], true, false, $claim( $params ) );
	$assert( is_wp_error( $sequence ) && 'refund_separate_confirmation_required' === $sequence->get_error_code(), 'Refund requires single confirmation.' );
	foreach ( [ [ 'order_ids' => [ $id ] ], [ 'currency' => 'EUR' ], [ 'restock_items' => true ], [ 'line_items' => [] ], [ 'gateway' => 'other' ], [ 'status' => 'refunded' ], [ 'refund_payment' => false ], [ 'expected_refund_state' => str_repeat( 'a', 64 ) ], [ 'amount' => 1 ], [ 'amount' => true ], [ 'amount' => 1.25 ], [ 'amount' => '1e0' ], [ 'amount' => '0' ], [ 'amount' => '-1' ], [ 'amount' => '1.251' ], [ 'amount' => '11' ], [ 'reason' => null ], [ 'reason' => str_repeat( 'x', 501 ) ], [ 'reason' => '<b>x</b>' ], [ 'order_id' => (string) $id ] ] as $delta ) {
		$bad = array_replace( $params, $delta );
		$result = YoRo_Action_Executor::execute( 'orders.refund', $bad, true, false, [], $claim( $bad ) );
		$assert( is_wp_error( $result ) || empty( $result['success'] ), 'Invalid input must fail: ' . wp_json_encode( $delta ) );
	}
	$assert( [] === YoRo_Refund_Contract_Fake_Gateway::$calls, 'No preflight failure may reach gateway.' );
	$gateway->supports = [];
	$unsupported = YoRo_Orders_Read_Service::refund_execution_state( $id )['guard'];
	$assert( false === $unsupported['eligible'] && null === $unsupported['state_token'], 'Unsupported gateway never mints execution authority.' );
	$gateway->supports = [ 'refunds' ];
	$mismatch = $params; $mismatch['amount'] = '2.00';
	$assert( is_wp_error( YoRo_Action_Executor::execute( 'orders.refund', $mismatch, true, false, [], $claim( $params ) ) ), 'Claim must bind exact amount and target.' );

	$preview = YoRo_Orders_Write_Service::preview_refund( $params );
	$assert( ! is_wp_error( $preview ) && '8.75' === $preview['remaining_after_refund'] && false === $preview['restock_items'], 'Exact preview amount and no-restock disclosure.' );
	$permit = $claim( $params );
	$result = YoRo_Action_Executor::execute( 'orders.refund', $params, true, false, [], $permit );
	$assert( ! is_wp_error( $result ) && true === $result['success'] && 'refunded' === $result['refund']['status'], 'Confirmed fake gateway refund must succeed: ' . wp_json_encode( $result ) );
	$canonical = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, 'orders.refund' );
	$assert( true === $canonical['success'] && true === $canonical['data']['refund']['refunded_payment'] && false === $canonical['data']['refund']['retry_allowed'], 'Truthful canonical successful refund.' );
	$assert( 1 === count( YoRo_Refund_Contract_Fake_Gateway::$calls ) && 1 === count( $args_seen ), 'Exactly one fake transfer.' );
	$replay = YoRo_Action_Executor::execute( 'orders.refund', $params, true, false, [], $permit );
	$assert( is_wp_error( $replay ) && 1 === count( YoRo_Refund_Contract_Fake_Gateway::$calls ), 'Consumed claim never transfers again.' );
	$stale = YoRo_Action_Executor::execute( 'orders.refund', $params, true, false, [], $claim( $params ) );
	$assert( empty( $stale['success'] ) && 1 === count( YoRo_Refund_Contract_Fake_Gateway::$calls ), 'Stale refund guard never transfers.' );
	$params = $params_for( $id ); $params['amount'] = '8.75';
	$result = YoRo_Action_Executor::execute( 'orders.refund', $params, true, false, [], $claim( $params ) );
	$assert( true === $result['success'], 'Exact remaining amount may refund fully.' );
	$assert( 'refunded' === wc_get_order( $id )->get_status(), 'Owner full-refund status effect is retained.' );
	$assert( false === YoRo_Orders_Read_Service::refund_execution_state( $id )['guard']['eligible'], 'No remaining balance cannot mint another guard.' );
	foreach ( [ 'throw', 'error' ] as $mode ) {
		YoRo_Refund_Contract_Fake_Gateway::$mode = $mode;
		$unknown_id = $make(); $unknown_params = $params_for( $unknown_id ); $unknown_permit = $claim( $unknown_params );
		$before = count( YoRo_Refund_Contract_Fake_Gateway::$calls );
		$result = YoRo_Action_Executor::execute( 'orders.refund', $unknown_params, true, false, [], $unknown_permit );
		$assert( false === $result['success'] && true === $result['refund']['outcome_unknown'] && 'reconciliation_required' === $result['refund']['status'], 'Injected unknown outcome never succeeds.' );
		$canonical = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, 'orders.refund' );
		$assert( false === $canonical['success'] && true === $canonical['data']['refund']['outcome_unknown'], 'Unknown canonical result must retain reconciliation.' );
		YoRo_Action_Executor::execute( 'orders.refund', $unknown_params, true, false, [], $unknown_permit );
		$assert( $before + 1 === count( YoRo_Refund_Contract_Fake_Gateway::$calls ), 'Unknown outcome cannot replay consumed claim.' );
	}
	YoRo_Refund_Contract_Fake_Gateway::$mode = 'success';
	$post_id = $make(); $post_params = $params_for( $post_id ); $post_permit = $claim( $post_params );
	$post_hook = static function ( $refund_id ) use ( $post_id ) {
		$refund = wc_get_order( $refund_id );
		if ( $refund->get_parent_id() === $post_id ) { $refund->set_currency( 'EUR' ); $refund->save(); }
	};
	add_action( 'woocommerce_refund_created', $post_hook );
	try {
		$before = count( YoRo_Refund_Contract_Fake_Gateway::$calls );
		$post_result = YoRo_Action_Executor::execute( 'orders.refund', $post_params, true, false, [], $post_permit );
		$assert( false === $post_result['success'] && true === $post_result['refund']['outcome_unknown'], 'Unprovable postcondition after gateway success requires reconciliation.' );
		YoRo_Action_Executor::execute( 'orders.refund', $post_params, true, false, [], $post_permit );
		$assert( $before + 1 === count( YoRo_Refund_Contract_Fake_Gateway::$calls ), 'Postcondition failure cannot repeat a money effect.' );
	} finally { remove_action( 'woocommerce_refund_created', $post_hook ); }
	$assert( 0 === $http_count && 0 === $restock_count, 'Zero network and zero restock.' );
	echo "refund-execution-contract PASS (isolated fake gateway; zero network; exact owner API, guards, single-use claim, no retry/restock/items)\n";
} finally {
	remove_filter( 'pre_http_request', $no_http, 999 ); remove_filter( 'pre_wp_mail', $no_mail, 999 );
	remove_action( 'woocommerce_restock_refunded_item', $restock ); remove_action( 'woocommerce_create_refund', $observe, 10 );
	foreach ( $orders as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) { foreach ( $order->get_refunds() as $refund ) { $refund->delete( true ); } $order->delete( true ); }
	}
	$reflection->setValue( WC()->payment_gateways(), $gateways ); wp_set_current_user( $previous_user );
}
