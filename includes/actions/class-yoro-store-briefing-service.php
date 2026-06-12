<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Briefing_Service {

	public static function get_business_summary( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$window_key = YoRo_Orders_Read_Service::briefing_normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_7_days' ) );
		$focus      = ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview';
		$window     = YoRo_Orders_Read_Service::briefing_get_operations_window( $window_key );

		$orders = YoRo_Orders_Read_Service::report_query_orders(
			[
				'status'       => YoRo_Orders_Read_Service::briefing_get_revenue_order_status_slugs(),
				'date_created' => $window['query'],
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);

		$order_count         = 0;
		$order_total         = 0.0;
		$discount_total      = 0.0;
		$refund_total        = 0.0;
		$shipping_total      = 0.0;
		$tax_total           = 0.0;
		$items_sold          = 0.0;
		$customer_map        = [];
		$product_map         = [];
		$category_map        = [];
		$category_cache      = [];
		$order_status_counts  = [];
		$excluded_order_count = 0;
		$excluded_order_total = 0.0;
		$recent_orders        = [];

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$status = $order->get_status();

			if ( ! isset( $order_status_counts[ $status ] ) ) {
				$order_status_counts[ $status ] = 0;
			}

			$order_status_counts[ $status ]++;

			$total       = (float) $order->get_total();

			if ( ! YoRo_Orders_Read_Service::briefing_is_revenue_order_status( $status ) ) {
				$excluded_order_count++;
				$excluded_order_total += $total;
				continue;
			}

			$order_count++;
			$order_total += $total;
			$discount_total += (float) $order->get_discount_total();
			$shipping_total += (float) $order->get_shipping_total();
			$tax_total      += (float) $order->get_total_tax();

			if ( method_exists( $order, 'get_total_refunded' ) ) {
				$refund_total += (float) $order->get_total_refunded();
			}

			$customer_key = strtolower( trim( $order->get_billing_email() ) );

			if ( empty( $customer_key ) ) {
				$customer_key = 'guest:' . $order->get_id();
			}

			if ( ! isset( $customer_map[ $customer_key ] ) ) {
				$customer_map[ $customer_key ] = [
					'name'        => trim( $order->get_formatted_billing_full_name() ),
					'email'       => $order->get_billing_email(),
					'order_count' => 0,
					'total_spent' => 0.0,
				];
			}

			$customer_map[ $customer_key ]['order_count']++;
			$customer_map[ $customer_key ]['total_spent'] += $total;

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product    = $item->get_product();
				$product_id = $item->get_product_id();
				$key        = $product_id ? (string) $product_id : 'item:' . md5( $item->get_name() );
				$category_context = YoRo_Orders_Read_Service::report_get_product_category_context( $product_id, $category_cache );

				if ( ! isset( $product_map[ $key ] ) ) {
					$product_map[ $key ] = [
						'id'         => $product_id,
						'name'       => $item->get_name(),
						'sku'        => $product instanceof WC_Product ? $product->get_sku() : '',
						'category'   => $category_context['primary_name'] ?? '',
						'categories' => array_map(
							function( $category ) {
								return sanitize_text_field( $category['name'] ?? '' );
							},
							is_array( $category_context['categories'] ?? null ) ? $category_context['categories'] : []
						),
						'quantity'   => 0,
						'total'      => 0.0,
					];
				}

				$quantity = (float) $item->get_quantity();
				$line_total = (float) $item->get_total();
				$items_sold += $quantity;
				$product_map[ $key ]['quantity'] += $quantity;
				$product_map[ $key ]['total']    += $line_total;
				YoRo_Orders_Read_Service::report_record_category_mix( $category_map, $category_context, $key, $quantity, $line_total );
			}

			if ( count( $recent_orders ) < 5 ) {
				$recent_orders[] = YoRo_Order_Presenter::format_order_summary( $order );
			}
		}

		$average_order_value = $order_count > 0 ? $order_total / $order_count : 0;
		$net_sales           = max( 0, $order_total - $refund_total );
		$repeat_customer_count = count(
			array_filter(
				$customer_map,
				function( $customer ) {
					return is_array( $customer ) && absint( $customer['order_count'] ?? 0 ) > 1;
				}
			)
		);
		$repeat_customer_rate = count( $customer_map ) > 0 ? ( $repeat_customer_count / count( $customer_map ) ) * 100 : 0;
		$refund_rate          = $order_total > 0 ? ( $refund_total / $order_total ) * 100 : 0;
		$discount_rate        = $order_total > 0 ? ( $discount_total / $order_total ) * 100 : 0;
		$items_per_order      = $order_count > 0 ? $items_sold / $order_count : 0;
		$backlog_total       = YoRo_Orders_Read_Service::briefing_count_orders( [ 'status' => [ 'pending', 'processing', 'on-hold' ] ] );
		$attention_total     = YoRo_Orders_Read_Service::briefing_count_orders(
			[
				'status'       => [ 'pending', 'processing', 'on-hold' ],
				'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-48 hours' ),
			]
		);

		uasort(
			$product_map,
			function( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);
		uasort(
			$customer_map,
			function( $a, $b ) {
				return $b['total_spent'] <=> $a['total_spent'];
			}
		);

		$top_customers = array_slice(
			array_map(
				function( $customer ) {
					return [
						'name'        => $customer['name'],
						'email'       => $customer['email'],
						'order_count' => $customer['order_count'],
						'total_spent' => wc_format_decimal( $customer['total_spent'], 2 ),
					];
				},
				array_values( $customer_map )
			),
			0,
			3
		);

		$top_products = array_slice(
			array_map(
				function( $product ) {
					return [
						'id'         => $product['id'],
						'name'       => $product['name'],
						'sku'        => $product['sku'] ?? '',
						'category'   => $product['category'] ?? '',
						'categories' => array_values( array_filter( is_array( $product['categories'] ?? null ) ? $product['categories'] : [] ) ),
						'quantity'   => $product['quantity'],
						'total'      => wc_format_decimal( $product['total'], 2 ),
					];
				},
				array_values( $product_map )
			),
			0,
			5
		);
		$top_categories = YoRo_Orders_Read_Service::report_format_category_mix( $category_map, 5 );

		$business_metrics = [
			'order_count'         => $order_count,
			'gross_sales'         => wc_format_decimal( $order_total, 2 ),
			'net_sales'           => wc_format_decimal( $net_sales, 2 ),
			'average_order_value' => wc_format_decimal( $average_order_value, 2 ),
			'discount_total'      => wc_format_decimal( $discount_total, 2 ),
			'refund_total'        => wc_format_decimal( $refund_total, 2 ),
			'shipping_total'      => wc_format_decimal( $shipping_total, 2 ),
			'tax_total'           => wc_format_decimal( $tax_total, 2 ),
			'unique_customers'    => count( $customer_map ),
			'repeat_customers'    => $repeat_customer_count,
			'repeat_customer_rate' => wc_format_decimal( $repeat_customer_rate, 1 ),
			'items_sold'          => wc_format_decimal( $items_sold, 0 ),
			'items_per_order'     => wc_format_decimal( $items_per_order, 2 ),
			'product_lines'       => count( $product_map ),
			'product_categories'  => count( $category_map ),
			'refund_rate'         => wc_format_decimal( $refund_rate, 1 ),
			'discount_rate'       => wc_format_decimal( $discount_rate, 1 ),
			'excluded_orders'     => $excluded_order_count,
			'excluded_order_total' => wc_format_decimal( $excluded_order_total, 2 ),
			'revenue_statuses'    => YoRo_Orders_Read_Service::briefing_get_revenue_order_status_slugs(),
		];
		$previous_window           = YoRo_Date_Helper::build_previous_window( $window );
		$previous_business_metrics = self::summarize_business_window( $previous_window );
		$comparison                = self::build_business_comparison( $business_metrics, $previous_business_metrics, $previous_window );

		$customer_count_label = sprintf(
			_n( '%d active customer', '%d active customers', count( $customer_map ), 'yoro-ai-core' ),
			count( $customer_map )
		);

		$gross_sales_label = wc_format_decimal( $order_total, 2 ) . ' ' . get_woocommerce_currency();
		$narrative_context = self::build_business_summary_narrative_context(
			$window,
			$business_metrics,
			$order_status_counts,
			$backlog_total,
			$attention_total
		);
		$headline_insights = self::build_business_summary_insights(
			$window,
			$business_metrics,
			$order_status_counts,
			$backlog_total,
			$attention_total,
			$top_customers,
			$top_products,
			$narrative_context
		);
		$business_context = self::build_business_orchestrator_context(
			$window_key,
			$window,
			$focus,
			$business_metrics,
			$order_status_counts,
			$backlog_total,
			$attention_total,
			$headline_insights
		);
		$priority_items = self::build_business_priority_items(
			$business_context,
			$headline_insights,
			$top_customers,
			$top_products
		);
		$recommended_sequence = self::build_business_recommended_sequence( $business_context );
		$business_actions     = self::build_business_actions( $business_context );
		$report               = self::build_business_report(
			$window,
			$business_metrics,
			$order_status_counts,
			$backlog_total,
			$attention_total,
			$headline_insights,
			$business_actions,
			$top_products,
			$top_categories,
			$comparison
		);

		return [
			'success'      => true,
			'action'       => 'store.get_business_summary',
			'summary'      => sprintf(
				'Sales report for %1$s: %2$d orders, %3$s gross sales, %4$s.',
				$window['label'],
				$order_count,
				$gross_sales_label,
				$customer_count_label
			),
			'message'      => sprintf(
				'Sales report for %1$s: %2$d orders, %3$s gross sales, %4$s.',
				$window['label'],
				$order_count,
				$gross_sales_label,
				$customer_count_label
			),
			'count'        => $order_count,
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [
				'window'        => $window,
				'currency'      => get_woocommerce_currency(),
				'changes_store' => false,
			],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'window'       => YoRo_Date_Helper::build_window_payload( $window ),
			'focus'                => $focus,
			'business_metrics'     => $business_metrics,
			'order_status_counts'  => $order_status_counts,
			'backlog_total'        => $backlog_total,
			'attention_total'      => $attention_total,
			'narrative_context'    => $narrative_context,
			'business_context'     => $business_context,
			'comparison'           => $comparison,
			'priority_items'       => array_slice( $priority_items, 0, 3 ),
			'recommended_sequence' => array_slice( $recommended_sequence, 0, 4 ),
			'business_actions'     => array_slice( $business_actions, 0, 4 ),
			'headline_insights'    => $headline_insights,
			'top_customers'        => $top_customers,
			'top_products'         => $top_products,
			'top_categories'       => $top_categories,
			'recent_orders'        => $recent_orders,
			'report'               => $report,
			'result_type'          => 'business_summary',
			'display_type'         => 'business_summary',
		];
	}

	private static function summarize_business_window( $window ) {
		$summary = [
			'order_count'         => 0,
			'gross_sales'         => 0.0,
			'net_sales'           => 0.0,
			'average_order_value' => 0.0,
			'unique_customers'    => 0,
			'repeat_customers'    => 0,
			'repeat_customer_rate' => 0.0,
			'items_sold'          => 0.0,
			'items_per_order'     => 0.0,
			'product_lines'       => 0,
			'product_categories'  => 0,
			'refund_rate'         => 0.0,
			'discount_rate'       => 0.0,
		];

		if ( empty( $window['query'] ) || ! function_exists( 'wc_get_orders' ) ) {
			return $summary;
		}

		$customer_map = [];
		$product_map  = [];
		$category_map = [];
		$category_cache = [];
		$refund_total = 0.0;
		$discount_total = 0.0;

		$orders = YoRo_Orders_Read_Service::report_query_orders(
			[
				'status'       => YoRo_Orders_Read_Service::briefing_get_all_order_status_slugs(),
				'date_created' => $window['query'],
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$summary['order_count']++;
			$summary['gross_sales'] += (float) $order->get_total();
			$discount_total += (float) $order->get_discount_total();

			if ( method_exists( $order, 'get_total_refunded' ) ) {
				$refund_total += (float) $order->get_total_refunded();
			}

			$customer_key = strtolower( trim( $order->get_billing_email() ) );
			if ( empty( $customer_key ) ) {
				$customer_key = 'guest:' . $order->get_id();
			}

			if ( ! isset( $customer_map[ $customer_key ] ) ) {
				$customer_map[ $customer_key ] = 0;
			}

			$customer_map[ $customer_key ]++;

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product_id = $item->get_product_id();
				$product_key = $product_id ? (string) $product_id : 'item:' . md5( $item->get_name() );
				$category_context = YoRo_Orders_Read_Service::report_get_product_category_context( $product_id, $category_cache );
				$quantity = (float) $item->get_quantity();
				$line_total = (float) $item->get_total();
				$product_map[ $product_key ] = true;
				$summary['items_sold'] += $quantity;
				YoRo_Orders_Read_Service::report_record_category_mix( $category_map, $category_context, $product_key, $quantity, $line_total );
			}
		}

		$summary['net_sales'] = max( 0, $summary['gross_sales'] - $refund_total );
		$summary['average_order_value'] = $summary['order_count'] > 0 ? $summary['gross_sales'] / $summary['order_count'] : 0.0;
		$summary['unique_customers'] = count( $customer_map );
		$summary['repeat_customers'] = count(
			array_filter(
				$customer_map,
				function( $order_count ) {
					return absint( $order_count ) > 1;
				}
			)
		);
		$summary['repeat_customer_rate'] = $summary['unique_customers'] > 0 ? ( $summary['repeat_customers'] / $summary['unique_customers'] ) * 100 : 0.0;
		$summary['product_lines'] = count( $product_map );
		$summary['product_categories'] = count( $category_map );
		$summary['items_per_order'] = $summary['order_count'] > 0 ? $summary['items_sold'] / $summary['order_count'] : 0.0;
		$summary['refund_rate'] = $summary['gross_sales'] > 0 ? ( $refund_total / $summary['gross_sales'] ) * 100 : 0.0;
		$summary['discount_rate'] = $summary['gross_sales'] > 0 ? ( $discount_total / $summary['gross_sales'] ) * 100 : 0.0;

		return $summary;
	}

	private static function build_business_comparison( $business_metrics, $previous_metrics, $previous_window ) {
		$keys = [ 'order_count', 'gross_sales', 'net_sales', 'average_order_value', 'unique_customers', 'repeat_customers', 'repeat_customer_rate', 'items_sold', 'items_per_order', 'product_lines', 'product_categories', 'refund_rate', 'discount_rate' ];
		$comparison = [
			'window'  => YoRo_Date_Helper::build_window_payload( is_array( $previous_window ) ? $previous_window : [] ),
			'metrics' => [],
		];

		foreach ( $keys as $key ) {
			$current = (float) ( $business_metrics[ $key ] ?? 0 );
			$previous = (float) ( $previous_metrics[ $key ] ?? 0 );
			$comparison['metrics'][ $key ] = YoRo_Report_Builder::metric_comparison( $current, $previous );
			$comparison['metrics'][ $key ]['previous'] = wc_format_decimal( $previous, in_array( $key, [ 'gross_sales', 'net_sales', 'average_order_value', 'items_per_order' ], true ) ? 2 : ( in_array( $key, [ 'repeat_customer_rate', 'refund_rate', 'discount_rate' ], true ) ? 1 : 0 ) );
		}

		return $comparison;
	}

	private static function comparison_for_metric( $comparison, $key ) {
		return is_array( $comparison ) && ! empty( $comparison['metrics'][ $key ] ) && is_array( $comparison['metrics'][ $key ] )
			? $comparison['metrics'][ $key ]
			: [];
	}

	private static function build_business_report( $window, $business_metrics, $order_status_counts, $backlog_total, $attention_total, $headline_insights, $business_actions, $top_products, $top_categories, $comparison = [] ) {
		$order_count = absint( $business_metrics['order_count'] ?? 0 );
		$gross_sales = sanitize_text_field( $business_metrics['gross_sales'] ?? '0' );
		$net_sales = sanitize_text_field( $business_metrics['net_sales'] ?? '0' );
		$average_order_value = sanitize_text_field( $business_metrics['average_order_value'] ?? '0' );
		$discount_total = sanitize_text_field( $business_metrics['discount_total'] ?? '0' );
		$refund_total = sanitize_text_field( $business_metrics['refund_total'] ?? '0' );
		$shipping_total = sanitize_text_field( $business_metrics['shipping_total'] ?? '0' );
		$tax_total = sanitize_text_field( $business_metrics['tax_total'] ?? '0' );
		$items_sold = sanitize_text_field( $business_metrics['items_sold'] ?? '0' );
		$items_per_order = sanitize_text_field( $business_metrics['items_per_order'] ?? '0' );
		$product_lines = absint( $business_metrics['product_lines'] ?? 0 );
		$product_categories = absint( $business_metrics['product_categories'] ?? 0 );
		$repeat_customers = absint( $business_metrics['repeat_customers'] ?? 0 );
		$repeat_customer_rate = sanitize_text_field( $business_metrics['repeat_customer_rate'] ?? '0' );
		$refund_rate = sanitize_text_field( $business_metrics['refund_rate'] ?? '0' );
		$discount_rate = sanitize_text_field( $business_metrics['discount_rate'] ?? '0' );
		$excluded_orders = absint( $business_metrics['excluded_orders'] ?? 0 );
		$currency = get_woocommerce_currency();
		$health = $attention_total > 0 ? 'watch' : ( $order_count > 0 ? 'good' : 'watch' );
		$gross_comparison = self::comparison_for_metric( $comparison, 'gross_sales' );
		$net_comparison = self::comparison_for_metric( $comparison, 'net_sales' );
		$order_comparison = self::comparison_for_metric( $comparison, 'order_count' );
		$aov_comparison = self::comparison_for_metric( $comparison, 'average_order_value' );
		$customer_comparison = self::comparison_for_metric( $comparison, 'unique_customers' );
		$item_comparison = self::comparison_for_metric( $comparison, 'items_sold' );
		$items_per_order_comparison = self::comparison_for_metric( $comparison, 'items_per_order' );
		$product_line_comparison = self::comparison_for_metric( $comparison, 'product_lines' );
		$product_category_comparison = self::comparison_for_metric( $comparison, 'product_categories' );
		$repeat_customer_comparison = self::comparison_for_metric( $comparison, 'repeat_customers' );
		$repeat_customer_rate_comparison = self::comparison_for_metric( $comparison, 'repeat_customer_rate' );
		$refund_rate_comparison = self::comparison_for_metric( $comparison, 'refund_rate' );
		$discount_rate_comparison = self::comparison_for_metric( $comparison, 'discount_rate' );
		$headline = $order_count > 0
			? sprintf( __( 'Sales activity for %1$s: %2$d orders, %3$s %4$s gross sales, and %5$s average order value.', 'yoro-ai-core' ), $window['label'], $order_count, $gross_sales, $currency, $average_order_value )
			: sprintf( __( 'No order revenue is visible for %s yet.', 'yoro-ai-core' ), $window['label'] );

		$metrics = [
			YoRo_Report_Builder::metric( 'gross_sales', __( 'Gross sales', 'yoro-ai-core' ), $gross_sales, $currency, 'currency', $order_count > 0 ? 'positive' : 'watch', $gross_comparison['trend'] ?? '', $gross_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'net_sales', __( 'Net sales', 'yoro-ai-core' ), $net_sales, $currency, 'currency', $order_count > 0 ? 'positive' : 'watch', $net_comparison['trend'] ?? '', $net_comparison['delta'] ?? null, 'store', 'sales', __( 'Reportable order total after refunds for this window.', 'yoro-ai-core' ), 12 ),
			YoRo_Report_Builder::metric( 'average_order_value', __( 'Average order value', 'yoro-ai-core' ), $average_order_value, $currency, 'currency', 'neutral', $aov_comparison['trend'] ?? '', $aov_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'discount_total', __( 'Discounts', 'yoro-ai-core' ), $discount_total, $currency, 'currency' ),
			YoRo_Report_Builder::metric( 'refund_total', __( 'Refunded', 'yoro-ai-core' ), $refund_total, $currency, 'currency', (float) $refund_total > 0 ? 'watch' : 'neutral' ),
			YoRo_Report_Builder::metric( 'shipping_total', __( 'Shipping', 'yoro-ai-core' ), $shipping_total, $currency, 'currency' ),
			YoRo_Report_Builder::metric( 'tax_total', __( 'Tax', 'yoro-ai-core' ), $tax_total, $currency, 'currency' ),
			YoRo_Report_Builder::metric( 'orders', __( 'Orders', 'yoro-ai-core' ), $order_count, 'orders', 'number', 'neutral', $order_comparison['trend'] ?? '', $order_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'active_customers', __( 'Active customers', 'yoro-ai-core' ), absint( $business_metrics['unique_customers'] ?? 0 ), 'customers', 'number', 'neutral', $customer_comparison['trend'] ?? '', $customer_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'repeat_customers', __( 'Repeat customers', 'yoro-ai-core' ), $repeat_customers, 'customers', 'number', 'neutral', $repeat_customer_comparison['trend'] ?? '', $repeat_customer_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'repeat_customer_rate', __( 'Repeat customer rate', 'yoro-ai-core' ), $repeat_customer_rate, '%', 'percent', $repeat_customers > 0 ? 'positive' : 'neutral', $repeat_customer_rate_comparison['trend'] ?? '', $repeat_customer_rate_comparison['delta'] ?? null, 'store', 'customers', __( 'Share of active customers with more than one order in this window.', 'yoro-ai-core' ), 35 ),
			YoRo_Report_Builder::metric( 'items_sold', __( 'Items sold', 'yoro-ai-core' ), $items_sold, 'items', 'number', 'neutral', $item_comparison['trend'] ?? '', $item_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'items_per_order', __( 'Items per order', 'yoro-ai-core' ), $items_per_order, 'items', 'number', 'neutral', $items_per_order_comparison['trend'] ?? '', $items_per_order_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'product_lines', __( 'Product lines', 'yoro-ai-core' ), $product_lines, 'products', 'number', 'neutral', $product_line_comparison['trend'] ?? '', $product_line_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'product_categories', __( 'Categories sold', 'yoro-ai-core' ), $product_categories, 'categories', 'number', 'neutral', $product_category_comparison['trend'] ?? '', $product_category_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'refund_rate', __( 'Refund rate', 'yoro-ai-core' ), $refund_rate, '%', 'percent', (float) $refund_rate > 0 ? 'watch' : 'neutral', $refund_rate_comparison['trend'] ?? '', $refund_rate_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'discount_rate', __( 'Discount rate', 'yoro-ai-core' ), $discount_rate, '%', 'percent', (float) $discount_rate > 0 ? 'neutral' : 'neutral', $discount_rate_comparison['trend'] ?? '', $discount_rate_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'attention_items', __( 'Needs attention', 'yoro-ai-core' ), $attention_total, 'orders', 'number', $attention_total > 0 ? 'watch' : 'positive' ),
		];

		if ( $excluded_orders > 0 ) {
			$metrics[] = YoRo_Report_Builder::metric(
				'excluded_orders',
				__( 'Not counted as sales', 'yoro-ai-core' ),
				$excluded_orders,
				'orders',
				'number',
				'neutral',
				'',
				null,
				'store',
				'sales',
				__( 'Pending, failed, cancelled, or refunded orders kept out of the sales totals.', 'yoro-ai-core' ),
				70
			);
		}

		$profile_context  = YoRo_Report_Builder::store_profile_context( [ 'sales', 'reporting' ] );
		$profile_insights = YoRo_Report_Builder::business_profile_insights( $profile_context, $business_metrics, $window );
		$insights         = [];

		foreach ( array_slice( is_array( $headline_insights ) ? $headline_insights : [], 0, 3 ) as $insight ) {
			if ( ! is_array( $insight ) ) {
				continue;
			}

			$insights[] = YoRo_Report_Builder::insight(
				$insight['title'] ?? __( 'Business signal', 'yoro-ai-core' ),
				$insight['message'] ?? '',
				$insight['topic'] ?? 'business',
				$insight['severity'] ?? $insight['tone'] ?? 'neutral'
			);
		}

		if ( empty( $insights ) && ! empty( $top_products[0]['name'] ) ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Top product signal', 'yoro-ai-core' ),
				sprintf( __( '%s is the strongest product signal in this window.', 'yoro-ai-core' ), sanitize_text_field( $top_products[0]['name'] ) ),
				'products',
				'positive'
			);
		}

		if ( ! empty( $top_categories[0]['name'] ) ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Category demand signal', 'yoro-ai-core' ),
				sprintf( __( '%1$s is the leading product category with %2$s items sold.', 'yoro-ai-core' ), sanitize_text_field( $top_categories[0]['name'] ), sanitize_text_field( $top_categories[0]['quantity'] ?? '0' ) ),
				'products',
				'neutral',
				'products',
				45
			);
		}

		$insights = array_merge( $insights, $profile_insights );

		$alerts = [];

		if ( $attention_total > 0 ) {
			$alerts[] = YoRo_Report_Builder::alert(
				__( 'Operations may affect revenue follow-through', 'yoro-ai-core' ),
				sprintf( __( '%d attention items are present while reading the sales report.', 'yoro-ai-core' ), $attention_total ),
				'watch',
				'operations',
				'operations',
				20
			);
		}

		$actions = [];

		foreach ( array_slice( is_array( $business_actions ) ? $business_actions : [], 0, 3 ) as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}

			$actions[] = YoRo_Report_Builder::action(
				$action['label'] ?? $action['command'] ?? '',
				$action['command'] ?? '',
				$action['kind'] ?? 'view',
				$action['topic'] ?? '',
				$action['priority'] ?? 50,
				$action['reason'] ?? ''
			);
		}

		if ( empty( $actions ) ) {
			$actions = [
				YoRo_Report_Builder::action( __( 'Show top products', 'yoro-ai-core' ), 'Show store signals for this week', 'view', 'products', 40, __( 'Use this to see whether product demand is concentrated.', 'yoro-ai-core' ) ),
				YoRo_Report_Builder::action( __( 'Check operations', 'yoro-ai-core' ), 'Show operations report for today', 'view', 'operations', 50, __( 'Use this to see whether order pressure needs action.', 'yoro-ai-core' ) ),
			];
		}

		$breakdowns = self::build_product_mix_breakdowns( $top_products, $top_categories, $currency, (float) $gross_sales );
		$report     = YoRo_Report_Builder::build( 'business', $window, $health, $headline, $metrics, $insights, $alerts, $actions, $actions, $breakdowns );
		$report     = YoRo_Report_Builder::attach_profile_context( $report, $profile_context );

		if ( ! empty( $comparison ) ) {
			$report['comparison'] = $comparison;
		}

		return $report;
	}

	public static function report_build_product_mix_breakdowns( $top_products, $top_categories, $currency, $gross_sales = 0.0 ) {
		return self::build_product_mix_breakdowns( $top_products, $top_categories, $currency, $gross_sales );
	}

	private static function build_product_mix_breakdowns( $top_products, $top_categories, $currency, $gross_sales = 0.0 ) {
		$breakdowns  = [];
		$gross_sales = is_numeric( $gross_sales ) ? (float) $gross_sales : 0.0;

		if ( is_array( $top_products ) && ! empty( $top_products ) ) {
			$product_items = [];

			foreach ( array_slice( $top_products, 0, 5 ) as $product ) {
				if ( ! is_array( $product ) || empty( $product['name'] ) ) {
					continue;
				}

				$total = (float) ( $product['total'] ?? 0 );
				$product_items[] = YoRo_Report_Builder::breakdown_item(
					$product['name'],
					$product['quantity'] ?? 0,
					__( 'items', 'yoro-ai-core' ),
					wc_format_decimal( $total, 2 ) . ' ' . $currency,
					$gross_sales > 0 ? wc_format_decimal( ( $total / $gross_sales ) * 100, 1 ) . '%' : '',
					[
						'id'         => $product['id'] ?? 0,
						'sku'        => $product['sku'] ?? '',
						'category'   => $product['category'] ?? '',
						'categories' => is_array( $product['categories'] ?? null ) ? $product['categories'] : [],
					]
				);
			}

			if ( ! empty( $product_items ) ) {
				$breakdowns[] = YoRo_Report_Builder::breakdown(
					'top_products',
					__( 'Top products', 'yoro-ai-core' ),
					$product_items,
					'products',
					40,
					__( 'Products contributing the most sales in this report window.', 'yoro-ai-core' )
				);
			}
		}

		if ( is_array( $top_categories ) && ! empty( $top_categories ) ) {
			$category_items = [];

			foreach ( array_slice( $top_categories, 0, 5 ) as $category ) {
				if ( ! is_array( $category ) || empty( $category['name'] ) ) {
					continue;
				}

				$total = (float) ( $category['total'] ?? 0 );
				$category_items[] = YoRo_Report_Builder::breakdown_item(
					$category['name'],
					$category['quantity'] ?? 0,
					__( 'items', 'yoro-ai-core' ),
					wc_format_decimal( $total, 2 ) . ' ' . $currency,
					$gross_sales > 0 ? wc_format_decimal( ( $total / $gross_sales ) * 100, 1 ) . '%' : '',
					[
						'id'            => $category['id'] ?? 0,
						'product_lines' => $category['product_lines'] ?? 0,
					]
				);
			}

			if ( ! empty( $category_items ) ) {
				$breakdowns[] = YoRo_Report_Builder::breakdown(
					'top_categories',
					__( 'Top categories', 'yoro-ai-core' ),
					$category_items,
					'products',
					45,
					__( 'Product categories contributing the most sales in this report window.', 'yoro-ai-core' )
				);
			}
		}

		return $breakdowns;
	}

	public static function get_daily_briefing( $params ) {
		$window_key     = YoRo_Orders_Read_Service::briefing_normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'today' ) );
		$limit          = isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 10 ) : 5;
		$window         = YoRo_Orders_Read_Service::briefing_get_operations_window( $window_key );
		$briefing_label = self::get_briefing_label_for_window( $window_key, $window );

		$operations = YoRo_Orders_Read_Service::get_operations_summary(
			[
				'window' => $window_key,
				'focus'  => 'overview',
			]
		);
		$attention        = YoRo_Orders_Read_Service::get_operations_attention_queue( [ 'limit' => $limit ] );
		$fulfillment      = YoRo_Orders_Read_Service::get_fulfillment_queue( [ 'limit' => $limit ] );
		$customer_service = YoRo_Orders_Read_Service::get_customer_service_queue( [ 'limit' => $limit ] );
		$business         = self::get_business_summary(
			[
				'window' => $window_key,
				'focus'  => 'overview',
			]
		);
		$inventory = YoRo_Orders_Read_Service::get_inventory_queue(
			[
				'limit' => $limit,
				'focus' => 'overview',
			]
		);

		foreach ( [ $operations, $attention, $fulfillment, $customer_service, $business, $inventory ] as $section_result ) {
			if ( is_wp_error( $section_result ) ) {
				return $section_result;
			}
		}

		$sections = [
			[
				'key'     => 'operations',
				'label'   => __( 'Operations', 'yoro-ai-core' ),
				'summary' => sanitize_text_field( $operations['summary'] ?? '' ),
				'count'   => absint( $operations['count'] ?? 0 ),
			],
			[
				'key'     => 'attention',
				'label'   => __( 'Attention', 'yoro-ai-core' ),
				'summary' => sanitize_text_field( $attention['summary'] ?? '' ),
				'count'   => absint( $attention['count'] ?? 0 ),
			],
			[
				'key'     => 'fulfillment',
				'label'   => __( 'Fulfillment', 'yoro-ai-core' ),
				'summary' => sanitize_text_field( $fulfillment['summary'] ?? '' ),
				'count'   => absint( $fulfillment['count'] ?? 0 ),
			],
			[
				'key'     => 'customer_service',
				'label'   => __( 'Customer follow-up', 'yoro-ai-core' ),
				'summary' => sanitize_text_field( $customer_service['summary'] ?? '' ),
				'count'   => absint( $customer_service['count'] ?? 0 ),
			],
			[
				'key'     => 'business',
				'label'   => __( 'Business', 'yoro-ai-core' ),
				'summary' => sanitize_text_field( $business['summary'] ?? '' ),
				'count'   => absint( $business['business_metrics']['order_count'] ?? 0 ),
			],
			[
				'key'     => 'inventory',
				'label'   => __( 'Inventory', 'yoro-ai-core' ),
				'summary' => sanitize_text_field( $inventory['summary'] ?? '' ),
				'count'   => absint( $inventory['count'] ?? 0 ),
			],
		];

		$briefing_context = self::build_daily_briefing_context(
			$window,
			$operations,
			$attention,
			$fulfillment,
			$customer_service,
			$business,
			$inventory
		);
		$priority_items = self::build_daily_briefing_priority_items(
			$operations,
			$attention,
			$fulfillment,
			$customer_service,
			$business,
			$inventory,
			$briefing_context
		);
		$priority_drilldowns = self::build_daily_briefing_drilldowns(
			$window_key,
			$briefing_context,
			$priority_items
		);
		$briefing_actions = self::build_daily_briefing_actions(
			$briefing_context,
			$fulfillment,
			$customer_service,
			$inventory
		);
		$recommended_sequence = self::build_daily_briefing_recommended_sequence(
			$briefing_context,
			$priority_drilldowns,
			$briefing_actions,
			$priority_items
		);
		$report = self::build_daily_briefing_report(
			$window,
			$briefing_label,
			$briefing_context,
			$sections,
			$priority_items,
			$briefing_actions,
			$priority_drilldowns,
			$business['comparison'] ?? []
		);

		return [
			'success'      => true,
			'action'       => 'store.get_daily_briefing',
			'summary'      => sprintf(
				'%8$s for %1$s: %2$d new orders, %3$d attention items, %4$d fulfillment items, %5$d customer follow-up items, %6$d inventory items, %7$s gross sales.',
				$window['label'],
				absint( $operations['window_order_count'] ?? 0 ),
				absint( $operations['attention_total'] ?? 0 ),
				absint( $fulfillment['count'] ?? 0 ),
				absint( $customer_service['count'] ?? 0 ),
				absint( $inventory['count'] ?? 0 ),
				sanitize_text_field( $business['business_metrics']['gross_sales'] ?? '0' ),
				$briefing_label
			),
			'message'      => sprintf(
				'%8$s for %1$s: %2$d new orders, %3$d attention items, %4$d fulfillment items, %5$d customer follow-up items, %6$d inventory items, %7$s gross sales.',
				$window['label'],
				absint( $operations['window_order_count'] ?? 0 ),
				absint( $operations['attention_total'] ?? 0 ),
				absint( $fulfillment['count'] ?? 0 ),
				absint( $customer_service['count'] ?? 0 ),
				absint( $inventory['count'] ?? 0 ),
				sanitize_text_field( $business['business_metrics']['gross_sales'] ?? '0' ),
				$briefing_label
			),
			'count'        => count( $sections ),
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [
				'window'        => $window,
				'limit'         => $limit,
				'changes_store' => false,
			],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'window'       => YoRo_Date_Helper::build_window_payload( $window ),
			'sections'               => $sections,
			'briefing_context'       => $briefing_context,
			'briefing_label'         => $briefing_label,
			'comparison'             => $business['comparison'] ?? [],
			'priority_items'         => array_slice( $priority_items, 0, 3 ),
			'priority_drilldowns'    => array_slice( $priority_drilldowns, 0, 4 ),
			'briefing_actions'       => array_slice( $briefing_actions, 0, 4 ),
			'recommended_sequence'   => array_slice( $recommended_sequence, 0, 4 ),
			'operations_summary'     => $operations,
			'attention_queue'        => $attention,
			'fulfillment_queue'      => $fulfillment,
			'customer_service_queue' => $customer_service,
			'business_summary'       => $business,
			'inventory_queue'        => $inventory,
			'report'                 => $report,
			'result_type'            => 'daily_briefing',
			'display_type'           => 'daily_briefing',
		];
	}

	private static function build_daily_briefing_report( $window, $briefing_label, $briefing_context, $sections, $priority_items, $briefing_actions, $priority_drilldowns, $comparison = [] ) {
		$window_orders = absint( $briefing_context['window_orders'] ?? 0 );
		$attention_total = absint( $briefing_context['attention_total'] ?? 0 );
		$backlog_total = absint( $briefing_context['backlog_total'] ?? 0 );
		$fulfillment_total = absint( $briefing_context['fulfillment_items'] ?? 0 );
		$customer_service_total = absint( $briefing_context['customer_followups'] ?? 0 );
		$inventory_total = absint( $briefing_context['inventory_items'] ?? 0 );
		$gross_sales = sanitize_text_field( $briefing_context['gross_sales'] ?? '0' );
		$active_customers = absint( $briefing_context['active_customers'] ?? 0 );
		$top_topic = sanitize_key( $briefing_context['top_topic'] ?? 'overview' );
		$health = $attention_total > 0 ? 'watch' : ( $window_orders > 0 ? 'good' : 'watch' );
		$order_comparison = self::comparison_for_metric( $comparison, 'order_count' );
		$gross_comparison = self::comparison_for_metric( $comparison, 'gross_sales' );
		$customer_comparison = self::comparison_for_metric( $comparison, 'unique_customers' );
		$profile_context = YoRo_Report_Builder::store_profile_context( [ 'reporting', 'sales', 'operations', 'fulfillment', 'inventory', 'customers' ] );
		$profile_business_metrics = [
			'average_order_value' => $window_orders > 0 ? ( (float) $gross_sales / $window_orders ) : 0,
		];
		$profile_insights = array_merge(
			YoRo_Report_Builder::business_profile_insights( $profile_context, $profile_business_metrics, $window ),
			YoRo_Report_Builder::operations_profile_insights( $profile_context ),
			YoRo_Report_Builder::inventory_profile_insights( $profile_context )
		);
		$headline = sprintf(
			__( '%1$s for %2$s: %3$d new orders, %4$s gross sales, and %5$d attention items.', 'yoro-ai-core' ),
			$briefing_label,
			$window['label'],
			$window_orders,
			$gross_sales,
			$attention_total
		);

		$metrics = [
			YoRo_Report_Builder::metric( 'new_orders', __( 'New orders', 'yoro-ai-core' ), $window_orders, 'orders', 'number', 'neutral', $order_comparison['trend'] ?? '', $order_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'gross_sales', __( 'Gross sales', 'yoro-ai-core' ), $gross_sales, get_woocommerce_currency(), 'currency', 'neutral', $gross_comparison['trend'] ?? '', $gross_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'active_customers', __( 'Active customers', 'yoro-ai-core' ), $active_customers, 'customers', 'number', 'neutral', $customer_comparison['trend'] ?? '', $customer_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'attention_items', __( 'Needs attention', 'yoro-ai-core' ), $attention_total, 'items', 'number', $attention_total > 0 ? 'watch' : 'positive' ),
			YoRo_Report_Builder::metric( 'open_backlog', __( 'Open backlog', 'yoro-ai-core' ), $backlog_total, 'orders', 'number', $backlog_total > 0 ? 'watch' : 'positive', '', null, 'store', 'operations', __( 'Live unresolved order backlog across the store.', 'yoro-ai-core' ), 35 ),
			YoRo_Report_Builder::metric( 'fulfillment_items', __( 'Fulfillment items', 'yoro-ai-core' ), $fulfillment_total, 'items', 'number', $fulfillment_total > 0 ? 'watch' : 'neutral', '', null, 'store', 'operations', __( 'Orders or fulfillment cases that may need shipping or handling review.', 'yoro-ai-core' ), 40 ),
			YoRo_Report_Builder::metric( 'customer_followups', __( 'Customer follow-ups', 'yoro-ai-core' ), $customer_service_total, 'items', 'number', $customer_service_total > 0 ? 'watch' : 'neutral', '', null, 'store', 'operations', __( 'Customer-facing cases currently waiting for review or reply.', 'yoro-ai-core' ), 45 ),
			YoRo_Report_Builder::metric( 'inventory_items', __( 'Inventory items', 'yoro-ai-core' ), $inventory_total, 'items', 'number', $inventory_total > 0 ? 'watch' : 'neutral', '', null, 'store', 'inventory', __( 'Stock-related items that may affect sales or fulfillment.', 'yoro-ai-core' ), 50 ),
			YoRo_Report_Builder::metric( 'tracked_sections', __( 'Queues tracked', 'yoro-ai-core' ), count( is_array( $sections ) ? $sections : [] ), 'queues' ),
			YoRo_Report_Builder::metric( 'main_pressure', __( 'Main pressure', 'yoro-ai-core' ), str_replace( '_', ' ', $top_topic ), '', 'text', $attention_total > 0 ? 'watch' : 'neutral' ),
		];

		$insights = [];

		foreach ( array_slice( is_array( $priority_items ) ? $priority_items : [], 0, 3 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$insights[] = YoRo_Report_Builder::insight(
				$item['title'] ?? __( 'Store signal', 'yoro-ai-core' ),
				$item['message'] ?? $item['reason'] ?? '',
				$item['topic'] ?? 'overview',
				$item['severity'] ?? 'neutral'
			);
		}

		$insights = array_merge( $insights, $profile_insights );

		$alerts = [];

		if ( $attention_total > 0 ) {
			$alerts[] = YoRo_Report_Builder::alert(
				__( 'Attention queue is active', 'yoro-ai-core' ),
				sprintf( __( '%d items need review before the store can be considered clear.', 'yoro-ai-core' ), $attention_total ),
				'watch',
				'operations',
				'operations',
				20
			);
		}

		$actions = [];

		foreach ( array_slice( is_array( $briefing_actions ) ? $briefing_actions : [], 0, 3 ) as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}

			$actions[] = YoRo_Report_Builder::action(
				$action['label'] ?? $action['command'] ?? '',
				$action['command'] ?? '',
				$action['kind'] ?? 'view',
				$action['topic'] ?? '',
				$action['priority'] ?? 50,
				$action['reason'] ?? ''
			);
		}

		$drilldowns = [];

		foreach ( array_slice( is_array( $priority_drilldowns ) ? $priority_drilldowns : [], 0, 4 ) as $drilldown ) {
			if ( ! is_array( $drilldown ) ) {
				continue;
			}

			$drilldowns[] = YoRo_Report_Builder::action(
				$drilldown['label'] ?? $drilldown['command'] ?? '',
				$drilldown['command'] ?? '',
				$drilldown['kind'] ?? 'view',
				$drilldown['topic'] ?? '',
				$drilldown['priority'] ?? 50,
				$drilldown['reason'] ?? ''
			);
		}

		$report = YoRo_Report_Builder::build( 'store_overview', $window, $health, $headline, $metrics, $insights, $alerts, $actions, $drilldowns );
		$report = YoRo_Report_Builder::attach_profile_context( $report, $profile_context );

		if ( ! empty( $comparison ) ) {
			$report['comparison'] = $comparison;
		}

		return $report;
	}

	private static function get_briefing_label_for_window( $window_key, $window ) {
		$relative_days = YoRo_Date_Helper::get_relative_days_from_window_key( $window_key );

		if ( $relative_days > 0 ) {
			return sprintf( __( '%d-day store report', 'yoro-ai-core' ), $relative_days );
		}

		if ( ! empty( YoRo_Date_Helper::get_relative_window_from_window_key( $window_key ) ) || preg_match( '/^(?:range|month|quarter|year)_/', sanitize_key( $window_key ) ) ) {
			return sprintf( __( '%s store report', 'yoro-ai-core' ), YoRo_Date_Helper::get_window_label( $window_key ) );
		}

		switch ( sanitize_key( $window_key ) ) {
			case 'last_24_hours':
				return __( '24-hour store report', 'yoro-ai-core' );
			case 'today':
				return __( 'Today store report', 'yoro-ai-core' );
			case 'yesterday':
				return __( 'Yesterday store report', 'yoro-ai-core' );
			case 'this_week':
			case 'last_week':
			case 'last_7_days':
				return __( 'Weekly store report', 'yoro-ai-core' );
			case 'this_month':
			case 'last_month':
			case 'last_30_days':
				return __( 'Monthly store report', 'yoro-ai-core' );
			case 'this_quarter':
			case 'last_quarter':
				return __( 'Quarterly store report', 'yoro-ai-core' );
			case 'this_year':
			case 'last_year':
				return __( 'Yearly store report', 'yoro-ai-core' );
			default:
				return __( 'Store report', 'yoro-ai-core' );
		}
	}

	private static function get_briefing_window_phrase( $window_key ) {
		$relative_days = YoRo_Date_Helper::get_relative_days_from_window_key( $window_key );

		if ( $relative_days > 0 ) {
			return sprintf( 'last %d days', $relative_days );
		}

		if ( ! empty( YoRo_Date_Helper::get_relative_window_from_window_key( $window_key ) ) || preg_match( '/^(?:range|month|quarter|year)_/', sanitize_key( $window_key ) ) ) {
			return strtolower( YoRo_Date_Helper::get_window_label( $window_key ) );
		}

		switch ( sanitize_key( $window_key ) ) {
			case 'last_24_hours':
				return 'last 24 hours';
			case 'this_week':
				return 'this week';
			case 'last_week':
				return 'last week';
			case 'last_7_days':
				return 'last 7 days';
			case 'this_month':
				return 'this month';
			case 'last_month':
				return 'last month';
			case 'last_30_days':
				return 'last 30 days';
			case 'this_quarter':
				return 'this quarter';
			case 'last_quarter':
				return 'last quarter';
			case 'this_year':
				return 'this year';
			case 'last_year':
				return 'last year';
			case 'yesterday':
				return 'yesterday';
			default:
				return 'today';
		}
	}

	private static function build_daily_briefing_context( $window, $operations, $attention, $fulfillment, $customer_service, $business, $inventory ) {
		$window_key             = sanitize_key( $window['key'] ?? 'today' );
		$window_orders          = absint( $operations['window_order_count'] ?? 0 );
		$attention_total        = absint( $operations['attention_total'] ?? 0 );
		$backlog_total          = absint( $operations['backlog_total'] ?? 0 );
		$fulfillment_total      = absint( $fulfillment['count'] ?? 0 );
		$customer_service_total = absint( $customer_service['count'] ?? 0 );
		$gross_sales            = (float) ( $business['business_metrics']['gross_sales'] ?? 0 );
		$active_customers       = absint( $business['business_metrics']['unique_customers'] ?? 0 );
		$inventory_total        = absint( $inventory['count'] ?? 0 );
		$business_state         = 'steady';
		$business_message       = '';

		$priority_scores = [
			'operations_attention' => $attention_total + (int) floor( $backlog_total / 10 ),
			'fulfillment'          => $fulfillment_total,
			'customer_service'     => $customer_service_total,
			'inventory'            => $inventory_total,
		];

		arsort( $priority_scores );

		$top_topic    = (string) key( $priority_scores );
		$top_score    = (int) reset( $priority_scores );
		$second_score = 0;
		$score_values = array_values( $priority_scores );

		if ( isset( $score_values[1] ) ) {
			$second_score = (int) $score_values[1];
		}

		$state = 'steady_watch';

		if ( $top_score >= 200 ) {
			$state = 'high_pressure';
		} elseif ( $top_score >= 50 ) {
			$state = 'focused_pressure';
		} elseif ( $window_orders <= 5 && $top_score <= 10 ) {
			$state = 'quiet_controlled';
		} elseif ( $window_orders >= 15 && $top_score <= 25 ) {
			$state = 'active_controlled';
		}

		if ( $top_score > 0 && abs( $top_score - $second_score ) <= 10 ) {
			$state = 'split_pressure';
		}

		if ( 0 === $window_orders && $gross_sales <= 0 && 0 === $active_customers ) {
			$business_state   = 'quiet';
			$business_message = __( 'Commercial activity is quiet so far in the current window.', 'yoro-ai-core' );
		} elseif ( $window_orders <= 3 && $gross_sales < 250 && $active_customers <= 1 ) {
			$business_state   = 'soft';
			$business_message = __( 'Commercial activity is light so far in the current window.', 'yoro-ai-core' );
		} elseif ( $window_orders >= 25 || $gross_sales >= 5000 || $active_customers >= 10 ) {
			$business_state   = 'strong';
			$business_message = __( 'Commercial activity looks strong in the current window.', 'yoro-ai-core' );
		} elseif ( $window_orders >= 10 || $gross_sales >= 1000 || $active_customers >= 3 ) {
			$business_state   = 'active';
			$business_message = __( 'Commercial activity is moving at a healthy pace in the current window.', 'yoro-ai-core' );
		}

		return [
			'window_key'       => $window_key,
			'window_label'     => sanitize_text_field( $window['label'] ?? __( 'Today', 'yoro-ai-core' ) ),
			'state'            => $state,
			'top_topic'        => sanitize_key( $top_topic ),
			'top_score'        => $top_score,
			'second_score'     => $second_score,
			'window_orders'    => $window_orders,
			'gross_sales'      => wc_format_decimal( $gross_sales, 2 ),
			'active_customers'   => $active_customers,
			'backlog_total'      => $backlog_total,
			'fulfillment_items'  => $fulfillment_total,
			'customer_followups' => $customer_service_total,
			'inventory_items'    => $inventory_total,
			'business_state'     => $business_state,
			'business_message'   => $business_message,
			'priority_scores'    => $priority_scores,
		];
	}

	private static function build_daily_briefing_priority_items( $operations, $attention, $fulfillment, $customer_service, $business, $inventory, $briefing_context = [] ) {
		$items     = [];
		$top_topic = sanitize_key( $briefing_context['top_topic'] ?? '' );
		$top_score = absint( $briefing_context['top_score'] ?? 0 );
		$state     = sanitize_key( $briefing_context['state'] ?? '' );

		if ( ! empty( $operations['attention_total'] ) ) {
			$items[] = [
				'topic'   => 'operations_attention',
				'score'   => absint( $briefing_context['priority_scores']['operations_attention'] ?? 0 ),
				'tone'    => 'operations_attention' === $top_topic || $top_score >= 50 ? 'warning' : 'neutral',
				'title'   => __( 'Operational pressure is elevated', 'yoro-ai-core' ),
				'message' => sprintf(
					__( 'The live store queue shows %1$d attention items and %2$d backlog items right now.', 'yoro-ai-core' ),
					absint( $operations['attention_total'] ?? 0 ),
					absint( $operations['backlog_total'] ?? 0 )
				),
			];
		}

		if ( ! empty( $fulfillment['count'] ) ) {
			$items[] = [
				'topic'   => 'fulfillment',
				'score'   => absint( $briefing_context['priority_scores']['fulfillment'] ?? 0 ),
				'tone'    => 'fulfillment' === $top_topic ? 'warning' : 'neutral',
				'title'   => __( 'Fulfillment still needs active review', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $fulfillment['assistant_summary'] ?? $fulfillment['summary'] ?? '' ),
			];
		}

		if ( ! empty( $customer_service['count'] ) ) {
			$items[] = [
				'topic'   => 'customer_service',
				'score'   => absint( $briefing_context['priority_scores']['customer_service'] ?? 0 ),
				'tone'    => 'customer_service' === $top_topic ? 'warning' : 'neutral',
				'title'   => __( 'Customer follow-up remains active', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $customer_service['assistant_summary'] ?? $customer_service['summary'] ?? '' ),
			];
		}

		if ( ! empty( $business['headline_insights'][0]['message'] ) || ! empty( $briefing_context['business_message'] ) ) {
			$business_title = __( 'Commercial activity deserves a quick read', 'yoro-ai-core' );
			$business_tone  = 'positive';

			if ( 'quiet' === ( $briefing_context['business_state'] ?? '' ) ) {
				$business_title = __( 'Commercial activity is quiet', 'yoro-ai-core' );
				$business_tone  = 'watch';
			} elseif ( 'soft' === ( $briefing_context['business_state'] ?? '' ) ) {
				$business_title = __( 'Commercial activity is still light', 'yoro-ai-core' );
				$business_tone  = 'watch';
			}

			$items[] = [
				'topic'   => 'business',
				'score'   => absint( $business['business_metrics']['order_count'] ?? 0 ),
				'tone'    => $business_tone,
				'title'   => $business_title,
				'message' => sanitize_text_field( $briefing_context['business_message'] ?: $business['headline_insights'][0]['message'] ),
			];
		}

		if ( ! empty( $inventory['count'] ) ) {
			$items[] = [
				'topic'   => 'inventory',
				'score'   => absint( $briefing_context['priority_scores']['inventory'] ?? 0 ),
				'tone'    => 'inventory' === $top_topic ? 'watch' : 'neutral',
				'title'   => __( 'Inventory risk is still present', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $inventory['assistant_summary'] ?? $inventory['summary'] ?? '' ),
			];
		}

		if ( empty( $items ) ) {
			$items[] = [
				'topic'   => 'overview',
				'score'   => 0,
				'tone'    => 'neutral',
				'title'   => __( 'No single queue stands out as critical right now', 'yoro-ai-core' ),
				'message' => __( 'The store still has routine operational work, but nothing in this report looks unusually acute.', 'yoro-ai-core' ),
			];
		}

		usort(
			$items,
			function( $a, $b ) {
				return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
			}
		);

		if ( 'split_pressure' === $state && count( $items ) >= 2 ) {
			$items[0]['title'] = __( 'Two queues are competing for attention', 'yoro-ai-core' );
		}

		return $items;
	}

	private static function build_daily_briefing_drilldowns( $window_key, $briefing_context = [], $priority_items = [] ) {
		$top_topic     = sanitize_key( $briefing_context['top_topic'] ?? '' );
		$window_phrase = self::get_briefing_window_phrase( $window_key );
		$summary_label = self::get_operations_summary_label_for_window( $window_key );
		$drilldowns    = [];

		switch ( $top_topic ) {
			case 'operations_attention':
				$drilldowns = [
					[
						'label'   => __( 'Open attention queue', 'yoro-ai-core' ),
						'topic'   => 'operations_attention',
						'command' => 'Show operations attention queue',
					],
					[
						'label'   => __( 'Check fulfillment pressure', 'yoro-ai-core' ),
						'topic'   => 'fulfillment',
						'command' => 'Show fulfillment queue',
					],
					[
						'label'   => __( 'Check customer follow-up', 'yoro-ai-core' ),
						'topic'   => 'customer_service',
						'command' => 'Show customer service queue',
					],
					[
						'label'   => $summary_label,
						'topic'   => 'operations_overview',
						'command' => 'Show operations report for ' . $window_phrase,
					],
				];
				break;

			case 'fulfillment':
				$drilldowns = [
					[
						'label'   => __( 'Open fulfillment queue', 'yoro-ai-core' ),
						'topic'   => 'fulfillment',
						'command' => 'Show fulfillment queue',
					],
					[
						'label'   => __( 'Check shipping blockers', 'yoro-ai-core' ),
						'topic'   => 'fulfillment',
						'command' => 'What looks stuck in shipping?',
					],
					[
						'label'   => __( 'Review attention queue', 'yoro-ai-core' ),
						'topic'   => 'operations_attention',
						'command' => 'Show operations attention queue',
					],
				];
				break;

			case 'customer_service':
				$drilldowns = [
					[
						'label'   => __( 'Open customer follow-up', 'yoro-ai-core' ),
						'topic'   => 'customer_service',
						'command' => 'Show customer service queue',
					],
					[
						'label'   => __( 'See who needs a reply first', 'yoro-ai-core' ),
						'topic'   => 'customer_service',
						'command' => 'Who should we answer first ' . $window_phrase . '?',
					],
					[
						'label'   => __( 'Review fulfillment queue', 'yoro-ai-core' ),
						'topic'   => 'fulfillment',
						'command' => 'Show fulfillment queue',
					],
				];
				break;

			case 'inventory':
				$drilldowns = [
					[
						'label'   => __( 'Open inventory queue', 'yoro-ai-core' ),
						'topic'   => 'inventory',
						'command' => 'Show inventory queue',
					],
					[
						'label'   => __( 'Focus on restock priorities', 'yoro-ai-core' ),
						'topic'   => 'inventory',
						'command' => 'What should we restock first?',
					],
					[
						'label'   => $summary_label,
						'topic'   => 'operations_overview',
						'command' => 'Show operations report for ' . $window_phrase,
					],
				];
				break;

			case 'business':
				$drilldowns = [
					[
						'label'   => __( 'Open sales report', 'yoro-ai-core' ),
						'topic'   => 'business',
						'command' => 'Show sales report for ' . $window_phrase,
					],
					[
						'label'   => __( 'Review operations report', 'yoro-ai-core' ),
						'topic'   => 'operations_overview',
						'command' => 'Show operations report for ' . $window_phrase,
					],
					[
						'label'   => __( 'Check customer follow-up', 'yoro-ai-core' ),
						'topic'   => 'customer_service',
						'command' => 'Show customer service queue',
					],
				];
				break;
		}

		if ( empty( $drilldowns ) ) {
			$drilldowns = [
				[
					'label'   => $summary_label,
					'topic'   => 'operations_overview',
					'command' => 'Show operations report for ' . $window_phrase,
				],
				[
					'label'   => __( 'Open attention queue', 'yoro-ai-core' ),
					'topic'   => 'operations_attention',
					'command' => 'Show operations attention queue',
				],
				[
					'label'   => __( 'Open sales report', 'yoro-ai-core' ),
					'topic'   => 'business',
					'command' => 'Show sales report for ' . $window_phrase,
				],
			];
		}

		foreach ( $priority_items as $priority_item ) {
			if ( empty( $priority_item['topic'] ) || empty( $priority_item['title'] ) ) {
				continue;
			}

			$priority_topic = sanitize_key( $priority_item['topic'] );

			if ( $priority_topic === $top_topic ) {
				continue;
			}

			$drilldowns[] = [
				'label'   => sprintf(
					__( 'Review: %s', 'yoro-ai-core' ),
					sanitize_text_field( $priority_item['title'] )
				),
				'topic'   => $priority_topic,
				'command' => self::get_briefing_review_command_for_topic( $priority_topic, $window_phrase ),
			];
		}

		$normalized = [];
		$seen       = [];

		foreach ( $drilldowns as $drilldown ) {
			$label   = sanitize_text_field( $drilldown['label'] ?? '' );
			$topic   = sanitize_key( $drilldown['topic'] ?? '' );
			$command = sanitize_text_field( $drilldown['command'] ?? '' );

			if ( '' === $label || '' === $command ) {
				continue;
			}

			$key = strtolower( $command );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$normalized[] = [
				'label'   => $label,
				'topic'   => '' !== $topic ? $topic : 'operations_overview',
				'command' => $command,
			];
		}

		return $normalized;
	}

	private static function get_operations_summary_label_for_window( $window_key ) {
		$relative_days = YoRo_Date_Helper::get_relative_days_from_window_key( $window_key );

		if ( $relative_days > 0 ) {
			return sprintf( __( 'Review %d-day operations report', 'yoro-ai-core' ), $relative_days );
		}

		if ( ! empty( YoRo_Date_Helper::get_relative_window_from_window_key( $window_key ) ) || preg_match( '/^(?:range|month|quarter|year)_/', sanitize_key( $window_key ) ) ) {
			return sprintf( __( 'Review %s operations report', 'yoro-ai-core' ), YoRo_Date_Helper::get_window_label( $window_key ) );
		}

		switch ( sanitize_key( $window_key ) ) {
			case 'last_24_hours':
				return __( 'Review 24-hour operations report', 'yoro-ai-core' );
			case 'this_week':
			case 'last_week':
			case 'last_7_days':
				return __( 'Review weekly operations report', 'yoro-ai-core' );
			case 'this_month':
			case 'last_month':
			case 'last_30_days':
				return __( 'Review monthly operations report', 'yoro-ai-core' );
			case 'this_quarter':
			case 'last_quarter':
				return __( 'Review quarterly operations report', 'yoro-ai-core' );
			case 'this_year':
			case 'last_year':
				return __( 'Review yearly operations report', 'yoro-ai-core' );
			default:
				return __( 'Review operations report', 'yoro-ai-core' );
		}
	}

	private static function get_briefing_review_command_for_topic( $topic, $window_phrase ) {
		switch ( sanitize_key( $topic ) ) {
			case 'operations_attention':
				return 'Show operations attention queue';
			case 'fulfillment':
				return 'Show fulfillment queue';
			case 'customer_service':
				return 'Show customer service queue';
			case 'inventory':
				return 'Show inventory queue';
			case 'business':
				return 'Show sales report for ' . $window_phrase;
			default:
				return 'Show operations report for ' . $window_phrase;
		}
	}

	private static function build_daily_briefing_actions( $briefing_context = [], $fulfillment = [], $customer_service = [], $inventory = [] ) {
		$top_topic = sanitize_key( $briefing_context['top_topic'] ?? '' );
		$actions   = [];

		$fulfillment_orders = isset( $fulfillment['orders'] ) && is_array( $fulfillment['orders'] ) ? $fulfillment['orders'] : [];
		$service_orders     = isset( $customer_service['orders'] ) && is_array( $customer_service['orders'] ) ? $customer_service['orders'] : [];

		if ( ! empty( $fulfillment_orders[0]['suggested_command'] ) ) {
			$actions[] = [
				'topic'   => 'fulfillment',
				'label'   => __( 'Preview next delay email', 'yoro-ai-core' ),
				'command' => sanitize_text_field( $fulfillment_orders[0]['suggested_command'] ),
				'tone'    => 'fulfillment' === $top_topic ? 'primary' : 'secondary',
			];
		}

		if ( ! empty( $service_orders[0]['suggested_command'] ) ) {
			$actions[] = [
				'topic'   => 'customer_service',
				'label'   => __( 'Draft next customer reply', 'yoro-ai-core' ),
				'command' => sanitize_text_field( $service_orders[0]['suggested_command'] ),
				'tone'    => 'customer_service' === $top_topic ? 'primary' : 'secondary',
			];
		}

		if ( ! empty( $inventory['count'] ) ) {
			$actions[] = [
				'topic'   => 'inventory',
				'label'   => __( 'Review restock priorities', 'yoro-ai-core' ),
				'command' => 'What should we restock first?',
				'tone'    => 'inventory' === $top_topic ? 'primary' : 'secondary',
			];
		}

		if ( empty( $actions ) ) {
			return [];
		}

		usort(
			$actions,
			function( $a, $b ) {
				$a_primary = ( 'primary' === ( $a['tone'] ?? '' ) ) ? 1 : 0;
				$b_primary = ( 'primary' === ( $b['tone'] ?? '' ) ) ? 1 : 0;

				return $b_primary <=> $a_primary;
			}
		);

		return $actions;
	}

	private static function build_daily_briefing_recommended_sequence( $briefing_context = [], $priority_drilldowns = [], $briefing_actions = [], $priority_items = [] ) {
		$sequence     = [];
		$top_topic    = sanitize_key( $briefing_context['top_topic'] ?? '' );
		$window_label = sanitize_text_field( $briefing_context['window_label'] ?? __( 'Today', 'yoro-ai-core' ) );

		foreach ( $priority_drilldowns as $drilldown ) {
			$command = sanitize_text_field( $drilldown['command'] ?? '' );
			$topic   = sanitize_key( $drilldown['topic'] ?? '' );

			if ( '' === $command ) {
				continue;
			}

			$sequence[] = [
				'kind'    => 'view',
				'topic'   => '' !== $topic ? $topic : 'operations_overview',
				'label'   => sanitize_text_field( $drilldown['label'] ?? $command ),
				'command' => $command,
				'reason'  => self::build_briefing_sequence_reason_for_topic( '' !== $topic ? $topic : 'operations_overview', $top_topic, $window_label ),
			];
		}

		foreach ( $briefing_actions as $action_item ) {
			$command = sanitize_text_field( $action_item['command'] ?? '' );

			if ( '' === $command ) {
				continue;
			}

			$sequence[] = [
				'kind'    => 'action',
				'topic'   => sanitize_key( $action_item['topic'] ?? '' ),
				'label'   => sanitize_text_field( $action_item['label'] ?? $command ),
				'command' => $command,
				'reason'  => self::build_briefing_sequence_reason_for_action( $action_item, $top_topic ),
			];
		}

		if ( empty( $sequence ) ) {
			foreach ( $priority_items as $priority_item ) {
				$command = self::get_briefing_review_command_for_topic(
					sanitize_key( $priority_item['topic'] ?? '' ),
					self::get_briefing_window_phrase( $briefing_context['window_key'] ?? 'today' )
				);

				if ( '' === $command ) {
					continue;
				}

				$sequence[] = [
					'kind'    => 'view',
					'topic'   => sanitize_key( $priority_item['topic'] ?? '' ),
					'label'   => sanitize_text_field( $priority_item['title'] ?? $command ),
					'command' => $command,
					'reason'  => sanitize_text_field( $priority_item['message'] ?? '' ),
				];
			}
		}

		$normalized = [];
		$seen       = [];
		$step       = 1;

		foreach ( $sequence as $item ) {
			$command = sanitize_text_field( $item['command'] ?? '' );
			$label   = sanitize_text_field( $item['label'] ?? $command );

			if ( '' === $command || '' === $label ) {
				continue;
			}

			$key = strtolower( $command );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$item['step'] = $step;
			$normalized[] = $item;
			$step++;
		}

		return $normalized;
	}

	private static function build_briefing_sequence_reason_for_topic( $topic, $top_topic, $window_label ) {
		$topic = sanitize_key( $topic );

		switch ( $topic ) {
			case 'operations_attention':
				return 'operations_attention' === $top_topic
					? sprintf( __( 'Start here because the attention queue is the main pressure point in %s.', 'yoro-ai-core' ), $window_label )
					: __( 'Use this to review the live attention queue first.', 'yoro-ai-core' );
			case 'fulfillment':
				return __( 'Use this next to confirm whether shipping or aging processing work is building up.', 'yoro-ai-core' );
			case 'customer_service':
				return __( 'Use this to review which customers still need follow-up after the main queue check.', 'yoro-ai-core' );
			case 'inventory':
				return __( 'Use this to check whether stock or replenishment risk is contributing to today’s pressure.', 'yoro-ai-core' );
			case 'business':
				return __( 'Use this to balance the operational view with the commercial picture.', 'yoro-ai-core' );
			default:
				return __( 'Use this to review the broader operating picture for the current window.', 'yoro-ai-core' );
		}
	}

	private static function build_briefing_sequence_reason_for_action( $action_item, $top_topic ) {
		$topic = sanitize_key( $action_item['topic'] ?? '' );

		switch ( $topic ) {
			case 'fulfillment':
				return 'fulfillment' === $top_topic
					? __( 'This is the fastest next move if you want to act on the top fulfillment issue.', 'yoro-ai-core' )
					: __( 'Use this after review if you want to prepare the next fulfillment follow-up.', 'yoro-ai-core' );
			case 'customer_service':
				return 'customer_service' === $top_topic
					? __( 'This is the fastest next move if you want to start the top customer follow-up.', 'yoro-ai-core' )
					: __( 'Use this once you decide which customer follow-up should move first.', 'yoro-ai-core' );
			case 'inventory':
				return __( 'Use this if you want to turn the inventory signal into a restock decision next.', 'yoro-ai-core' );
			default:
				return __( 'Use this when you are ready to act on the current store report.', 'yoro-ai-core' );
		}
	}

	private static function build_business_summary_narrative_context( $window, $business_metrics, $order_status_counts, $backlog_total, $attention_total ) {
		$order_count        = absint( $business_metrics['order_count'] ?? 0 );
		$gross_sales        = (float) ( $business_metrics['gross_sales'] ?? 0 );
		$completed_count    = absint( $order_status_counts['completed'] ?? 0 );
		$open_order_count   = absint( $order_status_counts['pending'] ?? 0 ) + absint( $order_status_counts['processing'] ?? 0 ) + absint( $order_status_counts['on-hold'] ?? 0 );
		$performance_level  = 'quiet';
		$pressure_level     = 'light';
		$state              = 'quiet_and_controlled';

		if ( $order_count >= 25 || $gross_sales >= 5000 ) {
			$performance_level = 'strong';
		} elseif ( $order_count >= 10 || $gross_sales >= 1000 ) {
			$performance_level = 'steady';
		}

		if ( $attention_total >= 25 || $backlog_total >= 50 ) {
			$pressure_level = 'heavy';
		} elseif ( $attention_total >= 5 || $backlog_total >= 10 ) {
			$pressure_level = 'elevated';
		}

		if ( 'strong' === $performance_level && 'heavy' === $pressure_level ) {
			$state = 'strong_but_under_pressure';
		} elseif ( 'strong' === $performance_level ) {
			$state = 'strong_and_controlled';
		} elseif ( 'steady' === $performance_level && 'heavy' === $pressure_level ) {
			$state = 'steady_but_under_pressure';
		} elseif ( 'steady' === $performance_level && 'elevated' === $pressure_level ) {
			$state = 'steady_with_watchpoints';
		} elseif ( 'quiet' === $performance_level && 'heavy' === $pressure_level ) {
			$state = 'quiet_but_under_pressure';
		} elseif ( 'quiet' === $performance_level && 'elevated' === $pressure_level ) {
			$state = 'quiet_with_pressure';
		}

		return [
			'window_label'          => sanitize_text_field( $window['label'] ?? __( 'this period', 'yoro-ai-core' ) ),
			'performance_level'     => $performance_level,
			'pressure_level'        => $pressure_level,
			'state'                 => $state,
			'window_scoped_metrics' => [
				'order_count'        => $order_count,
				'gross_sales'        => wc_format_decimal( $gross_sales, 2 ),
				'unique_customers'   => absint( $business_metrics['unique_customers'] ?? 0 ),
				'completed_orders'   => $completed_count,
				'open_window_orders' => $open_order_count,
			],
			'live_scoped_metrics'   => [
				'attention_total' => absint( $attention_total ),
				'backlog_total'   => absint( $backlog_total ),
			],
		];
	}

	private static function build_business_summary_insights( $window, $business_metrics, $order_status_counts, $backlog_total, $attention_total, $top_customers, $top_products, $narrative_context = [] ) {
		$insights         = [];
		$order_count      = absint( $business_metrics['order_count'] ?? 0 );
		$completed_count  = absint( $order_status_counts['completed'] ?? 0 );
		$processing_count = absint( $order_status_counts['processing'] ?? 0 );
		$pending_count    = absint( $order_status_counts['pending'] ?? 0 );
		$on_hold_count    = absint( $order_status_counts['on-hold'] ?? 0 );
		$state            = sanitize_key( $narrative_context['state'] ?? '' );
		$repeat_customers = absint( $business_metrics['repeat_customers'] ?? 0 );
		$repeat_customer_rate = (float) ( $business_metrics['repeat_customer_rate'] ?? 0 );
		$refund_rate      = (float) ( $business_metrics['refund_rate'] ?? 0 );
		$discount_rate    = (float) ( $business_metrics['discount_rate'] ?? 0 );
		$items_per_order  = (float) ( $business_metrics['items_per_order'] ?? 0 );

		switch ( $state ) {
			case 'quiet_but_under_pressure':
				$insights[] = [
					'topic'   => 'state_summary',
					'tone'    => 'warning',
					'title'   => __( 'Sales are quiet, but operational load is still heavy', 'yoro-ai-core' ),
					'message' => __( 'The requested window looks commercially light, but the live store queue is still carrying substantial operational pressure.', 'yoro-ai-core' ),
				];
				break;

			case 'strong_but_under_pressure':
				$insights[] = [
					'topic'   => 'state_summary',
					'tone'    => 'watch',
					'title'   => __( 'Demand is strong, but the team is under pressure', 'yoro-ai-core' ),
					'message' => __( 'Sales activity in the requested window is healthy, but the current queue still suggests meaningful operational strain.', 'yoro-ai-core' ),
				];
				break;

			case 'strong_and_controlled':
				$insights[] = [
					'topic'   => 'state_summary',
					'tone'    => 'positive',
					'title'   => __( 'Commercial performance looks healthy', 'yoro-ai-core' ),
					'message' => __( 'The requested window looks commercially strong and the current queue does not suggest unusual pressure.', 'yoro-ai-core' ),
				];
				break;

			case 'steady_with_watchpoints':
				$insights[] = [
					'topic'   => 'state_summary',
					'tone'    => 'watch',
					'title'   => __( 'Performance is steady, with some operational watchpoints', 'yoro-ai-core' ),
					'message' => __( 'The requested window looks stable overall, but the current queue still has a few areas worth monitoring.', 'yoro-ai-core' ),
				];
				break;
		}

		if ( $repeat_customers > 0 ) {
			$insights[] = [
				'topic'   => 'repeat_customers',
				'tone'    => $repeat_customer_rate >= 25 ? 'positive' : 'neutral',
				'title'   => __( 'Repeat customers are visible in this window', 'yoro-ai-core' ),
				'message' => sprintf(
					__( '%1$d customers ordered more than once, giving this window a %2$s%% repeat customer rate.', 'yoro-ai-core' ),
					$repeat_customers,
					wc_format_decimal( $repeat_customer_rate, 1 )
				),
			];
		}

		if ( $refund_rate > 0 ) {
			$insights[] = [
				'topic'   => 'refunds',
				'tone'    => $refund_rate >= 5 ? 'watch' : 'neutral',
				'title'   => __( 'Refunds affect the sales readout', 'yoro-ai-core' ),
				'message' => sprintf(
					__( 'Refunds represent %s%% of gross sales in this window, so net sales are the better health check.', 'yoro-ai-core' ),
					wc_format_decimal( $refund_rate, 1 )
				),
			];
		}

		if ( $discount_rate >= 10 ) {
			$insights[] = [
				'topic'   => 'discounts',
				'tone'    => 'watch',
				'title'   => __( 'Discounting is shaping sales quality', 'yoro-ai-core' ),
				'message' => sprintf(
					__( 'Discounts are %s%% of gross sales in this window, so margin quality may deserve a quick check.', 'yoro-ai-core' ),
					wc_format_decimal( $discount_rate, 1 )
				),
			];
		}

		if ( $items_per_order >= 2 ) {
			$insights[] = [
				'topic'   => 'basket_size',
				'tone'    => 'positive',
				'title'   => __( 'Basket size is helping order value', 'yoro-ai-core' ),
				'message' => sprintf(
					__( 'Customers are buying %s items per order on average in this window.', 'yoro-ai-core' ),
					wc_format_decimal( $items_per_order, 2 )
				),
			];
		}

		if ( $attention_total > 0 ) {
			$insights[] = [
				'topic'   => 'operations_pressure',
				'tone'    => $attention_total >= 10 ? 'warning' : 'watch',
				'title'   => __( 'Operational pressure is building', 'yoro-ai-core' ),
				'message' => sprintf(
					__( 'Across the store right now, %1$d items look time-sensitive and %2$d orders are still sitting in backlog.', 'yoro-ai-core' ),
					$attention_total,
					$backlog_total
				),
			];
		} elseif ( $backlog_total > 0 ) {
			$insights[] = [
				'topic'   => 'operations_pressure',
				'tone'    => 'watch',
				'title'   => __( 'Backlog is present but controlled', 'yoro-ai-core' ),
				'message' => sprintf(
					__( 'Across the store right now, %d orders are still open across pending, processing, or on-hold states.', 'yoro-ai-core' ),
					$backlog_total
				),
			];
		}

		if ( $completed_count > 0 && $order_count > 0 ) {
			$completed_share = ( $completed_count / max( 1, $order_count ) ) * 100;
			$insights[]      = [
				'topic'   => 'fulfillment_velocity',
				'tone'    => $completed_share >= 50 ? 'positive' : 'neutral',
				'title'   => __( 'Completed orders are carrying the period', 'yoro-ai-core' ),
				'message' => sprintf(
					__( '%1$d of %2$d orders are already completed, which suggests fulfillment is moving.', 'yoro-ai-core' ),
					$completed_count,
					$order_count
				),
			];
		} elseif ( $processing_count + $pending_count + $on_hold_count > 0 ) {
			$insights[] = [
				'topic'   => 'open_order_mix',
				'tone'    => 'neutral',
				'title'   => __( 'Most activity is still in-flight', 'yoro-ai-core' ),
				'message' => sprintf(
					__( '%1$d pending, %2$d processing, and %3$d on-hold orders still need to convert into completed revenue.', 'yoro-ai-core' ),
					$pending_count,
					$processing_count,
					$on_hold_count
				),
			];
		}

		if ( ! empty( $top_customers[0]['name'] ) || ! empty( $top_customers[0]['email'] ) ) {
			$leader_name = ! empty( $top_customers[0]['name'] ) ? $top_customers[0]['name'] : $top_customers[0]['email'];
			$insights[]  = [
				'topic'   => 'top_customers',
				'tone'    => 'positive',
				'title'   => __( 'A clear customer leader stands out', 'yoro-ai-core' ),
				'message' => sprintf(
					__( '%1$s generated %2$d orders in this window and is one of the strongest repeat-customer signals.', 'yoro-ai-core' ),
					$leader_name,
					absint( $top_customers[0]['order_count'] ?? 0 )
				),
			];
		}

		if ( ! empty( $top_products[0]['name'] ) ) {
			$insights[] = [
				'topic'   => 'top_products',
				'tone'    => 'positive',
				'title'   => __( 'One product is leading demand', 'yoro-ai-core' ),
				'message' => sprintf(
					__( '%1$s sold %2$s units and is the strongest product signal in this period.', 'yoro-ai-core' ),
					$top_products[0]['name'],
					wc_format_decimal( $top_products[0]['quantity'] ?? 0, 0 )
				),
			];
		}

		if ( 0 === $order_count ) {
			$insights[] = [
				'topic'   => 'sales_gap',
				'tone'    => 'watch',
				'title'   => __( 'There is no order activity in this window', 'yoro-ai-core' ),
				'message' => sprintf(
					__( 'YoRo did not find any orders in %s, so the sales report is mostly a pressure check rather than a revenue readout.', 'yoro-ai-core' ),
					sanitize_text_field( $window['label'] ?? __( 'this period', 'yoro-ai-core' ) )
				),
			];
		}

		$insights = array_values(
			array_filter(
				$insights,
				function( $insight ) {
					return ! empty( $insight['message'] );
				}
			)
		);

		return array_slice( $insights, 0, 3 );
	}

	private static function build_business_orchestrator_context( $window_key, $window, $focus, $business_metrics, $order_status_counts, $backlog_total, $attention_total, $headline_insights ) {
		$order_count      = absint( $business_metrics['order_count'] ?? 0 );
		$gross_sales      = (float) ( $business_metrics['gross_sales'] ?? 0 );
		$unique_customers = absint( $business_metrics['unique_customers'] ?? 0 );
		$top_topic        = sanitize_key( $headline_insights[0]['topic'] ?? '' );
		$state            = sanitize_key( $headline_insights[0]['tone'] ?? 'neutral' );

		if ( '' === $top_topic ) {
			if ( $attention_total >= max( 25, $order_count ) ) {
				$top_topic = 'operations_pressure';
				$state     = 'warning';
			} elseif ( $order_count >= 20 || $gross_sales >= 5000 ) {
				$top_topic = 'sales';
				$state     = 'positive';
			} elseif ( ! empty( $order_status_counts['processing'] ) ) {
				$top_topic = 'fulfillment_velocity';
				$state     = 'watch';
			} else {
				$top_topic = 'overview';
			}
		}

		return [
			'window_key'       => $window_key,
			'window_label'     => sanitize_text_field( $window['label'] ?? __( 'Last 7 days', 'yoro-ai-core' ) ),
			'focus'            => sanitize_key( $focus ),
			'top_topic'        => $top_topic,
			'state'            => $state,
			'order_count'      => $order_count,
			'gross_sales'      => wc_format_decimal( $gross_sales, 2 ),
			'unique_customers' => $unique_customers,
			'backlog_total'    => absint( $backlog_total ),
			'attention_total'  => absint( $attention_total ),
			'completed_count'  => absint( $order_status_counts['completed'] ?? 0 ),
			'processing_count' => absint( $order_status_counts['processing'] ?? 0 ),
			'pending_count'    => absint( $order_status_counts['pending'] ?? 0 ),
			'on_hold_count'    => absint( $order_status_counts['on-hold'] ?? 0 ),
			'failed_count'     => absint( $order_status_counts['failed'] ?? 0 ),
		];
	}

	private static function build_business_priority_items( $business_context, $headline_insights, $top_customers, $top_products ) {
		$items = [];

		foreach ( $headline_insights as $index => $insight ) {
			$items[] = [
				'topic'   => sanitize_key( $insight['topic'] ?? '' ),
				'score'   => max( 1, 100 - ( $index * 10 ) ),
				'tone'    => sanitize_key( $insight['tone'] ?? 'neutral' ),
				'title'   => sanitize_text_field( $insight['title'] ?? __( 'Business signal', 'yoro-ai-core' ) ),
				'message' => sanitize_text_field( $insight['message'] ?? '' ),
			];
		}

		if ( ! empty( $top_customers[0]['name'] ) ) {
			$items[] = [
				'topic'   => 'top_customers',
				'score'   => absint( $business_context['unique_customers'] ?? 0 ),
				'tone'    => 'positive',
				'title'   => __( 'A repeat customer signal is visible', 'yoro-ai-core' ),
				'message' => sprintf(
					__( '%1$s generated %2$d orders in this window and is shaping the current customer mix.', 'yoro-ai-core' ),
					sanitize_text_field( $top_customers[0]['name'] ),
					absint( $top_customers[0]['order_count'] ?? 0 )
				),
			];
		}

		if ( ! empty( $top_products[0]['name'] ) ) {
			$items[] = [
				'topic'   => 'top_products',
				'score'   => absint( $top_products[0]['quantity'] ?? 0 ),
				'tone'    => 'neutral',
				'title'   => __( 'Product demand is concentrated', 'yoro-ai-core' ),
				'message' => sprintf(
					__( '%1$s is leading demand with %2$s units in this window.', 'yoro-ai-core' ),
					sanitize_text_field( $top_products[0]['name'] ),
					sanitize_text_field( $top_products[0]['quantity'] ?? '0' )
				),
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

	private static function build_business_recommended_sequence( $business_context ) {
		$window_phrase = self::get_briefing_window_phrase( $business_context['window_key'] ?? 'last_7_days' );
		$window_label  = self::get_operations_summary_label_for_window( $business_context['window_key'] ?? 'last_7_days' );
		$top_topic     = sanitize_key( $business_context['top_topic'] ?? '' );
		$sequence      = [];

		if ( 'operations_pressure' === $top_topic ) {
			$sequence[] = [
				'kind'    => 'view',
				'topic'   => 'operations_attention',
				'label'   => __( 'Open the attention queue first', 'yoro-ai-core' ),
				'command' => 'Show operations attention queue',
				'reason'  => __( 'Start here when the live queue is overshadowing the commercial picture.', 'yoro-ai-core' ),
			];
			$sequence[] = [
				'kind'    => 'view',
				'topic'   => 'fulfillment',
				'label'   => __( 'Check fulfillment pressure next', 'yoro-ai-core' ),
				'command' => 'Show fulfillment queue',
				'reason'  => __( 'Use this next to confirm whether business pressure is turning into shipping work.', 'yoro-ai-core' ),
			];
		} else {
			$sequence[] = [
				'kind'    => 'view',
				'topic'   => 'operations_overview',
				'label'   => $window_label,
				'command' => 'Show operations report for ' . $window_phrase,
				'reason'  => __( 'Start here when you want the operating context behind the commercial picture.', 'yoro-ai-core' ),
			];
			$sequence[] = [
				'kind'    => 'view',
				'topic'   => 'inventory',
				'label'   => __( 'Check inventory pressure', 'yoro-ai-core' ),
				'command' => 'Show inventory queue',
				'reason'  => __( 'Use this next to see whether demand is starting to create stock risk.', 'yoro-ai-core' ),
			];
		}

		$sequence[] = [
			'kind'    => 'view',
			'topic'   => 'customer_service',
			'label'   => __( 'Check customer follow-up load', 'yoro-ai-core' ),
			'command' => 'Show customer service queue',
			'reason'  => __( 'Use this to see whether customer support load is building behind current activity.', 'yoro-ai-core' ),
		];
		$sequence[] = [
			'kind'    => 'view',
			'topic'   => 'business_summary',
			'label'   => __( 'Re-check this sales window', 'yoro-ai-core' ),
			'command' => 'Show sales report for ' . $window_phrase,
			'reason'  => __( 'Use this last after queue checks when you want a refreshed commercial read for the same window.', 'yoro-ai-core' ),
		];

		return $sequence;
	}

	private static function build_business_actions( $business_context ) {
		$window_phrase = self::get_briefing_window_phrase( $business_context['window_key'] ?? 'last_7_days' );

		return [
			[
				'topic'   => 'operations_attention',
				'label'   => __( 'Open attention queue', 'yoro-ai-core' ),
				'command' => 'Show operations attention queue',
			],
			[
				'topic'   => 'fulfillment',
				'label'   => __( 'Check fulfillment queue', 'yoro-ai-core' ),
				'command' => 'Show fulfillment queue',
			],
			[
				'topic'   => 'inventory',
				'label'   => __( 'Check inventory queue', 'yoro-ai-core' ),
				'command' => 'Show inventory queue',
			],
			[
				'topic'   => 'operations_overview',
				'label'   => __( 'Open operations report', 'yoro-ai-core' ),
				'command' => 'Show operations report for ' . $window_phrase,
			],
		];
	}

}
