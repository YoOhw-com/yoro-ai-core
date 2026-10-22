<?php
// Scheduled refresh selects existing validated projections; explicit handshakes remain fresh.
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
interface YoRo_AI_Client_Interface {}
function sanitize_text_field( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function apply_filters( $name, $value ) { return $value; }
class YoRo_AI_Settings {
    public static $connected = true;
    public static $expires;
    public static $credential_expires = '';
    public static $status = 'available';
    public static function get_ai_api_status() { return [ 'credential' => [ 'expires_at' => self::$credential_expires ] ]; }
    public static function get_early_access_onboarding( $status ) { return [ 'status' => self::$status, 'expires_at' => self::$expires ]; }
    public static function is_ai_api_account_connected( $status ) { return self::$connected; }
}
require dirname( __DIR__, 2 ) . '/includes/ai/clients/class-yoro-ai-api-client.php';
class YoRo_Maintenance_Unit_Client extends YoRo_AI_API_Client {
    public $calls = 0;
    public function handshake() { $this->calls++; return [ 'refreshed' => true ]; }
}
$assert = static function( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$client = new YoRo_Maintenance_Unit_Client();
YoRo_AI_Settings::$expires = gmdate( 'c', time() + 300 );
for ( $i = 0; $i < 20; $i++ ) { $client->handshake_if_due(); }
$assert( 0 === $client->calls, 'A validated current projection skips repeated connection maintenance.' );
YoRo_AI_Settings::$expires = gmdate( 'c', time() + 5 );
$client->handshake_if_due();
$assert( 1 === $client->calls, 'The existing five-minute projection refreshes before its actual expiry.' );
YoRo_AI_Settings::$expires = gmdate( 'c', time() + 300 );
YoRo_AI_Settings::$status = 'unknown'; $client->handshake_if_due();
YoRo_AI_Settings::$status = 'available'; YoRo_AI_Settings::$connected = false; $client->handshake_if_due();
YoRo_AI_Settings::$connected = true; YoRo_AI_Settings::$credential_expires = gmdate( 'c', time() + DAY_IN_SECONDS ); $client->handshake_if_due();
$assert( 4 === $client->calls, 'Unknown authority, disconnection or a due credential rotation cannot use cached maintenance.' );
$client->handshake();
$assert( 5 === $client->calls, 'Explicit handshake remains an unconditional connection operation.' );
echo "Due-based connection maintenance contract passed.\n";
