<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Order_Refund_Service {

	const IDEMPOTENCY_META_KEY = '_yoro_refund_idempotency';
	const IDEMPOTENCY_RECORD_LIMIT = 50;
	const IDEMPOTENCY_LOCK_OPTION_PREFIX = 'yoro_ai_refund_lock_';

	public static function create_refund( $params ) {
		$contract = self::normalize_refund_contract( $params );
		$error    = self::validate_refund_contract( $contract );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$order = wc_get_order( $contract['order_id'] );

		if ( ! $order ) {
			return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
		}

		$idempotent_result = self::find_idempotent_refund( $order, $contract );

		if ( is_wp_error( $idempotent_result ) ) {
			return $idempotent_result;
		}

		if ( $idempotent_result instanceof WC_Order_Refund ) {
			return self::build_success_result( $order, $idempotent_result, $contract, true );
		}

		$lock = self::acquire_idempotency_lock( $order, $contract );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		if ( $lock instanceof WC_Order_Refund ) {
			return self::build_success_result( $order, $lock, $contract, true );
		}

		$lock_acquired = true === $lock;

		try {
			$error = self::validate_refund_against_order( $contract, $order );

			if ( is_wp_error( $error ) ) {
				return $error;
			}

			$line_item_contract = self::build_refund_line_items( $order, $contract );

			if ( is_wp_error( $line_item_contract ) ) {
				return $line_item_contract;
			}

			$refund = wc_create_refund(
				[
					'amount'         => $contract['amount'],
					'reason'         => $contract['reason'],
					'order_id'       => $order->get_id(),
					'line_items'     => $line_item_contract['wc_line_items'],
					'refund_payment' => $contract['refund_payment'],
					'restock_items'  => $contract['restock_items'],
				]
			);

			if ( is_wp_error( $refund ) ) {
				return $refund;
			}

			if ( ! $refund instanceof WC_Order_Refund ) {
				return new WP_Error( 'refund_create_failed', __( 'WooCommerce did not return a saved refund.', 'yoro-ai-core' ) );
			}

			self::record_idempotent_refund( $order, $refund, $contract );

			return self::build_success_result( $order, $refund, $contract, false );
		} finally {
			if ( $lock_acquired ) {
				self::release_idempotency_lock( $order, $contract );
			}
		}
	}

	public static function preview_create_refund( $params ) {
		$contract     = self::normalize_refund_contract( $params );
		$error        = self::validate_refund_contract( $contract );
		$order        = $contract['order_id'] > 0 ? wc_get_order( $contract['order_id'] ) : false;
		$block_reason = '';
		$idempotent_replay = false;

		if ( is_wp_error( $error ) ) {
			$block_reason = $error->get_error_code();
			$message      = $error->get_error_message();
		} elseif ( ! $order ) {
			$block_reason = 'order_not_found';
			$message      = __( 'I could not find that order.', 'yoro-ai-core' );
		} else {
			$idempotent_result = self::find_idempotent_refund( $order, $contract );

			if ( is_wp_error( $idempotent_result ) ) {
				$block_reason = $idempotent_result->get_error_code();
				$message      = $idempotent_result->get_error_message();
			} elseif ( $idempotent_result instanceof WC_Order_Refund ) {
				$idempotent_replay = true;
				$message = __( 'This idempotency key already completed this refund; retrying will return the saved result without creating another refund.', 'yoro-ai-core' );
			} else {
				$error = self::validate_refund_against_order( $contract, $order );

				if ( is_wp_error( $error ) ) {
					$block_reason = $error->get_error_code();
					$message      = $error->get_error_message();
				} else {
					$message = sprintf(
						/* translators: 1: refund amount, 2: currency, 3: order number */
						__( 'This will create an irreversible refund of %1$s %2$s for order #%3$s after approval.', 'yoro-ai-core' ),
						wc_format_decimal( $contract['amount'], 2 ),
						$order->get_currency(),
						$order->get_order_number()
					);
				}
			}
		}

		$line_item_preview = $order && ! $idempotent_replay
			? self::build_line_item_preview( $order, $contract )
			: [
				'items'                     => [],
				'shipping_remaining_amount' => $order ? self::remaining_shipping_amount( $order ) : 0.0,
			];

		if ( is_wp_error( $line_item_preview ) ) {
			if ( '' === $block_reason ) {
				$block_reason = $line_item_preview->get_error_code();
				$message      = $line_item_preview->get_error_message();
			}

			$line_item_preview = [
				'items'                     => [],
				'shipping_remaining_amount' => $order ? self::remaining_shipping_amount( $order ) : 0.0,
			];
		}

		$remaining = $order ? self::remaining_refund_amount( $order ) : 0.0;

		return [
			'type'                      => 'write',
			'will_execute'              => false,
			'action'                    => 'orders.create_refund',
			'summary'                   => $message,
			'message'                   => $message,
			'blocked'                   => '' !== $block_reason,
			'block_reason'              => $block_reason,
			'order_id'                  => $contract['order_id'],
			'order_exists'              => (bool) $order,
			'refund_amount'             => wc_format_decimal( $contract['amount'], 2 ),
			'refund_payment'            => $contract['refund_payment'],
			'refund_payment_set'        => $contract['refund_payment_set'],
			'restock_items'             => $contract['restock_items'],
			'idempotency_key_set'       => '' !== $contract['idempotency_key'],
			'idempotent_replay'         => $idempotent_replay,
			'reason'                    => $contract['reason'],
			'remaining_amount'          => wc_format_decimal( $remaining, 2 ),
			'shipping_amount'           => wc_format_decimal( $contract['shipping_amount'], 2 ),
			'shipping_remaining_amount' => wc_format_decimal( $line_item_preview['shipping_remaining_amount'] ?? 0, 2 ),
			'line_items'                => $line_item_preview['items'] ?? [],
			'inventory_impact'          => array_values( array_filter( array_column( $line_item_preview['items'] ?? [], 'inventory_impact' ) ) ),
			'currency'                  => $order ? $order->get_currency() : '',
			'external_payment'          => $contract['refund_payment'],
			'irreversible'              => true,
			'count'                     => $order && '' === $block_reason && ! $idempotent_replay ? 1 : 0,
			'changes_store'             => $order && '' === $block_reason && ! $idempotent_replay,
		];
	}

	private static function build_success_result( WC_Order $order, WC_Order_Refund $refund, $contract, $idempotent_replay ) {
		$summary = $idempotent_replay
			? sprintf(
				/* translators: 1: refund ID, 2: order number */
				__( 'Returned existing refund #%1$d for order #%2$s without creating a duplicate.', 'yoro-ai-core' ),
				$refund->get_id(),
				$order->get_order_number()
			)
			: sprintf(
				/* translators: 1: refund amount, 2: currency, 3: order number */
				__( 'Created a refund of %1$s %2$s for order #%3$s.', 'yoro-ai-core' ),
				wc_format_decimal( $contract['amount'], 2 ),
				$order->get_currency(),
				$order->get_order_number()
			);
		$context = YoRo_Orders_Read_Service::refund_preview( [ 'order_id' => $order->get_id() ] );

		return array_merge(
			is_array( $context ) ? $context : [],
			[
				'success'           => true,
				'action'            => 'orders.create_refund',
				'summary'           => $summary,
				'message'           => $summary,
				'order_id'          => $order->get_id(),
				'refund_id'         => $refund->get_id(),
				'refund_amount'     => wc_format_decimal( $refund->get_amount(), 2 ),
				'refund_payment'    => $contract['refund_payment'],
				'restock_items'     => $contract['restock_items'],
				'shipping_amount'   => wc_format_decimal( $contract['shipping_amount'], 2 ),
				'line_items'        => $contract['line_items'],
				'idempotent_replay' => (bool) $idempotent_replay,
				'reason'            => $contract['reason'],
				'processed'         => [ $refund->get_id() ],
				'skipped'           => [],
				'errors'            => [],
				'count'             => $idempotent_replay ? 0 : 1,
				'read_only'         => false,
				'undoable'          => false,
				'undo_plan'         => [],
				'result_type'       => 'refund',
				'display_type'      => 'refund_preview',
				'meta'              => [
					'changes_store'       => ! $idempotent_replay,
					'external_payment'    => $contract['refund_payment'],
					'irreversible_action' => true,
					'idempotent_replay'   => (bool) $idempotent_replay,
				],
			]
		);
	}

	private static function normalize_refund_contract( $params ) {
		$params               = is_array( $params ) ? $params : [];
		$refund_payment_set   = array_key_exists( 'refund_payment', $params );
		$refund_payment       = false;
		$refund_payment_valid = true;
		$restock_items        = false;
		$restock_items_valid  = true;

		if ( $refund_payment_set ) {
			$refund_payment       = filter_var( $params['refund_payment'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$refund_payment_valid = null !== $refund_payment;
			$refund_payment       = true === $refund_payment;
		}

		if ( array_key_exists( 'restock_items', $params ) ) {
			$restock_items       = filter_var( $params['restock_items'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$restock_items_valid = null !== $restock_items;
			$restock_items       = true === $restock_items;
		}

		$shipping_amount_valid = ! array_key_exists( 'shipping_amount', $params ) || is_numeric( $params['shipping_amount'] );
		$shipping_amount       = $shipping_amount_valid ? round( (float) ( $params['shipping_amount'] ?? 0 ), wc_get_price_decimals() ) : -1.0;
		$line_items            = [];

		foreach ( is_array( $params['line_items'] ?? null ) ? $params['line_items'] : [] as $line_item ) {
			if ( ! is_array( $line_item ) ) {
				$line_items[] = [];
				continue;
			}

			$line_items[] = [
				'order_item_id' => absint( $line_item['order_item_id'] ?? 0 ),
				'quantity'      => isset( $line_item['quantity'] ) && is_numeric( $line_item['quantity'] ) ? absint( $line_item['quantity'] ) : 0,
				'amount'        => isset( $line_item['amount'] ) && is_numeric( $line_item['amount'] ) ? round( (float) $line_item['amount'], wc_get_price_decimals() ) : -1.0,
				'tax'           => self::normalize_refund_tax( $line_item['tax'] ?? 0 ),
			];
		}

		return [
			'order_id'                 => absint( $params['order_id'] ?? 0 ),
			'amount'                   => isset( $params['amount'] ) && is_numeric( $params['amount'] ) ? round( (float) $params['amount'], wc_get_price_decimals() ) : 0.0,
			'reason'                   => sanitize_textarea_field( $params['reason'] ?? '' ),
			'refund_payment'           => $refund_payment,
			'refund_payment_set'       => $refund_payment_set,
			'refund_payment_valid'     => $refund_payment_valid,
			'line_items'               => $line_items,
			'line_items_input_valid'   => ! array_key_exists( 'line_items', $params ) || is_array( $params['line_items'] ),
			'shipping_amount'          => $shipping_amount,
			'shipping_amount_valid'    => $shipping_amount_valid,
			'restock_items'            => $restock_items,
			'restock_items_valid'      => $restock_items_valid,
			'idempotency_key'          => sanitize_text_field( $params['idempotency_key'] ?? '' ),
			'idempotency_key_original' => trim( (string) ( $params['idempotency_key'] ?? '' ) ),
		];
	}

	private static function normalize_refund_tax( $tax ) {
		if ( is_array( $tax ) ) {
			$normalized = [];

			foreach ( $tax as $rate_id => $amount ) {
				if ( absint( $rate_id ) <= 0 || ! is_numeric( $amount ) ) {
					return [ 0 => -1.0 ];
				}

				$normalized[ absint( $rate_id ) ] = round( (float) $amount, wc_get_price_decimals() );
			}

			return $normalized;
		}

		return is_numeric( $tax ) ? round( (float) $tax, wc_get_price_decimals() ) : -1.0;
	}

	private static function validate_refund_contract( $contract ) {
		if ( $contract['order_id'] <= 0 ) {
			return new WP_Error( 'missing_order_target', __( 'Please choose an order first.', 'yoro-ai-core' ) );
		}

		if ( $contract['amount'] <= 0 ) {
			return new WP_Error( 'invalid_refund_amount', __( 'Please provide a refund amount greater than zero.', 'yoro-ai-core' ) );
		}

		if ( ! $contract['refund_payment_set'] ) {
			return new WP_Error( 'refund_payment_choice_required', __( 'Choose whether WooCommerce should send the refund through the payment gateway.', 'yoro-ai-core' ) );
		}

		if ( ! $contract['refund_payment_valid'] ) {
			return new WP_Error( 'invalid_refund_payment', __( 'The refund payment choice must be true or false.', 'yoro-ai-core' ) );
		}

		if ( ! $contract['line_items_input_valid'] || count( $contract['line_items'] ) > 100 ) {
			return new WP_Error( 'invalid_refund_line_items', __( 'Refund line items must be a list of at most 100 items.', 'yoro-ai-core' ) );
		}

		if ( ! $contract['shipping_amount_valid'] || $contract['shipping_amount'] < 0 ) {
			return new WP_Error( 'invalid_refund_shipping_amount', __( 'Refund shipping amount must be zero or a positive number.', 'yoro-ai-core' ) );
		}

		if ( ! $contract['restock_items_valid'] ) {
			return new WP_Error( 'invalid_restock_items', __( 'The restock choice must be true or false.', 'yoro-ai-core' ) );
		}

		if ( $contract['restock_items'] && empty( $contract['line_items'] ) ) {
			return new WP_Error( 'restock_requires_line_items', __( 'Restocking requires explicit refund line items and quantities.', 'yoro-ai-core' ) );
		}

		if ( strlen( $contract['idempotency_key_original'] ) > 190 || '' !== $contract['idempotency_key_original'] && '' === $contract['idempotency_key'] ) {
			return new WP_Error( 'invalid_refund_idempotency_key', __( 'Please provide a valid idempotency key no longer than 190 characters.', 'yoro-ai-core' ) );
		}

		return true;
	}

	private static function validate_refund_against_order( $contract, WC_Order $order ) {
		$remaining = self::remaining_refund_amount( $order );

		if ( $remaining <= 0 ) {
			return new WP_Error( 'order_not_refundable', __( 'This order has no remaining refundable amount.', 'yoro-ai-core' ) );
		}

		if ( $contract['amount'] > $remaining ) {
			return new WP_Error( 'refund_amount_exceeds_remaining', sprintf( __( 'The refund amount exceeds the remaining refundable amount of %s.', 'yoro-ai-core' ), wc_format_decimal( $remaining, 2 ) ) );
		}

		$line_item_preview = self::build_line_item_preview( $order, $contract );

		if ( is_wp_error( $line_item_preview ) ) {
			return $line_item_preview;
		}

		if ( ! empty( $contract['line_items'] ) || $contract['shipping_amount'] > 0 ) {
			$component_total = (float) ( $line_item_preview['component_total'] ?? 0 );
			$tolerance       = 1 / pow( 10, max( 0, wc_get_price_decimals() ) );

			if ( abs( $component_total - $contract['amount'] ) >= $tolerance ) {
				return new WP_Error( 'refund_components_do_not_match_amount', __( 'Refund line item, tax, and shipping amounts must add up to the total refund amount.', 'yoro-ai-core' ) );
			}
		}

		if ( $contract['refund_payment'] && '' === trim( (string) $order->get_transaction_id() ) ) {
			return new WP_Error( 'refund_transaction_missing', __( 'The order has no payment transaction ID, so an automatic gateway refund cannot be attempted.', 'yoro-ai-core' ) );
		}

		if ( $contract['refund_payment'] && ! self::gateway_supports_refunds( $order ) ) {
			return new WP_Error( 'refund_gateway_unsupported', __( 'The order payment gateway does not support automatic refunds.', 'yoro-ai-core' ) );
		}

		return true;
	}

	private static function build_line_item_preview( WC_Order $order, $contract ) {
		$items           = [];
		$component_total = $contract['shipping_amount'];
		$seen_item_ids   = [];

		foreach ( $contract['line_items'] as $requested ) {
			$item_id = absint( $requested['order_item_id'] ?? 0 );
			$item    = $order->get_item( $item_id, false );

			if ( $item_id <= 0 || isset( $seen_item_ids[ $item_id ] ) || ! $item instanceof WC_Order_Item_Product ) {
				return new WP_Error( 'invalid_refund_order_item', __( 'Each refund line item must reference one unique product line from the order.', 'yoro-ai-core' ) );
			}

			$seen_item_ids[ $item_id ] = true;
			$refunded                  = self::refunded_item_totals( $order, $item_id );
			$remaining_quantity        = max( 0, absint( $item->get_quantity() ) - $refunded['quantity'] );
			$remaining_amount          = max( 0.0, (float) $item->get_total() - $refunded['amount'] );
			$remaining_tax             = max( 0.0, (float) $item->get_total_tax() - $refunded['tax'] );
			$quantity                  = absint( $requested['quantity'] ?? 0 );
			$amount                    = (float) ( $requested['amount'] ?? -1 );
			$tax_total                 = self::requested_tax_total( $requested['tax'] ?? -1 );
			$original_taxes            = is_array( $item->get_taxes()['total'] ?? null ) ? $item->get_taxes()['total'] : [];
			$refunded_taxes            = self::refunded_item_taxes( $order, $item_id );

			if ( $quantity <= 0 || $quantity > $remaining_quantity ) {
				return new WP_Error( 'refund_quantity_exceeds_remaining', __( 'A refund quantity exceeds the remaining refundable quantity for that order item.', 'yoro-ai-core' ) );
			}

			if ( $amount < 0 || $amount > $remaining_amount ) {
				return new WP_Error( 'refund_line_amount_exceeds_remaining', __( 'A refund line amount exceeds the remaining refundable amount for that order item.', 'yoro-ai-core' ) );
			}

			if ( $tax_total < 0 || $tax_total > $remaining_tax ) {
				return new WP_Error( 'refund_line_tax_exceeds_remaining', __( 'A refund tax amount exceeds the remaining refundable tax for that order item.', 'yoro-ai-core' ) );
			}

			if ( is_array( $requested['tax'] ) ) {
				foreach ( $requested['tax'] as $rate_id => $requested_tax ) {
					if ( ! array_key_exists( $rate_id, $original_taxes ) ) {
						return new WP_Error( 'refund_tax_rate_unknown', __( 'A refund tax rate does not belong to the original order item.', 'yoro-ai-core' ) );
					}

					$remaining_rate_tax = max( 0.0, (float) $original_taxes[ $rate_id ] - (float) ( $refunded_taxes[ $rate_id ] ?? 0 ) );

					if ( (float) $requested_tax > $remaining_rate_tax ) {
						return new WP_Error( 'refund_line_tax_exceeds_remaining', __( 'A refund tax amount exceeds the remaining refundable tax for that order item.', 'yoro-ai-core' ) );
					}
				}
			}

			$product          = $item->get_product();
			$inventory_impact = [];

			if ( $contract['restock_items'] && $product instanceof WC_Product && $product->managing_stock() ) {
				$current_stock        = $product->get_stock_quantity();
				$reduced_stock        = max( 0, (int) wc_stock_amount( $item->get_meta( '_reduced_stock', true ) ) );
				$restockable_quantity = min( $quantity, $reduced_stock );
				$inventory_impact = [
					'product_id'       => $product->get_id(),
					'quantity_change'  => $restockable_quantity,
					'requested_quantity' => $quantity,
					'current_stock'    => null === $current_stock ? null : (int) $current_stock,
					'projected_stock'  => null === $current_stock ? null : (int) $current_stock + $restockable_quantity,
					'manages_stock'    => true,
					'will_restock'     => $restockable_quantity > 0,
				];
			}

			$component_total += $amount + $tax_total;
			$items[] = [
				'order_item_id'     => $item_id,
				'product_id'       => $item->get_product_id(),
				'variation_id'     => $item->get_variation_id(),
				'name'             => sanitize_text_field( $item->get_name() ),
				'requested_quantity' => $quantity,
				'requested_amount' => wc_format_decimal( $amount, 2 ),
				'requested_tax'    => wc_format_decimal( $tax_total, 2 ),
				'remaining_quantity' => $remaining_quantity,
				'remaining_amount' => wc_format_decimal( $remaining_amount, 2 ),
				'remaining_tax'    => wc_format_decimal( $remaining_tax, 2 ),
				'inventory_impact' => $inventory_impact,
			];
		}

		$shipping_remaining = self::remaining_shipping_amount( $order );

		if ( $contract['shipping_amount'] > $shipping_remaining ) {
			return new WP_Error( 'refund_shipping_exceeds_remaining', __( 'The shipping refund exceeds the remaining refundable shipping amount.', 'yoro-ai-core' ) );
		}

		return [
			'items'                     => $items,
			'component_total'           => round( $component_total, wc_get_price_decimals() ),
			'shipping_remaining_amount' => $shipping_remaining,
		];
	}

	private static function build_refund_line_items( WC_Order $order, $contract ) {
		$preview = self::build_line_item_preview( $order, $contract );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$wc_line_items = [];

		foreach ( $contract['line_items'] as $requested ) {
			$item_id = absint( $requested['order_item_id'] );
			$item    = $order->get_item( $item_id, false );

			$wc_line_items[ $item_id ] = [
				'qty'          => absint( $requested['quantity'] ),
				'refund_total' => (float) $requested['amount'],
				'refund_tax'   => self::refund_tax_map( $item, $requested['tax'] ),
			];
		}

		$shipping_remaining = $contract['shipping_amount'];

		foreach ( $order->get_items( 'shipping' ) as $shipping_item_id => $shipping_item ) {
			if ( $shipping_remaining <= 0 ) {
				break;
			}

			$refunded       = self::refunded_item_totals( $order, $shipping_item_id );
			$available      = max( 0.0, (float) $shipping_item->get_total() - $refunded['amount'] );
			$refund_amount  = min( $available, $shipping_remaining );

			if ( $refund_amount <= 0 ) {
				continue;
			}

			$wc_line_items[ $shipping_item_id ] = [
				'qty'          => 0,
				'refund_total' => $refund_amount,
				'refund_tax'   => [],
			];
			$shipping_remaining -= $refund_amount;
		}

		return [
			'wc_line_items' => $wc_line_items,
		];
	}

	private static function refund_tax_map( WC_Order_Item_Product $item, $tax ) {
		$original_taxes = is_array( $item->get_taxes()['total'] ?? null ) ? $item->get_taxes()['total'] : [];

		if ( is_array( $tax ) ) {
			$mapped = [];

			foreach ( $tax as $rate_id => $amount ) {
				if ( array_key_exists( $rate_id, $original_taxes ) ) {
					$mapped[ $rate_id ] = (float) $amount;
				}
			}

			return $mapped;
		}

		$tax_total = (float) $tax;

		if ( $tax_total <= 0 || empty( $original_taxes ) ) {
			return [];
		}

		$original_total = array_sum( array_map( 'floatval', $original_taxes ) );

		if ( $original_total <= 0 ) {
			return [];
		}

		$mapped    = [];
		$allocated = 0.0;
		$rate_ids  = array_keys( $original_taxes );

		foreach ( $rate_ids as $index => $rate_id ) {
			$amount = $index === count( $rate_ids ) - 1
				? $tax_total - $allocated
				: round( $tax_total * ( (float) $original_taxes[ $rate_id ] / $original_total ), wc_get_price_decimals() );
			$mapped[ $rate_id ] = $amount;
			$allocated += $amount;
		}

		return $mapped;
	}

	private static function requested_tax_total( $tax ) {
		if ( is_array( $tax ) ) {
			foreach ( $tax as $amount ) {
				if ( ! is_numeric( $amount ) || (float) $amount < 0 ) {
					return -1.0;
				}
			}

			return array_sum( array_map( 'floatval', $tax ) );
		}

		return is_numeric( $tax ) ? (float) $tax : -1.0;
	}

	private static function refunded_item_totals( WC_Order $order, $order_item_id ) {
		$totals = [
			'quantity' => 0,
			'amount'   => 0.0,
			'tax'      => 0.0,
		];

		foreach ( $order->get_refunds() as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			foreach ( $refund->get_items( [ 'line_item', 'shipping' ] ) as $refund_item ) {
				if ( absint( $refund_item->get_meta( '_refunded_item_id', true ) ) !== absint( $order_item_id ) ) {
					continue;
				}

				$totals['quantity'] += absint( $refund_item->get_quantity() );
				$totals['amount']   += abs( (float) $refund_item->get_total() );
				$totals['tax']      += abs( (float) $refund_item->get_total_tax() );
			}
		}

		return $totals;
	}

	private static function refunded_item_taxes( WC_Order $order, $order_item_id ) {
		$taxes = [];

		foreach ( $order->get_refunds() as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
				if ( absint( $refund_item->get_meta( '_refunded_item_id', true ) ) !== absint( $order_item_id ) ) {
					continue;
				}

				$item_taxes = is_array( $refund_item->get_taxes()['total'] ?? null ) ? $refund_item->get_taxes()['total'] : [];

				foreach ( $item_taxes as $rate_id => $amount ) {
					$taxes[ $rate_id ] = (float) ( $taxes[ $rate_id ] ?? 0 ) + abs( (float) $amount );
				}
			}
		}

		return $taxes;
	}

	private static function remaining_shipping_amount( WC_Order $order ) {
		$remaining = 0.0;

		foreach ( $order->get_items( 'shipping' ) as $shipping_item_id => $shipping_item ) {
			$refunded = self::refunded_item_totals( $order, $shipping_item_id );
			$remaining += max( 0.0, (float) $shipping_item->get_total() - $refunded['amount'] );
		}

		return round( $remaining, wc_get_price_decimals() );
	}

	private static function remaining_refund_amount( WC_Order $order ) {
		if ( method_exists( $order, 'get_remaining_refund_amount' ) ) {
			return max( 0.0, (float) $order->get_remaining_refund_amount() );
		}

		return max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
	}

	private static function gateway_supports_refunds( WC_Order $order ) {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ $order->get_payment_method() ] ?? null;

		return is_object( $gateway ) && method_exists( $gateway, 'supports' ) && $gateway->supports( 'refunds' );
	}

	private static function idempotency_signature( $contract ) {
		return hash(
			'sha256',
			wp_json_encode(
				[
					'order_id'        => $contract['order_id'],
					'amount'          => $contract['amount'],
					'reason'          => $contract['reason'],
					'refund_payment'  => $contract['refund_payment'],
					'line_items'      => $contract['line_items'],
					'shipping_amount' => $contract['shipping_amount'],
					'restock_items'   => $contract['restock_items'],
				]
			)
		);
	}

	private static function find_idempotent_refund( WC_Order $order, $contract ) {
		if ( '' === $contract['idempotency_key'] ) {
			return null;
		}

		$records = $order->get_meta( self::IDEMPOTENCY_META_KEY, true );
		$records = is_array( $records ) ? $records : [];
		$key     = hash( 'sha256', $contract['idempotency_key'] );
		$record  = $records[ $key ] ?? null;

		if ( ! is_array( $record ) ) {
			return null;
		}

		if ( ! hash_equals( (string) ( $record['signature'] ?? '' ), self::idempotency_signature( $contract ) ) ) {
			return new WP_Error( 'refund_idempotency_key_conflict', __( 'That refund idempotency key was already used with different parameters.', 'yoro-ai-core' ) );
		}

		$refund = wc_get_order( absint( $record['refund_id'] ?? 0 ) );

		return $refund instanceof WC_Order_Refund
			? $refund
			: new WP_Error( 'refund_idempotency_record_invalid', __( 'The saved idempotency record cannot be verified, so the refund retry was stopped.', 'yoro-ai-core' ) );
	}

	private static function acquire_idempotency_lock( WC_Order $order, $contract ) {
		if ( '' === $contract['idempotency_key'] ) {
			return false;
		}

		$option_name = self::idempotency_lock_option_name( $order );
		$lock_record = [
			'order_id'   => $order->get_id(),
			'key_hash'   => hash( 'sha256', $contract['idempotency_key'] ),
			'signature'  => self::idempotency_signature( $contract ),
			'created_at' => gmdate( 'c' ),
		];

		if ( add_option( $option_name, $lock_record, '', false ) ) {
			return true;
		}

		$existing = self::find_idempotent_refund( $order, $contract );

		if ( is_wp_error( $existing ) || $existing instanceof WC_Order_Refund ) {
			return $existing;
		}

		return new WP_Error( 'refund_idempotency_in_progress', __( 'A refund with that idempotency key is already in progress.', 'yoro-ai-core' ) );
	}

	private static function release_idempotency_lock( WC_Order $order, $contract ) {
		if ( '' !== $contract['idempotency_key'] ) {
			delete_option( self::idempotency_lock_option_name( $order ) );
		}
	}

	private static function idempotency_lock_option_name( WC_Order $order ) {
		return self::IDEMPOTENCY_LOCK_OPTION_PREFIX . $order->get_id();
	}

	private static function record_idempotent_refund( WC_Order $order, WC_Order_Refund $refund, $contract ) {
		if ( '' === $contract['idempotency_key'] ) {
			return;
		}

		$records = $order->get_meta( self::IDEMPOTENCY_META_KEY, true );
		$records = is_array( $records ) ? $records : [];
		$key     = hash( 'sha256', $contract['idempotency_key'] );
		$records[ $key ] = [
			'signature'  => self::idempotency_signature( $contract ),
			'refund_id'  => $refund->get_id(),
			'created_at' => gmdate( 'c' ),
		];
		$records = array_slice( $records, -self::IDEMPOTENCY_RECORD_LIMIT, null, true );
		$order->update_meta_data( self::IDEMPOTENCY_META_KEY, $records );
		$order->save();
	}
}
