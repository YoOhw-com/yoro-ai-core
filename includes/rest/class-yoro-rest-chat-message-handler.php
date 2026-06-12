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

		if ( '' === $stage ) {
			if ( 'clarify' === $mode ) {
				$stage = 'clarify';
			} elseif ( ! empty( $steps ) ) {
				$stage = 'action_plan';
			} else {
				$stage = 'done';
			}
		}

		$confirm_required = 'confirm' === $stage || ! empty( $response['confirm_required'] ) || ! empty( $response['requires_confirmation'] );
		$summary = sanitize_textarea_field(
			$assistant['message']
				?? ( $assistant['question'] ?? ( $response['summary'] ?? '' ) )
		);
		$plan = self::build_plan( $response, $action_plan );
		$pending_operation_token = sanitize_text_field( $response['pending_operation_token'] ?? ( $meta['pending_operation_token'] ?? '' ) );
		$conversation_id = sanitize_text_field( $response['conversation_id'] ?? ( $meta['conversation_id'] ?? '' ) );
		$parsed = [
			'success'         => true,
			'matched'         => true,
			'stage'           => $stage,
			'action'          => sanitize_text_field( $response['action'] ?? '' ),
			'params'          => isset( $response['params'] ) && is_array( $response['params'] ) ? $response['params'] : [],
			'parser_source'   => 'conversation_orchestrator',
			'parser_rule'     => 'laravel.conversation.message',
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

			$local_preview = self::local_confirmation_preview( $parsed['action'], $parsed['params'] );

			if ( [] !== $local_preview ) {
				$result = array_merge( $result, $local_preview );
				$result['success'] = false;
				$result['action'] = $parsed['action'];
				$result['params'] = $parsed['params'];
				$result['result_type'] = 'confirmation';
				$result['display_type'] = 'confirmation';

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

		return [
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
			'surface'                 => sanitize_key( $context['surface'] ?? 'admin_copilot' ),
			'store_knowledge'         => YoRo_Store_Knowledge_Provider::get_context( 'conversation' ),
		];
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
}
