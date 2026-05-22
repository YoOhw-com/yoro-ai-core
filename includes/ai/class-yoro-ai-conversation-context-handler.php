<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Conversation_Context_Handler {

	public static function looks_like_current_store_entity_question( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/^(?:who is|who\'s|tell me about|what do you know about|describe|show me)\s+(?:this|the)\s+(?:customer|buyer|order)\b/i',
			$normalized
		);
	}

	public static function build_current_store_entity_response( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$order_id = self::resolve_assistant_context_order_id( $client_context );

		if ( $order_id ) {
			$result = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'orders.get_details',
				'params'             => [
					'order_id' => $order_id,
				],
				'parser_rule'        => 'rule.context.current_store_entity',
				'parser_source'      => 'rule',
				'confidence'         => 0.95,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];

			$normalized_result = self::normalize_parsed_result(
				$result,
				$command,
				$normalized,
				$client_context,
				$callbacks
			);

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

		return self::clarification_response(
			$command,
			$normalized,
			__( 'I can look that up, but I need an order or customer reference first. Which order did you mean?', 'yoro-ai-core' ),
			'details'
		);
	}

	public static function is_developer_handoff_confirmation( $normalized ) {
		$normalized   = trim( (string) $normalized );
		$last_result  = self::get_last_assistant_message_result();
		$topic        = sanitize_key( $last_result['conversation_topic'] ?? '' );
		$has_context  = ! empty( $last_result['handoff_request_text'] );
		$offered      = ! empty( $last_result['developer_handoff_offer'] );
		$valid_topic  = in_array( $topic, [ 'unsupported_request', 'developer_handoff' ], true );

		if ( ! $has_context || ( ! $offered && ! $valid_topic ) ) {
			return false;
		}

		return self::looks_like_developer_handoff_phrase( $normalized );
	}

	public static function looks_like_developer_handoff_phrase( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );
		$normalized = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $normalized );
		$normalized = preg_replace( '/\s+/', ' ', $normalized );
		$normalized = trim( (string) $normalized );

		if ( in_array( $normalized, [ 'yes', 'yes please', 'sure', 'sure please', 'please do', 'do it' ], true ) ) {
			return true;
		}

		if (
			false !== strpos( $normalized, 'developers' ) &&
			preg_match( '/\b(send|pass|share)\b/i', $normalized )
		) {
			return true;
		}

		return false;
	}

	public static function build_developer_handoff_response( $command, $normalized, $callbacks = [] ) {
		$last_result  = self::get_last_assistant_message_result();
		$request_text = sanitize_text_field( $last_result['handoff_request_text'] ?? '' );

		if ( '' === $request_text ) {
			$request_text = sanitize_text_field( $last_result['summary'] ?? __( 'Unsupported request', 'yoro-ai-core' ) );
		}

		if ( '' === $request_text ) {
			return self::build_assistant_message_response(
				$command,
				$normalized,
				'rule.chat.unsupported_handoff_missing_context',
				__( 'I can save that for the developers, but I need the original request first.', 'yoro-ai-core' ),
				__( 'Please repeat the request you want me to pass along, and I will capture it from there.', 'yoro-ai-core' ),
				[],
				[
					'conversation_topic' => 'developer_handoff',
					'result_type'        => 'assistant_message',
				]
			);
		}

		$original_command = '';
		$context_order_id = 0;

		if ( function_exists( 'get_current_user_id' ) && class_exists( 'YoRo_Conversation_Memory' ) ) {
			$user_id = get_current_user_id();

			if ( $user_id ) {
				if ( method_exists( 'YoRo_Conversation_Memory', 'get_execution_context' ) ) {
					$execution_context = YoRo_Conversation_Memory::get_execution_context( $user_id );
					$original_command  = sanitize_textarea_field( $execution_context['last_command'] ?? '' );
				}

				if ( method_exists( 'YoRo_Conversation_Memory', 'get_current_order_id' ) ) {
					$context_order_id = absint( YoRo_Conversation_Memory::get_current_order_id( $user_id ) );
				}
			}
		}

		$result = [
			'success'            => true,
			'matched'            => true,
			'action'             => 'assistant.submit_developer_request',
			'params'             => [
				'request_text'              => $request_text,
				'original_command'          => $original_command,
				'unsupported_capability'    => sanitize_text_field( $last_result['handoff_request_text'] ?? '' ),
				'conversation_family'       => sanitize_key( $last_result['conversation_family'] ?? '' ),
				'closest_supported_surface' => sanitize_key( $last_result['closest_supported_surface'] ?? '' ),
				'context_order_id'          => $context_order_id,
			],
			'parser_rule'        => 'rule.chat.unsupported_handoff_confirm',
			'parser_source'      => 'rule',
			'confidence'         => 0.99,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];

		return self::normalize_parsed_result(
			$result,
			$command,
			$normalized,
			[],
			$callbacks
		);
	}

	private static function resolve_assistant_context_order_id( $client_context = [] ) {
		if ( ! empty( $client_context['resolved_order_ids'] ) && is_array( $client_context['resolved_order_ids'] ) ) {
			$order_id = absint( reset( $client_context['resolved_order_ids'] ) );

			if ( $order_id ) {
				return $order_id;
			}
		}

		if ( ! empty( $client_context['order_ids'] ) && is_array( $client_context['order_ids'] ) ) {
			$order_id = absint( reset( $client_context['order_ids'] ) );

			if ( $order_id ) {
				return $order_id;
			}
		}

		if ( ! empty( $client_context['order_id'] ) ) {
			$order_id = absint( $client_context['order_id'] );

			if ( $order_id ) {
				return $order_id;
			}
		}

		if ( class_exists( 'YoRo_Context_Provider' ) && method_exists( 'YoRo_Context_Provider', 'get_current_order_id' ) ) {
			$order_id = absint( YoRo_Context_Provider::get_current_order_id() );

			if ( $order_id ) {
				return $order_id;
			}
		}

		return 0;
	}

	private static function get_last_assistant_message_result() {
		$runtime_context = [];

		if ( function_exists( 'get_current_user_id' ) ) {
			$user_id = get_current_user_id();

			if ( $user_id && class_exists( 'YoRo_Conversation_Memory' ) && method_exists( 'YoRo_Conversation_Memory', 'get_runtime_context' ) ) {
				$runtime_context = YoRo_Conversation_Memory::get_runtime_context( $user_id );
			}
		}

		return isset( $runtime_context['last_result'] ) && is_array( $runtime_context['last_result'] )
			? $runtime_context['last_result']
			: [];
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
