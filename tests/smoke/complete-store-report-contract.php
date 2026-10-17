<?php
if (!defined('ABSPATH')) { exit(1); }
if (str_ends_with((string) wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')) { throw new RuntimeException('Disposable local WordPress only.'); }
if (!class_exists('YoRo_Complete_Store_Report') || !class_exists('WooCommerce')) { throw new RuntimeException('Report candidate and WooCommerce required.'); }
$assert=static function($ok,$message){if(!$ok)throw new RuntimeException($message);};
$original=get_current_user_id(); $admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
add_filter('pre_wp_mail','__return_true');
$block=static function(){return new WP_Error('report_test_no_network','No network during aggregation.');};add_filter('pre_http_request',$block);
global $wpdb;
$sync=static function(){return 'no';}; add_filter('pre_option_woocommerce_custom_orders_table_data_sync_enabled',$sync);
$assert(wc_get_container()->get(Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class)->create_database_tables(),'HPOS tables exist before fixture transactions.');
foreach(['yes'=>'HPOS','no'=>'CPT'] as $storage=>$label){
$mode=static function()use($storage){return $storage;};add_filter('pre_option_woocommerce_custom_orders_table_enabled',$mode);
$wpdb->query('START TRANSACTION');
try {
 wp_set_current_user((int)$admins[0]);
 YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data']]);
 $operation=['action'=>'store.get_business_summary','params'=>['window'=>'today'],'expires_at'=>gmdate('c',time()+300)];
 $run=static function()use($operation){for($i=0;$i<200;$i++){ $r=YoRo_Early_Access_Store_Overview_Read::execute($operation);if(is_wp_error($r))throw new RuntimeException($r->get_error_code());if(empty($r['deferred']))return$r;}$s=get_option(YoRo_Complete_Store_Report::STATE_OPTION,[]);throw new RuntimeException('Report never completes: '.wp_json_encode(array_intersect_key($s,array_flip(['phase','cursor','complete','anchor']))));};
 $before=$run();$currency=get_woocommerce_currency();$base=$before['extensions']['yoro.store_overview']['full_report']['current']['currencies'][$currency]['order_count']??0;
 $make=static function($amount,$status='processing',$date=null,$currency=null){$o=new WC_Order();$o->set_status($status);$o->set_currency($currency??get_woocommerce_currency());$o->set_total($amount);$o->set_date_created($date??time()-20);$o->set_billing_email('PRIVATE@example.com');$o->set_customer_note('PRIVATE-NOTE');$o->save();return$o;};
 for($i=0;$i<205;$i++){$o=$make('1.25');}
 $old=$make('7','pending',time()-40*DAY_IN_SECONDS);$foreign=$make('12','completed',time()-20,$currency==='EUR'?'USD':'EUR');
 $slow=static function(){return 0.001;};add_filter('yoro_complete_report_seconds',$slow);
 $first=YoRo_Early_Access_Store_Overview_Read::execute($operation);$assert(!empty($first['deferred']),'A bounded first batch checkpoints instead of truncating.');
 $result=$run();remove_filter('yoro_complete_report_seconds',$slow);
 $full=$result['extensions']['yoro.store_overview']['full_report'];
 $assert($full['current']['currencies'][$currency]['order_count']===$base+205,'Every qualifying order beyond 100 is counted exactly once across retries.');
 $assert($full['current']['scanned']>200&&!$result['extensions']['yoro.store_overview']['current']['has_more'],'Complete scan exceeds two batches.');
 $assert(isset($full['current']['currencies'][$foreign->get_currency()]),'Currencies are separate, never silently excluded.');
 $assert($full['backlog']['attention']>$before['extensions']['yoro.store_overview']['full_report']['backlog']['attention'],'Older unresolved orders enter current backlog.');
 $assert(!str_contains(wp_json_encode($result),'PRIVATE'),'Aggregate excludes customer identities and content.');
 $batches=0;$observe=static function()use(&$batches){$batches++;};add_action('yoro_complete_report_batch',$observe);
 $other=$operation;$other['action']='store.get_insights';$cached=YoRo_Early_Access_Store_Overview_Read::execute($other);
 $assert($batches===0&&$cached['data']['metrics']===$result['data']['metrics'],'Four reports reuse a current aggregate without another scan.');remove_action('yoro_complete_report_batch',$observe);
 $o->set_total('2.50');$o->save();$revised=$run();
 $assert($revised['data']['metrics']['gross_sales']!==$result['data']['metrics']['gross_sales'],'Order updates invalidate cached totals.');
 YoRo_Complete_Store_Report::invalidate();$change=static function(){YoRo_Complete_Store_Report::invalidate();};add_action('yoro_complete_report_batch',$change);
 $changed=YoRo_Early_Access_Store_Overview_Read::execute($operation);remove_action('yoro_complete_report_batch',$change);
 $assert(is_wp_error($changed)&&$changed->get_error_code()==='report_changed','Changes between batches never produce a Complete result.');
 $paid=$make('30','processing',time()-40*DAY_IN_SECONDS);$paid->set_date_paid(time()-10);$paid->save();
 $paidOperation=$operation;$paidOperation['params']['date_basis']='paid';
 $readPaid=YoRo_Early_Access_Store_Overview_Read::execute($paidOperation);while(is_array($readPaid)&&!empty($readPaid['deferred'])){$readPaid=YoRo_Early_Access_Store_Overview_Read::execute($paidOperation);}
 $assert(!is_wp_error($readPaid)&&$readPaid['extensions']['yoro.store_overview']['full_report']['date_basis']==='paid','Payment-date reports include older orders paid during the current period.');
 $refund=new WC_Order_Refund();$refund->set_parent_id($paid->get_id());$refund->set_amount('5.25');$refund->set_total('-5.25');$refund->set_date_created(time()-5);$refund->save();
 $paid->set_status('refunded');$paid->save();$afterRefund=YoRo_Early_Access_Store_Overview_Read::execute($paidOperation);while(is_array($afterRefund)&&!empty($afterRefund['deferred'])){$afterRefund=YoRo_Early_Access_Store_Overview_Read::execute($paidOperation);}
 $assert(!is_wp_error($afterRefund),'Refunded status remains eligible for original order totals.');
 $refundMoney=$afterRefund['extensions']['yoro.store_overview']['full_report']['current']['currencies'][$currency];
 $assert(YoRo_Complete_Store_Report::units($refundMoney['refunds'])>=5250000,'Partial refund amounts are recorded by refund creation date.');
 $assert(YoRo_Complete_Store_Report::units($refundMoney['order_totals_less_period_refunds'])===YoRo_Complete_Store_Report::units($refundMoney['order_totals'])-YoRo_Complete_Store_Report::units($refundMoney['refunds']),'Refund subtraction is exact and does not exclude the original refunded order.');
 $expired=$operation;$expired['expires_at']=gmdate('c',time()-1);$assert(is_wp_error(YoRo_Early_Access_Store_Overview_Read::execute($expired)),'Checkpoint/cache cannot extend an expired execution request.');
 $assert(YoRo_Complete_Store_Report::units('0.001')===1000&&YoRo_Complete_Store_Report::units('123.456789')===123456789,'Zero/three-decimal currencies retain six-digit calculation precision.');
 wp_set_current_user(0);$assert(is_wp_error(YoRo_Early_Access_Store_Overview_Read::execute($operation)),'Cached aggregates do not bypass permissions.');
 echo "Complete store reports: full batches, checkpoints, currencies, backlog, cache, mutation and authorization passed.\n";
} finally {$wpdb->query('ROLLBACK');wp_set_current_user($original);wp_cache_flush();remove_filter('pre_option_woocommerce_custom_orders_table_enabled',$mode);}
}
remove_filter('pre_http_request',$block);remove_filter('pre_option_woocommerce_custom_orders_table_data_sync_enabled',$sync);
