<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Parser_Result_Normalizer {

	public static function normalize( $parsed, $command, $normalized, $client_context = [] ) {
		if ( ! is_array( $parsed ) ) {
			return $parsed;
		}

		$parsed['success'] = isset( $parsed['success'] ) ? (bool) $parsed['success'] : true;
		$parsed['matched'] = isset( $parsed['matched'] ) ? (bool) $parsed['matched'] : true;

		$parsed['original_command'] = $parsed['original_command'] ?? $command;
		$parsed['normalized_command'] = $parsed['normalized_command'] ?? $normalized;

		$parsed['parser_source'] = $parsed['parser_source'] ?? 'rule';
		$parsed['confidence'] = isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 1.0;

		if ( class_exists( 'YoRo_Command_Shape_Extractor' ) ) {
			$shape = isset( $client_context['_command_shape'] ) && is_array( $client_context['_command_shape'] )
				? $client_context['_command_shape']
				: YoRo_Command_Shape_Extractor::extract( $command, $client_context );
			$parsed['command_shape'] = YoRo_Command_Shape_Extractor::compact_for_prompt( $shape );
		}

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

	public static function hydrate_intent_family_from_action( $result ) {
		$result = is_array( $result ) ? $result : [];
		$action = sanitize_text_field( $result['action'] ?? '' );

		if ( '' === $action || ! empty( $result['intent_family'] ) ) {
			return $result;
		}

		$map = [
			'orders.search'                                 => 'search_orders',
			'orders.get_details'                            => 'get_order_details',
			'orders.refund_preview'                         => 'refund_review',
			'workflow.refund_review'                        => 'refund_review',
			'orders.hold'                                   => 'hold_order',
			'orders.add_note'                               => 'add_note',
			'orders.update_status'                          => 'update_order_status',
				'orders.bulk_update_status_with_note'           => 'update_order_status',
				'orders.compose_customer_email'                 => 'customer_email',
				'orders.get_customer_email_drafts'              => 'customer_email',
				'orders.save_customer_email_draft'              => 'customer_email',
				'orders.update_customer_email_draft'            => 'customer_email',
				'orders.delete_customer_email_draft'            => 'customer_email',
				'orders.render_customer_email_preview'          => 'customer_email',
				'orders.send_customer_email'                    => 'customer_email',
				'workflow.write_email_for_order'                => 'customer_email',
				'workflow.write_and_save_email_draft_for_order' => 'customer_email',
				'workflow.write_and_send_email_for_order'       => 'customer_email',
			'workflow.write_email_for_orders'               => 'customer_email',
			'workflow.write_and_save_email_drafts_for_orders' => 'customer_email',
			'workflow.write_and_send_emails_for_orders'     => 'customer_email',
		];

		if ( isset( $map[ $action ] ) ) {
			$result['intent_family'] = $map[ $action ];
		}

		if ( isset( $result['command_decision'] ) && is_array( $result['command_decision'] ) && empty( $result['command_decision']['intent_family'] ) && ! empty( $result['intent_family'] ) ) {
			$result['command_decision']['intent_family'] = $result['intent_family'];
		}

		return $result;
	}

	private static function normalize_fresh_orders_search_result( $parsed, $command, $normalized ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$action = sanitize_text_field( $parsed['action'] ?? '' );

		if ( ! in_array( $action, [ 'orders.get_details', 'get_order_details' ], true ) ) {
			return $parsed;
		}

		if ( ! self::looks_like_fresh_orders_quantity_request( $normalized ) ) {
			return $parsed;
		}

		$explicit_order_ids = class_exists( 'YoRo_Order_Helper' )
			? YoRo_Order_Helper::extract_order_ids_from_text( (string) $command )
			: [];

		if ( ! empty( $explicit_order_ids ) ) {
			return $parsed;
		}

		$params = [
			'limit' => class_exists( 'YoRo_AI_Inferred_Action_Param_Normalizer' )
				? YoRo_AI_Inferred_Action_Param_Normalizer::extract_requested_limit_from_command( $command, 10, 50 )
				: 10,
		];

		$detected_status = class_exists( 'YoRo_Order_Helper' )
			? YoRo_Order_Helper::detect_status_from_search_semantics( $normalized )
			: '';

		if ( ! empty( $detected_status ) && class_exists( 'YoRo_Order_Helper' ) ) {
			$detected_status = YoRo_Order_Helper::normalize_status_slug( $detected_status );

			if ( YoRo_Order_Helper::is_valid_order_status( $detected_status ) ) {
				$params['status'] = [ $detected_status ];
			}
		}

		$summary = sprintf(
			/* translators: %d: order count */
			__( 'Showing latest %d orders.', 'yoro-ai-core' ),
			absint( $params['limit'] )
		);
		$reasoning_summary = __( 'The command asks for a recent order list, so it should route to order search instead of a single-order details view.', 'yoro-ai-core' );
		$raw_param_keys = array_values( array_map( 'sanitize_key', array_keys( $params ) ) );

		$parsed['action'] = 'orders.search';
		$parsed['intent_family'] = 'search_orders';
		$parsed['params'] = $params;
		$parsed['confidence'] = max( (float) ( $parsed['confidence'] ?? 0 ), 0.9 );
		$parsed['summary'] = $summary;
		$parsed['reasoning_summary'] = $reasoning_summary;
		$parsed['raw_param_keys'] = $raw_param_keys;

		if ( isset( $parsed['intent'] ) && is_array( $parsed['intent'] ) ) {
			$parsed['intent']['name'] = 'search_orders';
			$parsed['intent']['action'] = 'orders.search';
			$parsed['intent']['confidence'] = max( (float) ( $parsed['intent']['confidence'] ?? 0 ), 0.9 );
		}

		if ( isset( $parsed['command_decision'] ) && is_array( $parsed['command_decision'] ) ) {
			$parsed['command_decision']['mode'] = 'task_request';
			$parsed['command_decision']['intent_family'] = 'search_orders';
			$parsed['command_decision']['action'] = 'orders.search';
			$parsed['command_decision']['confidence'] = $parsed['confidence'];
			$parsed['command_decision']['clarification_question'] = '';
			$parsed['command_decision']['missing'] = [];
			$parsed['command_decision']['ambiguities'] = [];
			$parsed['command_decision']['raw_param_keys'] = $raw_param_keys;
		}

		return $parsed;
	}

	private static function looks_like_fresh_orders_quantity_request( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( '' === $normalized ) {
			return false;
		}

		if ( false === strpos( $normalized, 'order' ) ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:show|get|find|list|search|open|load|browse|review)?\s*(?:me\s+)?\d{1,2}\s+(?:new|latest|recent|last|newest)\s+orders?\b|\b(?:show|get|find|list|search|open|load|browse|review)\s+(?:me\s+)?(?:the\s+)?(?:new|latest|recent|last|newest)\s+\d{1,2}\s+orders?\b/i',
			$normalized
		);
	}

	private static function normalize_status_qualified_order_lookup_result( $parsed, $command, $normalized ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$action = sanitize_text_field( $parsed['action'] ?? '' );

		if ( ! in_array( $action, [ 'orders.get_details', 'get_order_details' ], true ) ) {
			return $parsed;
		}

		if ( ! self::looks_like_status_qualified_order_search_request( $normalized ) ) {
			return $parsed;
		}

		$explicit_order_ids = class_exists( 'YoRo_Order_Helper' )
			? YoRo_Order_Helper::extract_order_ids_from_text( (string) $command )
			: [];

		if ( ! empty( $explicit_order_ids ) ) {
			return $parsed;
		}

		$detected_status = class_exists( 'YoRo_Order_Helper' )
			? YoRo_Order_Helper::detect_status_from_search_semantics( $normalized )
			: '';

		if ( empty( $detected_status ) || ! class_exists( 'YoRo_Order_Helper' ) ) {
			return $parsed;
		}

		$detected_status = YoRo_Order_Helper::normalize_status_slug( $detected_status );

		if ( ! YoRo_Order_Helper::is_valid_order_status( $detected_status ) ) {
			return $parsed;
		}

		$limit = self::extract_generic_order_search_limit_from_command( $command, $normalized, 10 );
		$params = [
			'limit'  => $limit,
			'status' => [ $detected_status ],
		];

		$status_label = str_replace( '-', ' ', $detected_status );
		$status_label = trim( preg_replace( '/\s+/', ' ', $status_label ) );

		if ( 1 === $limit ) {
			$summary = sprintf(
				/* translators: %s: status label */
				__( 'Showing one %s order.', 'yoro-ai-core' ),
				$status_label
			);
			$reasoning_summary = __( 'The command asks for an order by status without an explicit order number, so it should search for one matching order instead of opening the current order context.', 'yoro-ai-core' );
		} else {
			$summary = sprintf(
				/* translators: 1: order count, 2: status label */
				__( 'Showing %1$d %2$s orders.', 'yoro-ai-core' ),
				$limit,
				$status_label
			);
			$reasoning_summary = __( 'The command asks for orders filtered by status without explicit order numbers, so it should route to order search instead of a single-order details view.', 'yoro-ai-core' );
		}

		$raw_param_keys = array_values( array_map( 'sanitize_key', array_keys( $params ) ) );

		$parsed['action'] = 'orders.search';
		$parsed['intent_family'] = 'search_orders';
		$parsed['params'] = $params;
		$parsed['summary'] = $summary;
		$parsed['reasoning_summary'] = $reasoning_summary;
		$parsed['raw_param_keys'] = $raw_param_keys;
		$parsed['dropped_param_keys'] = [];
		$parsed['params_trimmed_count'] = 0;

		if ( isset( $parsed['intent'] ) && is_array( $parsed['intent'] ) ) {
			$parsed['intent']['name'] = 'search_orders';
			$parsed['intent']['action'] = 'orders.search';
		}

		if ( isset( $parsed['command_decision'] ) && is_array( $parsed['command_decision'] ) ) {
			$parsed['command_decision']['mode'] = 'task_request';
			$parsed['command_decision']['intent_family'] = 'search_orders';
			$parsed['command_decision']['action'] = 'orders.search';
			$parsed['command_decision']['raw_param_keys'] = $raw_param_keys;
			$parsed['command_decision']['dropped_param_keys'] = [];
			$parsed['command_decision']['params_trimmed_count'] = 0;
			$parsed['command_decision']['clarification_question'] = '';
			$parsed['command_decision']['missing'] = [];
			$parsed['command_decision']['ambiguities'] = [];
		}

		return $parsed;
	}

	private static function looks_like_status_qualified_order_search_request( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( '' === $normalized || false === strpos( $normalized, 'order' ) ) {
			return false;
		}

		if ( preg_match( '/\border\s*#?\s*\d+\b/i', $normalized ) ) {
			return false;
		}

		if ( ! class_exists( 'YoRo_Order_Helper' ) || '' === YoRo_Order_Helper::detect_status_from_search_semantics( $normalized ) ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:show|open|view|get|list|find|search|review)\b.*\b(?:a|an|one|\d{1,2}|some)?\s*(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b|\b\d{1,2}\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b/i',
			$normalized
		);
	}

	private static function extract_generic_order_search_limit_from_command( $command, $normalized, $default = 10 ) {
		$limit = class_exists( 'YoRo_AI_Inferred_Action_Param_Normalizer' )
			? YoRo_AI_Inferred_Action_Param_Normalizer::extract_requested_limit_from_command( $command, $default, 50 )
			: min( max( 1, absint( $default ) ), 50 );

		if (
			preg_match(
				'/\b(?:show|open|view|get|list|find|search|review)\b.*\b(?:a|an|one)\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+order\b/i',
				$normalized
			)
		) {
			return 1;
		}

		return $limit;
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
			if ( is_array( $params['status'] ) ) {
				$statuses = array_values( array_filter( array_map( 'sanitize_key', $params['status'] ) ) );

				if ( 1 === count( $statuses ) ) {
					$entities['status'] = $statuses[0];
				} elseif ( ! empty( $statuses ) ) {
					$entities['status'] = $statuses;
				}
			} else {
				$entities['status'] = sanitize_key( $params['status'] );
			}
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
}
