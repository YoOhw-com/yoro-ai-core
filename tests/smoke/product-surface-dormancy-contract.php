<?php
/**
 * YoRo initial-release product surface dormancy smoke contract.
 *
 * Run from the plugin root with:
 * php tests/smoke/product-surface-dormancy-contract.php
 *
 * The release smoke workflow also runs this file through WP-CLI eval-file.
 */

$plugin_root = dirname( __DIR__, 2 );
$failures    = [];

$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$read_source = static function ( $relative_path ) use ( $plugin_root, &$failures ) {
	$path = $plugin_root . '/' . ltrim( $relative_path, '/' );

	if ( ! is_readable( $path ) ) {
		$failures[] = "Required source is not readable: {$relative_path}";
		return '';
	}

	$source = file_get_contents( $path );

	if ( false === $source ) {
		$failures[] = "Required source could not be read: {$relative_path}";
		return '';
	}

	return $source;
};

$plugin_source       = $read_source( 'yoro-ai-core.php' );
$readme_source       = $read_source( 'readme.txt' );
$admin_source        = $read_source( 'includes/admin/class-yoro-admin-page.php' );
$rest_source         = $read_source( 'includes/rest/class-yoro-rest-api.php' );
$capabilities_source = $read_source( 'includes/core/class-yoro-capabilities.php' );
$composer_source     = $read_source( 'includes/ai/class-yoro-ai-email-composer.php' );
$email_ui_source     = $read_source( 'assets/js/yoro-ai-email-ui.js' );

$assert(
	1 === preg_match( '/^ \* Plugin Name: YoRo AI$/m', $plugin_source ),
	'The main plugin header must expose the public product name YoRo AI.'
);
$assert(
	1 === preg_match( '/^ \* Version: (0\.2\.5\.1)$/m', $plugin_source, $plugin_version ),
	'The candidate must have the distinct plugin version 0.2.5.1.'
);
$assert(
	false !== strpos( $plugin_source, '$yoro_ai_core_plugin_data = get_file_data(' ) &&
	false !== strpos( $plugin_source, "'Version' => 'Version'" ) &&
	false !== strpos( $plugin_source, "'YORO_AI_CORE_VERSION'," ) &&
	false !== strpos( $plugin_source, "? \$yoro_ai_core_plugin_data['Version']" ),
	'The runtime version must continue to derive from the plugin header.'
);
if ( defined( 'YORO_AI_CORE_VERSION' ) ) {
	$assert( '0.2.5.1' === YORO_AI_CORE_VERSION, 'The loaded runtime must report the candidate version 0.2.5.1.' );
}
$assert(
	false !== strpos( $plugin_source, 'Text Domain: yoro-ai-core' ) &&
	false !== strpos( $plugin_source, "define( 'YORO_AI_CORE_FILE', __FILE__ );" ) &&
	false !== strpos( $plugin_source, "define( 'YORO_AI_CORE_PATH', plugin_dir_path( __FILE__ ) );" ),
	'Internal yoro-ai-core compatibility identifiers must remain unchanged.'
);

$assert(
	0 === strpos( $readme_source, "=== YoRo AI ===\n" ),
	'The WordPress readme title must expose YoRo AI.'
);
$assert(
	1 === preg_match( '/^Stable tag: (0\.2\.5\.1)$/m', $readme_source, $stable_version ) &&
	isset( $plugin_version[1] ) && $plugin_version[1] === $stable_version[1] &&
	false !== strpos( $readme_source, "= 0.2.5.1 =\n" ),
	'The readme Stable tag and changelog must match the candidate plugin header.'
);
$assert(
	false !== strpos( $readme_source, 'YoRo AI is the local WordPress runtime for YoRo.' ),
	'The WordPress readme introduction must use the YoRo AI product name.'
);
$assert(
	false === strpos( $readme_source, 'YoRo Connector for WordPress' ),
	'The old public plugin product name must not remain in the WordPress readme.'
);
$assert(
	false !== strpos( $readme_source, '`yoro-ai-core`' ) &&
	false !== strpos( $readme_source, 'YoRo AI Core connects to the YoRo AI API URL' ),
	'Technically correct yoro-ai-core and Core runtime references must remain intact.'
);

$mailbox_ui_markers = [
	'data-yoro-quick-actions',
	'data-yoro-quick-inbox',
	'data-yoro-quick-toggle',
	'data-yoro-mailbox-settings',
	'data-yoro-mailbox-settings-body',
	"esc_html_e( 'Shop desk', 'yoro-ai-core' )",
	"esc_html_e( 'Email Inbox', 'yoro-ai-core' )",
];

foreach ( $mailbox_ui_markers as $mailbox_ui_marker ) {
	$assert(
		false === strpos( $admin_source, $mailbox_ui_marker ),
		"Mailbox product UI marker must stay dormant: {$mailbox_ui_marker}"
	);
}

$preserved_admin_markers = [
	'id="yoro-ai-command"',
	'id="yoro-ai-run"',
	'data-yoro-workflow-action="store.get_daily_briefing"',
	'data-yoro-workflow-action="operations.get_fulfillment_queue"',
	'draft an email for order #7199',
	"'yoro-ai-email-ui'",
	"'woocommerceEmailFromAddress'",
	"'woocommerceEmailFromName'",
	'name="yoro_ai_email_compose_enabled" value="yes"',
];

foreach ( $preserved_admin_markers as $preserved_admin_marker ) {
	$assert(
		false !== strpos( $admin_source, $preserved_admin_marker ),
		"Required chat/workflow/customer-email surface is missing: {$preserved_admin_marker}"
	);
}

$preserved_mailbox_compatibility_markers = [
	"'emailInboxUrl'",
	"'emailInboxSyncUrl'",
	"'emailThreadBaseUrl'",
	"'emailGmailConnectIntentUrl'",
	"'emailMicrosoftConnectIntentUrl'",
	"'emailImapConnectUrl'",
	"'emailMailboxDisconnectUrl'",
	"'manage_mailbox'",
];

foreach ( $preserved_mailbox_compatibility_markers as $compatibility_marker ) {
	$assert(
		false !== strpos( $admin_source, $compatibility_marker ),
		"Mailbox compatibility localization must remain intact: {$compatibility_marker}"
	);
}

$preserved_routes = [
	'/email/gmail/connect-intents',
	'/email/microsoft/connect-intents',
	'/email/imap/connect',
	'/email/disconnect',
	'/email/inbox',
	'/email/inbox/sync',
	'/email/threads/(?P<thread_id>\\d+)',
	'/email/threads/(?P<thread_id>\\d+)/reply',
];

foreach ( $preserved_routes as $preserved_route ) {
	$assert(
		false !== strpos( $rest_source, $preserved_route ),
		"Mailbox REST compatibility route must remain intact: {$preserved_route}"
	);
}

$assert(
	false !== strpos( $capabilities_source, "const MANAGE_MAILBOX = 'yoro_manage_mailbox';" ),
	'The internal Mailbox capability identifier must remain intact.'
);
$assert(
	false !== strpos( $composer_source, 'class YoRo_AI_Email_Composer' ) &&
	false !== strpos( $composer_source, "supports( 'email_compose' )" ),
	'The WooCommerce/customer-email composer implementation must remain intact.'
);
$assert(
	false !== strpos( $email_ui_source, 'function buildEditableWooEmailPreview' ) &&
	false !== strpos( $email_ui_source, 'function renderCustomerEmailPreviewResult' ),
	'The shared customer-email UI implementation must remain available.'
);
$assert(
	is_file( $plugin_root . '/tests/smoke/mailbox-inbox-rest-contract.php' ) &&
	is_file( $plugin_root . '/tests/smoke/mailbox-thread-surface-contract.js' ),
	'Existing Mailbox backend/thread compatibility smoke contracts must remain present.'
);

if ( defined( 'ABSPATH' ) && function_exists( 'get_users' ) ) {
	if ( ! class_exists( 'YoRo_Admin_Page' ) ) {
		require_once $plugin_root . '/includes/admin/class-yoro-admin-page.php';
	}

	$original_user_id = get_current_user_id();
	$admin_ids        = get_users(
		[
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		]
	);
	$admin_id         = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;

	$assert( $admin_id > 0, 'A WordPress administrator is required for rendered product-surface checks.' );

	if ( $admin_id > 0 ) {
		wp_set_current_user( $admin_id );
		ob_start();
		YoRo_Admin_Page::render_page();
		$rendered_admin = (string) ob_get_clean();
		wp_set_current_user( $original_user_id );

		$rendered_dormant_markers = [
			'data-yoro-quick-actions',
			'data-yoro-quick-inbox',
			'data-yoro-quick-toggle',
			'data-yoro-mailbox-settings',
			'>Shop desk<',
			'>Email Inbox<',
		];

		foreach ( $rendered_dormant_markers as $rendered_dormant_marker ) {
			$assert(
				false === strpos( $rendered_admin, $rendered_dormant_marker ),
				"Rendered Mailbox product UI must stay dormant: {$rendered_dormant_marker}"
			);
		}

		$rendered_preserved_markers = [
			'id="yoro-ai-command"',
			'id="yoro-ai-run"',
			'data-yoro-workflow-action="store.get_daily_briefing"',
			'data-yoro-workflow-action="operations.get_fulfillment_queue"',
			'name="yoro_ai_email_compose_enabled" value="yes"',
		];

		foreach ( $rendered_preserved_markers as $rendered_preserved_marker ) {
			$assert(
				false !== strpos( $rendered_admin, $rendered_preserved_marker ),
				"Rendered chat/workflow/customer-email surface is missing: {$rendered_preserved_marker}"
			);
		}
	}
}

$settings_source = $read_source( 'includes/ai/class-yoro-ai-settings.php' );
$assert( false !== strpos( $settings_source, "'runtime_enabled' => false" ), 'Onboarding fallback must stay runtime disabled.' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo product surface dormancy smoke contract passed.\n";
