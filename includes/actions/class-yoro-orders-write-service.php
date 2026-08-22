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
		$prepared = self::prepare_order_status_write( $params );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];

		foreach ( $prepared['targets'] as $target ) {
			$order_id = $target['order_id'];
			$order = $target['order'];

			if ( ! $order ) {
					$skipped[] = [
						'id'     => $order_id,
						'reason' => __( 'I could not find that order.', 'yoro-ai-core' ),
					];
				continue;
			}

			try {
				$order->update_status( $prepared['status'], $prepared['note'] );
				$processed[] = $order_id;
			} catch ( Exception $e ) {
				$errors[] = [
					'id'      => $order_id,
					'message' => $e->getMessage(),
				];
			}
		}

		return self::build_order_status_write_result( $prepared, $processed, $skipped, $errors );
	}

	public static function prepare_order_status_write( $params, $constraints = [] ) {
		$params = is_array( $params ) ? $params : [];
		$constraints = is_array( $constraints ) ? $constraints : [];
		$order_ids = self::order_ids_from_params( $params );
		$status = ! empty( $params['status'] )
			? YoRo_Order_Helper::normalize_status_slug( $params['status'] )
			: '';
		$note = ! empty( $params['note'] )
			? sanitize_textarea_field( $params['note'] )
			: __( 'Order status updated by YoRo AI.', 'yoro-ai-core' );

		if ( empty( $order_ids ) || empty( $status ) ) {
			return new WP_Error( 'invalid_params', __( 'Please choose at least one order and the status to apply.', 'yoro-ai-core' ) );
		}

		if ( ! empty( $constraints['canonical_order_ids_only'] ) && ! self::has_one_canonical_order_id_only( $params ) ) {
			return new WP_Error( 'native_order_target_not_covered', __( 'The native order capability requires one exact order ID.', 'yoro-ai-core' ) );
		}

		if ( ! empty( $constraints['single_target'] ) && 1 !== count( $order_ids ) ) {
			return new WP_Error( 'native_order_target_not_covered', __( 'The native order capability only covers one exact order.', 'yoro-ai-core' ) );
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

		$allowed_statuses = isset( $constraints['allowed_statuses'] ) && is_array( $constraints['allowed_statuses'] )
			? array_values( array_unique( array_map( 'sanitize_key', $constraints['allowed_statuses'] ) ) )
			: [];

		if ( ! empty( $allowed_statuses ) && ! in_array( $status, $allowed_statuses, true ) ) {
			return new WP_Error( 'native_order_status_not_covered', __( 'The native order capability does not cover this status.', 'yoro-ai-core' ) );
		}

		$version_validation = self::validate_order_versions( $order_ids, $params );

		if ( is_wp_error( $version_validation ) ) {
			return $version_validation;
		}

		$targets = [];

		foreach ( $order_ids as $order_id ) {
			$order = $version_validation['orders'][ $order_id ] ?? false;

			if ( ! $order instanceof WC_Order ) {
				return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
			}

			if ( ! empty( $constraints['status_must_change'] ) && $status === $order->get_status() ) {
				return new WP_Error( 'native_order_status_unchanged', __( 'The native order capability does not cover an unchanged status.', 'yoro-ai-core' ) );
			}

			$targets[] = [
				'order_id'         => $order_id,
				'order'            => $order,
				'previous_status'  => $order->get_status(),
				'expected_version' => $version_validation['version_map'][ $order_id ],
			];
		}

		return [
			'order_ids' => $order_ids,
			'status'    => $status,
			'note'      => $note,
			'targets'   => $targets,
		];
	}

	public static function build_order_status_write_result( array $prepared, array $processed, array $skipped = [], array $errors = [] ) {
		$status = sanitize_key( $prepared['status'] ?? '' );
		$previous_statuses = [];

		foreach ( $prepared['targets'] ?? [] as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$previous_statuses[] = [
				'order_id' => absint( $target['order_id'] ?? 0 ),
				'status'   => sanitize_key( $target['previous_status'] ?? '' ),
			];
		}

		$current_versions = [];

		foreach ( $processed as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				$current_versions[] = [
					'order_id' => $order_id,
					'expected_version' => YoRo_Order_Presenter::order_version( $order ),
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
			'undo_plan'    => empty( $previous_statuses ) ? [] : [
				[
					'action' => 'orders.restore_statuses',
					'params' => [
						'statuses' => $previous_statuses,
						'expected_versions' => $current_versions,
					],
				],
			],
			'current_versions' => $current_versions,
		];
	}

	public static function restore_order_statuses( $params ) {
		$statuses = self::normalize_restore_statuses( $params['statuses'] ?? [] );

		if ( empty( $statuses ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'I could not find the previous order status to restore.', 'yoro-ai-core' )
			);
		}

		$version_validation = self::validate_order_versions( wp_list_pluck( $statuses, 'order_id' ), $params );

		if ( is_wp_error( $version_validation ) ) {
			return $version_validation;
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];

		foreach ( $statuses as $status_item ) {
			$order_id = absint( $status_item['order_id'] ?? 0 );
			$status   = sanitize_key( $status_item['status'] ?? '' );

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
		$statuses = self::normalize_restore_statuses( $params['statuses'] ?? [] );

		$orders = [];
		$block_reason = '';
		$message = __( 'This will restore the selected order statuses after approval.', 'yoro-ai-core' );

		foreach ( $statuses as $status_item ) {
			$order_id = absint( $status_item['order_id'] ?? 0 );
			$status = YoRo_Order_Helper::normalize_status_slug( $status_item['status'] ?? '' );

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
		} elseif ( is_wp_error( self::validate_order_versions( $order_ids, $params ) ) ) {
			$block_reason = 'order_version_conflict';
			$message = __( 'At least one order changed after it was read. Refresh the order details before approving this status update.', 'yoro-ai-core' );
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

	private static function validate_order_versions( $order_ids, $params ) {
		$order_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $order_ids ) ) ) );
		$expected_versions = is_array( $params['expected_versions'] ?? null ) ? $params['expected_versions'] : [];
		$version_map = [];

		foreach ( $expected_versions as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$order_id = absint( $item['order_id'] ?? 0 );
			$expected_version = sanitize_text_field( $item['expected_version'] ?? '' );

			if ( $order_id < 1 || '' === $expected_version || isset( $version_map[ $order_id ] ) ) {
				return new WP_Error( 'order_version_contract_invalid', __( 'Every target order must have one unique expected version.', 'yoro-ai-core' ) );
			}

			$version_map[ $order_id ] = $expected_version;
		}

		if ( count( $version_map ) !== count( $order_ids ) || array_diff( $order_ids, array_keys( $version_map ) ) ) {
			return new WP_Error( 'order_version_contract_invalid', __( 'Every target order must have one expected version from the latest read.', 'yoro-ai-core' ) );
		}

		$orders = [];

		foreach ( $order_ids as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

			if ( ! $order instanceof WC_Order ) {
				return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
			}

			$current_version = YoRo_Order_Presenter::order_version( $order );

			if ( ! hash_equals( $current_version, $version_map[ $order_id ] ) ) {
				return new WP_Error(
					'order_version_conflict',
					__( 'At least one order changed after it was read. Refresh the order details before trying again.', 'yoro-ai-core' ),
					[ 'order_id' => $order_id, 'current_version' => $current_version ]
				);
			}

			$orders[ $order_id ] = $order;
		}

		return [
			'version_map' => $version_map,
			'orders'      => $orders,
		];
	}

	private static function normalize_restore_statuses( $statuses ) {
		$normalized = [];

		foreach ( is_array( $statuses ) ? $statuses : [] as $key => $item ) {
			if ( is_array( $item ) ) {
				$order_id = absint( $item['order_id'] ?? 0 );
				$status = sanitize_key( $item['status'] ?? '' );
			} else {
				$order_id = absint( $key );
				$status = sanitize_key( $item );
			}

			if ( $order_id > 0 && '' !== $status ) {
				$normalized[] = [
					'order_id' => $order_id,
					'status'   => $status,
				];
			}
		}

		return $normalized;
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

	private static function has_one_canonical_order_id_only( $params ) {
		if ( ! is_array( $params ) || ! isset( $params['order_ids'] ) || ! is_array( $params['order_ids'] ) || 1 !== count( $params['order_ids'] ) ) {
			return false;
		}

		if ( ! empty( $params['order_id'] ) ) {
			return false;
		}

		$order_id = reset( $params['order_ids'] );

		return is_int( $order_id ) && $order_id > 0;
	}
}
