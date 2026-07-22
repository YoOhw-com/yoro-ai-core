<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Refund_Intelligence_Service {

	public static function get_refund_intelligence( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$params     = is_array( $params ) ? $params : [];
		$window_key = YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_30_days' );
		$window     = YoRo_Date_Helper::resolve_window( $window_key );

		if ( empty( $window ) ) {
			return new WP_Error( 'invalid_refund_report_window', __( 'Please provide a valid refund report time window.', 'yoro-ai-core' ) );
		}

		$limit      = isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 10;
		$current    = self::summarize_window( $window, $limit, true );
		$comparison_window = self::comparison_window_from_params( $params );

		if ( is_wp_error( $comparison_window ) ) {
			return $comparison_window;
		}

		if ( empty( $comparison_window ) ) {
			$comparison_window = YoRo_Date_Helper::build_previous_window( $window );
		}

		$previous   = self::summarize_window( $comparison_window, 0, false );
		$comparison = self::build_comparison( $current['metrics'], $previous['metrics'], $comparison_window );
		$report     = self::build_report( $window, $current, $comparison );
		$summary    = sprintf(
			/* translators: 1: refund count, 2: refund amount, 3: currency, 4: window label */
			__( '%1$d refunds totaling %2$s %3$s were created in %4$s.', 'yoro-ai-core' ),
			$current['metrics']['refund_count'],
			wc_format_decimal( $current['metrics']['refund_amount'], 2 ),
			get_woocommerce_currency(),
			sanitize_text_field( $window['label'] ?? $window_key )
		);

		return [
			'success'       => true,
			'action'        => 'store.get_refund_intelligence',
			'summary'       => $summary,
			'message'       => $summary,
			'window'        => YoRo_Date_Helper::build_window_payload( $window ),
			'metrics'       => self::format_metrics( $current['metrics'] ),
			'comparison'    => $comparison,
			'top_products'  => $current['top_products'],
			'top_reasons'   => $current['top_reasons'],
			'refunds'       => $current['refunds'],
			'report'        => $report,
			'count'         => count( $current['refunds'] ),
			'total_count'   => $current['metrics']['refund_count'],
			'read_only'     => true,
			'processed'     => [],
			'skipped'       => [],
			'errors'        => [],
			'result_type'   => 'report',
			'display_type'  => 'report',
		];
	}

	public static function preview_get_refund_intelligence( $params ) {
		$params     = is_array( $params ) ? $params : [];
		$window_key = YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_30_days' );
		$window     = YoRo_Date_Helper::resolve_window( $window_key );
		$comparison_window = self::comparison_window_from_params( $params );
		$blocked    = empty( $window ) || is_wp_error( $comparison_window );
		$message    = $blocked
			? ( is_wp_error( $comparison_window ) ? $comparison_window->get_error_message() : __( 'Please provide a valid refund report time window.', 'yoro-ai-core' ) )
			: sprintf(
				/* translators: %s: report window label */
				__( 'This will read refund intelligence for %s without changing the store.', 'yoro-ai-core' ),
				sanitize_text_field( $window['label'] ?? $window_key )
			);

		return [
			'type'          => 'read',
			'will_execute'  => ! $blocked,
			'action'        => 'store.get_refund_intelligence',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => $blocked,
			'block_reason'  => $blocked ? ( is_wp_error( $comparison_window ) ? $comparison_window->get_error_code() : 'invalid_refund_report_window' ) : '',
			'window'        => empty( $window ) ? [] : YoRo_Date_Helper::build_window_payload( $window ),
			'limit'         => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 10,
			'changes_store' => false,
		];
	}

	private static function summarize_window( $window, $limit, $include_drilldowns ) {
		$refunds = self::query_refunds(
			[
				'type'         => 'shop_order_refund',
				'date_created' => sanitize_text_field( $window['query'] ?? '' ),
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);
		$product_map      = [];
		$reason_map       = [];
		$refunded_orders  = [];
		$refund_amount    = 0.0;
		$safe_refunds     = [];

		foreach ( $refunds as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			$amount     = abs( (float) $refund->get_amount() );
			$parent_id  = absint( $refund->get_parent_id() );
			$reason     = self::safe_reason( $refund->get_reason() );
			$reason_key = '' !== $reason ? strtolower( $reason ) : '__none__';
			$refund_amount += $amount;

			if ( $parent_id > 0 ) {
				$refunded_orders[ $parent_id ] = true;
			}

			if ( ! isset( $reason_map[ $reason_key ] ) ) {
				$reason_map[ $reason_key ] = [
					'reason' => '' !== $reason ? $reason : __( 'No reason provided', 'yoro-ai-core' ),
					'count'  => 0,
					'amount' => 0.0,
				];
			}

			$reason_map[ $reason_key ]['count']++;
			$reason_map[ $reason_key ]['amount'] += $amount;

			foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
				$product_id = absint( $refund_item->get_product_id() );
				$key        = $product_id > 0 ? (string) $product_id : 'item:' . md5( $refund_item->get_name() );

				if ( ! isset( $product_map[ $key ] ) ) {
					$product_map[ $key ] = [
						'product_id'   => $product_id,
						'variation_id' => absint( $refund_item->get_variation_id() ),
						'name'         => sanitize_text_field( $refund_item->get_name() ),
						'quantity'     => 0,
						'refund_count' => 0,
						'amount'       => 0.0,
					];
				}

				$product_map[ $key ]['quantity'] += absint( $refund_item->get_quantity() );
				$product_map[ $key ]['refund_count']++;
				$product_map[ $key ]['amount'] += abs( (float) $refund_item->get_total() ) + abs( (float) $refund_item->get_total_tax() );
			}

			if ( $include_drilldowns && count( $safe_refunds ) < $limit ) {
				$created = $refund->get_date_created();
				$safe_refunds[] = [
					'refund_id'   => $refund->get_id(),
					'order_id'    => $parent_id,
					'amount'      => wc_format_decimal( $amount, 2 ),
					'currency'    => sanitize_text_field( $refund->get_currency() ),
					'reason'      => '' !== $reason ? $reason : __( 'No reason provided', 'yoro-ai-core' ),
					'created_at'  => $created ? $created->date( DATE_ATOM ) : '',
				];
			}
		}

		$revenue_orders = YoRo_Orders_Read_Service::report_query_orders(
			[
				'status'       => YoRo_Orders_Read_Service::briefing_get_revenue_order_status_slugs(),
				'date_created' => sanitize_text_field( $window['query'] ?? '' ),
			],
			20000,
			250
		);
		$gross_sales = 0.0;
		$revenue_order_ids = [];

		foreach ( $revenue_orders as $order ) {
			if ( $order instanceof WC_Order && YoRo_Orders_Read_Service::briefing_is_revenue_order_status( $order->get_status() ) ) {
				$gross_sales += (float) $order->get_total();
				$revenue_order_ids[ $order->get_id() ] = true;
			}
		}
		$refunded_revenue_orders = array_intersect_key( $refunded_orders, $revenue_order_ids );

		uasort( $product_map, static fn( $left, $right ) => $right['amount'] <=> $left['amount'] );
		uasort( $reason_map, static fn( $left, $right ) => $right['amount'] <=> $left['amount'] );

		return [
			'metrics'      => [
				'refund_count'        => count( $refunds ),
				'refunded_orders'      => count( $refunded_orders ),
				'refunded_revenue_orders' => count( $refunded_revenue_orders ),
				'refund_amount'        => $refund_amount,
				'refund_amount_rate'   => $gross_sales > 0 ? ( $refund_amount / $gross_sales ) * 100 : 0.0,
				'refunded_order_rate'  => count( $revenue_order_ids ) > 0 ? ( count( $refunded_revenue_orders ) / count( $revenue_order_ids ) ) * 100 : 0.0,
				'gross_sales'          => $gross_sales,
				'revenue_order_count'  => count( $revenue_orders ),
			],
			'top_products' => array_map( [ __CLASS__, 'format_product' ], array_slice( array_values( $product_map ), 0, 10 ) ),
			'top_reasons'  => array_map( [ __CLASS__, 'format_reason' ], array_slice( array_values( $reason_map ), 0, 10 ) ),
			'refunds'      => $safe_refunds,
		];
	}

	private static function build_comparison( $current, $previous, $previous_window ) {
		$comparison = [
			'window'  => YoRo_Date_Helper::build_window_payload( $previous_window ),
			'metrics' => [],
		];

		foreach ( [ 'refund_count', 'refunded_orders', 'refund_amount', 'refund_amount_rate', 'refunded_order_rate' ] as $key ) {
			$comparison['metrics'][ $key ] = array_merge(
				YoRo_Report_Builder::metric_comparison( $current[ $key ] ?? 0, $previous[ $key ] ?? 0 ),
				[
					'current'  => self::format_metric_value( $key, $current[ $key ] ?? 0 ),
					'previous' => self::format_metric_value( $key, $previous[ $key ] ?? 0 ),
				]
			);
		}

		return $comparison;
	}

	private static function build_report( $window, $current, $comparison ) {
		$metrics    = $current['metrics'];
		$currency   = get_woocommerce_currency();
		$comparisons = $comparison['metrics'] ?? [];
		$report_metrics = [
			YoRo_Report_Builder::metric( 'refund_amount', __( 'Refund amount', 'yoro-ai-core' ), wc_format_decimal( $metrics['refund_amount'], 2 ), $currency, 'currency', $metrics['refund_amount'] > 0 ? 'watch' : 'neutral', $comparisons['refund_amount']['trend'] ?? '', $comparisons['refund_amount']['delta'] ?? null, 'store', 'refunds' ),
			YoRo_Report_Builder::metric( 'refund_count', __( 'Refunds', 'yoro-ai-core' ), $metrics['refund_count'], 'refunds', 'number', $metrics['refund_count'] > 0 ? 'watch' : 'neutral', $comparisons['refund_count']['trend'] ?? '', $comparisons['refund_count']['delta'] ?? null, 'store', 'refunds' ),
			YoRo_Report_Builder::metric( 'refunded_orders', __( 'Refunded orders', 'yoro-ai-core' ), $metrics['refunded_orders'], 'orders', 'number', 'neutral', $comparisons['refunded_orders']['trend'] ?? '', $comparisons['refunded_orders']['delta'] ?? null, 'store', 'refunds' ),
			YoRo_Report_Builder::metric( 'refund_amount_rate', __( 'Refund amount rate', 'yoro-ai-core' ), wc_format_decimal( $metrics['refund_amount_rate'], 1 ), '%', 'percent', $metrics['refund_amount_rate'] > 0 ? 'watch' : 'neutral', $comparisons['refund_amount_rate']['trend'] ?? '', $comparisons['refund_amount_rate']['delta'] ?? null, 'store', 'refunds', __( 'Refund amount created in this window divided by gross sales from orders created in the same window.', 'yoro-ai-core' ) ),
			YoRo_Report_Builder::metric( 'refunded_order_rate', __( 'Refunded order rate', 'yoro-ai-core' ), wc_format_decimal( $metrics['refunded_order_rate'], 1 ), '%', 'percent', $metrics['refunded_order_rate'] > 0 ? 'watch' : 'neutral', $comparisons['refunded_order_rate']['trend'] ?? '', $comparisons['refunded_order_rate']['delta'] ?? null, 'store', 'refunds', __( 'Share of revenue orders created in this window that also have a refund created in this window.', 'yoro-ai-core' ) ),
		];
		$breakdowns = [
			YoRo_Report_Builder::breakdown(
				'top_refunded_products',
				__( 'Top refunded products', 'yoro-ai-core' ),
				array_map(
					static fn( $item ) => YoRo_Report_Builder::breakdown_item( $item['name'], $item['amount'], $currency, sprintf( __( '%1$d units across %2$d refund lines', 'yoro-ai-core' ), $item['quantity'], $item['refund_count'] ) ),
					$current['top_products']
				),
				'refunds'
			),
			YoRo_Report_Builder::breakdown(
				'top_refund_reasons',
				__( 'Top refund reasons', 'yoro-ai-core' ),
				array_map(
					static fn( $item ) => YoRo_Report_Builder::breakdown_item( $item['reason'], $item['count'], 'refunds', $item['amount'] . ' ' . get_woocommerce_currency() ),
					$current['top_reasons']
				),
				'refunds'
			),
		];
		$drilldowns = [];

		foreach ( array_slice( $current['refunds'], 0, 5 ) as $refund ) {
			$drilldowns[] = YoRo_Report_Builder::action(
				sprintf( __( 'Review refund #%d', 'yoro-ai-core' ), absint( $refund['refund_id'] ) ),
				sprintf( __( 'Show refund details for order #%d', 'yoro-ai-core' ), absint( $refund['order_id'] ) ),
				'view',
				'refunds'
			);
		}

		$report = YoRo_Report_Builder::build(
			'refund_intelligence',
			$window,
			$metrics['refund_count'] > 0 ? 'watch' : 'healthy',
			sprintf( __( '%1$d refunds totaling %2$s %3$s.', 'yoro-ai-core' ), $metrics['refund_count'], wc_format_decimal( $metrics['refund_amount'], 2 ), $currency ),
			$report_metrics,
			[],
			[],
			[],
			$drilldowns,
			$breakdowns
		);
		$report['comparison'] = $comparison;

		return $report;
	}

	private static function comparison_window_from_params( $params ) {
		$has_comparison = is_array( $params['time_window'] ?? null ) && array_key_exists( 'comparison', $params['time_window'] );

		if ( ! $has_comparison ) {
			return [];
		}

		$comparison = is_array( $params['time_window']['comparison'] ?? null ) ? $params['time_window']['comparison'] : [];

		if ( empty( $comparison ) ) {
			return new WP_Error( 'invalid_refund_report_comparison', __( 'The refund comparison window is invalid or incomplete.', 'yoro-ai-core' ) );
		}

		$key = YoRo_Date_Helper::normalize_window_key( $comparison['canonical_key'] ?? $comparison['key'] ?? '' );

		if ( '' !== $key ) {
			return YoRo_Date_Helper::resolve_window( $key );
		}

		$start = sanitize_text_field( $comparison['start'] ?? $comparison['start_date'] ?? '' );
		$end   = sanitize_text_field( $comparison['end'] ?? $comparison['end_date'] ?? '' );

		if ( '' === $start || '' === $end ) {
			return new WP_Error( 'invalid_refund_report_comparison', __( 'The refund comparison window is invalid or incomplete.', 'yoro-ai-core' ) );
		}

		try {
			$timezone       = wp_timezone();
			$start_datetime = new DateTimeImmutable( $start, $timezone );
			$end_datetime   = new DateTimeImmutable( $end, $timezone );
		} catch ( Exception $exception ) {
			return new WP_Error( 'invalid_refund_report_comparison', __( 'The refund comparison window is invalid or incomplete.', 'yoro-ai-core' ) );
		}

		if ( $end_datetime < $start_datetime ) {
			return new WP_Error( 'invalid_refund_report_comparison', __( 'The refund comparison window is invalid or incomplete.', 'yoro-ai-core' ) );
		}

		return [
			'key'       => 'comparison',
			'label'     => sanitize_text_field( $comparison['label'] ?? __( 'Comparison period', 'yoro-ai-core' ) ),
			'date_from' => $start_datetime->format( 'Y-m-d' ),
			'date_to'   => $end_datetime->format( 'Y-m-d' ),
			'start'     => $start_datetime->format( 'Y-m-d H:i:s' ),
			'end'       => $end_datetime->format( 'Y-m-d H:i:s' ),
			'start_ts'  => $start_datetime->getTimestamp(),
			'end_ts'    => $end_datetime->getTimestamp(),
			'query'     => $start_datetime->format( 'Y-m-d H:i:s' ) . '...' . $end_datetime->format( 'Y-m-d H:i:s' ),
			'timezone'  => wp_timezone_string(),
		];
	}

	private static function safe_reason( $reason ) {
		return mb_substr( sanitize_text_field( (string) $reason ), 0, 160 );
	}

	private static function format_product( $product ) {
		return [
			'product_id'   => absint( $product['product_id'] ?? 0 ),
			'variation_id' => absint( $product['variation_id'] ?? 0 ),
			'name'         => sanitize_text_field( $product['name'] ?? '' ),
			'quantity'     => absint( $product['quantity'] ?? 0 ),
			'refund_count' => absint( $product['refund_count'] ?? 0 ),
			'amount'       => wc_format_decimal( $product['amount'] ?? 0, 2 ),
		];
	}

	private static function format_reason( $reason ) {
		return [
			'reason' => self::safe_reason( $reason['reason'] ?? '' ),
			'count'  => absint( $reason['count'] ?? 0 ),
			'amount' => wc_format_decimal( $reason['amount'] ?? 0, 2 ),
		];
	}

	private static function format_metrics( $metrics ) {
		return [
			'refund_count'       => absint( $metrics['refund_count'] ?? 0 ),
			'refunded_orders'     => absint( $metrics['refunded_orders'] ?? 0 ),
			'refunded_revenue_orders' => absint( $metrics['refunded_revenue_orders'] ?? 0 ),
			'refund_amount'       => wc_format_decimal( $metrics['refund_amount'] ?? 0, 2 ),
			'refund_amount_rate'  => wc_format_decimal( $metrics['refund_amount_rate'] ?? 0, 1 ),
			'refunded_order_rate' => wc_format_decimal( $metrics['refunded_order_rate'] ?? 0, 1 ),
			'gross_sales'         => wc_format_decimal( $metrics['gross_sales'] ?? 0, 2 ),
			'revenue_order_count' => absint( $metrics['revenue_order_count'] ?? 0 ),
		];
	}

	private static function format_metric_value( $key, $value ) {
		return wc_format_decimal( $value, in_array( $key, [ 'refund_amount', 'refund_amount_rate', 'refunded_order_rate' ], true ) ? 2 : 0 );
	}

	private static function query_refunds( $args, $max_refunds = 20000, $page_size = 250 ) {
		$args        = is_array( $args ) ? $args : [];
		$max_refunds = min( max( absint( $max_refunds ), 1 ), 20000 );
		$page_size   = min( max( absint( $page_size ), 1 ), 500 );
		$refunds     = [];
		$page        = 1;

		unset( $args['offset'], $args['page'], $args['paged'], $args['paginate'] );

		while ( count( $refunds ) < $max_refunds ) {
			$query_args             = $args;
			$query_args['limit']    = min( $page_size, $max_refunds - count( $refunds ) );
			$query_args['page']     = $page;
			$query_args['paginate'] = true;
			$result                 = wc_get_orders( $query_args );
			$page_refunds           = is_object( $result ) && isset( $result->orders ) ? $result->orders : $result;
			$page_refunds           = is_array( $page_refunds ) ? $page_refunds : [];
			$max_pages              = is_object( $result ) && isset( $result->max_num_pages ) ? absint( $result->max_num_pages ) : 0;

			foreach ( $page_refunds as $refund ) {
				if ( $refund instanceof WC_Order_Refund ) {
					$refunds[] = $refund;
				}
			}

			if ( empty( $page_refunds ) || ( $max_pages > 0 && $page >= $max_pages ) || ( 0 === $max_pages && count( $page_refunds ) < $query_args['limit'] ) ) {
				break;
			}

			$page++;
		}

		return $refunds;
	}
}
