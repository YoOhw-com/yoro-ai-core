<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Separate read-only support channel over existing site transport. Never forwards browser actor authority. */
class YoRo_Support_Transport {
	public static function request( $method, $path, array $body = [], array $session = [], $operator = false ) {
		$api = YoRo_AI_Settings::get_ai_api_url();
		$key = YoRo_AI_Settings::get_ai_api_site_key();
		$base = ( new YoRo_AI_API_Endpoint_Resolver() )->api_base( $api );
		if ( '' === $key || 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) ) { return new WP_Error( 'support_unavailable', 'Support is unavailable.' ); }
		$headers = [ 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json', 'Content-Type' => 'application/json',
			'X-YoRo-Support-Abuse' => self::abuse_key() ];
		if ( $session ) {
			$headers['X-YoRo-Support-Session'] = $session['session_id'];
			$headers['X-YoRo-Support-Credential'] = $session['credential'];
		}
		if ( $operator ) { $headers['X-YoRo-Actor-Assertion'] = YoRo_WordPress_Actor::assertion( $key ); }
		$response = wp_remote_request( $base . '/customer-support' . $path, [ 'method' => $method, 'timeout' => 18,
			'connect_timeout' => 3, 'redirection' => 0, 'sslverify' => true, 'headers' => $headers,
			'body' => 'GET' === $method ? null : wp_json_encode( $body ) ] );
		if ( ! is_wp_error( $response ) && 403 === wp_remote_retrieve_response_code( $response ) ) {
			$error = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $error ) && 'support_session_unavailable' === ( $error['code'] ?? '' ) ) {
				return new WP_Error( 'support_session_inactive', 'This support session is no longer active.' );
			}
		}
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return new WP_Error( 'support_unavailable', 'Support is unavailable. Please retry later or use the site contact details.' );
		}
		$value = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $value ) ? $value : new WP_Error( 'support_unavailable', 'Support is unavailable.' );
	}

	public static function abuse_key() {
		// Ignore forwarded headers; the deployment must configure its trusted reverse proxy separately.
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		return hash_hmac( 'sha256', $ip, hash_hmac( 'sha256', gmdate( 'Y-m-d' ), wp_salt( 'auth' ) ) );
	}

	public static function seal( array $value ) {
		$iv = random_bytes( 12 ); $tag = '';
		$cipher = openssl_encrypt( wp_json_encode( $value ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, home_url() );
		return false === $cipher ? '' : rtrim( strtr( base64_encode( $iv . $tag . $cipher ), '+/', '-_' ), '=' );
	}
	public static function open( $value ) {
		$raw = base64_decode( strtr( (string) $value, '-_', '+/' ), true );
		if ( false === $raw || strlen( $raw ) < 29 || strlen( $raw ) > 4096 ) { return []; }
		$text = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), home_url() );
		$session = is_string( $text ) ? json_decode( $text, true ) : null;
		return is_array( $session ) && is_string( $session['session_id'] ?? null ) && is_string( $session['credential'] ?? null )
			&& (int) ( $session['expires'] ?? 0 ) > time() ? $session : [];
	}
	public static function csrf( $cookie ) { return hash_hmac( 'sha256', (string) $cookie, self::key() ); }
	private static function key() { return hash( 'sha256', wp_salt( 'secure_auth' ) . '|' . home_url() . '|' . YoRo_AI_Settings::get_ai_api_site_key(), true ); }
}
