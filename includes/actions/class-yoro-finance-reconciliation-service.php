<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Finance_Reconciliation_Service {

	const MAX_ORDERS = 1000;

	public static function get_summary( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$params     = is_array( $params ) ? $params : [];
		$window_key = YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_30_days' );
		$window     = YoRo_Date_Helper::resolve_window( $window_key );

		if ( empty( $window ) ) {
			return new WP_Error( 'invalid_finance_window', __( 'Please provide a valid finance reconciliation time window.', 'yoro-ai-core' ) );
		}

		$orders = wc_get_orders(
			[
				'limit'        => self::MAX_ORDERS + 1,
				'status'       => array_values( array_unique( array_merge( wc_get_is_paid_statuses(), [ 'refunded' ] ) ) ),
				'date_created' => sanitize_text_field( $window['query'] ?? '' ),
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);
		$truncated = count( $orders ) > self::MAX_ORDERS;
		$orders    = array_slice( $orders, 0, self::MAX_ORDERS );
		$totals    = [
			'order_total' => 0.0,
			'refunded'    => 0.0,
			'tax'         => 0.0,
			'shipping'    => 0.0,
			'net_collected'=> 0.0,
		];
		$payment_methods = [];

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order_total = (float) $order->get_total();
			$refunded    = (float) $order->get_total_refunded();
			$method      = sanitize_key( $order->get_payment_method() ) ?: 'unknown';

			$totals['order_total']  += $order_total;
			$totals['refunded']     += $refunded;
			$totals['tax']          += (float) $order->get_total_tax();
			$totals['shipping']     += (float) $order->get_shipping_total();
			$totals['net_collected']+= $order_total - $refunded;
			$payment_methods[ $method ] = ( $payment_methods[ $method ] ?? 0.0 ) + $order_total - $refunded;
		}

		$currency = get_woocommerce_currency();
		$formatted_totals = array_map( static fn( $value ) => wc_format_decimal( $value, 2 ), $totals );
		$formatted_methods = array_map( static fn( $value ) => wc_format_decimal( $value, 2 ), $payment_methods );

		return [
			'success'       => true,
			'action'        => 'finance.get_reconciliation_summary',
			'window'        => YoRo_Date_Helper::build_window_payload( $window ),
			'currency'      => $currency,
			'order_count'   => count( $orders ),
			'totals'        => $formatted_totals,
			'payment_methods'=> $formatted_methods,
			'payouts'       => [
				'available' => false,
				'reason'    => 'no_registered_payout_adapter',
			],
			'coverage_contract' => [
				'schema_version'       => 1,
				'coverage_mode'        => 'bounded_history',
				'time_window_support'  => 'canonical_windows',
				'evidence_mode'        => 'woocommerce_order_ledger',
				'record_limit'         => self::MAX_ORDERS,
				'truncated'            => $truncated,
				'payout_evidence'      => false,
				'observed_at'          => gmdate( DATE_ATOM ),
			],
			'count'         => count( $orders ),
			'read_only'     => true,
			'changes_store' => false,
			'result_type'   => 'finance_reconciliation',
			'display_type'  => 'report',
		];
	}

	public static function preview( $params ) {
		$params     = is_array( $params ) ? $params : [];
		$window_key = YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_30_days' );

		return [
			'type'          => 'read',
			'will_execute'  => ! empty( YoRo_Date_Helper::resolve_window( $window_key ) ),
			'action'        => 'finance.get_reconciliation_summary',
			'changes_store' => false,
		];
	}
}
