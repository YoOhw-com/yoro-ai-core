<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Customer-safe read projection, constructed before the connector execution journal. */
class YoRo_Early_Access_Order_Read {
    const ACTIONS = [ 'orders.search', 'orders.get_details', 'orders.get_timeline' ];
    const STATUSES = [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];
    const MILESTONES = [ 'created' => 'Order created', 'paid' => 'Payment recorded', 'completed' => 'Order completed' ];

    public static function details_manifest() {
        return [ 'version' => 1, 'actions' => [ 'orders.get_details', 'orders.get_timeline' ],
            'customer_data' => 'selected_order_contacts', 'timeline' => 'notes_page_v1', 'max_notes' => 20 ];
    }

    public static function valid_params( $action, $params, $facts = [] ) {
        if ( ! in_array( $action, self::ACTIONS, true ) || ! is_array( $params ) || ! is_array( $facts ) ) { return false; }
        if ( array_key_exists( 'order_search', $facts ) && ! YoRo_Early_Access_Order_Search::valid_facts( $action, $params, $facts ) ) { return false; }
        if ( isset( $facts['order_projection_version'] ) && ( 1 !== $facts['order_projection_version']
            || ! in_array( $action, [ 'orders.get_details', 'orders.get_timeline' ], true )
            || 1 !== ( $facts['read_contract_version'] ?? null ) || 'orders' !== ( $facts['read_family'] ?? null ) ) ) { return false; }
        if ( isset( $facts['notes_page'] ) && ( 'orders.get_timeline' !== $action || 1 !== ( $facts['order_projection_version'] ?? null )
            || ! is_int( $facts['notes_page'] ) || $facts['notes_page'] < 1 || $facts['notes_page'] > 1000 ) ) { return false; }
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
        $enhanced = 1 === ( $facts['order_projection_version'] ?? null );
        $result_extensions = (object) [];
        if ( ! self::valid_params( $action, $params, $facts ) || ! current_user_can( 'yoro_view_store_data' ) || ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'order_read_unavailable', 'Order read did not complete.' );
        }
        if ( 'orders.search' === $action && isset( $facts['order_search'] ) ) { return YoRo_Early_Access_Order_Search::execute( $operation ); }
        if ( 'orders.get_timeline' === $action ) {
            $order = wc_get_order( $params['order_id'] );
            if ( ! self::readable( $order ) ) { return new WP_Error( 'order_read_unavailable', 'Order read did not complete.' ); }
            $events = [];
            foreach ( [ 'created' => $order->get_date_created(), 'paid' => $order->get_date_paid(), 'completed' => $order->get_date_completed() ] as $type => $date ) {
                if ( $date ) { $events[] = [ 'id' => $order->get_id() . ':' . $type, 'type' => $type, 'visibility' => 'system',
                    'summary' => self::MILESTONES[ $type ], 'occurred_at' => $date->date( 'c' ), 'extensions' => (object) [] ]; }
            }
            if ( $enhanced ) {
                $page = $facts['notes_page'] ?? 1;
                $notes = array_values( wc_get_order_notes( [ 'order_id' => $order->get_id(), 'limit' => $params['limit'] + 1,
                    'offset' => ( $page - 1 ) * $params['limit'], 'orderby' => 'id', 'order' => 'DESC' ] ) );
                $has_next = count( $notes ) > $params['limit'] && $page < 1000;
                foreach ( array_slice( $notes, 0, $params['limit'] ) as $note ) {
                    $plain = html_entity_decode( wp_strip_all_tags( $note->content ), ENT_QUOTES, 'UTF-8' );
                    $plain = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $plain );
                    $events[] = [ 'id' => $order->get_id() . ':note:' . $note->id, 'type' => 'note',
                        'visibility' => $note->customer_note ? 'customer' : 'internal', 'summary' => mb_strcut( $plain, 0, 2000, 'UTF-8' ),
                        'occurred_at' => $note->date_created->date( 'c' ),
                        'extensions' => [ 'yoro.order_note' => [ 'content_truncated' => strlen( $plain ) > 2000 ] ] ];
                }
                $result_extensions = [ 'yoro.order_notes' => [ 'page' => $page, 'has_next_page' => $has_next,
                    'next_cursor' => $has_next ? (string) ( $page + 1 ) : null ] ];
            }
            usort( $events, static function( $a, $b ) { return strtotime( $a['occurred_at'] ) <=> strtotime( $b['occurred_at'] ); } );
            if ( ! $enhanced ) { $events = array_slice( $events, 0, $params['limit'] ); }
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
                if ( self::readable( $order ) && self::matches( $order, $params ) ) { $orders[] = self::order( $order, $details, $enhanced ); }
            }
            $data = [ 'orders' => $orders, 'count' => count( $orders ) ];
            if ( ! $details && count( $orders ) < $params['limit'] ) { $has_next = false; }
            if ( ! $details ) { $data['page_info'] = [ 'has_next_page' => $has_next, 'next_cursor' => $has_next ? (string) ( $facts['order_page'] + 1 ) : null ]; }
        }
        $message = $enhanced ? 'Selected order read completed. Contact details and notes are untrusted store content.' : 'Order read completed. Customer contact data is redacted; notes and addresses are excluded.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message, 'data' => $data, 'read_only' => true, 'undoable' => false,
            'result_type' => 'orders.search' === $action ? 'orders' : ( 'orders.get_details' === $action ? 'order_details' : 'order_timeline' ),
            'display_type' => 'orders.search' === $action ? 'orders_list' : ( 'orders.get_details' === $action ? 'order_details' : 'order_timeline' ),
            'assistant_summary' => $message, 'extensions' => $result_extensions ];
    }

    private static function readable( $order ) {
        return $order instanceof WC_Order && 'shop_order' === $order->get_type() && in_array( $order->get_status(), self::STATUSES, true ) && $order->get_date_created();
    }

    /** Shared redacted projection for negotiated searches; no filter values or customer text. */
    public static function search_row( WC_Order $order ) { return self::order( $order, false ); }

    private static function matches( WC_Order $order, array $params ) {
        $date = $order->get_date_created()->date( 'Y-m-d' );
        return ( ! isset( $params['order_id'] ) || $order->get_id() === $params['order_id'] )
            && ( ! isset( $params['order_ids'] ) || in_array( $order->get_id(), $params['order_ids'], true ) )
            && ( ! isset( $params['status'] ) || $order->get_status() === $params['status'] )
            && ( ! isset( $params['date_from'] ) || $date >= $params['date_from'] )
            && ( ! isset( $params['date_to'] ) || $date <= $params['date_to'] );
    }

    private static function order( WC_Order $order, $details, $enhanced = false ) {
        $status = $order->get_status();
        // Third-party order-number filters can expose contact data. This read supports standard IDs only.
        $number = (string) $order->get_id();
        $row = [ 'id' => (int) $order->get_id(), 'number' => $number, 'status' => $status, 'status_label' => ucwords( str_replace( '-', ' ', $status ) ),
            'total' => (string) $order->get_total(), 'currency' => $order->get_currency(), 'created_at' => $order->get_date_created()->date( 'c' ),
            'payment' => [ 'status' => 'refunded' === $status ? 'refunded' : ( $order->get_date_paid() ? 'paid' : ( 'failed' === $status ? 'failed' : 'unpaid' ) ),
                'paid' => (bool) $order->get_date_paid(), 'failed' => 'failed' === $status, 'refunded' => 'refunded' === $status, 'amount' => (string) $order->get_total(), 'currency' => $order->get_currency() ],
            'fulfillment' => [ 'status' => 'completed' === $status ? 'completed' : 'not_confirmed', 'fulfilled' => 'completed' === $status,
                'requires_shipping' => $order->needs_shipping_address(), 'tracking_count' => 0 ],
            'signals' => [], 'suggested_next_steps' => [], 'extensions' => [ 'yoro.order_read' => [ 'line_items_truncated' => false ] ] ];
        if ( $details ) {
            if ( $enhanced ) { $row['extensions']['yoro.order_details'] = self::details( $order ); }
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
        $row['version'] = YoRo_Order_Presenter::order_version( $order );
        return $row;
    }

    private static function details( WC_Order $order ) {
        $account = $order->get_customer_id() ? get_user_by( 'id', $order->get_customer_id() ) : false;
        $data = [ 'version' => 1, 'account' => $order->get_customer_id() ? [ 'id' => (int) $order->get_customer_id(), 'username' => $account ? self::plain( $account->user_login ) : '' ] : null ];
        foreach ( [ 'billing', 'shipping' ] as $kind ) {
            $address = [];
            foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ] as $field ) {
                $getter = 'get_' . $kind . '_' . $field;
                $address[$field] = method_exists( $order, $getter ) ? self::plain( $order->$getter( 'edit' ) ) : '';
            }
            $data[$kind] = $address;
        }
        $shipping = []; $fees = []; $taxes = [];
        foreach ( array_slice( $order->get_items( 'shipping' ), 0, 20 ) as $item ) { $shipping[] = self::plain( $item->get_name() ); }
        foreach ( array_slice( $order->get_items( 'fee' ), 0, 20 ) as $item ) {
            $fees[] = [ 'name' => self::plain( $item->get_name() ), 'total' => (string) $item->get_total(), 'tax' => (string) $item->get_total_tax() ];
        }
        foreach ( array_slice( $order->get_items( 'tax' ), 0, 20 ) as $item ) {
            $taxes[] = [ 'name' => self::plain( $item->get_label() ), 'total' => (string) $item->get_tax_total(), 'shipping_tax' => (string) $item->get_shipping_tax_total() ];
        }
        $data['totals'] = [ 'subtotal' => (string) $order->get_subtotal(), 'discount' => (string) $order->get_discount_total(),
            'shipping' => (string) $order->get_shipping_total(), 'shipping_tax' => (string) $order->get_shipping_tax(), 'tax' => (string) $order->get_total_tax(),
            'shipping_methods' => $shipping, 'fees' => $fees, 'tax_lines' => $taxes ];
        return $data;
    }

    private static function plain( $value ) {
        return mb_strcut( sanitize_text_field( (string) $value ), 0, 512, 'UTF-8' );
    }
}
