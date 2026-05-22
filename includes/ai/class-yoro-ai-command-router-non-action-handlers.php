<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Command_Router_Non_Action_Handlers {

	public static function callbacks() {
		return [
			'build_unsupported_response_decision' => static function( $family, $command, $result ) {
				return self::build_unsupported_response_decision( $family, $command, $result );
			},
			'build_ai_inferred_capability_support_response' => static function( $command, $normalized, $client_context, $result ) {
				return self::build_ai_inferred_capability_support_response( $command, $normalized, $client_context, $result );
			},
			'is_capability_support_inquiry' => static function( $normalized ) {
				return self::is_capability_support_inquiry( $normalized );
			},
			'is_capability_question' => static function( $normalized ) {
				return self::is_capability_question( $normalized );
			},
			'build_capability_support_response' => static function( $command, $normalized, $client_context ) {
				return self::build_capability_support_response( $command, $normalized, $client_context );
			},
		];
	}

	public static function build_ai_inferred_capability_support_response( $command, $normalized, $client_context = [], $result = [] ) {
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
			$support_record['summary'] ?? ( $result['summary'] ?? __( 'I can help with store operations.', 'yoro-ai-core' ) ),
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

	public static function is_capability_question( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/^(?:help|help me|what can you do|what do you do|what can you help with|what can you help me with|show me what you can do|what should i ask|what should i ask you|how can you help|how can you help me)(?:\s+(?:yoro))?\??$/i',
			$normalized
		);
	}

	public static function is_capability_support_inquiry( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/\b(?:support|supported|compatible|compatibility|work with|works with|integrate with|connect to)\b.*\b(?:plugin|plugins|extension|extensions|app|apps|tool|tools|service|services|carrier|carriers|platform|platforms)\b|\b(?:plugin|extension|app|tool|service|carrier|platform)\b.*\b(?:support|supported|compatible|compatibility|work with|works with)\b|\b(?:do|does|can|are)\b.*\b(?:support|supported|compatible|work with|works with)\b/i',
			$normalized
		);
	}

	public static function build_capability_support_response( $command, $normalized, $client_context = [] ) {
		$target_name    = self::extract_capability_target_name( $command, $normalized );
		$plugin_match   = self::find_matching_plugin_for_target( $target_name );
		$target_family  = self::classify_capability_target_family( $command, $normalized, $target_name, $plugin_match );
		$support_record = class_exists( 'YoRo_Capability_Registry' )
			? YoRo_Capability_Registry::build_support_decision( $target_family, $target_name, $plugin_match )
			: [];

		$summary = $support_record['summary'] ?? __( 'I can help with store operations, but I do not verify that compatibility directly yet.', 'yoro-ai-core' );
		$followup = $support_record['followup'] ?? __( 'Next, I can help with the closest store workflow I do support.', 'yoro-ai-core' );
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

	public static function build_unsupported_response_decision( $family = '', $command = '', $result = [] ) {
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
					? sprintf( __( "I can't handle %s yet.", 'yoro-ai-core' ), strtolower( $requested_capability ) )
					: __( "I can't create a new order yet.", 'yoro-ai-core' );
				$followup = __( 'Next, I can pass that request to the developers. For now, I can help you inspect or manage existing orders.', 'yoro-ai-core' );
				break;
			case 'shipping_extensions':
				$summary  = '' !== $requested_capability
					? sprintf( __( "I can't handle %s yet.", 'yoro-ai-core' ), strtolower( $requested_capability ) )
					: __( "I can't create shipments or shipping labels yet.", 'yoro-ai-core' );
				$followup = __( 'Next, I can pass that request to the developers. For now, I can help you review the order or the fulfillment queue.', 'yoro-ai-core' );
				break;
			case 'third_party_crm_and_team_integrations':
				$summary  = '' !== $target_object
					? sprintf( __( "I can't connect directly to %s yet.", 'yoro-ai-core' ), $target_object )
					: __( "I can't connect to that external tool yet.", 'yoro-ai-core' );
				$followup = __( 'Next, I can pass that integration request to the developers. For now, I can help with the store work I already support.', 'yoro-ai-core' );
				break;
			default:
				$summary  = sanitize_text_field( $result['summary'] ?? '' );
				$followup = sanitize_text_field( $result['followup'] ?? '' );
				break;
		}

		$request_text = sanitize_text_field( $result['unsupported_capability'] ?? $requested_capability ?: $target_object ?: $command );

		return [
			'capability_key'            => $capability_family_key,
			'status'                    => sanitize_key( $result['support_hypothesis'] ?? 'unsupported' ),
			'summary'                   => '' !== $summary ? $summary : __( "I can't do that yet.", 'yoro-ai-core' ),
			'followup'                  => '' !== $followup ? $followup : __( 'Next, I can pass that request to the developers.', 'yoro-ai-core' ),
			'closest_supported_surface' => '',
			'developer_handoff_offer'   => true,
			'handoff_request_text'      => $request_text,
			'suggestions'               => [
				[
					'label'   => __( 'Send to developers', 'yoro-ai-core' ),
					'action'  => 'assistant.submit_developer_request',
					'params'  => [
						'request_text'              => $request_text,
						'original_command'          => sanitize_text_field( $command ),
						'unsupported_capability'    => $request_text,
						'conversation_family'       => $family,
						'closest_supported_surface' => '',
						'context_order_id'          => 0,
					],
					'confirm' => false,
					'preview' => false,
				],
			],
		];
	}

	private static function extract_capability_target_name( $command, $normalized ) {
		$candidates = [
			'/^(?:do|does|can|are)\s+(?:you|this|it)\s+(?:support|supported by|work with|works with|integrate with|connect to|handle|use|compatible with)\s+(?:the\s+)?(?:plugin|plugins|extension|extensions|app|apps|tool|tools|service|services|carrier|carriers|platform|platforms)?\s*"?(.+?)"?\??$/i',
			'/^(?:is|are)\s+(?:you|this|it)\s+(?:compatible with)\s+(?:the\s+)?(?:plugin|plugins|extension|extensions|app|apps|tool|tools|service|services|carrier|carriers|platform|platforms)?\s*"?(.+?)"?\??$/i',
			'/\b(?:support|supported by|compatible with|work with|works with|integrate with|connect to|handle|use)\s+(?:the\s+)?(?:plugin|plugins|extension|extensions|app|apps|tool|tools|service|services|carrier|carriers|platform|platforms)?\s*"?(.+?)"?\??$/i',
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

		if ( '' !== $plugin_name_normalized && 'woocommerce' !== $plugin_name_normalized ) {
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
					'label' => __( 'Send to developers', 'yoro-ai-core' ),
					'value' => 'Yes, send this to developers',
				],
			];
		}

		return array_slice(
			self::dedupe_assistant_suggestions(
				[
					[
						'label' => __( 'Show morning briefing', 'yoro-ai-core' ),
						'value' => 'Show morning briefing',
					],
					[
						'label' => __( 'Show operations attention queue', 'yoro-ai-core' ),
						'value' => 'Show operations attention queue',
					],
					[
						'label' => __( 'Show latest 5 orders', 'yoro-ai-core' ),
						'value' => 'Show latest 5 orders',
					],
				]
			),
			0,
			3
		);
	}

	private static function build_capability_support_handoff_text( $target_name, $family, $plugin_match = [] ) {
		$target_label = ! empty( $plugin_match['name'] ) ? $plugin_match['name'] : $target_name;
		$target_label = sanitize_text_field( $target_label );

		switch ( sanitize_key( $family ) ) {
			case 'shipping_extension':
				return sprintf( __( 'Support or compatibility for shipping extension: %s', 'yoro-ai-core' ), $target_label );
			case 'tracking_extension':
				return sprintf( __( 'Support or compatibility for tracking extension: %s', 'yoro-ai-core' ), $target_label );
			case 'carrier_integration':
				return sprintf( __( 'Direct carrier integration support for: %s', 'yoro-ai-core' ), $target_label );
			case 'external_integration':
				return sprintf( __( 'External integration support for: %s', 'yoro-ai-core' ), $target_label );
			case 'plugin_compatibility':
				return sprintf( __( 'Plugin compatibility support for: %s', 'yoro-ai-core' ), $target_label );
			default:
				return sprintf( __( 'Capability support question for: %s', 'yoro-ai-core' ), $target_label );
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

	private static function build_assistant_message_response( $command, $normalized, $parser_rule, $summary, $followup = '', $suggestions = [], $extra_result = [] ) {
		$topic    = is_array( $extra_result ) ? sanitize_key( $extra_result['conversation_topic'] ?? '' ) : '';
		$summary  = self::normalize_assistant_response_copy( $summary, $topic );
		$followup = self::normalize_assistant_response_copy( $followup, $topic );
		$clarify_reason = is_array( $extra_result ) ? sanitize_key( $extra_result['clarify_reason'] ?? '' ) : '';
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
}
