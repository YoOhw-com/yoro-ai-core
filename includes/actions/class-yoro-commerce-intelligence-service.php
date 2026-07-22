<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Commerce_Intelligence_Service {

	const MAX_ORDERS = 10000;

	public static function get_inventory_risk( $params ) {
		$params = is_array( $params ) ? $params : [];
		$window_key = YoRo_Date_Helper::window_key_from_params( $params, 'window', 'today' );
		$requested_window = YoRo_Date_Helper::resolve_window( $window_key );

		if ( empty( $requested_window ) ) {
			return new WP_Error( 'invalid_inventory_risk_window', __( 'Please provide a valid inventory risk time window.', 'yoro-ai-core' ) );
		}

		if ( self::has_explicit_time_window( $params ) && 'today' !== $window_key ) {
			return new WP_Error(
				'historical_inventory_risk_unsupported',
				__( 'Inventory risk currently supports only a live snapshot. Choose the current view or use a historical inventory provider.', 'yoro-ai-core' )
			);
		}

		$queue  = YoRo_Store_Inventory_Service::get_inventory_queue( $params );

		if ( is_wp_error( $queue ) ) {
			return $queue;
		}

		$risks = [];

		foreach ( (array) ( $queue['queues'] ?? [] ) as $queue_item ) {
			$key = sanitize_key( $queue_item['key'] ?? '' );

			foreach ( (array) ( $queue_item['products'] ?? [] ) as $product ) {
				$product_id = absint( $product['id'] ?? $product['product_id'] ?? 0 );
				$quantity   = isset( $product['stock_quantity'] ) && is_numeric( $product['stock_quantity'] )
					? (int) $product['stock_quantity']
					: null;
				$threshold  = isset( $product['low_stock_amount'] ) && is_numeric( $product['low_stock_amount'] )
					? max( 0, (int) $product['low_stock_amount'] )
					: null;

				$risks[] = [
					'product_id'              => $product_id,
					'name'                    => sanitize_text_field( $product['name'] ?? '' ),
					'sku'                     => sanitize_text_field( $product['sku'] ?? '' ),
					'risk_type'               => $key,
					'current_stock_quantity'  => $quantity,
					'low_stock_threshold'     => $threshold,
					'reorder_proposal'        => [
						'action'            => 'products.update_stock',
						'product_id'        => $product_id,
						'target_quantity'   => null,
						'requires_operator_input' => true,
						'execution_allowed' => false,
					],
					'evidence_basis'          => 'current_stock_and_live_low_stock_threshold',
				];
			}
		}

		$summary = sprintf(
			/* translators: %d: inventory risk count */
			__( 'Found %d live inventory risk signals. Reorder quantities remain proposals until an operator provides and confirms a target.', 'yoro-ai-core' ),
			count( $risks )
		);

		return [
			'success'        => true,
			'action'         => 'store.get_inventory_risk',
			'summary'        => $summary,
			'message'        => $summary,
			'risks'          => array_slice( $risks, 0, 50 ),
			'metrics'        => [
				'risk_signal_count' => count( $risks ),
				'queue_count'       => absint( $queue['queue_count'] ?? 0 ),
			],
			'source'         => [
				'type'       => 'live_store_inventory',
				'basis'      => 'current_stock_and_live_low_stock_threshold',
				'forecasted' => false,
			],
			'coverage_contract' => [
				'schema_version'     => 1,
				'coverage_mode'      => 'live_snapshot',
				'time_window_support'=> 'current_only',
				'evidence_mode'      => 'live_inventory_thresholds',
				'requested_window'   => YoRo_Date_Helper::build_window_payload( $requested_window ),
				'observation_kind'   => 'live_snapshot',
				'historical_evidence' => false,
				'observed_at'        => gmdate( DATE_ATOM ),
			],
			'read_only'      => true,
			'changes_store'  => false,
			'processed'      => [],
			'skipped'        => [],
			'errors'         => [],
			'count'          => count( $risks ),
			'result_type'    => 'report',
			'display_type'   => 'report',
		];
	}

	public static function get_customer_lifecycle( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$params     = is_array( $params ) ? $params : [];
		$window_key = YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_30_days' );
		$window     = YoRo_Date_Helper::resolve_window( $window_key );

		if ( empty( $window ) ) {
			return new WP_Error( 'invalid_customer_lifecycle_window', __( 'Please provide a valid customer lifecycle time window.', 'yoro-ai-core' ) );
		}

		$orders = self::query_orders( [
			'date_created' => sanitize_text_field( $window['query'] ?? '' ),
			'orderby'      => 'date',
			'order'        => 'DESC',
		] );
		$customers = [];

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$customer_id = absint( $order->get_customer_id() );
			$email       = strtolower( sanitize_email( $order->get_billing_email() ) );
			$key         = $customer_id > 0 ? 'customer:' . $customer_id : 'email:' . $email;

			if ( 'email:' === $key ) {
				$key = 'order:' . $order->get_id();
			}

			if ( ! isset( $customers[ $key ] ) ) {
				$customers[ $key ] = [
					'customer_id' => $customer_id,
					'email'       => $email,
					'order_count' => 0,
					'total'       => 0.0,
					'last_order_at' => '',
				];
			}

			$customers[ $key ]['order_count']++;
			$customers[ $key ]['total'] += (float) $order->get_total();
			$date = $order->get_date_created();

			if ( $date && '' === $customers[ $key ]['last_order_at'] ) {
				$customers[ $key ]['last_order_at'] = $date->date( DATE_ATOM );
			}
		}

		$repeat = count( array_filter( $customers, static fn( $customer ) => absint( $customer['order_count'] ?? 0 ) > 1 ) );
		$single = count( $customers ) - $repeat;
		$summary = sprintf(
			/* translators: 1: customer count, 2: repeat customer count */
			__( 'Observed %1$d customer identities in the selected window; %2$d placed more than one order in that same window.', 'yoro-ai-core' ),
			count( $customers ),
			$repeat
		);

		return [
			'success'       => true,
			'action'        => 'store.get_customer_lifecycle',
			'summary'       => $summary,
			'message'       => $summary,
			'window'        => YoRo_Date_Helper::build_window_payload( $window ),
			'metrics'       => [
				'customer_identities'       => count( $customers ),
				'repeat_in_window'           => $repeat,
				'single_order_in_window'     => $single,
				'orders'                     => count( $orders ),
			],
			'customers'     => array_slice( array_values( $customers ), 0, 20 ),
			'classification_contract' => [
				'repeat_in_window'       => 'more_than_one_order_in_selected_window',
				'single_order_in_window' => 'exactly_one_order_in_selected_window',
				'new_customer_claimed'    => false,
				'at_risk_claimed'         => false,
			],
			'coverage_contract' => [
				'schema_version'      => 1,
				'coverage_mode'       => 'bounded_history',
				'time_window_support' => 'canonical_windows',
				'evidence_mode'       => 'order_history',
				'requested_window'    => YoRo_Date_Helper::build_window_payload( $window ),
				'observation_kind'    => 'historical_window',
				'historical_evidence'=> true,
				'observed_at'         => gmdate( DATE_ATOM ),
			],
			'read_only'     => true,
			'changes_store' => false,
			'processed'     => [],
			'skipped'       => [],
			'errors'        => [],
			'count'         => count( $customers ),
			'result_type'   => 'report',
			'display_type'  => 'report',
		];
	}

	public static function get_returns_intelligence( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$params     = is_array( $params ) ? $params : [];
		$window_key = YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_30_days' );
		$window     = YoRo_Date_Helper::resolve_window( $window_key );

		if ( empty( $window ) ) {
			return new WP_Error( 'invalid_returns_window', __( 'Please provide a valid returns time window.', 'yoro-ai-core' ) );
		}

		$orders = self::query_orders( [
			'orderby' => 'date',
			'order'   => 'DESC',
		] );
		$start   = isset( $window['start'] ) && $window['start'] instanceof DateTimeInterface ? $window['start']->getTimestamp() : 0;
		$end     = isset( $window['end'] ) && $window['end'] instanceof DateTimeInterface ? $window['end']->getTimestamp() : PHP_INT_MAX;
		$requests = [];
		$by_type = [];
		$by_resolution = [];
		$by_status = [];
		$event_count = 0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			foreach ( (array) $order->get_meta( YoRo_Order_Return_Service::REQUESTS_META_KEY, true ) as $request ) {
				$timestamp = strtotime( sanitize_text_field( $request['requested_at'] ?? '' ) );

				if ( false === $timestamp || $timestamp < $start || $timestamp > $end ) {
					continue;
				}

				$type       = sanitize_key( $request['type'] ?? 'return' );
				$resolution = sanitize_key( $request['resolution'] ?? 'review' );
				$status     = sanitize_key( $request['status'] ?? 'requested' );
				$events     = array_values( array_filter( (array) ( $request['events'] ?? [] ), 'is_array' ) );
				$by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + 1;
				$by_resolution[ $resolution ] = ( $by_resolution[ $resolution ] ?? 0 ) + 1;
				$by_status[ $status ] = ( $by_status[ $status ] ?? 0 ) + 1;
				$event_count += count( $events );
				$requests[] = [
					'request_id'   => sanitize_text_field( $request['request_id'] ?? '' ),
					'order_id'     => $order->get_id(),
					'type'         => $type,
					'resolution'   => $resolution,
					'status'       => $status,
					'item_count'   => count( (array) ( $request['line_items'] ?? [] ) ),
					'event_count'  => count( $events ),
					'latest_event' => empty( $events ) ? null : end( $events ),
					'requested_at' => gmdate( DATE_ATOM, $timestamp ),
					'updated_at'   => sanitize_text_field( $request['updated_at'] ?? $request['requested_at'] ?? '' ),
				];
			}
		}

		$summary = sprintf(
			/* translators: %d: return request count */
			__( 'Found %d recorded return or exchange requests in the selected window.', 'yoro-ai-core' ),
			count( $requests )
		);

		return [
			'success'       => true,
			'action'        => 'store.get_returns_intelligence',
			'summary'       => $summary,
			'message'       => $summary,
			'window'        => YoRo_Date_Helper::build_window_payload( $window ),
			'metrics'       => [
				'request_count' => count( $requests ),
				'by_type'       => $by_type,
				'by_resolution' => $by_resolution,
				'by_status'     => $by_status,
				'event_count'   => $event_count,
			],
			'requests'      => array_slice( $requests, 0, 20 ),
			'coverage_contract' => [
				'schema_version'      => 1,
				'coverage_mode'       => 'bounded_history',
				'time_window_support' => 'canonical_windows',
				'evidence_mode'       => 'return_request_events',
				'requested_window'    => YoRo_Date_Helper::build_window_payload( $window ),
				'observation_kind'    => 'historical_window',
				'historical_evidence'=> true,
				'observed_at'         => gmdate( DATE_ATOM ),
			],
			'read_only'     => true,
			'changes_store' => false,
			'processed'     => [],
			'skipped'       => [],
			'errors'        => [],
			'count'         => count( $requests ),
			'result_type'   => 'report',
			'display_type'  => 'report',
		];
	}

	public static function preview_read( $action, $params, $default_window = 'last_30_days' ) {
		$params     = is_array( $params ) ? $params : [];
		$window_key = YoRo_Date_Helper::window_key_from_params( $params, 'window', $default_window );
		$window     = YoRo_Date_Helper::resolve_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => ! empty( $window ),
			'action'        => sanitize_text_field( $action ),
			'blocked'       => empty( $window ),
			'block_reason'  => empty( $window ) ? 'invalid_time_window' : '',
			'window'        => empty( $window ) ? [] : YoRo_Date_Helper::build_window_payload( $window ),
			'changes_store' => false,
		];
	}

	private static function query_orders( $args ) {
		$orders = [];
		$page   = 1;

		do {
			$result = wc_get_orders( array_merge( $args, [
				'limit'    => min( 250, self::MAX_ORDERS - count( $orders ) ),
				'page'     => $page,
				'paginate' => true,
			] ) );
			$batch = is_object( $result ) && isset( $result->orders ) ? $result->orders : $result;
			$batch = is_array( $batch ) ? $batch : [];
			$orders = array_merge( $orders, $batch );
			++$page;
		} while ( ! empty( $batch ) && count( $orders ) < self::MAX_ORDERS && ( ! is_object( $result ) || $page <= absint( $result->max_num_pages ?? 0 ) ) );

		return array_slice( $orders, 0, self::MAX_ORDERS );
	}

	private static function has_explicit_time_window( $params ) {
		return is_array( $params )
			&& ( ! empty( $params['time_window'] ) || ! empty( $params['window'] ) || ! empty( $params['date_window'] ) );
	}
}
