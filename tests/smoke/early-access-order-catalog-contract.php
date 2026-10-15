<?php
/** Run only in a disposable WordPress/WooCommerce installation. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Early_Access_Order_Read' ) ) { YoRo_AI_Core::init(); }
if ( ! class_exists( 'WooCommerce' ) ) { echo "YoRo order reads unavailable without WooCommerce.\n"; return; }
if ( str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'yorohq.com' ) ) { throw new RuntimeException( 'Never run fixture contracts on canary or production.' ); }
$assert = static function( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
add_filter( 'pre_wp_mail', '__return_true' );
$original_user = get_current_user_id();
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
    wp_set_current_user( (int) $admins[0] );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data' ] ] );
    $ids = [];
    foreach ( [ 'pending', 'processing', 'completed' ] as $i => $status ) {
        $order = new WC_Order(); $order->set_status( $status ); $order->set_currency( 'USD' ); $order->set_total( '12.30' );
        $order->set_date_created( '2026-10-14T12:00:00+00:00' );
        $order->set_billing_first_name( 'PRIVATE-NAME' ); $order->set_billing_email( 'private-order@example.com' );
        $order->set_billing_phone( '+12025550123' ); $order->set_billing_address_1( 'PRIVATE-ADDRESS' );
        $order->set_customer_note( 'PRIVATE-NOTE' ); $order->update_meta_data( 'secret', 'PRIVATE-META' ); $order->save();
        $ids[] = $order->get_id();
    }
    $before = array_map( static function( $id ) { return wp_json_encode( wc_get_order( $id )->get_data() ); }, $ids );
    $read = static function( $action, $params, $page = 1 ) { return YoRo_Early_Access_Order_Read::execute( [ 'action' => $action, 'params' => $params,
        'extensions' => [ 'yoro.mcp_early_access_product_read' => [ 'read_family' => 'orders', 'order_page' => $page ] ] ] ); };
    $first = $read( 'orders.search', [ 'date_from' => '2026-10-14', 'date_to' => '2026-10-14', 'limit' => 1 ] );
    $second = $read( 'orders.search', [ 'date_from' => '2026-10-14', 'date_to' => '2026-10-14', 'limit' => 1 ], 2 );
    $assert( ! is_wp_error( $first ) && $first['data']['page_info']['has_next_page'] && '2' === $first['data']['page_info']['next_cursor'], 'Search must offer bounded server pages.' );
    $assert( $first['data']['orders'][0]['id'] !== $second['data']['orders'][0]['id'], 'Pages must not repeat the same order in a stable store.' );
    $exact = $read( 'orders.search', [ 'order_id' => $ids[0], 'status' => 'pending', 'limit' => 1 ] );
    $assert( 1 === $exact['data']['count'] && $ids[0] === $exact['data']['orders'][0]['id'], 'Exact lookup must find an older matching ID.' );
    $mismatch = $read( 'orders.search', [ 'order_id' => $ids[0], 'status' => 'completed', 'limit' => 10 ] );
    $assert( 0 === $mismatch['data']['count'], 'Exact ID must not bypass status filtering.' );
    $outside = $read( 'orders.search', [ 'order_id' => $ids[0], 'date_from' => '2026-10-15', 'limit' => 10 ] );
    $assert( 0 === $outside['data']['count'], 'Exact ID must not bypass date filtering.' );
    $unsafe_number = static function() { return '12025550123'; };
    add_filter( 'woocommerce_order_number', $unsafe_number );
    $detail = $read( 'orders.get_details', [ 'order_ids' => $ids ] );
    remove_filter( 'woocommerce_order_number', $unsafe_number );
    $assert( (string) $ids[0] === $detail['data']['orders'][0]['number'], 'Third-party display numbers cannot disclose contact data.' );
    $assert( 3 === $detail['data']['count'] && '***' === $detail['data']['orders'][0]['customer']['email'], 'Details must redact contact data before journaling.' );
    $wire = wp_json_encode( $detail );
    foreach ( [ 'PRIVATE-', 'private-order@example.com', '+12025550123' ] as $private ) { $assert( false === strpos( $wire, $private ), 'Private customer data must never enter the read projection.' ); }
    $timeline = $read( 'orders.get_timeline', [ 'order_id' => $ids[0], 'limit' => 10 ] );
    $assert( 1 === $timeline['data']['count'] && 'created' === $timeline['data']['events'][0]['type'], 'Timeline contains only recorded system milestones.' );
    foreach ( [ [ 'orders.update_status', [ 'order_ids' => $ids, 'status' => 'completed' ] ], [ 'orders.search', [ 'limit' => 21 ] ],
        [ 'orders.search', [ 'limit' => 10, 'email' => 'private-order@example.com' ] ], [ 'orders.search', [ 'limit' => 10, 'date_from' => '2026-02-30' ] ],
        [ 'orders.get_details', [ 'order_ids' => [ '1' ] ] ], [ 'orders.get_timeline', [ 'order_id' => $ids[0], 'limit' => 0 ] ] ] as $bad ) {
        $assert( ! YoRo_Early_Access_Order_Read::valid_params( $bad[0], $bad[1], [ 'order_page' => 1 ] ), 'Invalid or write selectors must fail closed.' );
    }
    $timezone = get_option( 'timezone_string' );
    update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
    $local = new WC_Order(); $local->set_status( 'pending' ); $local->set_currency( 'USD' ); $local->set_total( '0.00' );
    $local->set_date_created( '2026-10-14T23:30:00+00:00' ); $local->save();
    $local_result = $read( 'orders.search', [ 'order_id' => $local->get_id(), 'date_from' => '2026-10-15', 'date_to' => '2026-10-15', 'limit' => 1 ] );
    $assert( 1 === $local_result['data']['count'] && '2026-10-15' === substr( $local_result['data']['orders'][0]['created_at'], 0, 10 ), 'Creation date filters must use the store timezone across UTC midnight.' );
    update_option( 'timezone_string', $timezone );
    wp_set_current_user( 0 );
    $assert( is_wp_error( $read( 'orders.get_details', [ 'order_ids' => $ids ] ) ), 'Unauthenticated callers cannot execute order reads.' );
    wp_set_current_user( (int) $admins[0] );
    $after = array_map( static function( $id ) { return wp_json_encode( wc_get_order( $id )->get_data() ); }, $ids );
    $assert( $before === $after, 'Order reads must not alter business data.' );
    echo "YoRo bounded order search, details, milestones, privacy and no-write contracts passed.\n";
} finally { $wpdb->query( 'ROLLBACK' ); wp_set_current_user( $original_user ); wp_cache_flush(); }
