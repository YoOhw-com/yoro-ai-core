<?php
/** Only an explicitly disposable WordPress/WooCommerce fixture database. */
if (!defined('ABSPATH') || !class_exists('WooCommerce')) { throw new RuntimeException('WooCommerce test runtime required.'); }
if (str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')) { throw new RuntimeException('Never run write fixtures on canary or production.'); }
$assert=static function($ok,$message){ if(!$ok){throw new RuntimeException($message);} };
$original=get_current_user_id();$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
add_filter('pre_wp_mail','__return_true');global $wpdb;$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int)$admins[0]);YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    $code='yr0178-coupon-'.wp_generate_uuid4();
    $product=new WC_Product_Simple();$product->set_name('Coupon restriction fixture');$product->set_status('publish');$product->save();
    $term=wp_insert_term('Coupon restriction '.wp_generate_uuid4(),'product_cat');$term=(int)$term['term_id'];
    $search=YoRo_Coupon_Management::search(['code'=>$code,'page'=>1]);
    $assert(!is_wp_error($search)&&$search['data']['coupons']===[],'Exact code search returns an empty source.');
    $params=['coupon_id'=>0,'changes'=>['code'=>$code,'discount_type'=>'percent','amount'=>'12.345678','description'=>'Plain coupon description','date_expires'=>'2028-03-12',
        'usage_limit'=>10,'usage_limit_per_user'=>1,'individual_use'=>true,'free_shipping'=>true,'exclude_sale_items'=>true,'product_ids'=>[$product->get_id()],
        'product_categories'=>[$term],'minimum_amount'=>'1.000001','maximum_amount'=>'100.000001'], 'expected_version'=>$search['data']['source']['version']];
    $preview=YoRo_Coupon_Management::preview_create($params);
    $assert(!is_wp_error($preview) && $preview['after']['status']==='draft' && !wc_get_coupon_id_by_code($code),'Create preview includes disabled default and never saves. '.(is_wp_error($preview)?$preview->get_error_message():wp_json_encode($preview)));
    $operation=static fn($action,$params)=>['action'=>$action,'params'=>$params,'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1',
        'authorization'=>['execution_permit'=>'disposable'],'extensions'=>['yoro.mcp_early_access_commerce_write'=>['source_operation_id'=>'envelope_'.str_repeat('a',64),'source_result_hash'=>str_repeat('b',64),'expected_version'=>$params['expected_version']]]];
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($operation('coupons.create_managed',$params),static fn()=>false)),'A lost execution fence denies coupon creation.');
    $created=YoRo_Early_Access_Commerce_Write::execute($operation('coupons.create_managed',$params));
    $assert(!is_wp_error($created),'Create exact decimal coupon succeeds. '.(is_wp_error($created)?$created->get_error_message():wp_json_encode($created)));
    $id=$created['data']['coupon_id'];$coupon=new WC_Coupon($id);
    $assert($coupon->get_status()==='draft' && $coupon->get_amount('edit')==='12.345678','Draft and precise decimal persist through WooCommerce CRUD.');
    $assert(wp_date('Y-m-d H:i:s',$coupon->get_date_expires()->getTimestamp(),wp_timezone())==='2028-03-12 23:59:59','Expiry uses store-local end of day.');
    $assert(is_wp_error(YoRo_Coupon_Management::execute_create($params)),'Create cannot replay an old exact-code source or duplicate code.');
    $details=YoRo_Coupon_Management::get_details(['coupon_id'=>$id,'_yoro_confirmed'=>false,'_yoro_preview'=>false]);
    $assert(!is_wp_error($details) && $details['data']['coupon']['id']===$id,'Detail selector strips internal executor flags.');
    $patch=static fn($changes)=>['coupon_id'=>$id,'changes'=>$changes,'expected_version'=>YoRo_Coupon_Management::version(new WC_Coupon($id))];
    $read=YoRo_Early_Access_Coupon_Read::execute(['action'=>'coupons.get_details','params'=>['coupon_id'=>$id],'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1']);
    $assert(!is_wp_error($read) && $read['data']['coupon']['id']===$id,'Canonical read execution preserves bounded coupon data.');
    foreach([['amount'=>'100.000001'],['minimum_amount'=>'1000'],['product_ids'=>[PHP_INT_MAX]],['product_categories'=>[PHP_INT_MAX]],['code'=>'renamed'],['email_restrictions'=>['x@example.test']],['status'=>'trash'],['usage_limit'=>0],['description'=>'<b>bad</b>']] as $bad){$assert(is_wp_error(YoRo_Coupon_Management::execute_update($patch($bad))),'Unsafe coupon settings reject before save: '.wp_json_encode($bad));}
    $stale=$patch(['status'=>'publish']);$coupon=new WC_Coupon($id);$coupon->set_description('External change');$coupon->save();
    $assert(is_wp_error(YoRo_Coupon_Management::execute_update($stale)),'Current code version is required after external changes.');
    $denied=static function($caps,$cap,$user,$args)use($term){return $cap==='assign_term'&&($args[0]??null)===$term?['do_not_allow']:$caps;};
    add_filter('map_meta_cap',$denied,10,4);
    $assert(is_wp_error(YoRo_Coupon_Management::preview_update($patch(['product_categories'=>[$term],'status'=>'publish']))),'Every requested restriction checks actor permission.');
    remove_filter('map_meta_cap',$denied,10);
    $clear=YoRo_Early_Access_Commerce_Write::execute($operation('coupons.update',$patch(['description'=>'','date_expires'=>null,'usage_limit'=>null,'usage_limit_per_user'=>null,'minimum_amount'=>'','maximum_amount'=>'','product_ids'=>[],'product_categories'=>[],'status'=>'publish'])));
    $assert(!is_wp_error($clear),'Clear and publish patch persists. '.(is_wp_error($clear)?$clear->get_error_message():wp_json_encode($clear)));
    $coupon=new WC_Coupon($id);$assert($coupon->get_status()==='publish' && $coupon->get_date_expires()===null && $coupon->get_usage_limit()===0 && $coupon->get_product_ids()===[],'Explicit clear semantics match WooCommerce.');
    $coupon->set_description(str_repeat('<b>legacy</b>',200));$coupon->save();$row=YoRo_Coupon_Management::row(new WC_Coupon($id));
    $assert(strlen($row['fields']['description'])<=1000&&!in_array('description',$row['editable_fields'],true),'Legacy HTML/long descriptions stay readable and independently uneditable.');
    echo "Coupon management CRUD, source/version, decimal/date, clear, restriction permission and negative contracts passed.\n";
} finally {$wpdb->query('ROLLBACK');wp_cache_flush();wp_set_current_user($original);remove_filter('pre_wp_mail','__return_true');}
