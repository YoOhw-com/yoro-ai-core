<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    public function __construct( public $code, public $message, public $data = [] ) {}
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $url, $part ) { return parse_url( $url, $part ); }
function untrailingslashit( $url ) { return rtrim( $url, '/' ); }
function esc_url_raw( $url ) { return $url; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_remote_request( $url, $args ) { $GLOBALS['requests'][] = [ $url, $args ]; return $GLOBALS['response']; }
function wp_remote_retrieve_response_code( $value ) { return $value['response']['code'] ?? 200; }
function wp_remote_retrieve_body( $value ) { return $value['body'] ?? ''; }
class YoRo_Connector_Runtime {
    public static $allowed = true;
    public static function early_access_read_allowed() { return self::$allowed; }
}
class YoRo_AI_Settings {
    public static $api = 'https://api.example/api/v1';
    public static function get_ai_api_url() { return self::$api; }
    public static function get_ai_api_site_key() { return 'fixture-private-key'; }
}
require dirname( __DIR__, 2 ) . '/includes/commerce/class-yoro-connector-client.php';
$assert = static function( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } };
$GLOBALS['requests'] = []; $GLOBALS['response'] = [ 'response' => [ 'code' => 200 ], 'body' => '{"success":true}' ];
$payload = [ 'version' => 1, 'secret' => str_repeat( 'b', 64 ), 'endpoint' => 'https://shop.example/wp-json/yoro/v1/connector-wake' ];
$client = new YoRo_Connector_Client();
$assert( [ 'success' => true ] === $client->register_early_access_wake( $payload ), 'Registration accepts the exact minimal API success receipt.' );
[ $url, $args ] = $GLOBALS['requests'][0];
$assert( 'https://api.example/api/v1/early-access/wake/register' === $url && wp_json_encode( $payload ) === $args['body'], 'Registration must use the configured API and exact protocol payload.' );
$assert( 'POST' === $args['method'] && 10 === $args['timeout'] && 0 === $args['redirection'] && true === $args['sslverify'] && 2048 === $args['limit_response_size'], 'Secret registration has bounded, verified HTTPS with no redirects.' );
$assert( 'Bearer fixture-private-key' === $args['headers']['Authorization'], 'Only the current site credential authenticates registration.' );
$assert( [ 'transport' => [] ] === $client->early_access_transport_metrics(), 'Registration is separate from the operation-delivery budget.' );
foreach ( [ 302, 401, 403, 503 ] as $status ) {
    $GLOBALS['response']['response']['code'] = $status;
    $assert( is_wp_error( $client->register_early_access_wake( $payload ) ), 'An unsuccessful registration remains unavailable.' );
}
$GLOBALS['requests'] = []; YoRo_AI_Settings::$api = 'http://api.example/api/v1';
$assert( is_wp_error( $client->register_early_access_wake( $payload ) ) && [] === $GLOBALS['requests'], 'Signing secrets must never be registered over plaintext HTTP.' );
YoRo_AI_Settings::$api = 'https://api.example/api/v1'; YoRo_Connector_Runtime::$allowed = false;
$assert( is_wp_error( $client->register_early_access_wake( $payload ) ) && [] === $GLOBALS['requests'], 'Disabled ordinary runtime must not register wake transport.' );
echo "Connector wake HTTPS registration contract passed.\n";
