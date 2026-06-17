<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_API_Client implements YoRo_AI_Client_Interface {

	public function handshake() {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		return $this->handshake_with_credentials( $api_url, $site_key );
	}

	public function handshake_with_credentials( $api_url, $site_key, $record_status = true ) {
		if ( '' === $api_url || '' === $site_key ) {
			$error = new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);

			if ( $record_status ) {
				$this->record_handshake_status( false, $error->get_error_message() );
			}

			return $error;
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_handshake_endpoint_url( $api_url ),
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $site_key,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'site_url'         => home_url(),
						'plugin_version'   => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
						'language_context' => $this->build_language_context(),
					]
				),
			]
		);
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$this->record_request( 'handshake', $duration_ms, 'error' );

			if ( $record_status ) {
				$this->record_handshake_status( false, $response->get_error_message() );
			}

			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $this->public_response_message(
				$json,
				__( 'YoRo AI API connection test failed.', 'yoro-ai-core' )
			);

			$this->record_request( 'handshake', $duration_ms, 'error' );

			if ( $record_status ) {
				$this->record_handshake_status( false, $message );
			}

			return new WP_Error( 'yoro_ai_api_handshake_error', $message );
		}

		if ( ! is_array( $json ) ) {
			$message = __( 'YoRo AI API returned an invalid handshake response.', 'yoro-ai-core' );

			$this->record_request( 'handshake', $duration_ms, 'error' );

			if ( $record_status ) {
				$this->record_handshake_status( false, $message );
			}

			return new WP_Error( 'yoro_ai_api_invalid_handshake', $message );
		}

		$status = $this->normalize_handshake_response( $json );

		$this->record_request( 'handshake', $duration_ms, 'success' );

		if ( $record_status ) {
			$this->record_handshake_status( true, '', $status );
		}

		return $status;
	}

	public function send_conversation_message( array $payload ) {
		return $this->request_conversation_message(
			$this->build_conversation_message_payload( $payload ),
			'conversation_message',
			'yoro_ai_api_conversation_error',
			__( 'I could not reach the YoRo AI conversation service.', 'yoro-ai-core' )
		);
	}

	public function get_conversation( $conversation_id ) {
		return $this->request_conversation_show(
			sanitize_text_field( (string) $conversation_id ),
			'conversation_show',
			'yoro_ai_api_conversation_show_error',
			__( 'I could not load the YoRo AI conversation.', 'yoro-ai-core' )
		);
	}

	public function confirm_action( array $payload ) {
		return $this->request_action_confirm(
			$this->build_action_confirm_payload( $payload ),
			'action_confirm',
			'yoro_ai_api_action_confirm_error',
			__( 'I could not confirm the YoRo AI action.', 'yoro-ai-core' )
		);
	}

	public function record_action_execution( array $payload ) {
		return $this->request_action_execution(
			$this->build_action_execution_payload( $payload ),
			'action_execution_event',
			'yoro_ai_api_action_execution_error',
			__( 'I could not record the YoRo AI action event.', 'yoro-ai-core' )
		);
	}

	public function compose_email( array $payload ) {
		return $this->request_email_compose(
			$this->build_email_compose_payload( $payload ),
			'compose_email',
			'yoro_ai_api_email_error',
			__( 'I could not reach the YoRo AI API email composer.', 'yoro-ai-core' )
		);
	}

	public function create_wordpress_connect_intent( array $payload, $api_url = '' ) {
		$api_url = '' !== $api_url ? $api_url : YoRo_AI_Settings::get_ai_api_url();

		if ( '' === $api_url ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API URL is not configured yet.', 'yoro-ai-core' )
			);
		}

		$response = wp_remote_post(
			$this->build_wordpress_connect_intent_endpoint_url( $api_url ),
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

		return $this->decode_public_json_response(
			$response,
			'yoro_ai_api_connect_intent_error',
			__( 'I could not start the YoRo account connection.', 'yoro-ai-core' )
		);
	}

	public function exchange_wordpress_connect_code( array $payload, $api_url = '' ) {
		$api_url = '' !== $api_url ? $api_url : YoRo_AI_Settings::get_ai_api_url();

		if ( '' === $api_url ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API URL is not configured yet.', 'yoro-ai-core' )
			);
		}

		$response = wp_remote_post(
			$this->build_wordpress_connect_exchange_endpoint_url( $api_url ),
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

		return $this->decode_public_json_response(
			$response,
			'yoro_ai_api_connect_exchange_error',
			__( 'I could not finish the YoRo account connection.', 'yoro-ai-core' )
		);
	}

	private function request_conversation_message( array $payload, $operation, $error_code, $default_message ) {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::ai_api_allows_feature( 'conversation' ) ) {
			return new WP_Error(
				'yoro_ai_api_feature_not_in_plan',
				__( 'This YoRo AI API feature is not available on the current plan.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::ai_api_has_quota_remaining() ) {
			return new WP_Error(
				'yoro_ai_api_quota_exhausted',
				__( 'The current YoRo AI API monthly request quota is exhausted.', 'yoro-ai-core' )
			);
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_conversation_message_endpoint_url( $api_url ),
			[
				'timeout' => $this->api_timeout( $operation, $payload ),
				'headers' => [
					'Authorization' => 'Bearer ' . $site_key,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
			]
		);
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$this->record_request( $operation, $duration_ms, 'error' );
			$this->record_api_usage( $json );

			return new WP_Error(
				$error_code,
				$this->public_response_message( $json, $default_message )
			);
		}

		if ( ! is_array( $json ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return new WP_Error(
				'yoro_ai_api_invalid_response',
				__( 'YoRo AI API returned an invalid response.', 'yoro-ai-core' )
			);
		}

		$this->record_request( $operation, $duration_ms, 'success' );
		$this->record_api_usage( $json );

		return wp_json_encode( $this->normalize_conversation_response( $json ) );
	}

	private function request_conversation_show( $conversation_id, $operation, $error_code, $default_message ) {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		if ( '' === $conversation_id ) {
			return new WP_Error(
				'yoro_ai_api_missing_conversation_id',
				__( 'I need a conversation id before I can load that conversation.', 'yoro-ai-core' )
			);
		}

		$started_at = microtime( true );
		$response = wp_remote_get(
			$this->build_conversation_show_endpoint_url( $api_url, $conversation_id ),
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $site_key,
					'Accept'        => 'application/json',
				],
			]
		);
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return new WP_Error(
				$error_code,
				$this->public_response_message( $json, $default_message )
			);
		}

		if ( ! is_array( $json ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return new WP_Error(
				'yoro_ai_api_invalid_response',
				__( 'YoRo AI API returned an invalid response.', 'yoro-ai-core' )
			);
		}

		$this->record_request( $operation, $duration_ms, 'success' );

		return $json;
	}

	private function request_action_confirm( array $payload, $operation, $error_code, $default_message ) {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_action_confirm_endpoint_url( $api_url ),
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $site_key,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
			]
		);
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return new WP_Error(
				$error_code,
				$this->public_response_message( $json, $default_message )
			);
		}

		$this->record_request( $operation, $duration_ms, 'success' );

		return is_array( $json ) ? $json : [ 'success' => true ];
	}

	private function request_action_execution( array $payload, $operation, $error_code, $default_message ) {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_action_execution_endpoint_url( $api_url ),
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $site_key,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
			]
		);
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return new WP_Error(
				$error_code,
				$this->public_response_message( $json, $default_message )
			);
		}

		$this->record_request( $operation, $duration_ms, 'success' );

		return is_array( $json ) ? $json : [ 'success' => true ];
	}

	private function request_email_compose( array $payload, $operation, $error_code, $default_message ) {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::ai_api_allows_feature( 'email_compose' ) ) {
			return new WP_Error(
				'yoro_ai_api_feature_not_in_plan',
				__( 'This YoRo AI API feature is not available on the current plan.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::ai_api_has_quota_remaining() ) {
			return new WP_Error(
				'yoro_ai_api_quota_exhausted',
				__( 'The current YoRo AI API monthly request quota is exhausted.', 'yoro-ai-core' )
			);
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_email_compose_endpoint_url( $api_url ),
			[
				'timeout' => $this->api_timeout( $operation, $payload ),
				'headers' => [
					'Authorization' => 'Bearer ' . $site_key,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
			]
		);
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$this->record_request( $operation, $duration_ms, 'error' );
			$this->record_api_usage( $json );

			return new WP_Error(
				$error_code,
				$this->public_response_message( $json, $default_message )
			);
		}

		if ( ! is_array( $json ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return new WP_Error(
				'yoro_ai_api_invalid_response',
				__( 'YoRo AI API returned an invalid response.', 'yoro-ai-core' )
			);
		}

		$this->record_request( $operation, $duration_ms, 'success' );
		$this->record_api_usage( $json );

		return wp_json_encode( $this->normalize_email_compose_response( $json ) );
	}

	private function public_response_message( $json, $default_message ) {
		$message = is_array( $json ) && ! empty( $json['message'] )
			? sanitize_text_field( $json['message'] )
			: $default_message;

		return str_ireplace( 'openai', 'YoRo AI API', $message );
	}

	private function decode_public_json_response( $response, $error_code, $default_message ) {
		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

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

	private function api_timeout( $operation, array $payload ) {
		return max(
			15,
			absint(
				apply_filters(
					'yoro_ai_api_request_timeout',
					75,
					sanitize_key( $operation ),
					$payload
				)
			)
		);
	}

	private function build_conversation_base_payload( array $payload ) {
		return [
			'feature'        => sanitize_key( $payload['feature'] ?? 'conversation' ),
			'command'        => sanitize_textarea_field( $payload['command'] ?? '' ),
			'site_url'       => esc_url_raw( $payload['site_url'] ?? home_url() ),
			'plugin_version' => sanitize_text_field( $payload['plugin_version'] ?? ( defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '' ) ),
			'language_context' => $this->build_conversation_language_context( $payload ),
			'context'        => isset( $payload['context'] ) && is_array( $payload['context'] )
				? $payload['context']
				: [],
			'router_hints'   => isset( $payload['router_hints'] ) && is_array( $payload['router_hints'] )
				? $payload['router_hints']
				: [],
			'action_catalog' => isset( $payload['action_catalog'] ) && is_array( $payload['action_catalog'] )
				? $payload['action_catalog']
				: [],
		];
	}

	private function build_conversation_message_payload( array $payload ) {
		$base_payload = $this->build_conversation_base_payload( $payload );
		$context = isset( $payload['context'] ) && is_array( $payload['context'] )
			? $payload['context']
			: [];
		$user_context = isset( $payload['user_context'] ) && is_array( $payload['user_context'] )
			? $payload['user_context']
			: [];

		return array_merge(
			$base_payload,
			[
				'message'         => $base_payload['command'],
				'conversation_id' => sanitize_text_field( $payload['conversation_id'] ?? ( $context['conversation_id'] ?? '' ) ),
				'user_context'    => $user_context,
			]
		);
	}

	private function build_action_confirm_payload( array $payload ) {
		return [
			'conversation_id'         => sanitize_text_field( $payload['conversation_id'] ?? '' ),
			'pending_operation_token' => sanitize_text_field( $payload['pending_operation_token'] ?? '' ),
		];
	}

	private function build_action_execution_payload( array $payload ) {
		return [
			'conversation_id'         => sanitize_text_field( $payload['conversation_id'] ?? '' ),
			'pending_operation_token' => sanitize_text_field( $payload['pending_operation_token'] ?? '' ),
			'event_type'              => sanitize_key( $payload['event_type'] ?? 'executed' ),
			'status'                  => sanitize_key( $payload['status'] ?? 'executed' ),
			'action'                  => sanitize_text_field( $payload['action'] ?? '' ),
			'operation_id'            => sanitize_text_field( $payload['operation_id'] ?? '' ),
			'summary'                 => sanitize_text_field( $payload['summary'] ?? '' ),
			'params'                  => isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : [],
			'result'                  => isset( $payload['result'] ) && is_array( $payload['result'] ) ? $payload['result'] : [],
			'user_content_slots'      => $this->normalize_user_content_slots( $payload['user_content_slots'] ?? [] ),
			'source'                  => sanitize_key( $payload['source'] ?? 'plugin' ),
			'trace_id'                => sanitize_text_field( $payload['trace_id'] ?? '' ),
		];
	}

	private function build_email_compose_payload( array $payload ) {
		return [
			'instruction'    => sanitize_textarea_field( $payload['instruction'] ?? '' ),
			'site_url'       => esc_url_raw( $payload['site_url'] ?? home_url() ),
			'plugin_version' => sanitize_text_field( $payload['plugin_version'] ?? ( defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '' ) ),
			'language_context' => $this->build_language_context( $payload ),
			'email_context_pack' => isset( $payload['email_context_pack'] ) && is_array( $payload['email_context_pack'] )
				? $payload['email_context_pack']
				: [],
			'order_context'  => isset( $payload['order_context'] ) && is_array( $payload['order_context'] )
				? $payload['order_context']
				: [],
		];
	}

	private function build_language_context( array $payload = [] ) {
		$incoming = isset( $payload['language_context'] ) && is_array( $payload['language_context'] )
			? $payload['language_context']
			: [];
		$site_locale = sanitize_text_field( $incoming['site_locale'] ?? ( $payload['site_locale'] ?? get_locale() ) );
		$user_locale = sanitize_text_field(
			$incoming['user_locale']
				?? ( $payload['user_locale'] ?? ( function_exists( 'get_user_locale' ) ? get_user_locale() : $site_locale ) )
		);
		$preferred_language = sanitize_key(
			$incoming['preferred_language']
				?? ( $payload['preferred_language'] ?? $this->language_from_locale( $user_locale ?: $site_locale ) )
		);

		return [
			'site_locale'        => $site_locale,
			'user_locale'        => $user_locale,
			'preferred_language' => $preferred_language ?: 'en',
		];
	}

	private function build_conversation_language_context( array $payload = [] ) {
		$context = $this->build_language_context( $payload );
		$incoming = isset( $payload['language_context'] ) && is_array( $payload['language_context'] )
			? $payload['language_context']
			: [];
		$has_explicit_preference = isset( $incoming['preferred_language'] ) || isset( $payload['preferred_language'] );

		if ( ! $has_explicit_preference ) {
			$context['preferred_language'] = '';
		}

		return $context;
	}

	private function language_from_locale( $locale ) {
		$locale = strtolower( sanitize_text_field( (string) $locale ) );

		if ( '' === $locale ) {
			return 'en';
		}

		$parts = preg_split( '/[_-]/', $locale );

		return sanitize_key( $parts[0] ?? 'en' ) ?: 'en';
	}

	private function build_conversation_message_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/conversations/message$#', $api_url ) ) {
			return $api_url;
		}

		if ( preg_match( '#/sites/handshake$#', $api_url ) ) {
			return preg_replace( '#/sites/handshake$#', '/conversations/message', $api_url );
		}

		return $api_url . '/conversations/message';
	}

	private function build_conversation_show_endpoint_url( $api_url, $conversation_id ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );
		$conversation_id = rawurlencode( sanitize_text_field( (string) $conversation_id ) );

		if ( preg_match( '#/conversations/[^/]+$#', $api_url ) ) {
			return preg_replace( '#/conversations/[^/]+$#', '/conversations/' . $conversation_id, $api_url );
		}

		if ( preg_match( '#/conversations/message$#', $api_url ) ) {
			return preg_replace( '#/conversations/message$#', '/conversations/' . $conversation_id, $api_url );
		}

		if ( preg_match( '#/actions/(execute|confirm)$#', $api_url ) ) {
			return preg_replace( '#/actions/(execute|confirm)$#', '/conversations/' . $conversation_id, $api_url );
		}

		if ( preg_match( '#/sites/handshake$#', $api_url ) ) {
			return preg_replace( '#/sites/handshake$#', '/conversations/' . $conversation_id, $api_url );
		}

		return $api_url . '/conversations/' . $conversation_id;
	}

	private function build_action_execution_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/actions/execute$#', $api_url ) ) {
			return $api_url;
		}

		if ( preg_match( '#/conversations/message$#', $api_url ) ) {
			return preg_replace( '#/conversations/message$#', '/actions/execute', $api_url );
		}

		if ( preg_match( '#/sites/handshake$#', $api_url ) ) {
			return preg_replace( '#/sites/handshake$#', '/actions/execute', $api_url );
		}

		return $api_url . '/actions/execute';
	}

	private function build_action_confirm_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/actions/confirm$#', $api_url ) ) {
			return $api_url;
		}

		if ( preg_match( '#/actions/execute$#', $api_url ) ) {
			return preg_replace( '#/actions/execute$#', '/actions/confirm', $api_url );
		}

		if ( preg_match( '#/conversations/message$#', $api_url ) ) {
			return preg_replace( '#/conversations/message$#', '/actions/confirm', $api_url );
		}

		if ( preg_match( '#/sites/handshake$#', $api_url ) ) {
			return preg_replace( '#/sites/handshake$#', '/actions/confirm', $api_url );
		}

		return $api_url . '/actions/confirm';
	}

	private function build_email_compose_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/ai/email/compose$#', $api_url ) ) {
			return $api_url;
		}

		if ( preg_match( '#/sites/handshake$#', $api_url ) ) {
			return preg_replace( '#/sites/handshake$#', '/ai/email/compose', $api_url );
		}

		return $api_url . '/ai/email/compose';
	}

	private function build_wordpress_connect_intent_endpoint_url( $api_url ) {
		$api_url = $this->build_api_base_url( $api_url );

		if ( preg_match( '#/connect/wordpress/intents$#', $api_url ) ) {
			return $api_url;
		}

		return $api_url . '/connect/wordpress/intents';
	}

	private function build_wordpress_connect_exchange_endpoint_url( $api_url ) {
		$api_url = $this->build_api_base_url( $api_url );

		if ( preg_match( '#/connect/wordpress/exchange$#', $api_url ) ) {
			return $api_url;
		}

		return $api_url . '/connect/wordpress/exchange';
	}

	private function build_handshake_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/sites/handshake$#', $api_url ) ) {
			return $api_url;
		}

		return $api_url . '/sites/handshake';
	}

	private function build_api_base_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		return preg_replace(
			'#/(?:sites/handshake|conversations(?:/message|/[^/]+)?|actions/(?:execute|confirm)|ai/email/compose|connect/wordpress/(?:intents|exchange))$#',
			'',
			$api_url
		);
	}

	private function normalize_conversation_response( array $response ) {
		$mode = sanitize_key( $response['mode'] ?? 'clarify' );
		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : [];
		$response_meta = isset( $response['meta'] ) && is_array( $response['meta'] )
			? $this->normalize_response_meta( $response['meta'] )
			: [];
		$action_plan = $this->normalize_conversation_action_plan( $response );
		$requested_operations = $this->normalize_conversation_requested_operations( $response['requested_operations'] ?? [] );
		$operation_graph = $this->normalize_conversation_operation_graph(
			$response['operation_graph'] ?? [],
			$response['operations'] ?? [],
			$requested_operations
		);
		$assistant_response = $this->normalize_assistant_response( $response['assistant'] ?? [] );
		$friendly_summary   = sanitize_textarea_field(
			$assistant_response['message']
				?? ( $assistant_response['question'] ?? ( $response['summary'] ?? ( $response['message'] ?? '' ) ) )
		);

		if ( ! in_array( $mode, [ 'task_request', 'clarify', 'chat_only', 'unsupported_request', 'out_of_scope' ], true ) ) {
			$mode = 'clarify';
		}

		return [
			'conversation_id'       => sanitize_text_field( $response['conversation_id'] ?? ( $response_meta['conversation_id'] ?? '' ) ),
			'stage'                 => sanitize_key( $response['stage'] ?? ( $response_meta['conversation_stage'] ?? '' ) ),
			'pending_operation_token' => sanitize_text_field( $response['pending_operation_token'] ?? ( $response_meta['pending_operation_token'] ?? '' ) ),
			'mode'                   => $mode,
			'language'               => sanitize_text_field( $response['language'] ?? 'unknown' ),
			'intent_family'          => sanitize_key( $response['intent_family'] ?? '' ),
			'action'                 => sanitize_text_field( $response['action'] ?? '' ),
			'params'                 => isset( $response['params'] ) && is_array( $response['params'] ) ? $response['params'] : [],
			'confidence'             => isset( $response['confidence'] ) ? (float) $response['confidence'] : 0,
			'missing'                => isset( $response['missing'] ) && is_array( $response['missing'] ) ? $response['missing'] : [],
			'ambiguities'            => isset( $response['ambiguities'] ) && is_array( $response['ambiguities'] ) ? $response['ambiguities'] : [],
			'evidence'               => isset( $response['evidence'] ) && is_array( $response['evidence'] )
				? $response['evidence']
				: [
					'matched_terms'       => [],
					'referenced_context'  => [],
					'notes'               => 'YoRo AI API route response.',
				],
			'semantic_frame'         => isset( $response['semantic_frame'] ) && is_array( $response['semantic_frame'] ) ? $response['semantic_frame'] : [],
			'operation_graph'        => $operation_graph,
			'operations'             => isset( $operation_graph['operations'] ) && is_array( $operation_graph['operations'] ) ? $operation_graph['operations'] : [],
			'requested_operations'   => $requested_operations,
			'clarification_question' => sanitize_text_field( $response['clarification_question'] ?? '' ),
			'summary'                => $friendly_summary,
			'reasoning_summary'      => sanitize_text_field( $response['reasoning_summary'] ?? '' ),
			'action_plan'            => $action_plan,
			'assistant'              => $assistant_response,
			'meta'                   => array_merge(
				$response_meta,
				[
					'routing_provider'            => $this->normalize_public_routing_provider( $response['routing_provider'] ?? '' ),
					'yoro_ai_api_parser_source'   => sanitize_key( $response['parser_source'] ?? '' ),
					'yoro_ai_api_request_id'      => sanitize_text_field( $usage['request_id'] ?? '' ),
					'yoro_ai_api_plan'            => sanitize_key( $usage['plan'] ?? '' ),
					'yoro_ai_api_usage_period'    => sanitize_text_field( $usage['period'] ?? '' ),
					'yoro_ai_api_usage_limit'     => isset( $usage['limit'] ) ? absint( $usage['limit'] ) : null,
					'yoro_ai_api_usage_used'      => isset( $usage['used'] ) ? absint( $usage['used'] ) : null,
					'yoro_ai_api_usage_remaining' => isset( $usage['remaining'] ) ? absint( $usage['remaining'] ) : null,
					'conversation_id'             => sanitize_text_field( $response['conversation_id'] ?? ( $response_meta['conversation_id'] ?? '' ) ),
					'conversation_stage'          => sanitize_key( $response['stage'] ?? ( $response_meta['conversation_stage'] ?? '' ) ),
					'pending_operation_token'     => sanitize_text_field( $response['pending_operation_token'] ?? ( $response_meta['pending_operation_token'] ?? '' ) ),
				]
			),
		];
	}

	private function normalize_assistant_response( $assistant ) {
		if ( ! is_array( $assistant ) ) {
			return [];
		}

		return [
			'version'           => isset( $assistant['version'] ) ? absint( $assistant['version'] ) : 1,
			'language'          => sanitize_text_field( $assistant['language'] ?? 'unknown' ),
			'tone'              => sanitize_key( $assistant['tone'] ?? 'natural' ),
			'response_type'     => sanitize_key( $assistant['response_type'] ?? 'message' ),
			'needs_input'       => ! empty( $assistant['needs_input'] ),
			'message'           => sanitize_textarea_field( $assistant['message'] ?? '' ),
			'question'          => sanitize_textarea_field( $assistant['question'] ?? '' ),
			'operation_summary' => sanitize_textarea_field( $assistant['operation_summary'] ?? '' ),
		];
	}

	private function normalize_conversation_action_plan( array $response ) {
		$mode = sanitize_key( $response['mode'] ?? 'clarify' );
		$action = sanitize_text_field( $response['action'] ?? '' );
		$params = isset( $response['params'] ) && is_array( $response['params'] ) ? $response['params'] : [];
		$plan = isset( $response['action_plan'] ) && is_array( $response['action_plan'] )
			? $response['action_plan']
			: [];
		$type = sanitize_key( $plan['type'] ?? '' );

		if ( ! in_array( $type, [ 'single', 'sequence', 'none' ], true ) ) {
			$type = 'task_request' === $mode && '' !== $action ? 'single' : 'none';
		}

		$plan_action = sanitize_text_field( $plan['action'] ?? $action );
		$plan_params = isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : $params;
		$steps = [];

		if ( isset( $plan['steps'] ) && is_array( $plan['steps'] ) ) {
			foreach ( $plan['steps'] as $index => $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}

				$normalized_step = $this->normalize_conversation_action_plan_step( $step, $index );

				if ( '' !== $normalized_step['action'] ) {
					$steps[] = $normalized_step;
				}
			}
		}

		if ( 'single' === $type && empty( $steps ) && '' !== $plan_action ) {
			$steps[] = $this->normalize_conversation_action_plan_step(
				[
					'id'     => 'step_1',
					'label'  => $plan_action,
					'action' => $plan_action,
					'params' => $plan_params,
				],
				0
			);
		}

		if ( 'none' === $type ) {
			$plan_action = '';
			$plan_params = [];
			$steps = [];
		}

		return [
			'type'   => $type,
			'action' => $plan_action,
			'params' => $plan_params,
			'steps'  => $steps,
		];
	}

	private function normalize_conversation_action_plan_step( array $step, $index = 0 ) {
		$action = sanitize_text_field( $step['action'] ?? '' );
		$meta = isset( $step['meta'] ) && is_array( $step['meta'] ) ? $step['meta'] : [];

		return [
			'id'       => ! empty( $step['id'] ) ? sanitize_key( $step['id'] ) : 'step_' . ( absint( $index ) + 1 ),
			'label'    => ! empty( $step['label'] ) ? sanitize_text_field( $step['label'] ) : $action,
			'action'   => $action,
			'params'   => isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
			'when'     => isset( $step['when'] ) && is_array( $step['when'] ) ? $step['when'] : [],
			'capture'  => isset( $step['capture'] ) && is_array( $step['capture'] ) ? $step['capture'] : [],
			'on_error' => ! empty( $step['on_error'] ) ? sanitize_key( $step['on_error'] ) : 'stop',
			'retry'    => isset( $step['retry'] ) && is_array( $step['retry'] )
				? $step['retry']
				: [ 'max_attempts' => 1 ],
			'meta'     => $meta,
			'user_content_slots' => $this->normalize_user_content_slots( $step['user_content_slots'] ?? ( $meta['user_content_slots'] ?? [] ) ),
		];
	}

	private function normalize_conversation_requested_operations( $operations ) {
		if ( empty( $operations ) || ! is_array( $operations ) ) {
			return [];
		}

		$normalized = [];

		foreach ( array_values( $operations ) as $index => $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$operation_key = sanitize_key( $operation['operation'] ?? '' );
			$action        = sanitize_text_field( $operation['action'] ?? '' );

			if ( '' === $operation_key && '' === $action ) {
				continue;
			}

			$normalized[] = [
				'id'        => sanitize_key( $operation['id'] ?? ( 'op_' . ( count( $normalized ) + 1 ) ) ),
				'operation' => $operation_key,
				'action'    => $action,
				'params'    => isset( $operation['params'] ) && is_array( $operation['params'] ) ? $operation['params'] : [],
				'missing'   => isset( $operation['missing'] ) && is_array( $operation['missing'] )
					? array_values( array_filter( array_map( 'sanitize_key', $operation['missing'] ) ) )
					: [],
				'user_content_slots' => $this->normalize_user_content_slots( $operation['user_content_slots'] ?? [] ),
				'evidence'  => sanitize_text_field( $operation['evidence'] ?? '' ),
				'position'  => isset( $operation['position'] ) ? absint( $operation['position'] ) : $index + 1,
			];
		}

		return $normalized;
	}

	private function normalize_conversation_operation_graph( $operation_graph, $operations, $fallback_requested_operations ) {
		$operation_graph = is_array( $operation_graph ) ? $operation_graph : [];
		$operations = is_array( $operations ) ? $operations : [];
		$fallback_requested_operations = is_array( $fallback_requested_operations ) ? $fallback_requested_operations : [];
		$raw_operations = [];

		if ( ! empty( $operation_graph['operations'] ) && is_array( $operation_graph['operations'] ) ) {
			$raw_operations = $operation_graph['operations'];
		} elseif ( ! empty( $operations ) ) {
			$raw_operations = $operations;
		} else {
			$raw_operations = $fallback_requested_operations;
		}

		$normalized_operations = [];

		foreach ( array_values( $raw_operations ) as $index => $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$params = isset( $operation['params'] ) && is_array( $operation['params'] ) ? $operation['params'] : [];
			$target = isset( $operation['target'] ) && is_array( $operation['target'] ) ? $operation['target'] : [];
			$order_ids = isset( $target['order_ids'] ) && is_array( $target['order_ids'] )
				? array_values( array_filter( array_map( 'absint', $target['order_ids'] ) ) )
				: [];

			if ( empty( $order_ids ) && ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
				$order_ids = array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) );
			}

			if ( empty( $order_ids ) && ! empty( $params['order_id'] ) ) {
				$order_ids = [ absint( $params['order_id'] ) ];
			}

			$operation_key = sanitize_key( $operation['operation'] ?? ( $operation['kind'] ?? '' ) );

			if ( '' === $operation_key && empty( $operation['action'] ) ) {
				continue;
			}

			$normalized_operations[] = [
				'id'        => sanitize_key( $operation['id'] ?? ( 'op_' . ( count( $normalized_operations ) + 1 ) ) ),
				'kind'      => sanitize_key( $operation['kind'] ?? $operation_key ),
				'operation' => $operation_key,
				'action'    => sanitize_text_field( $operation['action'] ?? '' ),
				'target'    => [
					'type'        => ! empty( $target['type'] ) ? sanitize_key( $target['type'] ) : ( ! empty( $order_ids ) ? 'order' : '' ),
					'order_id'    => ! empty( $target['order_id'] ) ? absint( $target['order_id'] ) : ( $order_ids[0] ?? null ),
					'order_ids'   => $order_ids,
					'customer_id' => ! empty( $target['customer_id'] ) ? absint( $target['customer_id'] ) : null,
					'email'       => sanitize_email( $target['email'] ?? ( $params['email'] ?? '' ) ),
				],
				'params'    => $params,
				'missing'   => isset( $operation['missing'] ) && is_array( $operation['missing'] )
					? array_values( array_filter( array_map( 'sanitize_key', $operation['missing'] ) ) )
					: [],
				'user_content_slots' => $this->normalize_user_content_slots( $operation['user_content_slots'] ?? [] ),
				'evidence'  => sanitize_text_field( $operation['evidence'] ?? '' ),
				'position'  => isset( $operation['position'] ) ? absint( $operation['position'] ) : $index + 1,
			];
		}

		usort(
			$normalized_operations,
			static function ( $a, $b ) {
				return absint( $a['position'] ?? 0 ) <=> absint( $b['position'] ?? 0 );
			}
		);

		return [
			'version'    => ! empty( $operation_graph['version'] ) ? absint( $operation_graph['version'] ) : 1,
			'operations' => array_values( $normalized_operations ),
		];
	}

	private function normalize_user_content_slots( $slots ) {
		if ( empty( $slots ) || ! is_array( $slots ) ) {
			return [];
		}

		$normalized = [];

		foreach ( array_values( $slots ) as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$value = array_key_exists( 'value', $slot ) && null !== $slot['value']
				? sanitize_textarea_field( (string) $slot['value'] )
				: null;
			$field = sanitize_key( $slot['field'] ?? '' );

			if ( '' === $field && null === $value ) {
				continue;
			}

			$item = [
				'operation_id' => sanitize_key( $slot['operation_id'] ?? '' ),
				'operation_key' => sanitize_key( $slot['operation_key'] ?? '' ),
				'action' => sanitize_text_field( $slot['action'] ?? '' ),
				'field' => $field,
				'value' => $value,
				'content_scope' => sanitize_key( $slot['content_scope'] ?? '' ),
				'provenance' => sanitize_key( $slot['provenance'] ?? '' ),
				'source_text_from_latest_user_message' => sanitize_textarea_field( $slot['source_text_from_latest_user_message'] ?? '' ),
				'source_text' => sanitize_textarea_field( $slot['source_text'] ?? '' ),
				'source_span' => $this->normalize_user_content_source_span( $slot['source_span'] ?? [] ),
				'confidence' => isset( $slot['confidence'] ) ? max( 0, min( 1, (float) $slot['confidence'] ) ) : null,
			];

			$normalized[] = array_filter(
				$item,
				static function ( $value ) {
					return null !== $value && '' !== $value && [] !== $value;
				}
			);

			if ( count( $normalized ) >= 20 ) {
				break;
			}
		}

		return $normalized;
	}

	private function normalize_user_content_source_span( $source_span ) {
		if ( ! is_array( $source_span ) ) {
			return [];
		}

		$start = array_key_exists( 'start', $source_span ) && null !== $source_span['start']
			? absint( $source_span['start'] )
			: null;
		$end = array_key_exists( 'end', $source_span ) && null !== $source_span['end']
			? absint( $source_span['end'] )
			: null;

		if ( null === $start && null === $end ) {
			return [];
		}

		return [
			'start' => $start,
			'end'   => $end,
		];
	}

	private function normalize_response_meta( array $meta ) {
		$normalized = [];

		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( (string) $key );
			$key = $this->normalize_public_meta_key( $key );

			if ( '' === $key || 0 !== strpos( $key, 'yoro_ai_api_' ) ) {
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$normalized[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$normalized[ $key ] = $this->normalize_public_meta_value( $key, $value );
			}
		}

		return $normalized;
	}

	private function normalize_public_meta_key( $key ) {
		$key = sanitize_key( (string) $key );

		switch ( $key ) {
			case 'yoro_ai_api_openai_ms':
				return 'yoro_ai_api_provider_ms';
			case 'yoro_ai_api_profile_router_openai_ms':
				return 'yoro_ai_api_profile_router_provider_ms';
			default:
				return $key;
		}
	}

	private function normalize_public_meta_value( $key, $value ) {
		if ( 'routing_provider' === $key ) {
			return $this->normalize_public_routing_provider( $value );
		}

		if ( 'yoro_ai_api_model' === $key ) {
			return 'yoro-ai-api';
		}

		return sanitize_text_field( $value );
	}

	private function normalize_public_routing_provider( $provider ) {
		return 'yoro_ai_api';
	}

	private function normalize_email_compose_response( array $response ) {
		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : [];

		return [
			'subject'          => sanitize_text_field( $response['subject'] ?? '' ),
			'body'             => sanitize_textarea_field( $response['body'] ?? '' ),
			'routing_provider' => $this->normalize_public_routing_provider( $response['routing_provider'] ?? '' ),
			'meta'             => [
				'yoro_ai_api_request_id'      => sanitize_text_field( $usage['request_id'] ?? '' ),
				'yoro_ai_api_plan'            => sanitize_key( $usage['plan'] ?? '' ),
				'yoro_ai_api_usage_period'    => sanitize_text_field( $usage['period'] ?? '' ),
				'yoro_ai_api_usage_limit'     => isset( $usage['limit'] ) ? absint( $usage['limit'] ) : null,
				'yoro_ai_api_usage_used'      => isset( $usage['used'] ) ? absint( $usage['used'] ) : null,
				'yoro_ai_api_usage_remaining' => isset( $usage['remaining'] ) ? absint( $usage['remaining'] ) : null,
			],
		];
	}

	private function normalize_handshake_response( array $response ) {
		$site         = isset( $response['site'] ) && is_array( $response['site'] ) ? $response['site'] : [];
		$client       = isset( $response['client'] ) && is_array( $response['client'] ) ? $response['client'] : [];
		$entitlements = isset( $response['entitlements'] ) && is_array( $response['entitlements'] ) ? $response['entitlements'] : [];
		$usage        = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : [];

		return [
			'connected'    => ! empty( $response['success'] ),
			'checked_at'   => current_time( 'mysql' ),
			'site'         => [
				'id'     => absint( $site['id'] ?? 0 ),
				'name'   => sanitize_text_field( $site['name'] ?? '' ),
				'url'    => esc_url_raw( $site['url'] ?? '' ),
				'status' => sanitize_key( $site['status'] ?? '' ),
				'plan'   => sanitize_key( $site['plan'] ?? '' ),
			],
			'client'       => [
				'site_url'       => esc_url_raw( $client['site_url'] ?? '' ),
				'plugin_version' => sanitize_text_field( $client['plugin_version'] ?? '' ),
			],
			'entitlements' => [
				'conversation'        => ! empty( $entitlements['conversation'] ),
				'email_composer'      => ! empty( $entitlements['email_composer'] ),
				'usage_plan'          => sanitize_key( $entitlements['usage_plan'] ?? '' ),
				'subscription_status' => sanitize_key( $entitlements['subscription_status'] ?? '' ),
			],
			'usage'        => [
				'period'    => sanitize_text_field( $usage['period'] ?? '' ),
				'limit'     => isset( $usage['limit'] ) ? absint( $usage['limit'] ) : null,
				'used'      => isset( $usage['used'] ) ? absint( $usage['used'] ) : null,
				'remaining' => isset( $usage['remaining'] ) ? absint( $usage['remaining'] ) : null,
			],
		];
	}

	private function record_handshake_status( $connected, $message = '', array $status = [] ) {
		YoRo_AI_Settings::update_ai_api_status(
			array_merge(
				$status,
				[
					'connected'  => (bool) $connected,
					'checked_at' => current_time( 'mysql' ),
					'message'    => sanitize_text_field( $message ),
				]
			)
		);
	}

	private function record_api_usage( $response ) {
		if ( ! is_array( $response ) || empty( $response['usage'] ) || ! is_array( $response['usage'] ) ) {
			return;
		}

		$usage = $response['usage'];
		$status = YoRo_AI_Settings::get_ai_api_status();
		$status['connected'] = true;
		$status['checked_at'] = current_time( 'mysql' );
		$status['message'] = '';
		$status['usage'] = [
			'period'    => sanitize_text_field( $usage['period'] ?? '' ),
			'limit'     => isset( $usage['limit'] ) ? absint( $usage['limit'] ) : null,
			'used'      => isset( $usage['used'] ) ? absint( $usage['used'] ) : null,
			'remaining' => isset( $usage['remaining'] ) ? absint( $usage['remaining'] ) : null,
		];

		if ( ! empty( $usage['plan'] ) && empty( $status['site']['plan'] ) ) {
			$status['site']['plan'] = sanitize_key( $usage['plan'] );
		}

		YoRo_AI_Settings::update_ai_api_status( $status );
	}

	private function record_request( $operation, $duration_ms, $status ) {
		YoRo_AI_Request_Tracker::record(
			sanitize_key( 'yoro_ai_api_' . $operation ),
			absint( $duration_ms ),
			sanitize_key( $status )
		);
	}

}
