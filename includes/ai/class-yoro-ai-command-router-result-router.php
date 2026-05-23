<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Command_Router_Result_Router {

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if ( ! class_exists( 'YoRo_AI_Command_Router_Pipeline' ) ) {
			return null;
		}

		$decision = YoRo_AI_Command_Router_Pipeline::route(
			$command,
			$normalized,
			$client_context
		);

		if ( is_wp_error( $decision ) ) {
			return YoRo_AI_Command_Router_Response_Builder::build_clarification_response(
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
				__( 'I could not safely map this command to an available action. Please rephrase it with the target and desired action.', 'yoro-ai-core' ),
				0
			);
		}

		if ( ! is_array( $decision ) ) {
			return null;
		}

		$mode       = sanitize_key( $decision['mode'] ?? 'clarify' );
		$confidence = isset( $decision['confidence'] ) ? (float) $decision['confidence'] : 0;

		if ( 'task_request' !== $mode ) {
			return YoRo_AI_Command_Router_Response_Builder::build_non_action_response(
				$command,
				$normalized,
				$decision,
				$mode,
				$confidence,
				class_exists( 'YoRo_AI_Command_Router_Non_Action_Handlers' )
					? YoRo_AI_Command_Router_Non_Action_Handlers::callbacks()
					: []
			);
		}

		$action = sanitize_text_field( $decision['action'] ?? '' );

		if ( '' === $action ) {
			return YoRo_AI_Command_Router_Response_Builder::build_clarification_response(
				$command,
				$normalized,
				$decision,
				__( 'I understood this as a store task, but I need to know which action you want me to take.', 'yoro-ai-core' ),
				$confidence
			);
		}

		$missing = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];
		$missing = self::normalize_missing_for_action_contract( $action, $missing, $decision );
		$decision['missing'] = $missing;

		if ( ! empty( $missing ) ) {
			return YoRo_AI_Command_Router_Response_Builder::build_clarification_response(
				$command,
				$normalized,
				$decision,
				$decision['clarification_question'] ?: __( 'I need a little more detail before I can run that action.', 'yoro-ai-core' ),
				$confidence
			);
		}

		$threshold = self::get_confidence_threshold( $decision );

		if ( $confidence < $threshold && ! self::should_bypass_low_confidence_clarify( $decision ) ) {
			return YoRo_AI_Command_Router_Response_Builder::build_clarification_response(
				$command,
				$normalized,
				$decision,
				$decision['clarification_question'] ?: __( 'I am not confident enough to run this yet. What did you mean?', 'yoro-ai-core' ),
				$confidence
			);
		}

		$parsed = [
			'success'              => true,
			'matched'              => true,
			'action'               => $action,
			'intent_family'        => sanitize_key( $decision['intent_family'] ?? '' ),
			'params'               => isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [],
			'parser_rule'          => 'ai.command_router.v2',
			'parser_source'        => 'ai_router',
			'confidence'           => $confidence,
			'original_command'     => $command,
			'normalized_command'   => $normalized,
			'language'             => sanitize_text_field( $decision['language'] ?? 'unknown' ),
			'summary'              => sanitize_text_field( $decision['summary'] ?? '' ),
			'reasoning_summary'    => sanitize_text_field( $decision['reasoning_summary'] ?? '' ),
			'meta'                 => isset( $decision['meta'] ) && is_array( $decision['meta'] ) ? $decision['meta'] : [],
			'semantic_frame'       => isset( $decision['semantic_frame'] ) && is_array( $decision['semantic_frame'] ) ? $decision['semantic_frame'] : [],
			'semantic_frame_validator_changes' => isset( $decision['semantic_frame_validator_changes'] ) && is_array( $decision['semantic_frame_validator_changes'] ) ? $decision['semantic_frame_validator_changes'] : [],
			'raw_param_keys'       => isset( $decision['raw_param_keys'] ) && is_array( $decision['raw_param_keys'] ) ? $decision['raw_param_keys'] : [],
			'dropped_param_keys'   => isset( $decision['dropped_param_keys'] ) && is_array( $decision['dropped_param_keys'] ) ? $decision['dropped_param_keys'] : [],
			'params_trimmed_count' => isset( $decision['params_trimmed_count'] ) ? absint( $decision['params_trimmed_count'] ) : 0,
			'command_decision'     => YoRo_AI_Command_Router_Response_Builder::compact_decision( $decision ),
		];

		if ( isset( $decision['actions'] ) && is_array( $decision['actions'] ) ) {
			$parsed['actions'] = $decision['actions'];
		}

		$normalized_result = self::normalize_parsed_result(
			$parsed,
			$command,
			$normalized,
			$client_context,
			$callbacks
		);

		$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
		$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );
		$resolved_result = self::hydrate_result_intent_family_from_action( $resolved_result, $callbacks );
		$resolved_result = class_exists( 'YoRo_AI_Router_Result_Corrector' )
			? YoRo_AI_Router_Result_Corrector::correct_order_status_semantics( $resolved_result, $normalized )
			: $resolved_result;

		$composition_candidate = class_exists( 'YoRo_AI_Action_Composition_Router' )
			? YoRo_AI_Action_Composition_Router::maybe_build_validated_candidate(
				$command,
				$normalized,
				$client_context,
				$resolved_result,
				self::candidate_router_callbacks( $callbacks )
			)
			: null;

		if ( is_array( $composition_candidate ) ) {
			return $composition_candidate;
		}

		$direct_workflow_result = class_exists( 'YoRo_AI_Workflow_Candidate_Router' )
			? YoRo_AI_Workflow_Candidate_Router::maybe_build_validated_direct_ai_workflow_result(
				$command,
				$normalized,
				$client_context,
				$resolved_result,
				self::candidate_router_callbacks( $callbacks )
			)
			: null;

		if ( is_array( $direct_workflow_result ) ) {
			return $direct_workflow_result;
		}

		$workflow_candidate = class_exists( 'YoRo_AI_Workflow_Candidate_Router' )
			? YoRo_AI_Workflow_Candidate_Router::maybe_build_validated_workflow_candidate(
				$command,
				$normalized,
				$client_context,
				$resolved_result,
				self::candidate_router_callbacks( $callbacks )
			)
			: null;

		if ( is_array( $workflow_candidate ) ) {
			return $workflow_candidate;
		}

		$dynamic_workflow_candidate = self::maybe_build_dynamic_workflow_candidate(
			$command,
			$normalized,
			$client_context,
			$resolved_result,
			self::candidate_router_callbacks( $callbacks )
		);

		if ( is_array( $dynamic_workflow_candidate ) ) {
			return $dynamic_workflow_candidate;
		}

		return YoRo_Entity_Validator::validate( $resolved_result );
	}

	private static function maybe_build_dynamic_workflow_candidate( $command, $normalized, $client_context, $resolved_result, $callbacks = [] ) {
		if (
			! class_exists( 'YoRo_AI_Workflow_Planner' ) ||
			! self::should_delegate_to_dynamic_workflow_planner( $resolved_result )
		) {
			return null;
		}

		$workflow_context = self::build_dynamic_workflow_context( $client_context, $resolved_result );
		$candidate = YoRo_AI_Workflow_Planner::parse( $command, $workflow_context );

		if ( ! is_array( $candidate ) ) {
			return null;
		}

		$normalized_result = self::normalize_parsed_result(
			$candidate,
			$command,
			$normalized,
			$workflow_context,
			$callbacks
		);

		if (
			class_exists( 'YoRo_Entity_Resolver' ) &&
			class_exists( 'YoRo_Intent_Router' ) &&
			class_exists( 'YoRo_Entity_Validator' )
		) {
			$resolved = YoRo_Entity_Resolver::resolve( $normalized_result, $workflow_context );
			$resolved = YoRo_Intent_Router::resolve_action( $resolved );

			return YoRo_Entity_Validator::validate( $resolved );
		}

		return $normalized_result;
	}

	private static function should_delegate_to_dynamic_workflow_planner( $result ) {
		if ( ! is_array( $result ) ) {
			return false;
		}

		if ( ! empty( $result['actions'] ) && is_array( $result['actions'] ) ) {
			return false;
		}

		if ( 'ai_router' !== sanitize_key( $result['parser_source'] ?? '' ) ) {
			return false;
		}

		$action = sanitize_text_field( $result['action'] ?? '' );

		if ( '' === $action || 0 === strpos( $action, 'workflow.' ) ) {
			return false;
		}

		$frame = isset( $result['semantic_frame'] ) && is_array( $result['semantic_frame'] )
			? $result['semantic_frame']
			: [];
		$surface = sanitize_key( $frame['surface'] ?? '' );
		$operation = sanitize_key( $frame['operation'] ?? '' );
		$target_type = sanitize_key( $frame['target_type'] ?? '' );

		return 'workflows' === $surface ||
			'workflow' === $target_type ||
			in_array( $operation, [ 'workflow_preview', 'workflow_control' ], true );
	}

	private static function build_dynamic_workflow_context( $client_context, $resolved_result ) {
		$context = is_array( $client_context ) ? $client_context : [];
		$result = is_array( $resolved_result ) ? $resolved_result : [];

		$context['ai_workflow_scope'] = self::dynamic_workflow_scope_for_result( $result );
		$context['ai_router_action'] = sanitize_text_field( $result['action'] ?? '' );

		if ( ! empty( $result['semantic_frame'] ) && is_array( $result['semantic_frame'] ) ) {
			$context['ai_router_semantic_frame'] = $result['semantic_frame'];
		}

		return $context;
	}

	private static function dynamic_workflow_scope_for_result( $result ) {
		$action = sanitize_text_field( $result['action'] ?? '' );
		$frame = isset( $result['semantic_frame'] ) && is_array( $result['semantic_frame'] )
			? $result['semantic_frame']
			: [];
		$operation = sanitize_key( $frame['operation'] ?? '' );

		if (
			in_array( $action, [ 'orders.compose_customer_email', 'orders.render_customer_email_preview', 'orders.save_customer_email_draft' ], true ) ||
			in_array( $operation, [ 'compose_email', 'save_email_draft', 'send_email' ], true )
		) {
			return 'email';
		}

		if ( 'orders.refund_preview' === $action || 'refund_preview' === $operation ) {
			return 'refund';
		}

		if (
			in_array( $action, [ 'orders.add_note', 'orders.hold', 'orders.update_status' ], true ) ||
			in_array( $operation, [ 'add_note', 'hold', 'update_status', 'bulk_update_status' ], true )
		) {
			return 'order_single';
		}

		return 'review';
	}

	private static function normalize_parsed_result( $result, $command, $normalized, $client_context = [], $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		if ( ! empty( $callbacks['normalize_parsed_result'] ) && is_callable( $callbacks['normalize_parsed_result'] ) ) {
			$normalized_result = call_user_func(
				$callbacks['normalize_parsed_result'],
				$result,
				$command,
				$normalized,
				$client_context
			);

			return is_array( $normalized_result ) ? $normalized_result : $result;
		}

		return class_exists( 'YoRo_Parser_Result_Normalizer' )
			? YoRo_Parser_Result_Normalizer::normalize( $result, $command, $normalized, $client_context )
			: $result;
	}

	private static function hydrate_result_intent_family_from_action( $result, $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		if ( ! empty( $callbacks['hydrate_result_intent_family_from_action'] ) && is_callable( $callbacks['hydrate_result_intent_family_from_action'] ) ) {
			$hydrated = call_user_func( $callbacks['hydrate_result_intent_family_from_action'], $result );

			return is_array( $hydrated ) ? $hydrated : $result;
		}

		return class_exists( 'YoRo_Parser_Result_Normalizer' )
			? YoRo_Parser_Result_Normalizer::hydrate_intent_family_from_action( $result )
			: $result;
	}

	private static function candidate_router_callbacks( $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		return [
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) use ( $callbacks ) {
				return self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );
			},
		];
	}

	private static function decision_has_meaningful_param_value( $params, $key ) {
		$params = is_array( $params ) ? $params : [];
		$key    = sanitize_key( $key );

		if ( '' === $key || ! array_key_exists( $key, $params ) ) {
			return false;
		}

		$value = $params[ $key ];

		if ( is_array( $value ) ) {
			return ! empty( array_filter( $value, static function( $item ) {
				if ( is_string( $item ) ) {
					return '' !== trim( $item );
				}

				return null !== $item && false !== $item && '' !== $item;
			} ) );
		}

		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}

		return null !== $value && false !== $value && '' !== $value;
	}

	private static function normalize_missing_for_action_contract( $action, $missing, $decision ) {
		$action = sanitize_text_field( $action );
		$missing = is_array( $missing )
			? array_values( array_filter( array_map( 'sanitize_key', $missing ) ) )
			: [];

		if ( '' === $action || empty( $missing ) || ! class_exists( 'YoRo_Action_Registry' ) ) {
			return $missing;
		}

		$action_def = YoRo_Action_Registry::get( $action );

		if ( ! is_array( $action_def ) ) {
			return $missing;
		}

		$required_params = isset( $action_def['required_params'] ) && is_array( $action_def['required_params'] )
			? array_values( array_filter( array_map( 'sanitize_key', $action_def['required_params'] ) ) )
			: [];

		if ( empty( $required_params ) ) {
			return [];
		}

		$params = isset( $decision['params'] ) && is_array( $decision['params'] )
			? $decision['params']
			: [];
		$missing_required = array_values( array_intersect( $missing, $required_params ) );

		return array_values(
			array_filter(
				$missing_required,
				static function( $key ) use ( $params ) {
					if ( ! array_key_exists( $key, $params ) ) {
						return true;
					}

					$value = $params[ $key ];

					if ( is_array( $value ) ) {
						return empty( array_filter( $value ) );
					}

					return '' === trim( (string) $value );
				}
			)
		);
	}

	private static function should_bypass_low_confidence_clarify( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];
		$mode     = sanitize_key( $decision['mode'] ?? '' );
		$action   = sanitize_text_field( $decision['action'] ?? '' );
		$params   = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$catalog_item = isset( $decision['action_catalog_item'] ) && is_array( $decision['action_catalog_item'] )
			? $decision['action_catalog_item']
			: [];
		$missing = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];
		$ambiguities = isset( $decision['ambiguities'] ) && is_array( $decision['ambiguities'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $decision['ambiguities'] ) ) )
			: [];

		if ( 'task_request' !== $mode || '' === $action ) {
			return false;
		}

		if ( 'orders.search' === $action ) {
			if ( ! empty( $missing ) || ! empty( $ambiguities ) ) {
				return false;
			}

			return self::decision_has_meaningful_param_value( $params, 'limit' ) ||
				self::decision_has_meaningful_param_value( $params, 'status' ) ||
				self::decision_has_meaningful_param_value( $params, 'date_window' ) ||
				self::decision_has_meaningful_param_value( $params, 'date_from' ) ||
				self::decision_has_meaningful_param_value( $params, 'date_to' ) ||
				self::decision_has_meaningful_param_value( $params, 'email' ) ||
				self::decision_has_meaningful_param_value( $params, 'phone' ) ||
				self::decision_has_meaningful_param_value( $params, 'customer_name' ) ||
				self::decision_has_meaningful_param_value( $params, 'payment_method' ) ||
				self::decision_has_meaningful_param_value( $params, 'billing_country' );
		}

		if ( empty( $catalog_item ) ) {
			return false;
		}

		if ( empty( $catalog_item['write_action'] ) && empty( $catalog_item['requires_confirmation'] ) ) {
			return false;
		}

		if ( ! empty( $missing ) || ! empty( $ambiguities ) ) {
			return false;
		}

		$required_params = ! empty( $catalog_item['required_params'] ) && is_array( $catalog_item['required_params'] )
			? array_values( array_filter( array_map( 'sanitize_key', $catalog_item['required_params'] ) ) )
			: [];

		if ( empty( $required_params ) ) {
			return false;
		}

		foreach ( $required_params as $required_param ) {
			if ( ! self::decision_has_meaningful_param_value( $params, $required_param ) ) {
				return false;
			}
		}

		return true;
	}

	private static function get_confidence_threshold( $decision ) {
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
}
