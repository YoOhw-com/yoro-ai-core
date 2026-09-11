<?php
/** B5 local observation contract; requires disposable WordPress/WooCommerce fixture. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$failures = [];
$assert = static function ( $ok, $message ) use ( &$failures ) { if ( ! $ok ) { $failures[] = $message; } };
global $wpdb;
$table = YoRo_DB::logs_table_name();
$ids = [];
$orders = [];
$users = [];
$old_user = get_current_user_id();
$insert = static function ( $order_id, $action = 'orders.send_customer_email', $status = 'executed', $result = [], $params = null, $preview = false ) use ( &$ids, $wpdb, $table ) {
	$wpdb->insert( $table, [ 'user_id' => 0, 'command' => 'private@example.test secret subject', 'action' => $action,
		'params' => null === $params ? serialize( [ 'order_id' => $order_id, 'email' => 'private@example.test', 'instruction' => 'secret body' ] ) : $params,
		'preview' => $preview ? 1 : 0, 'confirm' => 0, 'status' => $status, 'result' => serialize( $result ),
		'created_at' => '2026-09-10 12:00:00', 'error' => 'private@example.test' ] );
	$ids[] = (int) $wpdb->insert_id;
	return end( $ids );
};
$read = static function ( $id, $limit = 20, $cursor = null ) {
	$params = [ 'order_id' => $id, 'limit' => $limit ];
	if ( null !== $cursor ) { $params['cursor'] = $cursor; }
	return YoRo_Orders_Read_Service::get_email_history( $params );
};
try {
	$user = wp_insert_user( [ 'user_login' => 'yoro-history-' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] );
	$users[] = $user;
	get_user_by( 'id', $user )->add_cap( 'yoro_view_store_data' );
	wp_set_current_user( $user );
	$order = wc_create_order(); $orders[] = $order; $id = $order->get_id();
	$other = wc_create_order(); $orders[] = $other;
	$empty = $read( $id );
	$assert( ! is_wp_error( $empty ) && [] === $empty['email_history']['events'], 'Empty local history must return an empty page.' );
	$a = $insert( $id, 'orders.send_customer_email', 'executed', [ 'success' => true ] );
	$b = $insert( $id, 'orders.email_customer_template', 'error', [ 'success' => false ] );
	$c = YoRo_Logger::log( [ 'action' => 'orders.send_transactional_email', 'params' => [ 'order_id' => $id ], 'status' => 'executed', 'result' => [ 'success' => true, 'outcome' => 'mailer_accepted', 'recipient_masked' => 'private@example.test', 'body' => 'secret body' ] ] ); $ids[] = $c;
	$d = YoRo_Logger::log( [ 'action' => 'orders.send_transactional_email', 'params' => [ 'order_id' => $id ], 'status' => 'error', 'result' => [ 'success' => false, 'error' => [ 'code' => 'transactional_email_outcome_unknown', 'message' => 'secret' ] ] ] ); $ids[] = $d;
	$insert( $other->get_id() ); $insert( $id, 'orders.send_customer_email', 'preview', [], null, true );
	$page = $read( $id, 2 )['email_history'];
	$assert( array_column( $page['events'], 'log_id' ) === [ $d, $c ], 'Newest terminal events must be ordered by immutable id.' );
	$assert( true === $page['pagination']['has_more'] && true === $page['pagination']['scan_complete'], 'An additional match proves has_more=true.' );
	$assert( 'unknown' === $page['events'][0]['mailer_outcome'] && 'mailer_accepted' === $page['events'][1]['mailer_outcome'], 'Explicit observations must survive logger compaction.' );
	$new = $insert( $id );
	$second = $read( $id, 2, $page['pagination']['next_cursor'] )['email_history'];
	$assert( array_column( $second['events'], 'log_id' ) === [ $b, $a ], 'Continuation must retain extra match and exclude concurrent appended rows.' );
	$assert( false === $second['pagination']['has_more'] && null === $second['pagination']['next_cursor'], 'Snapshot exhaustion is proven false.' );
	$assert( 'failure' === $second['events'][0]['action_status'] && 'not_recorded' === $second['events'][1]['mailer_outcome'], 'Legacy status is preserved without inventing acceptance.' );
	$assert( is_wp_error( $read( $other->get_id(), 2, $page['pagination']['next_cursor'] ) ), 'Cross-order cursor must fail.' );
	$assert( is_wp_error( $read( $id, 3, $page['pagination']['next_cursor'] ) ), 'Cross-limit cursor must fail.' );
	$assert( is_wp_error( $read( $id, 2, $page['pagination']['next_cursor'] . '0' ) ), 'Tampered cursor must fail.' );
	foreach ( [ 0, -1, 51, '2', false ] as $limit ) { $assert( is_wp_error( $read( $id, $limit ) ), 'Invalid limit must fail.' ); }
	foreach ( [ [ 'order_id' => $id, 'recipient' => 'private@example.test' ], [ 'order_id' => (string) $id ], [ 'order_id' => $id, 'cursor' => null ], [ 'order_id' => $id, 'order_ids' => [ $id ] ] ] as $bad ) {
		$assert( is_wp_error( YoRo_Orders_Read_Service::get_email_history( $bad ) ), 'Extra/malformed params must fail.' );
	}
	for ( $i = 0; $i < 505; $i++ ) { $insert( $other->get_id() ); }
	$sparse = $read( $id )['email_history'];
	$assert( [] === $sparse['events'] && null === $sparse['pagination']['has_more'] && false === $sparse['pagination']['scan_complete'] && 500 === $sparse['pagination']['scanned_rows'] && is_string( $sparse['pagination']['next_cursor'] ), 'Sparse >500 must produce bounded empty unknown continuation.' );
	$continued = $read( $id, 20, $sparse['pagination']['next_cursor'] )['email_history'];
	$assert( 5 === count( $continued['events'] ) && false === $continued['pagination']['has_more'], 'Sparse continuation must reach older exact-order events.' );
	$insert( $id, 'orders.send_customer_email', 'executed', [], 'a:1:{broken' );
	$insert( $id, 'orders.send_customer_email', 'executed', [], serialize( [ 'order_id' => $id, 'junk' => str_repeat( 'x', 17000 ) ] ) );
	class YoRo_History_No_Construct { public function __wakeup() { throw new Exception( 'Object deserialization executed' ); } }
	$insert( $id, 'orders.send_customer_email', 'executed', [], serialize( [ 'order_id' => $id, 'junk' => new YoRo_History_No_Construct() ] ) );
	$insert( $id, 'orders.send_customer_email', 'executed', [], serialize( [ 'order_id' => $other->get_id(), 'order_ids' => [ $id ] ] ) );
	$insert( $id, 'orders.send_customer_email', 'executed', [ 'success' => true ], serialize( [ 'order_ids' => [ $id, $other->get_id() ] ] ) );
	$legacy = $insert( $id, 'orders.send_customer_email', 'executed' );
	$wpdb->update( $table, [ 'result' => null ], [ 'id' => $legacy ] );
	$unsafe = $read( $id )['email_history'];
	$assert( count( $unsafe['events'] ) === 1 && $unsafe['events'][0]['log_id'] === $legacy && 'not_recorded' === $unsafe['events'][0]['mailer_outcome'], 'Bulk/conflicting targets must be excluded; missing legacy detail stays lower fidelity.' );
	$assert( 3 === $unsafe['coverage']['warnings']['unsafe_rows_skipped'], 'Corrupt, oversized and object legacy rows must be skipped safely.' );
	$public = YoRo_Commerce_Contract_Builder::canonical_execution_payload( [ 'success' => true, 'email_history' => $continued, 'raw' => 'private@example.test', 'summary' => 'secret body' ], 'orders.get_email_history' );
	$assert( true === $public['success'] && false === strpos( wp_json_encode( $public ), 'private@example.test' ) && false === strpos( wp_json_encode( $public ), 'secret body' ), 'Public envelope must contain no raw PII or injected prose.' );
	$null_success = YoRo_Commerce_Contract_Builder::canonical_execution_payload( [ 'success' => true, 'email_history' => null ], 'orders.get_email_history' );
	$assert( false === $null_success['success'] && null === $null_success['data']['email_history'], 'Successful null history must fail closed.' );
	$valid_string = $insert( $id, 'orders.email_customer_template', 'executed', [], serialize( [ 'order_id' => (string) $id ] ) );
	$insert( $id, 'orders.email_customer_template', 'executed', [], serialize( [ 'order_id' => $id . 'junk' ] ) );
	$insert( $id, 'orders.email_customer_template', 'executed', [], serialize( [ 'order_ids' => [ (string) $id, (string) $other->get_id() ] ] ) );
	$string_page = $read( $id )['email_history'];
	$assert( $string_page['events'][0]['log_id'] === $valid_string, 'Canonical legacy decimal singleton must match; coercive strings and bulk must not.' );
	$continued['events'][0]['recipient'] = 'private@example.test';
	$denied = YoRo_Commerce_Contract_Builder::canonical_execution_payload( [ 'success' => true, 'email_history' => $continued ], 'orders.get_email_history' );
	$assert( false === $denied['success'] && null === $denied['data']['email_history'], 'Unknown public event fields must fail closed.' );
	wp_set_current_user( 0 );
	$assert( is_wp_error( $read( $id ) ), 'Every page must repeat permission enforcement.' );
} finally {
	wp_set_current_user( $old_user );
	foreach ( $ids as $log_id ) { $wpdb->delete( $table, [ 'id' => $log_id ] ); }
	foreach ( $orders as $order ) { $order->delete( true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $user_id ) { wp_delete_user( $user_id ); }
}
echo wp_json_encode( [ 'success' => ! $failures, 'failures' => $failures ], JSON_PRETTY_PRINT ) . PHP_EOL;
if ( $failures ) { exit( 1 ); }
