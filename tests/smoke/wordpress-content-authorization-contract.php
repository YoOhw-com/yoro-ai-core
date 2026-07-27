<?php
/**
 * YoRo WordPress content native-capability smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/wordpress-content-authorization-contract.php
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
$admin_ids = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;
$original_user_id = get_current_user_id();
$subscriber_id = 0;
$post_id = 0;

try {
	$assert( $admin_id > 0, 'An administrator is required for the native capability contract.' );
	wp_set_current_user( $admin_id );
	$post_id = wp_insert_post(
		[
			'post_type'    => 'post',
			'post_status'  => 'private',
			'post_title'   => 'YoRo native capability smoke',
			'post_content' => 'This private content must not cross the WordPress capability boundary.',
			'post_author'  => $admin_id,
		],
		true
	);
	$assert( ! is_wp_error( $post_id ), 'The private capability fixture could not be created.' );

	$subscriber_id = wp_create_user(
		'yoro_capability_smoke_' . wp_generate_password( 8, false ),
		wp_generate_password( 32, true ),
		'yoro-capability-smoke-' . wp_generate_password( 8, false ) . '@example.test'
	);
	$assert( ! is_wp_error( $subscriber_id ), 'The subscriber capability fixture could not be created.' );

	if ( ! is_wp_error( $subscriber_id ) ) {
		( new WP_User( $subscriber_id ) )->set_role( 'subscriber' );
		wp_set_current_user( $subscriber_id );

		$get_result = YoRo_WordPress_Content_Actions::get_content( [ 'content_id' => $post_id ] );
		$assert( is_wp_error( $get_result ) && 'yoro_content_read_forbidden' === $get_result->get_error_code(), 'Private content reads must enforce read_post.' );

		$create_result = YoRo_WordPress_Content_Actions::create_draft(
			[
				'title'   => 'Forbidden draft',
				'content' => 'A subscriber must not create this draft.',
			]
		);
		$assert( is_wp_error( $create_result ) && 'yoro_content_create_forbidden' === $create_result->get_error_code(), 'Draft creation must enforce the post type create capability.' );

		$comments_result = YoRo_WordPress_Content_Actions::list_comments( [ 'content_id' => $post_id ] );
		$assert( is_wp_error( $comments_result ) && 'yoro_comments_read_forbidden' === $comments_result->get_error_code(), 'Comment access must enforce moderate_comments.' );

		$media_result = YoRo_WordPress_Content_Actions::search_media( [] );
		$assert( is_wp_error( $media_result ) && 'yoro_media_read_forbidden' === $media_result->get_error_code(), 'Media access must enforce upload_files.' );
	}
} finally {
	wp_set_current_user( $admin_id );

	if ( ! is_wp_error( $post_id ) && $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}

	if ( ! is_wp_error( $subscriber_id ) && $subscriber_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $subscriber_id );
	}

	wp_set_current_user( $original_user_id );
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo WordPress content native-capability smoke contract passed.\n";
