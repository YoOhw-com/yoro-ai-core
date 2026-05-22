<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Recovery_Fallback_Result_Router {

	const RESOLUTION_PATH_DETERMINISTIC_OVERRIDE = 'deterministic_override';
	const RESOLUTION_PATH_RECOVERY_FALLBACK      = 'recovery_fallback';

	public static function maybe_build_semantic_read_only_fallback( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$semantic_read_only_fallback = null;

		if ( class_exists( 'YoRo_AI_Semantic_Retrieval_Helper' ) ) {
			$semantic_read_only_fallback = YoRo_AI_Semantic_Retrieval_Helper::build_contextual_read_only_fallback(
				$command,
				$normalized,
				$client_context
			);

			if ( ! is_array( $semantic_read_only_fallback ) ) {
				$semantic_read_only_fallback = YoRo_AI_Semantic_Retrieval_Helper::build_direct_operations_overview_fallback(
					$command,
					$normalized
				);
			}
		}

		if ( ! is_array( $semantic_read_only_fallback ) ) {
			return null;
		}

		return self::with_resolution_path(
			self::normalize_resolve_validate_result( $semantic_read_only_fallback, $command, $normalized, $client_context, $callbacks ),
			self::RESOLUTION_PATH_RECOVERY_FALLBACK,
			'semantic_read_only_fallback'
		);
	}

	public static function maybe_build_command_shape_query_action_fallback( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if (
			! class_exists( 'YoRo_Command_Shape_Extractor' ) ||
			! class_exists( 'YoRo_Router_Decision_Validator' ) ||
			! class_exists( 'YoRo_AI_Command_Router_Response_Builder' )
		) {
			return null;
		}

		$client_context = is_array( $client_context ) ? $client_context : [];
		$shape          = isset( $client_context['_command_shape'] ) && is_array( $client_context['_command_shape'] )
			? $client_context['_command_shape']
			: YoRo_Command_Shape_Extractor::extract( $command, $client_context );

		if ( ! YoRo_Command_Shape_Extractor::is_order_query_action_shape( $shape ) ) {
			return null;
		}

		$query_action  = isset( $shape['query_action'] ) && is_array( $shape['query_action'] )
			? $shape['query_action']
			: [];
		$target_action = sanitize_text_field( $query_action['action'] ?? '' );

		if ( '' === $target_action ) {
			return null;
		}

		$context_with_shape = array_merge(
			$client_context,
			[
				'_command_shape' => $shape,
			]
		);
		$decision = YoRo_Router_Decision_Validator::normalize(
			[
				'mode'                   => 'task_request',
				'intent_family'          => 'query_and_apply',
				'action'                 => $target_action,
				'params'                 => [],
				'confidence'             => 0.9,
				'missing'                => [],
				'ambiguities'            => [],
				'clarification_question' => '',
				'raw_param_keys'         => [],
				'dropped_param_keys'     => [],
				'params_trimmed_count'   => 0,
			],
			$command,
			$normalized,
			$context_with_shape
		);

		if (
			! is_array( $decision ) ||
			'multi.query_and_apply' !== sanitize_text_field( $decision['action'] ?? '' ) ||
			empty( $decision['actions'] ) ||
			! is_array( $decision['actions'] )
		) {
			return null;
		}

		return self::with_resolution_path(
			[
				'success'              => true,
				'matched'              => true,
				'action'               => 'multi.query_and_apply',
				'intent_family'        => sanitize_key( $decision['intent_family'] ?? 'query_and_apply' ),
				'params'               => isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [],
				'actions'              => $decision['actions'],
				'parser_rule'          => 'shape.query_action',
				'parser_source'        => 'semantic_shape',
				'confidence'           => isset( $decision['confidence'] ) ? (float) $decision['confidence'] : 0.9,
				'original_command'     => $command,
				'normalized_command'   => $normalized,
				'summary'              => sanitize_text_field( $decision['summary'] ?? '' ),
				'reasoning_summary'    => sanitize_text_field( $decision['reasoning_summary'] ?? '' ),
				'raw_param_keys'       => isset( $decision['raw_param_keys'] ) && is_array( $decision['raw_param_keys'] ) ? $decision['raw_param_keys'] : [],
				'dropped_param_keys'   => isset( $decision['dropped_param_keys'] ) && is_array( $decision['dropped_param_keys'] ) ? $decision['dropped_param_keys'] : [],
				'params_trimmed_count' => isset( $decision['params_trimmed_count'] ) ? absint( $decision['params_trimmed_count'] ) : 0,
				'command_shape'        => YoRo_Command_Shape_Extractor::compact_for_prompt( $shape ),
				'command_decision'     => YoRo_AI_Command_Router_Response_Builder::compact_decision( $decision ),
			],
			self::RESOLUTION_PATH_RECOVERY_FALLBACK,
			'command_shape_query_action'
		);
	}

	public static function maybe_build_command_shape_order_search_fallback( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if (
			! class_exists( 'YoRo_Command_Shape_Extractor' ) ||
			! class_exists( 'YoRo_Router_Decision_Validator' ) ||
			! class_exists( 'YoRo_AI_Command_Router_Response_Builder' )
		) {
			return null;
		}

		$client_context = is_array( $client_context ) ? $client_context : [];
		$shape          = isset( $client_context['_command_shape'] ) && is_array( $client_context['_command_shape'] )
			? $client_context['_command_shape']
			: YoRo_Command_Shape_Extractor::extract( $command, $client_context );

		if ( ! YoRo_Command_Shape_Extractor::is_order_list_search_shape( $shape ) ) {
			return null;
		}

		$context_with_shape = array_merge(
			$client_context,
			[
				'_command_shape' => $shape,
			]
		);
		$decision = YoRo_Router_Decision_Validator::normalize(
			[
				'mode'                   => 'clarify',
				'intent_family'          => 'search_orders',
				'action'                 => '',
				'params'                 => [],
				'confidence'             => 0.82,
				'missing'                => [],
				'ambiguities'            => [],
				'clarification_question' => '',
				'raw_param_keys'         => [],
				'dropped_param_keys'     => [],
				'params_trimmed_count'   => 0,
			],
			$command,
			$normalized,
			$context_with_shape
		);

		if (
			! is_array( $decision ) ||
			'orders.search' !== sanitize_text_field( $decision['action'] ?? '' )
		) {
			return null;
		}

		return self::with_resolution_path(
			[
				'success'              => true,
				'matched'              => true,
				'action'               => 'orders.search',
				'intent_family'        => sanitize_key( $decision['intent_family'] ?? 'search_orders' ),
				'params'               => isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [],
				'parser_rule'          => 'shape.orders.search',
				'parser_source'        => 'semantic_shape',
				'confidence'           => isset( $decision['confidence'] ) ? (float) $decision['confidence'] : 0.82,
				'original_command'     => $command,
				'normalized_command'   => $normalized,
				'summary'              => sanitize_text_field( $decision['summary'] ?? '' ),
				'reasoning_summary'    => sanitize_text_field( $decision['reasoning_summary'] ?? '' ),
				'raw_param_keys'       => isset( $decision['raw_param_keys'] ) && is_array( $decision['raw_param_keys'] ) ? $decision['raw_param_keys'] : [],
				'dropped_param_keys'   => isset( $decision['dropped_param_keys'] ) && is_array( $decision['dropped_param_keys'] ) ? $decision['dropped_param_keys'] : [],
				'params_trimmed_count' => isset( $decision['params_trimmed_count'] ) ? absint( $decision['params_trimmed_count'] ) : 0,
				'command_shape'        => YoRo_Command_Shape_Extractor::compact_for_prompt( $shape ),
				'command_decision'     => YoRo_AI_Command_Router_Response_Builder::compact_decision( $decision ),
			],
			self::RESOLUTION_PATH_RECOVERY_FALLBACK,
			'command_shape_order_search'
		);
	}

	public static function maybe_build_command_shape_write_missing_target_fallback( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if (
			! class_exists( 'YoRo_Command_Shape_Extractor' ) ||
			! class_exists( 'YoRo_Router_Decision_Validator' ) ||
			! class_exists( 'YoRo_AI_Command_Router_Response_Builder' )
		) {
			return null;
		}

		$client_context = is_array( $client_context ) ? $client_context : [];
		$shape          = isset( $client_context['_command_shape'] ) && is_array( $client_context['_command_shape'] )
			? $client_context['_command_shape']
			: YoRo_Command_Shape_Extractor::extract( $command, $client_context );

		if ( ! YoRo_Command_Shape_Extractor::is_order_write_missing_target_shape( $shape ) ) {
			return null;
		}

		$write_missing_target = isset( $shape['write_missing_target'] ) && is_array( $shape['write_missing_target'] )
			? $shape['write_missing_target']
			: [];
		$action               = sanitize_text_field( $write_missing_target['action'] ?? '' );

		if ( ! in_array( $action, [ 'orders.hold', 'orders.update_status', 'orders.add_note' ], true ) ) {
			return null;
		}

		$context_with_shape = array_merge(
			$client_context,
			[
				'_command_shape' => $shape,
			]
		);
		$decision           = YoRo_Router_Decision_Validator::normalize(
			[
				'mode'                   => 'task_request',
				'intent_family'          => 'orders.add_note' === $action ? 'add_note' : ( 'orders.hold' === $action ? 'hold_order' : 'update_order_status' ),
				'action'                 => $action,
				'params'                 => [],
				'confidence'             => 0.85,
				'missing'                => [],
				'ambiguities'            => [],
				'clarification_question' => '',
				'raw_param_keys'         => [],
				'dropped_param_keys'     => [],
				'params_trimmed_count'   => 0,
			],
			$command,
			$normalized,
			$context_with_shape
		);

		if (
			! is_array( $decision ) ||
			'clarify' !== sanitize_key( $decision['mode'] ?? '' ) ||
			$action !== sanitize_text_field( $decision['action'] ?? '' )
		) {
			return null;
		}

		$decision['command_shape'] = YoRo_Command_Shape_Extractor::compact_for_prompt( $shape );
		$message                   = sanitize_text_field( $decision['clarification_question'] ?? '' );

		if ( '' === $message ) {
			$message = __( 'Which order IDs should I use?', 'yoro-ai-core' );
		}

		$response = YoRo_AI_Command_Router_Response_Builder::build_clarification_response(
			$command,
			$normalized,
			$decision,
			$message,
			isset( $decision['confidence'] ) ? (float) $decision['confidence'] : 0.85
		);

		$response['parser_rule']   = 'shape.write_missing_target';
		$response['parser_source'] = 'semantic_shape';

		return self::with_resolution_path(
			$response,
			self::RESOLUTION_PATH_RECOVERY_FALLBACK,
			'command_shape_write_missing_target'
		);
	}

	public static function maybe_build_command_shape_explicit_order_write_fallback( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if (
			! class_exists( 'YoRo_Command_Shape_Extractor' ) ||
			! class_exists( 'YoRo_AI_Command_Router_Response_Builder' )
		) {
			return null;
		}

		$client_context = is_array( $client_context ) ? $client_context : [];
		$shape          = isset( $client_context['_command_shape'] ) && is_array( $client_context['_command_shape'] )
			? $client_context['_command_shape']
			: YoRo_Command_Shape_Extractor::extract( $command, $client_context );

		$order_ids = isset( $shape['explicit_order_ids'] ) && is_array( $shape['explicit_order_ids'] )
			? array_values( array_filter( array_map( 'absint', $shape['explicit_order_ids'] ) ) )
			: [];
		$write_intent = isset( $shape['write_intent'] ) && is_array( $shape['write_intent'] )
			? $shape['write_intent']
			: [];
		$action       = sanitize_text_field( $write_intent['action'] ?? '' );

		if (
			'orders' !== sanitize_key( $shape['surface'] ?? '' ) ||
			empty( $order_ids ) ||
			! in_array( $action, [ 'orders.add_note', 'orders.update_status', 'orders.hold' ], true )
		) {
			return null;
		}

		$note          = sanitize_textarea_field( $write_intent['note'] ?? '' );
		$target_status = sanitize_key( $write_intent['target_status'] ?? '' );
		$params        = [
			'order_ids' => $order_ids,
			'order_id'  => absint( reset( $order_ids ) ),
		];
		$compact_shape = YoRo_Command_Shape_Extractor::compact_for_prompt( $shape );

		if ( 'orders.add_note' === $action && '' === $note ) {
			$decision = [
				'mode'                   => 'clarify',
				'intent_family'          => 'add_note',
				'action'                 => 'orders.add_note',
				'params'                 => $params,
				'confidence'             => 0.86,
				'missing'                => [ 'note' ],
				'ambiguities'            => [],
				'clarification_question' => sprintf(
					/* translators: %d: order id */
					__( 'What note should I add to order #%d?', 'yoro-ai-core' ),
					absint( reset( $order_ids ) )
				),
				'raw_param_keys'         => [ 'order_ids' ],
				'dropped_param_keys'     => [],
				'params_trimmed_count'   => 0,
				'command_shape'          => $compact_shape,
			];

			$response = YoRo_AI_Command_Router_Response_Builder::build_clarification_response(
				$command,
				$normalized,
				$decision,
				$decision['clarification_question'],
				0.86
			);
		} else {
			$intent_family  = 'add_note';
			$raw_param_keys = [ 'order_ids', 'note' ];
			$summary        = sprintf(
				/* translators: %d: order id */
				__( 'Add internal note to order #%d.', 'yoro-ai-core' ),
				absint( reset( $order_ids ) )
			);
			$reasoning_summary = __( 'The command names an explicit order and includes note content.', 'yoro-ai-core' );
			$confidence        = 0.9;

			if ( 'orders.add_note' === $action ) {
				$params['note'] = $note;
			} elseif ( 'orders.update_status' === $action ) {
				if ( '' === $target_status ) {
					return null;
				}

				$intent_family  = 'update_order_status';
				$raw_param_keys = [ 'order_ids', 'status' ];
				$params['status'] = $target_status;
				$params['note']   = __( 'Order status updated by YoRo AI.', 'yoro-ai-core' );
				$summary          = sprintf(
					/* translators: 1: order id, 2: order status */
					__( 'Update order #%1$d to %2$s.', 'yoro-ai-core' ),
					absint( reset( $order_ids ) ),
					$target_status
				);
				$reasoning_summary = __( 'The command names explicit order IDs and a valid target status.', 'yoro-ai-core' );
				$confidence        = 0.91;
			} elseif ( 'orders.hold' === $action ) {
				$intent_family  = 'hold_order';
				$raw_param_keys = [ 'order_ids' ];
				$summary        = sprintf(
					/* translators: %d: order id */
					__( 'Put order #%d on hold.', 'yoro-ai-core' ),
					absint( reset( $order_ids ) )
				);
				$reasoning_summary = __( 'The command names explicit order IDs and asks to put them on hold.', 'yoro-ai-core' );
				$confidence        = 0.91;
			}

			$decision       = [
				'mode'                   => 'task_request',
				'intent_family'          => $intent_family,
				'action'                 => $action,
				'params'                 => $params,
				'confidence'             => $confidence,
				'missing'                => [],
				'ambiguities'            => [],
				'clarification_question' => '',
				'raw_param_keys'         => $raw_param_keys,
				'dropped_param_keys'     => [],
				'params_trimmed_count'   => 0,
				'command_shape'          => $compact_shape,
			];
			$response       = [
				'success'              => true,
				'matched'              => true,
				'action'               => $action,
				'intent_family'        => $intent_family,
				'params'               => $params,
				'confidence'           => $confidence,
				'original_command'     => $command,
				'normalized_command'   => $normalized,
				'summary'              => $summary,
				'reasoning_summary'    => $reasoning_summary,
				'raw_param_keys'       => $raw_param_keys,
				'dropped_param_keys'   => [],
				'params_trimmed_count' => 0,
				'command_shape'        => $compact_shape,
				'command_decision'     => YoRo_AI_Command_Router_Response_Builder::compact_decision( $decision ),
			];
		}

		$response['parser_rule']   = 'shape.explicit_order_write';
		$response['parser_source'] = 'semantic_shape';
		$response['command_shape'] = $compact_shape;

		return self::with_resolution_path(
			$response,
			self::RESOLUTION_PATH_RECOVERY_FALLBACK,
			'command_shape_explicit_order_write'
		);
	}

	private static function normalize_resolve_validate_result( $parsed, $command, $normalized, $client_context = [], $callbacks = [] ) {
		$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );

		if (
			class_exists( 'YoRo_Entity_Resolver' ) &&
			class_exists( 'YoRo_Intent_Router' ) &&
			class_exists( 'YoRo_Entity_Validator' )
		) {
			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		return $normalized_result;
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

	private static function with_resolution_path( $result, $resolution_path, $fallback_reason = '' ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$sanitized_path = sanitize_key( (string) $resolution_path );
		$result['resolution_path'] = $sanitized_path;
		$sanitized_fallback_reason = sanitize_key( (string) $fallback_reason );

		if ( '' !== $sanitized_fallback_reason && empty( $result['fallback_reason'] ) ) {
			$result['fallback_reason'] = $sanitized_fallback_reason;
		}

		if ( ! empty( $result['result'] ) && is_array( $result['result'] ) && empty( $result['result']['resolution_path'] ) ) {
			$result['result']['resolution_path'] = $sanitized_path;
		}

		if (
			'' !== $sanitized_fallback_reason &&
			! empty( $result['result'] ) &&
			is_array( $result['result'] ) &&
			empty( $result['result']['fallback_reason'] )
		) {
			$result['result']['fallback_reason'] = $sanitized_fallback_reason;
		}

		return $result;
	}
}
