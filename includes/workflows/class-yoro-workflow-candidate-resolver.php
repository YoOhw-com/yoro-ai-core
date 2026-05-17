<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Workflow_Candidate_Resolver {

	public static function resolve( $command, $normalized, $client_context = [], $hint = [] ) {
		if ( ! class_exists( 'YoRo_Workflow_Templates' ) ) {
			return null;
		}

		$command        = is_string( $command ) ? trim( $command ) : '';
		$normalized     = strtolower( trim( (string) $normalized ) );
		$client_context = is_array( $client_context ) ? $client_context : [];
		$hint           = is_array( $hint ) ? $hint : [];
		$template_id    = self::detect_template_id( $command, $normalized, $client_context, $hint );

		if ( '' === $template_id ) {
			return null;
		}

		$params = self::build_template_params(
			$template_id,
			$command,
			$normalized,
			$client_context,
			$hint
		);

		$parsed = YoRo_Workflow_Templates::build_parsed(
			$template_id,
			$params,
			$command
		);

		if ( ! is_array( $parsed ) ) {
			return $parsed;
		}

		$trigger_action = sanitize_text_field( $hint['action'] ?? '' );
		$trigger_source = sanitize_key( $hint['parser_source'] ?? '' );

		$parsed['workflow_promotion_source'] = '' !== $trigger_source ? $trigger_source : 'rule';
		$parsed['workflow_promotion_reason'] = 'post_router_workflow_candidate';

		if ( '' !== $trigger_action ) {
			$parsed['workflow_promotion_trigger_action'] = $trigger_action;
		}

		if ( ! empty( $parsed['workflow'] ) && is_array( $parsed['workflow'] ) ) {
			$parsed['workflow']['promotion'] = [
				'source'         => $parsed['workflow_promotion_source'],
				'reason'         => $parsed['workflow_promotion_reason'],
				'trigger_action' => $trigger_action,
			];
		}

		return $parsed;
	}

	private static function detect_template_id( $command, $normalized, $client_context, $hint ) {
		$action = sanitize_text_field( $hint['action'] ?? '' );

		if ( self::looks_like_review_suspicious_order_request( $normalized, $action ) ) {
			return 'review_suspicious_order';
		}

		if ( self::looks_like_verify_customer_before_fulfillment_request( $normalized, $action ) ) {
			return 'verify_customer_before_fulfillment';
		}

		if ( self::looks_like_refund_review_request( $normalized, $action ) ) {
			return 'refund_review';
		}

		if ( self::looks_like_email_authoring_request( $normalized, $client_context, $command, $hint ) ) {
			$delivery_mode = self::resolve_email_delivery_mode( $normalized, $hint );

			if ( 'save_draft' === $delivery_mode ) {
				return 'write_and_save_email_draft_for_order';
			}

			if ( 'send' === $delivery_mode ) {
				return 'write_and_send_email_for_order';
			}

			return 'write_email_for_order';
		}

		return '';
	}

	private static function build_template_params( $template_id, $command, $normalized, $client_context, $hint ) {
		$params    = isset( $hint['params'] ) && is_array( $hint['params'] ) ? $hint['params'] : [];
		$order_id  = self::resolve_order_id( $command, $client_context, $params );
		$template_params = [];

		if ( $order_id > 0 ) {
			$template_params['order_id'] = $order_id;
		}

		if ( in_array( $template_id, [ 'write_email_for_order', 'write_and_save_email_draft_for_order', 'write_and_send_email_for_order' ], true ) ) {
			$instruction = self::extract_email_instruction( $command, $normalized, $params );
			$delivery_mode = self::resolve_email_delivery_mode( $normalized, $hint );

			if ( '' !== $instruction ) {
				$template_params['instruction'] = $instruction;
			}

			$template_params['delivery_mode'] = $delivery_mode;
		}

		if ( 'verify_customer_before_fulfillment' === $template_id ) {
			$template_params['email_delivery_mode'] = self::resolve_email_delivery_mode( $normalized, $hint );
		}

		if ( 'refund_review' === $template_id ) {
			$delivery_mode = self::resolve_refund_delivery_mode( $normalized, $hint );
			$template_params['delivery_mode'] = $delivery_mode;
			$instruction = self::extract_refund_followup_instruction( $command, $normalized, $params );

			if ( '' !== $instruction ) {
				$template_params['instruction'] = $instruction;
			} elseif ( 'preview' !== $delivery_mode ) {
					$instruction = 'Explain the refund review outcome and next steps to the customer. Keep it clear and concise.';
				$template_params['instruction'] = $instruction;
			}
		}

		if ( in_array( $template_id, [ 'hold_and_add_note', 'update_status_with_note' ], true ) ) {
			$note = self::extract_note( $command, $params );

			if ( '' !== $note ) {
				$template_params['note'] = $note;
			}
		}

		if ( 'update_status_with_note' === $template_id ) {
			$status = self::extract_status( $command, $params );

			if ( '' !== $status ) {
				$template_params['status'] = $status;
			}
		}

		return $template_params;
	}

	private static function resolve_order_id( $command, $client_context, $params ) {
		$order_ids = class_exists( 'YoRo_Order_Helper' )
			? YoRo_Order_Helper::extract_order_ids_from_text( $command )
			: [];

		if ( ! empty( $order_ids[0] ) ) {
			return absint( $order_ids[0] );
		}

		if ( ! empty( $params['order_id'] ) ) {
			return absint( $params['order_id'] );
		}

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) && ! empty( $params['order_ids'][0] ) ) {
			return absint( $params['order_ids'][0] );
		}

		if ( ! empty( $client_context['current_order_id'] ) ) {
			return absint( $client_context['current_order_id'] );
		}

		if ( ! empty( $client_context['last_referenced_order_id'] ) ) {
			return absint( $client_context['last_referenced_order_id'] );
		}

		return 0;
	}

	private static function looks_like_refund_review_request( $normalized, $action ) {
		if ( 'refund workflow' === $normalized ) {
			return true;
		}

		if ( 'orders.refund_preview' !== $action ) {
			return false;
		}

		if ( ! preg_match( '/\b(?:refund|refundable)\b/i', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:preview|xem trước|xem truoc)\b/u', $normalized ) ) {
			return false;
		}

		return self::contains_refund_workflow_cue( $normalized );
	}

	private static function contains_refund_workflow_cue( $normalized ) {
		if ( ! is_string( $normalized ) || '' === trim( $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\brefund\s+workflow\b/i', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:follow-up|follow up)\b/i', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:email|draft|save(?:\s+it)?(?:\s+as)?\s+draft|save\s+as\s+draft|send|notify customer|email customer)\b/i', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:gửi|gui|nháp|nhap)\b/u', $normalized ) ) {
			return true;
		}

		return false;
	}

	private static function looks_like_review_suspicious_order_request( $normalized, $action ) {
		if ( in_array( $normalized, [ 'review suspicious order', 'inspect suspicious order', 'investigate suspicious order' ], true ) ) {
			return true;
		}

		if (
			preg_match( '/\b(?:review|check|inspect|investigate|look at)\b/i', $normalized ) &&
			preg_match( '/\b(?:suspicious|risky|fraud)\b/i', $normalized ) &&
			preg_match( '/\border\b/i', $normalized )
		) {
			return true;
		}

		if ( 'orders.find_by_identity' === $action ) {
			return preg_match( '/\b(?:suspicious|risky|fraud|identity)\b/i', $normalized )
				&& preg_match( '/\b(?:review|check|inspect|investigate|look at)\b/i', $normalized );
		}

		return false;
	}

	private static function looks_like_verify_customer_before_fulfillment_request( $normalized, $action ) {
		if ( in_array( $normalized, [ 'verify customer before fulfillment', 'customer verification workflow', 'verify this customer' ], true ) ) {
			return true;
		}

		if ( in_array( $action, [ 'orders.hold', 'orders.compose_customer_email' ], true ) ) {
			return preg_match( '/\b(?:verify|verification|confirm)\b/i', $normalized )
				&& (
					preg_match( '/\b(?:fulfillment|ship|shipping)\b/i', $normalized ) ||
					preg_match( '/\b(?:customer|address|identity|details)\b/i', $normalized )
				);
		}

		return false;
	}

	private static function looks_like_write_email_for_order_request( $normalized, $action, $client_context, $command, $hint ) {
		if ( ! self::looks_like_email_authoring_request( $normalized, $client_context, $command, $hint ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:send|save|delete|template)\b/i', $normalized ) ) {
			return false;
		}

		return in_array(
			$action,
			[
				'orders.compose_customer_email',
				'workflow.write_email_for_order',
				'workflow.write_and_save_email_draft_for_order',
				'workflow.write_and_send_email_for_order',
			],
			true
		);
	}

	private static function resolve_email_delivery_mode( $normalized, $hint ) {
		$action = sanitize_text_field( $hint['action'] ?? '' );

		if ( 'workflow.write_and_save_email_draft_for_order' === $action ) {
			return 'save_draft';
		}

		if ( 'workflow.write_and_send_email_for_order' === $action ) {
			return 'send';
		}

		if ( preg_match( '/\b(?:save|store|keep)\b/i', $normalized ) && preg_match( '/\bdraft\b/i', $normalized ) ) {
			return 'save_draft';
		}

		if ( preg_match( '/\bsend\b/i', $normalized ) ) {
			return 'send';
		}

		return 'preview';
	}

	private static function resolve_refund_delivery_mode( $normalized, $hint ) {
		$mentions_email = preg_match( '/\b(?:email|reply|message)\b/i', $normalized );

		if ( ! $mentions_email ) {
			return 'preview';
		}

		return self::resolve_email_delivery_mode( $normalized, $hint );
	}

	private static function looks_like_email_authoring_request( $normalized, $client_context, $command, $hint ) {
		$has_order_reference = self::resolve_order_id(
			$command,
			$client_context,
			isset( $hint['params'] ) && is_array( $hint['params'] ) ? $hint['params'] : []
		) > 0;

		if ( ! $has_order_reference ) {
			return false;
		}

		return preg_match( '/\b(?:write|draft|compose|prepare|create)\b/i', $normalized )
			&& preg_match( '/\b(?:email|reply|message)\b/i', $normalized );
	}

	private static function extract_note( $command, $params ) {
		if ( preg_match( '/(?:add\s+note|with\s+note|note)\s*:?\s*(.+)$/i', (string) $command, $matches ) ) {
			return sanitize_textarea_field( trim( $matches[1] ) );
		}

		if ( ! empty( $params['note'] ) ) {
			return sanitize_textarea_field( $params['note'] );
		}

		return '';
	}

	private static function extract_status( $command, $params ) {
		if ( ! empty( $params['status'] ) ) {
			return sanitize_key( $params['status'] );
		}

		if ( class_exists( 'YoRo_Order_Helper' ) ) {
			$status = YoRo_Order_Helper::detect_status_from_text( strtolower( (string) $command ) );

			if ( empty( $status ) ) {
				$status = YoRo_Order_Helper::extract_status_phrase_from_command( (string) $command );
			}

			if ( ! empty( $status ) ) {
				return sanitize_key( YoRo_Order_Helper::normalize_status_slug( $status ) );
			}
		}

		return '';
	}

	private static function extract_email_instruction( $command, $normalized, $params ) {
		if ( ! empty( $params['instruction'] ) ) {
			$hint_instruction = sanitize_textarea_field( $params['instruction'] );

			if (
				'' !== $hint_instruction &&
				strtolower( $hint_instruction ) !== strtolower( trim( (string) $command ) ) &&
				strtolower( $hint_instruction ) !== strtolower( trim( (string) $normalized ) )
			) {
				return $hint_instruction;
			}
		}

		$instruction = preg_replace(
			'/\bfor\s+order\s+#?\d+\b/i',
			'',
			(string) $command
		);

		$instruction = preg_replace(
			'/\border\s+#?\d+\b/i',
			'',
			(string) $instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:write|draft|compose|prepare|create)\b\s*(?:a|an)?\s*/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:me|us)\s+(?:an?\s+)?(?:customer\s+)?(?:email|reply|message)\b[:\s-]*/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/\b(?:for\s+)?(?:this|that|the|current)?\s*order\b/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:customer\s+)?(?:email|reply|message)\b[:\s-]*/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/\b(?:customer\s+)?(?:email|reply|message)\s+for\b/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/\b(?:customer\s+)?(?:email|reply|message)\b(?:\s+for)?\s*$/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*for\b[:\s-]*/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/\s+(?:and\s+)?(?:save(?:\s+it)?(?:\s+as)?\s+draft|save\s+as\s+draft|send(?:\s+it)?|send\s+to\s+customer)\s*$/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/\b(?:customer\s+)?email\b\s*$/i',
			'',
			$instruction
		);

		$instruction = preg_replace(
			'/^\s*(?:a|an)\s+/i',
			'',
			$instruction
		);

		$instruction = trim( wp_strip_all_tags( $instruction ) );
		$instruction = trim( preg_replace( '/^[\s:,-]+|[\s:,-]+$/', '', $instruction ) );

		if ( '' === $instruction || strtolower( $instruction ) === strtolower( $normalized ) ) {
			return '';
		}

		return sanitize_textarea_field( $instruction );
	}

	private static function extract_refund_followup_instruction( $command, $normalized, $params ) {
		if ( ! empty( $params['instruction'] ) ) {
			$hint_instruction = sanitize_textarea_field( $params['instruction'] );

			if (
				'' !== $hint_instruction &&
				strtolower( $hint_instruction ) !== strtolower( trim( (string) $command ) ) &&
				strtolower( $hint_instruction ) !== strtolower( trim( (string) $normalized ) )
			) {
				return $hint_instruction;
			}
		}

		if ( preg_match( '/\b(?:email|reply|message)\b\s+(?:about|for|to)?\s*(.+?)(?:\s+and\s+(?:save|store|keep|send).*)?$/i', (string) $command, $matches ) ) {
			$instruction = sanitize_textarea_field( trim( $matches[1] ) );

			if ( '' !== $instruction ) {
				return $instruction;
			}
		}

		return '';
	}
}
