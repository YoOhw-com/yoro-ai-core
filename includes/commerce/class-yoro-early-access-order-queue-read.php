<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded customer-safe queue projection before local journal persistence. */
class YoRo_Early_Access_Order_Queue_Read {
    const ACTIONS = [ 'operations.get_attention_queue', 'operations.get_fulfillment_queue' ];
    const REASONS = [
        'pending' => [ 'pending_payment_older_than_24h', 24, 'medium', 'Pending payment for at least 24 hours' ],
        'on-hold' => [ 'on_hold_older_than_48h', 48, 'medium', 'On hold for at least 48 hours' ],
        'processing' => [ 'processing_older_than_72h', 72, 'high', 'Processing for at least 72 hours' ],
        'failed' => [ 'recent_failed_orders', 0, 'high', 'Failed order created within the last 7 days' ],
    ];

    public static function valid_params( $action, $params ) {
        return in_array( $action, self::ACTIONS, true ) && is_array( $params ) && [ 'limit' ] === array_keys( $params )
            && is_int( $params['limit'] ) && $params['limit'] >= 1 && $params['limit'] <= 20;
    }

    public static function execute( array $operation ) {
        $action = $operation['action'] ?? '';
        $params = $operation['params'] ?? [];
        if ( ! self::valid_params( $action, $params ) || ! current_user_can( 'yoro_view_store_data' ) || ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'order_queue_unavailable', 'Order queue read did not complete.' );
        }
        $fulfillment = 'operations.get_fulfillment_queue' === $action;
        $applicable = ! $fulfillment || wc_shipping_enabled();
        $now = time();
        $raw = $applicable ? wc_get_orders( [ 'type' => 'shop_order', 'status' => $fulfillment ? [ 'processing' ] : array_keys( self::REASONS ),
            'limit' => 101, 'orderby' => 'ID', 'order' => 'ASC', 'page' => 1 ] ) : [];
        $has_more = count( $raw ) > 100;
        $raw = array_slice( $raw, 0, 100 );
        $items = [];
        foreach ( $raw as $order ) {
            if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || ! $order->get_date_created() ) { continue; }
            $status = $order->get_status();
            $reason = self::REASONS[ $status ] ?? null;
            $age = (int) floor( ( $now - $order->get_date_created()->getTimestamp() ) / HOUR_IN_SECONDS );
            $requires_shipping = YoRo_Orders_Read_Service::fulfillment_order_requires_shipping( $order );
            if ( ! $reason || $age < 0 || $age > 10000000 ) { continue; }
            if ( $fulfillment ) {
                if ( 'processing' !== $status || ! $requires_shipping ) { continue; }
                $reason = [ 'processing_requires_shipping', 0, 'low', 'Processing order requires shipping; dispatch is not verified' ];
            } elseif ( $age < $reason[1] || ( 'failed' === $status && $now - $order->get_date_created()->getTimestamp() > 7 * DAY_IN_SECONDS ) ) { continue; }
            $item = [ 'order_id' => (int) $order->get_id(), 'order_number' => (string) $order->get_id(), 'status' => $status,
                'status_label' => ucwords( str_replace( '-', ' ', $status ) ), 'total' => (string) $order->get_total(), 'currency' => $order->get_currency(),
                'created_at' => $order->get_date_created()->date( 'c' ), 'age_hours' => $age, 'reason' => $reason[3], 'severity' => $reason[2],
                'priority_score' => ( 'high' === $reason[2] ? 1000 : ( 'medium' === $reason[2] ? 500 : 100 ) ) + min( $age, 720 ),
                'extensions' => [ 'yoro.order_queue' => [ 'reason_code' => $reason[0], 'requires_shipping' => $requires_shipping ] ] ];
            $item['version'] = hash( 'sha256', wp_json_encode( $item ) );
            $items[] = $item;
        }
        usort( $items, static function( $a, $b ) { return ( $b['priority_score'] <=> $a['priority_score'] ) ?: ( $a['order_id'] <=> $b['order_id'] ); } );
        $matched = count( $items );
        $items = array_slice( $items, 0, $params['limit'] );
        $message = 'Bounded order queue snapshot. Customer contact data, addresses and notes are excluded. Counts cover only scanned candidates.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message,
            'data' => [ 'items' => $items, 'count' => count( $items ), 'total_matches' => $matched, 'page_info' => [ 'has_next_page' => false, 'next_cursor' => null ] ],
            'read_only' => true, 'undoable' => false, 'result_type' => 'order_queue', 'display_type' => 'order_queue', 'assistant_summary' => $message,
            'extensions' => [ 'yoro.order_queue' => [ 'version' => 1, 'scan_limit' => 100, 'scanned' => count( $raw ), 'has_more_candidates' => $has_more,
                'returned_truncated' => $matched > count( $items ), 'generated_at' => gmdate( 'c', $now ), 'applicable' => $applicable ] ] ];
    }
}
