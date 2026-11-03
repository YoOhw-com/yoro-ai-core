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
    global $wpdb, $report_hooks;
    $before = count( $wpdb->queries );
    $actions = array_map( 'did_action', array_keys( $report_hooks ?? [] ) );
    $generation = get_option( '_yoro_complete_report_generation_v2' );
    $mutation = get_option( '_yoro_complete_report_mutation_v2' );
    $result = YoRo_Support_Commerce::quote( $params, $authority );
    if ( $report_hooks ?? [] ) {
        verify( $actions === array_map( 'did_action', array_keys( $report_hooks ) ), 'Read-only quote invokes no report persistence hook.' );
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
            }
        }
    }
    verify( 13 === count( $report_hooks ), 'All thirteen full Core report Woo callbacks remain registered.' );
    $before_profile = array_map( 'did_action', array_keys( $report_hooks ) );
    valid_result( YoRo_Support_Commerce::profile() );
    verify( $before_profile === array_map( 'did_action', array_keys( $report_hooks ) ), 'Profile inspection never invokes report callbacks.' );
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

// Context protocol uses synthetic API authorization but the same actual native Woo engine.
$authorization_revoked = false; $authorization_calls = 0;
$session = [ 'session_id' => 'context-fixture-session', 'credential' => 'synthetic-context-secret', 'revision' => 4, 'expires' => time() + 600 ];
$settings = [ 'revision' => 4, 'content_ids' => [], 'preferences' => [ 'features' => [ 'content' => false, 'products' => false, 'quote' => true, 'payments' => false, 'coupons' => true, 'orders' => true ] ] ];
$authority_filter = function ( $pre, $args, $url ) use ( &$authorization_revoked, &$authorization_calls, $session ) {
    if ( 'support-api-fixture.invalid' !== parse_url( $url, PHP_URL_HOST ) ) { return new WP_Error( 'fixture_outbound_denied', 'No external network.' ); }
    ++$authorization_calls;
    if ( $authorization_revoked ) { return [ 'response' => [ 'code' => 403 ], 'body' => wp_json_encode( [ 'code' => 'support_verification_required' ] ) ]; }
    if ( str_ends_with( $url, '/sessions/current' ) ) { return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'protocol' => 2 ] ) ]; }
    $body = json_decode( $args['body'], true );
    return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'protocol' => 2, 'purpose' => $body['purpose'], 'session_id' => $session['session_id'], 'revision' => 4,
        'access_id' => null, 'email' => null, 'expires_at' => null ] ) ];
};
add_filter( 'pre_http_request', $authority_filter, 1, 3 );
$selected = valid_result( YoRo_Support_Context::create( $settings, $session, 'quote', $params ) );
verify( ! str_contains( $selected['context_token'], 'synthetic-context-secret' ), 'Context inputs are encrypted, no browser credential.' );
$snap = valid_result( YoRo_Support_Context::snapshot( $settings, $session, '', null, $selected['context_token'] ) );
verify( $selected['card'] === $snap['cards'][0] && 'disabled' === $snap['cards'][0]['data']['payment_state'] && [] === $snap['cards'][0]['data']['payment_methods'], 'Fresh native context reproduces exact card and masks disabled payment facts.' );
verify( $authorization_calls === 2, 'Each selected context reauthorizes at API; browser token grants no authority.' );
$p->set_regular_price( '110' ); $p->save();
$changed = valid_result( YoRo_Support_Context::snapshot( $settings, $session, '', null, $selected['context_token'] ) );
verify( $changed['cards'][0]['version'] !== $snap['cards'][0]['version'], 'A product price change invalidates previous context snapshot.' );
$p->set_regular_price( '100' ); $p->save();
$foreign = $session; $foreign['session_id'] = 'other-session'; verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $foreign, '', null, $selected['context_token'] ) ), 'Cross-session context token denied.' );
$foreign = $session; $foreign['credential'] = 'rotated-secret'; verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $foreign, '', null, $selected['context_token'] ) ), 'Rotated credential context token denied.' );
$new_settings = $settings; $new_settings['revision']++; verify( is_wp_error( YoRo_Support_Context::snapshot( $new_settings, $session, '', null, $selected['context_token'] ) ), 'Changed settings revision denies held context.' );
$disabled = $settings; $disabled['preferences']['features']['quote'] = false; verify( is_wp_error( YoRo_Support_Context::snapshot( $disabled, $session, '', null, $selected['context_token'] ) ), 'Disabled capability cannot reuse held context.' );
$expired_context = YoRo_Support_Transport::open( $selected['context_token'] ); $expired_context['expires'] = time() - 1;
verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $session, '', null, YoRo_Support_Transport::seal( $expired_context ) ) ), 'Expired encrypted context fails without pruning.' );
$authorization_revoked = true; verify( is_wp_error( YoRo_Support_Context::snapshot( $settings, $session, '', null, $selected['context_token'] ) ), 'Fresh API revocation fences context read before facts are returned.' );

$authorization_revoked = false;
update_option( YoRo_Customer_Support::OPTION, $settings + [ 'enabled' => true, 'protocol' => 2 ] );
$cookie = YoRo_Support_Transport::seal( $session ); $_COOKIE[ '__Host-yoro_support_' . get_current_blog_id() ] = $cookie;
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
}
echo wp_json_encode( [ 'result' => 'passed', 'suite' => $full_core ? 'full-core-native-commerce' : 'native-commerce', 'assertions' => $count, 'woocommerce' => WC_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'full_core' => $full_core, 'report_callbacks' => count( $report_hooks ), 'provider_calls' => 0, 'outbound_mail' => 0, 'scope' => 'disposable fixture only' ] ), "\n";
