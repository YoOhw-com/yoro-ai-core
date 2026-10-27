<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded customer-safe queue projection before local journal persistence. */
class YoRo_Early_Access_Order_Queue_Read {
    const ACTIONS = [ 'operations.get_attention_queue', 'operations.get_fulfillment_queue' ];
    const REASONS = [
        'pending' => [ 'pending_payment_older_than_24h', 24, 'medium', 'Pending payment now; created at least 24 hours ago. Check payment context.' ],
        'on-hold' => [ 'on_hold_older_than_48h', 48, 'medium', 'On hold now; created at least 48 hours ago. Check the hold reason.' ],
        'processing' => [ 'processing_older_than_72h', 72, 'high', 'Processing now; created at least 72 hours ago. Check fulfillment context.' ],
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
        if ( isset( $operation['extensions']['yoro.mcp_early_access_product_read']['order_attention_shift'] ) ) {
            return self::shift( $operation );
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

    public static function shift_manifest() {
        return [ 'version' => 1, 'pagination' => 'signed_keyset_v1', 'scan_limit' => 100, 'max_items' => 20,
            'coverage' => 'observed_standard_orders', 'progress' => 'api_owned' ];
    }

    private static function cursor_context( array $operation ) {
        $facts = $operation['extensions']['yoro.mcp_early_access_product_read'];
        return hash( 'sha256', wp_json_encode( [ $operation['action'], get_current_blog_id(), get_current_user_id(),
            $facts['site_id'] ?? null, $facts['installation_uuid'] ?? null, $facts['wordpress_identity_id'] ?? null,
            $facts['profile'] ?? null, $facts['oauth_client_id'] ?? null, $facts['user_id'] ?? null ] ) );
    }

    private static function cursor( array $state ) {
        $body = rtrim( strtr( base64_encode( wp_json_encode( $state ) ), '+/', '-_' ), '=' );
        return $body . '.' . hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
    }

    private static function shift( array $operation ) {
        $shift = $operation['extensions']['yoro.mcp_early_access_product_read']['order_attention_shift'];
        if ( ! is_array( $shift ) || count( $shift ) !== 2 || 1 !== $shift['version']
            || ! is_string( $shift['cursor'] ) || strlen( $shift['cursor'] ) > 1024 ) {
            return new WP_Error( 'order_queue_refresh_required', 'Continuation is invalid. Refresh the queue; saved work is retained.' );
        }
        $now = time(); $context = self::cursor_context( $operation );
        $state = [ 'anchor' => 0, 'after' => 0, 'expires' => $now + HOUR_IN_SECONDS, 'context' => $context ];
        if ( '' !== $shift['cursor'] ) {
            $parts = explode( '.', $shift['cursor'] );
            if ( count( $parts ) !== 2 || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
                return new WP_Error( 'order_queue_refresh_required', 'Continuation is invalid. Refresh the queue; saved work is retained.' );
            }
            $state = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
            if ( ! is_array( $state ) || count( $state ) !== 4 || ! is_int( $state['anchor'] ?? null ) || $state['anchor'] < 0
                || ! is_int( $state['after'] ?? null ) || $state['after'] < 0 || $state['after'] > $state['anchor']
                || ! is_int( $state['expires'] ?? null ) || $state['expires'] <= $now || $state['expires'] > $now + HOUR_IN_SECONDS
                || ( $state['context'] ?? null ) !== $context ) {
                return new WP_Error( 'order_queue_refresh_required', 'Continuation expired or belongs to another context. Refresh the queue; saved work is retained.' );
            }
        } else {
            $latest = wc_get_orders( [ 'type' => 'shop_order', 'status' => YoRo_Early_Access_Order_Read::STATUSES,
                'limit' => 1, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'DESC' ] );
            if ( ! is_array( $latest ) ) { return new WP_Error( 'order_queue_unavailable', 'The current order boundary is unavailable.' ); }
            $state['anchor'] = (int) ( $latest[0] ?? 0 );
        }
        $fulfillment = 'operations.get_fulfillment_queue' === $operation['action'];
        $applicable = ! $fulfillment || wc_shipping_enabled();
        try { $raw = $applicable && $state['anchor'] > 0 ? self::shift_rows( $state['anchor'], $state['after'] ) : []; }
        catch ( Throwable $error ) { return new WP_Error( 'order_queue_unavailable', 'The bounded order scan could not be verified. Refresh; saved work is retained.' ); }
        $items = []; $scanned = 0; $unassessed = 0; $last = $state['after'];
        foreach ( array_slice( $raw, 0, 100 ) as $order ) {
            if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || $order->get_id() <= $last || $order->get_id() > $state['anchor'] ) {
                return new WP_Error( 'order_queue_unavailable', 'The bounded order scan returned inconsistent references.' );
            }
            $last = (int) $order->get_id(); $scanned++;
            if ( ! $order->get_date_created() ) { $unassessed++; continue; }
            $status = $order->get_status(); $reason = self::REASONS[ $status ] ?? null;
            $age = (int) floor( ( $now - $order->get_date_created()->getTimestamp() ) / HOUR_IN_SECONDS );
            $shipping = YoRo_Orders_Read_Service::fulfillment_order_requires_shipping( $order );
            if ( $age < 0 || $age > 10000000 ) { $unassessed++; continue; }
            if ( ! $reason ) { continue; }
            if ( $fulfillment ) {
                if ( 'processing' !== $status || ! $shipping ) { continue; }
                $reason = [ 'processing_requires_shipping', 0, 'low', 'Processing order requires shipping; dispatch is not verified' ];
            } elseif ( $age < $reason[1] || ( 'failed' === $status && $now - $order->get_date_created()->getTimestamp() > 7 * DAY_IN_SECONDS ) ) { continue; }
            $item = [ 'order_id' => $last, 'order_number' => (string) $last, 'status' => $status,
                'status_label' => ucwords( str_replace( '-', ' ', $status ) ), 'total' => (string) $order->get_total(), 'currency' => $order->get_currency(),
                'created_at' => $order->get_date_created()->date( 'c' ), 'age_hours' => $age, 'reason' => $reason[3], 'severity' => $reason[2],
                'priority_score' => ( 'high' === $reason[2] ? 1000 : ( 'medium' === $reason[2] ? 500 : 100 ) ) + min( $age, 720 ),
                'extensions' => [ 'yoro.order_queue' => [ 'reason_code' => $reason[0], 'requires_shipping' => $shipping ] ] ];
            $item['version'] = hash( 'sha256', wp_json_encode( $item ) ); $items[] = $item;
            if ( count( $items ) >= $operation['params']['limit'] ) { break; }
        }
        $more = count( $raw ) > $scanned;
        $state['after'] = $last;
        $cursor = $more ? self::cursor( $state ) : null;
        $message = 'Observed standard order IDs in a fixed ID boundary, oldest first; priority is bounded to these observations. Changes to earlier orders need a refresh. Saved work remains separate.';
        return [ 'success' => true, 'action' => $operation['action'], 'message' => $message, 'summary' => $message,
            'data' => [ 'items' => $items, 'count' => count( $items ), 'total_matches' => count( $items ), 'page_info' => [ 'has_next_page' => $more, 'next_cursor' => $cursor ] ],
            'read_only' => true, 'undoable' => false, 'result_type' => 'order_queue', 'display_type' => 'order_queue', 'assistant_summary' => $message,
            'extensions' => [ 'yoro.order_queue' => [ 'version' => 2, 'scan_limit' => 100, 'scanned' => $scanned,
                'has_more_candidates' => $more, 'returned_truncated' => false, 'generated_at' => gmdate( 'c', $now ), 'applicable' => $applicable,
                'unassessed' => $unassessed, 'anchor_id' => $state['anchor'], 'after_id' => $last, 'coverage' => 'observed_standard_orders',
                'unknown_context' => [ 'status_duration', 'preorder', 'made_to_order', 'digital_fulfillment', 'holidays', 'store_sla' ] ] ] ];
    }

    private static function shift_rows( $anchor, $after ) {
        global $wpdb; $token = wp_generate_uuid4(); $applied = false;
        $where = static function( $id ) use ( $anchor, $after, $wpdb ) { return $wpdb->prepare( "{$id}>%d AND {$id}<=%d", $after, $anchor ); };
        $hpos = static function( $clauses, $query, $vars ) use ( $token, &$applied, $where ) {
            if ( ( $vars['yoro_attention_cursor'] ?? null ) === $token ) { $clauses['where'] .= ' AND (' . $where( $query->get_table_name( 'orders' ) . '.id' ) . ')'; $applied = true; } return $clauses;
        };
        $cpt = static function( $sql, $query ) use ( $token, &$applied, $where, $wpdb ) {
            if ( $query->get( 'yoro_attention_cursor' ) === $token ) { $sql .= ' AND (' . $where( $wpdb->posts . '.ID' ) . ')'; $applied = true; } return $sql;
        };
        $args = static function( $args, $vars ) use ( $token ) { if ( ( $vars['yoro_attention_cursor'] ?? null ) === $token ) { $args['yoro_attention_cursor'] = $token; $args['suppress_filters'] = false; } return $args; };
        add_filter( 'woocommerce_orders_table_query_clauses', $hpos, 10, 3 ); add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $args, 10, 2 ); add_filter( 'posts_where', $cpt, 10, 2 );
        try {
            $rows = wc_get_orders( [ 'type' => 'shop_order', 'status' => YoRo_Early_Access_Order_Read::STATUSES,
                'limit' => 101, 'orderby' => 'ID', 'order' => 'ASC', 'yoro_attention_cursor' => $token ] );
            if ( ! $applied || $wpdb->last_error !== '' || ! is_array( $rows ) ) { throw new RuntimeException( 'order_attention_cursor_not_applied' ); } return $rows;
        } finally { remove_filter( 'woocommerce_orders_table_query_clauses', $hpos, 10 ); remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $args, 10 ); remove_filter( 'posts_where', $cpt, 10 ); }
    }

}
