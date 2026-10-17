<?php
/** Disposable WordPress/WooCommerce only; no remote mutation or mail. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Early_Access_Commerce_Write' ) ) { YoRo_AI_Core::init(); }
if ( ! class_exists( 'WooCommerce' ) ) { echo "Confirmed commerce writes unavailable without WooCommerce.\n"; return; }
if ( str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'yorohq.com' ) ) { throw new RuntimeException( 'Never run write fixtures on canary or production.' ); }
$assert = static function( $ok, $why ) { if ( ! $ok ) { throw new RuntimeException( $why ); } };
$original = get_current_user_id();
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
add_filter( 'pre_wp_mail', '__return_true' );
global $wpdb; $wpdb->query( 'START TRANSACTION' );
try {
    wp_set_current_user( (int) $admins[0] );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data', 'yoro_execute_actions' ] ] );
    $product = new WC_Product_Simple(); $product->set_name( 'Disposable confirmed-write fixture' ); $product->set_status( 'publish' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 5 ); $product->save(); $product = wc_get_product( $product->get_id() );
    $make = static function( $action, $params, $version ) {
        return [ 'action' => $action, 'params' => $params,
            ...('orders.add_note' === $action ? [ 'user_content_slots' => [ [ 'field' => 'note', 'content' => $params['note'], 'content_scope' => 'internal_note', 'provenance' => 'user_goal' ] ] ] : []), 'workflow_id' => wp_generate_uuid4(), 'operation_id' => 'operation_1',
            'authorization' => [ 'execution_permit' => 'disposable-test-permit' ], 'extensions' => [ 'yoro.mcp_early_access_commerce_write' => [
                'source_operation_id' => 'envelope_' . str_repeat( 'a', 64 ), 'source_result_hash' => str_repeat( 'b', 64 ), 'expected_version' => $version ] ] ];
    };
    $version = YoRo_Commerce_Discovery_Service::product_version( $product );
    $operation = $make( 'products.update_stock', [ 'product_id' => $product->get_id(), 'stock_quantity' => 7, 'expected_version' => $version ], $version );
    $fenced = YoRo_Early_Access_Commerce_Write::execute( $operation, static function() { return false; } );
    $assert( is_wp_error( $fenced ) && 'yoro_connector_runner_lock_lost' === $fenced->get_error_code() && 5 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'The final fence runs after target/version reads and before the canonical mutator.' );
    $r = YoRo_Early_Access_Commerce_Write::execute( $operation );
    $assert( ! is_wp_error( $r ) && 7 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'One confirmed stock mutation executes through the canonical executor: ' . ( is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() : wp_json_encode( $r ) ) );
    $assert( is_wp_error( YoRo_Early_Access_Commerce_Write::execute( $operation ) ), 'A stale target version cannot execute twice.' );
    $parent = new WC_Product_Variable(); $parent->set_name( 'Disposable parent-stock fixture' ); $parent->set_status( 'publish' ); $parent->set_manage_stock( true ); $parent->set_stock_quantity( 20 ); $parent->save();
    $variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); $variation->set_status( 'publish' ); $variation->set_manage_stock( false ); $variation->save(); $variation = wc_get_product( $variation->get_id() );
    $assert( 'parent' === $variation->get_manage_stock() && false === $variation->get_manage_stock( 'edit' ), 'The fixture really inherits parent stock.' );
    $inherited_version = YoRo_Commerce_Discovery_Service::product_version( $variation );
    $inherited = $make( 'products.update_stock', [ 'product_id' => $variation->get_id(), 'stock_quantity' => 7, 'expected_version' => $inherited_version ], $inherited_version );
    $assert( is_wp_error( YoRo_Early_Access_Commerce_Write::execute( $inherited ) ) && false === wc_get_product( $variation->get_id() )->get_manage_stock( 'edit' ) && 20 === wc_get_product( $parent->get_id() )->get_stock_quantity(), 'Inherited variation stock cannot silently become own stock or change its parent.' );
    $product->set_manage_stock( false ); $product->save(); $product = wc_get_product( $product->get_id() );
    $version = YoRo_Commerce_Discovery_Service::product_version( $product );
    $operation = $make( 'products.update_stock_status', [ 'product_id' => $product->get_id(), 'stock_status' => 'outofstock', 'expected_version' => $version ], $version );
    $r = YoRo_Early_Access_Commerce_Write::execute( $operation );
    $assert( ! is_wp_error( $r ) && 'outofstock' === wc_get_product( $product->get_id() )->get_stock_status(), 'Manual stock status uses the existing canonical write service.' );
    $product = wc_get_product($product->get_id());
    $edit_changes = ['name'=>'Edited disposable product','regular_price'=>'25','sale_price'=>'20',
        'sku'=>'yr0178-edit-'.$product->get_id(),'global_unique_id'=>'9780306406157','manage_stock'=>true,'stock_quantity'=>12,
        'stock_status'=>'instock','backorders'=>'notify','low_stock_amount'=>2];
    $version = YoRo_Commerce_Discovery_Service::product_version($product);
    $edit = $make('products.update_details',['product_id'=>$product->get_id(),'changes'=>$edit_changes,'expected_version'=>$version],$version);
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($edit, static function(){return false;})), 'Product edits honor the final execution fence.');
    $assert(wc_get_product($product->get_id())->get_name() !== $edit_changes['name'], 'A denied fence does not save fields.');
    $result = YoRo_Early_Access_Commerce_Write::execute($edit);
    $assert(!is_wp_error($result) && $result['data']['changes'] === $edit_changes, 'The product patch saves and verifies every confirmed field: '.(is_wp_error($result)?$result->get_error_code():wp_json_encode($result)));
    $product = wc_get_product($product->get_id());
    $assert($product->get_name()===$edit_changes['name'] && '20' === $product->get_price() && 12 === $product->get_stock_quantity(), 'Product name, active sale price and quantity update through WooCommerce CRUD.');
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($edit)), 'A product patch cannot execute again with the old version.');
    foreach ([['regular_price'=>'19'], ['sale_price'=>'26'], ['price'=>'1'], ['stock_quantity'=>1.5], ['global_unique_id'=>'ABC']] as $bad_changes) {
        $version=YoRo_Commerce_Discovery_Service::product_version($product);
        $bad=$make('products.update_details',['product_id'=>$product->get_id(),'changes'=>$bad_changes,'expected_version'=>$version],$version);
        $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($bad)), 'Invalid price, identifier, field or quantity is rejected before saving.');
    }
    $duplicate = new WC_Product_Simple();$duplicate->set_name('Duplicate identifier fixture');$duplicate->set_status('publish');$duplicate->set_sku('yr0178-duplicate-'.$product->get_id());$duplicate->save();
    $version=YoRo_Commerce_Discovery_Service::product_version($product);
    $bad=$make('products.update_details',['product_id'=>$product->get_id(),'changes'=>['name'=>'Must not persist','sku'=>$duplicate->get_sku()],'expected_version'=>$version],$version);
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($bad)) && wc_get_product($product->get_id())->get_name()===$edit_changes['name'], 'Duplicate SKU cannot partially persist the earlier name setter.');
    $product->set_sku('yr0178-same-second-'.$product->get_id());$product->save();$product=wc_get_product($product->get_id());
    $assert($version!==YoRo_Commerce_Discovery_Service::product_version($product), 'SKU participates in the version even within one timestamp second.');
    $facts=YoRo_Product_Edit::facts($variation);
    $assert($facts['stock_managed_by_parent'] && false===$facts['edit_fields']['manage_stock'], 'Editing inherited stock uses the raw local management setting.');
    $version=YoRo_Commerce_Discovery_Service::product_version($variation);
    $local=$make('products.update_details',['product_id'=>$variation->get_id(),'changes'=>['manage_stock'=>true,'stock_quantity'=>3,'stock_status'=>'instock'],'expected_version'=>$version],$version);
    $assert(!is_wp_error(YoRo_Early_Access_Commerce_Write::execute($local)) && 20===wc_get_product($parent->get_id())->get_stock_quantity(), 'Explicit variation stock opt-in never mutates parent quantity.');
    $version=YoRo_Commerce_Discovery_Service::product_version($product);
    $off=$make('products.update_details',['product_id'=>$product->get_id(),'changes'=>['manage_stock'=>false,'stock_quantity'=>null,'backorders'=>'no','low_stock_amount'=>null],'expected_version'=>$version],$version);
    $assert(!is_wp_error(YoRo_Early_Access_Commerce_Write::execute($off)), 'Disabling stock management explicitly includes WooCommerce clearing effects.');
    $product=wc_get_product($product->get_id());$assert(null===$product->get_stock_quantity() && 'no'===$product->get_backorders(), 'Disabled quantity and backorder settings match the reviewed patch.');
    $read=YoRo_Early_Access_Product_Read::execute(['action'=>'products.get_details','params'=>['product_ids'=>[$product->get_id()]],'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1']);
    $assert(isset($read['data']['products'][0]['extensions']['woocommerce.product']['edit_fields']), 'Product detail reads include bounded edit facts.');
    $order = new WC_Order(); $order->set_status( 'pending' ); $order->set_total( '10.00' ); $order->save();
    $read = YoRo_Early_Access_Order_Read::execute( [ 'action' => 'orders.get_details', 'params' => [ 'order_ids' => [ $order->get_id() ] ] ] );
    $assert( $read['data']['orders'][0]['version'] === YoRo_Order_Presenter::order_version( wc_get_order( $order->get_id() ) ), 'The detail read returns the write service optimistic version.' );
    $version = $read['data']['orders'][0]['version'];
    $operation = $make( 'orders.update_status', [ 'order_ids' => [ $order->get_id() ], 'status' => 'on-hold', 'expected_versions' => [ [ 'order_id' => $order->get_id(), 'expected_version' => $version ] ] ], $version );
    $r = YoRo_Early_Access_Commerce_Write::execute( $operation );
    $assert( ! is_wp_error( $r ) && 'on-hold' === wc_get_order( $order->get_id() )->get_status(), 'One order status mutation executes with the exact version.' );
    $version = YoRo_Order_Presenter::order_version( wc_get_order( $order->get_id() ) );
    $operation = $make( 'orders.add_note', [ 'order_ids' => [ $order->get_id() ], 'note' => 'Internal disposable fixture note.' ], $version );
    $r = YoRo_Early_Access_Commerce_Write::execute( $operation );
    $assert( ! is_wp_error( $r ) && true === $r['data']['internal_only'] && 1 === count( $r['data']['note_ids'] ), 'The note result proves one internal staff note.' );
    $assert( ! wc_get_order_note( $r['data']['note_ids'][0] )->customer_note && false === strpos( wp_json_encode( $r ), 'Internal disposable fixture note.' ), 'Neither customer-note visibility nor note text enters the durable result.' );
    $bad = $operation; $bad['params']['customer_note'] = true;
    $assert( is_wp_error( YoRo_Early_Access_Commerce_Write::execute( $bad ) ), 'Additional note visibility selectors are rejected.' );
    $bad = $operation; $bad['params']['order_ids'][] = $order->get_id() + 1;
    $assert( is_wp_error( YoRo_Early_Access_Commerce_Write::execute( $bad ) ), 'Bulk order changes are rejected.' );
    unset( $operation['authorization'] );
    $assert( is_wp_error( YoRo_Early_Access_Commerce_Write::execute( $operation ) ), 'A missing confirmed execution permit is rejected.' );
    echo "YoRo confirmed commerce write contract passed.\n";
} finally {
    $wpdb->query( 'ROLLBACK' ); wp_cache_flush(); wp_set_current_user( $original ); remove_filter( 'pre_wp_mail', '__return_true' );
}
