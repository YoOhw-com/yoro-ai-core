<?php
/**
 * YoRo WordPress-first runtime smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/wordpress-core-runtime-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$actions = YoRo_Action_Registry::all();
$modules = YoRo_WordPress_Site_Actions::modules();

$assert( class_exists( 'YoRo_Connector_Runtime' ), 'The connector runtime did not boot.' );
$assert( in_array( 'wordpress.core', $modules, true ), 'The WordPress core module is missing.' );
$assert( in_array( 'wordpress.content', $modules, true ), 'The WordPress content module is missing.' );
$assert( yoro_ai_core_has_woocommerce() === in_array( 'woocommerce', $modules, true ), 'WooCommerce module detection is inconsistent.' );

foreach ( [
	'site.get_info',
	'site.get_health',
	'site.get_update_status',
	'taxonomies.list',
	'terms.list',
	'content.list_revisions',
	'content.schedule',
	'content.trash',
	'content.restore',
	'content.restore_revision',
	'content.assign_terms',
	'content.set_featured_image',
	'content.bulk_update_status',
	'media.get',
	'media.update_metadata',
	'plugins.list',
	'themes.list',
	'users.list',
	'users.update_role',
] as $action_name ) {
	$assert( isset( $actions[ $action_name ] ), sprintf( 'WordPress action %s is missing.', $action_name ) );
}

foreach ( [ 'plugins.activate', 'plugins.deactivate', 'themes.activate' ] as $versioned_action ) {
	$required = $actions[ $versioned_action ]['required_params'] ?? [];
	$assert( in_array( 'expected_version', $required, true ), sprintf( 'WordPress action %s must require an optimistic version.', $versioned_action ) );
}

$media_upload = $actions['media.upload'] ?? [];
$assert( in_array( 'media_reference_id', $media_upload['required_params'] ?? [], true ), 'Media upload must require an opaque YoRo media reference.' );
$assert( ! array_key_exists( 'content_base64', $media_upload['input_schema'] ?? [] ), 'Media upload must not expose base64 payloads in the current catalog.' );

if ( ! yoro_ai_core_has_woocommerce() ) {
	$assert( ! isset( $actions['orders.search'] ), 'WooCommerce actions must not load on plain WordPress.' );
}

$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$original_user_id = get_current_user_id();

if ( ! empty( $admin_ids ) ) {
	wp_set_current_user( absint( $admin_ids[0] ) );
	$missing_option = 'yoro_smoke_option_missing';
	$policy_before = get_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, $missing_option );
	$backup_before = get_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, $missing_option );
	delete_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION );
	delete_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION );
	$blocked = YoRo_WordPress_Admin_Actions::activate_plugin( [ 'plugin' => 'example/example.php' ] );
	$assert( is_wp_error( $blocked ) && 'yoro_admin_policy_disabled' === $blocked->get_error_code(), 'Administration actions must fail closed until local policy is enabled.' );
	if ( $missing_option === $policy_before ) {
		delete_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION );
	} else {
		update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, $policy_before, false );
	}
	if ( $missing_option === $backup_before ) {
		delete_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION );
	} else {
		update_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, $backup_before, false );
	}
	wp_set_current_user( $original_user_id );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "YoRo WordPress-first runtime contract passed.\n";
