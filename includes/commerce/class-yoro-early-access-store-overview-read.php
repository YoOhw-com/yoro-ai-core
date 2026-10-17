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
        return ! array_diff( array_keys( $params ), [ 'window', 'date_basis' ] ) && in_array( $params['window'] ?? null, self::WINDOWS, true ) && in_array( $params['date_basis'] ?? 'created', [ 'created', 'paid' ], true );
    }

    public static function execute( array $operation, $guard = null ) {
        $action = $operation['action'] ?? '';
        $params = $operation['params'] ?? [];
        if ( ! self::valid_params( $action, $params ) || ! current_user_can( 'yoro_view_store_data' ) || ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'store_overview_unavailable', 'The store overview read did not complete.' );
        }
        $generated = time();
        if ( 'coupons.lookup' === $action ) { return self::coupon( $action, $params['coupon_code'], $generated ); }
        $full = YoRo_Complete_Store_Report::run( $operation, $guard );
        if ( is_wp_error( $full ) || isset( $full['deferred'] ) ) { return $full; }
        $currency = get_woocommerce_currency(); $periods = [];
        foreach ( [ 'current', 'previous' ] as $key ) {
            $p = $full[$key]; $m = $p['currencies'][$currency] ?? [ 'order_count' => 0, 'order_totals' => '0.000000' ];
            $units = YoRo_Complete_Store_Report::units( $m['order_totals'] );
            $periods[$key] = [ 'order_count' => $m['order_count'], 'gross_sales' => $m['order_totals'],
                'average_order_value' => YoRo_Complete_Store_Report::decimal( $m['order_count'] ? intdiv( $units + intdiv( $m['order_count'], 2 ), $m['order_count'] ) : 0 ),
                'currency' => $currency, 'is_complete' => 0 === $p['invalid_orders'] ];
        }
        $comparison = [];
        foreach ( [ 'order_count', 'gross_sales', 'average_order_value' ] as $field ) {
            $a = 'order_count' === $field ? $periods['current'][$field] : YoRo_Complete_Store_Report::units( $periods['current'][$field] );
            $b = 'order_count' === $field ? $periods['previous'][$field] : YoRo_Complete_Store_Report::units( $periods['previous'][$field] );
            $comparison[$field] = [ 'current' => $periods['current'][$field], 'previous' => $periods['previous'][$field], 'trend' => $a > $b ? 'up' : ( $a < $b ? 'down' : 'flat' ),
                'delta' => 'order_count' === $field ? (string) ( $a - $b ) : YoRo_Complete_Store_Report::decimal( $a - $b ) ];
        }
        $stats = static function( $p ) { return [ 'scanned' => $p['scanned'], 'has_more' => false, 'invalid_orders' => $p['invalid_orders'], 'other_currency_orders' => 0, 'status_counts' => $p['status_counts'] ]; };
        $data = [ 'window' => $full['windows'][0], 'metrics' => $periods['current'], 'comparison' => [ 'window' => $full['windows'][1], 'metrics' => $comparison ],
            'counts' => [ 'attention' => $full['backlog']['attention'], 'fulfillment' => $full['backlog']['fulfillment'], 'customer_followups' => 0, 'inventory' => 0 ],
            'attention_items' => [], 'inventory_items' => [], 'priorities' => [] ];
        return self::result( $action, $data, [ 'version' => 2, 'kind' => 'report', 'generated_at' => $full['generated_at'], 'batch_size' => 100,
            'current' => $stats( $full['current'] ), 'previous' => $stats( $full['previous'] ), 'amount_basis' => self::AMOUNT_BASIS,
            'sales_statuses' => [ 'processing', 'completed', 'refunded' ], 'not_assessed' => self::NOT_ASSESSED,
            'comparison_complete' => $periods['current']['is_complete'] && $periods['previous']['is_complete'], 'full_report' => $full ] );
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
            : 'Complete-period aggregate with separate currencies, refund-date flows and current backlog. Amount definitions and data timestamps are explicit. Inventory and customer follow-ups are not assessed.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message, 'data' => $data, 'read_only' => true, 'undoable' => false,
            'result_type' => 'store_overview', 'display_type' => 'store_overview', 'assistant_summary' => $message, 'extensions' => [ 'yoro.store_overview' => $meta ] ];
    }
}
