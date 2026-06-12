<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Orders_Write_Service {

	const YORO_NOTE_META_KEY = '_yoro_note_origin';
	const YORO_NOTE_META_VALUE = 'chat';

	private static function build_yoro_note_content( $note ) {
		return sprintf(
			/* translators: %s: note text */
			__( '[YoRo] %s', 'yoro-ai-core' ),
			$note
		);
	}

	private static function mark_note_as_yoro_created( $note_id ) {
		$note_id = absint( $note_id );

		if ( $note_id <= 0 ) {
			return;
		}

		update_comment_meta(
			$note_id,
			self::YORO_NOTE_META_KEY,
			self::YORO_NOTE_META_VALUE
		);
	}

	private static function is_yoro_created_note( $comment ) {
		if ( ! $comment || ! is_object( $comment ) ) {
			return false;
		}

		$comment_id = absint( $comment->comment_ID ?? 0 );

		if ( $comment_id > 0 ) {
			$origin = (string) get_comment_meta( $comment_id, self::YORO_NOTE_META_KEY, true );

			if ( self::YORO_NOTE_META_VALUE === $origin ) {
				return true;
			}
		}

		$content = (string) $comment->comment_content;

		return false !== strpos( $content, 'YoRo AI note:' )
			|| false !== strpos( $content, '[YoRo]' );
	}

	public static function add_private_note_template( $params ) {
		$template  = ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '';
		$templates = self::get_private_note_templates();

		if ( empty( $template ) || empty( $templates[ $template ] ) ) {
			return new WP_Error( 'invalid_template', __( 'Invalid private note template.', 'yoro-ai-core' ) );
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

	public static function add_order_note( $params ) {
		$note = isset( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '';

		if ( empty( $note ) ) {
			return new WP_Error( 'invalid_params', __( 'Please tell me what note to add.', 'yoro-ai-core' ) );
		}

		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = array_map( 'absint', $params['order_ids'] );
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ absint( $params['order_id'] ) ];
		}

		$order_ids = array_values( array_filter( array_unique( $order_ids ) ) );

		if ( empty( $order_ids ) ) {
			return new WP_Error( 'invalid_params', __( 'Please choose an order first.', 'yoro-ai-core' ) );
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
						'reason' => __( 'I could not find that order.', 'yoro-ai-core' ),
					];
				continue;
			}

			$note_id = $order->add_order_note(
				self::build_yoro_note_content( $note ),
				0,
				true
			);

			if ( $note_id ) {
				$note_ids[] = absint( $note_id );
				self::mark_note_as_yoro_created( $note_id );
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
				__( 'Please choose a note to remove first.', 'yoro-ai-core' )
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

			if ( ! self::is_yoro_created_note( $comment ) ) {
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

	public static function preview_remove_order_note( $params ) {
		$note_ids = ! empty( $params['note_ids'] ) && is_array( $params['note_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $params['note_ids'] ) ) ) )
			: [];

		$notes = [];
		$block_reason = '';
		$message = __( 'This will remove the selected YoRo-created note after approval.', 'yoro-ai-core' );

		foreach ( $note_ids as $note_id ) {
			$comment = get_comment( $note_id );
			$is_order_note = $comment && 'order_note' === $comment->comment_type;
			$is_yoro_created = $comment && self::is_yoro_created_note( $comment );

			if ( ! $comment && '' === $block_reason ) {
				$block_reason = 'note_not_found';
				$message = sprintf(
					/* translators: %d: note ID */
					__( 'I could not find note %d. Choose an existing YoRo-created note before approving this change.', 'yoro-ai-core' ),
					$note_id
				);
			} elseif ( $comment && ! $is_order_note && '' === $block_reason ) {
				$block_reason = 'invalid_note_target';
				$message = sprintf(
					/* translators: %d: note ID */
					__( 'Note %d is not an order note, so it cannot be removed by this action.', 'yoro-ai-core' ),
					$note_id
				);
			} elseif ( $comment && ! $is_yoro_created && '' === $block_reason ) {
				$block_reason = 'protected_note';
				$message = sprintf(
					/* translators: %d: note ID */
					__( 'Note %d was not created by YoRo AI, so it cannot be removed by this action.', 'yoro-ai-core' ),
					$note_id
				);
			}

			$notes[] = [
				'id'           => $note_id,
				'exists'       => (bool) $comment,
				'order_id'     => $comment ? absint( $comment->comment_post_ID ) : 0,
				'is_order_note'=> (bool) $is_order_note,
				'yoro_created' => (bool) $is_yoro_created,
			];
		}

		if ( empty( $note_ids ) ) {
			$block_reason = 'missing_note_target';
			$message = __( 'Please choose a YoRo-created note before this can be approved.', 'yoro-ai-core' );
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Remove YoRo-created order note',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'notes'         => $notes,
			'note_ids'      => $note_ids,
			'count'         => count( $notes ),
			'changes_store' => '' === $block_reason,
		];
	}

	public static function preview_add_order_note( $params ) {
		$note = isset( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '';
		$order_ids = self::order_ids_from_params( $params );
		$orders = [];
		$missing_order_ids = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$missing_order_ids[] = $order_id;
			}

			$orders[] = [
				'id'            => $order_id,
				'exists'        => (bool) $order,
				'note_preview'  => $note,
			];
		}

		$block_reason = '';
		$message = __( 'This will add an internal note after approval.', 'yoro-ai-core' );

		if ( ! empty( $missing_order_ids ) ) {
			$block_reason = 'order_not_found';
			$message = sprintf(
				/* translators: %s: comma-separated order IDs */
				__( 'I could not find order %s. Choose an existing order before approving this change.', 'yoro-ai-core' ),
				implode( ', ', $missing_order_ids )
			);
		} elseif ( '' === $note ) {
			$block_reason = 'missing_note';
			$message = __( 'Please provide the internal note text before this can be approved.', 'yoro-ai-core' );
		} elseif ( empty( $order_ids ) ) {
			$block_reason = 'missing_order_target';
			$message = __( 'Please choose an order before this can be approved.', 'yoro-ai-core' );
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Add internal note to store order(s)',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'orders'        => $orders,
			'count'         => count( $orders ),
			'note_preview'  => $note,
			'changes_store' => '' === $block_reason,
		];
	}

	public static function update_order_status( $params ) {
		$order_ids = self::order_ids_from_params( $params );

		$status = ! empty( $params['status'] )
			? YoRo_Order_Helper::normalize_status_slug( $params['status'] )
			: '';

		$note   = ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : __( 'Order status updated by YoRo AI.', 'yoro-ai-core' );

		if ( empty( $order_ids ) || empty( $status ) ) {
			return new WP_Error( 'invalid_params', __( 'Please choose at least one order and the status to apply.', 'yoro-ai-core' ) );
		}

		if ( ! YoRo_Order_Helper::is_valid_order_status( $status ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
						/* translators: %s: order status */
						__( 'I do not recognize the order status "%s".', 'yoro-ai-core' ),
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
						'reason' => __( 'I could not find that order.', 'yoro-ai-core' ),
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
			'summary'      => sprintf(
				'Updated %d orders to %s.',
				count( $processed ),
				YoRo_Order_Helper::get_order_status_label( $status )
			),
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
				__( 'I could not find the previous order status to restore.', 'yoro-ai-core' )
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
						'reason' => __( 'I could not find that order.', 'yoro-ai-core' ),
					];
				continue;
			}

			try {
				$order->update_status(
					$status,
					__( 'Order status restored by YoRo undo.', 'yoro-ai-core' ),
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

	public static function preview_restore_order_statuses( $params ) {
		$statuses = isset( $params['statuses'] ) && is_array( $params['statuses'] )
			? $params['statuses']
			: [];

		$orders = [];
		$block_reason = '';
		$message = __( 'This will restore the selected order statuses after approval.', 'yoro-ai-core' );

		foreach ( $statuses as $order_id => $status ) {
			$order_id = absint( $order_id );
			$status = YoRo_Order_Helper::normalize_status_slug( $status );

			if ( ! $order_id || '' === $status ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order && '' === $block_reason ) {
				$block_reason = 'order_not_found';
				$message = sprintf(
					/* translators: %d: order ID */
					__( 'I could not find order %d. Choose an existing order before approving this change.', 'yoro-ai-core' ),
					$order_id
				);
			} elseif ( ! YoRo_Order_Helper::is_valid_order_status( $status ) && '' === $block_reason ) {
				$block_reason = 'invalid_status';
				$message = sprintf(
					/* translators: %s: order status */
					__( 'I do not recognize the order status "%s". Choose a configured order status before approving this change.', 'yoro-ai-core' ),
					$status
				);
			}

			$orders[] = [
				'id'             => $order_id,
				'exists'         => (bool) $order,
				'current_status' => $order ? $order->get_status() : '',
				'current_status_label' => $order ? YoRo_Order_Helper::get_order_status_label( $order->get_status() ) : '',
				'target_status'  => $status,
				'target_status_label' => YoRo_Order_Helper::get_order_status_label( $status ),
			];
		}

		if ( empty( $orders ) ) {
			$block_reason = 'missing_restore_statuses';
			$message = __( 'I could not find the previous order statuses to restore.', 'yoro-ai-core' );
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Restore store order statuses',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'orders'        => $orders,
			'statuses'      => $statuses,
			'count'         => count( $orders ),
			'changes_store' => '' === $block_reason,
		];
	}

	public static function preview_update_order_status( $params ) {
		$order_ids = self::order_ids_from_params( $params );
		$status = ! empty( $params['status'] )
			? YoRo_Order_Helper::normalize_status_slug( $params['status'] )
			: '';

		$orders = [];
		$missing_order_ids = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$missing_order_ids[] = $order_id;
			}

			$orders[] = [
				'id'             => $order_id,
				'exists'         => (bool) $order,
				'current_status' => $order ? $order->get_status() : '',
				'current_status_label' => $order ? YoRo_Order_Helper::get_order_status_label( $order->get_status() ) : '',
				'target_status'  => $status,
				'target_status_label' => YoRo_Order_Helper::get_order_status_label( $status ),
			];
		}

		$target_status_label = YoRo_Order_Helper::get_order_status_label( $status );
		$block_reason = '';
		$message = sprintf(
			/* translators: %s: target order status */
			__( 'This will update the selected order status to %s after approval.', 'yoro-ai-core' ),
			$target_status_label
		);

		if ( empty( $order_ids ) ) {
			$block_reason = 'missing_order_target';
			$message = __( 'Please choose an order before this can be approved.', 'yoro-ai-core' );
		} elseif ( '' === $status ) {
			$block_reason = 'missing_status';
			$message = __( 'Please choose a target order status before this can be approved.', 'yoro-ai-core' );
		} elseif ( ! YoRo_Order_Helper::is_valid_order_status( $status ) ) {
			$block_reason = 'invalid_status';
			$message = sprintf(
				/* translators: %s: order status */
				__( 'I do not recognize the order status "%s". Choose a configured order status before approving this change.', 'yoro-ai-core' ),
				$status
			);
		} elseif ( ! empty( $missing_order_ids ) ) {
			$block_reason = 'order_not_found';
			$message = sprintf(
				/* translators: %s: comma-separated order IDs */
				__( 'I could not find order %s. Choose an existing order before approving this change.', 'yoro-ai-core' ),
				implode( ', ', $missing_order_ids )
			);
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Update store order status',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'orders'        => $orders,
			'count'         => count( $orders ),
			'target_status' => $status,
			'target_status_label' => $target_status_label,
			'changes_store' => '' === $block_reason,
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
			'suspicious'         => __( 'YoRo review: This order has suspicious signals and should be manually reviewed before fulfillment.', 'yoro-ai-core' ),
			'payment_mismatch'   => __( 'YoRo review: Payment information may not match the customer/order identity. Please verify before fulfillment.', 'yoro-ai-core' ),
			'needs_verification' => __( 'YoRo review: Customer verification is required before this order is processed further.', 'yoro-ai-core' ),
		];
	}

	private static function order_ids_from_params( $params ) {
		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = array_map( 'absint', $params['order_ids'] );
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ absint( $params['order_id'] ) ];
		}

		return array_values( array_filter( array_unique( $order_ids ) ) );
	}
}
