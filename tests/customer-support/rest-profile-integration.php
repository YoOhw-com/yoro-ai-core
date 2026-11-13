<?php
/** Fresh process: real Core/Woo REST registrations after the native fulfillment feature is enabled. */
$root = realpath( $argv[1] ?? '' );
if ( ! $root || is_link( $argv[1] ) || 'YR-0214-disposable-wordpress' !== @file_get_contents( $root . '/.support-disposable' ) ) {
    throw new RuntimeException( 'Fresh disposable fixture required.' );
}
$managed_frontend = 'managed-frontend' === ( $argv[2] ?? '' );
if ( $managed_frontend ) {
    // A native principal in this disposable DB mirrors the managed frontend's feature-loading gate.
    $GLOBALS['wp_filter']['plugins_loaded'][PHP_INT_MIN][] = [ 'function' => static function () { wp_set_current_user( 1 ); }, 'accepted_args' => 0 ];
}
function wp_mail( ...$args ) { throw new RuntimeException( 'REST profile must not send mail.' ); }
define( 'YORO_AI_API_SITE_KEY', 'synthetic-transport-key' );
define( 'YORO_AI_API_URL', 'https://support-api-fixture.invalid/api/v1' );
require $root . '/wp-load.php';
if ( 'yoro_support_disposable' !== DB_NAME || 'https://support-fixture.invalid' !== home_url() || ! DISABLE_WP_CRON
    || ! class_exists( 'YoRo_Support_Commerce', false )
    || ! Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'fulfillments' ) ) {
    throw new RuntimeException( 'Real Core and enabled native fulfillments in disposable DB required.' );
}
$count = 0;
function check_rest_profile( $condition, $message ) { global $count; ++$count; if ( ! $condition ) { throw new RuntimeException( $message ); } }
if ( $managed_frontend ) {
    check_rest_profile( '11.2.2' === WC_VERSION && ! is_admin() && current_user_can( 'manage_woocommerce' )
        && 'yes' === get_option( 'woocommerce_show_marketplace_suggestions' ), 'Actual native managed frontend and marketplace feature context is loaded.' );
}
check_rest_profile( true === YoRo_Support_Commerce::profile(), 'Native profile is supported before REST registration.' );
$http = 0;
$deny_http = static function () use ( &$http ) { ++$http; throw new RuntimeException( 'REST profile must not call HTTP.' ); };
add_filter( 'pre_http_request', $deny_http, PHP_INT_MIN, 3 );
// Complete Woo's native one-time checkout-field migration only in this proven disposable fixture.
// The audited REST/profile phase retains a strict no-database-write assertion.
Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::get_phone_field_visibility();
Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::get_company_field_visibility();
Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::get_address_2_field_visibility();
// Native quote discovers shipping methods even for this isolated fixture; warm only its derived cache before auditing.
if ( $managed_frontend ) { wc_get_shipping_method_count(); }
$managed_hooks = [ 'woocommerce_admin_get_user_data_fields', 'woocommerce_admin_payment_gateway_connection_return', 'woocommerce_admin_preload_options', 'woocommerce_admin_shared_settings', 'woocommerce_after_shipping_zone_object_save', 'woocommerce_analytics_refund_fix_batch', 'woocommerce_component_settings_preload_endpoints', 'woocommerce_components_settings', 'woocommerce_debug_tools', 'woocommerce_get_note_from_db', 'woocommerce_new_product', 'woocommerce_rest_prepare_payment_gateway', 'woocommerce_run_on_woocommerce_admin_updated', 'woocommerce_settings_saved', 'woocommerce_shipping_zone_method_added', 'woocommerce_tax_rate_added', 'woocommerce_tax_rate_deleted', 'woocommerce_tracks_event_properties', 'woocommerce_update_options_site-visibility', 'woocommerce_update_product', 'woocommerce_updated' ];
$managed_events = [];
if ( $managed_frontend ) { foreach ( $managed_hooks as $name ) { $managed_events[$name] = [ did_action( $name ), did_filter( $name ) ]; } }
$queries_before = count( $GLOBALS['wpdb']->queries );
if ( ! defined( 'REST_REQUEST' ) ) { define( 'REST_REQUEST', true ); }
rest_get_server();
$events = [];
foreach ( array_keys( $GLOBALS['wp_filter'] ) as $name ) {
    if ( false !== strpos( $name, 'fulfillment' ) || 'woocommerce_settings-email_customer_refunded_order' === $name
        || in_array( $name, [ 'woocommerce_sections_tax', 'woocommerce_settings_tax', 'woocommerce_settings_save_tax' ], true ) ) { $events[ $name ] = did_action( $name ); }
}
check_rest_profile( true === YoRo_Support_Commerce::profile(), 'Real REST/email registrations preserve the native supported profile.' );
$foreign = static fn( $value ) => $value;
add_filter( 'woocommerce_fulfillment_created_notification', $foreign, 10, 3 );
check_rest_profile( is_wp_error( YoRo_Support_Commerce::profile() ), 'Foreign callback on a known native hook remains unsupported.' );
remove_filter( 'woocommerce_fulfillment_created_notification', $foreign, 10 );
$hook = 'woocommerce_update_options_email_customer_fulfillment_created';
$bindings = array_values( array_filter( $GLOBALS['wp_filter'][ $hook ]->callbacks[10] ?? [], static fn( $entry ) => is_array( $entry['function'] ) && $entry['function'][0] instanceof WC_Email_Customer_Fulfillment_Created && 'process_admin_options' === $entry['function'][1] ) );
check_rest_profile( 1 === count( $bindings ), 'One actual native email settings registration is available for exact-binding controls.' );
$entry = $bindings[0];
remove_filter( $hook, $entry['function'], 10 );
add_filter( $hook, $entry['function'], 11, 1 );
check_rest_profile( is_wp_error( YoRo_Support_Commerce::profile() ), 'Same native callable at a different priority remains unsupported.' );
remove_filter( $hook, $entry['function'], 11 );
add_filter( $hook, $entry['function'], 10, 2 );
check_rest_profile( is_wp_error( YoRo_Support_Commerce::profile() ), 'Same native callable with a different arity remains unsupported.' );
add_filter( $hook, $entry['function'], 10, 1 );
check_rest_profile( true === YoRo_Support_Commerce::profile(), 'Exact native registration restores support.' );
if ( $managed_frontend ) {
    $admin_hook = 'woocommerce_updated';
    $admin_bindings = array_values( array_filter( $GLOBALS['wp_filter'][$admin_hook]->callbacks[10] ?? [], static function ( $entry ) {
        if ( ! $entry['function'] instanceof Closure ) { return false; }
        $reflection = new ReflectionFunction( $entry['function'] );
        return realpath( $reflection->getFileName() ) === realpath( dirname( WC_PLUGIN_FILE ) . '/src/Admin/RemoteInboxNotifications/RemoteInboxNotificationsEngine.php' )
            && 54 === $reflection->getStartLine() && 68 === $reflection->getEndLine();
    } ) );
    check_rest_profile( 1 === count( $admin_bindings ), 'Actual native admin closure is present once.' );
    $native_admin = $admin_bindings[0]['function'];
    add_filter( $admin_hook, $foreign, 10, 1 );
    check_rest_profile( is_wp_error( YoRo_Support_Commerce::profile() ), 'Foreign callback on the observed admin hook remains unsupported.' );
    remove_filter( $admin_hook, $foreign, 10 );
    remove_filter( $admin_hook, $native_admin, 10 ); add_filter( $admin_hook, $native_admin, 11, 1 );
    check_rest_profile( is_wp_error( YoRo_Support_Commerce::profile() ), 'Native admin closure at another priority remains unsupported.' );
    remove_filter( $admin_hook, $native_admin, 11 ); add_filter( $admin_hook, $native_admin, 10, 2 );
    check_rest_profile( is_wp_error( YoRo_Support_Commerce::profile() ), 'Native admin closure with another arity remains unsupported.' );
    add_filter( $admin_hook, $native_admin, 10, 1 );
    check_rest_profile( true === YoRo_Support_Commerce::profile(), 'Exact native admin closure restores support.' );
    $product = wc_get_product( (int) ( $argv[3] ?? 0 ) );
    check_rest_profile( $product && 'SYNTHETIC-MANAGED-PROFILE' === $product->get_sku() && $product->is_virtual(), 'Native disposable quote fixture required.' );
    $original = [ WC()->cart, WC()->customer, WC()->session, $GLOBALS['current_user'] ?? null ];
    $quote = YoRo_Support_Commerce::quote( [ 'items' => [ [ 'product_id' => $product->get_id(), 'quantity' => 2 ] ],
        'destination' => [ 'country' => 'US', 'state' => 'CA', 'postcode' => '90210', 'city' => 'Beverly Hills' ] ], [] );
    check_rest_profile( ! is_wp_error( $quote ) && '38.00' === ( $quote['data']['total'] ?? null ), 'Actual native managed-context quote calculates the fixture total.' );
    check_rest_profile( $original === [ WC()->cart, WC()->customer, WC()->session, $GLOBALS['current_user'] ?? null ], 'Managed quote restores visitor cart/customer/session/principal.' );
    $after = []; foreach ( $managed_hooks as $name ) { $after[$name] = [ did_action( $name ), did_filter( $name ) ]; }
    check_rest_profile( $managed_events === $after, 'Profile, REST registration and quote emit none of the conditional admin hooks.' );
}

check_rest_profile( $events === array_map( 'did_action', array_combine( array_keys( $events ), array_keys( $events ) ) ), 'Profile inspection invokes no fulfillment/email/tax/settings event.' );
foreach ( array_slice( $GLOBALS['wpdb']->queries, $queries_before ) as $query ) {
    if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $query[0] ) ) { throw new RuntimeException( 'REST registration/profile attempted a database write.' ); }
}
check_rest_profile( 0 === $http, 'REST registration/profile performs no HTTP.' );
echo wp_json_encode( [ 'result' => 'passed', 'suite' => $managed_frontend ? 'native-managed-frontend-profile' : 'native-rest-profile', 'assertions' => $count, 'woocommerce' => WC_VERSION, 'provider_calls' => 0, 'outbound_mail' => 0 ] ), "\n";
