<?php
/** Disposable owner fixtures for exact Customer-360 identity and read-only behavior. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Order' ) ) { throw new RuntimeException( 'WooCommerce fixture required.' ); }
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admins ), 'Fixture admin required.' );
$previous_user = get_current_user_id();
wp_set_current_user( (int) $admins[0] );
$orders = [];
$users = [];
$guarded = false;
$writes = 0;
$http = 0;
$guard = static function ( $sql ) use ( &$guarded, &$writes ) {
	if ( $guarded && ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql ) ) { ++$writes; throw new RuntimeException( 'Customer360 must not write.' ); }
	return $sql;
};
$no_http = static function () use ( &$http ) { ++$http; return new WP_Error( 'unexpected_http', 'Customer360 uses local owner data only.' ); };
$create = static function ( $customer, $email, $status = 'pending' ) use ( &$orders ) {
	$order = wc_create_order();
	$order->set_customer_id( $customer );
	$order->set_billing_email( $email );
	$order->set_billing_first_name( 'Shared name' );
	$order->set_billing_phone( '5551234567' );
	$order->set_billing_address_1( 'Shared address' );
	$order->set_customer_ip_address( '192.0.2.15' );
	$order->set_status( $status );
	$order->set_date_created( time() - 80 * HOUR_IN_SECONDS );
	$order->save();
	$orders[] = $order;
	return $order;
};
try {
	$suffix = strtolower( wp_generate_password( 8, false ) );
	foreach ( [ 'a', 'b' ] as $key ) {
		$id = wp_create_user( 'yr75-' . $key . '-' . $suffix, wp_generate_password(), 'yr75-' . $key . '-' . $suffix . '@example.test' );
		$assert( ! is_wp_error( $id ), 'Customer fixture creation.' );
		$users[] = $id;
	}
	$max_email = 'a@' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.' . str_repeat( 'd', 63 ) . '.' . str_repeat( 'e', 60 );
	$assert( 254 === strlen( $max_email ) && $max_email === YoRo_Orders_Read_Service::customer_360_email( $max_email ) && strlen( YoRo_Orders_Read_Service::customer_360_mask_email( $max_email ) ) <= 254 && YoRo_Orders_Read_Service::customer_360_mask_email( $max_email ) !== $max_email, 'Maximal valid email remains masked within the schema bound.' );
	$email = 'exact-' . $suffix . '@example.test';
	$a = $create( $users[0], $email );
	$a2 = $create( $users[0], 'different-' . $suffix . '@example.test', 'on-hold' );
	$b = $create( $users[1], $email );
	$guest = $create( 0, $email );
	$near = $create( 0, 'near-' . $suffix . '@example.test' );
	$a->add_order_note( 'Private token=DO-NOT-EXPOSE and exact contact ' . $email, false );
	$a->add_order_note( 'Customer-facing note ' . $email, true );
	add_filter( 'query', $guard );
	add_filter( 'pre_http_request', $no_http );
	$guarded = true;
	$r = YoRo_Orders_Read_Service::get_customer_timeline( [ 'customer_id' => $users[0], 'limit' => 50 ] );
	$assert( ! is_wp_error( $r ) && $r['success'], 'Exact registered timeline succeeds.' );
	$ids = array_values( array_unique( array_column( $r['events'], 'order_id' ) ) ); sort( $ids );
	$expected = [ $a->get_id(), $a2->get_id() ]; sort( $expected );
	$assert( $ids === $expected, 'Customer ID never unions same email/name/address/phone/IP domains.' );
	$assert( in_array( 'internal', array_column( $r['events'], 'visibility' ), true ) && in_array( 'customer', array_column( $r['events'], 'visibility' ), true ), 'Note visibility preserved.' );
	$assert( false === strpos( wp_json_encode( $r ), 'DO-NOT-EXPOSE' ) && false === strpos( wp_json_encode( $r ), $email ), 'No note body or raw email leakage.' );
	$assert( $r === YoRo_Orders_Read_Service::get_customer_timeline( [ 'customer_id' => $users[0], 'limit' => 50 ] ), 'Timeline ordering and projection are deterministic.' );
	$wire = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $r, 'customers.get_timeline' );
	$assert( array_keys( $wire['data'] ) === [ 'identity', 'events', 'count', 'coverage' ] && $wire['data']['events'] === $r['events'], 'Canonical timeline keeps only declared bounded fields.' );
	$executed = YoRo_Action_Executor::execute( 'customers.get_timeline', [ 'customer_id' => $users[0], 'limit' => 50 ] );
	$assert( ! is_wp_error( $executed ) && $executed['success'] && $executed['read_only'] && empty( $executed['requires_confirmation'] ), 'Registered timeline executes as a read without a confirmation gate: ' . wp_json_encode( $executed ) );
	$by_email = YoRo_Orders_Read_Service::get_customer_timeline( [ 'email' => ' ' . strtoupper( $email ) . ' ', 'limit' => 50 ] );
	$assert( ! is_wp_error( $by_email ), 'Explicit exact normalized email succeeds.' );
	$ids = array_values( array_unique( array_column( $by_email['events'], 'order_id' ) ) ); sort( $ids );
	$expected = [ $a->get_id(), $b->get_id(), $guest->get_id() ]; sort( $expected );
	$assert( $ids === $expected && 'email' === $by_email['identity']['basis'], 'Email domain remains exact without customer-ID union.' );
	foreach ( [ [], [ 'customer_id' => $users[0], 'email' => $email ], [ 'customer_id' => (string) $users[0] ], [ 'customer_id' => 0 ], [ 'email' => $email . "\n" ], [ 'email' => 'bad email' ], [ 'customer_name' => 'Shared name' ], [ 'phone' => '5551234567' ], [ 'ip' => '192.0.2.15' ], [ 'customer_id' => $users[0], 'site_id' => 99 ], [ 'customer_id' => $users[0], 'limit' => 51 ], [ 'customer_id' => $users[0], 'limit' => '5' ] ] as $invalid ) {
		$assert( is_wp_error( YoRo_Orders_Read_Service::get_customer_timeline( $invalid ) ), 'Malformed or ambiguous customer identity fails closed.' );
	}
	$inject = static function ( $results, $args ) use ( $b ) { return isset( $args['customer_id'] ) && $args['customer_id'] ? [ $b ] : $results; };
	add_filter( 'woocommerce_order_query', $inject, 99, 2 );
	try { $assert( is_wp_error( YoRo_Orders_Read_Service::get_customer_timeline( [ 'customer_id' => $users[0] ] ) ), 'Foreign owner result cannot leak into selected identity.' ); }
	finally { remove_filter( 'woocommerce_order_query', $inject, 99 ); }
	$limited = YoRo_Orders_Read_Service::get_customer_timeline( [ 'customer_id' => $users[0], 'limit' => 1 ] );
	$assert( $limited['count'] === 1 && $limited['coverage']['truncated'], 'Event cap reports bounded coverage.' );
	$q = YoRo_Store_Customer_Service::get_customer_follow_up_queue( [ 'limit' => 20 ] );
	$assert( ! is_wp_error( $q ) && $q['read_only'] && ! $q['undoable'], 'Follow-up queue is read-only.' );
	$executed_queue = YoRo_Action_Executor::execute( 'customers.get_follow_up_queue', [ 'limit' => 20 ] );
	$assert( ! is_wp_error( $executed_queue ) && $executed_queue['success'] && $executed_queue['read_only'] && empty( $executed_queue['requires_confirmation'] ), 'Registered follow-up executes as a read without a confirmation gate.' );
	$legacy = YoRo_Store_Customer_Service::get_customer_service_queue( [ 'limit' => 20 ] );
	$assert( array_column( $q['cases'], 'order_id' ) === array_slice( array_column( $legacy['orders'], 'id' ), 0, 20 ), 'New queue reuses legacy provider ranking exactly.' );
	$assert( count( array_unique( array_column( $q['cases'], 'order_id' ) ) ) === count( $q['cases'] ), 'Cases dedupe only the exact order identity.' );
	$assert( false === strpos( wp_json_encode( $q ), $email ), 'Follow-up cases mask source contact.' );
	foreach ( [ [ 'limit' => 21 ], [ 'limit' => '5' ], [ 'email' => $email ], [ 'auto_send' => true ], [ 'customer_name' => 'Shared name' ] ] as $invalid ) {
		$assert( is_wp_error( YoRo_Store_Customer_Service::get_customer_follow_up_queue( $invalid ) ), 'Follow-up rejects unsupported fields and bounds.' );
	}
	$assert( 0 === $writes && 0 === $http, 'Both capabilities perform zero writes and outbound requests.' );
	$guarded = false;
	for ( $i = 0; $i < 20; ++$i ) { $create( $users[0], $email ); }
	$guarded = true;
	$bounded = YoRo_Orders_Read_Service::get_customer_timeline( [ 'customer_id' => $users[0], 'limit' => 50 ] );
	$assert( ! is_wp_error( $bounded ) && 20 === $bounded['coverage']['orders_scanned'] && $bounded['coverage']['truncated'], 'Order scan cap is explicit.' );
	wp_set_current_user( 0 );
	$assert( is_wp_error( YoRo_Orders_Read_Service::get_customer_timeline( [ 'email' => $email ] ) ) && is_wp_error( YoRo_Store_Customer_Service::get_customer_follow_up_queue( [] ) ), 'Unprivileged caller cannot inspect Customer360.' );
} finally {
	$guarded = false;
	remove_filter( 'query', $guard );
	remove_filter( 'pre_http_request', $no_http );
	foreach ( $orders as $order ) { $order->delete( true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	wp_set_current_user( $previous_user );
}
echo "PASS: Customer360 exact identity, privacy, bounds, visibility, shared queue, zero writes and cleanup.\n";
