<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Executor {

	public static function execute( $action_name, $params = [], $confirm = false, $preview = false, $context = [] ) {
		$action = YoRo_Action_Registry::get( $action_name );

		if ( ! $action ) {
			return new WP_Error( 'yoro_invalid_action', __( 'I could not find that store action.', 'yoro-ai-core' ) );
		}

		if ( ! current_user_can( $action['permission'] ) ) {
			return new WP_Error( 'yoro_permission_denied', __( 'You do not have permission to execute that store action.', 'yoro-ai-core' ) );
		}

		$limit_check = self::check_action_limits( $action_name, $action, $params );

		if ( is_wp_error( $limit_check ) ) {
			return $limit_check;
		}

		if ( $preview ) {
			return self::preview( $action_name, $action, $params );
		}

		$is_undo = (
			is_array( $context ) &&
			! empty( $context['is_undo'] )
		);

		if (
			! $is_undo &&
			! empty( $action['write_action'] ) &&
			! $confirm
		) {
			return self::normalize_result(
				$action_name,
				[
					'success'               => false,
					'requires_confirmation' => true,
					'action'                => $action_name,
					'risk_level'            => $action['risk_level'],
					'message'               => __( 'Please approve this change when you are ready. Nothing has changed yet.', 'yoro-ai-core' ),
					'result_type'           => 'confirmation',
					'display_type'          => 'confirmation',
					'read_only'             => true,
				],
				$action
			);
		}

		if ( is_array( $params ) ) {
			$params['_yoro_confirmed'] = ! empty( $confirm );
			$params['_yoro_preview']   = ! empty( $preview );
		}

		if ( empty( $action['callback'] ) || ! is_callable( $action['callback'] ) ) {
			return new WP_Error( 'yoro_invalid_callback', __( 'I could not execute that action because the local handler is unavailable.', 'yoro-ai-core' ) );
		}

		do_action(
			'yoro_before_action_execute',
			$action_name,
			$params,
			[
				'confirm' => $confirm,
				'preview' => $preview,
				'context' => $context,
				'action'  => $action,
			]
		);

		if (
			! empty( $context['store_context_pack'] ) &&
			is_array( $context['store_context_pack'] ) &&
			class_exists( 'YoRo_Report_Builder' )
		) {
			$result = YoRo_Report_Builder::with_store_context_pack(
				$context['store_context_pack'],
				static function() use ( $action, $params ) {
					return call_user_func( $action['callback'], $params );
				}
			);
		} else {
			$result = call_user_func( $action['callback'], $params );
		}
		$result = self::normalize_result( $action_name, $result, $action );

		if (
			! empty( $action['write_action'] ) &&
			is_array( $result ) &&
			! empty( $result['success'] )
		) {
			YoRo_Action_Cache::flush_groups(
				[
					'operations_summary',
					'operations_attention_queue',
					'fulfillment_queue',
					'inventory_queue',
				]
			);
		}

		$result = apply_filters(
			'yoro_after_action_execute',
			$result,
			$action_name,
			$params,
			[
				'confirm' => $confirm,
				'preview' => $preview,
				'context' => $context,
				'action'  => $action,
			]
		);

		return $result;
	}

	private static function preview( $action_name, $action, $params ) {
		$result = null;

		if ( ! empty( $action['preview_callback'] ) && is_callable( $action['preview_callback'] ) ) {
			$result = call_user_func( $action['preview_callback'], $params );
		} else {
			$result = [
				'action'      => $action_name,
				'params'      => $params,
				'description' => $action['description'],
			];
		}

		if ( is_wp_error( $result ) ) {
			return self::normalize_error_result( $action_name, $result, $action );
		}

		return self::normalize_result(
			$action_name,
			[
				'success'               => true,
				'preview'               => true,
				'action'                => $action_name,
				'label'                 => $action['label'],
				'description'           => $action['description'],
				'risk_level'            => $action['risk_level'],
				'write_action'          => ! empty( $action['write_action'] ),
				'requires_confirmation' => ! empty( $action['requires_confirmation'] ),
				'max_items'             => absint( $action['max_items'] ),
				'plan'                  => $result,
				'read_only'             => true,
				'result_type'           => 'preview',
				'display_type'          => 'preview',
			],
			$action
		);
	}

	private static function check_action_limits( $action_name, $action, $params ) {
		$max_items = isset( $action['max_items'] ) ? absint( $action['max_items'] ) : 1;

		if ( $max_items <= 0 ) {
			return true;
		}

		$item_count = self::count_action_items( $action_name, $params );

		if ( $item_count > $max_items ) {
			return new WP_Error(
				'yoro_action_limit_exceeded',
				sprintf(
					/* translators: 1: action name, 2: item count, 3: max items */
					__( 'Action "%1$s" cannot process %2$d items. Maximum allowed is %3$d.', 'yoro-ai-core' ),
					$action_name,
					$item_count,
					$max_items
				)
			);
		}

		return true;
	}

	private static function count_action_items( $action_name, $params ) {
		if ( isset( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			return count( $params['order_ids'] );
		}

		if ( isset( $params['note_ids'] ) && is_array( $params['note_ids'] ) ) {
			return count( $params['note_ids'] );
		}

		if ( isset( $params['statuses'] ) && is_array( $params['statuses'] ) ) {
			return count( $params['statuses'] );
		}

		if ( isset( $params['limit'] ) ) {
			return absint( $params['limit'] );
		}

		if ( isset( $params['order_id'] ) ) {
			return 1;
		}

		if ( isset( $params['product_id'] ) ) {
			return 1;
		}

		return 1;
	}

	private static function normalize_result( $action_name, $result, $action = null ) {
		$action = is_array( $action ) ? $action : YoRo_Action_Registry::get( $action_name );

		if ( is_wp_error( $result ) ) {
			return self::normalize_error_result( $action_name, $result, $action );
		}

		$is_write_action = ! empty( $action['write_action'] );

		if ( ! is_array( $result ) ) {
			return [
				'success'      => true,
				'action'       => $action_name,
				'summary'      => '',
				'message'      => '',
				'data'         => [],
				'meta'         => [],
				'processed'    => [],
				'skipped'      => [],
				'errors'       => [],
				'count'        => 0,
				'read_only'    => ! $is_write_action,
				'undoable'     => false,
				'undo_plan'    => [],
				'result_type'  => 'raw',
				'display_type' => 'raw',
				'raw'          => $result,
			];
		}

		$has_explicit_read_only = array_key_exists( 'read_only', $result );

		$result = wp_parse_args(
			$result,
			[
				'success'      => true,
				'action'       => $action_name,
				'summary'      => '',
				'message'      => '',
				'data'         => [],
				'meta'         => [],
				'processed'    => [],
				'skipped'      => [],
				'errors'       => [],
				'count'        => 0,
				'read_only'    => false,
				'undoable'     => false,
				'undo_plan'    => [],
				'result_type'  => '',
				'display_type' => '',
			]
		);

		if ( ! $has_explicit_read_only ) {
			$result['read_only'] = ! $is_write_action;
		}

		foreach ( [ 'data', 'meta', 'processed', 'skipped', 'errors' ] as $key ) {
			if ( ! is_array( $result[ $key ] ) ) {
				$result[ $key ] = [];
			}
		}

		if ( ! is_array( $result['undo_plan'] ) ) {
			$result['undo_plan'] = [];
		}

		if ( empty( $result['message'] ) && ! empty( $result['summary'] ) ) {
			$result['message'] = $result['summary'];
		}

		if ( empty( $result['count'] ) ) {
			if ( ! empty( $result['processed'] ) && is_array( $result['processed'] ) ) {
				$result['count'] = count( $result['processed'] );
			} elseif ( ! empty( $result['orders'] ) && is_array( $result['orders'] ) ) {
				$result['count'] = count( $result['orders'] );
			}
		}

		if ( ! empty( $result['undo_plan'] ) && is_array( $result['undo_plan'] ) ) {
			$result['undoable'] = true;
		}

		if ( empty( $result['result_type'] ) ) {
			if ( ! empty( $result['orders'] ) && is_array( $result['orders'] ) ) {
				$result['result_type'] = 'orders';
			} elseif ( ! empty( $result['email'] ) ) {
				$result['result_type'] = 'email';
			} elseif ( ! empty( $result['draft'] ) || ! empty( $result['drafts'] ) ) {
				$result['result_type'] = 'email_drafts';
			} elseif ( ! empty( $result['multi_action'] ) ) {
				$result['result_type'] = 'multi_action';
			} else {
				$result['result_type'] = 'generic';
			}
		}

		if ( empty( $result['display_type'] ) ) {
			$result['display_type'] = $result['result_type'];
		}

		$result = self::attach_assistant_response_metadata( $action_name, $result, $action );

		return $result;
	}

	private static function attach_assistant_response_metadata( $action_name, $result, $action = null ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$action = is_array( $action ) ? $action : YoRo_Action_Registry::get( $action_name );
		$is_write_action = ! empty( $action['write_action'] );

		if ( empty( $result['meta'] ) || ! is_array( $result['meta'] ) ) {
			$result['meta'] = [];
		}

		if ( empty( $result['assistant_summary'] ) ) {
			$result['assistant_summary'] = self::build_assistant_summary(
				$action_name,
				$result,
				$is_write_action
			);
		}

		$result = self::clean_user_facing_response_copy( $result );

		return $result;
	}

	private static function clean_user_facing_response_copy( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		foreach ( [ 'summary', 'message', 'assistant_summary' ] as $key ) {
			if ( array_key_exists( $key, $result ) ) {
				$result[ $key ] = YoRo_Response_UX_Formatter::clean_user_facing_copy( $result[ $key ] );
			}
		}

		return $result;
	}

	private static function build_assistant_summary( $action_name, $result, $is_write_action ) {
		$summary = sanitize_text_field( $result['summary'] ?? '' );

		if ( $is_write_action ) {
			return $summary;
		}

		switch ( $action_name ) {
			case 'store.get_daily_briefing':
				$window_label = sanitize_text_field( $result['window']['label'] ?? 'today' );
				$briefing_context = isset( $result['briefing_context'] ) && is_array( $result['briefing_context'] )
					? $result['briefing_context']
					: [];
				$briefing_state = sanitize_key( $briefing_context['state'] ?? '' );
				$top_topic = sanitize_key( $briefing_context['top_topic'] ?? '' );
				$window_orders = absint( $briefing_context['window_orders'] ?? 0 );
				$gross_sales = sanitize_text_field( $briefing_context['gross_sales'] ?? '0' );
				$active_customers = absint( $briefing_context['active_customers'] ?? 0 );
				$business_state = sanitize_key( $briefing_context['business_state'] ?? '' );
				$priority_items = isset( $result['priority_items'] ) && is_array( $result['priority_items'] )
					? $result['priority_items']
					: [];
				$top_priority = ! empty( $priority_items[0]['message'] )
					? sanitize_text_field( $priority_items[0]['message'] )
					: '';
				$window_phrase = self::format_window_sentence_prefix( $window_label );
				if ( 'quiet' === $business_state ) {
					$window_line = sprintf(
						/* translators: %s: window label */
						__( '%s I have not seen any new orders yet, so gross sales and active customers are still at zero.', 'yoro-ai-core' ),
						$window_phrase
					);
				} elseif ( 'soft' === $business_state ) {
					$window_line = sprintf(
						/* translators: 1: window label, 2: new orders, 3: gross sales, 4: active customers */
						__( '%1$s I am only seeing light commercial activity so far: %2$d new orders, %3$s in gross sales, and %4$d active customers.', 'yoro-ai-core' ),
						$window_phrase,
						$window_orders,
						$gross_sales,
						$active_customers
					);
				} else {
					$window_line = sprintf(
						/* translators: 1: window label, 2: new orders, 3: gross sales, 4: active customers */
						__( '%1$s I found %2$d new orders, %3$s in gross sales, and %4$d active customers.', 'yoro-ai-core' ),
						$window_phrase,
						$window_orders,
						$gross_sales,
						$active_customers
					);
				}

				switch ( $briefing_state ) {
					case 'high_pressure':
						if ( 'quiet' === $business_state || 'soft' === $business_state ) {
							return sprintf(
								/* translators: 1: window line, 2: top priority */
								__( 'Commercial activity is quiet, but the store is still carrying heavy operating pressure. %1$s %2$s', 'yoro-ai-core' ),
								$window_line,
								$top_priority
							);
						}
						return sprintf(
							/* translators: 1: window line, 2: top priority */
							__( 'The store is carrying heavy operating pressure right now. %1$s %2$s', 'yoro-ai-core' ),
							$window_line,
							$top_priority
						);

					case 'focused_pressure':
						return sprintf(
							/* translators: 1: window line, 2: top priority */
							__( 'One queue stands out as the main operational priority today. %1$s %2$s', 'yoro-ai-core' ),
							$window_line,
							$top_priority
						);

					case 'split_pressure':
						return sprintf(
							/* translators: 1: window line, 2: top priority */
							__( 'The store picture is split across more than one active pressure point. %1$s %2$s', 'yoro-ai-core' ),
							$window_line,
							$top_priority
						);

					case 'active_controlled':
						return sprintf(
							/* translators: %s: window line */
							__( 'The store looks active overall, and the main queues appear relatively controlled. %s', 'yoro-ai-core' ),
							$window_line
						);

					case 'quiet_controlled':
						return sprintf(
							/* translators: %s: window line */
							__( 'The store looks fairly quiet right now, with no major queue standing out. %s', 'yoro-ai-core' ),
							$window_line
						);
				}

				if ( '' !== $top_priority ) {
					return sprintf(
						/* translators: 1: window line, 2: top priority */
						__( 'Here is the store report. %1$s %2$s', 'yoro-ai-core' ),
						$window_line,
						$top_priority
					);
				}

				return sprintf(
					/* translators: %s: window line */
					__( 'Here is the store report. %s', 'yoro-ai-core' ),
					$window_line
				);

			case 'operations.get_summary':
				$window_label = sanitize_text_field( $result['window']['label'] ?? 'this period' );
				$window_orders = absint( $result['window_order_count'] ?? 0 );
				$backlog_total = absint( $result['backlog_total'] ?? 0 );
				$attention_total = absint( $result['attention_total'] ?? 0 );
				$operations_state = self::classify_operations_summary_state(
					$window_orders,
					$backlog_total,
					$attention_total
				);
				$window_phrase = self::format_window_sentence_prefix( $window_label );
				$window_summary = sprintf(
					/* translators: 1: window label, 2: new orders */
					__( '%1$s I found %2$d new orders.', 'yoro-ai-core' ),
					$window_phrase,
					$window_orders
				);
				$pressure_summary = sprintf(
					/* translators: 1: backlog total, 2: attention total */
					__( 'The live queue currently shows %1$d backlog items and %2$d items that may need attention.', 'yoro-ai-core' ),
					$backlog_total,
					$attention_total
				);

				switch ( $operations_state ) {
					case 'quiet_but_loaded':
						return sprintf(
							/* translators: 1: window summary, 2: pressure summary */
							__( 'Order intake looks fairly quiet, but operational load is still heavy. %1$s %2$s', 'yoro-ai-core' ),
							$window_summary,
							$pressure_summary
						);

					case 'active_and_loaded':
						return sprintf(
							/* translators: 1: window summary, 2: pressure summary */
							__( 'The store looks active, and the queue is still carrying meaningful pressure. %1$s %2$s', 'yoro-ai-core' ),
							$window_summary,
							$pressure_summary
						);

					case 'active_and_controlled':
						return sprintf(
							/* translators: %s: window summary */
							__( 'The store looks active and relatively under control. %s', 'yoro-ai-core' ),
							$window_summary
						);
				}

				return sprintf(
					/* translators: 1: window summary, 2: pressure summary */
					__( 'The operations picture is mixed. %1$s %2$s', 'yoro-ai-core' ),
					$window_summary,
					$pressure_summary
				);

			case 'operations.get_attention_queue':
				return sprintf(
					/* translators: %d: count */
					__( 'I found %d operational queue matches that look like the highest-priority issues to review next.', 'yoro-ai-core' ),
					self::get_queue_match_count( $result )
				);

			case 'operations.get_fulfillment_queue':
				$fulfillment_summary = self::summarize_fulfillment_queue( $result );
				$total_items = self::get_queue_match_count( $result );
				$tracking_count = absint( $fulfillment_summary['tracking_gap_count'] ?? 0 );
				$aging_count = absint( $fulfillment_summary['aging_processing_count'] ?? 0 );

				if ( $tracking_count > 0 && $aging_count > 0 ) {
					return sprintf(
						/* translators: 1: total items, 2: tracking count, 3: aging count */
						__( 'Fulfillment pressure is concentrated in missing tracking and aging processing orders. I found %1$d queue matches, including %2$d without tracking signals and %3$d older processing matches.', 'yoro-ai-core' ),
						$total_items,
						$tracking_count,
						$aging_count
					);
				}

				if ( $tracking_count > 0 ) {
					return sprintf(
						/* translators: 1: total items, 2: tracking count */
						__( 'The main fulfillment watchpoint is missing tracking coverage. I found %1$d queue matches, including %2$d without a tracking signal.', 'yoro-ai-core' ),
						$total_items,
						$tracking_count
					);
				}

				if ( $aging_count > 0 ) {
					return sprintf(
						/* translators: 1: total items, 2: aging count */
						__( 'The main fulfillment pressure is older processing orders that still need movement. I found %1$d queue matches, including %2$d aging processing matches.', 'yoro-ai-core' ),
						$total_items,
						$aging_count
					);
				}

				return sprintf(
					/* translators: %d: count */
					__( 'I pulled the fulfillment queue and found %d queue matches that may be waiting on shipping or tracking follow-up.', 'yoro-ai-core' ),
					$total_items
				);

			case 'customers.get_service_queue':
				$service_summary = self::summarize_customer_service_queue( $result );
				$total_items = self::get_queue_match_count( $result );
				$payment_followups = absint( $service_summary['pending_payment_followup'] ?? 0 );
				$hold_followups = absint( $service_summary['on_hold_followup'] ?? 0 );
				$drafts_waiting = absint( $service_summary['saved_email_drafts'] ?? 0 );

				if ( $hold_followups > 0 && $payment_followups > 0 ) {
					return sprintf(
						/* translators: 1: total items, 2: on-hold follow-up count, 3: pending payment follow-up count */
						__( 'Customer follow-up load is concentrated in on-hold orders and pending payment cases. I found %1$d queue matches, including %2$d on-hold follow-ups and %3$d pending payment follow-ups.', 'yoro-ai-core' ),
						$total_items,
						$hold_followups,
						$payment_followups
					);
				}

				if ( $drafts_waiting > 0 ) {
					return sprintf(
						/* translators: 1: total items, 2: saved draft count */
						__( 'There is active customer follow-up work, with saved drafts already waiting for review. I found %1$d queue matches, including %2$d saved email drafts.', 'yoro-ai-core' ),
						$total_items,
						$drafts_waiting
					);
				}

				return sprintf(
					/* translators: %d: count */
					__( 'I found %d customer-facing queue matches that may need a reply or follow-up from the team.', 'yoro-ai-core' ),
					$total_items
				);

			case 'products.get_inventory_queue':
				$inventory_summary = self::summarize_inventory_queue( $result );
				$total_items = self::get_queue_match_count( $result );
				$out_of_stock = absint( $inventory_summary['out_of_stock'] ?? 0 );
				$low_stock = absint( $inventory_summary['low_stock'] ?? 0 );
				$backorder = absint( $inventory_summary['on_backorder'] ?? 0 );

				if ( $out_of_stock > 0 || $backorder > 0 ) {
					$out_of_stock_label = sprintf(
						/* translators: Placeholder values are generated from store data for this message. */
						_n( '%d out-of-stock product', '%d out-of-stock products', $out_of_stock, 'yoro-ai-core' ),
						$out_of_stock
					);
					$backorder_label = sprintf(
						/* translators: Placeholder values are generated from store data for this message. */
						_n( '%d backorder item', '%d backorder items', $backorder, 'yoro-ai-core' ),
						$backorder
					);

					return sprintf(
						/* translators: 1: total items, 2: out of stock label, 3: backorder label */
						__( 'Inventory pressure is showing up most clearly in stockouts and backorders. I found %1$d queue matches, including %2$s and %3$s.', 'yoro-ai-core' ),
						$total_items,
						$out_of_stock_label,
						$backorder_label
					);
				}

				if ( $low_stock > 0 ) {
					return sprintf(
						/* translators: 1: total items, 2: low stock count */
						__( 'Inventory risk is concentrated in low-stock products that may need replenishment soon. I found %1$d queue matches, including %2$d low-stock products.', 'yoro-ai-core' ),
						$total_items,
						$low_stock
					);
				}

				return sprintf(
					/* translators: %d: count */
					__( 'I found %d product queue matches that may need inventory attention, including low-stock, out-of-stock, or backorder risks.', 'yoro-ai-core' ),
					$total_items
				);

			case 'store.get_insights':
				return sprintf(
					/* translators: %d: count */
					__( 'I summarized %d store signals across orders, customers, and products for this request.', 'yoro-ai-core' ),
					absint( $result['count'] ?? 0 )
				);

			case 'store.get_business_summary':
				$window_label = sanitize_text_field( $result['window']['label'] ?? 'this period' );
				$gross_sales  = sanitize_text_field( $result['business_metrics']['gross_sales'] ?? '0' );
				$currency     = sanitize_text_field( $result['meta']['currency'] ?? '' );
				$customer_count = absint( $result['business_metrics']['unique_customers'] ?? 0 );
				$customer_label = sprintf(
					/* translators: Placeholder values are generated from store data for this message. */
					_n( '%d active customer', '%d active customers', $customer_count, 'yoro-ai-core' ),
					$customer_count
				);
				$narrative_context = isset( $result['narrative_context'] ) && is_array( $result['narrative_context'] )
					? $result['narrative_context']
					: [];
				$state = sanitize_key( $narrative_context['state'] ?? '' );
				$attention_total = absint( $result['attention_total'] ?? 0 );
				$backlog_total   = absint( $result['backlog_total'] ?? 0 );
				$window_phrase = self::format_window_sentence_prefix( $window_label );
				$window_summary  = sprintf(
					/* translators: 1: window label, 2: order count, 3: gross sales, 4: currency, 5: customer label */
					__( '%1$s I found %2$d orders, %3$s %4$s in gross sales, and %5$s.', 'yoro-ai-core' ),
					$window_phrase,
					absint( $result['business_metrics']['order_count'] ?? 0 ),
					$gross_sales,
					$currency,
					$customer_label
				);
				$pressure_summary = sprintf(
					/* translators: 1: attention total, 2: backlog total */
					__( 'Separately, the live store queue still shows %1$d time-sensitive items and %2$d backlog orders.', 'yoro-ai-core' ),
					$attention_total,
					$backlog_total
				);

				switch ( $state ) {
					case 'quiet_but_under_pressure':
						return sprintf(
							/* translators: 1: window summary, 2: pressure summary */
							__( 'This period looks commercially quiet, while the live store is still under heavy operational pressure. %1$s %2$s', 'yoro-ai-core' ),
							$window_summary,
							$pressure_summary
						);

					case 'strong_but_under_pressure':
						return sprintf(
							/* translators: 1: window summary, 2: pressure summary */
							__( 'This period looks commercially strong, while the live store is still carrying meaningful operational pressure. %1$s %2$s', 'yoro-ai-core' ),
							$window_summary,
							$pressure_summary
						);

					case 'strong_and_controlled':
						return sprintf(
							/* translators: %s: window summary */
							__( 'Commercial performance looks healthy and the live store appears relatively controlled. %s', 'yoro-ai-core' ),
							$window_summary
						);

					case 'steady_but_under_pressure':
						return sprintf(
							/* translators: 1: window summary, 2: pressure summary */
							__( 'This period looks commercially steady, while the live store is still under heavy operational pressure. %1$s %2$s', 'yoro-ai-core' ),
							$window_summary,
							$pressure_summary
						);

					case 'steady_with_watchpoints':
						return sprintf(
							/* translators: 1: window summary, 2: pressure summary */
							__( 'This period looks steady overall, with a few operational watchpoints still open in the live store. %1$s %2$s', 'yoro-ai-core' ),
							$window_summary,
							$pressure_summary
						);

					case 'quiet_with_pressure':
						return sprintf(
							/* translators: 1: window summary, 2: pressure summary */
							__( 'This period looks commercially quiet, and the live store is still carrying noticeable operational pressure. %1$s %2$s', 'yoro-ai-core' ),
							$window_summary,
							$pressure_summary
						);

					case 'quiet_and_controlled':
						return sprintf(
							/* translators: %s: window summary */
							__( 'This period looks quiet overall, and the live store appears relatively under control. %s', 'yoro-ai-core' ),
							$window_summary
						);
				}

				return sprintf(
					/* translators: 1: window summary, 2: pressure summary */
					__( 'The sales picture is mixed. %1$s %2$s', 'yoro-ai-core' ),
					$window_summary,
					$pressure_summary
				);

			case 'customers.lookup':
				$customer      = isset( $result['customer'] ) && is_array( $result['customer'] ) ? $result['customer'] : [];
				$customer_name = trim( (string) ( $customer['name'] ?? '' ) );
				$order_count   = absint( $customer['orders_count'] ?? ( $result['count'] ?? 0 ) );

				if ( '' !== $customer_name ) {
					return sprintf(
						/* translators: 1: customer name, 2: related order count */
						__( 'I found the customer profile for %1$s with %2$d related orders.', 'yoro-ai-core' ),
						$customer_name,
						$order_count
					);
				}

				return sprintf(
					/* translators: %d: count */
					__( 'I found this customer profile with %d related orders.', 'yoro-ai-core' ),
					$order_count
				);
		}

		return $summary;
	}

	private static function classify_operations_summary_state( $window_orders, $backlog_total, $attention_total ) {
		$window_orders   = absint( $window_orders );
		$backlog_total   = absint( $backlog_total );
		$attention_total = absint( $attention_total );

		if ( $window_orders <= 10 && ( $backlog_total >= 100 || $attention_total >= 50 ) ) {
			return 'quiet_but_loaded';
		}

		if ( $window_orders > 10 && ( $backlog_total >= 100 || $attention_total >= 50 ) ) {
			return 'active_and_loaded';
		}

		if ( $window_orders > 10 ) {
			return 'active_and_controlled';
		}

		return 'mixed';
	}

	private static function format_window_sentence_prefix( $window_label ) {
		$window_label = sanitize_text_field( $window_label );
		$normalized = strtolower( trim( $window_label ) );

		if ( 'today' === $normalized ) {
			return __( 'Today,', 'yoro-ai-core' );
		}

		if ( 'yesterday' === $normalized ) {
			return __( 'Yesterday,', 'yoro-ai-core' );
		}

		if ( 'last 7 days' === $normalized ) {
			return __( 'Over the last 7 days,', 'yoro-ai-core' );
		}

		if ( 'last 30 days' === $normalized ) {
			return __( 'Over the last 30 days,', 'yoro-ai-core' );
		}

		return sprintf(
			/* translators: %s: window label */
			__( 'In %s,', 'yoro-ai-core' ),
			$window_label
		);
	}

	private static function get_queue_match_count( $result ) {
		if ( is_array( $result ) && isset( $result['queue_item_count'] ) ) {
			return absint( $result['queue_item_count'] );
		}

		return absint( is_array( $result ) ? ( $result['count'] ?? 0 ) : 0 );
	}

	private static function summarize_fulfillment_queue( $result ) {
		$queues = isset( $result['queues'] ) && is_array( $result['queues'] ) ? $result['queues'] : [];
		$summary = [
			'tracking_gap_count'      => 0,
			'aging_processing_count'  => 0,
			'recent_ready_count'      => 0,
		];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );
			$count = absint( $queue['count'] ?? 0 );

			if ( 'processing_without_tracking' === $key ) {
				$summary['tracking_gap_count'] = $count;
			} elseif ( 'processing_older_than_48h' === $key ) {
				$summary['aging_processing_count'] = $count;
			} elseif ( 'recent_ready_to_fulfill' === $key ) {
				$summary['recent_ready_count'] = $count;
			}
		}

		return $summary;
	}

	private static function summarize_customer_service_queue( $result ) {
		$queues = isset( $result['queues'] ) && is_array( $result['queues'] ) ? $result['queues'] : [];
		$summary = [
			'pending_payment_followup' => 0,
			'on_hold_followup'         => 0,
			'failed_checkout_followup' => 0,
			'saved_email_drafts'       => 0,
		];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );
			$count = absint( $queue['count'] ?? 0 );

			if ( array_key_exists( $key, $summary ) ) {
				$summary[ $key ] = $count;
			}
		}

		return $summary;
	}

	private static function summarize_inventory_queue( $result ) {
		$queues = isset( $result['queues'] ) && is_array( $result['queues'] ) ? $result['queues'] : [];
		$summary = [
			'out_of_stock'             => 0,
			'low_stock'                => 0,
			'on_backorder'             => 0,
			'unmanaged_stock_selling'  => 0,
			'low_stock_in_processing_orders' => 0,
		];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );
			$count = absint( $queue['count'] ?? 0 );

			if ( array_key_exists( $key, $summary ) ) {
				$summary[ $key ] = $count;
			}
		}

		return $summary;
	}

	private static function normalize_error_result( $action_name, WP_Error $error, $action = null ) {
		$action = is_array( $action ) ? $action : YoRo_Action_Registry::get( $action_name );
		$is_write_action = ! empty( $action['write_action'] );
		$message         = $error->get_error_message();

		return [
			'success'      => false,
			'action'       => $action_name,
			'summary'      => $message,
			'message'      => $message,
			'data'         => [],
			'meta'         => [],
			'error'        => [
				'code'    => $error->get_error_code(),
				'message' => $message,
				'data'    => $error->get_error_data(),
			],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [
				[
					'code'    => $error->get_error_code(),
					'message' => $message,
				],
			],
			'count'        => 0,
			'read_only'    => ! $is_write_action,
			'undoable'     => false,
			'undo_plan'    => [],
			'result_type'  => self::infer_error_result_type( $action_name ),
			'display_type' => 'error',
		];
	}

	private static function infer_error_result_type( $action_name ) {
		$customer_email_actions = [
			'orders.email_customer_preview',
			'orders.email_customer_template',
			'orders.use_email_template',
			'orders.compose_customer_email',
			'orders.render_customer_email_preview',
			'orders.send_customer_email',
		];

		$customer_email_draft_actions = [
			'orders.save_customer_email_draft',
			'orders.get_customer_email_drafts',
			'orders.update_customer_email_draft',
			'orders.delete_customer_email_draft',
		];

		if ( in_array( $action_name, $customer_email_actions, true ) ) {
			return 'customer_email';
		}

		if ( in_array( $action_name, $customer_email_draft_actions, true ) ) {
			return 'customer_email_drafts';
		}

		if ( 'operations.get_summary' === $action_name ) {
			return 'operations_summary';
		}

		if ( 'store.get_business_summary' === $action_name ) {
			return 'business_summary';
		}

		if ( 'operations.get_attention_queue' === $action_name ) {
			return 'operations_attention_queue';
		}

		if ( 'operations.get_fulfillment_queue' === $action_name ) {
			return 'fulfillment_queue';
		}

		if ( 'customers.get_service_queue' === $action_name ) {
			return 'customer_service_queue';
		}

		if ( 'products.get_inventory_queue' === $action_name ) {
			return 'inventory_queue';
		}

		if ( in_array( $action_name, [ 'products.update_stock', 'products.update_stock_status' ], true ) ) {
			return 'inventory_queue';
		}

		if ( 'store.get_insights' === $action_name ) {
			return 'store_insights';
		}

		if ( 'store.get_business_summary' === $action_name ) {
			return 'business_summary';
		}

		if ( 0 === strpos( $action_name, 'email_templates.' ) ) {
			return 'email_templates';
		}

		if ( 0 === strpos( $action_name, 'orders.' ) ) {
			return 'orders';
		}

		return 'error';
	}
}
