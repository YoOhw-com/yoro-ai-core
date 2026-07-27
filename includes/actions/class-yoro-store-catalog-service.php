<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Catalog_Service {
	const BATCH_IDEMPOTENCY_OPTION_PREFIX = 'yoro_ai_catalog_batch_';
	const BATCH_MAX_ITEMS = 20;

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
			'display_type' => 'product',
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
			'display_type' => 'product',
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

	public static function update_product_catalog_batch( $params ) {
		$contract = self::normalize_batch_contract( $params );
		$error    = self::validate_batch_contract( $contract );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$existing = self::batch_idempotency_result( $contract );

		if ( is_wp_error( $existing ) || is_array( $existing ) ) {
			return $existing;
		}

		$preview = self::build_batch_preview( $contract );

		if ( ! empty( $preview['blocked'] ) ) {
			return new WP_Error( 'catalog_batch_validation_failed', __( 'One or more catalog batch items are invalid. Review the per-item preview before retrying.', 'yoro-ai-core' ) );
		}

		$lock = self::acquire_batch_idempotency_lock( $contract );

		if ( is_wp_error( $lock ) || is_array( $lock ) ) {
			return $lock;
		}

		$results   = [];
		$processed = [];
		$errors    = [];

		foreach ( $contract['items'] as $index => $item ) {
			$result = self::update_product_catalog( self::batch_item_params( $item ) );

			if ( is_wp_error( $result ) ) {
				$error_item = [
					'index'   => $index,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'target'  => self::batch_item_target( $item ),
				];
				$errors[]  = $error_item;
				$results[] = array_merge( $error_item, [ 'success' => false ] );
				continue;
			}

			$product_id  = absint( $result['product_id'] ?? 0 );
			$processed[] = $product_id;
			$results[]   = [
				'index'          => $index,
				'success'        => true,
				'product_id'     => $product_id,
				'target'         => self::batch_item_target( $item ),
				'changed_fields' => $result['changed_fields'] ?? [],
				'product'        => $result['product'] ?? [],
			];
		}

		$success = empty( $errors );
		$summary = sprintf(
			/* translators: 1: successful catalog updates, 2: failed catalog updates */
			__( 'Catalog batch finished with %1$d updated and %2$d failed products.', 'yoro-ai-core' ),
			count( $processed ),
			count( $errors )
		);
		$result = [
			'success'           => $success,
			'action'            => 'products.update_catalog_batch',
			'summary'           => $summary,
			'message'           => $summary,
			'results'           => $results,
			'processed'         => array_values( array_filter( $processed ) ),
			'skipped'           => [],
			'errors'            => $errors,
			'count'             => count( $processed ),
			'read_only'         => false,
			'undoable'          => false,
			'undo_plan'         => [],
			'idempotent_replay' => false,
			'result_type'       => 'products',
			'display_type'      => 'generic',
			'meta'              => [
				'changes_store'     => count( $processed ) > 0,
				'idempotent_replay' => false,
			],
		];

		self::complete_batch_idempotency_record( $contract, $result );

		return $result;
	}

	public static function preview_update_product_catalog_batch( $params ) {
		$contract = self::normalize_batch_contract( $params );
		$error    = self::validate_batch_contract( $contract );

		if ( is_wp_error( $error ) ) {
			return [
				'type'              => 'write',
				'will_execute'      => false,
				'action'            => 'products.update_catalog_batch',
				'summary'           => $error->get_error_message(),
				'message'           => $error->get_error_message(),
				'blocked'           => true,
				'block_reason'      => $error->get_error_code(),
				'items'             => [],
				'count'             => 0,
				'changes_store'     => false,
				'idempotent_replay' => false,
			];
		}

		$existing = self::batch_idempotency_result( $contract );

		if ( is_wp_error( $existing ) ) {
			return [
				'type'              => 'write',
				'will_execute'      => false,
				'action'            => 'products.update_catalog_batch',
				'summary'           => $existing->get_error_message(),
				'message'           => $existing->get_error_message(),
				'blocked'           => true,
				'block_reason'      => $existing->get_error_code(),
				'items'             => [],
				'count'             => 0,
				'changes_store'     => false,
				'idempotent_replay' => false,
			];
		}

		if ( is_array( $existing ) ) {
			return [
				'type'              => 'write',
				'will_execute'      => false,
				'action'            => 'products.update_catalog_batch',
				'summary'           => __( 'This batch idempotency key already completed; retrying will return the saved result without applying updates again.', 'yoro-ai-core' ),
				'message'           => __( 'This batch idempotency key already completed; retrying will return the saved result without applying updates again.', 'yoro-ai-core' ),
				'blocked'           => false,
				'block_reason'      => '',
				'items'             => $existing['results'] ?? [],
				'count'             => 0,
				'changes_store'     => false,
				'idempotent_replay' => true,
			];
		}

		return self::build_batch_preview( $contract );
	}

	private static function normalize_batch_contract( $params ) {
		$params = is_array( $params ) ? $params : [];
		$items  = [];
		$patch_fields = [ 'regular_price', 'sale_price', 'sale_price_from', 'sale_price_to', 'status', 'catalog_visibility' ];

		foreach ( is_array( $params['items'] ?? null ) ? $params['items'] : [] as $item ) {
			$item  = is_array( $item ) ? $item : [];
			$patch = is_array( $item['patch'] ?? null ) ? $item['patch'] : [];
			$items[] = [
				'product_id'       => absint( $item['product_id'] ?? 0 ),
				'variation_id'     => absint( $item['variation_id'] ?? 0 ),
				'product_sku'      => sanitize_text_field( $item['product_sku'] ?? '' ),
				'global_unique_id' => sanitize_text_field( $item['global_unique_id'] ?? '' ),
				'patch'            => array_intersect_key(
					$patch,
					array_fill_keys( $patch_fields, true )
				),
				'patch_valid'      => is_array( $item['patch'] ?? null ) && empty( array_diff( array_keys( $patch ), $patch_fields ) ),
			];
		}

		return [
			'items'                    => $items,
			'items_input_valid'        => is_array( $params['items'] ?? null ),
			'idempotency_key'          => sanitize_text_field( $params['idempotency_key'] ?? '' ),
			'idempotency_key_original' => trim( (string) ( $params['idempotency_key'] ?? '' ) ),
		];
	}

	private static function validate_batch_contract( $contract ) {
		if ( ! $contract['items_input_valid'] || empty( $contract['items'] ) || count( $contract['items'] ) > self::BATCH_MAX_ITEMS ) {
			return new WP_Error( 'invalid_catalog_batch_items', __( 'Catalog batch items must contain between 1 and 20 targets.', 'yoro-ai-core' ) );
		}

		if ( '' === $contract['idempotency_key'] || strlen( $contract['idempotency_key_original'] ) > 190 ) {
			return new WP_Error( 'catalog_batch_idempotency_key_required', __( 'A valid idempotency key no longer than 190 characters is required for catalog batch updates.', 'yoro-ai-core' ) );
		}

		return true;
	}

	private static function build_batch_preview( $contract ) {
		$items       = [];
		$blocked     = false;
		$seen_target = [];

		foreach ( $contract['items'] as $index => $item ) {
			$target_count = count(
				array_filter(
					[
						$item['product_id'],
						$item['variation_id'],
						$item['product_sku'],
						$item['global_unique_id'],
					],
					static fn( $value ) => '' !== (string) $value && 0 !== $value
				)
			);
			$preview = $item['patch_valid'] && 1 === $target_count
				? self::preview_update_product_catalog( self::batch_item_params( $item ) )
				: [
					'blocked'      => true,
					'block_reason' => $item['patch_valid'] ? 'ambiguous_catalog_batch_target' : 'invalid_catalog_batch_patch',
					'message'      => $item['patch_valid']
						? __( 'Each catalog batch item requires exactly one product or variation identifier.', 'yoro-ai-core' )
						: __( 'Each catalog batch item requires a patch object containing only supported catalog fields.', 'yoro-ai-core' ),
				];
			$product_id = absint( $preview['product_id'] ?? 0 );

			if ( $item['variation_id'] > 0 && 'variation' !== ( $preview['product']['type'] ?? '' ) ) {
				$preview['blocked']      = true;
				$preview['block_reason'] = 'invalid_catalog_batch_variation';
				$preview['message']      = __( 'The variation_id target must resolve to a WooCommerce product variation.', 'yoro-ai-core' );
			}

			if ( $product_id > 0 && isset( $seen_target[ $product_id ] ) ) {
				$preview['blocked']      = true;
				$preview['block_reason'] = 'duplicate_catalog_batch_target';
				$preview['message']      = __( 'Each product or variation may appear only once in a catalog batch.', 'yoro-ai-core' );
			}

			if ( $product_id > 0 ) {
				$seen_target[ $product_id ] = true;
			}

			$blocked = $blocked || ! empty( $preview['blocked'] );
			$items[] = [
				'index'          => $index,
				'target'         => self::batch_item_target( $item ),
				'product_id'     => $product_id,
				'blocked'        => ! empty( $preview['blocked'] ),
				'block_reason'   => sanitize_key( $preview['block_reason'] ?? '' ),
				'message'        => sanitize_text_field( $preview['message'] ?? '' ),
				'changed_fields' => $preview['changed_fields'] ?? [],
				'changes'        => $preview['changes'] ?? [],
			];
		}

		$message = $blocked
			? __( 'One or more catalog batch items need correction before approval.', 'yoro-ai-core' )
			: sprintf(
				/* translators: %d: product count */
				_n( 'This will update %d product after approval.', 'This will update %d products after approval.', count( $items ), 'yoro-ai-core' ),
				count( $items )
			);

		return [
			'type'              => 'write',
			'will_execute'      => false,
			'action'            => 'products.update_catalog_batch',
			'summary'           => $message,
			'message'           => $message,
			'blocked'           => $blocked,
			'block_reason'      => $blocked ? 'catalog_batch_validation_failed' : '',
			'items'             => $items,
			'count'             => $blocked ? 0 : count( $items ),
			'changes_store'     => ! $blocked,
			'idempotent_replay' => false,
		];
	}

	private static function batch_item_params( $item ) {
		return array_merge(
			[
				'product_id'       => $item['product_id'] > 0 ? $item['product_id'] : $item['variation_id'],
				'product_sku'      => $item['product_sku'],
				'global_unique_id' => $item['global_unique_id'],
			],
			$item['patch']
		);
	}

	private static function batch_item_target( $item ) {
		return array_filter(
			[
				'product_id'       => absint( $item['product_id'] ?? 0 ),
				'variation_id'     => absint( $item['variation_id'] ?? 0 ),
				'product_sku'      => sanitize_text_field( $item['product_sku'] ?? '' ),
				'global_unique_id' => sanitize_text_field( $item['global_unique_id'] ?? '' ),
			]
		);
	}

	private static function batch_idempotency_option_name( $key ) {
		return self::BATCH_IDEMPOTENCY_OPTION_PREFIX . substr( hash( 'sha256', $key ), 0, 40 );
	}

	private static function batch_idempotency_signature( $contract ) {
		return hash( 'sha256', wp_json_encode( $contract['items'] ) );
	}

	private static function batch_idempotency_result( $contract ) {
		$record = get_option( self::batch_idempotency_option_name( $contract['idempotency_key'] ), null );

		if ( ! is_array( $record ) ) {
			return null;
		}

		if ( ! hash_equals( (string) ( $record['signature'] ?? '' ), self::batch_idempotency_signature( $contract ) ) ) {
			return new WP_Error( 'catalog_batch_idempotency_key_conflict', __( 'That catalog batch idempotency key was already used with different targets or patches.', 'yoro-ai-core' ) );
		}

		if ( 'complete' === ( $record['status'] ?? '' ) && is_array( $record['result'] ?? null ) ) {
			$result = $record['result'];
			$result['idempotent_replay'] = true;
			$result['count']             = 0;
			$result['meta']['changes_store']     = false;
			$result['meta']['idempotent_replay'] = true;

			return $result;
		}

		return new WP_Error( 'catalog_batch_in_progress', __( 'A catalog batch with that idempotency key is already in progress.', 'yoro-ai-core' ) );
	}

	private static function acquire_batch_idempotency_lock( $contract ) {
		$option_name = self::batch_idempotency_option_name( $contract['idempotency_key'] );
		$record      = [
			'status'     => 'in_progress',
			'signature'  => self::batch_idempotency_signature( $contract ),
			'created_at' => gmdate( 'c' ),
		];

		if ( add_option( $option_name, $record, '', false ) ) {
			return true;
		}

		$existing = self::batch_idempotency_result( $contract );

		return null === $existing
			? new WP_Error( 'catalog_batch_lock_failed', __( 'The catalog batch could not acquire an idempotency lock.', 'yoro-ai-core' ) )
			: $existing;
	}

	private static function complete_batch_idempotency_record( $contract, $result ) {
		update_option(
			self::batch_idempotency_option_name( $contract['idempotency_key'] ),
			[
				'status'       => 'complete',
				'signature'    => self::batch_idempotency_signature( $contract ),
				'completed_at' => gmdate( 'c' ),
				'result'       => $result,
			],
			false
		);
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
