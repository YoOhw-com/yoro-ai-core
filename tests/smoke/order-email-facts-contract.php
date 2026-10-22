<?php
/** Actual disposable Woo records; no network or real mail. */
if (!defined('ABSPATH') || !class_exists('WooCommerce')) { return; }
$fixtureHost=(string)wp_parse_url(home_url(),PHP_URL_HOST);
if (!str_ends_with($fixtureHost,'.invalid') && $fixtureHost!=='yoro-plugin-smoke.test') {throw new RuntimeException('Disposable local .invalid fixture required.');}
require_once ABSPATH.WPINC.'/PHPMailer/PHPMailer.php';require_once ABSPATH.WPINC.'/PHPMailer/Exception.php';require_once ABSPATH.WPINC.'/class-wp-phpmailer.php';
if(!class_exists('YoRo_Facts_Captured_Mailer')){class YoRo_Facts_Captured_Mailer extends WP_PHPMailer {public int $captured=0;public function send(){++$this->captured;return true;}}}
$checks=0;$assert=static function($ok,$why)use(&$checks){++$checks;if(!$ok){throw new RuntimeException($why);}};
global $wpdb,$phpmailer;$oldMailer=$phpmailer;$oldUser=get_current_user_id();$phpmailer=$capture=new YoRo_Facts_Captured_Mailer(true);
$wpdb->query('START TRANSACTION');
try {
 wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
 $product=new WC_Product_Simple;$product->set_name('Current public digital title');$product->set_status('publish');$product->set_regular_price('35.125');$product->set_sku('FACTS-SKU-'.wp_generate_uuid4());$product->set_virtual(true);$product->set_downloadable(true);$product->save();
 $order=wc_create_order();$order->set_currency('KWD');$order->set_billing_email('buyer@example.invalid');$order->set_billing_first_name('Buyer');$order->set_billing_last_name('Order only');$order->set_billing_phone('SECRET PHONE');$order->set_billing_address_1('SECRET ADDRESS');$order->set_customer_note('PRIVATE CUSTOMER NOTE');$order->set_transaction_id('SECRET TRANSACTION');$order->save();$id=$order->get_id();
 $line=new WC_Order_Item_Product;$line->set_product($product);$line->set_name('Purchased historical title');$line->set_quantity(2);$line->set_subtotal('35.125');$line->set_total('30.125');$line->add_meta_data('private_supplier_instruction','PRIVATE ITEM DATA');$order->add_item($line);$order->set_discount_total('5');$order->set_shipping_total('2.500');$order->set_total('32.625');$order->save();$order->add_order_note('PRIVATE ADMIN NOTE');
 $read=static function()use($id,$capture,$assert){$n=$capture->captured;$data=YoRo_Order_Email::context($id,['context_profile'=>'email_facts_v1']);$assert($n===$capture->captured,'Facts read never sends mail.');return $data;};$before=$capture->captured;
 $queries=[];$observe=static function($args)use(&$queries){$queries[]=$args;return $args;};add_filter('woocommerce_order_query_args',$observe);$started=microtime(true);$queryStart=$wpdb->num_queries;
 try{$data=$read();}finally{remove_filter('woocommerce_order_query_args',$observe);}
 $metrics=['first_facts_seconds'=>microtime(true)-$started,'first_facts_sql_queries'=>$wpdb->num_queries-$queryStart,'line_count'=>1,'order_query_args'=>$queries];
 foreach($queries as$args){$assert(($args['type']??null)==='shop_order_refund'&&(!isset($args['parent'])||(int)$args['parent']===$id),'Context never queries another customer/order history; refund reads remain order-bound.');}
 $started=microtime(true);$queryStart=$wpdb->num_queries;$legacyData=YoRo_Order_Email::context($id);$metrics['legacy_authored_seconds']=microtime(true)-$started;$metrics['legacy_authored_sql_queries']=$wpdb->num_queries-$queryStart;
 if($path=getenv('YR0180_CONTEXT_METRICS_FILE')){file_put_contents($path,wp_json_encode($metrics,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
 $facts=$data['facts'];$json=wp_json_encode($facts);
 $assert($facts['items'][0]['name']==='Purchased historical title'&&$facts['items'][0]['catalog_name']==='Current public digital title','Historical/current product facts are separated.');
 $assert($facts['items'][0]['catalog_virtual']===true&&$facts['items'][0]['catalog_downloadable']===true,'Current published digital product is correctly typed.');
 foreach(['SECRET PHONE','SECRET ADDRESS','PRIVATE CUSTOMER NOTE','SECRET TRANSACTION','PRIVATE ITEM DATA','PRIVATE ADMIN NOTE',$order->get_order_key()]as$secret){$assert(!str_contains($json,$secret),'Private information is not projected: '.$secret);}
 $assert($facts['order']['totals']['subtotal']==='35.125'&&$facts['order']['currency']==='KWD','Real Woo stored decimal/currency projection is exact.');
 $assert($facts['order']['paid_at']['value']===null&&!$facts['order']['payment']['paid_recorded']&&$facts['order']['status']==='pending','Unpaid pending order has no invented payment evidence.');
 $assert($data['version']===$read()['version'],'Real Woo facts observation leaves the version stable.');
 $assert($data['version']===YoRo_Order_Email::context($id)['version'],'Fresh default legacy preparation and facts profile share the exact source version.');
 $params=['order_id'=>$id,'recipient'=>$data['recipient'],'expected_version'=>$data['version'],'changes'=>['subject'=>'Facts email','body'=>'Exact facts-grounded body']];
 $assert(!is_wp_error(YoRo_Order_Email::prepare($params,'orders.send_authored_email')),'Facts source passes the existing prepare guard.');
 $product->set_name('Changed public title');$product->save();$assert(is_wp_error(YoRo_Order_Email::prepare($params,'orders.send_authored_email')),'Changed real catalog invalidates facts before send.');
 $data=$read();$params['expected_version']=$data['version'];$product->set_status('draft');$product->save();$catalog=$read()['facts']['items'][0];
 $assert($catalog['catalog_availability']==='unavailable'&&$catalog['catalog_name']===null&&$catalog['catalog_virtual']===null,'Draft catalog is absent and not falsely physical.');
 $assert(is_wp_error(YoRo_Order_Email::prepare($params,'orders.send_authored_email')),'Catalog visibility changes invalidate pending source.');
 $product->delete(true);$facts=$read()['facts'];$assert($facts['items'][0]['name']==='Purchased historical title'&&$facts['items'][0]['catalog_availability']==='unavailable','Deleted product does not erase purchased evidence.');
 $data=$read();$params['expected_version']=$data['version'];$line->set_name('Changed stored purchased line');$line->save();$assert(is_wp_error(YoRo_Order_Email::prepare($params,'orders.send_authored_email')),'Stored item edit invalidates before send.');
 $refund=wc_create_refund(['order_id'=>$id,'amount'=>'0.10','reason'=>'PRIVATE REFUND REASON','refund_payment'=>false,'restock_items'=>false]);$assert(!is_wp_error($refund),'Recorded local partial refund created without gateway settlement.');
 $facts=$read()['facts'];$assert(in_array($facts['order']['totals']['refunded'],['0.1','0.10'],true)&&!$facts['order']['payment']['gateway_settlement_verified'],'Recorded refund aggregate is truthful without claiming settlement.');
 $assert(!str_contains(wp_json_encode($facts),'PRIVATE REFUND REASON'),'Refund internal reason is not projected.');
 $order=wc_get_order($id);$order->update_meta_data('_tracking_number','UNVERIFIED123');$order->save_meta_data();$facts=$read()['facts'];$assert($facts['tracking'][0]['tracking_number']==='UNVERIFIED123'&&!$facts['fulfillment']['carrier_verified']&&$facts['fulfillment']['dispatched_at']===null&&$facts['fulfillment']['delivered_at']===null,'Stored tracking makes no carrier dispatch/delivery claim.');
 $order->update_meta_data('_tracking_number','buyer@example.invalid');$order->save_meta_data();$assert($read()['facts']['tracking']===[],'Email-like tracking cannot leak customer contact.');
 $order->set_billing_phone('+1 (415) 555-0100');$order->update_meta_data('_tracking_number','14155550100');$order->save();$assert($read()['facts']['tracking']===[],'Stored contact phone is not tracking evidence.');
 $order->delete_meta_data('_tracking_number');$order->save_meta_data();
 update_option('woocommerce_feature_fulfillments_enabled','yes');
 if(YoRo_Order_Management::tracking_available()){$record=new Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;$record->set_entity_type(WC_Order::class);$record->set_entity_id((string)$id);$record->set_status('unfulfilled');$record->set_items([['item_id'=>$line->get_id(),'qty'=>1]]);$record->set_shipment_provider('PRIVATE CARRIER TEXT');$record->set_tracking_number('NATIVE123456');$record->save();$native=$read()['facts'];$assert($native['tracking'][0]===['source'=>'native_fulfillment','carrier'=>null,'tracking_number'=>'NATIVE123456']&&!$native['fulfillment']['carrier_verified'],'Native stored tracking exposes bounded codes and allowlisted carrier labels only.');}
 $assert($capture->captured<=1,'Only the fixture refund may produce a captured native notification.');
 foreach(['future','',null]as$bad){$assert(is_wp_error(YoRo_Order_Email::read(['order_id'=>$id,'context_profile'=>$bad])),'Unsupported profiles deny.');}
 $assert(is_wp_error(YoRo_Order_Email::read(['order_id'=>$id,'context_profile'=>'email_facts_v1','template'=>'order_details'])),'Facts cannot render the native address/payment template.');
 if($path=getenv('YR0180_REAL_FACTS_FILE')){file_put_contents($path,wp_json_encode(['legacy'=>YoRo_Order_Email::context($id),'profile'=>$read()],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
 echo 'Order email facts smoke passed; '.$checks.' assertions, 0 real deliveries.'."\n";
} finally {$wpdb->query('ROLLBACK');wp_cache_flush();$phpmailer=$oldMailer;wp_set_current_user($oldUser);}
