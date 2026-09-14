<?php
/** Public API doubles in an isolated Woo fixture; not proof of commercial 9.2.0 internals. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Order' ) || class_exists( 'WC_Subscriptions', false ) || class_exists( 'WC_Subscription', false ) ) { throw new RuntimeException( 'Isolated Woo fixture required.' ); }
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$original_user = get_current_user_id();
$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admin ), 'Admin fixture required.' );
wp_set_current_user( (int) $admin[0] );
$orders = []; $guarded = false; $writes = 0; $http = 0; $mail = 0;
$query = static function ( $sql ) use ( &$guarded, &$writes ) { if ( $guarded && ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql ) ) { ++$writes; throw new RuntimeException( 'Unexpected SQL write.' ); } return $sql; };
$http_guard = static function () use ( &$http ) { ++$http; return new WP_Error( 'no_http', 'Unexpected remote call.' ); };
$mail_guard = static function () use ( &$mail ) { ++$mail; return true; };
try {
	$order = wc_create_order(); $orders[] = $order; $order->set_status( 'processing' ); $order->set_billing_email( 'SECRET@example.test' ); $order->save();
	$refund = new WC_Order_Refund(); $refund->set_parent_id( $order->get_id() ); $refund->save(); $orders[] = $refund;
	$read = static fn() => YoRo_Subscriptions_Service::get_order_context( [ 'order_id' => $order->get_id() ] );
	add_filter( 'query', $query ); add_filter( 'pre_http_request', $http_guard ); add_filter( 'pre_wp_mail', $mail_guard ); $guarded = true;
	$assert( 'absent' === YoRo_Subscriptions_Service::get_status( [] )['status'] && 'unavailable' === $read()['status'], 'Absent owner.' );
	if ( ! class_exists( 'WC_Subscriptions', false ) ) { class WC_Subscriptions { public static $version = '9.2.0'; public function __construct() { throw new RuntimeException( 'No manager construction.' ); } } }
	$assert( 'incompatible' === YoRo_Subscriptions_Service::get_status( [] )['status'], 'Missing public shape.' );
	if ( ! class_exists( 'WC_Subscription', false ) ) {
		class WC_Subscription extends WC_Order {
			public $facts = []; public static $relations = []; public static $objects = []; public static $calls = []; public static $on_read = null;
			public function get_type() { return 'shop_subscription'; }
			public function get_status( $context = 'view' ) { return $this->facts['status'] ?? 'active'; }
			public function get_billing_period( $context = 'view' ) { return $this->facts['period'] ?? 'month'; }
			public function get_billing_interval( $context = 'view' ) { return $this->facts['interval'] ?? '1'; }
			public function is_manual() { return $this->facts['manual'] ?? false; }
			public function get_date( $type, $timezone = 'gmt' ) { if ( 'gmt' !== $timezone ) { throw new RuntimeException( 'UTC required.' ); } return $this->facts['dates'][ $type ] ?? ( 'start' === $type ? '2026-09-01 00:00:00' : '' ); }
			public function get_meta( $key = '', $single = true, $context = 'view' ) { throw new RuntimeException( 'No generic metadata.' ); }
		}
	}
	if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
		function wcs_get_subscriptions_for_order( $id, $args = [] ) { WC_Subscription::$calls[] = [ $id, $args ]; if ( WC_Subscription::$on_read ) { call_user_func( WC_Subscription::$on_read ); } return WC_Subscription::$relations; }
		function wcs_get_subscription( $id ) { return WC_Subscription::$objects[ $id ] ?? false; }
	}
	$assert( 'available' === YoRo_Action_Executor::execute( 'subscriptions.get_status', [] )['status'], 'Registered owner status.' );
	$assert( 'observed' === $read()['status'] && [] === $read()['subscriptions'], 'Empty relation set.' );
	$make = static function ( $id ) { $s = new WC_Subscription(); $s->set_id( $id ); return $s; };
	$one = $make( 900001 ); WC_Subscription::$relations = [ 900001 => $one ]; WC_Subscription::$objects = WC_Subscription::$relations;
	$r = YoRo_Action_Executor::execute( 'subscriptions.get_order_context', [ 'order_id' => $order->get_id() ] );
	$wire = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $r, 'subscriptions.get_order_context' );
	$assert( 'observed' === $r['status'] && 'automatic' === $r['subscriptions'][0]['renewal_mode'] && 1 === $r['subscriptions'][0]['billing_interval'], 'Registered read projects facts.' );
	$assert( false === strpos( wp_json_encode( [ $r, $wire ] ), 'SECRET' ), 'Privacy projection.' );
	$assert( [ $order->get_id(), [ 'order_type' => 'any' ] ] === end( WC_Subscription::$calls ), 'Exact owner relation arguments.' );
	$assert( [ 'subscription_id', 'status', 'billing_period', 'billing_interval', 'renewal_mode', 'schedule' ] === array_keys( $r['subscriptions'][0] ), 'Closed fields.' );
	foreach ( [ [ 'status', 'unknown' ], [ 'period', 'hour' ], [ 'interval', 0 ], [ 'interval', 366 ], [ 'interval', true ], [ 'manual', 'gateway' ], [ 'dates', [ 'start' => '2026-02-30 00:00:00' ] ], [ 'dates', [ 'next_payment' => 'SECRET' ] ], [ 'dates', [ 'next_payment' => '2026-08-01 00:00:00' ] ] ] as [ $key, $value ] ) {
		$one->facts = [ $key => $value ]; $bad = $read(); $assert( 'unavailable' === $bad['status'] && [] === $bad['subscriptions'], 'Malformed facts fail closed: ' . $key );
	}
	$one->facts = [ 'manual' => true, 'dates' => [ 'trial_end' => '0', 'end' => 0 ] ]; $assert( 'manual' === $read()['subscriptions'][0]['renewal_mode'], 'Manual and absent dates.' ); $one->facts = [];
	foreach ( [ false, [ 900001 => new stdClass() ], [ 900002 => $one ], [ 900001 => $one, 900002 => $one ] ] as $bad ) { WC_Subscription::$relations = $bad; $assert( 'unavailable' === $read()['status'], 'Malformed/duplicate relation.' ); }
	WC_Subscription::$relations = [ 900001 => $one ]; WC_Subscription::$objects = [ 900001 => $make( 900002 ) ]; $assert( 'unavailable' === $read()['status'], 'Reload identity mismatch.' );
	WC_Subscription::$relations = []; for ( $i = 1; $i <= 20; ++$i ) { WC_Subscription::$relations[ 900000 + $i ] = $make( 900000 + $i ); } WC_Subscription::$objects = WC_Subscription::$relations;
	$assert( 20 === count( $read()['subscriptions'] ), 'Exact20 succeeds.' ); WC_Subscription::$relations[900021] = $make( 900021 ); $assert( 'unavailable' === $read()['status'], '21 fails closed.' );
	WC_Subscription::$relations = []; WC_Subscription::$on_read = static function () { WC_Subscriptions::$version = '9.3.0'; }; $assert( 'unavailable' === $read()['status'], 'Owner drift.' ); WC_Subscription::$on_read = null;
	foreach ( [ '9.3.0', '8.2.0', '', null ] as $version ) { WC_Subscriptions::$version = $version; $assert( 'incompatible' === YoRo_Subscriptions_Service::get_status( [] )['status'], 'Unsupported versions.' ); }
	WC_Subscriptions::$version = '9.2.0';
	$blog = get_current_blog_id(); WC_Subscription::$on_read = static function () use ( $blog ) { $GLOBALS['blog_id'] = $blog + 1; };
	try { $assert( 'unavailable' === $read()['status'], 'Cross-site drift fails closed.' ); } finally { $GLOBALS['blog_id'] = $blog; WC_Subscription::$on_read = null; }
	foreach ( [ [], [ 'order_id' => '1' ], [ 'order_id' => true ], [ 'order_id' => 0 ], [ 'order_id' => -1 ], [ 'order_id' => $order->get_id(), 'customer_id' => null ], [ 'order_id' => $order->get_id(), 'renew' => false ], [ 'order_id' => $order->get_id(), 'payment_token' => 'SECRET' ] ] as $params ) { $assert( is_wp_error( YoRo_Subscriptions_Service::get_order_context( $params ) ), 'Raw input rejected.' ); }
	$assert( is_wp_error( YoRo_Subscriptions_Service::get_order_context( [ 'order_id' => $refund->get_id() ] ) ), 'Refund rejected.' );
	$assert( is_wp_error( YoRo_Subscriptions_Service::get_status( [ 'license' => null ] ) ), 'Status closed.' );
	wp_set_current_user( 0 ); $assert( is_wp_error( $read() ) && is_wp_error( YoRo_Subscriptions_Service::get_status( [] ) ), 'Permission gate.' );
	$assert( 0 === $writes && 0 === $http && 0 === $mail, 'Zero writes/remote/mail.' );
	echo "Subscriptions public API-double adapter PASS; commercial 9.2.0 integration not claimed.\n";
} finally {
	$guarded = false; remove_filter( 'query', $query ); remove_filter( 'pre_http_request', $http_guard ); remove_filter( 'pre_wp_mail', $mail_guard );
	foreach ( array_reverse( $orders ) as $fixture ) { $fixture->delete( true ); }
	wp_set_current_user( $original_user );
}
