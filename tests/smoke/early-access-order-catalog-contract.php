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
    $account_id = wp_create_user( 'yr0178-order-buyer', wp_generate_password(), 'buyer@example.invalid' );
    $assert( ! is_wp_error( $account_id ), 'The disposable customer fixture must be created.' );
    $selected = wc_get_order( $ids[0] ); $selected->set_customer_id( $account_id );
    $selected->set_shipping_first_name( 'Ada' ); $selected->set_shipping_last_name( 'Buyer' ); $selected->set_shipping_address_1( '123 Example Street' ); $selected->set_shipping_phone( '+12025550199' );
    $shipping = new WC_Order_Item_Shipping(); $shipping->set_method_title( 'Flat rate' ); $shipping->set_method_id( 'flat_rate' ); $shipping->set_total( '5' ); $selected->add_item( $shipping );
    $fee = new WC_Order_Item_Fee(); $fee->set_name( 'Handling' ); $fee->set_amount( '2' ); $fee->set_total( '2' ); $selected->add_item( $fee ); $selected->calculate_totals( false ); $selected->save();
    for ( $i = 0; $i < 22; $i++ ) { $selected->add_order_note( 'STAFF-NOTE-' . $i, false, true ); }
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
    $enhanced_read = static function( $action, $params, $page = 1 ) { return YoRo_Early_Access_Order_Read::execute( [ 'action' => $action, 'params' => $params,
        'extensions' => [ 'yoro.mcp_early_access_product_read' => [ 'read_family' => 'orders', 'read_contract_version' => 1, 'order_projection_version' => 1,
            ...( 'orders.get_timeline' === $action ? [ 'notes_page' => $page ] : [] ) ] ] ] ); };
    $rich = $enhanced_read( 'orders.get_details', [ 'order_ids' => $ids ] );
    $extra = $rich['data']['orders'][0]['extensions']['yoro.order_details'];
    $assert( 'yr0178-order-buyer' === $extra['account']['username'] && $account_id === $extra['account']['id'], 'Selected details must expose only the linked account username and ID.' );
    $assert( 'private-order@example.com' === $extra['billing']['email'] && '+12025550123' === $extra['billing']['phone'], 'Selected billing contacts must be included when negotiated.' );
    $assert( '+12025550199' === $extra['shipping']['phone'] && '' === $extra['shipping']['email'], 'Shipping contact values must not be invented from billing.' );
    $assert( 5.0 === (float) $extra['totals']['shipping'] && 'Handling' === $extra['totals']['fees'][0]['name'], 'Shipping and fee lines must come from order CRUD.' );
    $assert( null === $rich['data']['orders'][1]['extensions']['yoro.order_details']['account'], 'Guest orders must not inherit another account.' );
    $assert( ! str_contains( wp_json_encode( $rich ), 'PRIVATE-META' ), 'Private custom metadata must remain excluded.' );
    $notes1 = $enhanced_read( 'orders.get_timeline', [ 'order_id' => $ids[0], 'limit' => 20 ] );
    $notes2 = $enhanced_read( 'orders.get_timeline', [ 'order_id' => $ids[0], 'limit' => 20 ], 2 );
    $note_ids = static function( $result ) { return array_column( array_filter( $result['data']['events'], static function( $event ) { return 'note' === $event['type']; } ), 'id' ); };
    $assert( 20 === count( $note_ids( $notes1 ) ) && $notes1['extensions']['yoro.order_notes']['has_next_page'] && '2' === $notes1['extensions']['yoro.order_notes']['next_cursor'], 'Notes must use bounded pages.' );
    $assert( count( $note_ids( $notes2 ) ) >= 2 && ! array_intersect( $note_ids( $notes1 ), $note_ids( $notes2 ) ), 'Stable note pages must not repeat notes.' );
    $assert( false === strpos( wp_json_encode( $first ), 'private-order@example.com' ) && false === strpos( wp_json_encode( $first ), 'STAFF-NOTE-' ), 'Search summaries cannot inherit detail contacts or notes.' );
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
