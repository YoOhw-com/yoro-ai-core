<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Order_Refund_Service {

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

		$error = self::validate_refund_against_order( $contract, $order );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$refund = wc_create_refund(
			[
				'amount'         => $contract['amount'],
				'reason'         => $contract['reason'],
				'order_id'       => $order->get_id(),
				'refund_payment' => $contract['refund_payment'],
				'restock_items'  => false,
			]
		);

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		if ( ! $refund instanceof WC_Order_Refund ) {
			return new WP_Error( 'refund_create_failed', __( 'WooCommerce did not return a saved refund.', 'yoro-ai-core' ) );
		}

		$summary = sprintf(
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
				'success'        => true,
				'action'         => 'orders.create_refund',
				'summary'        => $summary,
				'message'        => $summary,
				'order_id'       => $order->get_id(),
				'refund_id'      => $refund->get_id(),
				'refund_amount'  => wc_format_decimal( $contract['amount'], 2 ),
				'refund_payment' => $contract['refund_payment'],
				'reason'         => $contract['reason'],
				'processed'      => [ $refund->get_id() ],
				'skipped'        => [],
				'errors'         => [],
				'count'          => 1,
				'read_only'      => false,
				'undoable'       => false,
				'undo_plan'      => [],
				'result_type'    => 'refund',
				'display_type'   => 'refund_preview',
				'meta'           => [
					'changes_store'      => true,
					'external_payment'   => $contract['refund_payment'],
					'irreversible_action' => true,
				],
			]
		);
	}

	public static function preview_create_refund( $params ) {
		$contract = self::normalize_refund_contract( $params );
		$error    = self::validate_refund_contract( $contract );
		$order    = $contract['order_id'] > 0 ? wc_get_order( $contract['order_id'] ) : false;
		$block_reason = '';

		if ( is_wp_error( $error ) ) {
			$block_reason = $error->get_error_code();
			$message      = $error->get_error_message();
		} elseif ( ! $order ) {
			$block_reason = 'order_not_found';
			$message      = __( 'I could not find that order.', 'yoro-ai-core' );
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

		$remaining = $order ? self::remaining_refund_amount( $order ) : 0.0;

		return [
			'type'               => 'write',
			'will_execute'       => false,
			'action'             => 'orders.create_refund',
			'summary'            => $message,
			'message'            => $message,
			'blocked'            => '' !== $block_reason,
			'block_reason'       => $block_reason,
			'order_id'           => $contract['order_id'],
			'order_exists'       => (bool) $order,
			'refund_amount'      => wc_format_decimal( $contract['amount'], 2 ),
			'refund_payment'     => $contract['refund_payment'],
			'refund_payment_set' => $contract['refund_payment_set'],
			'reason'             => $contract['reason'],
			'remaining_amount'   => wc_format_decimal( $remaining, 2 ),
			'currency'           => $order ? $order->get_currency() : '',
			'external_payment'   => $contract['refund_payment'],
			'irreversible'       => true,
			'count'              => $order && '' === $block_reason ? 1 : 0,
			'changes_store'      => '' === $block_reason,
		];
	}

	private static function normalize_refund_contract( $params ) {
		$params = is_array( $params ) ? $params : [];
		$refund_payment_set = array_key_exists( 'refund_payment', $params );
		$refund_payment     = false;
		$refund_payment_valid = true;

		if ( $refund_payment_set ) {
			$refund_payment = filter_var( $params['refund_payment'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$refund_payment_valid = null !== $refund_payment;
			$refund_payment = true === $refund_payment;
		}

		return [
			'order_id'             => absint( $params['order_id'] ?? 0 ),
			'amount'               => isset( $params['amount'] ) && is_numeric( $params['amount'] ) ? round( (float) $params['amount'], wc_get_price_decimals() ) : 0.0,
			'reason'               => sanitize_textarea_field( $params['reason'] ?? '' ),
			'refund_payment'       => $refund_payment,
			'refund_payment_set'   => $refund_payment_set,
			'refund_payment_valid' => $refund_payment_valid,
		];
	}

	private static function validate_refund_contract( $contract ) {
		if ( $contract['order_id'] <= 0 ) {
			return new WP_Error( 'missing_order_target', __( 'Please choose an order first.', 'yoro-ai-core' ) );
		}

		if ( $contract['amount'] <= 0 ) {
			return new WP_Error( 'invalid_refund_amount', __( 'Please provide a refund amount greater than zero.', 'yoro-ai-core' ) );
		}

		if ( ! $contract['refund_payment_set'] ) {
			return new WP_Error(
				'refund_payment_choice_required',
				__( 'Choose whether WooCommerce should send the refund through the payment gateway.', 'yoro-ai-core' )
			);
		}

		if ( ! $contract['refund_payment_valid'] ) {
			return new WP_Error( 'invalid_refund_payment', __( 'The refund payment choice must be true or false.', 'yoro-ai-core' ) );
		}

		return true;
	}

	private static function validate_refund_against_order( $contract, WC_Order $order ) {
		$remaining = self::remaining_refund_amount( $order );

		if ( $remaining <= 0 ) {
			return new WP_Error( 'order_not_refundable', __( 'This order has no remaining refundable amount.', 'yoro-ai-core' ) );
		}

		if ( $contract['amount'] > $remaining ) {
			return new WP_Error(
				'refund_amount_exceeds_remaining',
				sprintf(
					/* translators: %s: remaining refundable amount */
					__( 'The refund amount exceeds the remaining refundable amount of %s.', 'yoro-ai-core' ),
					wc_format_decimal( $remaining, 2 )
				)
			);
		}

		if ( $contract['refund_payment'] && '' === trim( (string) $order->get_transaction_id() ) ) {
			return new WP_Error(
				'refund_transaction_missing',
				__( 'The order has no payment transaction ID, so an automatic gateway refund cannot be attempted.', 'yoro-ai-core' )
			);
		}

		if ( $contract['refund_payment'] && ! self::gateway_supports_refunds( $order ) ) {
			return new WP_Error(
				'refund_gateway_unsupported',
				__( 'The order payment gateway does not support automatic refunds.', 'yoro-ai-core' )
			);
		}

		return true;
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

		return is_object( $gateway )
			&& method_exists( $gateway, 'supports' )
			&& $gateway->supports( 'refunds' );
	}
}
