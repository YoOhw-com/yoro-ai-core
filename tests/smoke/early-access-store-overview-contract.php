<?php
/** Disposable WordPress/WooCommerce fixtures only. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Early_Access_Store_Overview_Read' ) ) { YoRo_AI_Core::init(); }
if ( ! class_exists( 'WooCommerce' ) ) { echo "YoRo store overview unavailable without WooCommerce.\n"; return; }
if ( str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'yorohq.com' ) ) { throw new RuntimeException( 'Never run overview fixtures on canary or production.' ); }
$assert = static function( $ok, $why ) { if ( ! $ok ) { throw new RuntimeException( $why ); } };
$original = get_current_user_id(); $timezone = get_option( 'timezone_string' ); $currency = get_option( 'woocommerce_currency' ); $coupons_enabled = get_option( 'woocommerce_enable_coupons' );
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
add_filter( 'pre_wp_mail', '__return_true' );
global $wpdb; $wpdb->query( 'START TRANSACTION' );
try {
    wp_set_current_user( (int) $admins[0] );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data' ] ] );
    update_option( 'timezone_string', 'UTC' ); update_option( 'woocommerce_currency', 'USD' ); update_option( 'woocommerce_enable_coupons', 'yes' );
    $make = static function( $amount, $code, $status = 'processing', $stamp = null ) {
        $order = new WC_Order(); $order->set_status( $status ); $order->set_currency( $code ); $order->set_total( $amount ); $order->set_date_created( $stamp ?? time() - 10 );
        $order->set_billing_email( 'PRIVATE-EMAIL@example.com' ); $order->set_billing_phone( '12025550123' ); $order->set_customer_note( 'PRIVATE-NOTE' ); $order->save(); return $order;
    };
    $a = $make( '10', 'USD' ); $b = $make( '20', 'USD', 'completed' ); $foreign = $make( '999', 'EUR' ); $prior = $make( '5', 'USD', 'processing', time() - DAY_IN_SECONDS - 10 );
    $make( '7', 'USD', 'failed' );
    $product = new WC_Product_Simple(); $product->set_name( 'Overview shipping fixture' ); $product->set_virtual( false ); $product->save();
    $a->add_product( $product, 1 ); $prior->add_product( $product, 1 );
    $read = static function( $action, $params ) {
        $block = static function() { throw new RuntimeException( 'Overview reads cannot request a remote provider.' ); };
        add_filter( 'pre_http_request', $block );
        try { return YoRo_Early_Access_Store_Overview_Read::execute( [ 'action' => $action, 'params' => $params ] ); }
        finally { remove_filter( 'pre_http_request', $block ); }
    };
    $before = wp_json_encode( wc_get_order( $a->get_id() )->get_data() );
    $item_reads = []; $product_reads = [];
    $observe_items = static function( $items, $order ) use ( &$item_reads ) { $item_reads[] = $order->get_id(); return $items; };
    $observe_products = static function( $product, $item ) use ( &$product_reads ) { $product_reads[] = $item->get_order_id(); return $product; };
    add_filter( 'wc_shipping_enabled', '__return_true' );
    add_filter( 'woocommerce_order_get_items', $observe_items, 10, 2 );
    add_filter( 'woocommerce_order_item_product', $observe_products, 10, 2 );
    try {
        foreach ( array_slice( YoRo_Early_Access_Store_Overview_Read::ACTIONS, 0, 4 ) as $action ) {
            $item_reads = []; $product_reads = [];
            $r = $read( $action, [ 'window' => 'today' ] );
            $assert( true === $r['success'], 'All four report actions return bounded aggregate snapshots.' );
            $assert( 2 === $r['data']['metrics']['order_count'] && '30.000000' === $r['data']['metrics']['gross_sales'] && '15.000000' === $r['data']['metrics']['average_order_value'], 'Money aggregates use only processing/completed store-currency orders.' );
            $m = $r['extensions']['yoro.store_overview'];
            $assert( true === $r['data']['metrics']['is_complete'] && 0 === $m['current']['other_currency_orders'] && true === $m['comparison_complete'] && isset($m['full_report']['current']['currencies']['EUR']), 'Complete reports retain all currencies separately.' );
            $assert( [ 'customer_followups', 'inventory' ] === $m['not_assessed'] && 0 === $r['data']['counts']['customer_followups'] && 0 === $r['data']['counts']['inventory'], 'Frozen wire placeholders have mandatory not-assessed provenance.' );
            $assert( [] === $r['data']['attention_items'] && [] === $r['data']['inventory_items'], 'No customer or product records enter aggregate results.' );
            $assert( false === strpos( wp_json_encode( $r ), 'PRIVATE-' ) && false === strpos( wp_json_encode( $r ), '12025550123' ), 'Customer content is excluded before journaling.' );
            $assert( 2 === $r['data']['counts']['fulfillment'] && 1 === $r['data']['counts']['attention'], 'Current backlog includes physical unfulfilled orders from both current and older creation dates.' );
            $assert( 1 === $r['data']['comparison']['metrics']['order_count']['previous'] && '5.000000' === $r['data']['comparison']['metrics']['gross_sales']['previous']
                && '5.000000' === $r['data']['comparison']['metrics']['average_order_value']['previous'] && 1 === $m['previous']['status_counts']['processing'], 'Previous processing orders retain all monetary comparison and status metrics without item reads.' );
        }
    } finally {
        remove_filter( 'woocommerce_order_get_items', $observe_items, 10 ); remove_filter( 'woocommerce_order_item_product', $observe_products, 10 );
        remove_filter( 'wc_shipping_enabled', '__return_true' );
    }
    $assert( $before === wp_json_encode( wc_get_order( $a->get_id() )->get_data() ), 'Overview reads do not change orders.' );
    update_option( 'timezone_string', 'America/New_York' );
    [ $w, $p ] = YoRo_Early_Access_Store_Overview_Read::windows( 'today', strtotime( '2026-03-08T18:00:00Z' ) );
    $assert( '2026-03-08T00:00:00-05:00' === $w['start'] && '2026-03-08T14:00:00-04:00' === $w['end'] && '2026-03-07T14:00:00-05:00' === $p['end'], 'Calendar comparison honors DST and local clock boundaries.' );
    update_option( 'timezone_string', 'UTC' );
    $coupon = new WC_Coupon(); $coupon->set_code( 'yr0178save10' ); $coupon->set_discount_type( 'percent' ); $coupon->set_amount( '10' );
    $coupon->set_description( 'PRIVATE-DESCRIPTION<script>bad()</script>' ); $coupon->set_email_restrictions( [ 'private@example.com' ] ); $coupon->set_usage_limit( 10 ); $coupon->save();
    $r = $read( 'coupons.lookup', [ 'coupon_code' => 'yr0178save10' ] );
    $assert( 'supported' === $r['data']['availability'] && '10.000000' === $r['data']['coupon']['discount_amount'] && $r['data']['coupon']['email_restricted'], 'Exact coupon reads return supported fixed settings and restriction flags.' );
    $assert( false === $r['extensions']['yoro.store_overview']['eligibility_verified'] && false === strpos( wp_json_encode( $r ), 'PRIVATE-' ) && false === strpos( wp_json_encode( $r ), 'private@example.com' ), 'Coupon eligibility and private restrictions are not exposed or inferred.' );
    $assert( 'not_found' === $read( 'coupons.lookup', [ 'coupon_code' => 'yr0178missing' ] )['data']['availability'], 'Missing exact coupon is an explicit empty result.' );
    update_option( 'woocommerce_enable_coupons', 'no' );
    $assert( 'disabled' === $read( 'coupons.lookup', [ 'coupon_code' => 'yr0178save10' ] )['data']['availability'], 'Disabled coupons do not report redeemability.' );
    $newest = $make( '1000', 'USD', 'processing', time() - 1 );
    for ( $i = 0; $i < 101; $i++ ) { $backdated = $make( '1', 'USD', 'pending', time() - 60 ); }
    $assert( $newest->get_id() < $backdated->get_id(), 'Imported older orders have newer IDs at the sample cutoff.' );
    $r = $read( 'store.get_insights', [ 'window' => 'today' ] );
    $assert( $r['extensions']['yoro.store_overview']['current']['scanned'] > 100 && false === $r['extensions']['yoro.store_overview']['current']['has_more'] && true === $r['data']['metrics']['is_complete'], 'All matching period orders are aggregated beyond the former sample limit.' );
    $assert( 3 === $r['data']['metrics']['order_count'] && '1030.000000' === $r['data']['metrics']['gross_sales'], 'Creation date, rather than ID, determines which orders survive the sample cutoff.' );
    foreach ( [ [ 'orders.update_status', [ 'window' => 'today' ] ], [ 'operations.get_summary', [ 'window' => 'all_time' ] ], [ 'store.get_insights', [ 'window' => 'today', 'include_pii' => true ] ],
        [ 'coupons.lookup', [ 'coupon_code' => 'SAVE10' ] ], [ 'coupons.lookup', [ 'coupon_code' => 'private@example.com' ] ] ] as $bad ) {
        $assert( ! YoRo_Early_Access_Store_Overview_Read::valid_params( $bad[0], $bad[1] ), 'Only exact normalized read selectors are admitted.' );
    }
    wp_set_current_user( 0 );
    $assert( is_wp_error( $read( 'operations.get_summary', [ 'window' => 'today' ] ) ), 'Unauthenticated actors cannot read reports.' );
    echo "YoRo five-action overview: bounded money/status snapshots, DST, privacy, coupon settings and no-write contracts passed.\n";
} finally {
    $wpdb->query( 'ROLLBACK' ); wp_set_current_user( $original ); wp_cache_flush();
    update_option( 'timezone_string', $timezone ); update_option( 'woocommerce_currency', $currency ); update_option( 'woocommerce_enable_coupons', $coupons_enabled );
}
