<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Parser {

	const PROMPT_VERSION_PARSE = 'parse.v1';
	const PROMPT_VERSION_WORKFLOW = 'workflow.v1';
	const PROMPT_VERSION_SUGGESTIONS = 'suggestions.v1';
	const PROMPT_VERSION_EMAIL = 'email.v1';
	const PROMPT_VERSION_CONVERSATION = 'conversation.v3';
	const PROMPT_VERSION_COMMAND_ROUTER = 'command_router.v2';

	public static function parse( $command ) {
		$provider = self::get_provider();

		if ( YORO_AI_PROVIDER_YORO_CLOUD === $provider ) {
			return self::parse_with_yoro_cloud( $command );
		}

		return self::parse_with_openai_direct( $command );
	}

	public static function parse_freeform( $prompt ) {
		if ( YORO_AI_PROVIDER_YORO_CLOUD === self::get_provider() ) {
			return new WP_Error( 'not_ready', 'YoRo Cloud not ready' );
		}

		return self::parse_freeform_openai( $prompt );
	}

	public static function parse_workflow_plan( $prompt ) {
		if ( YORO_AI_PROVIDER_YORO_CLOUD === self::get_provider() ) {
			return new WP_Error(
				'not_ready',
				__( 'YoRo Cloud workflow planner is not ready.', 'yoro-agent-core' )
			);
		}

		return self::parse_workflow_plan_openai( $prompt );
	}

	public static function compose_email( WC_Order $order, $instruction ) {
		if ( ! YoRo_AI_Provider_Resolver::supports( 'email_compose' ) ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'OpenAI API key is missing.', 'yoro-agent-core' )
			);
		}

		$order_context = [
			'order_number'     => $order->get_order_number(),
			'status'           => $order->get_status(),
			'total'            => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ) ),
			'currency'         => $order->get_currency(),
			'customer_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'payment_method'   => $order->get_payment_method_title(),
			'shipping_name'    => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'shipping_city'    => $order->get_shipping_city(),
			'shipping_country' => $order->get_shipping_country(),
		];

		$trace_id = self::generate_trace_id();
		$payload = YoRo_AI_Email_Prompt::build( $order_context, $instruction );
		$result  = YoRo_AI_Provider_Resolver::get_client()->compose_email( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = YoRo_AI_Email_Result_Normalizer::decode( $result );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'yoro_ai_invalid_email',
				__( 'AI returned an invalid email draft.', 'yoro-agent-core' )
			);
		}

		return [
			'to'       => sanitize_email( $order->get_billing_email() ),
			'subject'  => $parsed['subject'],
			'body'     => $parsed['body'],
			'template' => 'ai_custom',
			'meta'     => self::build_meta(
				'email_compose',
				[
					'trace_id'       => $trace_id,
					'prompt_version' => self::PROMPT_VERSION_EMAIL,
				]
			),
		];
	}

	public static function parse_conversation( $command, $client_context = [] ) {
		if ( YORO_AI_PROVIDER_YORO_CLOUD === self::get_provider() ) {
			return self::parse_conversation_with_openai_direct( $command, $client_context );
		}

		return self::parse_conversation_with_openai_direct( $command, $client_context );
	}

	private static function get_provider() {
		return YoRo_AI_Provider_Resolver::resolve_provider();
	}

	public static function build_meta( $feature, array $overrides = [] ) {
		$feature = sanitize_key( $feature );
		$meta = [
			'feature'        => $feature,
			'provider'       => self::get_provider(),
			'model'          => YoRo_AI_Settings::get_openai_model(),
			'prompt_version' => self::get_prompt_version_for_feature( $feature ),
			'trace_id'       => self::generate_trace_id(),
		];

		foreach ( $overrides as $key => $value ) {
			$meta[ sanitize_key( $key ) ] = is_string( $value )
				? sanitize_text_field( $value )
				: $value;
		}

		return $meta;
	}

	private static function generate_trace_id() {
		return 'yoro_ai_' . wp_generate_uuid4();
	}

	private static function get_prompt_version_for_feature( $feature ) {
		switch ( sanitize_key( $feature ) ) {
			case 'workflow_plan':
				return self::PROMPT_VERSION_WORKFLOW;
			case 'suggestions':
				return self::PROMPT_VERSION_SUGGESTIONS;
			case 'email_compose':
				return self::PROMPT_VERSION_EMAIL;
			case 'conversation':
				return self::PROMPT_VERSION_CONVERSATION;
			case 'command_router':
				return self::PROMPT_VERSION_COMMAND_ROUTER;
			case 'parse':
			default:
				return self::PROMPT_VERSION_PARSE;
		}
	}

	private static function parse_with_openai_direct( $command ) {
		if ( ! YoRo_AI_Settings::get_openai_api_key() ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'OpenAI API key is missing.', 'yoro-agent-core' )
			);
		}

		$actions = YoRo_Action_Registry::all();

		$available_actions = [];

		foreach ( $actions as $name => $action ) {
			if ( ! self::should_expose_action_to_ai( $name, $action, $command ) ) {
				continue;
			}

			$available_actions[] = [
				'name'                  => $name,
				'description'           => $action['description'] ?? '',
				'input_schema'          => $action['input_schema'] ?? [],
				'requires_confirmation' => ! empty( $action['requires_confirmation'] ),
				'risk_level'            => $action['risk_level'] ?? 'low',
				'write_action'          => ! empty( $action['write_action'] ),
				'capabilities'          => $action['capabilities'] ?? [],
			];
		}

		$available_actions = apply_filters(
			'yoro_ai_available_actions',
			$available_actions,
			$command,
			$actions
		);
		$trace_id = self::generate_trace_id();
		$meta = self::build_meta(
			'parse',
			[
				'trace_id'       => $trace_id,
				'prompt_version' => self::PROMPT_VERSION_PARSE,
			]
		);
		$body = YoRo_AI_Parse_Prompt::build(
			$command,
			$available_actions,
			[],
			[ 'intents' => [], 'actions' => [] ],
			self::get_available_order_statuses_for_ai()
		);
		$output_text = YoRo_AI_Provider_Resolver::get_client()->parse_action( $body );

		if ( is_wp_error( $output_text ) ) {
			return $output_text;
		}

		$parsed = YoRo_AI_Parse_Result_Normalizer::decode( $output_text );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'yoro_ai_invalid_json',
				__( 'AI parser returned invalid JSON.', 'yoro-agent-core' )
			);
		}

		if ( empty( $parsed['matched'] ) ) {
			return [
				'success'          => false,
				'matched'          => false,
				'message'          => $parsed['reason'] ?? __( 'Command not recognized.', 'yoro-agent-core' ),
				'parser'           => 'ai',
				'meta'             => $meta,
				'original_command' => $command,
			];
		}

		$intent = sanitize_key( $parsed['intent'] ?? '' );
		$action = sanitize_text_field( $parsed['action'] ?? '' );

		if ( empty( $action ) && $intent && class_exists( 'YoRo_Intent_Router' ) ) {
			$action = YoRo_Intent_Router::map_intent_name_to_action( $intent );
		}

		if ( ! YoRo_Action_Registry::get( $action ) ) {
			return new WP_Error(
				'yoro_ai_unknown_action',
				__( 'AI parser selected an unknown action.', 'yoro-agent-core' )
			);
		}

		$params = self::clean_params_for_action(
			$action,
			is_array( $parsed['params'] ) ? $parsed['params'] : []
		);

		$confidence = isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0;

		//if ( self::is_ambiguous_command( $command, $action, $parsed ) ) {
		//	YoRo_Logger::log(
		//		[
		//			'type'       => 'ai_parse',
		//			'user_id'    => get_current_user_id(),
		//			'command'    => $command,
		//			'action'     => $action,
		//			'params'     => $params,
		//			'model'      => self::get_openai_model(),
		//			'confidence' => $confidence,
		//			'provider'   => self::get_provider(),
		//			'status'     => 'ambiguous',
		//			'result'     => [
		//				'reason' => $parsed['reason'] ?? '',
		//			],
		//		]
		//	);

		//	return [
		//		'success'          => false,
		//		'matched'          => false,
		//		'stage'            => 'clarify',
		//		'confidence'       => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0,
		//		'reason'           => $parsed['reason'] ?? '',
		//		'message'          => __( 'What would you like me to show or manage?', 'yoro-agent-core' ),
		//		'options'          => [
		//			[
		//				'label'   => __( 'Recent orders', 'yoro-agent-core' ),
		//				'command' => 'Show recent orders',
		//			],
		//			[
		//				'label'   => __( 'Pending orders', 'yoro-agent-core' ),
		//				'command' => 'Show pending orders',
		//			],
		//			[
		//				'label'   => __( 'Completed orders', 'yoro-agent-core' ),
		//				'command' => 'Show completed orders',
		//			],
		//		],
		//		'original_command' => $command,
		//	];
		//}

		YoRo_Logger::log(
			[
				'type'       => 'ai_parse',
				'user_id'    => get_current_user_id(),
				'command'    => $command,
				'action'     => $action,
				'params'     => $params,
				'trace_id'   => $trace_id,
				'model'      => YoRo_AI_Settings::get_openai_model(),
				'confidence' => $confidence,
				'provider'   => self::get_provider(),
				'status'     => 'success',
				'result'     => [
					'reason'      => $parsed['reason'] ?? '',
					'parser_rule' => 'ai.provider.structured_output',
					'meta'        => $meta,
				],
			]
		);

		return [
			'success'          => true,
			'matched'          => true,
			'action'           => $action,
			'intent'           => [
				'name'       => $intent ? $intent : sanitize_key( str_replace( '.', '_', $action ) ),
				'confidence' => $confidence,
				'source'     => 'ai',
			],
			'params'           => $params,
			'parser_rule'      => 'ai.provider.structured_output',
			'parser_source'    => 'ai',
			'confidence'       => $confidence,
			'reason'           => $parsed['reason'] ?? '',
			'meta'             => $meta,
			'original_command' => $command,
		];
	}

	private static function parse_freeform_openai( $prompt ) {
		if ( ! YoRo_AI_Settings::get_openai_api_key() ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'OpenAI API key is missing.', 'yoro-agent-core' )
			);
		}

		$body = [
			'model' => YoRo_AI_Settings::get_openai_model(),
			'input' => [
				[
					'role'    => 'system',
					'content' => 'Return JSON only. Return an array of suggestion objects. Do not include markdown.',
				],
				[
					'role'    => 'user',
					'content' => (string) $prompt,
				],
			],
			'text' => [
				'format' => [
					'type'   => 'json_schema',
					'name'   => 'yoro_suggestions',
					'strict' => true,
					'schema' => [
						'type'                 => 'array',
						'items'                => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'label' => [ 'type' => 'string' ],
								'command' => [ 'type' => 'string' ],
							],
							'required' => [ 'label', 'command' ],
						],
					],
				],
			],
		];

		return YoRo_AI_Provider_Resolver::get_client()->generate_suggestions( $body );
	}

	private static function parse_conversation_with_openai_direct( $command, $client_context = [] ) {
		if ( ! YoRo_AI_Settings::get_openai_api_key() ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'OpenAI API key is missing.', 'yoro-agent-core' )
			);
		}

		$trace_id = self::generate_trace_id();
		$meta = self::build_meta(
			'conversation',
			[
				'trace_id'       => $trace_id,
				'prompt_version' => self::PROMPT_VERSION_CONVERSATION,
			]
		);
		$body = YoRo_AI_Conversation_Prompt::build_request( $command, $client_context );
		$output_text = YoRo_AI_Provider_Resolver::get_client()->generate_suggestions( $body );

		if ( is_wp_error( $output_text ) ) {
			return $output_text;
		}

		$parsed = json_decode( (string) $output_text, true );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'yoro_ai_conversation_invalid_json',
				__( 'AI conversation gate returned invalid JSON.', 'yoro-agent-core' )
			);
		}

		$suggestions = [];
		$raw_suggestions = isset( $parsed['suggestions'] ) && is_array( $parsed['suggestions'] )
			? array_slice( $parsed['suggestions'], 0, 3 )
			: [];

		foreach ( $raw_suggestions as $item ) {
			$label = sanitize_text_field( $item['label'] ?? '' );
			$command_value = sanitize_text_field( $item['command'] ?? '' );

			if ( '' === $label || '' === $command_value ) {
				continue;
			}

			$suggestions[] = [
				'label' => $label,
				'value' => $command_value,
			];
		}

		$summary  = self::normalize_conversation_copy( $parsed['summary'] ?? '' );
		$followup = self::normalize_conversation_copy( $parsed['followup'] ?? '' );

		$capability_family_key = sanitize_key( $parsed['capability_family_key'] ?? '' );
		$nearest_registry_family = sanitize_key( $parsed['nearest_registry_family'] ?? '' );

		if ( ( '' === $capability_family_key || 'none' === $capability_family_key ) && '' !== $nearest_registry_family && 'none' !== $nearest_registry_family ) {
			$capability_family_key = $nearest_registry_family;
		}

		return [
			'mode'                           => sanitize_key( $parsed['mode'] ?? '' ),
			'intent_family'                  => sanitize_key( $parsed['intent_family'] ?? '' ),
			'closest_supported_surface'      => sanitize_key( $parsed['closest_supported_surface'] ?? '' ),
			'capability_family_key'          => $capability_family_key,
			'nearest_registry_family'        => $nearest_registry_family,
			'requested_capability'           => sanitize_text_field( $parsed['requested_capability'] ?? '' ),
			'target_object'                  => sanitize_text_field( $parsed['target_object'] ?? '' ),
			'target_type'                    => sanitize_key( $parsed['target_type'] ?? '' ),
			'target_family_guess'            => sanitize_key( $parsed['target_family_guess'] ?? '' ),
			'support_hypothesis'             => sanitize_key( $parsed['support_hypothesis'] ?? '' ),
			'reasoning_summary'              => self::normalize_conversation_copy( $parsed['reasoning_summary'] ?? '' ),
			'suggested_action'               => sanitize_text_field( $parsed['suggested_action'] ?? '' ),
			'suggested_action_params'        => isset( $parsed['suggested_action_params'] ) && is_array( $parsed['suggested_action_params'] )
				? $parsed['suggested_action_params']
				: [],
			'action_confidence'              => isset( $parsed['action_confidence'] ) ? (float) $parsed['action_confidence'] : 0,
			'clarification_needed'           => ! empty( $parsed['clarification_needed'] ),
			'clarification_question'         => self::normalize_conversation_copy( $parsed['clarification_question'] ?? '' ),
			'reference_target_type'          => sanitize_key( $parsed['reference_target_type'] ?? '' ),
			'reference_scope'                => sanitize_key( $parsed['reference_scope'] ?? '' ),
			'reference_selector'             => sanitize_key( $parsed['reference_selector'] ?? '' ),
			'reference_index'                => isset( $parsed['reference_index'] ) ? (int) $parsed['reference_index'] : -1,
			'reference_confidence'           => isset( $parsed['reference_confidence'] ) ? (float) $parsed['reference_confidence'] : 0,
			'summary'                        => $summary,
			'followup'                       => $followup,
			'confidence'                     => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0,
			'unsupported_capability'         => sanitize_text_field( $parsed['unsupported_capability'] ?? '' ),
			'developer_handoff_recommended'  => ! empty( $parsed['developer_handoff_recommended'] ),
			'requires_clarification'         => ! empty( $parsed['requires_clarification'] ),
			'suggestions'                    => $suggestions,
			'meta'                           => $meta,
		];
	}

	private static function normalize_conversation_copy( $text ) {
		$text = sanitize_text_field( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		$had_classifier_intro = (bool) preg_match( '/^Chat mode activated(?:\s+for\s+casual\s+conversation)?\.?\s*/i', $text );
		$patterns = [
			'/^Chat mode activated(?:\s+for\s+casual\s+conversation)?\.?\s*/i' => '',
			'/^User asked\b[:\s-]*/i'                => 'You asked ',
			'/^The user asked\b[:\s-]*/i'            => 'You asked ',
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

		if ( preg_match( '/\bidentity information\b|\bno WooCommerce task\b/i', $text ) ) {
			return __( 'I don’t know who you are from this chat alone. I can use store context when you ask about orders, customers, or operations.', 'yoro-agent-core' );
		}

		if ( preg_match( '/\bcasual conversation\b|\bchat mode\b|\bclassifier\b/i', $text ) ) {
			return '';
		}

		if ( $had_classifier_intro && preg_match( '/^I can help with WooCommerce store operations\.?$/i', $text ) ) {
			return __( 'Hi. I can help with WooCommerce store operations.', 'yoro-agent-core' );
		}

		return sanitize_text_field( $text );
	}

	private static function parse_workflow_plan_openai( $prompt ) {
		if ( ! YoRo_AI_Settings::get_openai_api_key() ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'OpenAI API key is missing.', 'yoro-agent-core' )
			);
		}

		$body = YoRo_AI_Workflow_Prompt::build_request( $prompt );

		$output_text = YoRo_AI_Provider_Resolver::get_client()->plan_workflow( $body );

		if ( is_wp_error( $output_text ) ) {
			return $output_text;
		}

		if ( empty( $output_text ) ) {
			return new WP_Error(
				'yoro_ai_workflow_empty_response',
				__( 'AI workflow planner returned an empty response.', 'yoro-agent-core' )
			);
		}

		return $output_text;
	}

	private static function parse_with_yoro_cloud( $command ) {
		$client = YoRo_AI_Provider_Resolver::get_client();

		if ( ! $client instanceof YoRo_Cloud_Client ) {
			return self::parse_with_openai_direct( $command );
		}

		return $client->parse_action(
			YoRo_AI_Parse_Prompt::build(
				$command,
				[],
				[],
				[ 'intents' => [], 'actions' => [] ],
				self::get_available_order_statuses_for_ai()
			)
		);
	}

	private static function clean_params_for_action( $action, $params ) {
		switch ( $action ) {
			case 'orders.search':
				$clean = [
					'status' => ! empty( $params['status'] ) && is_array( $params['status'] )
						? array_values( array_filter( array_map( 'sanitize_text_field', $params['status'] ) ) )
						: [ 'pending', 'processing', 'on-hold' ],
					'limit'  => ! empty( $params['limit'] )
						? min( max( absint( $params['limit'] ), 1 ), 50 )
						: 10,
				];

				if ( ! empty( $params['email'] ) ) {
					$clean['email'] = sanitize_email( $params['email'] );
				}

				if ( ! empty( $params['phone'] ) ) {
					$clean['phone'] = sanitize_text_field( $params['phone'] );
				}

				if ( ! empty( $params['payment_method'] ) ) {
					$clean['payment_method'] = sanitize_key( $params['payment_method'] );
				}

				if ( ! empty( $params['date_from'] ) ) {
					$clean['date_from'] = sanitize_text_field( $params['date_from'] );
				}

				if ( ! empty( $params['date_to'] ) ) {
					$clean['date_to'] = sanitize_text_field( $params['date_to'] );
				}

				if ( ! empty( $params['order_id'] ) ) {
					$clean['order_id'] = absint( $params['order_id'] );
				}

				if ( ! empty( $params['customer_name'] ) ) {
					$clean['customer_name'] = sanitize_text_field( $params['customer_name'] );
				}

				if ( ! empty( $params['billing_country'] ) ) {
					$clean['billing_country'] = strtoupper( sanitize_text_field( $params['billing_country'] ) );
				}

				if ( isset( $params['total_min'] ) && '' !== $params['total_min'] ) {
					$clean['total_min'] = (float) $params['total_min'];
				}

				if ( isset( $params['total_max'] ) && '' !== $params['total_max'] ) {
					$clean['total_max'] = (float) $params['total_max'];
				}

				return $clean;

			case 'orders.hold':
				return [
					'order_ids' => ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
						? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
						: [],
				];

			case 'orders.add_note':
				return [
					'order_id'  => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'order_ids' => ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
						? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
						: [],
					'note'      => ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '',
				];

			case 'orders.update_status':
				return [
					'order_id'  => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'order_ids' => ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
						? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
						: [],
					'status'    => ! empty( $params['status'] ) ? sanitize_key( $params['status'] ) : '',
				];

			case 'orders.bulk_update_status_with_note':
				return [
					'order_ids' => ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
						? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
						: [],
					'status'    => ! empty( $params['status'] ) ? sanitize_key( $params['status'] ) : '',
					'note'      => ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '',
				];

			case 'workflow.cancel':
				return [];

			case 'orders.get_details':
				return [
					'order_ids' => ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
						? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
						: [],
				];

			case 'customers.get_summary':
				$clean = [
					'limit' => ! empty( $params['limit'] )
						? min( max( absint( $params['limit'] ), 1 ), 50 )
						: 20,
				];

				if ( ! empty( $params['email'] ) ) {
					$clean['email'] = sanitize_email( $params['email'] );
				}

				if ( ! empty( $params['phone'] ) ) {
					$clean['phone'] = sanitize_text_field( $params['phone'] );
				}

				return $clean;

			case 'store.get_business_summary':
				return [
					'window' => ! empty( $params['window'] ) ? sanitize_key( $params['window'] ) : 'last_7_days',
					'focus'  => ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview',
				];

			case 'store.get_insights':
				return [
					'window' => ! empty( $params['window'] ) ? sanitize_key( $params['window'] ) : 'last_7_days',
					'limit'  => ! empty( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
				];

			case 'orders.find_by_identity':
				return [
					'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'limit'    => ! empty( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 50 ) : 20,
				];

			case 'orders.refund_preview':
				return [
					'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
				];

			case 'orders.compose_customer_email':
				return [
					'order_id'    => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'instruction' => ! empty( $params['instruction'] ) ? sanitize_textarea_field( $params['instruction'] ) : '',
				];

			case 'email_templates.save':
				return [
					'name'    => sanitize_text_field( $params['name'] ?? '' ),
					'subject' => sanitize_text_field( $params['subject'] ?? '' ),
					'body'    => sanitize_textarea_field( $params['body'] ?? '' ),
				];

			case 'email_templates.delete':
				return [
					'template_id' => sanitize_key( $params['template_id'] ?? '' ),
				];

			case 'orders.save_customer_email_draft':
				return [
					'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'subject'  => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '',
					'body'     => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '',
				];

			case 'orders.get_customer_email_drafts':
				return [
					'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
				];

			case 'orders.update_customer_email_draft':
				return [
					'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'draft_id' => ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : '',
					'subject'  => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '',
					'body'     => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '',
				];

			case 'orders.delete_customer_email_draft':
				return [
					'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'draft_id' => ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : '',
				];

			case 'orders.send_customer_email':
				return [
					'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'subject'  => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '',
					'body'     => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '',
				];

			case 'orders.email_customer_preview':
				return [
					'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'template' => ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '',
				];

			case 'orders.email_customer_template':
				return [
					'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'template' => ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '',
				];

			case 'orders.render_customer_email_preview':
				return [
					'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'subject'  => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '',
					'body'     => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '',
				];

			case 'orders.add_private_note_template':
				return [
					'order_id'  => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					'order_ids' => ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
						? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
						: [],
					'template'  => ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '',
				];

			case 'orders.find_by_customer':
				$clean = [
					'limit' => ! empty( $params['limit'] )
						? min( max( absint( $params['limit'] ), 1 ), 50 )
						: 20,
				];

				if ( ! empty( $params['email'] ) ) {
					$clean['email'] = sanitize_email( $params['email'] );
				}

				if ( ! empty( $params['phone'] ) ) {
					$clean['phone'] = sanitize_text_field( $params['phone'] );
				}

				if ( ! empty( $params['customer_id'] ) ) {
					$clean['customer_id'] = absint( $params['customer_id'] );
				}

				return $clean;

			default:
				return [];
		}
	}

	public static function get_confidence_threshold() {
		$threshold = get_option( 'yoro_ai_confidence_threshold', 0.75 );

		return max( 0, min( (float) $threshold, 1 ) );
	}

	public static function get_read_only_confidence_threshold() {
		$threshold = get_option( 'yoro_ai_read_only_confidence_threshold', 0.6 );

		return max( 0, min( (float) $threshold, 1 ) );
	}

	private static function is_ambiguous_command( $command, $action, $parsed ) {
		$normalized = strtolower( trim( wp_strip_all_tags( $command ) ) );

		$ambiguous_terms = [
			'stuff',
			'things',
			'items',
			'data',
			'recent stuff',
			'recent things',
		];

		foreach ( $ambiguous_terms as $term ) {
			if ( false !== strpos( $normalized, $term ) ) {
				return true;
			}
		}

		if ( 'orders.search' === $action ) {
			$has_order_word = preg_match( '/\border|orders|purchase|purchases\b/', $normalized );

			if ( ! $has_order_word ) {
				return true;
			}
		}

		return false;
	}

	private static function should_expose_action_to_ai( $action_name, $action, $command = '' ) {
		$mode = get_option( 'yoro_policy_mode', 'balanced' );

		$risk_level   = sanitize_key( $action['risk_level'] ?? 'low' );
		$write_action = ! empty( $action['write_action'] );

		$capabilities = isset( $action['capabilities'] ) && is_array( $action['capabilities'] )
			? array_values( array_map( 'sanitize_key', $action['capabilities'] ) )
			: [];

		$is_destructive = in_array( 'destructive', $capabilities, true );
		$is_financial   = in_array( 'financial', $capabilities, true );

		if ( 'strict' === $mode ) {
			if ( $is_destructive ) {
				return false;
			}

			if ( $write_action && 'high' === $risk_level ) {
				return false;
			}
		}

		if ( $is_destructive && ! self::command_appears_to_request_destructive_action( $command ) ) {
			return false;
		}

		if ( $is_financial && ! self::command_appears_to_request_financial_action( $command ) ) {
			return false;
		}

		return true;
	}

	private static function command_appears_to_request_destructive_action( $command ) {
		$normalized = strtolower( (string) $command );

		return (bool) preg_match(
			'/\b(delete|remove|discard|erase)\b/i',
			$normalized
		);
	}

	private static function command_appears_to_request_financial_action( $command ) {
		$normalized = strtolower( (string) $command );

		return (bool) preg_match(
			'/\b(refund|refundable|refunded|payment|paid|amount|total|money)\b/i',
			$normalized
		);
	}

	private static function get_available_order_statuses_for_ai() {
		if ( ! class_exists( 'YoRo_Order_Helper' ) ) {
			return [];
		}

		return array_values( YoRo_Order_Helper::get_order_status_options() );
	}
}
