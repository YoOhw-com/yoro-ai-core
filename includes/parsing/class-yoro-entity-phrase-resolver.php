<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Entity_Phrase_Resolver {

	public static function resolve( $command, $client_context ) {
		if ( empty( $client_context ) || ! is_array( $client_context ) ) {
			return [];
		}

		$normalized = strtolower( (string) $command );

		$order_ids = isset( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] )
			? $client_context['last_order_ids']
			: [];

		$order_ids = array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $order_ids )
				)
			)
		);

		if ( empty( $order_ids ) ) {
			return [];
		}

		$current_order_id = absint(
			$client_context['current_order_id']
				?? $client_context['last_referenced_order_id']
				?? 0
		);

		$ordinal_index = self::resolve_order_list_reference_index( $normalized, count( $order_ids ) );

		if ( null !== $ordinal_index && ! empty( $order_ids[ $ordinal_index ] ) ) {
			return [ absint( $order_ids[ $ordinal_index ] ) ];
		}

		// OTHER / REMAINING ORDERS
		if (
			false !== strpos( $normalized, 'other orders' ) ||
			false !== strpos( $normalized, 'the other orders' ) ||
			false !== strpos( $normalized, 'remaining orders' ) ||
			false !== strpos( $normalized, 'rest of the orders' )
		) {
			if ( ! $current_order_id ) {
				return $order_ids;
			}

			return array_values(
				array_filter(
					array_diff(
						$order_ids,
						[ $current_order_id ]
					)
				)
			);
		}

		// THOSE / THESE ORDERS
		if (
			false !== strpos( $normalized, 'those orders' ) ||
			false !== strpos( $normalized, 'these orders' ) ||
			false !== strpos( $normalized, 'all of them' ) ||
			false !== strpos( $normalized, 'all these' ) ||
			false !== strpos( $normalized, 'all those' ) ||
			false !== strpos( $normalized, 'selected orders' ) ||
			preg_match( '/\b(?:them|these|those)\b/i', $normalized )
		) {
			return $order_ids;
		}

		// THIS ORDER
		if (
			false !== strpos( $normalized, 'this order' ) ||
			false !== strpos( $normalized, 'that order' )
		) {
			if ( $current_order_id ) {
				return [ $current_order_id ];
			}
		}

		return [];
	}

	private static function resolve_order_list_reference_index( $normalized, $available_count ) {
		$available_count = absint( $available_count );

		if ( $available_count <= 0 ) {
			return null;
		}

		$ordinal_map = [
			'first'   => 0,
			'second'  => 1,
			'third'   => 2,
			'fourth'  => 3,
			'fifth'   => 4,
			'sixth'   => 5,
			'seventh' => 6,
			'eighth'  => 7,
			'ninth'   => 8,
			'tenth'   => 9,
		];

		foreach ( $ordinal_map as $word => $index ) {
			if ( preg_match( '/\b(?:the\s+)?' . preg_quote( $word, '/' ) . '\s+(?:one|order|item|result)\b/i', $normalized ) ) {
				return $index < $available_count ? $index : null;
			}
		}

		if ( preg_match( '/\b(?:the\s+)?last\s+(?:one|order|item|result)\b/i', $normalized ) ) {
			return $available_count - 1;
		}

		if ( preg_match( '/\b(\d+)(?:st|nd|rd|th)\s+(?:one|order|item|result)\b/i', $normalized, $matches ) ) {
			$index = max( 1, absint( $matches[1] ?? 0 ) ) - 1;

			return $index < $available_count ? $index : null;
		}

		return null;
	}
}
