<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Conversation_Router {

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$normalized = trim( (string) $normalized );

		if ( self::looks_like_developer_handoff_phrase( $normalized ) ) {
			if ( self::is_developer_handoff_confirmation( $normalized ) ) {
				return self::build_developer_handoff_response( $command, $normalized, $callbacks );
			}

			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.unsupported_handoff_without_context',
				__( 'I can send a request to the developers after we identify a specific unsupported capability first.', 'yoro-ai-core' ),
				__( 'Ask for the feature or action you want, and if I can’t do it yet I will offer to pass it along.', 'yoro-ai-core' ),
				[],
				[
					'conversation_topic' => 'developer_handoff',
					'result_type'        => 'assistant_message',
					'fallback_reason'    => 'developer_handoff_without_context',
				]
			);
		}

		if ( self::is_developer_handoff_confirmation( $normalized ) ) {
			return self::build_developer_handoff_response( $command, $normalized, $callbacks );
		}

		$explicit_capability_gap = self::build_explicit_capability_gap_response( $command, $normalized, $client_context );

		if ( is_array( $explicit_capability_gap ) ) {
			return $explicit_capability_gap;
		}

		if ( self::looks_like_current_store_entity_question( $normalized ) ) {
			return self::build_current_store_entity_response( $command, $normalized, $client_context, $callbacks );
		}

		if ( class_exists( 'YoRo_AI_Command_Router_Non_Action_Handlers' ) && YoRo_AI_Command_Router_Non_Action_Handlers::is_capability_support_inquiry( $normalized ) ) {
			return YoRo_AI_Command_Router_Non_Action_Handlers::build_capability_support_response( $command, $normalized, $client_context );
		}

		if ( self::looks_like_general_background_request( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.out_of_scope_background',
				__( 'I can’t help with general background about a person or topic here.', 'yoro-ai-core' ),
				__( 'I’m focused on WooCommerce store operations. Next, I can take you back to a store briefing or the main attention queue instead.', 'yoro-ai-core' ),
				self::build_out_of_scope_general_suggestions(),
				[
					'conversation_topic'  => 'out_of_scope_general',
					'conversation_family' => 'person_or_entity_background',
					'result_type'         => 'assistant_message',
				]
			);
		}

		if ( self::looks_like_general_non_store_statement( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.out_of_scope_statement',
				__( 'I can’t help with general statements or opinions about people or non-store topics here.', 'yoro-ai-core' ),
				__( 'I’m focused on WooCommerce store operations. Next, I can take you back to a store briefing or the main attention queue instead.', 'yoro-ai-core' ),
				self::build_out_of_scope_general_suggestions(),
				[
					'conversation_topic'  => 'out_of_scope_general',
					'conversation_family' => 'general_knowledge',
					'result_type'         => 'assistant_message',
				]
			);
		}

		if ( self::is_ambiguous_action_reference( $normalized ) && ! self::has_ai_reference_resolution_context( $client_context ) ) {
			return self::clarification_response(
				$command,
				$normalized,
				__( 'I need a little more detail before I act on that. What would you like me to do?', 'yoro-ai-core' ),
				'ambiguous_reference'
			);
		}

		if ( self::is_greeting_only( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.greeting.hello',
				__( 'Hello. I can help you review the store, open the main queues, or inspect a specific order.', 'yoro-ai-core' ),
				__( 'Try a briefing, a queue, or an order detail request.', 'yoro-ai-core' ),
				self::get_default_conversational_suggestions()
			);
		}

		if ( class_exists( 'YoRo_AI_Command_Router_Non_Action_Handlers' ) && YoRo_AI_Command_Router_Non_Action_Handlers::is_capability_question( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.capabilities',
				__( 'I can brief you on store activity, open the main queues, review orders, prepare customer emails, and help with fulfillment and inventory follow-up.', 'yoro-ai-core' ),
				__( 'A good place to start is a morning briefing, an attention queue, or a specific order.', 'yoro-ai-core' ),
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
						'label' => __( 'Show fulfillment queue', 'yoro-ai-core' ),
						'value' => 'Show fulfillment queue',
					],
					[
						'label' => __( 'Show inventory queue', 'yoro-ai-core' ),
						'value' => 'Show inventory queue',
					],
				]
			);
		}

		if ( self::is_identity_question( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.identity',
				__( 'I’m YoRo, your WooCommerce operations assistant. I help you inspect orders, review store pressure, prepare customer follow-up, and move through daily operational work.', 'yoro-ai-core' ),
				__( 'You can ask for a briefing, a queue, an order detail view, or an email draft review.', 'yoro-ai-core' ),
				self::get_default_conversational_suggestions()
			);
		}

		if ( self::is_wellbeing_question( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.wellbeing',
				__( 'I’m ready. Next, I can pull up the latest store picture or take you straight to the queue that needs attention most.', 'yoro-ai-core' ),
				__( 'Choose a briefing or queue below to continue.', 'yoro-ai-core' ),
				self::get_default_conversational_suggestions()
			);
		}

		if ( self::is_thanks_only( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.thanks',
				__( 'You’re welcome. When you’re ready, I can continue with the next store task.', 'yoro-ai-core' ),
				'',
				[
					[
						'label' => __( 'Show morning briefing', 'yoro-ai-core' ),
						'value' => 'Show morning briefing',
					],
					[
						'label' => __( 'Show customer service queue', 'yoro-ai-core' ),
						'value' => 'Show customer service queue',
					],
				]
			);
		}

		if ( self::is_goodbye_only( $normalized ) ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.goodbye',
				__( 'Understood. I’ll be here when you want to continue.', 'yoro-ai-core' ),
				'',
				[]
			);
		}

		return null;
	}

	private static function looks_like_developer_handoff_phrase( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Context_Handler' )
			? YoRo_AI_Conversation_Context_Handler::looks_like_developer_handoff_phrase( $normalized )
			: false;
	}

	private static function is_developer_handoff_confirmation( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Context_Handler' )
			? YoRo_AI_Conversation_Context_Handler::is_developer_handoff_confirmation( $normalized )
			: false;
	}

	private static function build_developer_handoff_response( $command, $normalized, $callbacks = [] ) {
		return class_exists( 'YoRo_AI_Conversation_Context_Handler' )
			? YoRo_AI_Conversation_Context_Handler::build_developer_handoff_response( $command, $normalized, $callbacks )
			: null;
	}

	private static function looks_like_current_store_entity_question( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Context_Handler' )
			? YoRo_AI_Conversation_Context_Handler::looks_like_current_store_entity_question( $normalized )
			: false;
	}

	private static function build_current_store_entity_response( $command, $normalized, $client_context = [], $callbacks = [] ) {
		return class_exists( 'YoRo_AI_Conversation_Context_Handler' )
			? YoRo_AI_Conversation_Context_Handler::build_current_store_entity_response( $command, $normalized, $client_context, $callbacks )
			: null;
	}

	private static function build_explicit_capability_gap_response( $command, $normalized, $client_context = [] ) {
		return class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::build_explicit_capability_gap_response( $command, $normalized, $client_context )
			: null;
	}

	private static function looks_like_general_background_request( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::looks_like_general_background_request( $normalized )
			: false;
	}

	private static function looks_like_general_non_store_statement( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::looks_like_general_non_store_statement( $normalized )
			: false;
	}

	private static function is_ambiguous_action_reference( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_ambiguous_action_reference( $normalized )
			: false;
	}

	private static function has_ai_reference_resolution_context( $client_context = [] ) {
		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::has_ai_reference_resolution_context( $client_context )
			: false;
	}

	private static function is_greeting_only( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_greeting_only( $normalized )
			: false;
	}

	private static function is_identity_question( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_identity_question( $normalized )
			: false;
	}

	private static function is_wellbeing_question( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_wellbeing_question( $normalized )
			: false;
	}

	private static function is_thanks_only( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_thanks_only( $normalized )
			: false;
	}

	private static function is_goodbye_only( $normalized ) {
		return class_exists( 'YoRo_AI_Conversation_Predicate_Classifier' )
			? YoRo_AI_Conversation_Predicate_Classifier::is_goodbye_only( $normalized )
			: false;
	}

	private static function get_default_conversational_suggestions() {
		return class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::get_default_conversational_suggestions()
			: [];
	}

	private static function build_out_of_scope_general_suggestions() {
		return class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::build_out_of_scope_general_suggestions()
			: [];
	}

	private static function build_assistant_message_response( $command, $normalized, $parser_rule, $summary, $followup = '', $suggestions = [], $extra_result = [] ) {
		return class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::build_assistant_message_response( $command, $normalized, $parser_rule, $summary, $followup, $suggestions, $extra_result )
			: null;
	}

	private static function clarification_response( $command, $normalized, $message, $clarify_reason = 'details' ) {
		return [
			'success'            => false,
			'matched'            => false,
			'stage'              => 'clarify',
			'message'            => $message,
			'options'            => class_exists( 'YoRo_AI_Conversation_Response_Helper' )
				? YoRo_AI_Conversation_Response_Helper::get_clarification_options( $normalized )
				: [],
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'clarify_reason'     => sanitize_key( $clarify_reason ),
		];
	}
}
