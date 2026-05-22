<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Pre_Router_Order_Workflow_Guard {

	public static function build_explicit_refund_response( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if ( ! class_exists( 'YoRo_Order_Helper' ) ) {
			return null;
		}

		$order_ids = YoRo_Order_Helper::extract_order_ids_from_text( $command );

		if ( empty( $order_ids ) ) {
			return null;
		}

		$text_for_match = strtolower( trim( $command . ' ' . $normalized ) );

		if ( ! preg_match( '/\b(?:refund|refundable)\b/i', $text_for_match ) && ! preg_match( '/\b(?:hoàn tiền|hoan tien)\b/u', $text_for_match ) ) {
			return null;
		}

		if ( count( $order_ids ) > 1 && self::looks_like_direct_multi_order_refund_write( $text_for_match ) ) {
			$compact_shape = self::compact_command_shape( $command, $client_context );
			$parsed = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'orders.update_status',
				'intent_family'      => 'update_order_status',
				'params'             => [
					'order_ids' => array_values( array_map( 'absint', $order_ids ) ),
					'status'    => 'refunded',
					'note'      => __( 'Order status updated to refunded by YoRo AI.', 'yoro-ai-core' ),
				],
				'parser_rule'        => 'guard.orders.update_status.explicit_refund_orders',
				'parser_source'      => 'guard',
				'confidence'         => 0.99,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];

			if ( ! empty( $compact_shape ) ) {
				$parsed['command_shape'] = $compact_shape;
			}

			return self::normalize_resolve_validate_result( $parsed, $command, $normalized, $client_context, $callbacks, true );
		}

		$order_id          = absint( reset( $order_ids ) );
		$delivery_mode     = self::resolve_explicit_refund_delivery_mode( $text_for_match );
		$requires_workflow = self::requires_explicit_refund_workflow( $text_for_match );
		$is_preview_only   = 'preview' === $delivery_mode && ! $requires_workflow;

		if ( $is_preview_only ) {
			$compact_shape = self::compact_command_shape( $command, $client_context );
			$parsed = [
				'success'            => true,
				'matched'            => true,
				'action'             => 'orders.refund_preview',
				'intent_family'      => 'refund_review',
				'params'             => [
					'order_id'  => $order_id,
					'order_ids' => [ $order_id ],
				],
				'parser_rule'        => 'guard.orders.refund_preview.explicit_order',
				'parser_source'      => 'guard',
				'confidence'         => 0.99,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];

			if ( ! empty( $compact_shape ) ) {
				$parsed['command_shape'] = $compact_shape;
			}

			$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );

			if (
				class_exists( 'YoRo_Entity_Resolver' ) &&
				class_exists( 'YoRo_Intent_Router' ) &&
				class_exists( 'YoRo_Entity_Validator' )
			) {
				$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
				$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );
				$resolved_result = self::hydrate_result_intent_family_from_action( $resolved_result, $callbacks );

				return YoRo_Entity_Validator::validate( $resolved_result );
			}

			return $normalized_result;
		}

		if ( ! class_exists( 'YoRo_Workflow_Templates' ) ) {
			return null;
		}

		$workflow_result = YoRo_Workflow_Templates::build_parsed(
			'refund_review',
			[
				'order_id'         => $order_id,
				'delivery_mode'    => $delivery_mode,
				'instruction'      => self::extract_explicit_refund_followup_instruction( $command, $normalized, $delivery_mode ),
				'original_command' => $command,
			],
			$command
		);

		if ( is_array( $workflow_result ) ) {
			$workflow_result['parser_source'] = 'guard';
			$workflow_result['parser_rule']   = 'guard.workflow.refund_review.explicit_order';
			$workflow_result['confidence']    = 0.99;
			$workflow_result['intent_family'] = 'refund_review';
			$workflow_result                  = self::with_command_shape( $workflow_result, $command, $client_context );
		}

		return $workflow_result;
	}

	public static function build_explicit_verification_response( $command, $normalized, $client_context = [] ) {
		if ( ! class_exists( 'YoRo_Order_Helper' ) || ! class_exists( 'YoRo_Workflow_Templates' ) ) {
			return null;
		}

		$order_ids = YoRo_Order_Helper::extract_order_ids_from_text( $command );

		if ( empty( $order_ids ) ) {
			return null;
		}

		$text_for_match = strtolower( trim( $command . ' ' . $normalized ) );

		if ( ! preg_match( '/\b(?:verify|verification|confirm)\b/i', $text_for_match ) && ! preg_match( '/\b(?:xác minh|xac minh|xác nhận|xac nhan)\b/u', $text_for_match ) ) {
			return null;
		}

		if (
			! preg_match( '/\b(?:address|identity|details|shipping|ship|fulfillment)\b/i', $text_for_match ) &&
			! preg_match( '/\b(?:địa chỉ|dia chi|giao hàng|giao hang)\b/u', $text_for_match )
		) {
			return null;
		}

		$order_id = absint( reset( $order_ids ) );
		$workflow_result = YoRo_Workflow_Templates::build_parsed(
			'verify_customer_before_fulfillment',
			[
				'order_id'            => $order_id,
				'email_delivery_mode' => self::resolve_explicit_email_delivery_mode( $text_for_match ),
				'original_command'    => $command,
			],
			$command
		);

		if ( is_array( $workflow_result ) ) {
			$workflow_result['parser_source'] = 'guard';
			$workflow_result['parser_rule']   = 'guard.workflow.verify_customer_before_fulfillment.explicit_order';
			$workflow_result['confidence']    = 0.99;
			$workflow_result                  = self::with_command_shape( $workflow_result, $command, $client_context );
		}

		return $workflow_result;
	}

	public static function resolve_explicit_refund_delivery_mode( $text ) {
		$text = is_string( $text ) ? strtolower( trim( $text ) ) : '';

		if ( '' === $text ) {
			return 'preview';
		}

		if ( preg_match( '/\b(?:save(?:\s+it)?(?:\s+as)?\s+draft|save\s+as\s+draft|draft)\b/i', $text ) ) {
			return 'save_draft';
		}

		if ( preg_match( '/\b(?:send|email customer|notify customer)\b/i', $text ) || preg_match( '/\b(?:gửi|gui)\b/u', $text ) ) {
			return 'send';
		}

		return 'preview';
	}

	private static function requires_explicit_refund_workflow( $text ) {
		$text = is_string( $text ) ? strtolower( trim( $text ) ) : '';

		if ( '' === $text ) {
			return false;
		}

		if ( preg_match( '/\brefund\s+workflow\b/i', $text ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:follow-up|follow up)\b/i', $text ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:email|draft|save(?:\s+it)?(?:\s+as)?\s+draft|save\s+as\s+draft|send|notify customer|email customer)\b/i', $text ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:gửi|gui|nháp|nhap)\b/u', $text ) ) {
			return true;
		}

		return false;
	}

	private static function looks_like_direct_multi_order_refund_write( $text ) {
		$text = is_string( $text ) ? strtolower( trim( $text ) ) : '';

		if ( '' === $text ) {
			return false;
		}

		if ( ! preg_match( '/^\s*(?:refund|refunded|hoàn tiền|hoan tien)\b/u', $text ) ) {
			return false;
		}

		return ! preg_match( '/\b(?:preview|review|check|inspect|look at|open|prepare|workflow|follow-up|follow up|email|draft|send|notify customer|xem trước|xem truoc)\b/u', $text );
	}

	private static function extract_explicit_refund_followup_instruction( $command, $normalized, $delivery_mode ) {
		$command    = is_string( $command ) ? trim( $command ) : '';
		$normalized = is_string( $normalized ) ? trim( $normalized ) : '';

		if ( '' === $command && '' === $normalized ) {
			return '';
		}

		$has_email_authoring_cue = preg_match( '/\b(?:write|draft|compose|create)\b/i', $command )
			&& preg_match( '/\b(?:email|reply|message)\b/i', $command );

		if ( ! $has_email_authoring_cue && ! in_array( $delivery_mode, [ 'save_draft', 'send' ], true ) ) {
			return '';
		}

		if (
			preg_match(
				'/\b(?:email|reply|message)\b\s+(?:about|for|to)?\s*(.+?)(?:\s+and\s+(?:save|store|keep|send).*)?$/i',
				$command,
				$matches
			)
		) {
			$instruction = sanitize_textarea_field( trim( (string) $matches[1] ) );

			if (
				'' !== $instruction &&
				! preg_match( '/^(?:it|this|that|them|the customer|for\s+it|about\s+it|as\s+draft|draft|send\s+it|save\s+it)$/i', $instruction )
			) {
				return $instruction;
			}
		}

		return 'Explain the refund review outcome and next steps to the customer. Keep it clear and concise.';
	}

	private static function resolve_explicit_email_delivery_mode( $text ) {
		return self::resolve_explicit_refund_delivery_mode( $text );
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

	private static function with_command_shape( $result, $command, $client_context = [] ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$compact_shape = self::compact_command_shape( $command, $client_context );

		if ( empty( $compact_shape ) ) {
			return $result;
		}

		$result['command_shape'] = $compact_shape;

		if ( isset( $result['parsed'] ) && is_array( $result['parsed'] ) ) {
			$result['parsed']['command_shape'] = $compact_shape;
		}

		return $result;
	}

	private static function compact_command_shape( $command, $client_context = [] ) {
		if ( ! class_exists( 'YoRo_Command_Shape_Extractor' ) ) {
			return [];
		}

		$client_context = is_array( $client_context ) ? $client_context : [];
		$shape = isset( $client_context['_command_shape'] ) && is_array( $client_context['_command_shape'] )
			? $client_context['_command_shape']
			: YoRo_Command_Shape_Extractor::extract( $command, $client_context );

		return YoRo_Command_Shape_Extractor::compact_for_prompt( $shape );
	}

	private static function normalize_resolve_validate_result( $parsed, $command, $normalized, $client_context = [], $callbacks = [], $hydrate_intent_family = false ) {
		$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );

		if (
			class_exists( 'YoRo_Entity_Resolver' ) &&
			class_exists( 'YoRo_Intent_Router' ) &&
			class_exists( 'YoRo_Entity_Validator' )
		) {
			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			if ( $hydrate_intent_family ) {
				$resolved_result = self::hydrate_result_intent_family_from_action( $resolved_result, $callbacks );
			}

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		return $normalized_result;
	}

	private static function hydrate_result_intent_family_from_action( $result, $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		if ( ! empty( $callbacks['hydrate_result_intent_family_from_action'] ) && is_callable( $callbacks['hydrate_result_intent_family_from_action'] ) ) {
			$hydrated = call_user_func( $callbacks['hydrate_result_intent_family_from_action'], $result );

			return is_array( $hydrated ) ? $hydrated : $result;
		}

		return $result;
	}
}
