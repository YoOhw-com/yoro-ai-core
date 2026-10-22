<?php
if (!defined('ABSPATH')) {exit;}
/** Existing shared objects only. Slug, assignments and variation identities are immutable. */
final class YoRo_Catalog_Management
{
    public const ReadActions=['products.get_catalog_edit_context'];
    public const Actions=self::ReadActions;
    public const WriteActions=['products.update_category','products.update_tag','products.update_global_attribute'];
    public const ManifestExtension='yoro.early_access_catalog_management';
    public static function manifest(): array
    {
        return ['version' => 1, 'actions' => [...self::Actions, ...self::WriteActions], 'max_targets' => 1, 'requires_source_read' => true,
            'requires_confirmation' => true, 'result_version' => 1, 'preserves_slug_assignments_variations' => true];
    }
    public static function kind(string $action): string
    {
        return match ($action) { 'products.update_category' => 'category', 'products.update_tag' => 'tag', 'products.update_global_attribute' => 'global_attribute', default => self::invalid() };
    }
    public static function properties(string $action): array
    {
        $name = ['type' => 'string', 'minLength' => 1, 'maxLength' => 200];
        return $action === 'products.update_global_attribute' ? ['name' => $name, 'order_by' => ['type' => 'string', 'enum' => ['menu_order', 'name', 'name_num', 'id']]]
            : ['name' => $name, 'description' => ['type' => 'string', 'maxLength' => 2000], ...($action === 'products.update_category' ? ['parent_id' => ['type' => 'integer', 'minimum' => 0]] : [])];
    }
    public static function changes(mixed $changes, string $action): array
    {
        if (! in_array($action, self::WriteActions, true) || ! is_array($changes) || $changes === [] || array_is_list($changes) || array_diff(array_keys($changes), array_keys(self::properties($action)))) { self::invalid(); }
        foreach ($changes as $field => $value) {
            $valid = match ($field) {
                'parent_id' => is_int($value) && $value >= 0,
                'order_by' => in_array($value, ['menu_order', 'name', 'name_num', 'id'], true),
                'name' => is_string($value) && trim($value) === $value && $value !== '' && self::plain($value, 200),
                'description' => is_string($value) && strlen($value) <= 2000 && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]/', $value) !== 1,
                default => false,
            };
            if (! $valid) { self::invalid(); }
        }
        ksort($changes, SORT_STRING); return $changes;
    }
    public static function input_properties(string $action): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        return $action === self::Actions[0] ? ['target_id' => $id, 'kind' => ['type' => 'string', 'enum' => ['category', 'tag', 'global_attribute']]]
            : ['target_id' => $id, 'changes' => self::object(self::properties($action), []), 'expected_version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']];
    }
    public static function result_schema(string $action): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];$text = ['type' => 'string', 'maxLength' => 200];$version = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
        if (in_array($action, self::WriteActions, true)) { return self::object(['target_id' => $id, 'kind' => ['type' => 'string', 'enum' => [self::kind($action)]], 'changes' => self::object(self::properties($action), []), 'slug' => $text, 'current_version' => $version, 'assignments_preserved' => ['type' => 'boolean', 'enum' => [true]], 'variations_preserved' => ['type' => 'boolean', 'enum' => [true]]]); }
        return self::object(['target_id' => $id, 'kind' => ['type' => 'string', 'enum' => ['category', 'tag', 'global_attribute']], 'name' => $text, 'slug' => $text,
            'description' => ['type' => 'string', 'maxLength' => 2000], 'parent_id' => ['type' => 'integer', 'minimum' => 0], 'order_by' => ['type' => 'string', 'enum' => ['', 'menu_order', 'name', 'name_num', 'id']],
            'attribute_type' => ['type' => 'string', 'maxLength' => 100], 'has_archives' => ['type' => 'boolean'], 'assignment_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
            'variation_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000], 'assignment_fingerprint' => $version, 'variation_fingerprint' => $version, 'version' => $version]);
    }
    private static function object(array $properties,?array $required=null): array {return ['type'=>'object','properties'=>$properties,'required'=>$required??array_keys($properties),'additionalProperties'=>false];}
    private static function invalid(): never {throw new InvalidArgumentException('Select one supported existing catalog object and preserve its slug and assignments.');}
    private static function plain($value,int $max): bool {return is_string($value)&&strlen($value)<=$max&&preg_match('//u',$value)===1&&!preg_match('/[\x00-\x1f\x7f<>]|%[a-f0-9]{2}/i',$value);}
    public static function register(): void
    {
        foreach([...self::ReadActions,...self::WriteActions]as$action){$write=in_array($action,self::WriteActions,true);$properties=self::input_properties($action);$input=[];foreach($properties as$key=>$p){$input[$key]=$p['type'];}
            YoRo_Action_Registry::register($action,['label'=>$write?'Edit existing shared catalog object':'Read existing catalog edit context','description'=>'Existing category, tag or global attribute. Slug, assignments, attribute type/archive and variation identities are preserved.',
                'category'=>'products','permission'=>$write?'yoro_execute_actions':'yoro_view_store_data','callback'=>$write?static fn($params)=>self::write((array)$params,$action):[self::class,'read'],
                'preview_callback'=>$write?static fn($params)=>self::preview((array)$params,$action):[self::class,'read'],'risk_level'=>$write?'medium':'low','write_action'=>$write,'requires_confirmation'=>$write,'effect'=>$write?'mutate':'read','external_side_effect'=>false,'reversibility'=>'none','target_type'=>'catalog_item','max_items'=>1,
                'input_schema'=>$input,'canonical_input_schema'=>$properties,'required_params'=>array_keys($properties),'concurrency_strategy'=>$write?'optimistic_version':'','capabilities'=>['products',$write?'write':'read']]);}
    }
    public static function valid_params(string $action,$params,$facts=[]): bool
    {
        if(!is_array($params)||!is_int($params['target_id']??null)||$params['target_id']<1||array_diff(array_keys($params),array_keys(self::input_properties($action)))||count($params)!==count(self::input_properties($action))){return false;}
        if(in_array($action,self::ReadActions,true)){return in_array($params['kind']??null,['category','tag','global_attribute'],true);}
        if(!in_array($action,self::WriteActions,true)||!is_string($params['expected_version']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$params['expected_version'])){return false;}
        try{self::changes($params['changes']??null,$action);return true;}catch(Throwable $error){return false;}
    }
    private static function impact(string $taxonomy,?int $termTaxonomyId=null): array
    {
        global $wpdb;
        $termClause=$termTaxonomyId===null?'':$wpdb->prepare(' AND tr.term_taxonomy_id=%d',$termTaxonomyId);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT tr.object_id,tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy=%s {$termClause} ORDER BY tr.object_id,tr.term_taxonomy_id LIMIT 10001",$taxonomy),ARRAY_A);
        if($wpdb->last_error!==''||count($rows)>10000){self::invalid();}
        $variants=str_starts_with($taxonomy,'pa_')?$wpdb->get_results($wpdb->prepare("SELECT post_id,meta_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s ORDER BY meta_id LIMIT 10001",'attribute_'.$taxonomy),ARRAY_A):[];
        if($wpdb->last_error!==''||count($variants)>10000){self::invalid();}
        return ['assignment_count'=>count($rows),'variation_count'=>count($variants),'assignment_fingerprint'=>YoRo_Early_Access_Product_Read::params_hash($rows),'variation_fingerprint'=>YoRo_Early_Access_Product_Read::params_hash($variants)];
    }
    public static function context(int $id,string $kind): array
    {
        if(!current_user_can('yoro_view_store_data')||!in_array($kind,['category','tag','global_attribute'],true)){self::invalid();}
        if($kind==='global_attribute'){
            $attribute=wc_get_attribute($id);if(!$attribute){self::invalid();}$taxonomy=$attribute->slug;
            $fields=['target_id'=>$id,'kind'=>$kind,'name'=>$attribute->name,'slug'=>preg_replace('/^pa_/','',$attribute->slug),'description'=>'','parent_id'=>0,'order_by'=>$attribute->order_by,'attribute_type'=>$attribute->type,'has_archives'=>(bool)$attribute->has_archives];
        }else{
            $taxonomy=$kind==='category'?'product_cat':'product_tag';$term=get_term($id,$taxonomy);
            if(!$term instanceof WP_Term||is_wp_error($term)){self::invalid();}
            $fields=['target_id'=>$id,'kind'=>$kind,'name'=>$term->name,'slug'=>$term->slug,'description'=>$term->description,'parent_id'=>(int)$term->parent,'order_by'=>'','attribute_type'=>'','has_archives'=>false];
        }
        if(!self::plain($fields['name'],200)||strlen($fields['slug'])>200||strlen($fields['description'])>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]/',$fields['description'])){self::invalid();}
        $impact=self::impact($taxonomy,$kind==='global_attribute'?null:(int)$term->term_taxonomy_id);$data=[...$fields,...$impact];$data['version']=YoRo_Early_Access_Product_Read::params_hash($data);return $data;
    }
    public static function prepare(array $params,string $action): array|WP_Error
    {
        $params=array_diff_key($params,array_flip(['_yoro_confirmed','_yoro_preview']));
        try{
            if(!self::valid_params($action,$params)||!current_user_can('yoro_execute_actions')||!current_user_can('manage_woocommerce')){self::invalid();}
            $kind=self::kind($action);$source=self::context($params['target_id'],$kind);$changes=self::changes($params['changes'],$action);
            if(!hash_equals($source['version'],$params['expected_version'])){self::invalid();}
            if($kind==='global_attribute'){
                if(!current_user_can('manage_product_terms')){self::invalid();}
                foreach(wc_get_attribute_taxonomies()as$other){if((int)$other->attribute_id!==$params['target_id']&&isset($changes['name'])&&mb_strtolower($other->attribute_label)===mb_strtolower($changes['name'])){self::invalid();}}
            }else{
                $taxonomy=$kind==='category'?'product_cat':'product_tag';$tax=get_taxonomy($taxonomy);
                if(!$tax||!current_user_can($tax->cap->edit_terms)||!current_user_can($tax->cap->manage_terms)){self::invalid();}
                $parent=$changes['parent_id']??$source['parent_id'];$seen=[$params['target_id']=>true];$cursor=$parent;$depth=0;
                while($cursor>0){if(isset($seen[$cursor])||++$depth>100){self::invalid();}$seen[$cursor]=true;$ancestor=get_term($cursor,$taxonomy);if(!$ancestor instanceof WP_Term||is_wp_error($ancestor)){self::invalid();}$cursor=(int)$ancestor->parent;}
                $name=$changes['name']??$source['name'];$existing=term_exists($name,$taxonomy,$parent);
                if($existing&&(int)(is_array($existing)?$existing['term_id']:$existing)!==$params['target_id']){self::invalid();}
                if(wp_unique_term_slug($source['slug'],(object)['term_id'=>$params['target_id'],'parent'=>$parent,'taxonomy'=>$taxonomy])!==$source['slug']){self::invalid();}
            }
            $changed=false;foreach($changes as$field=>$value){$changed=$changed||$value!==$source[$field];}if(!$changed){self::invalid();}
            return ['source'=>$source,'changes'=>$changes];
        }catch(Throwable $error){return new WP_Error('catalog_edit_conflict','The target, hierarchy, name, assignments or current version is unavailable or conflicts.');}
    }
    public static function read($params): array|WP_Error
    {
        $params=array_diff_key((array)$params,array_flip(['_yoro_confirmed','_yoro_preview']));
        try{if(!self::valid_params(self::ReadActions[0],$params)){self::invalid();}return self::result(self::ReadActions[0],self::context($params['target_id'],$params['kind']),true);}catch(Throwable $error){return new WP_Error('catalog_context_unavailable','The bounded existing catalog object is unavailable.');}
    }
    public static function execute(array $operation): array|WP_Error
    {
        if(($operation['extensions']['yoro.mcp_early_access_product_read']['read_family']??null)!=='catalog_management'||!self::valid_params($operation['action']??'',$operation['params']??[])){return new WP_Error('catalog_context_denied','Catalog read authority is unavailable.');}
        $execution=YoRo_Action_Executor::execute($operation['action'],$operation['params'],true,false,['connector_remote_agent'=>true,'workflow_id'=>$operation['workflow_id'],'operation_id'=>$operation['operation_id']]);
        return is_wp_error($execution)?$execution:YoRo_Commerce_Contract_Builder::canonical_execution_payload($execution,$operation['action']);
    }
    public static function preview(array $params,string $action): array|WP_Error
    {
        $p=self::prepare($params,$action);return is_wp_error($p)?$p:['success'=>true,'action'=>$action,'summary'=>'One existing shared object, with immutable slug and assignments.','preview'=>['changes'=>$p['changes'],'assignment_count'=>$p['source']['assignment_count'],'variation_count'=>$p['source']['variation_count']]];
    }
    public static function write(array $params,string $action): array|WP_Error
    {
        if(($params['_yoro_confirmed']??null)!==true||($params['_yoro_preview']??false)!==false){return new WP_Error('confirmation_required','Execute through the confirmed commerce executor.');}
        $params=array_diff_key($params,array_flip(['_yoro_confirmed','_yoro_preview']));
        $p=self::prepare($params,$action);if(is_wp_error($p)){return $p;}$s=$p['source'];$changes=$p['changes'];
        try{
            if($s['kind']==='global_attribute'){
                $result=wc_update_attribute($s['target_id'],['name'=>$changes['name']??$s['name'],'slug'=>$s['slug'],'type'=>$s['attribute_type'],'order_by'=>$changes['order_by']??$s['order_by'],'has_archives'=>$s['has_archives']]);
            }else{
                $result=wp_update_term($s['target_id'],$s['kind']==='category'?'product_cat':'product_tag',['name'=>$changes['name']??$s['name'],'description'=>$changes['description']??$s['description'],'parent'=>$changes['parent_id']??$s['parent_id'],'slug'=>$s['slug']]);
            }
            if(is_wp_error($result)){return $result;}
            $after=self::context($s['target_id'],$s['kind']);
            foreach(['slug','attribute_type','has_archives','assignment_fingerprint','variation_fingerprint']as$field){if($after[$field]!==$s[$field]){throw new RuntimeException('A protected shared-object identity changed.');}}
            foreach($changes as$field=>$value){if($after[$field]!==$value){throw new RuntimeException('The exact catalog change could not be read back.');}}
            return self::result($action,['target_id'=>$s['target_id'],'kind'=>$s['kind'],'changes'=>$changes,'slug'=>$s['slug'],'current_version'=>$after['version'],'assignments_preserved'=>true,'variations_preserved'=>true],false);
        }catch(Throwable $error){return new WP_Error('local_execution_outcome_unknown','The catalog outcome is unknown. Read the object and do not repeat this change automatically.');}
    }
    private static function result(string $action,array $data,bool $read): array
    {
        $message=$read?'Current existing shared catalog object and assignment impact.':'Exact shared catalog change verified; slug, assignments and variation identities preserved.';
        return ['success'=>true,'action'=>$action,'message'=>$message,'summary'=>$message,'data'=>$data,'read_only'=>$read,'undoable'=>false,'result_type'=>'catalog_management','display_type'=>'catalog_management','assistant_summary'=>$message,'extensions'=>(object)[]];
    }
}
