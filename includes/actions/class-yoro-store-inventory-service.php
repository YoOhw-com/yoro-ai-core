<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Inventory_Service {

	public static function get_inventory_queue( $params ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is not active.', 'yoro-agent-core' ) );
		}

		$limit = isset( $params['limit'] ) ? absint( $params['limit'] ) : 5;
		$limit = min( max( $limit, 1 ), 20 );
		$focus = ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview';
		$stock_management_enabled = self::is_stock_management_enabled();
		$inventory_mode = $stock_management_enabled ? 'tracked' : 'status_only';

		$queues = [
			YoRo_Orders_Read_Service::inventory_build_query_queue(
				'out_of_stock',
				__( 'Out of stock', 'yoro-agent-core' ),
				__( 'Published products currently marked out of stock.', 'yoro-agent-core' ),
				[
					'stock_status' => 'outofstock',
				],
				$limit
			),
			YoRo_Orders_Read_Service::inventory_build_query_queue(
				'on_backorder',
				__( 'On backorder', 'yoro-agent-core' ),
				__( 'Products currently available on backorder.', 'yoro-agent-core' ),
				[
					'stock_status' => 'onbackorder',
				],
				$limit
			),
		];

		if ( $stock_management_enabled ) {
			$queues[] = YoRo_Orders_Read_Service::inventory_build_query_queue(
				'low_stock',
				__( 'Low stock', 'yoro-agent-core' ),
				__( 'Products with managed stock at or below the low-stock threshold.', 'yoro-agent-core' ),
				[
					'manage_stock' => true,
					'stock_status' => 'instock',
				],
				$limit,
				function( $product ) {
					return $product instanceof WC_Product && YoRo_Orders_Read_Service::inventory_product_is_low_stock( $product );
				}
			);

			$queues[] = YoRo_Orders_Read_Service::inventory_build_query_queue(
				'unmanaged_stock_selling',
				__( 'Selling without stock management', 'yoro-agent-core' ),
				__( 'Published products that are in stock but do not manage stock quantity.', 'yoro-agent-core' ),
				[
					'stock_status' => 'instock',
				],
				$limit,
				function( $product ) {
					return $product instanceof WC_Product && ! $product->managing_stock();
				}
			);

			$queues[] = YoRo_Orders_Read_Service::inventory_build_recent_processing_queue( $limit );
		}

		$total_items = 0;
		$products    = [];

		foreach ( $queues as $queue ) {
			$total_items += absint( $queue['count'] ?? 0 );

			foreach ( $queue['products'] as $product ) {
				$products[ $product['id'] ] = $product;
			}
		}

		$products             = array_values( $products );
		$inventory_context    = self::build_inventory_orchestrator_context( $queues, $focus, $stock_management_enabled, $inventory_mode );
		$queues               = self::rank_inventory_queue_products( $queues, $inventory_context );
		$priority_items       = self::build_inventory_priority_items( $queues, $inventory_context );
		$case_rankings        = self::build_inventory_case_rankings( $queues, $inventory_context );
		$next_case            = self::build_inventory_next_recommended_case( $case_rankings, $inventory_context );
		$batch_candidates     = self::build_inventory_batch_review_candidates( $case_rankings );
		$recommended_sequence = self::build_inventory_recommended_sequence( $queues, $inventory_context, $case_rankings );
		$inventory_actions    = self::build_inventory_actions( $queues, $inventory_context );

		return [
			'success'              => true,
			'action'               => 'products.get_inventory_queue',
			'summary'              => sprintf(
				'Inventory queue: %d items across %d queues.',
				$total_items,
				count( $queues )
			),
			'message'              => sprintf(
				'Inventory queue: %d items across %d queues.',
				$total_items,
				count( $queues )
			),
			'count'                => $total_items,
			'read_only'            => true,
			'undoable'             => false,
			'undo_plan'            => [],
			'data'                 => [],
			'meta'                 => [
				'limit_per_queue' => $limit,
				'focus'           => $focus,
				'inventory_mode'  => $inventory_mode,
				'stock_management_enabled' => $stock_management_enabled,
				'changes_store'   => false,
			],
			'processed'            => [],
			'skipped'              => [],
			'errors'               => [],
			'queues'               => $queues,
			'products'             => $products,
			'limit_per_queue'      => $limit,
			'focus'                => $focus,
			'inventory_mode'       => $inventory_mode,
			'stock_management_enabled' => $stock_management_enabled,
			'inventory_context'    => $inventory_context,
			'priority_items'       => array_slice( $priority_items, 0, 3 ),
			'case_rankings'        => array_slice( $case_rankings, 0, 10 ),
			'next_recommended_case'=> $next_case,
			'batch_draft_candidates' => $batch_candidates,
			'recommended_sequence' => array_slice( $recommended_sequence, 0, 4 ),
			'inventory_actions'    => array_slice( $inventory_actions, 0, 4 ),
			'result_type'          => 'inventory_queue',
			'display_type'         => 'inventory_queue',
		];
	}

	private static function build_inventory_orchestrator_context( $queues, $focus, $stock_management_enabled, $inventory_mode ) {
		$counts = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' !== $key ) {
				$counts[ $key ] = absint( $queue['count'] ?? 0 );
			}
		}

		arsort( $counts );
		$top_topic = (string) key( $counts );
		$top_count = (int) reset( $counts );

		return [
			'top_topic'              => $top_topic,
			'top_count'              => $top_count,
			'total_items'            => array_sum( $counts ),
			'queues_tracked'         => count( $queues ),
			'focus'                  => sanitize_key( $focus ),
			'inventory_mode'         => sanitize_key( $inventory_mode ),
			'stock_management_enabled' => (bool) $stock_management_enabled,
			'out_of_stock_count'     => absint( $counts['out_of_stock'] ?? 0 ),
			'low_stock_count'        => absint( $counts['low_stock'] ?? 0 ),
			'backorder_count'        => absint( $counts['on_backorder'] ?? 0 ),
			'unmanaged_count'        => absint( $counts['unmanaged_stock_selling'] ?? 0 ),
			'recent_processing_count'=> absint( $counts['low_stock_in_processing_orders'] ?? 0 ),
		];
	}

	private static function build_inventory_priority_items( $queues, $inventory_context ) {
		$items     = [];
		$queue_map = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' !== $key ) {
				$queue_map[ $key ] = $queue;
			}
		}

		if ( ! empty( $queue_map['out_of_stock']['count'] ) ) {
			$items[] = [
				'topic'   => 'out_of_stock',
				'score'   => absint( $queue_map['out_of_stock']['count'] ?? 0 ) + 200,
				'tone'    => 'warning',
				'title'   => __( 'Stockouts are the clearest inventory risk right now', 'yoro-agent-core' ),
				'message' => __( 'Published products are already marked out of stock.', 'yoro-agent-core' ),
			];
		}

		if ( ! empty( $queue_map['on_backorder']['count'] ) ) {
			$items[] = [
				'topic'   => 'on_backorder',
				'score'   => absint( $queue_map['on_backorder']['count'] ?? 0 ) + 100,
				'tone'    => 'watch',
				'title'   => __( 'Backorders are still building behind demand', 'yoro-agent-core' ),
				'message' => __( 'Products are still selling while stock is already behind demand.', 'yoro-agent-core' ),
			];
		}

		if ( ! empty( $queue_map['low_stock']['count'] ) ) {
			$items[] = [
				'topic'   => 'low_stock',
				'score'   => absint( $queue_map['low_stock']['count'] ?? 0 ),
				'tone'    => 'neutral',
				'title'   => __( 'Low-stock items should be queued for replenishment', 'yoro-agent-core' ),
				'message' => __( 'Managed stock is already at or below the low-stock threshold.', 'yoro-agent-core' ),
			];
		}

		if ( ! empty( $queue_map['low_stock_in_processing_orders']['count'] ) ) {
			$items[] = [
				'topic'   => 'low_stock_in_processing_orders',
				'score'   => absint( $queue_map['low_stock_in_processing_orders']['count'] ?? 0 ),
				'tone'    => 'positive',
				'title'   => __( 'Recent processing demand is already pointing to the next restock needs', 'yoro-agent-core' ),
				'message' => __( 'Current processing orders are already leaning on the remaining stock.', 'yoro-agent-core' ),
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

	private static function build_inventory_recommended_sequence( $queues, $inventory_context, $case_rankings ) {
		$sequence = [];
		$top_case = ! empty( $case_rankings[0] ) && is_array( $case_rankings[0] ) ? $case_rankings[0] : [];
		$top_topic = sanitize_key( $top_case['topic'] ?? $inventory_context['top_topic'] ?? '' );
		$inventory_mode = sanitize_key( $inventory_context['inventory_mode'] ?? '' );

		$sequence[] = [
			'kind'    => 'action',
			'topic'   => 'inventory_review',
			'label'   => 'status_only' === $inventory_mode
				? __( 'Review the next stock-status issue', 'yoro-agent-core' )
				: __( 'Prepare the next inventory review', 'yoro-agent-core' ),
			'command' => 'Prepare next inventory review',
			'reason'  => self::build_inventory_sequence_reason( $top_topic, $inventory_mode ),
		];

		if ( ! empty( $inventory_context['out_of_stock_count'] ) || ! empty( $inventory_context['backorder_count'] ) || ! empty( $inventory_context['recent_processing_count'] ) ) {
			$sequence[] = [
				'kind'    => 'view',
				'topic'   => 'fulfillment_review',
				'label'   => __( 'Check fulfillment pressure next', 'yoro-agent-core' ),
				'command' => 'What seems blocked in fulfillment?',
				'reason'  => __( 'Use this next to confirm whether inventory gaps are feeding into fulfillment delays.', 'yoro-agent-core' ),
			];
		}

		$sequence[] = [
			'kind'    => 'view',
			'topic'   => 'operations_overview',
			'label'   => __( 'Review the broader store picture', 'yoro-agent-core' ),
			'command' => 'Can you give me a monthly store picture?',
			'reason'  => __( 'Use this after the inventory pass when you want the broader operating context.', 'yoro-agent-core' ),
		];

		return $sequence;
	}

	private static function build_inventory_sequence_reason( $top_topic, $inventory_mode ) {
		switch ( $top_topic ) {
			case 'out_of_stock':
				return 'status_only' === $inventory_mode
					? __( 'Start here when you want the clearest stock-status exception first.', 'yoro-agent-core' )
					: __( 'Start here when you want the clearest stockout to review before demand slips further.', 'yoro-agent-core' );
			case 'on_backorder':
				return __( 'Start here when you want to confirm which backordered item should be reviewed before it feeds deeper into fulfillment pressure.', 'yoro-agent-core' );
			case 'low_stock_in_processing_orders':
				return __( 'Start here when you want the inventory item that is already under live order demand.', 'yoro-agent-core' );
			case 'low_stock':
				return __( 'Start here when you want the cleanest low-stock review before it turns into a stockout.', 'yoro-agent-core' );
			case 'unmanaged_stock_selling':
				return __( 'Start here when you want to review where stock visibility is weakest before restock decisions become guesswork.', 'yoro-agent-core' );
			default:
				return __( 'Start here when you want the cleanest inventory priority read first.', 'yoro-agent-core' );
		}
	}

	private static function build_inventory_actions( $queues, $inventory_context ) {
		$actions = [
			[
				'topic'   => 'inventory_review',
				'label'   => __( 'Review restock priorities', 'yoro-agent-core' ),
				'command' => 'What should we restock first?',
			],
			[
				'topic'   => 'fulfillment_review',
				'label'   => __( 'Check fulfillment pressure', 'yoro-agent-core' ),
				'command' => 'What seems blocked in fulfillment?',
			],
			[
				'topic'   => 'operations_overview',
				'label'   => __( 'Open the store picture', 'yoro-agent-core' ),
				'command' => 'Can you give me a monthly store picture?',
			],
		];

		if ( empty( $inventory_context['stock_management_enabled'] ) ) {
			$actions[0]['label'] = __( 'Review stock status risks', 'yoro-agent-core' );
			$actions[0]['command'] = 'What inventory items are out of stock or on backorder?';
		}

		return $actions;
	}

	private static function is_stock_management_enabled() {
		return 'yes' === get_option( 'woocommerce_manage_stock', 'yes' );
	}

	private static function rank_inventory_queue_products( $queues, $inventory_context ) {
		foreach ( $queues as $queue_index => $queue ) {
			$topic = sanitize_key( $queue['key'] ?? '' );

			if ( empty( $queue['products'] ) || ! is_array( $queue['products'] ) ) {
				$queues[ $queue_index ]['products'] = [];
				continue;
			}

			foreach ( $queue['products'] as $product_index => $product ) {
				$queues[ $queue_index ]['products'][ $product_index ]['case_priority_score'] = self::score_inventory_case( $topic, $product, $inventory_context );
				$queues[ $queue_index ]['products'][ $product_index ]['queue_topic']          = $topic;
			}

			usort(
				$queues[ $queue_index ]['products'],
				function( $a, $b ) {
					return (float) ( $b['case_priority_score'] ?? 0 ) <=> (float) ( $a['case_priority_score'] ?? 0 );
				}
			);
		}

		return $queues;
	}

	private static function build_inventory_case_rankings( $queues, $inventory_context ) {
		$rankings = [];

		foreach ( $queues as $queue ) {
			$topic = sanitize_key( $queue['key'] ?? '' );
			$products = isset( $queue['products'] ) && is_array( $queue['products'] ) ? $queue['products'] : [];

			foreach ( $products as $product ) {
				$product_id = absint( $product['id'] ?? 0 );

				if ( $product_id <= 0 ) {
					continue;
				}

				$rankings[] = [
					'topic'             => $topic,
					'queue_label'       => sanitize_text_field( $queue['label'] ?? '' ),
					'queue_description' => sanitize_text_field( $queue['description'] ?? '' ),
					'product'           => $product,
					'primary_command'   => self::get_inventory_primary_command( $topic, $product, $inventory_context ),
					'score'             => (float) self::score_inventory_case( $topic, $product, $inventory_context ),
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

	private static function build_inventory_next_recommended_case( $case_rankings, $inventory_context ) {
		if ( empty( $case_rankings[0] ) || ! is_array( $case_rankings[0] ) ) {
			return [];
		}

		return self::map_inventory_ranked_case_to_card( $case_rankings[0], true, $inventory_context );
	}

	private static function build_inventory_batch_review_candidates( $case_rankings ) {
		$candidates = [];
		$seen_topics = [];
		$seen_products = [];

		foreach ( $case_rankings as $ranked_case ) {
			$topic = sanitize_key( $ranked_case['topic'] ?? '' );
			$product = isset( $ranked_case['product'] ) && is_array( $ranked_case['product'] ) ? $ranked_case['product'] : [];
			$product_id = absint( $product['id'] ?? 0 );

			if ( '' === $topic || $product_id <= 0 || isset( $seen_topics[ $topic ] ) || isset( $seen_products[ $product_id ] ) ) {
				continue;
			}

			$seen_topics[ $topic ] = true;
			$seen_products[ $product_id ] = true;
			$candidates[] = self::map_inventory_ranked_case_to_card( $ranked_case, false, [] );

			if ( count( $candidates ) >= 4 ) {
				return array_values( array_filter( $candidates ) );
			}
		}

		foreach ( $case_rankings as $ranked_case ) {
			$product = isset( $ranked_case['product'] ) && is_array( $ranked_case['product'] ) ? $ranked_case['product'] : [];
			$product_id = absint( $product['id'] ?? 0 );
			$card = self::map_inventory_ranked_case_to_card( $ranked_case, false, [] );

			if ( empty( $card ) || $product_id <= 0 || isset( $seen_products[ $product_id ] ) ) {
				continue;
			}

			$seen_products[ $product_id ] = true;
			$candidates[] = $card;

			if ( count( $candidates ) >= 4 ) {
				break;
			}
		}

		return array_values( array_filter( $candidates ) );
	}

	private static function map_inventory_ranked_case_to_card( $ranked_case, $is_start_here, $inventory_context ) {
		$topic = sanitize_key( $ranked_case['topic'] ?? '' );
		$product = isset( $ranked_case['product'] ) && is_array( $ranked_case['product'] ) ? $ranked_case['product'] : [];

		if ( '' === $topic || empty( $product ) ) {
			return [];
		}

		$product_id = absint( $product['id'] ?? 0 );
		$stock_summary = self::format_inventory_stock_summary( $product, $inventory_context );
		$meta_parts = array_filter(
			[
				sanitize_text_field( $ranked_case['queue_label'] ?? '' ),
				sanitize_text_field( $product['sku'] ?? '' ),
				$stock_summary,
			]
		);
		$actions = [
			[
				'label'   => self::get_inventory_primary_action_label( $topic, $inventory_context ),
				'command' => sanitize_text_field( $ranked_case['primary_command'] ?? '' ),
			],
		];

		if ( $is_start_here && ! empty( $product['edit_url'] ) ) {
			$actions[] = [
				'label' => __( 'Open product', 'yoro-agent-core' ),
				'href'  => esc_url_raw( $product['edit_url'] ),
			];
		}

		return [
			'topic'   => $topic,
			'title'   => $is_start_here
				? sprintf( __( 'Start with %s', 'yoro-agent-core' ), sanitize_text_field( $product['name'] ?? '#' . $product_id ) )
				: sanitize_text_field( $product['name'] ?? '#' . $product_id ),
			'meta'    => implode( ' · ', $meta_parts ),
			'badge'   => self::format_inventory_badge( $topic, $product ),
			'message' => self::build_inventory_case_message( $topic, $product, $inventory_context ),
			'reason'  => self::build_inventory_case_reason( $topic, $product, $inventory_context ),
			'actions' => array_values(
				array_filter(
					$actions,
					function( $item ) {
						return ! empty( $item['command'] ) || ! empty( $item['href'] );
					}
				)
			),
		];
	}

	private static function get_inventory_primary_command( $topic, $product, $inventory_context ) {
		if ( 'low_stock_in_processing_orders' === $topic ) {
			return 'Show fulfillment queue';
		}

		if ( 'status_only' === sanitize_key( $inventory_context['inventory_mode'] ?? '' ) ) {
			return 'Prepare next inventory review';
		}

		return 'Prepare next inventory review';
	}

	private static function get_inventory_primary_action_label( $topic, $inventory_context ) {
		if ( 'low_stock_in_processing_orders' === $topic ) {
			return __( 'Check fulfillment impact', 'yoro-agent-core' );
		}

		if ( 'status_only' === sanitize_key( $inventory_context['inventory_mode'] ?? '' ) ) {
			return __( 'Review stock-status issue', 'yoro-agent-core' );
		}

		return __( 'Prepare inventory review', 'yoro-agent-core' );
	}

	private static function build_inventory_case_message( $topic, $product, $inventory_context ) {
		$product_name = sanitize_text_field( $product['name'] ?? __( 'This product', 'yoro-agent-core' ) );
		$open_order_count = absint( $product['open_order_count'] ?? 0 );
		$inventory_mode = sanitize_key( $inventory_context['inventory_mode'] ?? '' );

		switch ( $topic ) {
			case 'out_of_stock':
				return 'status_only' === $inventory_mode
					? sprintf( __( '%s is marked out of stock and is the clearest stock-status exception to review first.', 'yoro-agent-core' ), $product_name )
					: sprintf( __( '%s is already out of stock and should be reviewed before demand slips further.', 'yoro-agent-core' ), $product_name );
			case 'on_backorder':
				return sprintf( __( '%s is already on backorder and may now be feeding directly into fulfillment pressure.', 'yoro-agent-core' ), $product_name );
			case 'low_stock_in_processing_orders':
				return sprintf(
					/* translators: %d: open order count */
					_n(
						'Recent processing demand is already consuming the remaining stock for this item across %d open order.',
						'Recent processing demand is already consuming the remaining stock for this item across %d open orders.',
						$open_order_count,
						'yoro-agent-core'
					),
					$open_order_count
				);
			case 'low_stock':
				return __( 'Stock is already at or below the low-stock threshold and should be reviewed before it becomes a stockout.', 'yoro-agent-core' );
			case 'unmanaged_stock_selling':
				return __( 'This product is still selling without quantity tracking, so its next inventory risk is harder to see.', 'yoro-agent-core' );
			default:
				return sanitize_text_field( $product['stock_status'] ?? '' );
		}
	}

	private static function build_inventory_case_reason( $topic, $product, $inventory_context ) {
		$inventory_mode = sanitize_key( $inventory_context['inventory_mode'] ?? '' );

		switch ( $topic ) {
			case 'out_of_stock':
				return 'status_only' === $inventory_mode
					? __( 'Use this first when you want the fastest stock-status review without relying on quantity tracking.', 'yoro-agent-core' )
					: __( 'Use this first when you want the clearest stockout to act on before reviewing softer inventory signals.', 'yoro-agent-core' );
			case 'on_backorder':
				return __( 'Use this next when you want to confirm whether customer demand is already outrunning available stock.', 'yoro-agent-core' );
			case 'low_stock_in_processing_orders':
				return __( 'Use this next when you want to connect inventory risk directly to the current fulfillment backlog.', 'yoro-agent-core' );
			case 'low_stock':
				return __( 'Use this next when you want to catch replenishment issues before they turn into stockouts.', 'yoro-agent-core' );
			case 'unmanaged_stock_selling':
				return __( 'Use this if you want to review where inventory visibility is weakest before deeper restock decisions.', 'yoro-agent-core' );
			default:
				return __( 'Use this if you want the cleanest next inventory check.', 'yoro-agent-core' );
		}
	}

	private static function format_inventory_stock_summary( $product, $inventory_context ) {
		$stock_status = sanitize_key( $product['stock_status'] ?? '' );
		$quantity = $product['stock_quantity'] ?? '';
		$threshold = absint( $product['low_stock_threshold'] ?? 0 );

		if ( '' === $quantity || null === $quantity ) {
			return 'status_only' === sanitize_key( $inventory_context['inventory_mode'] ?? '' )
				? sanitize_text_field( str_replace( '_', ' ', $stock_status ) )
				: __( 'Quantity not tracked', 'yoro-agent-core' );
		}

		$summary = sprintf( __( 'Stock %d', 'yoro-agent-core' ), (int) $quantity );

		if ( ! empty( $inventory_context['stock_management_enabled'] ) && $threshold > 0 ) {
			$summary .= sprintf( __( ' / low %d', 'yoro-agent-core' ), $threshold );
		}

		return $summary;
	}

	private static function format_inventory_badge( $topic, $product ) {
		if ( 'low_stock_in_processing_orders' === $topic ) {
			return sprintf( '%dx', absint( $product['open_order_count'] ?? 0 ) );
		}

		if ( isset( $product['stock_quantity'] ) && '' !== $product['stock_quantity'] && null !== $product['stock_quantity'] ) {
			return sprintf( '%d', (int) $product['stock_quantity'] );
		}

		return strtoupper( sanitize_text_field( $product['stock_status'] ?? '' ) );
	}

	private static function score_inventory_case( $topic, $product, $inventory_context ) {
		$base_scores = [
			'out_of_stock'                  => 500,
			'on_backorder'                  => 420,
			'low_stock_in_processing_orders'=> 340,
			'low_stock'                     => 260,
			'unmanaged_stock_selling'       => 120,
		];
		$score = (float) ( $base_scores[ $topic ] ?? 100 );

		if ( sanitize_key( $inventory_context['top_topic'] ?? '' ) === $topic ) {
			$score += 40;
		}

		$open_order_count = absint( $product['open_order_count'] ?? 0 );
		$score += min( 10, $open_order_count ) * 20;

		if ( isset( $product['stock_quantity'] ) && '' !== $product['stock_quantity'] && null !== $product['stock_quantity'] ) {
			$quantity = (int) $product['stock_quantity'];
			$threshold = absint( $product['low_stock_threshold'] ?? 0 );

			if ( $quantity <= 0 ) {
				$score += 40;
			} elseif ( $threshold > 0 && $quantity <= $threshold ) {
				$score += max( 10, ( $threshold - $quantity + 1 ) * 6 );
			}
		}

		if ( 'outofstock' === sanitize_key( $product['stock_status'] ?? '' ) ) {
			$score += 30;
		} elseif ( 'onbackorder' === sanitize_key( $product['stock_status'] ?? '' ) ) {
			$score += 20;
		}

		return $score;
	}
}
