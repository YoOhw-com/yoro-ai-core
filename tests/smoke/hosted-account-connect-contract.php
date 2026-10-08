<?php
/**
 * YoRo hosted account connection smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/hosted-account-connect-contract.php
 */

/** Isolated helper-stub fault injection; never runs in the parent WordPress process. */
if ( PHP_SAPI === 'cli' && '--onboarding-persistence-probe' === ( $argv[1] ?? '' ) ) {
	$probe_mode = $argv[2] ?? '';
	define( 'ABSPATH', '/tmp/yoro-isolated-probe/' );
	define( 'WP_DEBUG', true );
	define( 'YORO_AI_CORE_VERSION', '0.2.5.1' );
	$probe_root = dirname( __DIR__, 2 );
	$probe_uuid = '11111111-1111-4111-8111-111111111111';
	$probe_status = [
		'connected' => true, 'service_available' => true,
		'site' => [ 'id' => 1, 'url' => 'https://fixture-store.test' ], 'organization' => [ 'id' => 1 ],
		'account_connection' => [ 'connected' => true, 'status' => 'active' ],
		'connection' => [ 'id' => 1, 'installation_uuid' => $probe_uuid, 'status' => 'active', 'plugin_version' => '0.2.5.1' ],
		'credential' => [ 'id' => 1, 'type' => 'wordpress_connection', 'site_connection_id' => 1, 'expires_at' => gmdate( 'c', time() + 86400 ) ],
		'connector_runtime' => [ 'protocol_version' => 1, 'connector_version' => '0.2.5.1',
			'capability_manifest' => [ 'current' => true, 'manifest_id' => 'fixture-manifest', 'revision' => 1, 'expires_at' => gmdate( 'c', time() + 3600 ) ] ],
		'early_access_onboarding' => [ 'status' => 'requested_pending', 'source' => 'api_handshake', 'site_id' => 1,
			'installation_uuid' => $probe_uuid, 'connection_healthy' => true, 'compatible' => true,
			'checked_at' => gmdate( 'c' ), 'expires_at' => gmdate( 'c', time() + 300 ),
			'future_endpoint' => 'https://fixture-api.test/mcp/early-access', 'runtime_enabled' => false ],
		'early_access_credential_fingerprint' => hash( 'sha256', 'fixture-existing-key' ),
	];
	$probe_options = [ 'yoro_ai_installation_uuid' => $probe_uuid, 'yoro_ai_api_url' => 'https://fixture-api.test/api/v1',
		'yoro_ai_api_site_key' => 'fixture-existing-key', 'yoro_ai_api_enabled' => 'yes', 'yoro_ai_api_status' => $probe_status ];
	function get_option( $name, $default = false ) { global $probe_options; return $probe_options[ $name ] ?? $default; }
	function update_option( $name, $value, $autoload = false ) {
		global $probe_options, $probe_mode;
		$failures = [ 'key_store' => 'yoro_ai_api_site_key', 'url_store' => 'yoro_ai_api_url', 'enabled_store' => 'yoro_ai_api_enabled' ];
		if ( ( $failures[ $probe_mode ] ?? '' ) === $name ) { return false; }
		$probe_options[ $name ] = $value; return true;
	}
	function sanitize_text_field( $value ) { return is_scalar( $value ) ? strip_tags( (string) $value ) : ''; }
	function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function esc_url_raw( $value ) { return (string) $value; }
	function absint( $value ) { return abs( (int) $value ); }
	function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
	function wp_parse_url( $value ) { return parse_url( $value ); }
	function home_url() { return 'https://fixture-store.test'; }
	function wp_salt( $scheme ) { return 'fixture-only-salt-' . $scheme; }
	function __( $value, $domain = '' ) { return $value; }
	function random_bytes( $length ) {
		global $probe_mode;
		if ( 'crypto' === $probe_mode ) { throw new Exception( 'Isolated entropy failure' ); }
		return str_repeat( 'x', $length );
	}
	class WP_Error {
		public function __construct( private $code, private $message ) {}
		public function get_error_code() { return $this->code; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	class YoRo_AI_API_Client {
		public function handshake_with_credentials( $api_url, $site_key, $record_status = true ) {
			global $probe_status;
			$status = $probe_status;
			$status['early_access_credential_fingerprint'] = hash( 'sha256', $site_key );
			return $status;
		}
	}
	require $probe_root . '/includes/ai/class-yoro-ai-settings.php';
	$before = YoRo_AI_Settings::get_early_access_onboarding()['status'];
	$old_key = YoRo_AI_Settings::get_ai_api_site_key();
	$target_url = 'url_store' === $probe_mode ? 'https://replacement-api.test/api/v1' : 'https://fixture-api.test/api/v1';
	if ( 'enabled_store' === $probe_mode ) { $probe_options['yoro_ai_api_enabled'] = 'no'; }
	$result = YoRo_AI_Settings::save_connected_ai_api_credentials( $target_url, 'missing_key' === $probe_mode ? '' : 'fixture-replacement-key' );
	$after = YoRo_AI_Settings::get_early_access_onboarding()['status'];
	$preserved = hash_equals( $old_key, YoRo_AI_Settings::get_ai_api_site_key() );
	$preservation_ok = ! in_array( $probe_mode, [ 'crypto', 'key_store', 'missing_key' ], true ) || $preserved;
	$error_codes = [ 'crypto' => 'yoro_ai_api_encryption_failed', 'missing_key' => 'yoro_ai_api_missing_connect_credentials' ];
	$expected_error = isset( $error_codes[ $probe_mode ] )
		? is_wp_error( $result ) && $error_codes[ $probe_mode ] === $result->get_error_code() : ! is_wp_error( $result );
	$returned_status = is_wp_error( $result ) ? 'error' : ( $result['early_access_onboarding']['status'] ?? 'unknown' );
	$projection_withheld = ! in_array( $returned_status, [ 'available', 'requested_pending', 'withdrawn' ], true );
	$success = 'requested_pending' === $before && 'unknown' === $after && $preservation_ok && $expected_error && $projection_withheld;
	echo json_encode( [ 'success' => $success, 'mode' => $probe_mode, 'before' => $before, 'after' => $after, 'old_key_preserved' => $preserved, 'returned_status' => $returned_status ] ) . PHP_EOL;
	exit( $success ? 0 : 1 );
}

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
foreach ( [ 'crypto', 'key_store', 'url_store', 'enabled_store', 'missing_key' ] as $probe_mode ) {
	$process = proc_open( [ PHP_BINARY, '-d', 'disable_functions=random_bytes', __FILE__, '--onboarding-persistence-probe', $probe_mode ],
		[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $probe_pipes );
	$assert( is_resource( $process ), 'Could not start isolated onboarding persistence probe.' );
	if ( is_resource( $process ) ) {
		$probe_output = stream_get_contents( $probe_pipes[1] ); fclose( $probe_pipes[1] );
		$probe_error = stream_get_contents( $probe_pipes[2] ); fclose( $probe_pipes[2] );
		$probe_exit = proc_close( $process );
		$probe_result = json_decode( $probe_output, true );
		$assert( 0 === $probe_exit && true === ( $probe_result['success'] ?? false ), 'Isolated onboarding persistence failure did not invalidate status: ' . $probe_mode . ' ' . $probe_error );
	}
}
$original_user_id = get_current_user_id();
$actor_meta_before = [];
foreach ( array_unique( array_merge( [ $original_user_id ], get_users( [ 'role' => 'administrator', 'fields' => 'ids' ] ) ) ) as $actor_id ) {
	if ( $actor_id < 1 ) { continue; }
	foreach ( [ YoRo_WordPress_Actor::ACCESS_META_KEY, YoRo_WordPress_Actor::SUBJECT_META_KEY ] as $meta_key ) {
		$actor_meta_before[ $actor_id ][ $meta_key ] = get_user_meta( $actor_id, $meta_key, false );
	}
}
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
$api_url       = 'https://yoro-account-connect.test/api/v1';
$plain_key     = 'yoro_ai_sk_hosted_connect_smoke';

$handshake_transform = null;
$exact_handshake_body = null;
$http_filter = static function ( $preempt, $args, $url ) use ( &$captured_http, &$handshake_transform, &$exact_handshake_body ) {
	$body = [];

	if ( is_string( $args['body'] ?? null ) ) {
		$decoded = json_decode( $args['body'], true );
		$body    = is_array( $decoded ) ? $decoded : [];
	}

	$captured_http[] = [
		'url'     => (string) $url,
		'headers' => is_array( $args['headers'] ?? null ) ? $args['headers'] : [],
		'body'    => $body,
	];

	$payload = false !== strpos( (string) $url, '/sites/disconnect' )
		? [ 'success' => true ]
		: [
			'success'      => true,
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
				'expires_at'         => gmdate( 'c', time() + 30 * DAY_IN_SECONDS ),
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

	if ( false === strpos( (string) $url, '/sites/disconnect' ) ) {
		$payload['early_access_onboarding'] = [
			'status' => 'available', 'source' => 'api_handshake', 'site_id' => 1,
			'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(),
			'connection_healthy' => true, 'compatible' => true,
			'checked_at' => gmdate( 'c' ), 'expires_at' => gmdate( 'c', time() + 300 ),
			'future_endpoint' => 'https://yoro-account-connect.test/mcp/early-access', 'runtime_enabled' => false,
		];
		if ( is_callable( $handshake_transform ) ) {
			$payload = $handshake_transform( $payload );
			if ( is_wp_error( $payload ) ) { return $payload; }
		}
	}

	return [
		'headers'  => [],
		'body'     => null !== $exact_handshake_body && false !== strpos( (string) $url, '/sites/handshake' ) ? $exact_handshake_body : wp_json_encode( $payload ),
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'cookies'  => [],
		'filename' => null,
	];
};

add_filter( 'pre_http_request', $http_filter, 10, 3 );

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

	$previous_actor = get_current_user_id();
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
	$assert( ! empty( $admins ), 'Disposable runtime must provide an administrator for onboarding certification.' );
	if ( ! empty( $admins ) ) {
		wp_set_current_user( $admins[0] );
		foreach ( [ 'unknown', 'available', 'not_available', 'requested_pending', 'withdrawn' ] as $state ) {
			$handshake_transform = static function ( $payload ) use ( $state ) { $payload['early_access_onboarding']['status'] = $state; return $payload; };
			$result = ( new YoRo_AI_API_Client() )->handshake_with_credentials( $api_url, $plain_key );
			$assert( $state === ( $result['early_access_onboarding']['status'] ?? '' ), 'Client dropped onboarding state: ' . $state );
			$assert( $state === YoRo_AI_Settings::get_early_access_onboarding()['status'], 'Persisted status changed: ' . $state );
			$get = YoRo_REST_API::get_ai_api_status()->get_data();
			$post = YoRo_REST_API::test_ai_api_handshake();
			$post = is_wp_error( $post ) ? [] : $post->get_data();
			$assert( $state === ( $get['early_access_onboarding']['status'] ?? '' ), 'REST GET dropped status: ' . $state );
			$assert( $state === ( $post['early_access_onboarding']['status'] ?? '' ), 'REST POST dropped status: ' . $state );
			$assert( false === ( $get['early_access_onboarding']['runtime_enabled'] ?? null ), 'REST status granted runtime authority.' );
			$assert( ! array_key_exists( 'early_access_credential_fingerprint', $get ) && ! array_key_exists( 'credential', $get['early_access_onboarding'] ?? [] ), 'Status projection leaked credential binding.' );
		}
		$cases = [
			'unknown status' => static function ( $p ) { $p['early_access_onboarding']['status'] = 'enabled'; return $p; },
			'activation hint' => static function ( $p ) { $p['early_access_onboarding']['runtime_enabled'] = true; return $p; },
			'unsafe URL' => static function ( $p ) { $p['early_access_onboarding']['future_endpoint'] = 'javascript:alert(1)'; return $p; },
			'foreign host' => static function ( $p ) { $p['early_access_onboarding']['future_endpoint'] = 'https://evil.test/mcp/early-access'; return $p; },
			'URL credentials' => static function ( $p ) { $p['early_access_onboarding']['future_endpoint'] = 'https://user:secret@yoro-account-connect.test/mcp/early-access'; return $p; },
			'site mismatch' => static function ( $p ) { $p['early_access_onboarding']['site_id'] = 2; return $p; },
			'installation mismatch' => static function ( $p ) { $p['early_access_onboarding']['installation_uuid'] = wp_generate_uuid4(); return $p; },
			'foreign site URL' => static function ( $p ) { $p['site']['url'] = 'https://other-store.test'; return $p; },
			'missing field' => static function ( $p ) { unset( $p['early_access_onboarding'] ); return $p; },
			'malformed field' => static function ( $p ) { $p['early_access_onboarding'] = 'requested_pending'; return $p; },
			'expired' => static function ( $p ) { $p['early_access_onboarding']['expires_at'] = gmdate( 'c', time() - 1 ); return $p; },
			'stale' => static function ( $p ) { $p['early_access_onboarding']['checked_at'] = gmdate( 'c', time() - 3600 ); return $p; },
			'manifest expired' => static function ( $p ) { $p['connector_runtime']['capability_manifest']['expires_at'] = gmdate( 'c', time() - 1 ); return $p; },
			'unsupported Core' => static function ( $p ) { $p['connection']['plugin_version'] = '0.0.1'; return $p; },
			'PII authority hints' => static function ( $p ) { $p['early_access_onboarding']['tenant'] = [ 'email' => 'secret@example.test' ]; $p['early_access_onboarding']['client_id'] = 'secret-client'; return $p; },
		];
		foreach ( $cases as $label => $transform ) {
			$handshake_transform = $transform;
			$result = ( new YoRo_AI_API_Client() )->handshake_with_credentials( $api_url, $plain_key );
			$assert( 'unknown' === ( $result['early_access_onboarding']['status'] ?? '' ), 'Unsafe onboarding payload survived: ' . $label );
			$rest = YoRo_REST_API::get_ai_api_status()->get_data();
			$assert( 'unknown' === ( $rest['early_access_onboarding']['status'] ?? '' ), 'Unsafe stored payload reached REST: ' . $label );
			$assert( false === strpos( wp_json_encode( $rest['early_access_onboarding'] ?? [] ), 'secret' ), 'New field leaked private content.' );
		}
		$handshake_transform = static function ( $p ) { $p['early_access_onboarding']['status'] = 'requested_pending'; return $p; };
		( new YoRo_AI_API_Client() )->handshake_with_credentials( $api_url, $plain_key );
		$use_ai_user = wp_create_user( 'ea-status-viewer-' . wp_generate_password( 8, false ), wp_generate_password( 30 ), 'ea-status-' . wp_generate_password( 8, false ) . '@example.test' );
		$assert( ! is_wp_error( $use_ai_user ), 'Could not create disposable USE_AI-only actor.' );
		if ( ! is_wp_error( $use_ai_user ) ) {
			$user = new WP_User( $use_ai_user ); $user->add_cap( YoRo_Capabilities::USE_AI );
			wp_set_current_user( $use_ai_user );
			$assert( YoRo_REST_API::ai_status_permission_check(), 'Existing USE_AI status permission changed.' );
			$get = YoRo_REST_API::get_ai_api_status()->get_data();
			$post = YoRo_REST_API::test_ai_api_handshake(); $post = is_wp_error( $post ) ? [] : $post->get_data();
			$assert( ! array_key_exists( 'early_access_onboarding', $get ) && ! array_key_exists( 'early_access_onboarding', $post ), 'USE_AI-only actor saw private enrollment interest.' );
			wp_set_current_user( $admins[0] );
			if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
			wp_delete_user( $use_ai_user );
		}
		$cached = get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS );
		$cached['early_access_onboarding']['checked_at'] = gmdate( 'c', time() - 3600 );
		update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $cached, false );
		$assert( 'unknown' === ( YoRo_REST_API::get_ai_api_status()->get_data()['early_access_onboarding']['status'] ?? '' ), 'Cached stale intent survived REST readback.' );
		$handshake_transform = static function ( $p ) { return new WP_Error( 'disposable_network_failure', 'Network unavailable' ); };
		( new YoRo_AI_API_Client() )->handshake_with_credentials( $api_url, $plain_key );
		$assert( 'unknown' === YoRo_AI_Settings::get_early_access_onboarding()['status'], 'Failed handshake retained pending intent.' );
		$handshake_transform = null;
		( new YoRo_AI_API_Client() )->handshake_with_credentials( $api_url, $plain_key );
		$valid_status = YoRo_AI_Settings::get_ai_api_status();
		$assert( 'available' === YoRo_AI_Settings::get_early_access_onboarding()['status'], 'Fresh reconnect did not restore API-owned status.' );
		$encrypt = new ReflectionMethod( YoRo_AI_Settings::class, 'encrypt_ai_api_site_key' );
		update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $encrypt->invoke( null, 'different-test-credential' ), false );
		$assert( 'unknown' === YoRo_AI_Settings::get_early_access_onboarding()['status'], 'Changed credential preserved stale intent.' );
		update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $stored_key, false );
		$handshake_transform = static function ( $p ) { return new WP_Error( 'invalid_rotation', 'Invalid replacement' ); };
		YoRo_AI_Settings::save_connected_ai_api_credentials( $api_url, 'invalid-replacement-key' );
		$assert( 'unknown' === YoRo_AI_Settings::get_early_access_onboarding()['status'], 'Invalid credential rotation retained intent.' );
		$assert( $plain_key === YoRo_AI_Settings::get_ai_api_site_key(), 'Invalid rotation changed the active connection credential.' );
		$handshake_transform = null;
		( new YoRo_AI_API_Client() )->handshake_with_credentials( $api_url, $plain_key );
		wp_set_current_user( $previous_actor );
	}

	$pair_api_path = getenv( 'YORO_API_PAIR_PATH' );
	if ( false !== $pair_api_path && '' !== $pair_api_path ) {
		$pair_core_path = getenv( 'YORO_CORE_PAIR_PATH' );
		$assert( is_string( $pair_core_path ) && realpath( $pair_core_path ) === realpath( $plugin_root ), 'Exact onboarding pair Core path mismatch.' );
		$assert( is_file( $pair_api_path . '/vendor/autoload.php' ) && is_file( $pair_api_path . '/tests/Feature/McpEarlyAccessOnboardingTest.php' ), 'Exact onboarding pair producer/vendor missing.' );
		$run_pair_process = static function ( array $command, $cwd, $environment = null ) {
			$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, $cwd, $environment );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Exact onboarding process could not start.' ); }
			$output = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
			$error = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
			$code = proc_close( $process );
			if ( 0 !== $code ) { throw new RuntimeException( 'Exact onboarding process failed: ' . $output . $error ); }
			return trim( $output );
		};
		$api_head = $run_pair_process( [ 'git', 'rev-parse', 'HEAD' ], $pair_api_path );
		$core_head = $run_pair_process( [ 'git', 'rev-parse', 'HEAD' ], $pair_core_path );
		$assert( preg_match( '/^[a-f0-9]{40}$/', $api_head ) && preg_match( '/^[a-f0-9]{40}$/', $core_head ), 'Exact onboarding pair heads invalid.' );
		$api_expected = getenv( 'YORO_EXACT_API_SHA' ); $core_expected = getenv( 'YORO_EXACT_CORE_SHA' );
		$assert( ( ! $api_expected || $api_head === $api_expected ) && ( ! $core_expected || $core_head === $core_expected ), 'Exact onboarding pair head mismatch.' );
		$api_remote = $run_pair_process( [ 'git', 'remote', 'get-url', 'origin' ], $pair_api_path );
		$core_remote = $run_pair_process( [ 'git', 'remote', 'get-url', 'origin' ], $pair_core_path );
		$assert( preg_match( '#^https://github\.com/YoOhw-com/yoro-ai-api(?:\.git)?$#i', $api_remote ) && preg_match( '#^https://github\.com/YoOhw-com/yoro-ai-core(?:\.git)?$#i', $core_remote ), 'Exact onboarding pair repository mismatch.' );
		if ( $failures ) { throw new RuntimeException( 'Exact onboarding pair context failed closed.' ); }
		$fixture_path = tempnam( sys_get_temp_dir(), 'yoro-ea-exact-fixtures-' );
		if ( false === $fixture_path ) { throw new RuntimeException( 'Exact onboarding fixture file unavailable.' ); }
		$pair_admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
		if ( empty( $pair_admins ) ) { throw new RuntimeException( 'Exact onboarding manager missing.' ); }
		wp_set_current_user( $pair_admins[0] );
		$pair_actor = YoRo_WordPress_Actor::current_payload();
		$exact_key = 'yoro_ai_sk_exact_fixture_' . wp_generate_password( 30, false );
		$fixture_env = [ 'PATH' => getenv( 'PATH' ), 'APP_ENV' => 'testing',
			'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
			'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
			'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
			'YORO_EA_TEST_HANDSHAKE_FIXTURE' => $fixture_path,
			'YORO_EA_TEST_SITE_URL' => home_url(), 'YORO_EA_TEST_INSTALLATION_UUID' => YoRo_AI_Settings::get_installation_uuid(),
			'YORO_EA_TEST_SITE_KEY' => $exact_key, 'YORO_EA_TEST_WORDPRESS_SUBJECT' => $pair_actor['subject'] ];
		try {
			$producer_output = $run_pair_process( [ PHP_BINARY, 'artisan', 'test', '--compact', 'tests/Feature/McpEarlyAccessOnboardingTest.php', '--filter=produces site bound' ], $pair_api_path, $fixture_env );
			$fixture_bytes = file_get_contents( $fixture_path );
			$fixtures = json_decode( $fixture_bytes, true );
			$required_states = [ 'available', 'requested_pending', 'withdrawn', 'not_available', 'unknown' ];
			if ( ! is_array( $fixtures ) || array_keys( $fixtures ) !== $required_states ) { throw new RuntimeException( 'Exact onboarding five-state producer output invalid.' ); }
			update_option( YoRo_AI_Settings::OPTION_AI_API_URL, 'https://api.example.test/api/v1', false );
			$encrypt = new ReflectionMethod( YoRo_AI_Settings::class, 'encrypt_ai_api_site_key' );
			update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $encrypt->invoke( null, $exact_key ), false );
			update_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, 'yes', false );
			$exact_admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
			if ( empty( $exact_admins ) ) { throw new RuntimeException( 'Exact onboarding WordPress manager missing.' ); }
			wp_set_current_user( $exact_admins[0] );
			$passed_states = [];
			foreach ( $fixtures as $state => $raw_payload ) {
				if ( ! is_string( $raw_payload ) ) { throw new RuntimeException( 'Exact onboarding raw API response missing.' ); }
				$exact_handshake_body = $raw_payload;
				$handshake_transform = null;
				$result = ( new YoRo_AI_API_Client() )->handshake_with_credentials( YoRo_AI_Settings::get_ai_api_url(), $exact_key );
				$get = YoRo_REST_API::get_ai_api_status()->get_data();
				$post = YoRo_REST_API::test_ai_api_handshake();
				$post = is_wp_error( $post ) ? [] : $post->get_data();
				if ( is_wp_error( $result ) || ( $result['early_access_onboarding']['status'] ?? '' ) !== $state
					|| YoRo_AI_Settings::get_early_access_onboarding()['status'] !== $state
					|| ( $get['early_access_onboarding']['status'] ?? '' ) !== $state || ( $post['early_access_onboarding']['status'] ?? '' ) !== $state
					|| false !== ( $get['early_access_onboarding']['runtime_enabled'] ?? null ) ) {
					throw new RuntimeException( 'Exact signed API to actual WordPress onboarding chain failed: ' . $state );
				}
				$passed_states[] = $state;
			}
			echo 'YORO_EA_EXACT_HANDSHAKE_CHAIN ' . wp_json_encode( [ 'status' => 'passed', 'api_head' => $api_head, 'core_head' => $core_head,
				'fixture_sha256' => hash( 'sha256', $fixture_bytes ), 'states' => $passed_states,
				'consumer' => 'actual_wordpress_client_settings_rest_get_post', 'raw_api_response_unchanged' => true ] ) . PHP_EOL;
		} finally {
			unlink( $fixture_path );
			$exact_handshake_body = null;
			$handshake_transform = null;
			update_option( YoRo_AI_Settings::OPTION_AI_API_URL, $api_url, false );
			update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $stored_key, false );
			wp_set_current_user( $original_user_id );
		}
	} else {
		echo "YORO_EA_EXACT_HANDSHAKE_CHAIN not_available: ordinary Core smoke has no exact API pair context.\n";
	}

	$disconnect_result = YoRo_AI_Settings::disconnect_ai_api();
	$assert( true === $disconnect_result, 'Remote disconnect did not succeed.' );
	$assert( 'unknown' === YoRo_AI_Settings::get_early_access_onboarding()['status'], 'Disconnect preserved pending intent.' );
	$assert( '' === (string) get_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, '' ), 'Local credential was not cleared after remote revoke.' );

	$disconnect_requests = array_values(
		array_filter(
			$captured_http,
			static fn ( $request ) => false !== strpos( $request['url'], '/sites/disconnect' )
		)
	);
	$assert( 1 === count( $disconnect_requests ), 'Expected one server-side disconnect request.' );
	$assert( $installation_uuid === ( $disconnect_requests[0]['body']['installation_uuid'] ?? '' ), 'Disconnect did not bind the installation UUID.' );
} finally {
	remove_filter( 'pre_http_request', $http_filter, 10 );
	foreach ( $actor_meta_before as $actor_id => $actor_meta ) {
		foreach ( $actor_meta as $meta_key => $values ) {
			delete_user_meta( $actor_id, $meta_key );
			foreach ( $values as $value ) { add_user_meta( $actor_id, $meta_key, $value ); }
			$assert( get_user_meta( $actor_id, $meta_key, false ) === $values, 'Hosted smoke did not restore original actor metadata.' );
		}
	}
	wp_set_current_user( $original_user_id );

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
