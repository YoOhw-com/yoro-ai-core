<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Authenticated notification only. All execution authority remains in the pull protocol. */
class YoRo_Connector_Wake {
	const OPTION = 'yoro_connector_wake_v1';
	const NONCE_PREFIX = 'yoro_wake_nonce_';
	const RATE_PREFIX = 'yoro_wake_rate_';
	const MAX_SKEW = 60;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		register_rest_route( 'yoro/v1', '/connector-wake', [
			'methods' => 'POST', 'callback' => [ __CLASS__, 'handle' ],
			'permission_callback' => [ __CLASS__, 'permission' ],
		] );
	}

	private static function bindings() {
		if ( ! YoRo_Connector_Runtime::early_access_read_allowed() ) { return null; }
		$status = YoRo_AI_Settings::get_ai_api_status();
		$connection = $status['connector_runtime']['connector_connection_id'] ?? null;
		$installation = YoRo_AI_Settings::get_installation_uuid();
		$key_id = $status['credential']['id'] ?? null;
		$key = YoRo_AI_Settings::get_ai_api_site_key();
		$endpoint = rest_url( 'yoro/v1/connector-wake' );
		if ( ! is_string( $connection ) || '' === $connection || strlen( $connection ) > 128
			|| ! is_string( $installation ) || '' === $installation || ! is_int( $key_id ) || $key_id < 1 || '' === $key
			|| 'https' !== wp_parse_url( $endpoint, PHP_URL_SCHEME ) || false !== strpos( $endpoint, '?' ) ) { return null; }
		return [ 'connection_id' => $connection, 'installation_uuid' => $installation, 'site_key_id' => $key_id,
			'credential_hash' => hash( 'sha256', $key ), 'endpoint' => $endpoint ];
	}

	private static function binding_hash( array $bindings ) {
		return hash( 'sha256', wp_json_encode( $bindings ) );
	}

	private static function encryption_key() {
		return hash( 'sha256', 'yoro-wake-v1|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
	}

	private static function read_secret( array $state, $binding ) {
		if ( ! function_exists( 'openssl_decrypt' ) || ! hash_equals( $binding, (string) ( $state['binding'] ?? '' ) ) ) { return ''; }
		$packed = base64_decode( (string) ( $state['encrypted_secret'] ?? '' ), true );
		if ( false === $packed || 92 !== strlen( $packed ) ) { return ''; }
		$secret = openssl_decrypt( substr( $packed, 28 ), 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA,
			substr( $packed, 0, 12 ), substr( $packed, 12, 16 ), $binding );
		return is_string( $secret ) && preg_match( '/\A[a-f0-9]{64}\z/D', $secret ) ? $secret : '';
	}

	/** Best effort refresh from the existing locked publisher; cron remains available on failure. */
	public static function maybe_register( $client ) {
		$bindings = self::bindings();
		if ( null === $bindings || ! $client instanceof YoRo_Connector_Client ) { return; }
		$binding = self::binding_hash( $bindings );
		$state = get_option( self::OPTION, [] );
		$state = is_array( $state ) ? $state : [];
		$secret = self::read_secret( $state, $binding );
		if ( '' === $secret ) {
			if ( ! function_exists( 'openssl_encrypt' ) ) { return; }
			try {
				$secret = bin2hex( random_bytes( 32 ) );
				$iv = random_bytes( 12 );
			} catch ( Throwable $error ) { return; }
			$tag = '';
			$cipher = openssl_encrypt( $secret, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, $binding );
			if ( false === $cipher || 16 !== strlen( $tag ) ) { return; }
			$state = [ 'binding' => $binding, 'encrypted_secret' => base64_encode( $iv . $tag . $cipher ), 'next_attempt' => 0 ];
			update_option( self::OPTION, $state, false );
			// Fail closed if credential persistence failed; never register an unstored secret.
			$stored = get_option( self::OPTION, [] );
			if ( ! is_array( $stored ) || ! hash_equals( $secret, self::read_secret( $stored, $binding ) ) ) { return; }
		}
		if ( (int) ( $state['next_attempt'] ?? 0 ) > time() ) { return; }
		$state['next_attempt'] = time() + 60;
		update_option( self::OPTION, $state, false );
		$result = $client->register_early_access_wake( [ 'version' => 1, 'secret' => $secret, 'endpoint' => $bindings['endpoint'] ] );
		if ( ! is_wp_error( $result ) && true === ( $result['success'] ?? null ) ) {
			$state['next_attempt'] = time() + 600;
			update_option( self::OPTION, $state, false );
		}
	}

	private static function denied( $status = 401 ) {
		return new WP_Error( 'yoro_wake_denied', 'Connector wake is unavailable.', [ 'status' => $status ] );
	}

	/** Stateless because WordPress may invoke permission callbacks more than once. */
	public static function permission( $request ) {
		if ( ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() || [] !== $request->get_query_params()
			|| [] !== $request->get_file_params() || 'application/json' !== strtolower( trim( $request->get_header( 'content-type' ) ) ) ) { return self::denied(); }
		$raw = $request->get_body();
		if ( ! is_string( $raw ) || strlen( $raw ) > 1024 ) { return self::denied(); }
		$body = json_decode( $raw, true, 4 );
		$keys = is_array( $body ) ? array_keys( $body ) : [];
		sort( $keys );
		if ( [ 'connection_id', 'installation_uuid', 'nonce', 'site_key_id', 'timestamp', 'version' ] !== $keys
			|| wp_json_encode( $body ) !== $raw || 1 !== $body['version'] || ! is_int( $body['timestamp'] )
			|| abs( time() - $body['timestamp'] ) > self::MAX_SKEW || ! is_string( $body['nonce'] )
			|| ! preg_match( '/\A[a-f0-9]{64}\z/D', $body['nonce'] ) ) { return self::denied(); }
		$bindings = self::bindings();
		if ( null === $bindings ) { return self::denied( 503 ); }
		foreach ( [ 'connection_id', 'installation_uuid', 'site_key_id' ] as $key ) {
			if ( $bindings[ $key ] !== $body[ $key ] ) { return self::denied(); }
		}
		$state = get_option( self::OPTION, [] );
		$secret = is_array( $state ) ? self::read_secret( $state, self::binding_hash( $bindings ) ) : '';
		$signature = $request->get_header( 'x-yoro-wake-signature' );
		if ( '' === $secret || ! is_string( $signature ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $signature )
			|| ! hash_equals( hash_hmac( 'sha256', "yoro-wake-v1\n" . $raw, $secret ), $signature ) ) { return self::denied(); }
		return true;
	}

	private static function consume( $nonce ) {
		global $wpdb;
		$now = time();
		// Index-bounded, ephemeral transport receipts only; never touches operations or history.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 64", $wpdb->esc_like( self::NONCE_PREFIX ) . '%', $now ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) < %d LIMIT 8", $wpdb->esc_like( self::RATE_PREFIX ) . '%', strlen( self::RATE_PREFIX ) + 1, (int) floor( $now / 60 ) - 2 ) );
		$rate_key = self::RATE_PREFIX . (int) floor( $now / 60 );
		$rate = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = IF(CAST(option_value AS UNSIGNED) < 30, CAST(option_value AS UNSIGNED) + 1, option_value)", $rate_key ) );
		if ( ! in_array( $rate, [ 1, 2 ], true ) ) { return self::denied( 429 ); }
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::NONCE_PREFIX . $nonce, (string) ( $now + 2 * self::MAX_SKEW ) ) );
		return 1 === $inserted ? true : self::denied( 409 );
	}

	public static function handle( $request ) {
		$permission = self::permission( $request );
		if ( true !== $permission ) { return $permission; }
		$body = json_decode( $request->get_body(), true );
		$consumed = self::consume( $body['nonce'] );
		if ( true !== $consumed ) { return $consumed; }
		$result = ( new YoRo_Connector_Runtime() )->run_early_access_wake();
		if ( is_wp_error( $result ) ) {
			return self::denied( 'yoro_wake_busy' === $result->get_error_code() ? 409 : 503 );
		}
		$response = new WP_REST_Response( [ 'success' => true, 'processed' => (int) ( $result['processed'] ?? 0 ) ], 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
