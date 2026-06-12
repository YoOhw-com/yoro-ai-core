<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Product_Helper {

	public static function normalize_stock_status_slug( $status ) {
		$status = sanitize_key( (string) $status );

		if ( in_array( $status, [ 'out_of_stock', 'out-of-stock' ], true ) ) {
			return 'outofstock';
		}

		if ( in_array( $status, [ 'on_backorder', 'on-backorder' ], true ) ) {
			return 'onbackorder';
		}

		if ( in_array( $status, [ 'in_stock', 'in-stock' ], true ) ) {
			return 'instock';
		}

		return $status;
	}

	public static function get_stock_status_options() {
		$options = [];

		if ( function_exists( 'wc_get_product_stock_status_options' ) ) {
			foreach ( wc_get_product_stock_status_options() as $status => $label ) {
				$slug = self::normalize_stock_status_slug( $status );

				if ( '' === $slug ) {
					continue;
				}

				$options[ $slug ] = [
					'slug'  => $slug,
					'label' => wp_strip_all_tags( (string) $label ),
				];
			}
		}

		return $options;
	}

	public static function get_stock_status_label( $status ) {
		$status  = self::normalize_stock_status_slug( $status );
		$options = self::get_stock_status_options();

		if ( isset( $options[ $status ]['label'] ) && '' !== $options[ $status ]['label'] ) {
			return $options[ $status ]['label'];
		}

		return self::fallback_label( $status );
	}

	public static function get_product_status_label( $status ) {
		$status = sanitize_key( (string) $status );

		if ( '' === $status ) {
			return '';
		}

		$post_status = get_post_status_object( $status );

		if ( $post_status && ! empty( $post_status->label ) ) {
			return wp_strip_all_tags( (string) $post_status->label );
		}

		return self::fallback_label( $status );
	}

	public static function get_product_type_options() {
		$options = [];

		if ( function_exists( 'wc_get_product_types' ) ) {
			foreach ( wc_get_product_types() as $type => $label ) {
				$slug = sanitize_key( (string) $type );

				if ( '' === $slug ) {
					continue;
				}

				$options[ $slug ] = [
					'slug'  => $slug,
					'label' => wp_strip_all_tags( (string) $label ),
				];
			}
		}

		return $options;
	}

	public static function get_product_type_label( $type ) {
		$type    = sanitize_key( (string) $type );
		$options = self::get_product_type_options();

		if ( isset( $options[ $type ]['label'] ) && '' !== $options[ $type ]['label'] ) {
			return $options[ $type ]['label'];
		}

		return self::fallback_label( $type );
	}

	public static function map_product_type_count_labels( $counts ) {
		return self::map_keyed_labels( $counts, [ __CLASS__, 'get_product_type_label' ] );
	}

	public static function map_stock_status_count_labels( $counts ) {
		return self::map_keyed_labels( $counts, [ __CLASS__, 'get_stock_status_label' ] );
	}

	public static function map_product_status_count_labels( $counts ) {
		return self::map_keyed_labels( $counts, [ __CLASS__, 'get_product_status_label' ] );
	}

	public static function get_product_identifier_types() {
		return [
			[
				'key'        => 'product_id',
				'field'      => 'product_id',
				'value_type' => 'integer',
				'aliases'    => [
					'product id',
					'product #',
					'variation id',
					'variation #',
					'variation product id',
					'woocommerce id',
				],
			],
			[
				'key'        => 'product_sku',
				'field'      => 'product_sku',
				'value_type' => 'string',
				'aliases'    => [
					'sku',
					'product sku',
				],
			],
			[
				'key'        => 'global_unique_id',
				'field'      => 'global_unique_id',
				'value_type' => 'string',
				'aliases'    => [
					'global unique id',
					'global id',
					'gtin',
					'upc',
					'ean',
					'isbn',
					'barcode',
				],
			],
		];
	}

	private static function map_keyed_labels( $counts, $callback ) {
		$labels = [];

		foreach ( is_array( $counts ) ? $counts : [] as $key => $value ) {
			$labels[ $key ] = is_callable( $callback ) ? call_user_func( $callback, $key ) : self::fallback_label( $key );
		}

		return $labels;
	}

	private static function fallback_label( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		return ucwords( trim( preg_replace( '/\s+/', ' ', str_replace( [ '_', '-' ], ' ', $value ) ) ) );
	}
}
