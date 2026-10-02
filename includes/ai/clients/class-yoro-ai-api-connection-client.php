<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns YoRo account/site connection lifecycle transport.
 *
 * Conversation, mailbox, action execution, and semantic response normalization
 * intentionally remain outside this client.
 */
class YoRo_AI_API_Connection_Client {

	private $endpoints;

	public function __construct( $endpoints = null ) {
		$this->endpoints = $endpoints instanceof YoRo_AI_API_Endpoint_Resolver
			? $endpoints
			: new YoRo_AI_API_Endpoint_Resolver();
	}

	public function create_wordpress_connect_intent( array $payload, $api_url = '' ) {
		$api_url = '' !== $api_url ? esc_url_raw( $api_url ) : YoRo_AI_Settings::get_ai_api_url();

		if ( '' === $api_url ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API URL is not configured yet.', 'yoro-ai-core' )
			);
		}

		return $this->public_post(
			$this->endpoints->wordpress_connect_intent( $api_url ),
			$payload,
			'yoro_ai_api_connect_intent_error',
			__( 'I could not start the YoRo account connection.', 'yoro-ai-core' )
		);
	}

	public function exchange_wordpress_connect_code( array $payload, $api_url = '' ) {
		$api_url = '' !== $api_url ? esc_url_raw( $api_url ) : YoRo_AI_Settings::get_ai_api_url();

		if ( '' === $api_url ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API URL is not configured yet.', 'yoro-ai-core' )
			);
		}

		return $this->public_post(
			$this->endpoints->wordpress_connect_exchange( $api_url ),
			$payload,
			'yoro_ai_api_connect_exchange_error',
			__( 'I could not finish the YoRo account connection.', 'yoro-ai-core' )
		);
	}

	public function disconnect_site( array $payload = [] ) {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		return $this->authenticated_post(
			$this->endpoints->site_disconnect( $api_url ),
			$site_key,
			[
				'installation_uuid' => sanitize_text_field( $payload['installation_uuid'] ?? YoRo_AI_Settings::get_installation_uuid() ),
				'reason'            => sanitize_text_field( $payload['reason'] ?? 'Disconnected from WordPress.' ),
			],
			'yoro_ai_api_disconnect_error',
			__( 'YoRo could not revoke this WordPress connection.', 'yoro-ai-core' )
		);
	}

	public function rotate_site_credential() {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		return $this->authenticated_post(
			$this->endpoints->site_credential_rotation( $api_url ),
			$site_key,
			[
				'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(),
				'plugin_version'    => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
			],
			'yoro_ai_api_rotation_error',
			__( 'YoRo could not rotate the WordPress credential.', 'yoro-ai-core' )
		);
	}

	private function public_post( $url, array $payload, $error_code, $default_message ) {
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 15,
				'headers' => [
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->decode_public_json_response( $response, $error_code, $default_message );
	}

	private function authenticated_post( $url, $site_key, array $payload, $error_code, $default_message ) {
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 15,
				'redirection' => 0,
				'headers' => $this->authenticated_headers( $site_key ),
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->decode_public_json_response( $response, $error_code, $default_message );
	}

	private function authenticated_headers( $site_key ) {
		$headers = [
			'Authorization' => 'Bearer ' . $site_key,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
		];
		$assertion = class_exists( 'YoRo_WordPress_Actor' ) ? YoRo_WordPress_Actor::assertion( $site_key ) : '';

		if ( '' !== $assertion ) {
			$headers['X-YoRo-Actor-Assertion'] = $assertion;
		}

		return $headers;
	}

	private function public_response_message( $json, $default_message ) {
		$message = is_array( $json ) && ! empty( $json['message'] )
			? sanitize_text_field( $json['message'] )
			: $default_message;

		if ( false !== stripos( $message, 'insufficient authentication scopes' ) ) {
			return __( 'The connected mailbox needs updated permissions. Reconnect Gmail or Microsoft from Settings > Email Inbox, then try this inbox action again.', 'yoro-ai-core' );
		}

		return str_ireplace( 'openai', 'YoRo AI API', $message );
	}

	private function decode_public_json_response( $response, $error_code, $default_message ) {
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				$error_code,
				$this->public_response_message( $json, $default_message )
			);
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'yoro_ai_api_invalid_response',
				__( 'YoRo AI API returned an invalid response.', 'yoro-ai-core' )
			);
		}

		return $json;
	}
}
