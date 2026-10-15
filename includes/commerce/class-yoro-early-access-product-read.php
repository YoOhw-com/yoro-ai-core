<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded presentation projection over the existing canonical read executors. */
class YoRo_Early_Access_Product_Read {
	const ACTIONS = [ 'products.search', 'products.get_details', 'products.get_inventory_queue' ];

	public static function valid_params( $action, $params ) {
		if ( ! in_array( $action, self::ACTIONS, true ) || ! is_array( $params ) ) {
			return false;
		}
		if ( 'products.get_details' === $action ) {
			$ids = $params['product_ids'] ?? null;
			return 1 === count( $params ) && is_array( $ids ) && array_values( $ids ) === $ids
				&& count( $ids ) >= 1 && count( $ids ) <= 5 && count( array_unique( $ids, SORT_REGULAR ) ) === count( $ids )
				&& count( array_filter( $ids, static function( $id ) { return is_int( $id ) && $id > 0; } ) ) === count( $ids );
		}
		if ( ! is_int( $params['limit'] ?? null ) || $params['limit'] < 1 || $params['limit'] > 50 ) {
			return false;
		}
		if ( 'products.get_inventory_queue' === $action ) {
			return ! array_diff( array_keys( $params ), [ 'limit', 'focus' ] )
				&& in_array( $params['focus'] ?? 'overview', [ 'overview', 'low_stock', 'out_of_stock', 'on_backorder' ], true );
		}
		if ( array_diff( array_keys( $params ), [ 'sku', 'query', 'status', 'type', 'stock_status', 'limit' ] )
			|| 'publish' !== ( $params['status'] ?? null ) || ( isset( $params['sku'] ) && isset( $params['query'] ) ) ) {
			return false;
		}
		foreach ( [ 'sku' => 128, 'query' => 200 ] as $field => $max ) {
			if ( array_key_exists( $field, $params ) && ( ! is_string( $params[ $field ] ) || '' === trim( $params[ $field ] )
				|| strlen( $params[ $field ] ) > $max || ! preg_match( '//u', $params[ $field ] ) || preg_match( '/[\x00-\x1f\x7f]/', $params[ $field ] ) ) ) {
				return false;
			}
		}
		return ( ! isset( $params['type'] ) || in_array( $params['type'], [ 'simple', 'variable', 'variation' ], true ) )
			&& ( ! isset( $params['stock_status'] ) || in_array( $params['stock_status'], [ 'instock', 'outofstock', 'onbackorder' ], true ) );
	}

	public static function params_hash( array $params ) {
		ksort( $params, SORT_STRING );
		return hash( 'sha256', wp_json_encode( $params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public static function execute( array $operation ) {
		$action = $operation['action'];
		$params = $operation['params'];
		if ( ! self::valid_params( $action, $params ) ) {
			return new WP_Error( 'yoro_invalid_product_read', 'The bounded product selectors are invalid.' );
		}
		$execution = YoRo_Action_Executor::execute( $action, $params, true, false, [ 'connector_remote_agent' => true,
			'workflow_id' => $operation['workflow_id'], 'operation_id' => $operation['operation_id'] ] );
		if ( is_wp_error( $execution ) || ! is_array( $execution ) || empty( $execution['success'] ) ) {
			return new WP_Error( 'yoro_product_read_failed', 'The local product read did not complete.' );
		}
		$inventory = 'products.get_inventory_queue' === $action;
		$limit = 'products.get_details' === $action ? count( $params['product_ids'] ) : $params['limit'];
		$products = [];
		foreach ( (array) ( $execution['products'] ?? ( $execution['data']['products'] ?? [] ) ) as $row ) {
			$id = is_array( $row ) ? ( $row['product_id'] ?? ( $row['id'] ?? null ) ) : null;
			$product = is_numeric( $id ) ? wc_get_product( (int) $id ) : false;
			$dto = self::product( $product, 'products.get_details' === $action );
			if ( null === $dto || isset( $products[ $dto['id'] ] ) ) {
				continue;
			}
			if ( ( isset( $params['sku'] ) && $params['sku'] !== $dto['sku'] )
				|| ( isset( $params['type'] ) && $params['type'] !== $dto['type'] )
				|| ( isset( $params['stock_status'] ) && $params['stock_status'] !== $dto['stock_status'] )
				|| ( isset( $params['product_ids'] ) && ! in_array( $dto['id'], $params['product_ids'], true ) ) ) {
				continue;
			}
			if ( $inventory ) {
				$extra = $dto['extensions']['woocommerce.product'];
				$issue = 'outofstock' === $dto['stock_status'] ? 'out_of_stock' : ( 'onbackorder' === $dto['stock_status'] ? 'on_backorder' : null );
				if ( null === $issue && $extra['manage_stock'] && null !== $dto['stock_quantity'] && null !== $extra['low_stock_amount'] && $dto['stock_quantity'] <= $extra['low_stock_amount'] ) {
					$issue = 'low_stock';
				}
				if ( null === $issue || ( 'overview' !== ( $params['focus'] ?? 'overview' ) && $issue !== $params['focus'] ) ) {
					continue;
				}
				$extra['issue'] = $issue;
				foreach ( [ 'price', 'currency', 'type', 'status' ] as $field ) {
					$extra[ $field ] = $dto[ $field ];
					unset( $dto[ $field ] );
				}
				$dto['product_id'] = $dto['id'];
				$dto['extensions']['woocommerce.product'] = $extra;
			}
			$products[ $dto['id'] ] = $dto;
			if ( count( $products ) >= $limit ) {
				break;
			}
		}
		$data = [ $inventory ? 'items' : 'products' => array_values( $products ), 'count' => count( $products ) ];
		if ( 'products.get_details' !== $action ) {
			$data['page_info'] = [ 'has_next_page' => false, 'next_cursor' => null ];
		}
		$message = 'Product read completed.';
		return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message, 'data' => $data,
			'read_only' => true, 'undoable' => false, 'result_type' => $inventory ? 'inventory_queue' : 'products',
			'display_type' => $inventory ? 'inventory_queue' : 'products_list', 'assistant_summary' => $message, 'extensions' => (object) [] ];
	}

	private static function product( $product, $details ) {
		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() || ! in_array( $product->get_type(), [ 'simple', 'variable', 'variation' ], true ) ) {
			return null;
		}
		if ( 'variation' === $product->get_type() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent instanceof WC_Product || 'publish' !== $parent->get_status() ) {
				return null;
			}
		}
		$image = $product->get_image_id();
		$attributes = [];
		foreach ( $product->get_attributes() as $name => $value ) {
			$attributes[] = mb_strcut( sanitize_text_field( wc_attribute_label( $name ) . ': ' . $product->get_attribute( $name ) ), 0, 200, 'UTF-8' );
			if ( count( $attributes ) >= 20 ) { break; }
		}
		$threshold = function_exists( 'wc_get_low_stock_amount' ) ? wc_get_low_stock_amount( $product ) : null;
		$extra = [ 'parent_id' => (int) $product->get_parent_id(), 'image_url' => self::url( $image ? wp_get_attachment_image_url( $image, 'woocommerce_thumbnail' ) : null ),
			'permalink' => self::url( $product->get_permalink() ), 'attributes' => $attributes, 'manage_stock' => (bool) $product->managing_stock(),
			'low_stock_amount' => is_numeric( $threshold ) ? max( 0, (int) $threshold ) : null ];
		if ( $details ) { $extra['description'] = mb_strcut( sanitize_textarea_field( wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ) ), 0, 2000, 'UTF-8' ); }
		return [ 'id' => (int) $product->get_id(), 'name' => mb_strcut( sanitize_text_field( $product->get_name() ), 0, 1024, 'UTF-8' ),
			'sku' => $product->get_sku(), 'status' => 'publish', 'type' => $product->get_type(), 'price' => (string) $product->get_price(),
			'currency' => get_woocommerce_currency(), 'stock_quantity' => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
			'stock_status' => $product->get_stock_status(), 'version' => YoRo_Commerce_Discovery_Service::product_version( $product ), 'extensions' => [ 'woocommerce.product' => $extra ] ];
	}

	private static function url( $url ) {
		$home = wp_parse_url( home_url() );
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;
		return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? null ) && strlen( $url ) <= 2048
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ( $parts['port'] ?? null ) === ( $home['port'] ?? null )
			&& strtolower( $parts['host'] ?? '' ) === strtolower( $home['host'] ?? '' ) ? esc_url_raw( $url ) : null;
	}
}
