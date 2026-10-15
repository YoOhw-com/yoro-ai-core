<?php
/** Fixture contract: disposable WordPress/WooCommerce only. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Early_Access_Order_Tracking_Read' ) ) { YoRo_AI_Core::init(); }
if ( ! class_exists( 'WooCommerce' ) ) { echo "YoRo tracking reads unavailable without WooCommerce.\n"; return; }
if ( str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'yorohq.com' ) ) { throw new RuntimeException( 'Never run tracking fixtures on canary or production.' ); }
$assert = static function( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
add_filter( 'pre_wp_mail', '__return_true' );
$original_user = get_current_user_id();
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
    wp_set_current_user( (int) $admins[0] );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data' ] ] );
    $order = new WC_Order(); $order->set_status( 'processing' ); $order->set_currency( 'USD' ); $order->set_total( '12.30' );
    $order->set_billing_email( 'private-tracking@example.com' ); $order->set_billing_phone( '+1 202 555 0123' ); $order->set_shipping_phone( '+1 202 555 0456' );
    $order->set_shipping_address_1( 'PRIVATE-ADDRESS' ); $order->set_customer_note( 'PRIVATE-NOTE' );
    $records = [
        [ 'tracking_number' => '1Z999AA10123456784', 'tracking_provider' => 'ups', 'custom_tracking_link' => 'https://private.example/PRIVATE-URL' ],
        [ 'tracking_number' => '1Z999AA10123456784', 'tracking_provider' => 'PRIVATE-CARRIER' ],
        [ 'tracking_number' => 'AB12345678', 'tracking_provider' => 'PRIVATE-CARRIER', 'custom_tracking_provider' => 'PRIVATE-CUSTOM' ],
        [ 'tracking_number' => 'private-tracking@example.com' ], [ 'tracking_number' => 'https://private.example/1234' ],
        [ 'tracking_number' => '<script>12345</script>' ], [ 'tracking_number' => "123456\n" ], [ 'tracking_number' => 'А123456' ],
        [ 'tracking_number' => '12025550123' ], [ 'tracking_number' => '12025550456' ], [ 'tracking_number' => 'ABCDEF' ],
    ];
    $order->update_meta_data( '_wc_shipment_tracking_items', $records );
    $order->update_meta_data( '_aftership_tracking_number', 'FS12345678' ); $order->save();
    $read = static function( $id ) {
        $deny_http = static function() { throw new RuntimeException( 'Tracking reads cannot request an external provider.' ); };
        add_filter( 'pre_http_request', $deny_http );
        try { return YoRo_Early_Access_Order_Tracking_Read::execute( [ 'action' => 'fulfillment.get_tracking', 'params' => [ 'order_id' => $id ] ] ); }
        finally { remove_filter( 'pre_http_request', $deny_http ); }
    };
    $before = wp_json_encode( wc_get_order( $order->get_id() )->get_data() );
    $result = $read( $order->get_id() );
    $assert( 3 === $result['data']['count'], 'Only distinct supported codes and fallback records may be returned.' );
    $assert( 'UPS' === $result['data']['shipments'][0]['carrier'] && 'Not provided' === $result['data']['shipments'][1]['carrier'], 'Carrier labels must be fixed.' );
    foreach ( $result['data']['shipments'] as $row ) { $assert( '' === $row['tracking_url'] && 'unverified' === $row['status'] && [] === (array) $row['extensions'], 'No custom URL, free status or metadata crosses the projection.' ); }
    $wire = wp_json_encode( $result );
    foreach ( [ 'PRIVATE-', 'private-tracking@example.com', '12025550123', '12025550456', 'https:', '<script>' ] as $private ) { $assert( false === strpos( $wire, $private ), 'Customer content and custom links must not enter tracking results.' ); }
    $assert( $before === wp_json_encode( wc_get_order( $order->get_id() )->get_data() ), 'Tracking reads must not mutate order data.' );
    $order->update_meta_data( '_wc_shipment_tracking_items', array_map( static function( $i ) { return [ 'tracking_number' => 'TRACK' . str_pad( (string) $i, 6, '0', STR_PAD_LEFT ) ]; }, range( 1, 25 ) ) ); $order->save();
    $limited = $read( $order->get_id() );
    $assert( 5 === $limited['data']['count'] && $limited['extensions']['yoro.order_tracking']['truncated'], 'Tracking sources and output must remain bounded.' );
    $order->update_meta_data( '_wc_shipment_tracking_items', [] );
    foreach ( YoRo_Early_Access_Order_Tracking_Read::FALLBACK_KEYS as $key ) { $order->delete_meta_data( $key ); } $order->save();
    $empty = $read( $order->get_id() );
    $assert( 0 === $empty['data']['count'] && 'unavailable' === $empty['data']['status'], 'No supported records is not dispatch/delivery verification.' );
    $order->update_meta_data( '_wc_shipment_tracking_items', [ [ 'tracking_number' => 'a123456' ], [ 'tracking_number' => 'A123456' ] ] ); $order->save();
    $case = $read( $order->get_id() );
    $assert( [ 'a123456', 'A123456' ] === array_column( $case['data']['shipments'], 'tracking_number' ), 'Stored code case must be preserved, not guessed.' );
    $assert( is_wp_error( $read( PHP_INT_MAX ) ), 'Missing orders must fail closed.' );
    $refund = new WC_Order_Refund(); $refund->set_parent_id( $order->get_id() ); $refund->set_amount( 0 ); $refund->save();
    $assert( is_wp_error( $read( $refund->get_id() ) ), 'Refund records cannot impersonate an order.' );
    foreach ( [ [ 'orders.update_status', [ 'order_id' => 1 ] ], [ 'fulfillment.get_tracking', [ 'order_id' => '1' ] ],
        [ 'fulfillment.get_tracking', [ 'order_id' => 0 ] ], [ 'fulfillment.get_tracking', [ 'order_id' => 1, 'url' => 'https://private.example' ] ] ] as $bad ) {
        $assert( ! YoRo_Early_Access_Order_Tracking_Read::valid_params( $bad[0], $bad[1] ), 'Tracking selectors are exact and read-only.' );
    }
    $executor = new ReflectionMethod( 'YoRo_Connector_Runtime', 'expanded_read_executor' );
    foreach ( [ false, 0, '', [], (object) [] ] as $family ) { $assert( null === $executor->invoke( null, $family ), 'Unknown or non-string families must fail closed without loose PHP comparison.' ); }
    wp_set_current_user( 0 );
    $assert( is_wp_error( $read( $order->get_id() ) ), 'Unauthenticated actors cannot read tracking.' );
    echo "YoRo bounded stored tracking, privacy, no links, no status inference and no-write contracts passed.\n";
} finally { $wpdb->query( 'ROLLBACK' ); wp_set_current_user( $original_user ); wp_cache_flush(); }
