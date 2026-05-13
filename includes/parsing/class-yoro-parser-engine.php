<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Parser_Engine {

	public static function parse( $command, $client_context = [] ) {
		$command = is_string( $command ) ? trim( $command ) : '';

		if ( '' === $command ) {
			return new WP_Error(
				'yoro_empty_command',
				__( 'Command cannot be empty.', 'yoro-agent-core' )
			);
		}

		$normalized = self::normalize( $command );

		if ( class_exists( 'YoRo_Memory_Context_Resolver' ) ) {
			$client_context = YoRo_Memory_Context_Resolver::resolve( $command, $client_context );
		}

		$priority_workflow_continue = YoRo_Email_Command_Parser::parse_priority_workflow_continue(
			$command,
			$normalized,
			$client_context
		);

		if ( is_array( $priority_workflow_continue ) ) {
			if (
				! empty( $priority_workflow_continue['stage'] ) &&
				'clarify' === $priority_workflow_continue['stage']
			) {
				return $priority_workflow_continue;
			}

			$normalized_result = self::normalize_parsed_result(
				$priority_workflow_continue,
				$command,
				$normalized,
				$client_context
			);

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		$phrase_order_ids = YoRo_Entity_Phrase_Resolver::resolve( $command, $client_context );

		if ( ! empty( $phrase_order_ids ) ) {
			$client_context['resolved_order_ids'] = $phrase_order_ids;
		}

		$ai_router_result = self::parse_with_ai_command_router( $command, $normalized, $client_context );

		if ( is_array( $ai_router_result ) ) {
			return $ai_router_result;
		}

		$conversational = self::parse_conversational_only( $command, $normalized, $client_context );

		if ( is_array( $conversational ) ) {
			return $conversational;
		}

		/*
		* 1. Fallback to deterministic rules only when the AI command router is unavailable.
		*
		* Important:
		* Do this BEFORE generic ambiguity detection.
		* Commands like "Change this order status" are incomplete,
		* but they are not unknown. Rule parser should be allowed
		* to start a collect_missing_params workflow.
		*/
		$rule_result = self::parse_with_rules( $command, $normalized, $client_context );

		if (
			is_array( $rule_result ) &&
			! empty( $rule_result['stage'] ) &&
			'clarify' === $rule_result['stage'] &&
			! empty( $rule_result['action'] )
		) {
			return $rule_result;
		}

		if ( self::should_prioritize_strong_read_only_rule_result( $rule_result ) ) {
			return $rule_result;
		}

		if ( self::should_prioritize_strong_confirmation_gated_write_rule_result( $rule_result ) ) {
			return $rule_result;
		}

		/*
		* 2. Query-action pattern.
		*
		* This must run before we accept a high-confidence generic rule result,
		* otherwise imperative commands like "Hold latest 5 orders" get swallowed
		* by search-style parsers and lose their write intent.
		*/
		$query_action = self::detect_query_action_pattern( $command, $normalized );

		if ( $query_action ) {
			$normalized_result = self::normalize_parsed_result(
				$query_action,
				$command,
				$normalized,
				$client_context
			);

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		$hardening_result = self::parse_with_hardening_guards( $command, $normalized, $client_context );

		if ( is_array( $hardening_result ) ) {
			return $hardening_result;
		}

		if ( self::is_high_confidence_result( $rule_result ) ) {
			return $rule_result;
		}

		/*
		* 3. Generic ambiguity guard.
		*
		* Only run this after rule/query parsers had a chance.
		*/
		if ( self::is_too_ambiguous( $normalized ) ) {
			return self::clarification_response(
				$command,
				$normalized,
				__( 'I need more detail before running this. What would you like me to show or manage?', 'yoro-agent-core' )
			);
		}

		/*
		* Lightweight fallback:
		* "latest 5" / "recent 5" means latest WooCommerce orders.
		*/
		if ( preg_match( '/^(latest|recent)\s+(\d+)$/i', $normalized, $m ) ) {
			$limit = min( max( absint( $m[2] ), 1 ), 50 );

			$latest_orders_result = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'orders.search',
				'params'             => [
					'limit' => $limit,
				],
				'parser_rule'        => 'rule.query.orders.latest_fallback',
				'parser_source'      => 'rule',
				'confidence'         => 0.7,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];

			$normalized_result = self::normalize_parsed_result(
				$latest_orders_result,
				$command,
				$normalized,
				$client_context
			);

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		$incomplete_intent = self::detect_incomplete_intent( $command, $normalized );

		if ( $incomplete_intent ) {
			$normalized_result = self::normalize_parsed_result(
				$incomplete_intent,
				$command,
				$normalized,
				$client_context
			);

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		/*
		* 4. If a rule parser matched but was not high confidence, return it.
		*/
		if ( is_array( $rule_result ) && ! empty( $rule_result['matched'] ) ) {
			return $rule_result;
		}

		return self::clarification_response(
			$command,
			$normalized,
			__( 'I need a little more detail. What would you like me to do?', 'yoro-agent-core' )
		);
	}

	private static function parse_with_ai_command_router( $command, $normalized, $client_context = [] ) {
		if ( 'yes' !== get_option( 'yoro_ai_command_router_enabled', 'yes' ) ) {
			return null;
		}

		if ( ! class_exists( 'YoRo_AI_Command_Router' ) ) {
			return null;
		}

		$decision = YoRo_AI_Command_Router::route( $command, $client_context );

		if ( is_wp_error( $decision ) ) {
			$error_code = $decision->get_error_code();

			if (
				in_array(
					$error_code,
					[
						'yoro_ai_missing_api_key',
						'yoro_ai_intent_api_error',
					],
					true
				)
			) {
				return null;
			}

			return self::build_ai_command_router_clarification_response(
				$command,
				$normalized,
				[
					'mode'                  => 'clarify',
					'action'                => '',
					'confidence'            => 0,
					'missing'               => [],
					'ambiguities'           => [],
					'clarification_question' => $decision->get_error_message(),
				],
				__( 'I could not safely map this command to an available action. Please rephrase it with the target and desired action.', 'yoro-agent-core' ),
				0
			);
		}

		if ( ! is_array( $decision ) ) {
			return null;
		}

		$mode       = sanitize_key( $decision['mode'] ?? 'clarify' );
		$confidence = isset( $decision['confidence'] ) ? (float) $decision['confidence'] : 0;

		if ( 'task_request' !== $mode ) {
			return self::build_ai_command_router_non_action_response(
				$command,
				$normalized,
				$decision,
				$mode,
				$confidence
			);
		}

		$action = sanitize_text_field( $decision['action'] ?? '' );

		if ( '' === $action ) {
			return self::build_ai_command_router_clarification_response(
				$command,
				$normalized,
				$decision,
				__( 'I understood this as a store task, but I need to know which action you want me to take.', 'yoro-agent-core' ),
				$confidence
			);
		}

		$missing = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];

		if ( ! empty( $missing ) ) {
			return self::build_ai_command_router_clarification_response(
				$command,
				$normalized,
				$decision,
				$decision['clarification_question'] ?: __( 'I need a little more detail before I can run that action.', 'yoro-agent-core' ),
				$confidence
			);
		}

		$threshold = self::get_ai_command_router_confidence_threshold( $decision );

		if ( $confidence < $threshold ) {
			return self::build_ai_command_router_clarification_response(
				$command,
				$normalized,
				$decision,
				$decision['clarification_question'] ?: __( 'I am not confident enough to run this yet. What did you mean?', 'yoro-agent-core' ),
				$confidence
			);
		}

		$parsed = [
			'success'            => true,
			'matched'            => true,
			'action'             => $action,
			'params'             => isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [],
			'parser_rule'        => 'ai.command_router.v2',
			'parser_source'      => 'ai_router',
			'confidence'         => $confidence,
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'language'           => sanitize_text_field( $decision['language'] ?? 'unknown' ),
			'summary'            => sanitize_text_field( $decision['summary'] ?? '' ),
			'reasoning_summary'  => sanitize_text_field( $decision['reasoning_summary'] ?? '' ),
			'meta'               => isset( $decision['meta'] ) && is_array( $decision['meta'] ) ? $decision['meta'] : [],
			'command_decision'   => self::compact_ai_command_decision_for_result( $decision ),
		];

		$normalized_result = self::normalize_parsed_result(
			$parsed,
			$command,
			$normalized,
			$client_context
		);

		$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
		$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );
		$resolved_result = self::correct_ai_hold_to_update_status( $resolved_result, $normalized );
		$resolved_result = self::correct_ai_update_status_with_note_to_bulk_action( $resolved_result );

		return YoRo_Entity_Validator::validate( $resolved_result );
	}

	private static function build_ai_command_router_non_action_response( $command, $normalized, $decision, $mode, $confidence ) {
		if ( 'clarify' === $mode ) {
			return self::build_ai_command_router_clarification_response(
				$command,
				$normalized,
				$decision,
				$decision['clarification_question'] ?: ( $decision['summary'] ?: __( 'I need a little more detail before I act on that.', 'yoro-agent-core' ) ),
				$confidence
			);
		}

		$summary = sanitize_text_field( $decision['summary'] ?? '' );

		if ( '' === $summary ) {
			if ( 'unsupported_request' === $mode ) {
				$summary = __( 'YoRo cannot do that yet.', 'yoro-agent-core' );
			} elseif ( 'out_of_scope' === $mode ) {
				$summary = __( 'I am focused on WooCommerce store operations, so I cannot help with that topic here.', 'yoro-agent-core' );
			} else {
				$summary = __( 'I can help with WooCommerce store operations.', 'yoro-agent-core' );
			}
		}

		return self::build_ai_command_router_assistant_response(
			$command,
			$normalized,
			'ai.command_router.' . $mode,
			$summary,
			sanitize_text_field( $decision['clarification_question'] ?? '' ),
			isset( $decision['suggestions'] ) && is_array( $decision['suggestions'] ) ? $decision['suggestions'] : [],
			[
				'conversation_topic' => $mode,
				'result_type'        => 'assistant_message',
				'language'           => sanitize_text_field( $decision['language'] ?? 'unknown' ),
				'reasoning_summary'  => sanitize_text_field( $decision['reasoning_summary'] ?? '' ),
				'command_decision'   => self::compact_ai_command_decision_for_result( $decision ),
			],
			$confidence
		);
	}

	private static function build_ai_command_router_clarification_response( $command, $normalized, $decision, $message, $confidence ) {
		$suggestions = isset( $decision['suggestions'] ) && is_array( $decision['suggestions'] )
			? $decision['suggestions']
			: [];
		$current_workflow = self::build_pending_missing_params_workflow_from_decision(
			$command,
			$decision
		);

		$response = [
			'success'            => false,
			'matched'            => false,
			'stage'              => 'clarify',
			'message'            => sanitize_text_field( $message ),
			'options'            => ! empty( $suggestions ) ? $suggestions : self::get_clarification_options( $normalized ),
			'parser_rule'        => 'ai.command_router.clarify',
			'parser_source'      => 'ai_router',
			'confidence'         => $confidence,
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'language'           => sanitize_text_field( $decision['language'] ?? 'unknown' ),
			'summary'            => sanitize_text_field( $decision['summary'] ?? '' ),
			'reasoning_summary'  => sanitize_text_field( $decision['reasoning_summary'] ?? '' ),
			'command_decision'   => self::compact_ai_command_decision_for_result( $decision ),
		];

		if ( ! empty( $current_workflow ) ) {
			$response['current_workflow'] = $current_workflow;
		}

		return $response;
	}

	private static function build_pending_missing_params_workflow_from_decision( $command, $decision ) {
		$decision = is_array( $decision ) ? $decision : [];
		$action = sanitize_text_field( $decision['action'] ?? '' );
		$missing = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];

		if ( '' === $action || empty( $missing ) ) {
			$params = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
			$missing = self::infer_missing_params_for_pending_workflow( $action, $params, $missing );

			if ( '' === $action || empty( $missing ) ) {
				return [];
			}
		}

		$params = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$missing = self::infer_missing_params_for_pending_workflow( $action, $params, $missing );
		$workflow_type = 'generic';
		$workflow_params = [];
		$order_id = 0;

		switch ( $action ) {
			case 'orders.compose_customer_email':
				$order_id = self::extract_pending_workflow_order_id( $params );

				if ( ! $order_id || ! in_array( 'instruction', $missing, true ) ) {
					return [];
				}

				$workflow_type = 'customer_email';
				$workflow_params = [
					'order_id' => $order_id,
				];
				break;

			case 'orders.add_note':
				$order_ids = self::extract_pending_workflow_order_ids( $params );

				if ( empty( $order_ids ) || ! in_array( 'note', $missing, true ) ) {
					return [];
				}

				$order_id = absint( reset( $order_ids ) );
				$workflow_params = [
					'order_ids' => $order_ids,
				];
				break;

			case 'orders.update_status':
				$order_ids = self::extract_pending_workflow_order_ids( $params );

				if ( empty( $order_ids ) || ! in_array( 'status', $missing, true ) ) {
					return [];
				}

				$order_id = absint( reset( $order_ids ) );
				$workflow_params = [
					'order_ids' => $order_ids,
				];
				break;

			default:
				return [];
		}

		if ( empty( $workflow_params ) ) {
			return [];
		}

		return [
			'id'           => 'wf_' . wp_generate_uuid4(),
			'type'         => $workflow_type,
			'action'       => $action,
			'step'         => 'collect_missing_params',
			'order_id'     => $order_id,
			'params'       => $workflow_params,
			'missing'      => $missing,
			'last_command' => sanitize_textarea_field( $command ),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		];
	}

	private static function infer_missing_params_for_pending_workflow( $action, $params, $missing ) {
		$action = sanitize_text_field( $action );
		$params = is_array( $params ) ? $params : [];
		$missing = is_array( $missing )
			? array_values( array_filter( array_map( 'sanitize_key', $missing ) ) )
			: [];

		if ( 'orders.compose_customer_email' === $action ) {
			$missing = array_diff( $missing, [ 'body', 'email_body', 'message', 'topic' ] );

			if ( empty( $params['instruction'] ) ) {
				$missing[] = 'instruction';
			}
		}

		if ( 'orders.add_note' === $action ) {
			$missing = array_diff( $missing, [ 'note_text', 'text', 'content', 'message' ] );

			if ( empty( $params['note'] ) ) {
				$missing[] = 'note';
			}
		}

		if ( 'orders.update_status' === $action ) {
			$missing = array_diff( $missing, [ 'order_status', 'new_status' ] );

			if ( empty( $params['status'] ) ) {
				$missing[] = 'status';
			}
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $missing )
				)
			)
		);
	}

	private static function extract_pending_workflow_order_id( $params ) {
		$params = is_array( $params ) ? $params : [];

		if ( ! empty( $params['order_id'] ) ) {
			return absint( $params['order_id'] );
		}

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			return absint( reset( $params['order_ids'] ) );
		}

		return 0;
	}

	private static function extract_pending_workflow_order_ids( $params ) {
		$params = is_array( $params ) ? $params : [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			return array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) );
		}

		if ( ! empty( $params['order_id'] ) ) {
			return [ absint( $params['order_id'] ) ];
		}

		return [];
	}

	private static function build_ai_command_router_assistant_response( $command, $normalized, $parser_rule, $summary, $followup = '', $suggestions = [], $extra_result = [], $confidence = 1.0 ) {
		$topic    = is_array( $extra_result ) ? sanitize_key( $extra_result['conversation_topic'] ?? '' ) : '';
		$summary  = self::normalize_assistant_response_copy( $summary, $topic );
		$followup = self::normalize_assistant_response_copy( $followup, $topic );

		$result = array_merge(
			[
				'display_type'       => 'assistant_message',
				'result_type'        => 'assistant_message',
				'summary'            => $summary,
				'assistant_followup' => $followup,
				'suggestions'        => array_slice( self::dedupe_assistant_suggestions( $suggestions ), 0, 3 ),
			],
			is_array( $extra_result ) ? $extra_result : []
		);

		return [
			'success'            => true,
			'matched'            => true,
			'response_only'      => true,
			'parser_rule'        => $parser_rule,
			'parser_source'      => 'ai_router',
			'confidence'         => $confidence,
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'result'             => $result,
		];
	}

	private static function compact_ai_command_decision_for_result( $decision ) {
		return [
			'mode'                  => sanitize_key( $decision['mode'] ?? '' ),
			'action'                => sanitize_text_field( $decision['action'] ?? '' ),
			'confidence'            => isset( $decision['confidence'] ) ? (float) $decision['confidence'] : 0,
			'missing'               => isset( $decision['missing'] ) && is_array( $decision['missing'] ) ? array_values( array_map( 'sanitize_key', $decision['missing'] ) ) : [],
			'ambiguities'           => isset( $decision['ambiguities'] ) && is_array( $decision['ambiguities'] ) ? array_values( array_map( 'sanitize_text_field', $decision['ambiguities'] ) ) : [],
			'clarification_question' => sanitize_text_field( $decision['clarification_question'] ?? '' ),
		];
	}

	private static function get_ai_command_router_confidence_threshold( $decision ) {
		if (
			! empty( $decision['action_catalog_item'] ) &&
			is_array( $decision['action_catalog_item'] ) &&
			empty( $decision['action_catalog_item']['write_action'] ) &&
			empty( $decision['action_catalog_item']['requires_confirmation'] )
		) {
			return YoRo_AI_Parser::get_read_only_confidence_threshold();
		}

		return YoRo_AI_Parser::get_confidence_threshold();
	}

	private static function correct_ai_update_status_with_note_to_bulk_action( $result ) {
		if ( ! is_array( $result ) || 'orders.update_status' !== ( $result['action'] ?? '' ) ) {
			return $result;
		}

		$note = isset( $result['params']['note'] ) ? trim( sanitize_text_field( $result['params']['note'] ) ) : '';

		if ( '' === $note || __( 'Order status updated by YoRo Agent.', 'yoro-agent-core' ) === $note ) {
			return $result;
		}

		$result['action'] = 'orders.bulk_update_status_with_note';

		if ( isset( $result['intent'] ) && is_array( $result['intent'] ) ) {
			$result['intent']['name'] = 'orders_bulk_update_status_with_note';
			$result['intent']['action'] = 'orders.bulk_update_status_with_note';
		}

		return $result;
	}

	private static function parse_with_hardening_guards( $command, $normalized, $client_context = [] ) {
		$grounded_last_orders_reference = self::build_grounded_last_orders_reference_response( $command, $normalized, $client_context );

		if ( is_array( $grounded_last_orders_reference ) ) {
			return $grounded_last_orders_reference;
		}

		if ( self::is_email_template_list_request( $normalized ) ) {
			$result = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'email_templates.list',
				'params'             => [],
				'parser_rule'        => 'guard.email_templates.list',
				'parser_source'      => 'guard',
				'confidence'         => 0.97,
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'intent'             => [
					'name'       => 'email_templates_list',
					'confidence' => 0.97,
					'source'     => 'guard',
				],
			];

			$normalized_result = self::normalize_parsed_result(
				$result,
				$command,
				$normalized,
				$client_context
			);

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		$explicit_order_write = self::build_explicit_order_write_guard_response( $command, $normalized, $client_context );

		if ( is_array( $explicit_order_write ) ) {
			return $explicit_order_write;
		}

		$explicit_capability_gap = self::build_explicit_capability_gap_response( $command, $normalized, $client_context );

		if ( is_array( $explicit_capability_gap ) ) {
			return $explicit_capability_gap;
		}

		if ( self::is_unsupported_analytics_request( $normalized ) ) {
			return self::clarification_response(
				$command,
				$normalized,
				__( 'I can’t answer that analytics question directly yet. I can help with store summary, operations, attention, fulfillment, inventory, or customer follow-up.', 'yoro-agent-core' )
			);
		}

		if ( self::is_aged_pending_orders_request( $normalized ) ) {
			$result = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'operations.get_attention_queue',
				'params'             => [
					'limit' => 5,
				],
				'parser_rule'        => 'guard.operations_attention.aged_pending_orders',
				'parser_source'      => 'guard',
				'confidence'         => 0.96,
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'intent'             => [
					'name'       => 'operations_attention',
					'confidence' => 0.96,
					'source'     => 'guard',
				],
			];

			$normalized_result = self::normalize_parsed_result(
				$result,
				$command,
				$normalized,
				$client_context
			);

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		if ( self::is_general_what_should_we_do_request( $normalized ) ) {
			$result = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'operations.get_summary',
				'params'             => [
					'window' => self::detect_window_for_retrieval( $normalized, 'today' ),
					'focus'  => 'overview',
				],
				'parser_rule'        => 'guard.operations_overview.general_next_step',
				'parser_source'      => 'guard',
				'confidence'         => 0.95,
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'intent'             => [
					'name'       => 'operations_overview',
					'confidence' => 0.95,
					'source'     => 'guard',
				],
			];

			$normalized_result = self::normalize_parsed_result(
				$result,
				$command,
				$normalized,
				$client_context
			);

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		if ( self::is_operations_overview_today_request( $normalized ) ) {
			$result = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'operations.get_summary',
				'params'             => [
					'window' => 'today',
					'focus'  => 'overview',
				],
				'parser_rule'        => 'guard.operations_overview.today_overview',
				'parser_source'      => 'guard',
				'confidence'         => 0.96,
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'intent'             => [
					'name'       => 'operations_overview',
					'confidence' => 0.96,
					'source'     => 'guard',
				],
			];

			$normalized_result = self::normalize_parsed_result(
				$result,
				$command,
				$normalized,
				$client_context
			);

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		if ( self::is_store_update_request( $normalized ) ) {
			$result = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'operations.get_summary',
				'params'             => [
					'window' => self::detect_window_for_retrieval( $normalized, 'last_7_days' ),
					'focus'  => 'overview',
				],
				'parser_rule'        => 'guard.operations_overview.store_update',
				'parser_source'      => 'guard',
				'confidence'         => 0.95,
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'intent'             => [
					'name'       => 'operations_overview',
					'confidence' => 0.95,
					'source'     => 'guard',
				],
			];

			$normalized_result = self::normalize_parsed_result(
				$result,
				$command,
				$normalized,
				$client_context
			);

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		return null;
	}

	private static function should_try_ai_read_only_action_before_rules( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		if ( self::is_overview_or_queue_style_read_only_query( $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:daily briefing|daily brief|weekly briefing|weekly brief|monthly briefing|monthly brief|business summary|business overview|store insights|insights|inventory queue|stock queue|attention queue|fulfillment queue|shipping queue|customer support queue|customer service queue|operations summary|operations overview)\b/i', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:customer\s+(?:profile|summary|record|overview)|customer\s+orders?|latest\s+\d*\s*orders?|recent\s+\d*\s*orders?|pending\s+orders?|processing\s+orders?|completed\s+orders?|on[- ]hold\s+orders?)\b/i', $normalized ) ) {
			return true;
		}

		return (bool) preg_match(
			'/\b(?:show|open|view|get|give|load|pull|bring|list|find|search|browse|review)\b.*\b(?:order|orders|customer|customers|profile|summary|queue|briefing|brief|insight|insights|templates|drafts)\b/i',
			$normalized
		);
	}

	private static function should_try_ai_supported_action_before_rules( $normalized ) {
		if ( self::should_try_ai_read_only_action_before_rules( $normalized ) ) {
			return true;
		}

		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		if ( preg_match( '/\b(?:add\s+(?:a\s+)?)?note\b.*\border\b/i', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:change|mark|move|set|update)\b.*\border(?:s)?\b.*\b(?:to|as|status)\b/i', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:hold|on hold)\b.*\border(?:s)?\b/i', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:compose|write|draft|prepare)\b.*\bemail\b/i', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:send)\b.*\bemail\b/i', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:save|delete|remove)\b.*\b(?:draft|template)\b/i', $normalized ) ) {
			return true;
		}

		return false;
	}

	private static function is_ai_inferred_read_only_supported_result( $result ) {
		if ( ! is_array( $result ) || empty( $result['action'] ) ) {
			return false;
		}

		if ( 'ai.inferred_supported_action' !== sanitize_text_field( $result['parser_rule'] ?? '' ) ) {
			return false;
		}

		return self::is_ai_inferred_read_only_action_allowed( sanitize_text_field( $result['action'] ) );
	}

	private static function is_ai_inferred_supported_result( $result ) {
		if ( ! is_array( $result ) || empty( $result['action'] ) ) {
			return false;
		}

		if ( 'ai.inferred_supported_action' !== sanitize_text_field( $result['parser_rule'] ?? '' ) ) {
			return false;
		}

		$action = sanitize_text_field( $result['action'] );

		return self::is_ai_inferred_read_only_action_allowed( $action ) || self::is_ai_inferred_confirmation_gated_write_action_allowed( $action );
	}

	private static function build_grounded_last_orders_reference_response( $command, $normalized, $client_context = [] ) {
		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? $client_context['last_orders']
			: [];
		$last_order_ids = isset( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] )
			? array_values( array_filter( array_map( 'absint', $client_context['last_order_ids'] ) ) )
			: [];

		$count = ! empty( $last_orders ) ? count( $last_orders ) : count( $last_order_ids );

		if ( $count <= 0 ) {
			return null;
		}

		if (
			false !== strpos( $normalized, 'status' ) ||
			false !== strpos( $normalized, 'note' ) ||
			false !== strpos( $normalized, 'hold' ) ||
			false !== strpos( $normalized, 'refund' ) ||
			false !== strpos( $normalized, 'email' ) ||
			false !== strpos( $normalized, 'shipment' ) ||
			false !== strpos( $normalized, 'shipping label' ) ||
			false !== strpos( $normalized, 'customer' )
		) {
			return null;
		}

		$selector = self::resolve_grounded_last_orders_selector( $normalized );

		if ( '' === $selector ) {
			return null;
		}

		$resolved_order_ids = self::resolve_ai_reference_order_ids(
			$client_context,
			$selector,
			-1
		);

		if ( empty( $resolved_order_ids ) ) {
			return null;
		}

		$parsed = [
			'success'            => true,
			'matched'            => true,
			'action'             => 'orders.get_details',
			'params'             => [
				'order_ids' => $resolved_order_ids,
			],
			'parser_rule'        => 'guard.orders.get_details.last_orders_reference',
			'parser_source'      => 'guard',
			'confidence'         => 0.93,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];

		$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context );
		$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
		$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

		return YoRo_Entity_Validator::validate( $resolved_result );
	}

	private static function resolve_grounded_last_orders_selector( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( '' === $normalized ) {
			return '';
		}

		if ( preg_match( '/\b(?:the\s+)?first\s+one\b/i', $normalized ) ) {
			return 'first';
		}

		if ( preg_match( '/\b(?:the\s+)?second\s+one\b/i', $normalized ) ) {
			return 'second';
		}

		if ( preg_match( '/\b(?:the\s+)?third\s+one\b/i', $normalized ) ) {
			return 'third';
		}

		if ( preg_match( '/\b(?:the\s+)?last\s+one\b/i', $normalized ) ) {
			return 'last';
		}

		if ( preg_match( '/\b(?:that|this|current)\s+one\b/i', $normalized ) ) {
			return 'that';
		}

		return '';
	}

	private static function build_explicit_order_write_guard_response( $command, $normalized, $client_context = [] ) {
		if ( ! class_exists( 'YoRo_Order_Helper' ) ) {
			return null;
		}

		$order_ids = YoRo_Order_Helper::extract_order_ids_from_text( $command );

		if ( empty( $order_ids ) ) {
			return null;
		}

		$text_for_match = strtolower( trim( $command . ' ' . $normalized ) );

		$status = YoRo_Order_Helper::detect_status_from_text( $text_for_match );

		if ( empty( $status ) ) {
			$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
		}

		if ( ! empty( $status ) ) {
			$status = YoRo_Order_Helper::normalize_status_slug( $status );
		}

		if (
			! empty( $status ) &&
			YoRo_Order_Helper::is_valid_order_status( $status ) &&
			preg_match( '/\b(?:change|mark|move|set|update)\b/i', $text_for_match ) &&
			preg_match( '/\b(?:to|as|status)\b/i', $text_for_match )
		) {
			$parsed = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'orders.update_status',
				'params'             => [
					'order_ids' => array_values( array_map( 'absint', $order_ids ) ),
					'status'    => $status,
					'note'      => __( 'Order status updated by YoRo Agent.', 'yoro-agent-core' ),
				],
				'parser_rule'        => 'guard.orders.update_status.explicit_order',
				'parser_source'      => 'guard',
				'confidence'         => 0.99,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];

			$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context );
			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		if (
			preg_match( '/\b(?:hold|on hold)\b/i', $text_for_match ) &&
			! preg_match( '/\b(?:add\s+(?:a\s+)?)?note\b/i', $text_for_match )
		) {
			$parsed = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'orders.hold',
				'params'             => [
					'order_ids' => array_values( array_map( 'absint', $order_ids ) ),
				],
				'parser_rule'        => 'guard.orders.hold.explicit_order',
				'parser_source'      => 'guard',
				'confidence'         => 0.98,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];

			$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context );
			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		if (
			preg_match( '/\b(?:add\s+(?:a\s+)?)?note\b/i', $text_for_match ) &&
			! preg_match( '/\b(?:hold|on hold)\b/i', $text_for_match )
		) {
			$note = '';

			if ( preg_match( '/:\s*(.+)$/', $command, $matches ) ) {
				$note = sanitize_textarea_field( trim( $matches[1] ) );
			}

			if ( '' === $note ) {
				return [
					'success'            => false,
					'matched'            => true,
					'stage'              => 'clarify',
					'action'             => 'orders.add_note',
					'message'            => __( 'What note should I add?', 'yoro-agent-core' ),
					'params'             => [
						'order_ids' => array_values( array_map( 'absint', $order_ids ) ),
						'order_id'  => absint( reset( $order_ids ) ),
						'note'      => '',
					],
					'missing'            => [ 'note' ],
					'parser_rule'        => 'guard.orders.add_note.explicit_order',
					'parser_source'      => 'guard',
					'confidence'         => 0.98,
					'original_command'   => $command,
					'normalized_command' => $normalized,
				];
			}

			$parsed = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'orders.add_note',
				'params'             => [
					'order_ids' => array_values( array_map( 'absint', $order_ids ) ),
					'order_id'  => absint( reset( $order_ids ) ),
					'note'      => $note,
				],
				'parser_rule'        => 'guard.orders.add_note.explicit_order',
				'parser_source'      => 'guard',
				'confidence'         => 0.98,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];

			$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context );
			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		return null;
	}

	private static function detect_window_for_retrieval( $normalized, $default ) {
		if ( false !== strpos( $normalized, 'today' ) ) {
			return 'today';
		}

		if ( false !== strpos( $normalized, 'yesterday' ) ) {
			return 'yesterday';
		}

		if (
			false !== strpos( $normalized, 'last 30 days' ) ||
			false !== strpos( $normalized, 'last thirty days' ) ||
			false !== strpos( $normalized, 'this month' ) ||
			false !== strpos( $normalized, 'monthly' )
		) {
			return 'last_30_days';
		}

		if (
			false !== strpos( $normalized, 'last 7 days' ) ||
			false !== strpos( $normalized, 'last seven days' ) ||
			false !== strpos( $normalized, 'this week' ) ||
			false !== strpos( $normalized, 'weekly' )
		) {
			return 'last_7_days';
		}

		return $default;
	}

	private static function detect_limit_for_retrieval( $command, $default ) {
		$patterns = [
			'/\b(?:top|first|latest|recent|last|newest|lestet|show)\s+(\d{1,2})\b/i',
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
			'/\b(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				return min( max( absint( $matches[1] ), 1 ), 20 );
			}
		}

		return $default;
	}

	private static function detect_business_focus_for_retrieval( $normalized ) {
		if ( false !== strpos( $normalized, 'operations' ) || false !== strpos( $normalized, 'backlog' ) ) {
			return 'operations';
		}

		if ( false !== strpos( $normalized, 'customers' ) || false !== strpos( $normalized, 'customer' ) ) {
			return 'customers';
		}

		if ( false !== strpos( $normalized, 'products' ) || false !== strpos( $normalized, 'inventory' ) ) {
			return 'products';
		}

		return 'overview';
	}

	private static function detect_operations_focus_for_retrieval( $normalized ) {
		if ( false !== strpos( $normalized, 'backlog' ) || false !== strpos( $normalized, 'queue' ) ) {
			return 'backlog';
		}

		return 'overview';
	}

	private static function detect_inventory_focus_for_retrieval( $normalized ) {
		if ( false !== strpos( $normalized, 'low stock' ) ) {
			return 'low_stock';
		}

		if ( false !== strpos( $normalized, 'out of stock' ) || false !== strpos( $normalized, 'out-of-stock' ) ) {
			return 'out_of_stock';
		}

		if ( false !== strpos( $normalized, 'backorder' ) ) {
			return 'backorder';
		}

		return 'overview';
	}

	private static function is_unsupported_analytics_request( $normalized ) {
		$unsupported_markers = [
			'vip customers',
			'spent more than',
			'conversion rate',
			'best selling products',
			'best-selling products',
			'best selling product',
			'best-selling product',
		];

		foreach ( $unsupported_markers as $marker ) {
			if ( false !== strpos( $normalized, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	private static function is_aged_pending_orders_request( $normalized ) {
		if ( false === strpos( $normalized, 'pending' ) ) {
			return false;
		}

		if ( false === strpos( $normalized, 'more than' ) && false === strpos( $normalized, 'older than' ) ) {
			return false;
		}

		return false !== strpos( $normalized, 'orders' ) || false !== strpos( $normalized, 'order' );
	}

	private static function is_general_what_should_we_do_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		return (bool) preg_match(
			'/\bwhat(?:\'?s| is)?\s+(?:should\s+)?we\s+do\b.*\b(?:today|now)\b/i',
			$normalized
		);
	}

	private static function is_operations_overview_today_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		return (bool) preg_match(
			'/\bwhat(?:\'?s| is)?\s+going\s+on\b.*\b(?:today|right now|now)\b/i',
			$normalized
		);
	}

	private static function is_store_update_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		return (bool) preg_match(
			'/\b(?:store\s+update|update\s+on\s+the\s+store|update\s+for\s+the\s+store)\b/i',
			$normalized
		);
	}

	private static function is_email_template_list_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		$mentions_templates =
			false !== strpos( $normalized, 'template' ) ||
			false !== strpos( $normalized, 'templates' );

		if ( ! $mentions_templates ) {
			return false;
		}

		$has_email_context =
			false !== strpos( $normalized, 'email' ) ||
			false !== strpos( $normalized, 'reusable' ) ||
			false !== strpos( $normalized, 'saved' ) ||
			false !== strpos( $normalized, 'customer' );

		if ( ! $has_email_context ) {
			return false;
		}

		$has_list_intent =
			preg_match( '/\b(show|list|view|open|load|get|see|browse)\b/i', $normalized ) ||
			preg_match( '/\bwhat\b.*\btemplates?\b/i', $normalized ) ||
			preg_match( '/\btemplates?\b.*\b(?:available|saved|reusable)\b/i', $normalized );

		if ( ! $has_list_intent ) {
			return false;
		}

		return true;
	}

	private static function parse_with_rules( $command, $normalized, $client_context ) {
		$parsers = [
			[ 'YoRo_Orders_Command_Parser', 'parse_early' ],
			[ 'YoRo_Email_Command_Parser', 'parse' ],
			[ 'YoRo_Followup_Command_Parser', 'parse_write' ],
			[ 'YoRo_Orders_Command_Parser', 'parse_write' ],
			[ 'YoRo_Followup_Command_Parser', 'parse_read' ],
			[ 'YoRo_Orders_Command_Parser', 'parse_read' ],
			[ 'YoRo_Orders_Command_Parser', 'parse_search' ],
			[ 'YoRo_Orders_Command_Parser', 'parse_surface_fallback' ],
			[ 'YoRo_Workflow_Command_Parser', 'parse' ],
			[ 'YoRo_Memory_Command_Parser', 'parse' ],
		];

		$parsers = apply_filters(
			'yoro_parser_rules',
			$parsers,
			$command,
			$normalized,
			$client_context
		);

		foreach ( $parsers as $parser ) {
			if ( ! is_callable( $parser ) ) {
				continue;
			}

			$parsed = call_user_func( $parser, $command, $normalized, $client_context );
			
			if ( $parsed ) {
				if ( is_array( $parsed ) ) {
					$parsed['parser_source'] = $parsed['parser_source'] ?? 'rule';
					$parsed['confidence']    = $parsed['confidence'] ?? 1.0;

					if (
						! empty( $parsed['stage'] ) &&
						'clarify' === $parsed['stage']
					) {
						return $parsed;
					}

					$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context );
					$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
					$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

					return YoRo_Entity_Validator::validate( $resolved_result );
				}

				return $parsed;
			}
		}

		return null;
	}

	private static function is_high_confidence_result( $result ) {
		if ( ! is_array( $result ) || empty( $result['matched'] ) ) {
			return false;
		}

		$confidence = isset( $result['confidence'] ) ? (float) $result['confidence'] : 1.0;

		return $confidence >= 0.95;
	}

	private static function should_prioritize_strong_read_only_rule_result( $result ) {
		if ( ! is_array( $result ) || empty( $result['matched'] ) || empty( $result['action'] ) ) {
			return false;
		}

		$action = sanitize_text_field( $result['action'] );

		if ( ! in_array( $action, [ 'orders.get_details', 'orders.get_customer_email_drafts' ], true ) ) {
			return false;
		}

		$parser_rule = sanitize_text_field( $result['parser_rule'] ?? '' );

		return in_array(
			$parser_rule,
			[
				'rule.orders.get_details.by_id',
				'rule.orders.get_customer_email_drafts',
			],
			true
		);
	}

	private static function should_prioritize_strong_confirmation_gated_write_rule_result( $result ) {
		if ( ! is_array( $result ) || empty( $result['matched'] ) || empty( $result['action'] ) ) {
			return false;
		}

		return false;
	}

	private static function is_usable_ai_result( $result ) {
		if ( is_wp_error( $result ) ) {
			return false;
		}

		return is_array( $result ) && ! empty( $result['matched'] );
	}

	private static function finalize_ai_result( $result, $command, $normalized, $client_context = [] ) {
		$result = self::apply_grounded_read_only_ai_override( $result, $command, $normalized );
		$result = self::apply_grounded_confirmation_gated_write_ai_override( $result, $command, $normalized );
		$confidence = isset( $result['confidence'] ) ? (float) $result['confidence'] : 0;
		$threshold  = self::get_ai_confidence_threshold_for_result( $result );
		$best_effort_allowed = self::should_allow_best_effort_read_only_ai_result(
			$result,
			$confidence,
			$threshold,
			$normalized
		);

		if ( $confidence < $threshold && ! $best_effort_allowed ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'confidence'         => $confidence,
				'threshold'          => $threshold,
				'message'            => __( 'I am not confident enough to run this yet. What did you mean?', 'yoro-agent-core' ),
				'suggestion'         => $result['reason'] ?? '',
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		$result['parser_source'] = sanitize_key(
			$result['parser_source'] ?? 'ai'
		);

		$result = self::correct_ai_hold_to_update_status(
			$result,
			$normalized
		);

		$normalized_result = self::normalize_parsed_result(
			$result,
			$command,
			$normalized,
			$client_context
		);

		$resolved_result = YoRo_Entity_Resolver::resolve(
			$normalized_result,
			$client_context
		);

		$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

		// Important: run correction again after Intent Router,
		// because AI intent parser may be mapped to orders.hold here.
		$resolved_result = self::correct_ai_hold_to_update_status(
			$resolved_result,
			$normalized
		);

		if ( $best_effort_allowed ) {
			$resolved_result['meta'] = isset( $resolved_result['meta'] ) && is_array( $resolved_result['meta'] )
				? $resolved_result['meta']
				: [];
			$resolved_result['meta']['assumption'] = [
				'type'       => 'best_effort_read_only_routing',
				'message'    => __( 'YoRo made a best-effort guess for this read-only request.', 'yoro-agent-core' ),
				'confidence' => $confidence,
				'threshold'  => $threshold,
			];
		}

		return YoRo_Entity_Validator::validate( $resolved_result );
	}

	private static function apply_grounded_read_only_ai_override( $result, $command, $normalized ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$action = sanitize_text_field( $result['action'] ?? '' );
		$intent_name = sanitize_key( $result['intent']['name'] ?? '' );
		$order_ids = class_exists( 'YoRo_Order_Helper' ) ? YoRo_Order_Helper::extract_order_ids_from_text( $command ) : [];

		if ( empty( $order_ids ) || self::has_conflicting_grounded_order_intent( $normalized ) ) {
			return $result;
		}

		$is_order_details_hypothesis = (
			'orders.get_details' === $action ||
			'get_order_details' === $action ||
			'get_order_details' === $intent_name
		);

		if ( ! $is_order_details_hypothesis ) {
			return $result;
		}

		$params = isset( $result['params'] ) && is_array( $result['params'] ) ? $result['params'] : [];
		$params['order_ids'] = array_values( array_map( 'absint', $order_ids ) );

		if ( 1 === count( $params['order_ids'] ) ) {
			$params['order_id'] = $params['order_ids'][0];
		}

		$result['action'] = 'orders.get_details';
		$result['params'] = $params;
		$result['confidence'] = max( (float) ( $result['confidence'] ?? 0 ), 0.91 );
		$result['parser_rule'] = sanitize_text_field( $result['parser_rule'] ?? 'ai.provider.structured_output' );

		if ( isset( $result['intent'] ) && is_array( $result['intent'] ) ) {
			$result['intent']['name'] = 'get_order_details';
			$result['intent']['action'] = 'orders.get_details';
			$result['intent']['confidence'] = max( (float) ( $result['intent']['confidence'] ?? 0 ), 0.91 );
		}

		return $result;
	}

	private static function apply_grounded_confirmation_gated_write_ai_override( $result, $command, $normalized ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$grounded = self::build_grounded_confirmation_gated_write_inference(
			$command,
			$normalized,
			sanitize_text_field( $result['action'] ?? '' ),
			(float) ( $result['confidence'] ?? 0 ),
			sanitize_text_field( $result['requested_capability'] ?? '' ),
			sanitize_text_field( $result['target_object'] ?? '' ),
			sanitize_key( $result['target_type'] ?? '' ),
			isset( $result['params'] ) && is_array( $result['params'] ) ? $result['params'] : []
		);

		if ( ! is_array( $grounded ) || empty( $grounded['action'] ) ) {
			return $result;
		}

		$result['action'] = sanitize_text_field( $grounded['action'] );
		$result['params'] = isset( $grounded['params'] ) && is_array( $grounded['params'] ) ? $grounded['params'] : [];
		$result['confidence'] = max( (float) ( $result['confidence'] ?? 0 ), (float) ( $grounded['confidence'] ?? 0.88 ) );
		$result['parser_rule'] = sanitize_text_field( $result['parser_rule'] ?? 'ai.provider.structured_output' );

		if ( ! isset( $result['intent'] ) || ! is_array( $result['intent'] ) ) {
			$result['intent'] = [];
		}

		$intent_name_map = [
			'orders.update_status' => 'update_order_status',
			'orders.hold'          => 'hold_order',
			'orders.add_note'      => 'add_note',
		];

		$result['intent']['name'] = $intent_name_map[ $result['action'] ] ?? ( $result['intent']['name'] ?? '' );
		$result['intent']['action'] = $result['action'];
		$result['intent']['confidence'] = max( (float) ( $result['intent']['confidence'] ?? 0 ), (float) ( $grounded['confidence'] ?? 0.88 ) );

		return $result;
	}

	private static function build_grounded_confirmation_gated_write_inference( $command, $normalized, $action, $action_confidence = 0, $requested_capability = '', $target_object = '', $target_type = '', $params = [], $client_context = [] ) {
		$order_ids = class_exists( 'YoRo_Order_Helper' ) ? YoRo_Order_Helper::extract_order_ids_from_text( $command ) : [];

		if ( empty( $order_ids ) ) {
			return null;
		}

		$params = is_array( $params ) ? $params : [];
		$status = '';

		if ( class_exists( 'YoRo_Order_Helper' ) ) {
			$status = YoRo_Order_Helper::detect_status_from_text( strtolower( trim( $command . ' ' . $normalized ) ) );

			if ( empty( $status ) ) {
				$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
			}

			if ( ! empty( $status ) ) {
				$status = YoRo_Order_Helper::normalize_status_slug( $status );
			}
		}

		if (
			! empty( $status ) &&
			class_exists( 'YoRo_Order_Helper' ) &&
			YoRo_Order_Helper::is_valid_order_status( $status ) &&
			preg_match( '/\b(?:change|mark|move|set|update)\b/i', $normalized ) &&
			preg_match( '/\b(?:to|as|status)\b/i', $normalized )
		) {
			return [
				'action'     => 'orders.update_status',
				'params'      => [
					'order_ids' => array_values( array_map( 'absint', $order_ids ) ),
					'status'    => $status,
					'note'      => __( 'Order status updated by YoRo Agent.', 'yoro-agent-core' ),
				],
				'confidence' => $action_confidence >= 0.6 ? 0.92 : 0.88,
			];
		}

		if ( preg_match( '/\b(?:hold|on hold)\b/i', $normalized ) ) {
			return [
				'action'     => 'orders.hold',
				'params'      => [
					'order_ids' => array_values( array_map( 'absint', $order_ids ) ),
				],
				'confidence' => $action_confidence >= 0.6 ? 0.91 : 0.87,
			];
		}

		if ( preg_match( '/\b(?:add\s+(?:a\s+)?)?note\b/i', $normalized ) ) {
			$note = '';

			if ( isset( $params['note'] ) ) {
				$note = sanitize_textarea_field( $params['note'] );
			}

			if ( '' === $note && preg_match( '/:\s*(.+)$/', $command, $matches ) ) {
				$note = sanitize_textarea_field( trim( $matches[1] ) );
			}

			return [
				'action'     => 'orders.add_note',
				'params'      => [
					'order_id'  => absint( reset( $order_ids ) ),
					'order_ids' => array_values( array_map( 'absint', $order_ids ) ),
					'note'      => $note,
				],
				'confidence' => $action_confidence >= 0.6 ? 0.9 : 0.86,
			];
		}

		$note = '';

		if ( preg_match( '/(?:add\s+note|with\s+note|note)\s*:?\s*(.+)$/i', $command, $matches ) ) {
			$note = sanitize_textarea_field( trim( $matches[1] ) );
		}

		if (
			'orders.bulk_update_status_with_note' === $action ||
			(
				! empty( $status ) &&
				'' !== $note &&
				preg_match( '/\b(?:change|mark|update|move)\b/i', $normalized ) &&
				preg_match( '/\b(?:orders|these orders|selected orders|latest \d+ orders|recent \d+ orders)\b/i', $normalized )
			)
		) {
			$resolved_order_ids = [];

			if ( ! empty( $order_ids ) ) {
				$resolved_order_ids = array_values( array_map( 'absint', $order_ids ) );
			} elseif ( ! empty( $client_context['resolved_order_ids'] ) && is_array( $client_context['resolved_order_ids'] ) ) {
				$resolved_order_ids = array_values( array_filter( array_map( 'absint', $client_context['resolved_order_ids'] ) ) );
			} else {
				$resolved_order_ids = self::resolve_bulk_order_ids_from_context( $normalized, $client_context );
			}

			if ( ! empty( $resolved_order_ids ) && ! empty( $status ) && '' !== $note ) {
				return [
					'action' => 'orders.bulk_update_status_with_note',
					'params' => [
						'order_ids' => $resolved_order_ids,
						'status' => $status,
						'note' => $note,
					],
					'confidence' => $action_confidence >= 0.6 ? 0.91 : 0.87,
				];
			}
		}

		return null;
	}

	private static function resolve_bulk_order_ids_from_context( $normalized, $client_context = [] ) {
		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? $client_context['last_orders']
			: [];

		$last_order_ids = isset( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] )
			? array_values( array_filter( array_map( 'absint', $client_context['last_order_ids'] ) ) )
			: [];

		if (
			preg_match( '/\b(?:latest|recent)\s+(\d+)\s+orders?\b/i', $normalized, $matches ) &&
			! empty( $matches[1] )
		) {
			$limit = max( 1, absint( $matches[1] ) );

			if ( ! empty( $last_orders ) ) {
				return array_values(
					array_filter(
						array_map(
							'absint',
							wp_list_pluck( array_slice( $last_orders, 0, $limit ), 'id' )
						)
					)
				);
			}

			return array_slice( $last_order_ids, 0, $limit );
		}

		if (
			false !== strpos( $normalized, 'these orders' ) ||
			false !== strpos( $normalized, 'those orders' ) ||
			false !== strpos( $normalized, 'selected orders' ) ||
			false !== strpos( $normalized, 'all these' ) ||
			false !== strpos( $normalized, 'all of them' )
		) {
			if ( ! empty( $last_orders ) ) {
				return array_values(
					array_filter(
						array_map( 'absint', wp_list_pluck( $last_orders, 'id' ) )
					)
				);
			}

			return $last_order_ids;
		}

		return [];
	}

	private static function build_grounded_email_supported_action_inference( $command, $normalized, $action, $action_confidence = 0, $client_context = [], $existing_params = [] ) {
		$workflow = isset( $client_context['current_workflow'] ) && is_array( $client_context['current_workflow'] )
			? $client_context['current_workflow']
			: [];

		$current_order_id = absint( $client_context['current_order_id'] ?? 0 );
		$order_ids = class_exists( 'YoRo_Order_Helper' ) ? YoRo_Order_Helper::extract_order_ids_from_text( $command ) : [];
		$order_id = ! empty( $order_ids[0] ) ? absint( $order_ids[0] ) : $current_order_id;
		$existing_params = is_array( $existing_params ) ? $existing_params : [];

		$workflow_type = sanitize_key( $workflow['type'] ?? '' );
		$workflow_order_id = absint( $workflow['order_id'] ?? 0 );
		$workflow_step = sanitize_key( $workflow['step'] ?? '' );
		$workflow_subject = sanitize_text_field( $workflow['subject'] ?? '' );
		$workflow_body = sanitize_textarea_field( $workflow['body'] ?? '' );
		$workflow_draft_id = sanitize_key( $workflow['draft_id'] ?? '' );

		$has_email_anchor = false !== strpos( $normalized, 'email' );
		$has_draft_anchor = false !== strpos( $normalized, 'draft' );
		$has_template_anchor = false !== strpos( $normalized, 'template' );

		if (
			$order_id > 0 &&
			$has_email_anchor &&
			preg_match( '/\b(write|compose|draft|prepare)\b/i', $normalized )
		) {
			return [
				'action' => 'orders.compose_customer_email',
				'params' => [
					'order_id' => $order_id,
					'instruction' => sanitize_textarea_field( $command ),
				],
				'confidence' => $action_confidence >= 0.6 ? 0.93 : 0.90,
			];
		}

		if (
			'customer_email' === $workflow_type &&
			in_array( $workflow_step, [ 'awaiting_instruction', 'collect_missing_params' ], true ) &&
			$workflow_order_id > 0 &&
			( 'awaiting_instruction' === $workflow_step || in_array( 'instruction', (array) ( $workflow['missing'] ?? [] ), true ) ) &&
			'' !== trim( $command ) &&
			! preg_match( '/\b(?:show|open|view|list|find|search|details?)\b/i', $normalized )
		) {
			return [
				'action' => 'orders.compose_customer_email',
				'params' => [
					'order_id' => $workflow_order_id,
					'instruction' => sanitize_textarea_field( $command ),
				],
				'confidence' => $action_confidence >= 0.6 ? 0.94 : 0.91,
			];
		}

		if (
			'customer_email' === $workflow_type &&
			$workflow_order_id > 0 &&
			'' !== $workflow_subject &&
			'' !== $workflow_body
		) {
			if (
				preg_match( '/\bsend\b/i', $normalized ) &&
				$has_email_anchor
			) {
				return [
					'action' => 'orders.send_customer_email',
					'params' => [
						'order_id' => $workflow_order_id,
						'subject' => $workflow_subject,
						'body' => $workflow_body,
					],
					'confidence' => $action_confidence >= 0.6 ? 0.95 : 0.92,
				];
			}

			if ( preg_match( '/\bsave\b/i', $normalized ) && $has_draft_anchor ) {
				if ( '' !== $workflow_draft_id ) {
					return [
						'action' => 'orders.update_customer_email_draft',
						'params' => [
							'order_id' => $workflow_order_id,
							'draft_id' => $workflow_draft_id,
							'subject' => $workflow_subject,
							'body' => $workflow_body,
						],
						'confidence' => $action_confidence >= 0.6 ? 0.94 : 0.91,
					];
				}

				return [
					'action' => 'orders.save_customer_email_draft',
					'params' => [
						'order_id' => $workflow_order_id,
						'subject' => $workflow_subject,
						'body' => $workflow_body,
					],
					'confidence' => $action_confidence >= 0.6 ? 0.93 : 0.90,
				];
			}

			if (
				'' !== $workflow_draft_id &&
				preg_match( '/\b(delete|remove)\b/i', $normalized ) &&
				$has_draft_anchor
			) {
				return [
					'action' => 'orders.delete_customer_email_draft',
					'params' => [
						'order_id' => $workflow_order_id,
						'draft_id' => $workflow_draft_id,
					],
					'confidence' => $action_confidence >= 0.6 ? 0.95 : 0.92,
				];
			}

			if (
				preg_match( '/\bsave\b/i', $normalized ) &&
				$has_template_anchor
			) {
				$template_name = self::extract_template_name_from_command( $command );

				if ( '' !== $template_name ) {
					return [
						'action' => 'email_templates.save',
						'params' => [
							'name' => $template_name,
							'subject' => $workflow_subject,
							'body' => $workflow_body,
						],
						'confidence' => $action_confidence >= 0.6 ? 0.92 : 0.89,
					];
				}
			}
		}

		if (
			'email_templates.delete' === sanitize_text_field( $action ) &&
			! empty( $existing_params['template_id'] )
		) {
			return [
				'action' => 'email_templates.delete',
				'params' => [
					'template_id' => sanitize_key( $existing_params['template_id'] ),
				],
				'confidence' => $action_confidence >= 0.6 ? 0.90 : 0.87,
			];
		}

		return null;
	}

	private static function build_grounded_workflow_preview_inference( $command, $normalized, $action, $action_confidence = 0, $client_context = [], $inference = [] ) {
		$inference = is_array( $inference ) ? $inference : [];
		$intent_family = sanitize_key( $inference['intent_family'] ?? '' );
		$closest_supported_surface = sanitize_key( $inference['closest_supported_surface'] ?? '' );
		$reference_target_type = sanitize_key( $inference['reference_target_type'] ?? '' );
		$reference_scope = sanitize_key( $inference['reference_scope'] ?? '' );
		$reference_selector = sanitize_key( $inference['reference_selector'] ?? '' );
		$reference_index = isset( $inference['reference_index'] ) ? (int) $inference['reference_index'] : -1;
		$reference_confidence = isset( $inference['reference_confidence'] ) ? (float) $inference['reference_confidence'] : 0;
		$wants_preview = (bool) preg_match(
			'/\b(?:preview|prepare|draft|review|handle|next|recommendation|follow up|follow-up)\b/i',
			$normalized
		);

		if ( ! $wants_preview ) {
			return null;
		}

		$active_followup_type = sanitize_key( $client_context['active_followup_type'] ?? '' );
		$last_result_type = sanitize_key( $client_context['last_result_type'] ?? '' );

		$workflow_map = [
			'fulfillment' => [
				'action' => 'workflow.fulfillment_followup_preview',
				'context_key' => 'last_fulfillment_followup',
				'fallback_message' => 'fulfillment',
			],
			'customer_service' => [
				'action' => 'workflow.customer_followup_preview',
				'context_key' => 'last_service_followup',
				'fallback_message' => 'customer',
			],
			'operations_attention' => [
				'action' => 'workflow.operations_attention_preview',
				'context_key' => 'last_attention_followup',
				'fallback_message' => 'operations',
			],
			'inventory' => [
				'action' => 'workflow.inventory_pressure_preview',
				'context_key' => 'last_inventory_followup',
				'fallback_message' => 'inventory',
			],
			'business' => [
				'action' => 'workflow.business_summary_preview',
				'context_key' => 'last_business_followup',
				'fallback_message' => 'business',
			],
		];

		$family = '';

		if ( isset( $workflow_map[ $active_followup_type ] ) ) {
			$family = $active_followup_type;
		} elseif ( isset( $workflow_map[ $last_result_type ] ) ) {
			$family = $last_result_type;
		} elseif ( false !== strpos( $normalized, 'fulfillment' ) || false !== strpos( $normalized, 'delay email' ) ) {
			$family = 'fulfillment';
		} elseif ( false !== strpos( $normalized, 'customer' ) || false !== strpos( $normalized, 'support reply' ) || false !== strpos( $normalized, 'reply' ) ) {
			$family = 'customer_service';
		} elseif ( false !== strpos( $normalized, 'attention' ) || false !== strpos( $normalized, 'urgent operations' ) ) {
			$family = 'operations_attention';
		} elseif ( false !== strpos( $normalized, 'inventory' ) || false !== strpos( $normalized, 'stock issue' ) ) {
			$family = 'inventory';
		} elseif ( false !== strpos( $normalized, 'business' ) || false !== strpos( $normalized, 'signal' ) ) {
			$family = 'business';
		}

		if ( '' === $family && in_array( $closest_supported_surface, [ 'operations_attention', 'business_summary' ], true ) ) {
			$family = 'operations_attention' === $closest_supported_surface ? 'operations_attention' : 'business';
		}

		if ( '' === $family || empty( $workflow_map[ $family ] ) ) {
			return null;
		}

		$definition = $workflow_map[ $family ];
		$followup_state = isset( $client_context[ $definition['context_key'] ] ) && is_array( $client_context[ $definition['context_key'] ] )
			? $client_context[ $definition['context_key'] ]
			: [];

		$has_required_context =
			(
				'last_business_followup' === $definition['context_key'] &&
				! empty( $followup_state['recommended_sequence'] ) &&
				is_array( $followup_state['recommended_sequence'] )
			) ||
			(
				'last_business_followup' !== $definition['context_key'] &&
				! empty( $followup_state['queues'] ) &&
				is_array( $followup_state['queues'] )
			);

		if ( ! $has_required_context ) {
			return null;
		}

		$params = [
			'original_command' => $command,
		];

		if ( 'recommended_sequence' === $reference_target_type && $reference_confidence >= 0.75 ) {
			if ( 'none' !== $reference_selector && '' !== $reference_selector ) {
				$params['reference_selector'] = $reference_selector;
			}

			if ( $reference_index >= 0 ) {
				$params['reference_index'] = $reference_index;
			}
		}

		return [
			'action' => $definition['action'],
			'params' => $params,
			'confidence' => $action_confidence >= 0.6 ? 0.93 : 0.90,
		];
	}

	private static function build_grounded_workflow_control_inference( $command, $normalized, $action, $action_confidence = 0, $client_context = [] ) {
		$workflow = isset( $client_context['current_workflow'] ) && is_array( $client_context['current_workflow'] )
			? $client_context['current_workflow']
			: [];

		if ( empty( $workflow ) ) {
			return null;
		}

		$workflow_action = sanitize_text_field( $workflow['action'] ?? '' );
		$workflow_params = isset( $workflow['params'] ) && is_array( $workflow['params'] ) ? $workflow['params'] : [];

		$wants_cancel = (
			false !== strpos( $normalized, 'cancel' ) ||
			false !== strpos( $normalized, 'discard' ) ||
			false !== strpos( $normalized, 'stop' ) ||
			false !== strpos( $normalized, 'abort' ) ||
			false !== strpos( $normalized, 'never mind' ) ||
			false !== strpos( $normalized, 'nevermind' ) ||
			false !== strpos( $normalized, 'forget it' )
		);

		if ( $wants_cancel ) {
			return [
				'action' => 'workflow.cancel',
				'params' => [],
				'confidence' => $action_confidence >= 0.6 ? 0.97 : 0.94,
			];
		}

		$wants_continue = (
			'continue' === $normalized ||
			'resume' === $normalized ||
			false !== strpos( $normalized, 'continue workflow' ) ||
			false !== strpos( $normalized, 'resume workflow' ) ||
			false !== strpos( $normalized, 'continue this' ) ||
			false !== strpos( $normalized, 'continue with this' ) ||
			false !== strpos( $normalized, 'resume this' ) ||
			false !== strpos( $normalized, 'go ahead' ) ||
			false !== strpos( $normalized, 'proceed' ) ||
			false !== strpos( $normalized, 'carry on' )
		);

		if ( $wants_continue && '' !== $workflow_action && ! empty( $workflow_params ) ) {
			return [
				'action' => $workflow_action,
				'params' => $workflow_params,
				'confidence' => $action_confidence >= 0.6 ? 0.91 : 0.87,
			];
		}

		return null;
	}

	private static function resolve_ai_reference_action( $command, $normalized, $client_context = [], $inference = [] ) {
		$reference_target_type = sanitize_key( $inference['reference_target_type'] ?? '' );
		$reference_scope = sanitize_key( $inference['reference_scope'] ?? '' );
		$reference_selector = sanitize_key( $inference['reference_selector'] ?? '' );
		$reference_index = isset( $inference['reference_index'] ) ? (int) $inference['reference_index'] : -1;
		$reference_confidence = isset( $inference['reference_confidence'] ) ? (float) $inference['reference_confidence'] : 0;
		$action = sanitize_text_field( $inference['action'] ?? '' );
		$params = isset( $inference['params'] ) && is_array( $inference['params'] ) ? $inference['params'] : [];
		$action_confidence = isset( $inference['action_confidence'] ) ? (float) $inference['action_confidence'] : 0;

		if ( $reference_confidence < 0.75 || '' === $reference_target_type || 'none' === $reference_target_type ) {
			return null;
		}

		if ( 'recommended_sequence' === $reference_target_type ) {
			$followup_state = self::get_ai_reference_followup_state( $client_context, $reference_scope );

			if ( empty( $followup_state['sequence'] ) || ! is_array( $followup_state['sequence'] ) ) {
				return null;
			}

			$sequence = array_values( $followup_state['sequence'] );
			$resolved_index = self::resolve_ai_reference_index(
				$reference_selector,
				$reference_index,
				(int) $followup_state['current_index'],
				count( $sequence )
			);

			if ( null === $resolved_index || empty( $sequence[ $resolved_index ]['command'] ) ) {
				return null;
			}

			$resolved_command = sanitize_text_field( $sequence[ $resolved_index ]['command'] );
			$resolved = self::parse( $resolved_command, $client_context );

			if ( ! is_array( $resolved ) || empty( $resolved['action'] ) ) {
				return null;
			}

			return [
				'action' => sanitize_text_field( $resolved['action'] ),
				'params' => isset( $resolved['params'] ) && is_array( $resolved['params'] ) ? $resolved['params'] : [],
				'confidence' => max( $reference_confidence, $action_confidence, 0.9 ),
			];
		}

		if ( 'last_orders' === $reference_target_type ) {
			$order_ids = self::resolve_ai_reference_order_ids(
				$client_context,
				$reference_selector,
				$reference_index
			);

			if ( empty( $order_ids ) ) {
				return null;
			}

			if ( '' === $action || 'none' === $action ) {
				$action = 'orders.get_details';
			}

			$order_action_families = [
				'orders.get_details',
				'orders.add_note',
				'orders.update_status',
				'orders.hold',
				'orders.compose_customer_email',
			];

			if ( ! in_array( $action, $order_action_families, true ) ) {
				return null;
			}

			if ( 'orders.get_details' === $action || 'orders.hold' === $action ) {
				$params['order_ids'] = $order_ids;
			} else {
				$params['order_ids'] = $order_ids;
				$params['order_id'] = absint( reset( $order_ids ) );
			}

			return [
				'action' => $action,
				'params' => $params,
				'confidence' => max( $reference_confidence, $action_confidence, 0.88 ),
			];
		}

		if ( 'current_workflow' === $reference_target_type ) {
			$workflow = isset( $client_context['current_workflow'] ) && is_array( $client_context['current_workflow'] )
				? $client_context['current_workflow']
				: [];

			if ( empty( $workflow['action'] ) ) {
				return null;
			}

			$resolved_action = sanitize_text_field( $workflow['action'] );
			$resolved_params = isset( $workflow['params'] ) && is_array( $workflow['params'] ) ? $workflow['params'] : [];

			return [
				'action' => $resolved_action,
				'params' => $resolved_params,
				'confidence' => max( $reference_confidence, $action_confidence, 0.86 ),
			];
		}

		return null;
	}

	private static function get_ai_reference_followup_state( $client_context, $reference_scope ) {
		$active_followup_type = sanitize_key( $client_context['active_followup_type'] ?? '' );
		$last_result_type = sanitize_key( $client_context['last_result_type'] ?? '' );

		$family = '';

		if ( 'active_followup' === $reference_scope && '' !== $active_followup_type ) {
			$family = $active_followup_type;
		} elseif ( 'last_result' === $reference_scope && '' !== $last_result_type ) {
			$family = $last_result_type;
		} elseif ( '' !== $active_followup_type ) {
			$family = $active_followup_type;
		} else {
			$family = $last_result_type;
		}

		$map = [
			'business' => [ 'state_key' => 'last_business_followup', 'sequence_key' => 'recommended_sequence' ],
			'operations_attention' => [ 'state_key' => 'last_attention_followup', 'sequence_key' => 'recommended_sequence' ],
			'customer_service' => [ 'state_key' => 'last_service_followup', 'sequence_key' => 'recommended_sequence' ],
			'fulfillment' => [ 'state_key' => 'last_fulfillment_followup', 'sequence_key' => 'recommended_sequence' ],
			'inventory' => [ 'state_key' => 'last_inventory_followup', 'sequence_key' => 'recommended_sequence' ],
			'briefing' => [ 'state_key' => 'last_briefing', 'sequence_key' => 'recommended_sequence' ],
		];

		if ( empty( $map[ $family ] ) ) {
			return [];
		}

		$state_key = $map[ $family ]['state_key'];
		$sequence_key = $map[ $family ]['sequence_key'];
		$state = isset( $client_context[ $state_key ] ) && is_array( $client_context[ $state_key ] )
			? $client_context[ $state_key ]
			: [];

		return [
			'sequence' => isset( $state[ $sequence_key ] ) && is_array( $state[ $sequence_key ] ) ? $state[ $sequence_key ] : [],
			'current_index' => isset( $state['recommendation_cursor'] ) ? (int) $state['recommendation_cursor'] : -1,
		];
	}

	private static function resolve_ai_reference_index( $selector, $explicit_index, $current_index, $count ) {
		if ( $count <= 0 ) {
			return null;
		}

		if ( $explicit_index >= 0 && $explicit_index < $count ) {
			return $explicit_index;
		}

		switch ( $selector ) {
			case 'first':
				return 0;
			case 'second':
				return $count > 1 ? 1 : null;
			case 'third':
				return $count > 2 ? 2 : null;
			case 'last':
				return $count - 1;
			case 'next':
				if ( $current_index < 0 ) {
					return $count > 1 ? 1 : 0;
				}

				return ( $current_index + 1 ) < $count ? ( $current_index + 1 ) : null;
			case 'that':
			case 'this':
			case 'current':
				if ( $current_index >= 0 && $current_index < $count ) {
					return $current_index;
				}

				return 0;
		}

		return null;
	}

	private static function resolve_ai_reference_order_ids( $client_context, $selector, $explicit_index ) {
		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? $client_context['last_orders']
			: [];
		$last_order_ids = isset( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] )
			? array_values( array_filter( array_map( 'absint', $client_context['last_order_ids'] ) ) )
			: [];

		$count = ! empty( $last_orders ) ? count( $last_orders ) : count( $last_order_ids );
		$resolved_index = self::resolve_ai_reference_index( $selector, $explicit_index, -1, $count );

		if ( null === $resolved_index ) {
			return [];
		}

		if ( ! empty( $last_orders[ $resolved_index ]['id'] ) ) {
			return [ absint( $last_orders[ $resolved_index ]['id'] ) ];
		}

		if ( ! empty( $last_order_ids[ $resolved_index ] ) ) {
			return [ absint( $last_order_ids[ $resolved_index ] ) ];
		}

		return [];
	}

	private static function get_ai_confidence_threshold_for_result( $result ) {
		if ( self::is_read_only_ai_result( $result ) ) {
			return YoRo_AI_Parser::get_read_only_confidence_threshold();
		}

		return YoRo_AI_Parser::get_confidence_threshold();
	}

	private static function is_read_only_ai_result( $result ) {
		if ( ! is_array( $result ) || empty( $result['action'] ) ) {
			return false;
		}

		$action = YoRo_Action_Registry::get( $result['action'] );

		if ( ! is_array( $action ) ) {
			return false;
		}

		return empty( $action['write_action'] );
	}

	private static function should_allow_best_effort_read_only_ai_result( $result, $confidence, $threshold, $normalized ) {
		if ( ! self::is_read_only_ai_result( $result ) ) {
			return false;
		}

		if ( $confidence >= $threshold ) {
			return true;
		}

		if ( $confidence < max( 0.45, $threshold - 0.15 ) ) {
			return false;
		}

		if ( self::looks_truly_ambiguous_read_only_request( $normalized ) ) {
			return false;
		}

		return true;
	}

	private static function looks_truly_ambiguous_read_only_request( $normalized ) {
		$normalized = is_string( $normalized ) ? trim( strtolower( $normalized ) ) : '';

		if ( '' === $normalized ) {
			return true;
		}

		$generic_only_patterns = [
			'/^(help|show|tell me|what about|overview|summary)$/i',
			'/^(something|anything|stuff|things|data)$/i',
			'/^(show me something|tell me something)$/i',
		];

		foreach ( $generic_only_patterns as $pattern ) {
			if ( preg_match( $pattern, $normalized ) ) {
				return true;
			}
		}

		return false;
	}

	private static function correct_ai_hold_to_update_status( $result, $normalized ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		if (
			empty( $result['action'] ) ||
			'orders.hold' !== $result['action'] ||
			! preg_match( '/\b(move|change|set|update|mark)\b/i', $normalized ) ||
			! preg_match( '/\b(to|as|status)\b/i', $normalized )
		) {
			return $result;
		}

		$result['action'] = 'orders.update_status';

		if ( empty( $result['params'] ) || ! is_array( $result['params'] ) ) {
			$result['params'] = [];
		}

		if ( empty( $result['params']['status'] ) ) {
			$result['params']['status'] = 'on-hold';
		}

		$result['parser_rule'] = 'ai.corrected.orders.update_status_from_hold';

		if ( isset( $result['intent'] ) && is_array( $result['intent'] ) ) {
			$result['intent']['name']   = 'orders_update_status';
			$result['intent']['action'] = 'orders.update_status';
		}

		return $result;
	}

	private static function should_try_ai_workflow_before_rule_result( $command, $normalized, $rule_result ) {
		$normalized = strtolower( (string) $normalized );

		if (
			self::is_overview_or_queue_style_read_only_query( $normalized )
		) {
			return false;
		}

		if (
			is_array( $rule_result ) &&
			! empty( $rule_result['action'] ) &&
			in_array(
				$rule_result['action'],
				[
					'multi.hold_and_note',
					'multi.query_and_apply',
				],
				true
			)
		) {
			return false;
		}

		$has_multi_connector = preg_match(
			'/\b(and|then|after that|next|also)\b/i',
			$normalized
		);

		$action_hits = 0;

		foreach ( [ 'check', 'review', 'hold', 'on hold', 'add note', 'note', 'draft', 'email', 'send', 'save draft' ] as $term ) {
			if ( false !== strpos( $normalized, $term ) ) {
				$action_hits++;
			}
		}

		if ( $has_multi_connector && $action_hits >= 2 ) {
			return true;
		}

		if (
			is_array( $rule_result ) &&
			! empty( $rule_result['action'] ) &&
			'orders.compose_customer_email' === $rule_result['action'] &&
			(
				false !== strpos( $normalized, 'hold' ) ||
				false !== strpos( $normalized, 'note' ) ||
				false !== strpos( $normalized, 'put it on hold' )
			)
		) {
			return true;
		}

		return false;
	}

	private static function is_overview_or_queue_style_read_only_query( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		$read_patterns = [
			'business',
			'store picture',
			'store summary',
			'store overview',
			'store update',
			'store health',
			'store look like',
			'operations summary',
			'operations overview',
			'what\'s going on',
			'what s going on',
			'whats going on',
			'what\'s up',
			'what s up',
			'whats up',
			'how are we doing',
			'how is the store doing',
			'what\'s new',
			'what s new',
			'whats new',
			'shipment blockers',
			'shipping blockers',
			'stuck in shipping',
			'waiting to ship',
			'need restock',
			'restock first',
			'need a reply',
			'needs a reply',
			'reply to first',
			'follow up',
			'follow-up',
		];

		foreach ( $read_patterns as $pattern ) {
			if ( false !== strpos( $normalized, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	private static function normalize_parsed_result( $parsed, $command, $normalized, $client_context = [] ) {
		if ( ! is_array( $parsed ) ) {
			return $parsed;
		}

		$parsed['success'] = isset( $parsed['success'] ) ? (bool) $parsed['success'] : true;
		$parsed['matched'] = isset( $parsed['matched'] ) ? (bool) $parsed['matched'] : true;

		$parsed['original_command'] = $parsed['original_command'] ?? $command;
		$parsed['normalized_command'] = $parsed['normalized_command'] ?? $normalized;

		$parsed['parser_source'] = $parsed['parser_source'] ?? 'rule';
		$parsed['confidence'] = isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 1.0;

		if ( empty( $parsed['params'] ) || ! is_array( $parsed['params'] ) ) {
			$parsed['params'] = [];
		}

		if (
			'multi.query_and_apply' !== ( $parsed['action'] ?? '' ) &&
			empty( $parsed['params']['order_ids'] ) &&
			empty( $parsed['actions'] ) &&
			! empty( $client_context['resolved_order_ids'] )
		) {
			$parsed['params']['order_ids'] = array_values(
				array_filter(
					array_unique(
						array_map( 'absint', $client_context['resolved_order_ids'] )
					)
				)
			);
		}

		if ( ! empty( $parsed['action'] ) ) {
			$intent_name = '';

			if ( ! empty( $parsed['intent']['name'] ) ) {
				$intent_name = self::canonicalize_intent_name(
					$parsed['intent']['name'],
					$parsed['action']
				);
			}

			if ( ! $intent_name && class_exists( 'YoRo_Intent_Router' ) ) {
				$intent_name = YoRo_Intent_Router::map_action_to_intent_name(
					$parsed['action']
				);
			}

			if ( ! $intent_name ) {
				$intent_name = sanitize_key( str_replace( '.', '_', $parsed['action'] ) );
			}

			$parsed['intent'] = [
				'name'       => $intent_name,
				'action'     => sanitize_text_field( $parsed['action'] ),
				'confidence' => $parsed['confidence'],
				'source'     => $parsed['parser_source'],
			];
		}

		$parsed['entities'] = self::extract_entities_from_params( $parsed['params'] );

		return $parsed;
	}

	private static function extract_entities_from_params( $params ) {
		$entities = [];

		if ( ! empty( $params['order_id'] ) ) {
			$entities['order_ids'] = [ absint( $params['order_id'] ) ];
		}

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$entities['order_ids'] = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', $params['order_ids'] )
					)
				)
			);
		}

		if ( ! empty( $params['status'] ) ) {
			$entities['status'] = sanitize_key( $params['status'] );
		}

		if ( ! empty( $params['note'] ) ) {
			$entities['note'] = sanitize_textarea_field( $params['note'] );
		}

		if ( ! empty( $params['email'] ) ) {
			$entities['email'] = sanitize_email( $params['email'] );
		}

		if ( ! empty( $params['phone'] ) ) {
			$entities['phone'] = sanitize_text_field( $params['phone'] );
		}

		if ( ! empty( $params['template'] ) ) {
			$entities['template'] = sanitize_key( $params['template'] );
		}

		return $entities;
	}

	private static function canonicalize_intent_name( $intent_name, $action ) {
		$intent_name = sanitize_key( $intent_name );
		$action      = sanitize_text_field( $action );

		$map = [
			'store_get_business_summary'        => 'business_summary',
			'operations_get_summary'            => 'operations_overview',
			'operations_summary'                => 'operations_overview',
			'operations_get_attention_queue'    => 'operations_attention',
			'operations_attention_queue'        => 'operations_attention',
			'operations_get_fulfillment_queue'  => 'fulfillment_review',
			'fulfillment_queue'                 => 'fulfillment_review',
			'products_get_inventory_queue'      => 'inventory_review',
			'inventory_queue'                   => 'inventory_review',
			'customers_get_service_queue'       => 'customer_service_review',
			'customer_service_queue'            => 'customer_service_review',
		];

		if ( isset( $map[ $intent_name ] ) ) {
			return $map[ $intent_name ];
		}

		if ( class_exists( 'YoRo_Intent_Router' ) ) {
			$mapped = YoRo_Intent_Router::map_action_to_intent_name( $action );

			if ( $mapped ) {
				return $mapped;
			}
		}

		return $intent_name;
	}

	private static function detect_incomplete_intent( $command, $normalized ) {

		/*
		* Do not treat status-update commands as incomplete hold commands.
		*
		* Examples:
		* - Move order #123 to on hold
		* - Change order #123 to on hold
		* - Set order #123 as on hold
		*
		* These should be handled by orders.update_status.
		*/
		$is_status_update_intent = (
			preg_match( '/\b(move|change|set|update|mark)\b/i', $normalized ) &&
			preg_match( '/\b(to|as|status)\b/i', $normalized )
		);

		if (
			! $is_status_update_intent &&
			false !== strpos( $normalized, 'hold' ) &&
			false !== strpos( $normalized, 'order' )
		) {
			return [
				'success'            => true,
				'matched'            => true,
				'action'             => 'orders.hold',
				'params'             => [],
				'parser_rule'        => 'rule.incomplete.orders.hold',
				'parser_source'      => 'rule',
				'confidence'         => 0.8,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		if (
			false !== strpos( $normalized, 'note' ) &&
			false !== strpos( $normalized, 'order' )
		) {
			return [
				'success'            => true,
				'matched'            => true,
				'action'             => 'orders.add_note',
				'params'             => [],
				'parser_rule'        => 'rule.incomplete.orders.add_note',
				'parser_source'      => 'rule',
				'confidence'         => 0.75,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return null;
	}

	private static function detect_query_action_pattern( $command, $normalized ) {

		/*
		* Detect commands like:
		* - Hold latest 5 orders
		* - Put latest 5 orders on hold
		*
		* But DO NOT detect read/search commands like:
		* - Show latest 5 on-hold orders
		* - Latest 5 order on hold
		*/

		$has_write_hold_intent = preg_match(
			'/\b(hold|put|place|set|move|mark)\b/i',
			$normalized
		) && preg_match(
			'/\b(on-hold|on hold|hold)\b/i',
			$normalized
		);

		$has_read_intent = preg_match(
			'/\b(show|list|search|find|get|view|latest|recent)\b/i',
			$normalized
		);

		$is_on_hold_status_query = preg_match(
			'/\b(on-hold|on hold)\s+orders?\b/i',
			$normalized
		) || preg_match(
			'/\border(?:s)?\s+(?:on-hold|on hold)\b/i',
			$normalized
		);

		if ( $has_read_intent && $is_on_hold_status_query && ! $has_write_hold_intent ) {
			return null;
		}

		// Detect "hold latest 5 orders".
		if (
			preg_match( '/^\s*hold\b/i', $normalized ) &&
			false !== strpos( $normalized, 'order' ) &&
			preg_match( '/latest\s+(\d+)/', $normalized, $m )
		) {
			$limit = absint( $m[1] );

			return [
				'success' => true,
				'matched' => true,
				'action'  => 'multi.query_and_apply',
				'params'  => [
					'limit' => $limit,
				],
				'actions' => [
					[
						'action' => 'orders.search',
						'params' => [
							'status' => [ 'pending', 'processing', 'on-hold' ],
							'limit'  => $limit,
						],
					],
					[
						'action' => 'orders.hold',
						'params' => [],
					],
				],
				'parser_rule'        => 'rule.query_action.orders.hold.latest',
				'parser_source'      => 'rule',
				'confidence'         => 0.95,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		// Detect "put latest 5 orders on hold".
		if (
			preg_match( '/\b(put|place|set|move|mark)\b/i', $normalized ) &&
			preg_match( '/\blatest\s+(\d+)\b/i', $normalized, $m ) &&
			false !== strpos( $normalized, 'order' ) &&
			preg_match( '/\b(on-hold|on hold)\b/i', $normalized )
		) {
			$limit = absint( $m[1] );

			return [
				'success' => true,
				'matched' => true,
				'action'  => 'multi.query_and_apply',
				'params'  => [
					'limit' => $limit,
				],
				'actions' => [
					[
						'action' => 'orders.search',
						'params' => [
							'status' => [ 'pending', 'processing' ],
							'limit'  => $limit,
						],
					],
					[
						'action' => 'orders.hold',
						'params' => [],
					],
				],
				'parser_rule'        => 'rule.query_action.orders.put_latest_on_hold',
				'parser_source'      => 'rule',
				'confidence'         => 0.95,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		// Detect "update latest 5 orders to completed" and variants.
		if (
			preg_match( '/\b(?:change|mark|move|set|update)\b/i', $normalized ) &&
			preg_match( '/\b(?:latest|recent)\s+(\d+)\s+orders?\b/i', $normalized, $m ) &&
			false !== strpos( $normalized, 'order' )
		) {
			$status = '';
			$note = '';

			if ( class_exists( 'YoRo_Order_Helper' ) ) {
				$status = YoRo_Order_Helper::detect_status_from_text( $normalized );

				if ( empty( $status ) ) {
					$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
				}

				if ( ! empty( $status ) ) {
					$status = YoRo_Order_Helper::normalize_status_slug( $status );
				}
			}

			if ( preg_match( '/(?:add\s+note|with\s+note|note)\s*:?\s*(.+)$/i', $command, $matches ) ) {
				$note = sanitize_textarea_field( trim( $matches[1] ) );
			}

			if ( ! empty( $status ) && class_exists( 'YoRo_Order_Helper' ) && YoRo_Order_Helper::is_valid_order_status( $status ) ) {
				$limit = absint( $m[1] );
				$update_step_params = [
					'status' => $status,
				];

				if ( '' !== $note ) {
					$update_step_params['note'] = $note;
				}

				return [
					'success' => true,
					'matched' => true,
					'action'  => 'multi.query_and_apply',
					'params'  => [
						'limit' => $limit,
					],
					'actions' => [
						[
							'action' => 'orders.search',
							'params' => [
								'limit' => $limit,
							],
						],
						[
							'action' => '' !== $note ? 'orders.bulk_update_status_with_note' : 'orders.update_status',
							'params' => $update_step_params,
						],
					],
					'parser_rule'        => 'rule.query_action.orders.update_latest_status',
					'parser_source'      => 'rule',
					'confidence'         => 0.95,
					'original_command'   => $command,
					'normalized_command' => $normalized,
				];
			}
		}

		return null;
	}

	private static function normalize( $command ) {
		$command = strtolower( trim( wp_strip_all_tags( $command ) ) );
		$command = str_replace(
			[ '’', '`', '‘', '“', '”' ],
			[ '\'', '\'', '\'', '"', '"' ],
			$command
		);
		$command = preg_replace( '/\s+/', ' ', $command );

		return $command;
	}

	private static function is_too_ambiguous( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( '' === $normalized ) {
			return true;
		}

		$too_short = [
			'latest',
			'recent',
			'orders',
			'order',
			'show',
			'list',
			'find',
			'search',
		];

		return in_array( $normalized, $too_short, true );
	}

	private static function parse_conversational_only( $command, $normalized, $client_context = [] ) {
		$normalized = trim( (string) $normalized );

		if ( self::looks_like_developer_handoff_phrase( $normalized ) ) {
			if ( self::is_developer_handoff_confirmation( $normalized ) ) {
				return self::build_developer_handoff_response( $command, $normalized );
			}

			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.unsupported_handoff_without_context',
				__( 'I can send a request to the developers after we identify a specific unsupported capability first.', 'yoro-agent-core' ),
				__( 'Ask for the feature or action you want, and if I can’t do it yet I will offer to pass it along.', 'yoro-agent-core' ),
				[],
				[
					'conversation_topic' => 'developer_handoff',
					'result_type'        => 'assistant_message',
				]
			);
		}

		if ( self::is_developer_handoff_confirmation( $normalized ) ) {
			return self::build_developer_handoff_response( $command, $normalized );
		}

		$explicit_capability_gap = self::build_explicit_capability_gap_response( $command, $normalized, $client_context );

		if ( is_array( $explicit_capability_gap ) ) {
			return $explicit_capability_gap;
		}

		if ( self::looks_like_current_store_entity_question( $normalized ) ) {
			return self::build_current_store_entity_response( $command, $normalized, $client_context );
		}

		if ( self::is_capability_support_inquiry( $normalized ) ) {
			return self::build_capability_support_response( $command, $normalized, $client_context );
		}

		if ( self::looks_like_general_background_request( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.out_of_scope_background',
				__( 'I can’t help with general background about a person or topic here.', 'yoro-agent-core' ),
				__( 'I’m focused on WooCommerce store operations. If you want, I can take you back to a store briefing or the main attention queue instead.', 'yoro-agent-core' ),
				self::build_out_of_scope_general_suggestions(),
				[
					'conversation_topic'  => 'out_of_scope_general',
					'conversation_family' => 'person_or_entity_background',
					'result_type'         => 'assistant_message',
				]
			);
		}

		if ( self::looks_like_general_non_store_statement( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.out_of_scope_statement',
				__( 'I can’t help with general statements or opinions about people or non-store topics here.', 'yoro-agent-core' ),
				__( 'I’m focused on WooCommerce store operations. If you want, I can take you back to a store briefing or the main attention queue instead.', 'yoro-agent-core' ),
				self::build_out_of_scope_general_suggestions(),
				[
					'conversation_topic'  => 'out_of_scope_general',
					'conversation_family' => 'general_knowledge',
					'result_type'         => 'assistant_message',
				]
			);
		}

		if ( self::is_ambiguous_action_reference( $normalized ) && ! self::has_ai_reference_resolution_context( $client_context ) ) {
			return self::clarification_response(
				$command,
				$normalized,
				__( 'I need a little more detail before I act on that. What would you like me to do?', 'yoro-agent-core' )
			);
		}

		if ( self::is_greeting_only( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.greeting.hello',
				__( 'Hello. I can help you review the store, open the main queues, or inspect a specific order.', 'yoro-agent-core' ),
				__( 'Try a briefing, a queue, or an order detail request.', 'yoro-agent-core' ),
				self::get_default_conversational_suggestions()
			);
		}

		if ( self::is_capability_question( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.capabilities',
				__( 'I can brief you on store activity, open the main queues, review orders, prepare customer emails, and help with fulfillment and inventory follow-up.', 'yoro-agent-core' ),
				__( 'A good place to start is a morning briefing, an attention queue, or a specific order.', 'yoro-agent-core' ),
				[
					[
						'label' => __( 'Show morning briefing', 'yoro-agent-core' ),
						'value' => 'Show morning briefing',
					],
					[
						'label' => __( 'Show operations attention queue', 'yoro-agent-core' ),
						'value' => 'Show operations attention queue',
					],
					[
						'label' => __( 'Show fulfillment queue', 'yoro-agent-core' ),
						'value' => 'Show fulfillment queue',
					],
					[
						'label' => __( 'Show inventory queue', 'yoro-agent-core' ),
						'value' => 'Show inventory queue',
					],
				]
			);
		}

		if ( self::is_identity_question( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.identity',
				__( 'I’m YoRo, your WooCommerce operations assistant. I help you inspect orders, review store pressure, prepare customer follow-up, and move through daily operational work.', 'yoro-agent-core' ),
				__( 'You can ask for a briefing, a queue, an order detail view, or an email draft review.', 'yoro-agent-core' ),
				self::get_default_conversational_suggestions()
			);
		}

		if ( self::is_wellbeing_question( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.wellbeing',
				__( 'I’m ready. If you want, I can pull up the latest store picture or take you straight to the queue that needs attention most.', 'yoro-agent-core' ),
				__( 'Choose a briefing or queue below to continue.', 'yoro-agent-core' ),
				self::get_default_conversational_suggestions()
			);
		}

		if ( self::is_thanks_only( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.thanks',
				__( 'You’re welcome. When you’re ready, I can continue with the next store task.', 'yoro-agent-core' ),
				'',
				[
					[
						'label' => __( 'Show morning briefing', 'yoro-agent-core' ),
						'value' => 'Show morning briefing',
					],
					[
						'label' => __( 'Show customer service queue', 'yoro-agent-core' ),
						'value' => 'Show customer service queue',
					],
				]
			);
		}

		if ( self::is_goodbye_only( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.goodbye',
				__( 'Understood. I’ll be here when you want to continue.', 'yoro-agent-core' ),
				'',
				[]
			);
		}

		return null;
	}

	private static function should_try_ai_conversation_gate( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( '' === $normalized ) {
			return false;
		}

		if (
			self::is_greeting_only( $normalized ) ||
			self::is_capability_support_inquiry( $normalized ) ||
			self::is_capability_question( $normalized ) ||
			self::is_identity_question( $normalized ) ||
			self::is_wellbeing_question( $normalized ) ||
			self::is_thanks_only( $normalized ) ||
			self::is_goodbye_only( $normalized )
		) {
			return true;
		}

		if ( preg_match( '/^(?:can|could|would|do|does|did|is|are|am|who|what|how|why|when)\b/i', $normalized ) ) {
			return true;
		}

		if ( self::resolve_explicit_capability_gap_family( $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:create|make|open)\b.*\b(?:new order|draft order|manual order)\b/i', $normalized ) ) {
			return true;
		}

		if ( self::looks_like_general_out_of_scope_family( $normalized ) ) {
			return true;
		}

		if ( self::looks_like_general_background_request( $normalized ) ) {
			return true;
		}

		if ( self::looks_like_general_non_store_statement( $normalized ) ) {
			return true;
		}

		if ( self::is_ambiguous_action_reference( $normalized ) ) {
			return true;
		}

		if ( str_word_count( $normalized ) <= 12 ) {
			return true;
		}

		return false;
	}

	private static function parse_with_ai_conversation_gate( $command, $normalized, $client_context = [] ) {
		if ( ! class_exists( 'YoRo_AI_Parser' ) || ! method_exists( 'YoRo_AI_Parser', 'parse_conversation' ) ) {
			return null;
		}

		$result = YoRo_AI_Parser::parse_conversation( $command, $client_context );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return null;
		}

		$mode       = sanitize_key( $result['mode'] ?? '' );
		$family     = sanitize_key( $result['intent_family'] ?? '' );
		$surface    = sanitize_key( $result['closest_supported_surface'] ?? '' );
		$capability_family_key = sanitize_key( $result['capability_family_key'] ?? '' );
		$nearest_registry_family = sanitize_key( $result['nearest_registry_family'] ?? '' );
		$requested_capability = sanitize_text_field( $result['requested_capability'] ?? '' );
		$target_object = sanitize_text_field( $result['target_object'] ?? '' );
		$target_type = sanitize_key( $result['target_type'] ?? '' );
		$target_family_guess = sanitize_key( $result['target_family_guess'] ?? '' );
		$support_hypothesis = sanitize_key( $result['support_hypothesis'] ?? '' );
		$reasoning_summary = sanitize_text_field( $result['reasoning_summary'] ?? '' );
		$suggested_action = sanitize_text_field( $result['suggested_action'] ?? '' );
		$suggested_action_params = isset( $result['suggested_action_params'] ) && is_array( $result['suggested_action_params'] ) ? $result['suggested_action_params'] : [];
		$action_confidence = isset( $result['action_confidence'] ) ? (float) $result['action_confidence'] : 0;
		$clarification_needed = ! empty( $result['clarification_needed'] );
		$clarification_question = sanitize_text_field( $result['clarification_question'] ?? '' );
		$reference_target_type = sanitize_key( $result['reference_target_type'] ?? '' );
		$reference_scope = sanitize_key( $result['reference_scope'] ?? '' );
		$reference_selector = sanitize_key( $result['reference_selector'] ?? '' );
		$reference_index = isset( $result['reference_index'] ) ? (int) $result['reference_index'] : -1;
		$reference_confidence = isset( $result['reference_confidence'] ) ? (float) $result['reference_confidence'] : 0;
		$confidence = isset( $result['confidence'] ) ? (float) $result['confidence'] : 0;

		if (
			(
				self::looks_like_general_out_of_scope_family( $normalized ) ||
				self::looks_like_general_background_request( $normalized ) ||
				self::looks_like_general_non_store_statement( $normalized )
			) &&
			'out_of_scope_general' !== $mode
		) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'policy.out_of_scope_general.guard',
				__( 'I’m focused on WooCommerce store operations, so I can’t help with general news or other non-store topics here.', 'yoro-agent-core' ),
				__( 'If you want, I can take you back to a store briefing or the main attention queue instead.', 'yoro-agent-core' ),
				self::build_out_of_scope_general_suggestions(),
				[
					'conversation_topic'  => 'out_of_scope_general',
					'conversation_family' => 'general_knowledge',
					'result_type'         => 'assistant_message',
				]
			);
		}

		$explicit_capability_gap = self::resolve_explicit_capability_gap_family( $normalized );

		if ( $explicit_capability_gap && 'unsupported_request' !== $mode ) {
			$unsupported_decision = self::build_unsupported_response_decision(
				$explicit_capability_gap['parser_family'],
				$command,
				[ 'capability_family_key' => $explicit_capability_gap['capability_key'] ]
			);

			return self::build_assistant_message_response(
				$command,
				$normalized,
				'policy.unsupported_request.guard',
				$unsupported_decision['summary'],
				$unsupported_decision['followup'],
				$unsupported_decision['suggestions'],
				[
					'conversation_topic'      => 'unsupported_request',
					'conversation_family'     => sanitize_key( $explicit_capability_gap['parser_family'] ?? $family ?: 'unsupported_request' ),
					'closest_supported_surface' => sanitize_key( $unsupported_decision['closest_supported_surface'] ?? $explicit_capability_gap['surface'] ?? $surface ),
					'result_type'             => 'assistant_message',
					'developer_handoff_offer' => ! empty( $unsupported_decision['developer_handoff_offer'] ),
					'handoff_request_text'    => sanitize_text_field( $unsupported_decision['handoff_request_text'] ?? $result['unsupported_capability'] ?? $command ),
					'capability_family_key'   => sanitize_key( $unsupported_decision['capability_key'] ?? $explicit_capability_gap['capability_key'] ?? $capability_family_key ),
					'nearest_registry_family' => sanitize_key( $nearest_registry_family ),
					'requested_capability'    => $requested_capability,
					'target_object'           => $target_object,
					'target_type'             => $target_type,
					'target_family_guess'     => $target_family_guess,
					'support_hypothesis'      => $support_hypothesis,
					'reasoning_summary'       => $reasoning_summary,
				]
			);
		}

		$ai_inferred_action_result = self::route_ai_inferred_supported_action(
			$command,
			$normalized,
			$client_context,
			[
				'intent_family'           => $family,
				'closest_supported_surface' => $surface,
				'suggested_action'        => $suggested_action,
				'suggested_action_params' => $suggested_action_params,
				'action_confidence'       => $action_confidence,
				'clarification_needed'    => $clarification_needed,
				'clarification_question'  => $clarification_question,
				'reference_target_type'   => $reference_target_type,
				'reference_scope'         => $reference_scope,
				'reference_selector'      => $reference_selector,
				'reference_index'         => $reference_index,
				'reference_confidence'    => $reference_confidence,
				'requested_capability'    => $requested_capability,
				'target_object'           => $target_object,
				'target_type'             => $target_type,
				'mode'                    => $mode,
			]
		);

		if ( is_array( $ai_inferred_action_result ) ) {
			return $ai_inferred_action_result;
		}

		if ( 'task_request' === $mode ) {
			if ( $confidence < 0.6 ) {
				return null;
			}

			return null;
		}

		if ( $confidence < 0.6 ) {
			return null;
		}

		if ( 'chat_only' === $mode ) {
			$ai_inferred_support_response = self::build_ai_inferred_capability_support_response( $command, $normalized, $client_context, $result );

			if ( is_array( $ai_inferred_support_response ) ) {
				return $ai_inferred_support_response;
			}

			return self::build_assistant_message_response(
				$command,
				$normalized,
				'ai.conversation.chat_only',
				$result['summary'] ?? __( 'Hello. I can help with store operations.', 'yoro-agent-core' ),
				$result['followup'] ?? '',
				self::build_chat_only_suggestions( $surface ),
				[
					'conversation_topic' => 'chat_only',
					'conversation_family' => $family,
					'closest_supported_surface' => $surface,
					'result_type'        => 'assistant_message',
					'capability_family_key' => $capability_family_key,
					'nearest_registry_family' => sanitize_key( $nearest_registry_family ),
					'requested_capability' => $requested_capability,
					'target_object' => $target_object,
					'target_type' => $target_type,
					'target_family_guess' => $target_family_guess,
					'support_hypothesis' => $support_hypothesis,
					'reasoning_summary' => $reasoning_summary,
				]
			);
		}

		if ( 'out_of_scope_general' === $mode ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'ai.conversation.out_of_scope_general',
				$result['summary'] ?: __( 'I’m focused on WooCommerce store operations, so I can’t help with that topic here.', 'yoro-agent-core' ),
				$result['followup'] ?: __( 'If you want, I can take you back to a store briefing or the main attention queue instead.', 'yoro-agent-core' ),
				self::build_out_of_scope_general_suggestions(),
				[
					'conversation_topic' => 'out_of_scope_general',
					'conversation_family' => $family,
					'closest_supported_surface' => $surface,
					'result_type'        => 'assistant_message',
					'capability_family_key' => $capability_family_key,
					'nearest_registry_family' => sanitize_key( $nearest_registry_family ),
					'requested_capability' => $requested_capability,
					'target_object' => $target_object,
					'target_type' => $target_type,
					'target_family_guess' => $target_family_guess,
					'support_hypothesis' => $support_hypothesis,
					'reasoning_summary' => $reasoning_summary,
				]
			);
		}

		if ( 'unsupported_request' === $mode ) {
			$unsupported_decision = self::build_unsupported_response_decision( $family, $command, $result );

			return self::build_assistant_message_response(
				$command,
				$normalized,
				'ai.conversation.unsupported_request',
				$unsupported_decision['summary'],
				$unsupported_decision['followup'],
				$unsupported_decision['suggestions'],
				[
					'conversation_topic'      => 'unsupported_request',
					'conversation_family'     => $family,
					'closest_supported_surface' => sanitize_key( $unsupported_decision['closest_supported_surface'] ?? $surface ),
					'result_type'             => 'assistant_message',
					'developer_handoff_offer' => ! empty( $unsupported_decision['developer_handoff_offer'] ),
					'handoff_request_text'    => sanitize_text_field( $unsupported_decision['handoff_request_text'] ?? $result['unsupported_capability'] ?? $command ),
					'capability_family_key'   => sanitize_key( $unsupported_decision['capability_key'] ?? $capability_family_key ),
					'nearest_registry_family' => sanitize_key( $nearest_registry_family ),
					'requested_capability'    => $requested_capability,
					'target_object'           => $target_object,
					'target_type'             => $target_type,
					'target_family_guess'     => $target_family_guess,
					'support_hypothesis'      => $support_hypothesis,
					'reasoning_summary'       => $reasoning_summary,
				]
			);
		}

		if ( 'clarify' === $mode || ! empty( $result['requires_clarification'] ) ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'message'            => $result['summary'] ?: __( 'I need a little more detail before I act on that. What would you like me to do?', 'yoro-agent-core' ),
				'options'            => self::build_family_clarification_options( $family, $surface ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'confidence'         => $confidence,
				'suggestion'         => $result['followup'] ?? '',
			];
		}

		return null;
	}

	private static function build_ai_inferred_capability_support_response( $command, $normalized, $client_context = [], $result = [] ) {
		$result = is_array( $result ) ? $result : [];
		$family = sanitize_key( $result['intent_family'] ?? '' );

		if ( ! in_array( $family, [ 'capability_question', 'plugin_compatibility', 'carrier_integration', 'tracking_extension', 'shipping_extension', 'external_integration' ], true ) ) {
			return null;
		}

		$capability_family_key = sanitize_key( $result['capability_family_key'] ?? '' );
		$nearest_registry_family = sanitize_key( $result['nearest_registry_family'] ?? '' );
		$support_hypothesis = sanitize_key( $result['support_hypothesis'] ?? '' );
		$target_family_guess = sanitize_key( $result['target_family_guess'] ?? '' );
		$target_object = sanitize_text_field( $result['target_object'] ?? '' );
		$requested_capability = sanitize_text_field( $result['requested_capability'] ?? '' );
		$surface = sanitize_key( $result['closest_supported_surface'] ?? '' );
		$target_name = '' !== $target_object ? $target_object : $requested_capability;
		$effective_capability_key = $capability_family_key;

		if ( '' === $effective_capability_key || 'none' === $effective_capability_key ) {
			$effective_capability_key = $nearest_registry_family;
		}

		if ( '' === $effective_capability_key || 'none' === $effective_capability_key ) {
			return null;
		}

		if ( ! class_exists( 'YoRo_Capability_Registry' ) ) {
			return null;
		}

		$support_record = YoRo_Capability_Registry::build_support_decision(
			$target_family_guess ?: $family,
			$target_name,
			[],
			[
				'capability_key_override' => $effective_capability_key,
				'status_override'         => $support_hypothesis,
				'requested_capability'    => $requested_capability,
			]
		);

		$suggestions = self::build_capability_support_suggestions(
			$target_family_guess ?: $family,
			$normalized,
			[],
			$client_context,
			! empty( $support_record['developer_handoff_offer'] ),
			$support_record
		);

		$handoff_text = self::build_capability_support_handoff_text( $target_name, $target_family_guess ?: $family, [] );

		return self::build_assistant_message_response(
			$command,
			$normalized,
			'ai.conversation.chat_only.capability_inferred',
			$support_record['summary'] ?? ( $result['summary'] ?? __( 'I can help with store operations.', 'yoro-agent-core' ) ),
			$support_record['followup'] ?? ( $result['followup'] ?? '' ),
			$suggestions,
			[
				'conversation_topic'        => 'capability_support',
				'conversation_family'       => $target_family_guess ?: $family,
				'closest_supported_surface' => sanitize_key( $support_record['surface'] ?? $surface ),
				'result_type'               => 'assistant_message',
				'developer_handoff_offer'   => ! empty( $support_record['developer_handoff_offer'] ),
				'handoff_request_text'      => $handoff_text,
				'capability_support_status' => sanitize_key( $support_record['status'] ?? $support_hypothesis ),
				'capability_family_key'     => sanitize_key( $support_record['capability_key'] ?? $effective_capability_key ),
				'nearest_registry_family'   => sanitize_key( $nearest_registry_family ),
				'requested_capability'      => $requested_capability,
				'target_object'             => $target_object,
				'target_type'               => sanitize_key( $result['target_type'] ?? '' ),
				'target_family_guess'       => $target_family_guess,
				'support_hypothesis'        => $support_hypothesis,
				'reasoning_summary'         => sanitize_text_field( $result['reasoning_summary'] ?? '' ),
			]
		);
	}

	private static function is_ai_inferred_read_only_action_allowed( $action ) {
		$action = sanitize_text_field( $action );

		return in_array(
			$action,
			[
				'orders.get_details',
				'orders.search',
				'customers.get_summary',
				'orders.find_by_customer',
				'orders.find_by_identity',
				'store.get_morning_briefing',
				'store.get_daily_briefing',
				'store.get_business_summary',
				'store.get_insights',
				'operations.get_summary',
				'operations.get_attention_queue',
				'operations.get_fulfillment_queue',
				'products.get_inventory_queue',
				'customers.get_service_queue',
				'email_templates.list',
				'orders.get_customer_email_drafts',
				'orders.compose_customer_email',
				'workflow.fulfillment_followup_preview',
				'workflow.customer_followup_preview',
				'workflow.operations_attention_preview',
				'workflow.inventory_pressure_preview',
				'workflow.business_summary_preview',
			],
			true
		);
	}

	private static function is_ai_inferred_confirmation_gated_write_action_allowed( $action ) {
		$action = sanitize_text_field( $action );

		return in_array(
			$action,
			[
				'orders.add_note',
				'orders.update_status',
				'orders.hold',
				'orders.bulk_update_status_with_note',
				'orders.save_customer_email_draft',
				'orders.update_customer_email_draft',
				'orders.delete_customer_email_draft',
				'orders.send_customer_email',
				'email_templates.save',
				'email_templates.delete',
				'workflow.cancel',
			],
			true
		);
	}

	private static function route_ai_inferred_supported_action( $command, $normalized, $client_context = [], $inference = [] ) {
		$inference = is_array( $inference ) ? $inference : [];
		$action = sanitize_text_field( $inference['suggested_action'] ?? '' );
		$intent_family = sanitize_key( $inference['intent_family'] ?? '' );
		$closest_supported_surface = sanitize_key( $inference['closest_supported_surface'] ?? '' );
		$params = isset( $inference['suggested_action_params'] ) && is_array( $inference['suggested_action_params'] ) ? $inference['suggested_action_params'] : [];
		$action_confidence = isset( $inference['action_confidence'] ) ? (float) $inference['action_confidence'] : 0;
		$clarification_needed = ! empty( $inference['clarification_needed'] );
		$clarification_question = sanitize_text_field( $inference['clarification_question'] ?? '' );
		$reference_target_type = sanitize_key( $inference['reference_target_type'] ?? '' );
		$reference_scope = sanitize_key( $inference['reference_scope'] ?? '' );
		$reference_selector = sanitize_key( $inference['reference_selector'] ?? '' );
		$reference_index = isset( $inference['reference_index'] ) ? (int) $inference['reference_index'] : -1;
		$reference_confidence = isset( $inference['reference_confidence'] ) ? (float) $inference['reference_confidence'] : 0;
		$requested_capability = sanitize_text_field( $inference['requested_capability'] ?? '' );
		$target_object = sanitize_text_field( $inference['target_object'] ?? '' );
		$target_type = sanitize_key( $inference['target_type'] ?? '' );
		$mode = sanitize_key( $inference['mode'] ?? '' );
		$derived_actions = [];
		$customer_lookup_params = self::extract_customer_lookup_params_from_command( $command, $normalized, $target_object );
		$grounded_order_details = self::build_grounded_order_details_inference( $command, $normalized, $action, $action_confidence, $requested_capability, $target_object, $target_type );

		if ( '' === $action || 'none' === $action ) {
			$surface_inferred_action = self::infer_ai_read_only_action_from_surface(
				$closest_supported_surface,
				$normalized,
				$params
			);

			if ( is_array( $surface_inferred_action ) ) {
				$action = sanitize_text_field( $surface_inferred_action['action'] ?? '' );
				$params = isset( $surface_inferred_action['params'] ) && is_array( $surface_inferred_action['params'] ) ? $surface_inferred_action['params'] : $params;
				$action_confidence = max( $action_confidence, (float) ( $surface_inferred_action['confidence'] ?? 0 ) );
				$clarification_needed = false;
			}
		}

		if ( is_array( $grounded_order_details ) ) {
			$action = 'orders.get_details';
			$params = $grounded_order_details['params'];
			$action_confidence = max( $action_confidence, (float) $grounded_order_details['confidence'] );
			$clarification_needed = false;
		}

		$ai_reference_action = self::resolve_ai_reference_action(
			$command,
			$normalized,
			$client_context,
			[
				'action' => $action,
				'params' => $params,
				'action_confidence' => $action_confidence,
				'reference_target_type' => $reference_target_type,
				'reference_scope' => $reference_scope,
				'reference_selector' => $reference_selector,
				'reference_index' => $reference_index,
				'reference_confidence' => $reference_confidence,
			]
		);

		if ( is_array( $ai_reference_action ) ) {
			$action = sanitize_text_field( $ai_reference_action['action'] ?? $action );
			$params = isset( $ai_reference_action['params'] ) && is_array( $ai_reference_action['params'] ) ? $ai_reference_action['params'] : $params;
			$action_confidence = max( $action_confidence, (float) ( $ai_reference_action['confidence'] ?? 0 ) );
			$clarification_needed = false;
		}

		$grounded_write_action = self::build_grounded_confirmation_gated_write_inference(
			$command,
			$normalized,
			$action,
			$action_confidence,
			$requested_capability,
			$target_object,
			$target_type,
			$params,
			$client_context
		);

		if ( is_array( $grounded_write_action ) ) {
			$action = sanitize_text_field( $grounded_write_action['action'] );
			$params = $grounded_write_action['params'];
			$action_confidence = max( $action_confidence, (float) $grounded_write_action['confidence'] );
			$clarification_needed = false;
		}

		$grounded_query_action = self::build_grounded_query_action_inference(
			$command,
			$normalized,
			$action,
			$params,
			$client_context
		);

		if ( is_array( $grounded_query_action ) ) {
			$action = sanitize_text_field( $grounded_query_action['action'] );
			$params = isset( $grounded_query_action['params'] ) && is_array( $grounded_query_action['params'] ) ? $grounded_query_action['params'] : $params;
			$derived_actions = isset( $grounded_query_action['actions'] ) && is_array( $grounded_query_action['actions'] ) ? $grounded_query_action['actions'] : $derived_actions;
			$action_confidence = max( $action_confidence, (float) ( $grounded_query_action['confidence'] ?? 0 ) );
			$clarification_needed = false;
		}

		$grounded_workflow_preview = self::build_grounded_workflow_preview_inference(
			$command,
			$normalized,
			$action,
			$action_confidence,
			$client_context,
			[
				'intent_family' => $intent_family,
				'closest_supported_surface' => $closest_supported_surface,
				'reference_target_type' => $reference_target_type,
				'reference_scope' => $reference_scope,
				'reference_selector' => $reference_selector,
				'reference_index' => $reference_index,
				'reference_confidence' => $reference_confidence,
			]
		);

		if ( is_array( $grounded_workflow_preview ) ) {
			$action = sanitize_text_field( $grounded_workflow_preview['action'] );
			$params = $grounded_workflow_preview['params'];
			$action_confidence = max( $action_confidence, (float) $grounded_workflow_preview['confidence'] );
			$clarification_needed = false;
		}

		$grounded_workflow_control = self::build_grounded_workflow_control_inference(
			$command,
			$normalized,
			$action,
			$action_confidence,
			$client_context
		);

		if ( is_array( $grounded_workflow_control ) ) {
			$action = sanitize_text_field( $grounded_workflow_control['action'] );
			$params = $grounded_workflow_control['params'];
			$action_confidence = max( $action_confidence, (float) $grounded_workflow_control['confidence'] );
			$clarification_needed = false;
		}

		$grounded_email_action = self::build_grounded_email_supported_action_inference(
			$command,
			$normalized,
			$action,
			$action_confidence,
			$client_context,
			$params
		);

		if ( is_array( $grounded_email_action ) ) {
			$action = sanitize_text_field( $grounded_email_action['action'] );
			$params = $grounded_email_action['params'];
			$action_confidence = max( $action_confidence, (float) $grounded_email_action['confidence'] );
			$clarification_needed = false;
		}

		if ( 'multi.query_and_apply' !== $action ) {
			$shape_inferred_action = self::infer_ai_read_only_action_from_request_shape(
				$command,
				$normalized,
				$action,
				$params,
				$customer_lookup_params,
				$target_object
			);

			if ( is_array( $shape_inferred_action ) ) {
				$action = sanitize_text_field( $shape_inferred_action['action'] ?? $action );
				$params = isset( $shape_inferred_action['params'] ) && is_array( $shape_inferred_action['params'] ) ? $shape_inferred_action['params'] : $params;
				$action_confidence = max( $action_confidence, (float) ( $shape_inferred_action['confidence'] ?? 0 ) );
				$clarification_needed = false;
			}
		}

		if ( self::looks_like_customer_summary_request( $normalized ) && ! empty( $customer_lookup_params ) ) {
			$action = 'customers.get_summary';
			$params = array_merge( $customer_lookup_params, $params );
		} elseif ( self::looks_like_customer_orders_request( $normalized ) && ! empty( $customer_lookup_params ) ) {
			$action = 'orders.find_by_customer';
			$params = array_merge( $customer_lookup_params, $params );
		} elseif ( in_array( $action, [ 'customers.get_summary', 'orders.find_by_customer' ], true ) && ! empty( $customer_lookup_params ) ) {
			$params = array_merge( $customer_lookup_params, $params );
		}

		$is_read_only_allowed = self::is_ai_inferred_read_only_action_allowed( $action );
		$is_write_allowed = self::is_ai_inferred_confirmation_gated_write_action_allowed( $action );
		$is_query_action_allowed = in_array( $action, [ 'multi.query_and_apply' ], true );

		if ( '' === $action || 'none' === $action || ( ! $is_read_only_allowed && ! $is_write_allowed && ! $is_query_action_allowed ) ) {
			return null;
		}

		if ( ( $is_write_allowed || $is_query_action_allowed ) && 'task_request' !== $mode ) {
			return null;
		}

		if ( ! $is_query_action_allowed ) {
			$params = self::normalize_ai_inferred_action_params(
				$action,
				$params,
				$command,
				$normalized,
				$client_context
			);
		}

		if ( $action_confidence >= 0.85 && ! $clarification_needed ) {
			$parsed = [
				'success'            => true,
				'matched'            => true,
				'action'             => $action,
				'params'             => $params,
				'parser_rule'        => 'ai.inferred_supported_action',
				'parser_source'      => 'ai',
				'confidence'         => $action_confidence,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];

			if ( 'multi.query_and_apply' === $action && ! empty( $derived_actions ) ) {
				$parsed['actions'] = $derived_actions;
			}

			$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context );
			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		if ( $action_confidence >= 0.60 || $clarification_needed ) {
			return self::build_ai_action_clarification_response(
				$command,
				$normalized,
				$action,
				$clarification_question,
				$requested_capability,
				$target_object,
				$is_write_allowed
			);
		}

		return null;
	}

	private static function build_grounded_query_action_inference( $command, $normalized, $action, $params = [], $client_context = [] ) {
		$params = is_array( $params ) ? $params : [];
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized || false === strpos( $normalized, 'order' ) ) {
			return null;
		}

		$limit = self::extract_ai_requested_limit_from_command( $command, 0, 50 );
		$scope_is_latest = (bool) preg_match( '/\b(?:latest|recent|last|newest|lestet)\s+\d+\s+orders?\b/i', $normalized );
		$scope_is_contextual_plural = (
			false !== strpos( $normalized, 'these orders' ) ||
			false !== strpos( $normalized, 'those orders' ) ||
			false !== strpos( $normalized, 'selected orders' ) ||
			false !== strpos( $normalized, 'all these' ) ||
			false !== strpos( $normalized, 'all of them' )
		);

		if ( ! $scope_is_latest && ! $scope_is_contextual_plural ) {
			return null;
		}

		$scope_order_ids = self::resolve_bulk_order_ids_from_context( $normalized, $client_context );
		$status = '';
		$note = '';

		if ( class_exists( 'YoRo_Order_Helper' ) ) {
			$status = YoRo_Order_Helper::detect_status_from_text( $normalized );

			if ( empty( $status ) ) {
				$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
			}

			if ( ! empty( $status ) ) {
				$status = YoRo_Order_Helper::normalize_status_slug( $status );
			}
		}

		if ( preg_match( '/(?:add\s+note|with\s+note|note)\s*:?\s*(.+)$/i', $command, $matches ) ) {
			$note = sanitize_textarea_field( trim( $matches[1] ) );
		}

		if (
			preg_match( '/\b(?:hold|on hold)\b/i', $normalized ) &&
			! preg_match( '/\b(?:show|list|find|search|view|get)\b/i', $normalized )
		) {
			if ( ! empty( $scope_order_ids ) ) {
				return [
					'action'     => 'orders.hold',
					'params'     => [
						'order_ids' => $scope_order_ids,
					],
					'confidence' => 0.9,
				];
			}

			if ( $scope_is_latest && $limit > 0 ) {
				return [
					'action'     => 'multi.query_and_apply',
					'params'     => [
						'limit' => $limit,
					],
					'actions'    => [
						[
							'action' => 'orders.search',
							'params' => [
								'status' => [ 'pending', 'processing', 'on-hold' ],
								'limit'  => $limit,
							],
						],
						[
							'action' => 'orders.hold',
							'params' => [],
						],
					],
					'confidence' => 0.88,
				];
			}
		}

		if (
			! empty( $status ) &&
			preg_match( '/\b(?:change|mark|move|set|update)\b/i', $normalized ) &&
			preg_match( '/\b(?:to|as|status)\b/i', $normalized ) &&
			! preg_match( '/\b(?:show|list|find|search|view|get)\b/i', $normalized )
		) {
			if ( $scope_is_latest && $limit > 0 ) {
				$update_step_params = [
					'status' => $status,
				];

				if ( '' !== $note ) {
					$update_step_params['note'] = $note;
				}

				return [
					'action'     => 'multi.query_and_apply',
					'params'     => [
						'limit' => $limit,
					],
					'actions'    => [
						[
							'action' => 'orders.search',
							'params' => [
								'limit' => $limit,
							],
						],
						[
							'action' => '' !== $note ? 'orders.bulk_update_status_with_note' : 'orders.update_status',
							'params' => $update_step_params,
						],
					],
					'confidence' => 0.9,
				];
			}

			if ( $scope_is_contextual_plural && ! empty( $scope_order_ids ) ) {
				return [
					'action'     => '' !== $note ? 'orders.bulk_update_status_with_note' : 'orders.update_status',
					'params'     => array_filter(
						[
							'order_ids' => $scope_order_ids,
							'status'    => $status,
							'note'      => $note,
						],
						static function( $value ) {
							return ! ( '' === $value || [] === $value );
						}
					),
					'confidence' => 0.9,
				];
			}
		}

		return null;
	}

	private static function infer_ai_read_only_action_from_surface( $surface, $normalized, $params = [] ) {
		$surface = sanitize_key( (string) $surface );
		$normalized = strtolower( trim( (string) $normalized ) );
		$params = is_array( $params ) ? $params : [];

		if ( '' === $surface || 'none' === $surface ) {
			return null;
		}

		$map = [
			'morning_briefing'       => 'store.get_morning_briefing',
			'operations_summary'     => 'operations.get_summary',
			'operations_attention'   => 'operations.get_attention_queue',
			'customer_service_queue' => 'customers.get_service_queue',
			'fulfillment_queue'      => 'operations.get_fulfillment_queue',
			'inventory_queue'        => 'products.get_inventory_queue',
			'business_summary'       => 'store.get_business_summary',
			'latest_orders'          => 'orders.search',
			'order_details'          => 'orders.get_details',
		];

		$action = $map[ $surface ] ?? '';

		if ( '' === $action ) {
			return null;
		}

		if (
			'store.get_daily_briefing' !== $action &&
			preg_match( '/\b(?:daily briefing|daily brief|weekly briefing|weekly brief|monthly briefing|monthly brief|today(?:\'s| s)? briefing|this week briefing|this month briefing)\b/i', $normalized )
		) {
			$action = 'store.get_daily_briefing';
		}

		if (
			'store.get_insights' !== $action &&
			preg_match( '/\b(?:insight|insights|signals|patterns|trend|trends)\b/i', $normalized )
		) {
			$action = 'store.get_insights';
		}

		return [
			'action'     => $action,
			'params'     => $params,
			'confidence' => 0.86,
		];
	}

	private static function infer_ai_read_only_action_from_request_shape( $command, $normalized, $action, $params, $customer_lookup_params = [], $target_object = '' ) {
		$params = is_array( $params ) ? $params : [];
		$customer_lookup_params = is_array( $customer_lookup_params ) ? $customer_lookup_params : [];
		$target_object = sanitize_text_field( (string) $target_object );

		if ( self::looks_like_customer_summary_request( $normalized ) && ! empty( $customer_lookup_params ) ) {
			return [
				'action'     => 'customers.get_summary',
				'params'     => array_merge( $customer_lookup_params, $params ),
				'confidence' => 0.91,
			];
		}

		if ( self::looks_like_customer_orders_request( $normalized ) && ! empty( $customer_lookup_params ) ) {
			return [
				'action'     => 'orders.find_by_customer',
				'params'     => array_merge( $customer_lookup_params, $params ),
				'confidence' => 0.91,
			];
		}

		if ( self::looks_like_orders_search_request( $normalized ) ) {
			return [
				'action'     => 'orders.search',
				'params'     => $params,
				'confidence' => 'orders.search' === $action ? 0.90 : 0.87,
			];
		}

		if ( 'customers.get_summary' === $action && empty( $customer_lookup_params ) && '' !== $target_object ) {
			$target_name = self::sanitize_customer_lookup_name( $target_object );

			if ( '' !== $target_name ) {
				$params['customer_name'] = $target_name;

				return [
					'action'     => 'customers.get_summary',
					'params'     => $params,
					'confidence' => 0.87,
				];
			}
		}

		return null;
	}

	private static function normalize_ai_inferred_action_params( $action, $params, $command, $normalized, $client_context = [] ) {
		$action = sanitize_text_field( $action );
		$params = is_array( $params ) ? $params : [];

		$normalize_limit = static function( $value, $default = 5, $max = 50 ) {
			$value = absint( $value );
			if ( $value <= 0 ) {
				$value = absint( $default );
			}
			return min( max( $value, 1 ), absint( $max ) );
		};

		switch ( $action ) {
			case 'orders.search':
				$normalized_params = [];
				$normalized_params['limit'] = self::extract_ai_requested_limit_from_command(
					$command,
					$normalize_limit( $params['limit'] ?? 10, 10, 50 ),
					50
				);

				if ( isset( $params['status'] ) ) {
					$status_values = is_array( $params['status'] ) ? $params['status'] : [ $params['status'] ];
					$status_values = array_values( array_filter( array_map( [ 'YoRo_Order_Helper', 'normalize_status_slug' ], $status_values ) ) );
					$status_values = array_values( array_filter( $status_values, [ 'YoRo_Order_Helper', 'is_valid_order_status' ] ) );

					if ( ! empty( $status_values ) ) {
						$normalized_params['status'] = $status_values;
					}
				}

				if ( empty( $normalized_params['status'] ) && class_exists( 'YoRo_Order_Helper' ) ) {
					$detected_status = YoRo_Order_Helper::detect_status_from_text( $normalized );

					if ( ! empty( $detected_status ) ) {
						$detected_status = YoRo_Order_Helper::normalize_status_slug( $detected_status );

						if ( YoRo_Order_Helper::is_valid_order_status( $detected_status ) ) {
							$normalized_params['status'] = [ $detected_status ];
						}
					}
				}

				foreach ( [ 'email', 'phone', 'payment_method', 'customer_name', 'billing_country', 'date_from', 'date_to' ] as $key ) {
					if ( empty( $params[ $key ] ) ) {
						continue;
					}

					if ( 'email' === $key ) {
						$normalized_params[ $key ] = sanitize_email( $params[ $key ] );
					} else {
						$normalized_params[ $key ] = sanitize_text_field( $params[ $key ] );
					}
				}

				foreach ( [ 'total_min', 'total_max' ] as $key ) {
					if ( isset( $params[ $key ] ) && '' !== (string) $params[ $key ] ) {
						$normalized_params[ $key ] = (float) $params[ $key ];
					}
				}

				if ( empty( $normalized_params['email'] ) && preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $command, $matches ) ) {
					$normalized_params['email'] = sanitize_email( $matches[0] );
				}

				if ( empty( $normalized_params['phone'] ) && preg_match( '/\b(?:phone|tel|mobile)\s*:?\s*([+\d][\d\s().\-]{5,})/i', $command, $matches ) ) {
					$normalized_params['phone'] = sanitize_text_field( trim( $matches[1] ) );
				}

				if ( empty( $normalized_params['payment_method'] ) && preg_match( '/\b(?:payment|payment method|gateway|paid by|pay by|via)\s*:?\s*([a-zA-Z0-9_\-\s]+)/i', $command, $matches ) ) {
					$normalized_params['payment_method'] = self::normalize_ai_payment_method( $matches[1] );
				}

				if ( empty( $normalized_params['customer_name'] ) && preg_match( '/\b(?:customer|customer name|name)\s*:?\s*([a-zA-Z\s.\'-]{2,})/i', $command, $matches ) ) {
					$normalized_params['customer_name'] = sanitize_text_field( trim( $matches[1] ) );
				}

				if ( empty( $normalized_params['billing_country'] ) && preg_match( '/\b(?:billing country|country)\s*:?\s*([a-zA-Z]{2})\b/i', $command, $matches ) ) {
					$normalized_params['billing_country'] = strtoupper( sanitize_text_field( $matches[1] ) );
				}

				if ( empty( $normalized_params['date_from'] ) && empty( $normalized_params['date_to'] ) ) {
					if ( preg_match( '/\bfrom\s+(\d{4}-\d{2}-\d{2})\s+(?:to|until)\s+(\d{4}-\d{2}-\d{2})/i', $command, $matches ) ) {
						$normalized_params['date_from'] = sanitize_text_field( $matches[1] );
						$normalized_params['date_to']   = sanitize_text_field( $matches[2] );
					} elseif ( preg_match( '/\bafter\s+(\d{4}-\d{2}-\d{2})/i', $command, $matches ) ) {
						$normalized_params['date_from'] = sanitize_text_field( $matches[1] );
					} elseif ( preg_match( '/\bbefore\s+(\d{4}-\d{2}-\d{2})/i', $command, $matches ) ) {
						$normalized_params['date_to'] = sanitize_text_field( $matches[1] );
					}
				}

				if ( ! isset( $normalized_params['total_min'] ) && ! isset( $normalized_params['total_max'] ) ) {
					if ( preg_match( '/\b(?:total|amount)\s+(?:from|between)\s+([\d.,]+)\s+(?:to|and)\s+([\d.,]+)/i', $command, $matches ) ) {
						$normalized_params['total_min'] = (float) str_replace( ',', '', $matches[1] );
						$normalized_params['total_max'] = (float) str_replace( ',', '', $matches[2] );
					} elseif ( preg_match( '/\b(?:total|amount)\s+(?:over|above|greater than|more than)\s+([\d.,]+)/i', $command, $matches ) ) {
						$normalized_params['total_min'] = (float) str_replace( ',', '', $matches[1] );
					} elseif ( preg_match( '/\b(?:total|amount)\s+(?:under|below|less than)\s+([\d.,]+)/i', $command, $matches ) ) {
						$normalized_params['total_max'] = (float) str_replace( ',', '', $matches[1] );
					}
				}

				return $normalized_params;

			case 'customers.get_summary':
				$lookup_params = self::extract_customer_lookup_params_from_command( $command, $normalized );
				return array_filter(
					[
						'limit'         => self::extract_ai_requested_limit_from_command( $command, $normalize_limit( $params['limit'] ?? 20, 20, 50 ), 50 ),
						'email'         => ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : ( ! empty( $lookup_params['email'] ) ? sanitize_email( $lookup_params['email'] ) : '' ),
						'phone'         => ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : ( ! empty( $lookup_params['phone'] ) ? sanitize_text_field( $lookup_params['phone'] ) : '' ),
						'customer_name' => ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : ( ! empty( $lookup_params['customer_name'] ) ? sanitize_text_field( $lookup_params['customer_name'] ) : '' ),
						'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : ( ! empty( $lookup_params['order_id'] ) ? absint( $lookup_params['order_id'] ) : 0 ),
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.find_by_customer':
				$lookup_params = self::extract_customer_lookup_params_from_command( $command, $normalized );
				return array_filter(
					[
						'limit'         => self::extract_ai_requested_limit_from_command( $command, $normalize_limit( $params['limit'] ?? 20, 20, 50 ), 50 ),
						'email'         => ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : ( ! empty( $lookup_params['email'] ) ? sanitize_email( $lookup_params['email'] ) : '' ),
						'phone'         => ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : ( ! empty( $lookup_params['phone'] ) ? sanitize_text_field( $lookup_params['phone'] ) : '' ),
						'customer_id'   => ! empty( $params['customer_id'] ) ? absint( $params['customer_id'] ) : ( ! empty( $lookup_params['customer_id'] ) ? absint( $lookup_params['customer_id'] ) : 0 ),
						'customer_name' => ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : ( ! empty( $lookup_params['customer_name'] ) ? sanitize_text_field( $lookup_params['customer_name'] ) : '' ),
						'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : ( ! empty( $lookup_params['order_id'] ) ? absint( $lookup_params['order_id'] ) : 0 ),
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.find_by_identity':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
						'limit'    => $normalize_limit( $params['limit'] ?? 20, 20, 50 ),
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'store.get_daily_briefing':
				return [
					'window' => self::normalize_ai_window_param( $params['window'] ?? 'today', 'today' ),
					'limit'  => $normalize_limit( $params['limit'] ?? 1, 1, 20 ),
				];

			case 'store.get_business_summary':
				return [
					'window' => self::normalize_ai_window_param( $params['window'] ?? 'last_7_days', 'last_7_days' ),
					'focus'  => self::normalize_ai_focus_param( 'business', $params['focus'] ?? 'overview', 'overview' ),
				];

			case 'store.get_insights':
				return [
					'window' => self::normalize_ai_window_param( $params['window'] ?? 'last_7_days', 'last_7_days' ),
					'limit'  => $normalize_limit( $params['limit'] ?? 5, 5, 20 ),
				];

			case 'operations.get_summary':
				return [
					'window' => self::normalize_ai_window_param( $params['window'] ?? 'last_7_days', 'last_7_days' ),
					'focus'  => self::normalize_ai_focus_param( 'operations', $params['focus'] ?? 'overview', 'overview' ),
				];

			case 'products.get_inventory_queue':
				return [
					'limit' => $normalize_limit( $params['limit'] ?? 5, 5, 20 ),
					'focus' => self::normalize_ai_focus_param( 'inventory', $params['focus'] ?? 'overview', 'overview' ),
				];

			case 'operations.get_fulfillment_queue':
			case 'operations.get_attention_queue':
			case 'customers.get_service_queue':
				return [
					'limit' => $normalize_limit( $params['limit'] ?? 5, 5, 20 ),
					'focus' => self::normalize_ai_focus_param( 'queue', $params['focus'] ?? 'overview', 'overview' ),
				];

			case 'orders.get_customer_email_drafts':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'workflow.fulfillment_followup_preview':
			case 'workflow.customer_followup_preview':
			case 'workflow.operations_attention_preview':
			case 'workflow.inventory_pressure_preview':
			case 'workflow.business_summary_preview':
				$normalized_params = [
					'original_command' => $command,
				];

				if ( ! empty( $params['reference_selector'] ) ) {
					$normalized_params['reference_selector'] = sanitize_key( $params['reference_selector'] );
				}

				if ( isset( $params['reference_index'] ) && (int) $params['reference_index'] >= 0 ) {
					$normalized_params['reference_index'] = (int) $params['reference_index'];
				}

				return $normalized_params;

			case 'workflow.cancel':
				return [
					'original_command' => $command,
				];

			case 'orders.update_status':
				$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
					? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
					: [];

				if ( empty( $order_ids ) && class_exists( 'YoRo_Order_Helper' ) ) {
					$order_ids = array_values( array_filter( array_map( 'absint', YoRo_Order_Helper::extract_order_ids_from_text( $command ) ) ) );
				}

				if ( empty( $order_ids ) && ! empty( $client_context['current_order_id'] ) ) {
					$order_ids = [ absint( $client_context['current_order_id'] ) ];
				}

				$status = ! empty( $params['status'] ) ? sanitize_key( $params['status'] ) : '';

				if ( '' === $status && class_exists( 'YoRo_Order_Helper' ) ) {
					$status = YoRo_Order_Helper::detect_status_from_text( strtolower( trim( $command . ' ' . $normalized ) ) );

					if ( empty( $status ) ) {
						$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
					}

					if ( ! empty( $status ) ) {
						$status = YoRo_Order_Helper::normalize_status_slug( $status );
					}
				}

				return array_filter(
					[
						'order_ids' => $order_ids,
						'order_id'  => ! empty( $order_ids ) ? absint( reset( $order_ids ) ) : 0,
						'status'    => $status,
						'note'      => ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : __( 'Order status updated by YoRo Agent.', 'yoro-agent-core' ),
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.hold':
				$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
					? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
					: [];

				if ( empty( $order_ids ) && class_exists( 'YoRo_Order_Helper' ) ) {
					$order_ids = array_values( array_filter( array_map( 'absint', YoRo_Order_Helper::extract_order_ids_from_text( $command ) ) ) );
				}

				if ( empty( $order_ids ) && ! empty( $client_context['current_order_id'] ) ) {
					$order_ids = [ absint( $client_context['current_order_id'] ) ];
				}

				return array_filter(
					[
						'order_ids' => $order_ids,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.add_note':
				$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
					? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
					: [];

				if ( empty( $order_ids ) && class_exists( 'YoRo_Order_Helper' ) ) {
					$order_ids = array_values( array_filter( array_map( 'absint', YoRo_Order_Helper::extract_order_ids_from_text( $command ) ) ) );
				}

				if ( empty( $order_ids ) && ! empty( $client_context['current_order_id'] ) ) {
					$order_ids = [ absint( $client_context['current_order_id'] ) ];
				}

				$note = ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '';

				if ( '' === $note && preg_match( '/:\s*(.+)$/', $command, $matches ) ) {
					$note = sanitize_textarea_field( trim( $matches[1] ) );
				}

				return array_filter(
					[
						'order_ids' => $order_ids,
						'order_id'  => ! empty( $order_ids ) ? absint( reset( $order_ids ) ) : 0,
						'note'      => $note,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.compose_customer_email':
				$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;

				if ( ! $order_id && class_exists( 'YoRo_Order_Helper' ) ) {
					$order_ids = array_values( array_filter( array_map( 'absint', YoRo_Order_Helper::extract_order_ids_from_text( $command ) ) ) );
					$order_id = ! empty( $order_ids ) ? absint( reset( $order_ids ) ) : 0;
				}

				if ( ! $order_id && ! empty( $client_context['current_order_id'] ) ) {
					$order_id = absint( $client_context['current_order_id'] );
				}

				return array_filter(
					[
						'order_id'    => $order_id,
						'instruction' => ! empty( $params['instruction'] ) ? sanitize_textarea_field( $params['instruction'] ) : sanitize_textarea_field( $command ),
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.send_customer_email':
			case 'orders.save_customer_email_draft':
			case 'orders.update_customer_email_draft':
			case 'orders.delete_customer_email_draft':
			case 'email_templates.save':
			case 'email_templates.delete':
				return self::normalize_ai_email_workflow_action_params( $action, $params, $command, $normalized, $client_context );
		}

		return $params;
	}

	private static function normalize_ai_window_param( $value, $default = 'last_7_days' ) {
		$value = sanitize_key( str_replace( '-', '_', (string) $value ) );

		$map = [
			'today'         => 'today',
			'yesterday'     => 'yesterday',
			'this_week'     => 'last_7_days',
			'weekly'        => 'last_7_days',
			'last_7_days'   => 'last_7_days',
			'this_month'    => 'last_30_days',
			'monthly'       => 'last_30_days',
			'last_30_days'  => 'last_30_days',
		];

		return $map[ $value ] ?? $default;
	}

	private static function normalize_ai_focus_param( $family, $value, $default = 'overview' ) {
		$value = sanitize_key( str_replace( '-', '_', (string) $value ) );

		$maps = [
			'business' => [ 'overview', 'operations', 'customers', 'products' ],
			'operations' => [ 'overview', 'orders', 'backlog', 'shipping' ],
			'inventory' => [ 'overview', 'low_stock', 'out_of_stock', 'backorder' ],
			'queue' => [ 'overview', 'pending', 'processing', 'backlog', 'needs_reply' ],
		];

		$allowed = $maps[ $family ] ?? [ $default ];

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private static function build_grounded_order_details_inference( $command, $normalized, $action, $action_confidence, $requested_capability = '', $target_object = '', $target_type = '' ) {
		$order_ids = class_exists( 'YoRo_Order_Helper' ) ? YoRo_Order_Helper::extract_order_ids_from_text( $command ) : [];

		if ( empty( $order_ids ) ) {
			return null;
		}

		if ( self::has_conflicting_grounded_order_intent( $normalized ) ) {
			return null;
		}

		$is_order_grounded = (
			'orders.get_details' === $action ||
			'order' === sanitize_key( $target_type ) ||
			false !== strpos( strtolower( $requested_capability ), 'order' ) ||
			false !== strpos( strtolower( $target_object ), 'order' )
		);

		if ( ! $is_order_grounded && ! self::looks_like_generic_record_open_request( $normalized ) ) {
			return null;
		}

		return [
			'params'      => [
				'order_ids' => array_values( array_map( 'absint', $order_ids ) ),
			],
			'confidence'  => $action_confidence >= 0.6 ? 0.94 : 0.91,
		];
	}

	private static function has_conflicting_grounded_order_intent( $normalized ) {
		return (bool) preg_match(
			'/\b(?:update|change|set status|status to|hold|on hold|add note|note|refund|email|draft|send|save draft|label|shipment|shipping label|packing slip|track|tracking|sync|connect|export|delete|remove|cancel)\b/i',
			$normalized
		);
	}

	private static function looks_like_generic_record_open_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:show|open|view|get|give|load|pull|bring|inspect|review)\b/i',
			$normalized
		);
	}

	private static function extract_template_name_from_command( $command ) {
		if ( preg_match( '/\btemplate\s+named\s+"([^"]+)"/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/\btemplate\s+named\s+([a-zA-Z0-9][a-zA-Z0-9\s._\-]{1,80})$/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/\bas\s+template\s+"([^"]+)"/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/\bas\s+template\s+([a-zA-Z0-9][a-zA-Z0-9\s._\-]{1,80})$/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		return '';
	}

	private static function looks_like_customer_summary_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:customer\s+(?:profile|summary|record|overview)|profile\s+for\s+(?:this|that|same)?\s*customer|summary\s+for\s+(?:this|that|same)?\s*customer)\b/i',
			$normalized
		);
	}

	private static function looks_like_customer_orders_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:orders?\s+for\s+(?:this|that|same)?\s*customer|this customer(?:\'s| s)?\s+orders|customer\s+orders?)\b/i',
			$normalized
		);
	}

	private static function looks_like_orders_search_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		if ( self::looks_like_customer_orders_request( $normalized ) || self::looks_like_customer_summary_request( $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\border\s*#?\s*\d+\b/i', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:update|change|status|hold|refund|note|email|shipment|shipping label|draft|send|save draft|delete)\b/i', $normalized ) ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:latest|recent|last|newest|lestet|pending|processing|completed|cancelled|canceled|on[- ]hold|orders?\s+list|show\s+orders|find\s+orders|search\s+orders|list\s+orders|review\s+orders)\b/i',
			$normalized
		);
	}

	private static function extract_customer_lookup_params_from_command( $command, $normalized, $target_object = '' ) {
		$params = [];

		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $command, $matches ) ) {
			$params['email'] = sanitize_email( $matches[0] );
		}

		if ( preg_match( '/\b(?:phone|tel|mobile)\s*:?\s*([+\d][\d\s().\-]{5,})/i', $command, $matches ) ) {
			$params['phone'] = sanitize_text_field( trim( $matches[1] ) );
		}

		if ( empty( $params['phone'] ) ) {
			$bare_phone = self::extract_customer_lookup_phone_from_command( $command, $normalized );

			if ( '' !== $bare_phone ) {
				$params['phone'] = $bare_phone;
			}
		}

		if ( preg_match( '/\bcustomer\s*(?:id)?\s*#?:?\s*(\d+)\b/i', $command, $matches ) ) {
			$params['customer_id'] = absint( $matches[1] );
		}

		if ( empty( $params['email'] ) && empty( $params['phone'] ) && empty( $params['customer_id'] ) ) {
			$name = self::extract_customer_lookup_name_from_command( $command, $normalized, $target_object );

			if ( '' !== $name ) {
				$params['customer_name'] = $name;
			}
		}

		return $params;
	}

	private static function extract_customer_lookup_name_from_command( $command, $normalized, $target_object = '' ) {
		$patterns = [
			'/\b(?:customer\s+(?:profile|summary|record|overview)\s+(?:for|of)\s+)([a-zA-Z][a-zA-Z\s.\'\-]{1,80})$/i',
			'/\b(?:show|open|view|get|load|summarize)\s+(?:me\s+)?([a-zA-Z][a-zA-Z\s.\'\-]{1,80})[\'’]s\s+customer\s+(?:profile|summary|record|overview)\b/i',
			'/\b(?:orders?\s+for\s+)([a-zA-Z][a-zA-Z\s.\'\-]{1,80})$/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				$name = self::sanitize_customer_lookup_name( $matches[1] ?? '' );

				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		$target_object = self::sanitize_customer_lookup_name( $target_object );

		if ( '' !== $target_object && ! preg_match( '/\b(?:customer|this|that|same|profile|summary|record|overview)\b/i', strtolower( $target_object ) ) ) {
			return $target_object;
		}

		if (
			false !== strpos( $normalized, 'this customer' ) ||
			false !== strpos( $normalized, 'that customer' ) ||
			false !== strpos( $normalized, 'same customer' )
		) {
			return '';
		}

		return '';
	}

	private static function extract_customer_lookup_phone_from_command( $command, $normalized ) {
		if ( ! self::looks_like_customer_summary_request( $normalized ) && ! self::looks_like_customer_orders_request( $normalized ) ) {
			return '';
		}

		if ( preg_match( '/(?:for|customer|summary|profile|record|overview)\s+([+\d][\d\s().\-]{7,})$/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/([+\d][\d\s().\-]{7,})$/', trim( $command ), $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		return '';
	}

	private static function sanitize_customer_lookup_name( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value, " \t\n\r\0\x0B'’\"" );

		if ( '' === $value ) {
			return '';
		}

		$blocked = [
			'customer',
			'this customer',
			'that customer',
			'same customer',
			'profile',
			'summary',
			'record',
			'overview',
		];

		return in_array( strtolower( $value ), $blocked, true ) ? '' : $value;
	}

	private static function extract_ai_requested_limit_from_command( $command, $default = 10, $max = 50 ) {
		$default = max( 1, absint( $default ) );
		$max     = max( $default, absint( $max ) );

		$patterns = [
			'/\b(?:limit|top|latest|recent|last|newest|lestet)\s+(\d{1,2})\b/i',
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
			'/\b(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
			'/\bfirst\s+(\d{1,2})\s+orders?\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				return min( max( absint( $matches[1] ), 1 ), $max );
			}
		}

		return min( $default, $max );
	}

	private static function normalize_ai_payment_method( $value ) {
		$value = strtolower( trim( wp_strip_all_tags( (string) $value ) ) );

		$map = [
			'stripe'               => 'stripe',
			'credit card'          => 'stripe',
			'card'                 => 'stripe',
			'visa'                 => 'stripe',
			'mastercard'           => 'stripe',
			'paypal'               => 'paypal',
			'pay pal'              => 'paypal',
			'cod'                  => 'cod',
			'cash on delivery'     => 'cod',
			'cash'                 => 'cod',
			'bacs'                 => 'bacs',
			'bank transfer'        => 'bacs',
			'direct bank transfer' => 'bacs',
			'wire transfer'        => 'bacs',
			'cheque'               => 'cheque',
			'check'                => 'cheque',
		];

		return $map[ $value ] ?? sanitize_key( $value );
	}

	private static function normalize_ai_email_workflow_action_params( $action, $params, $command, $normalized, $client_context = [] ) {
		$params = is_array( $params ) ? $params : [];
		$workflow = isset( $client_context['current_workflow'] ) && is_array( $client_context['current_workflow'] )
			? $client_context['current_workflow']
			: [];

		$workflow_order_id = absint( $workflow['order_id'] ?? 0 );
		$workflow_subject  = sanitize_text_field( $workflow['subject'] ?? '' );
		$workflow_body     = sanitize_textarea_field( $workflow['body'] ?? '' );
		$workflow_draft_id = sanitize_key( $workflow['draft_id'] ?? '' );

		switch ( $action ) {
			case 'orders.send_customer_email':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : $workflow_order_id,
						'subject'  => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : $workflow_subject,
						'body'     => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : $workflow_body,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.save_customer_email_draft':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : $workflow_order_id,
						'subject'  => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : $workflow_subject,
						'body'     => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : $workflow_body,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.update_customer_email_draft':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : $workflow_order_id,
						'draft_id' => ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : $workflow_draft_id,
						'subject'  => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : $workflow_subject,
						'body'     => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : $workflow_body,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.delete_customer_email_draft':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : $workflow_order_id,
						'draft_id' => ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : $workflow_draft_id,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'email_templates.save':
				return array_filter(
					[
						'name'    => ! empty( $params['name'] ) ? sanitize_text_field( $params['name'] ) : self::extract_template_name_from_command( $command ),
						'subject' => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : $workflow_subject,
						'body'    => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : $workflow_body,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'email_templates.delete':
				return array_filter(
					[
						'template_id' => ! empty( $params['template_id'] ) ? sanitize_key( $params['template_id'] ) : '',
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);
		}

		return $params;
	}

	private static function build_ai_action_clarification_response( $command, $normalized, $action, $clarification_question = '', $requested_capability = '', $target_object = '', $is_write_action = false ) {
		$action = sanitize_text_field( $action );
		$clarification_question = sanitize_text_field( $clarification_question );

		$action_command_map = [
			'orders.get_details' => 'Show order details for this order',
			'orders.search' => 'Show latest 5 orders',
			'customers.get_summary' => 'Show customer summary',
			'orders.find_by_customer' => 'Show orders for this customer',
			'orders.find_by_identity' => 'Find related orders',
			'store.get_morning_briefing' => 'Show morning briefing',
			'store.get_daily_briefing' => 'Show daily briefing for today',
			'store.get_business_summary' => 'Show business summary for this week',
			'store.get_insights' => 'Show store insights for this week',
			'operations.get_summary' => 'Show operations summary for today',
			'operations.get_attention_queue' => 'Show operations attention queue',
			'operations.get_fulfillment_queue' => 'Show fulfillment queue',
			'products.get_inventory_queue' => 'Show inventory queue',
			'customers.get_service_queue' => 'Show customer service queue',
			'email_templates.list' => 'Show saved email templates',
			'orders.get_customer_email_drafts' => 'Load email drafts for this order',
			'orders.compose_customer_email' => 'Compose a customer email for this order',
			'orders.add_note' => 'Add a note to this order',
			'orders.update_status' => 'Update this order status',
			'orders.hold' => 'Put this order on hold',
			'orders.bulk_update_status_with_note' => 'Update these orders with a note',
			'orders.save_customer_email_draft' => 'Save this email draft',
			'orders.update_customer_email_draft' => 'Update this email draft',
			'orders.delete_customer_email_draft' => 'Delete this email draft',
			'orders.send_customer_email' => 'Send this email',
			'email_templates.save' => 'Save this as a template',
			'email_templates.delete' => 'Delete this template',
			'workflow.cancel' => 'Cancel this workflow',
			'workflow.fulfillment_followup_preview' => 'Preview the next fulfillment follow-up',
			'workflow.customer_followup_preview' => 'Preview the next customer follow-up',
			'workflow.operations_attention_preview' => 'Preview the next operations attention follow-up',
			'workflow.inventory_pressure_preview' => 'Preview the next inventory follow-up',
			'workflow.business_summary_preview' => 'Preview the next business summary follow-up',
		];

		$suggested_command = $action_command_map[ $action ] ?? '';
		$message = '' !== $clarification_question
			? $clarification_question
			: (
				$is_write_action
				? __( 'I think I know what you want to change, but I want to confirm it first.', 'yoro-agent-core' )
				: __( 'I think I know what you want to open, but I want to confirm it first.', 'yoro-agent-core' )
			);
		$followup = '';

		if ( '' !== $suggested_command ) {
			$followup = sprintf(
				__( 'If that is right, say "%s".', 'yoro-agent-core' ),
				$suggested_command
			);
		} elseif ( '' !== $requested_capability || '' !== $target_object ) {
			$followup = __( 'Please restate the store view or record you want me to open.', 'yoro-agent-core' );
		}

		return self::build_assistant_message_response(
			$command,
			$normalized,
			'ai.inferred_read_only_action.clarify',
			$message,
			$followup,
			[],
			[
				'conversation_topic' => 'clarify',
				'conversation_family' => 'ai_inferred_action',
				'result_type' => 'assistant_message',
			]
		);
	}

	private static function looks_like_general_out_of_scope_family( $normalized ) {
		return (bool) preg_match(
			'/\b(?:news|headline|weather|temperature|forecast|sports|match|score|football|soccer|basketball|baseball|tennis|cricket|politics|election|president|prime minister|stock market|share price|bitcoin|crypto|movie|music|recipe|translate|translation|history|science|calendar|reminder|alarm|traffic)\b/i',
			$normalized
		);
	}

	private static function looks_like_general_background_request( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( self::contains_store_scope_markers( $normalized ) ) {
			return false;
		}

		return (bool) preg_match(
			'/^(?:tell me about|who is|what do you know about|give me information about|describe)\b/i',
			$normalized
		);
	}

	private static function looks_like_general_non_store_statement( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( self::contains_store_scope_markers( $normalized ) ) {
			return false;
		}

		return (bool) preg_match(
			'/^(?:[a-z0-9\'" -]{2,})\s+(?:is|are|was|were|seems|looks|sounds)\b/i',
			$normalized
		);
	}

	private static function looks_like_current_store_entity_question( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/^(?:who is|who\'s|tell me about|what do you know about|describe|show me)\s+(?:this|the)\s+(?:customer|buyer|order)\b/i',
			$normalized
		);
	}

	private static function build_current_store_entity_response( $command, $normalized, $client_context = [] ) {
		$order_id = self::resolve_assistant_context_order_id( $client_context );

		if ( $order_id ) {
			$result = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'orders.get_details',
				'params'             => [
					'order_id' => $order_id,
				],
				'parser_rule'        => 'rule.context.current_store_entity',
				'parser_source'      => 'rule',
				'confidence'         => 0.95,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];

			$normalized_result = self::normalize_parsed_result(
				$result,
				$command,
				$normalized,
				$client_context
			);

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		return self::clarification_response(
			$command,
			$normalized,
			__( 'I can look that up, but I need an order or customer reference first. Which order did you mean?', 'yoro-agent-core' )
		);
	}

	private static function contains_store_scope_markers( $normalized ) {
		return (bool) preg_match(
			'/\b(?:order|orders|customer|customers|product|products|queue|briefing|store|inventory|fulfillment|shipping|shipment|tracking|note|status|email|draft|refund|backorder|stock|sku)\b/i',
			(string) $normalized
		);
	}

	private static function is_greeting_only( $normalized ) {
		$normalized = trim( (string) $normalized );

		$normalized = preg_replace( '/[!,?.]+/', ' ', $normalized );
		$normalized = preg_replace( '/\s+/', ' ', $normalized );

		return (bool) preg_match(
			'/^(?:hi|hello|hey|yo|hiya|good morning|good afternoon|good evening|morning|afternoon|evening)(?:\s+(?:there|yoro|team|friend|folks))?\s*$/i',
			$normalized
		);
	}

	private static function is_capability_question( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/^(?:help|help me|what can you do|what do you do|what can you help with|what can you help me with|show me what you can do|what should i ask|what should i ask you|how can you help|how can you help me)(?:\s+(?:yoro))?\??$/i',
			$normalized
		);
	}

	private static function is_capability_support_inquiry( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/\b(?:support|supported|compatible|compatibility|work with|works with|integrate with|connect to)\b.*\b(?:plugin|plugins|extension|extensions|app|apps|tool|tools|service|services|carrier|carriers|platform|platforms)\b|\b(?:plugin|extension|app|tool|service|carrier|platform)\b.*\b(?:support|supported|compatible|compatibility|work with|works with)\b|\b(?:do|does|can|are)\b.*\b(?:support|supported|compatible|work with|works with)\b/i',
			$normalized
		);
	}

	private static function is_identity_question( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/^(?:who are you|what are you|who is this|what is this|who is yoro|what is yoro)(?:\s+(?:exactly))?\??$/i',
			$normalized
		);
	}

	private static function is_wellbeing_question( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/^(?:how are you|how are you doing|how are things|how are you today|hows it going|how is it going)(?:\s+(?:yoro|today|there))?\??$/i',
			$normalized
		);
	}

	private static function is_thanks_only( $normalized ) {
		$normalized = trim( (string) $normalized );
		$normalized = preg_replace( '/[!,?.]+/', '', $normalized );

		return (bool) preg_match(
			'/^(?:thanks|thank you|thanks yoro|thank you yoro|great thanks|awesome thanks|many thanks|thanks a lot)(?:\s+(?:yoro))?$/i',
			$normalized
		);
	}

	private static function is_goodbye_only( $normalized ) {
		$normalized = trim( (string) $normalized );
		$normalized = preg_replace( '/[!,?.]+/', '', $normalized );

		return (bool) preg_match(
			'/^(?:bye|goodbye|see you|see you later|talk later|catch you later|bye yoro|goodbye yoro|ok bye|thanks bye)(?:\s+(?:yoro))?$/i',
			$normalized
		);
	}

	private static function is_ambiguous_action_reference( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/^(?:fix|handle|do|process|check|review|resolve|update|change|manage|take care of|help with)\s+(?:this|that|it|this one|that one)(?:\s+please)?[!.?]*$/i',
			$normalized
		);
	}

	private static function has_ai_reference_resolution_context( $client_context = [] ) {
		if ( ! is_array( $client_context ) ) {
			return false;
		}

		if ( ! empty( $client_context['current_workflow'] ) && is_array( $client_context['current_workflow'] ) ) {
			return true;
		}

		if ( ! empty( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] ) ) {
			return true;
		}

		if ( ! empty( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] ) ) {
			return true;
		}

		$followup_keys = [
			'last_business_followup',
			'last_attention_followup',
			'last_service_followup',
			'last_fulfillment_followup',
			'last_inventory_followup',
			'last_briefing',
		];

		foreach ( $followup_keys as $state_key ) {
			if ( empty( $client_context[ $state_key ] ) || ! is_array( $client_context[ $state_key ] ) ) {
				continue;
			}

			$state = $client_context[ $state_key ];

			if ( ! empty( $state['recommended_sequence'] ) && is_array( $state['recommended_sequence'] ) ) {
				return true;
			}

			if ( ! empty( $state['queues'] ) && is_array( $state['queues'] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function build_capability_support_response( $command, $normalized, $client_context = [] ) {
		$target_name    = self::extract_capability_target_name( $command, $normalized );
		$plugin_match   = self::find_matching_plugin_for_target( $target_name );
		$target_family  = self::classify_capability_target_family( $command, $normalized, $target_name, $plugin_match );
		$support_record = YoRo_Capability_Registry::build_support_decision( $target_family, $target_name, $plugin_match );

		$summary = $support_record['summary'] ?? __( 'I can help with store operations, but I do not verify that compatibility directly yet.', 'yoro-agent-core' );
		$followup = $support_record['followup'] ?? __( 'If you want, I can help with the closest store workflow I do support.', 'yoro-agent-core' );
		$suggestions = self::build_capability_support_suggestions(
			$target_family,
			$normalized,
			$plugin_match,
			$client_context,
			! empty( $support_record['developer_handoff_offer'] ),
			$support_record
		);
		$handoff_text = self::build_capability_support_handoff_text( $target_name, $target_family, $plugin_match );

		return self::build_assistant_message_response(
			$command,
			$normalized,
			'rule.chat.capability_support',
			$summary,
			$followup,
			$suggestions,
			[
				'conversation_topic'        => 'capability_support',
				'conversation_family'       => $target_family,
				'closest_supported_surface' => sanitize_key( $support_record['surface'] ?? 'none' ),
				'result_type'               => 'assistant_message',
				'developer_handoff_offer'   => ! empty( $support_record['developer_handoff_offer'] ),
				'handoff_request_text'      => $handoff_text,
				'capability_support_status' => sanitize_key( $support_record['status'] ?? 'unknown' ),
				'capability_family_key'     => sanitize_key( $support_record['capability_key'] ?? '' ),
			]
		);
	}

	private static function extract_capability_target_name( $command, $normalized ) {
		$candidates = [
			'/^(?:do|does|can|are)\s+(?:you|this|it)\s+(?:support|supported by|work with|works with|integrate with|connect to|handle|use|compatible with)\s+(?:the\s+)?(?:plugin|plugins|extension|extensions|app|apps|tool|tools|service|services|carrier|carriers|platform|platforms)?\s*["“]?(.+?)["”]?\??$/i',
			'/^(?:is|are)\s+(?:you|this|it)\s+(?:compatible with)\s+(?:the\s+)?(?:plugin|plugins|extension|extensions|app|apps|tool|tools|service|services|carrier|carriers|platform|platforms)?\s*["“]?(.+?)["”]?\??$/i',
			'/\b(?:support|supported by|compatible with|work with|works with|integrate with|connect to|handle|use)\s+(?:the\s+)?(?:plugin|plugins|extension|extensions|app|apps|tool|tools|service|services|carrier|carriers|platform|platforms)?\s*["“]?(.+?)["”]?\??$/i',
		];

		foreach ( $candidates as $pattern ) {
			if ( preg_match( $pattern, (string) $command, $matches ) ) {
				$target = sanitize_text_field( trim( $matches[1] ?? '' ) );
				$target = preg_replace( '/^(?:plugin|extension|app|tool|service|carrier|platform)\s+/i', '', (string) $target );
				$target = trim( (string) $target, " \t\n\r\0\x0B?!.," );

				if ( '' !== $target ) {
					return $target;
				}
			}
		}

		$normalized = preg_replace( '/^(?:do|does|can|are|is)\s+(?:you|this|it)\s+/i', '', (string) $normalized );
		$normalized = preg_replace( '/\b(?:support|supported|compatible|compatibility|work with|works with|integrate with|connect to)\b/i', '', (string) $normalized );
		$normalized = preg_replace( '/\b(?:plugin|plugins|extension|extensions|app|apps|tool|tools|service|services|carrier|carriers|platform|platforms)\b/i', '', (string) $normalized );
		$normalized = trim( preg_replace( '/\s+/', ' ', (string) $normalized ) );

		return sanitize_text_field( trim( (string) $normalized, " \t\n\r\0\x0B?!.," ) );
	}

	private static function classify_capability_target_family( $command, $normalized, $target_name, $plugin_match = [] ) {
		$haystack = strtolower( trim( $normalized . ' ' . $target_name . ' ' . $command ) );
		$target_normalized = strtolower( trim( (string) $target_name ) );
		$plugin_name_normalized = ! empty( $plugin_match['name'] ) ? strtolower( trim( (string) $plugin_match['name'] ) ) : '';

		if ( preg_match( '/\b(?:slack|hubspot|crm|mailchimp|zapier|google sheets|google drive|notion)\b/i', $haystack ) ) {
			return 'external_integration';
		}

		if ( preg_match( '/\b(?:aftership|shipment tracking|tracking plugin|tracking)\b/i', $haystack ) ) {
			return 'tracking_extension';
		}

		if ( preg_match( '/\b(?:dhl|fedex|ups|usps)\b/i', $haystack ) ) {
			return 'carrier_integration';
		}

		if ( preg_match( '/\b(?:shipping|shipment|rate|rates|fulfillment)\b/i', $haystack ) ) {
			return 'shipping_extension';
		}

		if (
			'' !== $plugin_name_normalized &&
			'woocommerce' !== $plugin_name_normalized
		) {
			return 'plugin_compatibility';
		}

		if (
			'' !== $target_normalized &&
			preg_match( '/^woocommerce\b/i', $target_normalized ) &&
			! in_array( $target_normalized, [ 'woocommerce', 'woocommerce core', 'woo commerce' ], true )
		) {
			return 'plugin_compatibility';
		}

		if ( preg_match( '/\b(?:plugin|plugins|extension|extensions)\b/i', $haystack ) ) {
			return 'plugin_compatibility';
		}

		if (
			in_array( $target_normalized, [ 'woocommerce', 'woocommerce core', 'woo commerce' ], true ) ||
			(
				'' === $target_normalized &&
				preg_match( '/\bwoocommerce\b/i', $haystack )
			)
		) {
			return 'core_platform';
		}

		return 'capability_question';
	}

	private static function build_capability_support_suggestions( $family, $normalized = '', $plugin_match = [], $client_context = [], $include_handoff = false, $support_record = [] ) {
		$suggestions = [];

		if (
			class_exists( 'YoRo_Capability_Registry' ) &&
			is_array( $support_record ) &&
			! empty( $support_record['capability_key'] )
		) {
			return YoRo_Capability_Registry::build_support_suggestions(
				$support_record,
				$client_context,
				$normalized,
				$include_handoff
			);
		}

		if ( $include_handoff ) {
			return [
				[
					'label' => __( 'Yes, send this to developers', 'yoro-agent-core' ),
					'value' => 'Yes, send this to developers',
				],
			];
		}

		$suggestions[] = [
			'label' => __( 'Show morning briefing', 'yoro-agent-core' ),
			'value' => 'Show morning briefing',
		];
		$suggestions[] = [
			'label' => __( 'Show operations attention queue', 'yoro-agent-core' ),
			'value' => 'Show operations attention queue',
		];
		$suggestions[] = [
			'label' => __( 'Show latest 5 orders', 'yoro-agent-core' ),
			'value' => 'Show latest 5 orders',
		];

		return array_slice( self::dedupe_assistant_suggestions( $suggestions ), 0, 3 );
	}

	private static function build_capability_support_handoff_text( $target_name, $family, $plugin_match = [] ) {
		$target_label = ! empty( $plugin_match['name'] ) ? $plugin_match['name'] : $target_name;
		$target_label = sanitize_text_field( $target_label );

		switch ( sanitize_key( $family ) ) {
			case 'shipping_extension':
				return sprintf( __( 'Support or compatibility for shipping extension: %s', 'yoro-agent-core' ), $target_label );
			case 'tracking_extension':
				return sprintf( __( 'Support or compatibility for tracking extension: %s', 'yoro-agent-core' ), $target_label );
			case 'carrier_integration':
				return sprintf( __( 'Direct carrier integration support for: %s', 'yoro-agent-core' ), $target_label );
			case 'external_integration':
				return sprintf( __( 'External integration support for: %s', 'yoro-agent-core' ), $target_label );
			case 'plugin_compatibility':
				return sprintf( __( 'Plugin compatibility support for: %s', 'yoro-agent-core' ), $target_label );
			default:
				return sprintf( __( 'Capability support question for: %s', 'yoro-agent-core' ), $target_label );
		}
	}

	private static function find_matching_plugin_for_target( $target_name ) {
		$target_name = sanitize_text_field( (string) $target_name );

		if ( '' === $target_name ) {
			return [];
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$target_key = self::normalize_capability_lookup_string( $target_name );
		$target_tokens = array_values( array_filter( explode( ' ', $target_key ) ) );
		$best_match = [];
		$best_score = 0;

		foreach ( (array) $plugins as $plugin_file => $plugin_data ) {
			$plugin_name = sanitize_text_field( $plugin_data['Name'] ?? '' );

			if ( '' === $plugin_name ) {
				continue;
			}

			$plugin_key = self::normalize_capability_lookup_string( $plugin_name );
			$score = 0;

			if ( $plugin_key === $target_key ) {
				$score = 100;
			} elseif ( ! empty( $target_tokens ) ) {
				$plugin_tokens = array_values( array_filter( explode( ' ', $plugin_key ) ) );
				$matched_tokens = 0;

				foreach ( $target_tokens as $token ) {
					if ( in_array( $token, $plugin_tokens, true ) ) {
						$matched_tokens++;
					}
				}

				if ( $matched_tokens === count( $target_tokens ) ) {
					$extra_tokens = max( 0, count( $plugin_tokens ) - count( $target_tokens ) );
					$score = 80 - min( 20, $extra_tokens * 2 );
				} elseif (
					count( $target_tokens ) > 1 &&
					$matched_tokens >= max( 2, count( $target_tokens ) - 1 )
				) {
					$score = 60;
				}
			}

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = [
					'name'   => $plugin_name,
					'file'   => $plugin_file,
					'active' => function_exists( 'is_plugin_active' ) ? is_plugin_active( $plugin_file ) : false,
				];
			}
		}

		return $best_score >= 70 ? $best_match : [];
	}

	private static function normalize_capability_lookup_string( $value ) {
		$value = strtolower( sanitize_text_field( (string) $value ) );
		$value = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return trim( (string) $value );
	}

	private static function is_developer_handoff_confirmation( $normalized ) {
		$normalized = trim( (string) $normalized );
		$last_result = self::get_last_assistant_message_result();

		if (
			empty( $last_result['handoff_request_text'] ) ||
			(
				empty( $last_result['developer_handoff_offer'] ) &&
				! in_array(
					sanitize_key( $last_result['conversation_topic'] ?? '' ),
					[ 'unsupported_request', 'developer_handoff' ],
					true
				)
			)
		) {
			return false;
		}

		return self::looks_like_developer_handoff_phrase( $normalized );
	}

	private static function looks_like_developer_handoff_phrase( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );
		$normalized = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $normalized );
		$normalized = preg_replace( '/\s+/', ' ', $normalized );
		$normalized = trim( (string) $normalized );

		if ( in_array( $normalized, [ 'yes', 'yes please', 'sure', 'sure please', 'please do', 'do it' ], true ) ) {
			return true;
		}

		if (
			false !== strpos( $normalized, 'developers' ) &&
			preg_match( '/\b(send|pass|share)\b/i', $normalized )
		) {
			return true;
		}

		return false;
	}

	private static function get_default_conversational_suggestions() {
		return [
			[
				'label' => __( 'Show morning briefing', 'yoro-agent-core' ),
				'value' => 'Show morning briefing',
			],
			[
				'label' => __( 'Show operations attention queue', 'yoro-agent-core' ),
				'value' => 'Show operations attention queue',
			],
			[
				'label' => __( 'Show customer service queue', 'yoro-agent-core' ),
				'value' => 'Show customer service queue',
			],
		];
	}

	private static function build_out_of_scope_general_suggestions() {
		return [
			[
				'label' => __( 'Show morning briefing', 'yoro-agent-core' ),
				'value' => 'Show morning briefing',
			],
			[
				'label' => __( 'Show operations summary for today', 'yoro-agent-core' ),
				'value' => 'Show operations summary for today',
			],
			[
				'label' => __( 'Show operations attention queue', 'yoro-agent-core' ),
				'value' => 'Show operations attention queue',
			],
		];
	}

	private static function resolve_explicit_capability_gap_family( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( self::looks_like_order_creation_family( $normalized ) ) {
			return [
				'parser_family'  => 'order_creation',
				'capability_key' => 'order_authoring',
				'surface'        => 'latest_orders',
			];
		}

		if ( self::looks_like_shipping_or_shipment_family( $normalized ) ) {
			return [
				'parser_family'  => 'shipping_operation',
				'capability_key' => 'shipping_extensions',
				'surface'        => 'fulfillment_queue',
			];
		}

		if ( self::looks_like_integration_or_sync_family( $normalized ) ) {
			return [
				'parser_family'  => 'external_integration',
				'capability_key' => 'third_party_crm_and_team_integrations',
				'surface'        => 'operations_attention',
			];
		}

		return null;
	}

	private static function build_explicit_capability_gap_response( $command, $normalized, $client_context = [] ) {
		$gap = self::resolve_explicit_capability_gap_family( $normalized );

		if ( ! is_array( $gap ) ) {
			return null;
		}

		$unsupported_decision = self::build_unsupported_response_decision(
			$gap['parser_family'],
			$command,
			[
				'capability_family_key' => $gap['capability_key'],
			]
		);

		return self::build_assistant_message_response(
			$command,
			$normalized,
			'guard.explicit_capability_gap',
			$unsupported_decision['summary'],
			$unsupported_decision['followup'],
			$unsupported_decision['suggestions'],
			[
				'conversation_topic'        => 'unsupported_request',
				'conversation_family'       => sanitize_key( $gap['parser_family'] ),
				'closest_supported_surface' => sanitize_key( $unsupported_decision['closest_supported_surface'] ?? $gap['surface'] ?? '' ),
				'result_type'               => 'assistant_message',
				'developer_handoff_offer'   => ! empty( $unsupported_decision['developer_handoff_offer'] ),
				'handoff_request_text'      => sanitize_text_field( $unsupported_decision['handoff_request_text'] ?? $command ),
				'capability_family_key'     => sanitize_key( $unsupported_decision['capability_key'] ?? $gap['capability_key'] ),
			]
		);
	}

	private static function build_unsupported_response_decision( $family = '', $command = '', $result = [] ) {
		$family = sanitize_key( $family );
		$command = sanitize_text_field( $command );
		$result = is_array( $result ) ? $result : [];

		if ( class_exists( 'YoRo_Capability_Registry' ) ) {
			$capability_family_key = sanitize_key( $result['capability_family_key'] ?? '' );
			$request_text = sanitize_text_field( $result['unsupported_capability'] ?? $command );

			return YoRo_Capability_Registry::build_unsupported_decision(
				$family,
				$result,
				$capability_family_key,
				$request_text
			);
		}
		$capability_family_key = sanitize_key( $result['capability_family_key'] ?? '' );
		$nearest_registry_family = sanitize_key( $result['nearest_registry_family'] ?? '' );
		if ( ( '' === $capability_family_key || 'none' === $capability_family_key ) && '' !== $nearest_registry_family && 'none' !== $nearest_registry_family ) {
			$capability_family_key = $nearest_registry_family;
		}
		$summary = '';
		$followup = '';
		$requested_capability = sanitize_text_field( $result['requested_capability'] ?? '' );
		$target_object = sanitize_text_field( $result['target_object'] ?? '' );

		switch ( $capability_family_key ) {
			case 'order_authoring':
				$summary  = '' !== $requested_capability
					? sprintf( __( 'I can’t handle %s yet.', 'yoro-agent-core' ), strtolower( $requested_capability ) )
					: __( 'I can’t create a new order yet.', 'yoro-agent-core' );
				$followup = __( 'If you want, I can pass that request to the developers. For now, I can help you inspect or manage existing orders.', 'yoro-agent-core' );
				break;
			case 'shipping_extensions':
				$summary  = '' !== $requested_capability
					? sprintf( __( 'I can’t handle %s yet.', 'yoro-agent-core' ), strtolower( $requested_capability ) )
					: __( 'I can’t create shipments or shipping labels yet.', 'yoro-agent-core' );
				$followup = __( 'If you want, I can pass that request to the developers. For now, I can help you review the order or the fulfillment queue.', 'yoro-agent-core' );
				break;
			case 'third_party_crm_and_team_integrations':
				$summary  = '' !== $target_object
					? sprintf( __( 'I can’t connect directly to %s yet.', 'yoro-agent-core' ), $target_object )
					: __( 'I can’t connect to that external tool yet.', 'yoro-agent-core' );
				$followup = __( 'If you want, I can pass that integration request to the developers. For now, I can help with the store work I already support.', 'yoro-agent-core' );
				break;
			default:
				$summary  = sanitize_text_field( $result['summary'] ?? '' );
				$followup = sanitize_text_field( $result['followup'] ?? '' );
				break;
		}

		return [
			'capability_key'            => $capability_family_key,
			'status'                    => sanitize_key( $result['support_hypothesis'] ?? 'unsupported' ),
			'summary'                   => '' !== $summary ? $summary : __( 'I can’t do that yet.', 'yoro-agent-core' ),
			'followup'                  => '' !== $followup ? $followup : __( 'If you want, I can pass that request to the developers.', 'yoro-agent-core' ),
			'closest_supported_surface' => '',
			'developer_handoff_offer'   => true,
			'handoff_request_text'      => sanitize_text_field( $result['unsupported_capability'] ?? $requested_capability ?: $target_object ?: $command ),
			'suggestions'               => [
				[
					'label' => __( 'Yes, send this to developers', 'yoro-agent-core' ),
					'value' => 'Yes, send this to developers',
				],
			],
		];
	}

	private static function build_chat_only_suggestions( $surface = '' ) {
		switch ( sanitize_key( $surface ) ) {
			case 'morning_briefing':
				return [
					[
						'label' => __( 'Show morning briefing', 'yoro-agent-core' ),
						'value' => 'Show morning briefing',
					],
					[
						'label' => __( 'Show operations attention queue', 'yoro-agent-core' ),
						'value' => 'Show operations attention queue',
					],
					[
						'label' => __( 'Show customer service queue', 'yoro-agent-core' ),
						'value' => 'Show customer service queue',
					],
				];
			case 'order_details':
				return [
					[
						'label' => __( 'Show latest 5 orders', 'yoro-agent-core' ),
						'value' => 'Show latest 5 orders',
					],
					[
						'label' => __( 'Show operations attention queue', 'yoro-agent-core' ),
						'value' => 'Show operations attention queue',
					],
					[
						'label' => __( 'Show fulfillment queue', 'yoro-agent-core' ),
						'value' => 'Show fulfillment queue',
					],
				];
			default:
				return self::get_default_conversational_suggestions();
		}
	}

	private static function build_family_clarification_options( $family = '', $surface = '' ) {
		$family  = sanitize_key( $family );
		$surface = sanitize_key( $surface );

		if ( 'ambiguous_action_reference' === $family || 'order_details' === $surface ) {
			return [
				[
					'label'   => __( 'Show order details', 'yoro-agent-core' ),
					'command' => 'Show order details for this order',
				],
				[
					'label'   => __( 'Show fulfillment queue', 'yoro-agent-core' ),
					'command' => 'Show fulfillment queue',
				],
				[
					'label'   => __( 'Show customer service queue', 'yoro-agent-core' ),
					'command' => 'Show customer service queue',
				],
			];
		}

		if ( 'shipping_operation' === $family || 'fulfillment_queue' === $surface ) {
			return [
				[
					'label'   => __( 'Show fulfillment queue', 'yoro-agent-core' ),
					'command' => 'Show fulfillment queue',
				],
				[
					'label'   => __( 'Show order details', 'yoro-agent-core' ),
					'command' => 'Show order details for this order',
				],
				[
					'label'   => __( 'Show operations attention queue', 'yoro-agent-core' ),
					'command' => 'Show operations attention queue',
				],
			];
		}

		if ( 'external_integration' === $family ) {
			return [
				[
					'label'   => __( 'Show operations attention queue', 'yoro-agent-core' ),
					'command' => 'Show operations attention queue',
				],
				[
					'label'   => __( 'Show morning briefing', 'yoro-agent-core' ),
					'command' => 'Show morning briefing',
				],
				[
					'label'   => __( 'Show latest 5 orders', 'yoro-agent-core' ),
					'command' => 'Show latest 5 orders',
				],
			];
		}

		if ( 'store_request' === $family || 'unclear_store_request' === $family ) {
			return [
				[
					'label'   => __( 'Show morning briefing', 'yoro-agent-core' ),
					'command' => 'Show morning briefing',
				],
				[
					'label'   => __( 'Show operations attention queue', 'yoro-agent-core' ),
					'command' => 'Show operations attention queue',
				],
				[
					'label'   => __( 'Show latest 5 orders', 'yoro-agent-core' ),
					'command' => 'Show latest 5 orders',
				],
			];
		}

		return self::get_clarification_options( $family );
	}

	private static function resolve_assistant_context_order_id( $client_context = [] ) {
		if ( ! empty( $client_context['resolved_order_ids'] ) && is_array( $client_context['resolved_order_ids'] ) ) {
			$order_id = absint( reset( $client_context['resolved_order_ids'] ) );

			if ( $order_id ) {
				return $order_id;
			}
		}

		if ( ! empty( $client_context['order_ids'] ) && is_array( $client_context['order_ids'] ) ) {
			$order_id = absint( reset( $client_context['order_ids'] ) );

			if ( $order_id ) {
				return $order_id;
			}
		}

		if ( ! empty( $client_context['order_id'] ) ) {
			$order_id = absint( $client_context['order_id'] );

			if ( $order_id ) {
				return $order_id;
			}
		}

		if ( class_exists( 'YoRo_Context_Provider' ) && method_exists( 'YoRo_Context_Provider', 'get_current_order_id' ) ) {
			$order_id = absint( YoRo_Context_Provider::get_current_order_id() );

			if ( $order_id ) {
				return $order_id;
			}
		}

		return 0;
	}

	private static function looks_like_integration_or_sync_family( $normalized ) {
		return (bool) preg_match(
			'/\b(?:sync|integrate|integration|connect|slack|hubspot|crm|google sheets|export|import|schedule)\b/i',
			(string) $normalized
		);
	}

	private static function looks_like_shipping_or_shipment_family( $normalized ) {
		return (bool) preg_match(
			'/\b(?:shipping label|packing slip|book shipment|create shipment|shipment|fedex|dhl|ups|print label|print packing slip|track shipment|tracking)\b/i',
			(string) $normalized
		);
	}

	private static function looks_like_order_creation_family( $normalized ) {
		return (bool) preg_match(
			'/\b(?:create|make|open)\b.*\b(?:new order|draft order|manual order)\b/i',
			(string) $normalized
		);
	}

	private static function dedupe_assistant_suggestions( $suggestions ) {
		$output = [];
		$seen   = [];

		foreach ( (array) $suggestions as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( $item['label'] ?? '' );
			$value = sanitize_text_field( $item['value'] ?? $item['command'] ?? '' );

			if ( '' === $label || '' === $value ) {
				continue;
			}

			$key = strtolower( $value );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$output[] = [
				'label' => $label,
				'value' => $value,
			];
		}

		return $output;
	}

	private static function get_last_assistant_message_result() {
		$runtime_context = [];

		if ( function_exists( 'get_current_user_id' ) ) {
			$user_id = get_current_user_id();

			if ( $user_id && class_exists( 'YoRo_Conversation_Memory' ) && method_exists( 'YoRo_Conversation_Memory', 'get_runtime_context' ) ) {
				$runtime_context = YoRo_Conversation_Memory::get_runtime_context( $user_id );
			}
		}

		$last_result = isset( $runtime_context['last_result'] ) && is_array( $runtime_context['last_result'] )
			? $runtime_context['last_result']
			: [];

		return $last_result;
	}

	private static function build_developer_handoff_response( $command, $normalized ) {
		$last_result = self::get_last_assistant_message_result();
		$request_text = sanitize_text_field( $last_result['handoff_request_text'] ?? '' );

		if ( '' === $request_text ) {
			$request_text = sanitize_text_field( $last_result['summary'] ?? __( 'Unsupported request', 'yoro-agent-core' ) );
		}

		if ( '' === $request_text ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.unsupported_handoff_missing_context',
				__( 'I can save that for the developers, but I need the original request first.', 'yoro-agent-core' ),
				__( 'Please repeat the request you want me to pass along, and I will capture it from there.', 'yoro-agent-core' ),
				[],
				[
					'conversation_topic' => 'developer_handoff',
					'result_type'        => 'assistant_message',
				]
			);
		}

		$original_command = '';
		$context_order_id = 0;

		if ( function_exists( 'get_current_user_id' ) && class_exists( 'YoRo_Conversation_Memory' ) ) {
			$user_id = get_current_user_id();

			if ( $user_id ) {
				if ( method_exists( 'YoRo_Conversation_Memory', 'get_execution_context' ) ) {
					$execution_context = YoRo_Conversation_Memory::get_execution_context( $user_id );
					$original_command  = sanitize_textarea_field( $execution_context['last_command'] ?? '' );
				}

				if ( method_exists( 'YoRo_Conversation_Memory', 'get_current_order_id' ) ) {
					$context_order_id = absint( YoRo_Conversation_Memory::get_current_order_id( $user_id ) );
				}
			}
		}

		$result = [
			'success'            => true,
			'matched'            => true,
			'action'             => 'assistant.submit_developer_request',
			'params'             => [
				'request_text'              => $request_text,
				'original_command'          => $original_command,
				'unsupported_capability'    => sanitize_text_field( $last_result['handoff_request_text'] ?? '' ),
				'conversation_family'       => sanitize_key( $last_result['conversation_family'] ?? '' ),
				'closest_supported_surface' => sanitize_key( $last_result['closest_supported_surface'] ?? '' ),
				'context_order_id'          => $context_order_id,
			],
			'parser_rule'        => 'rule.chat.unsupported_handoff_confirm',
			'parser_source'      => 'rule',
			'confidence'         => 0.99,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];

		return self::normalize_parsed_result(
			$result,
			$command,
			$normalized
		);
	}

	private static function build_assistant_message_response( $command, $normalized, $parser_rule, $summary, $followup = '', $suggestions = [], $extra_result = [] ) {
		$topic    = is_array( $extra_result ) ? sanitize_key( $extra_result['conversation_topic'] ?? '' ) : '';
		$summary  = self::normalize_assistant_response_copy( $summary, $topic );
		$followup = self::normalize_assistant_response_copy( $followup, $topic );

		$result = array_merge(
			[
				'display_type'       => 'assistant_message',
				'result_type'        => 'assistant_message',
				'summary'            => $summary,
				'assistant_followup' => $followup,
				'suggestions'        => array_slice( self::dedupe_assistant_suggestions( $suggestions ), 0, 3 ),
			],
			is_array( $extra_result ) ? $extra_result : []
		);

		return [
			'success'            => true,
			'matched'            => true,
			'response_only'      => true,
			'parser_rule'        => $parser_rule,
			'parser_source'      => 'rule',
			'confidence'         => 1.0,
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'result'             => $result,
		];
	}

	private static function normalize_assistant_response_copy( $text, $topic = '' ) {
		$text = sanitize_text_field( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		$had_classifier_intro = (bool) preg_match( '/^Chat mode activated(?:\s+for\s+casual\s+conversation)?\.?\s*/i', $text );
		$patterns = [
			'/^Chat mode activated(?:\s+for\s+casual\s+conversation)?\.?\s*/i' => '',
			'/^User asked\b[:\s-]*/i'                => 'You asked ',
			'/^The user asked\b[:\s-]*/i'            => 'You asked ',
			'/^User asks\b[:\s-]*/i'                 => 'You asked ',
			'/^The user asks\b[:\s-]*/i'             => 'You asked ',
			'/^User requested\b[:\s-]*/i'            => 'You asked me to ',
			'/^The user requested\b[:\s-]*/i'        => 'You asked me to ',
			'/^User requests\b[:\s-]*/i'             => 'You asked me to ',
			'/^The user requests\b[:\s-]*/i'         => 'You asked me to ',
			'/^User is asking\b[:\s-]*/i'            => 'You asked ',
			'/^The user is asking\b[:\s-]*/i'        => 'You asked ',
			'/\bthe user\b/i'                        => 'you',
			'/\bUser\b/'                             => 'You',
		];

		foreach ( $patterns as $pattern => $replacement ) {
			$text = preg_replace( $pattern, $replacement, $text );
		}

		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( 'chat_only' === $topic && preg_match( '/\bidentity information\b|\bno WooCommerce task\b/i', $text ) ) {
			return __( 'I don’t know who you are from this chat alone. I can use store context when you ask about orders, customers, or operations.', 'yoro-agent-core' );
		}

		if ( in_array( $topic, [ 'chat_only', 'out_of_scope_general', 'out_of_scope' ], true ) && preg_match( '/\bcasual conversation\b|\bchat mode\b|\bclassifier\b/i', $text ) ) {
			$text = '';
		}

		if ( 'chat_only' === $topic && $had_classifier_intro && preg_match( '/^I can help with WooCommerce store operations\.?$/i', $text ) ) {
			return __( 'Hi. I can help with WooCommerce store operations.', 'yoro-agent-core' );
		}

		if ( '' === $text ) {
			if ( 'chat_only' === $topic ) {
				return __( 'Hi. I can help with WooCommerce store operations.', 'yoro-agent-core' );
			}

			if ( in_array( $topic, [ 'out_of_scope_general', 'out_of_scope' ], true ) ) {
				return __( 'I’m focused on WooCommerce store operations, so I can’t help with that topic here.', 'yoro-agent-core' );
			}
		}

		return sanitize_text_field( $text );
	}

	private static function clarification_response( $command, $normalized, $message ) {
		$options = self::get_clarification_options( $normalized );

		return [
			'success'            => false,
			'matched'            => false,
			'stage'              => 'clarify',
			'message'            => $message,
			'options'            => $options,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function get_clarification_options( $normalized ) {
		$normalized = is_string( $normalized ) ? strtolower( trim( $normalized ) ) : '';

		if (
			false !== strpos( $normalized, 'inventory' ) ||
			false !== strpos( $normalized, 'stock' ) ||
			false !== strpos( $normalized, 'restock' ) ||
			false !== strpos( $normalized, 'product' )
		) {
			return [
				[
					'label'   => __( 'Show inventory queue', 'yoro-agent-core' ),
					'command' => 'Show inventory queue',
				],
				[
					'label'   => __( 'Show fulfillment queue', 'yoro-agent-core' ),
					'command' => 'Show fulfillment queue',
				],
				[
					'label'   => __( 'Show operations attention queue', 'yoro-agent-core' ),
					'command' => 'Show operations attention queue',
				],
			];
		}

		if (
			false !== strpos( $normalized, 'shipping' ) ||
			false !== strpos( $normalized, 'shipment' ) ||
			false !== strpos( $normalized, 'tracking' ) ||
			false !== strpos( $normalized, 'fulfillment' )
		) {
			return [
				[
					'label'   => __( 'Show fulfillment queue', 'yoro-agent-core' ),
					'command' => 'Show fulfillment queue',
				],
				[
					'label'   => __( 'Show operations attention queue', 'yoro-agent-core' ),
					'command' => 'Show operations attention queue',
				],
				[
					'label'   => __( 'Show latest 5 orders', 'yoro-agent-core' ),
					'command' => 'Show latest 5 orders',
				],
			];
		}

		if (
			false !== strpos( $normalized, 'customer' ) ||
			false !== strpos( $normalized, 'email' ) ||
			false !== strpos( $normalized, 'reply' ) ||
			false !== strpos( $normalized, 'draft' )
		) {
			return [
				[
					'label'   => __( 'Show customer service queue', 'yoro-agent-core' ),
					'command' => 'Show customer service queue',
				],
				[
					'label'   => __( 'Show latest 5 orders', 'yoro-agent-core' ),
					'command' => 'Show latest 5 orders',
				],
				[
					'label'   => __( 'Show morning briefing', 'yoro-agent-core' ),
					'command' => 'Show morning briefing',
				],
			];
		}

		if (
			false !== strpos( $normalized, 'business' ) ||
			false !== strpos( $normalized, 'store' ) ||
			false !== strpos( $normalized, 'week' ) ||
			false !== strpos( $normalized, 'month' ) ||
			false !== strpos( $normalized, 'summary' ) ||
			false !== strpos( $normalized, 'new' )
		) {
			return [
				[
					'label'   => __( 'Show business summary for this week', 'yoro-agent-core' ),
					'command' => 'Show business summary for this week',
				],
				[
					'label'   => __( 'Show operations summary for today', 'yoro-agent-core' ),
					'command' => 'Show operations summary for today',
				],
				[
					'label'   => __( 'Show operations attention queue', 'yoro-agent-core' ),
					'command' => 'Show operations attention queue',
				],
			];
		}

		return [
			[
				'label'   => __( 'Show morning briefing', 'yoro-agent-core' ),
				'command' => 'Show morning briefing',
			],
			[
				'label'   => __( 'Show operations attention queue', 'yoro-agent-core' ),
				'command' => 'Show operations attention queue',
			],
			[
				'label'   => __( 'Show latest 5 orders', 'yoro-agent-core' ),
				'command' => 'Show latest 5 orders',
			],
		];
	}
}
