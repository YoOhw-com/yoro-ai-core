<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Fulfillment_Write_Service {

	public static function add_tracking( $params ) {
		$contract = self::normalize_tracking_contract( $params );
		$error    = self::validate_tracking_contract( $contract );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$order = wc_get_order( $contract['order_id'] );

		if ( ! $order ) {
			return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
		}

		if ( ! YoRo_Integration_Registry::supports( 'orders.add_tracking', $contract['adapter_key'] ) ) {
			return new WP_Error(
				'yoro_integration_adapter_unavailable',
				__( 'The requested tracking adapter is not available.', 'yoro-ai-core' )
			);
		}

		if ( self::tracking_is_unchanged( $order, $contract ) ) {
			return new WP_Error(
				'tracking_already_exists',
				__( 'That tracking number is already saved on the order.', 'yoro-ai-core' )
			);
		}

		$result = YoRo_Integration_Registry::execute(
			'orders.add_tracking',
			[
				'order'    => $order,
				'tracking' => self::tracking_payload( $contract ),
			],
			$contract['adapter_key']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$summary = sprintf(
			/* translators: 1: tracking number, 2: order number */
			__( 'Added tracking %1$s to order #%2$s.', 'yoro-ai-core' ),
			$contract['tracking_number'],
			$order->get_order_number()
		);

		return [
			'success'      => true,
			'action'       => 'orders.add_tracking',
			'summary'      => $summary,
			'message'      => $summary,
			'order_id'     => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'tracking'     => self::tracking_payload( $contract ),
			'adapter_key'  => sanitize_text_field( $result['adapter_key'] ?? $contract['adapter_key'] ),
			'processed'    => [ $order->get_id() ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'result_type'  => 'tracking',
			'display_type' => 'generic',
			'meta'         => [
				'changes_store' => true,
				'adapter_key'    => sanitize_text_field( $result['adapter_key'] ?? $contract['adapter_key'] ),
			],
		];
	}

	public static function preview_add_tracking( $params ) {
		$contract = self::normalize_tracking_contract( $params );
		$error    = self::validate_tracking_contract( $contract );
		$order    = $contract['order_id'] > 0 ? wc_get_order( $contract['order_id'] ) : false;
		$block_reason = '';

		if ( is_wp_error( $error ) ) {
			$block_reason = $error->get_error_code();
			$message      = $error->get_error_message();
		} elseif ( ! $order ) {
			$block_reason = 'order_not_found';
			$message      = __( 'I could not find that order.', 'yoro-ai-core' );
		} elseif ( ! YoRo_Integration_Registry::supports( 'orders.add_tracking', $contract['adapter_key'] ) ) {
			$block_reason = 'yoro_integration_adapter_unavailable';
			$message      = __( 'The requested tracking adapter is not available.', 'yoro-ai-core' );
		} elseif ( self::tracking_is_unchanged( $order, $contract ) ) {
			$block_reason = 'tracking_already_exists';
			$message      = __( 'That tracking number is already saved on the order.', 'yoro-ai-core' );
		} else {
			$message = sprintf(
				/* translators: 1: tracking number, 2: order number */
				__( 'This will add tracking %1$s to order #%2$s after approval.', 'yoro-ai-core' ),
				$contract['tracking_number'],
				$order->get_order_number()
			);
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'orders.add_tracking',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'order_id'      => $contract['order_id'],
			'order_exists'  => (bool) $order,
			'tracking'      => self::tracking_payload( $contract ),
			'adapter_key'   => $contract['adapter_key'],
			'count'         => $order && '' === $block_reason ? 1 : 0,
			'changes_store' => '' === $block_reason,
		];
	}

	private static function normalize_tracking_contract( $params ) {
		$params = is_array( $params ) ? $params : [];
		$tracking_url = trim( (string) ( $params['tracking_url'] ?? '' ) );
		$shipped_at   = trim( (string) ( $params['shipped_at'] ?? '' ) );

		if ( '' !== $shipped_at ) {
			try {
				$shipped_at = ( new DateTimeImmutable( $shipped_at, wp_timezone() ) )->format( DATE_ATOM );
			} catch ( Exception $exception ) {
				$shipped_at = '__invalid__';
			}
		}

		return [
			'order_id'       => absint( $params['order_id'] ?? 0 ),
			'tracking_number' => sanitize_text_field( $params['tracking_number'] ?? '' ),
			'carrier'        => sanitize_text_field( $params['carrier'] ?? '' ),
			'tracking_url'   => '' !== $tracking_url ? esc_url_raw( $tracking_url ) : '',
			'tracking_url_input' => $tracking_url,
			'shipped_at'     => $shipped_at,
			'adapter_key'    => sanitize_text_field( $params['adapter_key'] ?? YoRo_Integration_Registry::CORE_TRACKING_ADAPTER ),
		];
	}

	private static function validate_tracking_contract( $contract ) {
		if ( $contract['order_id'] <= 0 ) {
			return new WP_Error( 'missing_order_target', __( 'Please choose an order first.', 'yoro-ai-core' ) );
		}

		if ( '' === $contract['tracking_number'] ) {
			return new WP_Error( 'missing_tracking_number', __( 'Please provide the shipment tracking number.', 'yoro-ai-core' ) );
		}

		if ( strlen( $contract['tracking_number'] ) > 190 ) {
			return new WP_Error( 'invalid_tracking_number', __( 'The tracking number is too long.', 'yoro-ai-core' ) );
		}

		if ( '' !== $contract['tracking_url_input'] && '' === $contract['tracking_url'] ) {
			return new WP_Error( 'invalid_tracking_url', __( 'Please provide a valid tracking URL.', 'yoro-ai-core' ) );
		}

		if ( '__invalid__' === $contract['shipped_at'] ) {
			return new WP_Error( 'invalid_shipped_at', __( 'Please provide a valid shipment date.', 'yoro-ai-core' ) );
		}

		return true;
	}

	private static function tracking_payload( $contract ) {
		return [
			'tracking_number' => $contract['tracking_number'],
			'carrier'         => $contract['carrier'],
			'tracking_url'    => $contract['tracking_url'],
			'shipped_at'      => '__invalid__' === $contract['shipped_at'] ? '' : $contract['shipped_at'],
		];
	}

	private static function tracking_is_unchanged( WC_Order $order, $contract ) {
		$tracking_number = (string) $contract['tracking_number'];

		if ( (string) $order->get_meta( '_yoro_tracking_number', true ) === $tracking_number ) {
			return true;
		}

		$shipment_items = $order->get_meta( '_wc_shipment_tracking_items', true );

		foreach ( is_array( $shipment_items ) ? $shipment_items : [] as $shipment_item ) {
			if ( is_array( $shipment_item ) && (string) ( $shipment_item['tracking_number'] ?? '' ) === $tracking_number ) {
				return true;
			}
		}

		return false;
	}
}
