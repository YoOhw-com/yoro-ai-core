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

// Bounded refund observation uses only disposable owner fixtures.
$previous_user = get_current_user_id(); wp_set_current_user( (int) $admins[0] );
$fixture_refunds = []; $fixture_orders = []; $guarded = false; $queries = []; $writes = 0; $http = 0;
$refund_query = static function ( $results, $args ) use ( &$queries, &$guarded ) {
	if ( $guarded && 'shop_order_refund' === ( $args['type'] ?? '' ) ) {
		$queries[] = $args;
		if ( ! is_int( $args['limit'] ) || $args['limit'] < 1 || $args['limit'] > 51 || 'ids' !== $args['return'] || ! is_int( $args['parent'] ) || $args['parent'] < 1 ) { throw new RuntimeException( 'Unbounded refund query.' ); }
	}
	return $results;
};
$make_refund = static function ( $parent, $amount = '1.00' ) use ( &$fixture_refunds ) {
	$r = new WC_Order_Refund(); $r->set_parent_id( $parent ); $r->set_amount( $amount ); $r->set_reason( 'Returned item' ); $r->set_refunded_payment( false ); $r->set_date_created( '2026-09-14T00:00:00+00:00' ); $r->save(); $fixture_refunds[] = $r; return $r;
};
try {
	$order = wc_create_order(); $order->set_total( '100.00' ); $order->set_currency( 'USD' ); $order->set_billing_email( 'SECRET@example.test' ); $order->set_transaction_id( 'SECRET' ); $order->save(); $fixture_orders[] = $order;
	$foreign = wc_create_order(); $foreign->set_total( '10.00' ); $foreign->save(); $fixture_orders[] = $foreign;
	add_filter( 'query', $guard ); add_filter( 'pre_http_request', $no_http ); add_filter( 'woocommerce_order_query', $refund_query, 99, 2 ); $guarded = true;
	$read = static fn( $extra = [] ) => YoRo_Orders_Read_Service::get_refunds( [ 'order_id' => $order->get_id() ] + $extra );
	$preview = static fn() => YoRo_Orders_Read_Service::refund_preview( [ 'order_id' => $order->get_id() ] );
	$empty = $read(); $assert( ! is_wp_error( $empty ) && [] === $empty['refunds'] && false === $empty['pagination']['has_more'], 'Empty refund history.' );
	$r = $preview(); $assert( ! is_wp_error( $r ) && '100.00' === $r['order']['remaining'] && '' === $r['order']['transaction_id'], 'Bounded normal preview and privacy.' );
	$guarded = false; $one = $make_refund( $order->get_id(), '10.00' ); $other = $make_refund( $foreign->get_id() ); $guarded = true;
	$r = YoRo_Action_Executor::execute( 'orders.get_refunds', [ 'order_id' => $order->get_id() ] );
	$assert( ! is_wp_error( $r ) && $one->get_id() === $r['refunds'][0]['refund_id'] && '10.00' === $r['refunds'][0]['amount'], 'Registered history executes.' );
	$assert( false === strpos( wp_json_encode( $r ), 'SECRET' ) && ! array_key_exists( 'created_by', $r['refunds'][0] ), 'No customer/payment/profile disclosure.' );
	$inject = static function ( $results, $args ) use ( $other ) { return 'shop_order_refund' === ( $args['type'] ?? '' ) ? [ $other->get_id() ] : $results; };
	add_filter( 'woocommerce_order_query', $inject, 100, 2 );
	try { $assert( is_wp_error( $read() ) && is_wp_error( $preview() ), 'Wrong parent closes both actions.' ); } finally { remove_filter( 'woocommerce_order_query', $inject, 100 ); }
	$inject = static function ( $results, $args ) use ( $one ) { return 'shop_order_refund' === ( $args['type'] ?? '' ) ? [ $one->get_id(), $one->get_id() ] : $results; };
	add_filter( 'woocommerce_order_query', $inject, 100, 2 );
	try { $assert( is_wp_error( $read() ), 'Duplicate refund identity.' ); } finally { remove_filter( 'woocommerce_order_query', $inject, 100 ); }
	$inject = static function ( $results, $args ) { return 'shop_order_refund' === ( $args['type'] ?? '' ) ? [ 9007199254740991 ] : $results; };
	add_filter( 'woocommerce_order_query', $inject, 100, 2 );
	try { $assert( is_wp_error( $read() ), 'Deleted/missing query row fails closed.' ); } finally { remove_filter( 'woocommerce_order_query', $inject, 100 ); }
	foreach ( [ [], [ 'order_id' => '1' ], [ 'order_id' => 0 ], [ 'order_id' => true ], [ 'order_id' => $order->get_id(), 'amount' => null ], [ 'order_id' => $order->get_id(), 'restore_stock' => false ], [ 'order_id' => $order->get_id(), 'email' => 'SECRET' ] ] as $bad ) { $assert( is_wp_error( YoRo_Orders_Read_Service::get_refunds( $bad ) ) && is_wp_error( YoRo_Orders_Read_Service::refund_preview( $bad ) ), 'Raw financial/customer inputs denied.' ); }
	foreach ( [ [ 'limit' => 0 ], [ 'limit' => 51 ], [ 'limit' => '5' ], [ 'page' => 101 ], [ 'page' => null ] ] as $bad ) { $assert( is_wp_error( $read( $bad ) ), 'Invalid pagination.' ); }
	foreach ( [ 'amount' => [ -1, INF, 'not-money', [] ], 'reason' => [ str_repeat( 'x', 501 ), "bad\0text", "\xff", [] ], 'date_created' => [ null, 'invalid', new WC_DateTime( '1960-01-01' ) ], 'refunded_payment' => [ 1, 'yes', null ] ] as $property => $values ) {
		foreach ( $values as $invalid ) {
			$filter = static fn() => $invalid;
			$hook = 'woocommerce_order_refund_get_' . $property;
			add_filter( $hook, $filter );
			try { $assert( is_wp_error( $read() ) && is_wp_error( $preview() ), 'Malformed refund owner ' . $property . ' closes both actions.' ); }
			finally { remove_filter( $hook, $filter ); }
		}
	}
	$filter = static fn() => '&lt;b&gt;Returned&lt;/b&gt; item';
	add_filter( 'woocommerce_order_refund_get_reason', $filter );
	try { $assert( 'Returned item' === $read()['refunds'][0]['reason'], 'Encoded tags become bounded plain text.' ); }
	finally { remove_filter( 'woocommerce_order_refund_get_reason', $filter ); }
	foreach ( [ [ (string) $one->get_id() ], [ $one ], [ $order->get_id() ], [ null ] ] as $invalid_rows ) {
		$inject = static fn( $results, $args ) => 'shop_order_refund' === ( $args['type'] ?? '' ) ? $invalid_rows : $results;
		add_filter( 'woocommerce_order_query', $inject, 100, 2 );
		try { $assert( is_wp_error( $read() ) && is_wp_error( $preview() ), 'Malformed owner query row is rejected.' ); }
		finally { remove_filter( 'woocommerce_order_query', $inject, 100 ); }
	}
	$inject = static fn( $results, $args ) => 'shop_order_refund' === ( $args['type'] ?? '' ) ? [ $one->get_id(), $other->get_id() ] : $results;
	add_filter( 'woocommerce_order_query', $inject, 100, 2 );
	try { $assert( is_wp_error( $read( [ 'limit' => 1 ] ) ), 'Lookahead owner is validated before pagination succeeds.' ); }
	finally { remove_filter( 'woocommerce_order_query', $inject, 100 ); }
	$original_blog = $GLOBALS['blog_id'];
	$change_site = static function ( $value ) { $GLOBALS['blog_id'] = 999999; return $value; };
	add_filter( 'woocommerce_order_refund_get_amount', $change_site );
	try { $assert( is_wp_error( $read() ), 'Current-site drift during owner projection fails closed.' ); }
	finally { $GLOBALS['blog_id'] = $original_blog; remove_filter( 'woocommerce_order_refund_get_amount', $change_site ); }
	$guarded = false;
	$item_order = wc_create_order(); $item_order->set_currency( 'USD' ); $item_order->set_total( '200.00' ); $item_order->save(); $fixture_orders[] = $item_order;
	$item = new WC_Order_Item_Product(); $item->set_name( 'Normal item' ); $item->set_quantity( 2 ); $item->set_total( '20.00' ); $item->set_subtotal( '20.00' ); $item_order->add_item( $item ); $item_order->save();
	$item_refund = $make_refund( $item_order->get_id(), '10.00' );
	$refund_item = new WC_Order_Item_Product(); $refund_item->set_name( 'Returned item' ); $refund_item->set_quantity( -1 ); $refund_item->set_total( '-10.00' ); $refund_item->set_subtotal( '-10.00' ); $refund_item->add_meta_data( '_refunded_item_id', $item->get_id(), true ); $item_refund->add_item( $refund_item ); $item_refund->save();
	$guarded = true;
	$item_preview = static fn() => YoRo_Orders_Read_Service::refund_preview( [ 'order_id' => $item_order->get_id() ] );
	$r = $item_preview();
	$assert( ! is_wp_error( $r ) && 1.0 === $r['items'][0]['remaining_quantity'] && '10.00' === $r['items'][0]['remaining_total'] && 1.0 === $r['items'][0]['refunded_quantity'], 'Bounded preview preserves WooCommerce per-item quantity and amount semantics.' );
	$guarded = false;
	for ( $i = 0; $i < 198; ++$i ) { $extra = new WC_Order_Item_Product(); $extra->set_name( 'Bounded item' ); $extra->set_quantity( 1 ); $extra->set_total( '0' ); $extra->set_subtotal( '0' ); $item_order->add_item( $extra ); } $item_order->save();
	$guarded = true;
	$r = $item_preview(); $assert( ! is_wp_error( $r ) && 199 === count( $r['items'] ), 'Exactly 200 combined order/refund line items succeeds.' );
	$guarded = false; $extra = new WC_Order_Item_Product(); $extra->set_name( 'Over bound' ); $extra->set_quantity( 1 ); $extra->set_total( '0' ); $extra->set_subtotal( '0' ); $item_order->add_item( $extra ); $item_order->save(); $guarded = true;
	$assert( is_wp_error( $item_preview() ), '201 combined line items fails closed.' );
	$guarded = false; for ( $i = 1; $i < 50; ++$i ) { $make_refund( $order->get_id() ); } $guarded = true;
	$r = $preview(); $assert( ! is_wp_error( $r ) && 50 === count( $r['refunds'] ), 'Exactly50 preview.' );
	$first = $read( [ 'limit' => 20, 'page' => 1 ] ); $second = $read( [ 'limit' => 20, 'page' => 2 ] );
	$assert( 20 === count( $first['refunds'] ) && 20 === count( $second['refunds'] ) && $first['pagination']['has_more'] && ! array_intersect( array_column( $first['refunds'], 'refund_id' ), array_column( $second['refunds'], 'refund_id' ) ) && false === $first['pagination']['snapshot_consistent'], 'Bounded deterministic live pagination.' );
	$guarded = false; $make_refund( $order->get_id() ); $guarded = true;
	$assert( is_wp_error( $preview() ) && 50 === count( $read( [ 'limit' => 50 ] )['refunds'] ), 'Overcap preview fails; history stays bounded.' );
	$assert( ! empty( $queries ) && 0 === $writes && 0 === $http, 'Bounded owner reads with zero mutations/network.' );
	wp_set_current_user( 0 ); $assert( is_wp_error( $read() ) && is_wp_error( $preview() ), 'Permission enforced on both actions.' );
	echo "PASS: refund observation bounds, pagination, identity, privacy and read-only behavior.\n";
} finally {
	$guarded = false; remove_filter( 'query', $guard ); remove_filter( 'pre_http_request', $no_http ); remove_filter( 'woocommerce_order_query', $refund_query, 99 );
	foreach ( array_reverse( $fixture_refunds ) as $r ) { $r->delete( true ); }
	foreach ( $fixture_orders as $r ) { $r->delete( true ); }
	wp_set_current_user( $previous_user );
}
