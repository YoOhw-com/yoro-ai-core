<?php
/** Disposable WordPress/WooCommerce fixture; all contact values are synthetic. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Early_Access_Customer_Service_Read' ) ) { YoRo_AI_Core::init(); }
if ( ! class_exists( 'WooCommerce' ) ) { echo "Customer service reads unavailable without WooCommerce.\n"; return; }
if ( str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'yorohq.com' ) ) { throw new RuntimeException( 'Disposable fixtures only.' ); }
$assert = static function( $ok, $why ) { if ( ! $ok ) { throw new RuntimeException( $why ); } };
$original = get_current_user_id(); $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
add_filter( 'pre_wp_mail', '__return_true' );
$block = static function() { throw new RuntimeException( 'A bounded customer read cannot make outbound requests.' ); };
add_filter( 'pre_http_request', $block );
global $wpdb; $wpdb->query( 'START TRANSACTION' );
try {
    wp_set_current_user( (int) $admins[0] );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data', 'yoro_execute_actions' ] ] );
    $create = static function( $email, $phone, $name, $status, $age, $currency = 'USD' ) {
        $order = new WC_Order(); $order->set_status( $status ); $order->set_total( '12.30' ); $order->set_currency( $currency );
        $order->set_billing_email( $email ); $order->set_billing_phone( $phone ); $order->set_billing_first_name( $name ); $order->set_billing_last_name( 'Customer' );
        $order->set_customer_ip_address( '192.0.2.17' ); $order->set_customer_user_agent( 'PRIVATE-USER-AGENT' );
        $order->set_date_created( time() - $age ); $order->update_meta_data( '_yoro_device_id', 'PRIVATE-DEVICE' ); $order->save();
        $order->add_order_note( 'PRIVATE-NOTE-MUST-NOT-LEAVE', 0, false );
        return $order;
    };
    $email = 'yr0178-' . wp_generate_uuid4() . '@example.test';
    $one = $create( $email, '+1 (555) 123-4567', 'Synthetic', 'pending', 3 * DAY_IN_SECONDS );
    $two = $create( $email, '+1 555 123 4567', 'Synthetic', 'on-hold', 3 * DAY_IN_SECONDS );
    $other = $create( 'other-' . wp_generate_uuid4() . '@example.test', '+1 555 765 4321', 'Other', 'failed', DAY_IN_SECONDS );
    $empty = $create( '', '', '', 'completed', DAY_IN_SECONDS ); $empty->set_customer_ip_address( '' ); $empty->delete_meta_data( '_yoro_device_id' ); $empty->save();
    $run = static function( $action, $params ) { return YoRo_Early_Access_Customer_Service_Read::execute( [ 'action' => $action, 'params' => [ 'limit' => 20, ...$params ] ] ); };
    $profile = $run( 'customers.lookup', [ 'email' => $email ] );
    $assert( ! is_wp_error( $profile ) && 1 === $profile['data']['count'] && 2 === $profile['data']['customers'][0]['orders_count'], 'Exact email produces one bounded guest customer summary.' );
    $assert( '24.60' === $profile['data']['customers'][0]['lifetime_value'] && 'scanned_orders_only' === $profile['data']['customers'][0]['extensions']['yoro.customer_service']['amount_scope'], 'The canonical compatibility amount is explicitly sample-only.' );
    foreach ( [ [ 'email' => strtoupper( $email ) ], [ 'phone' => '+1 555-123-4567' ], [ 'customer_name' => 'synthetic customer' ], [ 'order_id' => (int) $one->get_id() ] ] as $selector ) {
        $r = $run( 'orders.find_by_customer', $selector ); $ids = array_column( $r['data']['orders'] ?? [], 'id' );
        $assert( ! is_wp_error( $r ) && 2 === count( $ids ) && in_array( $one->get_id(), $ids, true ) && in_array( $two->get_id(), $ids, true ), 'Each exact customer selector stays within its customer identity.' );
    }
    $r = $run( 'orders.find_by_customer', [ 'phone' => '5551234567' ] );
    $assert( [] === $r['data']['orders'], 'Phone suffixes do not match another normalized number.' );
    $r = $run( 'orders.find_by_customer', [ 'order_id' => (int) $empty->get_id() ] );
    $assert( [] === $r['data']['orders'], 'A guest reference without contact cannot turn into an unqualified all-customer scan.' );
    $r = $run( 'orders.find_by_identity', [ 'order_id' => (int) $one->get_id() ] ); $ids = array_column( $r['data']['orders'], 'id' );
    $assert( ! in_array( $one->get_id(), $ids, true ) && in_array( $two->get_id(), $ids, true ) && in_array( $other->get_id(), $ids, true ), 'Correlation excludes its seed and may find shared IP/device signals without asserting the same customer.' );
    $r = $run( 'customers.get_service_queue', [] );
    $assert( ! is_wp_error( $r ) && $r['extensions']['yoro.customer_service']['scanned'] <= 100 && count( $r['data']['orders'] ) <= 20, 'The queue stays bounded and read-only.' );
    foreach ( [ $r, $profile, $run( 'orders.find_by_identity', [ 'order_id' => (int) $one->get_id() ] ) ] as $result ) {
        $encoded = wp_json_encode( $result );
        foreach ( [ $email, '+1', 'Synthetic', 'PRIVATE-', '192.0.2.17' ] as $private ) { $assert( false === strpos( $encoded, $private ), 'No contact, name, IP/device value, user agent or note enters the result/journal projection.' ); }
    }
    foreach ( [ [ 'email' => $email, 'customer_id' => 1 ], [ 'customer_id' => 0 ], [ 'phone' => '123' ], [ 'email' => '<script>' ], [ 'customer_name' => 'a' ], [ 'limit' => 21 ] ] as $bad ) {
        $assert( is_wp_error( $run( 'customers.lookup', $bad ) ), 'Invalid, ambiguous and oversized selectors fail closed.' );
    }
    $assert( is_wp_error( $run( 'customers.get_service_queue', [ 'email' => $email ] ) ) && is_wp_error( $run( 'orders.find_by_identity', [ 'email' => $email ] ) ), 'Each action keeps its own selector boundary.' );
    $assert( 'pending' === wc_get_order( $one->get_id() )->get_status() && 'on-hold' === wc_get_order( $two->get_id() )->get_status(), 'The reads do not mutate orders.' );
    $euro = $create( $email, '+1 (555) 123-4567', 'Synthetic', 'completed', DAY_IN_SECONDS, 'EUR' );
    $r = $run( 'customers.lookup', [ 'email' => $email ] );
    $assert( 2 === $r['data']['count'] && [ 'EUR', 'USD' ] === array_column( $r['data']['customers'], 'currency' ), 'A customer with multiple currencies gets separate sample totals, never a mixed-currency sum.' );
    $many_email = 'bounded-' . wp_generate_uuid4() . '@example.test';
    for ( $i = 0; $i < 101; $i++ ) {
        $candidate = new WC_Order(); $candidate->set_status( 'completed' ); $candidate->set_total( '1.00' ); $candidate->set_billing_email( $many_email ); $candidate->save();
    }
    $r = $run( 'orders.find_by_customer', [ 'email' => $many_email, 'limit' => 3 ] );
    $meta = $r['extensions']['yoro.customer_service'];
    $assert( 3 === $r['data']['count'] && 100 === $meta['scanned'] && 100 === $meta['matched_orders'] && $meta['has_more_candidates'] && $meta['returned_truncated'], 'A real 101-order dataset proves the bounded scan and visible limit instead of claiming an exhaustive history.' );
    echo "YoRo customer service bounded selectors, guest isolation, identity correlation and private-free projection passed.\n";
} finally {
    $wpdb->query( 'ROLLBACK' ); wp_cache_flush(); wp_set_current_user( $original ); remove_filter( 'pre_wp_mail', '__return_true' ); remove_filter( 'pre_http_request', $block );
}
