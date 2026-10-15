<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded customer correlation. Contact values are used locally and never returned. */
final class YoRo_Early_Access_Customer_Service_Read {
    const ACTIONS = [ 'customers.lookup', 'orders.find_by_customer', 'orders.find_by_identity', 'customers.get_service_queue' ];
    const SELECTORS = [ 'email', 'phone', 'customer_id', 'customer_name', 'order_id' ];

    public static function manifest() {
        return [ 'version' => 1, 'actions' => self::ACTIONS, 'max_items' => 20, 'scan_limit' => 100,
            'customer_data' => 'redacted', 'semantics' => 'bounded_candidate_snapshot_v1' ];
    }

    public static function valid_params( $action, $params ) {
        if ( ! in_array( $action, self::ACTIONS, true ) || ! is_array( $params ) || array_diff( array_keys( $params ), [ 'limit', ...self::SELECTORS ] )
            || ! is_int( $params['limit'] ?? null ) || $params['limit'] < 1 || $params['limit'] > 20 ) { return false; }
        $selectors = array_intersect( array_keys( $params ), self::SELECTORS );
        if ( 'customers.get_service_queue' === $action ) { return ! $selectors; }
        if ( count( $selectors ) !== 1 || ( 'orders.find_by_identity' === $action && ! isset( $params['order_id'] ) ) ) { return false; }
        $key = reset( $selectors ); $value = $params[$key];
        if ( in_array( $key, [ 'customer_id', 'order_id' ], true ) ) { return is_int( $value ) && $value > 0; }
        if ( ! is_string( $value ) || trim( $value ) !== $value || preg_match( '/[\x00-\x1f\x7f<>]/', $value ) || 1 !== preg_match( '//u', $value ) ) { return false; }
        if ( 'email' === $key ) { return strlen( $value ) <= 254 && (bool) is_email( $value ); }
        if ( 'phone' === $key ) { return strlen( $value ) <= 32 && preg_match( '/\A[+0-9 ()-]+\z/D', $value ) && strlen( self::phone( $value ) ) >= 7 && strlen( self::phone( $value ) ) <= 15; }
        return mb_strlen( $value ) >= 2 && mb_strlen( $value ) <= 80;
    }

    public static function execute( array $operation ) {
        $action = $operation['action'] ?? ''; $params = $operation['params'] ?? [];
        if ( ! self::valid_params( $action, $params ) || ! current_user_can( 'yoro_view_store_data' ) || ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'customer_service_unavailable', 'Customer service read did not complete.' );
        }
        $queue = 'customers.get_service_queue' === $action; $identity = 'orders.find_by_identity' === $action;
        $seed = isset( $params['order_id'] ) ? wc_get_order( $params['order_id'] ) : null;
        if ( isset( $params['order_id'] ) && ! self::readable( $seed ) ) { return new WP_Error( 'customer_service_unavailable', 'The reference order is unavailable.' ); }
        $query = [ 'type' => 'shop_order', 'status' => $queue ? [ 'pending', 'on-hold', 'failed' ] : YoRo_Early_Access_Order_Read::STATUSES,
            'limit' => 101, 'orderby' => 'ID', 'order' => $queue ? 'ASC' : 'DESC' ];
        if ( isset( $params['email'] ) ) { $query['billing_email'] = strtolower( $params['email'] ); }
        if ( isset( $params['customer_id'] ) ) { $query['customer_id'] = $params['customer_id']; }
        $raw = wc_get_orders( $query ); $more = count( $raw ) > 100; $raw = array_slice( $raw, 0, 100 );
        $matched = []; $reasons = []; $now = time();
        foreach ( $raw as $order ) {
            if ( ! self::readable( $order ) ) { continue; }
            $why = $queue ? self::service_reasons( $order, $now ) : ( $identity ? self::identity_reasons( $seed, $order ) : self::customer_reasons( $params, $seed, $order ) );
            if ( ! $why ) { continue; }
            $matched[] = $order; $reasons[(int) $order->get_id()] = $why;
        }
        $profiles = 'customers.lookup' === $action;
        $rows = $profiles ? self::profiles( $matched, $params['limit'] ) : array_map( static function( $order ) {
            $read = YoRo_Early_Access_Order_Read::execute( [ 'action' => 'orders.search', 'params' => [ 'order_id' => (int) $order->get_id(), 'limit' => 1 ],
                'extensions' => [ 'yoro.mcp_early_access_product_read' => [ 'order_page' => 1 ] ] ] );
            return is_wp_error( $read ) ? null : ( $read['data']['orders'][0] ?? null );
        }, array_slice( $matched, 0, $params['limit'] ) );
        $rows = array_values( array_filter( $rows ) ); $matches = [];
        if ( ! $profiles ) { foreach ( $rows as $row ) { $matches[] = [ 'order_id' => $row['id'], 'reasons' => $reasons[$row['id']] ]; } }
        $message = 'Bounded customer service snapshot. Contact details, addresses, notes, IP and device values are excluded. Counts and amounts cover scanned orders only; no messages are sent.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message,
            'data' => [ $profiles ? 'customers' : 'orders' => $rows, 'count' => count( $rows ), 'page_info' => [ 'has_next_page' => false, 'next_cursor' => null ] ],
            'read_only' => true, 'undoable' => false, 'result_type' => $profiles ? 'customer_summary' : 'orders', 'display_type' => $profiles ? 'customer_summary' : 'orders_list', 'assistant_summary' => $message,
            'extensions' => [ 'yoro.customer_service' => [ 'version' => 1, 'scan_limit' => 100, 'scanned' => count( $raw ), 'matched_orders' => count( $matched ),
                'has_more_candidates' => $more, 'returned_truncated' => $profiles ? self::$profile_count > count( $rows ) : count( $matched ) > count( $rows ),
                'generated_at' => gmdate( 'c', $now ), 'reference_order_id' => $seed ? (int) $seed->get_id() : null, 'matches' => $matches ] ] ];
    }

    private static $profile_count = 0;

    private static function readable( $order ) {
        return $order instanceof WC_Order && 'shop_order' === $order->get_type() && $order->get_date_created()
            && in_array( $order->get_status(), YoRo_Early_Access_Order_Read::STATUSES, true );
    }

    private static function phone( $value ) { return preg_replace( '/[^0-9]/', '', (string) $value ); }

    private static function customer_reasons( array $params, $seed, WC_Order $order ) {
        if ( $seed ) {
            if ( $seed->get_customer_id() > 0 ) { return $seed->get_customer_id() === $order->get_customer_id() ? [ 'customer_id' ] : []; }
            $email = strtolower( $seed->get_billing_email() ); $phone = self::phone( $seed->get_billing_phone() );
            if ( $email ) { return $email === strtolower( $order->get_billing_email() ) ? [ 'email' ] : []; }
            return $phone && $phone === self::phone( $order->get_billing_phone() ) ? [ 'phone' ] : [];
        }
        if ( isset( $params['customer_id'] ) ) { return $params['customer_id'] === $order->get_customer_id() ? [ 'customer_id' ] : []; }
        if ( isset( $params['email'] ) ) { return strtolower( $params['email'] ) === strtolower( $order->get_billing_email() ) ? [ 'email' ] : []; }
        if ( isset( $params['phone'] ) ) { return self::phone( $params['phone'] ) === self::phone( $order->get_billing_phone() ) ? [ 'phone' ] : []; }
        $name = mb_strtolower( $params['customer_name'] );
        return $name === mb_strtolower( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) ? [ 'customer_name' ] : [];
    }

    private static function identity_reasons( WC_Order $seed, WC_Order $order ) {
        if ( $seed->get_id() === $order->get_id() ) { return []; }
        $values = static function( WC_Order $o ) { return [ 'email' => strtolower( $o->get_billing_email() ), 'phone' => self::phone( $o->get_billing_phone() ),
            'ip' => (string) $o->get_customer_ip_address(), 'device' => (string) $o->get_meta( '_yoro_device_id', true ) ]; };
        $left = $values( $seed ); $right = $values( $order ); $why = [];
        foreach ( $left as $key => $value ) { if ( '' !== $value && $value === $right[$key] ) { $why[] = $key; } }
        return $why;
    }

    private static function service_reasons( WC_Order $order, $now ) {
        $age = $now - $order->get_date_created()->getTimestamp(); $status = $order->get_status();
        if ( 'pending' === $status && $age >= DAY_IN_SECONDS ) { return [ 'pending_payment_older_than_24h' ]; }
        if ( 'on-hold' === $status && $age >= 2 * DAY_IN_SECONDS ) { return [ 'on_hold_older_than_48h' ]; }
        return 'failed' === $status && $age >= 0 && $age <= 7 * DAY_IN_SECONDS ? [ 'recent_failed_orders' ] : [];
    }

    private static function profiles( array $orders, $limit ) {
        $groups = [];
        foreach ( $orders as $order ) {
            $identity = $order->get_customer_id() > 0 ? 'user:' . $order->get_customer_id()
                : ( $order->get_billing_email() ? 'email:' . strtolower( $order->get_billing_email() ) : ( self::phone( $order->get_billing_phone() ) ? 'phone:' . self::phone( $order->get_billing_phone() ) : 'order:' . $order->get_id() ) );
            $groups[$identity . '|' . $order->get_currency()][] = $order;
        }
        self::$profile_count = count( $groups ); $profiles = [];
        foreach ( array_slice( $groups, 0, $limit ) as $group ) {
            $seed = $group[0]; $id = $seed->get_customer_id() > 0 ? (int) $seed->get_customer_id() : 'guest-order-' . $seed->get_id();
            $times = array_map( static function( $o ) { return $o->get_date_created()->getTimestamp(); }, $group );
            $profiles[] = [ 'id' => $id, 'name' => is_int( $id ) ? 'Customer #' . $id : 'Guest order group', 'email' => '***', 'phone' => '***', 'status' => 'active',
                'orders_count' => count( $group ), 'lifetime_value' => (string) wc_format_decimal( array_sum( array_map( static function( $o ) { return (float) $o->get_total(); }, $group ) ), 2 ),
                'currency' => $seed->get_currency(), 'created_at' => gmdate( 'c', min( $times ) ), 'last_order_at' => gmdate( 'c', max( $times ) ),
                'segments' => [], 'risk_flags' => [], 'related_orders' => [], 'signals' => [], 'suggested_next_steps' => [],
                'support_context' => [ 'available' => false, 'interaction_count' => 0, 'last_interaction_at' => 'Not included', 'summary' => 'Not included in this read.' ],
                'extensions' => [ 'yoro.customer_service' => [ 'seed_order_id' => (int) $seed->get_id(), 'amount_scope' => 'scanned_orders_only' ] ] ];
        }
        return $profiles;
    }
}
