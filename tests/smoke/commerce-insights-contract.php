<?php
if (!defined('ABSPATH')) { exit(1); }
if (str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')) { throw new RuntimeException('Disposable local WordPress only.'); }
if (!class_exists('YoRo_Commerce_Insights')||!class_exists('WooCommerce')) { throw new RuntimeException('Commerce insights candidate and WooCommerce required.'); }
$assert=static function($ok,$message){if(!$ok){throw new RuntimeException($message);}};
$original=get_current_user_id();$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
$mail=static fn()=>true;add_filter('pre_wp_mail',$mail);
$block=static fn()=>new WP_Error('insight_no_network','No network during report tests.');add_filter('pre_http_request',$block);
$sync=static fn()=>'no';add_filter('pre_option_woocommerce_custom_orders_table_data_sync_enabled',$sync);
$assert(wc_get_container()->get(Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class)->create_database_tables(),'HPOS tables are ready.');
global $wpdb;
foreach(['yes'=>'HPOS','no'=>'CPT'] as $storage=>$label){
    $mode=static fn()=>$storage;add_filter('pre_option_woocommerce_custom_orders_table_enabled',$mode);$wpdb->query('START TRANSACTION');
    try{
        wp_set_current_user((int)$admins[0]);YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data']]);
        $date=strtotime('2001-02-15T12:00:00Z');
        $category=wp_insert_term('Insight fixture '.wp_generate_uuid4(),'product_cat');$assert(!is_wp_error($category),'Category fixture created.');
        $product=new WC_Product_Simple();$product->set_name('Insight fixture');$product->set_status('publish');$product->set_regular_price('5');$product->set_category_ids([(int)$category['term_id']]);$product->save();
        $make=static function($date,$currency='USD')use($product){$o=new WC_Order();$o->set_status('completed');$o->set_currency($currency);$o->set_date_created($date);$item=new WC_Order_Item_Product();$item->set_product($product);$item->set_quantity(2);$item->set_subtotal('10');$item->set_total('8');$o->add_item($item);$coupon=new WC_Order_Item_Coupon();$coupon->set_code('insight-fixture');$coupon->set_discount('2');$o->add_item($coupon);$o->set_total('8');$o->save();return $o;};
        for($n=0;$n<205;$n++){$make($date);}
        $old=$make(strtotime('2000-12-15T12:00:00Z'));
        $source=array_values($old->get_items('line_item'))[0];
        $refund=new WC_Order_Refund();$refund->set_parent_id($old->get_id());$refund->set_currency('USD');$refund->set_date_created($date);$refund->set_amount('5');$refund->set_total('-5');
        $item=new WC_Order_Item_Product();$item->set_product($product);$item->set_quantity(-1);$item->set_subtotal('-4');$item->set_total('-4');$item->add_meta_data('_refunded_item_id',$source->get_id());$refund->add_item($item);$refund->save();
        $make($date,'EUR');
        $operation=['action'=>YoRo_Commerce_Insights::ReadActions[0],'params'=>['window'=>'custom','date_from'=>'2001-02-15','date_to'=>'2001-02-15','comparison'=>'none','limit'=>50],'expires_at'=>gmdate('c',time()+1800)];
        $run=static function($action)use($operation){$request=$operation;$request['action']=$action;for($n=0;$n<1000;$n++){$r=YoRo_Commerce_Insights::execute($request);if(is_wp_error($r)){throw new RuntimeException($r->get_error_code());}if(!isset($r['deferred'])){return$r['data'];}}throw new RuntimeException('Insight did not resume.');};
        $short=static fn()=>0.001;add_filter('yoro_complete_report_seconds',$short);$data=$run($operation['action']);remove_filter('yoro_complete_report_seconds',$short);
        $rows=array_values(array_filter($data['periods'][0]['rows'],static fn($r)=>$r['product_id']===$product->get_id()&&$r['currency']==='USD'));
        $assert(count($rows)===1,'Product groups stay distinct by currency.');$m=$rows[0];
        $assert($m['sales']==='1640.000000'&&$m['quantity']==='409.000000'&&$m['refunds']==='4.000000'&&$m['net_sales']==='1636.000000','All 205 sales and the old-order refund are counted exactly across checkpoints.');
        $usd=array_values(array_filter($data['periods'][0]['totals'],static fn($r)=>$r['currency']==='USD'))[0];
        $assert($usd['unallocated_refunds']==='1.000000','Refund amounts without attributable product lines remain explicit.');
        $assert($data['periods'][1]['totals']===[]&&strlen(wp_json_encode($data))<=42000,'Disabled comparison and payload budget are preserved.');
        $coupons=$run(YoRo_Commerce_Insights::ReadActions[2]);$c=array_values(array_filter($coupons['periods'][0]['rows'],static fn($r)=>$r['id']==='insight-fixture'&&$r['currency']==='USD'))[0];
        $assert($c['discounts']==='410.000000'&&$c['quantity']==='205.000000'&&$c['refunds']==='0.000000','Coupon uses retain original discounts and do not infer refund attribution.');
        $categories=$run(YoRo_Commerce_Insights::ReadActions[1]);$r=array_values(array_filter($categories['periods'][0]['rows'],static fn($r)=>$r['id']===(string)$category['term_id']&&$r['currency']==='USD'))[0];
        $assert($r['net_sales']==='1636.000000'&&$r['product_id']===0,'Current category membership is a separate group, not a product target.');
        $refunds=$run(YoRo_Commerce_Insights::ReadActions[3]);$m=array_values(array_filter($refunds['periods'][0]['totals'],static fn($r)=>$r['currency']==='USD'))[0];
        $assert($m['sales']==='0.000000'&&$m['refunds']==='4.000000'&&$m['unallocated_refunds']==='1.000000','Refund analysis counts refund-date flows without original-sale duplication.');
        $bad=$operation;$bad['params']['limit']=51;$assert(is_wp_error(YoRo_Commerce_Insights::execute($bad)),'Unbounded input is rejected.');
        $expired=$operation;$expired['expires_at']=gmdate('c',time()-1);$assert(is_wp_error(YoRo_Commerce_Insights::execute($expired)),'Saved calculation cannot extend execution authority.');
        wp_set_current_user(0);$assert(is_wp_error(YoRo_Commerce_Insights::execute($operation)),'Cached aggregation does not bypass current permissions.');
        echo 'Commerce insights '.$label.': all batches, refund date, coupon attribution, category, currencies, resumability, capacity and access PASS.'.PHP_EOL;
    }finally{$wpdb->query('ROLLBACK');wp_set_current_user($original);wp_cache_flush();remove_filter('pre_option_woocommerce_custom_orders_table_enabled',$mode);}
}
remove_filter('pre_http_request',$block);remove_filter('pre_wp_mail',$mail);remove_filter('pre_option_woocommerce_custom_orders_table_data_sync_enabled',$sync);
