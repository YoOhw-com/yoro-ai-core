<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Order_Return_Service {

	const REQUESTS_META_KEY = '_yoro_return_requests';
	const LOCK_OPTION_PREFIX = 'yoro_ai_return_lock_';
	const RECORD_LIMIT       = 50;
	const EVENT_LIMIT        = 100;

	public static function create_return_request( $params ) {
		$contract = self::normalize_contract( $params );
		$error    = self::validate_contract( $contract );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$order = wc_get_order( $contract['order_id'] );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
		}

		$error = self::validate_line_items( $order, $contract['line_items'] );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$existing = self::find_existing( $order, $contract );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( is_array( $existing ) ) {
			return self::success_result( $order, $existing, true );
		}

		$lock_key = self::lock_key( $order, $contract );

		if ( ! add_option( $lock_key, time(), '', 'no' ) ) {
			return new WP_Error( 'return_request_in_progress', __( 'The same return request is already being processed.', 'yoro-ai-core' ) );
		}

		try {
			$existing = self::find_existing( $order, $contract );

			if ( is_array( $existing ) ) {
				return self::success_result( $order, $existing, true );
			}

			$request = [
				'request_id'     => wp_generate_uuid4(),
				'type'           => $contract['type'],
				'line_items'     => $contract['line_items'],
				'reason'         => $contract['reason'],
				'resolution'     => $contract['resolution'],
				'idempotency_hash' => self::idempotency_hash( $contract['idempotency_key'] ),
				'contract_hash'  => self::contract_hash( $contract ),
				'requested_at'   => gmdate( DATE_ATOM ),
				'status'         => 'requested',
				'updated_at'     => gmdate( DATE_ATOM ),
			];
			$request['events'] = [
				[
					'event_id'        => wp_generate_uuid4(),
					'event_type'      => 'return_request.created',
					'from_status'     => '',
					'to_status'       => 'requested',
					'note'            => '',
					'idempotency_hash' => $request['idempotency_hash'],
					'contract_hash'   => $request['contract_hash'],
					'occurred_at'     => $request['requested_at'],
				],
			];
			$requests = array_values( array_filter( (array) $order->get_meta( self::REQUESTS_META_KEY, true ), 'is_array' ) );
			$requests[] = $request;
			$requests = array_slice( $requests, -self::RECORD_LIMIT );
			$order->update_meta_data( self::REQUESTS_META_KEY, $requests );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: 1: return type, 2: request ID */
					__( '[YoRo] Recorded %1$s request %2$s for operator review. No refund, replacement, or stock change was executed.', 'yoro-ai-core' ),
					$contract['type'],
					$request['request_id']
				),
				0,
				false
			);

			return self::success_result( $order, $request, false );
		} finally {
			delete_option( $lock_key );
		}
	}

	public static function update_return_request_status( $params ) {
		$contract = self::normalize_lifecycle_contract( $params );
		$error    = self::validate_lifecycle_contract( $contract );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$order = wc_get_order( $contract['order_id'] );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
		}

		$lock_key = self::lifecycle_lock_key( $contract );

		if ( ! add_option( $lock_key, time(), '', 'no' ) ) {
			return new WP_Error( 'return_request_transition_in_progress', __( 'That return request is already being reviewed.', 'yoro-ai-core' ) );
		}

		try {
			$order    = wc_get_order( $contract['order_id'] );
			$requests = $order instanceof WC_Order
				? array_values( array_filter( (array) $order->get_meta( self::REQUESTS_META_KEY, true ), 'is_array' ) )
				: [];
			$index    = self::request_index( $requests, $contract['request_id'] );

			if ( null === $index ) {
				return new WP_Error( 'return_request_not_found', __( 'I could not find that return request on the order.', 'yoro-ai-core' ) );
			}

			$request = $requests[ $index ];
			$existing_event = self::find_lifecycle_event( $request, $contract );

			if ( is_wp_error( $existing_event ) ) {
				return $existing_event;
			}

			if ( is_array( $existing_event ) ) {
				return self::lifecycle_success_result( $order, $request, $existing_event, true );
			}

			$from_status = sanitize_key( $request['status'] ?? 'requested' );
			$error       = self::validate_transition( $from_status, $contract['status'] );

			if ( is_wp_error( $error ) ) {
				return $error;
			}

			$event = [
				'event_id'        => wp_generate_uuid4(),
				'event_type'      => 'return_request.status_changed',
				'from_status'     => $from_status,
				'to_status'       => $contract['status'],
				'note'             => $contract['note'],
				'idempotency_hash' => self::idempotency_hash( $contract['idempotency_key'] ),
				'contract_hash'   => self::lifecycle_contract_hash( $contract ),
				'occurred_at'     => gmdate( DATE_ATOM ),
			];
			$events = array_values( array_filter( (array) ( $request['events'] ?? [] ), 'is_array' ) );
			$events[] = $event;
			$request['events']     = array_slice( $events, -self::EVENT_LIMIT );
			$request['status']     = $contract['status'];
			$request['updated_at'] = $event['occurred_at'];
			$requests[ $index ]    = $request;
			$order->update_meta_data( self::REQUESTS_META_KEY, $requests );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: 1: request ID, 2: previous status, 3: new status */
					__( '[YoRo] Return request %1$s changed from %2$s to %3$s. Refund, replacement, customer contact, and stock changes remain separate operations.', 'yoro-ai-core' ),
					$contract['request_id'],
					$from_status,
					$contract['status']
				),
				0,
				false
			);

			return self::lifecycle_success_result( $order, $request, $event, false );
		} finally {
			delete_option( $lock_key );
		}
	}

	public static function preview_update_return_request_status( $params ) {
		$contract = self::normalize_lifecycle_contract( $params );
		$error    = self::validate_lifecycle_contract( $contract );
		$order    = ! is_wp_error( $error ) ? wc_get_order( $contract['order_id'] ) : false;
		$requests = $order instanceof WC_Order
			? array_values( array_filter( (array) $order->get_meta( self::REQUESTS_META_KEY, true ), 'is_array' ) )
			: [];
		$index    = self::request_index( $requests, $contract['request_id'] );
		$request  = null !== $index ? $requests[ $index ] : null;

		if ( ! is_wp_error( $error ) && ! is_array( $request ) ) {
			$error = new WP_Error( 'return_request_not_found', __( 'I could not find that return request on the order.', 'yoro-ai-core' ) );
		}

		if ( ! is_wp_error( $error ) ) {
			$error = self::validate_transition( sanitize_key( $request['status'] ?? 'requested' ), $contract['status'] );
		}

		return [
			'type'                  => 'write',
			'will_execute'          => false,
			'action'                => 'orders.update_return_request',
			'blocked'               => is_wp_error( $error ),
			'block_reason'          => is_wp_error( $error ) ? $error->get_error_code() : '',
			'order_id'              => $contract['order_id'],
			'request_id'            => $contract['request_id'],
			'from_status'           => is_array( $request ) ? sanitize_key( $request['status'] ?? 'requested' ) : '',
			'to_status'             => $contract['status'],
			'requires_confirmation' => true,
			'execution_scope'        => 'return_request_status_only',
			'financial_effect'       => false,
			'inventory_effect'       => false,
			'customer_contact_effect'=> false,
			'changes_store'          => ! is_wp_error( $error ),
		];
	}

	public static function preview_create_return_request( $params ) {
		$contract = self::normalize_contract( $params );
		$error    = self::validate_contract( $contract );
		$order    = $contract['order_id'] > 0 ? wc_get_order( $contract['order_id'] ) : false;

		if ( ! is_wp_error( $error ) && $order instanceof WC_Order ) {
			$error = self::validate_line_items( $order, $contract['line_items'] );
		}

		$existing = ! is_wp_error( $error ) && $order instanceof WC_Order ? self::find_existing( $order, $contract ) : null;
		$blocked  = is_wp_error( $error ) || ! $order;
		$replay   = is_array( $existing );

		return [
			'type'                  => 'write',
			'will_execute'          => false,
			'action'                => 'orders.create_return_request',
			'blocked'               => $blocked,
			'block_reason'          => is_wp_error( $error ) ? $error->get_error_code() : ( ! $order ? 'order_not_found' : '' ),
			'order_id'              => $contract['order_id'],
			'request_type'           => $contract['type'],
			'resolution'             => $contract['resolution'],
			'line_items'             => $contract['line_items'],
			'idempotency_key_set'    => '' !== $contract['idempotency_key'],
			'idempotent_replay'      => $replay,
			'requires_confirmation' => true,
			'execution_scope'        => 'record_request_only',
			'financial_effect'       => false,
			'inventory_effect'       => false,
			'customer_contact_effect'=> false,
			'changes_store'          => ! $blocked && ! $replay,
		];
	}

	private static function normalize_contract( $params ) {
		$params = is_array( $params ) ? $params : [];
		$items  = [];

		foreach ( (array) ( $params['line_items'] ?? [] ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$items[] = [
				'order_item_id' => absint( $item['order_item_id'] ?? 0 ),
				'quantity'      => absint( $item['quantity'] ?? 0 ),
			];
		}

		return [
			'order_id'       => absint( $params['order_id'] ?? 0 ),
			'type'           => sanitize_key( $params['type'] ?? '' ),
			'line_items'     => $items,
			'reason'         => sanitize_textarea_field( $params['reason'] ?? '' ),
			'resolution'     => sanitize_key( $params['resolution'] ?? '' ),
			'idempotency_key' => sanitize_text_field( $params['idempotency_key'] ?? '' ),
		];
	}

	private static function normalize_lifecycle_contract( $params ) {
		$params = is_array( $params ) ? $params : [];

		return [
			'order_id'       => absint( $params['order_id'] ?? 0 ),
			'request_id'     => sanitize_text_field( $params['request_id'] ?? '' ),
			'status'         => sanitize_key( $params['status'] ?? '' ),
			'note'           => sanitize_textarea_field( $params['note'] ?? '' ),
			'idempotency_key'=> sanitize_text_field( $params['idempotency_key'] ?? '' ),
		];
	}

	private static function validate_lifecycle_contract( $contract ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		if ( $contract['order_id'] < 1 || '' === $contract['request_id'] || '' === $contract['idempotency_key'] ) {
			return new WP_Error( 'return_lifecycle_target_missing', __( 'An exact order, return request, and idempotency key are required.', 'yoro-ai-core' ) );
		}

		if ( ! in_array( $contract['status'], [ 'approved', 'rejected', 'cancelled', 'completed' ], true ) ) {
			return new WP_Error( 'invalid_return_request_status', __( 'Choose approved, rejected, cancelled, or completed.', 'yoro-ai-core' ) );
		}

		return true;
	}

	private static function validate_transition( $from_status, $to_status ) {
		$transitions = [
			'requested' => [ 'approved', 'rejected', 'cancelled' ],
			'approved'  => [ 'completed', 'cancelled' ],
			'rejected'  => [],
			'cancelled' => [],
			'completed' => [],
		];

		if ( ! isset( $transitions[ $from_status ] ) || ! in_array( $to_status, $transitions[ $from_status ], true ) ) {
			return new WP_Error( 'invalid_return_request_transition', __( 'That return request status transition is not allowed.', 'yoro-ai-core' ) );
		}

		return true;
	}

	private static function request_index( $requests, $request_id ) {
		foreach ( $requests as $index => $request ) {
			if ( is_array( $request ) && hash_equals( (string) ( $request['request_id'] ?? '' ), (string) $request_id ) ) {
				return $index;
			}
		}

		return null;
	}

	private static function find_lifecycle_event( $request, $contract ) {
		$key_hash      = self::idempotency_hash( $contract['idempotency_key'] );
		$contract_hash = self::lifecycle_contract_hash( $contract );

		foreach ( (array) ( $request['events'] ?? [] ) as $event ) {
			if ( ! is_array( $event ) || ! hash_equals( $key_hash, (string) ( $event['idempotency_hash'] ?? '' ) ) ) {
				continue;
			}

			if ( ! hash_equals( $contract_hash, (string) ( $event['contract_hash'] ?? '' ) ) ) {
				return new WP_Error( 'return_lifecycle_idempotency_conflict', __( 'That idempotency key was already used for a different return review decision.', 'yoro-ai-core' ) );
			}

			return $event;
		}

		return null;
	}

	private static function lifecycle_success_result( WC_Order $order, $request, $event, $replay ) {
		$summary = $replay
			? __( 'Returned the previously recorded return request review event without creating a duplicate.', 'yoro-ai-core' )
			: __( 'Updated only the return request lifecycle. Refund, replacement, customer contact, and stock changes were not executed.', 'yoro-ai-core' );

		return [
			'success'           => true,
			'action'            => 'orders.update_return_request',
			'summary'           => $summary,
			'message'           => $summary,
			'order_id'          => $order->get_id(),
			'request_id'        => sanitize_text_field( $request['request_id'] ?? '' ),
			'status'            => sanitize_key( $request['status'] ?? '' ),
			'event'             => $event,
			'events'            => array_values( (array) ( $request['events'] ?? [] ) ),
			'idempotent_replay' => (bool) $replay,
			'financial_effect'  => false,
			'inventory_effect'  => false,
			'customer_contact_effect' => false,
			'changes_store'     => ! $replay,
			'connector_event'   => [
				'event_type' => sanitize_text_field( $event['event_type'] ?? 'return_request.status_changed' ),
				'event_id'   => sanitize_text_field( $event['event_id'] ?? '' ),
				'order_id'   => $order->get_id(),
				'request_id' => sanitize_text_field( $request['request_id'] ?? '' ),
			],
			'processed'         => $replay ? [] : [ sanitize_text_field( $event['event_id'] ?? '' ) ],
			'skipped'           => [],
			'errors'            => [],
			'count'             => $replay ? 0 : 1,
			'result_type'       => 'generic',
			'display_type'      => 'generic',
		];
	}

	private static function validate_contract( $contract ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		if ( $contract['order_id'] < 1 || empty( $contract['line_items'] ) ) {
			return new WP_Error( 'invalid_return_target', __( 'Choose an order and at least one exact order item quantity.', 'yoro-ai-core' ) );
		}

		if ( ! in_array( $contract['type'], [ 'return', 'exchange' ], true ) ) {
			return new WP_Error( 'invalid_return_type', __( 'Return type must be return or exchange.', 'yoro-ai-core' ) );
		}

		if ( ! in_array( $contract['resolution'], [ 'refund', 'replacement', 'store_credit', 'review' ], true ) ) {
			return new WP_Error( 'invalid_return_resolution', __( 'Choose refund, replacement, store credit, or review as the requested resolution.', 'yoro-ai-core' ) );
		}

		if ( '' === $contract['reason'] || '' === $contract['idempotency_key'] ) {
			return new WP_Error( 'return_content_missing', __( 'A user-provided reason and idempotency key are required.', 'yoro-ai-core' ) );
		}

		return true;
	}

	private static function validate_line_items( WC_Order $order, $line_items ) {
		$seen = [];

		foreach ( $line_items as $item ) {
			$item_id  = absint( $item['order_item_id'] ?? 0 );
			$quantity = absint( $item['quantity'] ?? 0 );
			$order_item = $order->get_item( $item_id );

			if ( $item_id < 1 || $quantity < 1 || isset( $seen[ $item_id ] ) || ! $order_item instanceof WC_Order_Item_Product ) {
				return new WP_Error( 'invalid_return_line_item', __( 'Each return line must reference one unique product order item and a positive quantity.', 'yoro-ai-core' ) );
			}

			if ( $quantity > absint( $order_item->get_quantity() ) ) {
				return new WP_Error( 'return_quantity_exceeded', __( 'A requested return quantity exceeds the ordered quantity.', 'yoro-ai-core' ) );
			}

			$seen[ $item_id ] = true;
		}

		return true;
	}

	private static function find_existing( WC_Order $order, $contract ) {
		$key_hash      = self::idempotency_hash( $contract['idempotency_key'] );
		$contract_hash = self::contract_hash( $contract );

		foreach ( (array) $order->get_meta( self::REQUESTS_META_KEY, true ) as $request ) {
			if ( ! is_array( $request ) || ! hash_equals( $key_hash, (string) ( $request['idempotency_hash'] ?? '' ) ) ) {
				continue;
			}

			if ( ! hash_equals( $contract_hash, (string) ( $request['contract_hash'] ?? '' ) ) ) {
				return new WP_Error( 'return_idempotency_conflict', __( 'That idempotency key was already used for a different return request.', 'yoro-ai-core' ) );
			}

			return $request;
		}

		return null;
	}

	private static function success_result( WC_Order $order, $request, $replay ) {
		$summary = $replay
			? __( 'Returned the previously recorded return request without creating a duplicate.', 'yoro-ai-core' )
			: __( 'Recorded the return request for operator review without refunding, replacing, contacting the customer, or changing stock.', 'yoro-ai-core' );

		return [
			'success'           => true,
			'action'            => 'orders.create_return_request',
			'summary'           => $summary,
			'message'           => $summary,
			'order_id'          => $order->get_id(),
			'request_id'        => sanitize_text_field( $request['request_id'] ?? '' ),
			'request_type'      => sanitize_key( $request['type'] ?? '' ),
			'resolution'        => sanitize_key( $request['resolution'] ?? '' ),
			'line_items'        => array_values( (array) ( $request['line_items'] ?? [] ) ),
			'idempotent_replay' => (bool) $replay,
			'read_only'         => false,
			'changes_store'     => ! $replay,
			'financial_effect'  => false,
			'inventory_effect'  => false,
			'processed'         => $replay ? [] : [ sanitize_text_field( $request['request_id'] ?? '' ) ],
			'skipped'           => [],
			'errors'            => [],
			'count'             => $replay ? 0 : 1,
			'result_type'       => 'generic',
			'display_type'      => 'generic',
		];
	}

	private static function idempotency_hash( $key ) {
		return hash_hmac( 'sha256', (string) $key, wp_salt( 'nonce' ) );
	}

	private static function contract_hash( $contract ) {
		$copy = $contract;
		unset( $copy['idempotency_key'] );

		return hash( 'sha256', wp_json_encode( $copy ) );
	}

	private static function lifecycle_contract_hash( $contract ) {
		$copy = $contract;
		unset( $copy['idempotency_key'] );

		return hash( 'sha256', wp_json_encode( $copy ) );
	}

	private static function lock_key( WC_Order $order, $contract ) {
		return self::LOCK_OPTION_PREFIX . $order->get_id() . '_' . substr( self::idempotency_hash( $contract['idempotency_key'] ), 0, 24 );
	}

	private static function lifecycle_lock_key( $contract ) {
		return self::LOCK_OPTION_PREFIX . absint( $contract['order_id'] ) . '_' . substr( hash( 'sha256', (string) $contract['request_id'] ), 0, 12 ) . '_' . substr( self::idempotency_hash( $contract['idempotency_key'] ), 0, 12 );
	}
}
