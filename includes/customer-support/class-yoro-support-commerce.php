<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class YoRo_Support_Commerce {
    public static function profile() {
        if ( ! class_exists( 'WC_Cart' ) || ! class_exists( 'WC_Cart_Totals' ) || ! defined( 'WC_PLUGIN_FILE' ) ) {
            return new WP_Error( 'support_commerce_unavailable', 'WooCommerce is unavailable.' );
        }
        global $wp_filter;
        $native = realpath( dirname( WC_PLUGIN_FILE ) ) . DIRECTORY_SEPARATOR;
        foreach ( $wp_filter as $name => $hook ) {
            if ( 'all' !== $name && 0 !== strpos( $name, 'woocommerce_' ) ) { continue; }
            // Woo registers this lazy background installer, which no quote path invokes.
            if ( 'woocommerce_wccom_install_products' === $name ) { continue; }
            foreach ( $hook->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $entry ) {
                    try {
                        $callback = $entry['function'];
                        if ( self::native_wordpress_callback( $name, $callback, $priority, $entry['accepted_args'] ?? null ) ) { continue; }
                        if ( self::report_mutation_callback( $name, $priority, $entry ) ) { continue; }
                        if ( self::subscription_mutation_callback( $name, $priority, $entry ) ) { continue; }
                        if ( is_array( $callback ) ) {
                            if ( is_string( $callback[0] ) && ! class_exists( $callback[0], false ) ) { return new WP_Error( 'support_checkout_unsupported', 'A checkout callback cannot be safely inspected.' ); }
                            $reflection = new ReflectionMethod( $callback[0], $callback[1] );
                        }
                        elseif ( is_string( $callback ) && strpos( $callback, '::' ) !== false ) { $reflection = new ReflectionMethod( ...explode( '::', $callback, 2 ) ); }
                        elseif ( is_object( $callback ) && ! $callback instanceof Closure ) { $reflection = new ReflectionMethod( $callback, '__invoke' ); }
                        else { $reflection = new ReflectionFunction( $callback ); }
                        $file = $reflection->getFileName();
                        if ( ! $file || 0 !== strpos( realpath( $file ), $native ) ) {
                            return new WP_Error( 'support_checkout_unsupported', 'This checkout integration is not supported for a read-only estimate. Please use checkout.' );
                        }
                    } catch ( Throwable $error ) { return new WP_Error( 'support_checkout_unsupported', 'Checkout could not be safely inspected.' ); }
                }
            }
        }
        return true;
    }

    /** Core subscription invalidation runs only on persistence, never quote hooks. */
    private static function subscription_mutation_callback( $hook, $priority, array $entry ) {
        $hooks = [ 'woocommerce_before_subscription_object_save', 'woocommerce_after_subscription_object_save',
            'woocommerce_before_delete_subscription', 'woocommerce_trash_subscription', 'woocommerce_untrash_subscription' ];
        if ( ! in_array( $hook, $hooks, true ) || 10 !== $priority || 0 !== ( $entry['accepted_args'] ?? null )
            || ! ( $entry['function'] instanceof Closure ) || ! class_exists( 'YoRo_Subscriptions_Read', false ) ) { return false; }
        $reflection = new ReflectionFunction( $entry['function'] );
        $scope = $reflection->getClosureScopeClass();
        $file = realpath( __DIR__ . '/../commerce/class-yoro-subscriptions-read.php' );
        return $file && $reflection->isStatic() && null === $reflection->getClosureThis()
            && [] === $reflection->getStaticVariables() && $scope && 'YoRo_Subscriptions_Read' === $scope->getName()
            && $file === realpath( $scope->getFileName() ) && $file === realpath( $reflection->getFileName() );
    }

    /** Full Core report invalidation observes only persistence hooks, never read-only quote hooks. */
    private static function report_mutation_callback( $hook, $priority, array $entry ) {
        $hooks = [ 'woocommerce_before_order_object_save' => 1, 'woocommerce_before_order_refund_object_save' => 1,
            'woocommerce_after_order_object_save' => 1, 'woocommerce_after_order_refund_object_save' => 1,
            'woocommerce_before_delete_order' => 0, 'woocommerce_delete_order' => 0, 'woocommerce_trash_order' => 0, 'woocommerce_untrash_order' => 0,
            'woocommerce_before_delete_order_item' => 0, 'woocommerce_new_order_item' => 0, 'woocommerce_update_order_item' => 0,
            'woocommerce_before_product_object_save' => 0, 'woocommerce_after_product_object_save' => 0 ];
        if ( ! isset( $hooks[ $hook ] ) || 10 !== $priority || ( $entry['accepted_args'] ?? null ) !== $hooks[ $hook ]
            || ! ( $entry['function'] instanceof Closure ) || ! class_exists( 'YoRo_Complete_Store_Report', false ) ) { return false; }
        $reflection = new ReflectionFunction( $entry['function'] );
        $scope = $reflection->getClosureScopeClass();
        $file = realpath( __DIR__ . '/../commerce/class-yoro-complete-store-report.php' );
        return $file && $reflection->isStatic() && $scope && 'YoRo_Complete_Store_Report' === $scope->getName()
            && $file === realpath( $scope->getFileName() ) && $file === realpath( $reflection->getFileName() );
    }

    /** Exact helpers registered by WooCommerce itself; arbitrary WP callbacks stay unsupported. */
    private static function native_wordpress_callback( $hook, $callback, $priority, $accepted_args ) {
        if ( in_array( $hook, [ 'woocommerce_rest_shop_order_refund_trashable', 'woocommerce_rest_shop_order_refund_object_trashable' ], true )
            && '__return_false' === $callback ) {
            $reflection = new ReflectionFunction( $callback );
            return 10 === $priority && 1 === $accepted_args
                && realpath( $reflection->getFileName() ) === realpath( ABSPATH . WPINC . '/functions.php' );
        }
        if ( 'woocommerce_stock_amount' === $hook && 'intval' === $callback ) { return true; }
        $known = [ 'woocommerce_shipping_rate_label' => [ 'sanitize_text_field' ], 'woocommerce_attribute_label' => [ 'wp_kses_post' ],
            'woocommerce_short_description' => [ 'do_blocks', 'wptexturize', 'convert_smilies', 'convert_chars', 'wpautop', 'shortcode_unautop', 'prepend_attachment', 'do_shortcode' ],
            'woocommerce_admin_settings_sanitize_option_woocommerce_notify_low_stock_amount' => [ 'absint' ],
            'woocommerce_admin_settings_sanitize_option_woocommerce_notify_no_stock_amount' => [ 'absint' ] ];
        $embed = 'woocommerce_short_description' === $hook && is_array( $callback ) && $callback[0] instanceof WP_Embed && 'run_shortcode' === $callback[1];
        if ( ! $embed && ( ! is_string( $callback ) || ! in_array( $callback, $known[ $hook ] ?? [], true ) ) ) { return false; }
        $reflection = $embed ? new ReflectionMethod( $callback[0], $callback[1] ) : new ReflectionFunction( $callback );
        return 0 === strpos( realpath( $reflection->getFileName() ), realpath( ABSPATH . WPINC ) . DIRECTORY_SEPARATOR );
    }

    public static function quote( array $params, array $authority ) {
        $profile = self::profile();
        if ( is_wp_error( $profile ) ) { return $profile; }
        if ( array_diff( array_keys( $params ), [ 'items', 'destination', 'coupon', 'shipping_rates', 'quoted_at', 'kind' ] )
            || ! is_array( $params['items'] ?? null ) || count( $params['items'] ) < 1 || count( $params['items'] ) > 10
            || ! is_array( $params['destination'] ?? null ) ) { return self::invalid(); }
        $destination = $params['destination'];
        if ( array_diff( array_keys( $destination ), [ 'country', 'state', 'postcode', 'city' ] ) ) { return self::invalid(); }
        foreach ( [ 'country', 'state', 'postcode', 'city' ] as $field ) {
            if ( ! is_string( $destination[ $field ] ?? '' ) || mb_strlen( $destination[ $field ] ?? '' ) > 100 || preg_match( '/[<>\x00-\x1f]/u', $destination[ $field ] ?? '' ) ) { return self::invalid(); }
        }
        $country = strtoupper( $destination['country'] ?? '' );
        if ( ! isset( WC()->countries->get_allowed_countries()[ $country ] ) || empty( $destination['postcode'] )
            || ! WC_Validation::is_postcode( $destination['postcode'], $country ) ) {
            return new WP_Error( 'support_destination_required', 'Choose a supported country and enter a valid postcode.' );
        }
        $states = WC()->countries->get_states( $country );
        if ( is_array( $states ) && $states && ! isset( $states[ $destination['state'] ?? '' ] ) ) {
            return new WP_Error( 'support_destination_required', 'Choose a valid state or province.' );
        }
        require_once __DIR__ . '/class-yoro-support-quote-cart.php';
        $cart = new YoRo_Support_Quote_Cart();
        $customer = new WC_Customer();
        $email = is_string( $authority['email'] ?? null ) ? $authority['email'] : '';
        $customer->set_billing_email( $email );
        foreach ( [ 'country', 'state', 'postcode', 'city' ] as $field ) {
            $customer->{ 'set_shipping_' . $field }( $destination[ $field ] ?? '' );
            $customer->{ 'set_billing_' . $field }( $destination[ $field ] ?? '' );
        }
        $customer->set_calculated_shipping( true );
        $seen = []; $physical = false;
        foreach ( $params['items'] as $index => $item ) {
            if ( ! is_array( $item ) || array_diff( array_keys( $item ), [ 'product_id', 'quantity' ] )
                || ! is_int( $item['product_id'] ?? null ) || ! is_int( $item['quantity'] ?? null ) || $item['quantity'] < 1 || $item['quantity'] > 100 ) { return self::invalid(); }
            if ( isset( $seen[ $item['product_id'] ] ) ) { return self::invalid(); }
            $seen[ $item['product_id'] ] = true;
            $product = wc_get_product( $item['product_id'] );
            if ( ! $product || ! in_array( $product->get_type(), [ 'simple', 'variation' ], true ) || ! $product->is_purchasable() || ! $product->is_in_stock()
                || ! self::public_product( $product ) || ( $product->is_sold_individually() && $item['quantity'] > 1 ) || ! $product->has_enough_stock( $item['quantity'] ) ) { return self::invalid(); }
            $physical = $physical || $product->needs_shipping();
            $cart->cart_contents[ (string) $index ] = [ 'key' => (string) $index, 'product_id' => $product->get_parent_id() ?: $product->get_id(),
                'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0, 'variation' => $product->is_type( 'variation' ) ? $product->get_variation_attributes() : [],
                'quantity' => $item['quantity'], 'data' => $product ];
        }
        if ( $physical && ! isset( WC()->countries->get_shipping_countries()[ $country ] ) ) { return new WP_Error( 'support_no_shipping_rate', 'No shipping rate is available for this destination.' ); }
        $original = [ WC()->cart, WC()->customer, WC()->session ];
        global $current_user;
        $original_user = $current_user;
        $deny_http = static function () { return new WP_Error( 'support_no_outbound', 'External checkout calls are unavailable.' ); };
        $memory = new class extends WC_Session {};
        $rates = $params['shipping_rates'] ?? [];
        if ( ! is_array( $rates ) || count( $rates ) > 10 || count( array_filter( $rates, 'is_string' ) ) !== count( $rates ) ) { return self::invalid(); }
        $memory->set( 'chosen_shipping_methods', $rates );
        try {
            WC()->cart = $cart; WC()->customer = $customer; WC()->session = $memory;
            $current_user = new WP_User( 0 );
            add_filter( 'pre_http_request', $deny_http, PHP_INT_MIN );
            if ( is_wp_error( $cart->check_cart_item_stock() ) ) { return self::invalid(); }
            $cart->calculate_totals();
            $coupon_state = [ 'state' => 'none', 'message' => '' ];
            if ( ! empty( $params['coupon'] ) ) {
                if ( ! is_string( $params['coupon'] ) || mb_strlen( $params['coupon'] ) > 100 ) { return self::invalid(); }
                $coupon = new WC_Coupon( $params['coupon'] );
                if ( ! wc_coupons_enabled() || ! wc_is_same_coupon( $coupon->get_code(), wc_format_coupon_code( $params['coupon'] ) ) ) {
                    $coupon_state = [ 'state' => 'invalid', 'message' => 'This coupon is unavailable.' ];
                } elseif ( ( $coupon->get_email_restrictions() || $coupon->get_usage_limit_per_user() ) && '' === $email ) {
                    $coupon_state = [ 'state' => 'verification_required', 'message' => 'Verify the purchasing email before checking customer restrictions.' ];
                } else {
                    $valid = ( new WC_Discounts( $cart ) )->is_coupon_valid( $coupon );
                    if ( ! is_wp_error( $valid ) && $coupon->get_usage_limit_per_user() ) {
                        $used = $coupon->get_data_store()->get_usage_by_email( $coupon, $email );
                        if ( $used >= $coupon->get_usage_limit_per_user() ) { $valid = new WP_Error( 'coupon_limit', 'Coupon usage limit reached.' ); }
                    }
                    if ( is_wp_error( $valid ) ) { $coupon_state = [ 'state' => 'invalid', 'message' => self::text( $valid->get_error_message(), 200 ) ]; }
                    else { $cart->set_applied_coupons( [ $coupon->get_code() ] ); $cart->calculate_totals(); $coupon_state = [ 'state' => 'valid', 'message' => 'Valid for this estimate. Not applied to your cart.' ]; }
                }
            }
            if ( $physical && ( ! $cart->has_calculated_shipping() || ! $cart->support_shipping->get_packages() ) ) { return new WP_Error( 'support_no_shipping_rate', 'No shipping rate is available for this destination.' ); }
            $shipping_rates = [];
            foreach ( $cart->support_shipping->get_packages() as $package_index => $package ) {
                if ( ! $package['rates'] ) { return new WP_Error( 'support_no_shipping_rate', 'No shipping rate is available for this destination.' ); }
                foreach ( $package['rates'] as $rate ) {
                    if ( ! in_array( $rate->get_method_id(), [ 'flat_rate', 'free_shipping', 'local_pickup' ], true ) ) { return new WP_Error( 'support_checkout_unsupported', 'This shipping method needs a supported integration.' ); }
                    $shipping_rates[] = [ 'id' => $rate->get_id(), 'label' => self::text( $rate->get_label(), 100 ), 'cost' => self::money( $rate->get_cost() ), 'package' => (int) $package_index, 'selected' => ( $memory->get( 'chosen_shipping_methods', [] )[ $package_index ] ?? '' ) === $rate->get_id() ];
                }
            }
            $methods = [];
            foreach ( $cart->needs_payment() ? WC()->payment_gateways()->payment_gateways() : [] as $gateway ) {
                if ( ! in_array( get_class( $gateway ), [ 'WC_Gateway_BACS', 'WC_Gateway_Cheque', 'WC_Gateway_COD' ], true ) ) {
                    if ( 'yes' === $gateway->enabled ) { return new WP_Error( 'support_checkout_unsupported', 'Payment availability needs checkout for this integration.' ); }
                    continue;
                }
                if ( $gateway->is_available() ) { $methods[] = self::text( $gateway->get_title(), 100 ); }
            }
            $items = [];
            foreach ( $cart->get_cart() as $item ) { $items[] = [ 'name' => self::text( $item['data']->get_name(), 160 ), 'quantity' => $item['quantity'], 'total' => self::money( $item['line_total'] ?? 0 ) ]; }
            $data = [ 'items' => $items, 'currency' => get_woocommerce_currency(), 'subtotal' => self::money( $cart->get_subtotal() ),
                'discount' => self::money( $cart->get_discount_total() ), 'shipping' => self::money( $cart->get_shipping_total() ),
                'tax' => self::money( $cart->get_total_tax() ), 'total' => self::money( $cart->get_total( 'edit' ) ),
                'quoted_at' => $params['quoted_at'] ?? gmdate( 'c' ), 'notice' => 'Estimate for this context. Checkout recalculates the final amount.',
                'coupon' => $coupon_state, 'payment_methods' => $methods, 'shipping_rates' => array_slice( $shipping_rates, 0, 20 ) ];
            return self::card( $params['kind'] ?? 'quote', $data, 'Current checkout estimate' );
        } catch ( Throwable $error ) { return new WP_Error( 'support_quote_unavailable', 'The estimate could not be safely calculated. Please use checkout.' ); }
        finally {
            remove_filter( 'pre_http_request', $deny_http, PHP_INT_MIN );
            [ WC()->cart, WC()->customer, WC()->session ] = $original;
            $current_user = $original_user;
        }
    }

    public static function orders( array $authority, $page = 1 ) {
        if ( ! self::verified( $authority ) || ! function_exists( 'wc_get_orders' ) || ! is_int( $page ) || $page < 1 || $page > 100 ) { return self::invalid(); }
        $orders = wc_get_orders( [ 'type' => 'shop_order', 'billing_email' => $authority['email'], 'limit' => 11,
            'offset' => ( $page - 1 ) * 10, 'orderby' => 'date', 'order' => 'DESC' ] );
        $rows = [];
        foreach ( array_slice( $orders, 0, 10 ) as $order ) {
            if ( ! self::owns( $order, $authority ) ) { continue; }
            $rows[] = [ 'reference' => self::order_reference( $order->get_id(), $authority ), 'number' => self::text( $order->get_order_number(), 60 ),
                'date' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
                'status' => self::text( wc_get_order_status_name( $order->get_status() ), 80 ), 'total' => self::money( $order->get_total() ), 'currency' => $order->get_currency() ];
        }
        return [ 'orders' => $rows, 'page' => $page, 'has_next' => count( $orders ) > 10 ];
    }

    public static function order( array $authority, $reference ) {
        if ( ! self::verified( $authority ) || ! is_string( $reference ) || strlen( $reference ) > 2000 ) { return self::invalid(); }
        $held = YoRo_Support_Transport::open( $reference, 'order' );
        if ( ! $held || ! is_int( $held['order_id'] ?? null ) || ! hash_equals( $authority['session_id'], $held['session_id'] )
            || ! hash_equals( YoRo_Support_Transport::binding( $authority['access_id'] ), (string) ( $held['access_binding'] ?? '' ) ) || ! hash_equals( hash( 'sha256', $authority['email'] ), $held['email_binding'] ?? '' ) ) { return self::invalid(); }
        $id = $held['order_id'];
        $order = wc_get_order( $id );
        if ( ! self::owns( $order, $authority ) ) { return self::invalid(); }
        $items = [];
        foreach ( $order->get_items() as $item ) {
            if ( count( $items ) >= 10 ) { return new WP_Error( 'support_order_too_large', 'Use the order page or contact the store for this order.' ); }
            $items[] = [ 'name' => self::text( $item->get_name(), 160 ), 'quantity' => (int) $item->get_quantity(), 'total' => self::money( $item->get_total() ) ];
        }
        $tracking = [];
        if ( class_exists( 'YoRo_Order_Management' ) ) {
            $native = YoRo_Order_Management::tracking_fingerprint( $order );
            foreach ( array_slice( $native['records'] ?? [], 0, 5 ) as $record ) {
                $number = (string) ( $record['tracking_number'] ?? '' );
                if ( ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9-]{4,62}[A-Za-z0-9]\z/D', $number ) || ! preg_match( '/[0-9]/', $number ) ) { continue; }
                $tracking[] = [ 'carrier' => self::text( $record['carrier'] ?? '', 100 ), 'number' => $number, 'url' => '', 'status' => 'Stored tracking; carrier delivery is not verified.' ];
            }
        }
        $data = [ 'reference' => self::text( $order->get_order_number(), 60 ), 'date' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
            'status' => self::text( wc_get_order_status_name( $order->get_status() ), 80 ), 'payment' => $order->is_paid() ? 'Paid' : 'Not recorded as paid',
            'items' => $items, 'total' => self::money( $order->get_total() ), 'currency' => $order->get_currency(),
            'refund' => self::money( $order->get_total_refunded() ) . ' refunded; this does not establish a return request.', 'tracking' => $tracking ];
        return self::card( 'order', $data, 'Order ' . $data['reference'] );
    }

    public static function card( $kind, array $data, $title ) {
        if ( ! in_array( $kind, [ 'quote', 'coupon', 'payment', 'order' ], true ) ) { return self::invalid(); }
        $version = hash( 'sha256', wp_json_encode( $data ) );
        return [ 'id' => $kind . ':' . $version, 'kind' => $kind, 'version' => $version, 'title' => $title,
            'text' => 'Current validated structured store data.', 'data' => $data ];
    }

    private static function verified( array $authority ) {
        return 'orders' === ( $authority['purpose'] ?? '' ) && is_string( $authority['email'] ?? null ) && is_email( $authority['email'] )
            && ! empty( $authority['access_id'] ) && strtotime( $authority['expires_at'] ?? '' ) > time();
    }
    private static function owns( $order, array $authority ) {
        return $order instanceof WC_Order && 'shop_order' === $order->get_type() && hash_equals( strtolower( $authority['email'] ), strtolower( $order->get_billing_email( 'edit' ) ) );
    }
    private static function order_reference( $id, array $authority ) {
        return YoRo_Support_Transport::seal( [ 'order_id' => (int) $id, 'session_id' => $authority['session_id'],
            'access_binding' => YoRo_Support_Transport::binding( $authority['access_id'] ), 'email_binding' => hash( 'sha256', $authority['email'] ), 'expires' => strtotime( $authority['expires_at'] ) ], 'order' );
    }
    private static function public_product( $product ) {
        $target = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
        return $target && 'publish' === $target->get_status() && $target->is_visible()
            && 'publish' === $product->get_status() && '' === (string) get_post_field( 'post_password', $target->get_id(), 'raw' )
            && '' === (string) get_post_field( 'post_password', $product->get_id(), 'raw' );
    }
    private static function money( $value ) { return wc_format_decimal( $value, wc_get_price_decimals() ); }
    private static function text( $value, $max ) { return mb_substr( wp_strip_all_tags( (string) $value ), 0, $max ); }
    private static function invalid() { return new WP_Error( 'support_context_invalid', 'The requested information is unavailable. Check your selection or verify again.' ); }
}
