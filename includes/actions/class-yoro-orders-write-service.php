<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Orders_Write_Service {

	public static function add_private_note_template( $params ) {
		$template  = ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '';
		$templates = self::get_private_note_templates();

		if ( empty( $template ) || empty( $templates[ $template ] ) ) {
			return new WP_Error( 'invalid_template', __( 'Invalid private note template.', 'yoro-agent-core' ) );
		}

		$params['note'] = $templates[ $template ];

		$result = self::add_order_note( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['action']   = 'orders.add_private_note_template';
		$result['template'] = $template;
		$result['summary']  = sprintf( 'Added %s note to %d orders.', $template, count( $result['processed'] ?? [] ) );

		return $result;
	}

	public static function hold_orders( $params ) {
		$order_ids = isset( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_map( 'absint', $params['order_ids'] )
			: [];

		$processed = [];
		$skipped   = [];
		$errors    = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$skipped[] = [
					'id'     => $order_id,
					'reason' => 'Order not found',
				];
				continue;
			}

			try {
				$order->update_status(
					'on-hold',
					__( 'Order placed on hold by YoRo Agent.', 'yoro-agent-core' )
				);

				$processed[] = $order_id;
			} catch ( Exception $e ) {
				$errors[] = [
					'id'      => $order_id,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'success'   => true,
			'action'    => 'orders.hold',
			'summary'   => sprintf( 'Placed %d orders on hold.', count( $processed ) ),
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'count'     => count( $processed ),
		];
	}

	public static function add_order_note( $params ) {
		$note = isset( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '';

		if ( empty( $note ) ) {
			return new WP_Error( 'invalid_params', __( 'Note is required.', 'yoro-agent-core' ) );
		}

		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = array_map( 'absint', $params['order_ids'] );
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ absint( $params['order_id'] ) ];
		}

		$order_ids = array_values( array_filter( array_unique( $order_ids ) ) );

		if ( empty( $order_ids ) ) {
			return new WP_Error( 'invalid_params', __( 'Order ID is required.', 'yoro-agent-core' ) );
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];
		$note_ids  = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$skipped[] = [
					'id'     => $order_id,
					'reason' => 'Order not found',
				];
				continue;
			}

			$note_id = $order->add_order_note(
				sprintf(
					__( 'YoRo Agent note: %s', 'yoro-agent-core' ),
					$note
				)
			);

			if ( $note_id ) {
				$note_ids[] = absint( $note_id );
			}

			$processed[] = $order_id;
		}

		return [
			'success'   => true,
			'action'    => 'orders.add_note',
			'summary'   => sprintf( 'Added notes to %d orders.', count( $processed ) ),
			'order_ids' => $processed,
			'note'      => $note,
			'note_ids'  => $note_ids,
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'count'     => count( $processed ),
		];
	}

	public static function remove_order_note( $params ) {
		$note_ids = ! empty( $params['note_ids'] ) && is_array( $params['note_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $params['note_ids'] ) ) ) )
			: [];

		if ( empty( $note_ids ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Note IDs are required.', 'yoro-agent-core' )
			);
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];

		foreach ( $note_ids as $note_id ) {
			$comment = get_comment( $note_id );

			if ( ! $comment ) {
				$skipped[] = [
					'id'     => $note_id,
					'reason' => 'Note not found',
				];
				continue;
			}

			if ( 'order_note' !== $comment->comment_type ) {
				$skipped[] = [
					'id'     => $note_id,
					'reason' => 'Not an order note',
				];
				continue;
			}

			if ( false === strpos( (string) $comment->comment_content, 'YoRo Agent note:' ) ) {
				$skipped[] = [
					'id'     => $note_id,
					'reason' => 'Not a YoRo-created note',
				];
				continue;
			}

			$deleted = wp_delete_comment( $note_id, true );

			if ( $deleted ) {
				$processed[] = $note_id;
			} else {
				$errors[] = [
					'id'      => $note_id,
					'message' => 'Could not delete note',
				];
			}
		}

		return [
			'success'   => true,
			'action'    => 'orders.remove_note',
			'summary'   => sprintf( 'Removed %d YoRo order notes.', count( $processed ) ),
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'note_ids'  => $processed,
			'count'     => count( $processed ),
		];
	}

	public static function preview_hold_orders( $params ) {
		$order_ids = isset( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_map( 'absint', $params['order_ids'] )
			: [];

		$orders = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			$orders[] = [
				'id'             => $order_id,
				'exists'         => (bool) $order,
				'current_status' => $order ? $order->get_status() : '',
				'target_status'  => 'on-hold',
			];
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Put selected WooCommerce orders on hold',
			'orders'        => $orders,
			'count'         => count( $orders ),
			'changes_store' => true,
		];
	}

	public static function preview_add_order_note( $params ) {
		$note = isset( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '';

		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = array_map( 'absint', $params['order_ids'] );
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ absint( $params['order_id'] ) ];
		}

		$orders = [];

		foreach ( array_filter( array_unique( $order_ids ) ) as $order_id ) {
			$order = wc_get_order( $order_id );

			$orders[] = [
				'id'            => $order_id,
				'exists'        => (bool) $order,
				'note_preview'  => $note,
			];
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Add internal note to WooCommerce order(s)',
			'orders'        => $orders,
			'count'         => count( $orders ),
			'note_preview'  => $note,
			'changes_store' => true,
		];
	}

	public static function update_order_status( $params ) {
		$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_map( 'absint', $params['order_ids'] )
			: [];

		$status = ! empty( $params['status'] )
			? YoRo_Order_Helper::normalize_status_slug( $params['status'] )
			: '';

		$note   = ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : __( 'Order status updated by YoRo Agent.', 'yoro-agent-core' );

		if ( empty( $order_ids ) || empty( $status ) ) {
			return new WP_Error( 'invalid_params', __( 'Order IDs and status are required.', 'yoro-agent-core' ) );
		}

		if ( ! YoRo_Order_Helper::is_valid_order_status( $status ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: order status */
					__( 'Invalid WooCommerce order status: %s.', 'yoro-agent-core' ),
					$status
				)
			);
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$skipped[] = [
					'id'     => $order_id,
					'reason' => 'Order not found',
				];
				continue;
			}

			try {
				$order->update_status( $status, $note );
				$processed[] = $order_id;
			} catch ( Exception $e ) {
				$errors[] = [
					'id'      => $order_id,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'success'      => true,
			'action'       => 'orders.update_status',
			'summary'      => sprintf( 'Updated %d orders to %s.', count( $processed ), $status ),
			'processed'    => $processed,
			'skipped'      => $skipped,
			'errors'       => $errors,
			'status'       => $status,
			'status_label' => YoRo_Order_Helper::get_order_status_label( $status ),
			'count'        => count( $processed ),
		];
	}

	public static function restore_order_statuses( $params ) {
		$statuses = isset( $params['statuses'] ) && is_array( $params['statuses'] )
			? $params['statuses']
			: [];

		if ( empty( $statuses ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Previous statuses are required.', 'yoro-agent-core' )
			);
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];

		foreach ( $statuses as $order_id => $status ) {
			$order_id = absint( $order_id );
			$status   = sanitize_key( $status );

			if ( ! $order_id || empty( $status ) ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$skipped[] = [
					'id'     => $order_id,
					'reason' => 'Order not found',
				];
				continue;
			}

			try {
				$order->update_status(
					$status,
					__( 'Order status restored by YoRo undo.', 'yoro-agent-core' ),
					true
				);

				$processed[] = $order_id;
			} catch ( Exception $e ) {
				$errors[] = [
					'id'      => $order_id,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'success'   => true,
			'action'    => 'orders.restore_statuses',
			'summary'   => sprintf( 'Restored statuses for %d orders.', count( $processed ) ),
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'count'     => count( $processed ),
		];
	}

	public static function bulk_update_status_with_note( $params ) {
		$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $params['order_ids'] ) ) ) )
			: [];

		$status = ! empty( $params['status'] )
			? YoRo_Order_Helper::normalize_status_slug( $params['status'] )
			: '';

		$note   = ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '';

		if ( empty( $order_ids ) || empty( $status ) || empty( $note ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order IDs, status, and note are required.', 'yoro-agent-core' )
			);
		}

		if ( ! YoRo_Order_Helper::is_valid_order_status( $status ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: order status */
					__( 'Invalid WooCommerce order status: %s.', 'yoro-agent-core' ),
					$status
				)
			);
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$skipped[] = [
					'id'     => $order_id,
					'reason' => 'Order not found',
				];
				continue;
			}

			try {
				$order->update_status(
					$status,
					sprintf(
						/* translators: %s: note text */
						__( 'YoRo Agent status update: %s', 'yoro-agent-core' ),
						$note
					)
				);

				$order->add_order_note(
					sprintf(
						/* translators: %s: note text */
						__( 'YoRo Agent note: %s', 'yoro-agent-core' ),
						$note
					)
				);

				$processed[] = $order_id;
			} catch ( Exception $e ) {
				$errors[] = [
					'id'      => $order_id,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'success'      => true,
			'action'       => 'orders.bulk_update_status_with_note',
			'summary'      => sprintf(
				'Updated %d orders to %s and added note.',
				count( $processed ),
				$status
			),
			'processed'    => $processed,
			'skipped'      => $skipped,
			'errors'       => $errors,
			'status'       => $status,
			'status_label' => YoRo_Order_Helper::get_order_status_label( $status ),
			'note'         => $note,
			'count'        => count( $processed ),
		];
	}

	public static function preview_update_order_status( $params ) {
		$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_map( 'absint', $params['order_ids'] )
			: [];

		$status = ! empty( $params['status'] ) ? sanitize_key( $params['status'] ) : '';

		$orders = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			$orders[] = [
				'id'             => $order_id,
				'exists'         => (bool) $order,
				'current_status' => $order ? $order->get_status() : '',
				'target_status'  => $status,
			];
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Update WooCommerce order status',
			'orders'        => $orders,
			'count'         => count( $orders ),
			'target_status' => $status,
			'changes_store' => true,
		];
	}

	public static function preview_bulk_update_status_with_note( $params ) {
		$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $params['order_ids'] ) ) ) )
			: [];

		$status = ! empty( $params['status'] ) ? sanitize_key( $params['status'] ) : '';
		$note   = ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '';

		$orders = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			$orders[] = [
				'id'             => $order_id,
				'exists'         => (bool) $order,
				'current_status' => $order ? $order->get_status() : '',
				'target_status'  => $status,
				'note_preview'   => $note,
			];
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Bulk update WooCommerce order status with internal note',
			'orders'        => $orders,
			'count'         => count( $orders ),
			'target_status' => $status,
			'note_preview'  => $note,
			'changes_store' => true,
		];
	}

	public static function preview_add_private_note_template( $params ) {
		$template  = ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '';
		$templates = self::get_private_note_templates();

		$params['note'] = $templates[ $template ] ?? '';

		return self::preview_add_order_note( $params ) + [
			'template' => $template,
		];
	}

	private static function get_private_note_templates() {
		return [
			'suspicious'         => __( 'YoRo review: This order has suspicious signals and should be manually reviewed before fulfillment.', 'yoro-agent-core' ),
			'payment_mismatch'   => __( 'YoRo review: Payment information may not match the customer/order identity. Please verify before fulfillment.', 'yoro-agent-core' ),
			'needs_verification' => __( 'YoRo review: Customer verification is required before this order is processed further.', 'yoro-agent-core' ),
		];
	}
}
