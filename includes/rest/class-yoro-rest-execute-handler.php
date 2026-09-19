<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Execute_Handler {

	public static function handle( WP_REST_Request $request ) {
		$request_started_at = microtime( true );
		$rate_limit = YoRo_Rate_Limiter::check( 'execute' );

		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$action                  = sanitize_text_field( $request->get_param( 'action' ) );
		$params                  = $request->get_param( 'params' );
		$confirm                 = (bool) $request->get_param( 'confirm' );
		$preview                 = (bool) $request->get_param( 'preview' );
		$conversation_id         = sanitize_text_field( $request->get_param( 'conversation_id' ) );
		$pending_operation_token = sanitize_text_field( $request->get_param( 'pending_operation_token' ) );

		if ( ! is_array( $params ) ) {
			$params = [];
		}

		$request_params = $params;

		$user_id  = get_current_user_id();
		$trace_id = 'yoro_' . wp_generate_uuid4();

		$stored_action_state = null;
		$pending_operation = [];
		$execution_permit = '';
		$claimed_execution_context = null;
		$direct_confirmation_allowed = '' === $pending_operation_token && ! in_array( $action, [ 'orders.send_transactional_email', 'orders.regenerate_download_permissions', 'products.adjust_stock', 'products.create_draft', 'products.update_content', 'products.update_pricing', 'coupons.update', 'fulfillment.add_tracking', 'fulfillment.update_tracking', 'fulfillment.create', 'orders.refund', 'orders.record_external_refund', 'orders.create', 'orders.update_items' ], true );

		if ( ( $confirm || in_array( $action, [ 'orders.create', 'orders.update_items' ], true ) ) && '' !== $conversation_id && '' !== $pending_operation_token ) {
			$stored_action_state = YoRo_REST_Conversation_Action_Coordinator::get_pending_operation_state(
				$conversation_id,
				$pending_operation_token
			);
		}

		if ( $confirm && '' !== $pending_operation_token && empty( $stored_action_state ) ) {
			return rest_ensure_response(
				self::build_stale_action_confirmation_response( $trace_id )
			);
		}

		if ( ! empty( $stored_action_state ) ) {
			$parsed = isset( $stored_action_state['parsed'] ) && is_array( $stored_action_state['parsed'] )
				? $stored_action_state['parsed']
				: [];
			$plan   = isset( $stored_action_state['plan'] ) && is_array( $stored_action_state['plan'] )
				? $stored_action_state['plan']
				: [];
			$action = sanitize_text_field( $plan['action'] ?? ( $parsed['action'] ?? $action ) );
			$params = isset( $plan['params'] ) && is_array( $plan['params'] )
				? $plan['params']
				: $params;
			$direct_confirmation_allowed = false;
			$pending_operation = isset( $stored_action_state['pending_operation'] ) && is_array( $stored_action_state['pending_operation'] )
				? $stored_action_state['pending_operation']
				: [];
		} else {
			if ( empty( $action ) ) {
				return new WP_Error(
					'yoro_empty_action',
					__( 'I need a store action before I can continue.', 'yoro-ai-core' )
				);
			}

			$registered_action = YoRo_Action_Registry::get( $action );

			if ( ! $registered_action ) {
				return new WP_Error(
					'yoro_invalid_action',
					__( 'I could not find that store action.', 'yoro-ai-core' )
				);
			}

			$parsed = [
				'success' => true,
				'matched' => true,
				'action'  => $action,
				'params'  => $params,
				'meta'    => [
					'conversation_id'         => $conversation_id,
					'pending_operation_token' => $pending_operation_token,
				],
			];

			$plan = [
				'type'   => 'single',
				'action' => $action,
				'params' => $params,
				'parsed' => $parsed,
			];
		}

		if ( '' !== $conversation_id ) {
			$parsed['meta'] = isset( $parsed['meta'] ) && is_array( $parsed['meta'] ) ? $parsed['meta'] : [];
			$parsed['meta']['conversation_id'] = $conversation_id;
		}

		$parsed = YoRo_REST_Conversation_Action_Coordinator::merge_pending_operation_token(
			$parsed,
			$pending_operation_token
		);
		$pending_operation_token = YoRo_REST_Conversation_Action_Coordinator::pending_operation_token_from_parsed(
			$parsed,
			$pending_operation_token
		);
		$trusted_origin = YoRo_Safety_Contract::trusted_origin( $pending_operation_token, 'execute' );
		$preflight = YoRo_Safety_Contract::preflight( $plan, $pending_operation );
		if ( in_array( $action, [ 'orders.create', 'orders.update_items' ], true ) ) {
			if ( empty( $stored_action_state ) || 'single' !== ( $plan['type'] ?? '' ) || 'confirmation_required' !== ( $pending_operation['status'] ?? '' ) || ( $request_params && $request_params !== $params ) ) {
				return new WP_Error( 'order_creation_pending_required', 'A current exact local pending order-creation operation is required.' );
			}
			if ( ! $confirm ) {
				$response = YoRo_Runtime_Guard::build_confirmation_response( $plan, $parsed, [] );
				$response['pending_operation_token'] = $pending_operation_token;
				$response['conversation_id'] = $conversation_id;
				return rest_ensure_response( $response );
			}
			if ( ! YoRo_Runtime_Guard::verify_creation_receipt( $request->get_param( 'displayed_preview_receipt' ), $plan, $parsed, $preflight ) ) {
				return new WP_Error( 'order_creation_preview_stale', 'The displayed estimate is missing, expired or changed. Refresh the preview and confirm again; nothing was created.' );
			}
			$request_params = [];
		}


		if ( $confirm && self::is_yoro_ai_internal_action( $action ) && '' !== $pending_operation_token ) {
			if ( 'email.reply.send' === $action ) {
				$request_params = self::with_mailbox_reply_woocommerce_html( $request_params );
			}

			$internal_preflight_plan = $plan;

			if ( 'single' === ( $internal_preflight_plan['type'] ?? '' ) ) {
				$internal_preflight_plan['params'] = array_merge(
					isset( $internal_preflight_plan['params'] ) && is_array( $internal_preflight_plan['params'] ) ? $internal_preflight_plan['params'] : [],
					$request_params
				);
			}

			$preflight = YoRo_Safety_Contract::preflight( $internal_preflight_plan, $pending_operation );

			$confirmation = YoRo_REST_Conversation_Action_Coordinator::confirm_pending_operation(
				$parsed,
				$pending_operation_token,
				$request_params,
				$preflight
			);

			if ( is_wp_error( $confirmation ) ) {
				return rest_ensure_response(
					YoRo_REST_Conversation_Action_Coordinator::build_confirmation_error_response(
						$trace_id,
						$confirmation
					)
				);
			}

			$claim = YoRo_REST_Conversation_Action_Coordinator::claim_pending_operation( $parsed, $confirmation );

			if ( is_wp_error( $claim ) ) {
				return rest_ensure_response(
					YoRo_REST_Conversation_Action_Coordinator::build_confirmation_error_response( $trace_id, $claim )
				);
			}

			return rest_ensure_response(
				self::build_yoro_ai_internal_action_response(
					$trace_id,
					$parsed,
					$plan,
					is_array( $claim ) ? $claim : []
				)
			);
		}

		$policy_context = [
			'source'  => $trusted_origin,
			'origin'  => $trusted_origin,
			'route'   => 'execute',
			'preview' => $preview,
			'confirm' => $confirm,
		];

		$runtime = YoRo_Runtime_Guard::prepare(
			$plan,
			$policy_context
		);

		if ( is_wp_error( $runtime ) ) {
			$semantic_response = YoRo_REST_Response_Helper::maybe_build_semantic_validation_response(
				$trace_id,
				$parsed,
				$plan,
				[],
				[],
				$runtime
			);

			if ( is_array( $semantic_response ) ) {
				return rest_ensure_response( $semantic_response );
			}

			return $runtime;
		}

		$runtime_log_context = YoRo_REST_Runtime_Log::build_context(
			$trace_id,
			$plan,
			$runtime,
			[
				'execution_source' => $trusted_origin,
				'parser_source'    => $trusted_origin,
				'retrieved_examples' => isset( $parsed['retrieved_examples'] ) && is_array( $parsed['retrieved_examples'] ) ? $parsed['retrieved_examples'] : [],
				'route_candidates' => isset( $parsed['route_candidates'] ) && is_array( $parsed['route_candidates'] ) ? $parsed['route_candidates'] : [],
				'request_started_at' => $request_started_at,
			]
		);

		$preview = ! empty( $runtime['preview'] );
		$confirm = ! empty( $runtime['confirm'] );
		$risk   = isset( $runtime['risk'] ) && is_array( $runtime['risk'] ) ? $runtime['risk'] : [];
		$policy = isset( $runtime['policy'] ) && is_array( $runtime['policy'] ) ? $runtime['policy'] : [];
		if ( ! empty( $runtime['requires_confirmation'] ) ) {
			$response = YoRo_Runtime_Guard::build_confirmation_response(
				$plan,
				$parsed,
				$runtime
			);

			$response['trace_id'] = $trace_id;
			$response['direct_confirmation_allowed'] = $direct_confirmation_allowed;
			YoRo_REST_Execute_Log_Helper::log_runtime_confirmation_required(
				$user_id,
				$action,
				$params,
				$trace_id,
				$runtime_log_context,
				$runtime
			);

			return rest_ensure_response( $response );
		}

		if ( $confirm ) {
			$confirmation = YoRo_REST_Conversation_Action_Coordinator::confirm_pending_operation(
				$parsed,
				$pending_operation_token,
				$request_params,
				$preflight
			);

			if ( is_wp_error( $confirmation ) ) {
				return rest_ensure_response(
					YoRo_REST_Conversation_Action_Coordinator::build_confirmation_error_response(
						$trace_id,
						$confirmation
					)
				);
			}

			$claim = YoRo_REST_Conversation_Action_Coordinator::claim_pending_operation( $parsed, $confirmation );

			if ( is_wp_error( $claim ) ) {
				return rest_ensure_response(
					YoRo_REST_Conversation_Action_Coordinator::build_confirmation_error_response( $trace_id, $claim )
				);
			}

			if ( is_array( $claim ) ) {
				$execution_permit = sanitize_text_field( $claim['_execution_permit'] ?? '' );
			}
		}

		// Only a successful claim in this request can create this non-serializable handoff.
		// Confirmation without a pending token keeps its legacy meaning for existing actions.
		$issued_permit = is_array( $confirmation ?? null ) ? ( $confirmation['execution_permit']['token'] ?? '' ) : '';
		$product_claim_bound = true;
		if ( in_array( $action, [ 'products.adjust_stock', 'products.create_draft', 'products.update_content', 'products.update_pricing', 'coupons.update', 'fulfillment.add_tracking', 'fulfillment.update_tracking', 'fulfillment.create', 'orders.refund', 'orders.record_external_refund', 'orders.create', 'orders.update_items' ], true ) ) {
			foreach ( [ 'plan_hash', 'preview_hash', 'policy_version' ] as $binding_key ) {
				$expected_binding = $confirmation['execution_permit'][ $binding_key ] ?? null;
				if ( 'policy_version' === $binding_key ) {
					$valid_binding = is_int( $expected_binding ) && $expected_binding > 0;
				} else {
					$valid_binding = is_string( $expected_binding ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $expected_binding );
				}
				if ( ! $valid_binding || $expected_binding !== ( $claim[ $binding_key ] ?? null ) ) { $product_claim_bound = false; }
			}
		}
		if ( in_array( $action, [ 'orders.create', 'orders.update_items' ], true ) && ( $confirmation['execution_permit']['preview_hash'] ?? null ) !== ( $preflight['preview_hash'] ?? null ) ) {
			$product_claim_bound = false;
		}
		if ( $product_claim_bound && $confirm && '' !== $conversation_id && '' !== $pending_operation_token && '' !== $execution_permit
			&& is_string( $issued_permit ) && hash_equals( $issued_permit, $execution_permit )
			&& true === ( $claim['success'] ?? false ) && 'claimed' === ( $claim['stage'] ?? '' )
			&& 'executing' === ( $claim['pending_operation_status'] ?? '' )
			&& $conversation_id === ( $claim['conversation_id'] ?? null ) && $pending_operation_token === ( $claim['pending_operation_token'] ?? null ) ) {
			$native_steps = 'single' === ( $plan['type'] ?? '' ) ? [ $plan ] : ( $plan['steps'] ?? [] );
			$native_steps = array_values( array_filter( (array) $native_steps, static function ( $step ) {
				return is_array( $step ) && in_array( $step['action'] ?? '', [ 'orders.send_transactional_email', 'orders.regenerate_download_permissions', 'products.adjust_stock', 'products.create_draft', 'products.update_content', 'products.update_pricing', 'coupons.update', 'fulfillment.add_tracking', 'fulfillment.update_tracking', 'fulfillment.create', 'orders.refund', 'orders.record_external_refund', 'orders.create', 'orders.update_items' ], true );
			} ) );
			if ( 1 === count( $native_steps ) ) {
				$approved_params = $native_steps[0]['params'] ?? [];
				$approved_action = $native_steps[0]['action'];
				$used = false;
				$claimed_execution_context = static function ( $action_name, $execution_params ) use ( $approved_params, $approved_action, &$used, $plan, $preflight ) {
					if ( $used || $approved_action !== $action_name || $execution_params !== $approved_params ) {
						return false;
					}
					$used = true;
					return in_array( $approved_action, [ 'orders.create', 'orders.update_items' ], true ) ? [ 'plan' => $plan, 'preview_hash' => $preflight['preview_hash'] ] : true;
				};
			}
		}
		$result = YoRo_Plan_Executor::execute( $plan, $confirm, $preview, $claimed_execution_context );

		if ( is_wp_error( $result ) ) {
			$result = YoRo_REST_Response_Helper::normalize_error_result( $action, $result );
		}

		if ( is_array( $result ) && empty( $result['success'] ) && empty( $result['requires_confirmation'] ) ) {
			$semantic_response = YoRo_REST_Response_Helper::maybe_build_semantic_validation_response_from_result(
				$trace_id,
				$parsed,
				$plan,
				$risk,
				$policy,
				$result
			);

			if ( is_array( $semantic_response ) ) {
				return rest_ensure_response( $semantic_response );
			}

			self::report_conversation_action_events(
				$conversation_id,
				$pending_operation_token,
				$action,
				$params,
				$result,
				$trace_id,
				$plan,
				$execution_permit
			);

			YoRo_REST_Execute_Log_Helper::log_error(
				$user_id,
				$action,
				$params,
				$preview,
				$confirm,
				$trace_id,
				$runtime_log_context,
				$result
			);

			return rest_ensure_response(
				[
					'success' => false,
					'stage'   => 'error',
					'trace_id' => $trace_id,
					'parsed'  => $parsed,
					'plan'    => $plan,
					'risk'    => $risk,
					'policy'  => $policy,
					'result'  => $result,
					'message' => $result['message'] ?? __( 'I could not complete this action.', 'yoro-ai-core' ),
				]
			);
		}

		if ( $preview ) {
			$response = [
				'success'                     => true,
				'stage'                       => 'preview',
				'trace_id'                    => $trace_id,
				'parsed'                      => $parsed,
				'plan'                        => $plan,
				'risk'                        => $risk,
				'policy'                      => $policy,
				'preview'                     => $result,
				'result'                      => $result,
				'direct_confirmation_allowed' => $direct_confirmation_allowed,
			];

			YoRo_REST_Execute_Log_Helper::log_preview(
				$user_id,
				$action,
				$params,
				$trace_id,
				$runtime_log_context,
				$result,
				$runtime
			);

			return rest_ensure_response( $response );
		}

		if ( is_array( $result ) && ! empty( $result['requires_confirmation'] ) ) {
			$result['result_type']  = $result['result_type'] ?? 'confirmation';
			$result['display_type'] = $result['display_type'] ?? 'confirmation';

			YoRo_REST_Execute_Log_Helper::log_confirmation_required(
				$user_id,
				$action,
				$params,
				$trace_id,
				$runtime_log_context,
				$result,
				$runtime
			);

			return rest_ensure_response(
				[
					'success'          => false,
					'stage'            => 'confirm',
					'trace_id'         => $trace_id,
					'parsed'           => $parsed,
					'plan'             => $plan,
					'confirm_required' => true,
					'message'          => $result['message'] ?? __( 'Please confirm before I continue.', 'yoro-ai-core' ),
					'result'           => $result,
					'risk'             => $risk,
					'policy'           => $policy,
					'direct_confirmation_allowed' => $direct_confirmation_allowed,
				]
			);
		}

		$log_id = YoRo_REST_Execute_Log_Helper::log_executed(
			$user_id,
			$action,
			$params,
			$confirm,
			$trace_id,
			$runtime_log_context,
			$result,
			$runtime
		);

		self::report_conversation_action_events(
			$conversation_id,
			$pending_operation_token,
			$action,
			$params,
			$result,
			$trace_id,
			$plan,
			$execution_permit
		);

		return rest_ensure_response(
			[
				'success' => true,
				'stage'   => 'done',
				'trace_id' => $trace_id,
				'parsed'  => $parsed,
				'plan'    => $plan,
				'result'  => $result,
				'log_id'  => $log_id,
				'risk'    => $risk,
				'policy'  => $policy,
			]
		);
	}

	private static function is_yoro_ai_internal_action( $action ) {
		$action = sanitize_text_field( (string) $action );

		return 0 === strpos( $action, 'store_preferences.' )
			|| 'email.reply.send' === $action;
	}

	public static function with_mailbox_reply_woocommerce_html( $params ) {
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		$body = sanitize_textarea_field( (string) ( $params['body'] ?? '' ) );

		if ( '' === trim( $body ) ) {
			return $params;
		}

		$subject   = sanitize_text_field( (string) ( $params['subject'] ?? '' ) );
		$html_body = class_exists( 'YoRo_Orders_Email_Service' )
			? YoRo_Orders_Email_Service::render_with_woocommerce_email_template( $subject, $body )
			: wpautop( esc_html( $body ) );

		$params['body']             = $body;
		$params['html_body']        = $html_body;
		$params['html_body_source'] = 'wordpress_woocommerce_mailer';

		if ( '' !== $subject ) {
			$params['subject'] = $subject;
		}

		return $params;
	}

	private static function build_yoro_ai_internal_action_response( $trace_id, $parsed, $plan, $confirmation ) {
		$result = isset( $confirmation['result'] ) && is_array( $confirmation['result'] )
			? $confirmation['result']
			: [];
		$message = sanitize_text_field(
			$result['message']
				?? ( $confirmation['message'] ?? __( 'I saved that store preference.', 'yoro-ai-core' ) )
		);
		$result_succeeded = ! isset( $result['success'] ) || ! empty( $result['success'] );

		if ( empty( $confirmation['success'] ) || empty( $confirmation['internal_execution'] ) || ! $result_succeeded ) {
			$message = sanitize_text_field(
				$result['message'] ?? ( $confirmation['message'] ?? __( 'I could not complete that internal action.', 'yoro-ai-core' ) )
			);

			return [
				'success' => false,
				'stage'   => 'error',
				'trace_id' => sanitize_text_field( (string) $trace_id ),
				'parsed'  => is_array( $parsed ) ? $parsed : [],
				'plan'    => is_array( $plan ) ? $plan : [],
				'message' => $message,
				'result'  => [
					'success'      => false,
					'message'      => $message,
					'summary'      => $message,
					'read_only'    => true,
					'result_type'  => 'error',
					'display_type' => 'error',
				],
			];
		}

		$result['success']      = true;
		$result['message']      = $message;
		$result['summary']      = sanitize_text_field( $result['summary'] ?? $message );
		$result['read_only']    = true;
		$result['result_type']  = $result['result_type'] ?? 'assistant_message';
		$result['display_type'] = $result['display_type'] ?? 'assistant_message';
		$pending_status         = sanitize_key( $confirmation['pending_operation_status'] ?? ( $confirmation['stage'] ?? '' ) );
		$is_terminal            = in_array( $pending_status, [ 'executed', 'failed', 'cancelled' ], true );
		$parsed                 = $is_terminal ? self::strip_terminal_pending_preview_artifacts( $parsed, $pending_status ) : $parsed;
		$result['pending_operation_active'] = false;

		if ( '' !== $pending_status ) {
			$result['pending_operation_status'] = $pending_status;
		}

		return [
			'success'                  => true,
			'stage'                    => 'done',
			'trace_id'                 => sanitize_text_field( (string) $trace_id ),
			'conversation_id'          => sanitize_text_field( (string) ( $confirmation['conversation_id'] ?? '' ) ),
			'pending_operation_token'  => $is_terminal ? '' : sanitize_text_field( (string) ( $confirmation['pending_operation_token'] ?? '' ) ),
			'pending_operation_status' => $pending_status,
			'pending_operation_active' => false,
			'parsed'                   => is_array( $parsed ) ? $parsed : [],
			'plan'                     => is_array( $plan ) ? $plan : [],
			'result'                   => $result,
			'message'                  => $message,
			'internal_execution'       => true,
		];
	}

	private static function strip_terminal_pending_preview_artifacts( $parsed, $pending_status ) {
		if ( ! is_array( $parsed ) ) {
			return [];
		}

		unset( $parsed['email_preview'] );

		if ( isset( $parsed['action_plan'] ) && is_array( $parsed['action_plan'] ) ) {
			unset( $parsed['action_plan']['email_preview'] );

			if ( isset( $parsed['action_plan']['steps'] ) && is_array( $parsed['action_plan']['steps'] ) ) {
				foreach ( $parsed['action_plan']['steps'] as $index => $step ) {
					if ( ! is_array( $step ) ) {
						continue;
					}

					if ( isset( $step['meta'] ) && is_array( $step['meta'] ) ) {
						unset( $step['meta']['email_preview'] );
					}

					$parsed['action_plan']['steps'][ $index ] = $step;
				}
			}
		}

		$parsed['meta'] = isset( $parsed['meta'] ) && is_array( $parsed['meta'] )
			? $parsed['meta']
			: [];
		$parsed['meta']['pending_operation_token'] = '';
		$parsed['meta']['pending_operation_active'] = false;
		$parsed['meta']['pending_operation_status'] = sanitize_key( (string) $pending_status );

		return $parsed;
	}

	private static function build_stale_action_confirmation_response( $trace_id ) {
		$message = __( 'This approval request expired. Please review the action again before approving.', 'yoro-ai-core' );

		return [
			'success'          => false,
			'stage'            => 'confirm',
			'trace_id'         => $trace_id,
			'confirm_required' => true,
			'message'          => $message,
			'result'           => [
				'success'      => false,
				'summary'      => $message,
				'message'      => $message,
				'read_only'    => true,
				'result_type'  => 'confirmation',
				'display_type' => 'confirmation',
				'errors'       => [
					[
						'code'    => 'yoro_stale_action_confirmation',
						'message' => $message,
					],
				],
			],
		];
	}

	private static function report_conversation_action_events( $conversation_id, $pending_operation_token, $action, $params, &$result, $trace_id, $plan = [], $execution_permit = '' ) {
		$conversation_id = sanitize_text_field( (string) $conversation_id );

		if ( '' === $conversation_id ) {
			return;
		}

		$payloads = self::build_conversation_action_event_payloads(
			$conversation_id,
			$pending_operation_token,
			$action,
			$params,
			$result,
			$trace_id,
			is_array( $plan ) ? $plan : [],
			$execution_permit
		);

		if ( empty( $payloads ) ) {
			return;
		}

		$client = YoRo_AI_Provider_Resolver::get_client();

		foreach ( $payloads as $payload ) {
			$response = $client->record_action_execution( $payload );
			if ( self::is_multi_action_execution_result( $result ) ) {
				$position = 0;
				foreach ( $result['actions'] as $key => $step ) {
					$index = $position++;
					if ( ! is_array( $step ) || ( $step['action'] ?? '' ) !== $payload['action']
						|| self::operation_id_for_step( $step, $index, $plan ) !== $payload['operation_id'] ) {
						continue;
					}
					$result['actions'][ $key ]['result'] = YoRo_REST_Conversation_Action_Coordinator::apply_report_presentation(
						is_array( $step['result'] ?? null ) ? $step['result'] : [],
						is_wp_error( $response ) ? [] : $response, $conversation_id, $payload['action'], $payload['operation_id']
					);
					break;
				}
			} else {
				$result = YoRo_REST_Conversation_Action_Coordinator::apply_report_presentation(
					$result, is_wp_error( $response ) ? [] : $response, $conversation_id, $payload['action'], $payload['operation_id']
				);
			}
		}
	}

	private static function build_conversation_action_event_payloads( $conversation_id, $pending_operation_token, $action, $params, $result, $trace_id, $plan, $execution_permit = '' ) {
		$result = is_array( $result ) ? $result : [];

		if ( empty( $result ) || ! empty( $result['preview'] ) || ! empty( $result['requires_confirmation'] ) ) {
			return [];
		}

		if ( self::is_multi_action_execution_result( $result ) ) {
			$payloads = [];
			$steps    = array_values( $result['actions'] );

			foreach ( $steps as $index => $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}

				$step_action = sanitize_text_field( (string) ( $step['action'] ?? '' ) );

				if ( '' === $step_action ) {
					continue;
				}

				$step_result = isset( $step['result'] ) && is_array( $step['result'] )
					? $step['result']
					: [];
				$status = self::action_event_status_for_step( $step, $step_result );

				$payloads[] = self::build_action_event_payload(
					$conversation_id,
					$pending_operation_token,
					$step_action,
					isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
					$step_result,
					$status,
					self::summary_for_action_event( $step_result, $step, $step_action ),
					self::operation_id_for_step( $step, $index, $plan ),
					self::trace_id_for_step( $trace_id, $index ),
					self::user_content_slots_for_step( $step, $index, $plan ),
					$execution_permit
				);
			}

			return $payloads;
		}

		$summary = is_array( $result )
			? sanitize_text_field( $result['message'] ?? ( $result['summary'] ?? '' ) )
			: '';
		$status = self::action_event_status_for_result( $result );

		return [
			self::build_action_event_payload(
				$conversation_id,
				$pending_operation_token,
				$action,
				is_array( $params ) ? $params : [],
				$result,
				$status,
				$summary,
				self::operation_id_for_single_action( $plan ),
				$trace_id,
				self::user_content_slots_for_single_action( $plan ),
				$execution_permit
			),
		];
	}

	private static function is_multi_action_execution_result( $result ) {
		return is_array( $result )
			&& ! empty( $result['multi_action'] )
			&& ! empty( $result['actions'] )
			&& is_array( $result['actions'] );
	}

	private static function build_action_event_payload( $conversation_id, $pending_operation_token, $action, $params, $result, $status, $summary, $operation_id, $trace_id, $user_content_slots = [], $execution_permit = '' ) {
		$status = sanitize_key( (string) $status );

		if ( ! in_array( $status, [ 'executed', 'failed' ], true ) ) {
			$status = 'executed';
		}

		return [
			'conversation_id'         => sanitize_text_field( (string) $conversation_id ),
			'pending_operation_token' => sanitize_text_field( (string) $pending_operation_token ),
			'execution_permit'        => sanitize_text_field( (string) $execution_permit ),
			'event_type'              => $status,
			'status'                  => $status,
			'action'                  => sanitize_text_field( (string) $action ),
			'operation_id'            => sanitize_text_field( (string) $operation_id ),
			'summary'                 => sanitize_text_field( (string) $summary ),
			'params'                  => is_array( $params ) ? $params : [],
			'result'                  => is_array( $result )
				? YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, $action )
				: [],
			'user_content_slots'      => self::normalize_user_content_slots_for_event( $user_content_slots ),
			'source'                  => 'plugin',
			'trace_id'                => sanitize_text_field( (string) $trace_id ),
		];
	}

	private static function user_content_slots_for_step( $step, $index, $plan ) {
		$step = is_array( $step ) ? $step : [];
		$plan = is_array( $plan ) ? $plan : [];
		$slots = self::user_content_slots_from_container( $step );

		if ( ! empty( $slots ) ) {
			return $slots;
		}

		$plan_steps = isset( $plan['steps'] ) && is_array( $plan['steps'] ) ? array_values( $plan['steps'] ) : [];
		$slots = self::user_content_slots_from_container( $plan_steps[ $index ] ?? [] );

		if ( ! empty( $slots ) ) {
			return $slots;
		}

		$parsed = isset( $plan['parsed'] ) && is_array( $plan['parsed'] ) ? $plan['parsed'] : [];
		$action_plan = isset( $parsed['action_plan'] ) && is_array( $parsed['action_plan'] ) ? $parsed['action_plan'] : [];
		$action_plan_steps = isset( $action_plan['steps'] ) && is_array( $action_plan['steps'] ) ? array_values( $action_plan['steps'] ) : [];
		$slots = self::user_content_slots_from_container( $action_plan_steps[ $index ] ?? [] );

		if ( ! empty( $slots ) ) {
			return $slots;
		}

		$operation_id = self::operation_id_for_step( $step, $index, $plan );
		$operation_graph = isset( $parsed['operation_graph'] ) && is_array( $parsed['operation_graph'] ) ? $parsed['operation_graph'] : [];
		$slots = self::user_content_slots_from_operations( $operation_graph['operations'] ?? [], $operation_id, $index );

		if ( ! empty( $slots ) ) {
			return $slots;
		}

		return self::user_content_slots_from_operations( $parsed['requested_operations'] ?? [], $operation_id, $index );
	}

	private static function user_content_slots_for_single_action( $plan ) {
		$plan = is_array( $plan ) ? $plan : [];
		$slots = self::user_content_slots_from_container( $plan );

		if ( ! empty( $slots ) ) {
			return $slots;
		}

		$parsed = isset( $plan['parsed'] ) && is_array( $plan['parsed'] ) ? $plan['parsed'] : [];
		$action_plan = isset( $parsed['action_plan'] ) && is_array( $parsed['action_plan'] ) ? $parsed['action_plan'] : [];
		$action_plan_steps = isset( $action_plan['steps'] ) && is_array( $action_plan['steps'] ) ? array_values( $action_plan['steps'] ) : [];
		$slots = self::user_content_slots_from_container( $action_plan_steps[0] ?? [] );

		if ( ! empty( $slots ) ) {
			return $slots;
		}

		$operation_id = self::operation_id_for_single_action( $plan );
		$operation_graph = isset( $parsed['operation_graph'] ) && is_array( $parsed['operation_graph'] ) ? $parsed['operation_graph'] : [];
		$slots = self::user_content_slots_from_operations( $operation_graph['operations'] ?? [], $operation_id, 0 );

		if ( ! empty( $slots ) ) {
			return $slots;
		}

		return self::user_content_slots_from_operations( $parsed['requested_operations'] ?? [], $operation_id, 0 );
	}

	private static function user_content_slots_from_container( $container ) {
		$container = is_array( $container ) ? $container : [];

		if ( ! empty( $container['user_content_slots'] ) && is_array( $container['user_content_slots'] ) ) {
			return $container['user_content_slots'];
		}

		$meta = isset( $container['meta'] ) && is_array( $container['meta'] ) ? $container['meta'] : [];

		return ! empty( $meta['user_content_slots'] ) && is_array( $meta['user_content_slots'] )
			? $meta['user_content_slots']
			: [];
	}

	private static function user_content_slots_from_operations( $operations, $operation_id, $index ) {
		$operations = is_array( $operations ) ? array_values( $operations ) : [];
		$operation_id = sanitize_text_field( (string) $operation_id );

		if ( '' !== $operation_id ) {
			foreach ( $operations as $operation ) {
				if ( ! is_array( $operation ) ) {
					continue;
				}

				if ( sanitize_text_field( (string) ( $operation['id'] ?? '' ) ) !== $operation_id ) {
					continue;
				}

				return self::user_content_slots_from_container( $operation );
			}
		}

		return self::user_content_slots_from_container( $operations[ $index ] ?? [] );
	}

	private static function normalize_user_content_slots_for_event( $slots ) {
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
				'source_span' => self::normalize_user_content_source_span_for_event( $slot['source_span'] ?? [] ),
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

	private static function normalize_user_content_source_span_for_event( $source_span ) {
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

	private static function action_event_status_for_step( $step, $result ) {
		$step   = is_array( $step ) ? $step : [];
		$result = is_array( $result ) ? $result : [];

		if ( ! empty( $step['failed'] ) || ( array_key_exists( 'success', $result ) && empty( $result['success'] ) ) ) {
			return 'failed';
		}

		return 'executed';
	}

	private static function action_event_status_for_result( $result ) {
		$result = is_array( $result ) ? $result : [];

		if ( array_key_exists( 'success', $result ) && empty( $result['success'] ) ) {
			return 'failed';
		}

		return 'executed';
	}

	private static function summary_for_action_event( $result, $step, $fallback_action ) {
		$result = is_array( $result ) ? $result : [];
		$step   = is_array( $step ) ? $step : [];

		return sanitize_text_field(
			$result['message']
				?? ( $result['summary'] ?? ( $step['label'] ?? $fallback_action ) )
		);
	}

	private static function operation_id_for_step( $step, $index, $plan ) {
		$step = is_array( $step ) ? $step : [];
		$plan = is_array( $plan ) ? $plan : [];

		$operation_id = sanitize_text_field( (string) ( $step['id'] ?? '' ) );

		if ( '' !== $operation_id ) {
			return $operation_id;
		}

		$operation_id = self::operation_id_from_plan_steps( $plan['steps'] ?? [], $index );

		if ( '' !== $operation_id ) {
			return $operation_id;
		}

		$parsed = isset( $plan['parsed'] ) && is_array( $plan['parsed'] ) ? $plan['parsed'] : [];
		$action_plan = isset( $parsed['action_plan'] ) && is_array( $parsed['action_plan'] ) ? $parsed['action_plan'] : [];

		$operation_id = self::operation_id_from_plan_steps( $action_plan['steps'] ?? [], $index );

		if ( '' !== $operation_id ) {
			return $operation_id;
		}

		$operation_graph = isset( $parsed['operation_graph'] ) && is_array( $parsed['operation_graph'] ) ? $parsed['operation_graph'] : [];

		$operation_id = self::operation_id_from_graph_operations( $operation_graph['operations'] ?? [], $index );

		if ( '' !== $operation_id ) {
			return $operation_id;
		}

		return 'step_' . ( absint( $index ) + 1 );
	}

	private static function operation_id_for_single_action( $plan ) {
		$plan = is_array( $plan ) ? $plan : [];
		$parsed = isset( $plan['parsed'] ) && is_array( $plan['parsed'] ) ? $plan['parsed'] : [];
		$action_plan = isset( $parsed['action_plan'] ) && is_array( $parsed['action_plan'] ) ? $parsed['action_plan'] : [];
		$operation_graph = isset( $parsed['operation_graph'] ) && is_array( $parsed['operation_graph'] ) ? $parsed['operation_graph'] : [];

		foreach (
			[
				self::operation_id_from_plan_steps( $plan['steps'] ?? [], 0 ),
				self::operation_id_from_plan_steps( $action_plan['steps'] ?? [], 0 ),
				self::operation_id_from_graph_operations( $operation_graph['operations'] ?? [], 0 ),
			] as $operation_id
		) {
			if ( '' !== $operation_id ) {
				return $operation_id;
			}
		}

		return '';
	}

	private static function operation_id_from_plan_steps( $steps, $index ) {
		$steps = is_array( $steps ) ? array_values( $steps ) : [];
		$step  = isset( $steps[ $index ] ) && is_array( $steps[ $index ] ) ? $steps[ $index ] : [];

		return sanitize_text_field( (string) ( $step['id'] ?? '' ) );
	}

	private static function operation_id_from_graph_operations( $operations, $index ) {
		$operations = is_array( $operations ) ? array_values( $operations ) : [];
		$operation  = isset( $operations[ $index ] ) && is_array( $operations[ $index ] ) ? $operations[ $index ] : [];

		return sanitize_text_field( (string) ( $operation['id'] ?? '' ) );
	}

	private static function trace_id_for_step( $trace_id, $index ) {
		$trace_id = sanitize_text_field( (string) $trace_id );

		if ( '' === $trace_id ) {
			return '';
		}

		return $trace_id . '_step_' . ( absint( $index ) + 1 );
	}
}
