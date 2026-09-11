<?php
/** C1 owner API contract; run only in the disposable WordPress/WooCommerce smoke harness. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$failures = [];
$assert = static function ( $ok, $message ) use ( &$failures ) { if ( ! $ok ) { $failures[] = $message; } };
$old_user = get_current_user_id();
$users = [];
$orders = [];
$permissions = [];
$products = [];
$read = static function ( $order_id, $limit = 20, $page = 1 ) {
	return YoRo_Orders_Read_Service::get_download_permissions( compact( 'order_id', 'limit', 'page' ) );
};
$create = static function ( $order, $product_id, $download_id, $remaining, $expires = null ) use ( &$permissions ) {
	$row = new WC_Customer_Download();
	$row->set_props( [ 'order_id' => $order->get_id(), 'order_key' => $order->get_order_key(), 'user_email' => 'private@example.test',
		'user_id' => $order->get_customer_id(), 'product_id' => $product_id, 'download_id' => $download_id,
		'downloads_remaining' => $remaining, 'download_count' => 2, 'access_granted' => '2026-09-01T00:00:00Z', 'access_expires' => $expires ] );
	$row->save();
	$permissions[] = $row;
	return $row;
};
// A bounded test adapter records owner query arguments and injects malformed owner responses.
class YoRo_Download_Permission_Test_Store extends WC_Customer_Download_Data_Store {
	public static $responses = [];
	public static $calls = [];
	public function get_downloads( $args = [] ) {
		self::$calls[] = $args;
		$response = array_shift( self::$responses );
		if ( $response instanceof Throwable ) { throw $response; }
		return $response;
	}
}
$store_filter = static function ( $stores ) { $stores['customer-download'] = 'YoRo_Download_Permission_Test_Store'; return $stores; };
try {
	$user = wp_insert_user( [ 'user_login' => 'yoro-download-' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] );
	$users[] = $user;
	get_user_by( 'id', $user )->add_cap( 'yoro_view_store_data' );
	wp_set_current_user( $user );
	$order = wc_create_order( [ 'customer_id' => $user ] ); $orders[] = $order; $id = $order->get_id();
	$guest = wc_create_order(); $orders[] = $guest;
	$empty = $read( $id );
	$assert( ! is_wp_error( $empty ) && [] === $empty['download_permissions']['permissions'], 'No rows means an empty observed permission page.' );
	$product = new WC_Product_Simple(); $product->set_name( 'Untrusted private@example.test https://secret.test/file license-token' ); $product->save(); $products[] = $product;
	$second_product = new WC_Product_Simple(); $second_product->set_name( 'Other file' ); $second_product->save(); $products[] = $second_product;
	$a = $create( $order, $product->get_id(), 'file-a', 3, '2020-01-01T00:00:00Z' );
	$b = $create( $order, $product->get_id(), 'file-b', '' );
	$c = $create( $order, $second_product->get_id(), 'file-c', 0 );
	$d = $create( $guest, $product->get_id(), 'guest-file', 4 );
	$offset_date = clone $a; $offset_date->set_access_granted( '2026-09-01T07:00:00+07:00' );
	$assert( '2026-09-01T00:00:00Z' === YoRo_Order_Presenter::format_download_permission( $offset_date, $id )['access_granted'], 'WooCommerce offset timestamps normalize to observed UTC without changing the source.' );
	$before = array_map( static function ( $row ) { return ( new WC_Customer_Download( $row->get_id() ) )->get_data(); }, $permissions );
	$first = $read( $id, 2 )['download_permissions'];
	$last = $read( $id, 2, 2 )['download_permissions'];
	$assert( [ 'file-a', 'file-b' ] === array_column( $first['permissions'], 'download_id' ), 'Permission IDs order the exact-order page deterministically.' );
	$assert( true === $first['pagination']['has_more'] && 2 === $first['pagination']['next_page'], 'Lookahead observes a next page.' );
	$assert( [ 'file-c' ] === array_column( $last['permissions'], 'download_id' ) && false === $last['pagination']['has_more'], 'Second page must not skip the lookahead row.' );
	$assert( 3 === $first['permissions'][0]['downloads_remaining'] && false === $first['permissions'][0]['unlimited_downloads'], 'Finite quota remains a nonnegative integer.' );
	$assert( true === $first['permissions'][1]['unlimited_downloads'] && null === $first['permissions'][1]['downloads_remaining'], 'Empty WooCommerce quota means explicit unlimited downloads.' );
	$assert( '2020-01-01T00:00:00Z' === $first['permissions'][0]['access_expires'] && null === $first['permissions'][1]['access_expires'], 'Expired/no-expiry facts remain observations.' );
	$assert( 'guest-file' === $read( $guest->get_id() )['download_permissions']['permissions'][0]['download_id'], 'Guest orders use the same exact-order boundary without customer enumeration.' );
	$public = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $read( $id ), 'orders.get_download_permissions' );
	$assert( true === $public['success'], 'Valid owner facts survive the canonical output guard.' );
	foreach ( [ 'private@example.test', '"order_key"', '"permission_id"', '"download_url"', 'secret.test', 'license-token', '"user_email"', $order->get_order_key() ] as $secret ) {
		$assert( false === strpos( wp_json_encode( $public ), $secret ), 'Forbidden identity, URL, metadata and secret values cannot enter output.' );
	}
	$after = array_map( static function ( $row ) { return ( new WC_Customer_Download( $row->get_id() ) )->get_data(); }, $permissions );
	$assert( wp_json_encode( $before ) === wp_json_encode( $after ), 'Read must not save, grant, regenerate, increment or deliver.' );
	$product->delete( true );
	$assert( ! is_wp_error( $read( $id ) ), 'Deleted current product metadata must not prevent permission observation.' );
	foreach ( [ null, '', '2', 0, -1, 51, false, 1.5, [], PHP_INT_MAX ] as $bad ) {
		$assert( is_wp_error( $read( $id, $bad ) ), 'Malformed and out-of-range limit must fail.' );
	}
	foreach ( [ null, '', '2', 0, -1, 101, false, 1.5, [], PHP_INT_MAX ] as $bad ) {
		$assert( is_wp_error( $read( $id, 20, $bad ) ), 'Malformed and out-of-range page must fail.' );
	}
	foreach ( [ 'order_ids', 'customer_id', 'user_id', 'user_email', 'product_id', 'download_id', 'order_key', 'permission_id', 'orderby', 'sql', 'regenerate', 'license_key' ] as $extra ) {
		$assert( is_wp_error( YoRo_Orders_Read_Service::get_download_permissions( [ 'order_id' => $id, $extra => 'forbidden' ] ) ), 'Extra/bulk/selectors must fail before owner access.' );
	}
	foreach ( [ null, '', (string) $id, 0, -1, false, 1.5, [] ] as $bad ) {
		$assert( is_wp_error( $read( $bad ) ), 'Only one positive integer order target is allowed.' );
	}
	$refund = new WC_Order_Refund(); $refund->set_parent_id( $id ); $refund->save(); $orders[] = $refund;
	$assert( is_wp_error( $read( $refund->get_id() ) ), 'Refunds are not order targets.' );
	$assert( is_wp_error( $read( PHP_INT_MAX ) ), 'Missing order fails closed.' );
	$assert( ! is_wp_error( $read( $id, 50, 100 ) ), 'Inclusive page/limit bounds are accepted.' );
	add_filter( 'woocommerce_data_stores', $store_filter );
	YoRo_Download_Permission_Test_Store::$responses = [ [ $a ], [ $b ] ];
	YoRo_Download_Permission_Test_Store::$calls = [];
	$cap = $read( $id, 1, 100 );
	$assert( true === $cap['download_permissions']['pagination']['has_more'] && null === $cap['download_permissions']['pagination']['next_page'], 'At page100 report observed more rows honestly without authorizing page101.' );
	$assert( [
		[ 'order_id' => $id, 'orderby' => 'permission_id', 'order' => 'ASC', 'return' => 'objects', 'limit' => 1, 'page' => 100 ],
		[ 'order_id' => $id, 'orderby' => 'permission_id', 'order' => 'ASC', 'return' => 'objects', 'limit' => 1, 'page' => 101 ],
	] === YoRo_Download_Permission_Test_Store::$calls, 'Only exact-order bounded deterministic owner queries are permitted.' );
	$bad_id = clone $a; $bad_id->set_download_id( 'https://secret.test/file?key=token' );
	foreach ( [ [ [ $d ], [] ], [ [ $a ], [ $d ] ], [ [ $bad_id ], [] ], [ [ new stdClass() ], [] ], [ [ $a, $b ], [] ], [ [], [ $a, $b ] ], [ new RuntimeException( 'private@example.test SQL secret' ), [] ], [ null, [] ] ] as $responses ) {
		YoRo_Download_Permission_Test_Store::$responses = $responses;
		$failed = $read( $id, 1 );
		$assert( is_wp_error( $failed ) && false === strpos( $failed->get_error_message(), 'private@example.test' ), 'Wrong-order, malformed, over-limit and exceptional owner results fail without partial leakage.' );
	}
	remove_filter( 'woocommerce_data_stores', $store_filter );
	foreach ( [ 'recipient', 'order_key', 'download_url', 'permission_id', 'license_key' ] as $field ) {
		$tampered = $public; $tampered['data']['download_permissions']['permissions'][0][ $field ] = 'secret';
		$guarded = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $tampered, 'orders.get_download_permissions' );
		$assert( false === $guarded['success'] && null === $guarded['data']['download_permissions'], 'Unknown public permission fields fail closed.' );
	}
	foreach ( [ 'unlimited', 'next_page', 'limitations', 'download_id' ] as $mutation ) {
		$tampered = $public;
		if ( 'unlimited' === $mutation ) { $tampered['data']['download_permissions']['permissions'][0]['unlimited_downloads'] = true; }
		if ( 'next_page' === $mutation ) { $tampered['data']['download_permissions']['pagination']['next_page'] = 2; }
		if ( 'limitations' === $mutation ) { array_pop( $tampered['data']['download_permissions']['coverage']['limitations'] ); }
		if ( 'download_id' === $mutation ) { $tampered['data']['download_permissions']['permissions'][0]['download_id'] = '/private/file'; }
		$assert( false === YoRo_Commerce_Contract_Builder::canonical_execution_payload( $tampered, 'orders.get_download_permissions' )['success'], 'Incoherent or unsafe public facts fail closed.' );
	}
	wp_set_current_user( 0 );
	$assert( is_wp_error( $read( $id ) ), 'Every read requires store read permission.' );
} finally {
	remove_filter( 'woocommerce_data_stores', $store_filter );
	wp_set_current_user( $old_user );
	foreach ( $permissions as $row ) { $row->delete( true ); }
	foreach ( array_reverse( $orders ) as $order ) { $order->delete( true ); }
	foreach ( $products as $product ) { if ( $product->get_id() ) { $product->delete( true ); } }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $user_id ) { wp_delete_user( $user_id ); }
}
echo wp_json_encode( [ 'success' => ! $failures, 'failures' => $failures ], JSON_PRETTY_PRINT ) . PHP_EOL;
if ( $failures ) { exit( 1 ); }
