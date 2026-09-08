<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Operations_Service {

	public static function get_operations_summary( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$window_key = YoRo_Orders_Read_Service::briefing_normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'today' ) );
		$focus      = ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview';
		$language   = sanitize_key( $params['response_language'] ?? '' ) ?: 'en';

		return YoRo_Action_Cache::remember(
			'operations_summary',
			[
				'queue_evidence_version' => 1,
				'window' => $window_key,
				'focus'  => $focus,
				'language' => $language,
			],
			function() use ( $window_key, $focus, $language ) {
				$window = YoRo_Orders_Read_Service::briefing_get_operations_window( $window_key );

				$window_statuses = [
					'pending',
					'processing',
					'on-hold',
					'completed',
					'cancelled',
					'refunded',
					'failed',
				];

				$window_status_counts = [];

				foreach ( $window_statuses as $status ) {
					$window_status_counts[ $status ] = YoRo_Orders_Read_Service::queue_count_orders(
						[
							'status'       => [ $status ],
							'date_created' => $window['query'],
						]
					);
				}
				$previous_window = YoRo_Date_Helper::build_previous_window( $window );
				$previous_window_status_counts = [];

				if ( ! empty( $previous_window['query'] ) ) {
					foreach ( $window_statuses as $status ) {
						$previous_window_status_counts[ $status ] = YoRo_Orders_Read_Service::queue_count_orders(
							[
								'status'       => [ $status ],
								'date_created' => $previous_window['query'],
							]
						);
					}
				}

				$backlog_counts = [
					'pending'    => YoRo_Orders_Read_Service::queue_count_orders( [ 'status' => [ 'pending' ] ] ),
					'processing' => YoRo_Orders_Read_Service::queue_count_orders( [ 'status' => [ 'processing' ] ] ),
					'on-hold'    => YoRo_Orders_Read_Service::queue_count_orders( [ 'status' => [ 'on-hold' ] ] ),
				];

				$attention_counts = [
					'pending_over_24h'    => YoRo_Orders_Read_Service::queue_count_orders(
						[
							'status'       => [ 'pending' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-24 hours' ),
						]
					),
					'processing_over_72h' => YoRo_Orders_Read_Service::queue_count_orders(
						[
							'status'       => [ 'processing' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-72 hours' ),
						]
					),
					'on_hold_over_48h'    => YoRo_Orders_Read_Service::queue_count_orders(
						[
							'status'       => [ 'on-hold' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-48 hours' ),
						]
					),
				];

				foreach ( [ $window_status_counts, $previous_window_status_counts, $backlog_counts, $attention_counts ] as $counts ) {
					foreach ( $counts as $count ) {
						if ( is_wp_error( $count ) ) {
							return $count;
						}
					}
				}
				$window_total    = array_sum( $window_status_counts );
				$previous_window_total = array_sum( $previous_window_status_counts );
				$backlog_total   = array_sum( $backlog_counts );
				$attention_total = array_sum( $attention_counts );
				$backlog_age     = self::summarize_backlog_age();
				if ( is_wp_error( $backlog_age ) ) {
					return $backlog_age;
				}
				$comparison      = [
					'window'  => YoRo_Date_Helper::build_window_payload( $previous_window ),
					'metrics' => [
						'new_orders'       => YoRo_Report_Builder::metric_comparison( $window_total, $previous_window_total ),
						'completed_orders' => YoRo_Report_Builder::metric_comparison( absint( $window_status_counts['completed'] ?? 0 ), absint( $previous_window_status_counts['completed'] ?? 0 ) ),
						'failed_orders'    => YoRo_Report_Builder::metric_comparison( absint( $window_status_counts['failed'] ?? 0 ), absint( $previous_window_status_counts['failed'] ?? 0 ) ),
					],
				];
				$recent_orders   = [];

				$recent_candidates = YoRo_Orders_Read_Service::queue_query_orders(
						[
							'limit'   => 5,
							'status'  => [ 'pending', 'processing', 'on-hold' ],
							'orderby' => 'date',
							'order'   => 'DESC',
						]
				);
				if ( is_wp_error( $recent_candidates ) ) {
					return $recent_candidates;
				}
				foreach ( $recent_candidates as $order ) {
					if ( $order instanceof WC_Order ) {
						$age = YoRo_Orders_Read_Service::queue_order_age_hours( $order );
						if ( is_wp_error( $age ) ) {
							return $age;
						}
						$row = YoRo_Order_Presenter::format_order_summary( $order );
						$row['age_hours'] = $age;
						$recent_orders[] = $row;
					}
				}

				$summary = 'vi' === $language
					? sprintf(
						'Tổng quan vận hành trong %1$s: %2$d đơn mới, %3$d mục tồn đọng, %4$d mục cần chú ý.',
						YoRo_Date_Helper::get_window_label( $window_key, 'vi' ),
						$window_total,
						$backlog_total,
						$attention_total
					)
					: sprintf(
						'Operations report for %1$s: %2$d new orders, %3$d open backlog, %4$d attention items.',
						$window['label'],
						$window_total,
						$backlog_total,
						$attention_total
					);
				$report = self::build_operations_summary_report(
					$window,
					$language,
					$window_total,
					$backlog_total,
					$attention_total,
					$backlog_counts,
					$attention_counts,
					$window_status_counts,
					$comparison,
					$backlog_age
				);

				return [
					'success'              => true,
					'action'               => 'operations.get_summary',
					'summary'              => $summary,
					'message'              => $summary,
					'count'                => $window_total,
					'read_only'            => true,
					'undoable'             => false,
					'undo_plan'            => [],
					'data'                 => [],
					'meta'                 => [ 'queue_evidence' => [ 'version' => 1, 'captured_at' => gmdate( 'c' ), 'age_basis' => 'date_created', 'status_duration' => 'unknown', 'count_basis' => 'query_matches', 'backlog_sample_size' => $backlog_age['sampled_orders'], 'backlog_candidate_limit' => 200, 'backlog_complete' => $backlog_age['sampled_orders'] < 200 ] ],
					'processed'            => [],
					'skipped'              => [],
					'errors'               => [],
					'window'               => YoRo_Date_Helper::build_window_payload( $window ),
					'focus'                => $focus,
					'window_order_count'   => $window_total,
					'backlog_total'        => $backlog_total,
					'attention_total'      => $attention_total,
					'window_status_counts' => $window_status_counts,
					'comparison'           => $comparison,
					'backlog_age'          => $backlog_age,
					'backlog_counts'       => $backlog_counts,
					'attention_counts'     => $attention_counts,
					'recent_orders'        => $recent_orders,
					'report'               => $report,
					'result_type'          => 'operations_summary',
					'display_type'         => 'operations_summary',
				];
			}
		);
	}

	private static function build_operations_summary_report( $window, $language, $window_total, $backlog_total, $attention_total, $backlog_counts, $attention_counts, $window_status_counts = [], $comparison = [], $backlog_age = [] ) {
		$is_vi = 'vi' === sanitize_key( $language );
		$window_label = YoRo_Date_Helper::get_window_label( $window['key'] ?? '', $is_vi ? 'vi' : 'en' );
		$health = $attention_total > 0 ? 'risk' : ( $backlog_total > 0 ? 'watch' : 'good' );
		$order_comparison = self::comparison_for_metric( $comparison, 'new_orders' );
		$completed_comparison = self::comparison_for_metric( $comparison, 'completed_orders' );
		$failed_comparison = self::comparison_for_metric( $comparison, 'failed_orders' );
		$oldest_backlog_hours = absint( $backlog_age['oldest_hours'] ?? 0 );
		$average_backlog_hours = absint( $backlog_age['average_hours'] ?? 0 );
		$headline = $is_vi
			? sprintf( 'Vận hành trong %1$s %2$s: %3$d đơn mới, %4$d mục tồn đọng, %5$d mục cần chú ý.', $window_label, $attention_total > 0 ? 'đang cần chú ý' : 'đang ổn định', $window_total, $backlog_total, $attention_total )
			: sprintf( 'Operations %1$s for %2$s: %3$d new orders, %4$d open backlog, %5$d attention items.', $attention_total > 0 ? 'need attention' : 'look stable', $window_label, $window_total, $backlog_total, $attention_total );

		$metrics = [
			YoRo_Report_Builder::metric( 'new_orders', __( 'New orders', 'yoro-ai-core' ), $window_total, 'orders', 'number', 'neutral', $order_comparison['trend'] ?? '', $order_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'open_backlog', __( 'Open backlog', 'yoro-ai-core' ), $backlog_total, 'orders', 'number', $backlog_total > 0 ? 'watch' : 'positive' ),
			YoRo_Report_Builder::metric( 'attention_items', __( 'Needs attention', 'yoro-ai-core' ), $attention_total, 'orders', 'number', $attention_total > 0 ? 'risk' : 'positive' ),
			YoRo_Report_Builder::metric( 'completed_orders', __( 'Completed orders', 'yoro-ai-core' ), absint( $window_status_counts['completed'] ?? 0 ), 'orders', 'number', 'neutral', $completed_comparison['trend'] ?? '', $completed_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'failed_orders', __( 'Failed orders', 'yoro-ai-core' ), absint( $window_status_counts['failed'] ?? 0 ), 'orders', 'number', absint( $window_status_counts['failed'] ?? 0 ) > 0 ? 'watch' : 'neutral', $failed_comparison['trend'] ?? '', $failed_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'pending_backlog', __( 'Pending backlog', 'yoro-ai-core' ), absint( $backlog_counts['pending'] ?? 0 ), 'orders' ),
			YoRo_Report_Builder::metric( 'processing_backlog', __( 'Processing backlog', 'yoro-ai-core' ), absint( $backlog_counts['processing'] ?? 0 ), 'orders' ),
			YoRo_Report_Builder::metric( 'on_hold_backlog', __( 'On-hold backlog', 'yoro-ai-core' ), absint( $backlog_counts['on-hold'] ?? 0 ), 'orders' ),
			YoRo_Report_Builder::metric( 'oldest_backlog_age', __( 'Oldest backlog age', 'yoro-ai-core' ), wc_format_decimal( $oldest_backlog_hours / 24, 1 ), 'days', 'number', $oldest_backlog_hours >= 72 ? 'risk' : ( $oldest_backlog_hours >= 24 ? 'watch' : 'neutral' ), '', null, 'store', 'operations', __( 'Oldest sampled pending, processing, or on-hold order age.', 'yoro-ai-core' ), 55 ),
			YoRo_Report_Builder::metric( 'average_backlog_age', __( 'Average backlog age', 'yoro-ai-core' ), wc_format_decimal( $average_backlog_hours / 24, 1 ), 'days', 'number', $average_backlog_hours >= 72 ? 'risk' : ( $average_backlog_hours >= 24 ? 'watch' : 'neutral' ), '', null, 'store', 'operations', __( 'Average age from the sampled open backlog.', 'yoro-ai-core' ), 56 ),
		];

		$profile_context  = YoRo_Report_Builder::store_profile_context( [ 'operations', 'fulfillment' ] );
		$profile_insights = YoRo_Report_Builder::operations_profile_insights( $profile_context );
		$insights = [
			YoRo_Report_Builder::insight(
				__( 'Backlog pressure', 'yoro-ai-core' ),
				$backlog_total > 0
					/* translators: %s: Value generated from store data. */
					? sprintf( __( '%d open orders are still sitting in pending, processing, or on-hold queues.', 'yoro-ai-core' ), $backlog_total )
					: __( 'No open operational backlog is visible in the main queues.', 'yoro-ai-core' ),
				'operations',
				$backlog_total > 0 ? 'watch' : 'positive'
			),
		];

		if ( $oldest_backlog_hours >= 24 ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Backlog age needs attention', 'yoro-ai-core' ),
				sprintf(
					/* translators: 1: oldest open order age in days, 2: average backlog age in days. */
					__( 'The oldest sampled open order was created %1$s days ago; sampled mean created-age is %2$s days. Time in the current status is unknown.', 'yoro-ai-core' ),
					wc_format_decimal( $oldest_backlog_hours / 24, 1 ),
					wc_format_decimal( $average_backlog_hours / 24, 1 )
				),
				'operations',
				$oldest_backlog_hours >= 72 ? 'risk' : 'watch',
				'operations',
				15
			);
		}

		$insights = array_merge( $insights, $profile_insights );

		$alerts = [];

		if ( $attention_total > 0 ) {
			$alerts[] = YoRo_Report_Builder::alert(
				__( 'Aged orders need review', 'yoro-ai-core' ),
				/* translators: %s: Value generated from store data. */
				sprintf( __( '%d orders are old enough to deserve a direct operations check.', 'yoro-ai-core' ), $attention_total ),
				'risk',
				'aged_orders',
				'operations',
				10
			);
		}

		if ( $oldest_backlog_hours >= 72 ) {
			$alerts[] = YoRo_Report_Builder::alert(
				__( 'Oldest sampled order needs review', 'yoro-ai-core' ),
				/* translators: %s: Value generated from store data. */
				sprintf( __( 'At least one sampled open order is older than %s days.', 'yoro-ai-core' ), wc_format_decimal( $oldest_backlog_hours / 24, 1 ) ),
				'risk',
				'backlog_age',
				'operations',
				12
			);
		}

		$actions = [
			YoRo_Report_Builder::action( __( 'Show processing orders', 'yoro-ai-core' ), 'Show processing orders', 'view', 'operations', 20, __( 'Review the largest active fulfillment workload first.', 'yoro-ai-core' ) ),
			YoRo_Report_Builder::action( __( 'Show pending orders', 'yoro-ai-core' ), 'Show pending orders', 'view', 'operations', 30, __( 'Check orders still waiting for payment or manual processing.', 'yoro-ai-core' ) ),
			YoRo_Report_Builder::action( __( 'Show on-hold orders', 'yoro-ai-core' ), 'Show on-hold orders', 'view', 'operations', 40, __( 'Review held orders that may need a decision.', 'yoro-ai-core' ) ),
		];

		$breakdowns = self::build_operations_breakdowns( $window_status_counts, $backlog_counts, $attention_counts, $window_total, $backlog_total, $attention_total );
		$report     = YoRo_Report_Builder::build( 'operations', $window, $health, $headline, $metrics, $insights, $alerts, $actions, $actions, $breakdowns );
		$report     = YoRo_Report_Builder::attach_profile_context( $report, $profile_context );

		if ( ! empty( $comparison ) ) {
			$report['comparison'] = $comparison;
		}

		return $report;
	}

	private static function comparison_for_metric( $comparison, $key ) {
		return is_array( $comparison ) && ! empty( $comparison['metrics'][ $key ] ) && is_array( $comparison['metrics'][ $key ] )
			? $comparison['metrics'][ $key ]
			: [];
	}

	private static function build_operations_breakdowns( $window_status_counts, $backlog_counts, $attention_counts, $window_total, $backlog_total, $attention_total ) {
		$breakdowns = [];

		$status_items = [];
		foreach ( is_array( $window_status_counts ) ? $window_status_counts : [] as $status => $count ) {
			$count = absint( $count );

			if ( 0 === $count ) {
				continue;
			}

			$status_items[] = YoRo_Report_Builder::breakdown_item(
				self::operation_status_label( $status ),
				$count,
				__( 'orders', 'yoro-ai-core' ),
				__( 'Created in this window', 'yoro-ai-core' ),
				self::format_count_share( $count, $window_total ),
				[
					'status' => sanitize_key( $status ),
				]
			);
		}

		if ( ! empty( $status_items ) ) {
			$breakdowns[] = YoRo_Report_Builder::breakdown(
				'status_mix',
				__( 'Order status mix', 'yoro-ai-core' ),
				$status_items,
				'operations',
				30,
				__( 'How new orders are distributed by current status in this report window.', 'yoro-ai-core' )
			);
		}

		$backlog_items = [];
		foreach ( is_array( $backlog_counts ) ? $backlog_counts : [] as $status => $count ) {
			$count = absint( $count );

			if ( 0 === $count ) {
				continue;
			}

			$backlog_items[] = YoRo_Report_Builder::breakdown_item(
				self::operation_status_label( $status ),
				$count,
				__( 'orders', 'yoro-ai-core' ),
				__( 'Open backlog', 'yoro-ai-core' ),
				self::format_count_share( $count, $backlog_total ),
				[
					'status' => sanitize_key( $status ),
					'queue'  => 'backlog',
				]
			);
		}

		if ( ! empty( $backlog_items ) ) {
			$breakdowns[] = YoRo_Report_Builder::breakdown(
				'backlog_mix',
				__( 'Open backlog', 'yoro-ai-core' ),
				$backlog_items,
				'operations',
				35,
				__( 'Pending, processing, and on-hold work currently waiting for action.', 'yoro-ai-core' )
			);
		}

		$attention_labels = [
			'pending_over_24h'    => __( 'Pending; created at least 24h ago', 'yoro-ai-core' ),
			'processing_over_72h' => __( 'Processing; created at least 72h ago', 'yoro-ai-core' ),
			'on_hold_over_48h'    => __( 'On-hold; created at least 48h ago', 'yoro-ai-core' ),
		];
		$attention_items  = [];

		foreach ( $attention_labels as $key => $label ) {
			$count = absint( $attention_counts[ $key ] ?? 0 );

			if ( 0 === $count ) {
				continue;
			}

			$attention_items[] = YoRo_Report_Builder::breakdown_item(
				$label,
				$count,
				__( 'orders', 'yoro-ai-core' ),
				__( 'Aged queue', 'yoro-ai-core' ),
				self::format_count_share( $count, $attention_total ),
				[
					'queue' => sanitize_key( $key ),
				]
			);
		}

		if ( ! empty( $attention_items ) ) {
			$breakdowns[] = YoRo_Report_Builder::breakdown(
				'attention_queues',
				__( 'Attention queues', 'yoro-ai-core' ),
				$attention_items,
				'operations',
				25,
				__( 'Aged order queues most likely to need review.', 'yoro-ai-core' )
			);
		}

		return $breakdowns;
	}

	private static function operation_status_label( $status ) {
		$status = sanitize_key( $status );

		if ( function_exists( 'wc_get_order_status_name' ) ) {
			$label = wc_get_order_status_name( 'wc-' . $status );

			if ( is_string( $label ) && '' !== $label ) {
				return $label;
			}
		}

		return ucwords( str_replace( [ '-', '_' ], ' ', $status ) );
	}

	private static function format_count_share( $count, $total ) {
		$count = absint( $count );
		$total = absint( $total );

		if ( 0 === $count || 0 === $total ) {
			return '';
		}

		return wc_format_decimal( ( $count / $total ) * 100, 1 ) . '%';
	}

	private static function summarize_backlog_age() {
		$summary = [
			'sampled_orders' => 0,
			'oldest_hours'   => 0,
			'average_hours'  => 0,
		];

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $summary;
		}

		$orders = YoRo_Orders_Read_Service::queue_query_orders(
			[
				'limit'   => 200,
				'status'  => [ 'pending', 'processing', 'on-hold' ],
				'orderby' => 'date',
				'order'   => 'ASC',
			]
		);

		if ( is_wp_error( $orders ) ) {
			return $orders;
		}
		$total_hours = 0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$age_hours = YoRo_Orders_Read_Service::queue_order_age_hours( $order );
			if ( is_wp_error( $age_hours ) ) {
				return $age_hours;
			}
			$summary['sampled_orders']++;
			$total_hours += $age_hours;
			$summary['oldest_hours'] = max( $summary['oldest_hours'], $age_hours );
		}

		if ( $summary['sampled_orders'] > 0 ) {
			$summary['average_hours'] = (int) round( $total_hours / $summary['sampled_orders'] );
		}

		return $summary;
	}

	public static function get_operations_attention_queue( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$limit                = isset( $params['limit'] ) ? absint( $params['limit'] ) : 5;
		$limit                = min( max( $limit, 1 ), 20 );
		$high_value_threshold = isset( $params['high_value_threshold'] ) ? (float) $params['high_value_threshold'] : 200;
		$high_value_threshold = max( 0, $high_value_threshold );

		return YoRo_Action_Cache::remember(
			'operations_attention_queue',
			[
				'limit'                => $limit,
				'high_value_threshold' => $high_value_threshold,
				'schema_version'       => 3,
			],
			function() use ( $limit, $high_value_threshold ) {
				$queues = [
					YoRo_Orders_Read_Service::operations_build_attention_queue(
						'pending_payment_older_than_24h',
						__( 'Pending payment older than 24h', 'yoro-ai-core' ),
						__( 'Orders currently pending, created at least 24 hours ago. Status duration is unknown.', 'yoro-ai-core' ),
						[
							'status'       => [ 'pending' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-24 hours' ),
						],
						$limit
					),
					YoRo_Orders_Read_Service::operations_build_attention_queue(
						'processing_older_than_72h',
						__( 'Processing older than 72h', 'yoro-ai-core' ),
						__( 'Paid/open orders that may need fulfillment follow-up.', 'yoro-ai-core' ),
						[
							'status'       => [ 'processing' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-72 hours' ),
						],
						$limit
					),
					YoRo_Orders_Read_Service::operations_build_attention_queue(
						'on_hold_older_than_48h',
						__( 'On-hold older than 48h', 'yoro-ai-core' ),
						__( 'Orders currently on hold, created at least 48 hours ago. Status duration is unknown.', 'yoro-ai-core' ),
						[
							'status'       => [ 'on-hold' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-48 hours' ),
						],
						$limit
					),
					YoRo_Orders_Read_Service::operations_build_attention_queue(
						'recent_failed_orders',
						__( 'Recent failed orders', 'yoro-ai-core' ),
						__( 'Orders created in the last 7 days whose current status is failed. Cause and time of failure are unknown.', 'yoro-ai-core' ),
						[
							'status'       => [ 'failed' ],
							'date_created' => YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-7 days' ) . '...' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( 'now' ),
						],
						$limit
					),
					YoRo_Orders_Read_Service::operations_build_high_value_open_queue( $limit, $high_value_threshold ),
				];
				foreach ( $queues as $queue ) {
					if ( is_wp_error( $queue ) ) {
						return $queue;
					}
				}
				$queues = self::rank_operations_attention_queue_orders( $queues );

				$total_items = array_sum( array_column( $queues, 'count' ) );
				$attention_context    = self::build_operations_attention_orchestrator_context( $queues, $high_value_threshold );
				$priority_items       = self::build_operations_attention_priority_items( $queues, $attention_context );
				$case_rankings        = self::build_operations_attention_case_rankings( $queues, $attention_context );
				$orders = array_column( $case_rankings, 'order' );
				$queue_evidence = YoRo_Orders_Read_Service::queue_evidence( $queues, $orders );
				$recommended_sequence = self::build_operations_attention_recommended_sequence( $case_rankings, $attention_context );
				$next_case            = self::build_operations_attention_next_recommended_case( $case_rankings );
				$batch_candidates     = self::build_operations_attention_batch_candidates( $case_rankings );
				$queue_count          = count( $queues );
				$visible_case_count   = 0;

				foreach ( $queues as $queue ) {
					$visible_case_count += count( is_array( $queue['orders'] ?? null ) ? $queue['orders'] : [] );
				}

				$visible_unique_order_count = count( $orders );
				$queue_summary        = sprintf(
					/* translators: 1: queue match count, 2: queue count */
					__( 'Operations attention queue: %1$d queue memberships across %2$d queues.', 'yoro-ai-core' ),
					$total_items,
					$queue_count
				);

				return [
					'success'              => true,
					'action'               => 'operations.get_attention_queue',
					'summary'              => $queue_summary,
					'message'              => $queue_summary,
					'count'                => $total_items,
					'queue_item_count'     => $total_items,
					'queue_count'          => $queue_count,
					'visible_case_count'   => $visible_case_count,
					'visible_order_count'  => $visible_case_count,
					'visible_unique_order_count' => $visible_unique_order_count,
					'read_only'            => true,
					'undoable'             => false,
					'undo_plan'            => [],
					'data'                 => [],
					'meta'                 => [
						'queue_evidence' => $queue_evidence,
						'limit_per_queue'       => $limit,
						'high_value_threshold'  => $high_value_threshold,
						'fraud_scoring_applied' => false,
					],
					'processed'            => [],
					'skipped'              => [],
					'errors'               => [],
					'queues'               => $queues,
					'orders'               => $orders,
					'limit_per_queue'      => $limit,
					'high_value_threshold' => $high_value_threshold,
					'attention_context'    => $attention_context,
					'priority_items'       => array_slice( $priority_items, 0, 3 ),
					'case_rankings'        => array_slice( $case_rankings, 0, 10 ),
					'recommended_sequence' => array_slice( $recommended_sequence, 0, 4 ),
					'next_recommended_case'=> $next_case,
					'batch_draft_candidates' => $batch_candidates,
					'result_type'          => 'operations_attention_queue',
					'display_type'         => 'operations_attention_queue',
				];
			}
		);
	}

	private static function build_operations_attention_orchestrator_context( $queues, $high_value_threshold ) {
		$counts = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$counts[ $key ] = absint( $queue['count'] ?? 0 );
		}

		ksort( $counts );
		arsort( $counts );
		$top_topic = (string) key( $counts );
		$top_count = (int) reset( $counts );

		return [
			'top_topic'            => $top_topic,
			'top_count'            => $top_count,
			'total_items'          => array_sum( $counts ),
			'queues_tracked'       => count( $queues ),
			'pending_count'        => absint( $counts['pending_payment_older_than_24h'] ?? 0 ),
			'processing_count'     => absint( $counts['processing_older_than_72h'] ?? 0 ),
			'on_hold_count'        => absint( $counts['on_hold_older_than_48h'] ?? 0 ),
			'failed_count'         => absint( $counts['recent_failed_orders'] ?? 0 ),
			'high_value_count'     => absint( $counts['high_value_open_orders'] ?? 0 ),
			'high_value_threshold' => (float) $high_value_threshold,
		];
	}

	private static function build_operations_attention_priority_items( $queues, $attention_context ) {
		$items     = [];
		$queue_map = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' !== $key ) {
				$queue_map[ $key ] = $queue;
			}
		}

		if ( ! empty( $queue_map['pending_payment_older_than_24h']['count'] ) ) {
			$items[] = [
				'topic'   => 'pending_payment_older_than_24h',
				'score'   => absint( $queue_map['pending_payment_older_than_24h']['count'] ?? 0 ) + 120,
				'tone'    => 'warning',
				'title'   => __( 'Pending payment orders are still stacking up', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $queue_map['pending_payment_older_than_24h']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['processing_older_than_72h']['count'] ) ) {
			$items[] = [
				'topic'   => 'processing_older_than_72h',
				'score'   => absint( $queue_map['processing_older_than_72h']['count'] ?? 0 ) + 90,
				'tone'    => 'watch',
				'title'   => __( 'Aging processing orders still need operational follow-through', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $queue_map['processing_older_than_72h']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['on_hold_older_than_48h']['count'] ) ) {
			$items[] = [
				'topic'   => 'on_hold_older_than_48h',
				'score'   => absint( $queue_map['on_hold_older_than_48h']['count'] ?? 0 ) + 60,
				'tone'    => 'watch',
				'title'   => __( 'On-hold orders are still waiting for a decision', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $queue_map['on_hold_older_than_48h']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['high_value_open_orders']['count'] ) ) {
			$items[] = [
				'topic'   => 'high_value_open_orders',
				'score'   => absint( $queue_map['high_value_open_orders']['count'] ?? 0 ) + 80,
				'tone'    => 'positive',
				'title'   => __( 'High-value open orders deserve a direct check', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $queue_map['high_value_open_orders']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['recent_failed_orders']['count'] ) ) {
			$items[] = [
				'topic'   => 'recent_failed_orders',
				'score'   => absint( $queue_map['recent_failed_orders']['count'] ?? 0 ) + 40,
				'tone'    => 'neutral',
				'title'   => __( 'Recent failed orders are worth a quick recovery pass', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $queue_map['recent_failed_orders']['description'] ?? '' ),
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

	private static function build_operations_attention_recommended_sequence( $case_rankings, $attention_context ) {
		$sequence = [];
		$seen_topics = [];

		foreach ( $case_rankings as $ranked_case ) {
			$topic = sanitize_key( $ranked_case['topic'] ?? '' );
			$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];

			if ( '' === $topic || isset( $seen_topics[ $topic ] ) ) {
				continue;
			}

			$seen_topics[ $topic ] = true;
			$sequence[] = [
				'kind'    => 'view',
				'topic'   => $topic,
				'label'   => self::get_operations_attention_sequence_label( $topic ),
				'command' => self::get_operations_attention_sequence_command( $topic, $order ),
				'reason'  => self::build_operations_attention_case_reason( $topic, $ranked_case ),
			];

			if ( count( $sequence ) >= 3 ) {
				break;
			}
		}

		$sequence[] = [
			'kind'    => 'view',
			'topic'   => 'operations_overview',
			'label'   => __( 'Review today\'s operating picture', 'yoro-ai-core' ),
			'command' => 'Show operations report for today',
			'reason'  => __( 'Use this last when you want the broader store picture after the queue-specific checks.', 'yoro-ai-core' ),
		];

		return $sequence;
	}

	private static function rank_operations_attention_queue_orders( $queues ) {
		foreach ( $queues as $queue_index => $queue ) {
			$topic = sanitize_key( $queue['key'] ?? '' );

			if ( empty( $queue['orders'] ) || ! is_array( $queue['orders'] ) ) {
				$queues[ $queue_index ]['orders'] = [];
				continue;
			}

			foreach ( $queue['orders'] as $order_index => $order ) {
				$queues[ $queue_index ]['orders'][ $order_index ]['case_priority_score'] = self::score_operations_attention_case( $topic, $order );
				$queues[ $queue_index ]['orders'][ $order_index ]['queue_topic']          = $topic;
			}

			usort(
				$queues[ $queue_index ]['orders'],
				function( $a, $b ) {
					return ( (float) ( $b['case_priority_score'] ?? 0 ) <=> (float) ( $a['case_priority_score'] ?? 0 ) ) ?: ( (int) $a['id'] <=> (int) $b['id'] );
				}
			);
		}

		return $queues;
	}

	private static function build_operations_attention_case_rankings( $queues, $attention_context ) {
		$rankings = [];

		foreach ( $queues as $queue ) {
			$topic  = sanitize_key( $queue['key'] ?? '' );
			$orders = isset( $queue['orders'] ) && is_array( $queue['orders'] ) ? $queue['orders'] : [];

			foreach ( $orders as $order ) {
				$primary_command = self::get_operations_attention_primary_command( $topic, $order );

				if ( '' === $primary_command ) {
					continue;
				}

				$rankings[] = [
					'topic'             => $topic,
					'queue_label'       => sanitize_text_field( $queue['label'] ?? '' ),
					'queue_description' => sanitize_text_field( $queue['description'] ?? '' ),
					'order'             => $order,
					'primary_command'   => $primary_command,
					'score'             => (float) self::score_operations_attention_case( $topic, $order, $attention_context ),
				];
			}
		}

		return YoRo_Orders_Read_Service::queue_case_rankings( $rankings );
	}

	private static function build_operations_attention_next_recommended_case( $case_rankings ) {
		if ( empty( $case_rankings[0] ) || ! is_array( $case_rankings[0] ) ) {
			return [];
		}

		return self::map_operations_attention_ranked_case_to_card( $case_rankings[0], true );
	}

	private static function build_operations_attention_batch_candidates( $case_rankings ) {
		$candidates = [];
		$seen_topics = [];
		$seen_orders = [];

		foreach ( $case_rankings as $ranked_case ) {
			$topic = sanitize_key( $ranked_case['topic'] ?? '' );
			$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];
			$order_id = absint( $order['id'] ?? 0 );

			if ( '' === $topic || isset( $seen_topics[ $topic ] ) || $order_id <= 0 || isset( $seen_orders[ $order_id ] ) ) {
				continue;
			}

			$seen_topics[ $topic ] = true;
			$seen_orders[ $order_id ] = true;
			$candidates[] = self::map_operations_attention_ranked_case_to_card( $ranked_case, false );

			if ( count( $candidates ) >= 5 ) {
				return array_values( array_filter( $candidates ) );
			}
		}

		foreach ( $case_rankings as $ranked_case ) {
			$card = self::map_operations_attention_ranked_case_to_card( $ranked_case, false );
			$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];
			$order_id = absint( $order['id'] ?? 0 );

			if ( empty( $card ) || $order_id <= 0 || isset( $seen_orders[ $order_id ] ) ) {
				continue;
			}

			$seen_orders[ $order_id ] = true;
			$candidates[] = $card;

			if ( count( $candidates ) >= 5 ) {
				break;
			}
		}

		return array_values( array_filter( $candidates ) );
	}

	private static function score_operations_attention_case( $topic, $order, $attention_context = [] ) {
		$topic     = sanitize_key( $topic );
		$age_hours = absint( $order['age_hours'] ?? 0 );
		$status    = sanitize_key( $order['status'] ?? '' );
		$total     = (float) ( $order['total_raw'] ?? $order['total'] ?? 0 );
		$score     = 0.0;
		$age_bonus = min( 168, $age_hours );
		$weights   = [
			'pending_payment_older_than_24h' => 920,
			'high_value_open_orders'         => 890,
			'processing_older_than_72h'      => 820,
			'on_hold_older_than_48h'         => 760,
			'recent_failed_orders'           => 700,
		];

		$score += (float) ( $weights[ $topic ] ?? 500 );
		$score += (float) $age_bonus;
		$score += min( 120.0, max( 0.0, $total / 10 ) );

		if ( 'pending' === $status ) {
			$score += 20;
		} elseif ( 'processing' === $status ) {
			$score += 15;
		} elseif ( 'on-hold' === $status ) {
			$score += 10;
		} elseif ( 'failed' === $status ) {
			$score += 5;
		}

		if ( ! empty( $attention_context ) && $topic === sanitize_key( $attention_context['top_topic'] ?? '' ) ) {
			$score += 25;
		}

		if ( $age_hours > 2160 ) {
			$score -= 60;
		}

		return $score;
	}

	private static function map_operations_attention_ranked_case_to_card( $ranked_case, $include_queue_action ) {
		$topic = sanitize_key( $ranked_case['topic'] ?? '' );
		$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];

		if ( empty( $topic ) || empty( $order ) ) {
			return [];
		}

		$actions = [
			[
				'label'   => self::get_operations_attention_primary_action_label( $topic ),
				'command' => sanitize_text_field( $ranked_case['primary_command'] ?? '' ),
			],
		];

		$details_command = 'Show order ' . absint( $order['id'] ?? 0 ) . ' details';

		if ( sanitize_text_field( $ranked_case['primary_command'] ?? '' ) !== $details_command ) {
			$actions[] = [
				'label'   => __( 'Open order details', 'yoro-ai-core' ),
				'command' => $details_command,
			];
		}

		if ( $include_queue_action ) {
			$actions[] = [
				'label'   => __( 'Find related orders', 'yoro-ai-core' ),
				'command' => 'Find orders by identity for order ' . absint( $order['id'] ?? 0 ),
			];
		}

		return [
			'topic'   => $topic,
			'title'   => sprintf(
				/* translators: %s: Value generated from store data. */
				__( 'Start with order #%s', 'yoro-ai-core' ),
				sanitize_text_field( $order['number'] ?? $order['id'] ?? '' )
			),
			'meta'    => trim(
				implode(
					' · ',
					array_filter(
						[
							sanitize_text_field( $order['customer_name'] ?? $order['billing_email'] ?? '' ),
							self::get_operations_attention_topic_meta_label( $topic ),
							sanitize_text_field( $order['status'] ?? '' ),
						]
					)
				)
			),
			'badge'   => absint( $order['age_hours'] ?? 0 ) . 'h',
			'message' => self::build_operations_attention_case_message( $topic, $ranked_case ),
			'reason'  => self::build_operations_attention_case_reason( $topic, $ranked_case ),
			'actions' => array_values( array_filter( $actions ) ),
		];
	}

	private static function get_operations_attention_primary_command( $topic, $order ) {
		$order_id = absint( $order['id'] ?? 0 );
		return $order_id > 0 ? 'Show order ' . $order_id . ' details' : '';
	}

	private static function get_operations_attention_primary_action_label( $topic ) {
		return __( 'Review order details', 'yoro-ai-core' );
	}

	private static function get_operations_attention_sequence_label( $topic ) {
		switch ( sanitize_key( $topic ) ) {
			case 'pending_payment_older_than_24h':
				return __( 'Review the next pending payment case', 'yoro-ai-core' );
			case 'processing_older_than_72h':
				return __( 'Review the next aging processing case', 'yoro-ai-core' );
			case 'on_hold_older_than_48h':
				return __( 'Review the next on-hold case', 'yoro-ai-core' );
			case 'recent_failed_orders':
				return __( 'Review the next failed order', 'yoro-ai-core' );
			case 'high_value_open_orders':
				return __( 'Review the next high-value open order', 'yoro-ai-core' );
			default:
				return __( 'Review the next attention case', 'yoro-ai-core' );
		}
	}

	private static function get_operations_attention_sequence_command( $topic, $order ) {
		return self::get_operations_attention_primary_command( $topic, $order );
	}

	private static function get_operations_attention_topic_meta_label( $topic ) {
		switch ( sanitize_key( $topic ) ) {
			case 'pending_payment_older_than_24h':
				return __( 'Pending payment', 'yoro-ai-core' );
			case 'processing_older_than_72h':
				return __( 'Aging processing', 'yoro-ai-core' );
			case 'on_hold_older_than_48h':
				return __( 'On-hold follow-up', 'yoro-ai-core' );
			case 'recent_failed_orders':
				return __( 'Failed checkout', 'yoro-ai-core' );
			case 'high_value_open_orders':
				return __( 'High-value open', 'yoro-ai-core' );
			default:
				return __( 'Attention case', 'yoro-ai-core' );
		}
	}

	private static function build_operations_attention_case_message( $topic, $ranked_case ) {
		$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];
		$age_hours = absint( $order['age_hours'] ?? 0 );
		$total = sanitize_text_field( trim( (string) ( $order['total'] ?? '' ) . ' ' . (string) ( $order['currency'] ?? '' ) ) );

		switch ( sanitize_key( $topic ) ) {
			case 'pending_payment_older_than_24h':
				/* translators: %s: Value generated from store data. */
				return sprintf( __( 'Currently pending; created %d hours ago. Time in this status is unknown.', 'yoro-ai-core' ), $age_hours );
			case 'processing_older_than_72h':
				/* translators: %s: Value generated from store data. */
				return sprintf( __( 'Currently processing; created %d hours ago. Time in this status is unknown.', 'yoro-ai-core' ), $age_hours );
			case 'on_hold_older_than_48h':
				/* translators: %s: Value generated from store data. */
				return sprintf( __( 'Currently on hold; created %d hours ago. Time in this status is unknown.', 'yoro-ai-core' ), $age_hours );
			case 'recent_failed_orders':
				return __( 'A recent failed checkout is still visible here and may need a quick recovery pass.', 'yoro-ai-core' );
			case 'high_value_open_orders':
				/* translators: %s: Value generated from store data. */
				return $total ? sprintf( __( 'This open order is worth %s and deserves a direct check while it is still unresolved.', 'yoro-ai-core' ), $total ) : __( 'This open order deserves a direct check while it is still unresolved.', 'yoro-ai-core' );
			default:
				return sanitize_text_field( $ranked_case['queue_description'] ?? __( 'This order still needs attention.', 'yoro-ai-core' ) );
		}
	}

	private static function build_operations_attention_case_reason( $topic, $ranked_case ) {
		$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];
		$age_hours = absint( $order['age_hours'] ?? 0 );

		switch ( sanitize_key( $topic ) ) {
			case 'pending_payment_older_than_24h':
				return __( 'Inspect this pending order before deciding whether payment follow-up is needed.', 'yoro-ai-core' );
			case 'processing_older_than_72h':
				return __( 'Inspect fulfillment evidence; created-age does not establish a delay.', 'yoro-ai-core' );
			case 'on_hold_older_than_48h':
				return __( 'Use this when older on-hold orders are likely to need an explicit decision instead of waiting longer.', 'yoro-ai-core' );
			case 'recent_failed_orders':
				return __( 'Use this when you want a quick recovery check on checkout problems before they go cold.', 'yoro-ai-core' );
			case 'high_value_open_orders':
				return __( 'Use this when you want the highest commercial exposure checked before lower-value backlog.', 'yoro-ai-core' );
			default:
				return $age_hours > 0
					/* translators: %s: Value generated from store data. */
					? sprintf( __( 'This order was created %d hours ago and is a candidate for review.', 'yoro-ai-core' ), $age_hours )
					: __( 'This case deserves a direct review.', 'yoro-ai-core' );
		}
	}
}
