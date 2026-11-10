<?php
/** Native WP/Woo DTOs through actual API source validation/settlement and actual Core transport/consumer. */
$root = realpath( $argv[1] ?? '' );
if ( ! $root || ! is_file( $root . '/.support-disposable' ) || 'YR-0214-disposable-wordpress' !== file_get_contents( $root . '/.support-disposable' ) ) { throw new RuntimeException( 'Owned native fixture required.' ); }
$fixture = json_decode( file_get_contents( $argv[2] ), true, flags: JSON_THROW_ON_ERROR );
function wp_mail( ...$args ) { throw new RuntimeException( 'Wire fixture must not send mail.' ); }
require $root . '/wp-load.php';
if ( 'yoro_support_disposable' !== DB_NAME || 'https://support-fixture.invalid' !== home_url() || ! WP_HTTP_BLOCK_EXTERNAL || ! DISABLE_WP_CRON ) { throw new RuntimeException( 'Owned blocked native profile required.' ); }
$count = 0;
function wire_check( $condition, $why ) { global $count; ++$count; if ( ! $condition ) { throw new RuntimeException( $why ); } }
function wire_api( $phase, $input = '' ) {
    global $fixture;
    $command = [ PHP_BINARY, __DIR__ . '/api-wire-probe.php', $fixture['api_root'], $fixture['dependencies'], $phase ];
    $process = proc_open( $command, [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
    if ( ! is_resource( $process ) ) { throw new RuntimeException( 'API fixture process failed.' ); }
    fwrite( $pipes[0], $input ); fclose( $pipes[0] );
    $output = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
    $error = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
    if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( 'API source fixture failed: ' . $error . $output ); }
    return json_decode( $output, true, flags: JSON_THROW_ON_ERROR );
}
wire_check( $fixture['woo'] === function_exists( 'wc_get_product' ), 'Actual fresh native process has the selected Woo on/off state.' );
$page = wp_insert_post( [ 'import_id' => 11, 'post_title' => 'Wire policy', 'post_content' => 'Native wire fixture policy.', 'post_type' => 'page', 'post_status' => 'publish' ] );
wire_check( 11 === $page, 'Actual approved native page retains the API fixture source identity.' );
if ( $fixture['woo'] ) {
    $product = new WC_Product_Simple(); $product->set_name( 'Wire product' ); $product->set_regular_price( '90' ); $product->set_sale_price( '62' ); $product->set_status( 'publish' ); $product->save();
}
$settings_receipt = wire_api( 'settings' );
$settings = $settings_receipt['settings_response']['settings'];
update_option( 'yoro_customer_support_v1', $settings );
class YoRo_AI_Settings {
    public static function get_ai_api_site_key() { return 'synthetic-wire-site-key'; }
    public static function get_ai_api_url() { return 'https://api-wire.invalid/api/v1'; }
}
require $fixture['dependencies_core'] . '/includes/ai/clients/class-yoro-ai-api-endpoint-resolver.php';
require $fixture['core_root'] . '/includes/customer-support/class-yoro-support-transport.php';
require $fixture['core_root'] . '/includes/customer-support/class-yoro-support-facts.php';
require $fixture['core_root'] . '/includes/customer-support/class-yoro-customer-support.php';
wire_check( str_starts_with( ( new ReflectionClass( YoRo_Customer_Support::class ) )->getFileName(), $fixture['core_root'] . '/' ) && str_starts_with( ( new ReflectionClass( YoRo_Support_Facts::class ) )->getFileName(), $fixture['core_root'] . '/' ), 'Actual native producer and consumer classes come from the selected exact Core source.' );
wire_check( YoRo_Customer_Support::settings() === $settings, 'Actual API settings DTO is consumed without reconstruction by actual Core settings.' );
$session = [ 'session_id' => 'synthetic-wire-session', 'credential' => 'synthetic-wire-credential', 'revision' => 1, 'expires' => time() + DAY_IN_SECONDS ];
if ( method_exists( YoRo_Customer_Support::class, 'snapshot' ) ) {
    $snapshot = ( new ReflectionMethod( YoRo_Customer_Support::class, 'snapshot' ) )->invoke( null, $settings, $session, 'Wire', null, '' );
} else { $snapshot = YoRo_Support_Facts::snapshot( $settings, 'Wire' ); }
wire_check( ! is_wp_error( $snapshot ) && $snapshot['commerce'] === $fixture['woo'] && count( $snapshot['sources'] ) >= 1, 'Actual Core native producer retains public support in both Woo states.' );
wire_check( in_array( 'content:11', array_column( $snapshot['sources'], 'id' ), true ), 'Actual native approved content enters the wire DTO.' );
wire_check( $fixture['woo'] === in_array( 'product', array_column( $snapshot['sources'], 'type' ), true ), 'Actual Woo state controls actual native product facts.' );
$receipt = wire_api( 'answer', wp_json_encode( $snapshot ) );
wire_check( $receipt['validated_snapshot'] === $snapshot && $receipt['owned_cleanup'] && 1 === $receipt['durable_consume_rows'], 'Actual API validates the unmodified native producer DTO and settles exactly one durable consume.' );
$completed = $receipt['completed_response'];
$requests = [];
$filter = static function( $pre, $args, $url ) use ( &$requests, $completed ) {
    $requests[] = [ 'url' => $url, 'method' => $args['method'], 'body' => json_decode( $args['body'], true ) ];
    if ( 'https://api-wire.invalid/api/v1/customer-support/turns' !== $url || 'POST' !== $args['method'] ) { throw new RuntimeException( 'Unexpected Core wire request.' ); }
    return [ 'headers' => [], 'body' => wp_json_encode( $completed ), 'response' => [ 'code' => 200 ] ];
};
add_filter( 'pre_http_request', $filter, 1, 3 );
$cookie = YoRo_Support_Transport::seal( $session ); $_COOKIE['__Host-yoro_support_1'] = $cookie; $_SERVER['HTTPS'] = 'on';
$request = new WP_REST_Request( 'POST' ); $request->set_body_params( [ 'turn_id' => '12345678-1234-4123-8123-123456789abc', 'message' => 'Wire' ] );
$request->set_header( 'Origin', home_url() ); $request->set_header( 'X-YoRo-Support-CSRF', YoRo_Support_Transport::csrf( $cookie ) );
wire_check( true === YoRo_Customer_Support::permission( $request ), 'Native same-origin permission remains active in the wire consumer.' );
$result = YoRo_Customer_Support::turn( $request );
remove_filter( 'pre_http_request', $filter, 1 );
wire_check( $result instanceof WP_REST_Response && 'Native wire fixture policy.' === $result->get_data()['answer']['text'], 'Actual Core turn consumes the actual API completed-answer DTO through actual fake HTTP transport.' );
wire_check( 1 === count( $requests ) && $requests[0]['body']['snapshot'] === $snapshot, 'Actual transport serializes the native producer DTO unchanged and performs one contained API request.' );
wire_check( 'Wire policy' === $result->get_data()['answer']['sources'][0]['title'] && str_starts_with( $result->get_data()['answer']['sources'][0]['url'], home_url() ), 'Actual Core resolves API citation against current native facts.' );
$forged = $completed['answer']; $forged['sources'] = [ [ 'id' => 'content:999999', 'url' => 'https://foreign.invalid/' ] ];
wire_check( [] === YoRo_Customer_Support::safe_answer( $forged, $snapshot )['sources'], 'Actual Core consumer drops a forged citation absent from current native facts.' );
wire_check( count( $receipt['rejected_native_dto_mutations'] ) === 3, 'Actual API consumer rejects unknown-field, foreign-host and unselected-source native DTO mutations.' );
if ( class_exists( YoRo_Support_Context::class, false ) ) {
    $capabilities = YoRo_Support_Context::capabilities( $settings );
    wire_check( $capabilities['content'] && $capabilities['products'] === $fixture['woo'], 'Current Core capability DTO retains actual native Woo state and public support.' );
    wire_check( ! $capabilities['quote'] && ! $capabilities['orders'], 'Public-only API settings never enable new private or quote controls.' );
}
echo wp_json_encode( [ 'result' => 'passed', 'assertions' => $count, 'fixture' => $fixture, 'settings_receipt' => $settings_receipt, 'core_producer_file' => ( new ReflectionClass( YoRo_Support_Facts::class ) )->getFileName(), 'core_consumer_file' => ( new ReflectionClass( YoRo_Customer_Support::class ) )->getFileName(), 'native_snapshot' => $snapshot, 'api_receipt' => $receipt, 'core_consumer_response' => $result->get_data(), 'wire_requests' => $requests,
    'wordpress' => $GLOBALS['wp_version'], 'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null, 'provider_requests' => 0, 'real_mail' => 0, 'scope' => 'actual source/native DTO conformance with fake HTTP/model only; synthetic cookie is not runtime auth proof' ] ), "\n";
