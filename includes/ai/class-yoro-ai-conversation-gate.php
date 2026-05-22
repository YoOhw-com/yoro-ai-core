<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Conversation_Gate {

	private static $callbacks = [];

	public static function should_try( $normalized, $callbacks = [] ) {
		$previous_callbacks = self::$callbacks;
		self::$callbacks = is_array( $callbacks ) ? $callbacks : [];

		try {
			return self::should_try_internal( $normalized );
		} finally {
			self::$callbacks = $previous_callbacks;
		}
	}

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$previous_callbacks = self::$callbacks;
		self::$callbacks = is_array( $callbacks ) ? $callbacks : [];

		try {
			return self::parse_internal( $command, $normalized, $client_context );
		} finally {
			self::$callbacks = $previous_callbacks;
		}
	}

	private static function should_try_internal( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( '' === $normalized ) {
			return false;
		}

		if (
			self::is_greeting_only( $normalized ) ||
			YoRo_AI_Command_Router_Non_Action_Handlers::is_capability_support_inquiry( $normalized ) ||
			YoRo_AI_Command_Router_Non_Action_Handlers::is_capability_question( $normalized ) ||
			self::is_identity_question( $normalized ) ||
			self::is_wellbeing_question( $normalized ) ||
			self::is_thanks_only( $normalized ) ||
			self::is_goodbye_only( $normalized )
		) {
			return true;
		}

		if ( preg_match( '/^(?:can|could|would|do|does|did|is|are|am|who|what|how|why|when)\b/i', $normalized ) ) {
			return true;
		}

		if ( self::resolve_explicit_capability_gap_family( $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:create|make|open)\b.*\b(?:new order|draft order|manual order)\b/i', $normalized ) ) {
			return true;
		}

		if ( self::looks_like_general_out_of_scope_family( $normalized ) ) {
			return true;
		}

		if ( self::looks_like_general_background_request( $normalized ) ) {
			return true;
		}

		if ( self::looks_like_general_non_store_statement( $normalized ) ) {
			return true;
		}

		if ( self::is_ambiguous_action_reference( $normalized ) ) {
			return true;
		}

		if ( str_word_count( $normalized ) <= 12 ) {
			return true;
		}

		return false;
	}

	private static function parse_internal( $command, $normalized, $client_context = [] ) {
		if ( ! class_exists( 'YoRo_AI_Parser' ) || ! method_exists( 'YoRo_AI_Parser', 'parse_conversation' ) ) {
			return null;
		}

		$result = YoRo_AI_Parser::parse_conversation( $command, $client_context );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return null;
		}

		$mode       = sanitize_key( $result['mode'] ?? '' );
		$family     = sanitize_key( $result['intent_family'] ?? '' );
		$surface    = sanitize_key( $result['closest_supported_surface'] ?? '' );
		$capability_family_key = sanitize_key( $result['capability_family_key'] ?? '' );
		$nearest_registry_family = sanitize_key( $result['nearest_registry_family'] ?? '' );
		$requested_capability = sanitize_text_field( $result['requested_capability'] ?? '' );
		$target_object = sanitize_text_field( $result['target_object'] ?? '' );
		$target_type = sanitize_key( $result['target_type'] ?? '' );
		$target_family_guess = sanitize_key( $result['target_family_guess'] ?? '' );
		$support_hypothesis = sanitize_key( $result['support_hypothesis'] ?? '' );
		$reasoning_summary = sanitize_text_field( $result['reasoning_summary'] ?? '' );
		$suggested_action = sanitize_text_field( $result['suggested_action'] ?? '' );
		$suggested_action_params = isset( $result['suggested_action_params'] ) && is_array( $result['suggested_action_params'] ) ? $result['suggested_action_params'] : [];
		$action_confidence = isset( $result['action_confidence'] ) ? (float) $result['action_confidence'] : 0;
		$clarification_needed = ! empty( $result['clarification_needed'] );
		$clarification_question = sanitize_text_field( $result['clarification_question'] ?? '' );
		$reference_target_type = sanitize_key( $result['reference_target_type'] ?? '' );
		$reference_scope = sanitize_key( $result['reference_scope'] ?? '' );
		$reference_selector = sanitize_key( $result['reference_selector'] ?? '' );
		$reference_index = isset( $result['reference_index'] ) ? (int) $result['reference_index'] : -1;
		$reference_confidence = isset( $result['reference_confidence'] ) ? (float) $result['reference_confidence'] : 0;
		$confidence = isset( $result['confidence'] ) ? (float) $result['confidence'] : 0;

		if (
			(
				self::looks_like_general_out_of_scope_family( $normalized ) ||
				self::looks_like_general_background_request( $normalized ) ||
				self::looks_like_general_non_store_statement( $normalized )
			) &&
			'out_of_scope_general' !== $mode
		) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'policy.out_of_scope_general.guard',
				__( 'I’m focused on WooCommerce store operations, so I can’t help with general news or other non-store topics here.', 'yoro-ai-core' ),
				__( 'Next, I can take you back to a store briefing or the main attention queue instead.', 'yoro-ai-core' ),
				self::build_out_of_scope_general_suggestions(),
				[
					'conversation_topic'  => 'out_of_scope_general',
					'conversation_family' => 'general_knowledge',
					'result_type'         => 'assistant_message',
				]
			);
		}

		$explicit_capability_gap = self::resolve_explicit_capability_gap_family( $normalized );

		if ( $explicit_capability_gap && 'unsupported_request' !== $mode ) {
			$unsupported_decision = YoRo_AI_Command_Router_Non_Action_Handlers::build_unsupported_response_decision(
				$explicit_capability_gap['parser_family'],
				$command,
				[ 'capability_family_key' => $explicit_capability_gap['capability_key'] ]
			);

			return self::build_assistant_message_response(
				$command,
				$normalized,
				'policy.unsupported_request.guard',
				$unsupported_decision['summary'],
				$unsupported_decision['followup'],
				$unsupported_decision['suggestions'],
				[
					'conversation_topic'      => 'unsupported_request',
					'conversation_family'     => sanitize_key( $explicit_capability_gap['parser_family'] ?? $family ?: 'unsupported_request' ),
					'closest_supported_surface' => sanitize_key( $unsupported_decision['closest_supported_surface'] ?? $explicit_capability_gap['surface'] ?? $surface ),
					'result_type'             => 'assistant_message',
					'developer_handoff_offer' => ! empty( $unsupported_decision['developer_handoff_offer'] ),
					'handoff_request_text'    => sanitize_text_field( $unsupported_decision['handoff_request_text'] ?? $result['unsupported_capability'] ?? $command ),
					'capability_family_key'   => sanitize_key( $unsupported_decision['capability_key'] ?? $explicit_capability_gap['capability_key'] ?? $capability_family_key ),
					'nearest_registry_family' => sanitize_key( $nearest_registry_family ),
					'requested_capability'    => $requested_capability,
					'target_object'           => $target_object,
					'target_type'             => $target_type,
					'target_family_guess'     => $target_family_guess,
					'support_hypothesis'      => $support_hypothesis,
					'reasoning_summary'       => $reasoning_summary,
				]
			);
		}

		$ai_inferred_action_result = self::route_ai_inferred_supported_action(
			$command,
			$normalized,
			$client_context,
			[
				'intent_family'           => $family,
				'closest_supported_surface' => $surface,
				'suggested_action'        => $suggested_action,
				'suggested_action_params' => $suggested_action_params,
				'action_confidence'       => $action_confidence,
				'clarification_needed'    => $clarification_needed,
				'clarification_question'  => $clarification_question,
				'reference_target_type'   => $reference_target_type,
				'reference_scope'         => $reference_scope,
				'reference_selector'      => $reference_selector,
				'reference_index'         => $reference_index,
				'reference_confidence'    => $reference_confidence,
				'requested_capability'    => $requested_capability,
				'target_object'           => $target_object,
				'target_type'             => $target_type,
				'mode'                    => $mode,
			]
		);

		if ( is_array( $ai_inferred_action_result ) ) {
			return $ai_inferred_action_result;
		}

		if ( 'task_request' === $mode ) {
			if ( $confidence < 0.6 ) {
				return null;
			}

			return null;
		}

		if ( $confidence < 0.6 ) {
			return null;
		}

		if ( 'chat_only' === $mode ) {
			$ai_inferred_support_response = YoRo_AI_Command_Router_Non_Action_Handlers::build_ai_inferred_capability_support_response( $command, $normalized, $client_context, $result );

			if ( is_array( $ai_inferred_support_response ) ) {
				return $ai_inferred_support_response;
			}

			return self::build_assistant_message_response(
				$command,
				$normalized,
				'ai.conversation.chat_only',
				$result['summary'] ?? __( 'Hello. I can help with store operations.', 'yoro-ai-core' ),
				$result['followup'] ?? '',
				self::build_chat_only_suggestions( $surface ),
				[
					'conversation_topic' => 'chat_only',
					'conversation_family' => $family,
					'closest_supported_surface' => $surface,
					'result_type'        => 'assistant_message',
					'capability_family_key' => $capability_family_key,
					'nearest_registry_family' => sanitize_key( $nearest_registry_family ),
					'requested_capability' => $requested_capability,
					'target_object' => $target_object,
					'target_type' => $target_type,
					'target_family_guess' => $target_family_guess,
					'support_hypothesis' => $support_hypothesis,
					'reasoning_summary' => $reasoning_summary,
				]
			);
		}

		if ( 'out_of_scope_general' === $mode ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'ai.conversation.out_of_scope_general',
				$result['summary'] ?: __( 'I’m focused on WooCommerce store operations, so I can’t help with that topic here.', 'yoro-ai-core' ),
				$result['followup'] ?: __( 'Next, I can take you back to a store briefing or the main attention queue instead.', 'yoro-ai-core' ),
				self::build_out_of_scope_general_suggestions(),
				[
					'conversation_topic' => 'out_of_scope_general',
					'conversation_family' => $family,
					'closest_supported_surface' => $surface,
					'result_type'        => 'assistant_message',
					'capability_family_key' => $capability_family_key,
					'nearest_registry_family' => sanitize_key( $nearest_registry_family ),
					'requested_capability' => $requested_capability,
					'target_object' => $target_object,
					'target_type' => $target_type,
					'target_family_guess' => $target_family_guess,
					'support_hypothesis' => $support_hypothesis,
					'reasoning_summary' => $reasoning_summary,
				]
			);
		}

		if ( 'unsupported_request' === $mode ) {
			$unsupported_decision = YoRo_AI_Command_Router_Non_Action_Handlers::build_unsupported_response_decision( $family, $command, $result );

			return self::build_assistant_message_response(
				$command,
				$normalized,
				'ai.conversation.unsupported_request',
				$unsupported_decision['summary'],
				$unsupported_decision['followup'],
				$unsupported_decision['suggestions'],
				[
					'conversation_topic'      => 'unsupported_request',
					'conversation_family'     => $family,
					'closest_supported_surface' => sanitize_key( $unsupported_decision['closest_supported_surface'] ?? $surface ),
					'result_type'             => 'assistant_message',
					'developer_handoff_offer' => ! empty( $unsupported_decision['developer_handoff_offer'] ),
					'handoff_request_text'    => sanitize_text_field( $unsupported_decision['handoff_request_text'] ?? $result['unsupported_capability'] ?? $command ),
					'capability_family_key'   => sanitize_key( $unsupported_decision['capability_key'] ?? $capability_family_key ),
					'nearest_registry_family' => sanitize_key( $nearest_registry_family ),
					'requested_capability'    => $requested_capability,
					'target_object'           => $target_object,
					'target_type'             => $target_type,
					'target_family_guess'     => $target_family_guess,
					'support_hypothesis'      => $support_hypothesis,
					'reasoning_summary'       => $reasoning_summary,
				]
			);
		}

		if ( 'clarify' === $mode || ! empty( $result['requires_clarification'] ) ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'message'            => $result['summary'] ?: __( 'I need a little more detail before I act on that. What would you like me to do?', 'yoro-ai-core' ),
				'options'            => self::build_family_clarification_options( $family, $surface ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'confidence'         => $confidence,
				'suggestion'         => $result['followup'] ?? '',
			];
		}

		return null;
	}

	private static function is_greeting_only( $normalized ) {
		$result = self::call_callback( 'is_greeting_only', [ $normalized ], null );

		if ( null !== $result ) {
			return (bool) $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_greeting_only( $normalized )
			: false;
	}

	private static function is_identity_question( $normalized ) {
		$result = self::call_callback( 'is_identity_question', [ $normalized ], null );

		if ( null !== $result ) {
			return (bool) $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_identity_question( $normalized )
			: false;
	}

	private static function is_wellbeing_question( $normalized ) {
		$result = self::call_callback( 'is_wellbeing_question', [ $normalized ], null );

		if ( null !== $result ) {
			return (bool) $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_wellbeing_question( $normalized )
			: false;
	}

	private static function is_thanks_only( $normalized ) {
		$result = self::call_callback( 'is_thanks_only', [ $normalized ], null );

		if ( null !== $result ) {
			return (bool) $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_thanks_only( $normalized )
			: false;
	}

	private static function is_goodbye_only( $normalized ) {
		$result = self::call_callback( 'is_goodbye_only', [ $normalized ], null );

		if ( null !== $result ) {
			return (bool) $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_goodbye_only( $normalized )
			: false;
	}

	private static function is_ambiguous_action_reference( $normalized ) {
		$result = self::call_callback( 'is_ambiguous_action_reference', [ $normalized ], null );

		if ( null !== $result ) {
			return (bool) $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_ambiguous_action_reference( $normalized )
			: false;
	}

	private static function looks_like_general_out_of_scope_family( $normalized ) {
		$result = self::call_callback( 'looks_like_general_out_of_scope_family', [ $normalized ], null );

		if ( null !== $result ) {
			return (bool) $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::looks_like_general_out_of_scope_family( $normalized )
			: false;
	}

	private static function looks_like_general_background_request( $normalized ) {
		$result = self::call_callback( 'looks_like_general_background_request', [ $normalized ], null );

		if ( null !== $result ) {
			return (bool) $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::looks_like_general_background_request( $normalized )
			: false;
	}

	private static function looks_like_general_non_store_statement( $normalized ) {
		$result = self::call_callback( 'looks_like_general_non_store_statement', [ $normalized ], null );

		if ( null !== $result ) {
			return (bool) $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::looks_like_general_non_store_statement( $normalized )
			: false;
	}

	private static function resolve_explicit_capability_gap_family( $normalized ) {
		$result = self::call_callback( 'resolve_explicit_capability_gap_family', [ $normalized ], null );

		if ( is_array( $result ) ) {
			return $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::resolve_explicit_capability_gap_family( $normalized )
			: null;
	}

	private static function build_out_of_scope_general_suggestions() {
		$result = self::call_callback( 'build_out_of_scope_general_suggestions', [], [] );

		if ( is_array( $result ) && ! empty( $result ) ) {
			return $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::build_out_of_scope_general_suggestions()
			: [];
	}

	private static function build_chat_only_suggestions( $surface = '' ) {
		$result = self::call_callback( 'build_chat_only_suggestions', [ $surface ], [] );

		if ( is_array( $result ) && ! empty( $result ) ) {
			return $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::build_chat_only_suggestions( $surface )
			: [];
	}

	private static function build_family_clarification_options( $family = '', $surface = '' ) {
		$result = self::call_callback( 'build_family_clarification_options', [ $family, $surface ], [] );

		if ( is_array( $result ) && ! empty( $result ) ) {
			return $result;
		}

		return class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::build_family_clarification_options( $family, $surface )
			: [];
	}

	private static function route_ai_inferred_supported_action( $command, $normalized, $client_context = [], $inference = [] ) {
		$result = self::call_callback( 'route_ai_inferred_supported_action', [ $command, $normalized, $client_context, $inference ], null );

		return is_array( $result ) ? $result : null;
	}

	private static function build_assistant_message_response( $command, $normalized, $parser_rule, $summary, $followup = '', $suggestions = [], $extra_result = [] ) {
		$result = self::call_callback(
			'build_assistant_message_response',
			[ $command, $normalized, $parser_rule, $summary, $followup, $suggestions, $extra_result ],
			null
		);

		if ( is_array( $result ) ) {
			return $result;
		}

		if ( class_exists( 'YoRo_AI_Conversation_Response_Helper' ) ) {
			return YoRo_AI_Conversation_Response_Helper::build_assistant_message_response(
				$command,
				$normalized,
				$parser_rule,
				$summary,
				$followup,
				$suggestions,
				$extra_result
			);
		}

		if ( class_exists( 'YoRo_AI_Command_Router_Response_Builder' ) ) {
			return YoRo_AI_Command_Router_Response_Builder::build_assistant_response(
				$command,
				$normalized,
				$parser_rule,
				$summary,
				$followup,
				$suggestions,
				$extra_result
			);
		}

		return null;
	}

	private static function call_callback( $name, $args = [], $default = null ) {
		if ( empty( self::$callbacks[ $name ] ) || ! is_callable( self::$callbacks[ $name ] ) ) {
			return $default;
		}

		return call_user_func_array( self::$callbacks[ $name ], $args );
	}
}
