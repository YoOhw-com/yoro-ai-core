<?php
if(!defined('ABSPATH')){exit(1);}if(str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')){throw new RuntimeException('Disposable local WordPress only.');}
$assert=static function($ok,$message){if(!$ok){throw new RuntimeException($message);}};$original=get_current_user_id();$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);wp_set_current_user((int)$admins[0]);
$actor_access_before=get_user_meta((int)$admins[0],YoRo_WordPress_Actor::ACCESS_META_KEY,true);$actor_subject_before=get_user_meta((int)$admins[0],YoRo_WordPress_Actor::SUBJECT_META_KEY,true);
add_filter('pre_wp_mail',static fn()=>true);add_filter('pre_http_request',static fn()=>new WP_Error('test_no_network','No network during local tests.'));$sync=static fn()=>'no';add_filter('pre_option_woocommerce_custom_orders_table_data_sync_enabled',$sync);
global $wpdb;
foreach(['yes'=>'HPOS','no'=>'CPT']as$storage=>$label){$mode=static fn()=>$storage;add_filter('pre_option_woocommerce_custom_orders_table_enabled',$mode);$wpdb->query('START TRANSACTION');try{
 YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data']]);
 for($i=0;$i<201;$i++){$p=new WC_Product_Simple();$p->set_name('Restock no-sales fixture '.$i);$p->set_status('publish');$p->set_manage_stock(true);$p->set_stock_quantity(3);$p->set_regular_price('5');$p->save();}
 $p=new WC_Product_Simple();$p->set_name('Restock complete sales fixture');$p->set_status('publish');$p->set_manage_stock(true);$p->set_backorders('yes');$p->set_stock_quantity(-1);$p->set_regular_price('5');$p->save();$id=$p->get_id();
 for($i=0;$i<205;$i++){$o=new WC_Order();$o->set_status('completed');$o->set_date_created(strtotime('2001-04-15T12:00:00Z'));$o->set_currency('USD');$item=new WC_Order_Item_Product();$item->set_product($p);$item->set_quantity(1);$item->set_subtotal('5');$item->set_total('5');$o->add_item($item);$o->set_total('5');$o->save();}
 wc_update_product_stock($id,-1,'set');clean_post_cache($id);
 $op=['action'=>YoRo_Commerce_Operations::ReadActions[0],'params'=>['window'=>'custom','date_from'=>'2001-04-15','date_to'=>'2001-04-15','limit'=>50,'focus'=>'all'],'expires_at'=>gmdate('c',time()+1800)];$short=static fn()=>0.001;add_filter('yoro_complete_report_seconds',$short);
 for($attempt=0;$attempt<1000;$attempt++){$result=YoRo_Commerce_Operations::execute($op);if(is_wp_error($result)){throw new RuntimeException($result->get_error_code());}if(!isset($result['deferred'])){break;}}
 remove_filter('yoro_complete_report_seconds',$short);$d=$result['data'];$row=array_values(array_filter($d['rows'],static fn($row)=>$row['product_id']===$id))[0]??null;
 $assert($row&&$row['net_units']==='205.000000'&&$row['units_per_day']==='205.000000'&&$row['stock_quantity']===-1&&$row['priority']==='out_of_stock','All 205 sales are counted for the exact self-managed stock owner: '.wp_json_encode($row));
 $assert($d['products_scanned']>=202&&$d['group_count']>=202&&$d['rows_complete']===false&&count($d['rows'])===50,'No-sales products are scanned and ranking truncation is explicit.');
 $state=['windows'=>[$d['window']],'stock_units'=>[$id=>100],'inventory'=>['cursor'=>$id-1,'anchor'=>$id,'scanned'=>0,'count'=>0,'rows'=>[]]];
 $p=wc_get_product($id);$p->set_stock_quantity(1000000000);$p->save();YoRo_Commerce_Operations::inventory_batch($state,['focus'=>'all','limit'=>50]);
 $assert($state['inventory']['rows'][0]['estimated_days_cover']==='9000000000.000000','Very small velocity caps the estimate before integer conversion.');
 $expired=$op;$expired['expires_at']=gmdate('c',time()-1);$assert(is_wp_error(YoRo_Commerce_Operations::execute($expired)),'Continuation keeps its original execution deadline.');
 echo wp_json_encode(['suite'=>'restock_report','storage'=>$label,'result'=>'PASS','sales'=>205,'catalog_records_scanned'=>$d['products_scanned'],'ranking_rows'=>count($d['rows']),'rows_complete'=>$d['rows_complete'],'estimate_overflow'=>'capped']).PHP_EOL;
 }finally{$wpdb->query('ROLLBACK');wp_cache_flush();remove_filter('pre_option_woocommerce_custom_orders_table_enabled',$mode);}
 $assert(get_user_meta((int)$admins[0],YoRo_WordPress_Actor::ACCESS_META_KEY,true)===$actor_access_before&&get_user_meta((int)$admins[0],YoRo_WordPress_Actor::SUBJECT_META_KEY,true)===$actor_subject_before,'Read-only report fixtures must restore actor access and subject for subsequent contracts.');}
wp_set_current_user($original);remove_filter('pre_option_woocommerce_custom_orders_table_data_sync_enabled',$sync);
