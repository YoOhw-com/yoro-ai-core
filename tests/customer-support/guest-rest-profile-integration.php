<?php
/** Real guest non-Store REST bootstrap with existing native tracking consent; owned fixture only. */
$root = realpath( $argv[1] ?? '' );
if ( ! $root || is_link( $argv[1] ) || 'YR-0214-disposable-wordpress' !== @file_get_contents( $root . '/.support-disposable' ) ) {
    throw new RuntimeException( 'Fresh disposable fixture required.' );
}
function wp_mail( ...$args ) { throw new RuntimeException( 'Guest REST profile must not send mail.' ); }
$http = 0;
$deny_http = static function () use ( &$http ) { ++$http; throw new RuntimeException( 'GUEST_REST_HTTP_DENIED' ); };
$GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MIN][] = [ 'function' => $deny_http, 'accepted_args' => 3 ];
define( 'YORO_AI_API_SITE_KEY', 'synthetic-transport-key' );
define( 'YORO_AI_API_URL', 'https://support-api-fixture.invalid/api/v1' );
$_SERVER['HTTP_HOST'] = 'support-fixture.invalid'; $_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/yr0217-g056-quote-native-v8.php?rest_route=/yoro/v1/support/session';
$_SERVER['QUERY_STRING'] = 'rest_route=/yoro/v1/support/session';
$_SERVER['PHP_SELF'] = '/yr0217-g056-quote-native-v8.php'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'];
$_GET = [ 'rest_route' => '/yoro/v1/support/session' ]; $_COOKIE = [];
require $root . '/wp-load.php';
if ( 'yoro_support_disposable' !== DB_NAME || 'https://support-fixture.invalid' !== home_url() || ! DISABLE_WP_CRON
    || '11.2.2' !== WC_VERSION || ! class_exists( 'YoRo_Support_Commerce', false )
    || is_admin() || is_user_logged_in() || 0 !== get_current_user_id() || current_user_can( 'manage_woocommerce' )
    || 'yes' !== get_option( 'woocommerce_allow_tracking' ) ) { throw new RuntimeException( 'Real native guest and existing consent in disposable fixture required.' ); }
$count = 0;
function check_guest_rest_profile( $condition, $message ) { global $count; ++$count; if ( ! $condition ) { throw new RuntimeException( $message ); } }
add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) use ( &$http, $root, $argv ) {
    if ( '/yoro/v1/support/session' !== $request->get_route() || ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) { throw new RuntimeException( 'Actual native guest REST route required.' ); }
    // Intercept only this fixture's controller; native registration/router ran normally. This is not UI/auth acceptance.
    $hooks = [ 'woocommerce_addon_installed', 'woocommerce_admin_order_data_after_order_details', 'woocommerce_coupon_updated_props', 'woocommerce_helper_connect_start', 'woocommerce_helper_connected', 'woocommerce_helper_denied', 'woocommerce_helper_disconnected', 'woocommerce_helper_subscriptions_refresh', 'woocommerce_order_status_changed', 'woocommerce_page_wc-addons_connection_error', 'woocommerce_process_shop_order_meta', 'woocommerce_settings_page_init', 'woocommerce_update_non_option_setting', 'woocommerce_update_option', 'woocommerce_update_options' ]; $events = [];
    foreach ( $hooks as $name ) { $events[$name] = [ did_action( $name ), did_filter( $name ) ]; }
    $queued_names = static function () {
        if ( ! class_exists( 'WC_Tracks_Footer_Pixel', false ) ) { return []; }
        $instance = ( new ReflectionProperty( 'WC_Tracks_Footer_Pixel', 'instance' ) )->getValue();
        if ( ! $instance ) { return []; }
        $events = ( new ReflectionProperty( 'WC_Tracks_Footer_Pixel', 'events' ) )->getValue( $instance );
        return array_map( static fn( $event ) => is_string( $event->_en ?? null ) && preg_match( '/^[a-z0-9_]{1,100}$/D', $event->_en ) ? $event->_en : 'OTHER_OPAQUE', $events );
    };
    $queue_digest = static function () {
        if ( ! class_exists( 'WC_Tracks_Footer_Pixel', false ) ) { return hash( 'sha256', serialize( [] ) ); }
        $instance = ( new ReflectionProperty( 'WC_Tracks_Footer_Pixel', 'instance' ) )->getValue();
        $events = $instance ? ( new ReflectionProperty( 'WC_Tracks_Footer_Pixel', 'events' ) )->getValue( $instance ) : [];
        return hash( 'sha256', serialize( array_map( static fn( $event ) => get_object_vars( $event ), $events ) ) );
    };
    $queued_before = $queued_names(); $queue_digest_before = $queue_digest();
    $queries_before = count( $GLOBALS['wpdb']->queries ); $http_before = $http;
    check_guest_rest_profile( true === YoRo_Support_Commerce::profile(), 'Native guest REST tracking registrations are supported.' );
    $name = 'woocommerce_order_status_changed';
    $bindings = array_values( array_filter( $GLOBALS['wp_filter'][$name]->callbacks[10] ?? [], static fn( $entry ) => is_array( $entry['function'] )
        && $entry['function'][0] instanceof WC_Orders_Tracking && 'track_order_status_change' === $entry['function'][1] && 3 === $entry['accepted_args'] ) );
    check_guest_rest_profile( 1 === count( $bindings ), 'One exact observed native tracking registration is loaded.' );
    $native = $bindings[0]['function']; $foreign = static fn( $value ) => $value;
    add_action( $name, $foreign, 10, 3 );
    check_guest_rest_profile( is_wp_error( YoRo_Support_Commerce::profile() ), 'Foreign callback on the known tracking hook remains unsupported.' );
    remove_action( $name, $foreign, 10 );
    remove_action( $name, $native, 10 ); add_action( $name, $native, 11, 3 );
    check_guest_rest_profile( is_wp_error( YoRo_Support_Commerce::profile() ), 'Different native tracking priority remains unsupported.' );
    remove_action( $name, $native, 11 ); add_action( $name, $native, 10, 4 );
    check_guest_rest_profile( is_wp_error( YoRo_Support_Commerce::profile() ), 'Different native tracking arity remains unsupported.' );
    remove_action( $name, $native, 10 ); add_action( $name, [ $native[0], 'track_created_date_change' ], 10, 3 );
    check_guest_rest_profile( is_wp_error( YoRo_Support_Commerce::profile() ), 'Another method in the same native file remains unsupported.' );
    remove_action( $name, [ $native[0], 'track_created_date_change' ], 10 ); add_action( $name, $native, 10, 3 );
    check_guest_rest_profile( true === YoRo_Support_Commerce::profile(), 'Restoring the exact native registration restores support.' );
    $product = wc_get_product( (int) ( $argv[2] ?? 0 ) );
    check_guest_rest_profile( $product && 'SYNTHETIC-GUEST-REST-TRACKING' === $product->get_sku() && $product->is_virtual(), 'Native disposable quote fixture required.' );
    $original = [ WC()->cart, WC()->customer, WC()->session, $GLOBALS['current_user'] ?? null ];
    $quote = YoRo_Support_Commerce::quote( [ 'items' => [ [ 'product_id' => $product->get_id(), 'quantity' => 2 ] ],
        'destination' => [ 'country' => 'US', 'state' => 'CA', 'postcode' => '90210', 'city' => 'Beverly Hills' ] ], [] );
    check_guest_rest_profile( ! is_wp_error( $quote ) && '38.00' === ( $quote['data']['total'] ?? null ), 'Native guest REST quote calculates the fixture total.' );
    check_guest_rest_profile( $original === [ WC()->cart, WC()->customer, WC()->session, $GLOBALS['current_user'] ?? null ], 'Quote restores visitor cart/customer/session/principal.' );
    $after = []; foreach ( $hooks as $name ) { $after[$name] = [ did_action( $name ), did_filter( $name ) ]; }
    check_guest_rest_profile( $events === $after, 'Profile and quote invoke none of the tracking persistence/settings/helper hooks.' );
    foreach ( array_slice( $GLOBALS['wpdb']->queries, $queries_before ) as $query ) {
        if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $query[0] ) ) { throw new RuntimeException( 'Guest profile/quote attempted a database write.' ); }
    }
    check_guest_rest_profile( $queued_before === $queued_names() && $queue_digest_before === $queue_digest(), 'Profile and quote preserve the opaque baseline telemetry queue exactly.' );
    check_guest_rest_profile( $http_before === $http && 0 === $http, 'Native registration/profile/quote performs no HTTP.' );
    $later_calls = 0; $later = static function () use ( &$later_calls ) { ++$later_calls; return false; };
    add_filter( 'pre_http_request', $later, PHP_INT_MAX, 3 ); $denied = false;
    try { wp_remote_get( 'https://blocked-transport-fixture.invalid/' ); } catch ( RuntimeException $error ) { $denied = 'GUEST_REST_HTTP_DENIED' === $error->getMessage(); }
    remove_filter( 'pre_http_request', $later, PHP_INT_MAX );
    check_guest_rest_profile( $denied && 0 === $later_calls && 1 === $http, 'Throw fence prevents an adversarial later filter from enabling outbound.' );
    echo wp_json_encode( [ 'result' => 'quote-phase-assertions-passed', 'suite' => 'native-guest-rest-tracking-profile', 'assertions' => $GLOBALS['count'], 'woocommerce' => WC_VERSION,
        'guest' => true, 'tracking_hooks' => count( $hooks ), 'queued_before' => $queued_before, 'queued_after' => $queued_names(), 'opaque_queue_unchanged' => $queue_digest_before === $queue_digest(), 'HTTP_negative_control_denied' => $http, 'provider_calls' => 0, 'outbound_mail' => 0,
        'qualification' => 'Quote-phase assertions only. Preserve actual process exit and any baseline shutdown denial separately; this JSON is never whole-process PASS.' ] ), "\n";
    exit( 0 );
}, PHP_INT_MIN, 3 );
wp(); throw new RuntimeException( 'Native guest REST route was not dispatched.' );
