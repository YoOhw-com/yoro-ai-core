<?php

if (!defined('ABSPATH')) { exit; }

/** Explicit WooCommerce fields only; new products and parent-only copies are drafts. */
final class YoRo_Product_Lifecycle
{
    public const ReadActions = ['products.get_creation_context'];
    public const WriteActions = ['products.create','products.duplicate','products.update_lifecycle','products.create_category','products.create_tag'];
    public const ManifestExtension = 'yoro.early_access_product_lifecycle';
    public const Fields = ['status','featured','catalog_visibility','weight','length','width','height','shipping_class_id','upsell_ids','cross_sell_ids'];
    private const ParentCopyFields = ['description','short_description','virtual','downloadable','regular_price','sale_price','date_on_sale_from','date_on_sale_to','tax_status','tax_class','manage_stock','stock_quantity','stock_status','backorders','low_stock_amount','sold_individually','weight','length','width','height','shipping_class_id','upsell_ids','cross_sell_ids','category_ids','tag_ids','image_id','gallery_image_ids','purchase_note','reviews_allowed','catalog_visibility','featured','attributes','default_attributes'];
    private const AttributeFields = ['id','name','options','position','visible','variation'];

    public static function manifest(): array
    {
        return ['version'=>1,'actions'=>[...self::ReadActions,...self::WriteActions],'max_items'=>100,'max_targets'=>1,
            'requires_source_read'=>true,'requires_confirmation'=>true,'result_version'=>1,'duplicates_children'=>false,'draft_reads'=>true];
    }

    private static function object(array $properties,?array $required=null): array
    { return ['type'=>'object','properties'=>$properties,'required'=>$required??array_keys($properties),'additionalProperties'=>false]; }

    public static function properties(string $action): array
    {
        if($action==='products.create') { return ['name'=>['type'=>'string','minLength'=>1,'maxLength'=>255],'type'=>['type'=>'string','enum'=>['simple','variable']]]; }
        if($action==='products.duplicate') { return ['name'=>['type'=>'string','minLength'=>1,'maxLength'=>255]]; }
        if(in_array($action,['products.create_category','products.create_tag'],true)) {
            return ['name'=>['type'=>'string','minLength'=>1,'maxLength'=>200],...($action==='products.create_category'?['parent_id'=>['type'=>'integer','minimum'=>0]]:[])];
        }
        if($action!=='products.update_lifecycle') { return []; }
        $decimal=['type'=>'string','pattern'=>'^(?:|(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?)$'];
        $ids=['type'=>'array','maxItems'=>20,'uniqueItems'=>true,'items'=>['type'=>'integer','minimum'=>1]];
        return ['status'=>['type'=>'string','enum'=>['draft','publish']],'featured'=>['type'=>'boolean'],
            'catalog_visibility'=>['type'=>'string','enum'=>['visible','catalog','search','hidden']],
            'weight'=>$decimal,'length'=>$decimal,'width'=>$decimal,'height'=>$decimal,'shipping_class_id'=>['type'=>'integer','minimum'=>0],
            'upsell_ids'=>$ids,'cross_sell_ids'=>$ids];
    }

    public static function input_properties(string $action): array
    {
        if($action===self::ReadActions[0]) { return []; }
        $zero=in_array($action,['products.create','products.create_category','products.create_tag'],true);
        $required=match($action){'products.create'=>['name','type'],'products.duplicate','products.create_category','products.create_tag'=>['name'],'products.update_lifecycle'=>[],default=>self::invalid()};
        return ['product_id'=>['type'=>'integer','minimum'=>$zero?0:1,...($zero?['maximum'=>0]:[])],
            'changes'=>self::object(self::properties($action),$required),'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']];
    }

    public static function result_schema(string $action): array
    {
        $version=['type'=>'string','pattern'=>'^[a-f0-9]{64}$'];$positive=['type'=>'integer','minimum'=>1];
        if($action===self::ReadActions[0]) {
            return self::object(['target'=>self::object(['id'=>['type'=>'integer','enum'=>[0]],'version'=>$version]),
                'product_types'=>['type'=>'array','minItems'=>2,'maxItems'=>2,'uniqueItems'=>true,'items'=>['type'=>'string','enum'=>['simple','variable']]],
                'weight_unit'=>['type'=>'string','maxLength'=>20],'dimension_unit'=>['type'=>'string','maxLength'=>20],
                'shipping_classes'=>['type'=>'array','maxItems'=>100,'items'=>self::object(['id'=>$positive,'name'=>['type'=>'string','maxLength'=>200]])],
                'shipping_classes_truncated'=>['type'=>'boolean']]);
        }
        if(in_array($action,['products.create_category','products.create_tag'],true)) {
            return self::object(['term_id'=>$positive,'taxonomy'=>['type'=>'string','enum'=>[$action==='products.create_category'?'product_cat':'product_tag']],
                'name'=>['type'=>'string','minLength'=>1,'maxLength'=>200],'parent_id'=>['type'=>'integer','minimum'=>0]]);
        }
        return self::object(['product_id'=>$positive,...(in_array($action,['products.create','products.duplicate'],true)?['source_product_id'=>['type'=>'integer','minimum'=>$action==='products.create'?0:1,...($action==='products.create'?['maximum'=>0]:[])]]:[]),
            'changes'=>self::object(self::properties($action),$action==='products.update_lifecycle'?[]:array_keys(self::properties($action))), 'current_version'=>$version]);
    }

    public static function register(): void
    {
        foreach([...self::ReadActions,...self::WriteActions] as $action) {
            $write=in_array($action,self::WriteActions,true);$properties=self::input_properties($action);$input=[];
            foreach($properties as $field=>$property) { $input[$field]=$property['type']; }
            YoRo_Action_Registry::register($action,['label'=>match($action){'products.create'=>'Create draft product','products.duplicate'=>'Duplicate product to draft','products.update_lifecycle'=>'Edit product visibility and shipping','products.create_category'=>'Create product category','products.create_tag'=>'Create product tag',default=>'Read product creation context'},
                'description'=>'Bounded product lifecycle settings. Copies include only a parent product; variations and arbitrary metadata are never copied.',
                'category'=>'products','permission'=>$write?'yoro_execute_actions':'yoro_view_store_data',
                'callback'=>$write?static fn($params)=>self::write((array)$params,$action):[self::class,'read_context'],
                'preview_callback'=>$write?static fn($params)=>self::preview((array)$params,$action):[self::class,'read_context'],
                'risk_level'=>$write?'medium':'low','write_action'=>$write,'requires_confirmation'=>$write,'effect'=>$write?'mutate':'read','reversibility'=>'none',
                'target_type'=>'product','max_items'=>$write?1:100,'input_schema'=>$input,'canonical_input_schema'=>$properties,
                'required_params'=>$write?['product_id','changes','expected_version']:[],'concurrency_strategy'=>$write?'optimistic_version':'','capabilities'=>['products',$write?'write':'read']]);
        }
    }

    private static function invalid(): never { throw new InvalidArgumentException('Use bounded product fields, a fresh source and permitted existing store entities.'); }

    private static function plain($value,int $max): bool
    {
        return is_string($value)&&$value!==''&&strlen($value)<=$max&&trim($value)===$value&&preg_match('//u',$value)===1
            &&preg_match('/[\x00-\x1f\x7f<>]|%[a-f0-9]{2}/i',$value)!==1;
    }

    public static function changes($changes,string $action): array
    {
        if(!in_array($action,self::WriteActions,true)||!is_array($changes)||$changes===[]||array_is_list($changes)||array_diff(array_keys($changes),array_keys(self::properties($action)))) {self::invalid();}
        $required=match($action){'products.create'=>['name','type'],'products.duplicate','products.create_category','products.create_tag'=>['name'],default=>[]};
        if(array_diff($required,array_keys($changes))) {self::invalid();}
        foreach($changes as $field=>$value) {
            $valid=match($field) {
                'name'=>self::plain($value,in_array($action,['products.create_category','products.create_tag'],true)?200:255),
                'type'=>in_array($value,['simple','variable'],true),'status'=>in_array($value,['draft','publish'],true),
                'featured'=>is_bool($value),'catalog_visibility'=>in_array($value,['visible','catalog','search','hidden'],true),
                'weight','length','width','height'=>is_string($value)&&preg_match('/\A(?:|(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?)\z/D',$value)===1,
                'shipping_class_id','parent_id'=>is_int($value)&&$value>=0,
                'upsell_ids','cross_sell_ids'=>is_array($value)&&array_is_list($value)&&count($value)<=20&&count(array_unique($value,SORT_REGULAR))===count($value)
                    &&count(array_filter($value,static fn($id)=>is_int($id)&&$id>0))===count($value),default=>false,
            };
            if(!$valid) {self::invalid();}
            if(in_array($field,['weight','length','width','height'],true)) {$changes[$field]=YoRo_Product_Edit::normalizedPrice($value);}
            if(in_array($field,['upsell_ids','cross_sell_ids'],true)) {sort($changes[$field],SORT_NUMERIC);}
        }
        return $changes;
    }

    public static function valid_params(string $action,$params): bool
    {
        if(!is_array($params)) {return false;}
        if($action===self::ReadActions[0]) {return $params===[];}
        if(!in_array($action,self::WriteActions,true)||count($params)!==3||array_diff(array_keys($params),['product_id','changes','expected_version'])
            ||!is_int($params['product_id']??null)||!is_string($params['expected_version']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$params['expected_version'])!==1) {return false;}
        $zero=in_array($action,['products.create','products.create_category','products.create_tag'],true);
        if($zero?$params['product_id']!==0:$params['product_id']<1) {return false;}
        try {self::changes($params['changes']??null,$action);return true;}catch(Throwable $error){return false;}
    }

    private static function permissions(bool $write): void
    {
        if(!current_user_can('manage_woocommerce')||!current_user_can($write?'yoro_execute_actions':'yoro_view_store_data')) {self::invalid();}
    }

    public static function context(): array
    {
        self::permissions(false);$classes=get_terms(['taxonomy'=>'product_shipping_class','hide_empty'=>false,'number'=>101,'orderby'=>'term_id','order'=>'ASC']);
        if(is_wp_error($classes)) {self::invalid();}
        $rows=[];$truncated=count($classes)>100;
        foreach(array_slice($classes,0,100) as $term){
            $name=mb_strcut(preg_replace('/[\x00-\x1f\x7f]/','',wp_strip_all_tags($term->name)),0,200,'UTF-8');
            $truncated=$truncated||$name!==$term->name;$rows[]=['id'=>(int)$term->term_id,'name'=>$name];
        }
        // Bound escaped Unicode as well as raw UTF-8 while retaining every selected ID.
        while(strlen(wp_json_encode($rows))>45056){$largest=0;foreach($rows as $index=>$row){if(strlen(wp_json_encode($row['name']))>strlen(wp_json_encode($rows[$largest]['name']))){$largest=$index;}}$rows[$largest]['name']=mb_strcut($rows[$largest]['name'],0,max(0,strlen($rows[$largest]['name'])-32),'UTF-8');$truncated=true;}
        $caps=[];foreach(['edit_products','publish_products','manage_product_terms','edit_product_terms','assign_product_terms'] as $cap){$caps[$cap]=current_user_can($cap);}
        global $wpdb;
        $generation=$wpdb->get_results("SELECT taxonomy,COUNT(*) AS total,MAX(term_taxonomy_id) AS maximum FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('product_cat','product_tag','product_shipping_class') GROUP BY taxonomy ORDER BY taxonomy",ARRAY_A);
        $data=['product_types'=>['simple','variable'],'weight_unit'=>(string)get_option('woocommerce_weight_unit','kg'),'dimension_unit'=>(string)get_option('woocommerce_dimension_unit','cm'),
            'shipping_classes'=>$rows,'shipping_classes_truncated'=>$truncated];
        if(strlen($data['weight_unit'])>20||strlen($data['dimension_unit'])>20||$wpdb->last_error!=='') {self::invalid();}
        // Cache generations can reset between read, preview and execution without any store change.
        $version=hash('sha256',wp_json_encode([$data,get_current_user_id(),$caps,$generation,get_option('woocommerce_manage_stock','no')]));
        return ['target'=>['id'=>0,'version'=>$version],...$data];
    }

    public static function fingerprint(WC_Product $product): array
    {
        $fields=[];foreach(self::Fields as $field){$getter='get_'.$field;$fields[$field]=$product->$getter('edit');}
        return $fields;
    }

    public static function details(WC_Product $product): array
    {
        $fields=self::fingerprint($product);$editable=self::Fields;$truncated=[];
        foreach($fields as $field=>$value) {
            if(in_array($field,['upsell_ids','cross_sell_ids'],true)) {sort($fields[$field],SORT_NUMERIC);if(count($value)>20){$fields[$field]=array_slice($fields[$field],0,20);$truncated[]=$field;}}
            elseif(in_array($field,['weight','length','width','height'],true)&&(!is_string($value)||preg_match('/\A(?:|(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?)\z/D',$value)!==1)) {$fields[$field]='';$truncated[]=$field;}
        }
        if(!in_array($product->get_status(),['draft','publish'],true)||!$product->is_type(['simple','variable'])||!current_user_can('edit_post',$product->get_id())) {$editable=[];}
        sort($truncated,SORT_STRING);$editable=array_values(array_diff($editable,$truncated));sort($editable,SORT_STRING);
        return ['version'=>1,'fields'=>$fields,'editable_fields'=>$editable,'truncated_fields'=>$truncated,'duplicate_supported'=>self::copy_supported($product)];
    }

    private static function product(int $id): WC_Product
    {
        $product=wc_get_product($id);
        if(!$product||!$product->is_type(['simple','variable'])||$product->get_parent_id()!==0||!in_array($product->get_status(),['draft','publish'],true)||!current_user_can('edit_post',$id)) {self::invalid();}
        return $product;
    }

    private static function term_permission(string $taxonomy,bool $manage): void
    {
        $object=get_taxonomy($taxonomy);if(!$object||!current_user_can($manage?$object->cap->manage_terms:$object->cap->assign_terms)) {self::invalid();}
    }

    private static function existing_term(int $id,string $taxonomy): void
    {
        self::term_permission($taxonomy,false);$term=get_term($id,$taxonomy);if(!$term||is_wp_error($term)||$term->taxonomy!==$taxonomy) {self::invalid();}
    }

    private static function validate_references(array $changes,int $id): void
    {
        if(($changes['shipping_class_id']??0)>0) {self::existing_term($changes['shipping_class_id'],'product_shipping_class');}
        if(array_key_exists('shipping_class_id',$changes)) {self::term_permission('product_shipping_class',false);}
        foreach(['upsell_ids','cross_sell_ids'] as $field){foreach($changes[$field]??[] as $otherId){$other=wc_get_product($otherId);if($otherId===$id||!$other||!$other->is_type(['simple','variable'])||!in_array($other->get_status(),['draft','publish'],true)||!current_user_can('edit_post',$otherId)){self::invalid();}}}
    }

    private static function copy_supported(WC_Product $source): bool
    {
        if(!$source->is_type(['simple','variable'])||!in_array($source->get_status(),['draft','publish'],true)||$source->get_parent_id()!==0
            ||strlen($source->get_description('edit'))>4000||strlen($source->get_short_description('edit'))>1000||count($source->get_category_ids('edit'))>20||count($source->get_tag_ids('edit'))>20
            ||count($source->get_gallery_image_ids('edit'))>10||count($source->get_attributes('edit'))>8||count($source->get_upsell_ids('edit'))>20||count($source->get_cross_sell_ids('edit'))>20
            ||$source->is_downloadable()||$source->get_downloads('edit')!==[]) {return false;}
        foreach($source->get_attributes('edit') as $attribute){if(!$attribute instanceof WC_Product_Attribute||count($attribute->get_options())>40){return false;}}
        return true;
    }

    /** Every standard preimage copied to the draft, independently of modified-time granularity. */
    public static function parent_copy_fingerprint(WC_Product $source): array
    {
        $fields=[];
        foreach(self::ParentCopyFields as $field){
            $getter='get_'.$field;$value=$source->$getter('edit');
            if($field==='attributes'){
                $attributes=[];foreach($value as $key=>$attribute){
                    // Variations expose scalar attribute choices; parent products expose WC attribute objects.
                    if($attribute instanceof WC_Product_Attribute){$attributes[$key]=self::attribute_values($attribute);}
                    elseif(is_scalar($attribute)||$attribute===null){$attributes[$key]=$attribute;}else{self::invalid();}
                }
                $value=$attributes;
            }elseif($value instanceof WC_DateTime){$value=['timestamp'=>$value->getTimestamp(),'timezone'=>$value->getTimezone()->getName()];}
            $fields[$field]=$value;
        }
        return ['type'=>$source->get_type(),'fields'=>$fields];
    }

    private static function attribute_values(WC_Product_Attribute $attribute): array
    {
        $values=[];foreach(self::AttributeFields as $field){$getter='get_'.$field;$values[$field]=$attribute->$getter();}
        return $values;
    }

    private static function copy_parent(WC_Product $source,string $name): WC_Product
    {
        if(!self::copy_supported($source)) {self::invalid();}
        $product=$source->is_type('variable')?new WC_Product_Variable():new WC_Product_Simple();
        foreach(self::ParentCopyFields as $field){
            $getter='get_'.$field;$setter='set_'.$field;$value=$source->$getter('edit');
            if($field==='attributes'){
                $attributes=[];foreach($value as $key=>$attribute){
                    if($attribute->is_taxonomy()){self::term_permission($attribute->get_name(),false);foreach($attribute->get_options() as $term){self::existing_term((int)$term,$attribute->get_name());}}
                    $copy=new WC_Product_Attribute();foreach(self::attribute_values($attribute) as $property=>$attributeValue){$attributeSetter='set_'.$property;$copy->$attributeSetter($attributeValue);}
                    // Copy standard attribute data only; plugin-owned extra data is arbitrary metadata.
                    $attributes[$key]=$copy;
                }
                $value=$attributes;
            }elseif($value instanceof WC_DateTime){$value=clone $value;}
            $product->$setter($value);
        }
        // Download file URLs and arbitrary metadata are deliberately outside this parent-only copy.
        if($source->is_downloadable()||$source->get_downloads('edit')!==[]) {self::invalid();}
        foreach(['category_ids'=>'product_cat','tag_ids'=>'product_tag'] as $field=>$taxonomy){$getter='get_'.$field;self::term_permission($taxonomy,false);foreach($source->$getter('edit') as $term){self::existing_term($term,$taxonomy);}}
        self::validate_references(self::fingerprint($source),$source->get_id());
        $product->set_name($name);$product->set_status('draft');$product->set_sku('');if(method_exists($product,'set_global_unique_id')){$product->set_global_unique_id('');}
        return $product;
    }

    public static function prepare(array $params,string $action): array|WP_Error
    {
        try {
            $params=array_diff_key($params,array_flip(['_yoro_confirmed','_yoro_preview']));self::permissions(true);
            if(!self::valid_params($action,$params)) {self::invalid();}
            $changes=self::changes($params['changes'],$action);$sourceId=$params['product_id'];$before=[];
            if($sourceId===0){$version=self::context()['target']['version'];}else{$source=self::product($sourceId);$version=YoRo_Commerce_Discovery_Service::product_version($source);}
            if(!hash_equals($version,$params['expected_version'])){return new WP_Error('product_version_conflict','The product or creation context changed. Read it again before previewing.');}
            if(in_array($action,['products.create_category','products.create_tag'],true)) {
                $taxonomy=$action==='products.create_category'?'product_cat':'product_tag';self::term_permission($taxonomy,true);$parent=$changes['parent_id']??0;
                if($parent>0){self::existing_term($parent,$taxonomy);}
                if(term_exists($changes['name'],$taxonomy)||get_term_by('slug',sanitize_title($changes['name']),$taxonomy)) {self::invalid();}
                return ['product'=>null,'source_id'=>0,'changes'=>$changes,'before'=>[],'taxonomy'=>$taxonomy,'parent_id'=>$parent];
            }
            if(in_array($action,['products.create','products.duplicate'],true)&&!current_user_can('edit_products')) {self::invalid();}
            if($action==='products.create') {$product=$changes['type']==='variable'?new WC_Product_Variable():new WC_Product_Simple();$product->set_name($changes['name']);$product->set_status('draft');}
            elseif($action==='products.duplicate') {$product=self::copy_parent($source,$changes['name']);}
            else {
                $product=$source;$details=self::details($source);if(array_diff(array_keys($changes),$details['editable_fields'])){self::invalid();}
                if(isset($changes['status'])&&$changes['status']==='publish'&&!current_user_can('publish_products')){self::invalid();}
                self::validate_references($changes,$sourceId);$before=array_intersect_key(self::fingerprint($source),$changes);
                foreach(['upsell_ids','cross_sell_ids'] as $field){if(isset($before[$field])){sort($before[$field],SORT_NUMERIC);}}
                if(array_filter($changes,static fn($value,$field)=>$value!==$before[$field],ARRAY_FILTER_USE_BOTH)===[]){self::invalid();}
                foreach($changes as $field=>$value){$setter='set_'.$field;$product->$setter($value);}
            }
            $product->validate_props();return ['product'=>$product,'source_id'=>$sourceId,'changes'=>$changes,'before'=>$before];
        }catch(Throwable $error){return new WP_Error('product_lifecycle_invalid','The product change is invalid or unavailable for this actor and store.');}
    }

    public static function preview(array $params,string $action): array|WP_Error
    {
        $prepared=self::prepare($params,$action);if(is_wp_error($prepared)){return $prepared;}
        return ['success'=>true,'read_only'=>true,'undoable'=>false,'product_id'=>$params['product_id'],'before'=>$prepared['before'],'after'=>$prepared['changes'],
            'message'=>'Review this exact change. New products and parent-only copies are drafts; no variations or arbitrary metadata are copied.'];
    }

    public static function write(array $params,string $action): array|WP_Error
    {
        if(($params['_yoro_confirmed']??null)!==true||($params['_yoro_preview']??false)!==false){return new WP_Error('product_confirmation_required','Execute through the confirmed product executor.');}
        $prepared=self::prepare($params,$action);if(is_wp_error($prepared)){return $prepared;}
        try {
            $changes=$prepared['changes'];
            if(isset($prepared['taxonomy'])) {
                $saved=wp_insert_term($changes['name'],$prepared['taxonomy'],['parent'=>$prepared['parent_id']]);
                if(is_wp_error($saved)){return $saved;}
                $term=get_term((int)$saved['term_id'],$prepared['taxonomy']);if(!$term||is_wp_error($term)||$term->name!==$changes['name']||(int)$term->parent!==$prepared['parent_id']){throw new RuntimeException('Term outcome mismatch.');}
                return self::result($action,['term_id'=>(int)$term->term_id,'taxonomy'=>$term->taxonomy,'name'=>$term->name,'parent_id'=>(int)$term->parent],false);
            }
            $product=$prepared['product'];$product->save();clean_post_cache($product->get_id());wc_delete_product_transients($product->get_id());$fresh=wc_get_product($product->get_id());
            if(!$fresh){throw new RuntimeException('Product outcome unavailable.');}
            if(in_array($action,['products.create','products.duplicate'],true)) {
                if($fresh->get_name('edit')!==$changes['name']||$fresh->get_status()!=='draft'||$fresh->get_sku('edit')!==''||$fresh->get_global_unique_id('edit')!==''||($fresh->is_type('variable')&&$fresh->get_children()!==[])
                    ||($action==='products.create'&&$fresh->get_type()!==$changes['type'])){throw new RuntimeException('Draft outcome mismatch.');}
            } else {
                $actual=self::fingerprint($fresh);foreach($changes as $field=>$value){if(in_array($field,['upsell_ids','cross_sell_ids'],true)){sort($actual[$field],SORT_NUMERIC);}if($actual[$field]!==$value){throw new RuntimeException('Saved field mismatch.');}}
            }
            return self::result($action,['product_id'=>$fresh->get_id(),...(in_array($action,['products.create','products.duplicate'],true)?['source_product_id'=>$prepared['source_id']]:[]),
                'changes'=>$changes,'current_version'=>YoRo_Commerce_Discovery_Service::product_version($fresh)],false);
        }catch(Throwable $error){return new WP_Error('local_execution_outcome_unknown','Read the store again; do not repeat this change.');}
    }

    public static function read_context($params): array|WP_Error
    {
        try{$params=array_diff_key((array)$params,array_flip(['_yoro_confirmed','_yoro_preview']));if(!self::valid_params(self::ReadActions[0],$params)){self::invalid();}return self::result(self::ReadActions[0],self::context(),true);}
        catch(Throwable $error){return new WP_Error('product_creation_context_unavailable','Product creation context is unavailable for this actor.');}
    }

    public static function execute_read(array $operation): array|WP_Error
    {
        $action=$operation['action']??'';$params=$operation['params']??null;if(!self::valid_params($action,$params)||!in_array($action,self::ReadActions,true)){return new WP_Error('product_creation_context_invalid','Creation context selectors are invalid.');}
        $execution=YoRo_Action_Executor::execute($action,$params,false,false,['connector_remote_agent'=>true,'workflow_id'=>$operation['workflow_id']??'','operation_id'=>$operation['operation_id']??'']);
        if(is_wp_error($execution)||!is_array($execution)||empty($execution['success'])){return is_wp_error($execution)?$execution:new WP_Error('product_creation_context_unavailable','The creation context read did not complete.');}
        return self::result($action,$execution['data'],true);
    }

    private static function result(string $action,array $data,bool $read): array
    {
        $message=$read?'Product creation settings read.':'Confirmed product lifecycle change saved. New products and copies remain drafts; copies contain no child variations.';
        return ['success'=>true,'action'=>$action,'message'=>$message,'summary'=>$message,'data'=>$data,'read_only'=>$read,'undoable'=>false,
            'result_type'=>$read?'product_creation_context':'commerce_write','display_type'=>$read?'product_creation_context':'commerce_write','assistant_summary'=>$message,'extensions'=>(object)[]];
    }
}
