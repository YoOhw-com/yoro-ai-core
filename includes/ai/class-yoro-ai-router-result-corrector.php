<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Router_Result_Corrector {

	public static function correct_order_status_semantics( $result, $normalized ) {
		$result = self::correct_hold_to_update_status( $result, $normalized );
		$result = self::correct_add_note_with_status_to_bulk_action( $result );
		$result = self::correct_update_status_with_note_to_bulk_action( $result );

		return $result;
	}

	public static function correct_hold_to_update_status( $result, $normalized ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		if (
			empty( $result['action'] ) ||
			'orders.hold' !== $result['action'] ||
			! preg_match( '/\b(move|change|set|update|mark)\b/i', $normalized ) ||
			! preg_match( '/\b(to|as|status)\b/i', $normalized )
		) {
			return $result;
		}

		$result['action'] = 'orders.update_status';

		if ( empty( $result['params'] ) || ! is_array( $result['params'] ) ) {
			$result['params'] = [];
		}

		if ( empty( $result['params']['status'] ) ) {
			$result['params']['status'] = 'on-hold';
		}

		$result['parser_rule'] = 'ai.corrected.orders.update_status_from_hold';

		if ( isset( $result['intent'] ) && is_array( $result['intent'] ) ) {
			$result['intent']['name']   = 'orders_update_status';
			$result['intent']['action'] = 'orders.update_status';
		}

		return $result;
	}

	private static function correct_update_status_with_note_to_bulk_action( $result ) {
		if ( ! is_array( $result ) || 'orders.update_status' !== ( $result['action'] ?? '' ) ) {
			return $result;
		}

		$note = isset( $result['params']['note'] ) ? trim( sanitize_text_field( $result['params']['note'] ) ) : '';

		$default_notes = [
			__( 'Order status updated by YoRo AI.', 'yoro-ai-core' ),
		];

		if ( '' === $note || in_array( $note, $default_notes, true ) ) {
			return $result;
		}

		$result['action'] = 'orders.bulk_update_status_with_note';

		if ( isset( $result['intent'] ) && is_array( $result['intent'] ) ) {
			$result['intent']['name']   = 'orders_bulk_update_status_with_note';
			$result['intent']['action'] = 'orders.bulk_update_status_with_note';
		}

		return $result;
	}

	private static function correct_add_note_with_status_to_bulk_action( $result ) {
		if ( ! is_array( $result ) || 'orders.add_note' !== ( $result['action'] ?? '' ) ) {
			return $result;
		}

		$status = sanitize_key( $result['params']['status'] ?? '' );
		$note   = isset( $result['params']['note'] ) ? trim( sanitize_text_field( $result['params']['note'] ) ) : '';
		$order_ids = isset( $result['params']['order_ids'] ) && is_array( $result['params']['order_ids'] )
			? array_values( array_filter( array_map( 'absint', $result['params']['order_ids'] ) ) )
			: [];

		if ( '' === $status || '' === $note || empty( $order_ids ) ) {
			return $result;
		}

		$result['action']        = 'orders.bulk_update_status_with_note';
		$result['intent_family'] = 'update_order_status';

		if ( isset( $result['intent'] ) && is_array( $result['intent'] ) ) {
			$result['intent']['name']   = 'orders_bulk_update_status_with_note';
			$result['intent']['action'] = 'orders.bulk_update_status_with_note';
		}

		if ( isset( $result['command_decision'] ) && is_array( $result['command_decision'] ) ) {
			$result['command_decision']['action']        = 'orders.bulk_update_status_with_note';
			$result['command_decision']['intent_family'] = 'update_order_status';
		}

		return $result;
	}
}
