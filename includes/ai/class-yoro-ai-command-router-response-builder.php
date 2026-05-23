<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Command_Router_Response_Builder {

	public static function build_clarification_response( $command, $normalized, $decision, $message, $confidence ) {
		$decision = is_array( $decision ) ? $decision : [];
		$suggestions = isset( $decision['suggestions'] ) && is_array( $decision['suggestions'] )
			? $decision['suggestions']
			: [];
		$clarify_reason = self::infer_clarify_reason( $decision );
		$action = sanitize_text_field( $decision['action'] ?? '' );
		$params = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$raw_missing = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];
		$missing = self::infer_missing_params_for_pending_workflow( $action, $params, $raw_missing );
		$current_workflow = self::build_pending_missing_params_workflow_from_decision(
			$command,
			$decision
		);
		$should_suppress_generic_options = self::should_suppress_clarify_options(
			$decision,
			$missing,
			$current_workflow
		);
		$options = [];

		if ( ! $should_suppress_generic_options ) {
			$options = ! empty( $suggestions ) ? $suggestions : self::get_clarification_options( $normalized );
		}

		$response = [
			'success'              => false,
			'matched'              => false,
			'stage'                => 'clarify',
			'message'              => sanitize_text_field( $message ),
			'options'              => $options,
			'parser_rule'          => 'ai.command_router.clarify',
			'parser_source'        => 'ai_router',
			'confidence'           => $confidence,
			'original_command'     => $command,
			'normalized_command'   => $normalized,
			'language'             => sanitize_text_field( $decision['language'] ?? 'unknown' ),
			'intent_family'        => sanitize_key( $decision['intent_family'] ?? '' ),
			'summary'              => sanitize_text_field( $decision['summary'] ?? '' ),
			'reasoning_summary'    => sanitize_text_field( $decision['reasoning_summary'] ?? '' ),
			'meta'                 => isset( $decision['meta'] ) && is_array( $decision['meta'] ) ? $decision['meta'] : [],
			'command_decision'     => self::compact_decision( $decision ),
			'clarify_reason'       => $clarify_reason,
			'raw_param_keys'       => isset( $decision['raw_param_keys'] ) && is_array( $decision['raw_param_keys'] ) ? $decision['raw_param_keys'] : [],
			'dropped_param_keys'   => isset( $decision['dropped_param_keys'] ) && is_array( $decision['dropped_param_keys'] ) ? $decision['dropped_param_keys'] : [],
			'params_trimmed_count' => isset( $decision['params_trimmed_count'] ) ? absint( $decision['params_trimmed_count'] ) : 0,
			'semantic_frame_validator_changes' => isset( $decision['semantic_frame_validator_changes'] ) && is_array( $decision['semantic_frame_validator_changes'] ) ? $decision['semantic_frame_validator_changes'] : [],
		];

		if ( isset( $decision['command_shape'] ) && is_array( $decision['command_shape'] ) ) {
			$response['command_shape'] = $decision['command_shape'];
		}

		if ( isset( $decision['semantic_frame'] ) && is_array( $decision['semantic_frame'] ) ) {
			$response['semantic_frame'] = $decision['semantic_frame'];
		}

		if ( isset( $decision['action_plan'] ) && is_array( $decision['action_plan'] ) ) {
			$response['action_plan'] = $decision['action_plan'];
		}

		if ( '' !== $action ) {
			$response['action'] = $action;
		}

		if ( ! empty( $params ) ) {
			$response['params'] = $params;
		}

		if ( ! empty( $missing ) ) {
			$response['missing'] = $missing;
		}

		if ( ! empty( $current_workflow ) ) {
			$response['current_workflow'] = $current_workflow;
			$response['options'] = self::append_workflow_cancel_option( $response['options'], $current_workflow );
		}

		return $response;
	}

	public static function build_assistant_response( $command, $normalized, $parser_rule, $summary, $followup = '', $suggestions = [], $extra_result = [], $confidence = 1.0 ) {
		$topic    = is_array( $extra_result ) ? sanitize_key( $extra_result['conversation_topic'] ?? '' ) : '';
		$summary  = self::normalize_assistant_response_copy( $summary, $topic );
		$followup = self::normalize_assistant_response_copy( $followup, $topic );
		$fallback_reason = is_array( $extra_result ) ? sanitize_key( $extra_result['fallback_reason'] ?? '' ) : '';
		$suggestions = self::filter_assistant_suggestions( $suggestions );

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
			'fallback_reason'    => $fallback_reason,
			'result'             => $result,
		];
	}

	public static function build_non_action_response( $command, $normalized, $decision, $mode, $confidence, $callbacks = [] ) {
		$decision  = is_array( $decision ) ? $decision : [];
		$callbacks = is_array( $callbacks ) ? $callbacks : [];
		$mode      = sanitize_key( $mode );

		if ( 'clarify' === $mode ) {
			$message = sanitize_text_field( $decision['clarification_question'] ?? '' );

			if ( '' === $message ) {
				$message = sanitize_text_field( $decision['summary'] ?? '' );
			}

			if ( '' === $message ) {
				$message = __( 'I need a little more detail before I act on that.', 'yoro-ai-core' );
			}

			return self::build_clarification_response(
				$command,
				$normalized,
				$decision,
				$message,
				$confidence
			);
		}

		if ( 'unsupported_request' === $mode ) {
			$unsupported_decision = self::call_callback(
				$callbacks,
				'build_unsupported_response_decision',
				[
					'',
					$command,
					[
						'summary'                => sanitize_text_field( $decision['summary'] ?? '' ),
						'followup'               => sanitize_text_field( $decision['clarification_question'] ?? '' ),
						'unsupported_capability' => sanitize_text_field( $command ),
					],
				],
				[]
			);
			$unsupported_decision = is_array( $unsupported_decision ) ? $unsupported_decision : [];

			return self::build_assistant_response(
				$command,
				$normalized,
				'ai.command_router.unsupported_request',
				$unsupported_decision['summary'] ?? sanitize_text_field( $decision['summary'] ?? '' ),
				$unsupported_decision['followup'] ?? sanitize_text_field( $decision['clarification_question'] ?? '' ),
				isset( $unsupported_decision['suggestions'] ) && is_array( $unsupported_decision['suggestions'] ) ? $unsupported_decision['suggestions'] : [],
				[
					'conversation_topic'        => 'unsupported_request',
					'closest_supported_surface' => sanitize_key( $unsupported_decision['closest_supported_surface'] ?? '' ),
					'result_type'               => 'assistant_message',
					'language'                  => sanitize_text_field( $decision['language'] ?? 'unknown' ),
					'reasoning_summary'         => sanitize_text_field( $decision['reasoning_summary'] ?? '' ),
					'command_decision'          => self::compact_decision( $decision ),
					'developer_handoff_offer'   => ! empty( $unsupported_decision['developer_handoff_offer'] ),
					'handoff_request_text'      => sanitize_text_field( $unsupported_decision['handoff_request_text'] ?? $command ),
					'fallback_reason'           => 'unsupported_request',
				],
				$confidence
			);
		}

		if ( 'chat_only' === $mode ) {
			$ai_inferred_support_response = self::call_callback(
				$callbacks,
				'build_ai_inferred_capability_support_response',
				[
					$command,
					$normalized,
					[],
					[
						'intent_family'           => sanitize_key( $decision['intent_family'] ?? '' ),
						'capability_family_key'   => sanitize_key( $decision['capability_family_key'] ?? '' ),
						'nearest_registry_family' => sanitize_key( $decision['nearest_registry_family'] ?? '' ),
						'support_hypothesis'      => sanitize_key( $decision['support_hypothesis'] ?? '' ),
						'target_family_guess'     => sanitize_key( $decision['target_family_guess'] ?? '' ),
						'target_object'           => sanitize_text_field( $decision['target_object'] ?? '' ),
						'requested_capability'    => sanitize_text_field( $decision['requested_capability'] ?? '' ),
						'closest_supported_surface' => sanitize_key( $decision['closest_supported_surface'] ?? '' ),
						'reasoning_summary'       => sanitize_text_field( $decision['reasoning_summary'] ?? '' ),
						'summary'                 => sanitize_text_field( $decision['summary'] ?? '' ),
						'followup'                => sanitize_text_field( $decision['followup'] ?? '' ),
						'target_type'             => sanitize_key( $decision['target_type'] ?? '' ),
					],
				],
				null
			);

			if ( is_array( $ai_inferred_support_response ) ) {
				return $ai_inferred_support_response;
			}

			$is_capability_inquiry = (bool) self::call_callback(
				$callbacks,
				'is_capability_support_inquiry',
				[ $normalized ],
				false
			);
			$is_capability_question = (bool) self::call_callback(
				$callbacks,
				'is_capability_question',
				[ $normalized ],
				false
			);

			if ( $is_capability_inquiry || $is_capability_question ) {
				$capability_response = self::call_callback(
					$callbacks,
					'build_capability_support_response',
					[ $command, $normalized, [] ],
					null
				);

				if ( is_array( $capability_response ) ) {
					return $capability_response;
				}
			}
		}

		$summary = sanitize_text_field( $decision['summary'] ?? '' );

		if ( '' === $summary ) {
			if ( 'out_of_scope' === $mode ) {
				$summary = __( 'I am focused on WooCommerce store operations, so I cannot help with that topic here.', 'yoro-ai-core' );
			} else {
				$summary = __( 'I can help with WooCommerce store operations.', 'yoro-ai-core' );
			}
		}

		return self::build_assistant_response(
			$command,
			$normalized,
			'ai.command_router.' . $mode,
			$summary,
			sanitize_text_field( $decision['clarification_question'] ?? '' ),
			isset( $decision['suggestions'] ) && is_array( $decision['suggestions'] ) ? $decision['suggestions'] : [],
			[
				'conversation_topic' => $mode,
				'intent_family'      => sanitize_key( $decision['intent_family'] ?? '' ),
				'result_type'        => 'assistant_message',
				'language'           => sanitize_text_field( $decision['language'] ?? 'unknown' ),
				'reasoning_summary'  => sanitize_text_field( $decision['reasoning_summary'] ?? '' ),
				'command_decision'   => self::compact_decision( $decision ),
				'fallback_reason'    => 'non_task_mode',
			],
			$confidence
		);
	}

	public static function compact_decision( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];

		return [
			'mode'                  => sanitize_key( $decision['mode'] ?? '' ),
			'intent_family'         => sanitize_key( $decision['intent_family'] ?? '' ),
			'action'                => sanitize_text_field( $decision['action'] ?? '' ),
			'confidence'            => isset( $decision['confidence'] ) ? (float) $decision['confidence'] : 0,
			'catalog_action_count'  => isset( $decision['catalog_action_count'] ) ? absint( $decision['catalog_action_count'] ) : 0,
			'missing'               => isset( $decision['missing'] ) && is_array( $decision['missing'] ) ? array_values( array_map( 'sanitize_key', $decision['missing'] ) ) : [],
			'ambiguities'           => isset( $decision['ambiguities'] ) && is_array( $decision['ambiguities'] ) ? array_values( array_map( 'sanitize_text_field', $decision['ambiguities'] ) ) : [],
			'clarification_question' => sanitize_text_field( $decision['clarification_question'] ?? '' ),
			'raw_param_keys'        => isset( $decision['raw_param_keys'] ) && is_array( $decision['raw_param_keys'] ) ? array_values( array_map( 'sanitize_key', $decision['raw_param_keys'] ) ) : [],
			'dropped_param_keys'    => isset( $decision['dropped_param_keys'] ) && is_array( $decision['dropped_param_keys'] ) ? array_values( array_map( 'sanitize_key', $decision['dropped_param_keys'] ) ) : [],
			'params_trimmed_count'  => isset( $decision['params_trimmed_count'] ) ? absint( $decision['params_trimmed_count'] ) : 0,
			'action_plan'           => isset( $decision['action_plan'] ) && is_array( $decision['action_plan'] ) ? $decision['action_plan'] : [],
			'semantic_frame_validator_changes' => isset( $decision['semantic_frame_validator_changes'] ) && is_array( $decision['semantic_frame_validator_changes'] ) ? $decision['semantic_frame_validator_changes'] : [],
		];
	}

	private static function call_callback( $callbacks, $key, $args = [], $default = null ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];
		$key       = sanitize_key( $key );

		if ( '' === $key || empty( $callbacks[ $key ] ) || ! is_callable( $callbacks[ $key ] ) ) {
			return $default;
		}

		return call_user_func_array( $callbacks[ $key ], is_array( $args ) ? $args : [] );
	}

	private static function should_suppress_clarify_options( $decision, $missing, $current_workflow ) {
		$decision = is_array( $decision ) ? $decision : [];
		$missing = is_array( $missing ) ? $missing : [];
		$current_workflow = is_array( $current_workflow ) ? $current_workflow : [];
		$action = sanitize_text_field( $decision['action'] ?? '' );
		$mode   = sanitize_key( $decision['mode'] ?? '' );

		if ( ! empty( $current_workflow ) ) {
			return false;
		}

		if ( '' === $action ) {
			return false;
		}

		return in_array( $mode, [ 'clarify', 'task_request' ], true );
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
		$params = self::hydrate_pending_workflow_target_params_from_command( $command, $action, $params );
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
				$workflow_type = 'order_note';
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
				$workflow_type = 'order_status_update';
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

	private static function hydrate_pending_workflow_target_params_from_command( $command, $action, $params ) {
		$action = sanitize_text_field( $action );
		$params = is_array( $params ) ? $params : [];

		if ( ! in_array( $action, [ 'orders.add_note', 'orders.update_status', 'orders.compose_customer_email' ], true ) ) {
			return $params;
		}

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			return $params;
		}

		if ( ! empty( $params['order_id'] ) ) {
			$params['order_ids'] = [ absint( $params['order_id'] ) ];
			return $params;
		}

		$order_ids = class_exists( 'YoRo_Order_Helper' )
			? YoRo_Order_Helper::extract_order_ids_from_text( (string) $command )
			: [];
		$order_ids = array_values( array_filter( array_map( 'absint', is_array( $order_ids ) ? $order_ids : [] ) ) );

		if ( empty( $order_ids ) ) {
			return $params;
		}

		$params['order_ids'] = $order_ids;
		$params['order_id']  = absint( reset( $order_ids ) );

		return $params;
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

	private static function filter_assistant_suggestions( $suggestions ) {
		if ( ! is_array( $suggestions ) ) {
			return [];
		}

		return array_values(
			array_filter(
				$suggestions,
				static function( $suggestion ) {
					if ( ! is_array( $suggestion ) ) {
						return false;
					}

					return '' !== sanitize_text_field( $suggestion['action'] ?? '' );
				}
			)
		);
	}

	private static function infer_clarify_reason( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];
		$missing = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];

		if ( ! empty( $missing[0] ) ) {
			switch ( $missing[0] ) {
				case 'order_id':
				case 'order_ids':
				case 'customer_id':
				case 'draft_id':
				case 'template_id':
					return 'missing_target';
				case 'status':
				case 'order_status':
					return 'missing_status';
				case 'note':
				case 'note_text':
					return 'missing_note';
				case 'instruction':
				case 'email_body':
				case 'body':
				case 'subject':
					return 'missing_instruction';
			}
		}

		$confidence = isset( $decision['confidence'] ) ? (float) $decision['confidence'] : 0;

		if ( $confidence > 0 && $confidence < 0.75 ) {
			return 'low_confidence';
		}

		return 'details';
	}

	private static function dedupe_assistant_suggestions( $suggestions ) {
		$output = [];
		$seen   = [];

		foreach ( (array) $suggestions as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( $item['label'] ?? '' );
			$action = sanitize_text_field( $item['action'] ?? '' );
			$value = sanitize_text_field( $item['value'] ?? $item['command'] ?? '' );

			if ( '' === $label || ( '' === $value && '' === $action ) ) {
				continue;
			}

			$key = '' !== $action
				? strtolower( $action . '|' . wp_json_encode( is_array( $item['params'] ?? null ) ? $item['params'] : [] ) )
				: strtolower( $value );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$normalized_item = [
				'label' => $label,
			];

			if ( '' !== $action ) {
				$normalized_item['action']  = $action;
				$normalized_item['params']  = is_array( $item['params'] ?? null ) ? $item['params'] : [];
				$normalized_item['confirm'] = ! empty( $item['confirm'] );
				$normalized_item['preview'] = ! empty( $item['preview'] );
			}

			if ( '' !== $value ) {
				$normalized_item['value'] = $value;
			}

			if ( ! empty( $item['requires_input'] ) ) {
				$normalized_item['requires_input'] = true;
			}

			$output[] = $normalized_item;
		}

		return $output;
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

		if ( class_exists( 'YoRo_Response_UX_Formatter' ) ) {
			$text = YoRo_Response_UX_Formatter::clean_user_facing_copy( $text );
		}

		if ( 'chat_only' === $topic && preg_match( '/\bidentity information\b|\bno WooCommerce task\b/i', $text ) ) {
			return __( "I don't know who you are from this chat alone. I can use store context when you ask about orders, customers, or operations.", 'yoro-ai-core' );
		}

		if ( in_array( $topic, [ 'chat_only', 'out_of_scope_general', 'out_of_scope' ], true ) && preg_match( '/\bcasual conversation\b|\bchat mode\b|\bclassifier\b/i', $text ) ) {
			$text = '';
		}

		if ( 'chat_only' === $topic && $had_classifier_intro && preg_match( '/^I can help with WooCommerce store operations\.?$/i', $text ) ) {
			return __( 'Hi. I can help with WooCommerce store operations.', 'yoro-ai-core' );
		}

		if ( '' === $text ) {
			if ( 'chat_only' === $topic ) {
				return __( 'Hi. I can help with WooCommerce store operations.', 'yoro-ai-core' );
			}

			if ( in_array( $topic, [ 'out_of_scope_general', 'out_of_scope' ], true ) ) {
				return __( "I'm focused on WooCommerce store operations, so I can't help with that topic here.", 'yoro-ai-core' );
			}
		}

		return sanitize_text_field( $text );
	}

	private static function append_workflow_cancel_option( $options, $workflow ) {
		$workflow = is_array( $workflow ) ? $workflow : [];

		if ( 'collect_missing_params' !== sanitize_key( $workflow['step'] ?? '' ) ) {
			return is_array( $options ) ? array_values( $options ) : [];
		}

		return [
			[
				'label'   => __( 'Cancel', 'yoro-ai-core' ),
				'command' => 'workflow.cancel',
			],
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
					'label'   => __( 'Show inventory queue', 'yoro-ai-core' ),
					'command' => 'Show inventory queue',
				],
				[
					'label'   => __( 'Show fulfillment queue', 'yoro-ai-core' ),
					'command' => 'Show fulfillment queue',
				],
				[
					'label'   => __( 'Show operations attention queue', 'yoro-ai-core' ),
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
					'label'   => __( 'Show fulfillment queue', 'yoro-ai-core' ),
					'command' => 'Show fulfillment queue',
				],
				[
					'label'   => __( 'Show operations attention queue', 'yoro-ai-core' ),
					'command' => 'Show operations attention queue',
				],
				[
					'label'   => __( 'Show latest 5 orders', 'yoro-ai-core' ),
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
					'label'   => __( 'Show customer service queue', 'yoro-ai-core' ),
					'command' => 'Show customer service queue',
				],
				[
					'label'   => __( 'Show latest 5 orders', 'yoro-ai-core' ),
					'command' => 'Show latest 5 orders',
				],
				[
					'label'   => __( 'Show morning briefing', 'yoro-ai-core' ),
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
					'label'   => __( 'Show business summary for this week', 'yoro-ai-core' ),
					'command' => 'Show business summary for this week',
				],
				[
					'label'   => __( 'Show operations summary for today', 'yoro-ai-core' ),
					'command' => 'Show operations summary for today',
				],
				[
					'label'   => __( 'Show operations attention queue', 'yoro-ai-core' ),
					'command' => 'Show operations attention queue',
				],
			];
		}

		return [
			[
				'label'   => __( 'Show morning briefing', 'yoro-ai-core' ),
				'command' => 'Show morning briefing',
			],
			[
				'label'   => __( 'Show operations attention queue', 'yoro-ai-core' ),
				'command' => 'Show operations attention queue',
			],
			[
				'label'   => __( 'Show latest 5 orders', 'yoro-ai-core' ),
				'command' => 'Show latest 5 orders',
			],
		];
	}
}
