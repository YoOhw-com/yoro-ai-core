<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Fulfillment_Service {

	public static function get_fulfillment_queue( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is not active.', 'yoro-agent-core' ) );
		}

		$limit = isset( $params['limit'] ) ? absint( $params['limit'] ) : 5;
		$limit = min( max( $limit, 1 ), 20 );

		$queues = [
			YoRo_Orders_Read_Service::fulfillment_build_filter_queue(
				'processing_without_tracking',
				__( 'Processing without tracking', 'yoro-agent-core' ),
				__( 'Shippable processing orders without a known shipment tracking signal.', 'yoro-agent-core' ),
				$limit,
				function( $order ) {
					return $order instanceof WC_Order
						&& YoRo_Orders_Read_Service::fulfillment_order_requires_shipping( $order )
						&& ! YoRo_Orders_Read_Service::fulfillment_order_has_tracking( $order );
				},
				__( 'No tracking number detected.', 'yoro-agent-core' )
			),
			YoRo_Orders_Read_Service::fulfillment_build_filter_queue(
				'processing_older_than_48h',
				__( 'Processing older than 48h', 'yoro-agent-core' ),
				__( 'Shippable processing orders that have been waiting more than 48 hours.', 'yoro-agent-core' ),
				$limit,
				function( $order ) {
					return $order instanceof WC_Order
						&& YoRo_Orders_Read_Service::fulfillment_order_requires_shipping( $order )
						&& YoRo_Orders_Read_Service::fulfillment_order_is_older_than_hours( $order, 48 );
				},
				__( 'Processing order has been waiting for fulfillment review for more than 48 hours.', 'yoro-agent-core' )
			),
			YoRo_Orders_Read_Service::fulfillment_build_filter_queue(
				'missing_shipping_address',
				__( 'Missing shipping address', 'yoro-agent-core' ),
				__( 'Shippable processing orders with incomplete shipping address fields.', 'yoro-agent-core' ),
				$limit,
				function( $order ) {
					return $order instanceof WC_Order
						&& YoRo_Orders_Read_Service::fulfillment_order_requires_shipping( $order )
						&& YoRo_Orders_Read_Service::fulfillment_order_has_incomplete_shipping_address( $order );
				},
				__( 'Shipping address appears incomplete.', 'yoro-agent-core' )
			),
			YoRo_Orders_Read_Service::fulfillment_build_filter_queue(
				'recent_ready_to_fulfill',
				__( 'Recent ready to fulfill', 'yoro-agent-core' ),
				__( 'Recent shippable processing orders with tracking and complete shipping details.', 'yoro-agent-core' ),
				$limit,
				function( $order ) {
					return $order instanceof WC_Order && YoRo_Orders_Read_Service::fulfillment_order_is_ready_to_fulfill( $order );
				},
				__( 'Tracked processing order looks ready for the next fulfillment step.', 'yoro-agent-core' )
			),
		];
		$queues = self::rank_fulfillment_queue_orders( $queues );

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
		$fulfillment_context  = self::build_fulfillment_orchestrator_context( $queues );
		$priority_items       = self::build_fulfillment_priority_items( $queues, $fulfillment_context );
		$case_rankings        = self::build_fulfillment_case_rankings( $queues, $fulfillment_context );
		$recommended_sequence = self::build_fulfillment_recommended_sequence( $case_rankings, $fulfillment_context );
		$next_case            = self::build_fulfillment_next_recommended_case( $case_rankings );
		$batch_candidates     = self::build_fulfillment_batch_draft_candidates( $case_rankings );

		return [
			'success'              => true,
			'action'               => 'operations.get_fulfillment_queue',
			'summary'              => sprintf(
				'Fulfillment queue: %d items across %d queues.',
				$total_items,
				count( $queues )
			),
			'message'              => sprintf(
				'Fulfillment queue: %d items across %d queues.',
				$total_items,
				count( $queues )
			),
			'count'                => $total_items,
			'read_only'            => true,
			'undoable'             => false,
			'undo_plan'            => [],
			'data'                 => [],
			'meta'                 => [
				'limit_per_queue'        => $limit,
				'tracking_meta_detected' => true,
				'changes_store'          => false,
			],
			'processed'            => [],
			'skipped'              => [],
			'errors'               => [],
			'queues'               => $queues,
			'orders'               => $orders,
			'limit_per_queue'      => $limit,
			'fulfillment_context'  => $fulfillment_context,
			'priority_items'       => array_slice( $priority_items, 0, 3 ),
			'case_rankings'        => array_slice( $case_rankings, 0, 10 ),
			'recommended_sequence' => array_slice( $recommended_sequence, 0, 4 ),
			'next_recommended_case'=> $next_case,
			'batch_draft_candidates' => $batch_candidates,
			'result_type'          => 'fulfillment_queue',
			'display_type'         => 'fulfillment_queue',
		];
	}

	private static function build_fulfillment_orchestrator_context( $queues ) {
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
			'top_topic'      => $top_topic,
			'top_count'      => $top_count,
			'total_items'    => array_sum( $counts ),
			'queues_tracked' => count( $queues ),
			'tracking_count' => absint( $counts['processing_without_tracking'] ?? 0 ),
			'aging_count'    => absint( $counts['processing_older_than_48h'] ?? 0 ),
			'address_count'  => absint( $counts['missing_shipping_address'] ?? 0 ),
			'ready_count'    => absint( $counts['recent_ready_to_fulfill'] ?? 0 ),
		];
	}

	private static function build_fulfillment_priority_items( $queues, $fulfillment_context ) {
		$items     = [];
		$queue_map = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' !== $key ) {
				$queue_map[ $key ] = $queue;
			}
		}

		if ( ! empty( $queue_map['processing_without_tracking']['count'] ) ) {
			$items[] = [
				'topic'   => 'processing_without_tracking',
				'score'   => absint( $queue_map['processing_without_tracking']['count'] ?? 0 ) + 200,
				'tone'    => 'warning',
				'title'   => __( 'Missing tracking is still the sharpest shipping risk', 'yoro-agent-core' ),
				'message' => sanitize_text_field( $queue_map['processing_without_tracking']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['processing_older_than_48h']['count'] ) ) {
			$items[] = [
				'topic'   => 'processing_older_than_48h',
				'score'   => absint( $queue_map['processing_older_than_48h']['count'] ?? 0 ),
				'tone'    => 'watch',
				'title'   => __( 'Older shippable processing orders still need review', 'yoro-agent-core' ),
				'message' => sanitize_text_field( $queue_map['processing_older_than_48h']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['missing_shipping_address']['count'] ) ) {
			$items[] = [
				'topic'   => 'missing_shipping_address',
				'score'   => absint( $queue_map['missing_shipping_address']['count'] ?? 0 ) + 50,
				'tone'    => 'warning',
				'title'   => __( 'Some shippable orders still have address gaps', 'yoro-agent-core' ),
				'message' => sanitize_text_field( $queue_map['missing_shipping_address']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['recent_ready_to_fulfill']['count'] ) ) {
			$items[] = [
				'topic'   => 'recent_ready_to_fulfill',
				'score'   => absint( $queue_map['recent_ready_to_fulfill']['count'] ?? 0 ),
				'tone'    => 'positive',
				'title'   => __( 'Recent tracked orders are ready for the next fulfillment pass', 'yoro-agent-core' ),
				'message' => sanitize_text_field( $queue_map['recent_ready_to_fulfill']['description'] ?? '' ),
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

	private static function rank_fulfillment_queue_orders( $queues ) {
		foreach ( $queues as $queue_index => $queue ) {
			$topic = sanitize_key( $queue['key'] ?? '' );

			if ( empty( $queue['orders'] ) || ! is_array( $queue['orders'] ) ) {
				$queues[ $queue_index ]['orders'] = [];
				continue;
			}

			foreach ( $queue['orders'] as $order_index => $order ) {
				$queues[ $queue_index ]['orders'][ $order_index ]['case_priority_score'] = self::score_fulfillment_case( $topic, $order );
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

	private static function build_fulfillment_case_rankings( $queues, $fulfillment_context ) {
		$rankings = [];

		foreach ( $queues as $queue ) {
			$topic  = sanitize_key( $queue['key'] ?? '' );
			$orders = isset( $queue['orders'] ) && is_array( $queue['orders'] ) ? $queue['orders'] : [];

			foreach ( $orders as $order ) {
				$primary_command = sanitize_text_field( $order['suggested_command'] ?? '' );

				if ( '' === $topic || '' === $primary_command ) {
					continue;
				}

				$rankings[] = [
					'topic'             => $topic,
					'queue_label'       => sanitize_text_field( $queue['label'] ?? '' ),
					'queue_description' => sanitize_text_field( $queue['description'] ?? '' ),
					'order'             => $order,
					'primary_command'   => $primary_command,
					'score'             => (float) self::score_fulfillment_case( $topic, $order, $fulfillment_context ),
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

	private static function score_fulfillment_case( $topic, $order, $fulfillment_context = [] ) {
		$topic        = sanitize_key( $topic );
		$age_hours    = absint( $order['age_hours'] ?? 0 );
		$score        = 0.0;
		$age_bonus    = min( 120, $age_hours );
		$queue_weight = [
			'processing_without_tracking' => 980,
			'missing_shipping_address'    => 900,
			'processing_older_than_48h'   => 840,
			'recent_ready_to_fulfill'     => 700,
		];

		$score += (float) ( $queue_weight[ $topic ] ?? 500 );
		$score += (float) $age_bonus;

		if ( ! empty( $order['tracking_detected'] ) ) {
			$score -= 30;
		}

		if ( ! empty( $order['shipping_method'] ) ) {
			$score += 10;
		}

		if ( ! empty( $fulfillment_context ) && $topic === sanitize_key( $fulfillment_context['top_topic'] ?? '' ) ) {
			$score += 25;
		}

		if ( $age_hours > 720 ) {
			$score -= 60;
		}

		return $score;
	}

	private static function build_fulfillment_recommended_sequence( $case_rankings, $fulfillment_context ) {
		$sequence  = [];
		$seen_topics = [];

		foreach ( $case_rankings as $ranked_case ) {
			$topic = sanitize_key( $ranked_case['topic'] ?? '' );

			if ( '' === $topic || isset( $seen_topics[ $topic ] ) ) {
				continue;
			}

			$seen_topics[ $topic ] = true;
			$sequence[] = [
				'kind'    => 'view',
				'topic'   => $topic,
				'label'   => self::get_fulfillment_sequence_label( $topic ),
				'command' => self::get_fulfillment_sequence_command( $topic, $ranked_case ),
				'reason'  => self::build_fulfillment_case_reason( $topic ),
			];

			if ( count( $sequence ) >= 3 ) {
				break;
			}
		}

		$sequence[] = [
			'kind'    => 'view',
			'topic'   => 'customer_service_review',
			'label'   => __( 'Check customer follow-up load', 'yoro-agent-core' ),
			'command' => 'Show customer service queue',
			'reason'  => __( 'Use this last if you want to coordinate shipping issues with the customer reply queue.', 'yoro-agent-core' ),
		];

		return $sequence;
	}

	private static function build_fulfillment_next_recommended_case( $case_rankings ) {
		if ( empty( $case_rankings[0] ) || ! is_array( $case_rankings[0] ) ) {
			return [];
		}

		$ranked_case = $case_rankings[0];
		$topic = sanitize_key( $ranked_case['topic'] ?? '' );
		$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];
		$ship_to = trim(
			implode(
				', ',
				array_filter(
					[
						sanitize_text_field( $order['shipping_city'] ?? '' ),
						sanitize_text_field( $order['shipping_country'] ?? '' ),
					]
				)
			)
		);

		return [
			'topic'   => $topic,
			'title'   => sprintf(
				/* translators: %s: order number */
				__( 'Start with order #%s', 'yoro-agent-core' ),
				sanitize_text_field( $order['number'] ?? $order['id'] ?? '' )
			),
			'meta'    => trim(
				implode(
					' · ',
					array_filter(
						[
							$ship_to,
							sanitize_text_field( $ranked_case['queue_label'] ?? '' ),
							sanitize_text_field( $order['shipping_method'] ?? '' ),
						]
					)
				)
			),
			'badge'   => sprintf( '%sh', absint( $order['age_hours'] ?? 0 ) ),
			'message' => sanitize_text_field( $order['fulfillment_reason'] ?? $ranked_case['queue_description'] ?? '' ),
			'reason'  => self::build_fulfillment_case_reason( $topic ),
			'actions' => array_values(
				array_filter(
					[
						[
							'label'   => self::get_fulfillment_primary_action_label( $topic ),
							'command' => sanitize_text_field( $ranked_case['primary_command'] ?? '' ),
						],
						[
							'label'   => __( 'Open order details', 'yoro-agent-core' ),
							'command' => 'Show order ' . absint( $order['id'] ?? 0 ) . ' details',
						],
						[
							'label'   => __( 'Open fulfillment queue', 'yoro-agent-core' ),
							'command' => 'Show fulfillment queue',
						],
					],
					function( $item ) {
						return ! empty( $item['command'] );
					}
				)
			),
		];
	}

	private static function build_fulfillment_batch_draft_candidates( $case_rankings ) {
		$candidates = [];
		$seen_topics = [];
		$seen_orders = [];

		foreach ( $case_rankings as $ranked_case ) {
			$topic = sanitize_key( $ranked_case['topic'] ?? '' );
			$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];
			$order_id = absint( $order['id'] ?? 0 );

			if ( '' === $topic || $order_id <= 0 || isset( $seen_topics[ $topic ] ) || isset( $seen_orders[ $order_id ] ) ) {
				continue;
			}

			$seen_topics[ $topic ] = true;
			$seen_orders[ $order_id ] = true;
			$candidates[] = self::map_fulfillment_ranked_case_to_card( $ranked_case );

			if ( count( $candidates ) >= 5 ) {
				return array_values( array_filter( $candidates ) );
			}
		}

		foreach ( $case_rankings as $ranked_case ) {
			$card = self::map_fulfillment_ranked_case_to_card( $ranked_case );
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

	private static function map_fulfillment_ranked_case_to_card( $ranked_case ) {
		$topic = sanitize_key( $ranked_case['topic'] ?? '' );
		$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];

		if ( '' === $topic || empty( $order ) ) {
			return [];
		}

		$ship_to = trim(
			implode(
				', ',
				array_filter(
					[
						sanitize_text_field( $order['shipping_city'] ?? '' ),
						sanitize_text_field( $order['shipping_country'] ?? '' ),
					]
				)
			)
		);

		return [
			'topic'   => $topic,
			'title'   => sprintf(
				/* translators: %s: order number */
				__( 'Order #%s', 'yoro-agent-core' ),
				sanitize_text_field( $order['number'] ?? $order['id'] ?? '' )
			),
			'meta'    => trim(
				implode(
					' · ',
					array_filter(
						[
							sanitize_text_field( $ranked_case['queue_label'] ?? '' ),
							$ship_to,
							sanitize_text_field( $order['shipping_method'] ?? '' ),
						]
					)
				)
			),
			'badge'   => sprintf( '%sh', absint( $order['age_hours'] ?? 0 ) ),
			'message' => sanitize_text_field( $order['fulfillment_reason'] ?? $ranked_case['queue_description'] ?? '' ),
			'actions' => [
				[
					'label'   => self::get_fulfillment_primary_action_label( $topic ),
					'command' => sanitize_text_field( $ranked_case['primary_command'] ?? '' ),
				],
				[
					'label'   => __( 'Open details', 'yoro-agent-core' ),
					'command' => 'Show order ' . absint( $order['id'] ?? 0 ) . ' details',
				],
			],
		];
	}

	private static function build_fulfillment_case_reason( $topic ) {
		switch ( sanitize_key( $topic ) ) {
			case 'processing_without_tracking':
				return __( 'Missing tracking is the clearest shipping risk to resolve first.', 'yoro-agent-core' );
			case 'missing_shipping_address':
				return __( 'An incomplete shipping address can block physical shipment outright.', 'yoro-agent-core' );
			case 'processing_older_than_48h':
				return __( 'This shippable order has been waiting long enough to deserve a manual fulfillment check.', 'yoro-agent-core' );
			case 'recent_ready_to_fulfill':
				return __( 'This is a good candidate if you want a quick tracked fulfillment win next.', 'yoro-agent-core' );
			default:
				return __( 'This case is the clearest next fulfillment follow-up from the queue.', 'yoro-agent-core' );
		}
	}

	private static function get_fulfillment_primary_action_label( $topic ) {
		switch ( sanitize_key( $topic ) ) {
			case 'processing_without_tracking':
				return __( 'Preview delay email', 'yoro-agent-core' );
			case 'recent_ready_to_fulfill':
				return __( 'Preview fulfillment check', 'yoro-agent-core' );
			default:
				return __( 'Preview next follow-up', 'yoro-agent-core' );
		}
	}

	private static function get_fulfillment_sequence_label( $topic ) {
		switch ( sanitize_key( $topic ) ) {
			case 'processing_without_tracking':
				return __( 'Prepare the next delay email', 'yoro-agent-core' );
			case 'missing_shipping_address':
				return __( 'Check the next shipping address issue', 'yoro-agent-core' );
			case 'processing_older_than_48h':
				return __( 'Review the next aging shippable order', 'yoro-agent-core' );
			case 'recent_ready_to_fulfill':
				return __( 'Review the next tracked order ready to move', 'yoro-agent-core' );
			default:
				return __( 'Review the next fulfillment follow-up', 'yoro-agent-core' );
		}
	}

	private static function get_fulfillment_sequence_command( $topic, $ranked_case ) {
		$topic = sanitize_key( $topic );

		if ( 'processing_older_than_48h' === $topic ) {
			return 'Show processing orders';
		}

		return sanitize_text_field( $ranked_case['primary_command'] ?? '' );
	}
}
