<?php
/** Real WordPress permission, context and connection checks; disposable fixtures only. */
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
$previous_user = wp_get_current_user();
$previous_screen = get_current_screen();
$previous_styles = $GLOBALS['wp_styles'] ?? null;
$previous_scripts = $GLOBALS['wp_scripts'] ?? null;
$previous_status = get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, false );
$role_caps = wp_roles()->roles;
$previous_hooks = array_map( static fn( $hook ) => clone $hook, $GLOBALS['wp_filter'] );
$requests = 0;
$block_http = static function () use ( &$requests ) {
	++$requests;
	return new WP_Error( 'fixture_network_forbidden', 'Launcher must not make HTTP requests.' );
};
$render = static function () {
	ob_start();
	YoRo_Admin_Page::render_launcher();
	return ob_get_clean();
};
$denied = static function ( $label ) use ( $assert, $render ) {
	$GLOBALS['wp_styles'] = new WP_Styles();
	$GLOBALS['wp_scripts'] = new WP_Scripts();
	YoRo_Admin_Page::enqueue_launcher_assets();
	$assert( '' === $render() && ! wp_style_is( 'yoro-admin-launcher', 'enqueued' ) && ! wp_script_is( 'yoro-admin-launcher', 'enqueued' ), 'No launcher markup, style or script for ' . $label );
};

try {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
	if ( ! $admins ) {
		throw new RuntimeException( 'Disposable fixture requires an administrator.' );
	}
	wp_set_current_user( $admins[0] );
	$admin = clone wp_get_current_user();
	set_current_screen( 'dashboard' );
	YoRo_Admin_Page::init();
	add_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
	$assert( false !== has_action( 'admin_enqueue_scripts', [ YoRo_Admin_Page::class, 'enqueue_launcher_assets' ] ), 'Launcher stylesheet must use the admin enqueue hook.' );
	$assert( false !== has_action( 'admin_footer', [ YoRo_Admin_Page::class, 'render_launcher' ] ), 'Launcher markup must use the admin footer hook.' );
	$assert( false === has_action( 'wp_footer', [ YoRo_Admin_Page::class, 'render_launcher' ] ) && false === has_action( 'wp_enqueue_scripts', [ YoRo_Admin_Page::class, 'enqueue_launcher_assets' ] ), 'No frontend hooks may expose the launcher.' );

	foreach ( [ 'dashboard', 'plugins', 'toplevel_page_yoro-ai-core' ] as $screen ) {
		set_current_screen( $screen );
		$GLOBALS['wp_styles'] = new WP_Styles();
		$GLOBALS['wp_scripts'] = new WP_Scripts();
		YoRo_Admin_Page::enqueue_launcher_assets();
		$assert( [ 'yoro-admin-launcher' ] === $GLOBALS['wp_styles']->queue && [ 'yoro-admin-launcher' ] === $GLOBALS['wp_scripts']->queue, 'Authorized site admin gets only the launcher CSS and load controller: ' . $screen );
		$style = $GLOBALS['wp_styles']->registered['yoro-admin-launcher'];
		$assert( YORO_AI_CORE_URL . 'assets/css/yoro-admin-launcher.css' === $style->src && [] === $style->deps, 'Launcher assets must remain local and dependency-free.' );
		$script = $GLOBALS['wp_scripts']->registered['yoro-admin-launcher'];
		$assert( YORO_AI_CORE_URL . 'assets/js/yoro-admin-launcher.js' === $script->src && [] === $script->deps && 1 === $GLOBALS['wp_scripts']->get_data( 'yoro-admin-launcher', 'group' ), 'The local dependency-free controller must load in the footer after the inert template.' );
	}

	update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, [], false );
	$html = $render();
	$dom = new DOMDocument();
	@$dom->loadHTML( $html );
	$xpath = new DOMXPath( $dom );
	$assert( 1 === $xpath->query( '//template[@id="yoro-admin-launcher-template"]//*[@id="yoro-admin-launcher"]' )->length && 0 === $xpath->query( '//*[@id="yoro-admin-launcher" and not(ancestor::template)]' )->length, 'Launcher and image markup must remain inert inside a template until page load.' );
	$assert( false !== strpos( $html, 'Hi, I’m YoRo! Let’s connect your account and get started.' ), 'The connection hint must use the approved natural greeting.' );
	$links = $xpath->query( '//a' );
	$assert( 2 === $links->length, 'Disconnected site gets an icon and a connection hint.' );
	foreach ( $links as $link ) {
		$assert( admin_url( 'admin.php?page=yoro-ai-core' ) === $link->getAttribute( 'href' ) && ! $link->hasAttribute( 'target' ), 'Both links must navigate in the same tab to this site, including subdirectories.' );
	}
	$assert( 1 === $xpath->query( '//a[@aria-label and string-length(@aria-label)>0]/picture/img[@width="64" and @height="64" and @alt="" and @aria-hidden="true"]' )->length, '64px icon must have an accessible link name and decorative image.' );
	$assert( 1 === $xpath->query( '//source[@media="(prefers-reduced-motion: reduce)"]' )->length && false !== strpos( $html, 'yoro-c-64-static.svg' ), 'Reduced motion must select the static asset.' );
	$assert( false !== strpos( $html, 'yoro-c-64-2x-3-cycles.svg' ) && false === strpos( $html, '<script' ) && false === strpos( $html, '<form' ), 'The inert launcher template must contain no inline script or connection form.' );
	$unsafe_text = static fn( $text, $original, $domain ) => 'yoro-ai-core' === $domain ? '<script>fixture</script> & "label"' : $text;
	$unsafe_url = static fn( $url ) => $url . '&fixture=" onmouseover="fixture';
	add_filter( 'gettext', $unsafe_text, 10, 3 );
	add_filter( 'admin_url', $unsafe_url );
	try {
		$escaped = $render();
		$escaped_dom = new DOMDocument();
		@$escaped_dom->loadHTML( $escaped );
		$escaped_xpath = new DOMXPath( $escaped_dom );
		$assert( false !== strpos( $escaped, '&lt;script&gt;' ) && 0 === $escaped_xpath->query( '//script | //*[@onmouseover]' )->length, 'Translated content, accessible names and navigation URL must remain escaped.' );
	} finally {
		remove_filter( 'gettext', $unsafe_text );
		remove_filter( 'admin_url', $unsafe_url );
	}

	$status = [
		'connected' => true, 'service_available' => true,
		'site' => [ 'id' => 1, 'url' => home_url() ], 'organization' => [ 'id' => 1 ],
		'account_connection' => [ 'connected' => true, 'status' => 'active' ],
		'connection' => [ 'id' => 1, 'status' => 'active', 'installation_uuid' => YoRo_AI_Settings::get_installation_uuid() ],
		'credential' => [ 'id' => 1, 'type' => 'wordpress_connection', 'site_connection_id' => 1, 'expires_at' => gmdate( 'c', time() + 600 ) ],
	];
	update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $status, false );
	$assert( YoRo_AI_Settings::is_ai_api_account_connected(), 'Fixture must satisfy the actual connection predicate.' );
	$connected = $render();
	$assert( false === strpos( $connected, 'yoro-admin-launcher__hint' ) && false !== strpos( $connected, 'yoro-admin-launcher__icon' ), 'Connected site retains the icon and hides the connection hint.' );
	$status['credential']['expires_at'] = gmdate( 'c', time() - 60 );
	update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $status, false );
	$assert( false !== strpos( $render(), 'yoro-admin-launcher__hint' ), 'Expired credentials must follow the existing disconnected predicate.' );

	foreach ( [ 'manage_options', YoRo_Capabilities::MANAGE_CONNECTION ] as $capability ) {
		$GLOBALS['current_user'] = clone $admin;
		$GLOBALS['current_user']->allcaps[ $capability ] = false;
		$denied( 'missing capability ' . $capability );
	}
	wp_set_current_user( 0 );
	$denied( 'anonymous user' );
	$GLOBALS['current_user'] = clone $admin;
	foreach ( [ 'dashboard-network', 'dashboard-user' ] as $screen ) {
		set_current_screen( $screen );
		$denied( $screen );
	}
	$GLOBALS['current_screen'] = null;
	$assert( ! is_admin(), 'WP-CLI fixture must permit a real frontend context.' );
	$denied( 'frontend' );
	$assert( 0 === $requests, 'Rendering and enqueueing must never perform HTTP requests.' );
	$assert( $role_caps === wp_roles()->roles, 'Launcher must not change role grants.' );
} finally {
	remove_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
	false === $previous_status ? delete_option( YoRo_AI_Settings::OPTION_AI_API_STATUS ) : update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $previous_status, false );
	$GLOBALS['current_user'] = $previous_user;
	$GLOBALS['current_screen'] = $previous_screen;
	$GLOBALS['wp_styles'] = $previous_styles;
	$GLOBALS['wp_scripts'] = $previous_scripts;
	$GLOBALS['wp_filter'] = $previous_hooks;
}
if ( $failures ) {
	throw new RuntimeException( implode( "\n", $failures ) );
}
echo "YoRo admin launcher permissions, contexts, connection state and local navigation passed.\n";
