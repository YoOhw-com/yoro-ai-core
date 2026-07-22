<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Order_Return_Service {

	const REQUESTS_META_KEY = '_yoro_return_requests';
	const LOCK_OPTION_PREFIX = 'yoro_ai_return_lock_';
	const RECORD_LIMIT       = 50;

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
			'idempotency_key'=> sanitize_text_field( $params['idempotency_key'] ?? '' ),
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

	private static function lock_key( WC_Order $order, $contract ) {
		return self::LOCK_OPTION_PREFIX . $order->get_id() . '_' . substr( self::idempotency_hash( $contract['idempotency_key'] ), 0, 24 );
	}
}
