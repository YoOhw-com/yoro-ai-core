<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Orders_Read_Service {

	public static function search_orders( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is not active.', 'yoro-ai-core' ) );
		}

		$limit = isset( $params['limit'] )
			? absint( $params['limit'] )
			: 10;

		$global_limit = absint(
			get_option( 'yoro_ai_max_result_limit', 20 )
		);

		if ( $global_limit <= 0 ) {
			$global_limit = 20;
		}

		$status = $params['status'] ?? [];

		if ( is_string( $status ) ) {
			$status = '' !== trim( $status ) ? [ $status ] : [];
		}

		$status = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) $status )
			)
		);

		$result_limit = min(
			max( $limit, 1 ),
			$global_limit,
			50
		);

		$args = [
			'limit' => $result_limit,
			'type' => 'shop_order',
			'orderby' => 'date',
			'order'   => 'DESC',
		];

		if ( ! empty( $status ) ) {
			$args['status'] = array_map( 'sanitize_key', (array) $status );
		}

		if ( ! empty( $params['order_id'] ) ) {
			$args['include'] = [ absint( $params['order_id'] ) ];
			$args['limit']   = 1;
			$result_limit    = 1;
			unset( $args['status'] );
		}

		if ( ! empty( $params['email'] ) ) {
			$args['billing_email'] = sanitize_email( $params['email'] );
		}

		if ( ! empty( $params['customer_id'] ) ) {
			$args['customer_id'] = absint( $params['customer_id'] );
		}

		if ( ! empty( $params['payment_method'] ) ) {
			$args['payment_method'] = sanitize_key( $params['payment_method'] );
		}

		if ( ! empty( $params['billing_country'] ) ) {
			$args['billing_country'] = strtoupper( sanitize_text_field( $params['billing_country'] ) );
		}

		if ( ! empty( $params['shipping_state'] ) ) {
			$args['shipping_state'] = strtoupper( sanitize_text_field( $params['shipping_state'] ) );
		}

		$min_age_hours = ! empty( $params['min_age_hours'] )
			? min( max( absint( $params['min_age_hours'] ), 1 ), 8760 )
			: 0;
		$date_window = ! empty( $params['date_window'] )
			? YoRo_Date_Helper::normalize_window_key( $params['date_window'] )
			: YoRo_Date_Helper::infer_window_key_from_text( $params['original_command'] ?? '' );
		$resolved_window = $date_window ? YoRo_Date_Helper::resolve_window( $date_window ) : [];

		if ( $min_age_hours > 0 ) {
			$args['date_created'] = '<=' . self::build_relative_cutoff( '-' . $min_age_hours . ' hours' );
			$resolved_window = [];
		} elseif ( ! empty( $resolved_window ) ) {
			$args['date_created'] = $resolved_window['query'];
		} elseif ( ! empty( $params['date_from'] ) || ! empty( $params['date_to'] ) ) {
			$date_from = ! empty( $params['date_from'] ) ? sanitize_text_field( $params['date_from'] ) : '';
			$date_to   = ! empty( $params['date_to'] ) ? sanitize_text_field( $params['date_to'] ) : '';

			if ( $date_from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
				$date_from .= ' 00:00:00';
			}

			if ( $date_to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
				$date_to .= ' 23:59:59';
			}

			if ( $date_from && $date_to ) {
				$args['date_created'] = $date_from . '...' . $date_to;
			} elseif ( $date_from ) {
				$args['date_created'] = '>=' . $date_from;
			} elseif ( $date_to ) {
				$args['date_created'] = '<=' . $date_to;
			}
		}

		$post_filter_scan = [];
		$orders = self::query_filtered_orders( $args, $params, $result_limit, $post_filter_scan );

		$data = [];

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$data[] = YoRo_Order_Presenter::format_order_summary( $order );
			}
		}

		$response = [
			'success'      => true,
			'action'       => 'orders.search',
			'summary'      => self::build_search_summary( count( $data ), $params, $resolved_window ),
			'count'        => count( $data ),
			'read_only'    => true,
			'orders'       => $data,
			'result_type'  => 'orders',
			'display_type' => 'orders_list',
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
		];

		if ( ! empty( $post_filter_scan ) ) {
			$response['post_filter_scan'] = $post_filter_scan;
		}

		if ( empty( $data ) ) {
			$response['empty_state'] = self::build_orders_search_empty_state( $params, $resolved_window );
		}

		if ( ! empty( $resolved_window ) ) {
			$response['date_window'] = YoRo_Date_Helper::build_window_payload( $resolved_window );
		}

		if ( $min_age_hours > 0 ) {
			$is_vietnamese = YoRo_Date_Helper::is_likely_vietnamese(
				strtolower( sanitize_text_field( (string) ( $params['original_command'] ?? '' ) ) )
			);
			$response['min_age_hours'] = $min_age_hours;
			$response['age_filter_label'] = class_exists( 'YoRo_Response_UX_Formatter' )
				? YoRo_Response_UX_Formatter::age_threshold_label(
					$min_age_hours,
					[
						'command' => $params['original_command'] ?? '',
					],
					$is_vietnamese ? 'vi' : 'en'
				)
				: '';
		}

		return $response;
	}

	private static function query_filtered_orders( $args, $params, $result_limit, &$scan_meta = [] ) {
		$args         = is_array( $args ) ? $args : [];
		$params       = is_array( $params ) ? $params : [];
		$result_limit = min( max( absint( $result_limit ), 1 ), 50 );
		$scan_meta    = [];

		if ( ! self::has_post_query_filters( $params ) || ! empty( $args['include'] ) ) {
			$orders = wc_get_orders( $args );
			$orders = self::apply_post_query_filters( $orders, $params );

			return array_slice( array_values( $orders ), 0, $result_limit );
		}

		$page_size  = min( 50, max( $result_limit * 3, 20 ) );
		$scan_limit = self::get_post_filter_scan_limit( $result_limit );
		$matched    = [];
		$seen_ids   = [];
		$scanned    = 0;
		$page       = 1;
		$base_args  = $args;

		unset( $base_args['offset'] );

		while ( $scanned < $scan_limit && count( $matched ) < $result_limit ) {
			$query_args = $base_args;
			$query_args['limit']  = min( $page_size, $scan_limit - $scanned );
			$query_args['offset'] = ( $page - 1 ) * $page_size;

			if ( $query_args['limit'] <= 0 ) {
				break;
			}

			$page_orders = wc_get_orders( $query_args );
			$page_orders = is_array( $page_orders ) ? $page_orders : [];
			$page_count  = count( $page_orders );

			if ( 0 === $page_count ) {
				break;
			}

			$scanned += $page_count;

			foreach ( self::apply_post_query_filters( $page_orders, $params ) as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				$order_id = $order->get_id();

				if ( isset( $seen_ids[ $order_id ] ) ) {
					continue;
				}

				$seen_ids[ $order_id ] = true;
				$matched[] = $order;

				if ( count( $matched ) >= $result_limit ) {
					break;
				}
			}

			if ( $page_count < $page_size ) {
				break;
			}

			$page++;
		}

		$scan_meta = [
			'applied'       => true,
			'scanned'       => $scanned,
			'limit'         => $scan_limit,
			'matched'       => count( $matched ),
			'result_limit'  => $result_limit,
			'filters'       => self::post_query_filter_keys( $params ),
		];

		return array_slice( array_values( $matched ), 0, $result_limit );
	}

	private static function has_post_query_filters( $params ) {
		return ! empty( self::post_query_filter_keys( $params ) );
	}

	private static function post_query_filter_keys( $params ) {
		$params = is_array( $params ) ? $params : [];
		$keys   = [];

		if ( ! empty( $params['shipping_state'] ) ) {
			$keys[] = 'shipping_state';
		}

		if ( ! empty( $params['phone'] ) ) {
			$keys[] = 'phone';
		}

		if ( ! empty( $params['customer_name'] ) ) {
			$keys[] = 'customer_name';
		}

		if ( isset( $params['total_min'] ) || isset( $params['total_max'] ) ) {
			$keys[] = 'order_total';
		}

		return $keys;
	}

	private static function get_post_filter_scan_limit( $result_limit ) {
		$configured = absint( get_option( 'yoro_order_search_post_filter_scan_limit', 500 ) );

		if ( $configured <= 0 ) {
			$configured = 500;
		}

		return min( max( $configured, max( absint( $result_limit ) * 20, 100 ) ), 1000 );
	}

	private static function apply_post_query_filters( $orders, $params ) {
		$orders = is_array( $orders ) ? $orders : [];
		$params = is_array( $params ) ? $params : [];

		if ( ! empty( $params['shipping_state'] ) ) {
			$shipping_state = strtoupper( sanitize_text_field( $params['shipping_state'] ) );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $shipping_state ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					return strtoupper( (string) $order->get_shipping_state() ) === $shipping_state;
				}
			);
		}

		if ( ! empty( $params['phone'] ) ) {
			$needle = preg_replace( '/\D+/', '', (string) $params['phone'] );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $needle ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$phone = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );

					return $needle && false !== strpos( $phone, $needle );
				}
			);
		}

		if ( ! empty( $params['customer_name'] ) ) {
			$name_needle = strtolower( trim( sanitize_text_field( $params['customer_name'] ) ) );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $name_needle ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$billing_name = strtolower(
						trim(
							$order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
						)
					);

					$shipping_name = strtolower(
						trim(
							$order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()
						)
					);

					return (
						false !== strpos( $billing_name, $name_needle ) ||
						false !== strpos( $shipping_name, $name_needle )
					);
				}
			);
		}

		if ( isset( $params['total_min'] ) || isset( $params['total_max'] ) ) {
			$total_min = isset( $params['total_min'] ) && '' !== $params['total_min']
				? (float) $params['total_min']
				: null;

			$total_max = isset( $params['total_max'] ) && '' !== $params['total_max']
				? (float) $params['total_max']
				: null;

			$orders = array_filter(
				$orders,
				function( $order ) use ( $total_min, $total_max ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$total = (float) $order->get_total();

					if ( null !== $total_min && $total < $total_min ) {
						return false;
					}

					if ( null !== $total_max && $total > $total_max ) {
						return false;
					}

					return true;
				}
			);
		}

		return array_values( $orders );
	}

	private static function build_orders_search_empty_state( $params, $resolved_window = [] ) {
		$params = is_array( $params ) ? $params : [];
		$command = strtolower( sanitize_text_field( (string) ( $params['original_command'] ?? '' ) ) );
		$is_vietnamese = YoRo_Date_Helper::is_likely_vietnamese( $command );
		$locale = $is_vietnamese ? 'vi' : 'en';
		$phrases = class_exists( 'YoRo_Response_UX_Formatter' )
			? YoRo_Response_UX_Formatter::order_search_filter_phrases(
				$params,
				$resolved_window,
				[
					'command' => $params['original_command'] ?? '',
				],
				$locale
			)
			: [];
		$criteria = ! empty( $phrases ) ? implode( ', ', $phrases ) : '';

		if ( $is_vietnamese ) {
			return [
				'title'       => __( 'Không tìm thấy đơn hàng phù hợp.', 'yoro-ai-core' ),
				'description' => '' !== $criteria
					? sprintf( __( 'Không có đơn hàng nào khớp với bộ lọc: %s.', 'yoro-ai-core' ), $criteria )
					: __( 'Không có đơn hàng nào khớp với yêu cầu này.', 'yoro-ai-core' ),
				'actions'     => [
					[
						'label'   => __( 'Xem 5 đơn mới nhất', 'yoro-ai-core' ),
						'command' => 'Show latest 5 orders',
					],
				],
			];
		}

		return [
			'title'       => __( 'No matching orders found.', 'yoro-ai-core' ),
			'description' => '' !== $criteria
				? sprintf( __( 'No orders matched these filters: %s.', 'yoro-ai-core' ), $criteria )
				: __( 'No orders matched this request.', 'yoro-ai-core' ),
			'actions'     => [
				[
					'label'   => __( 'Show latest 5 orders', 'yoro-ai-core' ),
					'command' => 'Show latest 5 orders',
				],
			],
		];
	}

	private static function build_search_summary( $count, $params, $resolved_window = [] ) {
		$count = absint( $count );
		$command = strtolower( sanitize_text_field( (string) ( $params['original_command'] ?? '' ) ) );
		$response_language = sanitize_key( $params['response_language'] ?? '' );
		$is_vietnamese = 'vi' === $response_language || YoRo_Date_Helper::is_likely_vietnamese( $command );
		$min_age_hours = isset( $params['min_age_hours'] ) ? absint( $params['min_age_hours'] ) : 0;
		$locale = $response_language ?: ( $is_vietnamese ? 'vi' : 'en' );

		if ( class_exists( 'YoRo_Response_UX_Formatter' ) ) {
			return YoRo_Response_UX_Formatter::order_search_summary(
				'found',
				$count,
				$params,
				$resolved_window,
				[
					'command' => $params['original_command'] ?? '',
				],
				$locale
			);
		}

		if ( $is_vietnamese ) {
			$status = self::first_status_filter( $params );
			$status_text = $status ? ' ' . $status : '';
			$window_text = ! empty( $resolved_window['key'] )
				? ' trong ' . YoRo_Date_Helper::get_window_label( $resolved_window['key'], 'vi' )
				: '';
			$age_text = $min_age_hours > 0 && class_exists( 'YoRo_Response_UX_Formatter' )
				? ' ' . YoRo_Response_UX_Formatter::age_threshold_phrase(
					$min_age_hours,
					[
						'command' => $params['original_command'] ?? '',
					],
					'vi'
				)
				: '';

			return sprintf( 'Tìm thấy %d đơn%s%s%s.', $count, $status_text, $window_text, $age_text );
		}

		if ( ! empty( $resolved_window['label'] ) ) {
			return sprintf( 'Found %d orders in %s.', $count, strtolower( $resolved_window['label'] ) );
		}

		if ( $min_age_hours > 0 ) {
			$status = self::first_status_filter( $params );
			$status_text = $status ? ' ' . str_replace( '-', ' ', $status ) : '';
			$age_phrase = class_exists( 'YoRo_Response_UX_Formatter' )
				? YoRo_Response_UX_Formatter::age_threshold_phrase(
					$min_age_hours,
					[
						'command' => $params['original_command'] ?? '',
					],
					'en'
				)
				: sprintf( 'older than %d hours', $min_age_hours );

			return sprintf( 'Found %1$d%2$s orders %3$s.', $count, $status_text, $age_phrase );
		}

		return sprintf( 'Found %d orders.', $count );
	}

	private static function first_status_filter( $params ) {
		$params = is_array( $params ) ? $params : [];

		if ( empty( $params['status'] ) ) {
			return '';
		}

		$status_values = is_array( $params['status'] ) ? $params['status'] : [ $params['status'] ];
		$status = reset( $status_values );

		return sanitize_key( $status );
	}

	public static function get_order_details( $params ) {
		$order_ids = YoRo_Order_Helper::normalize_order_ids( $params );

		if ( empty( $order_ids ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID is required.', 'yoro-ai-core' )
			);
		}

		$data = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$data[] = YoRo_Order_Presenter::format_order_details( $order );
		}

		if ( empty( $data ) ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-ai-core' )
			);
		}

		return [
			'success'      => true,
			'action'       => 'orders.get_details',
			'summary'      => sprintf( 'Order details: %d', count( $data ) ),
			'count'        => count( $data ),
			'read_only'    => true,
			'orders'       => $data,
			'result_type'  => 'orders',
			'display_type' => 'order_details',
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
		];
	}

	public static function get_operations_summary( $params ) {
		return YoRo_Store_Operations_Service::get_operations_summary( $params );
	}

	public static function get_operations_attention_queue( $params ) {
		return YoRo_Store_Operations_Service::get_operations_attention_queue( $params );
	}

	public static function get_fulfillment_queue( $params ) {
		return YoRo_Store_Fulfillment_Service::get_fulfillment_queue( $params );
	}

	public static function get_inventory_queue( $params ) {
		return YoRo_Store_Inventory_Service::get_inventory_queue( $params );
	}

	public static function get_store_insights( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is not active.', 'yoro-ai-core' ) );
		}

		$window_key = self::normalize_operations_window_key( $params['window'] ?? 'last_7_days' );
		$window     = self::get_operations_window( $window_key );
		$limit      = isset( $params['limit'] ) ? absint( $params['limit'] ) : 5;
		$limit      = min( max( $limit, 1 ), 20 );

		$orders = wc_get_orders(
			[
				'limit'        => 100,
				'status'       => self::get_all_order_status_slugs(),
				'date_created' => $window['query'],
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);

		$order_status_counts = [];
		$customer_map        = [];
		$product_map         = [];
		$order_total         = 0;
		$order_count         = 0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order_count++;
			$order_total += (float) $order->get_total();

			$status = $order->get_status();
			$order_status_counts[ $status ] = isset( $order_status_counts[ $status ] )
				? $order_status_counts[ $status ] + 1
				: 1;

			$email = strtolower( sanitize_email( $order->get_billing_email() ) );

			if ( $email ) {
				if ( ! isset( $customer_map[ $email ] ) ) {
					$customer_map[ $email ] = [
						'email'       => YoRo_Order_Presenter::mask_email( $email ),
						'name'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
						'order_count' => 0,
						'total_spent' => 0,
					];
				}

				$customer_map[ $email ]['order_count']++;
				$customer_map[ $email ]['total_spent'] += (float) $order->get_total();
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();
				$key     = $product instanceof WC_Product ? $product->get_id() : $item->get_name();

				if ( ! isset( $product_map[ $key ] ) ) {
					$product_map[ $key ] = [
						'id'       => $product instanceof WC_Product ? $product->get_id() : 0,
						'name'     => $item->get_name(),
						'sku'      => $product instanceof WC_Product ? $product->get_sku() : '',
						'quantity' => 0,
						'total'    => 0,
					];
				}

				$product_map[ $key ]['quantity'] += (float) $item->get_quantity();
				$product_map[ $key ]['total'] += (float) $item->get_total();
			}
		}

		$top_customers = array_values( $customer_map );
		usort(
			$top_customers,
			function( $a, $b ) {
				return ( $b['total_spent'] ?? 0 ) <=> ( $a['total_spent'] ?? 0 );
			}
		);
		$top_customers = array_slice( $top_customers, 0, $limit );

		foreach ( $top_customers as &$customer ) {
			$customer['total_spent'] = wc_format_decimal( $customer['total_spent'], 2 );
		}
		unset( $customer );

		$top_products = array_values( $product_map );
		usort(
			$top_products,
			function( $a, $b ) {
				return ( $b['quantity'] ?? 0 ) <=> ( $a['quantity'] ?? 0 );
			}
		);
		$top_products = array_slice( $top_products, 0, $limit );

		foreach ( $top_products as &$product ) {
			$product['total'] = wc_format_decimal( $product['total'], 2 );
		}
		unset( $product );

		$inventory_counts = [
			'out_of_stock' => self::count_products_by_stock_status( 'outofstock' ),
			'low_stock'    => self::count_low_stock_products(),
			'on_backorder' => self::count_products_by_stock_status( 'onbackorder' ),
		];

		$average_order_value = $order_count > 0 ? $order_total / $order_count : 0;

		$insights = [
			[
				'key'     => 'orders',
				'label'   => __( 'Orders', 'yoro-ai-core' ),
				'metrics' => [
					'order_count'         => $order_count,
					'gross_sales'         => wc_format_decimal( $order_total, 2 ),
					'average_order_value' => wc_format_decimal( $average_order_value, 2 ),
				],
				'items'   => self::map_counts_to_items( $order_status_counts ),
			],
			[
				'key'     => 'customers',
				'label'   => __( 'Customers', 'yoro-ai-core' ),
				'metrics' => [
					'unique_customers' => count( $customer_map ),
				],
				'items'   => $top_customers,
			],
			[
				'key'     => 'products',
				'label'   => __( 'Products', 'yoro-ai-core' ),
				'metrics' => $inventory_counts,
				'items'   => $top_products,
			],
		];

		return [
			'success'      => true,
			'action'       => 'store.get_insights',
			'summary'      => sprintf(
				'Grouped store snapshot for %1$s: %2$d orders, %3$d customers, %4$d product lines.',
				$window['label'],
				$order_count,
				count( $customer_map ),
				count( $product_map )
			),
			'message'      => sprintf(
				'Grouped store snapshot for %1$s: %2$d orders, %3$d customers, %4$d product lines.',
				$window['label'],
				$order_count,
				count( $customer_map ),
				count( $product_map )
			),
			'count'        => $order_count,
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [
				'window'          => $window,
				'limit_per_group' => $limit,
				'currency'        => get_woocommerce_currency(),
				'changes_store'   => false,
			],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'window'       => YoRo_Date_Helper::build_window_payload( $window ),
			'insights'     => $insights,
			'result_type'  => 'store_insights',
			'display_type' => 'store_insights',
		];
	}

	public static function get_business_summary( $params ) {
		return YoRo_Store_Briefing_Service::get_business_summary( $params );
	}

	public static function get_daily_briefing( $params ) {
		return YoRo_Store_Briefing_Service::get_daily_briefing( $params );
	}

	public static function get_morning_briefing( $params ) {
		return YoRo_Store_Briefing_Service::get_morning_briefing( $params );
	}

	public static function refund_preview( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;

		if ( ! $order_id ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID is required.', 'yoro-ai-core' )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-ai-core' )
			);
		}

		$total          = (float) $order->get_total();
		$total_refunded = (float) $order->get_total_refunded();
		$remaining      = max( 0, $total - $total_refunded );

		$items = [];

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			$qty           = (float) $item->get_quantity();
			$refunded_qty  = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
			$remaining_qty = max( 0, $qty - $refunded_qty );

			$item_total          = (float) $item->get_total();
			$item_total_refunded = abs( (float) $order->get_total_refunded_for_item( $item_id ) );
			$item_remaining      = max( 0, $item_total - $item_total_refunded );

			$items[] = [
				'item_id'            => absint( $item_id ),
				'product_id'         => $product ? absint( $product->get_id() ) : 0,
				'product_name'       => $item->get_name(),
				'sku'                => $product ? $product->get_sku() : '',
				'quantity'           => $qty,
				'refunded_quantity'  => $refunded_qty,
				'remaining_quantity' => $remaining_qty,
				'line_subtotal'      => wc_format_decimal( $item->get_subtotal(), 2 ),
				'line_total'         => wc_format_decimal( $item_total, 2 ),
				'refunded_total'     => wc_format_decimal( $item_total_refunded, 2 ),
				'remaining_total'    => wc_format_decimal( $item_remaining, 2 ),
			];
		}

		$refunds = [];

		foreach ( $order->get_refunds() as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			$refund_date = $refund->get_date_created();

			$refunds[] = [
				'id'         => $refund->get_id(),
				'amount'     => wc_format_decimal( abs( (float) $refund->get_amount() ), 2 ),
				'reason'     => $refund->get_reason(),
				'date'       => $refund_date ? YoRo_Date_Helper::format_wc_datetime( $refund_date ) : '',
				'date_ts'    => $refund_date ? YoRo_Date_Helper::get_wc_timestamp( $refund_date ) : 0,
				'created_by' => $refund->get_created_by(),
			];
		}

		$order_date_created = $order->get_date_created();

		return [
			'success' => true,
			'action'  => 'orders.refund_preview',
			'summary' => sprintf(
				'Order #%s has %s %s remaining refundable.',
				$order->get_order_number(),
				wc_format_decimal( $remaining, 2 ),
				$order->get_currency()
			),

			'result_type'  => 'refund',
			'display_type' => 'refund_preview',

			'order' => [
				'id'             => $order->get_id(),
				'number'         => $order->get_order_number(),
				'status'         => $order->get_status(),
				'currency'       => $order->get_currency(),
				'total'          => wc_format_decimal( $total, 2 ),
				'total_refunded' => wc_format_decimal( $total_refunded, 2 ),
				'remaining'      => wc_format_decimal( $remaining, 2 ),
				'payment_method' => $order->get_payment_method(),
				'payment_title'  => $order->get_payment_method_title(),
				'transaction_id' => $order->get_transaction_id(),
				'date_created'   => $order_date_created ? YoRo_Date_Helper::format_wc_datetime( $order_date_created ) : '',
				'date_created_ts'=> $order_date_created ? YoRo_Date_Helper::get_wc_timestamp( $order_date_created ) : 0,
				'edit_url'       => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			],

			'totals' => [
				'items_total'      => wc_format_decimal( $order->get_subtotal(), 2 ),
				'discount_total'   => wc_format_decimal( $order->get_discount_total(), 2 ),
				'shipping_total'   => wc_format_decimal( $order->get_shipping_total(), 2 ),
				'fee_total'        => wc_format_decimal( self::get_order_fee_total( $order ), 2 ),
				'tax_total'        => wc_format_decimal( $order->get_total_tax(), 2 ),
				'order_total'      => wc_format_decimal( $total, 2 ),
				'total_refunded'   => wc_format_decimal( $total_refunded, 2 ),
				'remaining_refund' => wc_format_decimal( $remaining, 2 ),
			],

			'items'      => $items,
			'refunds'    => $refunds,
			'can_refund' => $remaining > 0,
			'read_only'  => true,
		];
	}

	public static function get_customer_summary( $params ) {
		$email         = ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
		$phone         = ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '';
		$customer_name = ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '';
		$order_id      = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$limit         = ! empty( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 50 ) : 20;

		if ( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				if ( empty( $email ) ) {
					$email = sanitize_email( $order->get_billing_email() );
				}

				if ( empty( $phone ) ) {
					$phone = sanitize_text_field( $order->get_billing_phone() );
				}

				if ( empty( $customer_name ) ) {
					$customer_name = trim( $order->get_formatted_billing_full_name() );
				}
			}
		}

		if ( empty( $email ) && empty( $phone ) && empty( $customer_name ) ) {
			return new WP_Error( 'invalid_params', __( 'Email, phone, or customer name is required.', 'yoro-ai-core' ) );
		}

		$orders           = self::get_customer_orders( $email, $phone, $limit, $customer_name );
		$total_spent      = 0;
		$status_counts    = [];
		$payment_methods  = [];
		$first_order_timestamp = 0;
		$last_order_timestamp  = 0;
		$resolved_customer_name = '';
		$customer_id      = 0;
		$latest_order_id  = 0;
		$latest_order_no  = '';
		$data             = [];

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$total_spent += (float) $order->get_total();

			$status = $order->get_status();
			$status_counts[ $status ] = isset( $status_counts[ $status ] ) ? $status_counts[ $status ] + 1 : 1;

			$payment = $order->get_payment_method_title();

			if ( $payment ) {
				$payment_methods[ $payment ] = isset( $payment_methods[ $payment ] ) ? $payment_methods[ $payment ] + 1 : 1;
			}

			$date_created = $order->get_date_created();
			$date_ts      = $date_created ? (int) $date_created->getTimestamp() : 0;

			if ( $date_ts > 0 && ( 0 === $last_order_timestamp || $date_ts > $last_order_timestamp ) ) {
				$last_order_timestamp = $date_ts;
			}

			if ( $date_ts > 0 && ( 0 === $first_order_timestamp || $date_ts < $first_order_timestamp ) ) {
				$first_order_timestamp = $date_ts;
			}

			if ( '' === $resolved_customer_name ) {
				$resolved_customer_name = trim( $order->get_formatted_billing_full_name() );
			}

			if ( 0 === $customer_id ) {
				$customer_id = (int) $order->get_customer_id();
			}

			if ( 0 === $latest_order_id ) {
				$latest_order_id = $order->get_id();
				$latest_order_no = $order->get_order_number();
			}

			$data[] = YoRo_Order_Presenter::format_order_summary( $order );
		}

		return [
			'success'          => true,
			'action'           => 'customers.get_summary',
			'summary'          => sprintf( 'Found %d orders for this customer.', count( $data ) ),
			'count'            => count( $data ),
			'customer_name'    => '' !== $resolved_customer_name ? $resolved_customer_name : $customer_name,
			'customer_id'      => $customer_id,
			'read_only'        => true,
			'email'            => YoRo_Order_Presenter::mask_email( $email ),
			'phone'            => YoRo_Order_Presenter::mask_phone( $phone ),
			'total_spent'      => wc_format_decimal( $total_spent, 2 ),
			'currency'         => get_woocommerce_currency(),
			'status_counts'    => $status_counts,
			'payment_methods'  => $payment_methods,
			'first_order_date' => $first_order_timestamp > 0 ? YoRo_Date_Helper::format_timestamp( $first_order_timestamp ) : '',
			'first_order_date_ts' => $first_order_timestamp,
			'last_order_date'  => $last_order_timestamp > 0 ? YoRo_Date_Helper::format_timestamp( $last_order_timestamp ) : '',
			'last_order_date_ts' => $last_order_timestamp,
			'latest_order_id'  => $latest_order_id,
			'latest_order_no'  => $latest_order_no,
			'orders'           => $data,
			'result_type'      => 'customer_summary',
			'display_type'     => 'customer_summary',
			'processed'        => [],
			'skipped'          => [],
			'errors'           => [],
		];
	}

	public static function get_customer_service_queue( $params ) {
		return YoRo_Store_Customer_Service::get_customer_service_queue( $params );
	}

	public static function find_orders_by_customer( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is not active.', 'yoro-ai-core' ) );
		}

		$email       = ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
		$phone       = ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '';
		$customer_id = ! empty( $params['customer_id'] ) ? absint( $params['customer_id'] ) : 0;
		$customer_name = ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '';
		$order_id    = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$limit       = ! empty( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 50 ) : 20;

		if ( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				if ( empty( $email ) ) {
					$email = sanitize_email( $order->get_billing_email() );
				}

				if ( empty( $phone ) ) {
					$phone = sanitize_text_field( $order->get_billing_phone() );
				}

				if ( empty( $customer_id ) ) {
					$customer_id = (int) $order->get_customer_id();
				}

				if ( empty( $customer_name ) ) {
					$customer_name = trim( $order->get_formatted_billing_full_name() );
				}
			}
		}

		if ( empty( $email ) && empty( $phone ) && empty( $customer_id ) && empty( $customer_name ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Email, phone, customer ID, or customer name is required.', 'yoro-ai-core' )
			);
		}

		$args = [
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => self::get_all_order_status_slugs(),
		];

		if ( $email ) {
			$args['billing_email'] = $email;
		}

		if ( $customer_id ) {
			$args['customer_id'] = $customer_id;
		}

		$orders = wc_get_orders( $args );

		if ( $phone ) {
			$needle = self::normalize_phone_digits( $phone );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $needle ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$order_phone = self::normalize_phone_digits( $order->get_billing_phone() );

					return $needle && false !== strpos( $order_phone, $needle );
				}
			);
		}

		if ( $customer_name ) {
			$name_needle = strtolower( trim( sanitize_text_field( $customer_name ) ) );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $name_needle ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$billing_name = strtolower(
						trim(
							$order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
						)
					);

					$shipping_name = strtolower(
						trim(
							$order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()
						)
					);

					return (
						'' !== $name_needle &&
						(
							false !== strpos( $billing_name, $name_needle ) ||
							false !== strpos( $shipping_name, $name_needle )
						)
					);
				}
			);
		}

		$data = [];

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$data[] = YoRo_Order_Presenter::format_order_summary( $order );
			}
		}

		return [
			'success'      => true,
			'action'       => 'orders.find_by_customer',
			'summary'      => sprintf( 'Found %d customer orders.', count( $data ) ),
			'count'        => count( $data ),
			'read_only'    => true,
			'email'        => YoRo_Order_Presenter::mask_email( $email ),
			'phone'        => YoRo_Order_Presenter::mask_phone( $phone ),
			'customer_id'  => $customer_id,
			'customer_name'=> $customer_name,
			'orders'       => $data,
			'result_type'  => 'orders',
			'display_type' => 'orders_list',
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
		];
	}

	public static function find_orders_by_identity( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$limit    = ! empty( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 50 ) : 20;

		if ( ! $order_id ) {
			return new WP_Error( 'invalid_params', __( 'Order ID is required.', 'yoro-ai-core' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'order_not_found', __( 'Order not found.', 'yoro-ai-core' ) );
		}

		$identity = self::build_order_identity( $order );

		$candidates = wc_get_orders(
			[
				'limit'   => $limit + 20,
				'orderby' => 'date',
				'order'   => 'DESC',
				'exclude' => [ $order_id ],
				'status'  => self::get_all_order_status_slugs(),
			]
		);

		$matches = [];

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate instanceof WC_Order ) {
				continue;
			}

			$candidate_identity = self::build_order_identity( $candidate );
			$reasons            = [];

			if ( $identity['email_raw'] && $identity['email_raw'] === $candidate_identity['email_raw'] ) {
				$reasons[] = 'email';
			}

			if ( $identity['phone_digits'] && $identity['phone_digits'] === $candidate_identity['phone_digits'] ) {
				$reasons[] = 'phone';
			}

			if ( $identity['ip'] && $identity['ip'] === $candidate_identity['ip'] ) {
				$reasons[] = 'ip';
			}

			if ( $identity['device_id'] && $identity['device_id'] === $candidate_identity['device_id'] ) {
				$reasons[] = 'device';
			}

			if ( empty( $reasons ) ) {
				continue;
			}

			$item                  = YoRo_Order_Presenter::format_order_summary( $candidate );
			$item['match_reasons'] = $reasons;
			$item['match_score']   = count( $reasons );

			$matches[] = $item;
		}

		usort(
			$matches,
			function( $a, $b ) {
				return ( $b['match_score'] ?? 0 ) <=> ( $a['match_score'] ?? 0 );
			}
		);

		$matches = array_slice( $matches, 0, $limit );

		return [
			'success'      => true,
			'action'       => 'orders.find_by_identity',
			'summary'      => sprintf( 'Found %d identity-matched orders.', count( $matches ) ),
			'count'        => count( $matches ),
			'read_only'    => true,
			'order_id'     => $order_id,
			'identity'     => $identity,
			'orders'       => $matches,
			'result_type'  => 'orders',
			'display_type' => 'orders_list',
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
		];
	}

	public static function preview_search_orders( $params ) {
		$limit  = isset( $params['limit'] ) ? absint( $params['limit'] ) : 10;
		$status = $params['status'] ?? [];

		if ( is_string( $status ) ) {
			$status = '' !== trim( $status ) ? [ $status ] : [];
		}

		$status = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) $status )
			)
		);

		return [
			'type'            => 'read',
			'will_execute'    => false,
			'action'          => 'Search WooCommerce orders',
			'status'          => $status,
			'limit'           => min( max( $limit, 1 ), 50 ),
			'order_id'        => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'email'           => ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : '',
			'phone'           => ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '',
			'payment_method'  => ! empty( $params['payment_method'] ) ? sanitize_key( $params['payment_method'] ) : '',
			'date_window'     => ! empty( $params['date_window'] ) ? YoRo_Date_Helper::normalize_window_key( $params['date_window'] ) : '',
			'date_from'       => ! empty( $params['date_from'] ) ? sanitize_text_field( $params['date_from'] ) : '',
			'date_to'         => ! empty( $params['date_to'] ) ? sanitize_text_field( $params['date_to'] ) : '',
			'min_age_hours'   => ! empty( $params['min_age_hours'] ) ? min( max( absint( $params['min_age_hours'] ), 1 ), 8760 ) : 0,
			'changes_store'   => false,
			'customer_name'   => ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '',
			'billing_country' => ! empty( $params['billing_country'] ) ? strtoupper( sanitize_text_field( $params['billing_country'] ) ) : '',
			'total_min'       => isset( $params['total_min'] ) ? (float) $params['total_min'] : '',
			'total_max'       => isset( $params['total_max'] ) ? (float) $params['total_max'] : '',
		];
	}

	public static function preview_get_order_details( $params ) {
		$order_ids = isset( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_map( 'absint', $params['order_ids'] )
			: [];

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get WooCommerce order details',
			'order_ids'     => $order_ids,
			'count'         => count( $order_ids ),
			'changes_store' => false,
		];
	}

	public static function preview_get_operations_summary( $params ) {
		$window_key = self::normalize_operations_window_key( $params['window'] ?? 'today' );
		$focus      = ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview';
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get store operations summary',
			'window'        => $window['label'],
			'focus'         => $focus,
			'changes_store' => false,
		];
	}

	public static function preview_get_operations_attention_queue( $params ) {
		return [
			'type'                 => 'read',
			'will_execute'         => false,
			'action'               => 'Get operations attention queue',
			'limit_per_queue'      => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'high_value_threshold' => isset( $params['high_value_threshold'] ) ? max( 0, (float) $params['high_value_threshold'] ) : 200,
			'changes_store'        => false,
		];
	}

	public static function preview_get_fulfillment_queue( $params ) {
		return [
			'type'            => 'read',
			'will_execute'    => false,
			'action'          => 'Get fulfillment queue',
			'limit_per_queue' => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'changes_store'   => false,
		];
	}

	public static function preview_get_inventory_queue( $params ) {
		return [
			'type'            => 'read',
			'will_execute'    => false,
			'action'          => 'Get inventory queue',
			'limit_per_queue' => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'focus'           => ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview',
			'changes_store'   => false,
		];
	}

	public static function preview_get_store_insights( $params ) {
		$window_key = self::normalize_operations_window_key( $params['window'] ?? 'last_7_days' );
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get store insights',
			'window'        => $window['label'],
			'limit'         => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'changes_store' => false,
		];
	}

	public static function preview_get_business_summary( $params ) {
		$window_key = self::normalize_operations_window_key( $params['window'] ?? 'last_7_days' );
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get business summary',
			'window'        => $window['label'],
			'focus'         => ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview',
			'changes_store' => false,
		];
	}

	public static function preview_get_daily_briefing( $params ) {
		$window_key = self::normalize_operations_window_key( $params['window'] ?? 'today' );
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get daily store briefing',
			'window'        => $window['label'],
			'limit'         => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 10 ) : 5,
			'changes_store' => false,
		];
	}

	public static function preview_get_morning_briefing( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get morning briefing',
			'window'        => __( 'Yesterday and this morning', 'yoro-ai-core' ),
			'changes_store' => false,
		];
	}

	public static function preview_refund_preview( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Preview WooCommerce refund context',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'changes_store' => false,
		];
	}

	public static function preview_get_customer_summary( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get customer order summary',
			'email'         => ! empty( $params['email'] ) ? YoRo_Order_Presenter::mask_email( $params['email'] ) : '',
			'phone'         => ! empty( $params['phone'] ) ? YoRo_Order_Presenter::mask_phone( $params['phone'] ) : '',
			'limit'         => ! empty( $params['limit'] ) ? absint( $params['limit'] ) : 20,
			'changes_store' => false,
		];
	}

	public static function preview_get_customer_service_queue( $params ) {
		return [
			'type'            => 'read',
			'will_execute'    => false,
			'action'          => 'Get customer service queue',
			'limit_per_queue' => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'auto_send'       => false,
			'changes_store'   => false,
		];
	}

	public static function preview_find_orders_by_customer( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Find WooCommerce orders by customer',
			'email'         => ! empty( $params['email'] ) ? YoRo_Order_Presenter::mask_email( $params['email'] ) : '',
			'phone'         => ! empty( $params['phone'] ) ? YoRo_Order_Presenter::mask_phone( $params['phone'] ) : '',
			'customer_id'   => ! empty( $params['customer_id'] ) ? absint( $params['customer_id'] ) : 0,
			'limit'         => ! empty( $params['limit'] ) ? absint( $params['limit'] ) : 20,
			'changes_store' => false,
		];
	}

	public static function preview_find_orders_by_identity( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Find WooCommerce orders by shared identity',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'limit'         => ! empty( $params['limit'] ) ? absint( $params['limit'] ) : 20,
			'changes_store' => false,
		];
	}

	// Transitional bridge layer for extracted domain services.
	// These wrappers keep low-level query utilities in one place while
	// orchestration has moved into dedicated service classes.
	public static function briefing_normalize_operations_window_key( $window ) {
		return self::normalize_operations_window_key( $window );
	}

	public static function briefing_get_operations_window( $window_key ) {
		return self::get_operations_window( $window_key );
	}

	public static function briefing_get_all_order_status_slugs() {
		return self::get_all_order_status_slugs();
	}

	public static function briefing_count_orders( $args ) {
		return self::count_orders( $args );
	}

	public static function briefing_build_relative_cutoff( $modifier ) {
		return self::build_relative_cutoff( $modifier );
	}

	public static function operations_build_attention_queue( $key, $label, $description, $query_args, $limit ) {
		return self::build_attention_queue( $key, $label, $description, $query_args, $limit );
	}

	public static function operations_build_high_value_open_queue( $limit, $threshold ) {
		return self::build_high_value_open_queue( $limit, $threshold );
	}

	public static function customer_service_build_queue( $key, $label, $description, $query_args, $limit, $reason ) {
		return self::build_customer_service_queue( $key, $label, $description, $query_args, $limit, $reason );
	}

	public static function customer_service_build_saved_email_drafts_queue( $limit ) {
		return self::build_saved_email_drafts_queue( $limit );
	}

	public static function fulfillment_build_filter_queue( $key, $label, $description, $limit, $filter, $reason ) {
		return self::build_fulfillment_filter_queue( $key, $label, $description, $limit, $filter, $reason );
	}

	public static function fulfillment_get_processing_candidates( $limit = 50 ) {
		return self::get_processing_fulfillment_candidates( $limit );
	}

	public static function fulfillment_build_candidate_queue( $key, $label, $description, $candidates, $limit, $filter, $reason ) {
		return self::build_fulfillment_candidate_queue( $key, $label, $description, $candidates, $limit, $filter, $reason );
	}

	public static function fulfillment_order_has_tracking( WC_Order $order ) {
		return self::order_has_tracking( $order );
	}

	public static function fulfillment_order_has_incomplete_shipping_address( WC_Order $order ) {
		return self::order_has_incomplete_shipping_address( $order );
	}

	public static function fulfillment_order_is_ready_to_fulfill( WC_Order $order ) {
		return self::order_is_ready_to_fulfill( $order );
	}

	public static function fulfillment_order_requires_shipping( WC_Order $order ) {
		return self::order_requires_shipping( $order );
	}

	public static function fulfillment_order_is_older_than_hours( WC_Order $order, $hours ) {
		return self::get_order_age_hours( $order ) > max( 0, (int) $hours );
	}

	public static function inventory_build_query_queue( $key, $label, $description, $query_args, $limit, $filter = null ) {
		return self::build_inventory_query_queue( $key, $label, $description, $query_args, $limit, $filter );
	}

	public static function inventory_build_recent_processing_queue( $limit ) {
		return self::build_inventory_recent_processing_queue( $limit );
	}

	public static function inventory_product_is_low_stock( WC_Product $product ) {
		return self::product_is_low_stock( $product );
	}

	private static function get_customer_orders( $email = '', $phone = '', $limit = 20, $customer_name = '' ) {
		$args = [
			'limit'   => min( max( absint( $limit ), 1 ), 50 ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => self::get_all_order_status_slugs(),
		];

		if ( $email ) {
			$args['billing_email'] = sanitize_email( $email );
		}

		$orders = wc_get_orders( $args );

		if ( $phone ) {
			$needle = self::normalize_phone_digits( $phone );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $needle ) {
					return $order instanceof WC_Order
						&& $needle
						&& false !== strpos( self::normalize_phone_digits( $order->get_billing_phone() ), $needle );
				}
			);
		}

		if ( $customer_name ) {
			$name_needle = strtolower( trim( sanitize_text_field( $customer_name ) ) );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $name_needle ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$billing_name = strtolower(
						trim(
							$order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
						)
					);

					$shipping_name = strtolower(
						trim(
							$order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()
						)
					);

					return (
						'' !== $name_needle &&
						(
							false !== strpos( $billing_name, $name_needle ) ||
							false !== strpos( $shipping_name, $name_needle )
						)
					);
				}
			);
		}

		return array_values( $orders );
	}

	private static function normalize_operations_window_key( $window ) {
		$window_key = YoRo_Date_Helper::normalize_window_key( $window );

		return '' !== $window_key ? $window_key : 'today';
	}

	private static function get_operations_window( $window_key ) {
		$window = YoRo_Date_Helper::resolve_window( $window_key );

		if ( empty( $window ) ) {
			$window = YoRo_Date_Helper::resolve_window( 'today' );
		}

		return $window;
	}

	private static function build_relative_cutoff( $modifier ) {
		$cutoff = current_datetime();
		$cutoff = $cutoff->modify( $modifier );

		return $cutoff->format( 'Y-m-d H:i:s' );
	}

	private static function build_attention_queue( $key, $label, $description, $query_args, $limit ) {
		$count  = self::count_orders( $query_args );
		$orders = self::query_attention_orders( $query_args, $limit );

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'orders'      => $orders,
		];
	}

	private static function build_customer_service_queue( $key, $label, $description, $query_args, $limit, $reason ) {
		$count  = self::count_orders( $query_args );
		$orders = self::query_customer_service_orders( $query_args, $limit, $reason );

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'orders'      => $orders,
		];
	}

	private static function build_fulfillment_filter_queue( $key, $label, $description, $limit, $filter, $reason ) {
		return self::build_fulfillment_candidate_queue(
			$key,
			$label,
			$description,
			self::get_processing_fulfillment_candidates( 50 ),
			$limit,
			$filter,
			$reason
		);
	}

	private static function get_processing_fulfillment_candidates( $limit = 50 ) {
		return wc_get_orders(
			[
				'limit'   => min( max( absint( $limit ), 1 ), 200 ),
				'status'  => [ 'processing' ],
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);
	}

	private static function build_fulfillment_candidate_queue( $key, $label, $description, $candidates, $limit, $filter, $reason ) {
		$candidates = is_array( $candidates ) ? $candidates : [];
		$orders     = [];
		$count      = 0;

		foreach ( $candidates as $order ) {
			if ( ! $order instanceof WC_Order || ! is_callable( $filter ) || ! call_user_func( $filter, $order ) ) {
				continue;
			}

			$count++;

			if ( count( $orders ) < $limit ) {
				$orders[] = self::format_fulfillment_order( $order, $reason );
			}
		}

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'orders'      => $orders,
		];
	}

	private static function build_inventory_query_queue( $key, $label, $description, $query_args, $limit, $filter = null ) {
		$products = wc_get_products(
			wp_parse_args(
				$query_args,
				[
					'limit'   => 50,
					'status'  => 'publish',
					'orderby' => 'date',
					'order'   => 'DESC',
				]
			)
		);

		$data  = [];
		$count = 0;

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( is_callable( $filter ) && ! call_user_func( $filter, $product ) ) {
				continue;
			}

			$count++;

			if ( count( $data ) < $limit ) {
				$data[] = self::format_inventory_product( $product );
			}
		}

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'products'    => $data,
		];
	}

	private static function build_inventory_recent_processing_queue( $limit ) {
		$orders = wc_get_orders(
			[
				'limit'        => 25,
				'status'       => [ 'processing' ],
				'date_created' => self::build_relative_cutoff( '-14 days' ) . '...' . self::build_relative_cutoff( 'now' ),
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);

		$products = [];
		$count    = 0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();

				if ( ! $product instanceof WC_Product || ! self::product_is_low_stock( $product ) ) {
					continue;
				}

				$product_id = $product->get_id();

				if ( isset( $products[ $product_id ] ) ) {
					$products[ $product_id ]['open_order_count']++;
					continue;
				}

				$count++;

				if ( count( $products ) < $limit ) {
					$data                     = self::format_inventory_product( $product );
					$data['open_order_count'] = 1;
					$products[ $product_id ]  = $data;
				}
			}
		}

		return [
			'key'         => 'low_stock_in_processing_orders',
			'label'       => __( 'Low stock in processing orders', 'yoro-ai-core' ),
			'description' => __( 'Low-stock products that appear in recent processing orders.', 'yoro-ai-core' ),
			'count'       => $count,
			'products'    => array_values( $products ),
		];
	}

	private static function map_counts_to_items( $counts ) {
		$items = [];

		foreach ( $counts as $key => $count ) {
			$items[] = [
				'key'   => $key,
				'label' => ucwords( str_replace( [ '_', '-' ], ' ', $key ) ),
				'count' => absint( $count ),
			];
		}

		usort(
			$items,
			function( $a, $b ) {
				return ( $b['count'] ?? 0 ) <=> ( $a['count'] ?? 0 );
			}
		);

		return $items;
	}

	private static function count_products_by_stock_status( $stock_status ) {
		$products = wc_get_products(
			[
				'limit'        => -1,
				'status'       => 'publish',
				'stock_status' => sanitize_key( $stock_status ),
				'return'       => 'ids',
			]
		);

		return is_array( $products ) ? count( $products ) : 0;
	}

	private static function count_low_stock_products() {
		$products = wc_get_products(
			[
				'limit'        => 100,
				'status'       => 'publish',
				'manage_stock' => true,
				'return'       => 'objects',
			]
		);

		$count = 0;

		foreach ( $products as $product ) {
			if ( $product instanceof WC_Product && self::product_is_low_stock( $product ) ) {
				$count++;
			}
		}

		return $count;
	}

	private static function build_saved_email_drafts_queue( $limit ) {
		$candidates = wc_get_orders(
			[
				'limit'   => 50,
				'status'  => self::get_all_order_status_slugs(),
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);

		$orders = [];
		$count  = 0;

		foreach ( $candidates as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$drafts = $order->get_meta( '_yoro_customer_email_drafts', true );

			if ( ! is_array( $drafts ) || empty( $drafts ) ) {
				continue;
			}

			$count++;

			if ( count( $orders ) < $limit ) {
				$item                = self::format_customer_service_order(
					$order,
					__( 'Saved email draft is waiting for review or send.', 'yoro-ai-core' )
				);
				$item['draft_count'] = count( $drafts );
				$orders[]            = $item;
			}
		}

		return [
			'key'         => 'saved_email_drafts',
			'label'       => __( 'Saved email drafts', 'yoro-ai-core' ),
			'description' => __( 'Orders with saved customer email drafts that have not been sent by YoRo.', 'yoro-ai-core' ),
			'count'       => $count,
			'orders'      => $orders,
		];
	}

	private static function build_high_value_open_queue( $limit, $threshold ) {
		$candidates = wc_get_orders(
			[
				'limit'   => 50,
				'status'  => [ 'pending', 'processing', 'on-hold' ],
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);

		$orders = [];
		$count  = 0;

		foreach ( $candidates as $order ) {
			if ( ! $order instanceof WC_Order || (float) $order->get_total() < $threshold ) {
				continue;
			}

			$count++;

			if ( count( $orders ) < $limit ) {
				$orders[] = self::format_attention_order( $order );
			}
		}

		return [
			'key'         => 'high_value_open_orders',
			'label'       => __( 'High-value open orders', 'yoro-ai-core' ),
			'description' => sprintf(
				/* translators: %s: order total threshold. */
				__( 'Open orders with total greater than or equal to %s. This is an operations threshold, not fraud scoring.', 'yoro-ai-core' ),
				get_woocommerce_currency_symbol() . wc_format_decimal( $threshold, wc_get_price_decimals() )
			),
			'count'       => $count,
			'orders'      => $orders,
		];
	}

	private static function query_attention_orders( $query_args, $limit ) {
		$orders = wc_get_orders(
			wp_parse_args(
				$query_args,
				[
					'limit'   => $limit,
					'orderby' => 'date',
					'order'   => 'DESC',
				]
			)
		);

		$data = [];

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$data[] = self::format_attention_order( $order );
			}
		}

		return $data;
	}

	private static function format_inventory_product( WC_Product $product ) {
		$stock_quantity = $product->get_stock_quantity();
		$low_threshold  = self::get_product_low_stock_threshold( $product );

		return [
			'id'                  => $product->get_id(),
			'name'                => $product->get_name(),
			'sku'                 => $product->get_sku(),
			'type'                => $product->get_type(),
			'status'              => $product->get_status(),
			'stock_status'        => $product->get_stock_status(),
			'manage_stock'        => $product->managing_stock(),
			'stock_quantity'      => null === $stock_quantity ? '' : $stock_quantity,
			'low_stock_threshold' => $low_threshold,
			'backorders_allowed'  => $product->backorders_allowed(),
			'price'               => $product->get_price(),
			'edit_url'            => get_edit_post_link( $product->get_id(), 'raw' ),
		];
	}

	private static function product_is_low_stock( WC_Product $product ) {
		if ( ! $product->managing_stock() ) {
			return false;
		}

		$quantity  = $product->get_stock_quantity();
		$threshold = self::get_product_low_stock_threshold( $product );

		return null !== $quantity && $quantity <= $threshold;
	}

	private static function get_product_low_stock_threshold( WC_Product $product ) {
		$threshold = $product->get_low_stock_amount();

		if ( '' === $threshold || null === $threshold ) {
			$threshold = get_option( 'woocommerce_notify_low_stock_amount', 2 );
		}

		return max( 0, (int) $threshold );
	}

	private static function query_customer_service_orders( $query_args, $limit, $reason ) {
		$orders = wc_get_orders(
			wp_parse_args(
				$query_args,
				[
					'limit'   => $limit,
					'orderby' => 'date',
					'order'   => 'DESC',
				]
			)
		);

		$data = [];

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$data[] = self::format_customer_service_order( $order, $reason );
			}
		}

		return $data;
	}

	private static function format_attention_order( WC_Order $order ) {
		$data = YoRo_Order_Presenter::format_order_summary( $order );

		$data['age_hours'] = self::get_order_age_hours( $order );

		return $data;
	}

	private static function format_customer_service_order( WC_Order $order, $reason ) {
		$data = self::format_attention_order( $order );

		$data['customer_name']      = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$data['followup_reason']    = sanitize_text_field( $reason );
		$data['suggested_command']  = 'Write email for order #' . $order->get_id() . ' about: ' . sanitize_text_field( $reason );
		$data['customer_id']        = $order->get_customer_id();

		return $data;
	}

	private static function format_fulfillment_order( WC_Order $order, $reason ) {
		$data = self::format_attention_order( $order );

		$data['shipping_name']      = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$data['shipping_city']      = $order->get_shipping_city();
		$data['shipping_country']   = $order->get_shipping_country();
		$data['shipping_method']    = self::get_order_shipping_method_label( $order );
		$data['tracking_detected']  = self::order_has_tracking( $order );
		$data['fulfillment_reason'] = sanitize_text_field( $reason );
		$data['suggested_command']  = 'Preview shipping delay email for order ' . $order->get_id();

		return $data;
	}

	private static function order_has_tracking( WC_Order $order ) {
		$tracking_meta_keys = [
			'_tracking_number',
			'_wc_shipment_tracking_items',
			'_aftership_tracking_number',
			'_aftership_tracking_items',
			'_wcast_tracking_number',
			'_wcst_order_trackno',
			'_yith_tracking_code',
		];

		foreach ( $tracking_meta_keys as $key ) {
			$value = $order->get_meta( $key, true );

			if ( is_array( $value ) && ! empty( $value ) ) {
				return true;
			}

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private static function order_has_incomplete_shipping_address( WC_Order $order ) {
		if ( self::fulfillment_shipping_requirements_disabled() ) {
			return false;
		}

		$country  = trim( (string) $order->get_shipping_country() );
		$city     = trim( (string) $order->get_shipping_city() );
		$address1 = trim( (string) $order->get_shipping_address_1() );

		if ( '' === $country && '' === $city && '' === $address1 ) {
			$country  = trim( (string) $order->get_billing_country() );
			$city     = trim( (string) $order->get_billing_city() );
			$address1 = trim( (string) $order->get_billing_address_1() );
		}

		return '' === $country || '' === $city || '' === $address1;
	}

	private static function order_is_ready_to_fulfill( WC_Order $order ) {
		if ( 'processing' !== $order->get_status() ) {
			return false;
		}

		if ( ! self::order_requires_shipping( $order ) ) {
			return false;
		}

		if ( ! self::order_has_tracking( $order ) ) {
			return false;
		}

		if ( self::order_has_incomplete_shipping_address( $order ) ) {
			return false;
		}

		if ( self::get_order_age_hours( $order ) > 48 ) {
			return false;
		}

		if ( ! self::order_has_valid_shipping_method( $order ) ) {
			return false;
		}

		return true;
	}

	private static function order_requires_shipping( WC_Order $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof WC_Product ) {
				return true;
			}

			if ( ! $product->is_virtual() ) {
				return true;
			}
		}

		return false;
	}

	private static function order_has_valid_shipping_method( WC_Order $order ) {
		if ( self::fulfillment_shipping_requirements_disabled() ) {
			return true;
		}

		return '' !== trim( (string) self::get_order_shipping_method_label( $order ) );
	}

	private static function fulfillment_shipping_requirements_disabled() {
		return 'disabled' === sanitize_key( (string) get_option( 'woocommerce_ship_to_countries', '' ) );
	}

	private static function get_order_shipping_method_label( WC_Order $order ) {
		$methods = [];

		foreach ( $order->get_shipping_methods() as $method ) {
			if ( is_object( $method ) && method_exists( $method, 'get_name' ) ) {
				$methods[] = $method->get_name();
			}
		}

		return implode( ', ', array_filter( $methods ) );
	}

	private static function get_order_age_hours( WC_Order $order ) {
		$date_created = $order->get_date_created();

		if ( ! $date_created ) {
			return 0;
		}

		$created = $date_created->getTimestamp();
		$now     = current_datetime()->getTimestamp();

		return max( 0, (int) floor( ( $now - $created ) / HOUR_IN_SECONDS ) );
	}

	private static function count_orders( $args ) {
		$query_args = wp_parse_args(
			$args,
			[
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
				'orderby'  => 'date',
				'order'    => 'DESC',
			]
		);

		$result = wc_get_orders( $query_args );

		if ( is_object( $result ) && isset( $result->total ) ) {
			return absint( $result->total );
		}

		if ( is_array( $result ) ) {
			return count( $result );
		}

		return 0;
	}

	private static function get_all_order_status_slugs() {
		return array_map(
			function( $key ) {
				return str_replace( 'wc-', '', $key );
			},
			array_keys( wc_get_order_statuses() )
		);
	}

	private static function build_order_identity( WC_Order $order ) {
		$email = strtolower( sanitize_email( $order->get_billing_email() ) );
		$phone = $order->get_billing_phone();

		return [
			'email'        => YoRo_Order_Presenter::mask_email( $email ),
			'email_raw'    => $email,
			'phone'        => YoRo_Order_Presenter::mask_phone( $phone ),
			'phone_digits' => self::normalize_phone_digits( $phone ),
			'ip'           => sanitize_text_field( (string) $order->get_customer_ip_address() ),
			'user_agent'   => sanitize_text_field( (string) $order->get_customer_user_agent() ),
			'device_id'    => sanitize_text_field( (string) $order->get_meta( '_yoro_device_id', true ) ),
		];
	}

	private static function get_order_fee_total( WC_Order $order ) {
		$total = 0;

		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( $fee instanceof WC_Order_Item_Fee ) {
				$total += (float) $fee->get_total();
			}
		}

		return $total;
	}

	private static function normalize_phone_digits( $phone ) {
		return preg_replace( '/\D+/', '', (string) $phone );
	}
}
