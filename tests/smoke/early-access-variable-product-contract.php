<?php
/** Run only in a disposable WordPress/WooCommerce test installation. */
if (!defined('ABSPATH')) { exit(1); }
if (!class_exists('YoRo_Variable_Product')) { YoRo_AI_Core::init(); }
if (!class_exists('WooCommerce')) { echo "Variable-product management unavailable without WooCommerce.\n"; return; }
if (str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')) { throw new RuntimeException('Never run variable-product write fixtures on canary or production.'); }
$assert=static function($value,$message){if(!$value){throw new RuntimeException($message);}};
$original=get_current_user_id();$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
add_filter('pre_wp_mail','__return_true');global $wpdb;$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int)$admins[0]);
    YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    $attribute=new WC_Product_Attribute();$attribute->set_name('Size');$attribute->set_options(['Small','Medium','Large']);$attribute->set_visible(true);$attribute->set_variation(true);
    $parent=new WC_Product_Variable();$parent->set_name('Disposable variable management');$parent->set_status('publish');$parent->set_description(str_repeat('Parent content ',310));$parent->set_attributes([$attribute]);$parent->save();
    $make=static function($action,$changes,$parent){$version=YoRo_Variable_Product::context(wc_get_product($parent->get_id()))['version'];return [
        'action'=>$action,'params'=>['product_id'=>$parent->get_id(),'changes'=>$changes,'expected_version'=>$version],
        'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1','authorization'=>['execution_permit'=>'disposable-test-permit'],
        'extensions'=>['yoro.mcp_early_access_commerce_write'=>['source_operation_id'=>'envelope_'.str_repeat('a',64),'source_result_hash'=>str_repeat('b',64),'expected_version'=>$version]]];};
    $operation=$make(YoRo_Variable_Product::CreateAction,['attributes'=>[['name'=>'Size','option'=>'Small']],'regular_price'=>'25','sale_price'=>'20','sku'=>'yr0178-var-'.wp_generate_password(8,false,false)],$parent);
    $before=YoRo_Variable_Product::context($parent);
    $preview=YoRo_Variable_Product::preview($operation['params'],YoRo_Variable_Product::CreateAction);
    $assert(!is_wp_error($preview)&&YoRo_Variable_Product::context($parent)['children_count']===0,'Preview cannot create a variation.');
    $fenced=YoRo_Early_Access_Commerce_Write::execute($operation,static function(){return false;});
    $assert(is_wp_error($fenced)&&YoRo_Variable_Product::context($parent)['children_count']===0,'Final lease fence prevents creation.');
    $created=YoRo_Early_Access_Commerce_Write::execute($operation);
    $assert(!is_wp_error($created),'Creation executes: '.(is_wp_error($created)?$created->get_error_message():wp_json_encode($created)));
    $variationId=$created['data']['variation_id'];$variation=wc_get_product($variationId);
    $assert($variation->get_parent_id()===$parent->get_id()&&$variation->get_attributes()['size']==='Small'&&$variation->get_regular_price()==='25','Creation targets the parent and exact option/price.');
    $assert($variation->get_description('edit')==='' && (int)$variation->get_image_id('edit')===0 && $variation->get_gallery_image_ids('edit')===[]
        && wc_get_product($parent->get_id())->get_description('edit')===str_repeat('Parent content ',310),'New variations use their own empty content/media defaults even when parent source is truncated; omitted parent content remains unchanged.');
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($operation)),'Stale create cannot execute twice.');
    $duplicate=$make(YoRo_Variable_Product::CreateAction,['attributes'=>[['name'=>'Size','option'=>'Small']],'regular_price'=>'30'],$parent);
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($duplicate)),'A fresh preview cannot create an existing combination.');
    $defaults=$make(YoRo_Variable_Product::AttributeAction,['defaults'=>[['name'=>'Size','option'=>'Small']]],$parent);
    $updated=YoRo_Early_Access_Commerce_Write::execute($defaults);
    $assert(!is_wp_error($updated)&&wc_get_product($parent->get_id())->get_default_attributes()===['size'=>'Small'],'Defaults execute through canonical confirmation.');
    $attrs=YoRo_Variable_Product::context(wc_get_product($parent->get_id()))['attributes'];$attrs[0]['options']=['Medium','Large'];
    $remove=$make(YoRo_Variable_Product::AttributeAction,['attributes'=>$attrs,'defaults'=>[]],$parent);
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($remove)),'Used option cannot be removed.');
    foreach(['Small|Large','A &amp; B'] as $unstable){
        $attrs=YoRo_Variable_Product::context(wc_get_product($parent->get_id()))['attributes'];$attrs[0]['options'][]=$unstable;
        $version=YoRo_Variable_Product::context(wc_get_product($parent->get_id()))['version'];
        $unsafe=$make(YoRo_Variable_Product::AttributeAction,['attributes'=>$attrs],$parent);
        $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($unsafe))&&YoRo_Variable_Product::context(wc_get_product($parent->get_id()))['version']===$version,'Unstable custom values are refused before save.');
    }
    $parent=wc_get_product($parent->get_id());$parent->set_manage_stock(true);$parent->set_stock_quantity(20);$parent->save();
    $inherited=$make(YoRo_Variable_Product::CreateAction,['attributes'=>[['name'=>'Size','option'=>'Medium']],'regular_price'=>'28'],$parent);
    $inheritedResult=YoRo_Early_Access_Commerce_Write::execute($inherited);
    $assert(!is_wp_error($inheritedResult),'Inherited-stock creation executes.');
    $assert(wc_get_product($inheritedResult['data']['variation_id'])->get_manage_stock()==='parent'&&wc_get_product($parent->get_id())->get_stock_quantity()===20,'Creating an inherited variation preserves parent stock.');
    $read=YoRo_Variable_Product::execute(['action'=>YoRo_Variable_Product::ReadAction,'params'=>['product_id'=>$parent->get_id(),'page'=>1,'limit'=>1],'workflow_id'=>'fixture','operation_id'=>'read']);
    $assert(!is_wp_error($read)&&$read['data']['count']===1&&$read['data']['page_info']['total']===2&&$read['data']['page_info']['has_more']===true,'Variation read is parent-scoped and paginated.');
    $second=YoRo_Variable_Product::execute(['action'=>YoRo_Variable_Product::ReadAction,'params'=>['product_id'=>$parent->get_id(),'page'=>2,'limit'=>1]]);
    $assert(!is_wp_error($second)&&$second['data']['variations'][0]['id']!==$read['data']['variations'][0]['id'],'Second page returns a distinct variation.');
    $foreign=new WC_Product_Variable();$foreign->set_name('Foreign variable fixture');$foreign->set_status('publish');$foreign->set_attributes([$attribute]);$foreign->save();
    $prefixed=new WC_Product_Attribute();$prefixed->set_name('attribute_size');$prefixed->set_options(['One']);$prefixed->set_variation(true);
    $prefixParent=new WC_Product_Variable();$prefixParent->set_name('Prefixed custom axis');$prefixParent->set_status('publish');$prefixParent->set_attributes([$prefixed]);$prefixParent->save();
    $prefixCreate=$make(YoRo_Variable_Product::CreateAction,['attributes'=>[['name'=>'attribute_size','option'=>'One']],'regular_price'=>'7'],$prefixParent);
    $prefixResult=YoRo_Early_Access_Commerce_Write::execute($prefixCreate);
    $assert(!is_wp_error($prefixResult)&&wc_get_product($prefixResult['data']['variation_id'])->get_attributes()===['attribute_size'=>'One'],'The Woo setter prefix preserves the exact parent axis.');
    $foreignVariation=new WC_Product_Variation();$foreignVariation->set_parent_id($foreign->get_id());$foreignVariation->set_status('publish');$foreignVariation->set_regular_price('99');$foreignVariation->set_attributes(['size'=>'Small']);$foreignVariation->save();
    $scoped=YoRo_Variable_Product::execute(['action'=>YoRo_Variable_Product::ReadAction,'params'=>['product_id'=>$parent->get_id(),'page'=>1,'limit'=>20]]);
    $assert(!is_wp_error($scoped)&&$scoped['data']['page_info']['total']===2&&!in_array($foreignVariation->get_id(),array_column($scoped['data']['variations'],'id'),true),'A variation page cannot include another parent.');
    $stale=$make(YoRo_Variable_Product::CreateAction,['attributes'=>[['name'=>'Size','option'=>'Large']],'regular_price'=>'40'],$parent);
    $variation=wc_get_product($variationId);$variation->set_attributes(['size'=>'Large']);$variation->save();
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($stale)),'A child attribute edit invalidates the management preview even if parent edit fields are unchanged.');
    $taxonomyName='yrv_'.strtolower(wp_generate_password(6,false,false));$taxonomy='pa_'.$taxonomyName;
    $taxonomyId=wc_create_attribute(['name'=>'Fixture color','slug'=>$taxonomyName,'type'=>'select','order_by'=>'name']);
    $assert(!is_wp_error($taxonomyId),'A disposable global taxonomy is available.');register_taxonomy($taxonomy,['product']);
    $red=wp_insert_term('Red',$taxonomy);$blue=wp_insert_term('Blue',$taxonomy);$green=wp_insert_term('Green',$taxonomy);
    $globalAttribute=new WC_Product_Attribute();$globalAttribute->set_id($taxonomyId);$globalAttribute->set_name($taxonomy);$globalAttribute->set_options([$red['term_id'],$blue['term_id']]);$globalAttribute->set_variation(true);$globalAttribute->set_visible(true);
    $globalParent=new WC_Product_Variable();$globalParent->set_name('Disposable global parent');$globalParent->set_status('publish');$globalParent->set_attributes([$globalAttribute]);$globalParent->save();
    $globalCreate=$make(YoRo_Variable_Product::CreateAction,['attributes'=>[['name'=>$taxonomy,'option'=>'Blue']],'regular_price'=>'9'],$globalParent);
    $globalResult=YoRo_Early_Access_Commerce_Write::execute($globalCreate);
    $assert(!is_wp_error($globalResult)&&wc_get_product($globalResult['data']['variation_id'])->get_attributes()[$taxonomy]==='blue','Global choices resolve exact existing names to stored term slugs.');
    $globalFacts=YoRo_Variable_Product::context(wc_get_product($globalParent->get_id()));$globalAttrs=$globalFacts['attributes'];$globalAttrs[0]['options'][]='Green';
    $globalUpdate=$make(YoRo_Variable_Product::AttributeAction,['attributes'=>$globalAttrs,'defaults'=>[['name'=>$taxonomy,'option'=>'Green']]],$globalParent);
    $globalUpdated=YoRo_Early_Access_Commerce_Write::execute($globalUpdate);
    $assert(!is_wp_error($globalUpdated),'Existing global options/defaults update: '.(is_wp_error($globalUpdated)?$globalUpdated->get_error_code():''));
    $assert(wc_get_product($globalParent->get_id())->get_default_attributes()[$taxonomy]==='green','Global defaults store the existing term slug.');
    $unknown=$make(YoRo_Variable_Product::CreateAction,['attributes'=>[['name'=>$taxonomy,'option'=>'Not an existing term']],'regular_price'=>'9'],$globalParent);
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($unknown))&&!get_term_by('name','Not an existing term',$taxonomy),'Management never creates a global term implicitly.');
    $wild=new WC_Product_Variation();$wild->set_parent_id($globalParent->get_id());$wild->set_status('publish');$wild->set_regular_price('10');$wild->set_attributes([$taxonomy=>'']);$wild->save();
    $overlap=$make(YoRo_Variable_Product::CreateAction,['attributes'=>[['name'=>$taxonomy,'option'=>'Red']],'regular_price'=>'11'],$globalParent);
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($overlap)),'A wildcard variant blocks overlapping specific combinations.');
    $denied=static function($caps,$cap,$user,$args)use($parent){return $cap==='edit_post'&&($args[0]??null)===$parent->get_id()?['do_not_allow']:$caps;};
    add_filter('map_meta_cap',$denied,10,4);$deniedRead=YoRo_Variable_Product::execute(['action'=>YoRo_Variable_Product::ReadAction,'params'=>['product_id'=>$parent->get_id(),'page'=>1,'limit'=>20]]);remove_filter('map_meta_cap',$denied,10);
    $assert(is_wp_error($deniedRead),'Current product-edit permission is required for management metadata.');
    $createVersion=YoRo_Product_Lifecycle::context()['target']['version'];
    $newDraft=['action'=>'products.create','params'=>['product_id'=>0,'changes'=>['name'=>'Disposable lifecycle variable draft','type'=>'variable'],'expected_version'=>$createVersion],
        'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1','authorization'=>['execution_permit'=>'disposable-test-permit'],
        'extensions'=>['yoro.mcp_early_access_commerce_write'=>['source_operation_id'=>'envelope_'.str_repeat('a',64),'source_result_hash'=>str_repeat('b',64),'expected_version'=>$createVersion]]];
    $newDraftResult=YoRo_Early_Access_Commerce_Write::execute($newDraft);
    $assert(!is_wp_error($newDraftResult),'Lifecycle creates a draft variable parent through the actual confirmed wrapper.');$draft=wc_get_product($newDraftResult['data']['product_id']);
    $draftRead=['action'=>YoRo_Variable_Product::ReadAction,'params'=>['product_id'=>$draft->get_id(),'page'=>1,'limit'=>20],
        'extensions'=>['yoro.mcp_early_access_product_read'=>['product_lifecycle_version'=>1]]];
    $draftSnapshot=YoRo_Variable_Product::execute($draftRead);
    $assert(!is_wp_error($draftSnapshot)&&$draftSnapshot['data']['products'][0]['status']==='draft'&&$draftSnapshot['data']['variable']['attributes']===[]&&isset($draftSnapshot['data']['products'][0]['extensions']['woocommerce.product_lifecycle']),
        'An exact negotiated draft read exposes editable context without publishing the parent.');
    $legacyDraft=$draftRead;unset($legacyDraft['extensions']);$assert(is_wp_error(YoRo_Variable_Product::execute($legacyDraft)),'A legacy read cannot expose draft parent context.');
    $wrongFlag=$draftRead;$wrongFlag['extensions']['yoro.mcp_early_access_product_read']['product_lifecycle_version']='1';$assert(is_wp_error(YoRo_Variable_Product::execute($wrongFlag)),'Draft read negotiation uses an exact integer version.');
    $legacyPublished=YoRo_Variable_Product::execute(['action'=>YoRo_Variable_Product::ReadAction,'params'=>['product_id'=>$parent->get_id(),'page'=>1,'limit'=>20]]);
    $assert(!is_wp_error($legacyPublished)&&$legacyPublished['data']['products'][0]['status']==='publish'&&!isset($legacyPublished['data']['products'][0]['extensions']['woocommerce.product_lifecycle']),'Legacy published reads preserve their projection and executor path.');
    $draftAttributes=$make(YoRo_Variable_Product::AttributeAction,['attributes'=>[['name'=>'Size','options'=>['Small','Large'],'visible'=>true,'variation'=>true]],'defaults'=>[['name'=>'Size','option'=>'Small']]],$draft);
    $draftPreview=YoRo_Variable_Product::preview($draftAttributes['params'],YoRo_Variable_Product::AttributeAction);
    $assert(!is_wp_error($draftPreview)&&wc_get_product($draft->get_id())->get_attributes()===[],'Draft attribute preview remains non-mutating.');
    $draftSaved=YoRo_Early_Access_Commerce_Write::execute($draftAttributes);$assert(!is_wp_error($draftSaved)&&wc_get_product($draft->get_id())->get_status()==='draft'&&wc_get_product($draft->get_id())->get_default_attributes()===['size'=>'Small'],'Draft parent attributes and defaults save without publishing.');
    $draftVariation=$make(YoRo_Variable_Product::CreateAction,['attributes'=>[['name'=>'Size','option'=>'Small']],'regular_price'=>'19.50'],$draft);
    $draftFence=YoRo_Early_Access_Commerce_Write::execute($draftVariation,static fn()=>false);$assert(is_wp_error($draftFence)&&YoRo_Variable_Product::context(wc_get_product($draft->get_id()))['children_count']===0,'Draft variation creation retains the final execution fence.');
    $draftCreated=YoRo_Early_Access_Commerce_Write::execute($draftVariation);$assert(!is_wp_error($draftCreated),'A variation can be created inside the authorized draft parent. '.(is_wp_error($draftCreated)?$draftCreated->get_error_message():''));
    $draftChild=wc_get_product($draftCreated['data']['variation_id']);$assert($draftChild->get_parent_id()===$draft->get_id()&&$draftChild->get_status()==='publish'&&wc_get_product($draft->get_id())->get_status()==='draft','Saving a published child preserves the draft storefront parent.');
    $draftSnapshot=YoRo_Variable_Product::execute($draftRead);$assert(!is_wp_error($draftSnapshot)&&$draftSnapshot['data']['count']===1&&$draftSnapshot['data']['variations'][0]['id']===$draftChild->get_id(),'Negotiated management reads include children belonging to the draft parent.');
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($draftVariation)),'Draft variation source versions prevent replay.');
    $denyDraft=static function($caps,$cap,$user,$args)use($draft){return $cap==='edit_post'&&($args[0]??null)===$draft->get_id()?['do_not_allow']:$caps;};add_filter('map_meta_cap',$denyDraft,PHP_INT_MAX,4);
    $assert(is_wp_error(YoRo_Variable_Product::execute($draftRead))&&is_wp_error(YoRo_Variable_Product::preview($draftVariation['params'],YoRo_Variable_Product::CreateAction)),'Draft reads and writes require the exact parent edit capability.');remove_filter('map_meta_cap',$denyDraft,PHP_INT_MAX);
    $denyExecution=static fn($caps)=>array_replace($caps,['yoro_execute_actions'=>false]);add_filter('user_has_cap',$denyExecution,PHP_INT_MAX);
    $assert(is_wp_error(YoRo_Variable_Product::execute($draftRead)),'A user without the YoRo execution capability cannot access draft management metadata.');remove_filter('user_has_cap',$denyExecution,PHP_INT_MAX);
    foreach(['private','trash'] as $hiddenStatus){$hidden=new WC_Product_Variable();$hidden->set_name('Excluded variable '.$hiddenStatus);$hidden->set_status($hiddenStatus);$hidden->save();$hiddenRead=$draftRead;$hiddenRead['params']['product_id']=$hidden->get_id();$assert(is_wp_error(YoRo_Variable_Product::execute($hiddenRead)),'Lifecycle negotiation never exposes private or trashed variable parents.');}
    wp_set_current_user(0);$assert(is_wp_error(YoRo_Variable_Product::execute($draftRead)),'Unauthenticated draft management is denied.');wp_set_current_user((int)$admins[0]);
    $unknownSku='yr0178-unknown-'.$parent->get_id();$simulated=false;
    $postSave=static function($product)use($unknownSku,&$simulated){
        if(!$simulated&&$product instanceof WC_Product_Variation&&$product->get_sku()===$unknownSku){$simulated=true;global $wpdb;$wpdb->update($wpdb->posts,['post_status'=>'private'],['ID'=>$product->get_id()]);clean_post_cache($product->get_id());}
    };
    $unknownOperation=$make(YoRo_Variable_Product::CreateAction,['attributes'=>[['name'=>'Size','option'=>'Small']],'regular_price'=>'15','sku'=>$unknownSku],$parent);
    add_action('woocommerce_after_product_object_save',$postSave,10,1);
    try{$uncertain=YoRo_Early_Access_Commerce_Write::execute($unknownOperation);}finally{remove_action('woocommerce_after_product_object_save',$postSave,10);}
    $assert($simulated&&is_wp_error($uncertain)&&$uncertain->get_error_code()==='local_execution_outcome_unknown','Post-save verification failures remain unknown through canonical executor normalization.');
    $count=YoRo_Variable_Product::context(wc_get_product($parent->get_id()))['children_count'];
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($unknownOperation))&&YoRo_Variable_Product::context(wc_get_product($parent->get_id()))['children_count']===$count,'An uncertain created variation cannot be blindly created a second time.');
    if(getenv('YORO_VARIABLE_FIXTURE_EXPORT')==='/tmp/yr0178-variable-product-output.json'){
        file_put_contents('/tmp/yr0178-variable-product-output.json',wp_json_encode(['read'=>$read,'created'=>$created,'attributes'=>$updated],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    $manifest=YoRo_Commerce_Contract_Builder::build_capability_manifest(['connector_key'=>'woocommerce','connector_connection_id'=>'yr0178_variable_fixture','execution_mode'=>'remote_agent','protocol_version'=>1],1);
    $assert($manifest['extensions'][YoRo_Variable_Product::Extension]===YoRo_Variable_Product::manifest(),'Variable capabilities are separately negotiated.');
    echo "YoRo variable-product creation, global/custom options, defaults, parent stock, paging, scope, permission, duplicate/version/fence and unknown-outcome contracts passed.\n";
} finally {
    $wpdb->query('ROLLBACK');wp_set_current_user($original);remove_filter('pre_wp_mail','__return_true');wp_cache_flush();
}
