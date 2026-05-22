<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Briefing_Service {

	public static function get_business_summary( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is not active.', 'yoro-ai-core' ) );
		}

		$window_key = YoRo_Orders_Read_Service::briefing_normalize_operations_window_key( $params['window'] ?? 'last_7_days' );
		$focus      = ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview';
		$window     = YoRo_Orders_Read_Service::briefing_get_operations_window( $window_key );

		$orders = wc_get_orders(
			[
				'limit'        => 200,
				'status'       => YoRo_Orders_Read_Service::briefing_get_all_order_status_slugs(),
				'date_created' => $window['query'],
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);

		$order_count         = 0;
		$order_total         = 0.0;
		$completed_total     = 0.0;
		$customer_map        = [];
		$product_map         = [];
		$order_status_counts = [];
		$recent_orders       = [];

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order_count++;
			$status = $order->get_status();

			if ( ! isset( $order_status_counts[ $status ] ) ) {
				$order_status_counts[ $status ] = 0;
			}

			$order_status_counts[ $status ]++;

			$total       = (float) $order->get_total();
			$order_total += $total;

			if ( 'completed' === $status ) {
				$completed_total += $total;
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

				$product_id = $item->get_product_id();
				$key        = $product_id ? (string) $product_id : 'item:' . md5( $item->get_name() );

				if ( ! isset( $product_map[ $key ] ) ) {
					$product_map[ $key ] = [
						'id'       => $product_id,
						'name'     => $item->get_name(),
						'quantity' => 0,
						'total'    => 0.0,
					];
				}

				$product_map[ $key ]['quantity'] += (float) $item->get_quantity();
				$product_map[ $key ]['total']    += (float) $item->get_total();
			}

			if ( count( $recent_orders ) < 5 ) {
				$recent_orders[] = YoRo_Order_Presenter::format_order_summary( $order );
			}
		}

		$average_order_value = $order_count > 0 ? $order_total / $order_count : 0;
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
						'id'       => $product['id'],
						'name'     => $product['name'],
						'quantity' => $product['quantity'],
						'total'    => wc_format_decimal( $product['total'], 2 ),
					];
				},
				array_values( $product_map )
			),
			0,
			5
		);

		$business_metrics = [
			'order_count'         => $order_count,
			'gross_sales'         => wc_format_decimal( $order_total, 2 ),
			'completed_sales'     => wc_format_decimal( $completed_total, 2 ),
			'average_order_value' => wc_format_decimal( $average_order_value, 2 ),
			'unique_customers'    => count( $customer_map ),
			'product_lines'       => count( $product_map ),
		];

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

		return [
			'success'      => true,
			'action'       => 'store.get_business_summary',
			'summary'      => sprintf(
				'Business summary for %1$s: %2$d orders, %3$s gross sales, %4$s.',
				$window['label'],
				$order_count,
				$gross_sales_label,
				$customer_count_label
			),
			'message'      => sprintf(
				'Business summary for %1$s: %2$d orders, %3$s gross sales, %4$s.',
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
			'priority_items'       => array_slice( $priority_items, 0, 3 ),
			'recommended_sequence' => array_slice( $recommended_sequence, 0, 4 ),
			'business_actions'     => array_slice( $business_actions, 0, 4 ),
			'headline_insights'    => $headline_insights,
			'top_customers'        => $top_customers,
			'top_products'         => $top_products,
			'recent_orders'        => $recent_orders,
			'result_type'          => 'business_summary',
			'display_type'         => 'business_summary',
		];
	}

	public static function get_daily_briefing( $params ) {
		$window_key     = YoRo_Orders_Read_Service::briefing_normalize_operations_window_key( $params['window'] ?? 'today' );
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
			'result_type'            => 'daily_briefing',
			'display_type'           => 'daily_briefing',
		];
	}

	public static function get_morning_briefing( $params ) {
		$window_key           = YoRo_Orders_Read_Service::briefing_normalize_operations_window_key( $params['window'] ?? 'today' );
		$yesterday_window_key = YoRo_Orders_Read_Service::briefing_normalize_operations_window_key( $params['yesterday_window'] ?? 'yesterday' );
		$limit                = isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 5 ) : 5;
		$today_window         = YoRo_Orders_Read_Service::briefing_get_operations_window( $window_key );
		$yesterday_window     = YoRo_Orders_Read_Service::briefing_get_operations_window( $yesterday_window_key );

		$daily_briefing = self::get_daily_briefing(
			[
				'window' => $window_key,
				'limit'  => $limit,
			]
		);
		$yesterday_business = self::get_business_summary(
			[
				'window' => $yesterday_window_key,
				'focus'  => 'overview',
			]
		);

		if ( is_wp_error( $daily_briefing ) ) {
			return $daily_briefing;
		}

		if ( is_wp_error( $yesterday_business ) ) {
			return $yesterday_business;
		}

		$briefing_context = isset( $daily_briefing['briefing_context'] ) && is_array( $daily_briefing['briefing_context'] )
			? $daily_briefing['briefing_context']
			: [];
		$priority_items = isset( $daily_briefing['priority_items'] ) && is_array( $daily_briefing['priority_items'] )
			? array_slice( $daily_briefing['priority_items'], 0, 3 )
			: [];
		$priority_drilldowns = isset( $daily_briefing['priority_drilldowns'] ) && is_array( $daily_briefing['priority_drilldowns'] )
			? array_values( array_slice( $daily_briefing['priority_drilldowns'], 0, 4 ) )
			: [];
		$recommended_sequence = isset( $daily_briefing['recommended_sequence'] ) && is_array( $daily_briefing['recommended_sequence'] )
			? array_values( array_slice( $daily_briefing['recommended_sequence'], 0, 4 ) )
			: [];
		$business_metrics = isset( $yesterday_business['business_metrics'] ) && is_array( $yesterday_business['business_metrics'] )
			? $yesterday_business['business_metrics']
			: [];
		$business_context = isset( $yesterday_business['business_context'] ) && is_array( $yesterday_business['business_context'] )
			? $yesterday_business['business_context']
			: [];
		$top_priority = ! empty( $priority_items[0] ) && is_array( $priority_items[0] ) ? $priority_items[0] : [];
		$first_recommended_action = ! empty( $recommended_sequence[0] ) && is_array( $recommended_sequence[0] ) ? $recommended_sequence[0] : [];

		$headline = self::build_morning_briefing_headline(
			$business_context,
			$briefing_context,
			$top_priority
		);

		$yesterday_snapshot = [
			'orders'      => absint( $business_metrics['order_count'] ?? 0 ),
			'gross_sales' => sanitize_text_field( $business_metrics['gross_sales'] ?? '0' ),
			'customers'   => absint( $business_metrics['unique_customers'] ?? 0 ),
			'summary'     => sanitize_text_field( $yesterday_business['summary'] ?? '' ),
		];

		$live_pressure = [
			'main_pressure'            => sanitize_key( $briefing_context['top_topic'] ?? '' ),
			'attention_items'          => absint( $daily_briefing['operations_summary']['attention_total'] ?? 0 ),
			'fulfillment_items'        => absint( $daily_briefing['fulfillment_queue']['count'] ?? 0 ),
			'customer_followup_items'  => absint( $daily_briefing['customer_service_queue']['count'] ?? 0 ),
			'inventory_pressure_items' => absint( $daily_briefing['inventory_queue']['count'] ?? 0 ),
			'inventory_items'          => absint( $daily_briefing['inventory_queue']['count'] ?? 0 ),
		];

		$morning_drilldowns = self::build_morning_briefing_drilldowns(
			$priority_drilldowns,
			$recommended_sequence,
			$live_pressure
		);

		$suggested_commands = [];

		if ( ! empty( $first_recommended_action['command'] ) ) {
			$suggested_commands[] = sanitize_text_field( $first_recommended_action['command'] );
		}

		foreach ( [ 'Show operations attention queue', 'Show fulfillment queue', 'Show customer service queue' ] as $command ) {
			if ( ! in_array( $command, $suggested_commands, true ) ) {
				$suggested_commands[] = $command;
			}
		}

		$result = [
			'success'             => true,
			'action'              => 'store.get_morning_briefing',
			'summary'             => $headline,
			'message'             => $headline,
			'assistant_summary'   => self::build_morning_briefing_assistant_summary(
				$headline,
				$yesterday_window,
				$business_metrics
			),
			'assistant_followup'  => ! empty( $first_recommended_action['reason'] )
				? sanitize_text_field( $first_recommended_action['reason'] )
				: __( 'I can take you straight to the first queue worth reviewing.', 'yoro-ai-core' ),
			'count'               => 1,
			'read_only'           => true,
			'undoable'            => false,
			'undo_plan'           => [],
			'data'                => [],
			'meta'                => [
				'changes_store' => false,
				'proactive'     => true,
			],
			'processed'           => [],
			'skipped'             => [],
			'errors'              => [],
			'briefing_date'       => YoRo_Date_Helper::format_timestamp( current_datetime()->getTimestamp() ),
			'briefing_date_ts'    => current_datetime()->getTimestamp(),
			'time_window_label'   => __( 'Yesterday and this morning', 'yoro-ai-core' ),
			'headline'            => $headline,
			'yesterday_window'    => [
				'key'            => $yesterday_window_key,
				'label'          => sanitize_text_field( $yesterday_window['label'] ?? __( 'Yesterday', 'yoro-ai-core' ) ),
				'start'          => sanitize_text_field( $yesterday_window['start'] ?? '' ),
				'end'            => sanitize_text_field( $yesterday_window['end'] ?? '' ),
				'start_ts'       => isset( $yesterday_window['start_ts'] ) ? (int) $yesterday_window['start_ts'] : 0,
				'end_ts'         => isset( $yesterday_window['end_ts'] ) ? (int) $yesterday_window['end_ts'] : 0,
				'timezone'       => sanitize_text_field( $yesterday_window['timezone'] ?? '' ),
				'week_starts_on' => isset( $yesterday_window['week_starts_on'] ) ? absint( $yesterday_window['week_starts_on'] ) : absint( get_option( 'start_of_week', 1 ) ),
			],
			'live_window'         => [
				'key'            => $window_key,
				'label'          => sanitize_text_field( $today_window['label'] ?? __( 'Today', 'yoro-ai-core' ) ),
				'start'          => sanitize_text_field( $today_window['start'] ?? '' ),
				'end'            => sanitize_text_field( $today_window['end'] ?? '' ),
				'start_ts'       => isset( $today_window['start_ts'] ) ? (int) $today_window['start_ts'] : 0,
				'end_ts'         => isset( $today_window['end_ts'] ) ? (int) $today_window['end_ts'] : 0,
				'timezone'       => sanitize_text_field( $today_window['timezone'] ?? '' ),
				'week_starts_on' => isset( $today_window['week_starts_on'] ) ? absint( $today_window['week_starts_on'] ) : absint( get_option( 'start_of_week', 1 ) ),
			],
			'yesterday_snapshot'       => $yesterday_snapshot,
			'live_pressure'            => $live_pressure,
			'top_priorities'           => $priority_items,
			'first_recommended_action' => [
				'label'   => sanitize_text_field( $first_recommended_action['label'] ?? __( 'Open operations attention queue', 'yoro-ai-core' ) ),
				'command' => sanitize_text_field( $first_recommended_action['command'] ?? 'Show operations attention queue' ),
				'reason'  => sanitize_text_field( $first_recommended_action['reason'] ?? '' ),
				'kind'    => sanitize_key( $first_recommended_action['kind'] ?? 'step' ),
			],
			'priority_drilldowns' => $morning_drilldowns,
			'suggested_commands' => array_slice( $suggested_commands, 0, 4 ),
			'dismissible'        => true,
			'daily_briefing'     => $daily_briefing,
			'business_summary'   => $yesterday_business,
			'result_type'        => 'morning_briefing',
			'display_type'       => 'morning_briefing',
		];

		$result['signature'] = self::build_morning_briefing_signature( $result );

		return $result;
	}

	private static function get_briefing_label_for_window( $window_key, $window ) {
		switch ( sanitize_key( $window_key ) ) {
			case 'this_week':
			case 'last_week':
			case 'last_7_days':
				return __( 'Weekly briefing', 'yoro-ai-core' );
			case 'this_month':
			case 'last_month':
			case 'last_30_days':
				return __( 'Monthly briefing', 'yoro-ai-core' );
			default:
				return __( 'Daily briefing', 'yoro-ai-core' );
		}
	}

	private static function get_briefing_window_phrase( $window_key ) {
		switch ( sanitize_key( $window_key ) ) {
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
			'active_customers' => $active_customers,
			'business_state'   => $business_state,
			'business_message' => $business_message,
			'priority_scores'  => $priority_scores,
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
				'message' => __( 'The store still has routine operational work, but nothing in this briefing looks unusually acute.', 'yoro-ai-core' ),
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
						'command' => 'Show operations attention queue',
					],
					[
						'label'   => __( 'Check fulfillment pressure', 'yoro-ai-core' ),
						'command' => 'Show fulfillment queue',
					],
					[
						'label'   => __( 'Check customer follow-up', 'yoro-ai-core' ),
						'command' => 'Show customer service queue',
					],
					[
						'label'   => $summary_label,
						'command' => 'Show operations summary for ' . $window_phrase,
					],
				];
				break;

			case 'fulfillment':
				$drilldowns = [
					[
						'label'   => __( 'Open fulfillment queue', 'yoro-ai-core' ),
						'command' => 'Show fulfillment queue',
					],
					[
						'label'   => __( 'Check shipping blockers', 'yoro-ai-core' ),
						'command' => 'What looks stuck in shipping?',
					],
					[
						'label'   => __( 'Review attention queue', 'yoro-ai-core' ),
						'command' => 'Show operations attention queue',
					],
				];
				break;

			case 'customer_service':
				$drilldowns = [
					[
						'label'   => __( 'Open customer follow-up', 'yoro-ai-core' ),
						'command' => 'Show customer service queue',
					],
					[
						'label'   => __( 'See who needs a reply first', 'yoro-ai-core' ),
						'command' => 'Who should we answer first ' . $window_phrase . '?',
					],
					[
						'label'   => __( 'Review fulfillment queue', 'yoro-ai-core' ),
						'command' => 'Show fulfillment queue',
					],
				];
				break;

			case 'inventory':
				$drilldowns = [
					[
						'label'   => __( 'Open inventory queue', 'yoro-ai-core' ),
						'command' => 'Show inventory queue',
					],
					[
						'label'   => __( 'Focus on restock priorities', 'yoro-ai-core' ),
						'command' => 'What should we restock first?',
					],
					[
						'label'   => $summary_label,
						'command' => 'Show operations summary for ' . $window_phrase,
					],
				];
				break;

			case 'business':
				$drilldowns = [
					[
						'label'   => __( 'Open business summary', 'yoro-ai-core' ),
						'command' => 'Show business summary for ' . $window_phrase,
					],
					[
						'label'   => __( 'Review operations summary', 'yoro-ai-core' ),
						'command' => 'Show operations summary for ' . $window_phrase,
					],
					[
						'label'   => __( 'Check customer follow-up', 'yoro-ai-core' ),
						'command' => 'Show customer service queue',
					],
				];
				break;
		}

		if ( empty( $drilldowns ) ) {
			$drilldowns = [
				[
					'label'   => $summary_label,
					'command' => 'Show operations summary for ' . $window_phrase,
				],
				[
					'label'   => __( 'Open attention queue', 'yoro-ai-core' ),
					'command' => 'Show operations attention queue',
				],
				[
					'label'   => __( 'Open business summary', 'yoro-ai-core' ),
					'command' => 'Show business summary for ' . $window_phrase,
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
				'command' => self::get_briefing_review_command_for_topic( $priority_topic, $window_phrase ),
			];
		}

		$normalized = [];
		$seen       = [];

		foreach ( $drilldowns as $drilldown ) {
			$label   = sanitize_text_field( $drilldown['label'] ?? '' );
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
				'command' => $command,
			];
		}

		return $normalized;
	}

	private static function get_operations_summary_label_for_window( $window_key ) {
		switch ( sanitize_key( $window_key ) ) {
			case 'this_week':
			case 'last_week':
			case 'last_7_days':
				return __( 'Review weekly operations summary', 'yoro-ai-core' );
			case 'this_month':
			case 'last_month':
			case 'last_30_days':
				return __( 'Review monthly operations summary', 'yoro-ai-core' );
			default:
				return __( 'Review operations summary', 'yoro-ai-core' );
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
				return 'Show business summary for ' . $window_phrase;
			default:
				return 'Show operations summary for ' . $window_phrase;
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

			if ( '' === $command ) {
				continue;
			}

			$sequence[] = [
				'kind'    => 'view',
				'topic'   => self::infer_briefing_topic_from_command( $command ),
				'label'   => sanitize_text_field( $drilldown['label'] ?? $command ),
				'command' => $command,
				'reason'  => self::build_briefing_sequence_reason_for_command( $command, $top_topic, $window_label ),
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

	private static function infer_briefing_topic_from_command( $command ) {
		$normalized = strtolower( sanitize_text_field( $command ) );

		if ( false !== strpos( $normalized, 'attention' ) ) {
			return 'operations_attention';
		}

		if ( false !== strpos( $normalized, 'fulfillment' ) || false !== strpos( $normalized, 'shipping' ) ) {
			return 'fulfillment';
		}

		if ( false !== strpos( $normalized, 'customer service' ) || false !== strpos( $normalized, 'reply' ) ) {
			return 'customer_service';
		}

		if ( false !== strpos( $normalized, 'inventory' ) || false !== strpos( $normalized, 'restock' ) ) {
			return 'inventory';
		}

		if ( false !== strpos( $normalized, 'business' ) || false !== strpos( $normalized, 'sales' ) ) {
			return 'business';
		}

		return 'operations_overview';
	}

	private static function build_briefing_sequence_reason_for_command( $command, $top_topic, $window_label ) {
		$topic = self::infer_briefing_topic_from_command( $command );

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
				return __( 'Use this when you are ready to act on the current briefing.', 'yoro-ai-core' );
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
					__( 'YoRo did not find any orders in %s, so the business summary is mostly a pressure check rather than a sales readout.', 'yoro-ai-core' ),
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
				'command' => 'Show operations summary for ' . $window_phrase,
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
			'label'   => __( 'Re-check this business window', 'yoro-ai-core' ),
			'command' => 'Show business summary for ' . $window_phrase,
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
				'label'   => __( 'Open operations summary', 'yoro-ai-core' ),
				'command' => 'Show operations summary for ' . $window_phrase,
			],
		];
	}

	private static function build_morning_briefing_headline( $business_context, $briefing_context, $top_priority ) {
		$business_state = sanitize_key( $business_context['state'] ?? '' );
		$briefing_state = sanitize_key( $briefing_context['state'] ?? '' );
		$top_message    = sanitize_text_field( $top_priority['message'] ?? '' );

		if ( 'positive' === $business_state && in_array( $briefing_state, [ 'high_pressure', 'focused_pressure', 'split_pressure' ], true ) ) {
			return __( 'Sales held up yesterday, but this morning still needs focused attention.', 'yoro-ai-core' );
		}

		if ( in_array( $briefing_state, [ 'high_pressure', 'focused_pressure' ], true ) ) {
			return __( 'One queue is clearly more urgent than the rest this morning.', 'yoro-ai-core' );
		}

		if ( '' !== $top_message ) {
			return $top_message;
		}

		return __( 'Your store is in a stable place this morning, with a few items worth checking first.', 'yoro-ai-core' );
	}

	private static function build_morning_briefing_assistant_summary( $headline, $yesterday_window, $business_metrics ) {
		return sprintf(
			/* translators: 1: headline, 2: yesterday label, 3: order count, 4: gross sales */
			__( '%1$s Over %2$s, the store closed with %3$d orders and %4$s in gross sales.', 'yoro-ai-core' ),
			sanitize_text_field( $headline ),
			sanitize_text_field( strtolower( $yesterday_window['label'] ?? __( 'yesterday', 'yoro-ai-core' ) ) ),
			absint( $business_metrics['order_count'] ?? 0 ),
			sanitize_text_field( $business_metrics['gross_sales'] ?? '0' )
		);
	}

	private static function build_morning_briefing_drilldowns( $priority_drilldowns = [], $recommended_sequence = [], $live_pressure = [] ) {
		$items = [];

		foreach ( $priority_drilldowns as $drilldown ) {
			$command = sanitize_text_field( $drilldown['command'] ?? '' );
			$label   = sanitize_text_field( $drilldown['label'] ?? $command );

			if ( '' === $command || '' === $label ) {
				continue;
			}

			$topic = self::infer_briefing_topic_from_command( $command );

			$items[] = [
				'topic'         => $topic,
				'label'         => $label,
				'command'       => $command,
				'command_label' => $label,
				'summary'       => self::build_morning_drilldown_summary( $topic, $live_pressure ),
				'count'         => self::get_morning_drilldown_count( $topic, $live_pressure ),
				'recommended'   => false,
			];
		}

		$first_recommended = ! empty( $recommended_sequence[0] ) && is_array( $recommended_sequence[0] )
			? $recommended_sequence[0]
			: [];
		$recommended_topic = sanitize_key( $first_recommended['topic'] ?? self::infer_briefing_topic_from_command( $first_recommended['command'] ?? '' ) );
		$recommended_key   = strtolower( sanitize_text_field( $first_recommended['command'] ?? '' ) );

		foreach ( $items as $index => $item ) {
			if ( $recommended_key && strtolower( $item['command'] ) === $recommended_key ) {
				$items[ $index ]['recommended'] = true;
			}
		}

		if ( $recommended_topic ) {
			usort(
				$items,
				function( $a, $b ) use ( $recommended_topic ) {
					$a_match = $recommended_topic === sanitize_key( $a['topic'] ?? '' ) ? 1 : 0;
					$b_match = $recommended_topic === sanitize_key( $b['topic'] ?? '' ) ? 1 : 0;

					if ( $a_match !== $b_match ) {
						return $b_match <=> $a_match;
					}

					return (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 );
				}
			);
		}

		return array_slice( $items, 0, 3 );
	}

	private static function build_morning_drilldown_summary( $topic, $live_pressure = [] ) {
		$count = self::get_morning_drilldown_count( $topic, $live_pressure );

		switch ( sanitize_key( $topic ) ) {
			case 'operations_attention':
				return sprintf(
					_n( '%d order needs operational attention first.', '%d orders need operational attention first.', $count, 'yoro-ai-core' ),
					$count
				);
			case 'fulfillment':
				return sprintf(
					_n( '%d fulfillment item is waiting on progress.', '%d fulfillment items are waiting on progress.', $count, 'yoro-ai-core' ),
					$count
				);
			case 'customer_service':
				return sprintf(
					_n( '%d customer follow-up item is open.', '%d customer follow-up items are open.', $count, 'yoro-ai-core' ),
					$count
				);
			case 'inventory':
				return sprintf(
					_n( '%d inventory item looks at risk.', '%d inventory items look at risk.', $count, 'yoro-ai-core' ),
					$count
				);
			case 'business':
				return __( 'Review the commercial picture behind today\'s operating pressure.', 'yoro-ai-core' );
			default:
				return __( 'Open the queue view behind this morning priority.', 'yoro-ai-core' );
		}
	}

	private static function get_morning_drilldown_count( $topic, $live_pressure = [] ) {
		switch ( sanitize_key( $topic ) ) {
			case 'operations_attention':
				return absint( $live_pressure['attention_items'] ?? 0 );
			case 'fulfillment':
				return absint( $live_pressure['fulfillment_items'] ?? 0 );
			case 'customer_service':
				return absint( $live_pressure['customer_followup_items'] ?? 0 );
			case 'inventory':
				return absint( $live_pressure['inventory_items'] ?? $live_pressure['inventory_pressure_items'] ?? 0 );
			default:
				return 0;
		}
	}

	private static function build_morning_briefing_signature( $result ) {
		$parts = [
			sanitize_text_field( $result['briefing_date'] ?? '' ),
			sanitize_text_field( $result['headline'] ?? '' ),
		];

		$top_priorities = isset( $result['top_priorities'] ) && is_array( $result['top_priorities'] )
			? $result['top_priorities']
			: [];

		foreach ( array_slice( $top_priorities, 0, 3 ) as $priority ) {
			$parts[] = sanitize_text_field( $priority['title'] ?? '' );
		}

		return md5( wp_json_encode( $parts ) );
	}
}
