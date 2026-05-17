<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Operations_Service {

	public static function get_operations_summary( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is not active.', 'yoro-agent-core' ) );
		}

		$window_key = YoRo_Orders_Read_Service::briefing_normalize_operations_window_key( $params['window'] ?? 'today' );
		$focus      = ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview';

		return YoRo_Action_Cache::remember(
			'operations_summary',
			[
				'window' => $window_key,
				'focus'  => $focus,
			],
			function() use ( $window_key, $focus ) {
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
					$window_status_counts[ $status ] = YoRo_Orders_Read_Service::briefing_count_orders(
						[
							'status'       => [ $status ],
							'date_created' => $window['query'],
						]
					);
				}

				$backlog_counts = [
					'pending'    => YoRo_Orders_Read_Service::briefing_count_orders( [ 'status' => [ 'pending' ] ] ),
					'processing' => YoRo_Orders_Read_Service::briefing_count_orders( [ 'status' => [ 'processing' ] ] ),
					'on-hold'    => YoRo_Orders_Read_Service::briefing_count_orders( [ 'status' => [ 'on-hold' ] ] ),
				];

				$attention_counts = [
					'pending_over_24h'    => YoRo_Orders_Read_Service::briefing_count_orders(
						[
							'status'       => [ 'pending' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-24 hours' ),
						]
					),
					'processing_over_72h' => YoRo_Orders_Read_Service::briefing_count_orders(
						[
							'status'       => [ 'processing' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-72 hours' ),
						]
					),
					'on_hold_over_48h'    => YoRo_Orders_Read_Service::briefing_count_orders(
						[
							'status'       => [ 'on-hold' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-48 hours' ),
						]
					),
				];

				$window_total    = array_sum( $window_status_counts );
				$backlog_total   = array_sum( $backlog_counts );
				$attention_total = array_sum( $attention_counts );
				$recent_orders   = [];

				foreach (
					wc_get_orders(
						[
							'limit'   => 5,
							'status'  => [ 'pending', 'processing', 'on-hold' ],
							'orderby' => 'date',
							'order'   => 'DESC',
						]
					) as $order
				) {
					if ( $order instanceof WC_Order ) {
						$recent_orders[] = YoRo_Order_Presenter::format_order_summary( $order );
					}
				}

				return [
					'success'              => true,
					'action'               => 'operations.get_summary',
					'summary'              => sprintf(
						'Operations summary for %1$s: %2$d new orders, %3$d open backlog, %4$d attention items.',
						$window['label'],
						$window_total,
						$backlog_total,
						$attention_total
					),
					'message'              => sprintf(
						'Operations summary for %1$s: %2$d new orders, %3$d open backlog, %4$d attention items.',
						$window['label'],
						$window_total,
						$backlog_total,
						$attention_total
					),
					'count'                => $window_total,
					'read_only'            => true,
					'undoable'             => false,
					'undo_plan'            => [],
					'data'                 => [],
					'meta'                 => [],
					'processed'            => [],
					'skipped'              => [],
					'errors'               => [],
					'window'               => [
						'key'   => $window_key,
						'label' => $window['label'],
						'start' => $window['start'],
						'end'   => $window['end'],
					],
					'focus'                => $focus,
					'window_order_count'   => $window_total,
					'backlog_total'        => $backlog_total,
					'attention_total'      => $attention_total,
					'window_status_counts' => $window_status_counts,
					'backlog_counts'       => $backlog_counts,
					'attention_counts'     => $attention_counts,
					'recent_orders'        => $recent_orders,
					'result_type'          => 'operations_summary',
					'display_type'         => 'operations_summary',
				];
			}
		);
	}

	public static function get_operations_attention_queue( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is not active.', 'yoro-agent-core' ) );
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
			],
			function() use ( $limit, $high_value_threshold ) {
				$queues = [
					YoRo_Orders_Read_Service::operations_build_attention_queue(
						'pending_payment_older_than_24h',
						__( 'Pending payment older than 24h', 'yoro-agent-core' ),
						__( 'Orders still pending payment after 24 hours.', 'yoro-agent-core' ),
						[
							'status'       => [ 'pending' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-24 hours' ),
						],
						$limit
					),
					YoRo_Orders_Read_Service::operations_build_attention_queue(
						'processing_older_than_72h',
						__( 'Processing older than 72h', 'yoro-agent-core' ),
						__( 'Paid/open orders that may need fulfillment follow-up.', 'yoro-agent-core' ),
						[
							'status'       => [ 'processing' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-72 hours' ),
						],
						$limit
					),
					YoRo_Orders_Read_Service::operations_build_attention_queue(
						'on_hold_older_than_48h',
						__( 'On-hold older than 48h', 'yoro-agent-core' ),
						__( 'On-hold orders that have not moved for two days.', 'yoro-agent-core' ),
						[
							'status'       => [ 'on-hold' ],
							'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-48 hours' ),
						],
						$limit
					),
					YoRo_Orders_Read_Service::operations_build_attention_queue(
						'recent_failed_orders',
						__( 'Recent failed orders', 'yoro-agent-core' ),
						__( 'Failed checkout/payment attempts from the last 7 days.', 'yoro-agent-core' ),
						[
							'status'       => [ 'failed' ],
							'date_created' => YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-7 days' ) . '...' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( 'now' ),
						],
						$limit
					),
					YoRo_Orders_Read_Service::operations_build_high_value_open_queue( $limit, $high_value_threshold ),
				];
				$queues = self::rank_operations_attention_queue_orders( $queues );

				$total_items = 0;
				$orders      = [];

				foreach ( $queues as $queue ) {
					$total_items += absint( $queue['count'] ?? 0 );

					foreach ( $queue['orders'] as $order ) {
						$orders[ $order['id'] ] = $order;
					}
				}

				$orders               = array_values( $orders );
				usort(
					$orders,
					function( $a, $b ) {
						return (float) ( $b['case_priority_score'] ?? 0 ) <=> (float) ( $a['case_priority_score'] ?? 0 );
					}
				);
				$attention_context    = self::build_operations_attention_orchestrator_context( $queues, $high_value_threshold );
				$priority_items       = self::build_operations_attention_priority_items( $queues, $attention_context );
				$case_rankings        = self::build_operations_attention_case_rankings( $queues, $attention_context );
				$recommended_sequence = self::build_operations_attention_recommended_sequence( $case_rankings, $attention_context );
				$next_case            = self::build_operations_attention_next_recommended_case( $case_rankings );
				$batch_candidates     = self::build_operations_attention_batch_candidates( $case_rankings );

				return [
					'success'              => true,
					'action'               => 'operations.get_attention_queue',
					'summary'              => sprintf(
						'Operations attention queue: %d items across %d queues.',
						$total_items,
						count( $queues )
					),
					'message'              => sprintf(
						'Operations attention queue: %d items across %d queues.',
						$total_items,
						count( $queues )
					),
					'count'                => $total_items,
					'read_only'            => true,
					'undoable'             => false,
					'undo_plan'            => [],
					'data'                 => [],
					'meta'                 => [
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
				'title'   => __( 'Pending payment orders are still stacking up', 'yoro-agent-core' ),
				'message' => sanitize_text_field( $queue_map['pending_payment_older_than_24h']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['processing_older_than_72h']['count'] ) ) {
			$items[] = [
				'topic'   => 'processing_older_than_72h',
				'score'   => absint( $queue_map['processing_older_than_72h']['count'] ?? 0 ) + 90,
				'tone'    => 'watch',
				'title'   => __( 'Aging processing orders still need operational follow-through', 'yoro-agent-core' ),
				'message' => sanitize_text_field( $queue_map['processing_older_than_72h']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['on_hold_older_than_48h']['count'] ) ) {
			$items[] = [
				'topic'   => 'on_hold_older_than_48h',
				'score'   => absint( $queue_map['on_hold_older_than_48h']['count'] ?? 0 ) + 60,
				'tone'    => 'watch',
				'title'   => __( 'On-hold orders are still waiting for a decision', 'yoro-agent-core' ),
				'message' => sanitize_text_field( $queue_map['on_hold_older_than_48h']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['high_value_open_orders']['count'] ) ) {
			$items[] = [
				'topic'   => 'high_value_open_orders',
				'score'   => absint( $queue_map['high_value_open_orders']['count'] ?? 0 ) + 80,
				'tone'    => 'positive',
				'title'   => __( 'High-value open orders deserve a direct check', 'yoro-agent-core' ),
				'message' => sanitize_text_field( $queue_map['high_value_open_orders']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['recent_failed_orders']['count'] ) ) {
			$items[] = [
				'topic'   => 'recent_failed_orders',
				'score'   => absint( $queue_map['recent_failed_orders']['count'] ?? 0 ) + 40,
				'tone'    => 'neutral',
				'title'   => __( 'Recent failed orders are worth a quick recovery pass', 'yoro-agent-core' ),
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
			'label'   => __( 'Review today\'s operating picture', 'yoro-agent-core' ),
			'command' => 'Show operations summary for today',
			'reason'  => __( 'Use this last when you want the broader store picture after the queue-specific checks.', 'yoro-agent-core' ),
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
					return (float) ( $b['case_priority_score'] ?? 0 ) <=> (float) ( $a['case_priority_score'] ?? 0 );
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

		usort(
			$rankings,
			function( $a, $b ) {
				return (float) ( $b['score'] ?? 0 ) <=> (float) ( $a['score'] ?? 0 );
			}
		);

		return $rankings;
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
				'label'   => __( 'Open order details', 'yoro-agent-core' ),
				'command' => $details_command,
			];
		}

		if ( $include_queue_action ) {
			$actions[] = [
				'label'   => __( 'Find related orders', 'yoro-agent-core' ),
				'command' => 'Find orders by identity for order ' . absint( $order['id'] ?? 0 ),
			];
		}

		return [
			'topic'   => $topic,
			'title'   => sprintf(
				__( 'Start with order #%s', 'yoro-agent-core' ),
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

		if ( $order_id <= 0 ) {
			return '';
		}

		if ( 'pending_payment_older_than_24h' === $topic ) {
			return 'Write email for order #' . $order_id . ' about: ' . __( 'Payment follow-up may be useful.', 'yoro-agent-core' );
		}

		if ( 'processing_older_than_72h' === $topic ) {
			return 'Preview shipping delay email for order ' . $order_id;
		}

		if ( 'on_hold_older_than_48h' === $topic ) {
			return 'Write email for order #' . $order_id . ' about: ' . __( 'Order is still on hold and may need clarification.', 'yoro-agent-core' );
		}

		if ( 'recent_failed_orders' === $topic ) {
			return 'Write email for order #' . $order_id . ' about: ' . __( 'Failed checkout may need support follow-up.', 'yoro-agent-core' );
		}

		if ( 'high_value_open_orders' === $topic ) {
			return 'Show order ' . $order_id . ' details';
		}

		return 'Show order ' . $order_id . ' details';
	}

	private static function get_operations_attention_primary_action_label( $topic ) {
		switch ( sanitize_key( $topic ) ) {
			case 'pending_payment_older_than_24h':
				return __( 'Draft payment follow-up', 'yoro-agent-core' );
			case 'processing_older_than_72h':
				return __( 'Preview delay email', 'yoro-agent-core' );
			case 'on_hold_older_than_48h':
				return __( 'Draft on-hold reply', 'yoro-agent-core' );
			case 'recent_failed_orders':
				return __( 'Draft support reply', 'yoro-agent-core' );
			case 'high_value_open_orders':
				return __( 'Open high-value order', 'yoro-agent-core' );
			default:
				return __( 'Review order', 'yoro-agent-core' );
		}
	}

	private static function get_operations_attention_sequence_label( $topic ) {
		switch ( sanitize_key( $topic ) ) {
			case 'pending_payment_older_than_24h':
				return __( 'Review the next pending payment case', 'yoro-agent-core' );
			case 'processing_older_than_72h':
				return __( 'Review the next aging processing case', 'yoro-agent-core' );
			case 'on_hold_older_than_48h':
				return __( 'Review the next on-hold case', 'yoro-agent-core' );
			case 'recent_failed_orders':
				return __( 'Review the next failed order', 'yoro-agent-core' );
			case 'high_value_open_orders':
				return __( 'Review the next high-value open order', 'yoro-agent-core' );
			default:
				return __( 'Review the next attention case', 'yoro-agent-core' );
		}
	}

	private static function get_operations_attention_sequence_command( $topic, $order ) {
		return self::get_operations_attention_primary_command( $topic, $order );
	}

	private static function get_operations_attention_topic_meta_label( $topic ) {
		switch ( sanitize_key( $topic ) ) {
			case 'pending_payment_older_than_24h':
				return __( 'Pending payment', 'yoro-agent-core' );
			case 'processing_older_than_72h':
				return __( 'Aging processing', 'yoro-agent-core' );
			case 'on_hold_older_than_48h':
				return __( 'On-hold follow-up', 'yoro-agent-core' );
			case 'recent_failed_orders':
				return __( 'Failed checkout', 'yoro-agent-core' );
			case 'high_value_open_orders':
				return __( 'High-value open', 'yoro-agent-core' );
			default:
				return __( 'Attention case', 'yoro-agent-core' );
		}
	}

	private static function build_operations_attention_case_message( $topic, $ranked_case ) {
		$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];
		$age_hours = absint( $order['age_hours'] ?? 0 );
		$total = sanitize_text_field( trim( (string) ( $order['total'] ?? '' ) . ' ' . (string) ( $order['currency'] ?? '' ) ) );

		switch ( sanitize_key( $topic ) ) {
			case 'pending_payment_older_than_24h':
				return sprintf( __( 'Payment is still pending after %d hours and now needs a direct review.', 'yoro-agent-core' ), $age_hours );
			case 'processing_older_than_72h':
				return sprintf( __( 'This processing order has been open for %d hours without clearing the operations queue.', 'yoro-agent-core' ), $age_hours );
			case 'on_hold_older_than_48h':
				return sprintf( __( 'This order has been sitting on hold for %d hours and may need a decision or follow-up.', 'yoro-agent-core' ), $age_hours );
			case 'recent_failed_orders':
				return __( 'A recent failed checkout is still visible here and may need a quick recovery pass.', 'yoro-agent-core' );
			case 'high_value_open_orders':
				return $total ? sprintf( __( 'This open order is worth %s and deserves a direct check while it is still unresolved.', 'yoro-agent-core' ), $total ) : __( 'This open order deserves a direct check while it is still unresolved.', 'yoro-agent-core' );
			default:
				return sanitize_text_field( $ranked_case['queue_description'] ?? __( 'This order still needs attention.', 'yoro-agent-core' ) );
		}
	}

	private static function build_operations_attention_case_reason( $topic, $ranked_case ) {
		$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];
		$age_hours = absint( $order['age_hours'] ?? 0 );

		switch ( sanitize_key( $topic ) ) {
			case 'pending_payment_older_than_24h':
				return __( 'Use this first if you want the clearest payment-delay case before the rest of the queue ages further.', 'yoro-agent-core' );
			case 'processing_older_than_72h':
				return __( 'Use this next when you want to confirm whether operations delay is turning into a fulfillment problem.', 'yoro-agent-core' );
			case 'on_hold_older_than_48h':
				return __( 'Use this when older on-hold orders are likely to need an explicit decision instead of waiting longer.', 'yoro-agent-core' );
			case 'recent_failed_orders':
				return __( 'Use this when you want a quick recovery check on checkout problems before they go cold.', 'yoro-agent-core' );
			case 'high_value_open_orders':
				return __( 'Use this when you want the highest commercial exposure checked before lower-value backlog.', 'yoro-agent-core' );
			default:
				return $age_hours > 0
					? sprintf( __( 'This case has been waiting for %d hours and deserves a direct review.', 'yoro-agent-core' ), $age_hours )
					: __( 'This case deserves a direct review.', 'yoro-agent-core' );
		}
	}
}
