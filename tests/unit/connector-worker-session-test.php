<?php
// Pure scheduler contract; no network, WordPress boot or real sleeping.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once dirname( __DIR__, 2 ) . '/includes/commerce/class-yoro-connector-client.php';
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 200; } }
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) { function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ $name ] ?? ''; } }
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        public function __construct( $code, $message ) { $this->code = $code; }
        public function get_error_code() { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }
require_once dirname( __DIR__, 2 ) . '/includes/commerce/class-yoro-connector-runtime.php';
$assert = static function ( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
// Exercise the CLI scheduler with a virtual monotonic clock, without network or sleeping.
class YoRo_Connector_Session_Unit extends YoRo_Connector_Runtime {
    public $rounds = [];
    public $calls = 0;
    public $publish_flags = [];
    public $wake_healthy = false;
    protected function wake_available() { return $this->wake_healthy; }
    protected function early_access_session_round( $publish, $deadline ) {
        $this->calls++;
        $this->publish_flags[] = $publish;
        return array_shift( $this->rounds ) ?? [ 'reads' => 0, 'writes' => 0 ];
    }
}
$now = 0.0;
$sleeps = [];
$clock = static function () use ( &$now ) { return $now; };
$sleeper = static function ( $seconds ) use ( &$now, &$sleeps ) { $now += $seconds; $sleeps[] = $seconds; };
$session = new YoRo_Connector_Session_Unit( new YoRo_Connector_Client(), $clock, $sleeper );
$session->rounds = [ [ 'reads' => 1, 'writes' => 0 ], [ 'reads' => 1, 'writes' => 1 ], [ 'reads' => 0, 'writes' => 0 ] ];
$observed = [];
$receipt = $session->run_early_access_session( 55, static function ( $value ) use ( &$observed, &$now ) { $observed[] = [ $now, $value ]; } );
$assert( 2 === $receipt['reads'] && 1 === $receipt['writes'], 'The session must drain reads and writes without losing counts.' );
$assert( 0.0 === $observed[0][0] && 0.0 === $observed[1][0] && 0.0 === $observed[2][0], 'Queued work must drain without an inter-operation sleep.' );
$assert( 55.0 === $now && [ 2, 3, 3, 3 ] === array_slice( $sleeps, 0, 4 ), 'Idle polling must back off and stop at the bounded deadline.' );
$assert( [ true ] === array_values( array_filter( $session->publish_flags ) ), 'Publication may run only on the first round.' );
$now = 0.0; $sleeps = [];
$session->rounds = array_fill( 0, 41, [ 'reads' => 1, 'writes' => 0 ] );
$receipt = $session->run_early_access_session( 999 );
$assert( 40 === $receipt['ticks'] && [] === $sleeps, 'A hot queue must retain the hard session round bound.' );
$now = 0.0;
$session->rounds = [ new WP_Error( 'fixture_denied', 'Denied' ) ];
$error = $session->run_early_access_session();
$assert( is_wp_error( $error ) && 'fixture_denied' === $error->get_error_code() && 0.0 === $now, 'A denied or unknown result must stop without retrying the operation.' );

echo "Adaptive CLI worker session contract passed.\n";

$budget_client = new YoRo_Connector_Client();
$record_budget = new ReflectionMethod( YoRo_Connector_Client::class, 'record_early_access_budget' );
$record_budget->invoke( $budget_client, [ 'headers' => [ 'x-ratelimit-remaining' => '3' ] ] );
$assert( ! $budget_client->early_access_has_budget() && $budget_client->early_access_has_budget( 3 ), 'Low server quota must prohibit another full claim pipeline.' );
$record_budget->invoke( $budget_client, [ 'headers' => [ 'x-ratelimit-remaining' => '0' ] ] );
$assert( ! $budget_client->early_access_has_budget( 1 ), 'An exhausted or 429 response must prohibit local execution without result capacity.' );
$missing_headers = new YoRo_Connector_Client();
for ( $i = 0; $i < 21; $i++ ) { $record_budget->invoke( $missing_headers, [ 'headers' => [] ] ); }
$assert( ! $missing_headers->early_access_has_budget() && $missing_headers->early_access_has_budget( 3 ), 'Missing quota headers must still retain the conservative 24-request session cap.' );
echo "Shared read/write transport budget contract passed.\n";

// The HTTPS wake path is a single round without sleeping or publisher work.
$now = 0.0; $sleeps = [];
$wake = new YoRo_Connector_Session_Unit( new YoRo_Connector_Client(), $clock, $sleeper );
$wake->rounds = [ [ 'reads' => 1, 'writes' => 0 ], [ 'reads' => 1, 'writes' => 1 ] ];
$receipt = $wake->run_early_access_wake();
$assert( [ 'success' => true, 'processed' => 1 ] === $receipt && 1 === $wake->calls, 'A wake signal must process exactly one bounded ordinary round.' );
$assert( [ false ] === $wake->publish_flags && [] === $sleeps, 'Wake must not publish snapshots or wait for more work.' );
$wake->rounds = [ new WP_Error( 'fixture_denied', 'Denied' ) ];
$assert( is_wp_error( $wake->run_early_access_wake() ), 'Wake must preserve denied or unknown outcomes without rerunning a claim.' );
$deadline = new ReflectionProperty( YoRo_Connector_Client::class, 'early_access_deadline' );
$client_property = new ReflectionProperty( YoRo_Connector_Runtime::class, 'client' );
$assert( null === $deadline->getValue( $client_property->getValue( $wake ) ), 'Wake deadlines must be cleared even after transport errors.' );
$budget_client->set_early_access_deadline( 0 );
$timeout = new ReflectionMethod( YoRo_Connector_Client::class, 'early_access_timeout' );
$assert( 0 === $timeout->invoke( $budget_client ), 'An expired wake deadline has no further HTTP budget.' );
$budget_client->set_early_access_deadline();
$assert( 20 === $timeout->invoke( $budget_client ), 'The existing fallback transport timeout remains unchanged.' );
echo "Bounded single-round wake runtime contract passed.\n";

$now = 0.0; $sleeps = [];
$healthy_fallback = new YoRo_Connector_Session_Unit( new YoRo_Connector_Client(), $clock, $sleeper );
$healthy_fallback->wake_healthy = true;
$healthy_fallback->rounds = [ [ 'reads' => 1, 'writes' => 0 ], [ 'reads' => 1, 'writes' => 0 ] ];
$receipt = $healthy_fallback->run_early_access_session( 55 );
$assert( [ 2, 4, 5, 5, 5 ] === array_slice( $sleeps, 0, 5 ) && 55.0 === $now, 'Healthy wake fallback must poll within five seconds while retaining the session deadline.' );
$assert( 2 === $receipt['reads'] && 14 === $receipt['ticks'], 'Queued work still drains immediately, with twelve bounded fallback polls.' );
$now = 0.0; $sleeps = [];
$expiring_sleeper = static function( $seconds ) use ( &$now, &$sleeps, &$stale_fallback ) {
    $now += $seconds; $sleeps[] = $seconds;
    if ( $now >= 14 ) { $stale_fallback->wake_healthy = false; }
};
$stale_fallback = new YoRo_Connector_Session_Unit( new YoRo_Connector_Client(), $clock, $expiring_sleeper );
$stale_fallback->wake_healthy = true;
$stale_fallback->run_early_access_session( 25 );
$assert( [ 2.0, 4.0, 5.0, 5.0, 3.0, 3.0, 3.0 ] === array_map( 'floatval', $sleeps ), 'Stale registration must restore the existing three-second fallback within the running session.' );
echo "Wake-aware fallback polling contract passed.\n";

// A rate-limited API must not cause a tight retry loop or bypass the session deadline.
$now = 0.0; $sleeps = [];
$limited_client = new YoRo_Connector_Client();
$record_budget->invoke( $limited_client, [ 'response' => [ 'code' => 429 ], 'headers' => [ 'retry-after' => '120' ] ] );
$limited_session = new YoRo_Connector_Session_Unit( $limited_client, $clock, $sleeper );
$limited_session->rounds = [ [ 'reads' => 0, 'writes' => 0 ] ];
$limited_session->run_early_access_session( 55 );
$assert( [ 55.0 ] === $sleeps && 1 === $limited_session->calls, 'A long Retry-After consumes the remaining session in one bounded sleep.' );
$now = 0.0; $sleeps = [];
$limited_session = new YoRo_Connector_Session_Unit( $limited_client, $clock, $sleeper );
$limited_session->rounds = [ new WP_Error( 'rate_limited', 'Wait' ) ];
$assert( is_wp_error( $limited_session->run_early_access_session( 55 ) ) && [ 55.0 ] === $sleeps && 1 === $limited_session->calls, 'Rate-limit errors sleep once within the deadline and return without retrying a delivery.' );
echo "Rate-limited worker session contract passed.\n";

// The coordination contract supplies WordPress doubles and must also run when
// this scheduler contract is included by the bootstrapped WordPress smoke suite.
$coordination_pipes = [];
$coordination_process = proc_open(
    [ PHP_BINARY, __DIR__ . '/connector-runner-coordination-test.php' ],
    [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
    $coordination_pipes
);
$assert( is_resource( $coordination_process ), 'The isolated coordination contract must start.' );
fclose( $coordination_pipes[0] );
$coordination_output = stream_get_contents( $coordination_pipes[1] );
$coordination_errors = stream_get_contents( $coordination_pipes[2] );
fclose( $coordination_pipes[1] );
fclose( $coordination_pipes[2] );
$coordination_exit = proc_close( $coordination_process );
$assert( 0 === $coordination_exit, 'The isolated coordination contract must pass: ' . $coordination_errors );
echo $coordination_output;
