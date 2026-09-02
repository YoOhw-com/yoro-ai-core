<?php
/**
 * YoRo local support diagnostics smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/support-diagnostics-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( ! class_exists( 'YoRo_Admin_Page' ) && file_exists( $plugin_root . '/includes/admin/class-yoro-admin-page.php' ) ) {
	require_once $plugin_root . '/includes/admin/class-yoro-admin-page.php';
}

$failures = [];
$assert   = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'YoRo_Logger' ), 'YoRo_Logger is not loaded.' );
$assert( class_exists( 'YoRo_Admin_Page' ), 'YoRo_Admin_Page is not loaded.' );
$assert( class_exists( 'YoRo_Trace_Context' ), 'YoRo_Trace_Context is not loaded.' );

$admin_source = file_get_contents( $plugin_root . '/includes/admin/class-yoro-admin-page.php' );
$admin_source = is_string( $admin_source ) ? $admin_source : '';
$assert( false !== strpos( $admin_source, "add_action( 'admin_post_' . self::SUPPORT_DIAGNOSTICS_ACTION" ), 'The local download must use an authenticated admin-post action.' );
$assert( false !== strpos( $admin_source, "header( 'Content-Type: application/json; charset=UTF-8' )" ), 'The local download must emit JSON.' );
$assert( false !== strpos( $admin_source, "header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' )" ), 'The local download must emit Cache-Control: no-store.' );

if ( ! class_exists( 'YoRo_Logger' ) || ! class_exists( 'YoRo_Admin_Page' ) ) {
	echo wp_json_encode( [ 'success' => false, 'failures' => $failures ], JSON_PRETTY_PRINT ) . PHP_EOL;
	exit( 1 );
}

global $wpdb;

$table            = YoRo_DB::logs_table_name();
$original_user_id = get_current_user_id();
$original_http_host = $_SERVER['HTTP_HOST'] ?? null;
$inserted_ids     = [];
$temporary_user   = 0;
$captured_http    = [];
$http_filter      = static function ( $preempt, $args, $url ) use ( &$captured_http ) {
	$captured_http[] = (string) $url;

	return $preempt;
};

try {
	$_SERVER['HTTP_HOST'] = 'yoplay8.local';

	for ( $index = 0; $index < 101; $index++ ) {
		$inserted_ids[] = YoRo_Logger::log(
			[
				'user_id'    => 0,
				'command'    => 'Email private-customer@example.test with credential-secret.',
				'action'     => 'orders.get_details',
				'params'     => [
					'email'   => 'private-customer@example.test',
					'api_key' => 'credential-secret',
				],
				'preview'    => true,
				'confirm'    => false,
				'status'     => 'executed',
				'trace_id'   => 'trace-support-' . $index,
				'duration_ms' => 20 + $index,
				'runtime'    => [
					'router_mode' => 'profile',
					'intent_name' => 'order_details',
					'ai_meta'     => [
						'provider'               => 'private-provider-name',
						'model'                  => 'private-model-name',
						'yoro_ai_api_request_id' => 'request-support-' . $index,
					],
				],
				'result'     => [
					'success' => true,
					'message' => 'Private result payload credential-secret.',
				],
				'undo_data'  => [ 'secret' => 'undo-secret' ],
				'error'      => 'error-secret',
			]
		);
	}

	$inserted_ids = array_values( array_filter( array_map( 'absint', $inserted_ids ) ) );
	$assert( 101 === count( $inserted_ids ), 'The diagnostic fixture rows were not inserted.' );

	$malformed_id = $inserted_ids[99] ?? 0;

	if ( $malformed_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB -- Corrupts one exact smoke-owned telemetry row to verify fail-closed reading.
		$wpdb->update(
			$table,
			[ 'telemetry' => '{malformed credential-secret' ],
			[ 'id' => $malformed_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	$diagnostics = YoRo_Logger::get_support_diagnostics( 1000 );
	$encoded     = wp_json_encode( $diagnostics );

	$assert( 100 === count( $diagnostics ), 'Logger diagnostics must cap reads at 100 newest rows.' );
	$assert( end( $inserted_ids ) === absint( $diagnostics[0]['id'] ?? 0 ), 'Logger diagnostics are not newest-first.' );
	$assert( 'trace-support-100' === ( $diagnostics[0]['trace_id'] ?? '' ), 'Logger diagnostics dropped the safe trace identifier.' );
	$assert( 'orders.get_details' === ( $diagnostics[0]['action'] ?? '' ), 'Logger diagnostics dropped the safe action.' );
	$assert( 'executed' === ( $diagnostics[0]['status'] ?? '' ), 'Logger diagnostics dropped the safe status.' );
	$assert( 120 === ( $diagnostics[0]['telemetry']['duration_ms'] ?? null ), 'Logger diagnostics dropped safe timing telemetry.' );
	$assert( 'profile' === ( $diagnostics[0]['telemetry']['router_mode'] ?? '' ), 'Logger diagnostics dropped safe routing telemetry.' );
	$assert( 'yoro_ai_api' === ( $diagnostics[0]['telemetry']['ai_provider'] ?? '' ), 'Logger diagnostics did not retain the normalized public provider label.' );
	$assert( 'yoro-ai-api' === ( $diagnostics[0]['telemetry']['ai_model'] ?? '' ), 'Logger diagnostics did not retain the normalized public model label.' );
	$assert( false === strpos( $encoded, 'private-customer@example.test' ), 'Logger diagnostics exposed customer content.' );
	$assert( false === strpos( $encoded, 'credential-secret' ), 'Logger diagnostics exposed credential or raw telemetry material.' );
	$assert( false === strpos( $encoded, 'undo-secret' ), 'Logger diagnostics exposed undo data.' );
	$assert( false === strpos( $encoded, 'error-secret' ), 'Logger diagnostics exposed raw error data.' );

	$malformed = array_values(
		array_filter(
			$diagnostics,
			static fn ( $row ) => absint( $row['id'] ?? 0 ) === $malformed_id
		)
	);
	$assert( [] === ( $malformed[0]['telemetry'] ?? null ), 'Malformed telemetry must degrade to an empty safe projection.' );

	$diagnostic_row_method = new ReflectionMethod( 'YoRo_Logger', 'support_diagnostic_row' );
	$diagnostic_row_method->setAccessible( true );
	$legacy_row = $diagnostic_row_method->invoke(
		null,
		[
			'id'         => 777,
			'action'     => 'orders.get_details',
			'status'     => 'executed',
			'preview'    => 0,
			'confirm'    => 0,
			'created_at' => '2026-09-02 00:00:00',
		]
	);
	$assert( '' === ( $legacy_row['trace_id'] ?? null ), 'A legacy row without trace_id must remain a bounded partial diagnostic.' );
	$assert( [] === ( $legacy_row['telemetry'] ?? null ), 'A legacy row without telemetry must not use a raw fallback.' );

	$temporary_user = wp_insert_user(
		[
			'user_login' => 'yoro_support_diagnostics_' . wp_generate_password( 8, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'support-diagnostics-' . wp_generate_password( 8, false ) . '@example.test',
			'role'       => 'subscriber',
		]
	);

	$assert( ! is_wp_error( $temporary_user ), 'The temporary capability fixture user could not be created.' );

	if ( ! is_wp_error( $temporary_user ) ) {
		$temporary_user = absint( $temporary_user );
		$user = new WP_User( $temporary_user );
		$user->add_cap( YoRo_Capabilities::VIEW_AUDIT );
		wp_set_current_user( $temporary_user );

		$valid_nonce = wp_create_nonce( YoRo_Admin_Page::SUPPORT_DIAGNOSTICS_NONCE_ACTION );
		$assert( true === YoRo_Admin_Page::validate_support_diagnostics_download( $valid_nonce ), 'VIEW_AUDIT plus the action nonce must authorize the local download.' );
		$assert( is_wp_error( YoRo_Admin_Page::validate_support_diagnostics_download( '' ) ), 'A missing download nonce must fail closed.' );
		$assert( is_wp_error( YoRo_Admin_Page::validate_support_diagnostics_download( 'invalid-nonce' ) ), 'An invalid download nonce must fail closed.' );

		ob_start();
		YoRo_Admin_Page::render_history_page();
		$authorized_history = (string) ob_get_clean();
		$assert( false !== strpos( $authorized_history, 'action=' . YoRo_Admin_Page::SUPPORT_DIAGNOSTICS_ACTION ), 'VIEW_AUDIT users must see the local diagnostics control.' );

		$deny_audit_capability = static function ( $allcaps ) {
			unset( $allcaps[ YoRo_Capabilities::VIEW_AUDIT ] );

			return $allcaps;
		};
		add_filter( 'user_has_cap', $deny_audit_capability, 999 );
		$assert( is_wp_error( YoRo_Admin_Page::validate_support_diagnostics_download( $valid_nonce ) ), 'A user without VIEW_AUDIT must not download diagnostics.' );

		ob_start();
		YoRo_Admin_Page::render_history_page();
		$unauthorized_history = (string) ob_get_clean();
		$assert( false === strpos( $unauthorized_history, 'action=' . YoRo_Admin_Page::SUPPORT_DIAGNOSTICS_ACTION ), 'The diagnostics control must be hidden without VIEW_AUDIT.' );
		remove_filter( 'user_has_cap', $deny_audit_capability, 999 );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB -- Captures exact read-only state around payload construction.
	$log_count_before = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	$options_before   = maybe_serialize( get_option( 'yoro_ai_api_status', null ) );

	add_filter( 'pre_http_request', $http_filter, 10, 3 );
	$payload = YoRo_Admin_Page::build_support_diagnostics_payload();
	remove_filter( 'pre_http_request', $http_filter, 10 );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB -- Verifies the local export did not mutate the plugin log.
	$log_count_after = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	$options_after   = maybe_serialize( get_option( 'yoro_ai_api_status', null ) );

	$assert( 'trace_id' === ( $payload['correlation']['join_key'] ?? '' ), 'The local export must identify trace_id as its diagnostic join key.' );
	$assert( 'diagnostic_only' === ( $payload['correlation']['semantics'] ?? '' ), 'The local export must keep trace_id diagnostic-only.' );
	$assert( false === ( $payload['correlation']['authoritative'] ?? null ), 'The local export must not make trace_id authoritative.' );
	$assert( count( $payload['diagnostics'] ?? [] ) <= YoRo_Logger::SUPPORT_DIAGNOSTIC_LIMIT, 'The local export exceeded the bounded logger limit.' );
	$assert( [] === $captured_http, 'Building the local export must not make an outbound request or upload.' );
	$assert( $log_count_before === $log_count_after, 'Building the local export must not mutate logger state.' );
	$assert( $options_before === $options_after, 'Building the local export must not mutate runtime option state.' );
	$assert( 'X-YoRo-Trace-Id' === YoRo_Trace_Context::HEADER, 'The existing diagnostic trace header changed.' );
} finally {
	remove_filter( 'pre_http_request', $http_filter, 10 );

	if ( ! empty( $inserted_ids ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $inserted_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB -- Deletes only exact smoke-owned rows during cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id IN ({$placeholders})",
				$inserted_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB
	}

	wp_set_current_user( $original_user_id );

	if ( null === $original_http_host ) {
		unset( $_SERVER['HTTP_HOST'] );
	} else {
		$_SERVER['HTTP_HOST'] = $original_http_host;
	}

	if ( $temporary_user && ! is_wp_error( $temporary_user ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( absint( $temporary_user ) );
	}
}

if ( [] !== $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "YoRo support diagnostics contract: PASS\n";
