<?php
/** Isolated owner identity/read-contract fixtures; never load plugin entrypoints. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Order' ) ) { throw new RuntimeException( 'WooCommerce fixture required.' ); }
if ( class_exists( 'WC_Order_Cancellation_Return', false ) || class_exists( 'YOWC_Advanced_Order_Manager', false ) ) { throw new RuntimeException( 'Active owners are not allowed in the fixture.' ); }
$mode = $args[0] ?? 'free';
$assert = static function ( $ok, $description ) { if ( ! $ok ) { throw new RuntimeException( $description ); } };
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admins ), 'An isolated admin is required.' );
$user = get_current_user_id(); wp_set_current_user( (int) $admins[0] );
$guarded = false; $writes = 0; $http = 0; $mail = 0; $orders = []; $options = [];
$query_guard = static function ( $sql ) use ( &$guarded, &$writes ) {
	if ( $guarded && ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql ) ) { ++$writes; throw new RuntimeException( 'Unexpected adapter SQL mutation.' ); }
	return $sql;
};
$http_guard = static function () use ( &$http ) { ++$http; return new WP_Error( 'no_http', 'Unexpected adapter HTTP.' ); };
$mail_guard = static function () use ( &$mail, &$guarded ) { if ( $guarded ) { ++$mail; } return true; };
add_filter( 'pre_wp_mail', $mail_guard );
$source = getenv( 'YORO_CANCELLATION_RETURN_OWNER_SOURCE' );
try {
	$order = wc_create_order(); $orders[] = $order;
	$order->set_status( 'processing' ); $order->set_billing_email( 'SECRET@example.test' );
	$order->update_meta_data( 'request_return_items', [ [ 'name' => 'SECRET item' ] ] );
	$order->update_meta_data( 'return_attachments', [ 'SECRET-file.pdf' ] ); $order->save();
	$refund = new WC_Order_Refund(); $refund->set_parent_id( $order->get_id() ); $refund->save(); $orders[] = $refund;
	$put = static function ( array $values, $status = 'processing' ) use ( $order, &$guarded ) {
		$guarded = false;
		foreach ( $values as $key => $value ) { null === $value ? $order->delete_meta_data( $key ) : $order->update_meta_data( $key, $value ); }
		$order->set_status( $status ); $order->save(); $guarded = true;
	};
	$read = static fn() => YoRo_Cancellation_Return_Service::get_order_case( [ 'order_id' => $order->get_id() ] );
	add_filter( 'query', $query_guard ); add_filter( 'pre_http_request', $http_guard ); $guarded = true;
	$assert( 'absent' === YoRo_Cancellation_Return_Service::get_status( [] )['status'] && 'unavailable' === $read()['status'], 'Absent owner never inferred from metadata.' );
	$premium = in_array( $mode, [ 'premium', 'unsupported-premium' ], true );
	if ( $premium ) {
		if ( ! class_exists( 'YOWC_Advanced_Order_Manager', false ) ) { class YOWC_Advanced_Order_Manager {} }
		define( 'YOWC_ADVANCED_ORDER_MANAGER', 'premium' === $mode ? '1.4.0' : '1.4.1' );
	} else {
		if ( ! class_exists( 'WC_Order_Cancellation_Return', false ) ) { class WC_Order_Cancellation_Return {} }
		$assert( 'incompatible' === YoRo_Cancellation_Return_Service::get_status( [] )['status'], 'Class without characterized version is incompatible.' );
		define( 'WC_ORDER_CANCELLATION_RETURN_VERSION', 'unsupported-free' === $mode ? '1.1.15' : '1.1.14' );
		$guarded = false;
		if ( $source ) {
			require_once $source . '/inc/cores/request-eligibility.php';
			foreach ( [ 'order_cancel_enable_cancellation' => 'yes', 'order_return_enable_return' => 'yes', 'order_cancel_order_status' => [ 'wc-processing' ], 'order_return_order_status' => [ 'wc-completed' ], 'order_cancel_available_time' => '', 'order_return_available_time' => '' ] as $key => $value ) { $options[ $key ] = get_option( $key, null ); update_option( $key, $value, false ); }
		} elseif ( ! class_exists( 'WC_Order_Cancellation_Return_Request_Eligibility', false ) ) {
			class WC_Order_Cancellation_Return_Request_Eligibility {
				public static $cancel = true;
				public static $return = true;
				public static $on_cancel = null;
				public static $ids = [];
				public static function can_request_cancellation( $order ) { self::$ids[] = $order->get_id(); if ( self::$on_cancel ) { call_user_func( self::$on_cancel ); } return self::$cancel; }
				public static function can_request_return( $order ) { self::$ids[] = $order->get_id(); return self::$return; }
			}
		}
		$guarded = true;
	}
	if ( str_starts_with( $mode, 'unsupported' ) ) {
		$assert( 'incompatible' === YoRo_Cancellation_Return_Service::get_status( [] )['status'] && 'unavailable' === $read()['status'], 'Unsupported versions fail closed.' );
	} else {
		$status = YoRo_Action_Executor::execute( 'cancellation_return.get_status', [] );
		$assert( $status['success'] && $status['read_only'] && 'available' === $status['status'] && ( $premium ? 'premium' : 'free' ) === $status['owner_kind'], 'Alternative owner works without the other.' );
		$empty = $read(); $assert( 'none' === $empty['cancellation']['state'] && 'observed' === $empty['status'], 'No case evidence is explicit.' );
		$put( [ 'request_cancellation_reason' => 'SECRET request', 'request_cancellation_approved' => 'SECRET approval' ] );
		$executed = YoRo_Action_Executor::execute( 'cancellation_return.get_order_case', [ 'order_id' => $order->get_id() ] );
		$assert( $executed['success'] && $executed['read_only'] && empty( $executed['requires_confirmation'] ) && 'approved' === $executed['cancellation']['state'], 'Approval evidence is read without confirmation or action.' );
		$wire = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $executed, 'cancellation_return.get_order_case' );
		$assert( [ 'order_id', 'status', 'owner_kind', 'version', 'order_status', 'cancellation', 'return', 'coverage' ] === array_keys( $wire['data'] ), 'Canonical payload closes all top-level case fields.' );
		$assert( false === strpos( wp_json_encode( [ $executed, $wire, $status ] ), 'SECRET' ), 'Raw contact, reason, approval, items and attachments are omitted.' );
		$assert( 'not_observed' === $executed['coverage']['premium_eligibility'], 'Premium eligibility stays unobserved.' );
		if ( $premium ) { $assert( 'not_observed' === $executed['return']['eligibility']['status'], 'No Free call or guessed Premium eligibility.' ); }
		$put( [ 'request_cancellation_approved' => null, 'order_cancellation_rejection_reason' => 'SECRET rejection' ] );
		$assert( 'rejected' === $read()['cancellation']['state'], 'Retained rejection is recognized.' );
		$put( [ 'order_cancellation_rejection_reason' => null, 'cancellation_reason' => 'SECRET completed' ], 'cancelled' );
		$assert( 'completed' === $read()['cancellation']['state'], 'Owner completion evidence is recognized.' );
		$put( [ 'cancellation_reason' => null, 'request_cancellation_approved' => 'SECRET approval', 'order_cancellation_rejection_reason' => 'SECRET conflict' ] );
		$assert( 'unavailable' === $read()['status'], 'Conflicting terminal evidence is unavailable.' );
		$put( [ 'request_cancellation_approved' => null, 'order_cancellation_rejection_reason' => null ] );
		foreach ( [ [ 'SECRET compound' ], str_repeat( 'x', 8193 ) ] as $bad ) { $put( [ 'request_cancellation_reason' => $bad ] ); $assert( 'unavailable' === $read()['status'], 'Compound/oversized evidence fails closed.' ); }
		$put( [ 'request_cancellation_reason' => 'SECRET one' ] );
		$guarded = false; $order->add_meta_data( 'request_cancellation_reason', 'SECRET two', false ); $order->save(); $guarded = true;
		$assert( 'unavailable' === $read()['status'], 'Duplicate owner evidence fails closed.' );
		$put( [ 'request_cancellation_reason' => null ] );
		foreach ( [ [], [ 'order_id' => null ], [ 'order_id' => true ], [ 'order_id' => '91' ], [ 'order_id' => 1.5 ], [ 'order_id' => -1 ], [ 'order_id' => 0 ], [ 'order_id' => $order->get_id(), 'site_id' => null ], [ 'order_id' => $order->get_id(), 'approve' => false ], [ 'order_id' => $refund->get_id() ], [ 'order_id' => PHP_INT_MAX ] ] as $input ) { $assert( is_wp_error( YoRo_Cancellation_Return_Service::get_order_case( $input ) ), 'Invalid/widened/non-order target is denied.' ); }
		$assert( is_wp_error( YoRo_Cancellation_Return_Service::get_status( [ 'license' => null ] ) ), 'Status input remains closed.' );
		if ( $premium ) {
			$put( [ 'request_return_reason' => 'SECRET return', 'request_return_date' => '2026-09-14 10:01:02', 'request_return_resolution' => 'SECRET resolution', 'request_return_scope' => 'specific' ] );
			$case = $read()['return'];
			$assert( str_starts_with( $case['requested_at'], '2026-09-14T10:01:02' ) && $case['resolution_present'] && $case['item_scope_present'], 'Normalized date and bounded presence only.' );
			foreach ( [ '2026-02-31 10:00:00', 'tomorrow', str_repeat( 'x', 81 ) ] as $bad ) { $put( [ 'request_return_date' => $bad ] ); $assert( 'unavailable' === $read()['status'], 'Invalid or unbounded dates are unavailable.' ); }
			$put( [ 'request_return_date' => null, 'request_return_scope' => 'SECRET' ] ); $assert( 'unavailable' === $read()['status'], 'Invalid item scope fails closed.' );
		} elseif ( ! $source ) {
			foreach ( [ 'disabled', 'already_requested', 'rejected', 'status_not_allowed', 'time_expired' ] as $code ) {
				WC_Order_Cancellation_Return_Request_Eligibility::$cancel = new WP_Error( 'wcocr_cancellation_' . $code, 'SECRET human error' );
				WC_Order_Cancellation_Return_Request_Eligibility::$return = new WP_Error( 'wcocr_return_' . $code, 'SECRET human error' );
				$r = $read(); $assert( 'wcocr_cancellation_' . $code === $r['cancellation']['eligibility']['code'] && false === strpos( wp_json_encode( $r ), 'SECRET' ), 'Only bounded owner code is projected.' );
			}
			foreach ( [ false, [], new WP_Error( 'SECRET_UNKNOWN', 'SECRET' ) ] as $bad ) { WC_Order_Cancellation_Return_Request_Eligibility::$cancel = $bad; $assert( 'unavailable' === $read()['status'], 'Unexpected eligibility output is unavailable.' ); }
			WC_Order_Cancellation_Return_Request_Eligibility::$cancel = true; WC_Order_Cancellation_Return_Request_Eligibility::$return = true;
			$blog = get_current_blog_id();
			WC_Order_Cancellation_Return_Request_Eligibility::$on_cancel = static function () use ( $blog ) { $GLOBALS['blog_id'] = $blog + 1; };
			try { $assert( 'unavailable' === $read()['status'], 'Changed site context fails closed before another owner read.' ); }
			finally { $GLOBALS['blog_id'] = $blog; WC_Order_Cancellation_Return_Request_Eligibility::$on_cancel = null; }
		} else {
			$r = $read(); $assert( $r['cancellation']['eligibility']['eligible'] && 'wcocr_return_status_not_allowed' === $r['return']['eligibility']['code'], 'Actual Free owner decisions are preserved.' );
		}
		wp_set_current_user( 0 ); $assert( is_wp_error( YoRo_Cancellation_Return_Service::get_status( [] ) ) && is_wp_error( $read() ), 'Unprivileged reads are denied.' ); wp_set_current_user( (int) $admins[0] );
		if ( $premium ) { if ( ! class_exists( 'WC_Order_Cancellation_Return', false ) ) { class WC_Order_Cancellation_Return {} } }
		else { if ( ! class_exists( 'YOWC_Advanced_Order_Manager', false ) ) { class YOWC_Advanced_Order_Manager {} } }
		$assert( 'incompatible' === YoRo_Cancellation_Return_Service::get_status( [] )['status'], 'Both alternative owners fail closed.' );
	}
	$assert( 0 === $writes && 0 === $http && 0 === $mail, 'No adapter SQL writes, HTTP or mail.' );
} finally {
	$guarded = false; remove_filter( 'query', $query_guard ); remove_filter( 'pre_http_request', $http_guard );
	foreach ( array_reverse( $orders ) as $fixture ) { $fixture->delete( true ); }
	foreach ( $options as $key => $value ) { null === $value ? delete_option( $key ) : update_option( $key, $value, false ); }
	remove_filter( 'pre_wp_mail', $mail_guard ); wp_set_current_user( $user );
}
if ( 'free' === $mode && ! $source && defined( 'WP_CLI' ) && WP_CLI ) {
	foreach ( [ 'premium', 'unsupported-free', 'unsupported-premium' ] as $child ) {
		$result = WP_CLI::runcommand( 'eval-file ' . escapeshellarg( __FILE__ ) . ' ' . $child, [ 'launch' => true, 'return' => 'all', 'exit_error' => false ] );
		$assert( 0 === $result->return_code, $child . ' failed: ' . $result->stderr ); echo $result->stdout;
	}
}
echo 'PASS Cancellation & Return ' . $mode . ': owner version, exact local order, eligibility delegation, bounded facts, privacy, no mutations, cleanup.' . "\n";
