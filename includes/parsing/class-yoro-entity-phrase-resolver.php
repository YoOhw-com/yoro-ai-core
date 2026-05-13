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
			false !== strpos( $normalized, 'these orders' )
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
}
