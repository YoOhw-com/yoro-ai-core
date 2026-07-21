<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_API_Client implements YoRo_AI_Client_Interface {

	public function handshake() {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		$status = $this->handshake_with_credentials( $api_url, $site_key );

		if ( is_wp_error( $status ) || ! $this->should_rotate_credential( $status ) ) {
			return $status;
		}

		$rotation = $this->rotate_site_credential();

		if ( is_wp_error( $rotation ) || empty( $rotation['site_key'] ) ) {
			return $status;
		}

		$saved = YoRo_AI_Settings::save_connected_ai_api_credentials( $api_url, sanitize_text_field( $rotation['site_key'] ) );

		return is_wp_error( $saved ) ? $status : $saved;
	}

	public function handshake_with_credentials( $api_url, $site_key, $record_status = true ) {
		if ( '' === $api_url || '' === $site_key ) {
			$error = new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);

			if ( $record_status ) {
				$this->record_handshake_status( false, $error->get_error_message(), [], 'account_required' );
			}

			return $error;
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_handshake_endpoint_url( $api_url ),
			[
				'timeout' => 15,
				'headers' => $this->authenticated_headers( $site_key ),
				'body'    => wp_json_encode(
					[
						'site_url'         => home_url(),
						'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(),
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
				$this->record_handshake_status( false, $response->get_error_message(), [], 'degraded' );
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
				$failure_state = in_array( (int) $code, [ 401, 403, 422 ], true ) ? 'attention_required' : 'degraded';
				$this->record_handshake_status( false, $message, [], $failure_state );
			}

			return new WP_Error( 'yoro_ai_api_handshake_error', $message );
		}

		if ( ! is_array( $json ) ) {
			$message = __( 'YoRo AI API returned an invalid handshake response.', 'yoro-ai-core' );

			$this->record_request( 'handshake', $duration_ms, 'error' );

			if ( $record_status ) {
				$this->record_handshake_status( false, $message, [], 'degraded' );
			}

			return new WP_Error( 'yoro_ai_api_invalid_handshake', $message );
		}

		$status = $this->normalize_handshake_response( $json );

		if ( class_exists( 'YoRo_WordPress_Actor' ) ) {
			YoRo_WordPress_Actor::record_access( $status['actor_access'] ?? [] );
		}

		$this->record_request( 'handshake', $duration_ms, 'success' );

		if ( $record_status ) {
			$this->record_handshake_status( YoRo_AI_Settings::is_ai_api_account_connected( $status ), '', $status );
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

	public function claim_action( array $payload ) {
		return $this->request_action_claim(
			$this->build_action_claim_payload( $payload ),
			'action_claim',
			'yoro_ai_api_action_claim_error',
			__( 'I could not claim the approved YoRo action.', 'yoro-ai-core' )
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

	public function disconnect_site( array $payload = [] ) {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error( 'yoro_ai_api_missing_config', __( 'YoRo AI API is not connected yet.', 'yoro-ai-core' ) );
		}

		$response = wp_remote_post(
			$this->build_site_disconnect_endpoint_url( $api_url ),
			[
				'timeout' => 15,
				'headers' => $this->authenticated_headers( $site_key ),
				'body'    => wp_json_encode(
					[
						'installation_uuid' => sanitize_text_field( $payload['installation_uuid'] ?? YoRo_AI_Settings::get_installation_uuid() ),
						'reason'            => sanitize_text_field( $payload['reason'] ?? 'Disconnected from WordPress.' ),
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->decode_public_json_response(
			$response,
			'yoro_ai_api_disconnect_error',
			__( 'YoRo could not revoke this WordPress connection.', 'yoro-ai-core' )
		);
	}

	public function rotate_site_credential() {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error( 'yoro_ai_api_missing_config', __( 'YoRo AI API is not connected yet.', 'yoro-ai-core' ) );
		}

		$response = wp_remote_post(
			$this->build_site_credential_rotation_endpoint_url( $api_url ),
			[
				'timeout' => 15,
				'headers' => $this->authenticated_headers( $site_key ),
				'body'    => wp_json_encode(
					[
						'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(),
						'plugin_version'    => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->decode_public_json_response(
			$response,
			'yoro_ai_api_rotation_error',
			__( 'YoRo could not rotate the WordPress credential.', 'yoro-ai-core' )
		);
	}

	public function create_gmail_mailbox_connect_intent( array $payload ) {
		return $this->request_mailbox_api(
			'POST',
			'/email/gmail/connect-intents',
			[
				'return_url'               => esc_url_raw( $payload['return_url'] ?? '' ),
				'plugin_version'           => sanitize_text_field( $payload['plugin_version'] ?? ( defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '' ) ),
				'suggested_mailbox_email' => sanitize_email( $payload['suggested_mailbox_email'] ?? '' ),
			],
			'gmail_mailbox_connect_intent',
			'yoro_ai_api_gmail_connect_error',
			__( 'I could not start the Gmail inbox connection.', 'yoro-ai-core' ),
			false
		);
	}

	public function create_microsoft_mailbox_connect_intent( array $payload ) {
		return $this->request_mailbox_api(
			'POST',
			'/email/microsoft/connect-intents',
			[
				'return_url'               => esc_url_raw( $payload['return_url'] ?? '' ),
				'plugin_version'           => sanitize_text_field( $payload['plugin_version'] ?? ( defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '' ) ),
				'suggested_mailbox_email' => sanitize_email( $payload['suggested_mailbox_email'] ?? '' ),
			],
			'microsoft_mailbox_connect_intent',
			'yoro_ai_api_microsoft_connect_error',
			__( 'I could not start the Microsoft inbox connection.', 'yoro-ai-core' ),
			false
		);
	}

	public function connect_imap_mailbox( array $payload ) {
		return $this->request_mailbox_api(
			'POST',
			'/email/imap/connect',
			[
				'mailbox_email'             => sanitize_email( $payload['mailbox_email'] ?? '' ),
				'display_name'              => sanitize_text_field( $payload['display_name'] ?? '' ),
				'imap_host'                 => sanitize_text_field( $payload['imap_host'] ?? '' ),
				'imap_port'                 => absint( $payload['imap_port'] ?? 993 ),
				'imap_encryption'           => sanitize_key( $payload['imap_encryption'] ?? 'ssl' ),
				'imap_username'             => sanitize_text_field( $payload['imap_username'] ?? '' ),
				'imap_password'             => (string) ( $payload['imap_password'] ?? '' ),
				'smtp_host'                 => sanitize_text_field( $payload['smtp_host'] ?? '' ),
				'smtp_port'                 => absint( $payload['smtp_port'] ?? 587 ),
				'smtp_encryption'           => sanitize_key( $payload['smtp_encryption'] ?? 'tls' ),
				'smtp_username'             => sanitize_text_field( $payload['smtp_username'] ?? '' ),
				'smtp_password'             => (string) ( $payload['smtp_password'] ?? '' ),
				'plugin_version'            => sanitize_text_field( $payload['plugin_version'] ?? ( defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '' ) ),
				'suggested_mailbox_email'   => sanitize_email( $payload['suggested_mailbox_email'] ?? '' ),
			],
			'imap_mailbox_connect',
			'yoro_ai_api_imap_connect_error',
			__( 'I could not connect the IMAP inbox.', 'yoro-ai-core' ),
			false
		);
	}

	public function disconnect_gmail_mailbox() {
		return $this->request_mailbox_api(
			'POST',
			'/email/gmail/disconnect',
			[],
			'gmail_mailbox_disconnect',
			'yoro_ai_api_gmail_disconnect_error',
			__( 'I could not disconnect the Gmail inbox.', 'yoro-ai-core' ),
			false
		);
	}

	public function disconnect_mailbox() {
		return $this->request_mailbox_api(
			'POST',
			'/email/disconnect',
			[],
			'mailbox_disconnect',
			'yoro_ai_api_mailbox_disconnect_error',
			__( 'I could not disconnect the shop inbox.', 'yoro-ai-core' ),
			false
		);
	}

	public function get_mailbox_inbox( array $filters = [] ) {
		return $this->request_mailbox_api(
			'GET',
			'/email/inbox',
			[
				'limit'  => max( 1, min( 50, absint( $filters['limit'] ?? 25 ) ) ),
				'status' => sanitize_key( $filters['status'] ?? 'open' ),
				'unread' => ! empty( $filters['unread'] ) ? '1' : '0',
			],
			'mailbox_inbox',
			'yoro_ai_api_mailbox_inbox_error',
			__( 'I could not load the shop inbox.', 'yoro-ai-core' )
		);
	}

	public function sync_mailbox_inbox( array $options = [] ) {
		return $this->request_mailbox_api(
			'POST',
			'/email/inbox/sync',
			[
				'limit' => max( 1, min( 50, absint( $options['limit'] ?? 10 ) ) ),
			],
			'mailbox_inbox_sync',
			'yoro_ai_api_mailbox_sync_error',
			__( 'I could not sync the shop inbox.', 'yoro-ai-core' )
		);
	}

	public function get_mailbox_thread( $thread_id, array $payload = [] ) {
		$request_payload = [];

		if ( array_key_exists( 'allow_ai', $payload ) ) {
			$request_payload['allow_ai'] = ! empty( $payload['allow_ai'] ) ? '1' : '0';
		}

		if ( isset( $payload['store_context'] ) && is_array( $payload['store_context'] ) ) {
			$request_payload['store_context'] = $payload['store_context'];
		}

		return $this->request_mailbox_api(
			isset( $request_payload['store_context'] ) ? 'POST' : 'GET',
			'/email/threads/' . rawurlencode( (string) absint( $thread_id ) ),
			$request_payload,
			'mailbox_thread',
			'yoro_ai_api_mailbox_thread_error',
			__( 'I could not load that email thread.', 'yoro-ai-core' )
		);
	}

	public function update_mailbox_thread( $thread_id, array $payload ) {
		$request_payload = [];

		if ( array_key_exists( 'status', $payload ) ) {
			$request_payload['status'] = sanitize_key( (string) $payload['status'] );
		}

		if ( array_key_exists( 'unread', $payload ) ) {
			$request_payload['unread'] = ! empty( $payload['unread'] );
		}

		if ( array_key_exists( 'assignee', $payload ) ) {
			$request_payload['assignee'] = $this->sanitize_person_payload( $payload['assignee'] );
		}

		if ( array_key_exists( 'follow_up_reminder', $payload ) ) {
			$request_payload['follow_up_reminder'] = $this->sanitize_mailbox_follow_up_reminder( $payload['follow_up_reminder'] );
		}

		$request_payload['actor'] = $this->sanitize_person_payload( $payload['actor'] ?? [] );

		return $this->request_mailbox_api(
			'PATCH',
			'/email/threads/' . rawurlencode( (string) absint( $thread_id ) ),
			$request_payload,
			'mailbox_thread_update',
			'yoro_ai_api_mailbox_thread_update_error',
			__( 'I could not update that email thread.', 'yoro-ai-core' )
		);
	}

	public function command_mailbox_thread( $thread_id, $command, array $payload = [] ) {
		$command = sanitize_key( (string) $command );

		return $this->request_mailbox_api(
			'POST',
			'/email/threads/' . rawurlencode( (string) absint( $thread_id ) ) . '/' . rawurlencode( $command ),
			[
				'actor' => $this->sanitize_person_payload( $payload['actor'] ?? [] ),
			],
			'mailbox_thread_' . $command,
			'yoro_ai_api_mailbox_thread_command_error',
			__( 'I could not update that email thread.', 'yoro-ai-core' )
		);
	}

	public function add_mailbox_thread_note( $thread_id, array $payload ) {
		return $this->request_mailbox_api(
			'POST',
			'/email/threads/' . rawurlencode( (string) absint( $thread_id ) ) . '/notes',
			[
				'body'  => sanitize_textarea_field( (string) ( $payload['body'] ?? '' ) ),
				'actor' => $this->sanitize_person_payload( $payload['actor'] ?? [] ),
			],
			'mailbox_thread_note',
			'yoro_ai_api_mailbox_thread_note_error',
			__( 'I could not add the internal note.', 'yoro-ai-core' )
		);
	}

	public function retry_mailbox_thread_reply( $thread_id, $message_id, array $payload = [] ) {
		return $this->request_mailbox_api(
			'POST',
			'/email/threads/' . rawurlencode( (string) absint( $thread_id ) ) . '/messages/' . rawurlencode( (string) absint( $message_id ) ) . '/retry',
			[
				'actor' => $this->sanitize_person_payload( $payload['actor'] ?? [] ),
			],
			'mailbox_thread_reply_retry',
			'yoro_ai_api_mailbox_reply_retry_error',
			__( 'I could not retry the email reply.', 'yoro-ai-core' )
		);
	}

	public function reply_mailbox_thread( $thread_id, array $payload ) {
		$request_payload = [
			'body' => sanitize_textarea_field( $payload['body'] ?? '' ),
			'cc'   => isset( $payload['cc'] ) && is_array( $payload['cc'] )
				? $this->sanitize_address_list( $payload['cc'] )
				: [],
		];

		foreach ( [ 'subject', 'html_body', 'body_html', 'html_body_source', 'body_html_source' ] as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}

			$value = in_array( $key, [ 'html_body', 'body_html' ], true )
				? (string) $payload[ $key ]
				: sanitize_text_field( (string) $payload[ $key ] );

			if ( '' !== trim( $value ) ) {
				$request_payload[ $key ] = $value;
			}
		}

		return $this->request_mailbox_api(
			'POST',
			'/email/threads/' . rawurlencode( (string) absint( $thread_id ) ) . '/reply',
			$request_payload,
			'mailbox_thread_reply',
			'yoro_ai_api_mailbox_reply_error',
			__( 'I could not send the email reply.', 'yoro-ai-core' )
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
				'headers' => $this->authenticated_headers( $site_key ),
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
				'headers' => $this->authenticated_headers( $site_key ),
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
				'headers' => $this->authenticated_headers( $site_key ),
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
				'headers' => $this->authenticated_headers( $site_key ),
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

	private function request_action_claim( array $payload, $operation, $error_code, $default_message ) {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error( 'yoro_ai_api_missing_config', __( 'YoRo AI API is not connected yet.', 'yoro-ai-core' ) );
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			$this->build_action_claim_endpoint_url( $api_url ),
			[
				'timeout' => 15,
				'headers' => $this->authenticated_headers( $site_key ),
				'body'    => wp_json_encode( $payload ),
			]
		);
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$this->record_request( $operation, $duration_ms, 'error' );
			return new WP_Error( $error_code, $this->public_response_message( $json, $default_message ) );
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
				'headers' => $this->authenticated_headers( $site_key ),
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

	private function request_mailbox_api( $method, $path, array $payload, $operation, $error_code, $default_message, $requires_feature = true ) {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error(
				'yoro_ai_api_missing_config',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		$entitlements = YoRo_AI_Settings::get_ai_api_entitlements();

		if (
			$requires_feature &&
			array_key_exists( 'email_inbox', $entitlements ) &&
			empty( $entitlements['email_inbox'] )
		) {
			return new WP_Error(
				'yoro_ai_api_feature_not_in_plan',
				__( 'Shop inbox is not available on the current YoRo plan.', 'yoro-ai-core' )
			);
		}

		$method = strtoupper( sanitize_key( $method ) );
		$url    = $this->build_mailbox_endpoint_url( $api_url, $path );

		if ( 'GET' === $method && [] !== $payload ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $payload, '', '&', PHP_QUERY_RFC3986 );
		}

		$args = [
			'timeout' => $this->api_timeout( $operation, $payload ),
			'headers' => $this->authenticated_headers( $site_key ),
		];

		if ( 'GET' !== $method ) {
			$args['method'] = $method;
			$args['body'] = wp_json_encode( $payload );
		}

		$started_at = microtime( true );
		$response   = 'GET' === $method
			? wp_remote_get( $url, $args )
			: wp_remote_request( $url, $args );
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$this->record_request( $operation, $duration_ms, 'error' );

			return $response;
		}

		$this->record_request(
			$operation,
			$duration_ms,
			( wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300 ) ? 'success' : 'error'
		);

		return $this->decode_public_json_response( $response, $error_code, $default_message );
	}

	private function sanitize_address_list( array $items ) {
		$addresses = [];

		foreach ( array_values( $items ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$email = sanitize_email( $item['email'] ?? '' );

			if ( '' === $email ) {
				continue;
			}

			$addresses[] = [
				'email' => $email,
				'name'  => sanitize_text_field( $item['name'] ?? '' ),
			];
		}

		return $addresses;
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
		$built = [
			'conversation_id'         => sanitize_text_field( $payload['conversation_id'] ?? '' ),
			'pending_operation_token' => sanitize_text_field( $payload['pending_operation_token'] ?? '' ),
			'params'                  => isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : [],
		];

		foreach ( [ 'plan_hash', 'preview_hash' ] as $key ) {
			if ( isset( $payload[ $key ] ) ) {
				$built[ $key ] = sanitize_text_field( $payload[ $key ] );
			}
		}

		foreach ( [ 'resolved_impact', 'approved_groups' ] as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				$built[ $key ] = $payload[ $key ];
			}
		}

		return $built;
	}

	private function build_action_claim_payload( array $payload ) {
		return [
			'conversation_id'         => sanitize_text_field( $payload['conversation_id'] ?? '' ),
			'pending_operation_token' => sanitize_text_field( $payload['pending_operation_token'] ?? '' ),
			'execution_permit'        => sanitize_text_field( $payload['execution_permit'] ?? '' ),
		];
	}

	private function build_action_execution_payload( array $payload ) {
		return [
			'conversation_id'         => sanitize_text_field( $payload['conversation_id'] ?? '' ),
			'pending_operation_token' => sanitize_text_field( $payload['pending_operation_token'] ?? '' ),
			'execution_permit'        => sanitize_text_field( $payload['execution_permit'] ?? '' ),
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

		if ( preg_match( '#/actions/(execute|confirm|claim)$#', $api_url ) ) {
			return preg_replace( '#/actions/(execute|confirm|claim)$#', '/conversations/' . $conversation_id, $api_url );
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

	private function build_action_claim_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/actions/claim$#', $api_url ) ) {
			return $api_url;
		}

		if ( preg_match( '#/actions/(execute|confirm)$#', $api_url ) ) {
			return preg_replace( '#/actions/(execute|confirm)$#', '/actions/claim', $api_url );
		}

		if ( preg_match( '#/(conversations/message|sites/handshake)$#', $api_url ) ) {
			return preg_replace( '#/(conversations/message|sites/handshake)$#', '/actions/claim', $api_url );
		}

		return $api_url . '/actions/claim';
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

	private function build_mailbox_endpoint_url( $api_url, $path ) {
		$api_url = $this->build_api_base_url( $api_url );
		$path    = '/' . ltrim( (string) $path, '/' );

		return $api_url . $path;
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

	private function build_site_disconnect_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/sites/disconnect$#', $api_url ) ) {
			return $api_url;
		}

		return $api_url . '/sites/disconnect';
	}

	private function build_site_credential_rotation_endpoint_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		if ( preg_match( '#/sites/credentials/rotate$#', $api_url ) ) {
			return $api_url;
		}

		return $api_url . '/sites/credentials/rotate';
	}

	private function build_api_base_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		return preg_replace(
			'#/(?:sites/(?:handshake|disconnect|credentials/rotate)|conversations(?:/message|/[^/]+)?|actions/(?:execute|confirm|claim)|ai/email/compose|connect/wordpress/(?:intents|exchange)|email/(?:(?:gmail|microsoft)/connect-intents|gmail/disconnect|imap/connect|disconnect|inbox(?:/sync)?|threads/[^/]+(?:/(?:reply|archive|reopen|read|unread|notes|messages/[^/]+/retry))?))$#',
			'',
			$api_url
		);
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

	private function sanitize_person_payload( $person ) {
		if ( ! is_array( $person ) ) {
			return null;
		}

		$payload = [
			'id'    => sanitize_text_field( (string) ( $person['id'] ?? '' ) ),
			'name'  => sanitize_text_field( (string) ( $person['name'] ?? '' ) ),
			'email' => sanitize_email( (string) ( $person['email'] ?? '' ) ),
			'role'  => sanitize_key( (string) ( $person['role'] ?? '' ) ),
		];

		return '' !== implode( '', $payload ) ? $payload : null;
	}

	private function sanitize_mailbox_follow_up_reminder( $reminder ) {
		if ( ! is_array( $reminder ) ) {
			return null;
		}

		$payload = [];

		if ( array_key_exists( 'status', $reminder ) ) {
			$payload['status'] = sanitize_key( (string) $reminder['status'] );
		}

		if ( array_key_exists( 'after_hours', $reminder ) ) {
			$payload['after_hours'] = absint( $reminder['after_hours'] );
		}

		if ( array_key_exists( 'due_at', $reminder ) ) {
			$payload['due_at'] = sanitize_text_field( (string) $reminder['due_at'] );
		}

		if ( array_key_exists( 'reason', $reminder ) ) {
			$payload['reason'] = sanitize_text_field( (string) $reminder['reason'] );
		}

		return $payload;
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
		$safety = isset( $response['safety'] ) && is_array( $response['safety'] ) ? $response['safety'] : [];

		if ( ! in_array( $mode, [ 'task_request', 'clarify', 'chat_only', 'unsupported_request', 'out_of_scope' ], true ) ) {
			$mode = 'clarify';
		}

		return [
			'conversation_id'       => sanitize_text_field( $response['conversation_id'] ?? ( $response_meta['conversation_id'] ?? '' ) ),
			'stage'                 => sanitize_key( $response['stage'] ?? ( $response_meta['conversation_stage'] ?? '' ) ),
			'pending_operation_token' => sanitize_text_field( $response['pending_operation_token'] ?? ( $response_meta['pending_operation_token'] ?? '' ) ),
			'execution_required'      => ! empty( $response['execution_required'] ),
			'requires_confirmation'   => ! empty( $response['requires_confirmation'] ),
			'confirm_required'        => ! empty( $response['confirm_required'] ),
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
			'email_preview'          => isset( $response['email_preview'] ) && is_array( $response['email_preview'] ) ? $response['email_preview'] : [],
			'assistant'              => $assistant_response,
			'safety'                 => [
				'origin' => sanitize_key( $safety['origin'] ?? '' ),
				'profile' => sanitize_key( $safety['profile'] ?? '' ),
				'policy_version' => absint( $safety['policy_version'] ?? 0 ),
				'decision' => isset( $safety['decision'] ) && is_array( $safety['decision'] ) ? $safety['decision'] : [],
				'resolved_impact' => isset( $safety['resolved_impact'] ) && is_array( $safety['resolved_impact'] ) ? $safety['resolved_impact'] : [],
				'approval_groups' => isset( $safety['approval_groups'] ) && is_array( $safety['approval_groups'] ) ? $safety['approval_groups'] : [],
				'plan_hash' => sanitize_text_field( $safety['plan_hash'] ?? '' ),
				'preview_hash' => sanitize_text_field( $safety['preview_hash'] ?? '' ),
				'blocked' => ! empty( $safety['blocked'] ),
			],
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
			'email_preview' => isset( $plan['email_preview'] ) && is_array( $plan['email_preview'] ) ? $plan['email_preview'] : [],
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
		$organization = isset( $response['organization'] ) && is_array( $response['organization'] ) ? $response['organization'] : [];
		$connection   = isset( $response['connection'] ) && is_array( $response['connection'] ) ? $response['connection'] : [];
		$credential   = isset( $response['credential'] ) && is_array( $response['credential'] ) ? $response['credential'] : [];
		$entitlements = isset( $response['entitlements'] ) && is_array( $response['entitlements'] ) ? $response['entitlements'] : [];
		$usage        = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : [];
		$account_connection = isset( $response['account_connection'] ) && is_array( $response['account_connection'] ) ? $response['account_connection'] : [];
		$actor_access = isset( $response['actor_access'] ) && is_array( $response['actor_access'] ) ? $response['actor_access'] : [];
		$safety = isset( $response['safety'] ) && is_array( $response['safety'] ) ? $response['safety'] : [];
		$connector_runtime = isset( $response['connector_runtime'] ) && is_array( $response['connector_runtime'] ) ? $response['connector_runtime'] : [];
		$connection_id = absint( $connection['id'] ?? 0 );
		$installation_uuid = sanitize_text_field( $connection['installation_uuid'] ?? '' );
		$credential_expires_at = sanitize_text_field( $credential['expires_at'] ?? '' );
		$credential_expires = '' !== $credential_expires_at ? strtotime( $credential_expires_at ) : false;
		$credential_active = false === $credential_expires || $credential_expires > time();
		$account_connected = ! empty( $account_connection['connected'] )
			&& 'active' === sanitize_key( $account_connection['status'] ?? '' )
			&& absint( $organization['id'] ?? 0 ) > 0
			&& absint( $site['id'] ?? 0 ) > 0
			&& $connection_id > 0
			&& 'active' === sanitize_key( $connection['status'] ?? '' )
			&& '' !== $installation_uuid
			&& hash_equals( YoRo_AI_Settings::get_installation_uuid(), $installation_uuid )
			&& absint( $credential['id'] ?? 0 ) > 0
			&& $credential_active
			&& 'wordpress_connection' === sanitize_key( $credential['type'] ?? '' )
			&& $connection_id === absint( $credential['site_connection_id'] ?? 0 );

		return [
			'connected'    => $account_connected,
			'service_available' => true,
			'checked_at'   => current_time( 'mysql' ),
			'account_url'  => esc_url_raw( $response['account_url'] ?? '' ),
			'billing_url'  => esc_url_raw( $response['billing_url'] ?? '' ),
			'site'         => [
				'id'     => absint( $site['id'] ?? 0 ),
				'name'   => sanitize_text_field( $site['name'] ?? '' ),
				'url'    => esc_url_raw( $site['url'] ?? '' ),
				'status' => sanitize_key( $site['status'] ?? '' ),
				'plan'   => sanitize_key( $site['plan'] ?? '' ),
			],
			'organization' => [
				'id'   => absint( $organization['id'] ?? 0 ),
				'name' => sanitize_text_field( $organization['name'] ?? '' ),
			],
			'account_connection' => [
				'required'  => true,
				'connected' => $account_connected,
				'status'    => $account_connected ? 'active' : sanitize_key( $account_connection['status'] ?? 'account_required' ),
				'message'   => sanitize_text_field( $account_connection['message'] ?? '' ),
			],
			'actor_access' => [
				'required'    => true,
				'assigned'    => ! empty( $actor_access['assigned'] ),
				'status'      => sanitize_key( $actor_access['status'] ?? 'missing' ),
				'message'     => sanitize_text_field( $actor_access['message'] ?? '' ),
				'seat_id'     => sanitize_text_field( $actor_access['seat_id'] ?? '' ),
				'identity_id' => sanitize_text_field( $actor_access['identity_id'] ?? '' ),
				'role'        => sanitize_key( $actor_access['role'] ?? '' ),
				'permissions' => array_values( array_filter( array_map( 'sanitize_key', is_array( $actor_access['permissions'] ?? null ) ? $actor_access['permissions'] : [] ) ) ),
			],
			'connection'   => [
				'id'                => absint( $connection['id'] ?? 0 ),
				'installation_uuid' => sanitize_text_field( $connection['installation_uuid'] ?? '' ),
				'status'            => sanitize_key( $connection['status'] ?? '' ),
				'plugin_version'    => sanitize_text_field( $connection['plugin_version'] ?? '' ),
				'last_seen_at'      => sanitize_text_field( $connection['last_seen_at'] ?? '' ),
			],
			'credential'   => [
				'id'                 => absint( $credential['id'] ?? 0 ),
				'type'               => sanitize_key( $credential['type'] ?? '' ),
				'site_connection_id' => absint( $credential['site_connection_id'] ?? 0 ),
				'abilities'          => array_values( array_filter( array_map( 'sanitize_text_field', is_array( $credential['abilities'] ?? null ) ? $credential['abilities'] : [] ) ) ),
				'expires_at'         => sanitize_text_field( $credential['expires_at'] ?? '' ),
			],
			'client'       => [
				'site_url'       => esc_url_raw( $client['site_url'] ?? '' ),
				'plugin_version' => sanitize_text_field( $client['plugin_version'] ?? '' ),
			],
			'entitlements' => [
				'conversation'        => ! empty( $entitlements['conversation'] ),
				'email_composer'      => ! empty( $entitlements['email_composer'] ),
				'email_inbox'         => ! empty( $entitlements['email_inbox'] ),
				'write_actions'       => ! empty( $entitlements['write_actions'] ),
				'usage_plan'          => sanitize_key( $entitlements['usage_plan'] ?? '' ),
				'plan_label'          => sanitize_text_field( $entitlements['plan_label'] ?? '' ),
				'subscription_status' => sanitize_key( $entitlements['subscription_status'] ?? '' ),
				'access_state'        => sanitize_key( $entitlements['access_state'] ?? '' ),
				'blocked_reason'      => sanitize_key( $entitlements['blocked_reason'] ?? '' ),
			],
			'usage'        => [
				'period'             => sanitize_text_field( $usage['period'] ?? '' ),
				'limit'              => isset( $usage['credit_limit'] ) ? (float) $usage['credit_limit'] : ( isset( $usage['limit'] ) ? (float) $usage['limit'] : null ),
				'used'               => isset( $usage['credits_used'] ) ? (float) $usage['credits_used'] : ( isset( $usage['used'] ) ? (float) $usage['used'] : null ),
				'remaining'          => isset( $usage['credits_remaining'] ) ? (float) $usage['credits_remaining'] : ( isset( $usage['remaining'] ) ? (float) $usage['remaining'] : null ),
				'usage_percent'      => isset( $usage['usage_percent'] ) ? (float) $usage['usage_percent'] : null,
				'hard_limit_reached' => ! empty( $usage['hard_limit_reached'] ),
			],
			'safety'       => [
				'server_provided' => in_array( sanitize_key( $safety['profile'] ?? '' ), [ 'advisory', 'assisted', 'delegated' ], true ),
				'profile' => in_array( sanitize_key( $safety['profile'] ?? '' ), [ 'advisory', 'assisted', 'delegated' ], true )
					? sanitize_key( $safety['profile'] )
					: 'assisted',
				'version'        => max( 1, absint( $safety['version'] ?? 1 ) ),
				'rules'          => isset( $safety['rules'] ) && is_array( $safety['rules'] ) ? $safety['rules'] : [],
				'management_url' => esc_url_raw( $safety['management_url'] ?? '' ),
			],
			'connector_runtime' => $this->normalize_connector_runtime( $connector_runtime ),
		];
	}

	private function normalize_connector_runtime( array $runtime ) {
		$cutover = isset( $runtime['cutover'] ) && is_array( $runtime['cutover'] ) ? $runtime['cutover'] : [];
		$checks = isset( $cutover['checks'] ) && is_array( $cutover['checks'] ) ? $cutover['checks'] : [];

		return [
			'connector_key'           => sanitize_key( $runtime['connector_key'] ?? '' ),
			'connector_connection_id' => sanitize_text_field( $runtime['connector_connection_id'] ?? '' ),
			'execution_mode'          => sanitize_key( $runtime['execution_mode'] ?? '' ),
			'protocol_version'        => absint( $runtime['protocol_version'] ?? 0 ),
			'connector_version'       => sanitize_text_field( $runtime['connector_version'] ?? '' ),
			'delivery_protocol'       => sanitize_key( $runtime['delivery_protocol'] ?? '' ),
			'cutover'                 => [
				'cutover_enabled'         => ! empty( $cutover['cutover_enabled'] ),
				'connection_live_enabled' => ! empty( $cutover['connection_live_enabled'] ),
				'conformance_ready'       => ! empty( $cutover['conformance_ready'] ),
				'cutover_allowed'         => ! empty( $cutover['cutover_allowed'] ),
				'checks'                  => [
					'active_connection'          => ! empty( $checks['active_connection'] ),
					'remote_agent_mode'          => ! empty( $checks['remote_agent_mode'] ),
					'compatible_driver'          => ! empty( $checks['compatible_driver'] ),
					'operation_execution_feature' => ! empty( $checks['operation_execution_feature'] ),
					'current_capability_manifest' => ! empty( $checks['current_capability_manifest'] ),
					'current_context_pack'        => ! empty( $checks['current_context_pack'] ),
					'runtime_credential'          => ! empty( $checks['runtime_credential'] ),
					'conformance_attestation'     => ! empty( $checks['conformance_attestation'] ),
				],
			],
		];
	}

	private function should_rotate_credential( array $status ) {
		$expires_at = sanitize_text_field( $status['credential']['expires_at'] ?? '' );
		$expires    = '' !== $expires_at ? strtotime( $expires_at ) : false;

		if ( false === $expires || $expires <= time() ) {
			return false;
		}

		$threshold_days = max( 1, absint( apply_filters( 'yoro_ai_credential_rotation_threshold_days', 14 ) ) );

		return $expires <= time() + ( $threshold_days * DAY_IN_SECONDS );
	}

	private function record_handshake_status( $connected, $message = '', array $status = [], $failure_state = '' ) {
		$existing = YoRo_AI_Settings::get_ai_api_status();
		$next = array_merge( $existing, $status );
		unset( $next['actor_access'] );

		if ( 'degraded' === $failure_state ) {
			$next['service_available'] = false;
			$next['service_status'] = 'degraded';
			$next['checked_at'] = current_time( 'mysql' );
			$next['message'] = sanitize_text_field( $message );
			YoRo_AI_Settings::update_ai_api_status( $next );

			return;
		}

		$next['connected'] = (bool) $connected;
		$next['service_available'] = '' === $failure_state;
		$next['service_status'] = '' === $failure_state ? 'available' : $failure_state;
		$next['checked_at'] = current_time( 'mysql' );
		$next['message'] = sanitize_text_field( $message );

		if ( '' !== $failure_state ) {
			$next['account_connection'] = [
				'required'  => true,
				'connected' => false,
				'status'    => $failure_state,
				'message'   => sanitize_text_field( $message ),
			];
		}

		YoRo_AI_Settings::update_ai_api_status( $next );
	}

	private function record_api_usage( $response ) {
		if ( ! is_array( $response ) || empty( $response['usage'] ) || ! is_array( $response['usage'] ) ) {
			return;
		}

		$usage = $response['usage'];
		$status = YoRo_AI_Settings::get_ai_api_status();
		$status['checked_at'] = current_time( 'mysql' );
		$status['message'] = '';
		$status['service_available'] = true;
		$status['service_status'] = 'available';
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
