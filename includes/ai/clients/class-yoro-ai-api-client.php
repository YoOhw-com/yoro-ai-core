<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_API_Client implements YoRo_AI_Client_Interface {

	public function handshake() {
		$api_url  = YoRo_AI_Settings::get_cloud_api_url();
		$site_key = YoRo_AI_Settings::get_cloud_site_key();

		return $this->handshake_with_credentials( $api_url, $site_key );
	}

	public function handshake_with_credentials( $api_url, $site_key, $record_status = true ) {
		if ( '' === $api_url || '' === $site_key ) {
			$error = new WP_Error(
				'yoro_cloud_missing_config',
				__( 'YoRo AI API is not configured yet.', 'yoro-ai-core' )
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
						'site_url'       => home_url(),
						'plugin_version' => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
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
			$message = is_array( $json ) && ! empty( $json['message'] )
				? sanitize_text_field( $json['message'] )
				: __( 'YoRo AI API connection test failed.', 'yoro-ai-core' );

			$this->record_request( 'handshake', $duration_ms, 'error' );

			if ( $record_status ) {
				$this->record_handshake_status( false, $message );
			}

			return new WP_Error( 'yoro_cloud_handshake_error', $message );
		}

		if ( ! is_array( $json ) ) {
			$message = __( 'YoRo AI API returned an invalid handshake response.', 'yoro-ai-core' );

			$this->record_request( 'handshake', $duration_ms, 'error' );

			if ( $record_status ) {
				$this->record_handshake_status( false, $message );
			}

			return new WP_Error( 'yoro_cloud_invalid_handshake', $message );
		}

		$status = $this->normalize_handshake_response( $json );

		$this->record_request( 'handshake', $duration_ms, 'success' );

		if ( $record_status ) {
			$this->record_handshake_status( true, '', $status );
		}

		return $status;
	}

	public function parse_action( array $payload ) {
		return $this->not_ready( 'yoro_cloud_not_ready', __( 'YoRo AI API parser endpoint is reserved for the next phase.', 'yoro-ai-core' ) );
	}

	public function parse_intent( array $payload ) {
		return $this->request_route(
			$this->build_route_payload( $payload ),
			'parse_intent',
			'yoro_cloud_intent_api_error',
			__( 'I could not reach the YoRo AI API routing service.', 'yoro-ai-core' )
		);
	}

	public function plan_workflow( array $payload ) {
		return $this->request_workflow_plan(
			$this->build_workflow_plan_payload( $payload ),
			'plan_workflow',
			'yoro_cloud_workflow_api_error',
			__( 'I could not reach the YoRo AI API workflow planner.', 'yoro-ai-core' )
		);
	}

	public function generate_suggestions( array $payload ) {
		return $this->request_suggestions(
			$this->build_suggestions_payload( $payload ),
			'generate_suggestions',
			'yoro_cloud_suggestions_api_error',
			__( 'I could not reach the YoRo AI API suggestions service.', 'yoro-ai-core' )
		);
	}

	public function compose_email( array $payload ) {
		return $this->request_email_compose(
			$this->build_email_compose_payload( $payload ),
			'compose_email',
			'yoro_cloud_email_api_error',
			__( 'I could not reach the YoRo AI API email composer.', 'yoro-ai-core' )
		);
	}

	private function request_route( array $payload, $operation, $error_code, $default_message ) {
		$api_url  = YoRo_AI_Settings::get_cloud_api_url();
		$site_key = YoRo_AI_Settings::get_cloud_site_key();
		$feature  = sanitize_key( $payload['feature'] ?? '' );

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_cloud_missing_config',
				__( 'YoRo AI API is not configured yet.', 'yoro-ai-core' )
			);
		}

		if ( '' !== $feature && ! YoRo_AI_Settings::cloud_allows_feature( $feature ) ) {
			return new WP_Error(
				'yoro_cloud_feature_not_in_plan',
				__( 'This YoRo AI API feature is not available on the current plan.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::cloud_has_quota_remaining() ) {
			return new WP_Error(
				'yoro_cloud_quota_exhausted',
				__( 'The current YoRo AI API monthly request quota is exhausted.', 'yoro-ai-core' )
			);
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_route_endpoint_url( $api_url ),
			[
				'timeout' => $this->route_timeout( $operation, $payload ),
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
			$this->record_route_usage( $json );

			return new WP_Error(
				$error_code,
				is_array( $json ) && ! empty( $json['message'] )
					? sanitize_text_field( $json['message'] )
					: $default_message
			);
		}

		if ( ! is_array( $json ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return new WP_Error(
				'yoro_cloud_invalid_response',
				__( 'YoRo AI API returned an invalid response.', 'yoro-ai-core' )
			);
		}

		$this->record_request( $operation, $duration_ms, 'success' );
		$this->record_route_usage( $json );

		return wp_json_encode( $this->normalize_route_response( $json ) );
	}

	private function request_workflow_plan( array $payload, $operation, $error_code, $default_message ) {
		$api_url  = YoRo_AI_Settings::get_cloud_api_url();
		$site_key = YoRo_AI_Settings::get_cloud_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_cloud_missing_config',
				__( 'YoRo AI API is not configured yet.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::cloud_allows_feature( 'workflow_plan' ) ) {
			return new WP_Error(
				'yoro_cloud_feature_not_in_plan',
				__( 'This YoRo AI API feature is not available on the current plan.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::cloud_has_quota_remaining() ) {
			return new WP_Error(
				'yoro_cloud_quota_exhausted',
				__( 'The current YoRo AI API monthly request quota is exhausted.', 'yoro-ai-core' )
			);
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_workflow_plan_endpoint_url( $api_url ),
			[
				'timeout' => $this->route_timeout( $operation, $payload ),
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
			$this->record_route_usage( $json );

			return new WP_Error(
				$error_code,
				is_array( $json ) && ! empty( $json['message'] )
					? sanitize_text_field( $json['message'] )
					: $default_message
			);
		}

		if ( ! is_array( $json ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return new WP_Error(
				'yoro_cloud_invalid_response',
				__( 'YoRo AI API returned an invalid response.', 'yoro-ai-core' )
			);
		}

		$this->record_request( $operation, $duration_ms, 'success' );
		$this->record_route_usage( $json );

		return wp_json_encode( $this->normalize_workflow_plan_response( $json ) );
	}

	private function request_email_compose( array $payload, $operation, $error_code, $default_message ) {
		$api_url  = YoRo_AI_Settings::get_cloud_api_url();
		$site_key = YoRo_AI_Settings::get_cloud_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_cloud_missing_config',
				__( 'YoRo AI API is not configured yet.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::cloud_allows_feature( 'email_compose' ) ) {
			return new WP_Error(
				'yoro_cloud_feature_not_in_plan',
				__( 'This YoRo AI API feature is not available on the current plan.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::cloud_has_quota_remaining() ) {
			return new WP_Error(
				'yoro_cloud_quota_exhausted',
				__( 'The current YoRo AI API monthly request quota is exhausted.', 'yoro-ai-core' )
			);
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_email_compose_endpoint_url( $api_url ),
			[
				'timeout' => $this->route_timeout( $operation, $payload ),
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
			$this->record_route_usage( $json );

			return new WP_Error(
				$error_code,
				is_array( $json ) && ! empty( $json['message'] )
					? sanitize_text_field( $json['message'] )
					: $default_message
			);
		}

		if ( ! is_array( $json ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return new WP_Error(
				'yoro_cloud_invalid_response',
				__( 'YoRo AI API returned an invalid response.', 'yoro-ai-core' )
			);
		}

		$this->record_request( $operation, $duration_ms, 'success' );
		$this->record_route_usage( $json );

		return wp_json_encode( $this->normalize_email_compose_response( $json ) );
	}

	private function request_suggestions( array $payload, $operation, $error_code, $default_message ) {
		$api_url  = YoRo_AI_Settings::get_cloud_api_url();
		$site_key = YoRo_AI_Settings::get_cloud_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_cloud_missing_config',
				__( 'YoRo AI API is not configured yet.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::cloud_allows_feature( 'suggestions' ) ) {
			return new WP_Error(
				'yoro_cloud_feature_not_in_plan',
				__( 'This YoRo AI API feature is not available on the current plan.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Settings::cloud_has_quota_remaining() ) {
			return new WP_Error(
				'yoro_cloud_quota_exhausted',
				__( 'The current YoRo AI API monthly request quota is exhausted.', 'yoro-ai-core' )
			);
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_suggestions_endpoint_url( $api_url ),
			[
				'timeout' => $this->route_timeout( $operation, $payload ),
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
			$this->record_route_usage( $json );

			return new WP_Error(
				$error_code,
				is_array( $json ) && ! empty( $json['message'] )
					? sanitize_text_field( $json['message'] )
					: $default_message
			);
		}

		if ( ! is_array( $json ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return new WP_Error(
				'yoro_cloud_invalid_response',
				__( 'YoRo AI API returned an invalid response.', 'yoro-ai-core' )
			);
		}

		$this->record_request( $operation, $duration_ms, 'success' );
		$this->record_route_usage( $json );

		return wp_json_encode( $this->normalize_suggestions_response( $json ) );
	}

	private function route_timeout( $operation, array $payload ) {
		return max(
			15,
			absint(
				apply_filters(
					'yoro_ai_api_route_timeout',
					75,
					sanitize_key( $operation ),
					$payload
				)
			)
		);
	}

	private function build_route_payload( array $payload ) {
		if ( isset( $payload['feature'], $payload['command'] ) ) {
			return [
				'feature'        => sanitize_key( $payload['feature'] ),
				'command'        => sanitize_textarea_field( $payload['command'] ),
				'site_url'       => esc_url_raw( $payload['site_url'] ?? home_url() ),
				'plugin_version' => sanitize_text_field( $payload['plugin_version'] ?? ( defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '' ) ),
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

		$user_payload = $this->extract_user_payload( $payload );

		return [
			'feature'        => 'command_router',
			'command'        => sanitize_textarea_field( $user_payload['command'] ?? '' ),
			'site_url'       => home_url(),
			'plugin_version' => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
			'context'        => isset( $user_payload['context'] ) && is_array( $user_payload['context'] )
				? $user_payload['context']
				: [],
			'router_hints'   => isset( $user_payload['router_hints'] ) && is_array( $user_payload['router_hints'] )
				? $user_payload['router_hints']
				: [],
			'action_catalog' => isset( $user_payload['action_catalog'] ) && is_array( $user_payload['action_catalog'] )
				? $user_payload['action_catalog']
				: [],
		];
	}

	private function build_workflow_plan_payload( array $payload ) {
		if ( isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
			$payload = $this->extract_user_payload( $payload );
		}

		return [
			'command'                 => sanitize_textarea_field( $payload['command'] ?? '' ),
			'site_url'                => esc_url_raw( $payload['site_url'] ?? home_url() ),
			'plugin_version'          => sanitize_text_field( $payload['plugin_version'] ?? ( defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '' ) ),
			'workflow_scope'          => sanitize_key( $payload['workflow_scope'] ?? 'general' ),
			'allowed_action_names'    => isset( $payload['allowed_action_names'] ) && is_array( $payload['allowed_action_names'] )
				? array_values( array_filter( array_map( 'sanitize_text_field', $payload['allowed_action_names'] ) ) )
				: [],
			'context'                 => isset( $payload['context'] ) && is_array( $payload['context'] )
				? $payload['context']
				: [],
			'client_context'          => isset( $payload['client_context'] ) && is_array( $payload['client_context'] )
				? $payload['client_context']
				: [],
			'available_actions'       => isset( $payload['available_actions'] ) && is_array( $payload['available_actions'] )
				? $payload['available_actions']
				: [],
			'action_dependency_rules' => isset( $payload['action_dependency_rules'] ) && is_array( $payload['action_dependency_rules'] )
				? $payload['action_dependency_rules']
				: [],
			'condition_rules'         => isset( $payload['condition_rules'] ) && is_array( $payload['condition_rules'] )
				? $payload['condition_rules']
				: [],
		];
	}

	private function build_email_compose_payload( array $payload ) {
		if ( isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
			$payload = $this->extract_user_payload( $payload );
		}

		return [
			'instruction'    => sanitize_textarea_field( $payload['instruction'] ?? '' ),
			'site_url'       => esc_url_raw( $payload['site_url'] ?? home_url() ),
			'plugin_version' => sanitize_text_field( $payload['plugin_version'] ?? ( defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '' ) ),
			'order_context'  => isset( $payload['order_context'] ) && is_array( $payload['order_context'] )
				? $payload['order_context']
				: [],
		];
	}

	private function build_suggestions_payload( array $payload ) {
		$prompt = isset( $payload['prompt'] ) ? (string) $payload['prompt'] : '';

		if ( '' === trim( $prompt ) && isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
			$prompt = $this->extract_user_content( $payload );
		}

		$action = isset( $payload['action'] ) ? sanitize_text_field( $payload['action'] ) : '';

		if ( '' === $action ) {
			$action = $this->extract_action_from_suggestions_prompt( $prompt );
		}

		return [
			'prompt'         => sanitize_textarea_field( $prompt ),
			'action'         => sanitize_text_field( $action ),
			'site_url'       => esc_url_raw( $payload['site_url'] ?? home_url() ),
			'plugin_version' => sanitize_text_field( $payload['plugin_version'] ?? ( defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '' ) ),
		];
	}

	private function extract_user_payload( array $payload ) {
		$input = isset( $payload['input'] ) && is_array( $payload['input'] )
			? $payload['input']
			: [];

		foreach ( $input as $message ) {
			if ( ! is_array( $message ) || 'user' !== ( $message['role'] ?? '' ) ) {
				continue;
			}

			$content = isset( $message['content'] ) ? (string) $message['content'] : '';
			$decoded = json_decode( $content, true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return [];
	}

	private function extract_user_content( array $payload ) {
		$input = isset( $payload['input'] ) && is_array( $payload['input'] )
			? $payload['input']
			: [];

		foreach ( $input as $message ) {
			if ( ! is_array( $message ) || 'user' !== ( $message['role'] ?? '' ) ) {
				continue;
			}

			return isset( $message['content'] ) ? (string) $message['content'] : '';
		}

		return '';
	}

	private function extract_action_from_suggestions_prompt( $prompt ) {
		if ( preg_match( '/^Action:\s*(.+)$/mi', (string) $prompt, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		return '';
	}

	private function build_route_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/ai/route$#', $api_url ) ) {
			return $api_url;
		}

		if ( preg_match( '#/parse$#', $api_url ) ) {
			return preg_replace( '#/parse$#', '/ai/route', $api_url );
		}

		return $api_url . '/ai/route';
	}

	private function build_workflow_plan_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/ai/workflow/plan$#', $api_url ) ) {
			return $api_url;
		}

		if ( preg_match( '#/sites/handshake$#', $api_url ) ) {
			return preg_replace( '#/sites/handshake$#', '/ai/workflow/plan', $api_url );
		}

		if ( preg_match( '#/ai/route$#', $api_url ) ) {
			return preg_replace( '#/ai/route$#', '/ai/workflow/plan', $api_url );
		}

		if ( preg_match( '#/parse$#', $api_url ) ) {
			return preg_replace( '#/parse$#', '/ai/workflow/plan', $api_url );
		}

		return $api_url . '/ai/workflow/plan';
	}

	private function build_email_compose_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/ai/email/compose$#', $api_url ) ) {
			return $api_url;
		}

		if ( preg_match( '#/sites/handshake$#', $api_url ) ) {
			return preg_replace( '#/sites/handshake$#', '/ai/email/compose', $api_url );
		}

		if ( preg_match( '#/ai/workflow/plan$#', $api_url ) ) {
			return preg_replace( '#/ai/workflow/plan$#', '/ai/email/compose', $api_url );
		}

		if ( preg_match( '#/ai/route$#', $api_url ) ) {
			return preg_replace( '#/ai/route$#', '/ai/email/compose', $api_url );
		}

		if ( preg_match( '#/parse$#', $api_url ) ) {
			return preg_replace( '#/parse$#', '/ai/email/compose', $api_url );
		}

		return $api_url . '/ai/email/compose';
	}

	private function build_suggestions_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/ai/suggestions$#', $api_url ) ) {
			return $api_url;
		}

		if ( preg_match( '#/sites/handshake$#', $api_url ) ) {
			return preg_replace( '#/sites/handshake$#', '/ai/suggestions', $api_url );
		}

		if ( preg_match( '#/ai/email/compose$#', $api_url ) ) {
			return preg_replace( '#/ai/email/compose$#', '/ai/suggestions', $api_url );
		}

		if ( preg_match( '#/ai/workflow/plan$#', $api_url ) ) {
			return preg_replace( '#/ai/workflow/plan$#', '/ai/suggestions', $api_url );
		}

		if ( preg_match( '#/ai/route$#', $api_url ) ) {
			return preg_replace( '#/ai/route$#', '/ai/suggestions', $api_url );
		}

		if ( preg_match( '#/parse$#', $api_url ) ) {
			return preg_replace( '#/parse$#', '/ai/suggestions', $api_url );
		}

		return $api_url . '/ai/suggestions';
	}

	private function build_handshake_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/sites/handshake$#', $api_url ) ) {
			return $api_url;
		}

		if ( preg_match( '#/ai/route$#', $api_url ) ) {
			return preg_replace( '#/ai/route$#', '/sites/handshake', $api_url );
		}

		if ( preg_match( '#/parse$#', $api_url ) ) {
			return preg_replace( '#/parse$#', '/sites/handshake', $api_url );
		}

		return $api_url . '/sites/handshake';
	}

	private function normalize_route_response( array $response ) {
		$mode = sanitize_key( $response['mode'] ?? 'clarify' );
		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : [];

		if ( ! in_array( $mode, [ 'task_request', 'clarify', 'chat_only', 'unsupported_request', 'out_of_scope' ], true ) ) {
			$mode = 'clarify';
		}

		return [
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
			'clarification_question' => sanitize_text_field( $response['clarification_question'] ?? '' ),
			'summary'                => sanitize_text_field( $response['summary'] ?? ( $response['message'] ?? '' ) ),
			'reasoning_summary'      => sanitize_text_field( $response['reasoning_summary'] ?? '' ),
			'suggestions'            => isset( $response['suggestions'] ) && is_array( $response['suggestions'] ) ? $response['suggestions'] : [],
			'meta'                   => [
				'routing_provider'             => sanitize_key( $response['routing_provider'] ?? '' ),
				'yoro_ai_api_parser_source'    => sanitize_key( $response['parser_source'] ?? '' ),
				'yoro_ai_api_request_id'       => sanitize_text_field( $usage['request_id'] ?? '' ),
				'yoro_ai_api_plan'             => sanitize_key( $usage['plan'] ?? '' ),
				'yoro_ai_api_usage_period'     => sanitize_text_field( $usage['period'] ?? '' ),
				'yoro_ai_api_usage_limit'      => isset( $usage['limit'] ) ? absint( $usage['limit'] ) : null,
				'yoro_ai_api_usage_used'       => isset( $usage['used'] ) ? absint( $usage['used'] ) : null,
				'yoro_ai_api_usage_remaining'  => isset( $usage['remaining'] ) ? absint( $usage['remaining'] ) : null,
			],
		];
	}

	private function normalize_workflow_plan_response( array $response ) {
		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : [];
		$actions = [];

		if ( isset( $response['actions'] ) && is_array( $response['actions'] ) ) {
			foreach ( $response['actions'] as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}

				$actions[] = [
					'id'       => sanitize_key( $step['id'] ?? '' ),
					'label'    => sanitize_text_field( $step['label'] ?? '' ),
					'action'   => sanitize_text_field( $step['action'] ?? '' ),
					'params'   => isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
					'when'     => isset( $step['when'] ) && is_array( $step['when'] ) ? $step['when'] : [],
					'capture'  => isset( $step['capture'] ) && is_array( $step['capture'] ) ? $step['capture'] : [],
					'on_error' => sanitize_key( $step['on_error'] ?? 'stop' ),
					'retry'    => isset( $step['retry'] ) && is_array( $step['retry'] )
						? $step['retry']
						: [ 'max_attempts' => 1 ],
				];
			}
		}

		return [
			'matched'          => ! empty( $response['matched'] ),
			'confidence'       => isset( $response['confidence'] ) ? (float) $response['confidence'] : 0,
			'reason'           => sanitize_text_field( $response['reason'] ?? '' ),
			'workflow_label'   => sanitize_text_field( $response['workflow_label'] ?? '' ),
			'actions'          => $actions,
			'routing_provider' => sanitize_key( $response['routing_provider'] ?? '' ),
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

	private function normalize_email_compose_response( array $response ) {
		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : [];

		return [
			'subject'          => sanitize_text_field( $response['subject'] ?? '' ),
			'body'             => sanitize_textarea_field( $response['body'] ?? '' ),
			'routing_provider' => sanitize_key( $response['routing_provider'] ?? '' ),
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

	private function normalize_suggestions_response( array $response ) {
		$items = isset( $response['suggestions'] ) && is_array( $response['suggestions'] )
			? $response['suggestions']
			: $response;
		$suggestions = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( $item['label'] ?? '' );
			$command = sanitize_text_field( $item['command'] ?? '' );

			if ( '' === $label || '' === $command ) {
				continue;
			}

			$suggestions[] = [
				'label'   => $label,
				'command' => $command,
			];

			if ( count( $suggestions ) >= 3 ) {
				break;
			}
		}

		return $suggestions;
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
				'command_router' => ! empty( $entitlements['command_router'] ),
				'ai_router'      => ! empty( $entitlements['ai_router'] ),
				'email_composer' => ! empty( $entitlements['email_composer'] ),
				'workflows'      => ! empty( $entitlements['workflows'] ),
				'suggestions'    => ! empty( $entitlements['suggestions'] ),
				'usage_plan'     => sanitize_key( $entitlements['usage_plan'] ?? '' ),
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
		if ( ! class_exists( 'YoRo_AI_Settings' ) ) {
			return;
		}

		YoRo_AI_Settings::update_cloud_status(
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

	private function record_route_usage( $response ) {
		if ( ! class_exists( 'YoRo_AI_Settings' ) || ! is_array( $response ) || empty( $response['usage'] ) || ! is_array( $response['usage'] ) ) {
			return;
		}

		$usage = $response['usage'];
		$status = YoRo_AI_Settings::get_cloud_status();
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

		YoRo_AI_Settings::update_cloud_status( $status );
	}

	private function record_request( $operation, $duration_ms, $status ) {
		if ( class_exists( 'YoRo_AI_Request_Tracker' ) ) {
			YoRo_AI_Request_Tracker::record(
				sanitize_key( 'cloud_' . $operation ),
				absint( $duration_ms ),
				sanitize_key( $status )
			);
		}
	}

	private function not_ready( $code, $message ) {
		return new WP_Error( $code, $message );
	}
}

if ( ! class_exists( 'YoRo_Cloud_Client', false ) ) {
	class_alias( 'YoRo_AI_API_Client', 'YoRo_Cloud_Client' );
}
