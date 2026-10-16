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
