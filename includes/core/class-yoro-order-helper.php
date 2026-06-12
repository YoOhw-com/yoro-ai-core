<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Order_Helper {

	public static function extract_order_ids_from_text( $text ) {
		$text = (string) $text;
		$order_ids = [];

		if ( preg_match_all( '/(?:orders?|order\s+ids?)\s*(?:#?\d+(?:\s*(?:,|,?\s*and|&)\s*#?\d+)*)/i', $text, $groups ) ) {
			foreach ( $groups[0] as $group ) {
				if ( preg_match_all( '/#?(\d+)\b/', $group, $matches ) ) {
					foreach ( $matches[1] as $id ) {
						$order_ids[] = absint( $id );
					}
				}
			}
		}

		if ( preg_match_all( '/#(\d+)\b/', $text, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$order_ids[] = absint( $id );
			}
		}

		return array_values(
			array_filter(
				array_unique( $order_ids )
			)
		);
	}

	public static function normalize_order_ids( $params = [] ) {
		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = $params['order_ids'];
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ $params['order_id'] ];
		}

		return array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $order_ids )
				)
			)
		);
	}

	public static function apply_order_ids_to_params( $params = [] ) {
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		$order_ids = self::normalize_order_ids( $params );

		if ( ! empty( $order_ids ) ) {
			$params['order_ids'] = $order_ids;

			if ( 1 === count( $order_ids ) ) {
				$params['order_id'] = $order_ids[0];
			}
		}

		return $params;
	}

	public static function valid_order_statuses() {
		$statuses = [
			'pending',
			'processing',
			'on-hold',
			'completed',
			'cancelled',
			'refunded',
			'failed',
		];

		foreach ( self::get_order_status_options() as $slug => $data ) {
			$statuses[] = $slug;
		}

		return array_values( array_unique( array_filter( $statuses ) ) );
	}

	public static function normalize_status_slug( $status ) {
		$status = sanitize_key( (string) $status );

		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}

		if ( in_array( $status, [ 'on_hold', 'onhold' ], true ) ) {
			$status = 'on-hold';
		}

		if ( 'complete' === $status ) {
			$status = 'completed';
		}

		if ( 'canceled' === $status ) {
			$status = 'cancelled';
		}

		return $status;
	}

	public static function get_order_status_options() {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return [];
		}

		$options = [];

		foreach ( wc_get_order_statuses() as $status_key => $label ) {
			$slug = self::normalize_status_slug( $status_key );

			if ( ! $slug ) {
				continue;
			}

			$options[ $slug ] = [
				'key'   => $status_key,
				'slug'  => $slug,
				'label' => wp_strip_all_tags( $label ),
			];
		}

		return $options;
	}

	public static function is_valid_order_status( $status ) {
		$status = self::normalize_status_slug( $status );

		if ( empty( $status ) ) {
			return false;
		}

		return in_array( $status, self::valid_order_statuses(), true );
	}

	public static function get_order_status_label( $status ) {
		$status  = self::normalize_status_slug( $status );
		$options = self::get_order_status_options();

		if ( isset( $options[ $status ]['label'] ) ) {
			return $options[ $status ]['label'];
		}

		return ucwords( str_replace( '-', ' ', $status ) );
	}

	public static function normalize_status( $status ) {
		if ( is_array( $status ) ) {
			$statuses = [];

			foreach ( $status as $item ) {
				$normalized = self::normalize_status( $item );

				if ( $normalized ) {
					$statuses[] = $normalized;
				}
			}

			return array_values( array_unique( $statuses ) );
		}

		$status = self::normalize_status_slug( $status );

		return in_array( $status, self::valid_order_statuses(), true ) ? $status : '';
	}

}
