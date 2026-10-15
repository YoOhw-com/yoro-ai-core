<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Customer-safe read projection, constructed before the connector execution journal. */
class YoRo_Early_Access_Order_Read {
    const ACTIONS = [ 'orders.search', 'orders.get_details', 'orders.get_timeline' ];
    const STATUSES = [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];
    const MILESTONES = [ 'created' => 'Order created', 'paid' => 'Payment recorded', 'completed' => 'Order completed' ];

    public static function valid_params( $action, $params, $facts = [] ) {
        if ( ! in_array( $action, self::ACTIONS, true ) || ! is_array( $params ) ) { return false; }
        $allowed = 'orders.search' === $action ? [ 'order_id', 'status', 'date_from', 'date_to', 'limit' ]
            : ( 'orders.get_details' === $action ? [ 'order_ids' ] : [ 'order_id', 'limit' ] );
        if ( array_diff( array_keys( $params ), $allowed ) ) { return false; }
        if ( 'orders.get_details' === $action ) {
            $ids = $params['order_ids'] ?? null;
            return is_array( $ids ) && array_values( $ids ) === $ids && count( $ids ) >= 1 && count( $ids ) <= 5
                && count( array_unique( $ids, SORT_REGULAR ) ) === count( $ids )
                && count( array_filter( $ids, static function( $id ) { return is_int( $id ) && $id > 0; } ) ) === count( $ids );
        }
        if ( ! is_int( $params['limit'] ?? null ) || $params['limit'] < 1 || $params['limit'] > 20 ) { return false; }
        if ( ( 'orders.get_timeline' === $action || array_key_exists( 'order_id', $params ) )
            && ( ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1 ) ) { return false; }
        if ( array_key_exists( 'status', $params ) && ! in_array( $params['status'], self::STATUSES, true ) ) { return false; }
        foreach ( [ 'date_from', 'date_to' ] as $field ) {
            if ( array_key_exists( $field, $params ) ) {
                $date = is_string( $params[ $field ] ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $params[ $field ] ) : false;
                if ( ! $date || $date->format( 'Y-m-d' ) !== $params[ $field ] ) { return false; }
            }
        }
        if ( isset( $params['date_from'], $params['date_to'] ) && $params['date_from'] > $params['date_to'] ) { return false; }
        return 'orders.search' !== $action || ( is_int( $facts['order_page'] ?? null ) && $facts['order_page'] >= 1 && $facts['order_page'] <= 1000
            && ( ! isset( $params['order_id'] ) || 1 === $facts['order_page'] ) );
    }

    public static function execute( array $operation ) {
        $action = $operation['action'] ?? '';
        $params = $operation['params'] ?? [];
        $facts = $operation['extensions']['yoro.mcp_early_access_product_read'] ?? [];
        if ( ! self::valid_params( $action, $params, $facts ) || ! current_user_can( 'yoro_view_store_data' ) || ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'order_read_unavailable', 'Order read did not complete.' );
        }
        if ( 'orders.get_timeline' === $action ) {
            $order = wc_get_order( $params['order_id'] );
            if ( ! self::readable( $order ) ) { return new WP_Error( 'order_read_unavailable', 'Order read did not complete.' ); }
            $events = [];
            foreach ( [ 'created' => $order->get_date_created(), 'paid' => $order->get_date_paid(), 'completed' => $order->get_date_completed() ] as $type => $date ) {
                if ( $date ) { $events[] = [ 'id' => $order->get_id() . ':' . $type, 'type' => $type, 'visibility' => 'system',
                    'summary' => self::MILESTONES[ $type ], 'occurred_at' => $date->date( 'c' ), 'extensions' => (object) [] ]; }
            }
            usort( $events, static function( $a, $b ) { return strtotime( $a['occurred_at'] ) <=> strtotime( $b['occurred_at'] ); } );
            $events = array_slice( $events, 0, $params['limit'] );
            $data = [ 'order_id' => $params['order_id'], 'events' => $events, 'count' => count( $events ) ];
        } else {
            $details = 'orders.get_details' === $action;
            $has_next = false;
            if ( $details ) {
                $raw = array_map( 'wc_get_order', $params['order_ids'] );
            } elseif ( isset( $params['order_id'] ) ) {
                $raw = [ wc_get_order( $params['order_id'] ) ];
            } else {
                // WooCommerce CRUD supports both HPOS and legacy order storage. No post SQL or note reads.
                $args = [ 'type' => 'shop_order', 'limit' => $params['limit'], 'page' => $facts['order_page'], 'paginate' => true,
                    'orderby' => 'ID', 'order' => 'DESC', 'status' => isset( $params['status'] ) ? [ $params['status'] ] : self::STATUSES ];
                $from = isset( $params['date_from'] ) ? new DateTimeImmutable( $params['date_from'], wp_timezone() ) : null;
                $to = isset( $params['date_to'] ) ? ( new DateTimeImmutable( $params['date_to'], wp_timezone() ) )->modify( '+1 day' )->modify( '-1 second' ) : null;
                if ( $from && $to ) { $args['date_created'] = $from->getTimestamp() . '...' . $to->getTimestamp(); }
                elseif ( $from ) { $args['date_created'] = '>=' . $from->getTimestamp(); }
                elseif ( $to ) { $args['date_created'] = '<=' . $to->getTimestamp(); }
                $result = wc_get_orders( $args );
                $raw = $result->orders;
                $has_next = ! isset( $params['order_id'] ) && $facts['order_page'] < 1000 && $result->max_num_pages > $facts['order_page'];
            }
            $orders = [];
            foreach ( $raw as $order ) {
                if ( self::readable( $order ) && self::matches( $order, $params ) ) { $orders[] = self::order( $order, $details ); }
            }
            $data = [ 'orders' => $orders, 'count' => count( $orders ) ];
            if ( ! $details && count( $orders ) < $params['limit'] ) { $has_next = false; }
            if ( ! $details ) { $data['page_info'] = [ 'has_next_page' => $has_next, 'next_cursor' => $has_next ? (string) ( $facts['order_page'] + 1 ) : null ]; }
        }
        $message = 'Order read completed. Customer contact data is redacted; notes and addresses are excluded.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message, 'data' => $data, 'read_only' => true, 'undoable' => false,
            'result_type' => 'orders.search' === $action ? 'orders' : ( 'orders.get_details' === $action ? 'order_details' : 'order_timeline' ),
            'display_type' => 'orders.search' === $action ? 'orders_list' : ( 'orders.get_details' === $action ? 'order_details' : 'order_timeline' ),
            'assistant_summary' => $message, 'extensions' => (object) [] ];
    }

    private static function readable( $order ) {
        return $order instanceof WC_Order && 'shop_order' === $order->get_type() && in_array( $order->get_status(), self::STATUSES, true ) && $order->get_date_created();
    }

    private static function matches( WC_Order $order, array $params ) {
        $date = $order->get_date_created()->date( 'Y-m-d' );
        return ( ! isset( $params['order_id'] ) || $order->get_id() === $params['order_id'] )
            && ( ! isset( $params['order_ids'] ) || in_array( $order->get_id(), $params['order_ids'], true ) )
            && ( ! isset( $params['status'] ) || $order->get_status() === $params['status'] )
            && ( ! isset( $params['date_from'] ) || $date >= $params['date_from'] )
            && ( ! isset( $params['date_to'] ) || $date <= $params['date_to'] );
    }

    private static function order( WC_Order $order, $details ) {
        $status = $order->get_status();
        $number = (string) $order->get_order_number();
        if ( ! preg_match( '/\A[A-Za-z0-9#._-]{1,64}\z/D', $number ) ) { $number = (string) $order->get_id(); }
        $row = [ 'id' => (int) $order->get_id(), 'number' => $number, 'status' => $status, 'status_label' => ucwords( str_replace( '-', ' ', $status ) ),
            'total' => (string) $order->get_total(), 'currency' => $order->get_currency(), 'created_at' => $order->get_date_created()->date( 'c' ),
            'payment' => [ 'status' => 'refunded' === $status ? 'refunded' : ( $order->get_date_paid() ? 'paid' : ( 'failed' === $status ? 'failed' : 'unpaid' ) ),
                'paid' => (bool) $order->get_date_paid(), 'failed' => 'failed' === $status, 'refunded' => 'refunded' === $status, 'amount' => (string) $order->get_total(), 'currency' => $order->get_currency() ],
            'fulfillment' => [ 'status' => 'completed' === $status ? 'completed' : 'not_confirmed', 'fulfilled' => 'completed' === $status,
                'requires_shipping' => $order->needs_shipping_address(), 'tracking_count' => 0 ],
            'signals' => [], 'suggested_next_steps' => [], 'extensions' => [ 'yoro.order_read' => [ 'line_items_truncated' => false ] ] ];
        if ( $details ) {
            $items = $order->get_items( 'line_item' );
            $row['extensions']['yoro.order_read']['line_items_truncated'] = count( $items ) > 20;
            $row += [ 'customer' => [ 'id' => null, 'name' => 'Redacted', 'email' => '***', 'phone' => '***' ], 'line_items' => [], 'timeline' => [], 'related_orders' => [],
                'support_context' => [ 'available' => false, 'interaction_count' => 0, 'last_interaction_at' => 'Not included', 'summary' => 'Not included in this read.' ] ];
            foreach ( array_slice( $items, 0, 20 ) as $item ) {
                $product = $item->get_product();
                $row['line_items'][] = [ 'name' => $product ? mb_strcut( sanitize_text_field( $product->get_name() ), 0, 256, 'UTF-8' ) : 'Order item',
                    'quantity' => (int) $item->get_quantity(), 'sku' => $product ? mb_strcut( sanitize_text_field( $product->get_sku() ), 0, 128, 'UTF-8' ) : '', 'total' => (string) $item->get_total() ];
            }
        }
        $row['version'] = hash( 'sha256', wp_json_encode( $row ) );
        return $row;
    }
}
