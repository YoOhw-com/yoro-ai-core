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
				__( 'Nothing to undo.', 'yoro-agent-core' )
			);
		}

		$ttl = absint( get_option( 'yoro_undo_ttl', 60 ) );

		if ( $ttl > 0 && ! empty( $log['created_at'] ) ) {
			$created_at = strtotime( $log['created_at'] );

			if ( $created_at && ( time() - $created_at ) > $ttl ) {
				return new WP_Error(
					'yoro_undo_expired',
					__( 'Undo window has expired.', 'yoro-agent-core' )
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

		return [
			'success' => true,
			'message' => __( 'Undo completed.', 'yoro-agent-core' ),
			'type'    => 'undo_plan',
			'count'   => count( $results ),
			'result'  => $results,
		];
	}

	private static function handle_undo_type( $log, array $undo ) {
		$type = $undo['type'] ?? '';

		switch ( $type ) {
			case 'orders.hold':
			case 'orders.update_status':
				$restore_result = self::restore_order_statuses( $undo );

				if ( is_wp_error( $restore_result ) ) {
					return $restore_result;
				}
				break;

			case 'orders.add_note':
				if ( ! empty( $undo['note_ids'] ) && is_array( $undo['note_ids'] ) ) {
					return YoRo_Orders_Actions::remove_order_note(
						[
							'note_ids' => $undo['note_ids'],
						]
					);
				}

				return [
					'success' => true,
					'message' => __( 'No removable YoRo note IDs were found.', 'yoro-agent-core' ),
				];

			case 'delete_email_draft':
				self::delete_email_draft( $undo );
				break;

			case 'restore_email_draft':
				self::restore_email_draft( $undo );
				break;

			case 'delete_template':
				self::delete_template( $undo );
				break;

			case 'restore_template':
				self::restore_template( $undo );
				break;

			default:
				return [
					'success' => false,
					'message' => __( 'Undo not supported for this action.', 'yoro-agent-core' ),
				];
		}

		$mark_result = self::mark_undone_or_fail( $log['id'] );

		if ( is_wp_error( $mark_result ) ) {
			return $mark_result;
		}

		return [
			'success' => true,
			'message' => __( 'Undo completed.', 'yoro-agent-core' ),
			'type'    => $type,
		];
	}

	private static function restore_order_statuses( array $undo ) {
		if ( empty( $undo['orders'] ) ) {
			return true;
		}

		$restored = [];

		foreach ( $undo['orders'] as $item ) {
			$order = wc_get_order( $item['id'] );

			if ( $order && ! empty( $item['previous_status'] ) ) {
				$order->update_status(
					$item['previous_status'],
					__( 'Undo YoRo Agent status action.', 'yoro-agent-core' )
				);

				$restored[] = absint( $item['id'] );
			}
		}

		if ( empty( $restored ) ) {
			return new WP_Error(
				'yoro_undo_restore_failed',
				__( 'No orders were restored.', 'yoro-agent-core' )
			);
		}

		return true;
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
				__( 'Undo completed, but YoRo could not mark the action as undone.', 'yoro-agent-core' )
			);
		}

		return true;
	}
}
