<?php
// Mixed file versions must preserve the existing fresh-handshake fallback.
if ( ( $argv[1] ?? '' ) === 'legacy-runtime' ) {
    // The old runtime has none of the newly added maintenance methods. The new
    // client uses only existing settings and handshake contracts in this direction.
    class YoRo_Connector_Runtime {}
    require __DIR__ . '/connector-maintenance-test.php';
    if ( method_exists( YoRo_Connector_Runtime::class, 'maintain_api_connection' ) ) { throw new RuntimeException( 'The fixture must retain the old runtime surface.' ); }
    echo "New API client with legacy runtime maintenance contract passed.\n";
    return;
}
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public function __construct( public $code, public $message ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
if ( ! class_exists( 'YoRo_AI_API_Client' ) ) {
    class YoRo_AI_API_Client {
        public static $fresh = 0;
        public function handshake() { self::$fresh++; return [ 'success' => true ]; }
    }
}
require dirname( __DIR__, 2 ) . '/includes/commerce/class-yoro-connector-client.php';
require dirname( __DIR__, 2 ) . '/includes/commerce/class-yoro-connector-runtime.php';
class YoRo_Mixed_Runtime_Unit extends YoRo_Connector_Runtime {
    protected function early_access_session_round( $publish, $deadline ) { return [ 'reads' => 0, 'writes' => 0, 'empty_polls' => 1 ]; }
}
$assert = static function( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$runtime = new YoRo_Mixed_Runtime_Unit();
$result = $runtime->run_early_access_session();
$assert( 1 === YoRo_AI_API_Client::$fresh && 1 === $result['ticks'] && 1 === $result['empty_polls'], 'New CLI runtime must use the old client fresh handshake without a fatal or extra idle polls.' );
$cron_refresh = new ReflectionMethod( YoRo_Connector_Runtime::class, 'maintain_api_connection' );
$cron_refresh->invoke( null );
$assert( 2 === YoRo_AI_API_Client::$fresh, 'The shared cron maintenance seam must also fall back to the old client fresh handshake.' );
$process = proc_open( [ PHP_BINARY, __FILE__, 'legacy-runtime' ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
$assert( is_resource( $process ), 'The reverse mixed-version contract must start.' );
fclose( $pipes[0] ); $output = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
$assert( 0 === proc_close( $process ), 'New client with old runtime must retain its existing calls: ' . $errors );
echo $output;
echo "Old API client with new CLI/cron runtime contract passed.\n";
