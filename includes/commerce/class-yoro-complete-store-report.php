<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Resumable, private-free report aggregation in the authoritative Woo datastore. */
final class YoRo_Complete_Store_Report {
    const VERSION = 4;
    const BATCH_SIZE = 100;
    const STATE_OPTION = '_yoro_complete_report_state_v2';
    const GENERATION_OPTION = '_yoro_complete_report_generation_v2';
    const MUTATION_OPTION = '_yoro_complete_report_mutation_v2';
    private static $new_objects = [];
    private static $initialized = false;
    const MAX_UNITS = 9000000000000000;

    public static function init() {
        if ( self::$initialized ) { return; } self::$initialized = true;
        foreach ( [ 'woocommerce_before_order_object_save', 'woocommerce_before_order_refund_object_save' ] as $hook ) {
            add_action( $hook, static function( $order ) {
                self::$new_objects[spl_object_id( $order )] = ! $order->get_id();
                if ( $order->get_id() ) { self::invalidate(); }
            }, 10, 1 );
        }
        foreach ( [ 'woocommerce_after_order_object_save', 'woocommerce_after_order_refund_object_save' ] as $hook ) {
            add_action( $hook, static function( $order ) {
                $new = self::$new_objects[spl_object_id( $order )] ?? false;
                unset( self::$new_objects[spl_object_id( $order )] );
                self::invalidate( ! $new );
            }, 10, 1 );
        }
        foreach ( [ 'woocommerce_before_delete_order', 'woocommerce_delete_order', 'woocommerce_trash_order', 'woocommerce_untrash_order',
            'woocommerce_before_delete_order_item', 'woocommerce_new_order_item', 'woocommerce_update_order_item', 'woocommerce_before_product_object_save', 'woocommerce_after_product_object_save' ] as $hook ) {
            add_action( $hook, static function() { self::invalidate(); }, 10, 0 );
        }
        foreach (['set_object_terms','created_term','edited_term','delete_term'] as $hook) {
            add_action($hook, static function(...$args) use ($hook) {
                $taxonomy=$hook==='set_object_terms'?($args[3]??''):($args[2]??'');
                if (in_array($taxonomy,['product_cat','product_type'],true)) {self::invalidate();}
            },10,6);
        }
        add_action( 'updated_option', static function( $name ) {
            if ( in_array( $name, [ 'timezone_string', 'gmt_offset', 'woocommerce_currency', 'woocommerce_calc_taxes', 'woocommerce_calc_shipping', 'woocommerce_ship_to_countries', 'woocommerce_custom_orders_table_enabled', 'start_of_week', 'woocommerce_manage_stock', 'woocommerce_notify_low_stock_amount', 'woocommerce_notify_no_stock_amount' ], true ) ) { self::invalidate(); }
        }, 10, 1 );
    }

    public static function invalidate( $existing = true ) {
        update_option( self::GENERATION_OPTION, wp_generate_uuid4(), false );
        if ( $existing ) { update_option( self::MUTATION_OPTION, wp_generate_uuid4(), false ); }
    }

    private static function generation( $option = self::GENERATION_OPTION ) {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
        if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'report_generation_unavailable' ); }
        if ( null === $value ) { add_option( $option, wp_generate_uuid4(), '', false ); return self::generation( $option ); }
        return (string) $value;
    }

    public static function manifest() {
        return [ 'version' => 3, 'actions' => YoRo_Early_Access_Store_Overview_Read::ACTIONS, 'batch_size' => self::BATCH_SIZE,
            'coverage' => 'all_matching_orders', 'windows' => YoRo_Early_Access_Store_Overview_Read::WINDOWS,
            'date_bases' => [ 'created', 'paid' ], 'customer_data' => 'excluded', 'coupon_lookup' => 'exact_code',
            'amount_basis' => YoRo_Early_Access_Store_Overview_Read::AMOUNT_BASIS,
            'not_assessed' => YoRo_Early_Access_Store_Overview_Read::NOT_ASSESSED, 'comparison' => YoRo_Store_Report_Period::Comparisons, 'max_period_days' => 366, 'report_execution_seconds' => 1800, 'calendar' => 'store_timezone_and_week_start' ];
    }

    /** Four independent scopes bound storage without evicting another active calculation. */
    public static function run( array $operation, $guard = null, array $selection = [] ) {
        if ( ! current_user_can( 'yoro_view_store_data' ) ) { return new WP_Error( 'report_access_denied', 'Report access is unavailable.' ); }
        if ($selection && (array_diff(array_keys($selection), ['scope_key','predicate','matches','summary_only','finish','consume','finalize'])
            || !is_string($selection['scope_key'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D',$selection['scope_key'])
            || !is_callable($selection['predicate'] ?? null) || !is_callable($selection['matches'] ?? null)
            || ($selection['summary_only'] ?? null)!==true || !is_callable($selection['finish'] ?? null)
            || (isset($selection['consume']) && !is_callable($selection['consume']))
            || (isset($selection['finalize']) && !is_callable($selection['finalize'])))) {
            return new WP_Error('report_selection_invalid','The local report selector is unavailable.');
        }
        $params = $operation['params']; $basis = $params['date_basis'] ?? 'created';
        $scope = hash( 'sha256', wp_json_encode( [ self::VERSION, get_current_user_id(), home_url(), YoRo_AI_Settings::get_ai_api_url(),
            $selection ? ($operation['action'] ?? '') : '', $selection['scope_key'] ?? '', $params, $basis, (int) get_option( 'start_of_week', 1 ), wp_timezone_string(), get_woocommerce_currency(), wp_date( 'Y-m-d' ), \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ] ) );
        $state_key = self::state_key( $scope );
        if ( null === $state_key ) { return [ 'deferred' => true ]; }
        $state = get_option( $state_key, [] ); $revision = self::generation(); $mutation = self::generation( self::MUTATION_OPTION );
        $owner = hash( 'sha256', $operation['envelope_id'] ?? wp_generate_uuid4() );
        if ( ! is_array( $state ) || ( $state['scope'] ?? null ) !== $scope || ( $state['mutation'] ?? null ) !== $mutation || ( ! empty( $state['complete'] ) && ( $state['revision'] ?? null ) !== $revision && ( $state['owner'] ?? null ) !== $owner ) || ( $state['expires'] ?? 0 ) <= time() ) {
            $now = time();
            try { $resolved = YoRo_Store_Report_Period::resolve( $params, new DateTimeImmutable( '@' . $now ), wp_timezone_string(), (int) get_option( 'start_of_week', 1 ) ); }
            catch ( Throwable $error ) { return new WP_Error( 'report_period_invalid', 'Use valid reporting dates of at most 366 days, without future dates.' ); }
            [ $window, $previous ] = $resolved['windows'];
            $state = [ 'scope' => $scope, 'owner' => $owner, 'mutation' => $mutation, 'revision' => $revision, 'as_of' => $now, 'expires' => min( $now + 1800, isset( $operation['expires_at'] ) ? strtotime( $operation['expires_at'] ) : $now + 1800 ),
                'phase' => 0, 'cursor' => 0, 'anchor' => self::anchor(), 'basis' => $basis, 'windows' => [ $window, $previous ], 'period_context' => $resolved['context'],
                'periods' => [ self::empty_period(), self::empty_period() ], 'backlog' => [ 'open' => 0, 'attention' => 0, 'fulfillment' => 0 ], 'complete' => false ];
            if ($selection) {foreach($state['periods'] as &$period){$period['breakdowns_complete']=false;$period['product_ranking_complete']=false;}unset($period);}
        }
        if ( $state['complete'] && ( $state['completed_at'] ?? $state['as_of'] ) + 30 <= time() ) { delete_option( $state_key ); return self::run( $operation, $guard, $selection ); }
        $until = microtime( true ) + max( 0.001, min( 12, (float) apply_filters( 'yoro_complete_report_seconds', 12 ) ) );
        do {
            if ( ( is_callable( $guard ) && ! $guard() ) || ! current_user_can( 'yoro_view_store_data' )
                || ( isset( $operation['expires_at'] ) && strtotime( $operation['expires_at'] ) <= time() ) ) {
                return new WP_Error( 'report_access_expired', 'Report access expired before completion.' );
            }
            if ( self::generation( self::MUTATION_OPTION ) !== $state['mutation'] ) { delete_option( $state_key ); return new WP_Error( 'report_changed', 'Store data changed. Request a current report.' ); }
            if ( $state['complete'] ) { $result = $selection ? ($selection['finish'])($state) : self::finish( $state ); if ( self::generation( self::MUTATION_OPTION ) !== $state['mutation'] ) { return new WP_Error( 'report_changed', 'Store data changed. Request a current report.' ); } return $result; }
            if ($selection && $state['phase']===4) {
                if (!isset($selection['finalize']) || ($selection['finalize'])($state)) { $state['phase']=5;$state['complete']=true;$state['completed_at']=time(); }
                if (strlen(maybe_serialize($state))>2097152) {throw new RuntimeException('report_checkpoint_capacity');}
                if(self::generation(self::MUTATION_OPTION)!==$state['mutation']){delete_option($state_key);return new WP_Error('report_changed','Store data changed. Request a current report.');}
                update_option($state_key,$state,false);
                global $wpdb;$saved=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",$state_key));
                if($wpdb->last_error!==''||$saved!==maybe_serialize($state)){throw new RuntimeException('report_checkpoint_unavailable');}
                if(function_exists('wp_cache_supports')&&wp_cache_supports('flush_runtime')){wp_cache_flush_runtime();}
                continue;
            }
            if ( $state['period_context']['comparison_mode'] === 'none' && in_array( $state['phase'], [ 1, 3 ], true ) ) { $state['phase']++; $state['cursor'] = 0; continue; }
            $rows = self::batch( $state, $selection );
            foreach ( $rows as $order ) {
                if ( ! $order instanceof WC_Order && ! $order instanceof WC_Order_Refund ) { throw new RuntimeException( 'report_invalid_order' ); }
                if ($selection && !($selection['matches'])($order)) {throw new RuntimeException('report_selection_mismatch');}
                $state['cursor'] = max( $state['cursor'], $order->get_id() );
                self::consume( $state, $order );
                if (isset($selection['consume'])) { ($selection['consume'])($state, $order); }
            }
            if ( count( $rows ) < self::BATCH_SIZE ) {
                $state['phase']++; $state['cursor'] = 0;
                if ( $state['phase'] === 5 ) { $state['complete'] = true; $state['completed_at'] = time(); }
            }
            if ( self::generation( self::MUTATION_OPTION ) !== $state['mutation'] ) { delete_option( $state_key ); return new WP_Error( 'report_changed', 'Store data changed. Request a current report.' ); }
            // Persist cursor and aggregates together; a retry never adds the same committed batch twice.
            if ( strlen( maybe_serialize( $state ) ) > 2097152 ) { throw new RuntimeException( 'report_checkpoint_capacity' ); }
            update_option( $state_key, $state, false );
            global $wpdb; $saved = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $state_key ) );
            if ( '' !== $wpdb->last_error || $saved !== maybe_serialize( $state ) ) { throw new RuntimeException( 'report_checkpoint_unavailable' ); }
            do_action( 'yoro_complete_report_batch', $state['phase'], $state['cursor'] );
            if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) { wp_cache_flush_runtime(); }
        } while ( microtime( true ) < $until );
        return [ 'deferred' => true ];
    }

    private static function state_key( $scope ) {
        $key = self::STATE_OPTION . '_' . $scope;
        $index = get_option( self::STATE_OPTION . '_index', [] );
        if ( ! is_array( $index ) ) { $index = []; }
        foreach ( $index as $old => $expiry ) {
            if ( $expiry <= time() ) { delete_option( $old ); unset( $index[$old] ); }
        }
        if ( ! isset( $index[$key] ) && count( $index ) >= 4 ) {
            foreach ( $index as $old => $expiry ) {
                $state = get_option( $old, [] );
                if ( ! empty( $state['complete'] ) ) { delete_option( $old ); unset( $index[$old] ); break; }
            }
            if ( count( $index ) >= 4 ) { return null; }
        }
        $index[$key] = time() + 1800;
        update_option( self::STATE_OPTION . '_index', $index, false );
        return $key;
    }

    private static function anchor() {
        global $wpdb;
        $hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $table = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
        $value = $wpdb->get_var( "SELECT MAX(ID) FROM {$table}" );
        if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'report_anchor_unavailable' ); }
        return (int) $value;
    }

    /** Keyset predicates are scoped to this query and removed even on failure. */
    private static function batch( array $state, array $selection = [] ) {
        $refund = $state['phase'] === 2 || $state['phase'] === 3;
        $period = $state['phase'] % 2; $backlog = $state['phase'] === 4;
        $token = wp_generate_uuid4(); $applied = false;
        $predicate = static function( $id ) use ( $state ) { return $id . ' > ' . (int) $state['cursor'] . ' AND ' . $id . ' <= ' . (int) $state['anchor']; };
        $hpos = static function( $clauses, $query, $vars ) use ( $token, &$applied, $predicate, $selection, $refund ) {
            if ( ( $vars['yoro_report_cursor'] ?? null ) === $token ) { $id=$query->get_table_name('orders').'.id';$clauses['where'] .= ' AND (' . $predicate( $id ) . ')' . ($selection?' AND ('.($selection['predicate'])($id,true,$query,$refund).')':''); $applied = true; }
            return $clauses;
        };
        $legacy = static function( $where, $query ) use ( $token, &$applied, $predicate, $selection, $refund ) {
            if ( $query->get( 'yoro_report_cursor' ) === $token ) { global $wpdb;$id=$wpdb->posts.'.ID'; $where .= ' AND (' . $predicate( $id ) . ')' . ($selection?' AND ('.($selection['predicate'])($id,false,null,$refund).')':''); $applied = true; }
            return $where;
        };
        $legacy_args = static function( $args, $vars ) use ( $token ) { if ( ( $vars['yoro_report_cursor'] ?? null ) === $token ) { $args['yoro_report_cursor'] = $token; $args['suppress_filters'] = false; } return $args; };
        add_filter( 'woocommerce_orders_table_query_clauses', $hpos, 10, 3 );
        add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $legacy_args, 10, 2 ); add_filter( 'posts_where', $legacy, 10, 2 );
        try {
            $args = [ 'type' => $refund ? 'shop_order_refund' : 'shop_order', 'limit' => self::BATCH_SIZE, 'orderby' => 'ID', 'order' => 'ASC',
                'yoro_report_cursor' => $token, 'status' => $refund ? array_keys( wc_get_order_statuses() ) : ( $backlog ? [ 'pending', 'on-hold', 'processing', 'failed' ] : YoRo_Early_Access_Order_Read::STATUSES ) ];
            if ( ! $backlog ) { $w = $state['windows'][$period]; $args[ ! $refund && $state['basis'] === 'paid' ? 'date_paid' : 'date_created' ] = strtotime( $w['start'] ) . '...' . strtotime( $w['end'] ); }
            else { $args['date_created'] = '<=' . $state['as_of']; }
            $rows = wc_get_orders( $args );
            if ( ! $applied ) { throw new RuntimeException( 'report_cursor_not_applied' ); }
            return $rows;
        } finally {
            remove_filter( 'woocommerce_orders_table_query_clauses', $hpos, 10 ); remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $legacy_args, 10 ); remove_filter( 'posts_where', $legacy, 10 );
        }
    }

    private static function empty_period() {
        return [ 'scanned' => 0, 'refunds_scanned' => 0, 'invalid_orders' => 0, 'status_counts' => array_fill_keys( YoRo_Early_Access_Order_Read::STATUSES, 0 ), 'currencies' => [], 'daily' => [], 'products' => [], 'product_ranking_complete' => true, 'breakdowns_complete' => true, 'payments' => [] ];
    }

    private static function empty_money() {
        return array_fill_keys( [ 'order_count', 'order_totals', 'discounts', 'tax', 'shipping', 'fees', 'refunds', 'refund_tax', 'refund_shipping' ], 0 );
    }

    private static function consume( array &$state, $order ) {
        $phase = $state['phase']; $refund = $phase === 2 || $phase === 3;
        if ( $phase === 4 ) {
            $state['backlog']['open']++; $age = (int) floor( ( $state['as_of'] - $order->get_date_created()->getTimestamp() ) / HOUR_IN_SECONDS ); $status = $order->get_status();
            if ( ( $status === 'pending' && $age >= 24 ) || ( $status === 'on-hold' && $age >= 48 ) || ( $status === 'processing' && $age >= 72 ) || ( $status === 'failed' && $age <= 168 ) ) { $state['backlog']['attention']++; }
            if ( $status === 'processing' && wc_shipping_enabled() && YoRo_Orders_Read_Service::fulfillment_order_requires_shipping( $order ) ) { $state['backlog']['fulfillment']++; }
            return;
        }
        $p = &$state['periods'][$phase % 2]; $currency = $order->get_currency();
        if ( ! preg_match( '/\A[A-Z]{3}\z/D', $currency ) ) { $p['invalid_orders']++; return; }
        if ( ! isset( $p['currencies'][$currency] ) ) { if ( count( $p['currencies'] ) >= 32 ) { throw new RuntimeException( 'report_currency_capacity' ); } $p['currencies'][$currency] = self::empty_money(); }
        $m = &$p['currencies'][$currency];
        if ( $refund ) {
            $p['refunds_scanned']++;
            foreach ( [ 'refunds' => $order->get_amount(), 'refund_tax' => ltrim( (string) $order->get_total_tax(), '-' ), 'refund_shipping' => ltrim( (string) $order->get_shipping_total(), '-' ) ] as $field => $value ) { self::add( $m[$field], self::units( (string) $value ) ); }
            return;
        }
        $p['scanned']++; $status = $order->get_status();
        if ( ! isset( $p['status_counts'][$status] ) ) { $p['invalid_orders']++; return; }
        $p['status_counts'][$status]++;
        if ( ! in_array( $status, [ 'processing', 'completed', 'refunded' ], true ) ) { return; }
        $amount = self::units( (string) $order->get_total() ); $m['order_count']++;
        foreach ( [ 'order_totals' => $order->get_total(), 'discounts' => $order->get_discount_total(), 'tax' => $order->get_total_tax(), 'shipping' => $order->get_shipping_total() ] as $field => $value ) { self::add( $m[$field], self::units( (string) $value ) ); }
        foreach ( $order->get_items( 'fee' ) as $fee ) { self::add( $m['fees'], self::units( (string) $fee->get_total(), true ) ); }
        if ( $p['breakdowns_complete'] ) {
            $day = $order->{ $state['basis'] === 'paid' ? 'get_date_paid' : 'get_date_created' }()->setTimezone( wp_timezone() )->format( 'Y-m-d' );
            $p['daily'][$day][$currency] = $p['daily'][$day][$currency] ?? [ 'order_count' => 0, 'order_totals' => 0 ]; $p['daily'][$day][$currency]['order_count']++; self::add( $p['daily'][$day][$currency]['order_totals'], $amount );
            $method = sanitize_key( $order->get_payment_method() ) ?: 'not_set';
            if ( ! isset( $p['payments'][$method] ) && count( $p['payments'] ) >= 32 ) {
                $p['daily'] = []; $p['payments'] = []; $p['products'] = []; $p['breakdowns_complete'] = false; $p['product_ranking_complete'] = false;
            } else {
                $p['payments'][$method][$currency] = $p['payments'][$method][$currency] ?? [ 'order_count' => 0, 'order_totals' => 0 ]; $p['payments'][$method][$currency]['order_count']++; self::add( $p['payments'][$method][$currency]['order_totals'], $amount );
            }
        }
        if ( $phase === 0 && $p['product_ranking_complete'] ) {
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $id = $item->get_variation_id() ?: $item->get_product_id(); if ( ! $id ) { continue; }
                $key = $currency . ':' . $id;
                if ( ! isset( $p['products'][$key] ) && count( $p['products'] ) >= 10000 ) { $p['product_ranking_complete'] = false; $p['products'] = []; break; }
                $p['products'][$key] = $p['products'][$key] ?? [ 'id' => $id, 'currency' => $currency, 'quantity' => 0, 'line_totals' => 0 ];
                self::add( $p['products'][$key]['quantity'], self::units( (string) $item->get_quantity() ) ); self::add( $p['products'][$key]['line_totals'], self::units( (string) $item->get_total() ) );
            }
        }
    }

    public static function units( $value, $signed = false ) {
        if ( ! is_string( $value ) || ! preg_match( $signed ? '/\A(-?)(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,6}))?\z/D' : '/\A()(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,6}))?\z/D', $value, $m ) ) { throw new RuntimeException( 'report_invalid_money' ); }
        return ( $m[1] === '-' ? -1 : 1 ) * ( (int) $m[2] * 1000000 + (int) str_pad( $m[3] ?? '', 6, '0' ) );
    }

    private static function add( &$total, $amount ) {
        if ( abs( $amount ) > self::MAX_UNITS || abs( $total ) > self::MAX_UNITS - abs( $amount ) ) { throw new RuntimeException( 'report_numeric_capacity' ); }
        $total += $amount;
    }

    public static function decimal( $value ) {
        return ( $value < 0 ? '-' : '' ) . intdiv( abs( $value ), 1000000 ) . '.' . str_pad( (string) ( abs( $value ) % 1000000 ), 6, '0', STR_PAD_LEFT );
    }

    private static function finish( array $state ) {
        $periods = $state['periods'];
        if ( $periods[0]['invalid_orders'] || $periods[1]['invalid_orders'] ) { return new WP_Error( 'report_invalid_data', 'Some order records could not be assessed. Request a current report after reviewing store data.' ); }
        foreach ( $periods as &$p ) {
            ksort( $p['currencies'] ); ksort( $p['daily'] ); ksort( $p['payments'] );
            foreach ( $p['currencies'] as &$m ) {
                $m['order_totals_less_period_refunds'] = self::decimal( $m['order_totals'] - $m['refunds'] );
                $m['net_ex_tax_shipping'] = self::decimal( $m['order_totals'] - $m['tax'] - $m['shipping'] - $m['refunds'] + $m['refund_tax'] + $m['refund_shipping'] );
                foreach ( $m as $key => &$value ) { if ( is_int( $value ) && $key !== 'order_count' ) { $value = self::decimal( $value ); } } unset( $value );
            } unset( $m );
            foreach ( [ 'daily', 'payments' ] as $group ) { foreach ( $p[$group] as &$row ) { foreach ( $row as &$money ) { $money['order_totals'] = self::decimal( $money['order_totals'] ); } unset( $money ); } unset( $row ); }
            $products = [];
            foreach ( array_keys( $p['currencies'] ) as $currency ) {
                $items = array_values( array_filter( $p['products'], static function( $item ) use ( $currency ) { return $item['currency'] === $currency; } ) );
                usort( $items, static function( $a, $b ) { return $b['line_totals'] <=> $a['line_totals'] ?: $a['id'] <=> $b['id']; } );
                foreach ( array_slice( $items, 0, 5 ) as $row ) {
                    $product = wc_get_product( $row['id'] ); $row['name'] = $product && $product->get_status() === 'publish' ? mb_strcut( sanitize_text_field( $product->get_name() ), 0, 160, 'UTF-8' ) : 'Product #' . $row['id'];
                    $row['quantity'] = self::decimal( $row['quantity'] ); $row['line_totals'] = self::decimal( $row['line_totals'] ); $products[] = $row;
                }
            }
            $p['products'] = $products;
        } unset( $p );
        $context = $state['period_context']; $context['chart_granularity'] = 'day';
        $result = [ 'version' => 3, 'status' => 'complete', 'generated_at' => gmdate( 'c', $state['as_of'] ), 'completed_at' => gmdate( 'c', $state['completed_at'] ),
            'date_basis' => $state['basis'], 'refund_basis' => 'refund_created_date', 'data_revision' => hash( 'sha256', $state['revision'] ),
            'windows' => $state['windows'], 'period_context' => $context, 'current' => $periods[0], 'previous' => $periods[1], 'backlog' => $state['backlog'], 'batch_size' => self::BATCH_SIZE ];
        if ( strlen( wp_json_encode( $result ) ) > 38000 ) {
            $result['period_context']['chart_granularity'] = 'month';
            foreach ( [ 'current', 'previous' ] as $index => $key ) {
                $monthly = [];
                foreach ( $result[$key]['daily'] as $day => $currencies ) {
                    $bucket = YoRo_Store_Report_Period::bucket( $day, $state['windows'][$index], $result['period_context'] );
                    foreach ( $currencies as $currency => $row ) {
                        $monthly[$bucket][$currency] = $monthly[$bucket][$currency] ?? [ 'order_count' => 0, 'order_totals' => 0 ];
                        $monthly[$bucket][$currency]['order_count'] += $row['order_count'];
                        self::add( $monthly[$bucket][$currency]['order_totals'], self::units( $row['order_totals'] ) );
                    }
                }
                foreach ( $monthly as &$currencies ) { foreach ( $currencies as &$row ) { $row['order_totals'] = self::decimal( $row['order_totals'] ); } unset( $row ); } unset( $currencies );
                $result[$key]['daily'] = $monthly;
            }
        }
        if ( strlen( wp_json_encode( $result ) ) > 38000 ) {
            foreach ( [ 'current', 'previous' ] as $key ) { $result[$key]['daily'] = []; $result[$key]['payments'] = []; $result[$key]['products'] = []; $result[$key]['product_ranking_complete'] = false; $result[$key]['breakdowns_complete'] = false; }
        }
        if ( strlen( wp_json_encode( $result ) ) > 42000 ) { throw new RuntimeException( 'report_payload_capacity' ); }
        return $result;
    }
}
