<?php
// Signed notification boundary, without network or a live WordPress installation.
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    public function __construct( public $code, public $message, public $data = [] ) {}
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_salt( $key ) { return 'fixture-private-salt-' . $key; }
function wp_parse_url( $url, $part ) { return parse_url( $url, $part ); }
function rest_url( $path ) { return 'https://shop.example/wp-json/' . $path; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; $GLOBALS['autoload'][ $key ] = $autoload; return true; }
function register_rest_route( $namespace, $path, $args ) { $GLOBALS['route'] = [ $namespace, $path, $args ]; }
class WP_REST_Request {
    public $query = []; public $files = []; public $headers = [ 'content-type' => 'application/json' ];
    public function __construct( public $body, public $method = 'POST' ) {}
    public function get_method() { return $this->method; }
    public function get_query_params() { return $this->query; }
    public function get_file_params() { return $this->files; }
    public function get_header( $name ) { return $this->headers[ $name ] ?? ''; }
    public function get_body() { return $this->body; }
}
class WP_REST_Response {
    public $headers = [];
    public function __construct( public $body, public $status ) {}
    public function header( $key, $value ) { $this->headers[ $key ] = $value; }
}
class YoRo_AI_Settings {
    public static $key = 'private-site-key';
    public static function get_ai_api_status() { return [ 'connector_runtime' => [ 'connector_connection_id' => 'connector-fixture' ], 'credential' => [ 'id' => 42 ] ]; }
    public static function get_installation_uuid() { return 'installation-fixture'; }
    public static function get_ai_api_site_key() { return self::$key; }
}
class YoRo_Connector_Runtime {
    public static $allowed = true; public static $runs = 0; public static $busy = false;
    public static function early_access_read_allowed() { return self::$allowed; }
    public function run_early_access_wake() { self::$runs++; return self::$busy ? new WP_Error( 'yoro_wake_busy', 'Busy' ) : [ 'processed' => 1 ]; }
}
class YoRo_Connector_Client {
    public $calls = []; public $denied = false;
    public function register_early_access_wake( $payload ) { $this->calls[] = $payload; return $this->denied ? new WP_Error( 'unavailable', 'Unavailable' ) : [ 'success' => true ]; }
}
class Wake_Database {
    public $options = 'fixture_options'; public $nonces = []; public $rate = 0; public $fail = false;
    public function prepare( $sql, ...$values ) { return [ $sql, $values ]; }
    public function esc_like( $value ) { return addcslashes( $value, '_%' ); }
    public function query( $query ) {
        [ $sql, $values ] = $query;
        if ( $this->fail ) { return false; }
        if ( str_starts_with( $sql, 'DELETE' ) ) { return 0; }
        if ( str_contains( $sql, 'ON DUPLICATE KEY UPDATE' ) ) { return $this->rate++ < 30 ? 1 : 0; }
        if ( isset( $this->nonces[ $values[0] ] ) ) { return 0; }
        $this->nonces[ $values[0] ] = true;
        return 1;
    }
}
$GLOBALS['wpdb'] = new Wake_Database();
require dirname( __DIR__, 2 ) . '/includes/commerce/class-yoro-connector-wake.php';
$assertions = 0;
$assert = static function( $value, $message ) use ( &$assertions ) { $assertions++; if ( ! $value ) { throw new RuntimeException( $message ); } };
$client = new YoRo_Connector_Client();
YoRo_Connector_Wake::maybe_register( $client );
$assert( 1 === count( $client->calls ) && 1 === $client->calls[0]['version'], 'Wake registers its versioned endpoint once.' );
$secret = $client->calls[0]['secret'];
$assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $secret ), 'Wake uses 256 bits of random signing material.' );
$assert( ! str_contains( json_encode( $GLOBALS['options'] ), $secret ) && false === $GLOBALS['autoload'][ YoRo_Connector_Wake::OPTION ], 'Signing material is encrypted and not autoloaded.' );
YoRo_Connector_Wake::maybe_register( $client );
$assert( 1 === count( $client->calls ), 'Successful registration must not repeat every poll.' );
YoRo_Connector_Wake::register_routes();
$assert( [ 'YoRo_Connector_Wake', 'permission' ] === $GLOBALS['route'][2]['permission_callback'], 'The public endpoint must have a signed permission callback.' );
$body = [ 'version' => 1, 'connection_id' => 'connector-fixture', 'installation_uuid' => 'installation-fixture', 'site_key_id' => 42, 'timestamp' => time(), 'nonce' => bin2hex( random_bytes( 32 ) ) ];
$request = static function( $body, $raw = null ) use ( $secret ) {
    $req = new WP_REST_Request( $raw ?? wp_json_encode( $body ) );
    $req->headers['x-yoro-wake-signature'] = hash_hmac( 'sha256', "yoro-wake-v1\n" . $req->body, $secret );
    return $req;
};
$req = $request( $body );
$assert( true === YoRo_Connector_Wake::permission( $req ) && true === YoRo_Connector_Wake::permission( $req ), 'Permission checking is stateless and repeatable.' );
foreach ( [ 'connection_id' => 'another-connection', 'installation_uuid' => 'another-installation', 'site_key_id' => '42', 'version' => 2, 'timestamp' => time() - 61, 'nonce' => 'not-a-nonce', 'command' => 'execute' ] as $key => $value ) {
    $invalid = array_merge( $body, [ $key => $value ] );
    $assert( is_wp_error( YoRo_Connector_Wake::permission( $request( $invalid ) ) ), 'Reject an authenticated invalid field: ' . $key );
}
$assert( is_wp_error( YoRo_Connector_Wake::permission( $request( $body, substr( wp_json_encode( $body ), 0, -1 ) . ',"version":1}' ) ) ), 'Duplicate JSON keys are rejected, even with a valid signature.' );
$req = $request( $body ); $req->headers['x-yoro-wake-signature'] = str_repeat( 'a', 64 );
$assert( is_wp_error( YoRo_Connector_Wake::permission( $req ) ), 'A forged signature cannot wake the runner.' );
$req = $request( $body ); $req->query['user_id'] = 1;
$assert( is_wp_error( YoRo_Connector_Wake::permission( $req ) ), 'Query selectors are rejected.' );
$req = $request( $body ); $req->files['upload'] = 'x';
$assert( is_wp_error( YoRo_Connector_Wake::permission( $req ) ), 'File payloads are rejected.' );
$req = $request( $body ); $req->method = 'GET';
$assert( is_wp_error( YoRo_Connector_Wake::permission( $req ) ), 'Only POST notifications are accepted.' );
$req = $request( $body ); $req->headers['content-type'] = 'text/plain';
$assert( is_wp_error( YoRo_Connector_Wake::permission( $req ) ), 'Non-JSON bodies are rejected.' );
$req = $request( $body );
$result = YoRo_Connector_Wake::handle( $req );
$assert( $result instanceof WP_REST_Response && [ 'success' => true, 'processed' => 1 ] === $result->body, 'Wake returns a minimal receipt without operation data.' );
$assert( 'no-store, private' === $result->headers['Cache-Control'], 'Receipts cannot be cached.' );
$result = YoRo_Connector_Wake::handle( $req );
$assert( is_wp_error( $result ) && 409 === $result->data['status'] && 1 === YoRo_Connector_Runtime::$runs, 'An authenticated replay never reaches the runner.' );
$body['nonce'] = bin2hex( random_bytes( 32 ) ); YoRo_Connector_Runtime::$busy = true;
$result = YoRo_Connector_Wake::handle( $request( $body ) );
$assert( is_wp_error( $result ) && 409 === $result->data['status'], 'Busy locks signal retry; they do not pretend to process work.' );
YoRo_Connector_Runtime::$busy = false;
$GLOBALS['wpdb']->rate = 30; $body['nonce'] = bin2hex( random_bytes( 32 ) );
$result = YoRo_Connector_Wake::handle( $request( $body ) );
$assert( is_wp_error( $result ) && 429 === $result->data['status'] && 2 === YoRo_Connector_Runtime::$runs, 'Signed wake requests have a bounded per-minute budget.' );
$GLOBALS['wpdb']->rate = 0; $GLOBALS['wpdb']->fail = true;
$result = YoRo_Connector_Wake::handle( $request( $body ) );
$assert( is_wp_error( $result ) && 2 === YoRo_Connector_Runtime::$runs, 'Replay-store failure denies execution.' );
$GLOBALS['wpdb']->fail = false;
YoRo_Connector_Runtime::$allowed = false;
$assert( is_wp_error( YoRo_Connector_Wake::permission( $request( $body ) ) ), 'Disabling the ordinary runtime invalidates wake immediately.' );
YoRo_Connector_Runtime::$allowed = true;
YoRo_AI_Settings::$key = 'rotated-private-site-key';
$assert( is_wp_error( YoRo_Connector_Wake::permission( $request( $body ) ) ), 'Site credential rotation invalidates the old signing material.' );
YoRo_Connector_Wake::maybe_register( $client );
$assert( 2 === count( $client->calls ) && $secret !== $client->calls[1]['secret'], 'A new site credential registers a fresh independent secret.' );
echo "Signed connector wake boundary passed ({$assertions} assertions).\n";
