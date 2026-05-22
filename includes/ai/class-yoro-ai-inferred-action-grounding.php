<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Inferred_Action_Grounding {

	public static function build_last_orders_reference_response( $command, $normalized, $client_context = [], $callbacks = [] ) {
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

		$selector = self::resolve_last_orders_selector( $normalized );

		if ( '' === $selector ) {
			return null;
		}

		$resolved_order_ids = self::resolve_reference_order_ids(
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

		return self::normalize_resolve_validate_result( $parsed, $command, $normalized, $client_context, $callbacks );
	}

	public static function apply_read_only_ai_override( $result, $command, $normalized ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$action      = sanitize_text_field( $result['action'] ?? '' );
		$intent_name = sanitize_key( $result['intent']['name'] ?? '' );
		$order_ids   = class_exists( 'YoRo_Order_Helper' ) ? YoRo_Order_Helper::extract_order_ids_from_text( $command ) : [];

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

		$result['action']      = 'orders.get_details';
		$result['params']      = $params;
		$result['confidence']  = max( (float) ( $result['confidence'] ?? 0 ), 0.91 );
		$result['parser_rule'] = sanitize_text_field( $result['parser_rule'] ?? 'ai.provider.structured_output' );

		if ( isset( $result['intent'] ) && is_array( $result['intent'] ) ) {
			$result['intent']['name']       = 'get_order_details';
			$result['intent']['action']     = 'orders.get_details';
			$result['intent']['confidence'] = max( (float) ( $result['intent']['confidence'] ?? 0 ), 0.91 );
		}

		return $result;
	}

	public static function apply_confirmation_gated_write_ai_override( $result, $command, $normalized, $client_context = [] ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$grounded = self::build_confirmation_gated_write_inference(
			$command,
			$normalized,
			sanitize_text_field( $result['action'] ?? '' ),
			(float) ( $result['confidence'] ?? 0 ),
			sanitize_text_field( $result['requested_capability'] ?? '' ),
			sanitize_text_field( $result['target_object'] ?? '' ),
			sanitize_key( $result['target_type'] ?? '' ),
			isset( $result['params'] ) && is_array( $result['params'] ) ? $result['params'] : [],
			$client_context
		);

		if ( ! is_array( $grounded ) || empty( $grounded['action'] ) ) {
			return $result;
		}

		$result['action']      = sanitize_text_field( $grounded['action'] );
		$result['params']      = isset( $grounded['params'] ) && is_array( $grounded['params'] ) ? $grounded['params'] : [];
		$result['confidence']  = max( (float) ( $result['confidence'] ?? 0 ), (float) ( $grounded['confidence'] ?? 0.88 ) );
		$result['parser_rule'] = sanitize_text_field( $result['parser_rule'] ?? 'ai.provider.structured_output' );

		if ( ! isset( $result['intent'] ) || ! is_array( $result['intent'] ) ) {
			$result['intent'] = [];
		}

		$intent_name_map = [
			'orders.update_status' => 'update_order_status',
			'orders.hold'          => 'hold_order',
			'orders.add_note'      => 'add_note',
		];

		$result['intent']['name']       = $intent_name_map[ $result['action'] ] ?? ( $result['intent']['name'] ?? '' );
		$result['intent']['action']     = $result['action'];
		$result['intent']['confidence'] = max( (float) ( $result['intent']['confidence'] ?? 0 ), (float) ( $grounded['confidence'] ?? 0.88 ) );

		return $result;
	}

	public static function resolve_reference_action( $command, $normalized, $client_context = [], $inference = [], $callbacks = [] ) {
		$reference_target_type = sanitize_key( $inference['reference_target_type'] ?? '' );
		$reference_scope       = sanitize_key( $inference['reference_scope'] ?? '' );
		$reference_selector    = sanitize_key( $inference['reference_selector'] ?? '' );
		$reference_index       = isset( $inference['reference_index'] ) ? (int) $inference['reference_index'] : -1;
		$reference_confidence  = isset( $inference['reference_confidence'] ) ? (float) $inference['reference_confidence'] : 0;
		$action                = sanitize_text_field( $inference['action'] ?? '' );
		$params                = isset( $inference['params'] ) && is_array( $inference['params'] ) ? $inference['params'] : [];
		$action_confidence     = isset( $inference['action_confidence'] ) ? (float) $inference['action_confidence'] : 0;

		if ( $reference_confidence < 0.75 || '' === $reference_target_type || 'none' === $reference_target_type ) {
			return null;
		}

		if ( 'recommended_sequence' === $reference_target_type ) {
			$followup_state = self::get_reference_followup_state( $client_context, $reference_scope );

			if ( empty( $followup_state['sequence'] ) || ! is_array( $followup_state['sequence'] ) ) {
				return null;
			}

			$sequence = array_values( $followup_state['sequence'] );
			$resolved_index = self::resolve_reference_index(
				$reference_selector,
				$reference_index,
				(int) $followup_state['current_index'],
				count( $sequence )
			);

			if ( null === $resolved_index || empty( $sequence[ $resolved_index ]['command'] ) ) {
				return null;
			}

			$resolved_command = sanitize_text_field( $sequence[ $resolved_index ]['command'] );
			$resolved = self::parse_referenced_command( $resolved_command, $client_context, $callbacks );

			if ( ! is_array( $resolved ) || empty( $resolved['action'] ) ) {
				return null;
			}

			return [
				'action'     => sanitize_text_field( $resolved['action'] ),
				'params'     => isset( $resolved['params'] ) && is_array( $resolved['params'] ) ? $resolved['params'] : [],
				'confidence' => max( $reference_confidence, $action_confidence, 0.9 ),
			];
		}

		if ( 'last_orders' === $reference_target_type ) {
			$order_ids = self::resolve_reference_order_ids(
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
				$params['order_id']  = absint( reset( $order_ids ) );
			}

			return [
				'action'     => $action,
				'params'     => $params,
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
				'action'     => $resolved_action,
				'params'     => $resolved_params,
				'confidence' => max( $reference_confidence, $action_confidence, 0.86 ),
			];
		}

		return null;
	}

	public static function build_confirmation_gated_write_inference( $command, $normalized, $action, $action_confidence = 0, $requested_capability = '', $target_object = '', $target_type = '', $params = [], $client_context = [] ) {
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
			preg_match( '/\b(?:to|as|status)\b/i', $normalized ) &&
			! preg_match( '/\b(?:add\s+note|with\s+note|note)\b/i', $normalized )
		) {
			return [
				'action'     => 'orders.update_status',
				'params'      => [
					'order_ids' => array_values( array_map( 'absint', $order_ids ) ),
					'status'    => $status,
					'note'      => __( 'Order status updated by YoRo AI.', 'yoro-ai-core' ),
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

		if (
			preg_match( '/\b(?:add\s+(?:a\s+)?)?note\b/i', $normalized ) &&
			! preg_match( '/\b(?:change|mark|move|set|update)\b.*\b(?:to|as|status)\b/i', $normalized )
		) {
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

	public static function looks_like_contextual_plural_order_reference( $normalized ) {
		$normalized = is_string( $normalized ) ? strtolower( trim( $normalized ) ) : '';

		if ( '' === $normalized ) {
			return false;
		}

		return (
			false !== strpos( $normalized, 'these orders' ) ||
			false !== strpos( $normalized, 'those orders' ) ||
			false !== strpos( $normalized, 'selected orders' ) ||
			false !== strpos( $normalized, 'all these' ) ||
			false !== strpos( $normalized, 'all of them' ) ||
			preg_match( '/\bthem\b/i', $normalized )
		);
	}

	public static function resolve_bulk_order_ids_from_context( $normalized, $client_context = [] ) {
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

		if ( self::looks_like_contextual_plural_order_reference( $normalized ) ) {
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

	public static function build_email_supported_action_inference( $command, $normalized, $action, $action_confidence = 0, $client_context = [], $existing_params = [] ) {
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

	public static function build_workflow_preview_inference( $command, $normalized, $action, $action_confidence = 0, $client_context = [], $inference = [] ) {
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

	public static function build_workflow_control_inference( $command, $normalized, $action, $action_confidence = 0, $client_context = [] ) {
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

	public static function build_query_action_inference( $command, $normalized, $action, $params = [], $client_context = [] ) {
		$params = is_array( $params ) ? $params : [];
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized || false === strpos( $normalized, 'order' ) ) {
			return null;
		}

		$limit = self::extract_requested_limit_from_command( $command, 0, 50 );
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

	public static function build_order_details_inference( $command, $normalized, $action, $action_confidence, $requested_capability = '', $target_object = '', $target_type = '' ) {
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

	public static function has_conflicting_grounded_order_intent( $normalized ) {
		return (bool) preg_match(
			'/\b(?:update|change|set status|status to|hold|on hold|add note|note|refund|email|draft|send|save draft|label|shipment|shipping label|packing slip|track|tracking|sync|connect|export|delete|remove|cancel)\b/i',
			$normalized
		);
	}

	public static function looks_like_generic_record_open_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:show|open|view|get|give|load|pull|bring|inspect|review)\b/i',
			$normalized
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

		return $result;
	}

	private static function parse_referenced_command( $command, $client_context = [], $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		if ( ! empty( $callbacks['parse_command'] ) && is_callable( $callbacks['parse_command'] ) ) {
			$parsed = call_user_func( $callbacks['parse_command'], $command, $client_context );

			return is_array( $parsed ) ? $parsed : null;
		}

		return null;
	}

	private static function resolve_last_orders_selector( $normalized ) {
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

	private static function get_reference_followup_state( $client_context, $reference_scope ) {
		$active_followup_type = sanitize_key( $client_context['active_followup_type'] ?? '' );
		$last_result_type     = sanitize_key( $client_context['last_result_type'] ?? '' );

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
			'business'             => [ 'state_key' => 'last_business_followup', 'sequence_key' => 'recommended_sequence' ],
			'operations_attention' => [ 'state_key' => 'last_attention_followup', 'sequence_key' => 'recommended_sequence' ],
			'customer_service'     => [ 'state_key' => 'last_service_followup', 'sequence_key' => 'recommended_sequence' ],
			'fulfillment'          => [ 'state_key' => 'last_fulfillment_followup', 'sequence_key' => 'recommended_sequence' ],
			'inventory'            => [ 'state_key' => 'last_inventory_followup', 'sequence_key' => 'recommended_sequence' ],
			'briefing'             => [ 'state_key' => 'last_briefing', 'sequence_key' => 'recommended_sequence' ],
		];

		if ( empty( $map[ $family ] ) ) {
			return [];
		}

		$state_key    = $map[ $family ]['state_key'];
		$sequence_key = $map[ $family ]['sequence_key'];
		$state        = isset( $client_context[ $state_key ] ) && is_array( $client_context[ $state_key ] )
			? $client_context[ $state_key ]
			: [];

		return [
			'sequence'      => isset( $state[ $sequence_key ] ) && is_array( $state[ $sequence_key ] ) ? $state[ $sequence_key ] : [],
			'current_index' => isset( $state['recommendation_cursor'] ) ? (int) $state['recommendation_cursor'] : -1,
		];
	}

	private static function resolve_reference_index( $selector, $explicit_index, $current_index, $count ) {
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

	private static function resolve_reference_order_ids( $client_context, $selector, $explicit_index ) {
		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? $client_context['last_orders']
			: [];
		$last_order_ids = isset( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] )
			? array_values( array_filter( array_map( 'absint', $client_context['last_order_ids'] ) ) )
			: [];

		$count = ! empty( $last_orders ) ? count( $last_orders ) : count( $last_order_ids );
		$resolved_index = self::resolve_reference_index( $selector, $explicit_index, -1, $count );

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

	private static function extract_template_name_from_command( $command ) {
		return class_exists( 'YoRo_AI_Inferred_Action_Param_Normalizer' )
			? YoRo_AI_Inferred_Action_Param_Normalizer::extract_template_name_from_command( $command )
			: '';
	}

	private static function extract_requested_limit_from_command( $command, $default = 10, $max = 50 ) {
		return class_exists( 'YoRo_AI_Inferred_Action_Param_Normalizer' )
			? YoRo_AI_Inferred_Action_Param_Normalizer::extract_requested_limit_from_command( $command, $default, $max )
			: min( max( 1, absint( $default ) ), max( 1, absint( $max ) ) );
	}
}
