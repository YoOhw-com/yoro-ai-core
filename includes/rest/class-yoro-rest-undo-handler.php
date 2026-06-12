<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Undo_Handler {

	public static function handle( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$log_id  = absint( $request->get_param( 'log_id' ) );
		$log     = $log_id
			? YoRo_Logger::get_executed_by_id( $log_id, $user_id )
			: YoRo_Logger::get_last_executed( $user_id );

		if ( ! $log || empty( $log['undo_data'] ) ) {
			return new WP_Error(
				'yoro_no_undo',
				__( 'Nothing to undo.', 'yoro-ai-core' )
			);
		}

		$ttl = absint( get_option( 'yoro_undo_ttl', 60 ) );

		if ( $ttl > 0 && ! empty( $log['created_at'] ) ) {
			$created_at = strtotime( $log['created_at'] );

			if ( $created_at && ( time() - $created_at ) > $ttl ) {
				return new WP_Error(
					'yoro_undo_expired',
					__( 'Undo window has expired.', 'yoro-ai-core' )
				);
			}
		}

		$undo = $log['undo_data'];

		if ( self::is_undo_plan( $undo ) ) {
			return self::handle_undo_plan( $log, $undo );
		}

		return self::handle_undo_type( $log, $undo );
	}

	private static function is_undo_plan( $undo ) {
		return is_array( $undo ) && isset( $undo[0] ) && is_array( $undo[0] ) && ! empty( $undo[0]['action'] );
	}

	private static function handle_undo_plan( $log, array $undo ) {
		$results = [];

		foreach ( array_reverse( $undo ) as $step ) {
			$step_id     = sanitize_text_field( $step['id'] ?? '' );
			$step_action = sanitize_text_field( $step['action'] ?? '' );
			$step_source = sanitize_key( $step['source'] ?? '' );
			$step_params = isset( $step['params'] ) && is_array( $step['params'] )
				? $step['params']
				: [];

			if ( empty( $step_action ) ) {
				continue;
			}

			$step_result = YoRo_Action_Executor::execute(
				$step_action,
				$step_params,
				true,
				false,
				[
					'is_undo' => true,
				]
			);

			if ( is_wp_error( $step_result ) ) {
				return $step_result;
			}

			$results[] = [
				'id'     => $step_id,
				'action' => $step_action,
				'source' => $step_source,
				'params' => $step_params,
				'result' => $step_result,
			];
		}

		$mark_result = self::mark_undone_or_fail( $log['id'] );

		if ( is_wp_error( $mark_result ) ) {
			return $mark_result;
		}

		self::flush_store_action_cache();

		return [
			'success'      => true,
			'message'      => __( 'I undid that change.', 'yoro-ai-core' ),
			'summary'      => __( 'I restored the saved change.', 'yoro-ai-core' ),
			'type'         => 'undo_plan',
			'action'       => 'undo',
			'count'        => count( $results ),
			'processed'    => self::collect_processed_ids_from_steps( $results ),
			'result'       => $results,
			'steps'        => $results,
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'result_type'  => 'undo',
			'display_type' => 'undo',
		];
	}

	private static function handle_undo_type( $log, array $undo ) {
		$type = $undo['type'] ?? '';
		$result = [];

		switch ( $type ) {
			case 'orders.update_status':
				$result = self::restore_order_statuses( $undo );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
				break;

			case 'orders.add_note':
				if ( ! empty( $undo['note_ids'] ) && is_array( $undo['note_ids'] ) ) {
					$result = YoRo_Orders_Actions::remove_order_note(
						[
							'note_ids' => $undo['note_ids'],
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					break;
				}

				$result = [
					'success'   => true,
					'action'    => 'orders.remove_note',
					'summary'   => __( 'I did not find a saved YoRo note to remove.', 'yoro-ai-core' ),
					'message'   => __( 'I did not find a saved YoRo note to remove.', 'yoro-ai-core' ),
					'processed' => [],
					'count'     => 0,
				];
				break;

			case 'delete_email_draft':
				self::delete_email_draft( $undo );
				$result = [
					'success'   => true,
					'action'    => 'delete_email_draft',
					'summary'   => __( 'I removed the saved email draft.', 'yoro-ai-core' ),
					'processed' => ! empty( $undo['order_id'] ) ? [ absint( $undo['order_id'] ) ] : [],
				];
				break;

			case 'restore_email_draft':
				self::restore_email_draft( $undo );
				$result = [
					'success'   => true,
					'action'    => 'restore_email_draft',
					'summary'   => __( 'I restored the previous email draft.', 'yoro-ai-core' ),
					'processed' => ! empty( $undo['order_id'] ) ? [ absint( $undo['order_id'] ) ] : [],
				];
				break;

			case 'delete_template':
				self::delete_template( $undo );
				$result = [
					'success'   => true,
					'action'    => 'delete_template',
					'summary'   => __( 'I removed the saved email template.', 'yoro-ai-core' ),
					'processed' => ! empty( $undo['template_id'] ) ? [ sanitize_key( $undo['template_id'] ) ] : [],
				];
				break;

			case 'restore_template':
				self::restore_template( $undo );
				$result = [
					'success'   => true,
					'action'    => 'restore_template',
					'summary'   => __( 'I restored the previous email template.', 'yoro-ai-core' ),
					'processed' => ! empty( $undo['template_id'] ) ? [ sanitize_key( $undo['template_id'] ) ] : [],
				];
				break;

			default:
				return [
					'success' => false,
					'message' => __( 'Undo not supported for this action.', 'yoro-ai-core' ),
				];
		}

		$mark_result = self::mark_undone_or_fail( $log['id'] );

		if ( is_wp_error( $mark_result ) ) {
			return $mark_result;
		}

		self::flush_store_action_cache();

		return [
			'success'      => true,
			'message'      => __( 'I undid that change.', 'yoro-ai-core' ),
			'summary'      => __( 'I restored the saved change.', 'yoro-ai-core' ),
			'type'         => $type,
			'action'       => is_array( $result ) ? ( $result['action'] ?? $type ) : $type,
			'count'        => is_array( $result ) ? absint( $result['count'] ?? count( $result['processed'] ?? [] ) ) : 0,
			'processed'    => is_array( $result ) && ! empty( $result['processed'] ) && is_array( $result['processed'] ) ? $result['processed'] : [],
			'result'       => is_array( $result ) ? $result : [],
			'steps'        => [
				[
					'action' => is_array( $result ) ? ( $result['action'] ?? $type ) : $type,
					'params' => [],
					'result' => is_array( $result ) ? $result : [],
				],
			],
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'result_type'  => 'undo',
			'display_type' => 'undo',
		];
	}

	private static function restore_order_statuses( array $undo ) {
		if ( empty( $undo['orders'] ) ) {
			return [
				'success'   => true,
				'action'    => 'orders.restore_statuses',
				'summary'   => __( 'I did not find any saved order statuses to restore.', 'yoro-ai-core' ),
				'processed' => [],
				'count'     => 0,
			];
		}

		$restored = [];
		$statuses = [];
		$status_labels = [];

		foreach ( $undo['orders'] as $item ) {
			$order = wc_get_order( $item['id'] );

			if ( $order && ! empty( $item['previous_status'] ) ) {
				$previous_status = sanitize_key( $item['previous_status'] );
				$order->update_status(
					$previous_status,
					__( 'Undo YoRo AI status action.', 'yoro-ai-core' )
				);

				$order_id = absint( $item['id'] );
				$restored[] = $order_id;
				$statuses[ $order_id ] = $previous_status;
				$status_labels[ $order_id ] = YoRo_Order_Helper::get_order_status_label( $previous_status );
			}
		}

		if ( empty( $restored ) ) {
			return new WP_Error(
				'yoro_undo_restore_failed',
				__( 'No orders were restored.', 'yoro-ai-core' )
			);
		}

		return [
			'success'   => true,
			'action'    => 'orders.restore_statuses',
			'summary'   => sprintf(
				/* translators: %d: order count */
				__( 'Restored %d order statuses.', 'yoro-ai-core' ),
				count( $restored )
			),
			'processed' => $restored,
			'statuses'  => $statuses,
			'status_labels' => $status_labels,
			'count'     => count( $restored ),
		];
	}

	private static function collect_processed_ids_from_steps( array $steps ) {
		$processed = [];

		foreach ( $steps as $step ) {
			$result = isset( $step['result'] ) && is_array( $step['result'] ) ? $step['result'] : [];
			$ids = isset( $result['processed'] ) && is_array( $result['processed'] ) ? $result['processed'] : [];

			foreach ( $ids as $id ) {
				if ( ! in_array( $id, $processed, true ) ) {
					$processed[] = $id;
				}
			}
		}

		return $processed;
	}

	private static function flush_store_action_cache() {
		YoRo_Action_Cache::flush_groups(
			[
				'operations_summary',
				'operations_attention_queue',
				'fulfillment_queue',
				'orders',
			]
		);
	}

	private static function delete_email_draft( array $undo ) {
		$order = wc_get_order( $undo['order_id'] ?? 0 );

		if ( ! $order || empty( $undo['draft_id'] ) ) {
			return;
		}

		$drafts = $order->get_meta( '_yoro_customer_email_drafts', true );
		$drafts = is_array( $drafts ) ? $drafts : [];

		$drafts = array_filter(
			$drafts,
			function ( $draft ) use ( $undo ) {
				return ( $draft['id'] ?? '' ) !== $undo['draft_id'];
			}
		);

		$order->update_meta_data( '_yoro_customer_email_drafts', array_values( $drafts ) );
		$order->save();
	}

	private static function restore_email_draft( array $undo ) {
		$order = wc_get_order( $undo['order_id'] ?? 0 );

		if ( ! $order || empty( $undo['draft_id'] ) ) {
			return;
		}

		$drafts = $order->get_meta( '_yoro_customer_email_drafts', true );
		$drafts = is_array( $drafts ) ? $drafts : [];

		foreach ( $drafts as &$draft ) {
			if ( ! empty( $draft['id'] ) && $draft['id'] === $undo['draft_id'] ) {
				$draft['subject']    = $undo['subject'] ?? '';
				$draft['body']       = $undo['body'] ?? '';
				$draft['updated_at'] = current_time( 'mysql' );
			}
		}
		unset( $draft );

		$order->update_meta_data( '_yoro_customer_email_drafts', $drafts );
		$order->save();
	}

	private static function delete_template( array $undo ) {
		$template_id = sanitize_key( $undo['template_id'] ?? '' );

		if ( ! $template_id ) {
			return;
		}

		$templates = YoRo_Orders_Actions::get_email_templates();
		$templates = array_filter(
			$templates,
			function ( $template ) use ( $template_id ) {
				return ( $template['id'] ?? '' ) !== $template_id;
			}
		);

		YoRo_Orders_Actions::save_email_templates( array_values( $templates ) );
	}

	private static function restore_template( array $undo ) {
		if ( empty( $undo['template'] ) || ! is_array( $undo['template'] ) ) {
			return;
		}

		$templates = YoRo_Orders_Actions::get_email_templates();
		array_unshift( $templates, $undo['template'] );

		YoRo_Orders_Actions::save_email_templates(
			array_slice( $templates, 0, 50 )
		);
	}

	private static function mark_undone_or_fail( $log_id ) {
		$marked = YoRo_Logger::mark_undone( $log_id );

		if ( false === $marked ) {
			return new WP_Error(
				'yoro_undo_mark_failed',
				__( 'I restored the saved change, but could not mark the original action as undone.', 'yoro-ai-core' )
			);
		}

		return true;
	}
}
