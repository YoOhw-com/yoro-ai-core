<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Fulfillment_Lifecycle_Service {

	const STATUS_META  = '_yoro_fulfillment_status';
	const HISTORY_META = '_yoro_fulfillment_history';

	public static function get( $params ) {
		$order = self::order_from_params( $params );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return [
			'success'       => true,
			'action'        => 'orders.get_fulfillment',
			'order_id'      => $order->get_id(),
			'fulfillment'   => self::snapshot( $order ),
			'count'         => 1,
			'read_only'     => true,
			'changes_store' => false,
			'result_type'   => 'fulfillment_lifecycle',
		];
	}

	public static function update( $params ) {
		$order = self::order_from_params( $params );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$status = sanitize_key( is_array( $params ) ? ( $params['status'] ?? '' ) : '' );

		if ( ! in_array( $status, self::statuses(), true ) ) {
			return new WP_Error( 'invalid_fulfillment_status', __( 'Choose an advertised fulfillment lifecycle status.', 'yoro-ai-core' ) );
		}

		$before = sanitize_key( $order->get_meta( self::STATUS_META, true ) );
		$before = '' !== $before ? $before : 'unfulfilled';

		if ( $before !== $status ) {
			$history   = $order->get_meta( self::HISTORY_META, true );
			$history   = is_array( $history ) ? $history : [];
			$history[] = [
				'from'       => $before,
				'to'         => $status,
				'occurred_at'=> gmdate( DATE_ATOM ),
				'actor_id'   => get_current_user_id(),
			];

			$order->update_meta_data( self::STATUS_META, $status );
			$order->update_meta_data( self::HISTORY_META, array_slice( $history, -50 ) );
			$order->save();
		}

		return [
			'success'       => true,
			'action'        => 'orders.update_fulfillment',
			'order_id'      => $order->get_id(),
			'before_status' => $before,
			'fulfillment'   => self::snapshot( $order ),
			'count'         => 1,
			'read_only'     => false,
			'changes_store' => $before !== $status,
			'undoable'      => false,
			'result_type'   => 'fulfillment_lifecycle',
		];
	}

	public static function preview_update( $params ) {
		$order  = self::order_from_params( $params );
		$status = sanitize_key( is_array( $params ) ? ( $params['status'] ?? '' ) : '' );
		$reason = '';

		if ( is_wp_error( $order ) ) {
			$reason = $order->get_error_code();
		} elseif ( ! in_array( $status, self::statuses(), true ) ) {
			$reason = 'invalid_fulfillment_status';
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'orders.update_fulfillment',
			'blocked'       => '' !== $reason,
			'block_reason'  => $reason,
			'order_id'      => is_wp_error( $order ) ? absint( $params['order_id'] ?? 0 ) : $order->get_id(),
			'status'        => $status,
			'changes_store' => '' === $reason,
		];
	}

	public static function statuses() {
		return [ 'unfulfilled', 'ready_to_pack', 'packed', 'shipped', 'delivered', 'exception', 'cancelled' ];
	}

	private static function order_from_params( $params ) {
		$order_id = absint( is_array( $params ) ? ( $params['order_id'] ?? 0 ) : 0 );

		if ( $order_id <= 0 ) {
			return new WP_Error( 'missing_order_target', __( 'Please choose an order first.', 'yoro-ai-core' ) );
		}

		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order
			? $order
			: new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
	}

	private static function snapshot( WC_Order $order ) {
		$status  = sanitize_key( $order->get_meta( self::STATUS_META, true ) );
		$history = $order->get_meta( self::HISTORY_META, true );

		return [
			'status'          => '' !== $status ? $status : 'unfulfilled',
			'tracking_number' => sanitize_text_field( $order->get_meta( '_yoro_tracking_number', true ) ),
			'carrier'         => sanitize_text_field( $order->get_meta( '_yoro_tracking_carrier', true ) ),
			'history'         => array_values( is_array( $history ) ? $history : [] ),
		];
	}
}
