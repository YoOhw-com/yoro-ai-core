<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Stored tracking projection only; no carrier requests, URLs, customer text or note reads. */
class YoRo_Early_Access_Order_Tracking_Read {
    const ACTIONS = [ 'fulfillment.get_tracking' ];
    const CARRIERS = [ 'UPS', 'USPS', 'FedEx', 'DHL', 'DPD', 'GLS', 'Royal Mail', 'Canada Post', 'Australia Post', 'Not provided' ];
    const FALLBACK_KEYS = [ '_tracking_number', '_aftership_tracking_number', '_wcast_tracking_number', '_wcst_order_trackno', '_yith_tracking_code' ];

    public static function valid_params( $action, $params ) {
        return in_array( $action, self::ACTIONS, true ) && is_array( $params ) && [ 'order_id' ] === array_keys( $params )
            && is_int( $params['order_id'] ) && $params['order_id'] > 0;
    }

    public static function execute( array $operation ) {
        $action = $operation['action'] ?? '';
        $params = $operation['params'] ?? [];
        if ( ! self::valid_params( $action, $params ) || ! current_user_can( 'yoro_view_store_data' ) || ! function_exists( 'wc_get_order' ) ) {
            return new WP_Error( 'order_tracking_unavailable', 'Stored tracking read did not complete.' );
        }
        $order = wc_get_order( $params['order_id'] );
        if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || ! in_array( $order->get_status(), YoRo_Early_Access_Order_Read::STATUSES, true ) ) {
            return new WP_Error( 'order_tracking_unavailable', 'Stored tracking read did not complete.' );
        }
        $items = $order->get_meta( '_wc_shipment_tracking_items', true );
        $items = is_array( $items ) ? $items : [];
        $truncated = count( $items ) > 20;
        $shipments = [];
        $seen = [];
        $contact_digits = array_map( static function( $phone ) { return preg_replace( '/[^0-9]/', '', (string) $phone ); },
            [ $order->get_billing_phone(), $order->get_shipping_phone() ] );
        $append = static function( $code, $carrier ) use ( &$shipments, &$seen, &$truncated, $contact_digits ) {
            if ( ! is_string( $code ) || ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9-]{4,62}[A-Za-z0-9]\z/D', $code ) || ! preg_match( '/[0-9]/', $code ) ) { return; }
            $digits = str_replace( '-', '', $code );
            if ( ctype_digit( $digits ) && strlen( $digits ) >= 6 && in_array( $digits, $contact_digits, true ) ) { return; }
            if ( isset( $seen[ $code ] ) ) { return; }
            $seen[ $code ] = true;
            if ( count( $shipments ) >= 5 ) { $truncated = true; return; }
            $label = 'Not provided';
            if ( is_string( $carrier ) ) {
                foreach ( self::CARRIERS as $known ) {
                    if ( strtolower( $carrier ) === strtolower( $known ) ) { $label = $known; break; }
                }
            }
            $shipments[] = [ 'carrier' => $label, 'tracking_number' => $code, 'tracking_url' => '', 'status' => 'unverified', 'extensions' => (object) [] ];
        };
        foreach ( array_slice( $items, 0, 20 ) as $item ) {
            if ( is_array( $item ) ) { $append( $item['tracking_number'] ?? null, $item['tracking_provider'] ?? null ); }
        }
        foreach ( self::FALLBACK_KEYS as $key ) { $append( $order->get_meta( $key, true ), null ); }
        if ( class_exists( 'YoRo_Order_Management' ) ) {
            $native = YoRo_Order_Management::details( $order );
            $truncated = $truncated || $native['tracking_truncated'];
            foreach ( $native['tracking'] as $item ) { $append( $item['tracking_number'], $item['carrier'] ); }
        }
        $message = 'Stored tracking records only. Dispatch and delivery are not verified. Customer contact data and custom links are excluded.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message,
            'data' => [ 'order_id' => $params['order_id'], 'status' => $shipments ? 'available' : 'unavailable', 'shipments' => $shipments, 'count' => count( $shipments ) ],
            'read_only' => true, 'undoable' => false, 'result_type' => 'fulfillment_tracking', 'display_type' => 'fulfillment_tracking', 'assistant_summary' => $message,
            'extensions' => [ 'yoro.order_tracking' => [ 'version' => 1, 'truncated' => $truncated, 'provider_verified' => false ] ] ];
    }
}
