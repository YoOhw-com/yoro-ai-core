<?php
/** Synthetic, transaction-rolled-back fixtures for both authoritative order stores. Never run on canary/production. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Early_Access_Order_Search' ) ) { YoRo_AI_Core::init(); }
if ( ! class_exists( 'WooCommerce' ) ) { echo "Order search unavailable without WooCommerce.\n"; return; }
if ( str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'yorohq.com' ) ) { throw new RuntimeException( 'Disposable fixtures only.' ); }
$assert = static function( $ok, $why ) { if ( ! $ok ) { throw new RuntimeException( $why ); } };
// Fresh CPT installations may not have HPOS tables. Native DDL must finish before fixture transactions begin.
$assert( wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables(),
    'The disposable installation must have native HPOS tables before testing either datastore.' );
$original_user = get_current_user_id(); $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$block_http = static function() { throw new RuntimeException( 'An order search cannot make an outbound request.' ); };
add_filter( 'pre_http_request', $block_http ); add_filter( 'pre_wp_mail', '__return_true' );
// The fixture reproduces only the independently verified adapter's public identity and stored data schema.
$number_fixture = ! class_exists( 'WC_Seq_Order_Number', false );
if ( $number_fixture ) {
    class WC_Seq_Order_Number {
        const VERSION = '1.11.2';
        public function get_order_number( $number, $order ) { return $order->get_meta( '_order_number', true, 'edit' ) ?: $number; }
        public function find_order_by_order_number( $number ) { return 0; }
    }
    function wc_sequential_order_numbers() { static $instance; if ( ! $instance ) { $instance = new WC_Seq_Order_Number(); } return $instance; }
}
$active_number = static function( $plugins ) {
    $plugins[] = 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php'; return $plugins;
};
$no_sync = static function() { return 'no'; };
add_filter( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', $no_sync );
global $wpdb;
try {
    foreach ( [ 'yes' => 'HPOS', 'no' => 'CPT' ] as $storage => $label ) {
        $mode = static function() use ( $storage ) { return $storage; };
        add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $mode );
        $wpdb->query( 'START TRANSACTION' );
        try {
            wp_set_current_user( (int) $admins[0] );
            YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data' ] ] );
            $assert( ( 'yes' === $storage ) === Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'The requested datastore must be authoritative.' );
            $tag = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
            $email = 'search-' . $tag . '@example.invalid'; $login = 'search-buyer-' . $tag;
            $account = wp_create_user( $login, wp_generate_password(), 'account-' . $tag . '@example.invalid' );
            $assert( ! is_wp_error( $account ), 'Create the disposable account.' );
            $product = new WC_Product_Simple(); $product->set_name( 'Current product ' . $tag ); $product->set_regular_price( '12.30' );
            $product->set_sku( 'SKU-' . $tag ); $product->save();
            $create = static function( $currency = 'USD', $phone = '090 123 4567', $country = 'VN' ) use ( $email, $account ) {
                $order = new WC_Order(); $order->set_status( 'pending' ); $order->set_currency( $currency ); $order->set_total( '12.30' );
                $order->set_customer_id( $account ); $order->set_billing_email( $email ); $order->set_billing_phone( $phone ); $order->set_billing_country( $country );
                $order->set_billing_first_name( 'Synthetic' ); $order->set_billing_last_name( 'Buyer' );
                $order->set_shipping_first_name( 'Destination' ); $order->set_shipping_last_name( 'Person' );
                $order->set_shipping_country( 'US' ); $order->set_shipping_state( 'CA' ); $order->set_shipping_city( 'Example City' );
                $order->set_shipping_postcode( '90210' ); $order->set_shipping_address_1( '123 Example Street' ); $order->set_shipping_address_2( 'Unit 2' );
                $order->set_payment_method( 'bacs' ); $order->set_date_created( '2026-10-10T12:00:00+00:00' ); $order->save(); return $order;
            };
            $one = $create(); $two = $create( 'USD', '+84 (90) 123-4567' ); $euro = $create( 'EUR', '+84901234567' );
            $different_country = $create( 'USD', '0901234567', 'US' ); $different_country->set_customer_id( 0 ); $different_country->set_billing_email( 'other-' . $email ); $different_country->save();
            foreach ( [ $one, $two ] as $order ) {
                for ( $i = 0; $i < 2; $i++ ) {
                    $line = new WC_Order_Item_Product(); $line->set_product( $product ); $line->set_name( 'Historical 100%_ ' . $tag );
                    $line->set_quantity( 1 ); $line->set_total( '1.00' ); $order->add_item( $line );
                }
                $order->save(); $order->add_order_note( 'internal-search-' . $tag, false ); $order->add_order_note( 'internal-search-' . $tag, false );
                $order->add_order_note( 'customer-search-' . $tag, true );
                $order->update_meta_data( '_tracking_number', 'SCALAR-' . $tag );
                $order->update_meta_data( '_tracking_provider', 'Scalar Carrier' );
                $order->update_meta_data( '_wc_shipment_tracking_items', [ [ 'tracking_number' => 'TRACK-A-' . $tag, 'tracking_provider' => 'DHL' ],
                    [ 'tracking_provider' => 'UPS', 'tracking_number' => 'TRACK-B-' . $tag ],
                    [ 'tracking_number' => 'TRACK-C-' . $tag, 'tracking_provider' => 'custom', 'custom_tracking_provider' => 'Fixture Freight' ] ] ); $order->save();
            }
            $before = wp_json_encode( wc_get_order( $one->get_id() )->get_data() );
            // These newer orders put every matching fixture beyond the old 100-order candidate scan.
            for ( $i = 0; $i < 105; $i++ ) {
                $distractor = new WC_Order(); $distractor->set_status( 'completed' ); $distractor->set_currency( 'USD' );
                $distractor->set_total( '1' ); $distractor->save();
            }
            $read = static function( $filters, $page = 1, $params = [] ) {
                return YoRo_Early_Access_Order_Read::execute( [ 'action' => 'orders.search', 'params' => array_merge( [ 'limit' => 20 ], $params ),
                    'extensions' => [ 'yoro.mcp_early_access_product_read' => [ 'order_page' => $page, 'order_search' => [ 'version' => 1, 'filters' => $filters, 'page' => $page ] ] ] ] );
            };
            $hook_counts = static function() {
                $counts = [];
                foreach ( [ 'woocommerce_orders_table_query_clauses', 'posts_clauses' ] as $hook ) {
                    $counts[$hook] = isset( $GLOBALS['wp_filter'][$hook] ) ? array_sum( array_map( 'count', $GLOBALS['wp_filter'][$hook]->callbacks ) ) : 0;
                }
                return $counts;
            };
            $original_hook_counts = $hook_counts();
            $finder = static function( $params, $filters = [], $page = 1 ) {
                return YoRo_Early_Access_Customer_Service_Read::execute( [ 'action' => 'orders.find_by_customer', 'params' => array_merge( [ 'limit' => 20 ], $params ),
                    'extensions' => [ 'yoro.mcp_early_access_product_read' => [ 'order_search' => [ 'version' => 1, 'filters' => $filters, 'page' => $page ] ] ] ] );
            };
            $check = static function( $result, $expected, $why ) use ( $assert, $label ) {
                $assert( ! is_wp_error( $result ), $label . ': ' . $why . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) );
                $assert( $expected === $result['extensions']['yoro.order_search']['total_matches'], $label . ': ' . $why . ' count' );
                $ids = array_column( $result['data']['orders'], 'id' ); $assert( count( $ids ) === count( array_unique( $ids ) ), 'Repeated lines and notes cannot duplicate orders.' );
                return $result;
            };
            $exact_id = (int) $one->get_id();
            foreach ( [ [], [ 'email' => $email, 'currency' => 'USD' ] ] as $selectors ) {
                $exact = $check( $read( $selectors, 1, [ 'order_id' => $exact_id, 'limit' => 1 ] ), 1, 'Canonical ID remains exact before pagination' );
                $assert( [ $exact_id ] === array_column( $exact['data']['orders'], 'id' ) && ! $exact['data']['page_info']['has_next_page'], 'ID lookup cannot return another order or offer another page.' );
            }
            $check( $read( [ 'currency' => 'EUR' ], 1, [ 'order_id' => $exact_id, 'limit' => 1 ] ), 0, 'An ID lookup still requires every negotiated predicate' );
            $check( $read( [], 1, [ 'order_id' => PHP_INT_MAX, 'limit' => 1 ] ), 0, 'Unknown ID never becomes an unfiltered query' );
            $check( $read( [ 'email' => strtoupper( $email ) ] ), 3, 'Exact billing email reaches old orders' );
            $check( $read( [ 'username' => $login ] ), 3, 'Username resolves to the linked account' );
            $check( $read( [ 'username' => 'missing-' . $tag ] ), 0, 'Unknown username cannot become all customers' );
            $check( $read( [ 'customer_id' => (int) $account ] ), 3, 'Account ID filter' );
            $check( $read( [ 'reference_order_id' => (int) $one->get_id() ] ), 3, 'Reference order uses the linked account' );
            $check( $read( [ 'phone' => '+84 901-234-567', 'currency' => 'USD' ] ), 1, 'Raw normalized phone never guesses a country or suffix' );
            $check( $read( [ 'phone' => '+84901234567', 'phone_country' => 'VN', 'currency' => 'USD' ] ), 2, 'Country-aware phone requires a compatible stored country' );
            $different_country->set_billing_country( 'VN' ); $different_country->set_billing_phone( '0849123456' ); $different_country->save();
            $prefix_match = $check( $read( [ 'phone' => '849123456', 'phone_country' => 'VN' ] ), 1, 'A national number beginning with the calling code remains national' );
            $assert( [ $different_country->get_id() ] === array_column( $prefix_match['data']['orders'], 'id' ), 'Calling-code coincidence cannot select a different local number.' );
            $different_country->set_billing_country( 'US' ); $different_country->set_billing_phone( '0901234567' ); $different_country->save();
            $check( $read( [ 'phone' => '901234567', 'currency' => 'USD' ] ), 0, 'Phone suffixes do not match' );
            $assert( is_wp_error( $read( [ 'email' => $email, 'customer_name' => 'Buyer' ] ) ), 'Ambiguous customer selectors fail closed.' );
            $check( $read( [ 'customer_name' => 'synthetic buyer', 'currency' => 'EUR' ] ), 1, 'Exact full billing name' );
            $check( $read( [ 'customer_name' => 'Buyer', 'name_match' => 'contains', 'currency' => 'EUR' ] ), 1, 'Contains billing name' );
            $check( $read( [ 'customer_name' => 'Destination Person', 'name_source' => 'shipping', 'currency' => 'EUR' ] ), 1, 'Shipping name stays distinct from billing' );
            $check( $read( [ 'customer_name' => 'Synthetic Buyer', 'name_source' => 'shipping' ] ), 0, 'Shipping name does not fall back to billing' );
            $filters = [ 'email' => $email, 'product_id' => (int) $product->get_id(), 'sku' => $product->get_sku(), 'product_name' => $product->get_name(),
                'item_name' => '100%_', 'product_match' => 'contains', 'currency' => 'USD', 'total_min' => '12.30', 'total_max' => '12.30',
                'payment_method' => 'bacs', 'shipping_country' => 'US', 'shipping_state' => 'CA', 'shipping_city' => 'Example City',
                'shipping_postcode' => '90210', 'shipping_address' => 'Example Street', 'note_text' => 'internal-search-' . $tag, 'note_type' => 'internal',
                'tracking_number' => 'TRACK-A-' . $tag, 'tracking_carrier' => 'DHL' ];
            $combined = $check( $read( $filters, 1, [ 'status' => 'pending', 'date_from' => '2026-10-10', 'date_to' => '2026-10-10' ] ), 2, 'All filter clusters run before pagination' );
            $check( $read( $filters, 1, [ 'status' => 'completed' ] ), 0, 'Status is ANDed with extended filters' );
            $assert( $before === wp_json_encode( wc_get_order( $one->get_id() )->get_data() ), 'The search must not change business order data.' );
            $check( $read( [ 'email' => $email, 'total_min' => '12.300001', 'currency' => 'USD' ] ), 0, 'Money comparisons preserve decimal precision' );
            $check( $read( [ 'tracking_number' => 'SCALAR-' . $tag ] ), 2, 'Known scalar tracking adapter' );
            $check( $read( [ 'tracking_number' => 'SCALAR-' . $tag, 'tracking_carrier' => 'Scalar Carrier' ] ), 2, 'Scalar number and carrier use their known adapter pair' );
            $check( $read( [ 'tracking_number' => 'SCALAR-' . $tag, 'tracking_carrier' => 'UPS' ] ), 0, 'Scalar number cannot borrow a carrier from another adapter' );
            $check( $read( [ 'tracking_carrier' => 'UPS' ] ), 2, 'Known structured carrier adapter' );
            $check( $read( [ 'tracking_number' => 'TRACK-C-' . $tag, 'tracking_carrier' => 'Fixture Freight' ] ), 2, 'Structured custom carrier retains its own shipment number' );
            $check( $read( [ 'tracking_number' => 'TRACK-A-' . $tag, 'tracking_carrier' => 'UPS' ] ), 0, 'Tracking fields cannot cross shipment records' );
            $check( $read( [ 'note_text' => 'customer-search-' . $tag, 'note_type' => 'customer' ] ), 2, 'Customer note filter' );
            $check( $read( [ 'note_text' => 'customer-search-' . $tag, 'note_type' => 'internal' ] ), 0, 'Internal notes exclude customer notes' );
            $check( $read( [ 'product_name' => "' OR 1=1 --" ] ), 0, 'Prepared values do not become SQL syntax' );
            $check( $read( [ 'item_name' => '100%_', 'product_match' => 'contains' ] ), 2, 'LIKE treats percent and underscore literally' );
            $first = $check( $read( [ 'email' => $email, 'currency' => 'USD' ], 1, [ 'limit' => 1 ] ), 2, 'First bounded page' );
            $anchor = $first['extensions']['yoro.order_search']['anchor_id'];
            $assert( $first['data']['page_info']['has_next_page'] && 1 === $first['data']['count'], 'First page has one order and a next cursor.' );
            $inserted = $create();
            $second = $check( $read( [ 'email' => $email, 'currency' => 'USD', 'anchor_id' => $anchor ], 2, [ 'limit' => 1 ] ), 2, 'Anchor excludes a concurrent newer order' );
            $assert( ! $second['data']['page_info']['has_next_page'] && $first['data']['orders'][0]['id'] !== $second['data']['orders'][0]['id'], 'Stable pages do not repeat orders.' );
            $check( $read( [ 'email' => $email, 'currency' => 'USD', 'anchor_id' => $anchor ], 3, [ 'limit' => 1 ] ), 2, 'Past the final page retains the exact total' );
            $standalone = $check( $read( [ 'email' => $email, 'currency' => 'USD' ], 2, [ 'limit' => 1 ] ), 3, 'Standalone page numbers take a fresh snapshot' );
            $assert( 1 === $standalone['data']['count'], 'A standalone second page is supported.' );
            $check( $finder( [ 'email' => $email ], [ 'currency' => 'EUR', 'status' => 'pending', 'date_from' => '2026-10-10', 'date_to' => '2026-10-10' ] ), 1, 'Finder shares database filters and date/status bounds' );
            $phone_order = new WC_Order(); $phone_order->set_status( 'pending' ); $phone_order->set_billing_phone( '+1 202-555-0199' ); $phone_order->save();
            $period_phone = [ 'limit' => 20, 'phone' => '+1 (202) 555.0199' ];
            $phone_facts = [ 'order_search' => [ 'version' => 1, 'filters' => [], 'page' => 1 ] ];
            $assert( YoRo_Early_Access_Customer_Service_Read::valid_params( 'orders.find_by_customer', $period_phone, $phone_facts ), 'Negotiated finder validation accepts periods admitted by the API.' );
            $period_result = $check( $finder( $period_phone ), 1, 'Negotiated finder execution normalizes a period-formatted phone' );
            $assert( $phone_order->get_id() === $period_result['data']['orders'][0]['id'], 'The period-formatted finder returns the exact normalized phone match.' );
            foreach ( [ 'orders.find_by_customer', 'customers.lookup' ] as $legacy_action ) {
                $assert( ! YoRo_Early_Access_Customer_Service_Read::valid_params( $legacy_action, $period_phone ), 'Legacy customer reads retain their previous phone validation.' );
            }
            $assert( is_wp_error( YoRo_Early_Access_Customer_Service_Read::execute( [ 'action' => 'orders.find_by_customer', 'params' => $period_phone ] ) ), 'A legacy finder does not silently opt into the negotiated phone format.' );
            $reference = $check( $finder( [ 'order_id' => (int) $one->get_id() ], [ 'currency' => 'EUR' ] ), 1, 'Finder reference keeps customer correlation' );
            $assert( in_array( 'reference_order_id', $reference['extensions']['yoro.order_search']['matches'][0]['reasons'], true ), 'Reason keys identify the filter without echoing contact data.' );
            $assert( is_wp_error( $finder( [ 'email' => $email ], [ 'phone' => '+84901234567' ] ) ), 'Finder extras cannot override its canonical selector.' );
            foreach ( [ [ 'limit' => 21 ], [ 'date_from' => '2026-02-30' ] ] as $bad ) { $assert( is_wp_error( $read( [], 1, $bad ) ), 'Canonical root parameters remain bounded.' ); }
            foreach ( [ [ 'unknown' => 'value' ], [ 'phone_country' => 'VN' ], [ 'name_match' => 'contains' ], [ 'total_min' => '12.3' ],
                [ 'total_min' => '2', 'total_max' => '1', 'currency' => 'USD' ], [ 'total_min' => '1e2', 'currency' => 'USD' ], [ 'anchor_id' => 0 ],
                [ 'note_type' => 'customer' ], [ 'email' => '<script>' ], [ 'product_name' => str_repeat( 'x', 257 ) ] ] as $bad ) {
                $assert( is_wp_error( $read( $bad ) ), 'Malformed or unbound filters fail closed.' );
            }
            $assert( is_wp_error( $read( [], 1001 ) ), 'The page bound remains 1000.' );
            $legacy = YoRo_Early_Access_Order_Read::execute( [ 'action' => 'orders.search', 'params' => [ 'order_id' => (int) $one->get_id(), 'limit' => 1 ],
                'extensions' => [ 'yoro.mcp_early_access_product_read' => [ 'order_page' => 1 ] ] ] );
            $assert( ! is_wp_error( $legacy ) && ! array_key_exists( 'yoro.order_search', (array) $legacy['extensions'] ), 'Old operations retain the old projection without negotiation.' );
            $manifest = YoRo_Early_Access_Order_Search::manifest();
            if ( $number_fixture ) {
                $assert( ! in_array( 'order_number', $manifest['filters'], true ) && [] === $manifest['adapters']['order_number'], 'Unavailable numbering adapters are not advertised.' );
                $assert( is_wp_error( $read( [ 'order_number' => 'CUSTOM-' . $tag ] ) ), 'Unsupported custom numbers return an explicit error.' );
                $one->update_meta_data( '_order_number', 'CUSTOM-' . $tag ); $one->save();
                add_filter( 'option_active_plugins', $active_number );
                $assert( in_array( 'order_number', YoRo_Early_Access_Order_Search::manifest()['filters'], true ), 'The verified active adapter is advertised.' );
                $number = $check( $read( [ 'order_number' => 'CUSTOM-' . $tag ] ), 1, 'Verified custom-number metadata adapter works in either store' );
                $assert( $one->get_id() === $number['data']['orders'][0]['id'], 'A custom display number resolves the canonical order ID.' );
                $check( $read( [ 'order_number' => (string) $one->get_id() ] ), 0, 'Canonical ID cannot masquerade as a different display number' );
                remove_filter( 'option_active_plugins', $active_number );
            }
            $variable = new WC_Product_Variable(); $variable->set_name( 'Variable ' . $tag ); $variable->set_sku( 'PARENT-' . $tag ); $variable->save();
            $variation = new WC_Product_Variation(); $variation->set_parent_id( $variable->get_id() ); $variation->set_regular_price( '1' ); $variation->save();
            $line = new WC_Order_Item_Product(); $line->set_product( $variation ); $one->add_item( $line ); $one->save();
            $check( $read( [ 'product_id' => (int) $variable->get_id(), 'variation_id' => (int) $variation->get_id(), 'sku' => 'PARENT-' . $tag ] ), 1, 'Variation IDs and inherited current parent SKU' );
            $variation->set_sku( 'VARIANT-' . $tag ); $variation->save();
            $check( $read( [ 'variation_id' => (int) $variation->get_id(), 'sku' => 'PARENT-' . $tag ] ), 0, 'Own variation SKU supersedes parent SKU' );
            $check( $read( [ 'product_id' => (int) $product->get_id(), 'variation_id' => (int) $variation->get_id() ] ), 0, 'Product criteria must match the same order line' );
            $product->set_name( 'Renamed ' . $tag ); $product->set_sku( 'RENAMED-' . $tag ); $product->save();
            $check( $read( [ 'product_name' => 'Current product ' . $tag ] ), 0, 'Current product name does not claim a historical catalog snapshot' );
            $check( $read( [ 'sku' => 'SKU-' . $tag ] ), 0, 'SKU search uses the current catalog' );
            $product->delete( true );
            $check( $read( [ 'item_name' => 'Historical 100%_ ' . $tag ] ), 2, 'Stored line names survive catalog rename and deletion' );
            $wire = wp_json_encode( $combined );
            foreach ( [ $email, 'Synthetic', 'Destination', 'Example Street', 'internal-search-', 'TRACK-A-', '090 123', 'PRIVATE-' ] as $private ) {
                $assert( false === strpos( $wire, $private ), 'Search output and metadata must not echo contacts, addresses, notes or filter values.' );
            }
            wp_set_current_user( 0 ); $assert( is_wp_error( $read( [] ) ), 'Unauthenticated searches fail closed.' ); wp_set_current_user( (int) $admins[0] );
            $assert( $original_hook_counts === $hook_counts(), 'Scoped query callbacks are cleaned up.' );
        } finally {
            remove_filter( 'option_active_plugins', $active_number );
            $wpdb->query( 'ROLLBACK' ); wp_cache_flush(); remove_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $mode );
        }
        echo "YoRo order search {$label} contracts passed.\n";
    }
} finally {
    wp_set_current_user( $original_user ); remove_filter( 'pre_http_request', $block_http ); remove_filter( 'pre_wp_mail', '__return_true' );
    remove_filter( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', $no_sync );
}
