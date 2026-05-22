<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Intent_Extractor {

	public static function extract( $command, $normalized, $client_context = [], $hint = [] ) {
		$command        = is_string( $command ) ? trim( $command ) : '';
		$normalized     = is_string( $normalized ) ? strtolower( trim( $normalized ) ) : '';
		$client_context = is_array( $client_context ) ? $client_context : [];
		$hint           = is_array( $hint ) ? $hint : [];
		$params         = isset( $hint['params'] ) && is_array( $hint['params'] ) ? $hint['params'] : [];

		if ( '' === $command || '' === $normalized ) {
			return [
				'matched'             => false,
				'should_compose'      => false,
				'requested_actions'   => [],
				'unavailable_requests'=> [],
				'order_id'            => 0,
			];
		}

		$order_id       = self::resolve_order_id( $command, $client_context, $params );
		$has_connectors = self::has_multi_step_connectors( $normalized );
		$requested      = [];
		$unavailable    = [];
		$consumed_note  = false;
		$missing        = [];

		$status_position = self::find_status_change_position( $command );
		$status          = self::extract_status( $command, $params );
		$note            = self::extract_note( $command, $params );
		$note_position   = self::find_note_position( $command );
		$note_requested  = $note_position >= 0;

		if ( '' !== $status && '' !== $note && $status_position >= 0 ) {
			$requested[] = [
				'kind'     => 'status_with_note',
				'action'   => 'orders.bulk_update_status_with_note',
				'label'    => __( 'Update order status with note', 'yoro-ai-core' ),
				'position' => $status_position,
				'params'   => [
					'status' => $status,
					'note'   => $note,
				],
			];
			$consumed_note = true;
		} elseif ( '' !== $status && $status_position >= 0 ) {
			$requested[] = [
				'kind'     => 'update_status',
				'action'   => 'orders.update_status',
				'label'    => __( 'Update order status', 'yoro-ai-core' ),
				'position' => $status_position,
				'params'   => [
					'status' => $status,
				],
			];
		}

		$hold_position = self::find_hold_position( $command );
		if ( $hold_position >= 0 && ! self::hold_is_fully_covered_by_status_change( $status, $status_position, $hold_position ) ) {
			$requested[] = [
				'kind'     => 'hold',
				'action'   => 'orders.hold',
				'label'    => __( 'Put order on hold', 'yoro-ai-core' ),
				'position' => $hold_position,
				'params'   => [],
			];
		}

		if ( ! $consumed_note && '' !== $note && $note_position >= 0 ) {
			$requested[] = [
				'kind'     => 'add_note',
				'action'   => 'orders.add_note',
				'label'    => __( 'Add internal note', 'yoro-ai-core' ),
				'position' => $note_position,
				'params'   => [
					'note' => $note,
				],
			];
		} elseif ( $note_requested ) {
			$missing[] = 'note';
		}

		$email_position = self::find_email_position( $command );
		if ( $email_position >= 0 ) {
			$instruction = self::extract_email_instruction( $command, $normalized, $params );
			$delivery    = self::resolve_email_delivery_mode( $normalized, $params );

			$requested[] = [
				'kind'     => 'write_email',
				'action'   => 'orders.compose_customer_email',
				'label'    => __( 'Compose customer email', 'yoro-ai-core' ),
				'position' => $email_position,
				'params'   => [
					'instruction'   => '' !== $instruction ? $instruction : sanitize_textarea_field( $command ),
					'delivery_mode' => $delivery,
				],
			];
		}

		foreach ( self::detect_unavailable_requests( $command, $normalized ) as $item ) {
			$unavailable[] = $item;
		}

		usort(
			$requested,
			static function( $left, $right ) {
				return (int) ( $left['position'] ?? 0 ) <=> (int) ( $right['position'] ?? 0 );
			}
		);

		$requested = self::deduplicate_requested_actions( $requested );
		$supported_count = count( $requested );
		$unsupported_count = count( $unavailable );
		$should_compose = (
			$supported_count >= 3
			|| ( $supported_count >= 2 && $has_connectors && ( $note_requested || '' !== $note || $unsupported_count > 0 ) )
			|| ( $supported_count >= 2 && $unsupported_count > 0 )
			|| ( $supported_count >= 1 && $unsupported_count > 0 && $has_connectors )
		);

		return [
			'matched'              => $supported_count > 0,
			'should_compose'       => $should_compose,
			'requested_actions'    => array_values( $requested ),
			'unavailable_requests' => array_values( $unavailable ),
			'missing_params'       => array_values( array_unique( array_filter( array_map( 'sanitize_key', $missing ) ) ) ),
			'order_id'             => $order_id,
			'has_connectors'       => $has_connectors,
		];
	}

	private static function deduplicate_requested_actions( $requested ) {
		$requested = is_array( $requested ) ? $requested : [];
		$seen      = [];
		$unique    = [];

		foreach ( $requested as $item ) {
			$key = sanitize_key( $item['kind'] ?? '' ) . '|' . sanitize_text_field( $item['action'] ?? '' );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $item;
		}

		return $unique;
	}

	private static function has_multi_step_connectors( $normalized ) {
		return (bool) preg_match( '/(?:,|\band\b|\bthen\b|\band then\b|\balso\b|\bafter that\b|\bafterwards\b)/i', $normalized );
	}

	private static function hold_is_fully_covered_by_status_change( $status, $status_position, $hold_position ) {
		return 'on-hold' === $status && $status_position >= 0 && $hold_position >= 0 && absint( $status_position - $hold_position ) < 24;
	}

	private static function find_hold_position( $command ) {
		return self::find_first_position(
			$command,
			'/\b(?:hold|on[- ]hold|put\b.*?\bon\s+hold|move\b.*?\bto\s+on[- ]hold|set\b.*?\bto\s+on[- ]hold)\b/i'
		);
	}

	private static function find_status_change_position( $command ) {
		return self::find_first_position(
			$command,
			'/\b(?:change|mark|move|set|update)\b.*?\b(?:status|as|to)\b.*?\b(?:pending|processing|completed|on[- ]hold|cancelled|failed|refunded)\b/i'
		);
	}

	private static function find_note_position( $command ) {
		return self::find_first_position(
			$command,
			'/\b(?:add\s+note|leave\s+note|with\s+note|note\s*:)\b/i'
		);
	}

	private static function find_email_position( $command ) {
		return self::find_first_position(
			$command,
			'/\b(?:write|draft|compose|prepare|create|save|send)\b.*?\b(?:customer\s+)?(?:email|reply|message)\b/i'
		);
	}

	private static function find_first_position( $command, $pattern ) {
		$matches = [];

		if ( preg_match( $pattern, (string) $command, $matches, PREG_OFFSET_CAPTURE ) && isset( $matches[0][1] ) ) {
			return (int) $matches[0][1];
		}

		return -1;
	}

	private static function detect_unavailable_requests( $command, $normalized ) {
		$patterns = [
			[
				'pattern' => '/\b(?:shipping\s+label|shipment\s+label|print\s+label|book\s+shipment|create\s+shipment)\b/i',
				'key'     => 'shipping_label',
				'label'   => __( 'Shipping label or shipment booking', 'yoro-ai-core' ),
			],
			[
				'pattern' => '/\b(?:hubspot|slack|google\s+sheets|crm|sync\s+to)\b/i',
				'key'     => 'external_sync',
				'label'   => __( 'External sync or third-party integration', 'yoro-ai-core' ),
			],
			[
				'pattern' => '/\b(?:create|make|open)\b.*?\b(?:new\s+order|draft\s+order|manual\s+order)\b/i',
				'key'     => 'order_authoring',
				'label'   => __( 'Create or author a new order', 'yoro-ai-core' ),
			],
		];

		$unavailable = [];

		foreach ( $patterns as $item ) {
			if ( preg_match( $item['pattern'], $command ) ) {
				$unavailable[] = [
					'key'     => sanitize_key( $item['key'] ),
					'label'   => sanitize_text_field( $item['label'] ),
					'message' => __( 'Not available yet in YoRo.', 'yoro-ai-core' ),
				];
			}
		}

		return $unavailable;
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

	private static function extract_note( $command, $params ) {
		if ( preg_match( '/(?:add\s+note|with\s+note|note)\s*:?\s*(.+)$/i', (string) $command, $matches ) ) {
			$note = trim( (string) $matches[1] );
			$note = preg_replace( '/\s+(?:and|then|also)\s+(?:write|draft|compose|prepare|create|save|send)\b.*$/i', '', $note );
			$note = preg_replace( '/[\s,;:-]+$/', '', (string) $note );
			return sanitize_textarea_field( trim( (string) $note ) );
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

	private static function resolve_email_delivery_mode( $normalized, $params ) {
		if ( ! empty( $params['delivery_mode'] ) ) {
			return sanitize_key( $params['delivery_mode'] );
		}

		if ( preg_match( '/\b(?:save|store|keep)\b/i', $normalized ) && preg_match( '/\bdraft\b/i', $normalized ) ) {
			return 'save_draft';
		}

		if ( preg_match( '/\bsave\b/i', $normalized ) && preg_match( '/\b(?:email|reply|message)\b/i', $normalized ) ) {
			return 'save_draft';
		}

		if ( preg_match( '/\bsend\b/i', $normalized ) ) {
			return 'send';
		}

		return 'preview';
	}

	private static function extract_email_instruction( $command, $normalized, $params ) {
		if ( ! empty( $params['instruction'] ) ) {
			$hint_instruction = sanitize_textarea_field( $params['instruction'] );

			if ( '' !== $hint_instruction ) {
				return $hint_instruction;
			}
		}

		$email_segment = (string) $command;

		if ( preg_match( '/\b(?:write|draft|compose|prepare|create|save|send)\b(?=[^.!?]*\b(?:email|reply|message)\b)/i', (string) $command, $matches, PREG_OFFSET_CAPTURE ) && isset( $matches[0][1] ) ) {
			$email_segment = substr( (string) $command, (int) $matches[0][1] );
		}

		$instruction = preg_replace(
			'/\border\s+#?\d+\b/i',
			'',
			$email_segment
		);

		$instruction = preg_replace(
			'/^\s*(?:write|draft|compose|prepare|create|save|send)(?:\s+and\s+(?:save|send))?\b\s*(?:a|an)?\s*/i',
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
			'/\s+(?:and\s+)?(?:save(?:\s+it)?(?:\s+as)?\s+draft|save\s+as\s+draft|send(?:\s+it)?|send\s+to\s+customer)\s*$/i',
			'',
			$instruction
		);

		$instruction = trim( wp_strip_all_tags( $instruction ) );
		$instruction = trim( preg_replace( '/^[\s:,-]+|[\s:,-]+$/', '', $instruction ) );

		if ( self::is_generic_email_instruction( $instruction ) ) {
			$instruction = self::build_contextual_email_instruction( $command, $params );
		}

		if ( '' === $instruction || strtolower( $instruction ) === strtolower( $normalized ) ) {
			return '';
		}

		return sanitize_textarea_field( $instruction );
	}

	private static function is_generic_email_instruction( $instruction ) {
		$instruction = strtolower( trim( (string) $instruction ) );

		if ( '' === $instruction ) {
			return true;
		}

		return (bool) preg_match(
			'/^(?:about\s+it|it|this|that|email\s+about\s+it|an?\s+email\s+about\s+it|message\s+about\s+it|reply\s+about\s+it|n\s+email\s+about\s+it)$/i',
			$instruction
		);
	}

	private static function build_contextual_email_instruction( $command, $params ) {
		$status = self::extract_status( $command, $params );
		$note   = self::extract_note( $command, $params );

		if ( '' !== $status && '' !== $note ) {
			return sprintf(
				'Notify the customer that the order is now %1$s and explain: %2$s.',
				self::humanize_status( $status ),
				$note
			);
		}

		if ( '' !== $note ) {
			return sprintf(
				'Notify the customer and explain: %s.',
				$note
			);
		}

		if ( '' !== $status ) {
			return sprintf(
				'Notify the customer that the order is now %s.',
				self::humanize_status( $status )
			);
		}

		return '';
	}

	private static function humanize_status( $status ) {
		$status = str_replace( '-', ' ', sanitize_key( (string) $status ) );

		if ( '' === $status ) {
			return '';
		}

		return strtolower( $status );
	}
}
