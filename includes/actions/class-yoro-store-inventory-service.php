<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Inventory_Service {

	public static function prepare_product_pricing_update( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'yoro_permission_denied', 'Pricing updates require manage_woocommerce.' );
		}

		$params = is_array( $params ) ? $params : [];
		$unknown = array_diff( array_keys( $params ), [ 'product_id', 'regular_price', 'expected_version', '_yoro_confirmed', '_yoro_preview' ] );
		if ( $unknown || ! is_int( $params['product_id'] ?? null ) || $params['product_id'] <= 0
			|| ! is_string( $params['regular_price'] ?? null ) || ! self::is_bounded_regular_price( $params['regular_price'], false )
			|| ! is_string( $params['expected_version'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $params['expected_version'] ) ) {
			return new WP_Error( 'invalid_product_pricing_update', 'Use one product ID, one bounded non-negative decimal regular price and its current version.' );
		}

		clean_post_cache( $params['product_id'] );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $params['product_id'] ) : false;
		$eligibility = self::validate_product_pricing_eligibility( $product );
		if ( is_wp_error( $eligibility ) ) {
			return $eligibility;
		}

		$version = self::validate_product_version( $product, $params );
		if ( is_wp_error( $version ) ) {
			return $version;
		}

		$normalized = self::normalize_store_regular_price( $params['regular_price'] );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$current_regular_price = (string) $product->get_regular_price( 'edit' );
		$current_normalized = '' === $current_regular_price ? '' : self::normalize_store_regular_price( $current_regular_price );
		if ( is_wp_error( $current_normalized ) ) {
			return $current_normalized;
		}
		if ( $current_normalized === $normalized ) {
			return new WP_Error( 'product_pricing_no_change', 'The requested regular price already matches the current regular price.' );
		}

		return [
			'product' => $product,
			'previous_regular_price' => $current_regular_price,
			'regular_price' => $normalized,
			'active_price' => (string) $product->get_price( 'edit' ),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
		];
	}

	public static function preview_update_product_pricing( $params ) {
		$prepared = self::prepare_product_pricing_update( $params );
		$blocked = is_wp_error( $prepared );

		return [
			'type' => 'write',
			'will_execute' => false,
			'blocked' => $blocked,
			'block_reason' => $blocked ? $prepared->get_error_code() : '',
			'message' => $blocked ? $prepared->get_error_message() : 'Only the exact product regular price will change after claimed confirmation. Sale pricing, stock, content and store settings remain untouched.',
			'product_id' => $blocked ? null : $prepared['product']->get_id(),
			'product_name' => $blocked ? '' : wp_html_excerpt( sanitize_text_field( $prepared['product']->get_name() ), 200, '' ),
			'current_regular_price' => $blocked ? '' : $prepared['previous_regular_price'],
			'proposed_regular_price' => $blocked ? '' : $prepared['regular_price'],
			'current_active_price' => $blocked ? '' : $prepared['active_price'],
			'currency' => $blocked ? '' : $prepared['currency'],
			'expected_version' => $blocked ? '' : $params['expected_version'],
			'current_version' => $blocked ? '' : YoRo_Commerce_Discovery_Service::product_version( $prepared['product'] ),
		];
	}

	public static function update_product_pricing( $params ) {
		$prepared = self::prepare_product_pricing_update( $params );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$product = $prepared['product'];
		try {
			$product->set_regular_price( $prepared['regular_price'] );
			if ( ! $product->save() ) {
				return new WP_Error( 'product_pricing_reconciliation_required', 'The price outcome could not be verified. Read and reconcile the product; do not retry this operation.' );
			}
			clean_post_cache( $product->get_id() );
			$current = function_exists( 'wc_get_product' ) ? wc_get_product( $product->get_id() ) : false;
			$eligibility = self::validate_product_pricing_eligibility( $current );
			if ( is_wp_error( $eligibility )
				|| self::normalize_store_regular_price( (string) $current->get_regular_price( 'edit' ) ) !== $prepared['regular_price']
				|| (string) $current->get_price( 'edit' ) !== (string) $current->get_regular_price( 'edit' ) ) {
				return new WP_Error( 'product_pricing_reconciliation_required', 'The price may have changed but its persisted state is ambiguous. Read and reconcile the product; do not retry this operation.' );
			}

			$current_version = YoRo_Commerce_Discovery_Service::product_version( $current );

			return [
				'success' => true,
				'action' => 'products.update_pricing',
				'product_id' => $current->get_id(),
				'previous_regular_price' => $prepared['previous_regular_price'],
				'regular_price' => (string) $current->get_regular_price( 'edit' ),
				'active_price' => (string) $current->get_price( 'edit' ),
				'currency' => $prepared['currency'],
				'current_version' => $current_version,
				'message' => 'Product regular price updated.',
				'summary' => 'Product regular price updated.',
				'read_only' => false,
				'undoable' => false,
				'undo_plan' => [ [
					'action' => 'products.restore_pricing_state',
					'params' => [
						'product_id' => $current->get_id(),
						'expected_version' => $current_version,
						'previous_regular_price' => $prepared['previous_regular_price'],
					],
				] ],
				'result_type' => 'generic',
				'display_type' => 'generic',
			];
		} catch ( Throwable $exception ) {
			return new WP_Error( 'product_pricing_reconciliation_required', 'The price outcome could not be verified. Read and reconcile the product; do not retry this operation.' );
		}
	}

	public static function prepare_product_stock_adjustment( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Stock adjustment requires manage_woocommerce.' ); }
		$params = is_array( $params ) ? $params : [];
		$unknown = array_diff( array_keys( $params ), [ 'product_id', 'stock_delta', 'expected_version', '_yoro_confirmed', '_yoro_preview' ] );
		if ( $unknown || ! is_int( $params['product_id'] ?? null ) || $params['product_id'] <= 0
			|| ! is_int( $params['stock_delta'] ?? null ) || 0 === $params['stock_delta'] || abs( $params['stock_delta'] ) > 1000000
			|| ! is_string( $params['expected_version'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $params['expected_version'] ) ) {
			return new WP_Error( 'invalid_stock_adjustment', 'Use one product ID, a non-zero bounded integer delta and its current version.' );
		}
		if ( ! function_exists( 'wc_update_product_stock' ) || ! self::is_stock_management_enabled() ) {
			return new WP_Error( 'stock_management_disabled', 'WooCommerce stock management must be enabled.' );
		}
		// Discard object/meta caches so eligibility and version use the current owner state.
		clean_post_cache( $params['product_id'] );
		$product = wc_get_product( $params['product_id'] );
		if ( ! $product instanceof WC_Product || ! in_array( $product->get_type(), [ 'simple', 'variation' ], true )
			|| true !== $product->get_manage_stock( 'edit' ) || ! $product->managing_stock()
			|| $product->get_stock_managed_by_id() !== $product->get_id() ) {
			return new WP_Error( 'stock_adjustment_target_not_supported', 'Only a simple product or exact variation owning its managed stock is supported.' );
		}
		$version = self::validate_product_version( $product, $params );
		if ( is_wp_error( $version ) ) { return $version; }
		$quantity = $product->get_stock_quantity( 'edit' );
		if ( ! is_numeric( $quantity ) || ! is_finite( (float) $quantity ) || (float) (int) $quantity !== (float) $quantity || $quantity < 0 || $quantity > 1000000000
			|| $quantity + $params['stock_delta'] < 0 || $quantity + $params['stock_delta'] > 1000000000 ) {
			return new WP_Error( 'stock_adjustment_quantity_not_supported', 'The current and resulting quantities must be bounded non-negative integers.' );
		}
		return [ 'product' => $product, 'quantity' => (int) $quantity, 'delta' => $params['stock_delta'], 'stock_status' => $product->get_stock_status() ];
	}

	public static function preview_adjust_product_stock( $params ) {
		$prepared = self::prepare_product_stock_adjustment( $params );
		$blocked = is_wp_error( $prepared );
		return [ 'type' => 'write', 'will_execute' => false, 'blocked' => $blocked,
			'block_reason' => $blocked ? $prepared->get_error_code() : '',
			'message' => $blocked ? $prepared->get_error_message() : 'This relative stock adjustment changes only the exact product quantity after claimed confirmation; it does not change price, content, orders, customers or store settings.',
			'product_id' => $blocked ? null : $prepared['product']->get_id(),
			'product_name' => $blocked ? '' : wp_html_excerpt( sanitize_text_field( $prepared['product']->get_name() ), 200, '' ),
			'stock_delta' => $blocked ? null : $prepared['delta'],
			'expected_version' => $blocked ? '' : $params['expected_version'],
			'current_version' => $blocked ? '' : YoRo_Commerce_Discovery_Service::product_version( $prepared['product'] ),
			'current_stock_quantity' => $blocked ? null : $prepared['quantity'],
			'target_stock_quantity' => $blocked ? null : $prepared['quantity'] + $prepared['delta'],
		];
	}

	public static function adjust_product_stock( $params ) {
		$prepared = self::prepare_product_stock_adjustment( $params );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$product = $prepared['product'];
		$expected = $prepared['quantity'] + $prepared['delta'];
		try {
			// Owner arithmetic, never absolute read-compute-set. This is not compare-and-swap.
			$reported = wc_update_product_stock( $product, abs( $prepared['delta'] ), $prepared['delta'] > 0 ? 'increase' : 'decrease' );
			clean_post_cache( $product->get_id() );
			$current = wc_get_product( $product->get_id() );
			if ( ! $current instanceof WC_Product || ! in_array( $current->get_type(), [ 'simple', 'variation' ], true )
				|| ! is_numeric( $current->get_stock_quantity( 'edit' ) ) || ! is_finite( (float) $current->get_stock_quantity( 'edit' ) )
				|| ! is_numeric( $reported ) || (float) $reported !== (float) $expected
				|| (float) $current->get_stock_quantity( 'edit' ) !== (float) $expected || true !== $current->get_manage_stock( 'edit' )
				|| $current->get_stock_managed_by_id() !== $product->get_id() || ! self::is_stock_management_enabled() ) {
				return new WP_Error( 'stock_adjustment_reconciliation_required', 'The adjustment may have executed concurrently. Read and reconcile stock; do not retry this operation.' );
			}
			return [ 'success' => true, 'action' => 'products.adjust_stock', 'product_id' => $current->get_id(),
				'previous_stock_quantity' => $prepared['quantity'], 'stock_delta' => $prepared['delta'], 'stock_quantity' => $expected,
				'stock_status' => $current->get_stock_status(), 'manage_stock' => true,
				'current_version' => YoRo_Commerce_Discovery_Service::product_version( $current ),
				'message' => 'Product stock adjusted.', 'summary' => 'Product stock adjusted.', 'read_only' => false,
				'undoable' => false, 'undo_plan' => [ [ 'action' => 'products.restore_stock_state', 'params' => [
					'product_id' => $current->get_id(), 'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $current ),
					'manage_stock' => true, 'stock_quantity' => $prepared['quantity'], 'stock_status' => $prepared['stock_status'],
				] ] ], 'result_type' => 'generic', 'display_type' => 'generic' ];
		} catch ( Throwable $exception ) {
			return new WP_Error( 'stock_adjustment_reconciliation_required', 'The stock outcome could not be verified. Read and reconcile stock; do not retry this operation.' );
		}
	}



	public static function update_product_stock( $params ) {
		$prepared = self::prepare_product_stock_write( $params );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$product = $prepared['product'];
		$stock_quantity = $prepared['stock_quantity'];

		try {
			if ( method_exists( $product, 'set_manage_stock' ) ) {
				$product->set_manage_stock( true );
			}

			if ( method_exists( $product, 'set_stock_quantity' ) ) {
				$product->set_stock_quantity( $stock_quantity );
			}

			if ( method_exists( $product, 'set_stock_status' ) ) {
				$product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
			}

			$product->save();
		} catch ( Exception $exception ) {
			return new WP_Error( 'stock_update_failed', $exception->getMessage() );
		}

		$product = self::reload_product_after_inventory_write( $product );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		return self::build_product_stock_write_result( $prepared, $product );
	}

	public static function prepare_product_stock_write( $params, $constraints = [] ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$params = is_array( $params ) ? $params : [];
		$constraints = is_array( $constraints ) ? $constraints : [];

		if ( ! self::is_stock_management_enabled() ) {
			return new WP_Error(
				'stock_management_disabled',
				__( 'Stock management is disabled for the store. Enable WooCommerce stock management before updating product stock quantities.', 'yoro-ai-core' )
			);
		}

		if ( ! empty( $constraints['explicit_product_id_only'] ) && ! self::has_one_explicit_product_id_only( $params ) ) {
			return new WP_Error( 'native_product_target_not_covered', __( 'The native product capability requires one explicit product ID.', 'yoro-ai-core' ) );
		}

		$product_target = self::resolve_product_target( $params );
		$product_id     = absint( $product_target['product_id'] ?? 0 );
		$product        = $product_target['product'] ?? false;
		$has_quantity = array_key_exists( 'stock_quantity', is_array( $params ) ? $params : [] ) && is_numeric( $params['stock_quantity'] );
		$stock_quantity = $has_quantity ? max( 0, (int) $params['stock_quantity'] ) : null;

		if ( ! empty( $constraints['canonical_nonnegative_integer_quantity'] ) && ( ! isset( $params['stock_quantity'] ) || ! is_int( $params['stock_quantity'] ) || $params['stock_quantity'] < 0 ) ) {
			return new WP_Error( 'native_stock_quantity_not_covered', __( 'The native product capability requires a non-negative integer stock quantity.', 'yoro-ai-core' ) );
		}

		if ( '' === ( $product_target['target_type'] ?? '' ) || null === $stock_quantity ) {
			return new WP_Error( 'invalid_params', __( 'Please choose a product and the stock quantity to set.', 'yoro-ai-core' ) );
		}

		if ( ! $product ) {
			return new WP_Error( 'product_not_found', __( 'I could not find that product.', 'yoro-ai-core' ) );
		}

		$version_validation = self::validate_product_version( $product, $params );

		if ( is_wp_error( $version_validation ) ) {
			return $version_validation;
		}

		if ( self::variable_parent_requires_explicit_stock_quantity_scope( $product ) ) {
			return new WP_Error(
				'variable_parent_requires_variation_or_shared_stock',
				self::variable_parent_stock_quantity_message()
			);
		}

		$product_type_alias = self::supported_simple_product_alias( $product );
		$allowed_aliases = isset( $constraints['allowed_aliases'] ) && is_array( $constraints['allowed_aliases'] )
			? array_values( array_unique( array_map( 'sanitize_key', $constraints['allowed_aliases'] ) ) )
			: [];

		if ( ! empty( $constraints['supported_simple_only'] ) && '' === $product_type_alias ) {
			return new WP_Error( 'native_product_type_not_covered', __( 'The native product capability does not cover this product type.', 'yoro-ai-core' ) );
		}

		if ( ! empty( $allowed_aliases ) && ! in_array( $product_type_alias, $allowed_aliases, true ) ) {
			return new WP_Error( 'native_product_type_not_covered', __( 'The native product capability does not cover this product type.', 'yoro-ai-core' ) );
		}

		$previous_product_meta = self::product_inventory_target_meta( $product, $product_target );
		$previous_manage_stock = method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : null;
		$previous_stock_quantity = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
		$previous_stock_status = method_exists( $product, 'get_stock_status' ) ? sanitize_key( $product->get_stock_status() ) : '';
		$product_name = method_exists( $product, 'get_name' ) ? sanitize_text_field( $product->get_name() ) : sprintf( 'Product #%d', $product_id );

		return [
			'product_target'          => $product_target,
			'product_id'              => $product_id,
			'product'                 => $product,
			'product_type_alias'      => $product_type_alias,
			'product_type'            => sanitize_key( $product->get_type() ),
			'product_virtual'         => (bool) $product->get_virtual(),
			'product_downloadable'    => (bool) $product->get_downloadable(),
			'stock_quantity'          => $stock_quantity,
			'previous_product_meta'   => $previous_product_meta,
			'previous_manage_stock'   => $previous_manage_stock,
			'previous_stock_quantity' => $previous_stock_quantity,
			'previous_stock_status'   => $previous_stock_status,
			'product_name'            => $product_name,
		];
	}

	public static function build_product_stock_write_result( array $prepared, WC_Product $product ) {
		$product_target = $prepared['product_target'];
		$product_id = $prepared['product_id'];
		$stock_quantity = $prepared['stock_quantity'];
		$previous_product_meta = $prepared['previous_product_meta'];
		$previous_manage_stock = $prepared['previous_manage_stock'];
		$previous_stock_quantity = $prepared['previous_stock_quantity'];
		$previous_stock_status = $prepared['previous_stock_status'];
		$product_name = $prepared['product_name'];

		$current_stock_status = method_exists( $product, 'get_stock_status' ) ? sanitize_key( $product->get_stock_status() ) : '';
		$current_version = YoRo_Commerce_Discovery_Service::product_version( $product );
		$previous_label = is_numeric( $previous_stock_quantity ) ? (string) (int) $previous_stock_quantity : __( 'untracked', 'yoro-ai-core' );
		$enabled_stock_management = false === (bool) $previous_manage_stock;
		$current_product_meta = self::product_inventory_target_meta( $product, $product_target );

		if ( 'parent_shared_stock' === ( $current_product_meta['stock_target_kind'] ?? '' ) && self::product_is_variable_parent( $product ) ) {
			$summary = sprintf(
				/* translators: 1: product name, 2: product id, 3: previous stock, 4: new stock */
				__( 'Updated shared parent stock for %1$s (#%2$d) from %3$s to %4$d.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				$previous_label,
				$stock_quantity
			);
		} elseif ( $enabled_stock_management && self::product_is_variation( $product ) ) {
			$summary = sprintf(
				/* translators: 1: product name, 2: variation id, 3: new stock quantity */
				__( 'Enabled stock management for variation %1$s (#%2$d) and set stock to %3$d.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				$stock_quantity
			);
		} elseif ( $enabled_stock_management ) {
			$summary = sprintf(
				/* translators: 1: product name, 2: product id, 3: new stock quantity */
				__( 'Enabled stock management for %1$s (#%2$d) and set stock to %3$d.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				$stock_quantity
			);
		} elseif ( self::product_is_variation( $product ) ) {
			$summary = sprintf(
				/* translators: 1: product name, 2: variation id, 3: previous stock, 4: new stock */
				__( 'Updated variation stock for %1$s (#%2$d) from %3$s to %4$d.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				$previous_label,
				$stock_quantity
			);
		} else {
			$summary = sprintf(
				/* translators: 1: product name, 2: product id, 3: previous stock, 4: new stock */
				__( 'Updated stock for %1$s (#%2$d) from %3$s to %4$d.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				$previous_label,
				$stock_quantity
			);
		}

		$result_product = self::product_inventory_result_item(
			$product,
			$product_target,
			[
				'current_stock_quantity'        => is_numeric( $previous_stock_quantity ) ? (int) $previous_stock_quantity : null,
				'target_stock_quantity'         => $stock_quantity,
				'current_stock_status'          => $previous_stock_status,
				'target_stock_status'           => $current_stock_status,
				'previous_manage_stock'         => $previous_manage_stock,
				'current_manage_stock'          => method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : true,
				'previous_manages_stock_at'     => $previous_product_meta['manages_stock_at'] ?? '',
				'previous_stock_target_kind'    => $previous_product_meta['stock_target_kind'] ?? '',
				'will_enable_stock_management'  => $enabled_stock_management,
			]
		);

		return array_merge(
			[
			'success'                 => true,
			'action'                  => 'products.update_stock',
			'summary'                 => $summary,
			'message'                 => $summary,
			'product_id'              => $product_id,
			'product_name'            => $product_name,
			'product_sku'             => method_exists( $product, 'get_sku' ) ? sanitize_text_field( $product->get_sku() ) : ( $product_target['product_sku'] ?? '' ),
			'global_unique_id'        => method_exists( $product, 'get_global_unique_id' ) ? sanitize_text_field( $product->get_global_unique_id() ) : ( $product_target['global_unique_id'] ?? '' ),
			'product_target_type'     => $product_target['target_type'] ?? '',
			'product_target_value'    => $product_target['target_value'] ?? '',
			'previous_stock_quantity' => is_numeric( $previous_stock_quantity ) ? (int) $previous_stock_quantity : null,
			'stock_quantity'          => $stock_quantity,
			'previous_stock_status'   => $previous_stock_status,
			'stock_status'            => $current_stock_status,
			'previous_manage_stock'   => $previous_manage_stock,
			'manage_stock'            => method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : true,
			'enabled_stock_management' => $enabled_stock_management,
			'processed'               => [ $product_id ],
			'skipped'                 => [],
			'errors'                  => [],
			'products'                => [ $result_product ],
			'count'                   => 1,
			'read_only'               => false,
			'undoable'                => false,
			'undo_plan'               => [
				[
					'action' => 'products.restore_stock_state',
					'params' => [
						'product_id'      => $product_id,
						'expected_version' => $current_version,
						'manage_stock'     => $previous_manage_stock,
						'stock_quantity'   => is_numeric( $previous_stock_quantity ) ? (int) $previous_stock_quantity : null,
						'stock_status'     => $previous_stock_status,
					],
				],
			],
			'current_version'         => $current_version,
			'result_type'             => 'generic',
			'display_type'            => 'generic',
			'meta'                    => [
				'changes_store'             => true,
				'product_id'                => $product_id,
				'product_sku'               => method_exists( $product, 'get_sku' ) ? sanitize_text_field( $product->get_sku() ) : ( $product_target['product_sku'] ?? '' ),
				'global_unique_id'          => method_exists( $product, 'get_global_unique_id' ) ? sanitize_text_field( $product->get_global_unique_id() ) : ( $product_target['global_unique_id'] ?? '' ),
				'product_target_type'       => $product_target['target_type'] ?? '',
				'product_target_value'      => $product_target['target_value'] ?? '',
				'stock_management_enabled'  => true,
				'enabled_stock_management'  => $enabled_stock_management,
				'previous_manage_stock'     => $previous_manage_stock,
				'manage_stock'              => method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : true,
			] + $current_product_meta,
			],
			$current_product_meta
		);
	}

	public static function update_product_stock_status( $params ) {
		$prepared = self::prepare_product_stock_status_write( $params );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$product = $prepared['product'];
		$stock_status = $prepared['stock_status'];

		try {
			if ( method_exists( $product, 'set_stock_status' ) ) {
				$product->set_stock_status( $stock_status );
			}

			$product->save();
		} catch ( Exception $exception ) {
			return new WP_Error( 'stock_status_update_failed', $exception->getMessage() );
		}

		$product = self::reload_product_after_inventory_write( $product );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		return self::build_product_stock_status_write_result( $prepared, $product );
	}

	public static function prepare_product_stock_status_write( $params, $constraints = [] ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$params = is_array( $params ) ? $params : [];
		$constraints = is_array( $constraints ) ? $constraints : [];

		if ( ! empty( $constraints['explicit_product_id_only'] ) && ! self::has_one_explicit_product_id_only( $params ) ) {
			return new WP_Error( 'native_product_target_not_covered', __( 'The native product capability requires one explicit product ID.', 'yoro-ai-core' ) );
		}

		$product_target = self::resolve_product_target( $params );
		$product_id     = absint( $product_target['product_id'] ?? 0 );
		$product        = $product_target['product'] ?? false;
		$stock_status   = YoRo_Product_Helper::normalize_stock_status_slug( $params['stock_status'] ?? '' );
		$options        = YoRo_Product_Helper::get_stock_status_options();

		if ( '' === ( $product_target['target_type'] ?? '' ) || '' === $stock_status || ! isset( $options[ $stock_status ] ) ) {
			return new WP_Error( 'invalid_params', __( 'Please choose a product and a valid stock status.', 'yoro-ai-core' ) );
		}

		if ( ! $product ) {
			return new WP_Error( 'product_not_found', __( 'I could not find that product.', 'yoro-ai-core' ) );
		}

		$version_validation = self::validate_product_version( $product, $params );

		if ( is_wp_error( $version_validation ) ) {
			return $version_validation;
		}

		$product_meta            = self::product_inventory_target_meta( $product, $product_target );
		$previous_manage_stock   = method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : null;
		$previous_stock_quantity = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
		$managing_stock_quantity = self::product_manages_stock_quantity( $product );

		if ( $managing_stock_quantity ) {
			return new WP_Error(
				'stock_status_managed_by_quantity',
				__( 'Stock status is controlled by stock quantity for products with stock management enabled. Update the stock quantity instead.', 'yoro-ai-core' )
			);
		}

		if ( self::variable_parent_requires_variation_for_stock_status( $product ) ) {
			return new WP_Error(
				'variable_parent_requires_variation',
				self::variable_parent_stock_status_message()
			);
		}

		$product_type_alias = self::supported_simple_product_alias( $product );
		$allowed_aliases = isset( $constraints['allowed_aliases'] ) && is_array( $constraints['allowed_aliases'] )
			? array_values( array_unique( array_map( 'sanitize_key', $constraints['allowed_aliases'] ) ) )
			: [];
		$allowed_statuses = isset( $constraints['allowed_statuses'] ) && is_array( $constraints['allowed_statuses'] )
			? array_values( array_unique( array_map( 'sanitize_key', $constraints['allowed_statuses'] ) ) )
			: [];

		if ( ! empty( $constraints['supported_simple_only'] ) && '' === $product_type_alias ) {
			return new WP_Error( 'native_product_type_not_covered', __( 'The native product capability does not cover this product type.', 'yoro-ai-core' ) );
		}

		if ( ! empty( $allowed_aliases ) && ! in_array( $product_type_alias, $allowed_aliases, true ) ) {
			return new WP_Error( 'native_product_type_not_covered', __( 'The native product capability does not cover this product type.', 'yoro-ai-core' ) );
		}

		if ( ! empty( $allowed_statuses ) && ! in_array( $stock_status, $allowed_statuses, true ) ) {
			return new WP_Error( 'native_product_status_not_covered', __( 'The native product capability does not cover this stock status.', 'yoro-ai-core' ) );
		}

		$previous_stock_status = method_exists( $product, 'get_stock_status' ) ? YoRo_Product_Helper::normalize_stock_status_slug( $product->get_stock_status() ) : '';
		$product_name          = method_exists( $product, 'get_name' ) ? sanitize_text_field( $product->get_name() ) : sprintf( 'Product #%d', $product_id );
		$previous_label        = YoRo_Product_Helper::get_stock_status_label( $previous_stock_status );
		return [
			'product_target'       => $product_target,
			'product_id'           => $product_id,
			'product'              => $product,
			'product_type_alias'   => $product_type_alias,
			'product_type'         => sanitize_key( $product->get_type() ),
			'product_virtual'      => (bool) $product->get_virtual(),
			'product_downloadable' => (bool) $product->get_downloadable(),
			'stock_status'         => $stock_status,
			'product_meta'         => $product_meta,
			'previous_manage_stock' => $previous_manage_stock,
			'previous_stock_quantity' => $previous_stock_quantity,
			'previous_stock_status' => $previous_stock_status,
			'product_name'         => $product_name,
			'previous_label'       => $previous_label,
		];
	}

	public static function build_product_stock_status_write_result( array $prepared, WC_Product $product ) {
		$product_target = $prepared['product_target'];
		$product_id = $prepared['product_id'];
		$stock_status = $prepared['stock_status'];
		$product_meta = $prepared['product_meta'];
		$previous_manage_stock = $prepared['previous_manage_stock'];
		$previous_stock_status = $prepared['previous_stock_status'];
		$product_name = $prepared['product_name'];
		$previous_label = $prepared['previous_label'];

		$current_stock_status = method_exists( $product, 'get_stock_status' ) ? YoRo_Product_Helper::normalize_stock_status_slug( $product->get_stock_status() ) : $stock_status;
		$current_version = YoRo_Commerce_Discovery_Service::product_version( $product );
		$summary              = sprintf(
			/* translators: 1: product name, 2: product id, 3: previous stock status, 4: new stock status */
			__( 'Updated stock status for %1$s (#%2$d) from %3$s to %4$s.', 'yoro-ai-core' ),
			$product_name,
			$product_id,
			$previous_label,
			YoRo_Product_Helper::get_stock_status_label( $current_stock_status )
		);

		$result_product = self::product_inventory_result_item(
			$product,
			$product_target,
			[
				'current_stock_status'       => $previous_stock_status,
				'current_stock_status_label' => $previous_label,
				'target_stock_status'        => $current_stock_status,
				'target_stock_status_label'  => YoRo_Product_Helper::get_stock_status_label( $current_stock_status ),
				'previous_manage_stock'      => $previous_manage_stock,
				'current_manage_stock'       => method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : false,
				'stock_status_source'        => 'manual',
			]
		);

		return array_merge(
			[
			'success'                => true,
			'action'                 => 'products.update_stock_status',
			'summary'                => $summary,
			'message'                => $summary,
			'product_id'             => $product_id,
			'product_name'           => $product_name,
			'product_sku'            => method_exists( $product, 'get_sku' ) ? sanitize_text_field( $product->get_sku() ) : ( $product_target['product_sku'] ?? '' ),
			'global_unique_id'       => method_exists( $product, 'get_global_unique_id' ) ? sanitize_text_field( $product->get_global_unique_id() ) : ( $product_target['global_unique_id'] ?? '' ),
			'product_target_type'    => $product_target['target_type'] ?? '',
			'product_target_value'   => $product_target['target_value'] ?? '',
			'previous_stock_status'  => $previous_stock_status,
			'previous_stock_status_label' => $previous_label,
			'stock_status'           => $current_stock_status,
			'stock_status_label'     => YoRo_Product_Helper::get_stock_status_label( $current_stock_status ),
			'previous_manage_stock'  => $previous_manage_stock,
			'manage_stock'           => method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : false,
			'stock_status_source'    => 'manual',
			'processed'              => [ $product_id ],
			'skipped'                => [],
			'errors'                 => [],
			'products'               => [ $result_product ],
			'count'                  => 1,
			'read_only'              => false,
			'undoable'               => false,
			'undo_plan'              => [
				[
					'action' => 'products.restore_stock_state',
					'params' => [
						'product_id'      => $product_id,
						'expected_version' => $current_version,
						'manage_stock'     => $previous_manage_stock,
						'stock_quantity'   => method_exists( $product, 'get_stock_quantity' ) && is_numeric( $product->get_stock_quantity() ) ? (int) $product->get_stock_quantity() : null,
						'stock_status'     => $previous_stock_status,
					],
				],
			],
			'current_version'        => $current_version,
			'result_type'            => 'generic',
			'display_type'           => 'generic',
			'meta'                   => [
				'changes_store'            => true,
				'product_id'               => $product_id,
				'product_sku'              => method_exists( $product, 'get_sku' ) ? sanitize_text_field( $product->get_sku() ) : ( $product_target['product_sku'] ?? '' ),
				'global_unique_id'         => method_exists( $product, 'get_global_unique_id' ) ? sanitize_text_field( $product->get_global_unique_id() ) : ( $product_target['global_unique_id'] ?? '' ),
				'product_target_type'      => $product_target['target_type'] ?? '',
				'product_target_value'     => $product_target['target_value'] ?? '',
				'previous_stock_status'    => $previous_stock_status,
				'stock_status'             => $current_stock_status,
				'previous_manage_stock'    => $previous_manage_stock,
				'manage_stock'             => method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : false,
				'stock_status_source'      => 'manual',
				'stock_management_enabled' => self::is_stock_management_enabled(),
			] + $product_meta,
			],
			$product_meta
		);
	}

	public static function preview_update_product_stock_status( $params ) {
		$product_target = self::resolve_product_target( $params );
		$product_id     = absint( $product_target['product_id'] ?? 0 );
		$product        = $product_target['product'] ?? false;
		$stock_status   = YoRo_Product_Helper::normalize_stock_status_slug( $params['stock_status'] ?? '' );
		$options        = YoRo_Product_Helper::get_stock_status_options();
		$is_valid       = '' !== ( $product_target['target_type'] ?? '' ) && '' !== $stock_status && isset( $options[ $stock_status ] );
		$product_name = $product && method_exists( $product, 'get_name' ) ? sanitize_text_field( $product->get_name() ) : '';
		$current_stock_status = $product && method_exists( $product, 'get_stock_status' ) ? YoRo_Product_Helper::normalize_stock_status_slug( $product->get_stock_status() ) : '';
		$managing_stock_quantity = $product ? self::product_manages_stock_quantity( $product ) : false;
		$variable_parent_status_blocked = $product ? self::variable_parent_requires_variation_for_stock_status( $product ) : false;
		$target_label = YoRo_Product_Helper::get_stock_status_label( $stock_status );
		$current_label = YoRo_Product_Helper::get_stock_status_label( $current_stock_status );

		if ( ! $is_valid ) {
			$message = __( 'Please choose a product and a valid stock status.', 'yoro-ai-core' );
			$block_reason = 'invalid_params';
		} elseif ( ! $product ) {
			$message = __( 'I could not find that product.', 'yoro-ai-core' );
			$block_reason = 'product_not_found';
		} elseif ( is_wp_error( self::validate_product_version( $product, $params ) ) ) {
			$message = __( 'The product changed after it was read. Refresh the product details before approving this update.', 'yoro-ai-core' );
			$block_reason = 'product_version_conflict';
		} elseif ( $managing_stock_quantity ) {
			$message = __( 'Stock status is controlled by stock quantity for products with stock management enabled. Update the stock quantity instead.', 'yoro-ai-core' );
			$block_reason = 'stock_status_managed_by_quantity';
		} elseif ( $variable_parent_status_blocked ) {
			$message = self::variable_parent_stock_status_message();
			$block_reason = 'variable_parent_requires_variation';
		} else {
			$message = sprintf(
				/* translators: 1: product name, 2: product id, 3: current stock status, 4: target stock status */
				__( 'This will set stock status for %1$s (#%2$d) from %3$s to %4$s after approval.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				$current_label,
				$target_label
			);
			$block_reason = '';
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => __( 'Update product stock status', 'yoro-ai-core' ),
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'products'      => [
				self::product_inventory_result_item(
					$product,
					$product_target,
					[
					'current_stock_status'       => $current_stock_status,
					'current_stock_status_label' => $current_label,
					'target_stock_status'        => $stock_status,
					'target_stock_status_label'  => $target_label,
					'current_manage_stock'       => $product && method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : false,
					'stock_status_source'        => $managing_stock_quantity ? 'quantity_managed' : 'manual',
					]
				),
			],
			'count'         => $product ? 1 : 0,
			'changes_store' => '' === $block_reason,
		];
	}

	public static function preview_update_product_stock( $params ) {
		$product_target = self::resolve_product_target( $params );
		$product_id     = absint( $product_target['product_id'] ?? 0 );
		$product        = $product_target['product'] ?? false;
		$stock_quantity = array_key_exists( 'stock_quantity', is_array( $params ) ? $params : [] ) && is_numeric( $params['stock_quantity'] )
			? max( 0, (int) $params['stock_quantity'] )
			: null;
		$product_name = $product && method_exists( $product, 'get_name' ) ? sanitize_text_field( $product->get_name() ) : '';
		$current_stock_quantity = $product && method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
		$current_stock_status = $product && method_exists( $product, 'get_stock_status' ) ? sanitize_key( $product->get_stock_status() ) : '';
		$managing_stock = $product && method_exists( $product, 'managing_stock' ) ? (bool) $product->managing_stock() : false;
		$stock_management_enabled = self::is_stock_management_enabled();
		$will_enable_stock_management = $stock_management_enabled && $product ? ! $managing_stock : false;
		$product_meta = self::product_inventory_target_meta( $product, $product_target );
		$variable_parent_quantity_blocked = $product ? self::variable_parent_requires_explicit_stock_quantity_scope( $product ) : false;
		$block_reason = '';

		if ( ! $stock_management_enabled ) {
			$message      = __( 'Stock management is disabled for the store. This stock quantity update cannot run until WooCommerce stock management is enabled.', 'yoro-ai-core' );
			$block_reason = 'stock_management_disabled';
		} elseif ( '' === ( $product_target['target_type'] ?? '' ) || null === $stock_quantity ) {
			$message      = __( 'Please choose a product and the stock quantity to set.', 'yoro-ai-core' );
			$block_reason = 'invalid_params';
		} elseif ( ! $product ) {
			$message      = __( 'I could not find that product.', 'yoro-ai-core' );
			$block_reason = 'product_not_found';
		} elseif ( is_wp_error( self::validate_product_version( $product, $params ) ) ) {
			$message      = __( 'The product changed after it was read. Refresh the product details before approving this update.', 'yoro-ai-core' );
			$block_reason = 'product_version_conflict';
		} elseif ( $variable_parent_quantity_blocked ) {
			$message      = self::variable_parent_stock_quantity_message();
			$block_reason = 'variable_parent_requires_variation_or_shared_stock';
		} elseif ( 'parent_shared_stock' === ( $product_meta['stock_target_kind'] ?? '' ) && self::product_is_variable_parent( $product ) ) {
			$message = sprintf(
				/* translators: 1: product name, 2: product id, 3: target stock quantity */
				__( 'This will set shared parent stock for %1$s (#%2$d) to %3$d after approval. Variations without their own stock management use this shared pool.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				(int) $stock_quantity
			);
		} elseif ( $will_enable_stock_management && self::product_is_variation( $product ) ) {
			$message = sprintf(
				/* translators: 1: product name, 2: variation id, 3: target stock quantity */
				__( 'This will enable stock management for variation %1$s (#%2$d) and set stock to %3$d after approval. This variation will use its own stock quantity after approval.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				(int) $stock_quantity
			);
		} elseif ( $will_enable_stock_management ) {
			$message = sprintf(
				/* translators: 1: product name, 2: product id, 3: target stock quantity */
				__( 'This will enable stock management for %1$s (#%2$d) and set stock to %3$d after approval.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				(int) $stock_quantity
			);
		} elseif ( self::product_is_variation( $product ) ) {
			$message = sprintf(
				/* translators: 1: product name, 2: variation id, 3: target stock quantity */
				__( 'This will set variation stock for %1$s (#%2$d) to %3$d after approval.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				(int) $stock_quantity
			);
		} else {
			$message = sprintf(
				/* translators: 1: product name, 2: product id, 3: target stock quantity */
				__( 'This will set stock for %1$s (#%2$d) to %3$d after approval.', 'yoro-ai-core' ),
				$product_name,
				$product_id,
				(int) $stock_quantity
			);
		}

		return [
			'type'                     => 'write',
			'will_execute'             => false,
			'action'                   => __( 'Update product stock', 'yoro-ai-core' ),
			'summary'                  => $message,
			'message'                  => $message,
			'stock_management_enabled' => $stock_management_enabled,
			'blocked'                  => '' !== $block_reason,
			'block_reason'             => $block_reason,
			'products'                 => [
				self::product_inventory_result_item(
					$product,
					$product_target,
					[
					'current_stock_quantity' => is_numeric( $current_stock_quantity ) ? (int) $current_stock_quantity : null,
					'target_stock_quantity'  => $stock_quantity,
					'current_stock_status'   => $current_stock_status,
					'current_manage_stock'   => $managing_stock,
					'will_enable_stock_management' => $will_enable_stock_management,
					]
				),
			],
			'count'                    => $product ? 1 : 0,
			'changes_store'            => '' === $block_reason,
		];
	}

	public static function product_inventory_target_meta( $product, $product_target = [] ) {
		$defaults = [
			'product_type'                         => '',
			'product_type_label'                   => '',
			'parent_id'                            => null,
			'variation_id'                         => null,
			'variation_attributes'                 => [],
			'variation_label'                      => '',
			'variation_targets'                    => [],
			'variation_targets_truncated'          => false,
			'has_variations'                       => false,
			'variation_count'                      => 0,
			'children_managing_stock_count'        => 0,
			'children_inheriting_parent_stock_count' => 0,
			'children_manual_status_count'         => 0,
			'manages_stock_at'                     => 'none',
			'stock_target_kind'                    => 'manual_status',
			'stock_scope_label'                    => __( 'Manual stock status', 'yoro-ai-core' ),
		];

		if ( ! $product || ! method_exists( $product, 'get_type' ) ) {
			return $defaults;
		}

		$product_type         = sanitize_key( $product->get_type() );
		$parent_id            = self::product_parent_id( $product );
		$variation_attributes = self::product_variation_attributes( $product );
		$variation_label      = self::format_variation_attributes( $variation_attributes );
		$child_stock_summary  = self::variable_child_stock_summary( $product );
		$variation_targets    = self::product_variation_targets( $product );
		$variation_count      = absint( $child_stock_summary['total'] ?? 0 );
		$manages_stock_at     = self::product_manages_stock_at( $product, $product_type, $parent_id, $child_stock_summary );
		$stock_target_kind    = self::product_stock_target_kind( $product, $product_type, $manages_stock_at, $child_stock_summary );

		return [
			'product_type'                         => $product_type,
			'product_type_label'                   => class_exists( 'YoRo_Product_Helper' ) ? YoRo_Product_Helper::get_product_type_label( $product_type ) : self::humanize_inventory_key( $product_type ),
			'parent_id'                            => $parent_id > 0 ? $parent_id : null,
			'variation_id'                         => self::product_is_variation( $product ) && method_exists( $product, 'get_id' ) ? absint( $product->get_id() ) : null,
			'variation_attributes'                 => $variation_attributes,
			'variation_label'                      => $variation_label,
			'variation_targets'                    => $variation_targets['items'],
			'variation_targets_truncated'          => (bool) $variation_targets['truncated'],
			'has_variations'                       => $variation_count > 0,
			'variation_count'                      => $variation_count,
			'children_managing_stock_count'        => absint( $child_stock_summary['managing_stock'] ?? 0 ),
			'children_inheriting_parent_stock_count' => absint( $child_stock_summary['inheriting_parent_stock'] ?? 0 ),
			'children_manual_status_count'         => absint( $child_stock_summary['manual_status'] ?? 0 ),
			'manages_stock_at'                     => $manages_stock_at,
			'stock_target_kind'                    => $stock_target_kind,
			'stock_scope_label'                    => self::product_stock_scope_label( $stock_target_kind ),
		];
	}

	private static function product_inventory_result_item( $product, $product_target, $extra = [] ) {
		$product_id       = $product && method_exists( $product, 'get_id' ) ? absint( $product->get_id() ) : absint( $product_target['product_id'] ?? 0 );
		$product_name     = $product && method_exists( $product, 'get_name' ) ? sanitize_text_field( $product->get_name() ) : '';
		$product_sku      = $product && method_exists( $product, 'get_sku' ) ? sanitize_text_field( $product->get_sku() ) : ( $product_target['product_sku'] ?? '' );
		$global_unique_id = $product && method_exists( $product, 'get_global_unique_id' ) ? sanitize_text_field( $product->get_global_unique_id() ) : ( $product_target['global_unique_id'] ?? '' );

		return array_merge(
			[
				'id'                   => $product_id,
				'product_id'           => $product_id,
				'exists'               => (bool) $product,
				'name'                 => $product_name,
				'sku'                  => $product_sku,
				'product_sku'          => $product_sku,
				'global_unique_id'     => $global_unique_id,
				'product_target_type'  => $product_target['target_type'] ?? '',
				'product_target_value' => $product_target['target_value'] ?? '',
			],
			self::product_inventory_target_meta( $product, $product_target ),
			is_array( $extra ) ? $extra : []
		);
	}

	public static function restore_product_stock_state( $params ) {
		$product_id = absint( $params['product_id'] ?? 0 );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'product_not_found', __( 'I could not find that product.', 'yoro-ai-core' ) );
		}

		$version_validation = self::validate_product_version( $product, $params );

		if ( is_wp_error( $version_validation ) ) {
			return $version_validation;
		}

		try {
			$product->set_manage_stock( $params['manage_stock'] );
			$product->set_stock_quantity( null === ( $params['stock_quantity'] ?? null ) ? null : (int) $params['stock_quantity'] );
			$product->set_stock_status( sanitize_key( $params['stock_status'] ?? '' ) );
			$product->save();
		} catch ( Exception $exception ) {
			return new WP_Error( 'stock_restore_failed', $exception->getMessage() );
		}

		$product = self::reload_product_after_inventory_write( $product );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		return [
			'success'      => true,
			'action'       => 'products.restore_stock_state',
			'summary'      => sprintf( __( 'Restored stock state for product #%d.', 'yoro-ai-core' ), $product_id ),
			'processed'    => [ $product_id ],
			'count'        => 1,
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'current_version' => YoRo_Commerce_Discovery_Service::product_version( $product ),
		];
	}

	public static function restore_product_pricing_state( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'yoro_permission_denied', 'Pricing recovery requires manage_woocommerce.' );
		}

		$params = is_array( $params ) ? $params : [];
		$unknown = array_diff( array_keys( $params ), [ 'product_id', 'expected_version', 'previous_regular_price', '_yoro_confirmed', '_yoro_preview' ] );
		if ( $unknown || ! is_int( $params['product_id'] ?? null ) || $params['product_id'] <= 0
			|| ! is_string( $params['expected_version'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $params['expected_version'] )
			|| ! is_string( $params['previous_regular_price'] ?? null ) || ! self::is_bounded_regular_price( $params['previous_regular_price'], true ) ) {
			return new WP_Error( 'invalid_product_pricing_restore', 'Pricing recovery requires one product, its current version and the captured previous regular price.' );
		}

		clean_post_cache( $params['product_id'] );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $params['product_id'] ) : false;
		$eligibility = self::validate_product_pricing_eligibility( $product );
		if ( is_wp_error( $eligibility ) ) {
			return $eligibility;
		}
		$version = self::validate_product_version( $product, $params );
		if ( is_wp_error( $version ) ) {
			return $version;
		}

		$previous = '' === $params['previous_regular_price'] ? '' : self::normalize_store_regular_price( $params['previous_regular_price'] );
		if ( is_wp_error( $previous ) ) {
			return $previous;
		}

		try {
			$product->set_regular_price( $previous );
			if ( ! $product->save() ) {
				return new WP_Error( 'product_pricing_restore_reconciliation_required', 'The recovered price could not be verified. Read and reconcile the product.' );
			}
			clean_post_cache( $product->get_id() );
			$current = function_exists( 'wc_get_product' ) ? wc_get_product( $product->get_id() ) : false;
			$eligibility = self::validate_product_pricing_eligibility( $current );
			if ( is_wp_error( $eligibility ) || (string) $current->get_regular_price( 'edit' ) !== $previous
				|| (string) $current->get_price( 'edit' ) !== $previous ) {
				return new WP_Error( 'product_pricing_restore_reconciliation_required', 'The recovered price is ambiguous. Read and reconcile the product.' );
			}

			return [
				'success' => true,
				'action' => 'products.restore_pricing_state',
				'summary' => sprintf( __( 'Restored regular price for product #%d.', 'yoro-ai-core' ), $current->get_id() ),
				'processed' => [ $current->get_id() ],
				'count' => 1,
				'read_only' => false,
				'undoable' => false,
				'undo_plan' => [],
				'current_version' => YoRo_Commerce_Discovery_Service::product_version( $current ),
			];
		} catch ( Throwable $exception ) {
			return new WP_Error( 'product_pricing_restore_reconciliation_required', 'The recovered price could not be verified. Read and reconcile the product.' );
		}
	}

	private static function reload_product_after_inventory_write( WC_Product $product ) {
		$product_id = absint( $product->get_id() );
		$persisted_product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

		if ( ! $persisted_product instanceof WC_Product ) {
			return new WP_Error(
				'product_refresh_failed',
				__( 'The product was saved but its persisted state could not be read.', 'yoro-ai-core' )
			);
		}

		return $persisted_product;
	}

	private static function validate_product_version( WC_Product $product, $params ) {
		$expected_version = sanitize_text_field( is_array( $params ) ? ( $params['expected_version'] ?? '' ) : '' );
		$current_version = YoRo_Commerce_Discovery_Service::product_version( $product );

		if ( '' === $expected_version || ! hash_equals( $current_version, $expected_version ) ) {
			return new WP_Error(
				'product_version_conflict',
				__( 'The product changed after it was read. Refresh the product details before trying again.', 'yoro-ai-core' ),
				[ 'current_version' => $current_version ]
			);
		}

		return true;
	}

	private static function validate_product_pricing_eligibility( $product ) {
		if ( ! $product instanceof WC_Product || ! in_array( $product->get_type(), [ 'simple', 'variation' ], true ) ) {
			return new WP_Error( 'product_pricing_target_not_supported', 'Only one exact simple product or exact variation is supported.' );
		}

		$regular_price = (string) $product->get_regular_price( 'edit' );
		$active_price = (string) $product->get_price( 'edit' );
		$sale_price = (string) $product->get_sale_price( 'edit' );
		if ( ! self::is_bounded_regular_price( $regular_price, true ) ) {
			return new WP_Error( 'product_pricing_state_not_supported', 'The current regular price is outside the supported bounded decimal state.' );
		}
		if ( '' !== $sale_price || null !== $product->get_date_on_sale_from( 'edit' ) || null !== $product->get_date_on_sale_to( 'edit' ) ) {
			return new WP_Error( 'product_pricing_sale_state_not_supported', 'Products with a sale price or sale schedule are not supported by this regular-price action.' );
		}
		if ( ( '' === $regular_price && '' !== $active_price ) || ( '' !== $regular_price && $active_price !== $regular_price ) ) {
			return new WP_Error( 'product_pricing_active_price_not_supported', 'The active price must match the regular price before this action can run.' );
		}

		return true;
	}

	private static function normalize_store_regular_price( $regular_price ) {
		if ( ! is_string( $regular_price ) || ! self::is_bounded_regular_price( $regular_price, false ) || ! function_exists( 'wc_format_decimal' ) ) {
			return new WP_Error( 'invalid_product_regular_price', 'The regular price must be a bounded non-negative decimal string.' );
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? max( 0, absint( wc_get_price_decimals() ) ) : 2;
		$normalized = (string) wc_format_decimal( $regular_price, $decimals );

		return '' !== $normalized && self::is_bounded_regular_price( $normalized, false )
			? $normalized
			: new WP_Error( 'invalid_product_regular_price', 'The regular price cannot be represented at the current store precision.' );
	}

	private static function is_bounded_regular_price( $regular_price, $allow_empty ) {
		if ( ! is_string( $regular_price ) ) {
			return false;
		}
		if ( $allow_empty && '' === $regular_price ) {
			return true;
		}

		return 1 === preg_match( '/^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?$/D', $regular_price );
	}

	private static function variable_parent_requires_explicit_stock_quantity_scope( $product ) {
		return self::product_is_variable_parent( $product )
			&& self::product_variation_count( $product ) > 0
			&& ! self::product_directly_manages_stock( $product );
	}

	private static function variable_parent_requires_variation_for_stock_status( $product ) {
		return self::product_is_variable_parent( $product )
			&& self::product_variation_count( $product ) > 0
			&& ! self::product_directly_manages_stock( $product );
	}

	private static function variable_parent_stock_quantity_message() {
		return __( 'This is a variable product with variations, but parent-level stock management is not currently enabled. Choose a specific variation, or use an explicit shared parent-stock workflow before changing the parent stock quantity.', 'yoro-ai-core' );
	}

	private static function variable_parent_stock_status_message() {
		return __( 'This is a variable product. Choose a specific variation before changing stock status, or update parent stock quantity when parent stock management is enabled.', 'yoro-ai-core' );
	}

	private static function product_is_variable_parent( $product ) {
		if ( ! $product || ! method_exists( $product, 'get_type' ) ) {
			return false;
		}

		$product_type = sanitize_key( $product->get_type() );

		return 'variable' === $product_type || false !== strpos( $product_type, 'variable' );
	}

	private static function product_is_variation( $product ) {
		if ( ! $product || ! method_exists( $product, 'get_type' ) ) {
			return false;
		}

		$product_type = sanitize_key( $product->get_type() );

		return 'variation' === $product_type
			|| false !== strpos( $product_type, 'variation' )
			|| ( method_exists( $product, 'get_parent_id' ) && absint( $product->get_parent_id() ) > 0 );
	}

	private static function product_parent_id( $product ) {
		if ( ! self::product_is_variation( $product ) || ! method_exists( $product, 'get_parent_id' ) ) {
			return 0;
		}

		return absint( $product->get_parent_id() );
	}

	private static function product_variation_count( $product ) {
		if ( ! self::product_is_variable_parent( $product ) || ! method_exists( $product, 'get_children' ) ) {
			return 0;
		}

		return count( (array) $product->get_children() );
	}

	private static function variable_child_stock_summary( $product ) {
		$summary = [
			'total'                   => 0,
			'managing_stock'          => 0,
			'inheriting_parent_stock' => 0,
			'manual_status'           => 0,
		];

		if ( ! self::product_is_variable_parent( $product ) || ! method_exists( $product, 'get_children' ) ) {
			return $summary;
		}

		$children              = (array) $product->get_children();
		$summary['total']      = count( $children );
		$parent_manages_stock  = self::product_directly_manages_stock( $product );

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $summary;
		}

		foreach ( $children as $child_id ) {
			$child = wc_get_product( absint( $child_id ) );

			if ( ! $child ) {
				continue;
			}

			if ( self::product_directly_manages_stock( $child ) ) {
				$summary['managing_stock']++;
			} elseif ( $parent_manages_stock ) {
				$summary['inheriting_parent_stock']++;
			} else {
				$summary['manual_status']++;
			}
		}

		return $summary;
	}

	private static function product_variation_targets( $product, $limit = 6 ) {
		$targets = [
			'items'     => [],
			'truncated' => false,
		];

		if ( ! self::product_is_variable_parent( $product ) || ! method_exists( $product, 'get_children' ) || ! function_exists( 'wc_get_product' ) ) {
			return $targets;
		}

		$children             = array_values( (array) $product->get_children() );
		$limit                = max( 1, min( absint( $limit ), 20 ) );
		$targets['truncated'] = count( $children ) > $limit;

		foreach ( array_slice( $children, 0, $limit ) as $child_id ) {
			$variation = wc_get_product( absint( $child_id ) );

			if ( ! $variation ) {
				continue;
			}

			$variation_meta = self::product_inventory_target_meta( $variation );

			$targets['items'][] = [
				'id'                   => method_exists( $variation, 'get_id' ) ? absint( $variation->get_id() ) : absint( $child_id ),
				'variation_id'         => method_exists( $variation, 'get_id' ) ? absint( $variation->get_id() ) : absint( $child_id ),
				'parent_id'            => method_exists( $variation, 'get_parent_id' ) ? absint( $variation->get_parent_id() ) : 0,
				'name'                 => method_exists( $variation, 'get_name' ) ? sanitize_text_field( $variation->get_name() ) : '',
				'sku'                  => method_exists( $variation, 'get_sku' ) ? sanitize_text_field( $variation->get_sku() ) : '',
				'global_unique_id'     => method_exists( $variation, 'get_global_unique_id' ) ? sanitize_text_field( $variation->get_global_unique_id() ) : '',
				'variation_label'      => sanitize_text_field( $variation_meta['variation_label'] ?? '' ),
				'variation_attributes' => $variation_meta['variation_attributes'] ?? [],
				'manage_stock'         => method_exists( $variation, 'get_manage_stock' ) ? $variation->get_manage_stock( 'edit' ) : false,
				'manages_stock_at'     => sanitize_key( $variation_meta['manages_stock_at'] ?? 'none' ),
				'stock_target_kind'    => sanitize_key( $variation_meta['stock_target_kind'] ?? 'manual_status' ),
				'stock_scope_label'    => sanitize_text_field( $variation_meta['stock_scope_label'] ?? '' ),
				'stock_quantity'       => method_exists( $variation, 'get_stock_quantity' ) ? $variation->get_stock_quantity() : null,
				'stock_status'         => method_exists( $variation, 'get_stock_status' ) ? sanitize_key( $variation->get_stock_status() ) : '',
			];
		}

		return $targets;
	}

	private static function product_variation_attributes( $product ) {
		if ( ! self::product_is_variation( $product ) || ! method_exists( $product, 'get_variation_attributes' ) ) {
			return [];
		}

		$attributes = [];

		foreach ( (array) $product->get_variation_attributes() as $raw_name => $raw_value ) {
			$key         = self::clean_variation_attribute_key( $raw_name );
			$label       = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $key, $product ) : self::humanize_inventory_key( $key );
			$value       = is_scalar( $raw_value ) ? sanitize_text_field( (string) $raw_value ) : '';
			$value_label = self::variation_attribute_value_label( $key, $value, $label );

			$attributes[] = [
				'key'         => $key,
				'label'       => sanitize_text_field( $label ),
				'value'       => $value,
				'value_label' => $value_label,
			];
		}

		return $attributes;
	}

	private static function clean_variation_attribute_key( $raw_name ) {
		$key = sanitize_key( (string) $raw_name );

		if ( 0 === strpos( $key, 'attribute_' ) ) {
			$key = substr( $key, strlen( 'attribute_' ) );
		}

		return $key;
	}

	private static function variation_attribute_value_label( $key, $value, $attribute_label ) {
		if ( '' === $value ) {
			return sprintf(
				/* translators: %s: variation attribute label */
				__( 'Any %s', 'yoro-ai-core' ),
				$attribute_label
			);
		}

		if ( taxonomy_exists( $key ) ) {
			$term = get_term_by( 'slug', $value, $key );

			if ( $term && ! is_wp_error( $term ) && isset( $term->name ) ) {
				return sanitize_text_field( $term->name );
			}
		}

		return sanitize_text_field( $value );
	}

	private static function format_variation_attributes( $attributes ) {
		$parts = [];

		foreach ( is_array( $attributes ) ? $attributes : [] as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}

			$label = sanitize_text_field( $attribute['label'] ?? '' );
			$value = sanitize_text_field( $attribute['value_label'] ?? $attribute['value'] ?? '' );

			if ( '' === $label || '' === $value ) {
				continue;
			}

			$parts[] = $label . ': ' . $value;
		}

		return implode( ', ', $parts );
	}

	private static function product_directly_manages_stock( $product ) {
		if ( ! $product || ! method_exists( $product, 'get_manage_stock' ) ) {
			return false;
		}

		$value = $product->get_manage_stock( 'edit' );

		return true === $value || 1 === $value || '1' === $value || 'yes' === $value;
	}

	private static function product_manages_stock_at( $product, $product_type, $parent_id, $child_stock_summary ) {
		if ( self::product_is_variation( $product ) ) {
			if ( self::product_directly_manages_stock( $product ) ) {
				return 'variation';
			}

			$parent = $parent_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : false;

			if ( $parent && self::product_directly_manages_stock( $parent ) ) {
				return 'product';
			}

			return 'none';
		}

		if ( self::product_is_variable_parent( $product ) ) {
			if ( self::product_directly_manages_stock( $product ) ) {
				return 'product';
			}

			if ( absint( $child_stock_summary['managing_stock'] ?? 0 ) > 0 ) {
				return 'variation';
			}

			return 'none';
		}

		return self::product_directly_manages_stock( $product ) ? 'product' : 'none';
	}

	private static function product_stock_target_kind( $product, $product_type, $manages_stock_at, $child_stock_summary ) {
		if ( self::product_is_variation( $product ) ) {
			if ( 'variation' === $manages_stock_at ) {
				return 'variation_stock';
			}

			if ( 'product' === $manages_stock_at ) {
				return 'parent_shared_stock';
			}

			return 'manual_status';
		}

		if ( self::product_is_variable_parent( $product ) ) {
			if ( 'product' === $manages_stock_at ) {
				return 'parent_shared_stock';
			}

			if ( 'variation' === $manages_stock_at || absint( $child_stock_summary['managing_stock'] ?? 0 ) > 0 ) {
				return 'variation_stock';
			}

			return 'manual_status';
		}

		return 'product' === $manages_stock_at ? 'product_stock' : 'manual_status';
	}

	private static function product_stock_scope_label( $stock_target_kind ) {
		switch ( $stock_target_kind ) {
			case 'parent_shared_stock':
				return __( 'Parent shared stock', 'yoro-ai-core' );
			case 'variation_stock':
				return __( 'Variation stock', 'yoro-ai-core' );
			case 'product_stock':
				return __( 'Product stock', 'yoro-ai-core' );
			default:
				return __( 'Manual stock status', 'yoro-ai-core' );
		}
	}

	private static function humanize_inventory_key( $value ) {
		$value = trim( str_replace( [ '-', '_' ], ' ', (string) $value ) );

		return '' === $value ? '' : ucwords( $value );
	}

	private static function resolve_product_target( $params ) {
		$params = is_array( $params ) ? $params : [];
		$product_id = isset( $params['product_id'] ) ? absint( $params['product_id'] ) : 0;
		$product_sku = self::clean_product_identifier( $params['product_sku'] ?? '' );
		$global_unique_id = self::clean_product_identifier( $params['global_unique_id'] ?? '' );
		$target_type = '';
		$target_value = '';

		if ( $product_id > 0 ) {
			$target_type  = 'product_id';
			$target_value = (string) $product_id;
		} elseif ( '' !== $product_sku ) {
			$target_type  = 'product_sku';
			$target_value = $product_sku;

			if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
				$product_id = absint( wc_get_product_id_by_sku( $product_sku ) );
			}
		} elseif ( '' !== $global_unique_id ) {
			$target_type  = 'global_unique_id';
			$target_value = $global_unique_id;

			if ( function_exists( 'wc_get_product_id_by_global_unique_id' ) ) {
				$product_id = absint( wc_get_product_id_by_global_unique_id( $global_unique_id ) );
			}
		}

		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

		return [
			'product_id'       => $product_id,
			'product'          => $product,
			'product_sku'      => $product_sku,
			'global_unique_id' => $global_unique_id,
			'target_type'      => $target_type,
			'target_value'     => $target_value,
		];
	}

	private static function clean_product_identifier( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( sanitize_text_field( (string) $value ) );
	}

	private static function has_one_explicit_product_id_only( $params ) {
		if ( ! is_array( $params ) || ! isset( $params['product_id'] ) || ! is_int( $params['product_id'] ) || $params['product_id'] <= 0 ) {
			return false;
		}

		return '' === self::clean_product_identifier( $params['product_sku'] ?? '' )
			&& '' === self::clean_product_identifier( $params['global_unique_id'] ?? '' );
	}

	private static function supported_simple_product_alias( $product ) {
		if ( ! $product instanceof WC_Product || 'simple' !== sanitize_key( $product->get_type() ) ) {
			return '';
		}

		$is_virtual = (bool) $product->get_virtual();
		$is_downloadable = (bool) $product->get_downloadable();

		if ( ! $is_virtual && ! $is_downloadable ) {
			return 'physical';
		}

		if ( $is_virtual && ! $is_downloadable ) {
			return 'virtual';
		}

		return $is_virtual && $is_downloadable ? 'digital' : '';
	}

	public static function get_inventory_queue( $params ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$limit = isset( $params['limit'] ) ? absint( $params['limit'] ) : 5;
		$limit = min( max( $limit, 1 ), 20 );
		$focus = ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview';
		$stock_management_enabled = self::is_stock_management_enabled();
		$inventory_mode = $stock_management_enabled ? 'tracked' : 'status_only';

		$queues = [
			YoRo_Orders_Read_Service::inventory_build_query_queue(
				'out_of_stock',
				__( 'Out of stock', 'yoro-ai-core' ),
				__( 'Published products currently marked out of stock.', 'yoro-ai-core' ),
				[
					'stock_status' => 'outofstock',
				],
				$limit
			),
			YoRo_Orders_Read_Service::inventory_build_query_queue(
				'on_backorder',
				__( 'On backorder', 'yoro-ai-core' ),
				__( 'Products currently available on backorder.', 'yoro-ai-core' ),
				[
					'stock_status' => 'onbackorder',
				],
				$limit
			),
		];

		if ( $stock_management_enabled ) {
			$queues[] = YoRo_Orders_Read_Service::inventory_build_query_queue(
				'low_stock',
				__( 'Low stock', 'yoro-ai-core' ),
				__( 'Products with managed stock at or below the low-stock threshold.', 'yoro-ai-core' ),
				[
					'manage_stock' => true,
					'stock_status' => 'instock',
				],
				$limit,
				function( $product ) {
					return $product instanceof WC_Product && YoRo_Orders_Read_Service::inventory_product_is_low_stock( $product );
				}
			);

			$queues[] = YoRo_Orders_Read_Service::inventory_build_query_queue(
				'unmanaged_stock_selling',
				__( 'Selling without stock management', 'yoro-ai-core' ),
				__( 'Published products that are in stock but do not manage stock quantity.', 'yoro-ai-core' ),
				[
					'stock_status' => 'instock',
				],
				$limit,
				function( $product ) {
					return $product instanceof WC_Product && ! $product->managing_stock();
				}
			);

			$queues[] = YoRo_Orders_Read_Service::inventory_build_recent_processing_queue( $limit );
		}

		$total_items = 0;
		$products    = [];

		foreach ( $queues as $queue ) {
			$total_items += absint( $queue['count'] ?? 0 );

			foreach ( $queue['products'] as $product ) {
				$products[ $product['id'] ] = $product;
			}
		}

		$products             = array_values( $products );
		$inventory_context    = self::build_inventory_orchestrator_context( $queues, $focus, $stock_management_enabled, $inventory_mode );
		$queues               = self::rank_inventory_queue_products( $queues, $inventory_context );
		$priority_items       = self::build_inventory_priority_items( $queues, $inventory_context );
		$case_rankings        = self::build_inventory_case_rankings( $queues, $inventory_context );
		$next_case            = self::build_inventory_next_recommended_case( $case_rankings, $inventory_context );
		$batch_candidates     = self::build_inventory_batch_review_candidates( $case_rankings );
		$recommended_sequence = self::build_inventory_recommended_sequence( $queues, $inventory_context, $case_rankings );
		$inventory_actions    = self::build_inventory_actions( $queues, $inventory_context );
		$queue_count          = count( $queues );
		$visible_case_count   = 0;

		foreach ( $queues as $queue ) {
			$visible_case_count += count( is_array( $queue['products'] ?? null ) ? $queue['products'] : [] );
		}

		$visible_unique_product_count = count( $products );
		$queue_summary        = sprintf(
			/* translators: 1: queue match count, 2: queue count */
			__( 'Inventory queue: %1$d queue matches across %2$d queues.', 'yoro-ai-core' ),
			$total_items,
			$queue_count
		);

		return [
			'success'              => true,
			'action'               => 'products.get_inventory_queue',
			'summary'              => $queue_summary,
			'message'              => $queue_summary,
			'count'                => $total_items,
			'queue_item_count'     => $total_items,
			'queue_count'          => $queue_count,
			'visible_case_count'   => $visible_case_count,
			'visible_product_count' => $visible_case_count,
			'visible_unique_product_count' => $visible_unique_product_count,
			'read_only'            => true,
			'undoable'             => false,
			'undo_plan'            => [],
			'data'                 => [],
			'meta'                 => [
				'limit_per_queue' => $limit,
				'focus'           => $focus,
				'inventory_mode'  => $inventory_mode,
				'stock_management_enabled' => $stock_management_enabled,
				'changes_store'   => false,
			],
			'processed'            => [],
			'skipped'              => [],
			'errors'               => [],
			'queues'               => $queues,
			'products'             => $products,
			'limit_per_queue'      => $limit,
			'focus'                => $focus,
			'inventory_mode'       => $inventory_mode,
			'stock_management_enabled' => $stock_management_enabled,
			'inventory_context'    => $inventory_context,
			'priority_items'       => array_slice( $priority_items, 0, 3 ),
			'case_rankings'        => array_slice( $case_rankings, 0, 10 ),
			'next_recommended_case'=> $next_case,
			'batch_draft_candidates' => $batch_candidates,
			'recommended_sequence' => array_slice( $recommended_sequence, 0, 4 ),
			'inventory_actions'    => array_slice( $inventory_actions, 0, 4 ),
			'result_type'          => 'inventory_queue',
			'display_type'         => 'inventory_queue',
		];
	}

	private static function build_inventory_orchestrator_context( $queues, $focus, $stock_management_enabled, $inventory_mode ) {
		$counts = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' !== $key ) {
				$counts[ $key ] = absint( $queue['count'] ?? 0 );
			}
		}

		arsort( $counts );
		$top_topic = (string) key( $counts );
		$top_count = (int) reset( $counts );

		return [
			'top_topic'              => $top_topic,
			'top_count'              => $top_count,
			'total_items'            => array_sum( $counts ),
			'queues_tracked'         => count( $queues ),
			'focus'                  => sanitize_key( $focus ),
			'inventory_mode'         => sanitize_key( $inventory_mode ),
			'stock_management_enabled' => (bool) $stock_management_enabled,
			'out_of_stock_count'     => absint( $counts['out_of_stock'] ?? 0 ),
			'low_stock_count'        => absint( $counts['low_stock'] ?? 0 ),
			'backorder_count'        => absint( $counts['on_backorder'] ?? 0 ),
			'unmanaged_count'        => absint( $counts['unmanaged_stock_selling'] ?? 0 ),
			'recent_processing_count' => absint( $counts['low_stock_in_processing_orders'] ?? 0 ),
		];
	}

	private static function build_inventory_priority_items( $queues, $inventory_context ) {
		$items     = [];
		$queue_map = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' !== $key ) {
				$queue_map[ $key ] = $queue;
			}
		}

		if ( ! empty( $queue_map['out_of_stock']['count'] ) ) {
			$items[] = [
				'topic'   => 'out_of_stock',
				'score'   => absint( $queue_map['out_of_stock']['count'] ?? 0 ) + 200,
				'tone'    => 'warning',
				'title'   => __( 'Stockouts are the clearest inventory risk right now', 'yoro-ai-core' ),
				'message' => __( 'Published products are already marked out of stock.', 'yoro-ai-core' ),
			];
		}

		if ( ! empty( $queue_map['on_backorder']['count'] ) ) {
			$items[] = [
				'topic'   => 'on_backorder',
				'score'   => absint( $queue_map['on_backorder']['count'] ?? 0 ) + 100,
				'tone'    => 'watch',
				'title'   => __( 'Backorders are still building behind demand', 'yoro-ai-core' ),
				'message' => __( 'Products are still selling while stock is already behind demand.', 'yoro-ai-core' ),
			];
		}

		if ( ! empty( $queue_map['low_stock']['count'] ) ) {
			$items[] = [
				'topic'   => 'low_stock',
				'score'   => absint( $queue_map['low_stock']['count'] ?? 0 ),
				'tone'    => 'neutral',
				'title'   => __( 'Low-stock items should be queued for replenishment', 'yoro-ai-core' ),
				'message' => __( 'Managed stock is already at or below the low-stock threshold.', 'yoro-ai-core' ),
			];
		}

		if ( ! empty( $queue_map['low_stock_in_processing_orders']['count'] ) ) {
			$items[] = [
				'topic'   => 'low_stock_in_processing_orders',
				'score'   => absint( $queue_map['low_stock_in_processing_orders']['count'] ?? 0 ),
				'tone'    => 'positive',
				'title'   => __( 'Recent processing demand is already pointing to the next restock needs', 'yoro-ai-core' ),
				'message' => __( 'Current processing orders are already leaning on the remaining stock.', 'yoro-ai-core' ),
			];
		}

		usort(
			$items,
			function( $a, $b ) {
				return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
			}
		);

		return $items;
	}

	private static function build_inventory_recommended_sequence( $queues, $inventory_context, $case_rankings ) {
		$sequence = [];
		$top_case = ! empty( $case_rankings[0] ) && is_array( $case_rankings[0] ) ? $case_rankings[0] : [];
		$top_topic = sanitize_key( $top_case['topic'] ?? $inventory_context['top_topic'] ?? '' );
		$inventory_mode = sanitize_key( $inventory_context['inventory_mode'] ?? '' );

		$sequence[] = [
			'kind'    => 'action',
			'topic'   => 'inventory_review',
			'label'   => 'status_only' === $inventory_mode
				? __( 'Review the next stock-status issue', 'yoro-ai-core' )
				: __( 'Prepare the next inventory review', 'yoro-ai-core' ),
			'command' => 'Prepare next inventory review',
			'reason'  => self::build_inventory_sequence_reason( $top_topic, $inventory_mode ),
		];

		if ( ! empty( $inventory_context['out_of_stock_count'] ) || ! empty( $inventory_context['backorder_count'] ) || ! empty( $inventory_context['recent_processing_count'] ) ) {
			$sequence[] = [
				'kind'    => 'view',
				'topic'   => 'fulfillment_review',
				'label'   => __( 'Check fulfillment pressure next', 'yoro-ai-core' ),
				'command' => 'What seems blocked in fulfillment?',
				'reason'  => __( 'Use this next to confirm whether inventory gaps are feeding into fulfillment delays.', 'yoro-ai-core' ),
			];
		}

		$sequence[] = [
			'kind'    => 'view',
			'topic'   => 'operations_overview',
			'label'   => __( 'Review the broader store picture', 'yoro-ai-core' ),
			'command' => 'Can you give me a monthly store picture?',
			'reason'  => __( 'Use this after the inventory pass when you want the broader operating context.', 'yoro-ai-core' ),
		];

		return $sequence;
	}

	private static function build_inventory_sequence_reason( $top_topic, $inventory_mode ) {
		switch ( $top_topic ) {
			case 'out_of_stock':
				return 'status_only' === $inventory_mode
					? __( 'Start here when you want the clearest stock-status exception first.', 'yoro-ai-core' )
					: __( 'Start here when you want the clearest stockout to review before demand slips further.', 'yoro-ai-core' );
			case 'on_backorder':
				return __( 'Start here when you want to confirm which backordered item should be reviewed before it feeds deeper into fulfillment pressure.', 'yoro-ai-core' );
			case 'low_stock_in_processing_orders':
				return __( 'Start here when you want the inventory item that is already under live order demand.', 'yoro-ai-core' );
			case 'low_stock':
				return __( 'Start here when you want the cleanest low-stock review before it turns into a stockout.', 'yoro-ai-core' );
			case 'unmanaged_stock_selling':
				return __( 'Start here when you want to review where stock visibility is weakest before restock decisions become guesswork.', 'yoro-ai-core' );
			default:
				return __( 'Start here when you want the cleanest inventory priority read first.', 'yoro-ai-core' );
		}
	}

	private static function build_inventory_actions( $queues, $inventory_context ) {
		$actions = [
			[
				'topic'   => 'inventory_review',
				'label'   => __( 'Review restock priorities', 'yoro-ai-core' ),
				'command' => 'What should we restock first?',
			],
			[
				'topic'   => 'fulfillment_review',
				'label'   => __( 'Check fulfillment pressure', 'yoro-ai-core' ),
				'command' => 'What seems blocked in fulfillment?',
			],
			[
				'topic'   => 'operations_overview',
				'label'   => __( 'Open the store picture', 'yoro-ai-core' ),
				'command' => 'Can you give me a monthly store picture?',
			],
		];

		if ( empty( $inventory_context['stock_management_enabled'] ) ) {
			$actions[0]['label'] = __( 'Review stock status risks', 'yoro-ai-core' );
			$actions[0]['command'] = 'What inventory items are out of stock or on backorder?';
		}

		return $actions;
	}

	private static function product_manages_stock_quantity( $product ) {
		if ( ! $product || ! method_exists( $product, 'managing_stock' ) ) {
			return false;
		}

		return (bool) $product->managing_stock();
	}

	private static function is_stock_management_enabled() {
		return 'yes' === get_option( 'woocommerce_manage_stock', 'yes' );
	}

	private static function rank_inventory_queue_products( $queues, $inventory_context ) {
		foreach ( $queues as $queue_index => $queue ) {
			$topic = sanitize_key( $queue['key'] ?? '' );

			if ( empty( $queue['products'] ) || ! is_array( $queue['products'] ) ) {
				$queues[ $queue_index ]['products'] = [];
				continue;
			}

			foreach ( $queue['products'] as $product_index => $product ) {
				$queues[ $queue_index ]['products'][ $product_index ]['case_priority_score'] = self::score_inventory_case( $topic, $product, $inventory_context );
				$queues[ $queue_index ]['products'][ $product_index ]['queue_topic']          = $topic;
			}

			usort(
				$queues[ $queue_index ]['products'],
				function( $a, $b ) {
					return (float) ( $b['case_priority_score'] ?? 0 ) <=> (float) ( $a['case_priority_score'] ?? 0 );
				}
			);
		}

		return $queues;
	}

	private static function build_inventory_case_rankings( $queues, $inventory_context ) {
		$rankings = [];

		foreach ( $queues as $queue ) {
			$topic = sanitize_key( $queue['key'] ?? '' );
			$products = isset( $queue['products'] ) && is_array( $queue['products'] ) ? $queue['products'] : [];

			foreach ( $products as $product ) {
				$product_id = absint( $product['id'] ?? 0 );

				if ( $product_id <= 0 ) {
					continue;
				}

				$rankings[] = [
					'topic'             => $topic,
					'queue_label'       => sanitize_text_field( $queue['label'] ?? '' ),
					'queue_description' => sanitize_text_field( $queue['description'] ?? '' ),
					'product'           => $product,
					'primary_command'   => self::get_inventory_primary_command( $topic, $product, $inventory_context ),
					'score'             => (float) self::score_inventory_case( $topic, $product, $inventory_context ),
				];
			}
		}

		usort(
			$rankings,
			function( $a, $b ) {
				return (float) ( $b['score'] ?? 0 ) <=> (float) ( $a['score'] ?? 0 );
			}
		);

		return $rankings;
	}

	private static function build_inventory_next_recommended_case( $case_rankings, $inventory_context ) {
		if ( empty( $case_rankings[0] ) || ! is_array( $case_rankings[0] ) ) {
			return [];
		}

		return self::map_inventory_ranked_case_to_card( $case_rankings[0], true, $inventory_context );
	}

	private static function build_inventory_batch_review_candidates( $case_rankings ) {
		$candidates = [];
		$seen_topics = [];
		$seen_products = [];

		foreach ( $case_rankings as $ranked_case ) {
			$topic = sanitize_key( $ranked_case['topic'] ?? '' );
			$product = isset( $ranked_case['product'] ) && is_array( $ranked_case['product'] ) ? $ranked_case['product'] : [];
			$product_id = absint( $product['id'] ?? 0 );

			if ( '' === $topic || $product_id <= 0 || isset( $seen_topics[ $topic ] ) || isset( $seen_products[ $product_id ] ) ) {
				continue;
			}

			$seen_topics[ $topic ] = true;
			$seen_products[ $product_id ] = true;
			$candidates[] = self::map_inventory_ranked_case_to_card( $ranked_case, false, [] );

			if ( count( $candidates ) >= 4 ) {
				return array_values( array_filter( $candidates ) );
			}
		}

		foreach ( $case_rankings as $ranked_case ) {
			$product = isset( $ranked_case['product'] ) && is_array( $ranked_case['product'] ) ? $ranked_case['product'] : [];
			$product_id = absint( $product['id'] ?? 0 );
			$card = self::map_inventory_ranked_case_to_card( $ranked_case, false, [] );

			if ( empty( $card ) || $product_id <= 0 || isset( $seen_products[ $product_id ] ) ) {
				continue;
			}

			$seen_products[ $product_id ] = true;
			$candidates[] = $card;

			if ( count( $candidates ) >= 4 ) {
				break;
			}
		}

		return array_values( array_filter( $candidates ) );
	}

	private static function map_inventory_ranked_case_to_card( $ranked_case, $is_start_here, $inventory_context ) {
		$topic = sanitize_key( $ranked_case['topic'] ?? '' );
		$product = isset( $ranked_case['product'] ) && is_array( $ranked_case['product'] ) ? $ranked_case['product'] : [];

		if ( '' === $topic || empty( $product ) ) {
			return [];
		}

		$product_id = absint( $product['id'] ?? 0 );
		$stock_summary = self::format_inventory_stock_summary( $product, $inventory_context );
		$meta_parts = array_filter(
			[
				sanitize_text_field( $ranked_case['queue_label'] ?? '' ),
				sanitize_text_field( $product['sku'] ?? '' ),
				$stock_summary,
			]
		);
		$actions = [
			[
				'label'   => self::get_inventory_primary_action_label( $topic, $inventory_context ),
				'command' => sanitize_text_field( $ranked_case['primary_command'] ?? '' ),
			],
		];

		if ( $is_start_here && ! empty( $product['edit_url'] ) ) {
			$actions[] = [
				'label' => __( 'Open product', 'yoro-ai-core' ),
				'href'  => esc_url_raw( $product['edit_url'] ),
			];
		}

		return [
			'topic'   => $topic,
			'title'   => $is_start_here
				/* translators: %s: Value generated from store data. */
				? sprintf( __( 'Start with %s', 'yoro-ai-core' ), sanitize_text_field( $product['name'] ?? '#' . $product_id ) )
				: sanitize_text_field( $product['name'] ?? '#' . $product_id ),
			'meta'    => implode( ' · ', $meta_parts ),
			'badge'   => self::format_inventory_badge( $topic, $product ),
			'message' => self::build_inventory_case_message( $topic, $product, $inventory_context ),
			'reason'  => self::build_inventory_case_reason( $topic, $product, $inventory_context ),
			'actions' => array_values(
				array_filter(
					$actions,
					function( $item ) {
						return ! empty( $item['command'] ) || ! empty( $item['href'] );
					}
				)
			),
		];
	}

	private static function get_inventory_primary_command( $topic, $product, $inventory_context ) {
		if ( 'low_stock_in_processing_orders' === $topic ) {
			return 'Show fulfillment queue';
		}

		if ( 'status_only' === sanitize_key( $inventory_context['inventory_mode'] ?? '' ) ) {
			return 'Prepare next inventory review';
		}

		return 'Prepare next inventory review';
	}

	private static function get_inventory_primary_action_label( $topic, $inventory_context ) {
		if ( 'low_stock_in_processing_orders' === $topic ) {
			return __( 'Check fulfillment impact', 'yoro-ai-core' );
		}

		if ( 'status_only' === sanitize_key( $inventory_context['inventory_mode'] ?? '' ) ) {
			return __( 'Review stock-status issue', 'yoro-ai-core' );
		}

		return __( 'Prepare inventory review', 'yoro-ai-core' );
	}

	private static function build_inventory_case_message( $topic, $product, $inventory_context ) {
		$product_name = sanitize_text_field( $product['name'] ?? __( 'This product', 'yoro-ai-core' ) );
		$open_order_count = absint( $product['open_order_count'] ?? 0 );
		$inventory_mode = sanitize_key( $inventory_context['inventory_mode'] ?? '' );

		switch ( $topic ) {
			case 'out_of_stock':
				return 'status_only' === $inventory_mode
					/* translators: %s: Value generated from store data. */
					? sprintf( __( '%s is marked out of stock and is the clearest stock-status exception to review first.', 'yoro-ai-core' ), $product_name )
					/* translators: %s: Value generated from store data. */
					: sprintf( __( '%s is already out of stock and should be reviewed before demand slips further.', 'yoro-ai-core' ), $product_name );
			case 'on_backorder':
				/* translators: %s: Value generated from store data. */
				return sprintf( __( '%s is already on backorder and may now be feeding directly into fulfillment pressure.', 'yoro-ai-core' ), $product_name );
			case 'low_stock_in_processing_orders':
				return sprintf(
					/* translators: %d: open order count */
					_n(
						'Recent processing demand is already consuming the remaining stock for this item across %d open order.',
						'Recent processing demand is already consuming the remaining stock for this item across %d open orders.',
						$open_order_count,
						'yoro-ai-core'
					),
					$open_order_count
				);
			case 'low_stock':
				return __( 'Stock is already at or below the low-stock threshold and should be reviewed before it becomes a stockout.', 'yoro-ai-core' );
			case 'unmanaged_stock_selling':
				return __( 'This product is still selling without quantity tracking, so its next inventory risk is harder to see.', 'yoro-ai-core' );
			default:
				return sanitize_text_field( $product['stock_status_label'] ?? YoRo_Product_Helper::get_stock_status_label( $product['stock_status'] ?? '' ) );
		}
	}

	private static function build_inventory_case_reason( $topic, $product, $inventory_context ) {
		$inventory_mode = sanitize_key( $inventory_context['inventory_mode'] ?? '' );

		switch ( $topic ) {
			case 'out_of_stock':
				return 'status_only' === $inventory_mode
					? __( 'Use this first when you want the fastest stock-status review without relying on quantity tracking.', 'yoro-ai-core' )
					: __( 'Use this first when you want the clearest stockout to act on before reviewing softer inventory signals.', 'yoro-ai-core' );
			case 'on_backorder':
				return __( 'Use this next when you want to confirm whether customer demand is already outrunning available stock.', 'yoro-ai-core' );
			case 'low_stock_in_processing_orders':
				return __( 'Use this next when you want to connect inventory risk directly to the current fulfillment backlog.', 'yoro-ai-core' );
			case 'low_stock':
				return __( 'Use this next when you want to catch replenishment issues before they turn into stockouts.', 'yoro-ai-core' );
			case 'unmanaged_stock_selling':
				return __( 'Use this if you want to review where inventory visibility is weakest before deeper restock decisions.', 'yoro-ai-core' );
			default:
				return __( 'Use this if you want the cleanest next inventory check.', 'yoro-ai-core' );
		}
	}

	private static function format_inventory_stock_summary( $product, $inventory_context ) {
		$stock_status = sanitize_key( $product['stock_status'] ?? '' );
		$quantity = $product['stock_quantity'] ?? '';
		$threshold = absint( $product['low_stock_threshold'] ?? 0 );

		if ( '' === $quantity || null === $quantity ) {
			return 'status_only' === sanitize_key( $inventory_context['inventory_mode'] ?? '' )
				? sanitize_text_field( $product['stock_status_label'] ?? YoRo_Product_Helper::get_stock_status_label( $stock_status ) )
				: __( 'Quantity not tracked', 'yoro-ai-core' );
		}

		/* translators: %s: Value generated from store data. */
		$summary = sprintf( __( 'Stock %d', 'yoro-ai-core' ), (int) $quantity );

		if ( ! empty( $inventory_context['stock_management_enabled'] ) && $threshold > 0 ) {
			/* translators: %s: Value generated from store data. */
			$summary .= sprintf( __( ' / low %d', 'yoro-ai-core' ), $threshold );
		}

		return $summary;
	}

	private static function format_inventory_badge( $topic, $product ) {
		if ( 'low_stock_in_processing_orders' === $topic ) {
			return sprintf( '%dx', absint( $product['open_order_count'] ?? 0 ) );
		}

		if ( isset( $product['stock_quantity'] ) && '' !== $product['stock_quantity'] && null !== $product['stock_quantity'] ) {
			return sprintf( '%d', (int) $product['stock_quantity'] );
		}

		return sanitize_text_field( $product['stock_status_label'] ?? YoRo_Product_Helper::get_stock_status_label( $product['stock_status'] ?? '' ) );
	}

	private static function score_inventory_case( $topic, $product, $inventory_context ) {
		$base_scores = [
			'out_of_stock'                  => 500,
			'on_backorder'                  => 420,
			'low_stock_in_processing_orders' => 340,
			'low_stock'                     => 260,
			'unmanaged_stock_selling'       => 120,
		];
		$score = (float) ( $base_scores[ $topic ] ?? 100 );

		if ( sanitize_key( $inventory_context['top_topic'] ?? '' ) === $topic ) {
			$score += 40;
		}

		$open_order_count = absint( $product['open_order_count'] ?? 0 );
		$score += min( 10, $open_order_count ) * 20;

		if ( isset( $product['stock_quantity'] ) && '' !== $product['stock_quantity'] && null !== $product['stock_quantity'] ) {
			$quantity = (int) $product['stock_quantity'];
			$threshold = absint( $product['low_stock_threshold'] ?? 0 );

			if ( $quantity <= 0 ) {
				$score += 40;
			} elseif ( $threshold > 0 && $quantity <= $threshold ) {
				$score += max( 10, ( $threshold - $quantity + 1 ) * 6 );
			}
		}

		if ( 'outofstock' === sanitize_key( $product['stock_status'] ?? '' ) ) {
			$score += 30;
		} elseif ( 'onbackorder' === sanitize_key( $product['stock_status'] ?? '' ) ) {
			$score += 20;
		}

		return $score;
	}
}
