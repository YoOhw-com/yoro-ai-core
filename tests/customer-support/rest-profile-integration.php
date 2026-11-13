<?php
/** Fresh process: real Core/Woo REST registrations after the native fulfillment feature is enabled. */
$root = realpath( $argv[1] ?? '' );
if ( ! $root || is_link( $argv[1] ) || 'YR-0214-disposable-wordpress' !== @file_get_contents( $root . '/.support-disposable' ) ) {
    throw new RuntimeException( 'Fresh disposable fixture required.' );
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
check_rest_profile( true === YoRo_Support_Commerce::profile(), 'Native profile is supported before REST registration.' );
$http = 0;
$deny_http = static function () use ( &$http ) { ++$http; throw new RuntimeException( 'REST profile must not call HTTP.' ); };
add_filter( 'pre_http_request', $deny_http, PHP_INT_MIN, 3 );
// Complete Woo's native one-time checkout-field migration only in this proven disposable fixture.
// The audited REST/profile phase retains a strict no-database-write assertion.
Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::get_phone_field_visibility();
Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::get_company_field_visibility();
Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::get_address_2_field_visibility();
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
check_rest_profile( $events === array_map( 'did_action', array_combine( array_keys( $events ), array_keys( $events ) ) ), 'Profile inspection invokes no fulfillment/email/tax/settings event.' );
foreach ( array_slice( $GLOBALS['wpdb']->queries, $queries_before ) as $query ) {
    if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $query[0] ) ) { throw new RuntimeException( 'REST registration/profile attempted a database write.' ); }
}
check_rest_profile( 0 === $http, 'REST registration/profile performs no HTTP.' );
echo wp_json_encode( [ 'result' => 'passed', 'suite' => 'native-rest-profile', 'assertions' => $count, 'woocommerce' => WC_VERSION, 'provider_calls' => 0, 'outbound_mail' => 0 ] ), "\n";
