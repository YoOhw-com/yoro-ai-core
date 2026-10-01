<?php
/**
 * YoRo hosted account connection smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/hosted-account-connect-contract.php
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
$previous_http_host   = $_SERVER['HTTP_HOST'] ?? null;
$_SERVER['HTTP_HOST'] = 'yoplay8.local';

$assert( class_exists( 'YoRo_AI_Settings' ), 'YoRo_AI_Settings is not loaded.' );
$assert( class_exists( 'YoRo_AI_API_Client' ), 'YoRo_AI_API_Client is not loaded.' );
$assert( class_exists( 'YoRo_AI_Provider_Resolver' ), 'YoRo_AI_Provider_Resolver is not loaded.' );
$assert( class_exists( 'YoRo_REST_API' ), 'YoRo_REST_API is not loaded.' );
$assert( class_exists( 'YoRo_Admin_Page' ), 'YoRo_Admin_Page is not loaded.' );

if ( ! class_exists( 'YoRo_AI_Settings' ) || ! class_exists( 'YoRo_AI_API_Client' ) ) {
	echo wp_json_encode( [ 'success' => false, 'failures' => $failures ], JSON_PRETTY_PRINT ) . PHP_EOL;
	exit( 1 );
}

$option_names = [
	YoRo_AI_Settings::OPTION_AI_API_URL,
	YoRo_AI_Settings::OPTION_AI_API_SITE_KEY,
	YoRo_AI_Settings::OPTION_AI_API_ENABLED,
	YoRo_AI_Settings::OPTION_AI_API_STATUS,
	YoRo_AI_Settings::OPTION_INSTALLATION_UUID,
];
$old_options  = [];

foreach ( $option_names as $option_name ) {
	$old_options[ $option_name ] = get_option( $option_name, null );
}

$captured_http = [];
$api_url       = 'http://yoro-account-connect.test/api/v1';
$plain_key     = 'yoro_ai_sk_hosted_connect_smoke';

$http_mode = 'normal';
$http_filter = static function ( $preempt, $args, $url ) use ( &$captured_http, &$http_mode ) {
	$body = [];

	if ( is_string( $args['body'] ?? null ) ) {
		$decoded = json_decode( $args['body'], true );
		$body    = is_array( $decoded ) ? $decoded : [];
	}

	$captured_http[] = [
		'url'     => (string) $url,
		'headers' => is_array( $args['headers'] ?? null ) ? $args['headers'] : [],
		'body'    => $body,
		'stored_key' => YoRo_AI_Settings::get_ai_api_site_key(),
	];

	if ( ( 'verify_failure' === $http_mode && 'verify' === ( $body['credential_lifecycle_phase'] ?? '' ) )
		|| ( 'finalize_ambiguous' === $http_mode && 'finalize' === ( $body['credential_lifecycle_phase'] ?? '' ) )
		|| ( 'disconnect_failure' === $http_mode && false !== strpos( (string) $url, '/sites/disconnect' ) ) ) {
		return new WP_Error( 'lifecycle_transport_failure', 'Simulated lost lifecycle response.' );
	}
	$payload = false !== strpos( (string) $url, '/sites/disconnect' )
		? [ 'success' => true ]
		: [
			'success'      => true,
			'credential_lifecycle_version' => 2,
			'credential_lifecycle_phase' => $body['credential_lifecycle_phase'] ?? 'finalize',
			'account_url'  => 'http://yoro-account-connect.test/app',
			'billing_url'  => 'http://yoro-account-connect.test/app/billing',
			'site'         => [
				'id'     => 1,
				'name'   => 'Hosted Connect Smoke',
				'url'    => home_url(),
				'status' => 'active',
				'plan'   => 'trial',
			],
			'organization' => [ 'id' => 1, 'name' => 'Smoke Organization' ],
			'account_connection' => [
				'required'  => true,
				'connected' => true,
				'status'    => 'active',
				'message'   => 'Connected.',
			],
			'connection'   => [
				'id'                => 1,
				'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(),
				'status'            => 'active',
				'plugin_version'    => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
			],
			'credential'   => [
				'id'                 => 1,
				'type'               => 'wordpress_connection',
				'site_connection_id' => 1,
				'abilities'          => [ 'site:handshake', 'site:disconnect' ],
				'expires_at'         => gmdate( 'c', time() + DAY_IN_SECONDS ),
			],
			'entitlements' => [ 'conversation' => true, 'usage_plan' => 'trial' ],
			'usage'        => [ 'limit' => 100, 'used' => 0, 'remaining' => 100 ],
			'connector_runtime' => [
				'connector_key'           => 'woocommerce',
				'connector_connection_id' => 'connection_hosted_smoke',
				'execution_mode'          => 'remote_agent',
				'protocol_version'        => 1,
				'connector_version'       => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
				'delivery_protocol'       => 'pull_v1',
				'capability_manifest'     => [
					'current'     => true,
					'manifest_id' => 'manifest_hosted_smoke',
					'revision'    => 42,
					'expires_at'  => gmdate( 'c', time() + DAY_IN_SECONDS ),
				],
				'cutover'                 => [
					'cutover_enabled'         => false,
					'connection_live_enabled' => false,
					'conformance_ready'       => false,
					'cutover_allowed'         => false,
					'checks'                  => [
						'active_connection'          => true,
						'remote_agent_mode'          => true,
						'compatible_driver'          => true,
						'operation_execution_feature' => false,
						'current_capability_manifest' => false,
						'current_context_pack'        => false,
						'runtime_credential'          => true,
						'conformance_attestation'     => false,
					],
				],
			],
		];

	if ( 'disconnect_unconfirmed' === $http_mode && false !== strpos( (string) $url, '/sites/disconnect' ) ) {
		$payload = [ 'success' => false ];
	}

	return [
		'headers'  => [],
		'body'     => wp_json_encode( $payload ),
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'cookies'  => [],
		'filename' => null,
	];
};

add_filter( 'pre_http_request', $http_filter, 10, 3 );

$negative_control = getenv( 'YORO_CREDENTIAL_NEGATIVE_CONTROL' );
if ( in_array( $negative_control, [ 'encryption_unavailable', 'constant_locked' ], true ) ) {
	try {
		if ( 'constant_locked' === $negative_control ) {
			define( 'YORO_AI_API_SITE_KEY', 'yoro_ai_sk_constant_control' );
			$assert( is_wp_error( YoRo_AI_Settings::save_connected_ai_api_credentials( $api_url, $plain_key ) ), 'Constant key was replaced.' );
			$assert( is_wp_error( YoRo_AI_Settings::disconnect_ai_api() ), 'Constant key was disconnected.' );
			$assert( 'yoro_ai_sk_constant_control' === YoRo_AI_Settings::get_ai_api_site_key(), 'Constant credential changed.' );
		} else {
			$assert( ! function_exists( 'openssl_encrypt' ), 'Encryption-negative control must disable openssl_encrypt in this isolated PHP process.' );
			update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, 'yoro_enc_v1:prior_control', false );
			$result = YoRo_AI_Settings::save_connected_ai_api_credentials( $api_url, $plain_key );
			$assert( is_wp_error( $result ) && 'yoro_ai_api_encryption_unavailable' === $result->get_error_code(), 'Unavailable encryption was accepted.' );
			$assert( 'yoro_enc_v1:prior_control' === get_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY ), 'Encryption failure discarded previous storage.' );
		}
		$assert( [] === $captured_http, 'Negative storage controls performed a network mutation.' );
	} finally {
		remove_filter( 'pre_http_request', $http_filter, 10 );
		foreach ( $old_options as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false );
			}
		}
	}
	echo wp_json_encode( [ 'success' => [] === $failures, 'control' => $negative_control, 'failures' => $failures ] ) . PHP_EOL;
	exit( [] === $failures ? 0 : 1 );
}

try {
	update_option( YoRo_AI_Settings::OPTION_AI_API_URL, $api_url, false );
	delete_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY );

	$installation_uuid = YoRo_AI_Settings::get_installation_uuid();
	$connect_proof     = YoRo_AI_Settings::create_wordpress_connect_proof();
	$save_result       = YoRo_AI_Settings::save_connected_ai_api_credentials( $api_url, $plain_key );
	$stored_key        = (string) get_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, '' );

	$assert( ! is_wp_error( $save_result ), 'Hosted credential could not be verified and saved.' );
	$assert( preg_match( '/^[0-9a-f-]{36}$/', $installation_uuid ) === 1, 'Installation UUID is invalid.' );
	$assert( $installation_uuid === YoRo_AI_Settings::get_installation_uuid(), 'Installation UUID is not stable.' );
	$assert( in_array( YoRo_AI_Settings::get_ai_api_environment(), [ 'production', 'staging', 'development' ], true ), 'YoRo API environment is invalid.' );
	if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
		$assert( 'development' === YoRo_AI_Settings::get_ai_api_environment(), 'Local WordPress environments must map to development.' );
	}
	$assert( strlen( $connect_proof['state'] ?? '' ) >= 32, 'Connect state is too short.' );
	$assert( strlen( $connect_proof['code_verifier'] ?? '' ) >= 43, 'PKCE verifier is too short.' );
	$assert(
		( $connect_proof['code_challenge'] ?? '' ) === rtrim( strtr( base64_encode( hash( 'sha256', $connect_proof['code_verifier'], true ) ), '+/', '-_' ), '=' ),
		'PKCE challenge does not match the verifier.'
	);
	$assert( $installation_uuid === ( $connect_proof['installation_uuid'] ?? '' ), 'Connect proof is not bound to the installation UUID.' );
	$assert( 0 === strpos( $stored_key, 'yoro_enc_v1:' ), 'Site credential was not encrypted at rest.' );
	$assert( $plain_key === YoRo_AI_Settings::get_ai_api_site_key(), 'Encrypted site credential could not be decrypted.' );
	$assert( YoRo_AI_Settings::is_ai_api_account_connected(), 'A valid hosted account connection was not accepted.' );
	$assert( YORO_AI_PROVIDER_YORO_AI_API === YoRo_AI_Provider_Resolver::resolve_provider(), 'The provider did not unlock for an active account connection.' );
	$assert( 'connection_hosted_smoke' === ( $save_result['connector_runtime']['connector_connection_id'] ?? '' ), 'Handshake normalization dropped the connector runtime identity.' );
	$assert( true === ( $save_result['connector_runtime']['capability_manifest']['current'] ?? null ), 'Handshake normalization dropped the server manifest attestation.' );
	$assert( 'manifest_hosted_smoke' === ( $save_result['connector_runtime']['capability_manifest']['manifest_id'] ?? '' ), 'Handshake normalization changed the server manifest identity.' );
	$assert( 42 === ( $save_result['connector_runtime']['capability_manifest']['revision'] ?? 0 ), 'Handshake normalization changed the server manifest revision.' );
	$assert( false === ( $save_result['connector_runtime']['cutover']['cutover_allowed'] ?? null ), 'Handshake normalization must preserve a disabled live cutover.' );

	$connector_client = new YoRo_Connector_Client();
	$connector_client->publish_capability_manifest( [ 'smoke' => 'manifest' ] );
	$connector_client->publish_context_pack( [ 'smoke' => 'context' ] );
	$connector_client->claim_delivery();
	$connector_client->renew_lease( 'delivery_smoke', 'claim_smoke', 2 );
	$connector_client->publish_event( [ 'smoke' => 'event' ], 'claim_smoke', 2 );
	$connector_client->publish_result( [ 'smoke' => 'result' ], 'claim_smoke', 2 );

	$connector_requests = array_values(
		array_filter(
			$captured_http,
			static fn ( $request ) => false !== strpos( $request['url'], '/connectors/' )
		)
	);
	$connector_paths = array_map( static fn ( $request ) => wp_parse_url( $request['url'], PHP_URL_PATH ), $connector_requests );
	$assert(
		[
			'/api/v1/connectors/capability-manifests',
			'/api/v1/connectors/context-packs',
			'/api/v1/connectors/operation-deliveries/claim',
			'/api/v1/connectors/operation-deliveries/delivery_smoke/lease',
			'/api/v1/connectors/events',
			'/api/v1/connectors/execution-results',
		] === $connector_paths,
		'Connector client routes must remain under the canonical API v1 base.'
	);
	$assert( 'Bearer ' . $plain_key === ( $connector_requests[0]['headers']['Authorization'] ?? '' ), 'Connector client must authenticate with the active site credential.' );
	$assert( 'claim_smoke' === ( $connector_requests[3]['headers']['X-YoRo-Lease-Token'] ?? '' ), 'Lease renewal must retain the exact claim token.' );
	$assert( '2' === ( $connector_requests[5]['headers']['X-YoRo-Lease-Generation'] ?? '' ), 'Result publication must retain the lease generation.' );

	$active_status = YoRo_AI_Settings::get_ai_api_status();
	$legacy_status = $active_status;
	$legacy_status['connected'] = true;
	$legacy_status['account_connection'] = [
		'required'  => true,
		'connected' => false,
		'status'    => 'account_required',
	];
	$legacy_status['connection'] = [];
	$legacy_status['credential'] = [
		'id'                 => 2,
		'type'               => 'manual',
		'site_connection_id' => 0,
	];
	update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $legacy_status, false );
	$assert( ! YoRo_AI_Settings::is_ai_api_account_connected(), 'A legacy manual key incorrectly unlocked the account gate.' );
	$assert( '' === YoRo_AI_Provider_Resolver::resolve_provider(), 'A legacy manual key incorrectly resolved the YoRo provider.' );

	$wrong_installation_status = $active_status;
	$wrong_installation_status['connection']['installation_uuid'] = wp_generate_uuid4();
	update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $wrong_installation_status, false );
	$assert( ! YoRo_AI_Settings::is_ai_api_account_connected(), 'A credential bound to another installation incorrectly unlocked the account gate.' );

	update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $active_status, false );
	$assert( YoRo_AI_Settings::is_ai_api_account_connected(), 'The valid account state could not be restored.' );

	$previous_user_id = get_current_user_id();
	$administrators = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );

	if ( ! empty( $administrators ) ) {
		wp_set_current_user( absint( $administrators[0] ) );
		$assert( true === YoRo_REST_API::account_connection_permission_check(), 'The connected REST gate rejected an administrator.' );
		ob_start();
		YoRo_Admin_Page::render_page();
		$active_board = (string) ob_get_clean();
		$assert( false !== strpos( $active_board, 'yoro-account-gate is-hidden' ), 'The account gate did not hide for an active connection.' );

		update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $legacy_status, false );
		$assert( is_wp_error( YoRo_REST_API::account_connection_permission_check() ), 'The REST gate accepted a legacy account state.' );
		ob_start();
		YoRo_Admin_Page::render_page();
		$locked_board = (string) ob_get_clean();
		$assert( false !== strpos( $locked_board, 'class="yoro-account-gate"' ), 'The onboarding gate was not visible for a legacy connection.' );
		$assert( false !== strpos( $locked_board, 'Connect YoRo Account' ), 'The locked board is missing its Connect YoRo Account action.' );

		update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $active_status, false );
		wp_set_current_user( $previous_user_id );
	}

	$admin_script = file_get_contents( $plugin_root . '/assets/js/yoro-ai-admin.js' );
	$assert( false !== strpos( (string) $admin_script, 'function requireAccountConnection()' ), 'The admin board is missing its client-side account gate.' );
	$assert( false !== strpos( (string) $admin_script, 'function isYoRoAccessChecking()' ), 'The admin board does not distinguish access hydration from a locked state.' );
	$assert( false !== strpos( (string) $admin_script, 'const gateHidden = accessible || checking;' ), 'The account gate can flash while operator access is still loading.' );
	$assert( false !== strpos( (string) $admin_script, "chatShell.classList.toggle('is-account-locked', !accessible && !checking);" ), 'The chat board renders a locked visual state while access is still loading.' );
	$assert( false !== strpos( (string) $admin_script, 'account_connection_permission_check' ) || method_exists( 'YoRo_REST_API', 'account_connection_permission_check' ), 'The server-side account gate is missing.' );

	$handshakes = array_values( array_filter( $captured_http, static fn ( $request ) => false !== strpos( $request['url'], '/sites/handshake' ) ) );
	$assert( [ 'verify', 'finalize' ] === array_column( array_column( $handshakes, 'body' ), 'credential_lifecycle_phase' ), 'V2 credential commit did not verify before finalizing.' );
	$assert( $plain_key === ( $handshakes[0]['stored_key'] ?? '' ), 'Verify occurred before credential persistence/read-back.' );
	$client = new YoRo_AI_API_Client();
	$client->exchange_wordpress_connect_code( [ 'code' => 'control_code' ], $api_url );
	$client->rotate_site_credential();
	$lifecycle_requests = array_values( array_filter( $captured_http, static fn ( $request ) => false !== strpos( $request['url'], '/wordpress/exchange' ) || false !== strpos( $request['url'], '/credentials/rotate' ) ) );
	$assert( 2 === count( $lifecycle_requests ), 'Lifecycle negotiation requests are missing.' );
	foreach ( $lifecycle_requests as $request ) {
		$assert( 2 === ( $request['body']['credential_lifecycle_version'] ?? null ), 'Core did not negotiate credential lifecycle v2.' );
	}

	$previous_storage = get_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY );
	$previous_status = YoRo_AI_Settings::get_ai_api_status();
	$previous_http_count = count( $captured_http );
	$deny_persistence = static fn ( $value, $old_value ) => $old_value;
	add_filter( 'pre_update_option_' . YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $deny_persistence, 10, 2 );
	$failed_commit = YoRo_AI_Settings::save_connected_ai_api_credentials( $api_url, 'yoro_ai_sk_storage_failure' );
	remove_filter( 'pre_update_option_' . YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $deny_persistence, 10 );
	$assert( is_wp_error( $failed_commit ) && 'yoro_ai_api_persistence_failed' === $failed_commit->get_error_code(), 'Failed option write was accepted.' );
	$assert( $previous_storage === get_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY ), 'Failed persistence lost previous credential.' );
	$assert( count( $captured_http ) === $previous_http_count, 'Failed persistence invoked verify/finalize.' );

	$corrupt_readback = static function ( $value ) use ( $previous_storage ) {
		return $previous_storage === $value ? $value : 'yoro_enc_v1:corrupt';
	};
	add_filter( 'option_' . YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $corrupt_readback );
	$failed_readback = YoRo_AI_Settings::save_connected_ai_api_credentials( $api_url, 'yoro_ai_sk_readback_failure' );
	remove_filter( 'option_' . YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $corrupt_readback );
	$assert( is_wp_error( $failed_readback ), 'Corrupt encrypted read-back was accepted.' );
	$assert( $previous_storage === get_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY ), 'Read-back failure did not restore previous credential.' );
	$assert( count( $captured_http ) === $previous_http_count, 'Failed read-back invoked verify/finalize.' );

	$http_mode = 'verify_failure';
	$failed_verify = YoRo_AI_Settings::save_connected_ai_api_credentials( $api_url . '/other', 'yoro_ai_sk_verify_failure' );
	$assert( is_wp_error( $failed_verify ), 'Verify failure was accepted.' );
	$assert( $previous_storage === get_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY ) && $api_url === YoRo_AI_Settings::get_ai_api_url(), 'Verify failure did not restore previous credential and URL.' );
	$assert( $previous_status === YoRo_AI_Settings::get_ai_api_status(), 'Verify failure changed prior local account status.' );
	$http_mode = 'normal';
	$assert( ! is_wp_error( $client->handshake_with_credentials( $api_url, $plain_key, false, 'verify' ) ), 'Old credential is unusable after failed verify.' );

	$http_mode = 'finalize_ambiguous';
	$proven_replacement = 'yoro_ai_sk_finalization_control';
	$ambiguous = YoRo_AI_Settings::save_connected_ai_api_credentials( $api_url, $proven_replacement );
	$assert( ! is_wp_error( $ambiguous ) && ! empty( $ambiguous['credential_finalization_pending'] ), 'Ambiguous finalize did not retain truthful pending state.' );
	$assert( $proven_replacement === YoRo_AI_Settings::get_ai_api_site_key(), 'Ambiguous finalize restored an old potentially revoked credential.' );
	$http_mode = 'normal';
	$converged = $client->handshake_with_credentials( $api_url, $proven_replacement );
	$assert( ! is_wp_error( $converged ) && empty( $converged['credential_finalization_pending'] ), 'Ordinary handshake did not converge pending finalization.' );

	$deactivation_http_count = count( $captured_http );
	yoro_ai_core_deactivate();
	$assert( count( $captured_http ) === $deactivation_http_count, 'Deactivation performed a remote lifecycle mutation.' );
	$assert( $proven_replacement === YoRo_AI_Settings::get_ai_api_site_key(), 'Deactivation cleared recovery material.' );
	foreach ( [ 'disconnect_failure', 'disconnect_unconfirmed' ] as $mode ) {
		$http_mode = $mode;
		$disconnect_failure = YoRo_AI_Settings::disconnect_ai_api();
		$assert( is_wp_error( $disconnect_failure ), 'Unconfirmed remote disconnect was accepted.' );
		$assert( $proven_replacement === YoRo_AI_Settings::get_ai_api_site_key(), 'Remote disconnect failure discarded local recovery credential.' );
	}
	$http_mode = 'normal';

	$disconnect_result = YoRo_AI_Settings::disconnect_ai_api();
	$assert( true === $disconnect_result, 'Remote disconnect did not succeed.' );
	$assert( '' === (string) get_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, '' ), 'Local credential was not cleared after remote revoke.' );

	$disconnect_requests = array_values(
		array_filter(
			$captured_http,
			static fn ( $request ) => false !== strpos( $request['url'], '/sites/disconnect' )
		)
	);
	$assert( 3 === count( $disconnect_requests ), 'Expected two failed controls and one confirmed disconnect request.' );
	$assert( $installation_uuid === ( $disconnect_requests[0]['body']['installation_uuid'] ?? '' ), 'Disconnect did not bind the installation UUID.' );
} finally {
	remove_filter( 'pre_http_request', $http_filter, 10 );

	foreach ( $old_options as $option_name => $old_value ) {
		if ( null === $old_value ) {
			delete_option( $option_name );
		} else {
			update_option( $option_name, $old_value, false );
		}
	}

	if ( null === $previous_http_host ) {
		unset( $_SERVER['HTTP_HOST'] );
	} else {
		$_SERVER['HTTP_HOST'] = $previous_http_host;
	}
}

echo wp_json_encode(
	[
		'success'  => [] === $failures,
		'failures' => $failures,
	],
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( [] === $failures ? 0 : 1 );
