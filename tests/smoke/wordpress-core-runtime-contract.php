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

	$runtime = [
		'connector_key'           => 'wordpress',
		'connector_connection_id' => 'connection_wordpress_core_smoke',
		'execution_mode'          => 'remote_agent',
		'protocol_version'        => 1,
	];
	$manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( $runtime, time() );
	$context_pack = YoRo_Commerce_Contract_Builder::build_context_pack( $runtime, $manifest, time() );
	$domains = [];

	foreach ( (array) ( $context_pack['domains'] ?? [] ) as $domain ) {
		if ( is_array( $domain ) && ! empty( $domain['name'] ) ) {
			$domains[ $domain['name'] ] = $domain;
		}
	}

	$store_data = is_array( $domains['store']['data'] ?? null ) ? $domains['store']['data'] : [];
	$integration_data = is_array( $domains['integrations']['data'] ?? null ) ? $domains['integrations']['data'] : [];
	$assert( ! array_key_exists( 'currency', $store_data ), 'Plain WordPress context must omit an unavailable currency.' );
	$assert( ! array_key_exists( 'currency_symbol', $store_data ), 'Plain WordPress context must omit an unavailable currency symbol.' );
	$assert( ! array_key_exists( 'commerce_platform_version', $integration_data ), 'Plain WordPress context must omit an unavailable commerce platform version.' );
}

$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$original_user_id = get_current_user_id();

if ( ! empty( $admin_ids ) ) {
	wp_set_current_user( absint( $admin_ids[0] ) );
	$lifecycle_post_id = wp_insert_post(
		[
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => 'YoRo trash lifecycle smoke',
			'post_content' => 'Temporary smoke content.',
		]
	);
	$lifecycle_current = YoRo_WordPress_Content_Actions::get_content( [ 'content_id' => $lifecycle_post_id ] );
	$lifecycle_version = $lifecycle_current['data']['version'] ?? '';
	$modified_at = (string) ( $lifecycle_current['data']['modified_at'] ?? '' );
	$assert( 1 === preg_match( '/^\d{4}-/', $modified_at ), 'New draft timestamps must use a valid RFC 3339 year.' );
	$publish_at = time() + HOUR_IN_SECONDS;
	$lifecycle_scheduled = YoRo_WordPress_Content_Actions::schedule(
		[
			'content_id'      => $lifecycle_post_id,
			'expected_version' => $lifecycle_version,
			'publish_at'       => gmdate( DATE_RFC3339, $publish_at ),
		]
	);
	$assert( is_array( $lifecycle_scheduled ), 'Content schedule must return a successful result.' );
	$lifecycle_version = is_array( $lifecycle_scheduled ) ? ( $lifecycle_scheduled['data']['version'] ?? '' ) : $lifecycle_version;
	$scheduled_post = get_post( $lifecycle_post_id );
	$assert( is_array( $lifecycle_scheduled ) && 'future' === ( $lifecycle_scheduled['data']['status'] ?? '' ), 'Content schedule must keep a future post in the future state.' );
	$assert( $scheduled_post instanceof WP_Post && gmdate( 'Y-m-d H:i:s', $publish_at ) === $scheduled_post->post_date_gmt, 'Content schedule must preserve the requested UTC publication time.' );
	$lifecycle_trashed = YoRo_WordPress_Content_Actions::trash(
		[
			'content_id'      => $lifecycle_post_id,
			'expected_version' => $lifecycle_version,
		]
	);
	$trashed_version = $lifecycle_trashed['data']['version'] ?? '';
	$assert( 'trash' === ( $lifecycle_trashed['data']['status'] ?? '' ), 'Content trash must return the refreshed trash status.' );
	$assert( '' !== $trashed_version && $lifecycle_version !== $trashed_version, 'Content trash must return a refreshed optimistic version.' );
	$lifecycle_restored = YoRo_WordPress_Content_Actions::restore(
		[
			'content_id'      => $lifecycle_post_id,
			'expected_version' => $trashed_version,
		]
	);
	$assert( 'trash' !== ( $lifecycle_restored['data']['status'] ?? 'trash' ), 'Content restore must return the refreshed post status.' );
	$assert( $trashed_version !== ( $lifecycle_restored['data']['version'] ?? '' ), 'Content restore must return a refreshed optimistic version.' );
	wp_delete_post( $lifecycle_post_id, true );

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
