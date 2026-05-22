<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Conversation_Response_Helper {

	public static function get_default_conversational_suggestions() {
		return [
			[
				'label' => __( 'Show morning briefing', 'yoro-ai-core' ),
				'value' => 'Show morning briefing',
			],
			[
				'label' => __( 'Show operations attention queue', 'yoro-ai-core' ),
				'value' => 'Show operations attention queue',
			],
			[
				'label' => __( 'Show customer service queue', 'yoro-ai-core' ),
				'value' => 'Show customer service queue',
			],
		];
	}

	public static function build_out_of_scope_general_suggestions() {
		return [
			[
				'label' => __( 'Show morning briefing', 'yoro-ai-core' ),
				'value' => 'Show morning briefing',
			],
			[
				'label' => __( 'Show operations summary for today', 'yoro-ai-core' ),
				'value' => 'Show operations summary for today',
			],
			[
				'label' => __( 'Show operations attention queue', 'yoro-ai-core' ),
				'value' => 'Show operations attention queue',
			],
		];
	}

	public static function resolve_explicit_capability_gap_family( $normalized ) {
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

	public static function build_explicit_capability_gap_response( $command, $normalized, $client_context = [] ) {
		$gap = self::resolve_explicit_capability_gap_family( $normalized );

		if ( ! is_array( $gap ) || ! class_exists( 'YoRo_AI_Command_Router_Non_Action_Handlers' ) ) {
			return null;
		}

		$unsupported_decision = YoRo_AI_Command_Router_Non_Action_Handlers::build_unsupported_response_decision(
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

	public static function build_chat_only_suggestions( $surface = '' ) {
		switch ( sanitize_key( $surface ) ) {
			case 'morning_briefing':
				return [
					[
						'label' => __( 'Show morning briefing', 'yoro-ai-core' ),
						'value' => 'Show morning briefing',
					],
					[
						'label' => __( 'Show operations attention queue', 'yoro-ai-core' ),
						'value' => 'Show operations attention queue',
					],
					[
						'label' => __( 'Show customer service queue', 'yoro-ai-core' ),
						'value' => 'Show customer service queue',
					],
				];
			case 'order_details':
				return [
					[
						'label' => __( 'Show latest 5 orders', 'yoro-ai-core' ),
						'value' => 'Show latest 5 orders',
					],
					[
						'label' => __( 'Show operations attention queue', 'yoro-ai-core' ),
						'value' => 'Show operations attention queue',
					],
					[
						'label' => __( 'Show fulfillment queue', 'yoro-ai-core' ),
						'value' => 'Show fulfillment queue',
					],
				];
			default:
				return self::get_default_conversational_suggestions();
		}
	}

	public static function build_family_clarification_options( $family = '', $surface = '' ) {
		$family  = sanitize_key( $family );
		$surface = sanitize_key( $surface );

		if ( 'ambiguous_action_reference' === $family || 'order_details' === $surface ) {
			return [
				[
					'label'   => __( 'Show order details', 'yoro-ai-core' ),
					'command' => 'Show order details for this order',
				],
				[
					'label'   => __( 'Show fulfillment queue', 'yoro-ai-core' ),
					'command' => 'Show fulfillment queue',
				],
				[
					'label'   => __( 'Show customer service queue', 'yoro-ai-core' ),
					'command' => 'Show customer service queue',
				],
			];
		}

		if ( 'shipping_operation' === $family || 'fulfillment_queue' === $surface ) {
			return [
				[
					'label'   => __( 'Show fulfillment queue', 'yoro-ai-core' ),
					'command' => 'Show fulfillment queue',
				],
				[
					'label'   => __( 'Show order details', 'yoro-ai-core' ),
					'command' => 'Show order details for this order',
				],
				[
					'label'   => __( 'Show operations attention queue', 'yoro-ai-core' ),
					'command' => 'Show operations attention queue',
				],
			];
		}

		if ( 'external_integration' === $family ) {
			return [
				[
					'label'   => __( 'Show operations attention queue', 'yoro-ai-core' ),
					'command' => 'Show operations attention queue',
				],
				[
					'label'   => __( 'Show morning briefing', 'yoro-ai-core' ),
					'command' => 'Show morning briefing',
				],
				[
					'label'   => __( 'Show latest 5 orders', 'yoro-ai-core' ),
					'command' => 'Show latest 5 orders',
				],
			];
		}

		if ( 'store_request' === $family || 'unclear_store_request' === $family ) {
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

		return self::get_clarification_options( $family );
	}

	public static function dedupe_assistant_suggestions( $suggestions ) {
		$output = [];
		$seen   = [];

		foreach ( (array) $suggestions as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label  = sanitize_text_field( $item['label'] ?? '' );
			$action = sanitize_text_field( $item['action'] ?? '' );
			$value  = sanitize_text_field( $item['value'] ?? $item['command'] ?? '' );

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

	public static function build_assistant_message_response( $command, $normalized, $parser_rule, $summary, $followup = '', $suggestions = [], $extra_result = [] ) {
		$topic           = is_array( $extra_result ) ? sanitize_key( $extra_result['conversation_topic'] ?? '' ) : '';
		$summary         = self::normalize_assistant_response_copy( $summary, $topic );
		$followup        = self::normalize_assistant_response_copy( $followup, $topic );
		$clarify_reason  = is_array( $extra_result ) ? sanitize_key( $extra_result['clarify_reason'] ?? '' ) : '';
		$fallback_reason = is_array( $extra_result ) ? sanitize_key( $extra_result['fallback_reason'] ?? '' ) : '';

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
			'clarify_reason'     => $clarify_reason,
			'fallback_reason'    => $fallback_reason,
			'result'             => $result,
		];
	}

	public static function normalize_assistant_response_copy( $text, $topic = '' ) {
		$text = sanitize_text_field( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		$had_classifier_intro = (bool) preg_match( '/^Chat mode activated(?:\s+for\s+casual\s+conversation)?\.?\s*/i', $text );
		$patterns = [
			'/^Chat mode activated(?:\s+for\s+casual\s+conversation)?\.?\s*/i' => '',
			'/^User asked\b[:\s-]*/i'         => 'You asked ',
			'/^The user asked\b[:\s-]*/i'     => 'You asked ',
			'/^User asks\b[:\s-]*/i'          => 'You asked ',
			'/^The user asks\b[:\s-]*/i'      => 'You asked ',
			'/^User requested\b[:\s-]*/i'     => 'You asked me to ',
			'/^The user requested\b[:\s-]*/i' => 'You asked me to ',
			'/^User requests\b[:\s-]*/i'      => 'You asked me to ',
			'/^The user requests\b[:\s-]*/i'  => 'You asked me to ',
			'/^User is asking\b[:\s-]*/i'     => 'You asked ',
			'/^The user is asking\b[:\s-]*/i' => 'You asked ',
			'/\bthe user\b/i'                 => 'you',
			'/\bUser\b/'                      => 'You',
		];

		foreach ( $patterns as $pattern => $replacement ) {
			$text = preg_replace( $pattern, $replacement, $text );
		}

		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( class_exists( 'YoRo_Response_UX_Formatter' ) ) {
			$text = YoRo_Response_UX_Formatter::clean_user_facing_copy( $text );
		}

		if ( 'chat_only' === $topic && preg_match( '/\bidentity information\b|\bno WooCommerce task\b/i', $text ) ) {
			return __( 'I don’t know who you are from this chat alone. I can use store context when you ask about orders, customers, or operations.', 'yoro-ai-core' );
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
				return __( 'I’m focused on WooCommerce store operations, so I can’t help with that topic here.', 'yoro-ai-core' );
			}
		}

		return sanitize_text_field( $text );
	}

	public static function get_clarification_options( $normalized ) {
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
}
