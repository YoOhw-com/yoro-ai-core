<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Negotiated order search. Predicates run in the authoritative datastore before pagination. */
final class YoRo_Early_Access_Order_Search {
    const FILTERS = [ 'email', 'phone', 'phone_country', 'customer_id', 'username', 'customer_name', 'name_match', 'name_source',
        'reference_order_id', 'product_id', 'variation_id', 'sku', 'product_name', 'item_name', 'product_match', 'total_min', 'total_max',
        'currency', 'payment_method', 'shipping_country', 'shipping_state', 'shipping_city', 'shipping_postcode', 'shipping_address',
        'tracking_number', 'tracking_carrier', 'note_text', 'note_type', 'order_number', 'anchor_id' ];
    const SELECTORS = [ 'email', 'phone', 'customer_id', 'username', 'customer_name', 'reference_order_id' ];
    const COMPANIONS = [ 'phone_country', 'name_match', 'name_source', 'product_match', 'note_type', 'anchor_id', 'status', 'date_from', 'date_to' ];
    const TRACKING_KEYS = [ '_tracking_number', '_aftership_tracking_number', '_wcast_tracking_number', '_wcst_order_trackno', '_yith_tracking_code' ];
    const TRACKING_PROVIDERS = [ '_tracking_provider', '_aftership_tracking_provider', '_wcast_tracking_company_name', '_wcst_order_trackname', '_yith_tracking_carrier_name' ];

    public static function manifest() {
        $numbers = self::number_adapters();
        return [ 'version' => 1, 'actions' => [ 'orders.search', 'orders.find_by_customer' ], 'max_items' => 20, 'pagination' => 'page_v1',
            'filters' => $numbers ? self::FILTERS : array_values( array_diff( self::FILTERS, [ 'order_number' ] ) ),
            'adapters' => [ 'tracking' => [ 'stored_tracking_meta_v1', 'woocommerce_shipment_tracking_items' ], 'order_number' => $numbers ] ];
    }

    private static function number_adapters() {
        $adapters = [];
        // Verified upstream 1.11.2: get_order_number()/find_order_by_order_number() use _order_number in both stores.
        // https://github.com/godaddy-wordpress/woocommerce-sequential-order-numbers/blob/dffaf51bbbef08b1f962b66dfd552f17981a218e/woocommerce-sequential-order-numbers.php
        $plugin = 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php';
        $active = in_array( $plugin, (array) get_option( 'active_plugins', [] ), true ) || isset( get_site_option( 'active_sitewide_plugins', [] )[$plugin] );
        if ( $active && class_exists( 'WC_Seq_Order_Number', false ) && defined( 'WC_Seq_Order_Number::VERSION' )
            && '1.11.2' === WC_Seq_Order_Number::VERSION && function_exists( 'wc_sequential_order_numbers' )
            && method_exists( 'WC_Seq_Order_Number', 'get_order_number' ) && method_exists( 'WC_Seq_Order_Number', 'find_order_by_order_number' ) ) {
            $adapters[] = 'skyverge_sequential_order_numbers_1_11_2';
        }
        return $adapters;
    }

    public static function valid_facts( $action, $params, $facts ) {
        if ( ! in_array( $action, [ 'orders.search', 'orders.find_by_customer' ], true ) || ! is_array( $params ) || ! is_array( $facts )
            || ! is_array( $facts['order_search'] ?? null ) ) { return false; }
        $search = $facts['order_search'];
        if ( array_diff( array_keys( $search ), [ 'version', 'filters', 'page' ] ) || 1 !== ( $search['version'] ?? null )
            || ! is_int( $search['page'] ?? null ) || $search['page'] < 1 || $search['page'] > 1000
            || ! is_array( $search['filters'] ?? null ) || ! is_int( $params['limit'] ?? null ) || $params['limit'] < 1 || $params['limit'] > 20 ) { return false; }
        if ( 'orders.search' === $action && ( $facts['order_page'] ?? null ) !== $search['page'] ) { return false; }
        $filters = $search['filters'];
        $allowed = 'orders.find_by_customer' === $action ? array_merge( self::FILTERS, [ 'status', 'date_from', 'date_to' ] ) : self::FILTERS;
        if ( array_diff( array_keys( $filters ), $allowed ) ) { return false; }
        if ( 'orders.find_by_customer' === $action ) {
            $selectors = array_intersect( array_keys( $params ), YoRo_Early_Access_Customer_Service_Read::SELECTORS );
            if ( 1 !== count( $selectors ) || array_intersect( array_keys( $filters ), self::SELECTORS ) ) { return false; }
        }
        if ( count( array_intersect( array_keys( $filters ), self::SELECTORS ) ) > 1 ) { return false; }
        foreach ( [ 'name_match' => [ 'exact', 'contains' ], 'name_source' => [ 'billing', 'shipping' ], 'product_match' => [ 'exact', 'contains' ], 'note_type' => [ 'all', 'internal', 'customer' ] ] as $key => $values ) {
            if ( array_key_exists( $key, $filters ) && ! in_array( $filters[$key], $values, true ) ) { return false; }
        }
        $has_name = isset( $filters['customer_name'] ) || isset( $params['customer_name'] );
        $has_phone = isset( $filters['phone'] ) || isset( $params['phone'] );
        if ( ( isset( $filters['name_match'] ) || isset( $filters['name_source'] ) ) && ! $has_name
            || isset( $filters['phone_country'] ) && ! $has_phone
            || isset( $filters['product_match'] ) && ! isset( $filters['product_name'] ) && ! isset( $filters['item_name'] )
            || isset( $filters['note_type'] ) && ! isset( $filters['note_text'] ) ) { return false; }
        foreach ( [ 'customer_id', 'reference_order_id', 'product_id', 'variation_id', 'anchor_id' ] as $key ) {
            if ( array_key_exists( $key, $filters ) && ( ! is_int( $filters[$key] ) || $filters[$key] < 1 ) ) { return false; }
        }
        $lengths = [ 'email' => [ 3, 254 ], 'phone' => [ 7, 32 ], 'username' => [ 1, 60 ], 'customer_name' => [ 2, 80 ], 'sku' => [ 1, 128 ], 'product_name' => [ 2, 256 ],
            'item_name' => [ 2, 256 ], 'payment_method' => [ 1, 80 ], 'shipping_state' => [ 1, 80 ], 'shipping_city' => [ 1, 80 ], 'shipping_postcode' => [ 1, 32 ],
            'shipping_address' => [ 2, 200 ], 'tracking_number' => [ 6, 64 ], 'tracking_carrier' => [ 1, 80 ], 'note_text' => [ 3, 200 ], 'order_number' => [ 1, 64 ] ];
        foreach ( $lengths as $key => $bounds ) {
            if ( array_key_exists( $key, $filters ) && ! self::text( $filters[$key], $bounds[0], $bounds[1] ) ) { return false; }
        }
        if ( isset( $filters['email'] ) && ! is_email( $filters['email'] ) ) { return false; }
        foreach ( [ 'tracking_number', 'tracking_carrier' ] as $key ) {
            if ( isset( $filters[$key] ) && preg_match( '/[{}]/', $filters[$key] ) ) { return false; }
        }
        if ( isset( $filters['phone'] ) && ( ! preg_match( '/\A[+0-9 ().-]+\z/D', $filters['phone'] )
            || strlen( self::digits( $filters['phone'] ) ) < 7 || strlen( self::digits( $filters['phone'] ) ) > 15 ) ) { return false; }
        foreach ( [ 'phone_country', 'shipping_country' ] as $key ) {
            if ( isset( $filters[$key] ) && ( ! is_string( $filters[$key] ) || ! preg_match( '/\A[A-Z]{2}\z/D', $filters[$key] ) ) ) { return false; }
        }
        if ( isset( $filters['currency'] ) && ( ! is_string( $filters['currency'] ) || ! preg_match( '/\A[A-Z]{3}\z/D', $filters['currency'] ) ) ) { return false; }
        foreach ( [ 'total_min', 'total_max' ] as $key ) {
            if ( isset( $filters[$key] ) && ( ! is_string( $filters[$key] ) || ! preg_match( '/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D', $filters[$key] ) || ! isset( $filters['currency'] ) ) ) { return false; }
        }
        if ( isset( $filters['total_min'], $filters['total_max'] ) && self::decimal( $filters['total_min'] ) > self::decimal( $filters['total_max'] ) ) { return false; }
        if ( isset( $filters['status'] ) && ! in_array( $filters['status'], YoRo_Early_Access_Order_Read::STATUSES, true ) ) { return false; }
        foreach ( [ 'date_from', 'date_to' ] as $key ) {
            if ( array_key_exists( $key, $filters ) ) {
                $date = is_string( $filters[$key] ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $filters[$key] ) : false;
                if ( ! $date || $date->format( 'Y-m-d' ) !== $filters[$key] ) { return false; }
            }
        }
        return ! isset( $filters['date_from'], $filters['date_to'] ) || $filters['date_from'] <= $filters['date_to'];
    }

    private static function text( $value, $min, $max ) {
        return is_string( $value ) && trim( $value ) === $value && mb_strlen( $value ) >= $min && mb_strlen( $value ) <= $max
            && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1f\x7f<>]/', $value );
    }

    private static function digits( $value ) { return preg_replace( '/[^0-9]/', '', $value ); }
    private static function decimal( $value ) {
        $parts = explode( '.', $value, 2 );
        return str_pad( $parts[0], 12, '0', STR_PAD_LEFT ) . str_pad( $parts[1] ?? '', 6, '0' );
    }

    public static function execute( array $operation ) {
        $action = $operation['action'] ?? ''; $params = $operation['params'] ?? [];
        $facts = $operation['extensions']['yoro.mcp_early_access_product_read'] ?? [];
        if ( ! current_user_can( 'yoro_view_store_data' ) || ! function_exists( 'wc_get_orders' ) || ! self::valid_facts( $action, $params, $facts ) ) {
            return new WP_Error( 'order_search_invalid', 'The negotiated order search is invalid.' );
        }
        if ( 'orders.search' === $action && ! YoRo_Early_Access_Order_Read::valid_params( $action, $params, $facts ) ) {
            return new WP_Error( 'order_search_invalid', 'The canonical order search parameters are invalid.' );
        }
        $filters = $facts['order_search']['filters']; $page = $facts['order_search']['page'];
        $reasons = array_values( array_diff( array_keys( $filters ), self::COMPANIONS ) );
        $base = $params;
        if ( 'orders.find_by_customer' === $action ) {
            if ( ! YoRo_Early_Access_Customer_Service_Read::valid_params( $action, $params, $facts ) ) { return new WP_Error( 'order_search_invalid', 'The customer selector is invalid.' ); }
            foreach ( YoRo_Early_Access_Customer_Service_Read::SELECTORS as $key ) {
                if ( isset( $params[$key] ) ) { $mapped = 'order_id' === $key ? 'reference_order_id' : $key; $filters[$mapped] = $params[$key]; $reasons[] = $mapped; }
            }
            $base = array_intersect_key( $filters, array_flip( [ 'status', 'date_from', 'date_to' ] ) );
        }
        if ( isset( $filters['username'] ) ) {
            $user = get_user_by( 'login', $filters['username'] );
            if ( $user ) { $filters['customer_id'] = (int) $user->ID; }
            else { $filters['_no_match'] = true; }
        }
        if ( isset( $filters['reference_order_id'] ) ) {
            $seed = wc_get_order( $filters['reference_order_id'] );
            if ( ! self::readable( $seed ) ) { return new WP_Error( 'order_search_reference_unavailable', 'The reference order is unavailable.' ); }
            if ( $seed->get_customer_id() ) { $filters['customer_id'] = (int) $seed->get_customer_id(); }
            elseif ( is_email( $seed->get_billing_email( 'edit' ) ) ) { $filters['email'] = $seed->get_billing_email( 'edit' ); }
            elseif ( strlen( self::digits( $seed->get_billing_phone( 'edit' ) ) ) >= 7 ) { $filters['phone'] = $seed->get_billing_phone( 'edit' ); }
            else { return new WP_Error( 'order_search_reference_unavailable', 'The reference order has no supported customer identity.' ); }
        }
        // Display-number filters are arbitrary code; use only explicitly verified storage adapters.
        $filters['_custom_number'] = in_array( 'skyverge_sequential_order_numbers_1_11_2', self::number_adapters(), true );
        if ( isset( $filters['order_number'] ) && ! $filters['_custom_number'] ) {
            return new WP_Error( 'order_search_adapter_unavailable', 'No supported custom order-number adapter is available.' );
        }
        $latest = wc_get_orders( [ 'type' => 'shop_order', 'status' => YoRo_Early_Access_Order_Read::STATUSES,
            'limit' => 1, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'DESC' ] );
        $anchor = isset( $filters['anchor_id'] ) ? $filters['anchor_id'] : (int) ( $latest[0] ?? 0 );
        $args = [ 'type' => 'shop_order', 'status' => isset( $base['status'] ) ? [ $base['status'] ] : YoRo_Early_Access_Order_Read::STATUSES,
            'limit' => $params['limit'], 'page' => $page, 'paginate' => true, 'orderby' => 'ID', 'order' => 'DESC', 'suppress_filters' => false,
            '_yoro_order_search' => wp_generate_uuid4() ];
        if ( isset( $base['order_id'] ) ) { $filters['_exact_order_id'] = $base['order_id']; }
        $from = isset( $base['date_from'] ) ? new DateTimeImmutable( $base['date_from'], wp_timezone() ) : null;
        $to = isset( $base['date_to'] ) ? ( new DateTimeImmutable( $base['date_to'], wp_timezone() ) )->modify( '+1 day' )->modify( '-1 second' ) : null;
        if ( $from && $to ) { $args['date_created'] = $from->getTimestamp() . '...' . $to->getTimestamp(); }
        elseif ( $from ) { $args['date_created'] = '>=' . $from->getTimestamp(); }
        elseif ( $to ) { $args['date_created'] = '<=' . $to->getTimestamp(); }
        $queried = self::query( $args, $filters, $anchor );
        if ( is_wp_error( $queried ) ) { return $queried; }
        $rows = [];
        foreach ( $queried->orders as $order ) {
            if ( ! self::readable( $order ) ) { return new WP_Error( 'order_search_unavailable', 'The order search returned an unreadable order.' ); }
            $rows[] = YoRo_Early_Access_Order_Read::search_row( $order );
        }
        $has_next = $page < 1000 && $queried->max_num_pages > $page;
        $manifest = self::manifest(); $matches = [];
        foreach ( $rows as $row ) { $matches[] = [ 'order_id' => $row['id'], 'reasons' => array_values( array_unique( $reasons ) ) ]; }
        $message = 'Order search completed. Customer contact details, addresses, note text and tracking values are excluded from the result.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message,
            'data' => [ 'orders' => $rows, 'count' => count( $rows ), 'page_info' => [ 'has_next_page' => $has_next, 'next_cursor' => $has_next ? (string) ( $page + 1 ) : null ] ],
            'read_only' => true, 'undoable' => false, 'result_type' => 'orders', 'display_type' => 'orders_list', 'assistant_summary' => $message,
            'extensions' => [ 'yoro.order_search' => [ 'version' => 1, 'page' => $page, 'anchor_id' => $anchor, 'has_next_page' => $has_next,
                'total_matches' => (int) $queried->total, 'matches' => $matches, 'supported_filters' => $manifest['filters'], 'adapters' => $manifest['adapters'] ] ] ];
    }

    private static function readable( $order ) {
        return $order instanceof WC_Order && 'shop_order' === $order->get_type() && $order->get_date_created()
            && in_array( $order->get_status(), YoRo_Early_Access_Order_Read::STATUSES, true );
    }

    private static function query( array $args, array $filters, $anchor ) {
        global $wpdb;
        $token = $args['_yoro_order_search']; $applied = false;
        $hpos = static function( $clauses, $query, $vars ) use ( $token, $filters, $anchor, &$applied ) {
            if ( ( $vars['_yoro_order_search'] ?? null ) !== $token ) { return $clauses; }
            $clauses['where'] .= ' AND (' . self::predicate( $filters, $anchor, $query->get_table_name( 'orders' ) . '.id', true, $query ) . ')';
            $applied = true; return $clauses;
        };
        $cpt = static function( $clauses, $query ) use ( $token, $filters, $anchor, &$applied ) {
            if ( $query->get( '_yoro_order_search' ) !== $token ) { return $clauses; }
            global $wpdb;
            $clauses['where'] .= ' AND (' . self::predicate( $filters, $anchor, $wpdb->posts . '.ID', false ) . ')';
            $applied = true; return $clauses;
        };
        add_filter( 'woocommerce_orders_table_query_clauses', $hpos, 10, 3 );
        add_filter( 'posts_clauses', $cpt, 10, 2 );
        try {
            $result = wc_get_orders( $args );
            if ( ! $applied || $wpdb->last_error || ! is_object( $result ) || ! isset( $result->orders, $result->total, $result->max_num_pages ) ) {
                return new WP_Error( 'order_search_unavailable', 'The datastore could not execute the complete order search.' );
            }
            // Both WC/WP skip counting when the requested page is empty. Recover only the count with one bounded first-page query.
            if ( $args['page'] > 1 && ! $result->orders ) {
                $count_args = array_merge( $args, [ 'page' => 1, 'limit' => 1, 'return' => 'ids', '_yoro_order_search' => wp_generate_uuid4() ] );
                $counted = self::query( $count_args, $filters, $anchor );
                if ( is_wp_error( $counted ) ) { return $counted; }
                $result->total = $counted->total; $result->max_num_pages = (int) ceil( $counted->total / $args['limit'] );
            }
            return $result;
        } catch ( Throwable $error ) {
            return new WP_Error( 'order_search_unavailable', 'The datastore could not execute the complete order search.' );
        } finally {
            remove_filter( 'woocommerce_orders_table_query_clauses', $hpos, 10 );
            remove_filter( 'posts_clauses', $cpt, 10 );
        }
    }

    /** All identifiers below come from WooCommerce/WordPress or fixed mappings, never input. */
    private static function predicate( array $filters, $anchor, $id, $hpos, $query = null ) {
        global $wpdb;
        $main = $hpos ? $query->get_table_name( 'orders' ) : $wpdb->posts;
        $meta = $hpos ? $query->get_table_name( 'meta' ) : $wpdb->postmeta;
        $meta_id = $hpos ? 'order_id' : 'post_id';
        $scalar = static function( $field ) use ( $hpos, $main, $meta, $meta_id, $id, $query, $wpdb ) {
            $mapping = [ 'customer_id' => [ 'customer_id', '_customer_user' ], 'email' => [ 'billing_email', '_billing_email' ],
                'currency' => [ 'currency', '_order_currency' ], 'payment_method' => [ 'payment_method', '_payment_method' ], 'total' => [ 'total_amount', '_order_total' ] ];
            if ( isset( $mapping[$field] ) ) {
                if ( $hpos ) { return $main . '.' . $mapping[$field][0]; }
                $key = $mapping[$field][1];
            } else {
                if ( $hpos ) {
                    $parts = explode( '_', $field, 2 ); $table = $query->get_table_name( 'addresses' );
                    return $wpdb->prepare( "(SELECT yos_a.{$parts[1]} FROM {$table} yos_a WHERE yos_a.order_id = {$id} AND yos_a.address_type = %s LIMIT 1)", $parts[0] );
                }
                $key = '_' . $field;
            }
            return $wpdb->prepare( "(SELECT yos_m.meta_value FROM {$meta} yos_m WHERE yos_m.{$meta_id} = {$id} AND yos_m.meta_key = %s LIMIT 1)", $key );
        };
        $where = [ $wpdb->prepare( "{$id} <= %d", $anchor ) ];
        if ( isset( $filters['_exact_order_id'] ) ) { $where[] = $wpdb->prepare( "{$id} = %d", $filters['_exact_order_id'] ); }
        if ( ! empty( $filters['_no_match'] ) ) { $where[] = '1=0'; }
        foreach ( [ 'customer_id', 'email', 'currency', 'payment_method', 'shipping_country', 'shipping_state', 'shipping_city', 'shipping_postcode' ] as $key ) {
            if ( isset( $filters[$key] ) ) { $where[] = self::compare( $scalar( $key ), $filters[$key] ); }
        }
        if ( isset( $filters['phone'] ) ) {
            $phone = self::phone_sql( $scalar( 'billing_phone' ) ); $raw = self::digits( $filters['phone'] );
            $phone_where = [ self::compare( $phone, $raw ) ];
            if ( isset( $filters['phone_country'] ) ) {
                $variants = self::phone_variants( $filters['phone'], $filters['phone_country'] );
                if ( $variants ) { $phone_where[] = '(' . self::compare( $scalar( 'billing_country' ), $filters['phone_country'] ) . ' AND ('
                    . implode( ' OR ', array_map( static function( $value ) use ( $phone ) { return self::compare( $phone, $value ); }, $variants ) ) . '))'; }
            }
            $where[] = '(' . implode( ' OR ', $phone_where ) . ')';
        }
        if ( isset( $filters['customer_name'] ) ) {
            $source = $filters['name_source'] ?? 'billing';
            $expr = "TRIM(CONCAT(COALESCE(" . $scalar( $source . '_first_name' ) . ", ''), ' ', COALESCE(" . $scalar( $source . '_last_name' ) . ", '')))";
            $where[] = self::compare( $expr, $filters['customer_name'], $filters['name_match'] ?? 'exact' );
        }
        if ( isset( $filters['shipping_address'] ) ) {
            $expr = "TRIM(CONCAT(COALESCE(" . $scalar( 'shipping_address_1' ) . ", ''), ' ', COALESCE(" . $scalar( 'shipping_address_2' ) . ", '')))";
            $where[] = self::compare( $expr, $filters['shipping_address'], 'contains' );
        }
        foreach ( [ 'total_min' => '>=', 'total_max' => '<=' ] as $key => $operator ) {
            if ( isset( $filters[$key] ) ) { $where[] = $wpdb->prepare( 'CAST(' . $scalar( 'total' ) . " AS DECIMAL(26,6)) {$operator} CAST(%s AS DECIMAL(26,6))", $filters[$key] ); }
        }
        if ( isset( $filters['order_number'] ) ) {
            $number = $filters['order_number'];
            if ( ! empty( $filters['_custom_number'] ) ) {
                $stored = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$meta} yos_num WHERE yos_num.{$meta_id} = {$id} AND yos_num.meta_key = '_order_number' AND yos_num.meta_value = %s)", $number );
                if ( preg_match( '/\A[1-9][0-9]*\z/D', $number ) && strlen( $number ) < 19 ) {
                    $stored = '(' . $stored . ' OR (' . $wpdb->prepare( "{$id} = %d", (int) $number )
                        . " AND NOT EXISTS (SELECT 1 FROM {$meta} yos_num WHERE yos_num.{$meta_id} = {$id} AND yos_num.meta_key = '_order_number' AND yos_num.meta_value <> '')))";
                }
                $where[] = $stored;
            } else { $where[] = $wpdb->prepare( "{$id} = %d", (int) $number ); }
        }
        $item = self::item_predicate( $filters, $id );
        if ( $item ) { $where[] = $item; }
        if ( isset( $filters['note_text'] ) ) {
            $note = [ "yos_n.comment_post_ID = {$id}", "yos_n.comment_type = 'order_note'", "yos_n.comment_approved = '1'",
                self::compare( 'yos_n.comment_content', $filters['note_text'], 'contains' ) ];
            if ( 'all' !== ( $filters['note_type'] ?? 'all' ) ) {
                $exists = "EXISTS (SELECT 1 FROM {$wpdb->commentmeta} yos_nm WHERE yos_nm.comment_id = yos_n.comment_ID AND yos_nm.meta_key = 'is_customer_note' AND yos_nm.meta_value = '1')";
                $note[] = 'customer' === $filters['note_type'] ? $exists : 'NOT ' . $exists;
            }
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->comments} yos_n WHERE " . implode( ' AND ', $note ) . ')';
        }
        if ( isset( $filters['tracking_number'] ) || isset( $filters['tracking_carrier'] ) ) {
            $where[] = self::tracking_predicate( $filters, $id, $meta, $meta_id );
        }
        return implode( ' AND ', $where );
    }

    private static function compare( $expression, $value, $match = 'exact' ) {
        global $wpdb;
        return 'contains' === $match ? $wpdb->prepare( "{$expression} LIKE %s", '%' . $wpdb->esc_like( $value ) . '%' )
            : $wpdb->prepare( "{$expression} = %s", $value );
    }

    private static function phone_sql( $expression ) {
        foreach ( [ ' ', '+', '-', '(', ')', '.', '/', "\t", "\n", "\r" ] as $character ) {
            global $wpdb;
            $expression = $wpdb->prepare( "REPLACE({$expression}, %s, '')", $character );
        }
        return $expression;
    }

    /** Only explicit country rules; no suffix or country inference. Other countries retain exact normalized matching. */
    private static function phone_variants( $value, $country ) {
        $code = self::digits( (string) WC()->countries->get_country_calling_code( $country ) );
        if ( ! $code ) { return []; }
        $digits = self::digits( $value ); $international = 0 === strpos( $value, '+' ) || 0 === strpos( $digits, '00' );
        if ( 0 === strpos( $digits, '00' ) ) { $digits = substr( $digits, 2 ); }
        $trunk = in_array( $country, [ 'VN', 'GB', 'AU', 'NZ', 'DE', 'FR', 'NL', 'BE', 'CH', 'AT', 'JP', 'KR', 'IN', 'ZA', 'ID', 'MY', 'TH' ], true );
        $nanp = in_array( $country, [ 'US', 'CA' ], true );
        if ( ! $trunk && ! $nanp ) { return []; }
        if ( $international || 0 === strpos( $digits, $code ) ) {
            if ( 0 !== strpos( $digits, $code ) ) { return []; }
            $national = substr( $digits, strlen( $code ) );
        } else { $national = $trunk && 0 === strpos( $digits, '0' ) ? substr( $digits, 1 ) : $digits; }
        if ( strlen( $national ) < 6 || ( $nanp && strlen( $national ) !== 10 ) ) { return []; }
        return array_values( array_unique( [ $code . $national, '00' . $code . $national, ( $trunk ? '0' : '' ) . $national ] ) );
    }

    private static function item_predicate( array $filters, $id ) {
        global $wpdb;
        if ( ! array_intersect( array_keys( $filters ), [ 'product_id', 'variation_id', 'sku', 'product_name', 'item_name' ] ) ) { return ''; }
        $items = $wpdb->prefix . 'woocommerce_order_items'; $meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $product = "(SELECT yos_p.meta_value FROM {$meta} yos_p WHERE yos_p.order_item_id = yos_i.order_item_id AND yos_p.meta_key = '_product_id' LIMIT 1)";
        $variation = "(SELECT yos_v.meta_value FROM {$meta} yos_v WHERE yos_v.order_item_id = yos_i.order_item_id AND yos_v.meta_key = '_variation_id' LIMIT 1)";
        $effective = "CASE WHEN CAST(COALESCE({$variation}, '0') AS UNSIGNED) > 0 THEN {$variation} ELSE {$product} END";
        $where = [ "yos_i.order_id = {$id}", "yos_i.order_item_type = 'line_item'" ];
        foreach ( [ 'product_id' => $product, 'variation_id' => $variation ] as $key => $expression ) {
            if ( isset( $filters[$key] ) ) { $where[] = self::compare( $expression, (string) $filters[$key] ); }
        }
        if ( isset( $filters['item_name'] ) ) { $where[] = self::compare( 'yos_i.order_item_name', $filters['item_name'], $filters['product_match'] ?? 'exact' ); }
        if ( isset( $filters['product_name'] ) ) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->posts} yos_product WHERE yos_product.ID = ({$effective}) AND yos_product.post_type IN ('product','product_variation') AND "
                . self::compare( 'yos_product.post_title', $filters['product_name'], $filters['product_match'] ?? 'exact' ) . ')';
        }
        if ( isset( $filters['sku'] ) ) {
            $own = "(SELECT yos_s.meta_value FROM {$wpdb->postmeta} yos_s WHERE yos_s.post_id = ({$effective}) AND yos_s.meta_key = '_sku' LIMIT 1)";
            $parent = "(SELECT yos_ps.meta_value FROM {$wpdb->postmeta} yos_ps WHERE yos_ps.post_id = ({$product}) AND yos_ps.meta_key = '_sku' LIMIT 1)";
            $where[] = self::compare( "COALESCE(NULLIF({$own}, ''), {$parent})", $filters['sku'] );
        }
        return "EXISTS (SELECT 1 FROM {$items} yos_i WHERE " . implode( ' AND ', $where ) . ')';
    }

    private static function tracking_predicate( array $filters, $id, $meta, $meta_id ) {
        global $wpdb;
        $alternatives = [];
        foreach ( self::TRACKING_KEYS as $index => $key ) {
            $parts = [ $wpdb->prepare( 'yos_t.meta_key = %s', $key ), "yos_t.meta_value <> ''" ];
            if ( isset( $filters['tracking_number'] ) ) { $parts[] = self::compare( 'yos_t.meta_value', $filters['tracking_number'] ); }
            if ( isset( $filters['tracking_carrier'] ) ) {
                $parts[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$meta} yos_tc WHERE yos_tc.{$meta_id} = {$id} AND yos_tc.meta_key = %s AND yos_tc.meta_value = %s)", self::TRACKING_PROVIDERS[$index], $filters['tracking_carrier'] );
            }
            $alternatives[] = '(' . implode( ' AND ', $parts ) . ')';
        }
        // Bind fields to the same serialized shipment record, rather than crossing numbers and carriers between records.
        $number = isset( $filters['tracking_number'] ) ? serialize( 'tracking_number' ) . serialize( $filters['tracking_number'] ) : null;
        $carriers = isset( $filters['tracking_carrier'] ) ? [ serialize( 'tracking_provider' ) . serialize( $filters['tracking_carrier'] ),
            serialize( 'custom_tracking_provider' ) . serialize( $filters['tracking_carrier'] ) ] : [];
        $structured_parts = [];
        foreach ( $carriers as $carrier ) {
            if ( $number ) {
                $number_pattern = preg_quote( $number, '/' ); $carrier_pattern = preg_quote( $carrier, '/' );
                $pattern = '[{][^{}]*' . $number_pattern . '[^{}]*' . $carrier_pattern . '[^{}]*[}]|[{][^{}]*' . $carrier_pattern . '[^{}]*' . $number_pattern . '[^{}]*[}]';
                $structured_parts[] = $wpdb->prepare( 'yos_t.meta_value REGEXP %s', $pattern );
            } else { $structured_parts[] = self::compare( 'yos_t.meta_value', $carrier, 'contains' ); }
        }
        $structured = $carriers ? '(' . implode( ' OR ', $structured_parts ) . ')' : self::compare( 'yos_t.meta_value', $number, 'contains' );
        $alternatives[] = "(yos_t.meta_key = '_wc_shipment_tracking_items' AND {$structured})";
        return "EXISTS (SELECT 1 FROM {$meta} yos_t WHERE yos_t.{$meta_id} = {$id} AND (" . implode( ' OR ', $alternatives ) . '))';
    }
}
