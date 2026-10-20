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
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ $name ] ?? ''; }
function __( $value, $domain = null ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_html_e( $value, $domain = null ) { echo esc_html( $value ); }
function wp_date( $format, $timestamp ) { return gmdate( 'Y-m-d H:i', $timestamp ); }
function wp_salt( $key ) { return 'fixture-private-salt-' . $key; }
function wp_parse_url( $url, $part ) { return parse_url( $url, $part ); }
function rest_url( $path ) { return 'https://shop.example/wp-json/' . $path; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) {
    if ( ! empty( $GLOBALS['fail_health_write'] ) && YoRo_Connector_Wake::HEALTH_OPTION === $key ) { return false; }
    $GLOBALS['options'][ $key ] = $value; $GLOBALS['autoload'][ $key ] = $autoload; return true;
}
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
$assert( ! YoRo_Connector_Wake::available(), 'Registration alone must not slow polling before a real wake succeeds.' );
$assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $secret ), 'Wake uses 256 bits of random signing material.' );
$assert( ! str_contains( json_encode( $GLOBALS['options'] ), $secret ) && false === $GLOBALS['autoload'][ YoRo_Connector_Wake::OPTION ], 'Signing material is encrypted and not autoloaded.' );
YoRo_Connector_Wake::maybe_register( $client );
$assert( 1 === count( $client->calls ), 'Successful registration must not repeat every poll.' );
$health_response = static function( $state = 'healthy', $overrides = [] ) {
    return [ 'headers' => array_merge( [ 'x-yoro-wake-state' => $state, 'x-yoro-wake-observed-at' => (string) time(),
        'x-yoro-wake-last-success-at' => (string) ( time() - 1 ), 'x-yoro-wake-last-failure-at' => '0',
        'x-yoro-wake-retry-after' => '0' ], $overrides ) ];
};
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time(), 'x-yoro-wake-last-failure-at' => (string) time() ] ), true );
$assert( YoRo_Connector_Wake::available() && false === $GLOBALS['autoload'][ YoRo_Connector_Wake::HEALTH_OPTION ], 'A validated real success enables slower polling with nonautoload telemetry.' );
$before_registration = YoRo_Connector_Wake::health();
$GLOBALS['options'][ YoRo_Connector_Wake::OPTION ]['next_attempt'] = time() - 1;
YoRo_Connector_Wake::maybe_register( $client );
$assert( $before_registration === YoRo_Connector_Wake::health(), 'Same-key registration does not reset existing transport health.' );
YoRo_Connector_Wake::record_response( $health_response( 'blocked', [ 'x-yoro-wake-last-failure-at' => (string) time(), 'x-yoro-wake-retry-after' => '600' ] ), true );
$assert( ! YoRo_Connector_Wake::available() && 'blocked' === YoRo_Connector_Wake::health()['state'], 'A hosting challenge immediately restores the fast polling fallback.' );
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-observed-at' => (string) ( time() - 1 ), 'x-yoro-wake-last-success-at' => (string) ( time() - 1 ) ] ), true );
$assert( ! YoRo_Connector_Wake::available() && 'blocked' === YoRo_Connector_Wake::health()['state'], 'An older healthy reply cannot overwrite a newer hosting challenge.' );
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time() ] ), true );
$assert( ! YoRo_Connector_Wake::available(), 'An old success without the latest failure cannot clear a challenge within the same second.' );
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-observed-at' => (string) ( time() + 1 ) ] ), true );
$assert( ! YoRo_Connector_Wake::available(), 'Malformed or future replies cannot turn a blocked connection healthy.' );
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time(), 'x-yoro-wake-last-failure-at' => (string) time() ] ), true );
$assert( YoRo_Connector_Wake::available(), 'A new successful receipt recovers healthy transport after a hosting challenge.' );
foreach ( [ [ 'x-yoro-wake-state' => 'raw-error' ], [ 'x-yoro-wake-observed-at' => time() ],
    [ 'x-yoro-wake-observed-at' => (string) ( time() + 1 ) ], [ 'x-yoro-wake-observed-at' => (string) ( time() - 901 ) ],
    [ 'x-yoro-wake-last-success-at' => (string) ( time() + 1 ) ], [ 'x-yoro-wake-last-success-at' => (string) ( time() - 901 ) ],
    [ 'x-yoro-wake-last-failure-at' => (string) ( time() + 1 ) ], [ 'x-yoro-wake-retry-after' => '601' ],
    [ 'x-yoro-wake-retry-after' => '1' ], [ 'x-yoro-wake-last-success-at' => [] ], [ 'x-yoro-wake-retry-after' => '' ] ] as $invalid ) {
    YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time(), 'x-yoro-wake-last-failure-at' => (string) time() ] ), true );
    YoRo_Connector_Wake::record_response( $health_response( 'healthy', $invalid ), true );
    $assert( ! YoRo_Connector_Wake::available() && 'unknown' === YoRo_Connector_Wake::health()['state'], 'Malformed, expired or future telemetry cannot suppress polling.' );
}
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time(), 'x-yoro-wake-last-failure-at' => (string) time() ] ), true );
YoRo_Connector_Wake::record_response( [ 'headers' => [] ], true );
$assert( ! YoRo_Connector_Wake::available(), 'Missing headers restore fast polling for older APIs.' );
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time(), 'x-yoro-wake-last-failure-at' => (string) time() ] ), true );
YoRo_Connector_Wake::record_response( $health_response(), false );
$assert( ! YoRo_Connector_Wake::available(), 'An error or untrusted response cannot keep a healthy hint alive.' );
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time(), 'x-yoro-wake-last-failure-at' => (string) time() ] ), true );
$GLOBALS['options'][ YoRo_Connector_Wake::HEALTH_OPTION ]['observed_at'] = time() - 901;
$assert( ! YoRo_Connector_Wake::available(), 'Stored telemetry expires even without another API response.' );
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time(), 'x-yoro-wake-last-failure-at' => (string) time() ] ), true );
YoRo_Connector_Wake::record_poll_cooldown( 600 );
$assert( 600 === YoRo_Connector_Wake::poll_retry_after(), 'Rate cooldown survives the current worker session.' );
$GLOBALS['options'][ YoRo_Connector_Wake::POLL_OPTION ]['until'] = time() + 601;
$assert( 0 === YoRo_Connector_Wake::poll_retry_after(), 'Corrupted or future cooldowns cannot suspend polling indefinitely.' );
require dirname( __DIR__, 2 ) . '/includes/admin/class-yoro-admin-page.php';
$render_status = new ReflectionMethod( YoRo_Admin_Page::class, 'render_transport_status' );
ob_start(); $render_status->invoke( null, true ); $healthy_markup = ob_get_clean();
$assert( str_contains( $healthy_markup, 'Fast updates available' ) && str_contains( $healthy_markup, 'Last successful fast update:' ) && ! str_contains( $healthy_markup, $secret ), 'Connection UI renders useful success feedback without credentials.' );
YoRo_Connector_Wake::record_response( $health_response( 'blocked', [ 'x-yoro-wake-last-failure-at' => (string) time(), 'x-yoro-wake-retry-after' => '600' ] ), true );
ob_start(); $render_status->invoke( null, true ); $blocked_markup = ob_get_clean();
$assert( str_contains( $blocked_markup, 'Fast updates delayed by hosting' ) && str_contains( $blocked_markup, 'Polling worker not yet verified' ) && ! str_contains( $blocked_markup, 'raw-error' ), 'Hosting failures and unverified polling render distinct scoped guidance.' );
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time(), 'x-yoro-wake-last-failure-at' => (string) time() ] ), true );
$GLOBALS['fail_health_write'] = true;
YoRo_Connector_Wake::record_response( $health_response( 'blocked', [ 'x-yoro-wake-last-failure-at' => (string) time(), 'x-yoro-wake-retry-after' => '600' ] ), true );
$assert( ! YoRo_Connector_Wake::available() && 'unknown' === YoRo_Connector_Wake::health()['state'], 'A failed telemetry write cannot leave stale healthy state active in this session.' );
$GLOBALS['fail_health_write'] = false;
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time(), 'x-yoro-wake-last-failure-at' => (string) time() ] ), true );
$assert( YoRo_Connector_Wake::available(), 'A later stored receipt recovers from a local telemetry write failure.' );
$GLOBALS['fail_health_write'] = true;
YoRo_Connector_Wake::record_response( [ 'headers' => $GLOBALS['options'][ YoRo_Connector_Wake::HEALTH_OPTION ] ], false );
$assert( ! YoRo_Connector_Wake::available(), 'A failed unknown-state write also prefers fast polling.' );
$GLOBALS['fail_health_write'] = false;
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-last-success-at' => (string) time(), 'x-yoro-wake-last-failure-at' => (string) time() ] ), true );
// Model update_option returning false for an unchanged but successfully stored value.
$GLOBALS['fail_health_write'] = true;
$stored_health = $GLOBALS['options'][ YoRo_Connector_Wake::HEALTH_OPTION ];
YoRo_Connector_Wake::record_response( $health_response( 'healthy', [ 'x-yoro-wake-observed-at' => (string) $stored_health['observed_at'], 'x-yoro-wake-last-success-at' => (string) $stored_health['last_success_at'], 'x-yoro-wake-last-failure-at' => (string) $stored_health['last_failure_at'] ] ), true );
$assert( YoRo_Connector_Wake::available(), 'An unchanged stored value is not a telemetry write failure.' );
$GLOBALS['fail_health_write'] = false;
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
$assert( is_wp_error( $result ) && 409 === $result->data['status'] && 'yoro_wake_busy' === $result->get_error_code(), 'Busy locks have a distinct authenticated retry code; they do not pretend to process work.' );
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
$assert( ! YoRo_Connector_Wake::available(), 'Readiness requires the current credential binding.' );
$assert( 0 === YoRo_Connector_Wake::poll_retry_after(), 'Credential rotation cannot inherit another credential’s rate cooldown.' );
YoRo_Connector_Wake::maybe_register( $client );
$assert( 3 === count( $client->calls ) && $secret !== $client->calls[2]['secret'], 'A new site credential registers a fresh independent secret.' );

$GLOBALS['options'][ YoRo_Connector_Wake::HEALTH_OPTION ] = [];
$idle_reply = $health_response( 'unknown', [ 'x-yoro-wake-reason' => 'idle', 'x-yoro-wake-last-success-at' => (string) ( time() - 901 ) ] );
YoRo_Connector_Wake::record_response( $idle_reply, true );
$assert( 'idle' === YoRo_Connector_Wake::health()['reason'] && ! YoRo_Connector_Wake::available(), 'Idle presentation never treats an old wake receipt as current transport health.' );
ob_start(); $render_status->invoke( null, true ); $idle_markup = ob_get_clean();
$assert( str_contains( $idle_markup, 'Fast updates enabled · Idle' ) && ! str_contains( $idle_markup, 'Fast updates disabled' ), 'Idle and disabled have distinct presentation.' );
YoRo_Connector_Wake::record_response( $health_response( 'blocked', [ 'x-yoro-wake-reason' => 'blocked', 'x-yoro-wake-last-failure-at' => (string) time(), 'x-yoro-wake-retry-after' => '600' ] ), true );
YoRo_Connector_Wake::record_response( $idle_reply, true );
$assert( 'blocked' === YoRo_Connector_Wake::health()['state'], 'A delayed idle response cannot hide a same-second newer failure.' );
foreach ( [ [ 'healthy', 'idle' ], [ 'unknown', 'verified' ], [ 'disabled', 'idle' ], [ 'unknown', 'untrusted' ] ] as [ $state, $reason ] ) {
    $GLOBALS['options'][ YoRo_Connector_Wake::HEALTH_OPTION ] = [];
    YoRo_Connector_Wake::record_response( $health_response( $state, [ 'x-yoro-wake-reason' => $reason ] ), true );
    $assert( 'unverified' === YoRo_Connector_Wake::health()['reason'] && ! YoRo_Connector_Wake::available(), 'Contradictory or unknown reason cannot upgrade transport health.' );
}
YoRo_Connector_Wake::record_response( $health_response( 'disabled', [ 'x-yoro-wake-reason' => 'configuration_disabled' ] ), true );
ob_start(); $render_status->invoke( null, true ); $disabled_markup = ob_get_clean();
$assert( str_contains( $disabled_markup, 'Fast updates disabled' ) && ! YoRo_Connector_Wake::available(), 'Configuration-disabled wake stays explicitly disabled.' );
YoRo_Connector_Wake::record_poll_response( true, 'wake' );
$assert( 'unverified' === YoRo_Connector_Wake::polling_health()['state'], 'Wake cannot masquerade as an independent polling worker.' );
YoRo_Connector_Wake::record_poll_response( true, 'cli' );
$assert( 'healthy' === YoRo_Connector_Wake::polling_health()['state'] && 'cli' === YoRo_Connector_Wake::polling_health()['runner'], 'A real CLI poll verifies the worker independently of disabled wake.' );
YoRo_Connector_Wake::record_poll_response( false, 'cli' );
$assert( 'error' === YoRo_Connector_Wake::polling_health()['state'], 'A later poll failure is not hidden by the last successful heartbeat.' );
YoRo_Connector_Wake::record_poll_response( true, 'scheduled' );
$assert( 'healthy' === YoRo_Connector_Wake::polling_health()['state'] && 'scheduled' === YoRo_Connector_Wake::polling_health()['runner'], 'Successful cron fallback verifies its own worker.' );
foreach ( [ 'cli', 'scheduled' ] as $runner ) {
    $GLOBALS['options'][ YoRo_Connector_Wake::POLL_HEALTH_OPTION ][ $runner ] = [ 'last_attempt_at' => time() - 121, 'last_success_at' => time() - 121, 'ok' => true ];
}
$assert( 'delayed' === YoRo_Connector_Wake::polling_health()['state'], 'A heartbeat expires without claiming the worker is disabled.' );
foreach ( [ 'cli', 'scheduled' ] as $runner ) {
    $GLOBALS['options'][ YoRo_Connector_Wake::POLL_HEALTH_OPTION ][ $runner ]['last_attempt_at'] = time() + 1;
}
$assert( 'unverified' === YoRo_Connector_Wake::polling_health()['state'], 'Future heartbeat timestamps are not trusted.' );
YoRo_Connector_Wake::record_poll_response( true, 'cli' );
YoRo_AI_Settings::$key = 'another-rotated-private-site-key';
$assert( 'unverified' === YoRo_Connector_Wake::polling_health()['state'], 'A rotated credential cannot inherit another connection heartbeat.' );
echo "Signed connector wake boundary passed ({$assertions} assertions).\n";
