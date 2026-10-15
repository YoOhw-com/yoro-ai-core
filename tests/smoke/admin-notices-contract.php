<?php
/** Real WordPress hooks and connection-notice rendering; disposable WordPress only. */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once dirname( __DIR__, 2 ) . '/includes/admin/class-yoro-admin-page.php';

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$hooks = [ 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ];
$previous_hooks = [];
foreach ( $hooks as $hook ) {
	$previous_hooks[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) ? clone $GLOBALS['wp_filter'][ $hook ] : null;
}
$previous_screen = get_current_screen();
$notice_key = 'yoro_ai_connect_notice_' . get_current_user_id();
$previous_notice = get_transient( $notice_key );

try {
	$foreign_notice = static function () { echo '<div class="notice">Other plugin notification</div>'; };
	foreach ( $hooks as $hook ) {
		add_action( $hook, $foreign_notice );
	}
	foreach ( [ 'dashboard', 'plugins', 'dashboard-network', 'dashboard-user' ] as $screen_id ) {
		set_current_screen( $screen_id );
		YoRo_Admin_Page::isolate_admin_notices();
		foreach ( $hooks as $hook ) {
			$assert( false !== has_action( $hook, $foreign_notice ), 'Other admin screens must retain their notices: ' . $screen_id . '/' . $hook );
		}
	}
	$GLOBALS['current_screen'] = null;
	YoRo_Admin_Page::isolate_admin_notices();
	$assert( false !== has_action( 'admin_notices', $foreign_notice ), 'A missing screen must leave notices untouched.' );

	set_current_screen( 'toplevel_page_yoro-ai-core' );
	YoRo_Admin_Page::isolate_admin_notices();
	foreach ( $hooks as $hook ) {
		$assert( false === has_action( $hook, $foreign_notice ), 'YoRo must suppress foreign notice callbacks: ' . $hook );
		ob_start(); do_action( $hook ); $output = ob_get_clean();
		$assert( '' === $output, 'YoRo must not render foreign notice output: ' . $hook );
	}

	$render = new ReflectionMethod( YoRo_Admin_Page::class, 'render_ai_api_connect_notice' );
	foreach ( [ 'success' => 'status', 'error' => 'alert' ] as $type => $role ) {
		set_transient( $notice_key, [ 'type' => $type, 'message' => 'Connection <b>message</b> & "details"' ], 60 );
		ob_start(); $render->invoke( null ); $html = ob_get_clean();
		$dom = new DOMDocument();
		@$dom->loadHTML( $html );
		$xpath = new DOMXPath( $dom );
		$assert( 1 === $xpath->query( '//div[@data-yoro-notice and @role="' . $role . '"]' )->length, 'Connection notices must use their own accessible component: ' . $type );
		$assert( 0 === $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " notice ") or contains(concat(" ", normalize-space(@class), " "), " is-dismissible ")]' )->length, 'Connection notices must not use WordPress notice styling/dismissal.' );
		$assert( false !== strpos( $html, 'Connection message &amp; &quot;details&quot;' ) && false === strpos( $html, '<b>' ), 'Notice text must remain escaped and sanitized.' );
		$assert( 1 === $xpath->query( '//button[@type="button" and @data-yoro-notice-dismiss and @hidden and @aria-label]' )->length, 'The dismiss button must be labeled and enabled only by its own script.' );
		$assert( false === get_transient( $notice_key ), 'Connection notices must retain their one-time consumption.' );
	}
	set_transient( $notice_key, [ 'type' => 'error', 'message' => '' ], 60 );
	ob_start(); $render->invoke( null ); $empty = ob_get_clean();
	$assert( '' === $empty, 'Empty notices must not create a blank panel.' );
} finally {
	foreach ( $previous_hooks as $hook => $previous_hook ) {
		if ( null === $previous_hook ) {
			unset( $GLOBALS['wp_filter'][ $hook ] );
		} else {
			$GLOBALS['wp_filter'][ $hook ] = $previous_hook;
		}
	}
	$GLOBALS['current_screen'] = $previous_screen;
	if ( false === $previous_notice ) {
		delete_transient( $notice_key );
	} else {
		set_transient( $notice_key, $previous_notice, 600 );
	}
}
if ( $failures ) {
	throw new RuntimeException( implode( "\n", $failures ) );
}
echo "YoRo screen-scoped notice isolation and branded notice contract passed.\n";
