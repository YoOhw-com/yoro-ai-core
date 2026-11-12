<?php
// Isolated policy boundary test; native registration and quote effects use commerce-integration.php.
define( 'ABSPATH', __DIR__ . '/profile-fixture/' );
define( 'WC_PLUGIN_FILE', ABSPATH . 'woocommerce/woocommerce.php' );
class WC_Cart {}
class WC_Cart_Totals {}
class WP_Error {}
function wp_json_encode( $value ) { return json_encode( $value ); }
require dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-support-commerce.php';
$count = 0; $autoloads = 0; $calls = 0;
function check_profile( $condition, $reason ) { global $count; ++$count; if ( ! $condition ) { throw new RuntimeException( $reason ); } }
function profile_for( $hook, $callback, $priority = 10, $arity = 1 ) {
    $GLOBALS['wp_filter'] = [ $hook => (object) [ 'callbacks' => [ $priority => [ [ 'function' => $callback, 'accepted_args' => $arity ] ] ] ] ];
    return YoRo_Support_Commerce::profile();
}
$sentinel = static function () use ( &$autoloads ) { ++$autoloads; };
spl_autoload_register( $sentinel );
try {
    check_profile( ! class_exists( 'WC_Notes_Run_Db_Update', false ), 'The lazy native class starts unloaded.' );
    foreach ( [ [ 'WC_Notes_Run_Db_Update', 'maybe_update_notice' ], 'WC_Notes_Run_Db_Update::maybe_update_notice' ] as $callback ) {
        check_profile( true === profile_for( 'woocommerce_get_note_from_db', $callback ), 'Only the exact dormant native note registration is admitted.' );
        check_profile( ! class_exists( 'WC_Notes_Run_Db_Update', false ) && 0 === $autoloads, 'Inspection never autoloads the native note class.' );
        foreach ( [ [ 'woocommerce_get_note_from_db', 11, 1 ], [ 'woocommerce_get_note_from_db', 10, 2 ], [ 'woocommerce_before_get_rates_for_package', 10, 1 ] ] as [ $hook, $priority, $arity ] ) {
            check_profile( profile_for( $hook, $callback, $priority, $arity ) instanceof WP_Error, 'Changed hook, priority or arity is denied.' );
        }
    }
    foreach ( [ [ 'SupportUnloadedVendor', 'maybe_update_notice' ], 'SupportUnloadedVendor::maybe_update_notice', [ 'WC_Notes_Run_Db_Update', 'other_method' ], static function () use ( &$calls ) { ++$calls; } ] as $callback ) {
        check_profile( profile_for( 'woocommerce_get_note_from_db', $callback ) instanceof WP_Error, 'An unknown class, method or closure on the same hook stays unsupported.' );
    }
    check_profile( 0 === $autoloads && 0 === $calls, 'Unknown callbacks are neither autoloaded nor invoked.' );
    // A loaded class with the expected name must still pass the original defining-file registry guard.
    if ( true ) { class WC_Notes_Run_Db_Update { public static function maybe_update_notice( $value ) { $GLOBALS['calls']++; return $value; } } }
    check_profile( profile_for( 'woocommerce_get_note_from_db', [ 'WC_Notes_Run_Db_Update', 'maybe_update_notice' ] ) instanceof WP_Error, 'A loaded foreign class cannot use the dormant-class exception.' );
    check_profile( 0 === $autoloads && 0 === $calls, 'Loaded foreign callback is rejected without execution.' );
} finally { spl_autoload_unregister( $sentinel ); }
echo json_encode( [ 'result' => 'passed', 'assertions' => $count, 'autoloads' => $autoloads, 'callback_calls' => $calls, 'native_wordpress' => 'NOT_EVALUATED' ], JSON_THROW_ON_ERROR ), "\n";
