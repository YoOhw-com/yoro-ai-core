<?php
/** Run only in a disposable WordPress/WooCommerce test installation. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Early_Access_Product_Read' ) ) { YoRo_AI_Core::init(); }
if ( ! class_exists( 'WooCommerce' ) ) {
    echo "YoRo product catalog remains unavailable without WooCommerce.\n";
    return;
}
if ( str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'yorohq.com' ) ) {
    throw new RuntimeException( 'This contract must never run on the live canary or production.' );
}
$assert = static function( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } };
$original_user = get_current_user_id();
$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admin ), 'The disposable fixture needs an administrator.' );
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
    wp_set_current_user( (int) $admin[0] );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data' ] ] );
    $prefix = 'YR0178.' . wp_generate_password( 8, false, false );
    $simple = new WC_Product_Simple();
    $simple->set_name( 'YR0178 Green Shirt' ); $simple->set_sku( $prefix . '.SIMPLE' ); $simple->set_status( 'publish' );
    $simple->set_regular_price( '12.30' ); $simple->set_manage_stock( true ); $simple->set_stock_quantity( 1 ); $simple->set_low_stock_amount( 3 );
    $simple->set_short_description( '<p>Plain <strong>cotton</strong> shirt.</p>' ); $simple->save();
    $variable = new WC_Product_Variable(); $variable->set_name( 'YR0178 Variable Shirt' ); $variable->set_status( 'publish' ); $variable->save();
    $variation = new WC_Product_Variation(); $variation->set_parent_id( $variable->get_id() ); $variation->set_status( 'publish' );
    $variation->set_sku( $prefix . '.VARIATION' ); $variation->set_regular_price( '14.50' ); $variation->set_manage_stock( true ); $variation->set_stock_quantity( 4 ); $variation->save();
    $draft = new WC_Product_Simple(); $draft->set_name( 'YR0178 private draft' ); $draft->set_sku( $prefix . '.DRAFT' ); $draft->set_status( 'draft' ); $draft->save();
    $before = [ wp_json_encode( wc_get_product( $simple->get_id() )->get_data() ), wp_json_encode( wc_get_product( $variation->get_id() )->get_data() ) ];
    $read = static function( $action, $params ) { return YoRo_Early_Access_Product_Read::execute( [ 'action' => $action, 'params' => $params, 'workflow_id' => 'yr0178_read_fixture', 'operation_id' => 'read_1' ] ); };
    $search = $read( 'products.search', [ 'query' => 'YR0178 Green', 'status' => 'publish', 'limit' => 10 ] );
    $assert( is_array( $search ) && in_array( $simple->get_id(), array_column( $search['data']['products'], 'id' ), true ), 'Published text search must return the matching product.' );
    $detail = $read( 'products.get_details', [ 'product_ids' => [ $simple->get_id(), $variation->get_id(), $draft->get_id() ] ] );
    $assert( is_array( $detail ) && 2 === $detail['data']['count'], 'Details must include the published variation and exclude drafts.' );
    $assert( $variable->get_id() === $detail['data']['products'][1]['extensions']['woocommerce.product']['parent_id'], 'Variation details must identify their parent.' );
    $assert( false === strpos( $detail['data']['products'][0]['extensions']['woocommerce.product']['description'], '<' ), 'Descriptions must be plain text.' );
    $assert( get_edit_post_link( $simple->get_id(), 'raw' ) === $detail['data']['products'][0]['extensions']['woocommerce.product']['admin_edit_url'], 'A permitted actor must receive the exact simple product editor link.' );
    $assert( get_edit_post_link( $variable->get_id(), 'raw' ) === $detail['data']['products'][1]['extensions']['woocommerce.product']['admin_edit_url'], 'A variation must open its parent product editor.' );
    $denied_editor = static function( $caps, $cap, $user_id, $args ) use ( $simple, $variable ) {
        return 'edit_post' === $cap && in_array( (int) ( $args[0] ?? 0 ), [ $simple->get_id(), $variable->get_id() ], true ) ? [ 'do_not_allow' ] : $caps;
    };
    add_filter( 'map_meta_cap', $denied_editor, 10, 4 );
    try {
        $denied = $read( 'products.get_details', [ 'product_ids' => [ $simple->get_id(), $variation->get_id() ] ] );
        $assert( 2 === $denied['data']['count'], 'Read access must remain available without native edit permission.' );
        foreach ( $denied['data']['products'] as $item ) {
            $assert( null === $item['extensions']['woocommerce.product']['admin_edit_url'], 'An actor without edit permission must not receive an admin editor link.' );
        }
    } finally {
        remove_filter( 'map_meta_cap', $denied_editor, 10 );
    }
    $sku = $read( 'products.search', [ 'sku' => $prefix . '.VARIATION', 'status' => 'publish', 'limit' => 1 ] );
    $assert( 1 === $sku['data']['count'] && $variation->get_id() === $sku['data']['products'][0]['id'], 'An exact variation SKU must be readable.' );
    $queue = $read( 'products.get_inventory_queue', [ 'focus' => 'low_stock', 'limit' => 50 ] );
    $assert( is_array( $queue ) && in_array( $simple->get_id(), array_column( $queue['data']['items'], 'product_id' ), true ), 'The low-stock queue must include the low-stock fixture.' );
    foreach ( $queue['data']['items'] as $item ) {
        $assert( 'low_stock' === $item['extensions']['woocommerce.product']['issue'], 'Inventory focus must filter unrelated issues.' );
    }
    $variable->set_status( 'draft' ); $variable->save();
    $hidden = $read( 'products.get_details', [ 'product_ids' => [ $variation->get_id() ] ] );
    $assert( 0 === $hidden['data']['count'], 'A variation of a draft parent must not be exposed.' );
    $variable->set_status( 'publish' ); $variable->save();
    foreach ( [ [ 'products.search', [ 'status' => 'draft', 'limit' => 10 ] ], [ 'products.search', [ 'status' => 'publish', 'limit' => 51 ] ],
        [ 'products.get_details', [ 'product_ids' => [ '1' ] ] ], [ 'products.get_details', [ 'product_ids' => [ 1, 1 ] ] ],
        [ 'products.update_stock', [ 'product_ids' => [ 1 ] ] ], [ 'products.get_inventory_queue', [ 'focus' => 'customer_orders', 'limit' => 10 ] ] ] as $bad ) {
        $assert( ! YoRo_Early_Access_Product_Read::valid_params( $bad[0], $bad[1] ), 'Invalid or write selectors must fail closed.' );
    }
    $manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( [ 'connector_key' => 'woocommerce', 'connector_connection_id' => 'yr0178_fixture', 'execution_mode' => 'remote_agent', 'protocol_version' => 1 ], 1 );
    $assert( YoRo_Early_Access_Product_Read::ACTIONS === $manifest['extensions']['yoro.early_access_product_reads']['actions'], 'The manifest must advertise the exact bounded read contract.' );
    $assert( $before[0] === wp_json_encode( wc_get_product( $simple->get_id() )->get_data() ) && $before[1] === wp_json_encode( wc_get_product( $variation->get_id() )->get_data() ), 'Reads must not mutate product or stock data.' );
    $assert( YoRo_Early_Access_Product_Read::params_hash( [ 'status' => 'publish', 'limit' => 10, 'query' => 'Áo xanh' ] ) === hash( 'sha256', '{"limit":10,"query":"Áo xanh","status":"publish"}' ), 'Params hashing must match the API canonical hash.' );
    echo "YoRo bounded product, variation, inventory and no-write contracts passed.\n";
} finally {
    $wpdb->query( 'ROLLBACK' );
    wp_set_current_user( $original_user );
    wp_cache_flush();
}
