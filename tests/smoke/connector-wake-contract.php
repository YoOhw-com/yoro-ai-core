<?php
/** Wake replay/rate storage on the disposable WordPress MySQL database only. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Connector_Wake' ) ) { YoRo_AI_Core::init(); }
if ( ! class_exists( 'YoRo_Connector_Wake' ) ) { throw new RuntimeException( 'The wake endpoint is not bootstrapped.' ); }
if ( ! YoRo_Connector_Runtime::early_access_read_allowed() && YoRo_Connector_Wake::available() ) {
    throw new RuntimeException( 'A disabled ordinary runtime must not advertise wake readiness.' );
}
$request = new WP_REST_Request( 'POST', '/yoro/v1/connector-wake' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body( '{}' );
if ( ! is_wp_error( YoRo_Connector_Wake::permission( $request ) ) ) {
    throw new RuntimeException( 'An unsigned wake must never be admitted.' );
}
$local_root = getenv( 'YORO_WAKE_DISPOSABLE_ROOT' );
$ci_fixture = 'true' === getenv( 'CI' ) && 'http://yoro-plugin-smoke.test' === untrailingslashit( home_url() );
$local_fixture = is_string( $local_root ) && '' !== $local_root && realpath( $local_root ) === realpath( ABSPATH ) && 'yoro_yr0178_core_preview' === DB_NAME;
if ( ! $ci_fixture && ! $local_fixture ) {
    echo "Signed wake default-deny boot contract passed.\n";
    return;
}
global $wpdb;
$rate_key = YoRo_Connector_Wake::RATE_PREFIX . (int) floor( time() / 60 );
$previous_rate = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $rate_key ) );
$nonce = bin2hex( random_bytes( 32 ) );
$nonce_key = YoRo_Connector_Wake::NONCE_PREFIX . $nonce;
$second_key = YoRo_Connector_Wake::NONCE_PREFIX . bin2hex( random_bytes( 32 ) );
$consume = new ReflectionMethod( YoRo_Connector_Wake::class, 'consume' );
try {
    delete_option( $rate_key );
    if ( true !== $consume->invoke( null, $nonce ) ) { throw new RuntimeException( 'Atomic nonce insertion failed.' ); }
    $duplicate = $consume->invoke( null, $nonce );
    if ( ! is_wp_error( $duplicate ) || 409 !== $duplicate->get_error_data()['status'] ) {
        throw new RuntimeException( 'A duplicate nonce was admitted by MySQL.' );
    }
    $autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $nonce_key ) );
    if ( 'off' !== $autoload ) { throw new RuntimeException( 'Replay receipts must not be autoloaded.' ); }
    $wpdb->update( $wpdb->options, [ 'option_value' => '30' ], [ 'option_name' => $rate_key ] );
    $limited = $consume->invoke( null, substr( $second_key, strlen( YoRo_Connector_Wake::NONCE_PREFIX ) ) );
    if ( ! is_wp_error( $limited ) || 429 !== $limited->get_error_data()['status']
        || null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $second_key ) ) ) {
        throw new RuntimeException( 'A saturated MySQL budget admitted another nonce.' );
    }
    $rows = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $rate_key ) );
    if ( '30' !== $rows ) { throw new RuntimeException( 'A saturated counter must not keep growing.' ); }
} finally {
    delete_option( $nonce_key );
    delete_option( $second_key );
    null === $previous_rate ? delete_option( $rate_key ) : update_option( $rate_key, $previous_rate, false );
}
echo "Signed wake atomic MySQL replay and rate contracts passed.\n";
