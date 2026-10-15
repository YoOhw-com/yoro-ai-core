<?php
/** Fixture contract: disposable WordPress/WooCommerce only. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Early_Access_Order_Queue_Read' ) ) { YoRo_AI_Core::init(); }
if ( ! class_exists( 'WooCommerce' ) ) { echo "YoRo order queues unavailable without WooCommerce.\n"; return; }
if ( str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'yorohq.com' ) ) { throw new RuntimeException( 'Never run queue fixture contracts on canary or production.' ); }
$assert = static function( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
add_filter( 'pre_wp_mail', '__return_true' );
$original_user = get_current_user_id();
$shipping = get_option( 'woocommerce_ship_to_countries' );
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
    update_option( 'woocommerce_ship_to_countries', '' );
    wp_set_current_user( (int) $admins[0] );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data' ] ] );
    $product = new WC_Product_Simple(); $product->set_name( 'Queue physical fixture' ); $product->set_regular_price( '12.30' ); $product->save();
    $virtual = new WC_Product_Simple(); $virtual->set_name( 'Queue virtual fixture' ); $virtual->set_virtual( true ); $virtual->set_regular_price( '12.30' ); $virtual->save();
    $ids = [];
    foreach ( [ [ 'pending', 25, false ], [ 'on-hold', 49, false ], [ 'processing', 80, false ], [ 'failed', 48, false ],
        [ 'pending', 1, false ], [ 'failed', 200, false ], [ 'completed', 100, false ], [ 'processing', 1, true ] ] as $i => $spec ) {
        $order = new WC_Order(); $order->add_product( $spec[2] ? $virtual : $product, 1 ); $order->set_status( $spec[0] );
        $order->set_currency( 'USD' ); $order->set_total( '12.30' ); $order->set_date_created( time() - $spec[1] * HOUR_IN_SECONDS );
        $order->set_billing_first_name( 'PRIVATE-NAME' ); $order->set_billing_email( 'private-queue@example.com' );
        $order->set_billing_phone( '12025550123' ); $order->set_shipping_address_1( 'PRIVATE-ADDRESS' );
        $order->set_customer_note( 'PRIVATE-NOTE' ); $order->update_meta_data( 'private', 'PRIVATE-META' ); $order->save();
        $ids[] = $order->get_id();
    }
    $before = array_map( static function( $id ) { return wp_json_encode( wc_get_order( $id )->get_data() ); }, $ids );
    $read = static function( $action, $limit = 20 ) { return YoRo_Early_Access_Order_Queue_Read::execute( [ 'action' => $action, 'params' => [ 'limit' => $limit ] ] ); };
    $unsafe_number = static function() { return '12025550123'; }; add_filter( 'woocommerce_order_number', $unsafe_number );
    $attention = $read( 'operations.get_attention_queue' );
    remove_filter( 'woocommerce_order_number', $unsafe_number );
    $found = array_column( $attention['data']['items'], 'order_id' );
    foreach ( array_slice( $ids, 0, 4 ) as $id ) { $assert( in_array( $id, $found, true ), 'Expected aged or recent failed order must be in attention snapshot.' ); }
    foreach ( array_slice( $ids, 4 ) as $id ) { $assert( ! in_array( $id, $found, true ), 'Recent pending, old failure, completed and recent processing must not be attention items.' ); }
    $fulfillment = $read( 'operations.get_fulfillment_queue' );
    $found = array_column( $fulfillment['data']['items'], 'order_id' );
    $assert( in_array( $ids[2], $found, true ) && ! in_array( $ids[7], $found, true ), 'Fulfillment includes shippable processing orders, not virtual orders.' );
    $wire = wp_json_encode( [ $attention, $fulfillment ] );
    foreach ( [ 'PRIVATE-', 'private-queue@example.com', '12025550123' ] as $private ) { $assert( false === strpos( $wire, $private ), 'Queue projection must exclude private data before journaling.' ); }
    foreach ( $attention['data']['items'] as $item ) { $assert( (string) $item['order_id'] === $item['order_number'], 'Custom display number filters are excluded.' ); }
    $limited = $read( 'operations.get_attention_queue', 1 );
    $assert( 1 === $limited['data']['count'] && $limited['extensions']['yoro.order_queue']['returned_truncated'], 'Requested result limit must be explicit.' );
    $assert( [ 'has_next_page' => false, 'next_cursor' => null ] === $limited['data']['page_info'], 'Bounded snapshot cannot advertise unsupported server pages.' );
    update_option( 'woocommerce_ship_to_countries', 'disabled' );
    $disabled = $read( 'operations.get_fulfillment_queue' );
    $assert( 0 === $disabled['data']['count'] && ! $disabled['extensions']['yoro.order_queue']['applicable'], 'Shipping-disabled stores must report not applicable.' );
    update_option( 'woocommerce_ship_to_countries', '' );
    for ( $i = 0; $i < 102; $i++ ) {
        $extra = new WC_Order(); $extra->set_status( 'pending' ); $extra->set_date_created( time() - 30 * HOUR_IN_SECONDS ); $extra->save();
    }
    $bounded = $read( 'operations.get_attention_queue' );
    $assert( 100 === $bounded['extensions']['yoro.order_queue']['scanned'] && $bounded['extensions']['yoro.order_queue']['has_more_candidates'], 'Candidate scan stays bounded and discloses omitted candidates.' );
    $assert( count( $bounded['data']['items'] ) <= 20 && $bounded['data']['total_matches'] <= 100, 'Counts cover scanned sample only.' );
    foreach ( [ [ 'orders.update_status', [ 'limit' => 10 ] ], [ 'operations.get_attention_queue', [ 'limit' => '10' ] ],
        [ 'operations.get_attention_queue', [ 'limit' => 21 ] ], [ 'operations.get_fulfillment_queue', [ 'limit' => 10, 'email' => 'private' ] ] ] as $bad ) {
        $assert( ! YoRo_Early_Access_Order_Queue_Read::valid_params( $bad[0], $bad[1] ), 'Writes and widened queue selectors must fail closed.' );
    }
    wp_set_current_user( 0 );
    $assert( is_wp_error( $read( 'operations.get_attention_queue' ) ), 'Unauthenticated callers cannot execute queues.' );
    wp_set_current_user( (int) $admins[0] );
    $after = array_map( static function( $id ) { return wp_json_encode( wc_get_order( $id )->get_data() ); }, $ids );
    $assert( $before === $after, 'Queue reads do not mutate order data.' );
    echo "YoRo bounded attention/fulfillment queue, privacy, shipping applicability and no-write contracts passed.\n";
} finally { update_option( 'woocommerce_ship_to_countries', $shipping ); $wpdb->query( 'ROLLBACK' ); wp_set_current_user( $original_user ); wp_cache_flush(); }
