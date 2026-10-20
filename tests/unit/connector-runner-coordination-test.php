<?php
// Advisory scheduler hints cannot replace the existing database-session fence.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public function __construct( public $code, public $message ) {} public function get_error_code() { return $this->code; } } }
class YoRo_AI_Settings {
    public static $key = 'fixture-key';
    public static function get_installation_uuid() { return 'fixture-installation'; }
    public static function get_ai_api_url() { return 'https://api.example/api/v1'; }
    public static function get_ai_api_site_key() { return self::$key; }
    public static function get_ai_api_status() { return []; }
}
class YoRo_Connector_Snapshot_Publisher {
    public static $cutover = false;
    public static function runtime() { return [ 'cutover' => [ 'cutover_allowed' => self::$cutover ], 'delivery_protocol' => 'pull_v1' ]; }
}
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; $GLOBALS['autoload'][ $key ] = $autoload; return true; }
function sanitize_key( $value ) { return $value; }
require_once dirname( __DIR__, 2 ) . '/includes/commerce/class-yoro-connector-client.php';
require_once dirname( __DIR__, 2 ) . '/includes/commerce/class-yoro-connector-runtime.php';
$assertions = 0;
$assert = static function( $ok, $message ) use ( &$assertions ) { $assertions++; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$runtime = new YoRo_Connector_Runtime();
$owner = new ReflectionProperty( $runtime, 'session_owner' );
$observe = new ReflectionMethod( $runtime, 'observe_cli_session' );
$finish = new ReflectionMethod( $runtime, 'finish_cli_session' );
$recent = new ReflectionMethod( $runtime, 'cli_session_recent' );
$assert( ! $recent->invoke( null ), 'An absent CLI cannot suppress cron.' );
$owner->setValue( $runtime, 'runner-a' );
$observe->invoke( $runtime );
$assert( $recent->invoke( null ) && false === $GLOBALS['autoload'][ YoRo_Connector_Runtime::SESSION_OPTION ], 'A current CLI observation is private, bounded and nonautoloaded.' );
$assert( ! str_contains( json_encode( $GLOBALS['options'] ), 'fixture-key' ), 'Scheduler observations contain no credential.' );
YoRo_Connector_Runtime::tick(); // A current CLI bypasses cron before any database/network work.
$assert( ! isset( $GLOBALS['wpdb'] ), 'A recent CLI prevents a redundant cron poll.' );
$GLOBALS['options'][ YoRo_Connector_Runtime::SESSION_OPTION ]['observed_at'] = time() - 15;
$assert( ! $recent->invoke( null ), 'A crashed CLI restores fallback within fifteen seconds.' );
$GLOBALS['options'][ YoRo_Connector_Runtime::SESSION_OPTION ]['observed_at'] = time() + 1;
$assert( ! $recent->invoke( null ), 'A future observation cannot suspend cron.' );
$observe->invoke( $runtime );
YoRo_AI_Settings::$key = 'rotated-fixture-key';
$assert( ! $recent->invoke( null ), 'Credential rotation invalidates another connection observation.' );
YoRo_AI_Settings::$key = 'fixture-key';
$GLOBALS['options'][ YoRo_Connector_Runtime::SESSION_OPTION ]['owner'] = 'runner-b';
$finish->invoke( $runtime );
$assert( $recent->invoke( null ), 'An older session cannot expire a newer runner observation.' );
$GLOBALS['options'][ YoRo_Connector_Runtime::SESSION_OPTION ]['owner'] = 'runner-a';
$finish->invoke( $runtime );
$assert( ! $recent->invoke( null ) && isset( $GLOBALS['options'][ YoRo_Connector_Runtime::SESSION_OPTION ] ), 'A finished session restores cron and retains diagnostic history.' );
$round = new ReflectionMethod( $runtime, 'early_access_round_locked' );
$assert( 'yoro_connector_runner_lock_lost' === $round->invoke( $runtime, false, 100 )->get_error_code(), 'The shared round never executes without the real runner fence.' );
YoRo_Connector_Snapshot_Publisher::$cutover = true;
YoRo_Connector_Runtime::tick( true );
$assert( ! isset( $GLOBALS['wpdb'] ), 'Ordinary-only fallback refuses the generic cutover lane before taking a lock.' );
YoRo_Connector_Snapshot_Publisher::$cutover = false;
class YoRo_Coordination_Fence {
    public $options = 'fixture_options'; public $attempts = 0;
    public function prepare( $sql, ...$values ) { return $sql; }
    public function get_var( $sql ) { $this->attempts++; return 0; }
}
$GLOBALS['wpdb'] = new YoRo_Coordination_Fence();
$owner->setValue( $runtime, 'runner-a' ); $observe->invoke( $runtime );
YoRo_Connector_Runtime::tick( true );
$assert( 0 === $GLOBALS['wpdb']->attempts, 'A live CLI observation suppresses the ordinary cron fallback.' );
$GLOBALS['options'][ YoRo_Connector_Runtime::SESSION_OPTION ]['observed_at'] = time() - 15;
YoRo_Connector_Runtime::tick( true );
$assert( 1 === $GLOBALS['wpdb']->attempts, 'A stale CLI observation restores the fallback, which still must acquire the real database fence.' );
echo "CLI/cron coordination passed ({$assertions} assertions).\n";
