<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Catalog_Service {

	public static function get_product_details( $params ) {
		$target = YoRo_Store_Inventory_Service::resolve_product_target( $params );
		$product = $target['product'] ?? false;

		if ( '' === ( $target['target_type'] ?? '' ) ) {
			return new WP_Error( 'missing_product_target', __( 'Please choose a product first.', 'yoro-ai-core' ) );
		}

		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'product_not_found', __( 'I could not find that product.', 'yoro-ai-core' ) );
		}

		$item = self::product_result_item( $product, $target );
		$summary = sprintf(
			/* translators: 1: product name, 2: product ID */
			__( 'Found product %1$s (#%2$d).', 'yoro-ai-core' ),
			$item['name'],
			$item['product_id']
		);

		return [
			'success'      => true,
			'action'       => 'products.get_details',
			'summary'      => $summary,
			'message'      => $summary,
			'product_id'   => $item['product_id'],
			'product'      => $item,
			'products'     => [ $item ],
			'processed'    => [ $item['product_id'] ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'result_type'  => 'product',
			'display_type' => 'generic',
			'meta'         => [
				'changes_store' => false,
			],
		];
	}

	public static function preview_get_product_details( $params ) {
		$target = YoRo_Store_Inventory_Service::resolve_product_target( $params );
		$product = $target['product'] ?? false;
		$block_reason = '';

		if ( '' === ( $target['target_type'] ?? '' ) ) {
			$block_reason = 'missing_product_target';
			$message      = __( 'Please choose a product first.', 'yoro-ai-core' );
		} elseif ( ! $product instanceof WC_Product ) {
			$block_reason = 'product_not_found';
			$message      = __( 'I could not find that product.', 'yoro-ai-core' );
		} else {
			$message = sprintf(
				/* translators: 1: product name, 2: product ID */
				__( 'This will open details for %1$s (#%2$d).', 'yoro-ai-core' ),
				$product->get_name(),
				$product->get_id()
			);
		}

		return [
			'type'          => 'read',
			'will_execute'  => '' === $block_reason,
			'action'        => 'products.get_details',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'product'       => $product instanceof WC_Product ? self::product_result_item( $product, $target ) : [],
			'count'         => $product instanceof WC_Product ? 1 : 0,
			'changes_store' => false,
		];
	}

	public static function update_product_catalog( $params ) {
		$contract = self::normalize_catalog_contract( $params );
		$error    = self::validate_catalog_contract( $contract );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$product = $contract['product'];
		$before  = self::product_result_item( $product, $contract['target'] );

		try {
			self::apply_catalog_contract( $product, $contract );
			$product->save();
		} catch ( Exception $exception ) {
			return new WP_Error(
				'product_catalog_update_failed',
				__( 'WooCommerce could not save the product catalog update.', 'yoro-ai-core' )
			);
		}

		$product = wc_get_product( $product->get_id() );
		$after   = self::product_result_item( $product, $contract['target'] );
		$summary = sprintf(
			/* translators: 1: product name, 2: product ID */
			__( 'Updated catalog details for %1$s (#%2$d).', 'yoro-ai-core' ),
			$after['name'],
			$after['product_id']
		);

		return [
			'success'      => true,
			'action'       => 'products.update_catalog',
			'summary'      => $summary,
			'message'      => $summary,
			'product_id'   => $after['product_id'],
			'product'      => $after,
			'products'     => [ $after ],
			'before'       => $before,
			'changed_fields' => $contract['changed_fields'],
			'processed'    => [ $after['product_id'] ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'result_type'  => 'product',
			'display_type' => 'generic',
			'meta'         => [
				'changes_store' => true,
				'changed_fields' => $contract['changed_fields'],
			],
		];
	}

	public static function preview_update_product_catalog( $params ) {
		$contract = self::normalize_catalog_contract( $params );
		$error    = self::validate_catalog_contract( $contract );
		$block_reason = '';

		if ( is_wp_error( $error ) ) {
			$block_reason = $error->get_error_code();
			$message      = $error->get_error_message();
		} else {
			$message = sprintf(
				/* translators: 1: product name, 2: product ID */
				__( 'This will update catalog details for %1$s (#%2$d) after approval.', 'yoro-ai-core' ),
				$contract['product']->get_name(),
				$contract['product']->get_id()
			);
		}

		return [
			'type'           => 'write',
			'will_execute'   => false,
			'action'         => 'products.update_catalog',
			'summary'        => $message,
			'message'        => $message,
			'blocked'        => '' !== $block_reason,
			'block_reason'   => $block_reason,
			'product'        => $contract['product'] instanceof WC_Product ? self::product_result_item( $contract['product'], $contract['target'] ) : [],
			'product_id'     => absint( $contract['target']['product_id'] ?? 0 ),
			'changed_fields' => $contract['changed_fields'],
			'changes'        => $contract['changes'],
			'count'          => '' === $block_reason ? 1 : 0,
			'changes_store'  => '' === $block_reason,
		];
	}

	private static function normalize_catalog_contract( $params ) {
		$params  = is_array( $params ) ? $params : [];
		$target  = YoRo_Store_Inventory_Service::resolve_product_target( $params );
		$product = $target['product'] ?? false;
		$changes = [];

		foreach ( [ 'regular_price', 'sale_price', 'sale_price_from', 'sale_price_to', 'status', 'catalog_visibility' ] as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$changes[ $field ] = in_array( $field, [ 'regular_price', 'sale_price' ], true )
					? trim( (string) $params[ $field ] )
					: sanitize_text_field( $params[ $field ] );
			}
		}

		return [
			'target'         => $target,
			'product'        => $product,
			'changes'        => $changes,
			'changed_fields' => array_keys( $changes ),
		];
	}

	private static function validate_catalog_contract( $contract ) {
		if ( '' === ( $contract['target']['target_type'] ?? '' ) ) {
			return new WP_Error( 'missing_product_target', __( 'Please choose a product first.', 'yoro-ai-core' ) );
		}

		if ( ! $contract['product'] instanceof WC_Product ) {
			return new WP_Error( 'product_not_found', __( 'I could not find that product.', 'yoro-ai-core' ) );
		}

		if ( empty( $contract['changes'] ) ) {
			return new WP_Error( 'missing_catalog_changes', __( 'Please provide at least one catalog field to update.', 'yoro-ai-core' ) );
		}

		$changes = $contract['changes'];

		if ( isset( $changes['regular_price'] ) && ( '' === $changes['regular_price'] || ! is_numeric( $changes['regular_price'] ) || (float) $changes['regular_price'] < 0 ) ) {
			return new WP_Error( 'invalid_regular_price', __( 'Regular price must be zero or a positive number.', 'yoro-ai-core' ) );
		}

		if ( isset( $changes['sale_price'] ) && '' !== $changes['sale_price'] && ( ! is_numeric( $changes['sale_price'] ) || (float) $changes['sale_price'] < 0 ) ) {
			return new WP_Error( 'invalid_sale_price', __( 'Sale price must be empty, zero, or a positive number.', 'yoro-ai-core' ) );
		}

		$regular_price = array_key_exists( 'regular_price', $changes ) ? $changes['regular_price'] : $contract['product']->get_regular_price();
		$sale_price    = array_key_exists( 'sale_price', $changes ) ? $changes['sale_price'] : $contract['product']->get_sale_price();

		if ( '' !== $sale_price && '' === $regular_price ) {
			return new WP_Error( 'sale_price_requires_regular_price', __( 'A sale price requires a regular price.', 'yoro-ai-core' ) );
		}

		if ( '' !== $sale_price && '' !== $regular_price && (float) $sale_price > (float) $regular_price ) {
			return new WP_Error( 'sale_price_exceeds_regular_price', __( 'Sale price cannot be greater than regular price.', 'yoro-ai-core' ) );
		}

		foreach ( [ 'sale_price_from', 'sale_price_to' ] as $date_field ) {
			if ( ! empty( $changes[ $date_field ] ) && ! self::normalize_catalog_date( $changes[ $date_field ] ) ) {
				return new WP_Error( 'invalid_sale_schedule', __( 'Please provide valid sale schedule dates.', 'yoro-ai-core' ) );
			}
		}

		$sale_from = array_key_exists( 'sale_price_from', $changes )
			? self::normalize_catalog_date( $changes['sale_price_from'] )
			: $contract['product']->get_date_on_sale_from();
		$sale_to = array_key_exists( 'sale_price_to', $changes )
			? self::normalize_catalog_date( $changes['sale_price_to'] )
			: $contract['product']->get_date_on_sale_to();

		if ( $sale_from && $sale_to && $sale_from->getTimestamp() >= $sale_to->getTimestamp() ) {
			return new WP_Error( 'invalid_sale_schedule', __( 'Sale end date must be later than the sale start date.', 'yoro-ai-core' ) );
		}

		if ( ! empty( $changes['status'] ) && ! in_array( sanitize_key( $changes['status'] ), [ 'draft', 'pending', 'private', 'publish' ], true ) ) {
			return new WP_Error( 'invalid_product_status', __( 'Please choose draft, pending, private, or publish as the product status.', 'yoro-ai-core' ) );
		}

		if ( ! empty( $changes['catalog_visibility'] ) && ! array_key_exists( sanitize_key( $changes['catalog_visibility'] ), self::visibility_options() ) ) {
			return new WP_Error( 'invalid_catalog_visibility', __( 'Please choose a valid WooCommerce catalog visibility.', 'yoro-ai-core' ) );
		}

		return true;
	}

	private static function apply_catalog_contract( WC_Product $product, $contract ) {
		$changes = $contract['changes'];

		if ( array_key_exists( 'regular_price', $changes ) ) {
			$product->set_regular_price( wc_format_decimal( $changes['regular_price'] ) );
		}

		if ( array_key_exists( 'sale_price', $changes ) ) {
			$product->set_sale_price( '' === $changes['sale_price'] ? '' : wc_format_decimal( $changes['sale_price'] ) );
		}

		if ( array_key_exists( 'sale_price_from', $changes ) ) {
			$product->set_date_on_sale_from( self::normalize_catalog_date( $changes['sale_price_from'] ) );
		}

		if ( array_key_exists( 'sale_price_to', $changes ) ) {
			$product->set_date_on_sale_to( self::normalize_catalog_date( $changes['sale_price_to'] ) );
		}

		if ( ! empty( $changes['status'] ) ) {
			$product->set_status( sanitize_key( $changes['status'] ) );
		}

		if ( ! empty( $changes['catalog_visibility'] ) ) {
			$product->set_catalog_visibility( sanitize_key( $changes['catalog_visibility'] ) );
		}
	}

	private static function normalize_catalog_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		try {
			return new WC_DateTime( $value, wp_timezone() );
		} catch ( Exception $exception ) {
			return null;
		}
	}

	private static function visibility_options() {
		return function_exists( 'wc_get_product_visibility_options' )
			? wc_get_product_visibility_options()
			: [
				'visible' => 'Visible',
				'catalog' => 'Catalog',
				'search'  => 'Search',
				'hidden'  => 'Hidden',
			];
	}

	private static function product_result_item( WC_Product $product, $target ) {
		$image_id = $product->get_image_id();
		$categories = [];

		$category_names = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
		$category_names = is_wp_error( $category_names ) ? [] : $category_names;

		foreach ( $category_names as $category ) {
			$category = sanitize_text_field( $category );

			if ( '' !== $category ) {
				$categories[] = $category;
			}
		}

		return [
			'product_id'        => $product->get_id(),
			'name'              => sanitize_text_field( $product->get_name() ),
			'type'              => sanitize_key( $product->get_type() ),
			'status'            => sanitize_key( $product->get_status() ),
			'catalog_visibility' => sanitize_key( $product->get_catalog_visibility() ),
			'sku'               => sanitize_text_field( $product->get_sku() ),
			'global_unique_id'  => method_exists( $product, 'get_global_unique_id' ) ? sanitize_text_field( $product->get_global_unique_id() ) : '',
			'permalink'         => esc_url_raw( $product->get_permalink() ),
			'edit_url'          => get_edit_post_link( $product->get_id(), '' ),
			'image_url'         => $image_id ? esc_url_raw( wp_get_attachment_image_url( $image_id, 'thumbnail' ) ) : '',
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'price'             => $product->get_price(),
			'currency'          => get_woocommerce_currency(),
			'on_sale'           => $product->is_on_sale(),
			'manage_stock'      => $product->get_manage_stock(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'stock_status'      => sanitize_key( $product->get_stock_status() ),
			'categories'        => array_values( array_unique( $categories ) ),
			'target_type'       => sanitize_key( $target['target_type'] ?? '' ),
			'target_value'      => sanitize_text_field( $target['target_value'] ?? '' ),
		];
	}
}
