<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Customer_Service {

	public static function get_customer_service_queue( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$limit = isset( $params['limit'] ) ? absint( $params['limit'] ) : 5;
		$limit = min( max( $limit, 1 ), 20 );

		$queues = [
			YoRo_Orders_Read_Service::customer_service_build_queue(
				'pending_payment_followup',
				__( 'Pending payment follow-up', 'yoro-ai-core' ),
				__( 'Customers with pending payment orders older than 24 hours.', 'yoro-ai-core' ),
				[
					'status'       => [ 'pending' ],
					'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-24 hours' ),
				],
				$limit,
				__( 'Payment follow-up may be useful.', 'yoro-ai-core' )
			),
			YoRo_Orders_Read_Service::customer_service_build_queue(
				'on_hold_followup',
				__( 'On-hold follow-up', 'yoro-ai-core' ),
				__( 'Customers with on-hold orders older than 48 hours.', 'yoro-ai-core' ),
				[
					'status'       => [ 'on-hold' ],
					'date_created' => '<=' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-48 hours' ),
				],
				$limit,
				__( 'Order is still on hold and may need clarification.', 'yoro-ai-core' )
			),
			YoRo_Orders_Read_Service::customer_service_build_queue(
				'failed_checkout_followup',
				__( 'Failed checkout follow-up', 'yoro-ai-core' ),
				__( 'Recent failed orders that may need customer support.', 'yoro-ai-core' ),
				[
					'status'       => [ 'failed' ],
					'date_created' => YoRo_Orders_Read_Service::briefing_build_relative_cutoff( '-7 days' ) . '...' . YoRo_Orders_Read_Service::briefing_build_relative_cutoff( 'now' ),
				],
				$limit,
				__( 'Recent failed order may need checkout support.', 'yoro-ai-core' )
			),
			YoRo_Orders_Read_Service::customer_service_build_saved_email_drafts_queue( $limit ),
		];
		foreach ( $queues as $queue ) {
			if ( is_wp_error( $queue ) ) {
				return $queue;
			}
		}
		$queues = self::rank_customer_service_queue_orders( $queues );

		$total_items = array_sum( array_column( $queues, 'count' ) );
		$service_context      = self::build_customer_service_orchestrator_context( $queues );
		$priority_items       = self::build_customer_service_priority_items( $queues, $service_context );
		$case_rankings        = self::build_customer_service_case_rankings( $queues, $service_context );
		$orders = array_column( $case_rankings, 'order' );
		$queue_evidence = YoRo_Orders_Read_Service::queue_evidence( $queues, $orders );
		$recommended_sequence = self::build_customer_service_recommended_sequence( $case_rankings, $service_context );
		$next_case            = self::build_customer_service_next_recommended_case( $case_rankings );
		$batch_candidates     = self::build_customer_service_batch_draft_candidates( $case_rankings );
		$queue_count          = count( $queues );
		$visible_case_count   = 0;

		foreach ( $queues as $queue ) {
			$visible_case_count += count( is_array( $queue['orders'] ?? null ) ? $queue['orders'] : [] );
		}

		$visible_unique_order_count = count( $orders );
		$queue_summary        = sprintf(
			/* translators: 1: queue match count, 2: queue count */
			__( 'Customer service queue: %1$d queue memberships across %2$d queues.', 'yoro-ai-core' ),
			$total_items,
			$queue_count
		);

		return [
			'success'              => true,
			'action'               => 'customers.get_service_queue',
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
				'limit_per_queue' => $limit,
				'auto_send'       => false,
			],
			'processed'            => [],
			'skipped'              => [],
			'errors'               => [],
			'queues'               => $queues,
			'orders'               => $orders,
			'limit_per_queue'      => $limit,
			'service_context'      => $service_context,
			'priority_items'       => array_slice( $priority_items, 0, 3 ),
			'case_rankings'        => array_slice( $case_rankings, 0, 10 ),
			'recommended_sequence' => array_slice( $recommended_sequence, 0, 4 ),
			'next_recommended_case'=> $next_case,
			'batch_draft_candidates' => $batch_candidates,
			'result_type'          => 'customer_service_queue',
			'display_type'         => 'customer_service_queue',
		];
	}

	private static function build_customer_service_orchestrator_context( $queues ) {
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
			'top_topic'      => $top_topic,
			'top_count'      => $top_count,
			'queues_tracked' => count( $queues ),
			'total_items'    => array_sum( $counts ),
			'draft_count'    => absint( $counts['saved_email_drafts'] ?? 0 ),
			'on_hold_count'  => absint( $counts['on_hold_followup'] ?? 0 ),
			'payment_count'  => absint( $counts['pending_payment_followup'] ?? 0 ),
			'failed_count'   => absint( $counts['failed_checkout_followup'] ?? 0 ),
		];
	}

	private static function build_customer_service_priority_items( $queues, $service_context ) {
		$items     = [];
		$queue_map = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$queue_map[ $key ] = $queue;
		}

		if ( ! empty( $queue_map['on_hold_followup']['count'] ) ) {
			$items[] = [
				'topic'   => 'on_hold_followup',
				'score'   => absint( $queue_map['on_hold_followup']['count'] ?? 0 ),
				'tone'    => 'warning',
				'title'   => __( 'On-hold follow-ups are carrying most of the reply load', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $queue_map['on_hold_followup']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['pending_payment_followup']['count'] ) ) {
			$items[] = [
				'topic'   => 'pending_payment_followup',
				'score'   => absint( $queue_map['pending_payment_followup']['count'] ?? 0 ),
				'tone'    => 'watch',
				'title'   => __( 'Pending payment follow-ups still need attention', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $queue_map['pending_payment_followup']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['saved_email_drafts']['count'] ) ) {
			$items[] = [
				'topic'   => 'saved_email_drafts',
				'score'   => absint( $queue_map['saved_email_drafts']['count'] ?? 0 ),
				'tone'    => 'positive',
				'title'   => __( 'Saved drafts are already waiting for review', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $queue_map['saved_email_drafts']['description'] ?? '' ),
			];
		}

		if ( ! empty( $queue_map['failed_checkout_followup']['count'] ) ) {
			$items[] = [
				'topic'   => 'failed_checkout_followup',
				'score'   => absint( $queue_map['failed_checkout_followup']['count'] ?? 0 ),
				'tone'    => 'neutral',
				'title'   => __( 'Failed checkouts may need manual outreach', 'yoro-ai-core' ),
				'message' => sanitize_text_field( $queue_map['failed_checkout_followup']['description'] ?? '' ),
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

	private static function build_customer_service_recommended_sequence( $case_rankings, $service_context ) {
		$sequence   = [];
		$seen_topics = [];

		foreach ( $case_rankings as $ranked_case ) {
			$topic = sanitize_key( $ranked_case['topic'] ?? '' );
			$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];

			if ( '' === $topic || isset( $seen_topics[ $topic ] ) ) {
				continue;
			}

			$seen_topics[ $topic ] = true;
			$sequence[] = [
				'kind'    => 'saved_email_drafts' === $topic ? 'action' : 'view',
				'topic'   => $topic,
				'label'   => self::get_customer_service_sequence_label( $topic ),
				'command' => self::get_customer_service_sequence_command( $topic, $order ),
				'reason'  => self::build_customer_service_case_reason( $topic, $ranked_case ),
			];

			if ( count( $sequence ) >= 4 ) {
				break;
			}
		}

		return $sequence;
	}

	private static function build_customer_service_next_recommended_case( $case_rankings ) {
		if ( empty( $case_rankings[0] ) || ! is_array( $case_rankings[0] ) ) {
			return [];
		}

		return self::map_customer_service_ranked_case_to_card( $case_rankings[0], true );
	}

	private static function build_customer_service_batch_draft_candidates( $case_rankings ) {
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
			$candidates[]          = self::map_customer_service_ranked_case_to_card( $ranked_case, false );

			if ( count( $candidates ) >= 5 ) {
				return array_values( array_filter( $candidates ) );
			}
		}

		foreach ( $case_rankings as $ranked_case ) {
			$card = self::map_customer_service_ranked_case_to_card( $ranked_case, false );
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

	private static function rank_customer_service_queue_orders( $queues ) {
		foreach ( $queues as $queue_index => $queue ) {
			$topic = sanitize_key( $queue['key'] ?? '' );

			if ( empty( $queue['orders'] ) || ! is_array( $queue['orders'] ) ) {
				$queues[ $queue_index ]['orders'] = [];
				continue;
			}

			foreach ( $queue['orders'] as $order_index => $order ) {
				$queues[ $queue_index ]['orders'][ $order_index ]['case_priority_score'] = self::score_customer_service_case( $topic, $order );
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

	private static function build_customer_service_case_rankings( $queues, $service_context ) {
		$rankings = [];

		foreach ( $queues as $queue ) {
			$topic  = sanitize_key( $queue['key'] ?? '' );
			$orders = isset( $queue['orders'] ) && is_array( $queue['orders'] ) ? $queue['orders'] : [];

			foreach ( $orders as $order ) {
				$primary_command = self::get_customer_service_primary_command( $topic, $order );

				if ( '' === $primary_command ) {
					continue;
				}

				$rankings[] = [
					'topic'             => $topic,
					'queue_label'       => sanitize_text_field( $queue['label'] ?? '' ),
					'queue_description' => sanitize_text_field( $queue['description'] ?? '' ),
					'order'             => $order,
					'primary_command'   => $primary_command,
					'score'             => (float) self::score_customer_service_case( $topic, $order, $service_context ),
				];
			}
		}

		return YoRo_Orders_Read_Service::queue_case_rankings( $rankings );
	}

	private static function score_customer_service_case( $topic, $order, $service_context = [] ) {
		$topic        = sanitize_key( $topic );
		$age_hours    = absint( $order['age_hours'] ?? 0 );
		$draft_count  = absint( $order['draft_count'] ?? 0 );
		$status       = sanitize_key( $order['status'] ?? '' );
		$score        = 0.0;
		$age_bonus    = min( 120, $age_hours );
		$queue_weight = [
			'saved_email_drafts'       => 820,
			'on_hold_followup'         => 980,
			'pending_payment_followup' => 840,
			'failed_checkout_followup' => 700,
		];

		$score += (float) ( $queue_weight[ $topic ] ?? 500 );
		$score += (float) $age_bonus;
		$score += (float) ( $draft_count * 70 );

		if ( 'on-hold' === $status ) {
			$score += 35;
		} elseif ( 'pending' === $status ) {
			$score += 20;
		} elseif ( 'failed' === $status ) {
			$score += 10;
		}

		if ( ! empty( $service_context ) && $topic === sanitize_key( $service_context['top_topic'] ?? '' ) ) {
			$score += 25;
		}

		if ( $age_hours > 720 ) {
			$score -= 80;
		}

		if ( $age_hours > 2160 ) {
			$score -= 120;
		}

		return $score;
	}

	private static function map_customer_service_ranked_case_to_card( $ranked_case, $include_queue_action ) {
		$topic = sanitize_key( $ranked_case['topic'] ?? '' );
		$order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];

		if ( empty( $topic ) || empty( $order ) ) {
			return [];
		}

		$actions = [
			[
				'label'   => self::get_customer_service_primary_action_label( $topic ),
				'command' => sanitize_text_field( $ranked_case['primary_command'] ?? '' ),
			],
			[
				'label'   => __( 'Open order details', 'yoro-ai-core' ),
				'command' => 'Show order ' . absint( $order['id'] ?? 0 ) . ' details',
			],
		];

		if ( $include_queue_action ) {
			$actions[] = [
				'label'   => __( 'Open customer queue', 'yoro-ai-core' ),
				'command' => 'Show customer service queue',
			];
		}

		return [
			'topic'   => $topic,
			'title'   => sprintf(
				/* translators: %s: order number */
				__( 'Start with order #%s', 'yoro-ai-core' ),
				sanitize_text_field( $order['number'] ?? $order['id'] ?? '' )
			),
			'meta'    => trim(
				implode(
					' · ',
					array_filter(
						[
							sanitize_text_field( $order['customer_name'] ?? $order['billing_email'] ?? '' ),
							sanitize_text_field( $ranked_case['queue_label'] ?? '' ),
							sanitize_text_field( $order['status'] ?? '' ),
						]
					)
				)
			),
			'badge'   => sprintf( '%sh', absint( $order['age_hours'] ?? 0 ) ),
			'message' => self::build_customer_service_case_message( $topic, $ranked_case ),
			'reason'  => self::build_customer_service_case_reason( $topic, $ranked_case ),
			'score'   => (float) ( $ranked_case['score'] ?? 0 ),
			'actions' => array_values(
				array_filter(
					$actions,
					function( $item ) {
						return ! empty( $item['command'] );
					}
				)
			),
		];
	}

	private static function get_customer_service_sequence_label( $topic ) {
		switch ( sanitize_key( $topic ) ) {
			case 'saved_email_drafts':
				return __( 'Review the next saved draft', 'yoro-ai-core' );
			case 'on_hold_followup':
				return __( 'Inspect the next on-hold case', 'yoro-ai-core' );
			case 'pending_payment_followup':
				return __( 'Handle the next payment follow-up', 'yoro-ai-core' );
			case 'failed_checkout_followup':
				return __( 'Handle the next failed checkout follow-up', 'yoro-ai-core' );
			default:
				return __( 'Handle the next customer follow-up', 'yoro-ai-core' );
		}
	}

	private static function get_customer_service_sequence_command( $topic, $order ) {
		return self::get_customer_service_primary_command( $topic, $order );
	}

	private static function build_customer_service_case_message( $topic, $ranked_case = [] ) {
		$order       = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];
		$draft_count = absint( $order['draft_count'] ?? 0 );
		$age_hours   = absint( $order['age_hours'] ?? 0 );

		switch ( sanitize_key( $topic ) ) {
			case 'on_hold_followup':
				return $age_hours >= 72
					? sprintf(
						/* translators: %s: age in hours */
						__( 'Currently on hold; created %sh ago. Time in this status and reply needs are unknown.', 'yoro-ai-core' ),
						$age_hours
					)
					: __( 'This order is on hold. Inspect its details before deciding whether a reply is needed.', 'yoro-ai-core' );
			case 'saved_email_drafts':
				return $draft_count > 1
					? sprintf(
						/* translators: %d: draft count */
						_n(
							'%d saved draft is already attached to this order.',
							'%d saved drafts are already attached to this order.',
							$draft_count,
							'yoro-ai-core'
						),
						$draft_count
					)
					: __( 'A saved draft is already attached to this order and ready for review.', 'yoro-ai-core' );
			case 'pending_payment_followup':
				return $age_hours >= 48
					? sprintf(
						/* translators: %s: age in hours */
						__( 'Currently pending; created %sh ago. Time in this status is unknown.', 'yoro-ai-core' ),
						$age_hours
					)
					: __( 'This order is pending. Inspect its payment details before deciding whether to follow up.', 'yoro-ai-core' );
			case 'failed_checkout_followup':
				return __( 'An order created recently has failed status. This does not establish fraud or the cause of failure.', 'yoro-ai-core' );
			default:
				return sanitize_text_field( $order['followup_reason'] ?? $ranked_case['queue_description'] ?? '' );
		}
	}

	private static function build_customer_service_case_reason( $topic, $ranked_case = [] ) {
		return __( 'Inspect the observed order facts before deciding whether a customer reply is needed. Available actions require the normal permission and confirmation checks.', 'yoro-ai-core' );
	}

	private static function get_customer_service_primary_action_label( $topic ) {
		return __( 'Review order details', 'yoro-ai-core' );
	}

	private static function get_customer_service_primary_command( $topic, $order ) {
		$order_id = absint( $order['id'] ?? 0 );
		return $order_id > 0 ? 'Show order ' . $order_id . ' details' : '';
	}

}
