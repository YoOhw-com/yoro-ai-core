<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Inferred_Supported_Action_Router {

	public static function callbacks( $host_callbacks = [] ) {
		$host_callbacks = is_array( $host_callbacks ) ? $host_callbacks : [];

		return [
			'extract_customer_lookup_params_from_command' => static function( $command, $normalized, $target_object = '' ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Param_Normalizer' )
					? YoRo_AI_Inferred_Action_Param_Normalizer::extract_customer_lookup_params( $command, $normalized, $target_object )
					: [];
			},
			'build_grounded_order_details_inference' => static function( $command, $normalized, $action, $action_confidence = 0, $requested_capability = '', $target_object = '', $target_type = '' ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Grounding' )
					? YoRo_AI_Inferred_Action_Grounding::build_order_details_inference( $command, $normalized, $action, $action_confidence, $requested_capability, $target_object, $target_type )
					: null;
			},
			'infer_ai_read_only_action_from_surface' => static function( $surface, $normalized, $params = [] ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Param_Normalizer' )
					? YoRo_AI_Inferred_Action_Param_Normalizer::infer_read_only_action_from_surface( $surface, $normalized, $params )
					: null;
			},
			'resolve_ai_reference_action' => static function( $command, $normalized, $client_context = [], $inference = [] ) use ( $host_callbacks ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Grounding' )
					? YoRo_AI_Inferred_Action_Grounding::resolve_reference_action( $command, $normalized, $client_context, $inference, self::grounding_callbacks( $host_callbacks ) )
					: null;
			},
			'build_grounded_confirmation_gated_write_inference' => static function( $command, $normalized, $action, $action_confidence = 0, $requested_capability = '', $target_object = '', $target_type = '', $params = [], $client_context = [] ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Grounding' )
					? YoRo_AI_Inferred_Action_Grounding::build_confirmation_gated_write_inference( $command, $normalized, $action, $action_confidence, $requested_capability, $target_object, $target_type, $params, $client_context )
					: null;
			},
			'build_grounded_query_action_inference' => static function( $command, $normalized, $action, $params = [], $client_context = [] ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Grounding' )
					? YoRo_AI_Inferred_Action_Grounding::build_query_action_inference( $command, $normalized, $action, $params, $client_context )
					: null;
			},
			'build_grounded_workflow_preview_inference' => static function( $command, $normalized, $action, $action_confidence = 0, $client_context = [], $inference = [] ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Grounding' )
					? YoRo_AI_Inferred_Action_Grounding::build_workflow_preview_inference( $command, $normalized, $action, $action_confidence, $client_context, $inference )
					: null;
			},
			'build_grounded_workflow_control_inference' => static function( $command, $normalized, $action, $action_confidence = 0, $client_context = [] ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Grounding' )
					? YoRo_AI_Inferred_Action_Grounding::build_workflow_control_inference( $command, $normalized, $action, $action_confidence, $client_context )
					: null;
			},
			'build_grounded_email_supported_action_inference' => static function( $command, $normalized, $action, $action_confidence = 0, $client_context = [], $existing_params = [] ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Grounding' )
					? YoRo_AI_Inferred_Action_Grounding::build_email_supported_action_inference( $command, $normalized, $action, $action_confidence, $client_context, $existing_params )
					: null;
			},
			'infer_ai_read_only_action_from_request_shape' => static function( $command, $normalized, $action, $params, $customer_lookup_params = [], $target_object = '' ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Param_Normalizer' )
					? YoRo_AI_Inferred_Action_Param_Normalizer::infer_read_only_action_from_request_shape( $command, $normalized, $action, $params, $customer_lookup_params, $target_object )
					: null;
			},
			'looks_like_customer_summary_request' => static function( $normalized ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Param_Normalizer' )
					? YoRo_AI_Inferred_Action_Param_Normalizer::looks_like_customer_summary_request( $normalized )
					: false;
			},
			'looks_like_customer_orders_request' => static function( $normalized ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Param_Normalizer' )
					? YoRo_AI_Inferred_Action_Param_Normalizer::looks_like_customer_orders_request( $normalized )
					: false;
			},
			'normalize_ai_inferred_action_params' => static function( $action, $params, $command, $normalized, $client_context = [] ) {
				return class_exists( 'YoRo_AI_Inferred_Action_Param_Normalizer' )
					? YoRo_AI_Inferred_Action_Param_Normalizer::normalize( $action, $params, $command, $normalized, $client_context )
					: ( is_array( $params ) ? $params : [] );
			},
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) use ( $host_callbacks ) {
				$normalized_result = self::invoke(
					$host_callbacks,
					'normalize_parsed_result',
					[ $parsed, $command, $normalized, $client_context ]
				);

				return is_array( $normalized_result ) ? $normalized_result : $parsed;
			},
			'build_assistant_message_response' => static function( $command, $normalized, $parser_rule, $summary, $followup = '', $suggestions = [], $extra_result = [] ) use ( $host_callbacks ) {
				if ( class_exists( 'YoRo_AI_Conversation_Response_Helper' ) ) {
					return YoRo_AI_Conversation_Response_Helper::build_assistant_message_response( $command, $normalized, $parser_rule, $summary, $followup, $suggestions, $extra_result );
				}

				$response = self::invoke(
					$host_callbacks,
					'build_assistant_message_response',
					[ $command, $normalized, $parser_rule, $summary, $followup, $suggestions, $extra_result ]
				);

				return is_array( $response ) ? $response : null;
			},
		];
	}

	public static function route( $command, $normalized, $client_context = [], $inference = [], $callbacks = [] ) {
		$inference = is_array( $inference ) ? $inference : [];
		$callbacks = is_array( $callbacks ) ? $callbacks : [];
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
		$customer_lookup_params = self::invoke(
			$callbacks,
			'extract_customer_lookup_params_from_command',
			[ $command, $normalized, $target_object ],
			[]
		);

		if ( ! is_array( $customer_lookup_params ) ) {
			$customer_lookup_params = [];
		}

		$grounded_order_details = self::invoke(
			$callbacks,
			'build_grounded_order_details_inference',
			[ $command, $normalized, $action, $action_confidence, $requested_capability, $target_object, $target_type ]
		);

		if ( '' === $action || 'none' === $action ) {
			$surface_inferred_action = self::invoke(
				$callbacks,
				'infer_ai_read_only_action_from_surface',
				[ $closest_supported_surface, $normalized, $params ]
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

		$ai_reference_action = self::invoke(
			$callbacks,
			'resolve_ai_reference_action',
			[
				$command,
				$normalized,
				$client_context,
				[
					'action'               => $action,
					'params'               => $params,
					'action_confidence'    => $action_confidence,
					'reference_target_type' => $reference_target_type,
					'reference_scope'      => $reference_scope,
					'reference_selector'   => $reference_selector,
					'reference_index'      => $reference_index,
					'reference_confidence' => $reference_confidence,
				],
			]
		);

		if ( is_array( $ai_reference_action ) ) {
			$action = sanitize_text_field( $ai_reference_action['action'] ?? $action );
			$params = isset( $ai_reference_action['params'] ) && is_array( $ai_reference_action['params'] ) ? $ai_reference_action['params'] : $params;
			$action_confidence = max( $action_confidence, (float) ( $ai_reference_action['confidence'] ?? 0 ) );
			$clarification_needed = false;
		}

		$grounded_write_action = self::invoke(
			$callbacks,
			'build_grounded_confirmation_gated_write_inference',
			[
				$command,
				$normalized,
				$action,
				$action_confidence,
				$requested_capability,
				$target_object,
				$target_type,
				$params,
				$client_context,
			]
		);

		if ( is_array( $grounded_write_action ) ) {
			$action = sanitize_text_field( $grounded_write_action['action'] );
			$params = $grounded_write_action['params'];
			$action_confidence = max( $action_confidence, (float) $grounded_write_action['confidence'] );
			$clarification_needed = false;
		}

		$grounded_query_action = self::invoke(
			$callbacks,
			'build_grounded_query_action_inference',
			[ $command, $normalized, $action, $params, $client_context ]
		);

		if ( is_array( $grounded_query_action ) ) {
			$action = sanitize_text_field( $grounded_query_action['action'] );
			$params = isset( $grounded_query_action['params'] ) && is_array( $grounded_query_action['params'] ) ? $grounded_query_action['params'] : $params;
			$derived_actions = isset( $grounded_query_action['actions'] ) && is_array( $grounded_query_action['actions'] ) ? $grounded_query_action['actions'] : $derived_actions;
			$action_confidence = max( $action_confidence, (float) ( $grounded_query_action['confidence'] ?? 0 ) );
			$clarification_needed = false;
		}

		$grounded_workflow_preview = self::invoke(
			$callbacks,
			'build_grounded_workflow_preview_inference',
			[
				$command,
				$normalized,
				$action,
				$action_confidence,
				$client_context,
				[
					'intent_family'             => $intent_family,
					'closest_supported_surface' => $closest_supported_surface,
					'reference_target_type'     => $reference_target_type,
					'reference_scope'           => $reference_scope,
					'reference_selector'        => $reference_selector,
					'reference_index'           => $reference_index,
					'reference_confidence'      => $reference_confidence,
				],
			]
		);

		if ( is_array( $grounded_workflow_preview ) ) {
			$action = sanitize_text_field( $grounded_workflow_preview['action'] );
			$params = $grounded_workflow_preview['params'];
			$action_confidence = max( $action_confidence, (float) $grounded_workflow_preview['confidence'] );
			$clarification_needed = false;
		}

		$grounded_workflow_control = self::invoke(
			$callbacks,
			'build_grounded_workflow_control_inference',
			[ $command, $normalized, $action, $action_confidence, $client_context ]
		);

		if ( is_array( $grounded_workflow_control ) ) {
			$action = sanitize_text_field( $grounded_workflow_control['action'] );
			$params = $grounded_workflow_control['params'];
			$action_confidence = max( $action_confidence, (float) $grounded_workflow_control['confidence'] );
			$clarification_needed = false;
		}

		$grounded_email_action = self::invoke(
			$callbacks,
			'build_grounded_email_supported_action_inference',
			[ $command, $normalized, $action, $action_confidence, $client_context, $params ]
		);

		if ( is_array( $grounded_email_action ) ) {
			$action = sanitize_text_field( $grounded_email_action['action'] );
			$params = $grounded_email_action['params'];
			$action_confidence = max( $action_confidence, (float) $grounded_email_action['confidence'] );
			$clarification_needed = false;
		}

		if ( 'multi.query_and_apply' !== $action ) {
			$shape_inferred_action = self::invoke(
				$callbacks,
				'infer_ai_read_only_action_from_request_shape',
				[ $command, $normalized, $action, $params, $customer_lookup_params, $target_object ]
			);

			if ( is_array( $shape_inferred_action ) ) {
				$action = sanitize_text_field( $shape_inferred_action['action'] ?? $action );
				$params = isset( $shape_inferred_action['params'] ) && is_array( $shape_inferred_action['params'] ) ? $shape_inferred_action['params'] : $params;
				$action_confidence = max( $action_confidence, (float) ( $shape_inferred_action['confidence'] ?? 0 ) );
				$clarification_needed = false;
			}
		}

		$looks_like_customer_summary = (bool) self::invoke(
			$callbacks,
			'looks_like_customer_summary_request',
			[ $normalized ],
			false
		);
		$looks_like_customer_orders = (bool) self::invoke(
			$callbacks,
			'looks_like_customer_orders_request',
			[ $normalized ],
			false
		);

		if ( $looks_like_customer_summary && ! empty( $customer_lookup_params ) ) {
			$action = 'customers.get_summary';
			$params = array_merge( $customer_lookup_params, $params );
		} elseif ( $looks_like_customer_orders && ! empty( $customer_lookup_params ) ) {
			$action = 'orders.find_by_customer';
			$params = array_merge( $customer_lookup_params, $params );
		} elseif ( in_array( $action, [ 'customers.get_summary', 'orders.find_by_customer' ], true ) && ! empty( $customer_lookup_params ) ) {
			$params = array_merge( $customer_lookup_params, $params );
		}

		$is_read_only_allowed = self::is_read_only_action_allowed( $action );
		$is_write_allowed = self::is_confirmation_gated_write_action_allowed( $action );
		$is_query_action_allowed = in_array( $action, [ 'multi.query_and_apply' ], true );

		if ( '' === $action || 'none' === $action || ( ! $is_read_only_allowed && ! $is_write_allowed && ! $is_query_action_allowed ) ) {
			return null;
		}

		if ( ( $is_write_allowed || $is_query_action_allowed ) && 'task_request' !== $mode ) {
			return null;
		}

		if ( ! $is_query_action_allowed ) {
			$params = self::invoke(
				$callbacks,
				'normalize_ai_inferred_action_params',
				[ $action, $params, $command, $normalized, $client_context ],
				$params
			);
			$params = is_array( $params ) ? $params : [];
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

			$normalized_result = self::invoke(
				$callbacks,
				'normalize_parsed_result',
				[ $parsed, $command, $normalized, $client_context ]
			);

			if ( ! is_array( $normalized_result ) ) {
				return null;
			}

			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		if ( $action_confidence >= 0.60 || $clarification_needed ) {
			return self::build_clarification_response(
				$command,
				$normalized,
				$action,
				$clarification_question,
				$requested_capability,
				$target_object,
				$is_write_allowed,
				$callbacks
			);
		}

		return null;
	}

	public static function is_read_only_action_allowed( $action ) {
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
				'workflow.review_suspicious_order',
				'workflow.refund_review',
				'workflow.write_email_for_order',
				'workflow.write_and_save_email_draft_for_order',
				'workflow.write_and_send_email_for_order',
				'workflow.write_email_for_orders',
				'workflow.write_and_save_email_drafts_for_orders',
				'workflow.write_and_send_emails_for_orders',
				'workflow.fulfillment_followup_preview',
				'workflow.customer_followup_preview',
				'workflow.operations_attention_preview',
				'workflow.inventory_pressure_preview',
				'workflow.business_summary_preview',
			],
			true
		);
	}

	public static function is_confirmation_gated_write_action_allowed( $action ) {
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
				'workflow.hold_and_add_note',
				'workflow.update_status_with_note',
				'workflow.review_suspicious_order',
				'workflow.verify_customer_before_fulfillment',
				'workflow.cancel',
			],
			true
		);
	}

	private static function build_clarification_response( $command, $normalized, $action, $clarification_question = '', $requested_capability = '', $target_object = '', $is_write_action = false, $callbacks = [] ) {
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
			'workflow.hold_and_add_note' => 'Hold this order and add a note',
			'workflow.refund_review' => 'Review refund details for this order',
			'workflow.review_suspicious_order' => 'Review this suspicious order workflow',
			'workflow.update_status_with_note' => 'Update this order status with a note',
			'workflow.verify_customer_before_fulfillment' => 'Verify this customer before fulfillment',
			'workflow.write_email_for_order' => 'Write an email for this order',
			'workflow.write_and_save_email_draft_for_order' => 'Write and save an email draft for this order',
			'workflow.write_and_send_email_for_order' => 'Write and send an email for this order',
			'workflow.write_email_for_orders' => 'Write emails for these orders',
			'workflow.write_and_save_email_drafts_for_orders' => 'Write and save email drafts for these orders',
			'workflow.write_and_send_emails_for_orders' => 'Write and send emails for these orders',
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
				? __( 'I think I know what you want to change, but I want to confirm it first.', 'yoro-ai-core' )
				: __( 'I think I know what you want to open, but I want to confirm it first.', 'yoro-ai-core' )
			);
		$followup = '';

		if ( '' !== $suggested_command ) {
			$followup = sprintf(
				__( 'If that is right, say "%s".', 'yoro-ai-core' ),
				$suggested_command
			);
		} elseif ( '' !== $requested_capability || '' !== $target_object ) {
			$followup = __( 'Please restate the store view or record you want me to open.', 'yoro-ai-core' );
		}

		$response = self::invoke(
			$callbacks,
			'build_assistant_message_response',
			[
				$command,
				$normalized,
				'ai.inferred_read_only_action.clarify',
				$message,
				$followup,
				[],
				[
					'conversation_topic'  => 'clarify',
					'conversation_family' => 'ai_inferred_action',
					'result_type'         => 'assistant_message',
					'clarify_reason'      => 'ai_inferred_action',
				],
			]
		);

		if ( is_array( $response ) ) {
			return $response;
		}

		return [
			'success'            => false,
			'matched'            => false,
			'stage'              => 'clarify',
			'message'            => $message,
			'options'            => [],
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'confidence'         => 0.6,
			'suggestion'         => $followup,
			'clarify_reason'     => 'ai_inferred_action',
		];
	}

	private static function grounding_callbacks( $host_callbacks = [] ) {
		$host_callbacks = is_array( $host_callbacks ) ? $host_callbacks : [];

		return [
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) use ( $host_callbacks ) {
				$normalized_result = self::invoke(
					$host_callbacks,
					'normalize_parsed_result',
					[ $parsed, $command, $normalized, $client_context ]
				);

				return is_array( $normalized_result ) ? $normalized_result : $parsed;
			},
			'parse_command' => static function( $command, $client_context = [] ) use ( $host_callbacks ) {
				$parsed = self::invoke(
					$host_callbacks,
					'parse_command',
					[ $command, $client_context ]
				);

				return is_array( $parsed ) ? $parsed : null;
			},
		];
	}

	private static function invoke( $callbacks, $name, $args = [], $default = null ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		if ( empty( $callbacks[ $name ] ) || ! is_callable( $callbacks[ $name ] ) ) {
			return $default;
		}

		return call_user_func_array( $callbacks[ $name ], $args );
	}
}
