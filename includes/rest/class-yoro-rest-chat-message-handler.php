<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Chat_Message_Handler {

	public static function handle( WP_REST_Request $request ) {
		$rate_limit = YoRo_Rate_Limiter::check( 'chat' );

		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$message = sanitize_textarea_field(
			$request->get_param( 'message' )
				?: $request->get_param( 'command' )
		);

		if ( '' === $message ) {
			return new WP_Error(
				'yoro_empty_message',
				__( 'Please enter a request first.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Provider_Resolver::supports( 'conversation' ) ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		$client_context = $request->get_param( 'client_context' );

		if ( ! is_array( $client_context ) ) {
			$client_context = [];
		}

		$conversation_id = sanitize_text_field(
			$request->get_param( 'conversation_id' )
				?: ( $client_context['conversation_id'] ?? '' )
		);
		$client_context['conversation_id'] = $conversation_id;
		$context = self::compact_context( $client_context );
		$context['safety_contract_version'] = YoRo_Safety_Contract::VERSION;
		$context['safety_profile'] = YoRo_Safety_Contract::profile();
		$context['safety_policy_version'] = YoRo_Safety_Contract::policy_version();
		$catalog      = YoRo_Action_Catalog_Builder::build( $context );
		$router_hints = YoRo_Action_Catalog_Builder::build_router_hints( $message, $context, $catalog );

		$client = YoRo_AI_Provider_Resolver::get_client();

		$output_text = $client->send_conversation_message(
			[
				'feature'        => 'conversation',
				'command'        => $message,
				'message'        => $message,
				'conversation_id' => $conversation_id,
				'site_url'       => home_url(),
				'plugin_version' => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
				'context'        => $context,
				'user_context'   => self::user_context( $context ),
				'router_hints'   => is_array( $router_hints ) ? $router_hints : [],
				'action_catalog' => is_array( $catalog ) ? array_values( $catalog ) : [],
			]
		);

		if ( is_wp_error( $output_text ) ) {
			return $output_text;
		}

		$response = json_decode( (string) $output_text, true );

		if ( ! is_array( $response ) ) {
			return new WP_Error(
				'yoro_ai_invalid_conversation_response',
				__( 'YoRo AI conversation service returned an invalid response.', 'yoro-ai-core' )
			);
		}

		return rest_ensure_response( self::format_response( $response, $message ) );
	}

	public static function get_conversation( WP_REST_Request $request ) {
		$conversation_id = sanitize_text_field( (string) $request->get_param( 'conversation_id' ) );

		if ( '' === $conversation_id ) {
			return new WP_Error(
				'yoro_missing_conversation_id',
				__( 'I need a conversation id before I can load that conversation.', 'yoro-ai-core' )
			);
		}

		if ( ! YoRo_AI_Provider_Resolver::supports( 'conversation' ) ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		$client = YoRo_AI_Provider_Resolver::get_client();

		$response = $client->get_conversation( $conversation_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return rest_ensure_response( self::format_conversation_show_response( $response, $conversation_id ) );
	}

	private static function format_response( $response, $message ) {
		$response = is_array( $response ) ? $response : [];
		$meta = isset( $response['meta'] ) && is_array( $response['meta'] ) ? $response['meta'] : [];
		$mode = sanitize_key( $response['mode'] ?? 'clarify' );
		$stage = sanitize_key( $response['stage'] ?? ( $meta['conversation_stage'] ?? '' ) );
		$action_plan = isset( $response['action_plan'] ) && is_array( $response['action_plan'] ) ? $response['action_plan'] : [];
		$steps = isset( $action_plan['steps'] ) && is_array( $action_plan['steps'] ) ? $action_plan['steps'] : [];
		$assistant = isset( $response['assistant'] ) && is_array( $response['assistant'] ) ? $response['assistant'] : [];
		$email_preview = self::compact_email_preview(
			$response['email_preview'] ?? ( $action_plan['email_preview'] ?? [] )
		);

		if ( [] !== $email_preview ) {
			$response['email_preview'] = $email_preview;
		}

		if ( '' === $stage ) {
			if ( 'clarify' === $mode ) {
				$stage = 'clarify';
			} elseif ( ! empty( $steps ) ) {
				$stage = 'action_plan';
			} else {
				$stage = 'done';
			}
		}

		$is_mailbox_reply_preview = [] !== $email_preview && 'email.reply.send' === sanitize_text_field( $email_preview['action'] ?? '' );
		$confirm_required = 'confirm' === $stage
			|| ! empty( $response['confirm_required'] )
			|| ! empty( $response['requires_confirmation'] )
			|| ! empty( $email_preview['requires_confirmation'] )
			|| $is_mailbox_reply_preview;
		$summary = sanitize_textarea_field(
			$assistant['message']
				?? ( $assistant['question'] ?? ( $response['summary'] ?? '' ) )
		);
		$plan = self::build_plan( $response, $action_plan );
		$pending_operation_token = sanitize_text_field( $response['pending_operation_token'] ?? ( $meta['pending_operation_token'] ?? '' ) );
		$conversation_id = sanitize_text_field( $response['conversation_id'] ?? ( $meta['conversation_id'] ?? '' ) );
		$response_action = sanitize_text_field( $response['action'] ?? ( $email_preview['action'] ?? '' ) );
		$parsed = [
			'success'         => true,
			'matched'         => true,
			'stage'           => $stage,
			'action'          => $response_action,
			'params'          => isset( $response['params'] ) && is_array( $response['params'] ) ? $response['params'] : [],
			'parser_source'   => 'conversation_orchestrator',
			'parser_rule'     => 'yoro_ai.conversation.message',
			'confidence'      => isset( $response['confidence'] ) ? (float) $response['confidence'] : 0,
			'original_command'=> $message,
			'language'        => sanitize_text_field( $response['language'] ?? 'unknown' ),
			'intent_family'   => sanitize_key( $response['intent_family'] ?? '' ),
			'summary'         => sanitize_text_field( $response['summary'] ?? '' ),
			'assistant'       => $assistant,
			'action_plan'     => $action_plan,
			'operation_graph' => isset( $response['operation_graph'] ) && is_array( $response['operation_graph'] ) ? $response['operation_graph'] : [],
			'meta'            => array_merge(
				$meta,
				[
					'conversation_id'         => $conversation_id,
					'pending_operation_token' => $pending_operation_token,
				]
			),
		];

		if ( [] !== $email_preview ) {
			$parsed['email_preview'] = $email_preview;
		}
		$plan = self::apply_response_language_to_plan( $plan, sanitize_key( $parsed['language'] ?? '' ) );
		$confirmation_blocked = false;
		$blocked_message      = '';

		$result = [
			'success'       => true,
			'action'        => $parsed['action'],
			'params'        => $parsed['params'],
			'summary'       => $summary,
			'message'       => $summary,
			'read_only'     => true,
			'result_type'   => 'assistant_message',
			'display_type'  => 'assistant_message',
			'safety'        => isset( $response['safety'] ) && is_array( $response['safety'] ) ? $response['safety'] : [],
		];

		if ( ! $confirm_required && 'clarify' === $stage && '' !== $parsed['action'] ) {
			$local_preview = self::local_action_preview( $parsed['action'], $parsed['params'] );

			if ( self::local_preview_blocks_clarification( $local_preview ) ) {
				$confirmation_blocked = true;
				$blocked_message = self::apply_local_blocked_preview(
					$response,
					$assistant,
					$parsed,
					$result,
					$stage,
					$summary,
					$local_preview,
					__( 'This request needs a valid store target before it can continue.', 'yoro-ai-core' )
				);
			}
		}

		if ( ! $confirm_required && ! $confirmation_blocked && 'action_plan' === $stage && '' !== $parsed['action'] ) {
			$local_preview = self::local_action_preview( $parsed['action'], $parsed['params'] );

			if ( is_array( $local_preview ) && ! empty( $local_preview['blocked'] ) ) {
				$confirmation_blocked = true;
				$blocked_message = self::apply_local_blocked_preview(
					$response,
					$assistant,
					$parsed,
					$result,
					$stage,
					$summary,
					$local_preview,
					__( 'This request needs a valid store target before it can run.', 'yoro-ai-core' )
				);
			}
		}

		if ( $confirm_required ) {
			$result['success'] = false;
			$result['requires_confirmation'] = true;
			$result['result_type'] = 'confirmation';
			$result['display_type'] = 'confirmation';

			$local_preview = [] !== $email_preview && 'email.reply.send' === $parsed['action']
				? self::mailbox_reply_email_preview_result( $email_preview, $pending_operation_token )
				: self::local_confirmation_preview( $parsed['action'], $parsed['params'] );

			if ( [] !== $local_preview ) {
				$result = array_merge( $result, $local_preview );
				$result['success'] = 'customer_email_preview' === ( $local_preview['display_type'] ?? '' );
				$result['action'] = $parsed['action'];
				$result['params'] = $parsed['params'];
				$result['result_type'] = $local_preview['result_type'] ?? 'confirmation';
				$result['display_type'] = $local_preview['display_type'] ?? 'confirmation';

				if ( ! empty( $local_preview['blocked'] ) ) {
					$confirmation_blocked = true;
					$blocked_message = self::apply_local_blocked_preview(
						$response,
						$assistant,
						$parsed,
						$result,
						$stage,
						$summary,
						$local_preview,
						__( 'This change needs a more specific target before it can be approved.', 'yoro-ai-core' )
					);
				} else {
					$result['requires_confirmation'] = true;
				}
			}
		}

		if ( $confirmation_blocked ) {
			$pending_operation_token = '';
			$response['pending_operation_token'] = '';

			if ( isset( $response['meta'] ) && is_array( $response['meta'] ) ) {
				$response['meta']['pending_operation_token'] = '';
				$response['meta']['pending_operation_active'] = false;
				$response['meta']['pending_operation_status'] = 'blocked';
			}

			$parsed['meta']['pending_operation_token'] = '';
			$parsed['meta']['pending_operation_active'] = false;
			$parsed['meta']['pending_operation_status'] = 'blocked';
		}

		$ui_confirm_required = $confirm_required && ! $confirmation_blocked;

		return array_merge(
			$response,
			[
				'success'                 => true,
				'stage'                   => $stage,
				'conversation_id'         => $conversation_id,
				'pending_operation_token' => $pending_operation_token,
				'execution_required'      => in_array( $stage, [ 'action_plan', 'confirm' ], true ) && ! $confirmation_blocked,
				'requires_confirmation'   => $ui_confirm_required,
				'confirm_required'        => $ui_confirm_required,
				'pending_operation_active' => ! $confirmation_blocked,
				'pending_operation_status' => $confirmation_blocked ? 'blocked' : sanitize_key( $response['pending_operation_status'] ?? ( $meta['pending_operation_status'] ?? '' ) ),
				'message'                 => $summary,
				'parsed'                  => $parsed,
				'plan'                    => $plan,
				'result'                  => $result,
			]
		);
	}

	private static function format_conversation_show_response( $response, $requested_conversation_id ) {
		$response = is_array( $response ) ? $response : [];
		$conversation = isset( $response['conversation'] ) && is_array( $response['conversation'] )
			? $response['conversation']
			: [];
		$conversation_id = sanitize_text_field( $conversation['id'] ?? $requested_conversation_id );
		$messages = isset( $conversation['messages'] ) && is_array( $conversation['messages'] )
			? array_slice( array_values( $conversation['messages'] ), -60 )
			: [];
		$pending_graphs = isset( $conversation['pending_operation_graphs'] ) && is_array( $conversation['pending_operation_graphs'] )
			? array_values( $conversation['pending_operation_graphs'] )
			: [];
		$pending_operation_token = self::active_pending_operation_token( $pending_graphs );
		$pending_operation_states = self::pending_operation_states( $pending_graphs );
		$formatted_messages = [];

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role = sanitize_key( $message['role'] ?? '' );

			if ( ! in_array( $role, [ 'user', 'assistant' ], true ) ) {
				continue;
			}

			$item = [
				'id'         => absint( $message['id'] ?? 0 ),
				'role'       => $role,
				'content'    => sanitize_textarea_field( $message['content'] ?? '' ),
				'created_at' => sanitize_text_field( $message['created_at'] ?? '' ),
			];

			if ( 'assistant' === $role ) {
				$payload = isset( $message['payload'] ) && is_array( $message['payload'] ) ? $message['payload'] : [];
				$decision = isset( $payload['decision'] ) && is_array( $payload['decision'] ) ? $payload['decision'] : [];

				if ( [] === $decision && ! empty( $payload['email_preview'] ) && is_array( $payload['email_preview'] ) ) {
					$decision = $payload;
				}

				if ( [] !== $decision ) {
					$pending_token = sanitize_text_field( $payload['pending_operation_token'] ?? '' );
					$stage = sanitize_key( $payload['stage'] ?? ( $decision['stage'] ?? '' ) );
					$pending_state = '' !== $pending_token && isset( $pending_operation_states[ $pending_token ] )
						? $pending_operation_states[ $pending_token ]
						: [];
					$pending_active = '' !== $pending_token
						&& ! empty( $pending_state )
						&& self::is_pending_operation_active( $pending_state );
					$decision['conversation_id'] = $conversation_id;
					$decision['stage'] = '' !== $stage ? $stage : ( $decision['stage'] ?? '' );
					$decision['pending_operation_token'] = $pending_active
						? ( $pending_token ?: ( $decision['pending_operation_token'] ?? '' ) )
						: '';
					$decision['pending_operation_status'] = sanitize_key( $pending_state['status'] ?? ( '' !== $pending_token ? 'expired' : '' ) );
					$decision['pending_operation_active'] = $pending_active;
					$decision['meta'] = array_merge(
						isset( $decision['meta'] ) && is_array( $decision['meta'] ) ? $decision['meta'] : [],
						[
							'conversation_id'         => $conversation_id,
							'conversation_stage'      => $decision['stage'],
							'pending_operation_token' => $decision['pending_operation_token'],
							'pending_operation_status' => $decision['pending_operation_status'],
							'pending_operation_active' => $pending_active,
						]
					);
					$item['ui'] = self::format_response( $decision, '' );
				}
			}

			$formatted_messages[] = $item;
		}

		return [
			'success'                 => true,
			'conversation_id'         => $conversation_id,
			'status'                  => sanitize_key( $conversation['status'] ?? '' ),
			'title'                   => sanitize_text_field( $conversation['title'] ?? '' ),
			'last_message_at'         => sanitize_text_field( $conversation['last_message_at'] ?? '' ),
			'pending_operation_token' => $pending_operation_token,
			'messages'                => $formatted_messages,
		];
	}

	private static function apply_response_language_to_plan( $plan, $language ) {
		$plan     = is_array( $plan ) ? $plan : [];
		$language = sanitize_key( (string) $language );

		if ( '' === $language || 'unknown' === $language ) {
			return $plan;
		}

		if ( empty( $plan['params'] ) || ! is_array( $plan['params'] ) ) {
			$plan['params'] = [];
		}

		if ( empty( $plan['params']['response_language'] ) ) {
			$plan['params']['response_language'] = $language;
		}

		if ( ! empty( $plan['steps'] ) && is_array( $plan['steps'] ) ) {
			foreach ( $plan['steps'] as $index => $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}

				if ( empty( $step['params'] ) || ! is_array( $step['params'] ) ) {
					$step['params'] = [];
				}

				if ( empty( $step['params']['response_language'] ) ) {
					$step['params']['response_language'] = $language;
				}

				$plan['steps'][ $index ] = $step;
			}
		}

		return $plan;
	}

	private static function apply_local_blocked_preview( &$response, &$assistant, &$parsed, &$result, &$stage, &$summary, $local_preview, $fallback_message ) {
		$blocked_message = sanitize_textarea_field(
			$local_preview['message']
				?? ( $local_preview['summary'] ?? $fallback_message )
		);

		$stage = 'blocked';
		$result = array_merge( $result, $local_preview );
		$result['success'] = false;
		$result['action'] = $parsed['action'];
		$result['params'] = $parsed['params'];
		$result['execution_required'] = false;
		$result['requires_confirmation'] = false;
		$result['confirm_required'] = false;
		$result['pending_operation_active'] = false;
		$result['pending_operation_status'] = 'blocked';
		$result['result_type'] = 'confirmation';
		$result['display_type'] = 'confirmation';
		$result['summary'] = $blocked_message;
		$result['message'] = $blocked_message;
		$summary = $blocked_message;
		$parsed['stage'] = $stage;
		$parsed['summary'] = $blocked_message;
		$parsed['assistant']['message'] = '';
		$parsed['assistant']['question'] = '';
		$parsed['assistant']['operation_summary'] = $blocked_message;
		$assistant['message'] = '';
		$assistant['question'] = '';
		$assistant['operation_summary'] = $blocked_message;
		$response['assistant'] = $assistant;

		return $blocked_message;
	}

	private static function compact_email_preview( $preview ) {
		if ( ! is_array( $preview ) ) {
			return [];
		}

		$compact = [];

		foreach ( [ 'schema_version', 'type', 'kind', 'display_type', 'result_type', 'action', 'send_action', 'template', 'template_label', 'template_source', 'thread_id', 'provider_thread_id' ] as $key ) {
			if ( array_key_exists( $key, $preview ) ) {
				$compact[ $key ] = sanitize_text_field( (string) $preview[ $key ] );
			}
		}

		foreach ( [ 'subject', 'body', 'body_text', 'body_preview' ] as $key ) {
			if ( array_key_exists( $key, $preview ) ) {
				$compact[ $key ] = sanitize_textarea_field( (string) $preview[ $key ] );
			}
		}

		if ( array_key_exists( 'html_body', $preview ) ) {
			$compact['html_body'] = wp_kses_post( (string) $preview['html_body'] );
		}

		foreach ( [ 'requires_confirmation', 'can_edit', 'can_send' ] as $key ) {
			if ( array_key_exists( $key, $preview ) ) {
				$compact[ $key ] = (bool) $preview[ $key ];
			}
		}

		foreach ( [ 'to', 'cc', 'bcc' ] as $key ) {
			if ( array_key_exists( $key, $preview ) ) {
				$compact[ $key ] = self::compact_email_preview_recipient_list( $preview[ $key ] );
			}
		}

		if ( array_key_exists( 'from', $preview ) ) {
			$from = self::compact_email_preview_recipient_list( $preview['from'] );
			$compact['from'] = $from[0] ?? [];
		}

		if ( isset( $preview['email'] ) && is_array( $preview['email'] ) ) {
			$compact['email'] = [
				'template'  => sanitize_text_field( (string) ( $preview['email']['template'] ?? '' ) ),
				'to'        => sanitize_text_field( (string) ( $preview['email']['to'] ?? '' ) ),
				'subject'   => sanitize_textarea_field( (string) ( $preview['email']['subject'] ?? '' ) ),
				'body'      => sanitize_textarea_field( (string) ( $preview['email']['body'] ?? '' ) ),
				'html_body' => wp_kses_post( (string) ( $preview['email']['html_body'] ?? '' ) ),
			];
			$compact['email'] = array_filter(
				$compact['email'],
				static function ( $value ) {
					return '' !== $value && [] !== $value;
				}
			);
		}

		if ( isset( $preview['send_payload'] ) && is_array( $preview['send_payload'] ) ) {
			$compact['send_payload'] = [
				'action'                  => sanitize_text_field( (string) ( $preview['send_payload']['action'] ?? '' ) ),
				'thread_id'               => absint( $preview['send_payload']['thread_id'] ?? 0 ),
				'provider_thread_id'      => sanitize_text_field( (string) ( $preview['send_payload']['provider_thread_id'] ?? '' ) ),
				'to'                      => self::compact_email_preview_recipient_list( $preview['send_payload']['to'] ?? [] ),
				'cc'                      => self::compact_email_preview_recipient_list( $preview['send_payload']['cc'] ?? [] ),
				'subject'                 => sanitize_textarea_field( (string) ( $preview['send_payload']['subject'] ?? '' ) ),
				'body'                    => sanitize_textarea_field( (string) ( $preview['send_payload']['body'] ?? '' ) ),
				'pending_operation_token' => sanitize_text_field( (string) ( $preview['send_payload']['pending_operation_token'] ?? '' ) ),
				'can_send'                => ! array_key_exists( 'can_send', $preview['send_payload'] ) || ! empty( $preview['send_payload']['can_send'] ),
			];
		}

		if ( isset( $preview['source'] ) && is_array( $preview['source'] ) ) {
			$compact['source'] = [
				'type'               => sanitize_key( (string) ( $preview['source']['type'] ?? '' ) ),
				'provider'           => sanitize_key( (string) ( $preview['source']['provider'] ?? '' ) ),
				'mailbox_email'      => sanitize_email( (string) ( $preview['source']['mailbox_email'] ?? '' ) ),
				'thread_id'          => absint( $preview['source']['thread_id'] ?? 0 ),
				'provider_thread_id' => sanitize_text_field( (string) ( $preview['source']['provider_thread_id'] ?? '' ) ),
				'subject'            => sanitize_textarea_field( (string) ( $preview['source']['subject'] ?? '' ) ),
			];
			$compact['source'] = array_filter(
				$compact['source'],
				static function ( $value ) {
					return '' !== $value && 0 !== $value && [] !== $value;
				}
			);
		}

		if ( isset( $preview['unsupported_fields'] ) && is_array( $preview['unsupported_fields'] ) ) {
			$compact['unsupported_fields'] = array_values(
				array_filter(
					array_map( 'sanitize_key', $preview['unsupported_fields'] )
				)
			);
		}

		return $compact;
	}

	private static function compact_email_preview_recipient_list( $recipients ) {
		if ( is_string( $recipients ) ) {
			$recipients = array_filter( array_map( 'trim', explode( ',', $recipients ) ) );
		}

		if ( ! is_array( $recipients ) ) {
			return [];
		}

		if ( isset( $recipients['email'] ) || isset( $recipients['address'] ) ) {
			$recipients = [ $recipients ];
		}

		$compact = [];

		foreach ( $recipients as $recipient ) {
			$name  = '';
			$email = '';

			if ( is_array( $recipient ) ) {
				$name  = sanitize_text_field( (string) ( $recipient['name'] ?? '' ) );
				$email = sanitize_email( (string) ( $recipient['email'] ?? ( $recipient['address'] ?? '' ) ) );
			} else {
				$raw = trim( (string) $recipient );

				if ( 1 === preg_match( '/<([^>]+)>/', $raw, $matches ) ) {
					$email = sanitize_email( (string) $matches[1] );
					$name  = sanitize_text_field( trim( (string) preg_replace( '/<[^>]+>/', '', $raw ) ) );
				} else {
					$email = sanitize_email( $raw );
				}
			}

			if ( '' === $email ) {
				continue;
			}

			$item = [ 'email' => $email ];

			if ( '' !== $name ) {
				$item['name'] = $name;
			}

			$compact[] = $item;
		}

		return $compact;
	}

	private static function mailbox_reply_email_preview_result( $email_preview, $pending_operation_token ) {
		$subject = sanitize_textarea_field( $email_preview['subject'] ?? '' );
		$body    = sanitize_textarea_field( $email_preview['body'] ?? ( $email_preview['body_text'] ?? '' ) );
		$to      = isset( $email_preview['to'] ) && is_array( $email_preview['to'] ) ? $email_preview['to'] : [];
		$cc      = isset( $email_preview['cc'] ) && is_array( $email_preview['cc'] ) ? $email_preview['cc'] : [];
		$html_body = class_exists( 'YoRo_Orders_Email_Service' )
			? YoRo_Orders_Email_Service::render_with_woocommerce_email_template( $subject, $body )
			: wpautop( esc_html( $body ) );

		$email_preview['html_body'] = $html_body;
		$email_preview['html_body_source'] = 'wordpress_woocommerce_mailer';
		$email_preview['email'] = [
			'template'         => 'ai_custom',
			'to'               => self::format_email_preview_recipient_list( $to ),
			'subject'          => $subject,
			'body'             => $body,
			'html_body'        => $html_body,
			'html_body_source' => 'wordpress_woocommerce_mailer',
		];

		return [
			'success'               => true,
			'action'                => 'email.reply.send',
			'summary'               => __( 'Email reply preview', 'yoro-ai-core' ),
			'message'               => __( 'Review and send this reply from the connected shop mailbox.', 'yoro-ai-core' ),
			'read_only'             => false,
			'result_type'           => 'email_preview',
			'display_type'          => 'customer_email_preview',
			'kind'                  => 'mailbox_reply',
			'requires_confirmation' => true,
			'confirm_required'      => true,
			'email_preview'         => $email_preview,
			'email'                 => [
				'to'               => self::format_email_preview_recipient_list( $to ),
				'from'             => self::format_email_preview_recipient( $email_preview['from'] ?? [] ),
				'subject'          => $subject,
				'body'             => $body,
				'html_body'        => $html_body,
				'html_body_source' => 'wordpress_woocommerce_mailer',
				'template'         => 'ai_custom',
			],
			'mailbox_thread'        => [
				'id'                 => absint( $email_preview['thread_id'] ?? 0 ),
				'provider_thread_id' => sanitize_text_field( (string) ( $email_preview['provider_thread_id'] ?? '' ) ),
				'subject'            => $subject,
			],
			'send_payload'          => [
				'action'                  => 'email.reply.send',
				'thread_id'               => absint( $email_preview['thread_id'] ?? 0 ),
				'provider_thread_id'      => sanitize_text_field( (string) ( $email_preview['provider_thread_id'] ?? '' ) ),
				'to'                      => $to,
				'cc'                      => $cc,
				'subject'                 => $subject,
				'body'                    => $body,
				'html_body'               => $html_body,
				'html_body_source'        => 'wordpress_woocommerce_mailer',
				'pending_operation_token' => sanitize_text_field( (string) $pending_operation_token ),
			],
		];
	}

	private static function format_email_preview_recipient_list( $recipients ) {
		if ( ! is_array( $recipients ) ) {
			return '';
		}

		return implode(
			', ',
			array_filter(
				array_map(
					[ __CLASS__, 'format_email_preview_recipient' ],
					$recipients
				)
			)
		);
	}

	private static function format_email_preview_recipient( $recipient ) {
		if ( ! is_array( $recipient ) ) {
			return sanitize_text_field( (string) $recipient );
		}

		$email = sanitize_email( (string) ( $recipient['email'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $recipient['name'] ?? '' ) );

		if ( '' === $email ) {
			return $name;
		}

		if ( '' === $name ) {
			return $email;
		}

		return $name . ' <' . $email . '>';
	}

	private static function local_action_preview( $action_name, $params ) {
		$action_name = sanitize_text_field( (string) $action_name );

		$registered = YoRo_Action_Registry::get( $action_name );

		if ( ! is_array( $registered ) || empty( $registered['preview_callback'] ) || ! is_callable( $registered['preview_callback'] ) ) {
			return [];
		}

		$preview = call_user_func( $registered['preview_callback'], is_array( $params ) ? $params : [] );

		if ( is_wp_error( $preview ) || ! is_array( $preview ) ) {
			return [];
		}

		return $preview;
	}

	private static function local_confirmation_preview( $action_name, $params ) {
		$action_name = sanitize_text_field( (string) $action_name );

		$registered = YoRo_Action_Registry::get( $action_name );

		if ( ! is_array( $registered ) || ( empty( $registered['write_action'] ) && empty( $registered['requires_confirmation'] ) ) ) {
			return [];
		}

		return self::local_action_preview( $action_name, $params );
	}

	private static function local_preview_blocks_clarification( $preview ) {
		return self::local_preview_blocks_runtime( $preview );
	}

	private static function local_preview_blocks_runtime( $preview ) {
		if ( ! is_array( $preview ) || empty( $preview['blocked'] ) ) {
			return false;
		}

		$reason = sanitize_key( $preview['block_reason'] ?? '' );

		return in_array(
			$reason,
			[
				'order_not_found',
				'product_not_found',
				'variation_not_found',
				'variable_parent_requires_variation',
				'variable_parent_requires_variation_or_shared_stock',
				'template_not_found',
				'draft_not_found',
				'note_not_found',
				'invalid_note_target',
				'protected_note',
				'invalid_status',
				'invalid_recipient',
				'missing_recipient',
				'stock_management_disabled',
				'stock_status_managed_by_quantity',
			],
			true
		);
	}

	private static function active_pending_operation_token( array $pending_graphs ) {
		foreach ( $pending_graphs as $pending ) {
			if ( ! is_array( $pending ) ) {
				continue;
			}

			$status = sanitize_key( $pending['status'] ?? '' );

			if ( ! in_array( $status, [ 'ready_to_execute', 'confirmation_required', 'confirmed' ], true ) ) {
				continue;
			}

			$expires_at = sanitize_text_field( $pending['expires_at'] ?? '' );

			if ( '' !== $expires_at && strtotime( $expires_at ) < time() ) {
				continue;
			}

			return sanitize_text_field( $pending['token'] ?? '' );
		}

		return '';
	}

	private static function pending_operation_states( array $pending_graphs ) {
		$states = [];

		foreach ( $pending_graphs as $pending ) {
			if ( ! is_array( $pending ) ) {
				continue;
			}

			$token = sanitize_text_field( $pending['token'] ?? '' );

			if ( '' === $token ) {
				continue;
			}

			$states[ $token ] = [
				'status'     => sanitize_key( $pending['status'] ?? '' ),
				'expires_at' => sanitize_text_field( $pending['expires_at'] ?? '' ),
			];
		}

		return $states;
	}

	private static function is_pending_operation_active( array $pending ) {
		$status = sanitize_key( $pending['status'] ?? '' );

		if ( ! in_array( $status, [ 'ready_to_execute', 'confirmation_required', 'confirmed' ], true ) ) {
			return false;
		}

		$expires_at = sanitize_text_field( $pending['expires_at'] ?? '' );
		$expires_timestamp = '' !== $expires_at ? strtotime( $expires_at ) : false;

		return false === $expires_timestamp || $expires_timestamp >= time();
	}

	private static function build_plan( $response, $action_plan ) {
		$response = is_array( $response ) ? $response : [];
		$action_plan = is_array( $action_plan ) ? $action_plan : [];
		$steps = isset( $action_plan['steps'] ) && is_array( $action_plan['steps'] ) ? array_values( $action_plan['steps'] ) : [];
		$type = sanitize_key( $action_plan['type'] ?? '' );

		if ( '' === $type ) {
			$type = count( $steps ) > 1 ? 'sequence' : 'single';
		}

		if ( 'sequence' === $type ) {
			return [
				'type'   => 'sequence',
				'action' => sanitize_text_field( $action_plan['action'] ?? ( $response['action'] ?? 'multi_action' ) ),
				'params' => isset( $action_plan['params'] ) && is_array( $action_plan['params'] ) ? $action_plan['params'] : [],
				'steps'  => $steps,
			];
		}

		$first_step = isset( $steps[0] ) && is_array( $steps[0] ) ? $steps[0] : [];

		return [
			'type'   => 'single',
			'action' => sanitize_text_field( $action_plan['action'] ?? ( $first_step['action'] ?? ( $response['action'] ?? '' ) ) ),
			'params' => isset( $action_plan['params'] ) && is_array( $action_plan['params'] )
				? $action_plan['params']
				: ( isset( $first_step['params'] ) && is_array( $first_step['params'] ) ? $first_step['params'] : ( is_array( $response['params'] ?? null ) ? $response['params'] : [] ) ),
		];
	}

	private static function compact_context( $context ) {
		$context = is_array( $context ) ? $context : [];

		$compact = [
			'conversation_id'          => sanitize_text_field( $context['conversation_id'] ?? '' ),
			'pending_operation_token' => sanitize_text_field( $context['pending_operation_token'] ?? '' ),
			'action_catalog_scope'    => sanitize_key( $context['action_catalog_scope'] ?? '' ),
			'report_topic_selection'  => sanitize_key( $context['report_topic_selection'] ?? '' ),
			'report_topic'            => sanitize_key( $context['report_topic'] ?? '' ),
			'current_order_id'        => absint( $context['current_order_id'] ?? 0 ),
			'last_referenced_order_id'=> absint( $context['last_referenced_order_id'] ?? 0 ),
			'resolved_order_ids'      => self::integer_list( $context['resolved_order_ids'] ?? [] ),
			'last_order_ids'          => self::integer_list( $context['last_order_ids'] ?? [] ),
			'last_orders'             => self::compact_order_list( $context['last_orders'] ?? [] ),
			'mailbox_thread_id'       => absint( $context['mailbox_thread_id'] ?? 0 ),
			'surface'                 => sanitize_key( $context['surface'] ?? 'admin_copilot' ),
			'store_knowledge'         => YoRo_Store_Knowledge_Provider::get_context( 'conversation' ),
		];

		$mailbox_thread_context = self::compact_mailbox_thread_context( $context['mailbox_thread_context'] ?? [] );

		if ( ! empty( $mailbox_thread_context ) ) {
			$compact['mailbox_thread_context'] = self::enrich_mailbox_thread_context_with_order_candidates( $mailbox_thread_context );
		}

		return $compact;
	}

	private static function user_context( $context ) {
		$user = wp_get_current_user();

		return [
			'user_id'      => get_current_user_id(),
			'user_key'     => get_current_user_id() ? (string) get_current_user_id() : '',
			'display_name' => $user instanceof WP_User ? sanitize_text_field( $user->display_name ) : '',
			'locale'       => function_exists( 'get_user_locale' ) ? sanitize_text_field( get_user_locale() ) : sanitize_text_field( get_locale() ),
			'surface'      => sanitize_key( $context['surface'] ?? 'admin_copilot' ),
		];
	}

	private static function compact_mailbox_thread_context( $context ) {
		if ( ! is_array( $context ) ) {
			return [];
		}

		$connection = is_array( $context['connection'] ?? null ) ? $context['connection'] : [];
		$thread     = is_array( $context['thread'] ?? null ) ? $context['thread'] : [];
		$messages   = is_array( $context['messages'] ?? null ) ? array_values( $context['messages'] ) : [];
		$compact    = [
			'intent'     => sanitize_key( $context['intent'] ?? 'draft_reply' ),
			'source'     => sanitize_key( $context['source'] ?? 'selected_mailbox_thread' ),
			'connection' => array_filter(
				[
					'provider'      => sanitize_key( $connection['provider'] ?? '' ),
					'mailbox_email' => sanitize_email( $connection['mailbox_email'] ?? '' ),
					'display_name'  => self::compact_mailbox_text( $connection['display_name'] ?? '', 120 ),
				]
			),
			'thread'     => array_filter(
				[
					'id'                 => absint( $thread['id'] ?? 0 ),
					'provider_thread_id' => self::compact_mailbox_text( $thread['provider_thread_id'] ?? '', 120 ),
					'subject'            => self::compact_mailbox_text( $thread['subject'] ?? '', 200 ),
					'participants'       => self::compact_mailbox_addresses( $thread['participants'] ?? [], 8 ),
					'snippet'            => self::compact_mailbox_text( $thread['snippet'] ?? '', 500, true ),
					'status'             => sanitize_key( $thread['status'] ?? '' ),
					'unread'             => ! empty( $thread['unread'] ),
					'last_message_at'    => self::compact_mailbox_text( $thread['last_message_at'] ?? '', 80 ),
					'last_inbound_at'    => self::compact_mailbox_text( $thread['last_inbound_at'] ?? '', 80 ),
					'last_outbound_at'   => self::compact_mailbox_text( $thread['last_outbound_at'] ?? '', 80 ),
				],
				static function ( $value ) {
					return null !== $value && '' !== $value && [] !== $value;
				}
			),
			'messages'   => [],
		];

		$commerce_context = self::compact_mailbox_commerce_context( $context['commerce_context'] ?? [] );

		if ( ! empty( $commerce_context ) ) {
			$compact['commerce_context'] = $commerce_context;
		}

		$customer_context = self::compact_mailbox_customer_context( $context['customer_context'] ?? [] );

		if ( ! empty( $customer_context ) ) {
			$compact['customer_context'] = $customer_context;
		}

		$intelligence = self::compact_mailbox_intelligence( $context['intelligence'] ?? [] );

		if ( ! empty( $intelligence ) ) {
			$compact['intelligence'] = $intelligence;
		}

		foreach ( array_slice( $messages, -6 ) as $index => $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$compact_message = array_filter(
				[
					'position'    => $index + 1,
					'id'          => absint( $message['id'] ?? 0 ),
					'direction'   => sanitize_key( $message['direction'] ?? '' ),
					'status'      => sanitize_key( $message['status'] ?? '' ),
					'from_email'  => sanitize_email( $message['from_email'] ?? '' ),
					'from_name'   => self::compact_mailbox_text( $message['from_name'] ?? '', 120 ),
					'to'          => self::compact_mailbox_addresses( $message['to'] ?? [], 6 ),
					'cc'          => self::compact_mailbox_addresses( $message['cc'] ?? [], 6 ),
					'subject'     => self::compact_mailbox_text( $message['subject'] ?? '', 200 ),
					'snippet'     => self::compact_mailbox_text( $message['snippet'] ?? '', 500, true ),
					'body_text'   => self::compact_mailbox_text( $message['body_text'] ?? '', 1800, true ),
					'received_at' => self::compact_mailbox_text( $message['received_at'] ?? '', 80 ),
					'sent_at'     => self::compact_mailbox_text( $message['sent_at'] ?? '', 80 ),
					'created_at'  => self::compact_mailbox_text( $message['created_at'] ?? '', 80 ),
				],
				static function ( $value ) {
					return null !== $value && '' !== $value && [] !== $value;
				}
			);

			if ( ! empty( $compact_message ) ) {
				$compact['messages'][] = $compact_message;
			}
		}

		return array_filter(
			$compact,
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function compact_mailbox_addresses( $addresses, $limit ) {
		if ( ! is_array( $addresses ) ) {
			return [];
		}

		$compact = [];

		foreach ( $addresses as $address ) {
			if ( ! is_array( $address ) ) {
				continue;
			}

			$item = array_filter(
				[
					'name'  => self::compact_mailbox_text( $address['name'] ?? '', 120 ),
					'email' => sanitize_email( $address['email'] ?? '' ),
				]
			);

			if ( ! empty( $item ) ) {
				$compact[] = $item;
			}
		}

		return array_slice( $compact, 0, max( 1, absint( $limit ) ) );
	}

	private static function compact_mailbox_intelligence( $intelligence ) {
		if ( ! is_array( $intelligence ) ) {
			return [];
		}

		$classification = is_array( $intelligence['classification'] ?? null ) ? $intelligence['classification'] : [];
		$priority       = is_array( $intelligence['priority'] ?? null ) ? $intelligence['priority'] : [];
		$reply_guidance = is_array( $intelligence['reply_guidance'] ?? null ) ? $intelligence['reply_guidance'] : [];
		$reply_language = is_array( $intelligence['reply_language'] ?? null ) ? $intelligence['reply_language'] : [];

		return array_filter(
			[
				'schema_version' => absint( $intelligence['schema_version'] ?? 0 ),
				'classification' => array_filter(
					[
						'category'           => sanitize_key( $classification['category'] ?? '' ),
						'bucket'             => sanitize_key( $classification['bucket'] ?? '' ),
						'suggested_handling' => sanitize_key( $classification['suggested_handling'] ?? '' ),
					]
				),
				'priority'       => array_filter(
					[
						'level' => sanitize_key( $priority['level'] ?? '' ),
						'score' => absint( $priority['score'] ?? 0 ),
					]
				),
				'reply_guidance' => array_filter(
					[
						'policy'       => sanitize_key( $reply_guidance['policy'] ?? '' ),
						'missing_info' => array_values(
							array_filter(
								array_map( 'sanitize_key', is_array( $reply_guidance['missing_info'] ?? null ) ? $reply_guidance['missing_info'] : [] )
							)
						),
					],
					static function ( $value ) {
						return null !== $value && '' !== $value && [] !== $value;
					}
				),
				'reply_language' => array_filter(
					[
						'language' => sanitize_key( $reply_language['language'] ?? '' ),
						'source'   => sanitize_key( $reply_language['source'] ?? '' ),
					]
				),
			],
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function compact_mailbox_text( $value, $limit, $preserve_lines = false ) {
		$text = trim( wp_strip_all_tags( (string) $value ) );

		if ( '' === $text ) {
			return '';
		}

		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = $preserve_lines
			? preg_replace( "/\n{3,}/", "\n\n", $text )
			: preg_replace( '/\s+/', ' ', $text );

		return mb_substr( trim( (string) $text ), 0, max( 1, absint( $limit ) ) );
	}

	private static function integer_list( $values ) {
		return array_values(
			array_filter(
				array_unique(
					array_map( 'absint', is_array( $values ) ? $values : [] )
				)
			)
		);
	}

	private static function compact_order_list( $orders ) {
		if ( ! is_array( $orders ) ) {
			return [];
		}

		$compact = [];
		$position = 1;

		foreach ( $orders as $order ) {
			if ( ! is_array( $order ) ) {
				continue;
			}

			$id = absint( $order['id'] ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			$item = [
				'position' => $position,
				'id'       => $id,
			];

			if ( ! empty( $order['number'] ) ) {
				$item['number'] = sanitize_text_field( (string) $order['number'] );
			}

			if ( ! empty( $order['status'] ) ) {
				$item['status'] = sanitize_key( (string) $order['status'] );
			}

			$compact[] = $item;
			$position++;
		}

		return array_slice( $compact, 0, 20 );
	}

	private static function enrich_mailbox_thread_context_with_order_candidates( $context ) {
		if ( ! is_array( $context ) || empty( $context['messages'] ) || ! is_array( $context['messages'] ) ) {
			return is_array( $context ) ? $context : [];
		}

		$customer = self::mailbox_thread_customer_from_messages( $context );
		$email    = sanitize_email( $customer['email'] ?? '' );

		if ( '' === $email ) {
			return $context;
		}

		$order_candidates = self::mailbox_order_candidates_for_email( $email, 5 );

		if ( empty( $order_candidates ) ) {
			$context['customer_context'] = array_filter(
				array_merge(
					self::compact_mailbox_customer_context( $context['customer_context'] ?? [] ),
					[
						'email'  => $email,
						'name'   => self::compact_mailbox_text( $customer['name'] ?? '', 120 ),
						'status' => 'unknown',
					]
				)
			);

			return $context;
		}

		$context['customer_context'] = array_filter(
			array_merge(
				self::compact_mailbox_customer_context( $context['customer_context'] ?? [] ),
				[
					'email'            => $email,
					'name'             => self::compact_mailbox_text( $customer['name'] ?? '', 120 ),
					'status'           => 'matched_order_customer',
					'order_candidates' => $order_candidates,
				]
			)
		);
		$context['commerce_context'] = self::compact_mailbox_commerce_context(
			array_merge(
				is_array( $context['commerce_context'] ?? null ) ? $context['commerce_context'] : [],
				[
					'customer'         => [
						'email'  => $email,
						'name'   => self::compact_mailbox_text( $customer['name'] ?? '', 120 ),
						'status' => 'matched_order_customer',
					],
					'order_candidates' => $order_candidates,
				]
			)
		);

		return $context;
	}

	private static function mailbox_thread_customer_from_messages( $context ) {
		$mailbox_email = sanitize_email( $context['connection']['mailbox_email'] ?? '' );
		$messages      = is_array( $context['messages'] ?? null ) ? array_values( $context['messages'] ) : [];

		for ( $index = count( $messages ) - 1; $index >= 0; $index-- ) {
			$message   = is_array( $messages[ $index ] ?? null ) ? $messages[ $index ] : [];
			$direction = sanitize_key( $message['direction'] ?? '' );
			$email     = sanitize_email( $message['from_email'] ?? '' );

			if ( '' === $email || ( '' !== $mailbox_email && strtolower( $email ) === strtolower( $mailbox_email ) ) ) {
				continue;
			}

			if ( '' !== $direction && 'inbound' !== $direction ) {
				continue;
			}

			return [
				'email' => $email,
				'name'  => self::compact_mailbox_text( $message['from_name'] ?? '', 120 ),
			];
		}

		return [];
	}

	private static function mailbox_order_candidates_for_email( $email, $limit ) {
		$email = sanitize_email( $email );

		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$args = [
			'billing_email' => $email,
			'limit'         => max( 1, min( 10, absint( $limit ) ) ),
			'orderby'       => 'date',
			'order'         => 'DESC',
			'return'        => 'objects',
		];

		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$args['status'] = array_keys( wc_get_order_statuses() );
		}

		$orders = wc_get_orders( $args );

		if ( ! is_array( $orders ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					[ __CLASS__, 'compact_mailbox_order_candidate' ],
					$orders
				)
			)
		);
	}

	private static function compact_mailbox_order_candidate( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return [];
		}

		$tracking = self::mailbox_order_tracking_context( $order );

		return array_filter(
			[
				'id'                 => absint( $order->get_id() ),
				'number'             => sanitize_text_field( $order->get_order_number() ),
				'status'             => sanitize_key( $order->get_status() ),
				'payment_status'     => $order->is_paid() ? 'paid' : 'unpaid',
				'fulfillment_status' => self::mailbox_order_fulfillment_status( $order, $tracking ),
				'shipping_status'    => self::mailbox_order_shipping_status( $order, $tracking ),
				'tracking_status'    => $tracking['status'] ?? '',
				'tracking_number'    => $tracking['number'] ?? '',
				'total'              => wc_format_decimal( $order->get_total(), 2 ),
				'currency'           => sanitize_text_field( $order->get_currency() ),
				'created_at'         => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : '',
				'customer_email'     => sanitize_email( $order->get_billing_email() ),
				'customer_name'      => self::compact_mailbox_text( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 120 ),
			],
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function mailbox_order_tracking_context( WC_Order $order ) {
		$tracking_meta_keys = [
			'_tracking_number',
			'_aftership_tracking_number',
			'_wcast_tracking_number',
			'_yith_tracking_code',
		];

		foreach ( $tracking_meta_keys as $key ) {
			$value = trim( (string) $order->get_meta( $key, true ) );

			if ( '' !== $value ) {
				return [
					'status' => 'available',
					'number' => sanitize_text_field( $value ),
				];
			}
		}

		$shipment_items = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( is_array( $shipment_items ) && ! empty( $shipment_items ) ) {
			$first = is_array( $shipment_items[0] ?? null ) ? $shipment_items[0] : [];
			$value = trim( (string) ( $first['tracking_number'] ?? '' ) );

			return [
				'status' => 'available',
				'number' => sanitize_text_field( $value ),
			];
		}

		return [
			'status' => 'not_available',
			'number' => '',
		];
	}

	private static function mailbox_order_shipping_status( WC_Order $order, array $tracking ) {
		if ( ! $order->get_items( 'shipping' ) ) {
			return 'not_required';
		}

		if ( 'available' === ( $tracking['status'] ?? '' ) ) {
			return 'tracking_available';
		}

		if ( in_array( $order->get_status(), [ 'completed' ], true ) ) {
			return 'completed_no_tracking';
		}

		return 'not_shipped';
	}

	private static function mailbox_order_fulfillment_status( WC_Order $order, array $tracking ) {
		if ( 'available' === ( $tracking['status'] ?? '' ) ) {
			return 'tracking_available';
		}

		if ( in_array( $order->get_status(), [ 'completed' ], true ) ) {
			return 'fulfilled';
		}

		if ( in_array( $order->get_status(), [ 'processing', 'on-hold' ], true ) ) {
			return 'open';
		}

		return sanitize_key( $order->get_status() );
	}

	private static function compact_mailbox_commerce_context( $context ) {
		if ( ! is_array( $context ) ) {
			return [];
		}

		$customer = is_array( $context['customer'] ?? null ) ? $context['customer'] : [];

		return array_filter(
			[
				'customer'         => array_filter(
					[
						'status' => sanitize_key( $customer['status'] ?? '' ),
						'email'  => sanitize_email( $customer['email'] ?? '' ),
						'name'   => self::compact_mailbox_text( $customer['name'] ?? '', 120 ),
					]
				),
				'order_candidates' => self::compact_mailbox_order_candidates( $context['order_candidates'] ?? [] ),
			],
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function compact_mailbox_customer_context( $context ) {
		if ( ! is_array( $context ) ) {
			return [];
		}

		return array_filter(
			[
				'email'            => sanitize_email( $context['email'] ?? '' ),
				'name'             => self::compact_mailbox_text( $context['name'] ?? '', 120 ),
				'status'           => sanitize_key( $context['status'] ?? '' ),
				'order_candidates' => self::compact_mailbox_order_candidates( $context['order_candidates'] ?? ( $context['orders'] ?? [] ) ),
			],
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function compact_mailbox_order_candidates( $orders ) {
		if ( ! is_array( $orders ) ) {
			return [];
		}

		return array_slice(
			array_values(
				array_filter(
					array_map(
						static function ( $order ) {
							if ( $order instanceof WC_Order ) {
								return self::compact_mailbox_order_candidate( $order );
							}

							if ( ! is_array( $order ) ) {
								return [];
							}

							$id = absint( $order['id'] ?? ( $order['order_id'] ?? 0 ) );

							if ( $id <= 0 ) {
								return [];
							}

							return array_filter(
								[
									'id'                 => $id,
									'number'             => sanitize_text_field( (string) ( $order['number'] ?? ( $order['order_number'] ?? $id ) ) ),
									'status'             => sanitize_key( (string) ( $order['status'] ?? '' ) ),
									'payment_status'     => sanitize_key( (string) ( $order['payment_status'] ?? '' ) ),
									'fulfillment_status' => sanitize_key( (string) ( $order['fulfillment_status'] ?? '' ) ),
									'shipping_status'    => sanitize_key( (string) ( $order['shipping_status'] ?? '' ) ),
									'tracking_status'    => sanitize_key( (string) ( $order['tracking_status'] ?? '' ) ),
									'tracking_number'    => sanitize_text_field( (string) ( $order['tracking_number'] ?? '' ) ),
									'total'              => sanitize_text_field( (string) ( $order['total'] ?? '' ) ),
									'currency'           => sanitize_text_field( (string) ( $order['currency'] ?? '' ) ),
									'created_at'         => sanitize_text_field( (string) ( $order['created_at'] ?? ( $order['date_created'] ?? '' ) ) ),
									'customer_email'     => sanitize_email( (string) ( $order['customer_email'] ?? ( $order['billing_email'] ?? '' ) ) ),
									'customer_name'      => self::compact_mailbox_text( $order['customer_name'] ?? '', 120 ),
								],
								static function ( $value ) {
									return null !== $value && '' !== $value && [] !== $value;
								}
							);
						},
						$orders
					)
				)
			),
			0,
			5
		);
	}
}
