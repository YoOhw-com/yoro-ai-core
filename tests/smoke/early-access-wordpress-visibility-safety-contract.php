<?php
/** Ordinary visibility and deletion safety, only against owned disposable WordPress fixtures. */
if ( ! defined( 'ABSPATH' ) ) { throw new RuntimeException( 'A disposable WordPress installation is required.' ); }
$fixture_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$ci_disposable = 'true' === getenv( 'GITHUB_ACTIONS' ) && in_array( $fixture_host, [ 'yoro-plugin-smoke.test', 'yoro-wordpress-smoke.test' ], true )
    && is_string( getenv( 'RUNNER_TEMP' ) ) && '' !== getenv( 'RUNNER_TEMP' ) && str_starts_with( (string) realpath( ABSPATH ), rtrim( (string) realpath( getenv( 'RUNNER_TEMP' ) ), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
if ( ! str_ends_with( $fixture_host, '.invalid' ) && ! $ci_disposable ) { throw new RuntimeException( 'An exact disposable test installation is required.' ); }
require_once __DIR__ . '/../contracts/helpers/native-write-runtime.php';
require_once __DIR__ . '/../contracts/helpers/wordpress-management-runtime.php';
$assertions = 0;
$assert = static function( $condition, $why ) use ( &$assertions ) { $assertions++; if ( ! $condition ) { throw new RuntimeException( $why ); } };
YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data', 'yoro_execute_actions' ] ] );
yoro_native_write_fixture_setup();
YoRo_Early_Access_WordPress_Management::register();
add_filter( 'pre_wp_mail', '__return_true' );
$preview_path = getenv( 'G013_TRASH_PREVIEW' );
$assert( is_string( $preview_path ) && '' !== $preview_path, 'An explicit disposable cross-process preview file is required.' );
$prepare = static fn( $action, $id ) => YoRo_Early_Access_WordPress_Management::prepare( 'wordpress.management.' . $action, [ 'content_id' => $id ] );
$native_calls = 0;
$count_native = static function() use ( &$native_calls ) { $native_calls++; };
foreach ( [ 'wp_trash_post', 'before_delete_post', 'untrash_post', 'pre_post_update' ] as $hook ) { add_action( $hook, $count_native ); }
$acquire = new ReflectionMethod( YoRo_Connector_Runtime::class, 'acquire_lock' );
$release = new ReflectionMethod( YoRo_Connector_Runtime::class, 'release_lock' );
$lock = $acquire->invoke( null ); $assert( null !== $lock, 'Own the runtime lock.' );
try {
    if ( ! EMPTY_TRASH_DAYS ) {
        $operation = json_decode( file_get_contents( $preview_path ), true, 512, JSON_THROW_ON_ERROR );
        $id = $operation['params']['content_id']; $before = get_post( $id, ARRAY_A );
        $assert( is_array( $before ) && 'draft' === $before['post_status'], 'The enabled-trash preview target still exists.' );
        $assert( is_wp_error( $prepare( 'content.trash', $id ) ), 'Trash-disabled preparation must fail closed.' );
        $fences = 0;
        $result = YoRo_Early_Access_WordPress_Management::execute_write( $operation, static function() use ( &$fences ) { $fences++; return true; } );
        $assert( is_wp_error( $result ) && 0 === $native_calls && $before === get_post( $id, ARRAY_A ), 'Disabling trash after preview cannot call native trash/delete or change the original post.' );
        $assert( 0 === $fences, 'The disabled configuration is rejected before the native execution boundary.' );
        $assert( is_wp_error( YoRo_Early_Access_WordPress_Management::prepare( 'wordpress.management.content.bulk_update_status', [ 'content_ids' => [ $id ], 'status' => 'trash' ] ) ), 'The batch path cannot select trash.' );
        echo "Trash disabled: preparation, prior confirmed operation and batch selector denied; original post unchanged, zero native calls.\n";
    } else {
        $make = static fn( $status, $timestamp ) => wp_insert_post( [ 'post_type' => 'post', 'post_status' => $status, 'post_title' => 'G013 visibility safety', 'post_content' => 'Owned disposable exact content', 'post_date' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ) ] );
        $id = $make( 'draft', time() - DAY_IN_SECONDS );
        file_put_contents( $preview_path, wp_json_encode( yoro_wordpress_management_fixture_operation( 'content.trash', [ 'content_id' => $id ] ) ) );
        $future = $make( 'future', time() + DAY_IN_SECONDS );
        $native_calls = 0;
        $assert( is_wp_error( $prepare( 'content.publish', $future ) ) && 'future' === get_post_status( $future ) && 0 === $native_calls, 'Publish cannot promise immediate visibility while WordPress would retain a future date.' );
        $revision = wp_insert_post( [ 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $future, 'post_title' => 'Past visibility revision', 'post_content' => 'Confirmed revision content' ] );
        global $wpdb;
        $wpdb->update( $wpdb->posts, [ 'post_date' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ], [ 'ID' => $future ] ); clean_post_cache( $future ); $native_calls = 0;
        $assert( is_wp_error( YoRo_Early_Access_WordPress_Management::prepare( 'wordpress.management.content.restore_revision', [ 'content_id' => $future, 'revision_id' => $revision ] ) ) && 'future' === get_post_status( $future ) && 0 === $native_calls, 'Restoring a revision cannot normalize expired future visibility into immediate publication.' );
        add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
        $past_restore = $make( 'trash', time() - DAY_IN_SECONDS ); update_post_meta( $past_restore, '_wp_trash_meta_status', 'future' );
        $native_calls = 0;
        $assert( is_wp_error( $prepare( 'content.restore', $past_restore ) ) && 'trash' === get_post_status( $past_restore ) && 0 === $native_calls, 'Restoring previous future status with a past date cannot publish outside the preview.' );
        $future_restore = $make( 'trash', time() + DAY_IN_SECONDS ); update_post_meta( $future_restore, '_wp_trash_meta_status', 'future' );
        $old_timezone = get_option( 'timezone_string' ); update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
        $source = $prepare( 'content.restore', $future_restore );
        $assert( ! is_wp_error( $source ) && 'future' === $source['after']['status'] && str_contains( $source['effects'], '+07:00 (site time zone)' ), 'A compatible restore binds the scheduled time in the site timezone.' );
        if ( $path = getenv( 'G013_VISIBILITY_SOURCE' ) ) {
            $source_action = YoRo_Early_Access_WordPress_Management::source_action( 'content.restore' );
            file_put_contents( $path, wp_json_encode( [ 'action' => $source_action, 'params' => [ 'intent' => [ 'content_id' => $future_restore ] ], 'result' => YoRo_Early_Access_WordPress_Management::execute( [ 'action' => $source_action, 'params' => [ 'intent' => [ 'content_id' => $future_restore ] ] ] ) ] ) );
        }
        $wpdb->update( $wpdb->posts, [ 'post_date' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) ], [ 'ID' => $future_restore ] ); clean_post_cache( $future_restore );
        $changed_date = $prepare( 'content.restore', $future_restore );
        $assert( ! is_wp_error( $changed_date ) && $source['version'] !== $changed_date['version'], 'A changed future date changes the exact source version even when visibility and modified time stay the same.' );
        $positive = $make( 'trash', time() + 2 * DAY_IN_SECONDS ); update_post_meta( $positive, '_wp_trash_meta_status', 'future' );
        $positive_operation = yoro_wordpress_management_fixture_operation( 'content.restore', [ 'content_id' => $positive ] );
        $assert( 'new' === YoRo_Connector_Execution_Journal::begin( $positive_operation )['state'], 'The compatible scheduled restore owns its durable marker.' );
        $positive_result = YoRo_Early_Access_WordPress_Management::execute_write( $positive_operation, static fn() => true );
        $assert( ! is_wp_error( $positive_result ) && 'future' === get_post_status( $positive ), 'A compatible future restore executes with the confirmed future visibility.' );
        $operation = yoro_wordpress_management_fixture_operation( 'content.restore', [ 'content_id' => $future_restore ] );
        $native_calls = 0; $fences = 0;
        $assert( 'new' === YoRo_Connector_Execution_Journal::begin( $operation )['state'], 'The runner durable marker is created before execution.' );
        $result = YoRo_Early_Access_WordPress_Management::execute_write( $operation, static function() use ( $future_restore, &$fences ) {
            $fences++;
            global $wpdb;
            $wpdb->update( $wpdb->posts, [ 'post_date' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ], [ 'ID' => $future_restore ] ); clean_post_cache( $future_restore );
            return true;
        } );
        $assert( is_wp_error( $result ) && 'trash' === get_post_status( $future_restore ) && 0 === $native_calls && 1 === $fences, 'Date drift after the first validation/fence is rejected immediately before the native restore.' );
        $clock_restore = $make( 'trash', time() + 61 ); update_post_meta( $clock_restore, '_wp_trash_meta_status', 'future' );
        $operation = yoro_wordpress_management_fixture_operation( 'content.restore', [ 'content_id' => $clock_restore ] ); $native_calls = 0; $fences = 0;
        $assert( 'new' === YoRo_Connector_Execution_Journal::begin( $operation )['state'], 'The stale-clock fixture reaches an existing durable marker.' );
        $result = YoRo_Early_Access_WordPress_Management::execute_write( $operation, static function() use ( &$fences ) { $fences++; sleep( 2 ); return true; } );
        $assert( is_wp_error( $result ) && 'trash' === get_post_status( $clock_restore ) && 0 === $native_calls && 1 === $fences, 'Clock crossing the native scheduling boundary cannot publish before the confirmed future visibility.' );
        remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 ); update_option( 'timezone_string', $old_timezone );
        echo "Visibility safety: future-date publish, past-date future restore, date drift at fence and real stale clock denied before native mutation; site-local schedule bound and compatible future restore executed.\n";
    }
} finally { $release->invoke( null, $lock ); }
echo 'Safety assertions passed: ' . $assertions . ".\n";
