<?php
/** Disposable WooCommerce fixtures; no installation, feature flags or external requests. */
if(!defined('ABSPATH')||!class_exists('WooCommerce')||str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')) {throw new RuntimeException('Disposable WooCommerce required.');}
if(!class_exists('YoRo_Commerce_Discovery_Service')) {require_once dirname(__DIR__,2).'/yoro-ai-core.php';do_action('init');}
if(!class_exists('YoRo_Product_Lifecycle')) {require_once dirname(__DIR__,2).'/includes/actions/class-yoro-product-lifecycle.php';}
$assert=static function($ok,$message){if(!$ok){throw new RuntimeException($message);}};
$failed=static fn($result)=>is_wp_error($result)||(is_array($result)&&empty($result['success']));
$original=get_current_user_id();$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);global $wpdb;$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int)$admins[0]);YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    YoRo_Product_Lifecycle::register();
    $operation=static fn($action,$params)=>['action'=>$action,'params'=>$params,'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1','authorization'=>['execution_permit'=>'disposable'],
        'extensions'=>['yoro.mcp_early_access_commerce_write'=>['source_operation_id'=>'envelope_'.str_repeat('a',64),'source_result_hash'=>str_repeat('b',64),'expected_version'=>$params['expected_version']]]];
    $execute=static fn($action,$params)=>YoRo_Early_Access_Commerce_Write::execute($operation($action,$params));
    $contextParams=static fn($changes)=>['product_id'=>0,'changes'=>$changes,'expected_version'=>YoRo_Product_Lifecycle::context()['target']['version']];
    $params=static fn($id,$changes)=>['product_id'=>$id,'changes'=>$changes,'expected_version'=>YoRo_Commerce_Discovery_Service::product_version(wc_get_product($id))];
    $read=YoRo_Product_Lifecycle::execute_read(['action'=>'products.get_creation_context','params'=>[],'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1']);
    $assert(!$failed($read)&&$read['data']['target']['id']===0&&$read['data']['product_types']===['simple','variable']&&(array)$read['extensions']===[],'Creation context uses canonical empty extensions and no dummy product. '.wp_json_encode($read));
    $assert(YoRo_Product_Lifecycle::context()['target']['version']===$read['data']['target']['version'],'Repeated unchanged contexts have one source version.');
    $cacheGeneration=wp_cache_get_last_changed('terms');usleep(1000);wp_cache_delete('last_changed','terms');$afterReset=YoRo_Product_Lifecycle::context();
    $assert(wp_cache_get_last_changed('terms')!==$cacheGeneration&&$afterReset===$read['data'],'Resetting ephemeral term cache generation leaves the complete semantic creation context and version unchanged.');
    wp_cache_flush();$assert(YoRo_Product_Lifecycle::context()===$read['data'],'A full nonpersistent cache reset cannot invalidate an unchanged creation source.');
    $assert(is_wp_error(YoRo_Product_Lifecycle::read_context(['unexpected'=>true])),'Context rejects unknown selectors.');
    $new=$contextParams(['name'=>'Lifecycle simple draft','type'=>'simple']);$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'");
    wp_cache_delete('last_changed','terms');
    $preview=YoRo_Product_Lifecycle::preview($new,'products.create');$assert(!$failed($preview)&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'")===$before,'Creation preview never inserts product rows.');
    $assert(is_wp_error(YoRo_Product_Lifecycle::write($new,'products.create')),'Unconfirmed callbacks reject creation.');
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($operation('products.create',$new),static fn()=>false)),'A lost execution fence rejects creation.');
    wp_cache_delete('last_changed','terms');
    $saved=$execute('products.create',$new);$assert(!$failed($saved),'Create simple draft through the actual confirmed executor. '.wp_json_encode($saved));
    $simpleId=$saved['data']['product_id'];$simple=wc_get_product($simpleId);
    $assert($simple->is_type('simple')&&$simple->get_status()==='draft'&&$simple->get_sku()===''&&$simple->get_global_unique_id()===''&&$saved['data']['source_product_id']===0&&(array)$saved['extensions']===[],'New simple product is an identifier-free draft with a canonical result.');
    $saved=$execute('products.create',$contextParams(['name'=>'Lifecycle variable parent','type'=>'variable']));$assert(!$failed($saved),'Create a variable draft parent. '.wp_json_encode($saved));$parentId=$saved['data']['product_id'];
    $assert(wc_get_product($parentId)->is_type('variable')&&wc_get_product($parentId)->get_children()===[],'New variable parent has no child variations.');
    foreach([['name'=>'Bad','type'=>'external'],['name'=>'Bad','type'=>'simple','status'=>'publish'],['name'=>'<b>HTML</b>','type'=>'simple'],['name'=>str_repeat('é',128),'type'=>'simple'],['type'=>'simple'],['name'=>'Bad','type'=>'simple','meta_data'=>[]]] as $bad){$assert(is_wp_error(YoRo_Product_Lifecycle::preview($contextParams($bad),'products.create')),'Creation rejects unsupported, unbounded and arbitrary data.');}
    $contextBefore=YoRo_Product_Lifecycle::context()['target']['version'];
    $shipping=wp_insert_term('Lifecycle shipping '.wp_generate_uuid4(),'product_shipping_class');$assert(!is_wp_error($shipping),'Create a disposable existing shipping class.');$shippingId=(int)$shipping['term_id'];
    $assert($contextBefore!==YoRo_Product_Lifecycle::context()['target']['version'],'A real shipping-class insertion changes the durable creation context.');
    $staleShipping=$contextParams(['name'=>'Stale shipping context','type'=>'simple']);$renamed=wp_update_term($shippingId,'product_shipping_class',['name'=>'Lifecycle renamed shipping '.wp_generate_uuid4()]);
    $assert(!is_wp_error($renamed)&&$staleShipping['expected_version']!==YoRo_Product_Lifecycle::context()['target']['version']&&is_wp_error(YoRo_Product_Lifecycle::prepare($staleShipping,'products.create')),'Changing an existing shipping-class display value invalidates the old context without relying on cache timestamps.');
    $temporary=wp_insert_term('Lifecycle deleted shipping '.wp_generate_uuid4(),'product_shipping_class');$assert(!is_wp_error($temporary),'Create a disposable deletion fixture.');$contextBefore=YoRo_Product_Lifecycle::context()['target']['version'];
    $assert(wp_delete_term((int)$temporary['term_id'],'product_shipping_class')===true&&$contextBefore!==YoRo_Product_Lifecycle::context()['target']['version'],'Removing a shipping class changes the durable creation context.');
    $other=new WC_Product_Simple();$other->set_name('Lifecycle linked product');$other->set_status('publish');$other->save();$otherId=$other->get_id();
    $patch=$params($simpleId,['status'=>'publish','featured'=>true,'catalog_visibility'=>'catalog','weight'=>'1.250000','length'=>'2','width'=>'3.125','height'=>'4','shipping_class_id'=>$shippingId,'upsell_ids'=>[$otherId],'cross_sell_ids'=>[$parentId]]);
    $oldVersion=$patch['expected_version'];$preview=YoRo_Product_Lifecycle::preview($patch,'products.update_lifecycle');$assert(!$failed($preview)&&wc_get_product($simpleId)->get_status()==='draft','Draft lifecycle preview does not publish.');
    $saved=$execute('products.update_lifecycle',$patch);$assert(!$failed($saved),'Publish and edit bounded shipping/visibility/linked fields. '.wp_json_encode($saved));
    $simple=wc_get_product($simpleId);$assert($simple->get_status()==='publish'&&$simple->get_featured()&&$simple->get_weight()==='1.25'&&$simple->get_shipping_class_id()===$shippingId&&$simple->get_upsell_ids()===[$otherId],'Real WC CRUD fields persist exact normalized settings.');
    $assert($oldVersion!==YoRo_Commerce_Discovery_Service::product_version($simple),'Version hashes include changed lifecycle fields.');
    $assert($failed($execute('products.update_lifecycle',$patch)),'Stale version cannot replay lifecycle changes.');
    $assert(is_wp_error(YoRo_Product_Lifecycle::preview($params($simpleId,['weight'=>'1.25']),'products.update_lifecycle')),'No-op lifecycle changes reject.');
    $sameSecond=$params($simpleId,['height'=>'8']);$stamp=$simple->get_date_modified('edit');$simple->set_width('7');$simple->set_date_modified($stamp);$simple->save();
    $assert($failed($execute('products.update_lifecycle',$sameSecond)),'Same-second lifecycle mutation invalidates the fresh source hash.');
    foreach([['weight'=>'-1'],['height'=>'1e3'],['length'=>1],['shipping_class_id'=>PHP_INT_MAX],['upsell_ids'=>[$simpleId]],['upsell_ids'=>[$otherId,$otherId]],['cross_sell_ids'=>array_fill(0,21,$otherId)],['status'=>'private'],['featured'=>1],['unknown'=>true]] as $bad){$assert(is_wp_error(YoRo_Product_Lifecycle::preview($params($simpleId,$bad),'products.update_lifecycle')),'Invalid lifecycle fields reject before mutation: '.wp_json_encode($bad));}
    $deny=static function($caps,$cap,$user,$args)use($simpleId){return $cap==='edit_post'&&($args[0]??null)===$simpleId?['do_not_allow']:$caps;};add_filter('map_meta_cap',$deny,PHP_INT_MAX,4);
    $assert(is_wp_error(YoRo_Product_Lifecycle::preview($params($simpleId,['featured'=>false]),'products.update_lifecycle')),'Per-product capability is checked.');remove_filter('map_meta_cap',$deny,PHP_INT_MAX);
    $denyPublish=static fn($all)=>array_replace($all,['publish_products'=>false]);add_filter('user_has_cap',$denyPublish,PHP_INT_MAX);
    $assert(is_wp_error(YoRo_Product_Lifecycle::preview($params($parentId,['status'=>'publish']),'products.update_lifecycle')),'Publish capability is mandatory.');remove_filter('user_has_cap',$denyPublish,PHP_INT_MAX);
    $simple=wc_get_product($simpleId);$simple->set_sku('lifecycle-'.wp_generate_uuid4());$simple->set_global_unique_id('1234567890123');$simple->update_meta_data('_third_party_secret','must not copy');$simple->set_description('<p>Safe persisted description</p>');$simple->set_regular_price('12.50');$simple->save();
    $saved=$execute('products.duplicate',$params($simpleId,['name'=>'Lifecycle parent-only copy']));$assert(!$failed($saved),'Duplicate via confirmed executor. '.wp_json_encode($saved));$copy=wc_get_product($saved['data']['product_id']);
    $assert($saved['data']['source_product_id']===$simpleId&&$copy->get_status()==='draft'&&$copy->get_name()==='Lifecycle parent-only copy'&&$copy->get_sku()===''&&$copy->get_global_unique_id()===''&&$copy->get_meta('_third_party_secret')===''&&$copy->get_description()==='<p>Safe persisted description</p>','Copy preserves explicit standard data, clears identifiers and excludes metadata.');
    $variation=new WC_Product_Variation();$variation->set_parent_id($parentId);$variation->set_status('publish');$variation->set_sku('variation-'.wp_generate_uuid4());$variation->set_global_unique_id('1234567890128');$variation->save();
    $saved=$execute('products.duplicate',$params($parentId,['name'=>'Lifecycle variable parent-only copy']));$assert(!$failed($saved),'Duplicate draft variable parent. '.wp_json_encode($saved));$copy=wc_get_product($saved['data']['product_id']);
    $assert($copy->is_type('variable')&&$copy->get_status()==='draft'&&$copy->get_children()===[]&&wc_get_product($variation->get_id())->get_parent_id()===$parentId,'No child or variation unique identifiers are copied or moved.');
    $attribute=static function(string $name='Size',array $options=['Small','Large'],int $id=0): WC_Product_Attribute {
        $attribute=new WC_Product_Attribute();$attribute->set_id($id);$attribute->set_name($name);$attribute->set_options($options);$attribute->set_position(0);$attribute->set_visible(true);$attribute->set_variation(true);return $attribute;
    };
    $sourceProduct=static function(bool $variable=false)use($attribute): WC_Product {
        $source=$variable?new WC_Product_Variable():new WC_Product_Simple();$source->set_name('Same-second duplicate source '.wp_generate_uuid4());$source->set_status('publish');
        $source->set_attributes([$attribute()]);$source->set_date_modified('2026-01-02T03:04:05+00:00');$source->save();return wc_get_product($source->get_id());
    };
    $mutateAttribute=static function(WC_Product $source,string $field,$value): void {
        $attributes=$source->get_attributes('edit');$key=array_key_first($attributes);$changed=clone $attributes[$key];$setter='set_'.$field;$changed->$setter($value);$attributes[$key]=$changed;$source->set_attributes($attributes);
    };
    $sameSecondDuplicate=static function(string $field,WC_Product $source,callable $mutate)use($assert,$execute,$params): WC_Product {
        $stale=$params($source->get_id(),['name'=>'Reviewed duplicate '.$field]);$stamp=clone $source->get_date_modified('edit');
        $mutated=$mutate($source);if($mutated instanceof WC_Product){$source=$mutated;}$source->save();$source=wc_get_product($source->get_id());$source->set_date_modified($stamp);$source->save();$source=wc_get_product($source->get_id());
        $assert($source->get_date_modified('edit')->getTimestamp()===$stamp->getTimestamp(),'The '.$field.' regression preserves the exact modified timestamp.');
        $assert(YoRo_Commerce_Discovery_Service::product_version($source)!==$stale['expected_version'],'The full duplicate source hash binds '.$field.' independently from modified time.');
        $preview=YoRo_Product_Lifecycle::preview($stale,'products.duplicate');
        $assert(is_wp_error($preview)&&$preview->get_error_code()==='product_version_conflict','A stale duplicate preview rejects the changed '.$field.' source.');
        global $wpdb;$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'");$rejected=$execute('products.duplicate',$stale);
        $assert(is_wp_error($rejected)&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'")===$before,'The confirmed executor creates no copy from stale '.$field.' source values.');
        $fresh=$execute('products.duplicate',$params($source->get_id(),['name'=>'Fresh duplicate '.$field]));
        $assert(!is_wp_error($fresh)&&!empty($fresh['success']),'A new explicit duplicate with fresh '.$field.' source version succeeds.');
        $copy=wc_get_product($fresh['data']['product_id']);
        $assert(YoRo_Product_Lifecycle::parent_copy_fingerprint($copy)===YoRo_Product_Lifecycle::parent_copy_fingerprint($source),'Fresh duplicate preserves every supported '.$field.' source value exactly.');
        return $copy;
    };
    foreach(['virtual'=>true,'tax_status'=>'shipping','tax_class'=>'reduced-rate','sold_individually'=>true,'purchase_note'=>'Updated fixture purchase instruction','reviews_allowed'=>false] as $field=>$value){
        $sameSecondDuplicate($field,$sourceProduct(),static function($source)use($field,$value){$setter='set_'.$field;$source->$setter($value);});
    }
    foreach(['name'=>'Dimension','options'=>['Medium','Large'],'position'=>3,'visible'=>false,'variation'=>false] as $field=>$value){
        $sameSecondDuplicate('variable attribute '.$field,$sourceProduct(true),static fn($source)=>$mutateAttribute($source,$field,$value));
    }
    $defaults=$sourceProduct(true);$defaults->set_default_attributes(['size'=>'Small']);$defaults->save();
    $sameSecondDuplicate('variable default attributes',wc_get_product($defaults->get_id()),static fn($source)=>$source->set_default_attributes(['size'=>'Large']));
    // Taxonomy attributes bind their identity and exact term IDs as well as the six standard attribute values.
    $taxonomyName='lifecycle_bound_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);$taxonomyId=wc_create_attribute(['name'=>'Lifecycle bound attribute','slug'=>$taxonomyName,'type'=>'select','order_by'=>'menu_order','has_archives'=>false]);
    $assert(!is_wp_error($taxonomyId),'Create an isolated native WC attribute taxonomy.');$taxonomy='pa_'.$taxonomyName;
    register_taxonomy($taxonomy,['product'],['public'=>false,'capabilities'=>['manage_terms'=>'manage_product_terms','edit_terms'=>'edit_product_terms','delete_terms'=>'delete_product_terms','assign_terms'=>'assign_product_terms']]);
    $term=wp_insert_term('Bound option',$taxonomy);$assert(!is_wp_error($term),'Create an existing taxonomy attribute option.');
    $sameSecondDuplicate('taxonomy attribute identity and options',$sourceProduct(true),static fn($source)=>$source->set_attributes([$attribute($taxonomy,[(int)$term['term_id']],(int)$taxonomyId)]));
    $typeSource=$sourceProduct();$sameSecondDuplicate('parent product type',$typeSource,static function($source){wp_set_object_terms($source->get_id(),'variable','product_type');clean_post_cache($source->get_id());return new WC_Product_Variable($source->get_id());});
    $metadata=$sourceProduct(true);$attributes=$metadata->get_attributes('edit');$key=array_key_first($attributes);$changed=clone $attributes[$key];$changed->set_extra_data('private_fixture_payload',['must_not_copy'=>'fixture secret']);$attributes[$key]=$changed;$metadata->set_attributes($attributes);$metadata->save();
    $metadataId=$metadata->get_id();$rehydrate=static function($attribute,$raw,$product)use($metadataId){if($product->get_id()===$metadataId&&isset($raw['private_fixture_payload'])){$attribute->set_extra_data('private_fixture_payload',$raw['private_fixture_payload']);}return $attribute;};
    add_filter('woocommerce_product_read_attribute',$rehydrate,10,3);
    $metadata=wc_get_product($metadataId);$assert($metadata->get_attributes('edit')[$key]->get_all_extra_data()!==[],'A supported plugin read hook restores persisted attribute extra data in the source.');
    $sourceVersion=YoRo_Commerce_Discovery_Service::product_version($metadata);$standard=YoRo_Product_Lifecycle::parent_copy_fingerprint($metadata);
    $attributes=$metadata->get_attributes('edit');$changed=clone $attributes[$key];$changed->set_extra_data('private_fixture_payload',['must_not_copy'=>'changed fixture secret']);$attributes[$key]=$changed;$stamp=clone $metadata->get_date_modified('edit');$metadata->set_attributes($attributes);$metadata->save();$metadata=wc_get_product($metadata->get_id());$metadata->set_date_modified($stamp);$metadata->save();$metadata=wc_get_product($metadata->get_id());
    $assert(YoRo_Product_Lifecycle::parent_copy_fingerprint($metadata)===$standard&&YoRo_Commerce_Discovery_Service::product_version($metadata)===$sourceVersion,'Excluded arbitrary attribute metadata cannot alter the targeted standard copy values.');
    $fresh=$execute('products.duplicate',$params($metadata->get_id(),['name'=>'Standard attribute-only duplicate']));$assert(!is_wp_error($fresh),'Supported standard attributes remain duplicable when arbitrary attribute extra data is present.');
    $copy=wc_get_product($fresh['data']['product_id']);$assert($copy->get_attributes('edit')[$key]->get_all_extra_data()===[]&&YoRo_Product_Lifecycle::parent_copy_fingerprint($copy)===$standard,'A draft copies only six native attribute fields and exact defaults, never arbitrary attribute extra data.');
    $copyAttributes=get_post_meta($copy->get_id(),'_product_attributes',true);$assert(!array_key_exists('private_fixture_payload',$copyAttributes[$key]),'Arbitrary attribute extra data never reaches the copied native storage.');remove_filter('woocommerce_product_read_attribute',$rehydrate,10);
    $downloadable=$sourceProduct();$downloadStale=$params($downloadable->get_id(),['name'=>'Unsupported downloadable copy']);$downloadable->set_downloadable(true);$downloadable->save();
    $assert(YoRo_Commerce_Discovery_Service::product_version(wc_get_product($downloadable->get_id()))!==$downloadStale['expected_version']&&is_wp_error(YoRo_Product_Lifecycle::preview($downloadStale,'products.duplicate')),'Downloadable source changes invalidate old versions.');
    $assert(!YoRo_Product_Lifecycle::details(wc_get_product($downloadable->get_id()))['duplicate_supported']&&is_wp_error(YoRo_Product_Lifecycle::preview($params($downloadable->get_id(),['name'=>'Still unsupported']),'products.duplicate')),'A fresh version never enables unsupported downloadable copies.');
    $categoryName='Lifecycle category '.wp_generate_uuid4();$source=$contextParams(['name'=>$categoryName]);$saved=$execute('products.create_category',$source);$assert(!$failed($saved),'Create exact bounded category. '.wp_json_encode($saved));$categoryId=$saved['data']['term_id'];
    $assert($saved['data']===['term_id'=>$categoryId,'taxonomy'=>'product_cat','name'=>$categoryName,'parent_id'=>0],'Term result contains exactly stored taxonomy fields.');
    $assert($failed($execute('products.create_category',$source)),'Taxonomy generation invalidates old creation context.');
    $assert(is_wp_error(YoRo_Product_Lifecycle::preview($contextParams(['name'=>$categoryName]),'products.create_category')),'Existing category names cannot be recreated.');
    $saved=$execute('products.create_category',$contextParams(['name'=>'Lifecycle child '.wp_generate_uuid4(),'parent_id'=>$categoryId]));$assert(!$failed($saved)&&$saved['data']['parent_id']===$categoryId,'Existing category parent is honored.');
    $tagName='Lifecycle tag '.wp_generate_uuid4();$saved=$execute('products.create_tag',$contextParams(['name'=>$tagName]));$assert(!$failed($saved)&&$saved['data']['taxonomy']==='product_tag'&&$saved['data']['parent_id']===0,'Tag creation uses exact taxonomy and no parent.');
    foreach([['name'=>'Invalid parent','parent_id'=>PHP_INT_MAX],['name'=>'<b>HTML</b>'],['name'=>str_repeat('é',101)]] as $bad){$assert(is_wp_error(YoRo_Product_Lifecycle::preview($contextParams($bad),'products.create_category')),'Invalid category data rejects.');}
    $denyTerms=static fn($all)=>array_replace($all,['manage_product_terms'=>false]);add_filter('user_has_cap',$denyTerms,PHP_INT_MAX);
    $assert(is_wp_error(YoRo_Product_Lifecycle::preview($contextParams(['name'=>'No term capability']),'products.create_tag')),'Taxonomy management capability is mandatory.');remove_filter('user_has_cap',$denyTerms,PHP_INT_MAX);
    $contextBefore=YoRo_Product_Lifecycle::context()['target']['version'];$unit=get_option('woocommerce_weight_unit');update_option('woocommerce_weight_unit',$unit==='kg'?'g':'kg');
    $assert($contextBefore!==YoRo_Product_Lifecycle::context()['target']['version'],'Store units invalidate creation source version.');update_option('woocommerce_weight_unit',$unit);
    foreach(['woocommerce_dimension_unit'=>['cm','m'],'woocommerce_manage_stock'=>['yes','no']] as $option=>$values){
        $beforeSetting=YoRo_Product_Lifecycle::context()['target']['version'];$old=get_option($option);update_option($option,$old===$values[0]?$values[1]:$values[0]);
        $assert($beforeSetting!==YoRo_Product_Lifecycle::context()['target']['version'],'Actual '.$option.' settings changes invalidate the creation context.');update_option($option,$old);
        $assert($beforeSetting===YoRo_Product_Lifecycle::context()['target']['version'],'Restoring the same '.$option.' data restores the semantic version despite cache churn.');
    }
    $staleCaps=$contextParams(['name'=>'Capability-bound creation','type'=>'simple']);$contextCaps=static fn($all)=>array_replace($all,['publish_products'=>false]);add_filter('user_has_cap',$contextCaps,PHP_INT_MAX);
    try{$assert($staleCaps['expected_version']!==YoRo_Product_Lifecycle::context()['target']['version']&&is_wp_error(YoRo_Product_Lifecycle::prepare($staleCaps,'products.create')),'Actual actor capability changes invalidate the old creation source.');}
    finally{remove_filter('user_has_cap',$contextCaps,PHP_INT_MAX);}
    $assert($staleCaps['expected_version']===YoRo_Product_Lifecycle::context()['target']['version'],'Restoring the same actor capabilities restores the semantic context version.');
    $assert(YoRo_Product_Lifecycle::manifest()['duplicates_children']===false&&YoRo_Product_Lifecycle::manifest()['draft_reads']===true,'Manifest declares parent-only copies and authorized draft reads.');
    $overflow=wc_get_product($simpleId);$overflow->set_upsell_ids(range(100000,100020));$overflow->save();$details=YoRo_Product_Lifecycle::details(wc_get_product($simpleId));
    $assert(count($details['fields']['upsell_ids'])===20&&in_array('upsell_ids',$details['truncated_fields'],true)&&!in_array('upsell_ids',$details['editable_fields'],true)&&!$details['duplicate_supported'],'Oversized links disclose bounded source coverage and cannot authorize overwrite or copy.');
    $assert(is_wp_error(YoRo_Product_Lifecycle::preview($params($simpleId,['upsell_ids'=>[]]),'products.update_lifecycle')),'A truncated existing link list cannot authorize destructive replacement.');
    for($n=0;$n<101;++$n){$term=wp_insert_term(str_repeat('é',100).' '.$n.' '.wp_generate_uuid4(),'product_shipping_class');$assert(!is_wp_error($term),'Create bounded shipping-class fixtures.');}
    $bounded=YoRo_Product_Lifecycle::context();$assert(count($bounded['shipping_classes'])===100&&$bounded['shipping_classes_truncated']&&strlen(wp_json_encode($bounded))<=49152,'Shipping class rows and escaped Unicode fit the existing wire budget and explicitly disclose limited coverage.');
    wp_set_current_user(0);$assert(is_wp_error(YoRo_Product_Lifecycle::read_context([])),'Unauthenticated creation context is denied.');
    echo "Product lifecycle canonical reads, draft create/copy, exact fields, permissions, same-second versions, taxonomy creation and confirmed execution passed.\n";
}finally{if(isset($rehydrate)){remove_filter('woocommerce_product_read_attribute',$rehydrate,10);}$wpdb->query('ROLLBACK');wp_cache_flush();wp_set_current_user($original);}
