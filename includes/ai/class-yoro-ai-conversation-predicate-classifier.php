<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Conversation_Predicate_Classifier {

	public static function looks_like_general_out_of_scope_family( $normalized ) {
		return (bool) preg_match(
			'/\b(?:news|headline|weather|temperature|forecast|sports|match|score|football|soccer|basketball|baseball|tennis|cricket|politics|election|president|prime minister|stock market|share price|bitcoin|crypto|movie|music|recipe|translate|translation|history|science|calendar|reminder|alarm|traffic)\b/i',
			(string) $normalized
		);
	}

	public static function looks_like_general_background_request( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( self::contains_store_scope_markers( $normalized ) ) {
			return false;
		}

		return (bool) preg_match(
			'/^(?:tell me about|who is|what do you know about|give me information about|describe)\b/i',
			$normalized
		);
	}

	public static function looks_like_general_non_store_statement( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( self::contains_store_scope_markers( $normalized ) ) {
			return false;
		}

		return (bool) preg_match(
			'/^(?:[a-z0-9\'" -]{2,})\s+(?:is|are|was|were|seems|looks|sounds)\b/i',
			$normalized
		);
	}

	public static function contains_store_scope_markers( $normalized ) {
		return (bool) preg_match(
			'/\b(?:order|orders|customer|customers|product|products|queue|briefing|store|business|performance|revenue|sales|inventory|fulfillment|shipping|shipment|tracking|note|status|email|draft|refund|backorder|stock|sku)\b/i',
			(string) $normalized
		);
	}

	public static function is_greeting_only( $normalized ) {
		$normalized = trim( (string) $normalized );
		$normalized = preg_replace( '/[!,?.]+/', ' ', $normalized );
		$normalized = preg_replace( '/\s+/', ' ', $normalized );

		return (bool) preg_match(
			'/^(?:hi|hello|hey|yo|hiya|good morning|good afternoon|good evening|morning|afternoon|evening)(?:\s+(?:there|yoro|team|friend|folks))?\s*$/i',
			$normalized
		);
	}

	public static function is_identity_question( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/^(?:who are you|what are you|who is this|what is this|who is yoro|what is yoro)(?:\s+(?:exactly))?\??$/i',
			$normalized
		);
	}

	public static function is_wellbeing_question( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/^(?:how are you|how are you doing|how are things|how are you today|hows it going|how is it going)(?:\s+(?:yoro|today|there))?\??$/i',
			$normalized
		);
	}

	public static function is_thanks_only( $normalized ) {
		$normalized = trim( (string) $normalized );
		$normalized = preg_replace( '/[!,?.]+/', '', $normalized );

		return (bool) preg_match(
			'/^(?:thanks|thank you|thanks yoro|thank you yoro|great thanks|awesome thanks|many thanks|thanks a lot)(?:\s+(?:yoro))?$/i',
			$normalized
		);
	}

	public static function is_goodbye_only( $normalized ) {
		$normalized = trim( (string) $normalized );
		$normalized = preg_replace( '/[!,?.]+/', '', $normalized );

		return (bool) preg_match(
			'/^(?:bye|goodbye|see you|see you later|talk later|catch you later|bye yoro|goodbye yoro|ok bye|thanks bye)(?:\s+(?:yoro))?$/i',
			$normalized
		);
	}

	public static function is_ambiguous_action_reference( $normalized ) {
		$normalized = trim( (string) $normalized );

		return (bool) preg_match(
			'/^(?:fix|handle|do|process|check|review|resolve|update|change|manage|take care of|help with)\s+(?:this|that|it|this one|that one)(?:\s+please)?[!.?]*$/i',
			$normalized
		);
	}

	public static function has_ai_reference_resolution_context( $client_context = [] ) {
		if ( ! is_array( $client_context ) ) {
			return false;
		}

		if ( ! empty( $client_context['current_workflow'] ) && is_array( $client_context['current_workflow'] ) ) {
			return true;
		}

		if ( ! empty( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] ) ) {
			return true;
		}

		if ( ! empty( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] ) ) {
			return true;
		}

		$followup_keys = [
			'last_business_followup',
			'last_attention_followup',
			'last_service_followup',
			'last_fulfillment_followup',
			'last_inventory_followup',
			'last_briefing',
		];

		foreach ( $followup_keys as $state_key ) {
			if ( empty( $client_context[ $state_key ] ) || ! is_array( $client_context[ $state_key ] ) ) {
				continue;
			}

			$state = $client_context[ $state_key ];

			if ( ! empty( $state['recommended_sequence'] ) && is_array( $state['recommended_sequence'] ) ) {
				return true;
			}

			if ( ! empty( $state['queues'] ) && is_array( $state['queues'] ) ) {
				return true;
			}
		}

		return false;
	}
}
