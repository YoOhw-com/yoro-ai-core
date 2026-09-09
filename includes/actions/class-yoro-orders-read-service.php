<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Orders_Read_Service {

	public static function get_search_result_limit( $params ) {
		$params = is_array( $params ) ? $params : [];
		$limit = isset( $params['limit'] )
			? absint( $params['limit'] )
			: 10;

		$global_limit = absint( get_option( 'yoro_ai_max_result_limit', 20 ) );

		if ( $global_limit <= 0 ) {
			$global_limit = 20;
		}

		return min( max( $limit, 1 ), $global_limit, 50 );
	}

	public static function search_orders( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
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

		$result_limit = self::get_search_result_limit( $params );

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
		$date_window = YoRo_Date_Helper::window_key_from_params( $params, 'date_window', '' );
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

		return self::format_search_result( $orders, $params, $resolved_window, $min_age_hours, $post_filter_scan );
	}

	public static function format_search_result( $orders, $params, $resolved_window = [], $min_age_hours = 0, $post_filter_scan = [] ) {
		$orders = is_array( $orders ) ? $orders : [];
		$params = is_array( $params ) ? $params : [];
		$resolved_window = is_array( $resolved_window ) ? $resolved_window : [];
		$min_age_hours = absint( $min_age_hours );
		$post_filter_scan = is_array( $post_filter_scan ) ? $post_filter_scan : [];
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
			$locale = sanitize_key( $params['response_language'] ?? '' ) ?: 'en';
			$response['min_age_hours'] = $min_age_hours;
			$response['age_filter_label'] = YoRo_Response_UX_Formatter::age_threshold_label(
				$min_age_hours,
				[
					'command' => $params['original_command'] ?? '',
				],
				$locale
			);
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
		$locale = sanitize_key( $params['response_language'] ?? '' ) ?: 'en';
		$is_vietnamese = 'vi' === $locale;
		$phrases = YoRo_Response_UX_Formatter::order_search_filter_phrases(
			$params,
			$resolved_window,
			[
				'command' => $params['original_command'] ?? '',
			],
			$locale
		);
		$criteria = ! empty( $phrases ) ? implode( ', ', $phrases ) : '';

		if ( $is_vietnamese ) {
			return [
				'title'       => __( 'Không tìm thấy đơn hàng phù hợp.', 'yoro-ai-core' ),
				'description' => '' !== $criteria
					/* translators: %s: Value generated from store data. */
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
				/* translators: %s: Value generated from store data. */
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
		$response_language = sanitize_key( $params['response_language'] ?? '' );
		$locale = $response_language ?: 'en';

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

	private static function first_status_filter( $params ) {
		$params = is_array( $params ) ? $params : [];

		if ( empty( $params['status'] ) ) {
			return '';
		}

		$status_values = is_array( $params['status'] ) ? $params['status'] : [ $params['status'] ];
		$status = reset( $status_values );

		return sanitize_key( $status );
	}

	public static function get_payment_context( $params ) {
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', '_yoro_confirmed', '_yoro_preview' ] )
			|| ! isset( $params['order_id'] ) || ! is_int( $params['order_id'] ) || $params['order_id'] < 1 ) {
			return new WP_Error( 'invalid_params', __( 'Choose exactly one positive integer order ID.', 'yoro-ai-core' ) );
		}
		foreach ( [ '_yoro_confirmed', '_yoro_preview' ] as $flag ) {
			if ( array_key_exists( $flag, $params ) && ! is_bool( $params[ $flag ] ) ) {
				return new WP_Error( 'invalid_params', __( 'Invalid execution flags.', 'yoro-ai-core' ) );
			}
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}
		$order = wc_get_order( $params['order_id'] );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_id() !== $params['order_id'] ) {
			return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
		}
		$payment_context = YoRo_Order_Presenter::format_payment_context( $order );
		if ( null === $payment_context ) {
			return new WP_Error( 'payment_context_unavailable', __( 'The order payment context is unavailable.', 'yoro-ai-core' ) );
		}
		return [
			'success' => true,
			'action' => 'orders.get_payment_context',
			'summary' => 'WooCommerce order payment facts; independent gateway verification is not observed.',
			'payment_context' => $payment_context,
			'read_only' => true,
			'result_type' => 'payment_context',
			'display_type' => 'payment_context',
		];
	}

	public static function get_order_details( $params ) {
		$order_ids = YoRo_Order_Helper::normalize_order_ids( $params );

			if ( empty( $order_ids ) ) {
				return new WP_Error(
					'invalid_params',
					__( 'Please choose an order first.', 'yoro-ai-core' )
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
					__( 'I could not find that order.', 'yoro-ai-core' )
				);
			}

		$focused_response = self::focused_order_details_response( $params, $data );

		if ( ! empty( $focused_response ) ) {
			return $focused_response;
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

	private static function focused_order_details_response( $params, array $orders ) {
		$response_focus = sanitize_key( $params['response_focus'] ?? '' );

		if ( 'order_status' !== $response_focus || 1 !== count( $orders ) ) {
			return [];
		}

		$order = reset( $orders );

		if ( ! is_array( $order ) ) {
			return [];
		}

		$order_id = absint( $order['id'] ?? ( $params['order_id'] ?? 0 ) );
		$status   = sanitize_text_field( $order['status_label'] ?? '' );

		if ( '' === $status ) {
			$status = self::humanize_status_key( $order['status'] ?? '' );
		}

		if ( ! $order_id || '' === $status ) {
			return [];
		}

		$message = self::focused_order_status_message(
			$order_id,
			$status,
			sanitize_key( $params['response_language'] ?? '' )
		);

		return [
			'success'          => true,
			'action'           => 'orders.get_details',
			'summary'          => $message,
			'message'          => $message,
			'count'            => 1,
			'read_only'        => true,
			'order'            => $order,
			'orders'           => $orders,
			'response_focus'   => 'order_status',
			'requested_fields' => is_array( $params['requested_fields'] ?? null )
				? array_values( array_map( 'sanitize_key', $params['requested_fields'] ) )
				: [ 'status', 'status_label' ],
			'result_type'      => 'assistant_message',
			'display_type'     => 'assistant_message',
			'processed'        => [],
			'skipped'          => [],
			'errors'           => [],
		];
	}

	private static function focused_order_status_message( $order_id, $status, $language ) {
		if ( 0 === strpos( strtolower( (string) $language ), 'vi' ) ) {
			return sprintf( 'Đơn #%d đang ở trạng thái %s.', $order_id, $status );
		}

		return sprintf( 'Order #%d is currently %s.', $order_id, $status );
	}

	private static function humanize_status_key( $status ) {
		$status = sanitize_key( $status );
		$status = preg_replace( '/^wc-/', '', $status );
		$status = str_replace( [ '-', '_' ], ' ', $status );

		return ucwords( trim( $status ) );
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
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$window_key = self::normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_7_days' ) );
		$window     = self::get_operations_window( $window_key );
		$limit      = isset( $params['limit'] ) ? absint( $params['limit'] ) : 5;
		$limit      = min( max( $limit, 1 ), 20 );

		$orders = self::report_query_orders(
			[
				'status'       => self::get_revenue_order_status_slugs(),
				'date_created' => $window['query'],
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);

		$order_status_counts = [];
		$customer_map        = [];
		$product_map         = [];
		$category_map        = [];
		$category_cache      = [];
		$order_total         = 0;
		$order_count         = 0;
		$items_sold          = 0.0;

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
				$product_id = $item->get_product_id();
				$category_context = self::get_product_category_context( $product_id, $category_cache );

				if ( ! isset( $product_map[ $key ] ) ) {
					$product_map[ $key ] = [
						'id'         => $product instanceof WC_Product ? $product->get_id() : 0,
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
						'total'      => 0,
					];
				}

				$quantity = (float) $item->get_quantity();
				$line_total = (float) $item->get_total();
				$items_sold += $quantity;
				$product_map[ $key ]['quantity'] += $quantity;
				$product_map[ $key ]['total'] += $line_total;
				self::record_category_mix( $category_map, $category_context, $key, $quantity, $line_total );
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
			$product['categories'] = array_values( array_filter( is_array( $product['categories'] ?? null ) ? $product['categories'] : [] ) );
		}
		unset( $product );
		$top_categories = self::format_category_mix( $category_map, $limit );

		$inventory_counts = [
			'out_of_stock' => self::count_products_by_stock_status( 'outofstock' ),
			'low_stock'    => self::count_low_stock_products(),
			'on_backorder' => self::count_products_by_stock_status( 'onbackorder' ),
		];

		$average_order_value = $order_count > 0 ? $order_total / $order_count : 0;
		$repeat_customer_count = count(
			array_filter(
				$customer_map,
				function( $customer ) {
					return is_array( $customer ) && absint( $customer['order_count'] ?? 0 ) > 1;
				}
			)
		);
		$repeat_customer_rate = count( $customer_map ) > 0 ? ( $repeat_customer_count / count( $customer_map ) ) * 100 : 0;
		$items_per_order      = $order_count > 0 ? $items_sold / $order_count : 0;
		$previous_window = YoRo_Date_Helper::build_previous_window( $window );
		$previous_signal_metrics = self::summarize_store_signal_window( $previous_window );
		$comparison = self::build_store_signal_comparison(
			[
				'orders'              => $order_count,
				'gross_sales'         => $order_total,
				'average_order_value' => $average_order_value,
				'unique_customers'    => count( $customer_map ),
				'repeat_customers'    => $repeat_customer_count,
				'repeat_customer_rate' => $repeat_customer_rate,
				'items_sold'          => $items_sold,
				'items_per_order'     => $items_per_order,
				'product_lines'       => count( $product_map ),
				'product_categories'  => count( $category_map ),
			],
			$previous_signal_metrics,
			$previous_window
		);

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
					'repeat_customers' => $repeat_customer_count,
					'repeat_customer_rate' => wc_format_decimal( $repeat_customer_rate, 1 ),
				],
				'items'   => $top_customers,
			],
			[
				'key'     => 'products',
				'label'   => __( 'Products', 'yoro-ai-core' ),
				'metrics' => $inventory_counts,
				'category_mix' => $top_categories,
				'items'   => $top_products,
			],
		];
		$report = self::build_store_insights_report(
			$window,
			$order_count,
			$order_total,
			$average_order_value,
			count( $customer_map ),
			$repeat_customer_count,
			$repeat_customer_rate,
			count( $product_map ),
			count( $category_map ),
			$items_sold,
			$items_per_order,
			$inventory_counts,
			$top_customers,
			$top_products,
			$top_categories,
			$comparison
		);

		return [
			'success'      => true,
			'action'       => 'store.get_insights',
			'summary'      => sprintf(
				'Store signal report for %1$s: %2$d orders, %3$d customers, %4$d product lines.',
				$window['label'],
				$order_count,
				count( $customer_map ),
				count( $product_map )
			),
			'message'      => sprintf(
				'Store signal report for %1$s: %2$d orders, %3$d customers, %4$d product lines.',
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
			'top_products' => $top_products,
			'top_categories' => $top_categories,
			'comparison'   => $comparison,
			'report'       => $report,
			'result_type'  => 'store_insights',
			'display_type' => 'store_insights',
		];
	}

	private static function summarize_store_signal_window( $window ) {
		$summary = [
			'orders'              => 0,
			'gross_sales'         => 0.0,
			'average_order_value' => 0.0,
			'unique_customers'    => 0,
			'repeat_customers'    => 0,
			'repeat_customer_rate' => 0.0,
			'items_sold'          => 0.0,
			'items_per_order'     => 0.0,
			'product_lines'       => 0,
			'product_categories'  => 0,
		];

		if ( empty( $window['query'] ) || ! function_exists( 'wc_get_orders' ) ) {
			return $summary;
		}

		$customer_map = [];
		$product_map  = [];
		$category_map = [];
		$category_cache = [];
		$orders       = wc_get_orders(
			[
				'limit'        => 100,
				'status'       => self::get_revenue_order_status_slugs(),
				'date_created' => $window['query'],
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$summary['orders']++;
			$summary['gross_sales'] += (float) $order->get_total();

			$email = strtolower( sanitize_email( $order->get_billing_email() ) );
			if ( $email ) {
				if ( ! isset( $customer_map[ $email ] ) ) {
					$customer_map[ $email ] = 0;
				}

				$customer_map[ $email ]++;
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();
				$key     = $product instanceof WC_Product ? $product->get_id() : $item->get_name();
				$product_id = $item->get_product_id();
				$category_context = self::get_product_category_context( $product_id, $category_cache );
				$quantity = (float) $item->get_quantity();
				$line_total = (float) $item->get_total();
				$product_map[ $key ] = true;
				$summary['items_sold'] += $quantity;
				self::record_category_mix( $category_map, $category_context, $key, $quantity, $line_total );
			}
		}

		$summary['average_order_value'] = $summary['orders'] > 0 ? $summary['gross_sales'] / $summary['orders'] : 0.0;
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
		$summary['items_per_order'] = $summary['orders'] > 0 ? $summary['items_sold'] / $summary['orders'] : 0.0;

		return $summary;
	}

	private static function build_store_signal_comparison( $current_metrics, $previous_metrics, $previous_window ) {
		$keys = [ 'orders', 'gross_sales', 'average_order_value', 'unique_customers', 'repeat_customers', 'repeat_customer_rate', 'items_sold', 'items_per_order', 'product_lines', 'product_categories' ];
		$comparison = [
			'window'  => YoRo_Date_Helper::build_window_payload( is_array( $previous_window ) ? $previous_window : [] ),
			'metrics' => [],
		];

		foreach ( $keys as $key ) {
			$current = (float) ( $current_metrics[ $key ] ?? 0 );
			$previous = (float) ( $previous_metrics[ $key ] ?? 0 );
			$comparison['metrics'][ $key ] = YoRo_Report_Builder::metric_comparison( $current, $previous );
			$comparison['metrics'][ $key ]['previous'] = wc_format_decimal( $previous, in_array( $key, [ 'gross_sales', 'average_order_value', 'items_per_order' ], true ) ? 2 : ( 'repeat_customer_rate' === $key ? 1 : 0 ) );
		}

		return $comparison;
	}

	private static function report_comparison_for_metric( $comparison, $key ) {
		return is_array( $comparison ) && ! empty( $comparison['metrics'][ $key ] ) && is_array( $comparison['metrics'][ $key ] )
			? $comparison['metrics'][ $key ]
			: [];
	}

	private static function build_store_insights_report( $window, $order_count, $order_total, $average_order_value, $customer_count, $repeat_customer_count, $repeat_customer_rate, $product_line_count, $product_category_count, $items_sold, $items_per_order, $inventory_counts, $top_customers, $top_products, $top_categories, $comparison = [] ) {
		$window_label         = $window['label'] ?? __( 'selected window', 'yoro-ai-core' );
		$currency             = get_woocommerce_currency();
		$inventory_risk_count = absint( $inventory_counts['out_of_stock'] ?? 0 )
			+ absint( $inventory_counts['low_stock'] ?? 0 )
			+ absint( $inventory_counts['on_backorder'] ?? 0 );
		$health               = $inventory_risk_count > 0 ? 'watch' : ( $order_count > 0 ? 'good' : 'watch' );
		$headline             = sprintf(
			/* translators: Placeholder values are generated from store data for this message. */
			__( 'Store signals for %1$s: %2$d orders, %3$d customers, %4$d product lines.', 'yoro-ai-core' ),
			$window_label,
			$order_count,
			$customer_count,
			$product_line_count
		);
		$order_comparison = self::report_comparison_for_metric( $comparison, 'orders' );
		$gross_comparison = self::report_comparison_for_metric( $comparison, 'gross_sales' );
		$aov_comparison = self::report_comparison_for_metric( $comparison, 'average_order_value' );
		$customer_comparison = self::report_comparison_for_metric( $comparison, 'unique_customers' );
		$repeat_customer_comparison = self::report_comparison_for_metric( $comparison, 'repeat_customers' );
		$repeat_customer_rate_comparison = self::report_comparison_for_metric( $comparison, 'repeat_customer_rate' );
		$item_comparison = self::report_comparison_for_metric( $comparison, 'items_sold' );
		$items_per_order_comparison = self::report_comparison_for_metric( $comparison, 'items_per_order' );
		$product_line_comparison = self::report_comparison_for_metric( $comparison, 'product_lines' );
		$product_category_comparison = self::report_comparison_for_metric( $comparison, 'product_categories' );
		$profile_context = YoRo_Report_Builder::store_profile_context( [ 'reporting', 'sales', 'inventory', 'customers' ] );
		$profile_insights = array_merge(
			YoRo_Report_Builder::business_profile_insights(
				$profile_context,
				[
					'average_order_value' => $average_order_value,
				],
				$window
			),
			YoRo_Report_Builder::inventory_profile_insights( $profile_context )
		);

		$metrics = [
			YoRo_Report_Builder::metric( 'orders', __( 'Orders', 'yoro-ai-core' ), $order_count, 'orders', 'number', 'neutral', $order_comparison['trend'] ?? '', $order_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'gross_sales', __( 'Gross sales', 'yoro-ai-core' ), wc_format_decimal( $order_total, 2 ), $currency, 'currency', $order_total > 0 ? 'positive' : 'neutral', $gross_comparison['trend'] ?? '', $gross_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'average_order_value', __( 'Average order value', 'yoro-ai-core' ), wc_format_decimal( $average_order_value, 2 ), $currency, 'currency', 'neutral', $aov_comparison['trend'] ?? '', $aov_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'unique_customers', __( 'Customers', 'yoro-ai-core' ), $customer_count, 'customers', 'number', 'neutral', $customer_comparison['trend'] ?? '', $customer_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'repeat_customers', __( 'Repeat customers', 'yoro-ai-core' ), $repeat_customer_count, 'customers', 'number', 'neutral', $repeat_customer_comparison['trend'] ?? '', $repeat_customer_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'repeat_customer_rate', __( 'Repeat customer rate', 'yoro-ai-core' ), wc_format_decimal( $repeat_customer_rate, 1 ), '%', 'percent', $repeat_customer_count > 0 ? 'positive' : 'neutral', $repeat_customer_rate_comparison['trend'] ?? '', $repeat_customer_rate_comparison['delta'] ?? null, 'store', 'customers', __( 'Share of active customers with more than one order in this window.', 'yoro-ai-core' ), 35 ),
			YoRo_Report_Builder::metric( 'items_sold', __( 'Items sold', 'yoro-ai-core' ), wc_format_decimal( $items_sold, 0 ), 'items', 'number', 'neutral', $item_comparison['trend'] ?? '', $item_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'items_per_order', __( 'Items per order', 'yoro-ai-core' ), wc_format_decimal( $items_per_order, 2 ), 'items', 'number', 'neutral', $items_per_order_comparison['trend'] ?? '', $items_per_order_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'product_lines', __( 'Product lines', 'yoro-ai-core' ), $product_line_count, 'products', 'number', 'neutral', $product_line_comparison['trend'] ?? '', $product_line_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'product_categories', __( 'Categories sold', 'yoro-ai-core' ), $product_category_count, 'categories', 'number', 'neutral', $product_category_comparison['trend'] ?? '', $product_category_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'inventory_risk', __( 'Inventory risk', 'yoro-ai-core' ), $inventory_risk_count, 'items', 'number', $inventory_risk_count > 0 ? 'watch' : 'positive' ),
		];

		$insights = [];

		if ( $order_count > 0 ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Demand snapshot', 'yoro-ai-core' ),
				/* translators: Placeholder values are generated from store data for this message. */
				sprintf( __( 'The selected window contains %1$d orders with %2$s %3$s in gross sales.', 'yoro-ai-core' ), $order_count, wc_format_decimal( $order_total, 2 ), $currency ),
				'orders',
				'positive'
			);
		} else {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'No order activity', 'yoro-ai-core' ),
				__( 'No orders were found in the selected window, so customer and product signals may be sparse.', 'yoro-ai-core' ),
				'orders',
				'watch'
			);
		}

		if ( $repeat_customer_count > 0 ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Repeat buyer signal', 'yoro-ai-core' ),
				sprintf(
					/* translators: Placeholder values are generated from store data for this message. */
					__( '%1$d customers ordered more than once, giving this window a %2$s%% repeat customer rate.', 'yoro-ai-core' ),
					$repeat_customer_count,
					wc_format_decimal( $repeat_customer_rate, 1 )
				),
				'customers',
				$repeat_customer_rate >= 25 ? 'positive' : 'neutral'
			);
		}

		if ( $items_per_order >= 2 ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Basket size signal', 'yoro-ai-core' ),
				/* translators: %s: Value generated from store data. */
				sprintf( __( 'Customers are buying %s items per order on average in this window.', 'yoro-ai-core' ), wc_format_decimal( $items_per_order, 2 ) ),
				'orders',
				'positive'
			);
		}

		$top_customer = is_array( $top_customers ) && ! empty( $top_customers ) ? $top_customers[0] : null;
		if ( is_array( $top_customer ) ) {
			$customer_label = trim( (string) ( $top_customer['name'] ?? '' ) );
			if ( '' === $customer_label ) {
				$customer_label = (string) ( $top_customer['email'] ?? __( 'Top customer', 'yoro-ai-core' ) );
			}
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Customer concentration', 'yoro-ai-core' ),
				/* translators: Placeholder values are generated from store data for this message. */
				sprintf( __( '%1$s is the highest-value customer in this window with %2$s %3$s tracked.', 'yoro-ai-core' ), $customer_label, $top_customer['total_spent'] ?? '0.00', $currency ),
				'customers',
				'neutral'
			);
		}

		$top_product = is_array( $top_products ) && ! empty( $top_products ) ? $top_products[0] : null;
		if ( is_array( $top_product ) ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Top product signal', 'yoro-ai-core' ),
				/* translators: Placeholder values are generated from store data for this message. */
				sprintf( __( '%1$s is the strongest product signal with %2$s units sold.', 'yoro-ai-core' ), $top_product['name'] ?? __( 'Top product', 'yoro-ai-core' ), $top_product['quantity'] ?? 0 ),
				'products',
				'neutral'
			);
		}

		$top_category = is_array( $top_categories ) && ! empty( $top_categories ) ? $top_categories[0] : null;
		if ( is_array( $top_category ) ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Top category signal', 'yoro-ai-core' ),
				/* translators: Placeholder values are generated from store data for this message. */
				sprintf( __( '%1$s is the strongest category signal with %2$s units sold across %3$d product lines.', 'yoro-ai-core' ), $top_category['name'] ?? __( 'Top category', 'yoro-ai-core' ), $top_category['quantity'] ?? 0, absint( $top_category['product_lines'] ?? 0 ) ),
				'products',
				'neutral',
				'products',
				46
			);
		}

		$insights = array_merge( $insights, $profile_insights );

		$alerts = [];

		if ( $inventory_risk_count > 0 ) {
			$alerts[] = YoRo_Report_Builder::alert(
				__( 'Inventory needs review', 'yoro-ai-core' ),
				/* translators: %s: Value generated from store data. */
				sprintf( __( '%d products are out of stock, low stock, or on backorder.', 'yoro-ai-core' ), $inventory_risk_count ),
				'watch',
				'inventory',
				'inventory',
				20
			);
		}

		$window_command = $window['label'] ?? 'this week';
		$actions        = [
			YoRo_Report_Builder::action( __( 'Sales report', 'yoro-ai-core' ), 'Show sales report for ' . $window_command, 'view', 'sales', 30, __( 'Open the revenue and sales readout for the same window.', 'yoro-ai-core' ) ),
			YoRo_Report_Builder::action( __( 'Operations report', 'yoro-ai-core' ), 'Show operations report for ' . $window_command, 'view', 'operations', 40, __( 'Check whether the signal is creating order-work pressure.', 'yoro-ai-core' ) ),
			YoRo_Report_Builder::action( __( 'Inventory queue', 'yoro-ai-core' ), 'Show inventory queue', 'view', 'inventory', 50, __( 'Review stock pressure behind the product signals.', 'yoro-ai-core' ) ),
		];

		$breakdowns = YoRo_Store_Briefing_Service::report_build_product_mix_breakdowns( $top_products, $top_categories, $currency, (float) $order_total );
		$report     = YoRo_Report_Builder::build( 'store_insights', $window, $health, $headline, $metrics, $insights, $alerts, $actions, $actions, $breakdowns );
		$report     = YoRo_Report_Builder::attach_profile_context( $report, $profile_context );

		if ( ! empty( $comparison ) ) {
			$report['comparison'] = $comparison;
		}

		return $report;
	}

	public static function get_business_summary( $params ) {
		return YoRo_Store_Briefing_Service::get_business_summary( $params );
	}

	public static function get_daily_briefing( $params ) {
		return YoRo_Store_Briefing_Service::get_daily_briefing( $params );
	}

	public static function refund_preview( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;

		if ( ! $order_id ) {
				return new WP_Error(
					'invalid_params',
					__( 'Please choose an order first.', 'yoro-ai-core' )
				);
			}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
				return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
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
				'status_label'   => YoRo_Order_Helper::get_order_status_label( $order->get_status() ),
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
		$customer_id   = ! empty( $params['customer_id'] ) ? absint( $params['customer_id'] ) : 0;
		$customer_name = ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '';
		$order_id      = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$limit         = ! empty( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 50 ) : 20;

		if ( $customer_id ) {
			$customer = get_userdata( $customer_id );

			if ( $customer instanceof WP_User ) {
				if ( empty( $email ) ) {
					$email = sanitize_email( $customer->user_email );
				}

				if ( empty( $customer_name ) ) {
					$customer_name = sanitize_text_field( $customer->display_name );
				}
			}
		}

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
			return new WP_Error( 'invalid_params', __( 'Please provide a customer email, phone number, ID, or name first.', 'yoro-ai-core' ) );
		}

		$orders           = self::get_customer_orders( $email, $phone, $limit, $customer_name, $customer_id );
		$total_spent      = 0;
		$status_counts    = [];
		$payment_methods  = [];
		$first_order_timestamp = 0;
		$last_order_timestamp  = 0;
		$resolved_customer_name = '';
		$latest_order_id  = 0;
		$latest_order_no  = '';
		$data             = [];
		$recent_notes     = [];

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

			if ( empty( $email ) ) {
				$email = sanitize_email( $order->get_billing_email() );
			}

			if ( empty( $phone ) ) {
				$phone = sanitize_text_field( $order->get_billing_phone() );
			}

			if ( 0 === $customer_id ) {
				$customer_id = (int) $order->get_customer_id();
			}

			if ( 0 === $latest_order_id ) {
				$latest_order_id = $order->get_id();
				$latest_order_no = $order->get_order_number();
			}

			$data[] = YoRo_Order_Presenter::format_order_summary( $order );
			$recent_notes = array_merge( $recent_notes, self::customer_order_note_context( $order ) );
		}

		$masked_email = YoRo_Order_Presenter::mask_email( $email );
		$masked_phone = YoRo_Order_Presenter::mask_phone( $phone );
		$resolved_name = '' !== $resolved_customer_name ? $resolved_customer_name : $customer_name;
		$currency = get_woocommerce_currency();
		$total_spent_decimal = wc_format_decimal( $total_spent, 2 );
		$first_order_date = $first_order_timestamp > 0 ? YoRo_Date_Helper::format_timestamp( $first_order_timestamp ) : '';
		$last_order_date = $last_order_timestamp > 0 ? YoRo_Date_Helper::format_timestamp( $last_order_timestamp ) : '';

		$summary = '' !== $resolved_name
			? sprintf(
				'Found customer profile for %1$s with %2$d related orders.',
				$resolved_name,
				count( $data )
			)
			: sprintf(
				'Found this customer profile with %d related orders.',
				count( $data )
			);

		return [
			'success'          => true,
			'action'           => 'customers.lookup',
			'summary'          => $summary,
			'count'            => count( $data ),
			'customer'         => [
				'id'             => $customer_id,
				'edit_url'       => $customer_id > 0 && current_user_can( 'edit_user', $customer_id )
					? esc_url_raw( admin_url( 'user-edit.php?user_id=' . $customer_id ) )
					: '',
				'email'          => $masked_email,
				'phone'          => $masked_phone,
				'name'           => $resolved_name,
				'status'         => count( $data ) > 0 ? 'active' : 'unknown',
				'orders_count'   => count( $data ),
				'lifetime_value' => $total_spent_decimal,
				'currency'       => $currency,
				'first_order_at' => $first_order_date,
				'last_order_at'  => $last_order_date,
				'segments'       => count( $data ) > 1 ? [ 'repeat_customer' ] : [],
				'risk_flags'     => [],
				'meta'           => [
					'status_counts'   => $status_counts,
					'payment_methods' => $payment_methods,
				],
			],
			'related'          => [
				'recent_orders' => $data,
				'open_orders'   => [],
				'recent_notes'  => array_slice( $recent_notes, 0, 10 ),
				'draft_emails'  => [],
			],
			'customer_name'    => $resolved_name,
			'customer_id'      => $customer_id,
			'read_only'        => true,
			'email'            => $masked_email,
			'phone'            => $masked_phone,
			'total_spent'      => $total_spent_decimal,
			'currency'         => $currency,
			'status_counts'    => $status_counts,
			'status_count_labels' => self::order_status_count_labels( $status_counts ),
			'payment_methods'  => $payment_methods,
			'first_order_date' => $first_order_date,
			'first_order_date_ts' => $first_order_timestamp,
			'last_order_date'  => $last_order_date,
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

	private static function customer_order_note_context( WC_Order $order ) {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return [];
		}

		$context = [];
		foreach ( wc_get_order_notes( [
			'order_id' => $order->get_id(),
			'limit'    => 2,
			'orderby'  => 'date_created',
			'order'    => 'DESC',
		] ) as $note ) {
			if ( ! is_object( $note ) ) {
				continue;
			}

			$date_created = ! empty( $note->date_created ) && is_object( $note->date_created )
				? $note->date_created
				: null;
			$context[] = [
				'order_id' => $order->get_id(),
				'date'     => $date_created ? YoRo_Date_Helper::format_wc_datetime( $date_created ) : '',
				'customer' => ! empty( $note->customer_note ),
			];
		}

		return $context;
	}

	public static function get_customer_service_queue( $params ) {
		return YoRo_Store_Customer_Service::get_customer_service_queue( $params );
	}

	public static function find_orders_by_customer( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
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
					__( 'Please provide a customer email, phone number, ID, or name first.', 'yoro-ai-core' )
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
				return new WP_Error( 'invalid_params', __( 'Please choose an order first.', 'yoro-ai-core' ) );
			}

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
			}

		$identity = self::build_order_identity( $order );

		// phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Excludes the seed order while finding related customer orders.
		$candidates = wc_get_orders(
			[
				'limit'   => $limit + 20,
				'orderby' => 'date',
				'order'   => 'DESC',
				'exclude' => [ $order_id ],
				'status'  => self::get_all_order_status_slugs(),
			]
		);
		// phpcs:enable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude

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
			'action'          => 'Search store orders',
			'status'          => $status,
			'limit'           => min( max( $limit, 1 ), 50 ),
			'order_id'        => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'email'           => ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : '',
			'phone'           => ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '',
			'payment_method'  => ! empty( $params['payment_method'] ) ? sanitize_key( $params['payment_method'] ) : '',
			'date_window'     => YoRo_Date_Helper::window_key_from_params( $params, 'date_window', '' ),
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
		$order_ids = YoRo_Order_Helper::normalize_order_ids( $params );
		$orders = [];
		$missing_order_ids = [];
		$block_reason = '';
		$message = __( 'This order detail lookup is ready.', 'yoro-ai-core' );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$missing_order_ids[] = $order_id;
			}

			$orders[] = [
				'id'     => $order_id,
				'exists' => (bool) $order,
			];
		}

		if ( empty( $order_ids ) ) {
			$block_reason = 'missing_order_target';
			$message = __( 'Please choose an order before this can continue.', 'yoro-ai-core' );
		} elseif ( ! empty( $missing_order_ids ) ) {
			$block_reason = 'order_not_found';
			$message = sprintf(
				/* translators: %s: comma-separated order IDs */
				__( 'I could not find order %s. Choose an existing order before continuing.', 'yoro-ai-core' ),
				implode( ', ', $missing_order_ids )
			);
		}

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get store order details',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'order_ids'     => $order_ids,
			'orders'        => $orders,
			'count'         => count( $order_ids ),
			'changes_store' => false,
		];
	}

	public static function preview_get_operations_summary( $params ) {
		$window_key = self::normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'today' ) );
		$focus      = ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview';
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get operations report',
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
		$window_key = self::normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_7_days' ) );
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get store signal report',
			'window'        => $window['label'],
			'limit'         => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'changes_store' => false,
		];
	}

	public static function preview_get_business_summary( $params ) {
		$window_key = self::normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_7_days' ) );
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get sales report',
			'window'        => $window['label'],
			'focus'         => ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview',
			'changes_store' => false,
		];
	}

	public static function preview_get_daily_briefing( $params ) {
		$window_key = self::normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'today' ) );
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get store report',
			'window'        => $window['label'],
			'limit'         => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 10 ) : 5,
			'changes_store' => false,
		];
	}

	public static function preview_refund_preview( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$block_reason = '';
		$message = __( 'This refund preview lookup is ready.', 'yoro-ai-core' );

		if ( $order_id <= 0 ) {
			$block_reason = 'missing_order_target';
			$message = __( 'Please choose an order before this can continue.', 'yoro-ai-core' );
		} elseif ( ! $order ) {
			$block_reason = 'order_not_found';
			$message = sprintf(
				/* translators: %d: order ID */
				__( 'I could not find order %d. Choose an existing order before continuing.', 'yoro-ai-core' ),
				$order_id
			);
		}

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Preview store refund context',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'changes_store' => false,
		];
	}

	public static function preview_get_customer_summary( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Look up customer',
			'email'         => ! empty( $params['email'] ) ? YoRo_Order_Presenter::mask_email( $params['email'] ) : '',
			'phone'         => ! empty( $params['phone'] ) ? YoRo_Order_Presenter::mask_phone( $params['phone'] ) : '',
			'customer_id'   => ! empty( $params['customer_id'] ) ? absint( $params['customer_id'] ) : 0,
			'customer_name' => ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '',
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
			'action'        => 'Find store orders by customer',
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
			'action'        => 'Find store orders by shared identity',
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

	public static function briefing_get_revenue_order_status_slugs() {
		return self::get_revenue_order_status_slugs();
	}

	public static function briefing_is_revenue_order_status( $status ) {
		return self::is_revenue_order_status( $status );
	}

	public static function briefing_count_orders( $args ) {
		return self::count_orders( $args );
	}

	public static function briefing_build_relative_cutoff( $modifier ) {
		return self::build_relative_cutoff( $modifier );
	}

	public static function report_query_orders( $args, $max_orders = 10000, $page_size = 250 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$args       = is_array( $args ) ? $args : [];
		$max_orders = min( max( absint( $max_orders ), 1 ), 20000 );
		$page_size  = min( max( absint( $page_size ), 1 ), 500 );
		$orders     = [];
		$page       = 1;

		unset( $args['offset'], $args['page'], $args['paged'], $args['paginate'] );

		while ( count( $orders ) < $max_orders ) {
			$query_args = $args;
			$query_args['limit']    = min( $page_size, $max_orders - count( $orders ) );
			$query_args['page']     = $page;
			$query_args['paginate'] = true;

			if ( $query_args['limit'] <= 0 ) {
				break;
			}

			$result      = wc_get_orders( $query_args );
			$page_orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : $result;
			$page_orders = is_array( $page_orders ) ? $page_orders : [];
			$page_count  = count( $page_orders );
			$max_pages   = is_object( $result ) && isset( $result->max_num_pages ) ? absint( $result->max_num_pages ) : 0;

			if ( 0 === $page_count ) {
				break;
			}

			foreach ( $page_orders as $order ) {
				if ( $order instanceof WC_Order ) {
					$orders[] = $order;
				}
			}

			if ( $max_pages > 0 && $page >= $max_pages ) {
				break;
			}

			if ( 0 === $max_pages && $page_count < $query_args['limit'] ) {
				break;
			}

			$page++;
		}

		return $orders;
	}

	public static function operations_build_attention_queue( $key, $label, $description, $query_args, $limit ) {
		return self::build_attention_queue( $key, $label, $description, $query_args, $limit );
	}

	/** Created-age evidence for the bounded operational queues, never status duration. */
	public static function queue_order_age_hours( WC_Order $order, $observed_at = null ) {
		try {
			$created = $order->get_date_created();
			$timestamp = $created instanceof DateTimeInterface ? $created->getTimestamp() : null;
			$now = null === $observed_at ? current_datetime()->getTimestamp() : $observed_at;
		} catch ( Throwable $error ) {
			return self::queue_age_error();
		}
		if ( ! is_int( $timestamp ) || $timestamp <= 0 || $timestamp > $now ) {
			return self::queue_age_error();
		}
		return (int) floor( ( $now - $timestamp ) / HOUR_IN_SECONDS );
	}

	public static function queue_age_error() {
		return new WP_Error( 'yoro_queue_age_unavailable', __( 'The queue could not be read because a required order creation time is unavailable or invalid.', 'yoro-ai-core' ) );
	}

	/** Query the existing bounded slice without treating a query failure as an empty store. */
	public static function queue_query_orders( $args ) {
		try {
			$result = wc_get_orders( $args );
		} catch ( Throwable $error ) {
			return new WP_Error( 'yoro_queue_query_failed', __( 'The queue data could not be read.', 'yoro-ai-core' ) );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'yoro_queue_query_failed', __( 'The queue data could not be read.', 'yoro-ai-core' ) );
		}
		if ( ! empty( $args['paginate'] ) ) {
			return is_object( $result ) && isset( $result->total ) && is_numeric( $result->total ) && $result->total >= 0
				? $result : new WP_Error( 'yoro_queue_query_failed', __( 'The queue data could not be read.', 'yoro-ai-core' ) );
		}
		if ( ! is_array( $result ) || count( array_filter( $result, static function( $row ) { return $row instanceof WC_Order; } ) ) !== count( $result ) ) {
			return new WP_Error( 'yoro_queue_query_failed', __( 'The queue data could not be read.', 'yoro-ai-core' ) );
		}
		return $result;
	}

	public static function queue_count_orders( $args ) {
		$result = self::queue_query_orders( wp_parse_args( $args, [ 'limit' => 1, 'paginate' => true, 'return' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ] ) );
		return is_wp_error( $result ) ? $result : (int) $result->total;
	}

	/** Internal observation, moved to wordpress.execution.meta before returning the queue. */
	private static function queue_observation( $count, $visible, $limit, $sample_size = null ) {
		return [
			'captured_at' => gmdate( 'c' ),
			'age_basis' => 'date_created',
			'status_duration' => 'unknown',
			'count_basis' => null === $sample_size ? 'query_matches' : 'sample_matches',
			'match_count' => $count,
			'visible_memberships' => $visible,
			'limit' => $limit,
			'sample_size' => $sample_size,
			'candidate_limit' => null === $sample_size ? null : 50,
			'complete' => null === $sample_size || $sample_size < 50,
			'visible_truncated' => $count > $visible,
		];
	}

	public static function queue_evidence( &$queues, $orders ) {
		$evidence = [ 'version' => 1, 'captured_at' => gmdate( 'c' ), 'visible_memberships' => array_sum( array_map( static function( $queue ) { return count( $queue['orders'] ); }, $queues ) ), 'age_basis' => 'date_created', 'status_duration' => 'unknown', 'count_basis' => 'queue_memberships', 'unique_count_scope' => 'visible_sample', 'visible_unique_orders' => count( $orders ), 'queues' => [] ];
		foreach ( $queues as &$queue ) {
			$evidence['queues'][ $queue['key'] ] = $queue['_evidence'];
			unset( $queue['_evidence'] );
		}
		unset( $queue );
		return $evidence;
	}

	/** One order identity per result; keep the strongest existing score and all observed reasons. */
	public static function queue_case_rankings( $rankings ) {
		usort( $rankings, static function( $a, $b ) {
			return ( (float) $b['score'] <=> (float) $a['score'] )
				?: ( (int) $a['order']['id'] <=> (int) $b['order']['id'] )
				?: strcmp( $a['topic'], $b['topic'] );
		} );
		$cases = [];
		foreach ( $rankings as $case ) {
			$id = (int) $case['order']['id'];
			if ( ! isset( $cases[ $id ] ) ) {
				$case['order']['case_priority_score'] = $case['score'];
				$case['order']['queue_topic'] = $case['topic'];
				$case['order']['extensions']['woocommerce.queue_evidence']['reasons'] = [];
				$cases[ $id ] = $case;
			}
			$cases[ $id ]['order']['extensions']['woocommerce.queue_evidence']['reasons'][ $case['topic'] ] ??= [ 'topic' => $case['topic'], 'label' => $case['queue_label'], 'description' => $case['queue_description'], 'score' => $case['score'] ];
		}
		foreach ( $cases as &$case ) {
			ksort( $case['order']['extensions']['woocommerce.queue_evidence']['reasons'] );
			$case['order']['extensions']['woocommerce.queue_evidence']['reasons'] = array_values( $case['order']['extensions']['woocommerce.queue_evidence']['reasons'] );
		}
		unset( $case );
		return array_values( $cases );
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

	public static function report_get_product_category_context( $product_id, &$category_cache = [] ) {
		return self::get_product_category_context( $product_id, $category_cache );
	}

	public static function report_record_category_mix( &$category_map, $category_context, $product_key, $quantity, $total ) {
		self::record_category_mix( $category_map, $category_context, $product_key, $quantity, $total );
	}

	public static function report_format_category_mix( $category_map, $limit = 5 ) {
		return self::format_category_mix( $category_map, $limit );
	}

	private static function get_product_category_context( $product_id, &$category_cache = [] ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'product_cat' ) ) {
			return self::default_product_category_context();
		}

		if ( isset( $category_cache[ $product_id ] ) ) {
			return $category_cache[ $product_id ];
		}

		$terms = wp_get_post_terms(
			$product_id,
			'product_cat',
			[
				'orderby' => 'name',
				'order'   => 'ASC',
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			$category_cache[ $product_id ] = self::default_product_category_context();
			return $category_cache[ $product_id ];
		}

		$categories = [];

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$categories[] = [
				'id'   => absint( $term->term_id ),
				'name' => sanitize_text_field( $term->name ),
				'slug' => sanitize_key( $term->slug ),
			];
		}

		if ( empty( $categories ) ) {
			$category_cache[ $product_id ] = self::default_product_category_context();
			return $category_cache[ $product_id ];
		}

		$primary = $categories[0];

		$category_cache[ $product_id ] = [
			'primary_id'   => absint( $primary['id'] ?? 0 ),
			'primary_name' => sanitize_text_field( $primary['name'] ?? __( 'Uncategorized', 'yoro-ai-core' ) ),
			'categories'   => $categories,
		];

		return $category_cache[ $product_id ];
	}

	private static function default_product_category_context() {
		return [
			'primary_id'   => 0,
			'primary_name' => __( 'Uncategorized', 'yoro-ai-core' ),
			'categories'   => [],
		];
	}

	private static function record_category_mix( &$category_map, $category_context, $product_key, $quantity, $total ) {
		$category_context = is_array( $category_context ) ? $category_context : self::default_product_category_context();
		$category_id      = absint( $category_context['primary_id'] ?? 0 );
		$category_name    = sanitize_text_field( $category_context['primary_name'] ?? __( 'Uncategorized', 'yoro-ai-core' ) );
		$category_key     = $category_id > 0 ? 'category:' . $category_id : 'category:uncategorized';

		if ( ! isset( $category_map[ $category_key ] ) ) {
			$category_map[ $category_key ] = [
				'id'           => $category_id,
				'name'         => $category_name,
				'quantity'     => 0.0,
				'total'        => 0.0,
				'product_keys' => [],
			];
		}

		$category_map[ $category_key ]['quantity'] += (float) $quantity;
		$category_map[ $category_key ]['total']    += (float) $total;

		$product_key = sanitize_text_field( $product_key );

		if ( '' !== $product_key ) {
			$category_map[ $category_key ]['product_keys'][ $product_key ] = true;
		}
	}

	private static function format_category_mix( $category_map, $limit = 5 ) {
		$categories = array_values( is_array( $category_map ) ? $category_map : [] );

		usort(
			$categories,
			function( $a, $b ) {
				return ( $b['total'] ?? 0 ) <=> ( $a['total'] ?? 0 );
			}
		);

		$categories = array_slice( $categories, 0, min( max( absint( $limit ), 1 ), 20 ) );

		return array_map(
			function( $category ) {
				return [
					'id'            => absint( $category['id'] ?? 0 ),
					'name'          => sanitize_text_field( $category['name'] ?? __( 'Uncategorized', 'yoro-ai-core' ) ),
					'quantity'      => wc_format_decimal( $category['quantity'] ?? 0, 0 ),
					'total'         => wc_format_decimal( $category['total'] ?? 0, 2 ),
					'product_lines' => count( is_array( $category['product_keys'] ?? null ) ? $category['product_keys'] : [] ),
				];
			},
			$categories
		);
	}

	private static function get_customer_orders( $email = '', $phone = '', $limit = 20, $customer_name = '', $customer_id = 0 ) {
		$args = [
			'limit'   => min( max( absint( $limit ), 1 ), 50 ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => self::get_all_order_status_slugs(),
		];

		if ( $email ) {
			$args['billing_email'] = sanitize_email( $email );
		}

		if ( $customer_id ) {
			$args['customer_id'] = absint( $customer_id );
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
		$count  = self::queue_count_orders( $query_args );
		$orders = self::query_attention_orders( $query_args, $limit );

		if ( is_wp_error( $count ) || is_wp_error( $orders ) ) {
			return is_wp_error( $count ) ? $count : $orders;
		}

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'orders'      => $orders,
			'_evidence'   => self::queue_observation( $count, count( $orders ), $limit ),
		];
	}

	private static function build_customer_service_queue( $key, $label, $description, $query_args, $limit, $reason ) {
		$count  = self::queue_count_orders( $query_args );
		$orders = self::query_customer_service_orders( $query_args, $limit, $reason );

		if ( is_wp_error( $count ) || is_wp_error( $orders ) ) {
			return is_wp_error( $count ) ? $count : $orders;
		}

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'orders'      => $orders,
			'_evidence'   => self::queue_observation( $count, count( $orders ), $limit ),
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
		return self::queue_query_orders(
			[
				'limit'   => min( max( absint( $limit ), 1 ), 200 ),
				'status'  => [ 'processing' ],
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);
	}

	private static function build_fulfillment_candidate_queue( $key, $label, $description, $candidates, $limit, $filter, $reason ) {
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		$candidates = is_array( $candidates ) ? $candidates : [];
		$orders     = [];
		$count      = 0;

		foreach ( $candidates as $order ) {
			if ( ! $order instanceof WC_Order || ! is_callable( $filter ) || ! call_user_func( $filter, $order ) ) {
				continue;
			}

			$count++;

			if ( count( $orders ) < $limit ) {
				$item = self::format_fulfillment_order( $order, $reason );
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				$orders[] = $item;
			}
		}

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'orders'      => $orders,
			'_evidence'   => self::queue_observation( $count, count( $orders ), $limit, count( $candidates ) ),
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
				'label' => YoRo_Order_Helper::get_order_status_label( $key ),
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

	private static function order_status_count_labels( $counts ) {
		$labels = [];

		foreach ( is_array( $counts ) ? $counts : [] as $status => $count ) {
			$labels[ $status ] = YoRo_Order_Helper::get_order_status_label( $status );
		}

		return $labels;
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
		$candidates = self::queue_query_orders(
			[
				'limit'   => 50,
				'status'  => self::get_all_order_status_slugs(),
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);

		$orders = [];
		$count  = 0;

		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

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
				if ( is_wp_error( $item ) ) {
					return $item;
				}
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
			'_evidence'   => self::queue_observation( $count, count( $orders ), $limit, count( $candidates ) ),
		];
	}

	private static function build_high_value_open_queue( $limit, $threshold ) {
		$candidates = self::queue_query_orders(
			[
				'limit'   => 50,
				'status'  => [ 'pending', 'processing', 'on-hold' ],
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);

		$orders = [];
		$count  = 0;

		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		foreach ( $candidates as $order ) {
			if ( ! $order instanceof WC_Order || (float) $order->get_total() < $threshold ) {
				continue;
			}

			$count++;

			if ( count( $orders ) < $limit ) {
				$item = self::format_attention_order( $order );
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				$orders[] = $item;
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
			'_evidence'   => self::queue_observation( $count, count( $orders ), $limit, count( $candidates ) ),
		];
	}

	private static function query_attention_orders( $query_args, $limit ) {
		$orders = self::queue_query_orders(
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

		if ( is_wp_error( $orders ) ) {
			return $orders;
		}

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$item = self::format_attention_order( $order );
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				$data[] = $item;
			}
		}

		return $data;
	}

	private static function format_inventory_product( WC_Product $product ) {
		$stock_quantity = $product->get_stock_quantity();
		$low_threshold  = self::get_product_low_stock_threshold( $product );
		$product_id     = $product->get_id();
		$edit_url       = get_edit_post_link( $product_id, 'raw' );

		if ( ! $edit_url ) {
			$edit_url = admin_url( 'post.php?post=' . absint( $product_id ) . '&action=edit' );
		}

		$inventory_target_meta = class_exists( 'YoRo_Store_Inventory_Service' )
			? YoRo_Store_Inventory_Service::product_inventory_target_meta( $product )
			: [];

		return array_merge(
			[
			'id'                  => $product_id,
			'name'                => $product->get_name(),
			'sku'                 => $product->get_sku(),
			'type'                => $product->get_type(),
			'type_label'          => YoRo_Product_Helper::get_product_type_label( $product->get_type() ),
			'status'              => $product->get_status(),
			'status_label'        => YoRo_Product_Helper::get_product_status_label( $product->get_status() ),
			'stock_status'        => $product->get_stock_status(),
			'stock_status_label'  => YoRo_Product_Helper::get_stock_status_label( $product->get_stock_status() ),
			'manage_stock'        => $product->managing_stock(),
			'stock_quantity'      => null === $stock_quantity ? '' : $stock_quantity,
			'low_stock_threshold' => $low_threshold,
			'backorders_allowed'  => $product->backorders_allowed(),
			'price'               => $product->get_price(),
			'edit_url'            => $edit_url,
			],
			$inventory_target_meta
		);
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
		$orders = self::queue_query_orders(
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

		if ( is_wp_error( $orders ) ) {
			return $orders;
		}

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$item = self::format_customer_service_order( $order, $reason );
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				$data[] = $item;
			}
		}

		return $data;
	}

	private static function format_attention_order( WC_Order $order ) {
		$captured_at = time();
		$age = self::queue_order_age_hours( $order, $captured_at );
		if ( is_wp_error( $age ) ) {
			return $age;
		}
		$data = YoRo_Order_Presenter::format_order_summary( $order );

		$data['age_hours'] = $age;
		$data['extensions']['woocommerce.queue_evidence'] = [ 'age_basis' => 'date_created', 'status_duration' => 'unknown', 'captured_at' => gmdate( 'c', $captured_at ) ];

		return $data;
	}

	private static function format_customer_service_order( WC_Order $order, $reason ) {
		$data = self::format_attention_order( $order );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['customer_name']      = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$data['followup_reason']    = sanitize_text_field( $reason );
		$data['suggested_command']  = 'Show order ' . $order->get_id() . ' details';
		$data['customer_id']        = $order->get_customer_id();

		return $data;
	}

	private static function format_fulfillment_order( WC_Order $order, $reason ) {
		$data = self::format_attention_order( $order );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['shipping_name']      = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$data['shipping_city']      = $order->get_shipping_city();
		$data['shipping_country']   = $order->get_shipping_country();
		$data['shipping_method']    = self::get_order_shipping_method_label( $order );
		$data['tracking_detected']  = self::order_has_tracking( $order );
		$data['fulfillment_reason'] = sanitize_text_field( $reason );
		$data['suggested_command']  = 'Show order ' . $order->get_id() . ' details';

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

	private static function get_revenue_order_status_slugs() {
		$statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : [];

		if ( empty( $statuses ) || ! is_array( $statuses ) ) {
			$statuses = [ 'processing', 'completed' ];
		}

		// WooCommerce reports commonly treat on-hold orders as reportable sales activity.
		$statuses[] = 'on-hold';
		$statuses   = apply_filters( 'yoro_ai_sales_report_order_statuses', $statuses );
		$statuses   = is_array( $statuses ) ? $statuses : [];
		$normalized = [];

		foreach ( $statuses as $status ) {
			$status = self::normalize_order_status_slug( $status );

			if ( '' !== $status ) {
				$normalized[] = $status;
			}
		}

		$available  = self::get_all_order_status_slugs();
		$normalized = array_values( array_unique( array_intersect( $normalized, $available ) ) );

		if ( ! empty( $normalized ) ) {
			return $normalized;
		}

		return array_values( array_intersect( [ 'processing', 'completed', 'on-hold' ], $available ) );
	}

	private static function is_revenue_order_status( $status ) {
		return in_array( self::normalize_order_status_slug( $status ), self::get_revenue_order_status_slugs(), true );
	}

	private static function normalize_order_status_slug( $status ) {
		return sanitize_key( preg_replace( '/^wc-/', '', (string) $status ) );
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
