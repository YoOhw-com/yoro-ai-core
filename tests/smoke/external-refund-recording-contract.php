<?php
/** Disposable WooCommerce owner fixtures: external recording never reaches a payment gateway. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Order' ) ) { throw new RuntimeException( 'WooCommerce fixture required.' ); }
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
class YoRo_External_Refund_Fake_Gateway extends WC_Payment_Gateway {
	public static $calls = 0;
	public function __construct() { $this->id = 'yoro_external_refund_fixture'; $this->supports = []; }
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		++self::$calls; throw new RuntimeException( 'External recording must never reach process_refund.' );
	}
}
$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admin ), 'Fixture admin required.' );
$previous_user = get_current_user_id(); wp_set_current_user( (int) $admin[0] );
$gateway = new YoRo_External_Refund_Fake_Gateway();
$gateways = WC()->payment_gateways()->payment_gateways();
$registry = new ReflectionProperty( WC_Payment_Gateways::class, 'payment_gateways' );
$registry->setAccessible( true ); $registry->setValue( WC()->payment_gateways(), $gateways + [ $gateway->id => $gateway ] );
$http_count = 0; $restock_count = 0; $args_seen = []; $orders = []; $product = null;
$no_http = static function () use ( &$http_count ) { ++$http_count; throw new RuntimeException( 'No network in external-refund fixture.' ); };
$no_mail = static function () { return true; };
$restock = static function () use ( &$restock_count ) { ++$restock_count; };
$observe = static function ( $refund, $args ) use ( &$args_seen, $assert, &$orders ) {
	if ( ! in_array( $args['order_id'], $orders, true ) ) { throw new RuntimeException( 'Unexpected refund fixture target.' ); }
	$args_seen[] = $args;
	$assert( false === $args['refund_payment'] && false === $args['restock_items'] && [] === $args['line_items'], 'Exact owner local-only/no-restock/no-items arguments.' );
};
add_filter( 'pre_http_request', $no_http, 999 ); add_filter( 'pre_wp_mail', $no_mail, 999 );
add_action( 'woocommerce_restock_refunded_item', $restock ); add_action( 'woocommerce_create_refund', $observe, 10, 2 );
$make = static function ( $status = 'processing' ) use ( &$orders, $gateway, &$product ) {
	$order = wc_create_order(); $orders[] = $order->get_id();
	$order->set_currency( 'USD' ); $order->set_status( $status ); $order->set_payment_method( $gateway );
	$order->set_billing_email( 'external-fixture@example.test' ); $order->set_transaction_id( '' );
	$order->add_product( $product, 2, [ 'subtotal' => '10.00', 'total' => '10.00' ] );
	$order->set_total( '10.00' ); $order->save(); return $order->get_id();
};
$params_for = static function ( $id ) use ( $assert ) {
	$state = YoRo_Orders_Read_Service::external_refund_recording_state( $id );
	$assert( true === $state['guard']['eligible'], 'Fresh eligible external-record guard: ' . wp_json_encode( $state['guard'] ) );
	$assert( array_keys( $state['guard'] ) === [ 'order_id', 'eligible', 'blocked_reason', 'current_version', 'state_token', 'remaining_refundable' ], 'External guard has no provider, transaction or customer fields.' );
	return [ 'order_id' => $id, 'amount' => '1.25', 'expected_version' => $state['guard']['current_version'], 'expected_external_refund_state' => $state['guard']['state_token'], 'reason' => 'Externally completed; Human assertion' ];
};
$claim = static function ( $approved, $approved_action = 'orders.record_external_refund' ) {
	$used = false;
	return static function ( $action, $params ) use ( $approved, $approved_action, &$used ) {
		if ( $used || $approved_action !== $action || $params !== $approved ) { return false; }
		$used = true; return true;
	};
};
$execute = static function ( ...$args ) use ( &$product, $assert ) {
	$stock = wc_get_product( $product->get_id() )->get_stock_quantity();
	$result = YoRo_Action_Executor::execute( ...$args );
	$assert( $stock === wc_get_product( $product->get_id() )->get_stock_quantity(), 'External action may not alter inventory.' );
	return $result;
};
try {
	$action = YoRo_Action_Registry::get( 'orders.record_external_refund' );
	$assert( 'mutate' === $action['effect'] && 'high' === $action['risk_level'] && 'none' === $action['reversibility'] && false === $action['external_side_effect'] && true === $action['requires_confirmation'] && true === $action['write_action'] && in_array( 'financial', $action['capabilities'], true ), 'Local mutation metadata retains financial risk, confirmation and no external effect.' );
	$product = new WC_Product_Simple(); $product->set_name( 'External refund fixture product' );
	$product->set_status( 'draft' ); $product->set_regular_price( '5.00' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 10 ); $product->save();
	$id = $make(); $params = $params_for( $id );
	$assert( false === YoRo_Orders_Read_Service::refund_execution_state( $id )['guard']['eligible'], 'External recording remains available without gateway support or transaction.' );
	$unpaid_id = $make( 'pending' );
	$unpaid = YoRo_Orders_Read_Service::external_refund_recording_state( $unpaid_id )['guard'];
	$assert( false === $unpaid['eligible'] && null === $unpaid['state_token'] && 'order_not_paid' === $unpaid['blocked_reason'], 'Unpaid order cannot mint an external-record guard.' );
	$bare = $execute( 'orders.record_external_refund', $params, true );
	$assert( is_wp_error( $bare ) && 'external_refund_execution_permit_required' === $bare->get_error_code(), 'Bare confirmation cannot record a refund.' );
	$captured = $execute( 'orders.record_external_refund', $params, true, false, [ 'connector_remote_agent' => true, 'workflow_id' => 'captured', 'operation_id' => 'captured' ] );
	$assert( is_wp_error( $captured ), 'Captured runtime context must fail.' );
	$cross_claim = $execute( 'orders.record_external_refund', $params, true, false, [], $claim( $params, 'orders.refund' ) );
	$assert( is_wp_error( $cross_claim ), 'Gateway action claim cannot authorize external recording.' );
	$sequence = YoRo_Plan_Executor::execute( [ 'type' => 'sequence', 'steps' => [ [ 'action' => 'orders.record_external_refund', 'params' => $params, 'retry' => [ 'max_attempts' => 9 ] ] ] ], true, false, $claim( $params ) );
	$assert( is_wp_error( $sequence ) && 'external_refund_separate_confirmation_required' === $sequence->get_error_code(), 'External recording requires separate single confirmation.' );
	$malformed_paid = static function () { return 1; };
	add_filter( 'woocommerce_order_is_paid', $malformed_paid, 999 );
	try { $assert( false === YoRo_Orders_Read_Service::external_refund_recording_state( $id )['guard']['eligible'], 'Malformed owner paid state cannot mint a token.' ); }
	finally { remove_filter( 'woocommerce_order_is_paid', $malformed_paid, 999 ); }
	$captured_blog = get_current_blog_id(); $before_drift = count( $args_seen );
	$site_drift = static function ( $paid, $order ) use ( $id, $captured_blog ) {
		if ( $order->get_id() === $id ) { $GLOBALS['blog_id'] = $captured_blog + 1; }
		return $paid;
	};
	add_filter( 'woocommerce_order_is_paid', $site_drift, 999, 2 );
	try {
		$drift = YoRo_Action_Executor::execute( 'orders.record_external_refund', $params, true, false, [], $claim( $params ) );
		$assert( is_wp_error( $drift ) || empty( $drift['success'] ), 'Owner eligibility site drift must fail before local recording.' );
		$assert( count( $args_seen ) === $before_drift, 'Site drift cannot reach wc_create_refund.' );
	} finally { remove_filter( 'woocommerce_order_is_paid', $site_drift, 999 ); $GLOBALS['blog_id'] = $captured_blog; }
	$token_id = $make(); $token_order = wc_get_order( $token_id ); $token_order->set_transaction_id( 'fake-token-separation' ); $token_order->save();
	$gateway->supports = [ 'refunds' ];
	try {
		$external_params = $params_for( $token_id );
		$gateway_guard = YoRo_Orders_Read_Service::refund_execution_state( $token_id )['guard'];
		$assert( true === $gateway_guard['eligible'] && $gateway_guard['state_token'] !== $external_params['expected_external_refund_state'], 'Gateway and external guard domains remain distinct.' );
		$external_params['expected_external_refund_state'] = $gateway_guard['state_token'];
		$swapped = $execute( 'orders.record_external_refund', $external_params, true, false, [], $claim( $external_params ) );
		$assert( is_wp_error( $swapped ) || empty( $swapped['success'] ), 'Gateway guard cannot authorize external recording.' );
	} finally { $gateway->supports = []; }
	$other_id = $make();
	foreach ( [ [ 'order_ids' => [ $id ] ], [ 'currency' => 'EUR' ], [ 'restock_items' => true ], [ 'line_items' => [] ], [ 'gateway' => 'other' ], [ 'status' => 'refunded' ], [ 'refund_payment' => false ], [ 'provider_reference' => 'not-allowed' ], [ 'meta_data' => [] ], [ 'force' => true ], [ 'expected_external_refund_state' => str_repeat( 'a', 64 ) ], [ 'expected_refund_state' => str_repeat( 'a', 64 ) ], [ 'amount' => 1 ], [ 'amount' => 1.25 ], [ 'amount' => true ], [ 'amount' => '1e0' ], [ 'amount' => '0' ], [ 'amount' => '-1' ], [ 'amount' => '1.251' ], [ 'amount' => '11' ], [ 'reason' => null ], [ 'reason' => str_repeat( 'x', 501 ) ], [ 'reason' => '<b>x</b>' ], [ 'order_id' => (string) $id ], [ 'order_id' => $other_id ] ] as $delta ) {
		$bad = array_replace( $params, $delta );
		$result = $execute( 'orders.record_external_refund', $bad, true, false, [], $claim( $bad ) );
		$assert( is_wp_error( $result ) || empty( $result['success'] ), 'Invalid input must fail: ' . wp_json_encode( $delta ) );
	}
	$assert( [] === $args_seen && 0 === YoRo_External_Refund_Fake_Gateway::$calls, 'No preflight failure may create a record or call a gateway.' );
	$preview = YoRo_Orders_Write_Service::preview_record_external_refund( $params );
	$assert( ! is_wp_error( $preview ) && '8.75' === $preview['remaining_after_refund'] && false === $preview['payment_transfer_initiated'] && 'not_observed' === $preview['external_transfer_verification'], 'Preview truthfully discloses exact local recording without money or verification.' );
	$assert( [] === $args_seen, 'Preview creates no refund.' );
	$permit = $claim( $params );
	$result = $execute( 'orders.record_external_refund', $params, true, false, [], $permit );
	$assert( ! is_wp_error( $result ) && true === $result['success'] && 'recorded' === $result['external_refund']['status'], 'Confirmed external recording must succeed: ' . wp_json_encode( $result ) );
	$canonical = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, 'orders.record_external_refund' );
	$assert( true === $canonical['success'] && false === $canonical['data']['external_refund']['refunded_payment'] && false === $canonical['data']['external_refund']['payment_transfer_initiated'] && 'not_observed' === $canonical['data']['external_refund']['external_transfer_verification'], 'Canonical success never turns Human assertion into payment proof.' );
	$created = wc_get_order( $result['external_refund']['refund_id'] );
	$assert( $created instanceof WC_Order_Refund && [] === $created->get_items() && false === $created->get_refunded_payment(), 'Owner record is amount-only and not gateway-paid.' );
	$replay = $execute( 'orders.record_external_refund', $params, true, false, [], $permit );
	$assert( is_wp_error( $replay ) && 1 === count( $args_seen ), 'Consumed claim never creates a second record.' );
	$stale = $execute( 'orders.record_external_refund', $params, true, false, [], $claim( $params ) );
	$assert( empty( $stale['success'] ) && 1 === count( $args_seen ), 'Stale guard cannot record again.' );
	$params = $params_for( $id ); $params['amount'] = '8.75';
	$result = $execute( 'orders.record_external_refund', $params, true, false, [], $claim( $params ) );
	$assert( true === $result['success'] && 'refunded' === wc_get_order( $id )->get_status(), 'Exact remaining amount records fully with the owner status consequence.' );
	$assert( false === YoRo_Orders_Read_Service::external_refund_recording_state( $id )['guard']['eligible'], 'Zero remaining balance cannot mint a token.' );
	foreach ( [ 'throw', 'postcondition' ] as $mode ) {
		$unknown_id = $make(); $unknown_params = $params_for( $unknown_id ); $unknown_permit = $claim( $unknown_params );
		$hook = static function ( $refund_id ) use ( $unknown_id, $mode ) {
			$refund = wc_get_order( $refund_id );
			if ( $refund->get_parent_id() !== $unknown_id ) { return; }
			if ( 'throw' === $mode ) { throw new RuntimeException( 'Injected local failure after owner save.' ); }
			$refund->set_currency( 'EUR' ); $refund->save();
		};
		add_action( 'woocommerce_refund_created', $hook );
		try {
			$before = count( $args_seen );
			$result = $execute( 'orders.record_external_refund', $unknown_params, true, false, [], $unknown_permit );
			$assert( false === $result['success'] && true === $result['external_refund']['outcome_unknown'], 'Unknown local outcome never succeeds.' );
			$canonical = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, 'orders.record_external_refund' );
			$assert( false === $canonical['success'] && true === $canonical['data']['external_refund']['outcome_unknown'] && 'not_observed' === $canonical['data']['external_refund']['external_transfer_verification'], 'Unknown result retains local reconciliation and no external verification.' );
			$execute( 'orders.record_external_refund', $unknown_params, true, false, [], $unknown_permit );
			$assert( $before + 1 === count( $args_seen ), 'Unknown local outcome cannot retry the consumed claim.' );
		} finally { remove_action( 'woocommerce_refund_created', $hook ); }
	}
	$assert( 0 === $http_count && 0 === $restock_count && 0 === YoRo_External_Refund_Fake_Gateway::$calls, 'Zero HTTP, gateway calls, restock or inventory changes.' );
	echo "external-refund-recording-contract PASS (exact owner arguments; unsupported gateway; zero payment calls; one-use claim; no stock/items; local unknown outcomes)\n";
} finally {
	remove_filter( 'pre_http_request', $no_http, 999 ); remove_filter( 'pre_wp_mail', $no_mail, 999 );
	remove_action( 'woocommerce_restock_refunded_item', $restock ); remove_action( 'woocommerce_create_refund', $observe, 10 );
	foreach ( $orders as $id ) { $order = wc_get_order( $id ); if ( $order ) { foreach ( $order->get_refunds() as $refund ) { $refund->delete( true ); } $order->delete( true ); } }
	if ( $product ) { $product->delete( true ); }
	$registry->setValue( WC()->payment_gateways(), $gateways ); wp_set_current_user( $previous_user );
}
