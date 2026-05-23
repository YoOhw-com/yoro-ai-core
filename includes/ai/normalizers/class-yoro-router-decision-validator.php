<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Router_Decision_Validator {

	public static function normalize( $decision, $command, $normalized, $client_context = [] ) {
		$decision       = is_array( $decision ) ? $decision : [];
		$client_context = is_array( $client_context ) ? $client_context : [];
		$shape          = self::resolve_command_shape( $command, $client_context );

		if ( ! empty( $shape ) && class_exists( 'YoRo_Command_Shape_Extractor' ) ) {
			$decision['command_shape'] = YoRo_Command_Shape_Extractor::compact_for_prompt( $shape );
		}

		if ( class_exists( 'YoRo_Semantic_Frame_Normalizer' ) ) {
			$decision['semantic_frame'] = YoRo_Semantic_Frame_Normalizer::normalize( $decision['semantic_frame'] ?? [] );
		}

		$decision = self::guard_missing_add_note_content( $decision, $shape );

		return $decision;
	}

	public static function infer_email_authoring_instruction_from_command( $command, $normalized, $params = [] ) {
		$params = is_array( $params ) ? $params : [];
		$source = '';

		if ( ! empty( $params['instruction'] ) ) {
			$source = trim( sanitize_textarea_field( $params['instruction'] ) );
		}

		if ( '' === $source ) {
			$source = (string) $command;
		}

		$instruction = preg_replace(
			'/\bfor\s+order\s+#?\d+\b/i',
			'',
			$source
		);

		$instruction = preg_replace(
			'/\border\s+#?\d+\b/i',
			'',
			(string) $instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:write|draft|compose|prepare|create|save|send)(?:\s+and\s+(?:save|send))?\b\s*(?:(?:a|an|the)\b\s*)?/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:me|us)\b\s*/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:(?:all\s+(?:of\s+)?(?:them|these|those))|them|these|those)\s+(?=(?:about|regarding|for)\b)/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:(?:all\s+(?:of\s+)?(?:them|these|those))|them|these|those)\s+(?:the\s+)?(?:customer\s+)?(?:email|reply|message)\b[:\s-]*/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:(?:all\s+(?:of\s+)?(?:them|these|those))|them|these|those)\s+(?:an?\s+)?(?:customer\s+)?(?:email|reply|message)\b[:\s-]*/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:an?\s+)?(?:customer\s+)?(?:email|reply|message)\b[:\s-]*/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/\b(?:for\s+)?(?:this|that|the|current)?\s*order\b/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:for|regarding|about)\b[\s:,-]*/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/\s+(?:to|for)\s+(?:(?:all\s+(?:of\s+)?(?:them|these|those))|them|these|those)\s*$/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/\s+(?:and\s+)?(?:save(?:\s+it)?(?:\s+as)?\s+draft|save\s+as\s+draft|send(?:\s+it)?|send\s+to\s+customer)\s*$/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/\b(?:customer\s+)?email\b\s*$/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/\b(?:customer\s+)?email\b(?=\s*[\.\!\?]?\s*$)/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:a|an)\s+/i',
			'',
			$instruction
		);

		$instruction = trim( wp_strip_all_tags( (string) $instruction ) );
		$instruction = preg_replace( '/\s+([\.\!\?])/', '$1', (string) $instruction );
		$instruction = trim( preg_replace( '/^[\s:,-]+|[\s:,-]+$/', '', $instruction ) );

		if ( '' === $instruction ) {
			return '';
		}

		$normalized_instruction = strtolower( $instruction );
		$normalized_command     = strtolower( trim( (string) $normalized ) );

		if (
			$normalized_instruction === $normalized_command ||
			preg_match( '/^(?:email|customer email|reply|message|about it|it|this|that|for|to|about|regarding)$/i', $instruction )
		) {
			return '';
		}

		return sanitize_textarea_field( $instruction );
	}

	private static function resolve_command_shape( $command, $client_context = [] ) {
		$client_context = is_array( $client_context ) ? $client_context : [];

		if ( isset( $client_context['_command_shape'] ) && is_array( $client_context['_command_shape'] ) ) {
			return $client_context['_command_shape'];
		}

		if ( class_exists( 'YoRo_Command_Shape_Extractor' ) ) {
			return YoRo_Command_Shape_Extractor::extract( $command, $client_context );
		}

		return [];
	}

	private static function normalize_query_action_shape_decision( $decision, $shape ) {
		$decision = is_array( $decision ) ? $decision : [];
		$shape    = is_array( $shape ) ? $shape : [];

		if (
			! class_exists( 'YoRo_Command_Shape_Extractor' ) ||
			! YoRo_Command_Shape_Extractor::is_order_query_action_shape( $shape )
		) {
			return $decision;
		}

		$query_action = isset( $shape['query_action'] ) && is_array( $shape['query_action'] )
			? $shape['query_action']
			: [];

		$canonical_decision = self::build_query_action_decision_from_shape( $decision, $shape, $query_action );

		return is_array( $canonical_decision ) ? $canonical_decision : $decision;
	}

	private static function normalize_write_missing_target_shape_decision( $decision, $shape ) {
		$decision = is_array( $decision ) ? $decision : [];
		$shape    = is_array( $shape ) ? $shape : [];

		if (
			! class_exists( 'YoRo_Command_Shape_Extractor' ) ||
			! YoRo_Command_Shape_Extractor::is_order_write_missing_target_shape( $shape )
		) {
			return $decision;
		}

		$write_intent = isset( $shape['write_missing_target'] ) && is_array( $shape['write_missing_target'] ) && ! empty( $shape['write_missing_target'] )
			? $shape['write_missing_target']
			: ( isset( $shape['write_intent'] ) && is_array( $shape['write_intent'] )
				? $shape['write_intent']
				: [] );
		$action = sanitize_text_field( $write_intent['action'] ?? '' );

		if ( ! in_array( $action, [ 'orders.hold', 'orders.update_status', 'orders.add_note' ], true ) ) {
			return $decision;
		}

		$params = [];
		$raw_param_keys = [];
		$target_status = self::normalize_order_status_slug( $write_intent['target_status'] ?? '' );

		if ( 'orders.update_status' === $action ) {
			if ( ! self::is_valid_order_status( $target_status ) ) {
				return $decision;
			}

			$params['status'] = $target_status;
			$raw_param_keys[] = 'status';
		} elseif ( 'orders.add_note' === $action ) {
			$order_ids = isset( $shape['explicit_order_ids'] ) && is_array( $shape['explicit_order_ids'] )
				? array_values( array_filter( array_map( 'absint', $shape['explicit_order_ids'] ) ) )
				: [];

			if ( ! empty( $order_ids ) ) {
				$params['order_ids'] = $order_ids;
				$params['order_id']  = absint( reset( $order_ids ) );
				$raw_param_keys[]    = 'order_ids';
			}
		}

		$quantity = isset( $write_intent['quantity'] ) ? absint( $write_intent['quantity'] ) : absint( $shape['quantity'] ?? 0 );
		$status_label = '' !== $target_status ? str_replace( '-', ' ', $target_status ) : 'on hold';
		$missing = isset( $write_intent['missing'] ) && is_array( $write_intent['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $write_intent['missing'] ) ) )
			: [ 'order_ids' ];

		if ( 'orders.add_note' === $action ) {
			if ( in_array( 'order_ids', $missing, true ) && in_array( 'note', $missing, true ) ) {
				$question = __( 'Which order should receive the note, and what should the note say?', 'yoro-ai-core' );
			} elseif ( in_array( 'order_ids', $missing, true ) ) {
				$question = __( 'Which order should receive the note?', 'yoro-ai-core' );
			} else {
				$order_id = absint( $params['order_id'] ?? 0 );
				$question = $order_id > 0
					? sprintf(
						/* translators: %d: order id */
						__( 'What note should I add to order #%d?', 'yoro-ai-core' ),
						$order_id
					)
					: __( 'What note should I add?', 'yoro-ai-core' );
			}
		} elseif ( class_exists( 'YoRo_Response_UX_Formatter' ) ) {
			$question = YoRo_Response_UX_Formatter::write_missing_target_question(
				$action,
				$target_status,
				$quantity,
				'en'
			);
		} else {
			$question = 'orders.hold' === $action
				? __( 'Which order IDs should I put on hold?', 'yoro-ai-core' )
				: sprintf(
					/* translators: %s: order status label */
					__( 'Which order IDs should I update to %s?', 'yoro-ai-core' ),
					$status_label
				);
		}

		if ( $quantity > 0 && ! class_exists( 'YoRo_Response_UX_Formatter' ) ) {
			$question = 'orders.hold' === $action
				? sprintf(
					/* translators: %d: order count */
					__( 'Which %d order IDs should I put on hold?', 'yoro-ai-core' ),
					$quantity
				)
				: sprintf(
					/* translators: 1: order count, 2: order status label */
					__( 'Which %1$d order IDs should I update to %2$s?', 'yoro-ai-core' ),
					$quantity,
					$status_label
				);
		}

		$decision['mode'] = 'clarify';
		$decision['intent_family'] = 'orders.hold' === $action ? 'hold_order' : 'update_order_status';
		$decision['action'] = $action;
		$decision['params'] = $params;
		$decision['confidence'] = max( (float) ( $decision['confidence'] ?? 0 ), 0.85 );
		$decision['missing'] = $missing;
		$decision['ambiguities'] = [];
		$decision['clarification_question'] = $question;
		$decision['raw_param_keys'] = $raw_param_keys;
		$decision['dropped_param_keys'] = [];
		$decision['params_trimmed_count'] = 0;
		$decision['summary'] = $question;
		$decision['reasoning_summary'] = __( 'The command shape starts with a write action and gives only a count, so exact order IDs are required before executing.', 'yoro-ai-core' );

		return $decision;
	}

	private static function guard_missing_add_note_content( $decision, $shape ) {
		$decision = is_array( $decision ) ? $decision : [];
		$shape    = is_array( $shape ) ? $shape : [];

		if ( ! self::decision_contains_add_note_action( $decision ) ) {
			return $decision;
		}

		$order_ids          = self::extract_add_note_order_ids( $decision, $shape );
		$note               = self::extract_add_note_note( $decision );
		$shape_missing_note = self::shape_reports_missing_add_note_content( $shape );
		$placeholder_note   = self::looks_like_placeholder_add_note( $note );
		$has_note           = '' !== trim( (string) $note );

		if ( ! $placeholder_note && ( ! $shape_missing_note || $has_note ) ) {
			return $decision;
		}

		$params = [];

		if ( ! empty( $order_ids ) ) {
			$params['order_ids'] = $order_ids;
			$params['order_id']  = absint( reset( $order_ids ) );
		}

		$order_id = absint( $params['order_id'] ?? 0 );
		$question = $order_id > 0
			? sprintf(
				/* translators: %d: order id */
				__( 'What note should I add to order #%d?', 'yoro-ai-core' ),
				$order_id
			)
			: __( 'What note should I add?', 'yoro-ai-core' );

		$decision['mode']                   = 'clarify';
		$decision['intent_family']          = 'add_note';
		$decision['action']                 = 'orders.add_note';
		$decision['params']                 = $params;
		$decision['missing']                = [ 'note' ];
		$decision['ambiguities']            = [];
		$decision['clarification_question'] = $question;
		$decision['summary']                = $question;
		$decision['reasoning_summary']      = __( 'The add-note route is valid, but the command does not include literal note content.', 'yoro-ai-core' );
		$decision['requires_confirmation']  = false;
		$decision['raw_param_keys']         = ! empty( $order_ids ) ? [ 'order_ids' ] : [];
		$decision['dropped_param_keys']     = [];
		$decision['params_trimmed_count']   = 0;
		$decision['action_plan']            = [
			'type'   => 'none',
			'action' => null,
			'params' => [],
			'steps'  => [],
		];

		return $decision;
	}

	private static function decision_contains_add_note_action( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];

		if ( 'orders.add_note' === sanitize_text_field( $decision['action'] ?? '' ) ) {
			return true;
		}

		$action_plan = isset( $decision['action_plan'] ) && is_array( $decision['action_plan'] )
			? $decision['action_plan']
			: [];

		foreach ( (array) ( $action_plan['steps'] ?? [] ) as $step ) {
			if ( is_array( $step ) && 'orders.add_note' === sanitize_text_field( $step['action'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function extract_add_note_note( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];
		$params   = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];

		if ( 'orders.add_note' === sanitize_text_field( $decision['action'] ?? '' ) && isset( $params['note'] ) ) {
			return sanitize_textarea_field( $params['note'] );
		}

		$action_plan = isset( $decision['action_plan'] ) && is_array( $decision['action_plan'] )
			? $decision['action_plan']
			: [];

		foreach ( (array) ( $action_plan['steps'] ?? [] ) as $step ) {
			if ( ! is_array( $step ) || 'orders.add_note' !== sanitize_text_field( $step['action'] ?? '' ) ) {
				continue;
			}

			$step_params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];

			if ( isset( $step_params['note'] ) ) {
				return sanitize_textarea_field( $step_params['note'] );
			}
		}

		return '';
	}

	private static function extract_add_note_order_ids( $decision, $shape ) {
		$decision  = is_array( $decision ) ? $decision : [];
		$shape     = is_array( $shape ) ? $shape : [];
		$order_ids = [];

		$params = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$order_ids = array_merge( $order_ids, self::extract_order_ids_from_params( $params ) );

		$action_plan = isset( $decision['action_plan'] ) && is_array( $decision['action_plan'] )
			? $decision['action_plan']
			: [];

		foreach ( (array) ( $action_plan['steps'] ?? [] ) as $step ) {
			if ( ! is_array( $step ) || 'orders.add_note' !== sanitize_text_field( $step['action'] ?? '' ) ) {
				continue;
			}

			$order_ids = array_merge(
				$order_ids,
				self::extract_order_ids_from_params( isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [] )
			);
		}

		$constraints = isset( $decision['semantic_frame']['constraints'] ) && is_array( $decision['semantic_frame']['constraints'] )
			? $decision['semantic_frame']['constraints']
			: [];
		$order_ids = array_merge( $order_ids, self::extract_order_ids_from_params( $constraints ) );

		if ( isset( $shape['explicit_order_ids'] ) && is_array( $shape['explicit_order_ids'] ) ) {
			$order_ids = array_merge( $order_ids, array_map( 'absint', $shape['explicit_order_ids'] ) );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
	}

	private static function extract_order_ids_from_params( $params ) {
		$params = is_array( $params ) ? $params : [];
		$ids    = [];

		if ( isset( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$ids = array_merge( $ids, array_map( 'absint', $params['order_ids'] ) );
		}

		if ( ! empty( $params['order_id'] ) ) {
			$ids[] = absint( $params['order_id'] );
		}

		return array_values( array_filter( $ids ) );
	}

	private static function shape_reports_missing_add_note_content( $shape ) {
		$shape = is_array( $shape ) ? $shape : [];
		$write_missing_target = isset( $shape['write_missing_target'] ) && is_array( $shape['write_missing_target'] )
			? $shape['write_missing_target']
			: [];

		if ( 'orders.add_note' !== sanitize_text_field( $write_missing_target['action'] ?? '' ) ) {
			return false;
		}

		$missing = isset( $write_missing_target['missing'] ) && is_array( $write_missing_target['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $write_missing_target['missing'] ) ) )
			: [];

		return in_array( 'note', $missing, true );
	}

	private static function looks_like_placeholder_add_note( $note ) {
		$note = strtolower( trim( wp_strip_all_tags( (string) $note ) ) );
		$note = trim( preg_replace( '/\s+/', ' ', $note ), " \t\n\r\0\x0B." );

		if ( '' === $note ) {
			return false;
		}

		$patterns = [
			'/^add (?:an? )?(?:internal )?note (?:for|to) (?:the )?order #?\d+ as requested$/',
			'/^add (?:an? )?(?:internal )?note as requested$/',
			'/^(?:internal )?note (?:added|created) (?:for|to) (?:the )?order #?\d+ as requested$/',
			'/^(?:internal )?note (?:added|created) as requested$/',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $note ) ) {
				return true;
			}
		}

		return false !== strpos( $note, 'as requested' ) &&
			false !== strpos( $note, 'note' ) &&
			strlen( $note ) <= 90;
	}

	private static function build_query_action_decision_from_shape( $decision, $shape, $query_action ) {
		$decision     = is_array( $decision ) ? $decision : [];
		$shape        = is_array( $shape ) ? $shape : [];
		$query_action = is_array( $query_action ) ? $query_action : [];
		$target_action = sanitize_text_field( $query_action['action'] ?? '' );

		if ( ! in_array( $target_action, [ 'orders.hold', 'orders.update_status', 'orders.bulk_update_status_with_note' ], true ) ) {
			return null;
		}

		$limit = isset( $query_action['limit'] ) ? absint( $query_action['limit'] ) : absint( $shape['quantity'] ?? 0 );

		if ( $limit <= 0 ) {
			return null;
		}

		$limit = min( max( $limit, 1 ), 50 );

		$search_params = [
			'limit' => $limit,
		];
		$search_status = self::normalize_order_status_list( $query_action['search_status'] ?? [] );

		if ( ! empty( $search_status ) ) {
			$search_params['status'] = $search_status;
		}

		$apply_params = [];

		if ( in_array( $target_action, [ 'orders.update_status', 'orders.bulk_update_status_with_note' ], true ) ) {
			$status = self::normalize_order_status_slug( $query_action['target_status'] ?? '' );

			if ( ! self::is_valid_order_status( $status ) ) {
				return null;
			}

			$apply_params['status'] = $status;

			$note = sanitize_textarea_field( $query_action['note'] ?? '' );

			if ( '' !== $note ) {
				$apply_params['note'] = $note;
				$target_action = 'orders.bulk_update_status_with_note';
			} else {
				$target_action = 'orders.update_status';
			}
		} else {
			$target_action = 'orders.hold';
		}

		$decision['mode'] = 'task_request';
		$decision['intent_family'] = 'query_and_apply';
		$decision['action'] = 'multi.query_and_apply';
		$decision['params'] = [
			'limit' => $limit,
		];
		$decision['actions'] = [
			[
				'action' => 'orders.search',
				'params' => $search_params,
			],
			[
				'action' => $target_action,
				'params' => $apply_params,
			],
		];
		$decision['confidence'] = max( (float) ( $decision['confidence'] ?? 0 ), 0.9 );
		$decision['missing'] = [];
		$decision['ambiguities'] = [];
		$decision['clarification_question'] = '';
		$decision['raw_param_keys'] = [ 'limit' ];
		$decision['dropped_param_keys'] = [];
		$decision['params_trimmed_count'] = 0;
		$decision['summary'] = sprintf(
			/* translators: %d: order count */
			__( 'Preparing to apply an order action to the latest %d orders.', 'yoro-ai-core' ),
			$limit
		);
		$decision['reasoning_summary'] = __( 'The command shape targets a dynamic order selection plus a write action, so the router decision is normalized into a safe query-and-apply sequence.', 'yoro-ai-core' );

		return $decision;
	}

	private static function normalize_order_list_search_shape_decision( $decision, $command, $normalized, $shape ) {
		$decision = is_array( $decision ) ? $decision : [];
		$shape    = is_array( $shape ) ? $shape : [];

		if (
			! class_exists( 'YoRo_Command_Shape_Extractor' ) ||
			! YoRo_Command_Shape_Extractor::is_order_list_search_shape( $shape )
		) {
			return $decision;
		}

		if ( ! empty( $shape['explicit_order_ids'] ) ) {
			return $decision;
		}

		$action = sanitize_text_field( $decision['action'] ?? '' );
		$mode   = sanitize_key( $decision['mode'] ?? '' );

		if ( 'orders.search' === $action ) {
			if ( 'clarify' === $mode ) {
				$decision['mode'] = 'task_request';
				$decision['missing'] = [];
				$decision['ambiguities'] = [];
				$decision['clarification_question'] = '';
			}

			return $decision;
		}

		if ( ! self::should_promote_to_order_search( $decision, $normalized, $shape ) ) {
			return $decision;
		}

		$params = self::build_order_search_params_from_shape(
			isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [],
			$command,
			$normalized,
			$shape
		);

		$raw_param_keys = array_values( array_map( 'sanitize_key', array_keys( $params ) ) );

		$decision['mode'] = 'task_request';
		$decision['intent_family'] = 'search_orders';
		$decision['action'] = 'orders.search';
		$decision['params'] = $params;
		$decision['confidence'] = max( (float) ( $decision['confidence'] ?? 0 ), 0.9 );
		$decision['missing'] = [];
		$decision['ambiguities'] = [];
		$decision['clarification_question'] = '';
		$decision['raw_param_keys'] = $raw_param_keys;
		$decision['dropped_param_keys'] = [];
		$decision['params_trimmed_count'] = 0;
		$decision['summary'] = self::build_order_search_summary( $params, $shape, $command );
		$decision['reasoning_summary'] = __( 'The command shape targets an order list search, so the router should search orders instead of opening or previewing a single order.', 'yoro-ai-core' );

		return $decision;
	}

	private static function should_promote_to_order_search( $decision, $normalized = '', $shape = [] ) {
		$decision = is_array( $decision ) ? $decision : [];
		$shape    = is_array( $shape ) ? $shape : [];
		$action   = sanitize_text_field( $decision['action'] ?? '' );
		$mode     = sanitize_key( $decision['mode'] ?? '' );
		$missing  = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];

		if ( in_array( $action, [ 'orders.get_details', 'get_order_details', 'orders.find_by_customer', 'orders.refund_preview', 'workflow.refund_review' ], true ) ) {
			return true;
		}

		if ( 'operations.get_attention_queue' === $action && self::is_aged_status_order_search_shape( $shape ) ) {
			return true;
		}

		if ( 'clarify' === $mode && in_array( $action, [ '', 'orders.search' ], true ) ) {
			return empty( $missing ) || ! empty( array_intersect( $missing, [ 'order_id', 'order_ids' ] ) );
		}

		if ( 'clarify' === $mode && in_array( $action, [ 'orders.get_details', 'get_order_details', 'orders.find_by_customer', 'orders.refund_preview', 'workflow.refund_review' ], true ) ) {
			return true;
		}

		if (
			'clarify' === $mode &&
			in_array( $action, [ 'orders.update_status', 'orders.bulk_update_status_with_note' ], true ) &&
			! empty( array_intersect( $missing, [ 'order_id', 'order_ids' ] ) ) &&
			self::looks_like_status_list_lookup( $normalized )
		) {
			return true;
		}

		return false;
	}

	private static function is_aged_status_order_search_shape( $shape ) {
		$shape = is_array( $shape ) ? $shape : [];

		if ( ! self::is_order_list_search_shape( $shape ) ) {
			return false;
		}

		$status = sanitize_key( $shape['status'] ?? '' );

		if ( '' === $status ) {
			return false;
		}

		return self::resolve_min_age_hours( [], '', $shape ) > 0;
	}

	private static function looks_like_status_list_lookup( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized || false === strpos( $normalized, 'order' ) ) {
			return false;
		}

		if ( preg_match( '/^\s*(?:refund|cancel|complete|hold|update|change|mark|move|set|put|place)\b/i', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:update|change|mark|move|set|put|place|hold|cancel|complete)\b/i', $normalized ) ) {
			return false;
		}

		return (bool) preg_match(
			'/^\s*\d{1,2}\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b|\b(?:show|open|view|get|list|find|search|review)\b.*\b(?:a|an|one|\d{1,2}|some)?\s*(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b/i',
			$normalized
		);
	}

	private static function normalize_orders_search_decision( $decision, $command, $normalized, $shape ) {
		$decision = is_array( $decision ) ? $decision : [];
		$action   = sanitize_text_field( $decision['action'] ?? '' );

		if ( 'orders.search' !== $action ) {
			return $decision;
		}

		$raw_params = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$params = self::build_order_search_params_from_shape(
			$raw_params,
			$command,
			$normalized,
			is_array( $shape ) ? $shape : []
		);
		$existing_raw_param_keys = isset( $decision['raw_param_keys'] ) && is_array( $decision['raw_param_keys'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['raw_param_keys'] ) ) )
			: [];
		$existing_dropped_param_keys = isset( $decision['dropped_param_keys'] ) && is_array( $decision['dropped_param_keys'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['dropped_param_keys'] ) ) )
			: [];
		$raw_param_keys = ! empty( $existing_raw_param_keys )
			? $existing_raw_param_keys
			: array_values( array_map( 'sanitize_key', array_keys( $raw_params ) ) );
		$normalized_param_keys = array_values( array_map( 'sanitize_key', array_keys( $params ) ) );
		$dropped_param_keys = ! empty( $raw_param_keys )
			? array_values( array_unique( array_merge( $existing_dropped_param_keys, array_diff( $raw_param_keys, $normalized_param_keys ) ) ) )
			: [];
		$dropped_money_filter = array_intersect( [ 'total_min', 'total_max' ], $dropped_param_keys );

		$decision['params'] = $params;
		$decision['raw_param_keys'] = ! empty( $raw_param_keys ) ? $raw_param_keys : $normalized_param_keys;
		$decision['dropped_param_keys'] = $dropped_param_keys;
		$decision['params_trimmed_count'] = count( $dropped_param_keys );

		if ( self::is_order_list_search_shape( $shape ) ) {
			$decision['mode'] = 'task_request';
			$decision['intent_family'] = 'search_orders';
			$decision['missing'] = [];
			$decision['ambiguities'] = [];
			$decision['clarification_question'] = '';

			if ( ! empty( $dropped_money_filter ) || self::has_order_search_ux_filters( $params, $shape ) || empty( $decision['summary'] ) ) {
				$decision['summary'] = self::build_order_search_summary( $params, $shape, $command );
			}
		}

		return $decision;
	}

	private static function normalize_order_target_params_decision( $decision, $command, $client_context = [] ) {
		$decision       = is_array( $decision ) ? $decision : [];
		$client_context = is_array( $client_context ) ? $client_context : [];
		$action         = sanitize_text_field( $decision['action'] ?? '' );
		$params         = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];

		if ( ! in_array( $action, [ 'orders.hold', 'orders.add_note', 'orders.update_status', 'orders.bulk_update_status_with_note', 'orders.compose_customer_email' ], true ) ) {
			return $decision;
		}

		$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
			: [];

		if ( empty( $order_ids ) && class_exists( 'YoRo_Order_Helper' ) ) {
			$order_ids = array_values( array_filter( array_map( 'absint', YoRo_Order_Helper::extract_order_ids_from_text( (string) $command ) ) ) );
		}

		if ( empty( $order_ids ) && ! empty( $client_context['resolved_order_ids'] ) && is_array( $client_context['resolved_order_ids'] ) ) {
			$order_ids = array_values(
				array_filter(
					array_unique(
						array_map( 'absint', $client_context['resolved_order_ids'] )
					)
				)
			);
		} elseif ( empty( $order_ids ) && self::should_hydrate_from_recent_order_list( $decision, $client_context ) ) {
			$order_ids = self::extract_recent_order_ids_from_context( $client_context );
		}

		if ( 'orders.compose_customer_email' === $action && count( $order_ids ) > 1 ) {
			return $decision;
		}

		if ( ! empty( $order_ids ) ) {
			$decision['params']['order_ids'] = $order_ids;
			$decision['params']['order_id']  = absint( reset( $order_ids ) );
			$decision['missing'] = self::remove_satisfied_missing_params(
				$decision['missing'] ?? [],
				[ 'order_ids', 'order_id' ]
			);
		}

		return $decision;
	}

	private static function normalize_email_authoring_decision( $decision, $command, $normalized ) {
		$decision = is_array( $decision ) ? $decision : [];
		$action   = sanitize_text_field( $decision['action'] ?? '' );
		$mode     = sanitize_key( $decision['mode'] ?? '' );
		$params   = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$missing  = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];

		if ( ! in_array( $action, [ 'orders.compose_customer_email', 'orders.save_customer_email_draft', 'orders.send_customer_email' ], true ) ) {
			return $decision;
		}

		$order_id = absint( $params['order_id'] ?? 0 );
		$has_subject = self::decision_has_meaningful_param_value( $params, 'subject' );
		$has_body    = self::decision_has_meaningful_param_value( $params, 'body' );

		if (
			$order_id <= 0 ||
			! in_array( $mode, [ 'task_request', 'clarify' ], true )
		) {
			return $decision;
		}

		if ( 'orders.compose_customer_email' !== $action && $has_subject && $has_body ) {
			return $decision;
		}

		$needs_instruction_hydration = (
			'orders.compose_customer_email' === $action
			? in_array( 'instruction', $missing, true )
			: ( ! $has_subject || ! $has_body )
		);

		if ( ! $needs_instruction_hydration ) {
			return $decision;
		}

		$instruction = self::infer_email_authoring_instruction_from_command( $command, $normalized, $params );

		if ( '' === $instruction ) {
			return $decision;
		}

		$decision['action'] = 'orders.compose_customer_email';
		$decision['intent_family'] = 'customer_email';
		$decision['params']['instruction'] = $instruction;
		$decision['params']['order_id'] = $order_id;

		if ( empty( $decision['params']['order_ids'] ) || ! is_array( $decision['params']['order_ids'] ) ) {
			$decision['params']['order_ids'] = [ $order_id ];
		}

		unset( $decision['params']['subject'], $decision['params']['body'] );
		$decision['mode']   = 'task_request';
		$decision['missing'] = array_values(
			array_filter(
				$missing,
				static function( $item ) {
					return ! in_array( $item, [ 'instruction', 'body', 'subject', 'email_body', 'message', 'topic' ], true );
				}
			)
		);

		if ( empty( $decision['summary'] ) ) {
			$decision['summary'] = __( 'You asked me to write a customer email for a specific order.', 'yoro-ai-core' );
		}

		if ( empty( $decision['reasoning_summary'] ) ) {
			$decision['reasoning_summary'] = __( 'The command already states an email goal for a specific order, so the email instruction can be inferred directly.', 'yoro-ai-core' );
		}

		$decision['clarification_question'] = '';

		if ( empty( $decision['evidence'] ) || ! is_array( $decision['evidence'] ) ) {
			$decision['evidence'] = [
				'matched_terms'      => [],
				'referenced_context' => [],
				'notes'              => '',
			];
		}

		$decision['evidence']['notes'] = sanitize_text_field(
			trim(
				implode(
					' ',
					array_filter(
						[
							$decision['evidence']['notes'] ?? '',
							__( 'Email goal inferred directly from the natural-language request.', 'yoro-ai-core' ),
						]
					)
				)
			)
		);

		return $decision;
	}

	private static function normalize_email_instruction_decision( $decision, $command, $normalized ) {
		$decision = is_array( $decision ) ? $decision : [];
		$action   = sanitize_text_field( $decision['action'] ?? '' );
		$params   = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];

		if ( ! in_array( $action, [ 'orders.compose_customer_email', 'workflow.write_email_for_order', 'workflow.write_and_save_email_draft_for_order', 'workflow.write_and_send_email_for_order', 'workflow.write_email_for_orders', 'workflow.write_and_save_email_drafts_for_orders', 'workflow.write_and_send_emails_for_orders' ], true ) ) {
			return $decision;
		}

		$current_instruction = sanitize_textarea_field( $params['instruction'] ?? '' );
		$inferred_instruction = self::infer_email_authoring_instruction_from_command( $command, $normalized, $params );

		if ( '' === $inferred_instruction ) {
			return $decision;
		}

		if ( '' === $current_instruction || $inferred_instruction !== $current_instruction ) {
			$decision['params']['instruction'] = $inferred_instruction;
		}

		return $decision;
	}

	private static function normalize_briefing_decision( $decision, $normalized ) {
		$decision = is_array( $decision ) ? $decision : [];
		$action   = sanitize_text_field( $decision['action'] ?? '' );
		$mode     = sanitize_key( $decision['mode'] ?? '' );

		if ( 'task_request' !== $mode ) {
			return $decision;
		}

		if ( ! in_array( $action, [ 'store.get_business_summary', 'operations.get_summary', 'store.get_insights' ], true ) ) {
			return $decision;
		}

		$has_briefing_anchor = (bool) preg_match(
			'/\b(?:brief|briefing|snapshot|readout|brief me|brief us)\b/i',
			$normalized
		);
		$has_explicit_summary_anchor = (bool) preg_match(
			'/\b(?:business summary|business overview|operations summary|operations overview|store insights|insights)\b/i',
			$normalized
		);
		$has_morning_anchor = (bool) preg_match(
			'/\b(?:morning briefing|morning brief)\b/i',
			$normalized
		);

		if ( ! $has_briefing_anchor || $has_explicit_summary_anchor ) {
			return $decision;
		}

		$decision['action'] = $has_morning_anchor ? 'store.get_morning_briefing' : 'store.get_daily_briefing';

		if ( empty( $decision['summary'] ) || 'store.get_business_summary' === $action || 'operations.get_summary' === $action || 'store.get_insights' === $action ) {
			$decision['summary'] = $has_morning_anchor
				? __( 'Preparing a morning briefing for the store.', 'yoro-ai-core' )
				: __( 'Preparing a store briefing for the requested time window.', 'yoro-ai-core' );
		}

		$decision['reasoning_summary'] = $has_morning_anchor
			? __( 'The command explicitly asks for a morning briefing, so it should route to the morning briefing surface instead of a summary view.', 'yoro-ai-core' )
			: __( 'The command uses briefing language, so it should route to the store briefing surface instead of a business summary view.', 'yoro-ai-core' );

		return $decision;
	}

	private static function normalize_briefing_window_contract_decision( $decision, $normalized ) {
		$decision = is_array( $decision ) ? $decision : [];
		$action   = sanitize_text_field( $decision['action'] ?? '' );
		$mode     = sanitize_key( $decision['mode'] ?? '' );

		if ( 'task_request' !== $mode || ! in_array( $action, [ 'store.get_daily_briefing', 'store.get_morning_briefing' ], true ) ) {
			return $decision;
		}

		$params = isset( $decision['params'] ) && is_array( $decision['params'] )
			? $decision['params']
			: [];
		$window = sanitize_key( $params['window'] ?? '' );

		if (
			in_array( $window, [ 'weekly', 'this_week' ], true ) ||
			false !== strpos( $normalized, 'weekly' ) ||
			false !== strpos( $normalized, 'this week' )
		) {
			$params['window'] = 'last_7_days';
			$decision['params'] = $params;
		}

		$decision['intent_family'] = 'store_briefing';

		return $decision;
	}

	private static function normalize_read_only_window_decision( $decision, $normalized ) {
		$decision = is_array( $decision ) ? $decision : [];
		$action   = sanitize_text_field( $decision['action'] ?? '' );
		$mode     = sanitize_key( $decision['mode'] ?? '' );
		$params   = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$missing  = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];

		if ( 'clarify' !== $mode || '' === $action ) {
			return $decision;
		}

		if ( ! in_array( $action, [ 'store.get_business_summary', 'operations.get_summary', 'store.get_daily_briefing', 'store.get_morning_briefing', 'store.get_insights' ], true ) ) {
			return $decision;
		}

		$allowed_missing = array_diff( $missing, [ 'details', 'window' ] );

		if ( ! empty( $allowed_missing ) ) {
			return $decision;
		}

		if ( ! empty( $params['window'] ) ) {
			return $decision;
		}

		$default_window = in_array( $action, [ 'store.get_daily_briefing', 'store.get_morning_briefing' ], true )
			? 'today'
			: 'last_7_days';

		$params['window'] = self::detect_window_for_retrieval( $normalized, $default_window );

		if ( in_array( $action, [ 'store.get_business_summary', 'operations.get_summary' ], true ) && empty( $params['focus'] ) ) {
			$params['focus'] = 'overview';
		}

		$decision['mode'] = 'task_request';
		$decision['params'] = $params;
		$decision['missing'] = [];
		$decision['ambiguities'] = [];

		if ( empty( $decision['summary'] ) ) {
			$decision['summary'] = __( 'Preparing the requested store summary.', 'yoro-ai-core' );
		}

		if ( empty( $decision['reasoning_summary'] ) ) {
			$decision['reasoning_summary'] = __( 'The action surface is already clear, so the missing time window is normalized to the default reporting period instead of forcing an extra clarification.', 'yoro-ai-core' );
		}

		return $decision;
	}

	private static function normalize_write_confirmation_decision( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];
		$mode     = sanitize_key( $decision['mode'] ?? '' );
		$action   = sanitize_text_field( $decision['action'] ?? '' );
		$params   = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$missing  = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];
		$ambiguities = isset( $decision['ambiguities'] ) && is_array( $decision['ambiguities'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $decision['ambiguities'] ) ) )
			: [];
		$catalog_item = isset( $decision['action_catalog_item'] ) && is_array( $decision['action_catalog_item'] )
			? $decision['action_catalog_item']
			: [];

		if ( 'clarify' !== $mode || '' === $action || empty( $catalog_item ) ) {
			return $decision;
		}

		if ( empty( $catalog_item['write_action'] ) && empty( $catalog_item['requires_confirmation'] ) ) {
			return $decision;
		}

		if ( ! empty( $missing ) || ! empty( $ambiguities ) ) {
			return $decision;
		}

		$required_params = ! empty( $catalog_item['required_params'] ) && is_array( $catalog_item['required_params'] )
			? array_values( array_filter( array_map( 'sanitize_key', $catalog_item['required_params'] ) ) )
			: [];

		if ( empty( $required_params ) ) {
			return $decision;
		}

		foreach ( $required_params as $required_param ) {
			if ( ! self::decision_has_meaningful_param_value( $params, $required_param ) ) {
				return $decision;
			}
		}

		$decision['mode'] = 'task_request';
		$decision['clarification_question'] = '';
		$decision['missing'] = [];
		$decision['ambiguities'] = [];

		if ( empty( $decision['summary'] ) ) {
			$decision['summary'] = self::build_write_confirmation_summary( $action, $params );
		}

		if ( empty( $decision['reasoning_summary'] ) ) {
			$decision['reasoning_summary'] = __( 'The write action already has the required target and content, so backend confirmation should handle the final approval instead of forcing an extra clarification step.', 'yoro-ai-core' );
		}

		return $decision;
	}

	private static function build_write_confirmation_summary( $action, $params ) {
		$action = sanitize_text_field( $action );
		$params = is_array( $params ) ? $params : [];
		$order_id = absint( $params['order_id'] ?? 0 );

		if ( 0 === $order_id && ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_id = absint( $params['order_ids'][0] ?? 0 );
		}

		switch ( $action ) {
			case 'orders.add_note':
				$note = sanitize_textarea_field( $params['note'] ?? '' );

				if ( $order_id > 0 && '' !== $note ) {
					return sprintf(
						/* translators: 1: order id, 2: note content */
						__( 'Add internal note to order %1$d: "%2$s"', 'yoro-ai-core' ),
						$order_id,
						$note
					);
				}
				break;
			case 'orders.update_status':
				$status = sanitize_key( $params['status'] ?? '' );

				if ( $order_id > 0 && '' !== $status ) {
					return sprintf(
						/* translators: 1: order id, 2: status */
						__( 'Update order %1$d to status "%2$s".', 'yoro-ai-core' ),
						$order_id,
						$status
					);
				}
				break;
			case 'orders.hold':
				if ( $order_id > 0 ) {
					return sprintf(
						/* translators: %d: order id */
						__( 'Put order %d on hold.', 'yoro-ai-core' ),
						$order_id
					);
				}
				break;
		}

		return __( 'Preparing the requested write action.', 'yoro-ai-core' );
	}

	private static function normalize_actionable_clarify_decision( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];
		$mode     = sanitize_key( $decision['mode'] ?? '' );
		$action   = sanitize_text_field( $decision['action'] ?? '' );
		$params   = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$missing  = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];
		$ambiguities = isset( $decision['ambiguities'] ) && is_array( $decision['ambiguities'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $decision['ambiguities'] ) ) )
			: [];

		if ( 'clarify' !== $mode || '' === $action ) {
			return $decision;
		}

		if ( 'orders.search' !== $action ) {
			return $decision;
		}

		if ( ! empty( $missing ) || ! empty( $ambiguities ) ) {
			return $decision;
		}

		$has_meaningful_search_param =
			self::decision_has_meaningful_param_value( $params, 'limit' ) ||
			self::decision_has_meaningful_param_value( $params, 'status' ) ||
			self::decision_has_meaningful_param_value( $params, 'date_window' ) ||
			self::decision_has_meaningful_param_value( $params, 'min_age_hours' ) ||
			self::decision_has_meaningful_param_value( $params, 'date_from' ) ||
			self::decision_has_meaningful_param_value( $params, 'date_to' ) ||
			self::decision_has_meaningful_param_value( $params, 'email' ) ||
			self::decision_has_meaningful_param_value( $params, 'phone' ) ||
			self::decision_has_meaningful_param_value( $params, 'customer_name' ) ||
			self::decision_has_meaningful_param_value( $params, 'payment_method' ) ||
			self::decision_has_meaningful_param_value( $params, 'billing_country' );

		if ( ! $has_meaningful_search_param ) {
			return $decision;
		}

		$decision['mode'] = 'task_request';

		if ( empty( $decision['clarification_question'] ) ) {
			unset( $decision['clarification_question'] );
		}

		return $decision;
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
			false !== strpos( $normalized, 'monthly' )
		) {
			return 'last_30_days';
		}

		if ( false !== strpos( $normalized, 'this month' ) ) {
			return 'this_month';
		}

		if (
			false !== strpos( $normalized, 'last 7 days' ) ||
			false !== strpos( $normalized, 'last seven days' ) ||
			false !== strpos( $normalized, 'weekly' )
		) {
			return 'last_7_days';
		}

		if ( false !== strpos( $normalized, 'this week' ) ) {
			return 'this_week';
		}

		return $default;
	}

	private static function build_order_search_params_from_shape( $params, $command, $normalized, $shape ) {
		$params = is_array( $params ) ? $params : [];
		$shape  = is_array( $shape ) ? $shape : [];

		$limit = self::resolve_order_search_limit( $params, $command, $normalized, $shape );
		$limit = min( max( absint( $limit ), 1 ), 50 );

		$normalized_params = [
			'limit' => $limit,
		];

		$status = self::resolve_status_filter( $params, $normalized, $shape );

		if ( '' !== $status ) {
			$normalized_params['status'] = [ $status ];
		}

		$min_age_hours = self::resolve_min_age_hours( $params, $normalized, $shape );

		if ( $min_age_hours > 0 ) {
			$normalized_params['min_age_hours'] = $min_age_hours;
		}

		$date_window = self::resolve_date_window_filter( $params, $normalized, $shape );

		if (
			$min_age_hours <= 0 &&
			'' !== $date_window &&
			empty( $params['date_from'] ) &&
			empty( $params['date_to'] )
		) {
			$normalized_params['date_window'] = $date_window;
		}

		$money_filter = self::resolve_money_filter( $params, $shape, $normalized );

		if ( isset( $money_filter['total_min'] ) ) {
			$normalized_params['total_min'] = (float) $money_filter['total_min'];
		}

		if ( isset( $money_filter['total_max'] ) ) {
			$normalized_params['total_max'] = (float) $money_filter['total_max'];
		}

		$customer_identity = self::resolve_customer_identity_filter( $params, $shape );

		foreach ( [ 'email', 'phone', 'customer_name' ] as $key ) {
			if ( ! empty( $customer_identity[ $key ] ) ) {
				$normalized_params[ $key ] = 'email' === $key
					? sanitize_email( $customer_identity[ $key ] )
					: sanitize_text_field( $customer_identity[ $key ] );
			}
		}

		if ( ! empty( $customer_identity['customer_id'] ) ) {
			$normalized_params['customer_id'] = absint( $customer_identity['customer_id'] );
		}

		$payment_method = self::resolve_payment_method_filter( $params, $shape );

		if ( '' !== $payment_method ) {
			$normalized_params['payment_method'] = $payment_method;
		}

		$shipping_state = self::resolve_shipping_state_filter( $params, $shape );

		if ( '' !== $shipping_state ) {
			$normalized_params['shipping_state'] = $shipping_state;
		}

		foreach ( [ 'email', 'phone', 'payment_method', 'customer_name', 'billing_country', 'shipping_state', 'date_window', 'date_from', 'date_to' ] as $key ) {
			if ( empty( $params[ $key ] ) ) {
				continue;
			}

			if ( 'date_window' === $key && ! empty( $normalized_params['date_window'] ) ) {
				continue;
			}

			if ( isset( $normalized_params[ $key ] ) ) {
				continue;
			}

			$normalized_params[ $key ] = 'email' === $key
				? sanitize_email( $params[ $key ] )
				: sanitize_text_field( $params[ $key ] );
		}

		if ( ! empty( $params['customer_id'] ) && empty( $normalized_params['customer_id'] ) ) {
			$normalized_params['customer_id'] = absint( $params['customer_id'] );
		}

		return $normalized_params;
	}

	private static function resolve_order_search_limit( $params, $command, $normalized, $shape ) {
		$params         = is_array( $params ) ? $params : [];
		$shape          = is_array( $shape ) ? $shape : [];
		$quantity_scope = isset( $shape['quantity_scope'] ) && is_array( $shape['quantity_scope'] )
			? $shape['quantity_scope']
			: [];

		if ( ! empty( $quantity_scope['value'] ) ) {
			return min( max( absint( $quantity_scope['value'] ), 1 ), 50 );
		}

		if ( isset( $shape['quantity'] ) && null !== $shape['quantity'] ) {
			return min( max( absint( $shape['quantity'] ), 1 ), 50 );
		}

		return self::extract_order_search_limit_from_command(
			$command,
			$normalized,
			self::normalize_limit( $params['limit'] ?? 10 )
		);
	}

	private static function resolve_status_filter( $params, $normalized, $shape ) {
		$params = is_array( $params ) ? $params : [];
		$shape  = is_array( $shape ) ? $shape : [];

		if ( ! empty( $shape['status'] ) && self::is_valid_order_status( $shape['status'] ) ) {
			return YoRo_Order_Helper::normalize_status_slug( $shape['status'] );
		}

		if ( class_exists( 'YoRo_Order_Helper' ) ) {
			$detected_status = YoRo_Order_Helper::detect_status_from_search_semantics( $normalized );
			$detected_status = YoRo_Order_Helper::normalize_status_slug( $detected_status );

			if ( YoRo_Order_Helper::is_valid_order_status( $detected_status ) ) {
				return $detected_status;
			}
		}

		return '';
	}

	private static function resolve_date_window_filter( $params, $normalized, $shape ) {
		$params      = is_array( $params ) ? $params : [];
		$shape       = is_array( $shape ) ? $shape : [];
		$date_filter = isset( $shape['date_filter'] ) && is_array( $shape['date_filter'] )
			? $shape['date_filter']
			: [];

		if ( ! empty( $date_filter['window_key'] ) && class_exists( 'YoRo_Date_Helper' ) ) {
			$window_key = YoRo_Date_Helper::normalize_window_key( $date_filter['window_key'] );

			if ( '' !== $window_key ) {
				return $window_key;
			}
		}

		if ( ! empty( $params['date_window'] ) && class_exists( 'YoRo_Date_Helper' ) ) {
			$window_key = YoRo_Date_Helper::normalize_window_key( $params['date_window'] );

			if ( '' !== $window_key ) {
				return $window_key;
			}
		}

		if ( class_exists( 'YoRo_Date_Helper' ) ) {
			$window_key = YoRo_Date_Helper::infer_window_key_from_text( $normalized );

			if ( '' !== $window_key ) {
				return $window_key;
			}
		}

		return '';
	}

	private static function resolve_shipping_state_filter( $params, $shape ) {
		$params         = is_array( $params ) ? $params : [];
		$shape          = is_array( $shape ) ? $shape : [];
		$shipping_state = isset( $shape['shipping_state'] ) && is_array( $shape['shipping_state'] )
			? $shape['shipping_state']
			: [];
		$state          = '';

		if ( ! empty( $shipping_state['state'] ) ) {
			$state = self::normalize_state_code( $shipping_state['state'] );
		}

		if ( ! empty( $params['shipping_state'] ) ) {
			$param_state = self::normalize_state_code( $params['shipping_state'] );

			if ( '' !== $param_state ) {
				$state = $param_state;
			}
		}

		return $state;
	}

	private static function normalize_state_code( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( preg_match( '/^[A-Z]{2}$/', $value ) ) {
			return strtoupper( $value );
		}

		$key = strtolower( preg_replace( '/[^a-z]+/i', ' ', $value ) );
		$key = trim( preg_replace( '/\s+/', ' ', $key ) );

		$map = [
			'alabama' => 'AL',
			'alaska' => 'AK',
			'arizona' => 'AZ',
			'arkansas' => 'AR',
			'california' => 'CA',
			'colorado' => 'CO',
			'connecticut' => 'CT',
			'delaware' => 'DE',
			'florida' => 'FL',
			'georgia' => 'GA',
			'hawaii' => 'HI',
			'idaho' => 'ID',
			'illinois' => 'IL',
			'indiana' => 'IN',
			'iowa' => 'IA',
			'kansas' => 'KS',
			'kentucky' => 'KY',
			'louisiana' => 'LA',
			'maine' => 'ME',
			'maryland' => 'MD',
			'massachusetts' => 'MA',
			'michigan' => 'MI',
			'minnesota' => 'MN',
			'mississippi' => 'MS',
			'missouri' => 'MO',
			'montana' => 'MT',
			'nebraska' => 'NE',
			'nevada' => 'NV',
			'new hampshire' => 'NH',
			'new jersey' => 'NJ',
			'new mexico' => 'NM',
			'new york' => 'NY',
			'north carolina' => 'NC',
			'north dakota' => 'ND',
			'ohio' => 'OH',
			'oklahoma' => 'OK',
			'oregon' => 'OR',
			'pennsylvania' => 'PA',
			'rhode island' => 'RI',
			'south carolina' => 'SC',
			'south dakota' => 'SD',
			'tennessee' => 'TN',
			'texas' => 'TX',
			'utah' => 'UT',
			'vermont' => 'VT',
			'virginia' => 'VA',
			'washington' => 'WA',
			'west virginia' => 'WV',
			'wisconsin' => 'WI',
			'wyoming' => 'WY',
			'district of columbia' => 'DC',
			'washington dc' => 'DC',
		];

		return $map[ $key ] ?? '';
	}

	private static function resolve_payment_method_filter( $params, $shape ) {
		$params         = is_array( $params ) ? $params : [];
		$shape          = is_array( $shape ) ? $shape : [];
		$payment_method = isset( $shape['payment_method'] ) && is_array( $shape['payment_method'] )
			? $shape['payment_method']
			: [];
		$method         = '';

		if ( ! empty( $payment_method['method'] ) ) {
			$method = self::normalize_payment_method_value( $payment_method['method'] );
		}

		if ( ! empty( $params['payment_method'] ) ) {
			$method = self::normalize_payment_method_value( $params['payment_method'] );
		}

		return $method;
	}

	private static function normalize_payment_method_value( $value ) {
		$value = strtolower( trim( wp_strip_all_tags( (string) $value ) ) );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value, " \t\n\r\0\x0B'’\"" );

		if ( '' === $value ) {
			return '';
		}

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

	private static function resolve_customer_identity_filter( $params, $shape ) {
		$params            = is_array( $params ) ? $params : [];
		$shape             = is_array( $shape ) ? $shape : [];
		$customer_identity = isset( $shape['customer_identity'] ) && is_array( $shape['customer_identity'] )
			? $shape['customer_identity']
			: [];
		$resolved          = [];
		$type              = sanitize_key( $customer_identity['type'] ?? '' );

		if ( 'email' === $type && ! empty( $customer_identity['email'] ) ) {
			$resolved['email'] = sanitize_email( $customer_identity['email'] );
		} elseif ( 'phone' === $type && ! empty( $customer_identity['phone'] ) ) {
			$resolved['phone'] = sanitize_text_field( $customer_identity['phone'] );
		} elseif ( 'customer_id' === $type && ! empty( $customer_identity['customer_id'] ) ) {
			$resolved['customer_id'] = absint( $customer_identity['customer_id'] );
		} elseif ( 'customer_name' === $type && ! empty( $customer_identity['customer_name'] ) ) {
			$resolved['customer_name'] = sanitize_text_field( $customer_identity['customer_name'] );
		}

		if ( ! empty( $params['email'] ) ) {
			$resolved = [ 'email' => sanitize_email( $params['email'] ) ];
		} elseif ( ! empty( $params['phone'] ) ) {
			$resolved = [ 'phone' => sanitize_text_field( $params['phone'] ) ];
		} elseif ( ! empty( $params['customer_id'] ) ) {
			$resolved = [ 'customer_id' => absint( $params['customer_id'] ) ];
		} elseif ( ! empty( $params['customer_name'] ) ) {
			$resolved = [ 'customer_name' => sanitize_text_field( $params['customer_name'] ) ];
		}

		return array_filter(
			$resolved,
			static function( $value ) {
				return '' !== (string) $value && 0 !== $value;
			}
		);
	}

	private static function resolve_money_filter( $params, $shape, $normalized = '' ) {
		$params       = is_array( $params ) ? $params : [];
		$shape        = is_array( $shape ) ? $shape : [];
		$money_filter = isset( $shape['money_filter'] ) && is_array( $shape['money_filter'] )
			? $shape['money_filter']
			: [];
		$resolved     = [];
		$has_shape_money_filter = self::has_shape_money_filter( $money_filter );
		$has_money_text         = self::has_money_filter_text( $normalized );

		if ( ! $has_shape_money_filter && ! $has_money_text ) {
			return [];
		}

		if ( $has_shape_money_filter && ! empty( $money_filter['operator'] ) ) {
			$operator = sanitize_key( $money_filter['operator'] );

			if ( 'between' === $operator ) {
				if ( isset( $money_filter['min'] ) && '' !== (string) $money_filter['min'] ) {
					$resolved['total_min'] = max( 0, (float) $money_filter['min'] );
				}

				if ( isset( $money_filter['max'] ) && '' !== (string) $money_filter['max'] ) {
					$resolved['total_max'] = max( 0, (float) $money_filter['max'] );
				}
			} elseif ( 'above' === $operator && isset( $money_filter['value'] ) && '' !== (string) $money_filter['value'] ) {
				$resolved['total_min'] = max( 0, (float) $money_filter['value'] );
			} elseif ( 'below' === $operator && isset( $money_filter['value'] ) && '' !== (string) $money_filter['value'] ) {
				$resolved['total_max'] = max( 0, (float) $money_filter['value'] );
			}
		}

		foreach ( [ 'total_min', 'total_max' ] as $key ) {
			if ( isset( $params[ $key ] ) && '' !== (string) $params[ $key ] ) {
				$resolved[ $key ] = max( 0, (float) $params[ $key ] );
			}
		}

		if (
			isset( $resolved['total_min'], $resolved['total_max'] ) &&
			(float) $resolved['total_min'] > (float) $resolved['total_max']
		) {
			$min = (float) $resolved['total_max'];
			$max = (float) $resolved['total_min'];
			$resolved['total_min'] = $min;
			$resolved['total_max'] = $max;
		}

		return $resolved;
	}

	private static function has_shape_money_filter( $money_filter ) {
		if ( ! is_array( $money_filter ) || empty( $money_filter ) ) {
			return false;
		}

		if ( ! empty( $money_filter['operator'] ) ) {
			return true;
		}

		foreach ( [ 'value', 'min', 'max', 'text' ] as $key ) {
			if ( isset( $money_filter[ $key ] ) && '' !== trim( (string) $money_filter[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function has_money_filter_text( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		return (bool) preg_match(
			'/\\$|\\b(?:usd|dollars?|order\\s+total|total|amount|money|value|between|over|above|under|below|less\\s+than|more\\s+than|greater\\s+than|at\\s+least|at\\s+most)\\b/i',
			$normalized
		);
	}

	private static function resolve_min_age_hours( $params, $normalized, $shape ) {
		$params     = is_array( $params ) ? $params : [];
		$shape      = is_array( $shape ) ? $shape : [];
		$age_filter = isset( $shape['age_filter'] ) && is_array( $shape['age_filter'] )
			? $shape['age_filter']
			: [];

		if ( ! empty( $age_filter['hours'] ) ) {
			return min( max( absint( $age_filter['hours'] ), 1 ), 8760 );
		}

		if ( ! empty( $params['min_age_hours'] ) ) {
			return min( max( absint( $params['min_age_hours'] ), 1 ), 8760 );
		}

		$normalized = strtolower( trim( (string) $normalized ) );

		if (
			'' === $normalized ||
			! preg_match(
				'/\b(?:more\s+than|over|older\s+than|stuck\s+for|waiting\s+for)\s+(\d{1,3})\s+(hours?|hrs?|days?|weeks?)\b/i',
				$normalized,
				$matches
			)
		) {
			return 0;
		}

		$value = absint( $matches[1] ?? 0 );
		$unit  = sanitize_key( $matches[2] ?? '' );

		if ( $value <= 0 ) {
			return 0;
		}

		if ( in_array( $unit, [ 'week', 'weeks' ], true ) ) {
			return min( max( $value * 7 * 24, 1 ), 8760 );
		}

		if ( in_array( $unit, [ 'day', 'days' ], true ) ) {
			return min( max( $value * 24, 1 ), 8760 );
		}

		return min( max( $value, 1 ), 8760 );
	}

	private static function build_order_search_summary( $params, $shape = [], $command = '' ) {
		$params = is_array( $params ) ? $params : [];
		$shape  = is_array( $shape ) ? $shape : [];
		$limit  = max( 1, absint( $params['limit'] ?? 10 ) );
		$status = '';
		$min_age_hours = isset( $params['min_age_hours'] ) ? absint( $params['min_age_hours'] ) : 0;

		if ( class_exists( 'YoRo_Response_UX_Formatter' ) ) {
			return YoRo_Response_UX_Formatter::order_search_summary(
				'showing',
				$limit,
				$params,
				[],
				[
					'command' => $params['original_command'] ?? $command,
					'shape'   => $shape,
				],
				'en'
			);
		}

		if ( ! empty( $params['status'] ) ) {
			$status_values = is_array( $params['status'] ) ? $params['status'] : [ $params['status'] ];
			$status        = sanitize_key( reset( $status_values ) );
		}

		if ( '' !== $status ) {
			$status_label = trim( preg_replace( '/\s+/', ' ', str_replace( '-', ' ', $status ) ) );

			if ( $min_age_hours > 0 ) {
				$age_phrase = class_exists( 'YoRo_Response_UX_Formatter' )
					? YoRo_Response_UX_Formatter::age_threshold_phrase(
						$min_age_hours,
						[
							'command' => $params['original_command'] ?? $command,
							'shape'   => $shape,
						],
						'en'
					)
					: sprintf( 'older than %d hours', $min_age_hours );

				return sprintf(
					/* translators: 1: order status label, 2: natural age threshold phrase */
					__( 'Showing %1$s orders %2$s.', 'yoro-ai-core' ),
					$status_label,
					$age_phrase
				);
			}

			if ( 1 === $limit ) {
				return sprintf(
					/* translators: %s: order status label */
					__( 'Showing one %s order.', 'yoro-ai-core' ),
					$status_label
				);
			}

			return sprintf(
				/* translators: 1: order count, 2: order status label */
				__( 'Showing %1$d %2$s orders.', 'yoro-ai-core' ),
				$limit,
				$status_label
			);
		}

		return sprintf(
			/* translators: %d: order count */
			__( 'Showing latest %d orders.', 'yoro-ai-core' ),
			$limit
		);
	}

	private static function has_order_search_ux_filters( $params, $shape = [] ) {
		$params = is_array( $params ) ? $params : [];
		$shape  = is_array( $shape ) ? $shape : [];

		foreach ( [ 'min_age_hours', 'date_window', 'total_min', 'total_max', 'payment_method', 'shipping_state', 'email', 'phone', 'customer_id', 'customer_name' ] as $key ) {
			if ( isset( $params[ $key ] ) && '' !== (string) $params[ $key ] ) {
				return true;
			}
		}

		foreach ( [ 'age_filter', 'date_filter', 'money_filter', 'payment_method', 'shipping_state', 'customer_identity' ] as $key ) {
			if ( ! empty( $shape[ $key ] ) && is_array( $shape[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function extract_order_search_limit_from_command( $command, $normalized, $default = 10 ) {
		$default = self::normalize_limit( $default );

		if (
			preg_match(
				'/\b(?:show|open|view|get|list|find|search|review)\b.*\b(?:a|an|one)\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+order\b/i',
				$normalized
			)
		) {
			return 1;
		}

		$patterns = [
			'/\b(?:limit|top)\s+(\d{1,2})\b/i',
			'/\b(?:latest|recent|last|newest|lestet)\s+(\d{1,2})\s+orders?\b/i',
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
			'/\b(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(?:me\s+)?(\d{1,2})\s+(?:new|latest|recent|last|newest|lestet)\s+orders?\b/i',
			'/\b(\d{1,2})\s+(?:new|latest|recent|last|newest|lestet)\s+orders?\b/i',
			'/\b(\d{1,2})\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b/i',
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(?:me\s+)?(\d{1,2})\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b/i',
			'/\bfirst\s+(\d{1,2})\s+orders?\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, (string) $command, $matches ) ) {
				return min( max( absint( $matches[1] ), 1 ), 50 );
			}
		}

		return $default;
	}

	private static function should_hydrate_from_recent_order_list( $decision, $client_context = [] ) {
		$decision       = is_array( $decision ) ? $decision : [];
		$client_context = is_array( $client_context ) ? $client_context : [];
		$referenced_context = isset( $decision['evidence']['referenced_context'] ) && is_array( $decision['evidence']['referenced_context'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['evidence']['referenced_context'] ) ) )
			: [];

		if ( empty( $referenced_context ) ) {
			return false;
		}

		if ( ! in_array( 'recent_order_list', $referenced_context, true ) ) {
			return false;
		}

		return ! empty( self::extract_recent_order_ids_from_context( $client_context ) );
	}

	private static function extract_recent_order_ids_from_context( $client_context = [] ) {
		$client_context = is_array( $client_context ) ? $client_context : [];

		$order_ids = isset( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] )
			? array_values(
				array_filter(
					array_unique(
						array_map( 'absint', $client_context['last_order_ids'] )
					)
				)
			)
			: [];

		if ( ! empty( $order_ids ) ) {
			return $order_ids;
		}

		if ( empty( $client_context['last_orders'] ) || ! is_array( $client_context['last_orders'] ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_unique(
					array_map(
						static function( $order ) {
							return is_array( $order ) ? absint( $order['id'] ?? 0 ) : 0;
						},
						$client_context['last_orders']
					)
				)
			)
		);
	}

	private static function remove_satisfied_missing_params( $missing, $resolved_keys ) {
		$missing = is_array( $missing )
			? array_values( array_filter( array_map( 'sanitize_key', $missing ) ) )
			: [];
		$resolved_keys = is_array( $resolved_keys )
			? array_values( array_filter( array_map( 'sanitize_key', $resolved_keys ) ) )
			: [];

		if ( empty( $missing ) || empty( $resolved_keys ) ) {
			return $missing;
		}

		return array_values(
			array_filter(
				$missing,
				static function( $key ) use ( $resolved_keys ) {
					return ! in_array( $key, $resolved_keys, true );
				}
			)
		);
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

	private static function normalize_limit( $value, $default = 10 ) {
		$value = absint( $value );

		if ( $value <= 0 ) {
			$value = absint( $default );
		}

		return min( max( $value, 1 ), 50 );
	}

	private static function is_order_list_search_shape( $shape ) {
		return class_exists( 'YoRo_Command_Shape_Extractor' ) &&
			YoRo_Command_Shape_Extractor::is_order_list_search_shape( $shape );
	}

	private static function normalize_order_status_list( $statuses ) {
		$statuses = is_array( $statuses ) ? $statuses : [ $statuses ];
		$normalized = [];

		foreach ( $statuses as $status ) {
			$status = self::normalize_order_status_slug( $status );

			if ( '' === $status || ! self::is_valid_order_status( $status ) ) {
				continue;
			}

			$normalized[] = $status;
		}

		return array_values( array_unique( $normalized ) );
	}

	private static function normalize_order_status_slug( $status ) {
		$status = sanitize_key( $status );

		if ( '' === $status ) {
			return '';
		}

		if ( class_exists( 'YoRo_Order_Helper' ) ) {
			$status = YoRo_Order_Helper::normalize_status_slug( $status );
		}

		return sanitize_key( $status );
	}

	private static function is_valid_order_status( $status ) {
		if ( ! class_exists( 'YoRo_Order_Helper' ) ) {
			return false;
		}

		return YoRo_Order_Helper::is_valid_order_status( $status );
	}
}
