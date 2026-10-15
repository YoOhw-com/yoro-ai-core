<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded aggregate projection before the ordinary read journal. */
class YoRo_Early_Access_Store_Overview_Read {
    const ACTIONS = [ 'operations.get_summary', 'store.get_business_summary', 'store.get_daily_briefing', 'store.get_insights', 'coupons.lookup' ];
    const WINDOWS = [ 'today', 'yesterday', 'last_7_days', 'last_30_days' ];
    const NOT_ASSESSED = [ 'customer_followups', 'inventory' ];
    const AMOUNT_BASIS = 'order_totals_including_tax_shipping_after_discounts_before_refunds';

    public static function valid_params( $action, $params ) {
        if ( ! in_array( $action, self::ACTIONS, true ) || ! is_array( $params ) ) { return false; }
        if ( 'coupons.lookup' === $action ) {
            return [ 'coupon_code' ] === array_keys( $params ) && is_string( $params['coupon_code'] )
                && 1 === preg_match( '/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $params['coupon_code'] );
        }
        return [ 'window' ] === array_keys( $params ) && in_array( $params['window'], self::WINDOWS, true );
    }

    public static function execute( array $operation ) {
        $action = $operation['action'] ?? '';
        $params = $operation['params'] ?? [];
        if ( ! self::valid_params( $action, $params ) || ! current_user_can( 'yoro_view_store_data' ) || ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'store_overview_unavailable', 'The store overview read did not complete.' );
        }
        $generated = time();
        if ( 'coupons.lookup' === $action ) { return self::coupon( $action, $params['coupon_code'], $generated ); }
        $currency = get_woocommerce_currency();
        if ( ! is_string( $currency ) || ! preg_match( '/\A[A-Z]{3}\z/D', $currency ) ) { return new WP_Error( 'store_currency_unavailable', 'The store currency is unavailable.' ); }
        [ $window, $previous ] = self::windows( $params['window'], $generated );
        [ $current, $meta, $counts ] = self::period( $window, $currency, $generated );
        [ $prior, $prior_meta ] = self::period( $previous, $currency, $generated );
        $comparison = [];
        foreach ( [ 'order_count', 'gross_sales', 'average_order_value' ] as $field ) {
            $a = 'order_count' === $field ? $current[ $field ] : self::units( $current[ $field ] );
            $b = 'order_count' === $field ? $prior[ $field ] : self::units( $prior[ $field ] );
            $comparison[ $field ] = [ 'current' => $current[ $field ], 'previous' => $prior[ $field ], 'trend' => $a > $b ? 'up' : ( $a < $b ? 'down' : 'flat' ),
                'delta' => 'order_count' === $field ? (string) ( $a - $b ) : self::decimal( $a - $b ) ];
        }
        $complete = $current['is_complete'] && $prior['is_complete'];
        $priorities = [];
        if ( $counts['attention'] > 0 ) { $priorities[] = self::priority( 'attention', 'Orders need attention', 'At least one sampled order meets the attention rule.', 'medium' ); }
        if ( $complete && 0 === $current['order_count'] ) { $priorities[] = self::priority( 'no_sales', 'No qualifying orders', 'No processing or completed orders in the selected store-currency period.', 'low' ); }
        if ( $complete && $current['order_count'] > 0 && 'flat' !== $comparison['gross_sales']['trend'] ) {
            $up = 'up' === $comparison['gross_sales']['trend'];
            $priorities[] = self::priority( $up ? 'totals_up' : 'totals_down', $up ? 'Order totals increased' : 'Order totals decreased', 'Compared with the previous equivalent calendar period; this is not a forecast.', 'low' );
        }
        $data = [ 'window' => $window, 'metrics' => $current, 'comparison' => [ 'window' => $previous, 'metrics' => $comparison ],
            // Frozen wire fields are placeholders only; the mandatory assessment marker travels in the same journaled result.
            'counts' => $counts + [ 'customer_followups' => 0, 'inventory' => 0 ], 'attention_items' => [], 'inventory_items' => [], 'priorities' => $priorities ];
        return self::result( $action, $data, [ 'version' => 1, 'kind' => 'report', 'generated_at' => gmdate( 'c', $generated ), 'scan_limit' => 100,
            'current' => $meta, 'previous' => $prior_meta, 'amount_basis' => self::AMOUNT_BASIS, 'sales_statuses' => [ 'processing', 'completed' ],
            'not_assessed' => self::NOT_ASSESSED, 'comparison_complete' => $complete ] );
    }

    public static function windows( $key, $generated ) {
        $now = ( new DateTimeImmutable( '@' . $generated ) )->setTimezone( wp_timezone() );
        $days = 'last_7_days' === $key ? 7 : ( 'last_30_days' === $key ? 30 : 1 );
        $start = $now->setTime( 0, 0 )->modify( '-' . ( $days - 1 ) . ' days' );
        $end = $now;
        if ( 'yesterday' === $key ) { $start = $start->modify( '-1 day' ); $end = $start->setTime( 23, 59, 59 ); }
        $label = [ 'today' => 'Today', 'yesterday' => 'Yesterday', 'last_7_days' => 'Last 7 days', 'last_30_days' => 'Last 30 days' ][ $key ];
        $make = static function( $name, $title, $from, $to ) use ( $now ) { return [ 'key' => $name, 'label' => $title, 'start' => $from->format( 'c' ), 'end' => $to->format( 'c' ), 'timezone' => $now->getTimezone()->getName() ]; };
        return [ $make( $key, $label, $start, $end ), $make( 'previous_equivalent_window', 'Previous equivalent period', $start->modify( '-' . $days . ' days' ), $end->modify( '-' . $days . ' days' ) ) ];
    }

    private static function period( array $window, $currency, $generated ) {
        $raw = wc_get_orders( [ 'type' => 'shop_order', 'status' => YoRo_Early_Access_Order_Read::STATUSES, 'limit' => 101, 'page' => 1,
            'date_created' => strtotime( $window['start'] ) . '...' . strtotime( $window['end'] ), 'orderby' => 'ID', 'order' => 'DESC' ] );
        $has_more = count( $raw ) > 100; $raw = array_slice( $raw, 0, 100 );
        $statuses = array_fill_keys( YoRo_Early_Access_Order_Read::STATUSES, 0 );
        $invalid = 0; $other = 0; $total = 0; $eligible = 0; $counts = [ 'attention' => 0, 'fulfillment' => 0 ];
        foreach ( $raw as $order ) {
            if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || ! $order->get_date_created() || ! array_key_exists( $order->get_status(), $statuses ) ) { $invalid++; continue; }
            $status = $order->get_status(); $created = $order->get_date_created()->getTimestamp();
            if ( $created < strtotime( $window['start'] ) || $created > strtotime( $window['end'] ) ) { $invalid++; continue; }
            $statuses[ $status ]++;
            $age = (int) floor( ( $generated - $created ) / HOUR_IN_SECONDS );
            if ( ( 'pending' === $status && $age >= 24 ) || ( 'on-hold' === $status && $age >= 48 ) || ( 'processing' === $status && $age >= 72 ) || ( 'failed' === $status && $age >= 0 && $age <= 168 ) ) { $counts['attention']++; }
            if ( 'processing' === $status && wc_shipping_enabled() && YoRo_Orders_Read_Service::fulfillment_order_requires_shipping( $order ) ) { $counts['fulfillment']++; }
            if ( $order->get_currency() !== $currency ) { $other++; continue; }
            if ( ! in_array( $status, [ 'processing', 'completed' ], true ) ) { continue; }
            $amount = self::units( $order->get_total() );
            if ( null === $amount || $amount > 999999999999999 ) { $invalid++; continue; }
            $total += $amount; $eligible++;
        }
        $metrics = [ 'order_count' => $eligible, 'gross_sales' => self::decimal( $total ), 'average_order_value' => self::decimal( $eligible ? intdiv( $total + intdiv( $eligible, 2 ), $eligible ) : 0 ),
            'currency' => $currency, 'is_complete' => ! $has_more && 0 === $invalid && 0 === $other ];
        return [ $metrics, [ 'scanned' => count( $raw ), 'has_more' => $has_more, 'invalid_orders' => $invalid, 'other_currency_orders' => $other, 'status_counts' => $statuses ], $counts ];
    }

    private static function units( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/\A(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,6}))?\z/D', $value, $m ) ) { return null; }
        return (int) $m[1] * 1000000 + (int) str_pad( $m[2] ?? '', 6, '0' );
    }

    private static function decimal( $value ) {
        $sign = $value < 0 ? '-' : ''; $value = abs( $value );
        return $sign . intdiv( $value, 1000000 ) . '.' . str_pad( (string) ( $value % 1000000 ), 6, '0', STR_PAD_LEFT );
    }

    private static function priority( $domain, $title, $reason, $severity ) {
        return [ 'domain' => $domain, 'title' => $title, 'reason' => $reason, 'severity' => $severity, 'score' => 'medium' === $severity ? 50 : 10 ];
    }

    private static function coupon( $action, $code, $generated ) {
        $currency = get_woocommerce_currency();
        if ( ! is_string( $currency ) || ! preg_match( '/\A[A-Z]{3}\z/D', $currency ) ) { return new WP_Error( 'store_currency_unavailable', 'The store currency is unavailable.' ); }
        $enabled = wc_coupons_enabled(); $id = $enabled ? wc_get_coupon_id_by_code( $code ) : 0; $dto = null;
        $availability = ! $enabled ? 'disabled' : ( $id ? 'unsupported' : 'not_found' );
        if ( $id ) {
            $coupon = new WC_Coupon( $id ); $amount = self::units( $coupon->get_amount() );
            if ( strtolower( $coupon->get_code() ) === $code && in_array( $coupon->get_discount_type(), [ 'percent', 'fixed_cart', 'fixed_product' ], true ) && null !== $amount ) {
                $minimum = $coupon->get_minimum_amount(); $maximum = $coupon->get_maximum_amount();
                $min_units = '' === $minimum ? 0 : self::units( $minimum ); $max_units = '' === $maximum ? 0 : self::units( $maximum );
                if ( null === $min_units || null === $max_units || ( $max_units && $max_units < $min_units ) || ( 'percent' === $coupon->get_discount_type() && $amount > 100000000 ) ) { return new WP_Error( 'coupon_settings_unavailable', 'Stored coupon settings are invalid.' ); }
                foreach ( [ $coupon->get_usage_count(), $coupon->get_usage_limit(), $coupon->get_usage_limit_per_user() ] as $usage ) {
                    if ( ! is_numeric( $usage ) || $usage < 0 || $usage > 2147483647 ) { return new WP_Error( 'coupon_settings_unavailable', 'Stored coupon settings are invalid.' ); }
                }
                $expiry = $coupon->get_date_expires(); $availability = 'supported';
                $dto = [ 'id' => (int) $id, 'code' => $code, 'discount_type' => $coupon->get_discount_type(), 'discount_amount' => self::decimal( $amount ),
                    'expires_at' => $expiry ? gmdate( 'c', $expiry->getTimestamp() ) : null, 'usage_count' => (int) $coupon->get_usage_count(),
                    'usage_limit' => $coupon->get_usage_limit() ? (int) $coupon->get_usage_limit() : null,
                    'usage_limit_per_user' => $coupon->get_usage_limit_per_user() ? (int) $coupon->get_usage_limit_per_user() : null,
                    'minimum_amount' => $min_units ? self::decimal( $min_units ) : null,
                    'maximum_amount' => $max_units ? self::decimal( $max_units ) : null,
                    'individual_use' => (bool) $coupon->get_individual_use(), 'free_shipping' => (bool) $coupon->get_free_shipping(),
                    'product_restricted' => (bool) ( $coupon->get_product_ids() || $coupon->get_excluded_product_ids() || $coupon->get_product_categories() || $coupon->get_excluded_product_categories() ),
                    'email_restricted' => (bool) $coupon->get_email_restrictions(), 'exclude_sale_items' => (bool) $coupon->get_exclude_sale_items() ];
            }
        }
        return self::result( $action, [ 'coupon_code' => $code, 'currency' => $currency, 'availability' => $availability, 'coupon' => $dto ],
            [ 'version' => 1, 'kind' => 'coupon', 'generated_at' => gmdate( 'c', $generated ), 'eligibility_verified' => false ] );
    }

    private static function result( $action, array $data, array $meta ) {
        $message = 'coupons.lookup' === $action ? 'Stored coupon settings only. Cart and customer eligibility are not evaluated.'
            : 'Bounded aggregate snapshot of standard-status orders. Amounts are stored order totals, not net revenue. Customer follow-ups and inventory are not assessed. Comparison conclusions require complete periods.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message, 'data' => $data, 'read_only' => true, 'undoable' => false,
            'result_type' => 'store_overview', 'display_type' => 'store_overview', 'assistant_summary' => $message, 'extensions' => [ 'yoro.store_overview' => $meta ] ];
    }
}
