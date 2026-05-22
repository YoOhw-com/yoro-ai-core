<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Pre_Router_Contextual_Order_Write_Guard {

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$client_context = is_array( $client_context ) ? $client_context : [];

		if (
			! self::looks_like_contextual_order_write_command( $normalized ) ||
			! self::has_order_context( $client_context )
		) {
			return null;
		}

		$semantic_candidate = self::build_semantic_candidate( $command, $normalized, $client_context );

		if ( is_array( $semantic_candidate ) ) {
			return self::normalize_guard_result(
				$semantic_candidate,
				$command,
				$normalized,
				$client_context,
				$callbacks
			);
		}

		foreach ( self::parser_candidates() as $parser ) {
			if ( ! is_callable( $parser ) ) {
				continue;
			}

			$parsed = call_user_func( $parser, $command, $normalized, $client_context );

			if ( ! is_array( $parsed ) ) {
				continue;
			}

			if ( ! self::is_supported_contextual_write_result( $parsed ) ) {
				continue;
			}

			return self::normalize_guard_result(
				$parsed,
				$command,
				$normalized,
				$client_context,
				$callbacks
			);
		}

		return null;
	}

	private static function build_semantic_candidate( $command, $normalized, $client_context = [] ) {
		$order_ids = self::resolve_order_ids_from_context( $normalized, $client_context );

		if ( empty( $order_ids ) ) {
			return null;
		}

		$params = [
			'order_ids' => $order_ids,
			'order_id'  => $order_ids[0],
		];
		$note = self::extract_note( $command );

		if (
			'' !== $note &&
			preg_match( '/\b(?:add\s+note|leave\s+note|note\s*:?)\b/i', (string) $normalized )
		) {
			$params['note'] = $note;

			return self::candidate(
				'orders.add_note',
				$params,
				'guard.orders.contextual.add_note'
			);
		}

		$status = self::detect_status( $command, $normalized );

		if (
			'' !== $status &&
			preg_match( '/\b(?:change|mark|move|set|update)\b/i', (string) $normalized )
		) {
			$params['status'] = $status;

			if ( '' !== $note ) {
				$params['note'] = $note;

				return self::candidate(
					'orders.bulk_update_status_with_note',
					$params,
					'guard.orders.contextual.bulk_update_status_with_note'
				);
			}

			$params['note'] = __( 'Order status updated by YoRo AI.', 'yoro-ai-core' );

			return self::candidate(
				'orders.update_status',
				$params,
				'guard.orders.contextual.update_status'
			);
		}

		if (
			preg_match( '/\bhold\b/i', (string) $normalized ) &&
			! preg_match( '/\b(?:show|list|find|search|view|get)\b/i', (string) $normalized )
		) {
			return self::candidate(
				'orders.hold',
				$params,
				'guard.follow_up.orders.hold'
			);
		}

		return null;
	}

	private static function candidate( $action, $params, $parser_rule ) {
		return [
			'success'       => true,
			'matched'       => true,
			'action'        => sanitize_text_field( $action ),
			'params'        => is_array( $params ) ? $params : [],
			'parser_rule'   => sanitize_text_field( $parser_rule ),
			'parser_source' => 'guard',
			'confidence'    => 0.94,
		];
	}

	private static function parser_candidates() {
		return [
			[ 'YoRo_Followup_Command_Parser', 'parse_write' ],
			[ 'YoRo_Orders_Command_Parser', 'parse_early' ],
			[ 'YoRo_Orders_Command_Parser', 'parse_write' ],
		];
	}

	private static function is_supported_contextual_write_result( $parsed ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$action = sanitize_text_field( $parsed['action'] ?? '' );

		if ( 'clarify' === sanitize_key( $parsed['stage'] ?? '' ) ) {
			return true;
		}

		return in_array(
			$action,
			[
				'orders.add_note',
				'orders.bulk_update_status_with_note',
				'orders.hold',
				'orders.update_status',
			],
			true
		);
	}

	private static function normalize_guard_result( $parsed, $command, $normalized, $client_context = [], $callbacks = [] ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$parsed = self::apply_resolved_order_focus( $parsed, $normalized, $client_context );
		$parsed['parser_rule'] = self::normalize_parser_rule( $parsed['parser_rule'] ?? '' );
		$parsed['parser_source'] = 'guard';
		$parsed['confidence'] = isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0.94;

		if ( ! empty( $parsed['intent'] ) && is_array( $parsed['intent'] ) ) {
			$parsed['intent']['source'] = 'guard';
		}

		if ( ! empty( $parsed['stage'] ) && 'clarify' === sanitize_key( $parsed['stage'] ) ) {
			return $parsed;
		}

		$callbacks = is_array( $callbacks ) ? $callbacks : [];
		$normalized_result = $parsed;

		if ( ! empty( $callbacks['normalize_parsed_result'] ) && is_callable( $callbacks['normalize_parsed_result'] ) ) {
			$normalized_result = call_user_func(
				$callbacks['normalize_parsed_result'],
				$parsed,
				$command,
				$normalized,
				is_array( $client_context ) ? $client_context : []
			);
			$normalized_result = is_array( $normalized_result ) ? $normalized_result : $parsed;
		} elseif ( class_exists( 'YoRo_Parser_Result_Normalizer' ) ) {
			$normalized_result = YoRo_Parser_Result_Normalizer::normalize(
				$parsed,
				$command,
				$normalized,
				is_array( $client_context ) ? $client_context : []
			);
		}

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

	private static function normalize_parser_rule( $parser_rule ) {
		$parser_rule = sanitize_text_field( (string) $parser_rule );

		if ( 0 === strpos( $parser_rule, 'guard.' ) ) {
			return $parser_rule;
		}

		if ( 0 === strpos( $parser_rule, 'rule.follow_up.' ) ) {
			return 'guard.follow_up.' . substr( $parser_rule, strlen( 'rule.follow_up.' ) );
		}

		if ( 0 === strpos( $parser_rule, 'rule.orders.' ) ) {
			return 'guard.orders.contextual.' . substr( $parser_rule, strlen( 'rule.orders.' ) );
		}

		return 'guard.order_contextual_write';
	}

	private static function resolve_order_ids_from_context( $normalized, $client_context = [] ) {
		$client_context = is_array( $client_context ) ? $client_context : [];
		$normalized = is_string( $normalized ) ? strtolower( trim( $normalized ) ) : '';

		if (
			(
				false !== strpos( $normalized, 'this order' ) ||
				false !== strpos( $normalized, 'that order' ) ||
				false !== strpos( $normalized, 'the order' )
			) &&
			! empty( $client_context['resolved_order_ids'] ) &&
			is_array( $client_context['resolved_order_ids'] )
		) {
			return self::clean_order_ids( $client_context['resolved_order_ids'] );
		}

		if (
			false !== strpos( $normalized, 'this order' ) ||
			false !== strpos( $normalized, 'that order' ) ||
			false !== strpos( $normalized, 'the order' )
		) {
			$current_order_id = absint( $client_context['current_order_id'] ?? 0 );

			if ( $current_order_id > 0 ) {
				return [ $current_order_id ];
			}

			$last_referenced_order_id = absint( $client_context['last_referenced_order_id'] ?? 0 );

			if ( $last_referenced_order_id > 0 ) {
				return [ $last_referenced_order_id ];
			}
		}

		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? array_values( $client_context['last_orders'] )
			: [];
		$last_order_ids = isset( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] )
			? self::clean_order_ids( $client_context['last_order_ids'] )
			: [];
		$ordinal_index = self::resolve_order_list_reference_index( $normalized, $last_orders, $last_order_ids );

		if ( null !== $ordinal_index ) {
			if ( isset( $last_orders[ $ordinal_index ]['id'] ) ) {
				return self::clean_order_ids( [ $last_orders[ $ordinal_index ]['id'] ] );
			}

			if ( isset( $last_order_ids[ $ordinal_index ] ) ) {
				return self::clean_order_ids( [ $last_order_ids[ $ordinal_index ] ] );
			}
		}

		if (
			false !== strpos( $normalized, 'all of them' ) ||
			false !== strpos( $normalized, 'all these' ) ||
			false !== strpos( $normalized, 'those orders' ) ||
			false !== strpos( $normalized, 'these orders' ) ||
			false !== strpos( $normalized, 'selected orders' ) ||
			preg_match( '/\bthem\b/i', $normalized )
		) {
			if ( ! empty( $last_orders ) ) {
				return self::clean_order_ids( wp_list_pluck( $last_orders, 'id' ) );
			}

			return $last_order_ids;
		}

		if ( preg_match( '/\b(?:latest|recent)\s+(\d{1,2})\s+orders?\b/i', $normalized, $matches ) ) {
			$limit = min( max( absint( $matches[1] ?? 0 ), 1 ), 50 );

			if ( ! empty( $last_orders ) ) {
				return self::clean_order_ids( wp_list_pluck( array_slice( $last_orders, 0, $limit ), 'id' ) );
			}

			return array_slice( $last_order_ids, 0, $limit );
		}

		return [];
	}

	private static function resolve_order_list_reference_index( $normalized, $last_orders, $last_order_ids ) {
		$available_count = max(
			is_array( $last_orders ) ? count( $last_orders ) : 0,
			is_array( $last_order_ids ) ? count( $last_order_ids ) : 0
		);

		if ( $available_count <= 0 ) {
			return null;
		}

		$ordinal_map = [
			'first'  => 0,
			'second' => 1,
			'third'  => 2,
		];

		foreach ( $ordinal_map as $word => $index ) {
			if ( false !== strpos( $normalized, $word . ' one' ) ) {
				return $index < $available_count ? $index : null;
			}
		}

		if ( false !== strpos( $normalized, 'last one' ) ) {
			return $available_count - 1;
		}

		if ( preg_match( '/\b(\d+)(?:st|nd|rd|th)\s+one\b/i', $normalized, $matches ) ) {
			$index = max( 1, absint( $matches[1] ?? 0 ) ) - 1;

			return $index < $available_count ? $index : null;
		}

		return null;
	}

	private static function extract_note( $command ) {
		$command = is_string( $command ) ? trim( $command ) : '';

		if ( '' === $command ) {
			return '';
		}

		if ( preg_match( '/:\s*(.+)$/s', $command, $matches ) ) {
			return sanitize_textarea_field( trim( $matches[1] ?? '' ) );
		}

		if ( preg_match( '/\bwith\s+note\s+(.+)$/is', $command, $matches ) ) {
			return sanitize_textarea_field( trim( $matches[1] ?? '' ) );
		}

		return '';
	}

	private static function detect_status( $command, $normalized ) {
		if ( ! class_exists( 'YoRo_Order_Helper' ) ) {
			return '';
		}

		$status = YoRo_Order_Helper::detect_status_from_text( $normalized );

		if ( empty( $status ) ) {
			$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
		}

		return empty( $status ) ? '' : YoRo_Order_Helper::normalize_status_slug( $status );
	}

	private static function clean_order_ids( $order_ids ) {
		return array_values(
			array_filter(
				array_unique(
					array_map( 'absint', is_array( $order_ids ) ? $order_ids : [] )
				)
			)
		);
	}

	private static function apply_resolved_order_focus( $parsed, $normalized, $client_context = [] ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$client_context = is_array( $client_context ) ? $client_context : [];
		$normalized = is_string( $normalized ) ? strtolower( trim( $normalized ) ) : '';

		if (
			false === strpos( $normalized, 'this order' ) &&
			false === strpos( $normalized, 'that order' ) &&
			false === strpos( $normalized, 'the order' )
		) {
			return $parsed;
		}

		if ( empty( $client_context['resolved_order_ids'] ) || ! is_array( $client_context['resolved_order_ids'] ) ) {
			return $parsed;
		}

		$order_ids = array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $client_context['resolved_order_ids'] )
				)
			)
		);

		if ( empty( $order_ids ) ) {
			return $parsed;
		}

		if ( empty( $parsed['params'] ) || ! is_array( $parsed['params'] ) ) {
			$parsed['params'] = [];
		}

		$parsed['params']['order_ids'] = $order_ids;
		$parsed['params']['order_id'] = $order_ids[0];

		return $parsed;
	}

	private static function looks_like_contextual_order_write_command( $normalized ) {
		$normalized = is_string( $normalized ) ? strtolower( trim( $normalized ) ) : '';

		if ( '' === $normalized ) {
			return false;
		}

		if (
			! preg_match(
				'/\b(?:hold|change|mark|move|set|update|add\s+note|leave\s+note|note\s*:)\b/i',
				$normalized
			)
		) {
			return false;
		}

		return self::has_contextual_order_reference( $normalized );
	}

	private static function has_contextual_order_reference( $normalized ) {
		if (
			false !== strpos( $normalized, 'this order' ) ||
			false !== strpos( $normalized, 'that order' ) ||
			false !== strpos( $normalized, 'the order' ) ||
			false !== strpos( $normalized, 'these orders' ) ||
			false !== strpos( $normalized, 'those orders' ) ||
			false !== strpos( $normalized, 'selected orders' ) ||
			false !== strpos( $normalized, 'all these' ) ||
			false !== strpos( $normalized, 'all of them' ) ||
			preg_match( '/\bthem\b/i', $normalized ) ||
			preg_match( '/\b(?:first|second|third|last)\s+one\b/i', $normalized ) ||
			preg_match( '/\b\d+(?:st|nd|rd|th)\s+one\b/i', $normalized ) ||
			preg_match( '/\b(?:latest|recent)\s+\d{1,2}\s+orders?\b/i', $normalized )
		) {
			return true;
		}

		return false;
	}

	private static function has_order_context( $client_context ) {
		$client_context = is_array( $client_context ) ? $client_context : [];

		if (
			! empty( $client_context['current_order_id'] ) ||
			! empty( $client_context['last_referenced_order_id'] )
		) {
			return true;
		}

		foreach ( [ 'last_orders', 'last_order_ids', 'recent_order_ids', 'resolved_order_ids' ] as $key ) {
			if ( ! empty( $client_context[ $key ] ) && is_array( $client_context[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}
}
