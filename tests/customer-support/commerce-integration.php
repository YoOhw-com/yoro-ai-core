<?php
/** Native Woo engines and persistence, only inside the socket-only fresh runner. */
$root = realpath( $argv[1] ?? '' );
if ( ! $root || is_link( $argv[1] ) || 'YR-0214-disposable-wordpress' !== @file_get_contents( $root . '/.support-disposable' ) ) { throw new RuntimeException( 'Fresh disposable fixture required.' ); }
$GLOBALS['support_mail_effects'] = 0;
function wp_mail( ...$args ) { ++$GLOBALS['support_mail_effects']; return true; }
$full_core = 'full-commerce' === ( $argv[2] ?? '' );
if ( $full_core ) {
    define( 'YORO_AI_API_SITE_KEY', 'synthetic-transport-key' );
    define( 'YORO_AI_API_URL', 'https://support-api-fixture.invalid/api/v1' );
}
require $root . '/wp-load.php';
if ( 'yoro_support_disposable' !== DB_NAME || 'https://support-fixture.invalid' !== home_url() || ! DISABLE_WP_CRON ) { throw new RuntimeException( 'Disposable DB/site required.' ); }
WC_Install::install();
if ( ! $full_core ) {
    class YoRo_AI_Settings { public static function get_ai_api_site_key() { return 'synthetic-transport-key'; } public static function get_ai_api_url() { return 'https://support-api-fixture.invalid/api/v1'; } }
}
if ( ! $full_core ) {
    require_once dirname( __DIR__, 2 ) . '/includes/ai/clients/class-yoro-ai-api-endpoint-resolver.php';
    require_once dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-support-transport.php';
    require_once dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-support-facts.php';
    require_once dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-support-context.php';
    require_once dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-customer-support.php';
    require_once dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-support-commerce.php';
}
$count = 0;
function verify( $condition, $reason ) { global $count; ++$count; if ( ! $condition ) { throw new RuntimeException( $reason ); } }
function valid_result( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
function readonly_quote( array $params, array $authority ) {
    global $wpdb, $report_hooks, $subscription_hooks;
    $before = count( $wpdb->queries );
    $persistence_hooks = array_merge( $report_hooks ?? [], $subscription_hooks ?? [] );
    $actions = array_map( 'did_action', array_keys( $persistence_hooks ) );
    $generation = get_option( '_yoro_complete_report_generation_v2' );
    $mutation = get_option( '_yoro_complete_report_mutation_v2' );
    $result = YoRo_Support_Commerce::quote( $params, $authority );
    if ( $report_hooks ?? [] ) {
        verify( $actions === array_map( 'did_action', array_keys( $persistence_hooks ) ), 'Read-only quote invokes no report or subscription persistence hook.' );
        verify( $generation === get_option( '_yoro_complete_report_generation_v2' ) && $mutation === get_option( '_yoro_complete_report_mutation_v2' ), 'Read-only quote does not invalidate report generations.' );
    }
    $prefix = preg_quote( $wpdb->prefix, '/' );
    foreach ( array_slice( $wpdb->queries, $before ) as $query ) {
        $sql = $query[0];
        if ( preg_match( '/^\\s*(?:INSERT|UPDATE|DELETE|REPLACE)\\b/i', $sql )
            && preg_match( '/`?' . $prefix . '(?:posts|postmeta|woocommerce_sessions|wc_orders(?:_meta)?|wc_order_(?:addresses|operational_data|stats|product_lookup|tax_lookup|coupon_lookup)|woocommerce_order_(?:items|itemmeta))`?\\b/i', $sql ) ) {
            throw new RuntimeException( 'Quote attempted a protected commerce/session SQL mutation.' );
        }
    }
    verify( true, 'Actual query trace contains no protected commerce/session write.' );
    return $result;
}
function source_product( $name, $price = '100' ) { $p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_status( 'publish' ); $p->set_regular_price( $price ); $p->set_tax_status( 'taxable' ); $p->set_manage_stock( true ); $p->set_stock_quantity( 20 ); $p->save(); return $p; }
$report_hooks = [];
$subscription_hooks = [];
if ( $full_core ) {
    verify( defined( 'YORO_AI_CORE_FILE' ) && class_exists( 'YoRo_AI_Core', false ) && class_exists( 'YoRo_Complete_Store_Report', false ), 'Actual full Core is loaded through the WordPress active plugin bootstrap.' );
    verify( realpath( YORO_AI_CORE_FILE ) === realpath( $root . '/wp-content/plugins/yoro-ai-core/yoro-ai-core.php' ) && did_action( 'init' ) === 1, 'Full plugin entrypoint and normal WordPress init ran.' );
    verify( ( new ReflectionClass( 'YoRo_AI_Settings' ) )->getFileName() === YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-settings.php', 'Full Core settings are real, not the support-only replacement.' );
    foreach ( $GLOBALS['wp_filter'] as $name => $hook ) {
        if ( 0 !== strpos( $name, 'woocommerce_' ) ) { continue; }
        foreach ( $hook->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $entry ) {
                if ( ! $entry['function'] instanceof Closure ) { continue; }
                $reflection = new ReflectionFunction( $entry['function'] );
                $scope = $reflection->getClosureScopeClass();
                if ( $scope && 'YoRo_Complete_Store_Report' === $scope->getName() ) { $report_hooks[ $name ] = [ $entry['function'], $priority, $entry['accepted_args'] ]; }
                if ( $scope && 'YoRo_Subscriptions_Read' === $scope->getName() ) { $subscription_hooks[ $name ] = [ $entry['function'], $priority, $entry['accepted_args'] ]; }
            }
        }
    }
    verify( 13 === count( $report_hooks ), 'All thirteen full Core report Woo callbacks remain registered.' );
    verify( 5 === count( $subscription_hooks ), 'All five full Core subscription persistence callbacks remain registered.' );
    $before_profile = array_map( 'did_action', array_keys( array_merge( $report_hooks, $subscription_hooks ) ) );
    valid_result( YoRo_Support_Commerce::profile() );
    verify( $before_profile === array_map( 'did_action', array_keys( array_merge( $report_hooks, $subscription_hooks ) ) ), 'Profile inspection never invokes report or subscription callbacks.' );
    $report = reset( $report_hooks )[0];
    add_filter( 'woocommerce_before_calculate_totals', $report, 10, 1 );
    verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Even a real report callback is unsupported on a quote hook.' );
    remove_filter( 'woocommerce_before_calculate_totals', $report, 10 );
    $spoof = Closure::bind( static function() {}, null, 'YoRo_Complete_Store_Report' );
    add_filter( 'all', $spoof, 10, 0 );
    verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'An unknown all-hook callback remains unsupported.' );
    remove_filter( 'all', $spoof, 10 );
    add_action( 'woocommerce_before_product_object_save', $spoof, 10, 0 );
    verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'A foreign callback with a spoofed report class scope is denied.' );
    remove_action( 'woocommerce_before_product_object_save', $spoof, 10 );
    $order_report = $report_hooks['woocommerce_before_order_object_save'][0];
    foreach ( [ [ 9, 1 ], [ 10, 2 ] ] as $binding ) {
        add_action( 'woocommerce_before_order_object_save', $order_report, $binding[0], $binding[1] );
        verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Unexpected report priority or accepted-argument binding fails closed.' );
        remove_action( 'woocommerce_before_order_object_save', $order_report, $binding[0] );
        add_action( 'woocommerce_before_order_object_save', $order_report, 10, 1 );
    }
    add_action( 'woocommerce_before_product_object_save', [ 'YoRo_Complete_Store_Report', 'invalidate' ], 10, 0 );
    verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Unregistered methods in the same Core file are not directory-trusted.' );
    remove_action( 'woocommerce_before_product_object_save', [ 'YoRo_Complete_Store_Report', 'invalidate' ], 10 );
    $subscription = $subscription_hooks['woocommerce_before_subscription_object_save'][0];
    // Installing a real invalidator on all would itself recurse through WP_Error actions.
    $subscription_guard = new ReflectionMethod( 'YoRo_Support_Commerce', 'subscription_mutation_callback' );
    verify( false === $subscription_guard->invoke( null, 'all', 10, [ 'function' => $subscription, 'accepted_args' => 0 ] ), 'A real subscription callback cannot be admitted on all.' );
    add_action( 'woocommerce_before_calculate_totals', $subscription, 10, 0 );
    verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'A real subscription callback on a quote hook remains unsupported.' );
    remove_action( 'woocommerce_before_calculate_totals', $subscription, 10 );
    $spoof_subscription = Closure::bind( static function() {}, null, 'YoRo_Subscriptions_Read' );
    add_action( 'woocommerce_before_subscription_object_save', $spoof_subscription, 10, 0 );
    verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'A foreign callback with spoofed subscription scope remains unsupported.' );
    remove_action( 'woocommerce_before_subscription_object_save', $spoof_subscription, 10 );
    foreach ( [ [ 9, 0 ], [ 10, 1 ] ] as $binding ) {
        add_action( 'woocommerce_before_subscription_object_save', $subscription, $binding[0], $binding[1] );
        verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Unexpected subscription priority or accepted arguments fail closed.' );
        remove_action( 'woocommerce_before_subscription_object_save', $subscription, $binding[0] );
        add_action( 'woocommerce_before_subscription_object_save', $subscription, 10, 0 );
    }
    add_action( 'woocommerce_before_subscription_object_save', [ 'YoRo_Subscriptions_Read', 'manifest' ], 10, 0 );
    verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'A method in the subscription file is not directory-trusted.' );
    remove_action( 'woocommerce_before_subscription_object_save', [ 'YoRo_Subscriptions_Read', 'manifest' ], 10 );
    valid_result( YoRo_Support_Commerce::profile() );
}
update_option( 'woocommerce_currency', 'USD' ); update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_prices_include_tax', 'no' ); update_option( 'woocommerce_tax_based_on', 'shipping' ); update_option( 'woocommerce_default_customer_address', 'base' );
$zone = new WC_Shipping_Zone(); $zone->set_zone_name( 'Synthetic California' ); $zone->add_location( 'US', 'country' ); $zone->save();
$rate_id = $zone->add_shipping_method( 'flat_rate' ); update_option( 'woocommerce_flat_rate_' . $rate_id . '_settings', [ 'title' => 'Standard', 'tax_status' => 'taxable', 'cost' => '10' ] );
$second_id = $zone->add_shipping_method( 'flat_rate' ); update_option( 'woocommerce_flat_rate_' . $second_id . '_settings', [ 'title' => 'Express', 'tax_status' => 'taxable', 'cost' => '20' ] );
$tax_id = WC_Tax::_insert_tax_rate( [ 'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => '10.0000', 'tax_rate_name' => 'Synthetic tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ] );
update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
update_option( 'woocommerce_cod_settings', [ 'enabled' => 'yes', 'title' => 'Cash on delivery', 'enable_for_methods' => [ 'flat_rate:' . $second_id ] ] );
$p = source_product( 'Native quote product' );
$coupon = new WC_Coupon(); $coupon->set_code( 'synthetic-ten' ); $coupon->set_discount_type( 'percent' ); $coupon->set_amount( '10' ); $coupon->save();
$restricted = new WC_Coupon(); $restricted->set_code( 'synthetic-email' ); $restricted->set_discount_type( 'fixed_cart' ); $restricted->set_amount( '15' ); $restricted->set_email_restrictions( [ 'buyer@example.test' ] ); $restricted->set_usage_limit_per_user( 1 ); $restricted->save();
$params = [ 'items' => [ [ 'product_id' => $p->get_id(), 'quantity' => 2 ] ], 'destination' => [ 'country' => 'US', 'state' => 'CA', 'postcode' => '94105', 'city' => '' ], 'quoted_at' => '2026-11-01T00:00:00Z' ];
$original = [ WC()->cart, WC()->customer, WC()->session ];
$before = [ 'stock' => wc_get_product( $p->get_id() )->get_stock_quantity(), 'coupon' => $coupon->get_usage_count(), 'session_rows' => $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'woocommerce_sessions' ), 'mail' => $GLOBALS['support_mail_effects'], 'order_rows' => $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->posts . " WHERE post_type='shop_order'" ) ];
$profile = YoRo_Support_Commerce::profile(); valid_result( $profile );
$quote = valid_result( readonly_quote( $params, [] ) );
verify( '200.00' === $quote['data']['subtotal'], 'Native cart subtotal.' );
verify( '10.00' === $quote['data']['shipping'], 'Native shipping engine selected Standard.' );
verify( '21.00' === $quote['data']['tax'] && '231.00' === $quote['data']['total'], 'Native cart totals includes product/shipping taxes.' );
verify( 2 === count( $quote['data']['shipping_rates'] ) && 'Bank transfer' === $quote['data']['payment_methods'][0], 'Native available rates and payment methods.' );
$discounted = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-ten' ], [] ) );
verify( '20.00' === $discounted['data']['discount'] && '209.00' === $discounted['data']['total'], 'Native WC_Discounts and WC_Cart_Totals, no manual arithmetic.' );
$express = valid_result( readonly_quote( $params + [ 'shipping_rates' => [ 'flat_rate:' . $second_id ] ], [] ) );
verify( ! in_array( 'Cash on delivery', $quote['data']['payment_methods'], true ) && in_array( 'Cash on delivery', $express['data']['payment_methods'], true ), 'Native COD eligibility follows selected shipping method.' );
verify( '20.00' === $express['data']['shipping'] && '242.00' === $express['data']['total'], 'Native explicit alternate rate is context-bound.' );
// Coupon-driven shipping must use the final native rate set, not a temporary pre-coupon default.
$conditional_free = $zone->add_shipping_method( 'free_shipping' );
update_option( 'woocommerce_free_shipping_' . $conditional_free . '_settings', [ 'title' => 'Coupon free shipping', 'requires' => 'coupon' ] );
$shipping_coupon = new WC_Coupon(); $shipping_coupon->set_code( 'synthetic-free-shipping' ); $shipping_coupon->set_discount_type( 'fixed_cart' ); $shipping_coupon->set_amount( '0' ); $shipping_coupon->set_free_shipping( true ); $shipping_coupon->save();
$original_hide = get_option( 'woocommerce_shipping_hide_rates_when_free', 'no' );
try {
    foreach ( [ 'no', 'yes' ] as $hide ) {
        update_option( 'woocommerce_shipping_hide_rates_when_free', $hide );
        $free_params = $params + [ 'coupon' => $shipping_coupon->get_code() ];
        $free_quote = valid_result( readonly_quote( $free_params, [] ) );
        $free_rate = 'free_shipping:' . $conditional_free;
        verify( '0.00' === $free_quote['data']['shipping'] && '220.00' === $free_quote['data']['total'], 'Coupon free shipping has native zero shipping and exact taxed total, with paid rates hidden or visible.' );
        verify( [ $free_rate ] === array_column( array_filter( $free_quote['data']['shipping_rates'], static fn( $rate ) => $rate['selected'] ), 'id' ), 'No explicit selection lets the native coupon-aware default select free shipping.' );
        $explicit_free = valid_result( readonly_quote( $free_params + [ 'shipping_rates' => [ $free_rate ] ], [] ) );
        verify( '0.00' === $explicit_free['data']['shipping'], 'Explicit free rate from a previous coupon quote survives the temporary pre-coupon calculation.' );
        $explicit_paid = readonly_quote( $free_params + [ 'shipping_rates' => [ 'flat_rate:' . $second_id ] ], [] );
        verify( 'yes' === $hide ? is_wp_error( $explicit_paid ) : ! is_wp_error( $explicit_paid ) && '20.00' === $explicit_paid['data']['shipping'], 'Valid explicit paid choice is retained; a rate removed by hide-paid fails closed.' );
        $stale = readonly_quote( $params + [ 'shipping_rates' => [ $free_rate ] ], [] );
        verify( is_wp_error( $stale ), 'A coupon-only explicit free rate cannot survive without the coupon.' );
    }
} finally {
    update_option( 'woocommerce_shipping_hide_rates_when_free', $original_hide ); $zone->delete_shipping_method( $conditional_free ); $shipping_coupon->delete( true );
}

$needs_email = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-email' ], [] ) );
verify( 'verification_required' === $needs_email['data']['coupon']['state'] && '0.00' === $needs_email['data']['discount'], 'Unverified email restriction cannot be assumed.' );
$email_coupon = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-email' ], [ 'email' => 'buyer@example.test' ] ) );
verify( 'valid' === $email_coupon['data']['coupon']['state'] && '15.00' === $email_coupon['data']['discount'], 'Verified email supplies native restriction check.' );
$foreign_email = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-email' ], [ 'email' => 'foreign@example.test' ] ) );
verify( 'invalid' === $foreign_email['data']['coupon']['state'], 'Wrong verified email cannot apply restricted coupon.' );

$restricted->increase_usage_count( 'buyer@example.test' );
$used = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-email' ], [ 'email' => 'buyer@example.test' ] ) );
verify( 'invalid' === $used['data']['coupon']['state'], 'Native per-email usage limit is enforced.' );
$restricted->decrease_usage_count( 'buyer@example.test' );
$expired = new WC_Coupon(); $expired->set_code( 'synthetic-expired' ); $expired->set_discount_type( 'percent' ); $expired->set_amount( 5 ); $expired->set_date_expires( time() - 86400 ); $expired->save();
$result = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-expired' ], [] ) ); verify( 'invalid' === $result['data']['coupon']['state'], 'Expired coupon cannot discount estimate.' );

$spend = new WC_Coupon(); $spend->set_code( 'synthetic-spend' ); $spend->set_discount_type( 'percent' ); $spend->set_amount( 50 ); $spend->set_minimum_amount( 250 ); $spend->save();
$minimum = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-spend' ], [] ) ); verify( 'invalid' === $minimum['data']['coupon']['state'], 'Native minimum-spend restriction.' );
$spend = new WC_Coupon(); $spend->set_code( 'synthetic-max' ); $spend->set_discount_type( 'percent' ); $spend->set_amount( 50 ); $spend->set_maximum_amount( 150 ); $spend->save();
$maximum = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-max' ], [] ) ); verify( 'invalid' === $maximum['data']['coupon']['state'], 'Native maximum-spend restriction.' );
$excluded = source_product( 'Different coupon product' ); $spend = new WC_Coupon(); $spend->set_code( 'synthetic-product' ); $spend->set_discount_type( 'percent' ); $spend->set_amount( 50 ); $spend->set_product_ids( [ $excluded->get_id() ] ); $spend->save();
$wrong_product = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-product' ], [] ) ); verify( 'invalid' === $wrong_product['data']['coupon']['state'], 'Native product restriction, not an AI guess.' );
$category = wp_insert_term( 'Synthetic category', 'product_cat' ); $spend = new WC_Coupon(); $spend->set_code( 'synthetic-category' ); $spend->set_discount_type( 'percent' ); $spend->set_amount( 50 ); $spend->set_product_categories( [ $category['term_id'] ] ); $spend->save();
$wrong_category = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-category' ], [] ) ); verify( 'invalid' === $wrong_category['data']['coupon']['state'], 'Native category restriction.' );
$spend = new WC_Coupon(); $spend->set_code( 'synthetic-quantity' ); $spend->set_discount_type( 'percent' ); $spend->set_amount( 50 ); $spend->set_limit_usage_to_x_items( 1 ); $spend->save();
$limited_quantity = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-quantity' ], [] ) ); verify( '50.00' === $limited_quantity['data']['discount'], 'Native discount quantity cap on two-item quote.' );

$bad = $params; $bad['items'][] = $bad['items'][0]; verify( is_wp_error( readonly_quote( $bad, [] ) ), 'Duplicate products cannot bypass aggregate quantity bounds.' );
$individual = source_product( 'Individual' ); $individual->set_sold_individually( true ); $individual->save(); $bad = $params; $bad['items'][0]['product_id'] = $individual->get_id(); verify( is_wp_error( readonly_quote( $bad, [] ) ), 'Sold-individually product cannot be quoted in quantity two.' );
$parent = new WC_Product_Variable(); $parent->set_name( 'Variable fixture' ); $parent->set_status( 'publish' ); $attr = new WC_Product_Attribute(); $attr->set_name( 'Size' ); $attr->set_options( [ 'S', 'L' ] ); $attr->set_visible( true ); $attr->set_variation( true ); $parent->set_attributes( [ $attr ] ); $parent->save();
$variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); $variation->set_attributes( [ 'size' => 'S' ] ); $variation->set_status( 'publish' ); $variation->set_regular_price( '50' ); $variation->save(); WC_Product_Variable::sync( $parent->get_id() );
$variant_params = $params; $variant_params['items'] = [ [ 'product_id' => $variation->get_id(), 'quantity' => 1 ] ];
$variant_quote = valid_result( readonly_quote( $variant_params, [] ) ); verify( '50.00' === $variant_quote['data']['subtotal'], 'Exact native published variation price.' );
$variation->set_status( 'private' ); $variation->save(); verify( is_wp_error( readonly_quote( $variant_params, [] ) ), 'Private variation cannot be quoted.' );

$bad = $params; $bad['destination']['country'] = 'ZZ'; verify( is_wp_error( readonly_quote( $bad, [] ) ), 'Invalid destination denied.' );
$bad = $params; $bad['items'][0]['quantity'] = 1000; verify( is_wp_error( readonly_quote( $bad, [] ) ), 'Quantity bound enforced.' );
$hidden = source_product( 'Hidden' ); $hidden->set_catalog_visibility( 'hidden' ); $hidden->save(); $bad = $params; $bad['items'][0]['product_id'] = $hidden->get_id(); verify( is_wp_error( readonly_quote( $bad, [] ) ), 'Hidden products excluded.' );
verify( $original === [ WC()->cart, WC()->customer, WC()->session ], 'Original cart/customer/session object identity restored.' );
verify( $before['stock'] === wc_get_product( $p->get_id() )->get_stock_quantity() && $before['coupon'] === ( new WC_Coupon( $coupon->get_id() ) )->get_usage_count(), 'No stock/coupon usage mutation.' );
verify( $before['session_rows'] === $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'woocommerce_sessions' ) && $before['mail'] === $GLOBALS['support_mail_effects'], 'No persistent sessions or email effects.' );
verify( $before['order_rows'] === $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->posts . " WHERE post_type='shop_order'" ), 'No order created by quote.' );
$order = wc_create_order(); $order->set_billing_email( 'buyer@example.test' ); $order->add_product( $p, 1 ); $order->set_status( 'processing' ); $order->calculate_totals(); $order->save();
$other = wc_create_order(); $other->set_billing_email( 'other@example.test' ); $other->add_product( $p, 1 ); $other->save();
$proof = [ 'purpose' => 'orders', 'email' => 'buyer@example.test', 'access_id' => 'synthetic-access', 'session_id' => 'synthetic-session', 'expires_at' => gmdate( 'c', time() + 900 ) ];
$rows = valid_result( YoRo_Support_Commerce::orders( $proof ) ); verify( 1 === count( $rows['orders'] ), 'Native order search filters verified billing email.' );
$reference = $rows['orders'][0]['reference']; verify( ! str_starts_with( $reference, $order->get_id() . '.' ), 'Order reference is opaque and encrypted.' );
$detail = valid_result( YoRo_Support_Commerce::order( $proof, $reference ) ); verify( 'order' === $detail['kind'] && $detail['data']['items'][0]['name'] === $p->get_name(), 'Native own order detail.' );

$native_projection_cards = [];
foreach ( [ 100, 101 ] as $quantity ) {
    $native_item = current( $order->get_items() ); $native_item->set_quantity( $quantity ); $native_item->save();
    $large_detail = valid_result( YoRo_Support_Commerce::order( $proof, $reference ) );
    verify( $quantity === $large_detail['data']['items'][0]['quantity'] && 1 === count( $large_detail['data']['items'] ), 'Bounded historical order keeps the native line quantity, including more than the quote cap.' );
    $native_projection_cards[] = $large_detail;
}
foreach ( [ 'Gift for buyer@example.test', 'Gift for BUYER@EXAMPLE.TEST', 'Gift for buyer&#64;example.test', 'Gift for buyer@<b>example.test</b>', 'Gift for buyer&#64;<b>example.test</b>', 'Gift for buyer@&lt;b&gt;example.test&lt;/b&gt;' ] as $private_name ) {
    $native_item->set_name( $private_name ); $native_item->save();
    $private_detail = valid_result( YoRo_Support_Commerce::order( $proof, $reference ) );
    verify( 'Gift for [private email]' === $private_detail['data']['items'][0]['name'] && false === stripos( wp_json_encode( $private_detail ), 'buyer@example.test' ), 'Private order projection removes only the authoritative billing email before hashing and truncation.' );
    $native_projection_cards[] = $private_detail;
}
$native_item->set_name( 'Normal engraved product' ); $native_item->set_quantity( 1 ); $native_item->save();
$normal_detail = valid_result( YoRo_Support_Commerce::order( $proof, $reference ) );
verify( 'Normal engraved product' === $normal_detail['data']['items'][0]['name'], 'A normal private order product name is preserved.' );
$native_projection_cards[] = $normal_detail;
echo 'G031_NATIVE_ORDER_CARDS:', wp_json_encode( $native_projection_cards ), "\n";
$native_item->set_name( $p->get_name() ); $native_item->save();

verify( ! str_contains( wp_json_encode( $detail ), 'buyer@example.test' ) && ! isset( $detail['data']['billing_address'], $detail['data']['phone'], $detail['data']['internal_notes'] ), 'Only minimal private allowlist projected.' );

$registered_user = wp_insert_user( [ 'user_login' => 'synthetic-buyer', 'user_pass' => wp_generate_password(), 'user_email' => 'buyer@example.test' ] );
$registered = wc_create_order( [ 'customer_id' => $registered_user ] ); $registered->set_billing_email( 'buyer@example.test' ); $registered->add_product( $p, 1 ); $registered->calculate_totals(); $registered->save();
$multiple = valid_result( YoRo_Support_Commerce::orders( $proof ) ); verify( 2 === count( $multiple['orders'] ), 'Guest and registered orders for the same verified billing email are included, foreign order excluded.' );
$refund = wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => 10, 'reason' => 'Synthetic customer-visible refund', 'refund_payment' => false, 'restock_items' => false ] ); valid_result( $refund );
$refunded = valid_result( YoRo_Support_Commerce::order( $proof, $reference ) ); verify( str_starts_with( $refunded['data']['refund'], '10.00 refunded' ), 'Recorded refund is read from native refund records, not a return inference.' );
// Native fulfillment storage is initialized only in this proven fresh disposable DB.
if ( ! class_exists( 'YoRo_Order_Management', false ) ) { require_once dirname( __DIR__, 2 ) . '/includes/actions/class-yoro-order-management.php'; }
foreach ( [ 'wc_order_fulfillments', 'wc_order_fulfillment_meta' ] as $suffix ) {
    $table = $GLOBALS['wpdb']->prefix . $suffix;
    if ( $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $GLOBALS['wpdb']->esc_like( $table ) ) ) ) { throw new RuntimeException( 'Fresh absent native fulfillment storage required.' ); }
}
wc_get_container()->get( Automattic\WooCommerce\Internal\Features\FeaturesController::class )->change_feature_enable( 'fulfillments', true );
wc_get_container()->get( Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentsController::class )->initialize_fulfillments();
verify( YoRo_Order_Management::tracking_available(), 'Actual native fulfillment storage enabled in isolated fixture.' );
$record = new Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment(); $record->set_entity_type( WC_Order::class ); $record->set_entity_id( (string) $order->get_id() ); $record->set_status( 'unfulfilled' );
$record->set_items( [ [ 'item_id' => array_key_first( $order->get_items() ), 'qty' => 1 ] ] ); $record->set_shipment_provider( 'Synthetic stored carrier' ); $record->set_tracking_number( 'NATIVE123456' ); $record->save();
$tracked = valid_result( YoRo_Support_Commerce::order( $proof, $reference ) ); verify( 'NATIVE123456' === $tracked['data']['tracking'][0]['number'] && '' === $tracked['data']['tracking'][0]['url'] && str_contains( $tracked['data']['tracking'][0]['status'], 'not verified' ), 'Native recorded tracking is bounded and never presented as live carrier confirmation.' );

$changed = $proof; $changed['email'] = 'other@example.test'; verify( is_wp_error( YoRo_Support_Commerce::order( $changed, $reference ) ), 'Opaque reference is email-bound.' );
$changed = $proof; $changed['session_id'] = 'other-session'; verify( is_wp_error( YoRo_Support_Commerce::order( $changed, $reference ) ), 'Opaque reference is session-bound.' );
$changed = $proof; $changed['access_id'] = 'other-access'; verify( is_wp_error( YoRo_Support_Commerce::order( $changed, $reference ) ), 'Opaque reference is access-generation-bound.' );
$order->set_billing_email( 'moved@example.test' ); $order->save(); verify( is_wp_error( YoRo_Support_Commerce::order( $proof, $reference ) ), 'Order ownership change is revalidated.' );
$unknown = static fn( $price ) => $price; add_filter( 'woocommerce_product_get_price', $unknown ); verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Uncharacterized plugin callback fails closed.' ); remove_filter( 'woocommerce_product_get_price', $unknown );
$changed = $proof; $changed['expires_at'] = gmdate( 'c', time() - 1 ); verify( is_wp_error( YoRo_Support_Commerce::orders( $changed ) ), 'Expiry is enforced without cleanup.' );
$package_plugin = static fn( $packages ) => array_merge( $packages, $packages ); add_filter( 'woocommerce_cart_shipping_packages', $package_plugin ); verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Uncharacterized multi-package checkout is reported unsupported.' ); remove_filter( 'woocommerce_cart_shipping_packages', $package_plugin );
$bad = $params; $bad['destination']['country'] = 'GB'; $bad['destination']['state'] = ''; $bad['destination']['postcode'] = 'SW1A 1AA'; verify( is_wp_error( readonly_quote( $bad, [] ) ), 'No configured rate is unavailable, never free shipping.' );

// F05: the same native availability/code guards used by WC_Cart::apply_coupon.
update_option( 'woocommerce_enable_coupons', 'no' );
$off_coupon = valid_result( readonly_quote( $params + [ 'coupon' => 'synthetic-ten' ], [] ) );
verify( 'invalid' === $off_coupon['data']['coupon']['state'] && '0.00' === $off_coupon['data']['discount'], 'Disabled native coupons cannot discount a preview.' );
update_option( 'woocommerce_enable_coupons', 'yes' );
$id_coupon = valid_result( readonly_quote( $params + [ 'coupon' => (string) $coupon->get_id() ], [] ) );
verify( 'invalid' === $id_coupon['data']['coupon']['state'] && '0.00' === $id_coupon['data']['discount'], 'Numeric coupon post ID cannot substitute for its different code.' );
$numeric = new WC_Coupon(); $numeric->set_code( '987654321' ); $numeric->set_discount_type( 'percent' ); $numeric->set_amount( 10 ); $numeric->save();
verify( 'valid' === valid_result( readonly_quote( $params + [ 'coupon' => '987654321' ], [] ) )['data']['coupon']['state'], 'An actual numeric coupon code remains supported.' );

// F06/F09: virtual/free totals are distinct from missing physical shipping.
$virtual = source_product( 'Free virtual fixture', '0' ); $virtual->set_virtual( true ); $virtual->save();
$virtual_params = $params; $virtual_params['items'] = [ [ 'product_id' => $virtual->get_id(), 'quantity' => 1 ] ];
$free = valid_result( readonly_quote( $virtual_params, [] ) );

verify( is_wp_error( readonly_quote( $virtual_params + [ 'shipping_rates' => [ 'flat_rate:' . $rate_id ] ], [] ) ), 'An explicit physical-package rate cannot survive a final virtual quote.' );
foreach ( [ 1, 'missing-package' ] as $missing_package ) {
    $extra_selection = $params + [ 'shipping_rates' => [ 0 => 'flat_rate:' . $rate_id, $missing_package => 'flat_rate:' . $rate_id ] ];
    verify( is_wp_error( readonly_quote( $extra_selection, [] ) ), 'Explicit rate keys absent from final native packages fail closed.' );
}

verify( '0.00' === $free['data']['shipping'] && '0.00' === $free['data']['total'] && [] === $free['data']['payment_methods'], 'Native virtual zero-total cart needs neither shipping nor a payment gateway.' );
$virtual->set_regular_price( '30' ); $virtual->save();
verify( in_array( 'Bank transfer', valid_result( readonly_quote( $virtual_params, [] ) )['data']['payment_methods'], true ), 'Positive native total retains enabled payment gateway.' );
$all_off = new WC_Coupon(); $all_off->set_code( 'synthetic-free' ); $all_off->set_discount_type( 'percent' ); $all_off->set_amount( 100 ); $all_off->save();
verify( [] === valid_result( readonly_quote( $virtual_params + [ 'coupon' => 'synthetic-free' ], [] ) )['data']['payment_methods'], 'Native post-discount zero-total cart does not request a gateway.' );
update_option( 'woocommerce_ship_to_countries', 'disabled' );
verify( is_wp_error( readonly_quote( $params, [] ) ), 'Physical items with no shipping methods cannot become a false free-shipping total.' );
verify( ! is_wp_error( readonly_quote( $virtual_params, [] ) ), 'Shipping-disabled store still supports a genuinely virtual cart.' );
update_option( 'woocommerce_ship_to_countries', '' );
$zone->delete_shipping_method( $rate_id ); $zone->delete_shipping_method( $second_id );
verify( is_wp_error( readonly_quote( $params, [] ) ), 'Physical cart in a store with zero configured native methods cannot return a false zero fee.' );
$rate_id = $zone->add_shipping_method( 'flat_rate' ); update_option( 'woocommerce_flat_rate_' . $rate_id . '_settings', [ 'title' => 'Standard', 'tax_status' => 'taxable', 'cost' => '10' ] );
$second_id = $zone->add_shipping_method( 'flat_rate' ); update_option( 'woocommerce_flat_rate_' . $second_id . '_settings', [ 'title' => 'Express', 'tax_status' => 'taxable', 'cost' => '20' ] );

$free_zone = new WC_Shipping_Zone(); $free_zone->set_zone_name( 'Synthetic free UK' ); $free_zone->add_location( 'GB', 'country' ); $free_zone->save();
$free_id = $free_zone->add_shipping_method( 'free_shipping' ); update_option( 'woocommerce_free_shipping_' . $free_id . '_settings', [ 'title' => 'Free native shipping', 'requires' => '' ] );
$free_shipping = valid_result( readonly_quote( $bad, [] ) );
verify( '0.00' === $free_shipping['data']['shipping'] && ! empty( $free_shipping['data']['shipping_rates'] ), 'An actual native free-shipping rate can truthfully cost zero.' );

// F07/F08: stock is shared by native owner, and public visibility ignores password cookies.
$variation->set_status( 'publish' ); $variation->save();
$sibling = new WC_Product_Variation(); $sibling->set_parent_id( $parent->get_id() ); $sibling->set_attributes( [ 'size' => 'L' ] ); $sibling->set_status( 'publish' ); $sibling->set_regular_price( '50' ); $sibling->save();
$parent->set_manage_stock( true ); $parent->set_stock_quantity( 5 ); $parent->set_backorders( 'no' ); $parent->save(); WC_Product_Variable::sync( $parent->get_id() );
foreach ( [ [ 1, 3, true ], [ 2, 3, true ], [ 4, 4, false ] ] as [ $left, $right, $allowed ] ) {
    $siblings = $params; $siblings['items'] = [ [ 'product_id' => $variation->get_id(), 'quantity' => $left ], [ 'product_id' => $sibling->get_id(), 'quantity' => $right ] ];
    verify( $allowed === ! is_wp_error( readonly_quote( $siblings, [] ) ), 'Sibling variation aggregate stock uses parent threshold: ' . $left . '+' . $right );
}
verify( 5 === wc_get_product( $parent->get_id() )->get_stock_quantity(), 'Read-only shared-stock quotes never consume stock.' );
$parent->set_backorders( 'yes' ); $parent->save();
verify( ! is_wp_error( readonly_quote( $siblings, [] ) ), 'Native backorder permission remains supported on shared owner.' );
$parent->set_backorders( 'no' ); $parent->save();
$hold = wc_create_order(); $hold->add_product( wc_get_product( $variation->get_id() ), 2 ); $hold->save(); wc_reserve_stock_for_order( $hold );
$held_params = $params; $held_params['items'] = [ [ 'product_id' => $variation->get_id(), 'quantity' => 2 ], [ 'product_id' => $sibling->get_id(), 'quantity' => 2 ] ];
verify( wc_get_held_stock_quantity( wc_get_product( $variation->get_id() ) ) >= 2 && is_wp_error( readonly_quote( $held_params, [] ) ), 'Native held stock counts against the shared owner without borrowing live shopper order authority.' );
wc_release_stock_for_order( $hold );
verify( ! is_wp_error( readonly_quote( $held_params, [] ) ), 'Released native held stock restores the eligible read-only estimate.' );
foreach ( [ $p->get_id(), $parent->get_id(), $variation->get_id() ] as $protected_id ) {
    wp_update_post( [ 'ID' => $protected_id, 'post_password' => 'synthetic-password' ] );
    $protected_params = $protected_id === $p->get_id() ? $params : $variant_params;
    foreach ( [ false, true ] as $with_cookie ) {
        if ( $with_cookie ) { require_once ABSPATH . WPINC . '/class-phpass.php'; $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'synthetic-password' ); }
        else { unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] ); }
        verify( is_wp_error( readonly_quote( $protected_params, [] ) ), 'Password-protected product/parent/variation never becomes a public quote, including a valid password cookie.' );
    }
    wp_update_post( [ 'ID' => $protected_id, 'post_password' => '' ] ); unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
}
verify( ! is_wp_error( readonly_quote( $params, [] ) ), 'Public native product stays quotable after protected cases.' );

// Context protocol uses synthetic API authorization but the same actual native Woo engine.
$authorization_revoked = false; $authorization_calls = 0; $authorization_access = null; $authorization_email = null;
$session = [ 'session_id' => 'context-fixture-session', 'credential' => 'synthetic-context-secret', 'revision' => 4, 'expires' => time() + 600 ];
$settings = [ 'revision' => 4, 'content_ids' => [], 'preferences' => [ 'features' => [ 'content' => false, 'products' => false, 'quote' => true, 'payments' => false, 'coupons' => true, 'orders' => true ] ] ];
$authority_filter = function ( $pre, $args, $url ) use ( &$authorization_revoked, &$authorization_calls, &$authorization_access, &$authorization_email, $session ) {
    if ( 'support-api-fixture.invalid' !== parse_url( $url, PHP_URL_HOST ) ) { return new WP_Error( 'fixture_outbound_denied', 'No external network.' ); }
    ++$authorization_calls;
    if ( $authorization_revoked ) { return [ 'response' => [ 'code' => 403 ], 'body' => wp_json_encode( [ 'code' => 'support_verification_required' ] ) ]; }
    if ( str_ends_with( $url, '/sessions/current' ) ) { return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'protocol' => 2 ] ) ]; }
    $body = json_decode( $args['body'], true );
    return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'protocol' => 2, 'purpose' => $body['purpose'], 'session_id' => $session['session_id'], 'revision' => 4,
        'access_id' => $authorization_access, 'email' => $authorization_email, 'expires_at' => null === $authorization_access ? null : gmdate( 'c', time() + 600 ) ] ) ];
};
add_filter( 'pre_http_request', $authority_filter, 1, 3 );
$selected = valid_result( YoRo_Support_Context::create( $settings, $session, 'quote', $params ) );
verify( ! str_contains( $selected['context_token'], 'synthetic-context-secret' ), 'Context inputs are encrypted, no browser credential.' );
$snap = valid_result( YoRo_Support_Context::snapshot( $settings, $session, '', null, $selected['context_token'] ) );
verify( $selected['card'] === $snap['cards'][0] && 'disabled' === $snap['cards'][0]['data']['payment_state'] && [] === $snap['cards'][0]['data']['payment_methods'], 'Fresh native context reproduces exact card and masks disabled payment facts.' );
verify( $authorization_calls === 2, 'Each selected context reauthorizes at API; browser token grants no authority.' );
$decoded_context = YoRo_Support_Transport::open( $selected['context_token'], 'context' );
verify( ! isset( $decoded_context['credential'] ) && isset( $decoded_context['credential_binding'] ) && [] === YoRo_Support_Transport::open( $selected['context_token'] ), 'Context is purpose-separated and contains no reusable session credential.' );
$authorization_access = 'synthetic-new-access'; $authorization_email = 'buyer@example.test';
verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $session, '', null, $selected['context_token'] ) ), 'A public null-access context cannot silently upgrade after verification.' );
$private_selected = valid_result( YoRo_Support_Context::create( $settings, $session, 'quote', $params ) );
verify( $private_selected['private'] && ! is_wp_error( YoRo_Support_Context::snapshot( $settings, $session, '', null, $private_selected['context_token'] ) ), 'A freshly selected context retains the exact new live private binding.' );
$authorization_access = 'synthetic-changed-access'; $authorization_email = 'other@example.test';
verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $session, '', null, $private_selected['context_token'] ) ), 'Changed email/access cannot reuse a prior private context.' );
$authorization_access = null; $authorization_email = null;
verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $session, '', null, $private_selected['context_token'] ) ), 'Revoked private access cannot downgrade a prior context to public.' );
$p->set_regular_price( '110' ); $p->save();
$changed = valid_result( YoRo_Support_Context::snapshot( $settings, $session, '', null, $selected['context_token'] ) );
verify( $changed['cards'][0]['version'] !== $snap['cards'][0]['version'], 'A product price change invalidates previous context snapshot.' );
$p->set_regular_price( '100' ); $p->save();
$foreign = $session; $foreign['session_id'] = 'other-session'; verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $foreign, '', null, $selected['context_token'] ) ), 'Cross-session context token denied.' );
$foreign = $session; $foreign['credential'] = 'rotated-secret'; verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $foreign, '', null, $selected['context_token'] ) ), 'Rotated credential context token denied.' );
$new_settings = $settings; $new_settings['revision']++; verify( is_wp_error( YoRo_Support_Context::snapshot( $new_settings, $session, '', null, $selected['context_token'] ) ), 'Changed settings revision denies held context.' );
$disabled = $settings; $disabled['preferences']['features']['quote'] = false; verify( is_wp_error( YoRo_Support_Context::snapshot( $disabled, $session, '', null, $selected['context_token'] ) ), 'Disabled capability cannot reuse held context.' );
$expired_context = YoRo_Support_Transport::open( $selected['context_token'], 'context' ); $expired_context['expires'] = time() - 1;
verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $session, '', null, YoRo_Support_Transport::seal( $expired_context, 'context' ) ) ), 'Expired encrypted context fails without pruning.' );
$authorization_revoked = true; verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $session, '', null, $selected['context_token'] ) ), 'Fresh API revocation fences context read before facts are returned.' );

$authorization_revoked = false;
update_option( YoRo_Customer_Support::OPTION, $settings + [ 'enabled' => true, 'protocol' => 2 ] );
$cookie = YoRo_Support_Transport::seal( $session ); $_COOKIE[ '__Host-yoro_support_' . get_current_blog_id() ] = $cookie;
$g028_https_before = $_SERVER['HTTPS'] ?? null;
$_SERVER['HTTPS'] = 'on'; YoRo_Customer_Support::routes();
foreach ( [ $selected['context_token'], $reference ] as $substitute ) {
    $_COOKIE[ '__Host-yoro_support_' . get_current_blog_id() ] = $substitute;
    foreach ( [ 'verification' => [ 'action' => 'current' ], 'context' => [ 'kind' => 'orders' ], 'turn' => [ 'turn_id' => '12345678-1234-4123-8123-123456789abc', 'message' => 'Question' ] ] as $route => $body ) {
        $request = new WP_REST_Request( 'POST', '/yoro/v1/support/' . $route ); $request->set_body_params( $body );
        $request->set_header( 'Origin', home_url() ); $request->set_header( 'X-YoRo-Support-CSRF', YoRo_Support_Transport::csrf( $substitute ) );
        $before_calls = $authorization_calls; $denied = rest_do_request( $request );
        verify( $denied->get_status() >= 400 && $before_calls === $authorization_calls, 'Native REST rejects a context/order token as session authority before any API/private read.' );
    }
}
$_COOKIE[ '__Host-yoro_support_' . get_current_blog_id() ] = $cookie;
valid_result( YoRo_Support_Commerce::profile() );
$native_rest_hooks = [ 'woocommerce_rest_shop_order_refund_trashable', 'woocommerce_rest_shop_order_refund_object_trashable' ];
$GLOBALS['g028_mutating_callback_calls'] = 0;
function g028_foreign_mutating_callback( $value ) { ++$GLOBALS['g028_mutating_callback_calls']; update_option( 'g028_forbidden_profile_effect', true ); return $value; }
foreach ( $native_rest_hooks as $hook ) {
    verify( has_filter( $hook, '__return_false' ) === 10 && $GLOBALS['wp_filter'][ $hook ]->callbacks[10]['__return_false']['accepted_args'] === 1, 'Actual locked native refund guard retains exact priority and arity.' );
    foreach ( [ static fn( $value ) => $value, 'g028_foreign_mutating_callback' ] as $foreign ) {
        add_filter( $hook, $foreign, 10, 1 );
        verify( is_wp_error( readonly_quote( $params, [] ) ) && 0 === $GLOBALS['g028_mutating_callback_calls'] && false === get_option( 'g028_forbidden_profile_effect', false ), 'Foreign/closure/mutating callback is rejected without invocation or persistence.' );
        remove_filter( $hook, $foreign, 10 );
    }
    // A real Woo-owned mutating method must not escape through generic native ownership reflection.
    $native_mutator = [ ( new ReflectionClass( 'WC_Cart' ) )->newInstanceWithoutConstructor(), 'empty_cart' ];
    $mutator_owner = new ReflectionMethod( $native_mutator[0], $native_mutator[1] );
    verify( str_starts_with( realpath( $mutator_owner->getFileName() ), realpath( WC_ABSPATH ) . DIRECTORY_SEPARATOR ), 'Negative method reference has actual locked Woo ownership.' );
    add_filter( $hook, $native_mutator, 10, 1 );
    verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Each exact refund hook rejects an actual Woo-owned empty_cart method reference before generic native fallback; it is never invoked.' );
    remove_filter( $hook, $native_mutator, 10 );
    valid_result( YoRo_Support_Commerce::profile() );
    add_filter( $hook, '__return_false', 9, 1 );
    verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Correct global callback with an unexpected priority is rejected.' );
    remove_filter( $hook, '__return_false', 9 );
    add_filter( $hook, '__return_false', 10, 2 );
    verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Correct global callback with unexpected arity is rejected.' );
    add_filter( $hook, '__return_false', 10, 1 );
}
add_filter( 'woocommerce_before_calculate_totals', '__return_false', 10, 1 );
verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Same WordPress callback on an unrelated hook stays unsupported.' );
remove_filter( 'woocommerce_before_calculate_totals', '__return_false', 10 );
valid_result( YoRo_Support_Commerce::profile() );
// Inspect misplaced native mutators without ever invoking a quote while they are registered.
foreach ( [ 'wc_create_order', [ ( new ReflectionClass( 'WC_Cart' ) )->newInstanceWithoutConstructor(), 'empty_cart' ] ] as $misplaced ) {
    $hook = 'woocommerce_before_get_rates_for_package'; $before_hook = did_action( $hook );
    $before_orders = $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->posts . " WHERE post_type='shop_order'" );
    add_action( $hook, $misplaced, 10, 0 );
    try { verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Woo file ownership cannot authorize a function or method on an unsupported quote registration.' ); }
    finally { remove_action( $hook, $misplaced, 10 ); }
    verify( false === has_action( $hook, $misplaced ) && $before_hook === did_action( $hook ) && $before_orders === $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->posts . " WHERE post_type='shop_order'" ), 'Misplaced mutator is removed before any quote and no callback or order mutation occurred.' );
}
// Test a registration actually installed by the supported native package.
$known = null;
foreach ( $GLOBALS['wp_filter'] as $hook => $value ) {
    if ( 0 !== strpos( $hook, 'woocommerce_' ) ) { continue; }
    foreach ( $value->callbacks as $priority => $callbacks ) { foreach ( $callbacks as $entry ) {
        if ( 'wc_sanitize_coupon_code' === $entry['function'] ) { $known = [ $hook, $priority, $entry['accepted_args'] ]; break 3; }
    } }
}
verify( is_array( $known ), 'Native fixture supplies an actual known callback registration for binding tests.' );
foreach ( [ [ $known[1] + 1, $known[2] ], [ $known[1], $known[2] + 1 ] ] as $binding ) {
    add_filter( $known[0], 'wc_sanitize_coupon_code', $binding[0], $binding[1] );
    try { verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'A legitimate native callable with changed priority or arity is unsupported.' ); }
    finally { remove_filter( $known[0], 'wc_sanitize_coupon_code', $binding[0] ); add_filter( $known[0], 'wc_sanitize_coupon_code', $known[1], $known[2] ); }
}
valid_result( YoRo_Support_Commerce::profile() );

$autoloads = 0;
$sentinel = static function ( $class ) use ( &$autoloads ) { if ( 'SupportUnloadedVendor\\Mutator' === $class ) { ++$autoloads; } };
spl_autoload_register( $sentinel );
try {
    foreach ( [ 'SupportUnloadedVendor\\Mutator::run', [ 'SupportUnloadedVendor\\Mutator', 'run' ] ] as $unknown_static ) {
        add_action( 'woocommerce_before_get_rates_for_package', $unknown_static, 10, 0 );
        try { verify( is_wp_error( YoRo_Support_Commerce::profile() ) && 0 === $autoloads, 'Static string and array callbacks are denied without autoload or invocation.' ); }
        finally { remove_action( 'woocommerce_before_get_rates_for_package', $unknown_static, 10 ); }
    }
} finally { spl_autoload_unregister( $sentinel ); }
$static_positive = null;
foreach ( $GLOBALS['wp_filter'] as $hook => $value ) {
    if ( 0 !== strpos( $hook, 'woocommerce_' ) ) { continue; }
    foreach ( $value->callbacks as $priority => $callbacks ) { foreach ( $callbacks as $entry ) {
        $callback = $entry['function'];
        if ( is_array( $callback ) && is_string( $callback[0] ) && class_exists( $callback[0], false )
            && ( new ReflectionMethod( $callback[0], $callback[1] ) )->isStatic() ) {
            $static_positive = [ $hook, $priority, $entry ]; break 3;
        }
    } }
}
verify( is_array( $static_positive ), 'Actual native registry contains a loaded static callback positive.' );
[ $hook, $priority, $entry ] = $static_positive;
$static_string = implode( '::', $entry['function'] );
remove_filter( $hook, $entry['function'], $priority ); add_filter( $hook, $static_string, $priority, $entry['accepted_args'] );
try { valid_result( YoRo_Support_Commerce::profile() ); verify( true, 'A loaded native static string retains its exact legitimate registration.' ); }
finally { remove_filter( $hook, $static_string, $priority ); add_filter( $hook, $entry['function'], $priority, $entry['accepted_args'] ); }
$wp_bindings = [];
foreach ( $GLOBALS['wp_filter'] as $hook => $value ) {
    if ( 0 !== strpos( $hook, 'woocommerce_' ) ) { continue; }
    foreach ( $value->callbacks as $priority => $callbacks ) { foreach ( $callbacks as $entry ) {
        $callback = $entry['function'];
        if ( 'intval' === $callback || ( is_string( $callback ) && in_array( $callback, [ 'sanitize_text_field', 'wp_kses_post', 'do_blocks', 'wptexturize', 'convert_smilies', 'convert_chars', 'wpautop', 'shortcode_unautop', 'prepend_attachment', 'do_shortcode', 'absint' ], true ) )
            || ( is_array( $callback ) && $callback[0] instanceof WP_Embed && 'run_shortcode' === $callback[1] ) ) { $wp_bindings[] = [ $hook, $priority, $entry ]; }
    } }
}
verify( count( $wp_bindings ) >= 14, 'Locked native WordPress helper registrations are present as positives.' );
foreach ( $wp_bindings as [ $hook, $priority, $entry ] ) {
    foreach ( [ [ $priority + 1, $entry['accepted_args'] ], [ $priority, 0 ] ] as $binding ) {
        add_filter( $hook, $entry['function'], $binding[0], $binding[1] );
        try { verify( is_wp_error( YoRo_Support_Commerce::profile() ), 'Known native WordPress helpers require exact priority and arity, without invoking an altered registration.' ); }
        finally { remove_filter( $hook, $entry['function'], $binding[0] ); add_filter( $hook, $entry['function'], $priority, $entry['accepted_args'] ); }
    }
}
valid_result( YoRo_Support_Commerce::profile() );

$rest_quote = new WP_REST_Request( 'POST', '/yoro/v1/support/context' ); $rest_quote->set_body_params( [ 'kind' => 'quote', 'params' => $params ] );
$rest_quote->set_header( 'Origin', home_url() ); $rest_quote->set_header( 'X-YoRo-Support-CSRF', YoRo_Support_Transport::csrf( $cookie ) );
$rest_cart_before = [ WC()->cart, WC()->customer, WC()->session ];
$rest_quote_response = rest_do_request( $rest_quote );
verify( 200 === $rest_quote_response->get_status() && 'quote' === $rest_quote_response->get_data()['card']['kind'] && '231.00' === $rest_quote_response->get_data()['card']['data']['total'], 'Actual native REST bootstrap retains the supported read-only quote, native totals and exact refund guards.' );
verify( $rest_cart_before === [ WC()->cart, WC()->customer, WC()->session ] && 0 === $GLOBALS['g028_mutating_callback_calls'], 'Native REST estimate restores the live cart identities and never invokes rejected callbacks.' );
if ( null === $g028_https_before ) { unset( $_SERVER['HTTPS'] ); } else { $_SERVER['HTTPS'] = $g028_https_before; }

$request = new WP_REST_Request( 'POST' ); $request->set_body_params( [ 'kind' => 'products', 'query' => 'Native quote product' ] ); $request->set_header( 'X-YoRo-Support-CSRF', YoRo_Support_Transport::csrf( $cookie ) );
$choices = valid_result( YoRo_Customer_Support::context( $request ) )->get_data();
verify( ! empty( $choices['products'] ) && false === YoRo_Support_Context::capabilities( $settings )['products'], 'Quote product selection works while separate public product advice is disabled.' );
$request->set_body_params( [ 'kind' => 'products', 'query' => '', 'email' => 'forged@example.test' ] );
verify( is_wp_error( YoRo_Customer_Support::context( $request ) ), 'Unknown context control fields cannot inject browser identity.' );
remove_filter( 'pre_http_request', $authority_filter, 1 );


// Public reply cards are projected only from current native cited facts, never model-written fields.
$product_settings = [ 'enabled' => true, 'protocol' => 2, 'revision' => 4, 'content_ids' => [], 'contact' => '', 'preferences' => [ 'features' => [ 'content' => false, 'products' => true, 'quote' => false, 'coupons' => false, 'payments' => false, 'orders' => false ] ] ];
update_option( YoRo_Customer_Support::OPTION, $product_settings );
$p->set_sku( 'NATIVE-PUBLIC-SKU' ); $p->save();
$public_id = 'product:' . $p->get_id();
foreach ( [ 'unchanged', 'price-changed', 'hidden' ] as $scenario ) {
    $p->set_regular_price( '100' ); $p->set_catalog_visibility( 'visible' ); $p->save();
    $previous = YoRo_Support_Context::snapshot( $product_settings, $session, 'Native quote product' );
    $native_source = array_values( array_filter( $previous['sources'], static fn( $source ) => $source['id'] === $public_id ) )[0];
    $replay = function ( $pre, $args, $url ) use ( $scenario, $p, $previous, $public_id ) {
        if ( str_ends_with( $url, '/cancel' ) ) { return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'status' => 'failed' ] ) ]; }
        if ( ! str_ends_with( $url, '/turns' ) ) { throw new RuntimeException( 'Unexpected public-card transport.' ); }
        if ( 'price-changed' === $scenario ) { $p->set_regular_price( '120' ); $p->save(); }
        if ( 'hidden' === $scenario ) { $p->set_catalog_visibility( 'hidden' ); $p->save(); }
        return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'status' => 'completed', 'snapshot' => $previous,
            'answer' => [ 'text' => 'Current product.', 'products' => [ [ 'price' => '999999', 'url' => 'https://evil.invalid' ] ], 'sources' => [ [ 'id' => $public_id, 'title' => 'Model forged title', 'url' => 'https://evil.invalid' ] ] ] ] ) ];
    };
    add_filter( 'pre_http_request', $replay, 1, 3 );
    $request = new WP_REST_Request( 'POST' ); $request->set_body_params( [ 'turn_id' => '12345678-1234-4123-8123-123456789abc', 'message' => 'Native quote product' ] );
    $request->set_header( 'X-YoRo-Support-CSRF', YoRo_Support_Transport::csrf( $cookie ) );
    $reply = YoRo_Customer_Support::turn( $request );
    if ( 'unchanged' === $scenario ) {
        $card = valid_result( $reply )->get_data()['answer']['products'][0];
        verify( $card['title'] === $native_source['title'] && $card['price'] === $native_source['product']['price'] && $card['url'] === $native_source['url'] && 'NATIVE-PUBLIC-SKU' === $card['sku'], 'Public card uses native revalidated price, SKU and URL, ignoring forged model data.' );
    } else { verify( is_wp_error( $reply ), 'Changed or hidden public product cannot produce a stale completed reply/card, including replay.' ); }
    remove_filter( 'pre_http_request', $replay, 1 );
}
$p->set_regular_price( '100' ); $p->set_catalog_visibility( 'visible' ); $p->save();
$legacy = YoRo_Support_Facts::snapshot( $product_settings, 'Native quote product' );
$legacy_card = YoRo_Customer_Support::safe_answer( [ 'text' => 'Legacy public answer.', 'sources' => [ [ 'id' => $public_id ] ] ], $legacy );
verify( ! empty( $legacy_card['products'] ) && '' === $legacy_card['products'][0]['sku'], 'Protocol1 public product facts remain compatible with additive card projection.' );


if ( $full_core ) {
    foreach ( $report_hooks as $hook => $binding ) { verify( has_action( $hook, $binding[0] ) === $binding[1], 'Full report hooks remain registered after all quote and security cases.' ); }
    foreach ( $subscription_hooks as $hook => $binding ) { verify( has_action( $hook, $binding[0] ) === $binding[1], 'Full subscription hooks remain registered after all quote and security cases.' ); }
}
echo wp_json_encode( [ 'result' => 'passed', 'suite' => $full_core ? 'full-core-native-commerce' : 'native-commerce', 'assertions' => $count, 'woocommerce' => WC_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'full_core' => $full_core, 'report_callbacks' => count( $report_hooks ), 'provider_calls' => 0, 'outbound_mail' => 0, 'scope' => 'disposable fixture only' ] ), "\n";
