<?php
/** Disposable WooCommerce only. A batch is non-atomic and never retries uncertain item writes. */
if (!defined('ABSPATH')) { exit(1); }
if (!class_exists('YoRo_Product_Batch')) { YoRo_AI_Core::init(); }
if (!class_exists('WooCommerce')) { echo "Product batches unavailable without WooCommerce.\n"; return; }
if (str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), 'yorohq.com')) { throw new RuntimeException('Never run write fixtures on canary or production.'); }
$assert=static function($ok,$why){if(!$ok){throw new RuntimeException($why);}};
$original=get_current_user_id();$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
add_filter('pre_wp_mail','__return_true'); global $wpdb; $wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int)$admins[0]);
    YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    $products=[];
    for($i=0;$i<3;$i++){$product=new WC_Product_Simple();$product->set_name('Disposable batch '.$i);$product->set_status('publish');$product->set_regular_price('10');$product->save();$products[]=$product->get_id();}
    $make=static function($changes)use($products){
        $items=[];foreach($changes as $index=>$patch){$id=$products[$index];$items[]=['product_id'=>$id,'changes'=>$patch,'expected_version'=>YoRo_Commerce_Discovery_Service::product_version(wc_get_product($id))];}
        $params=['items'=>$items];$identity=hash('sha256',wp_generate_uuid4());
        return ['envelope_id'=>'envelope_'.$identity,'idempotency_key'=>'batch-'.$identity,'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1',
            'action'=>YoRo_Product_Batch::Action,'params'=>$params,'authorization'=>['execution_permit'=>'disposable'],
            'extensions'=>['yoro.mcp_early_access_commerce_write'=>['source_operation_id'=>'envelope_'.str_repeat('a',64),'source_result_hash'=>str_repeat('b',64),'expected_version'=>YoRo_Product_Batch::version($params)]]];
    };
    $batch=$make([['regular_price'=>'11'],['regular_price'=>'12'],['regular_price'=>'13']]);
    $preview=YoRo_Product_Batch::preview($batch['params']);
    $assert(!is_wp_error($preview)&&$preview['non_atomic']&&count($preview['items'])===3&&wc_get_product($products[0])->get_regular_price()==='10','Batch preview shows each version without saving.');
    $result=YoRo_Early_Access_Commerce_Write::execute($batch);
    $assert(!is_wp_error($result)&&$result['data']['updated_count']===3&&array_column($result['data']['items'],'status')===['updated','updated','updated'],'Confirmed explicit targets each receive their own verified outcome.');
    $assert(wc_get_product($products[0])->get_regular_price()==='11'&&wc_get_product($products[2])->get_regular_price()==='13','Only the approved prices persist.');
    $replay=YoRo_Early_Access_Commerce_Write::execute($batch);
    $assert(!is_wp_error($replay)&&$replay['data']===$result['data'],'Completed per-item journals return identical proof without repeated mutations.');
    $conflict=$make([['regular_price'=>'21'],['regular_price'=>'22'],['regular_price'=>'23']]);
    $changed=wc_get_product($products[1]);$changed->set_description('Concurrent source change');$changed->save();
    $mixed=YoRo_Early_Access_Commerce_Write::execute($conflict);
    $assert(!is_wp_error($mixed)&&array_column($mixed['data']['items'],'status')===['updated','failed','updated']&&$mixed['data']['items'][1]['code']==='version_conflict'
        &&wc_get_product($products[1])->get_regular_price()==='12','A normal conflict is reported per item while other explicit targets may proceed.');
    $permission=$make([['regular_price'=>'31'],['regular_price'=>'32']]);
    $deny=static fn($caps,$cap,$user,$args)=>$cap==='edit_post'&&($args[0]??null)===$products[0]?['do_not_allow']:$caps;
    add_filter('map_meta_cap',$deny,10,4);$denied=YoRo_Early_Access_Commerce_Write::execute($permission);remove_filter('map_meta_cap',$deny,10);
    $assert(!is_wp_error($denied)&&$denied['data']['items'][0]['code']==='not_allowed'&&$denied['data']['items'][1]['status']==='updated','Each target retains its own edit permission boundary.');
    $crashed=$make([['regular_price'=>'41'],['regular_price'=>'42'],['regular_price'=>'43']]);
    $child=YoRo_Product_Batch::child_operation($crashed,1);$assert(YoRo_Connector_Execution_Journal::begin($child)['state']==='new','An interrupted child marker can be modeled durably.');
    $unknown=YoRo_Early_Access_Commerce_Write::execute($crashed);
    $assert(!is_wp_error($unknown)&&array_column($unknown['data']['items'],'status')===['updated','unknown','not_attempted']
        &&wc_get_product($products[1])->get_regular_price()==='32'&&wc_get_product($products[2])->get_regular_price()==='23','An executing marker is never retried; later items stop after uncertainty.');
    $fenced=$make([['regular_price'=>'51'],['regular_price'=>'52'],['regular_price'=>'53']]);$calls=0;
    $stopped=YoRo_Early_Access_Commerce_Write::execute($fenced,static function()use(&$calls){return ++$calls<=2;});
    $assert(!is_wp_error($stopped)&&array_column($stopped['data']['items'],'status')===['updated','not_attempted','not_attempted']
        &&wc_get_product($products[1])->get_regular_price()==='32','The execution fence is checked before every item mutator and stops remaining items.');
    $unknown_save=$make([['regular_price'=>'61'],['regular_price'=>'62'],['regular_price'=>'63']]);
    $lose=static function($result,$action,$params)use($products){return $action==='products.update_details'&&($params['product_id']??null)===$products[1]
        ?new WP_Error('local_execution_outcome_unknown','Disposable post-save uncertainty'):$result;};
    add_filter('yoro_after_action_execute',$lose,10,3);$saved_unknown=YoRo_Early_Access_Commerce_Write::execute($unknown_save);remove_filter('yoro_after_action_execute',$lose,10);
    $assert(!is_wp_error($saved_unknown)&&array_column($saved_unknown['data']['items'],'status')===['updated','unknown','not_attempted']&&wc_get_product($products[1])->get_regular_price()==='62','Post-save uncertainty stops later writes and reports no fabricated success for the uncertain item.');
    $again=YoRo_Early_Access_Commerce_Write::execute($unknown_save);
    $assert(!is_wp_error($again)&&array_column($again['data']['items'],'status')===['updated','unknown','not_attempted'],'An unknown completed child result remains unknown on duplicate delivery.');
    $bad=$make([['regular_price'=>'71']]);$bad['params']['items'][0]['changes']['name']='Outside batch fields';
    $assert(!YoRo_Product_Batch::valid_params($bad['params'])&&is_wp_error(YoRo_Early_Access_Commerce_Write::execute($bad)),'Bulk cannot expand to content, identifiers, or catalog fields.');
    $bad=$make([['regular_price'=>'71']]);$bad['params']['items'][]=$bad['params']['items'][0];
    $assert(!YoRo_Product_Batch::valid_params($bad['params']),'Duplicate target IDs are rejected.');
    $bad=$make([['regular_price'=>'71']]);$bad['params']['items']=array_fill(0,11,$bad['params']['items'][0]);
    $assert(!YoRo_Product_Batch::valid_params($bad['params']),'More than ten targets fail closed.');
    $bad=$make([['regular_price'=>'71']]);$bad['extensions']['yoro.mcp_early_access_commerce_write']['expected_version']=str_repeat('f',64);
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($bad)),'The aggregate expected version binds ordered target versions.');
    $bare=YoRo_Action_Executor::execute(YoRo_Product_Batch::Action,$make([['regular_price'=>'71']])['params'],true);
    $assert(is_array($bare)&&empty($bare['success']),'Direct bare params cannot bypass confirmed parent-envelope lineage.');
    echo "Product batch contract passed: exact fields, versions/permissions, durable child replay/unknown, fence and non-atomic outcomes.\n";
} finally {$wpdb->query('ROLLBACK');wp_cache_flush();wp_set_current_user($original);remove_filter('pre_wp_mail','__return_true');}
