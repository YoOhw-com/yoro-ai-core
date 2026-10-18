<?php

if (!defined("ABSPATH")) { exit; }

/** Bounded variable-product management through canonical confirmed operations. */
final class YoRo_Variable_Product
{
    public const ReadAction = 'products.get_variable_details';
    public const AttributeAction = 'products.update_attributes';
    public const CreateAction = 'products.create_variation';
    public const Extension = 'yoro.early_access_variable_products';
    public const Actions = [self::ReadAction];
    public const WriteActions = [self::AttributeAction, self::CreateAction];
    public const MaxAttributes = 8;
    public const MaxOptions = 40;
    public const MaxChildren = 500;

    public static function manifest(): array
    {
        return ['version' => 1, 'actions' => [self::ReadAction, ...self::WriteActions], 'max_attributes' => self::MaxAttributes,
            'max_options' => self::MaxOptions, 'max_children' => self::MaxChildren, 'max_items' => 20,
            'requires_source_read' => true, 'requires_confirmation' => true, 'result_version' => 1];
    }

    public static function properties(string $action): array
    {
        $text = ['type' => 'string', 'minLength' => 1, 'maxLength' => 100];
        $selection = ['type' => 'object', 'properties' => ['name' => $text, 'option' => $text], 'required' => ['name', 'option'], 'additionalProperties' => false];
        if ($action === self::AttributeAction) {
            return ['attributes' => ['type' => 'array', 'maxItems' => self::MaxAttributes, 'items' => ['type' => 'object',
                'properties' => ['name' => $text, 'options' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MaxOptions, 'uniqueItems' => true, 'items' => $text],
                    'visible' => ['type' => 'boolean'], 'variation' => ['type' => 'boolean']],
                'required' => ['name', 'options', 'visible', 'variation'], 'additionalProperties' => false]],
                'defaults' => ['type' => 'array', 'maxItems' => self::MaxAttributes, 'items' => $selection]];
        }
        if ($action !== self::CreateAction) { self::invalid(); }

        return array_merge(['attributes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MaxAttributes, 'items' => $selection]], array_diff_key(YoRo_Product_Edit::properties(), array_fill_keys(['name','description','short_description','category_ids','tag_ids','image_id','gallery_image_ids'], true)));
    }

    public static function text( $value): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value && mb_strlen($value) <= 100
            && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x1f\x7f<>]/', $value) !== 1;
    }

    public static function factsSchema(): array
    {
        $object = static fn (array $fields): array => ['type' => 'object', 'properties' => $fields, 'required' => array_keys($fields), 'additionalProperties' => false];
        $text = ['type' => 'string', 'minLength' => 1, 'maxLength' => 100];
        $strings = static fn (int $max, array $item): array => ['type' => 'array', 'maxItems' => $max, 'uniqueItems' => true, 'items' => $item];
        $attributes = self::properties(self::AttributeAction);

        return $object(['attributes' => $attributes['attributes'], 'defaults' => $attributes['defaults'],
            'attribute_context' => ['type' => 'array', 'maxItems' => self::MaxAttributes, 'items' => $object([
                'name' => $text, 'label' => $text, 'global' => ['type' => 'boolean'], 'more_options' => ['type' => 'boolean'],
                'available_options' => $strings(self::MaxOptions, $text)])],
            'used_options' => ['type' => 'array', 'maxItems' => self::MaxAttributes, 'items' => $object([
                'name' => $text, 'options' => $strings(self::MaxOptions + 1, ['type' => 'string', 'maxLength' => 100])])],
            'attribute_order' => $strings(self::MaxAttributes, $text),
            'combinations' => ['type' => 'array', 'maxItems' => 256, 'items' => $object([
                'mask' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 255],
                'hashes' => $strings(self::MaxChildren, ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'])])],
            'children_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => self::MaxChildren],
            'editable' => ['type' => 'boolean'], 'version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']]);
    }

    public static function attributes( $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MaxAttributes) { self::invalid(); }
        $names = [];
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) !== 4 || array_diff(array_keys($row), ['name', 'options', 'visible', 'variation']) !== []
                || ! self::text($row['name'] ?? null) || isset($names[mb_strtolower($row['name'])])
                || ! is_bool($row['visible'] ?? null) || ! is_bool($row['variation'] ?? null)
                || ! is_array($row['options'] ?? null) || ! array_is_list($row['options']) || $row['options'] === []
                || count($row['options']) > self::MaxOptions || count(array_unique($row['options'], SORT_REGULAR)) !== count($row['options'])) { self::invalid(); }
            foreach ($row['options'] as $option) { if (! self::text($option)) { self::invalid(); } }
            if (!str_starts_with(mb_strtolower($row['name']), 'pa_')
                && array_values(wc_get_text_attributes(wc_implode_text_attributes($row['options']))) !== $row['options']) { self::invalid(); }
            $names[mb_strtolower($row['name'])] = true;
        }

        return $rows;
    }

    public static function selections( $rows, bool $allowEmpty = true): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MaxAttributes || (! $allowEmpty && $rows === [])) { self::invalid(); }
        $names = [];
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) !== 2 || array_diff(array_keys($row), ['name', 'option']) !== []
                || ! self::text($row['name'] ?? null) || ! self::text($row['option'] ?? null) || isset($names[mb_strtolower($row['name'])])) { self::invalid(); }
            $names[mb_strtolower($row['name'])] = true;
        }

        return $rows;
    }

    public static function changes(string $action,  $changes): array
    {
        if (! is_array($changes) || $changes === [] || array_is_list($changes)
            || array_diff(array_keys($changes), array_keys(self::properties($action))) !== []) { self::invalid(); }
        if ($action === self::AttributeAction) {
            if (array_key_exists('attributes', $changes)) { self::attributes($changes['attributes']); }
            if (array_key_exists('defaults', $changes)) { self::selections($changes['defaults']); }
        } else {
            self::selections($changes['attributes'] ?? null, false);
            YoRo_Product_Edit::changes(array_diff_key($changes, ['attributes' => true]));
            if (! is_string($changes['regular_price'] ?? null) || $changes['regular_price'] === '') { self::invalid(); }
        }

        return $changes;
    }

    public static function combination(array $selections, array $order, int $mask): string
    {
        $values = array_column($selections, 'option', 'name');
        $pairs = [];
        foreach ($order as $i => $name) { if ($mask & (1 << $i)) { $pairs[] = [$name, $values[$name] ?? '']; } }

        return hash('sha256', json_encode($pairs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function effective(string $action, array $changes, array $row): array
    {
        $changes = self::changes($action, $changes);
        $facts = $row['variable_product'] ?? null;
        if (($row['type'] ?? null) !== 'variable' || ! is_array($facts) || ($facts['editable'] ?? null) !== true) { self::invalid(); }
        $before = $facts['attributes'];
        $attributes = $changes['attributes'] ?? $before;
        if ($action === self::AttributeAction) {
            $known = array_column($facts['attribute_context'], null, 'name');
            $next = array_column($attributes, null, 'name');
            foreach ($attributes as $attribute) {
                $current = $known[$attribute['name']] ?? null;
                if (str_starts_with(mb_strtolower($attribute['name']), 'pa_') && (! $current || ! $current['global']
                    || array_diff($attribute['options'], $current['available_options']) !== [])) { self::invalid(); }
            }
            foreach ($facts['used_options'] as $used) {
                if (! isset($next[$used['name']]) || ! $next[$used['name']]['variation']
                    || array_diff(array_filter($used['options'], fn ($option) => $option !== ''), $next[$used['name']]['options']) !== []) { self::invalid(); }
            }
            self::validateSelections($changes['defaults'] ?? $facts['defaults'], $attributes);
            if ($attributes === $before && ($changes['defaults'] ?? $facts['defaults']) === $facts['defaults']) { self::invalid(); }

            return $changes;
        }
        if ($facts['children_count'] >= self::MaxChildren) { self::invalid(); }
        $selections = $changes['attributes'];
        self::validateSelections($selections, $before, true);
        foreach ($facts['combinations'] as $combination) {
            if (in_array(self::combination($selections, $facts['attribute_order'], $combination['mask']), $combination['hashes'], true)) { self::invalid(); }
        }
        $base = ['name' => 'Variation', 'regular_price' => '', 'sale_price' => '', 'date_on_sale_from' => null, 'date_on_sale_to' => null,
            'sku' => '', 'global_unique_id' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock',
            'backorders' => 'no', 'low_stock_amount' => null, 'description' => '', 'short_description' => '',
            'category_ids' => [], 'tag_ids' => [], 'image_id' => 0, 'gallery_image_ids' => []];
        $meta = $row['extensions']['woocommerce.product'];
        $meta['edit_fields'] = $base;
        $meta['editable_fields'] = array_values(array_diff($meta['editable_fields'], ['name']));
        $meta['editable_fields'] = array_values(array_unique([...$meta['editable_fields'], 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to']));
        $meta['stock_managed_by_parent'] = $row['extensions']['woocommerce.product']['edit_fields']['manage_stock'];
        $fields = YoRo_Product_Edit::effective(array_diff_key($changes, ['attributes' => true]), ['type' => 'variation', 'extensions' => ['woocommerce.product' => $meta]]);

        return array_merge(['attributes' => $selections], $fields);
    }

    public static function validateSelections(array $selections, array $attributes, bool $complete = false): void
    {
        $options = [];
        foreach ($attributes as $attribute) { if ($attribute['variation']) { $options[$attribute['name']] = $attribute['options']; } }
        foreach ($selections as $selection) {
            if (! isset($options[$selection['name']]) || ! in_array($selection['option'], $options[$selection['name']], true)) { self::invalid(); }
        }
        if ($complete && (count($selections) !== count($options) || $options === [])) { self::invalid(); }
    }

    private static function parent_product($id, bool $allowDraft = false)
    {
        $product = is_int($id) && $id > 0 ? wc_get_product($id) : false;
        if (!$product || !$product->is_type('variable') || !in_array($product->get_status(), $allowDraft ? ['publish','draft'] : ['publish'], true)
            || !current_user_can('yoro_execute_actions') || !current_user_can('edit_post', $id)) { self::invalid(); }
        return $product;
    }

    private static function option_name($attribute, $value)
    {
        if ($value === '') { return ''; }
        if ($attribute->is_taxonomy()) {
            $term = get_term_by('slug', $value, $attribute->get_name());
            if (!$term || is_wp_error($term)) { self::invalid(); }
            $value = $term->name;
        }
        if (!self::text($value)) { self::invalid(); }
        return $value;
    }

    public static function context(WC_Product_Variable $parent): array
    {
        $parent=self::parent_product($parent->get_id(),true);
        $ids = get_posts(['post_type'=>'product_variation','post_parent'=>$parent->get_id(),
            'post_status'=>['publish','private','draft','pending','future'],'numberposts'=>self::MaxChildren + 1,
            'fields'=>'ids','orderby'=>'ID','order'=>'ASC','suppress_filters'=>false]);
        if (count($ids)>self::MaxChildren || count($parent->get_attributes())>self::MaxAttributes) { self::invalid(); }
        update_meta_cache('post', $ids);
        $attributes=[];$context=[];$byKey=[];$defaults=[];
        foreach ($parent->get_attributes() as $key=>$attribute) {
            $name=$attribute->get_name();$values=[];
            if ($attribute->is_taxonomy()) {
                foreach ($attribute->get_options() as $termId) {
                    $term=get_term($termId,$name);if(!$term||is_wp_error($term)){self::invalid();}$values[]=$term->name;
                }
            } else { $values=$attribute->get_options(); }
            $attributes[]=['name'=>$name,'options'=>array_values($values),'visible'=>(bool)$attribute->get_visible(),'variation'=>(bool)$attribute->get_variation()];
            $available=$values;$more=false;
            if ($attribute->is_taxonomy()) {
                $terms=get_terms(['taxonomy'=>$name,'hide_empty'=>false,'number'=>self::MaxOptions+1,'orderby'=>'name','order'=>'ASC']);
                if(is_wp_error($terms)){self::invalid();}
                foreach($terms as $term){if(!in_array($term->name,$available,true)){if(count($available)>=self::MaxOptions){$more=true;break;}$available[]=$term->name;}}
            }
            $context[]=['name'=>$name,'label'=>wc_attribute_label($name),'global'=>$attribute->is_taxonomy(),
                'available_options'=>array_values($available),'more_options'=>$more];
            $byKey[$key]=$attribute;
        }
        self::attributes($attributes);
        foreach($parent->get_default_attributes() as $key=>$value){
            if($value===''){continue;}if(!isset($byKey[$key])){self::invalid();}
            $defaults[]=['name'=>$byKey[$key]->get_name(),'option'=>self::option_name($byKey[$key],$value)];
        }
        self::selections($defaults);
        $order=[];foreach($attributes as $a){if($a['variation']){$order[]=$a['name'];}}sort($order,SORT_STRING);
        $used=[];$combinations=[];$versions=[];
        foreach($ids as $id){
            $child=wc_get_product($id);if(!$child||!$child->is_type('variation')||$child->get_parent_id()!==$parent->get_id()){self::invalid();}
            $raw=$child->get_attributes('edit');$selected=[];$mask=0;
            foreach($order as $i=>$name){
                $attribute=$byKey[sanitize_title($name)]??null;if(!$attribute){self::invalid();}
                $value=self::option_name($attribute,$raw[sanitize_title($name)]??'');
                $used[$name][]=$value;if($value!==''){$mask|=1<<$i;$selected[]=['name'=>$name,'option'=>$value];}
            }
            $combinations[$mask][]=self::combination($selected,$order,$mask);
            $versions[]=[$id,$child->get_status(),$raw];
        }
        $usedRows=[];foreach($used as $name=>$values){$values=array_values(array_unique($values));if(count($values)>self::MaxOptions+1){self::invalid();}$usedRows[]=['name'=>$name,'options'=>$values];}
        $combinationRows=[];ksort($combinations,SORT_NUMERIC);foreach($combinations as $mask=>$hashes){$combinationRows[]=['mask'=>(int)$mask,'hashes'=>array_values(array_unique($hashes))];}
        $version=hash('sha256',wp_json_encode([YoRo_Commerce_Discovery_Service::product_version($parent),$attributes,$defaults,$versions],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return ['attributes'=>$attributes,'defaults'=>$defaults,'attribute_context'=>$context,'used_options'=>$usedRows,
            'attribute_order'=>$order,'combinations'=>$combinationRows,'children_count'=>count($ids),'editable'=>true,'version'=>$version];
    }

    public static function valid_read_params($action, $params): bool
    {
        return $action===self::ReadAction && is_array($params) && count($params)===3
            && !array_diff(array_keys($params),['product_id','page','limit']) && is_int($params['product_id']??null) && $params['product_id']>0
            && is_int($params['page']??null) && $params['page']>=1 && is_int($params['limit']??null) && $params['limit']>=1 && $params['limit']<=20
            && ($params['page']-1)*$params['limit']<self::MaxChildren;
    }

    public static function read(array $operation)
    {
        $params=$operation['params']??[];
        $lifecycle=1===($operation['extensions']['yoro.mcp_early_access_product_read']['product_lifecycle_version']??null);
        try {
            if(!self::valid_read_params($operation['action']??'', $params)||!current_user_can('yoro_view_store_data')){self::invalid();}
            $parent=self::parent_product($params['product_id'],$lifecycle);$context=self::context($parent);
            $query=wc_get_products(['type'=>'variation','parent'=>$parent->get_id(),'status'=>'publish','limit'=>$params['limit'],
                'page'=>$params['page'],'orderby'=>'id','order'=>'ASC','paginate'=>true]);
            $rows=[];foreach($query->products as $variation){$row=YoRo_Early_Access_Product_Read::product($variation,false,$lifecycle);if($row){$rows[]=$row;}}
            $data=['products'=>[YoRo_Early_Access_Product_Read::product($parent,true,$lifecycle)],'variations'=>$rows,'variable'=>$context,
                'count'=>count($rows),'page_info'=>['page'=>$params['page'],'limit'=>$params['limit'],'total'=>(int)$query->total,'has_more'=>$params['page']<(int)$query->max_num_pages]];
            return ['success'=>true,'action'=>self::ReadAction,'message'=>'Variable product read completed.','summary'=>'Variable product read completed.',
                'data'=>$data,'read_only'=>true,'undoable'=>false,'result_type'=>'variable_product','display_type'=>'variable_product','assistant_summary'=>'Variable product read completed.','extensions'=>(object)[]];
        } catch(Throwable $error){return new WP_Error('variable_product_unavailable','Use an authorized variable product within the supported bounds.');}
    }

    public static function valid_params($action, $params): bool
    {
        return self::valid_read_params($action, $params);
    }

    public static function execute(array $operation)
    {
        // Preserve negotiated read facts without widening the legacy registry callback.
        if(1===($operation['extensions']['yoro.mcp_early_access_product_read']['product_lifecycle_version']??null)){return self::read($operation);}
        $result=YoRo_Action_Executor::execute($operation['action'],$operation['params'],true,false,['connector_remote_agent'=>true,
            'workflow_id'=>$operation['workflow_id']??'','operation_id'=>$operation['operation_id']??'']);
        if(is_wp_error($result)){return $result;}
        if(!is_array($result)||empty($result['success'])){return new WP_Error('variable_product_unavailable','The variable-product read did not complete.');}
        $payload=YoRo_Commerce_Contract_Builder::canonical_execution_payload($result,self::ReadAction);
        $payload['extensions']=(object)[];
        return $payload;
    }

    public static function register(): void
    {
        foreach(self::WriteActions as $action){
            YoRo_Action_Registry::register($action,[
                'label'=>$action===self::CreateAction?'Create variation':'Edit product attributes',
                'description'=>'Change one authorized published or draft variable product after explicit confirmation.',
                'category'=>'products','permission'=>'yoro_execute_actions','callback'=>static function($params)use($action){return self::write($params,$action);},
                'preview_callback'=>static function($params)use($action){return self::preview($params,$action);},
                'risk_level'=>'medium','write_action'=>true,'requires_confirmation'=>true,'effect'=>'mutate','reversibility'=>'none',
                'target_type'=>'product','max_items'=>1,'input_schema'=>['product_id'=>'integer','changes'=>'object','expected_version'=>'string'],
                'canonical_input_schema'=>['product_id'=>['type'=>'integer','minimum'=>1],
                    'changes'=>['type'=>'object','properties'=>self::properties($action),'required'=>$action===self::CreateAction?['attributes','regular_price']:[],'additionalProperties'=>false],
                    'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']],
                'required_params'=>['product_id','changes','expected_version'],'concurrency_strategy'=>'optimistic_version','capabilities'=>['products','inventory','write']]);
        }
        YoRo_Action_Registry::register(self::ReadAction,[
            'label'=>'View variations','description'=>'Read one authorized variable product and a bounded page of published variations. Draft access requires negotiated lifecycle support.',
            'category'=>'products','permission'=>'yoro_view_store_data','callback'=>static function($params){
                unset($params['_yoro_confirmed'],$params['_yoro_preview']);return self::read(['action'=>self::ReadAction,'params'=>$params]);},
            'risk_level'=>'low','write_action'=>false,'read_only'=>true,'effect'=>'read','target_type'=>'product','max_targets'=>1,'max_items'=>20,
            'input_schema'=>['product_id'=>'integer','page'=>'integer','limit'=>'integer'],'required_params'=>['product_id','page','limit'],
            'canonical_input_schema'=>['product_id'=>['type'=>'integer','minimum'=>1],'page'=>['type'=>'integer','minimum'=>1,'maximum'=>500],
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>20]],'capabilities'=>['products','read']]);
    }

    private static function prepare($params, string $action)
    {
        try {
            if(!is_array($params)||!in_array($action,self::WriteActions,true)
                ||array_diff(array_keys($params),['product_id','changes','expected_version','_yoro_confirmed','_yoro_preview'])){self::invalid();}
            $parent=self::parent_product($params['product_id']??null,true);$facts=self::context($parent);
            if(!is_string($params['expected_version']??null)||!hash_equals($facts['version'],$params['expected_version'])){
                return new WP_Error('product_version_conflict','The variable product changed after preview. Read it again.');
            }
            $changes=self::effective($action,self::changes($action,$params['changes']??null),['type'=>'variable','variable_product'=>$facts,
                'extensions'=>['woocommerce.product'=>YoRo_Product_Edit::facts($parent)]]);
            if($action===self::AttributeAction){
                $attributes=$changes['attributes']??$facts['attributes'];$objects=[];$defaults=[];
                foreach($attributes as $position=>$data){
                    $key=sanitize_title($data['name']);if($key===''||isset($objects[$key])){self::invalid();}
                    $attribute=new WC_Product_Attribute();$attribute->set_name($data['name']);$options=$data['options'];
                    if(str_starts_with($data['name'],'pa_')){
                        $id=wc_attribute_taxonomy_id_by_name($data['name']);if(!$id||!taxonomy_exists($data['name'])){self::invalid();}
                        $attribute->set_id($id);$options=[];
                        foreach($data['options'] as $name){$term=get_term_by('name',$name,$data['name']);if(!$term||$term->name!==$name){self::invalid();}$options[]=$term->term_id;}
                    }
                    $attribute->set_options($options);$attribute->set_visible($data['visible']);$attribute->set_variation($data['variation']);$attribute->set_position($position);$objects[$key]=$attribute;
                }
                foreach($changes['defaults']??$facts['defaults'] as $selection){$key=sanitize_title($selection['name']);$defaults[$key]=self::stored_option($objects[$key],$selection['option']);}
                $parent->set_attributes($objects);$parent->set_default_attributes($defaults);$parent->validate_props();
                return ['parent'=>$parent,'product'=>$parent,'changes'=>$changes];
            }
            $child=new WC_Product_Variation();$child->set_parent_id($parent->get_id());$child->set_status('publish');$values=[];
            foreach($changes['attributes'] as $selection){$key=sanitize_title($selection['name']);$attribute=$parent->get_attributes()[$key]??null;if(!$attribute){self::invalid();}$values['attribute_'.$key]=self::stored_option($attribute,$selection['option']);}
            $child->set_attributes($values);YoRo_Product_Edit::apply_changes($child,array_diff_key($changes,['attributes'=>true]));
            return ['parent'=>$parent,'product'=>$child,'changes'=>$changes];
        } catch(Throwable $error){return new WP_Error('variable_product_invalid','Check the attributes, defaults, combination and product identifiers. No change was saved.');}
    }

    private static function stored_option(WC_Product_Attribute $attribute, string $value): string
    {
        if(!$attribute->is_taxonomy()){return $value;}
        $term=get_term_by('name',$value,$attribute->get_name());if(!$term||$term->name!==$value){self::invalid();}
        return $term->slug;
    }

    public static function preview($params, string $action)
    {
        $prepared=self::prepare($params,$action);if(is_wp_error($prepared)){return $prepared;}
        return ['success'=>true,'read_only'=>true,'undoable'=>false,'product_id'=>$prepared['parent']->get_id(),
            'after'=>$prepared['changes'],'message'=>'Review this variable-product change before confirming.'];
    }

    public static function write($params, string $action)
    {
        $prepared=self::prepare($params,$action);if(is_wp_error($prepared)){return $prepared;}
        $product=$prepared['product'];$parentId=$prepared['parent']->get_id();$parentStatus=$prepared['parent']->get_status();
        try {
            $product->save();
            WC_Product_Variable::sync($parentId);wc_delete_product_transients($parentId);clean_post_cache($parentId);clean_post_cache($product->get_id());
            $parent=wc_get_product($parentId);if(!$parent||!$parent->is_type('variable')||$parent->get_status()!==$parentStatus||!in_array($parentStatus,['publish','draft'],true)){throw new RuntimeException('Outcome mismatch');}$facts=self::context($parent);$changes=$prepared['changes'];
            if($action===self::AttributeAction){
                foreach($changes as $field=>$value){
                    $actual=$facts[$field];
                    if($field==='attributes'){
                        foreach($value as $index=>&$attribute){if(str_starts_with($attribute['name'],'pa_')){sort($attribute['options'],SORT_STRING);if(isset($actual[$index]['options'])){sort($actual[$index]['options'],SORT_STRING);}}}unset($attribute);
                    }
                    if($field==='defaults'){$value=array_column($value,'option','name');$actual=array_column($actual,'option','name');ksort($value);ksort($actual);}
                    if($actual!==$value){throw new RuntimeException('Outcome mismatch');}
                }
            } else {
                $current=wc_get_product($product->get_id());if(!$current||!$current->is_type('variation')||$current->get_parent_id()!==$parentId||$current->get_status()!=='publish'){throw new RuntimeException('Outcome mismatch');}$actual=YoRo_Product_Edit::facts($current)['edit_fields'];
                foreach(array_diff_key($changes,['attributes'=>true]) as $field=>$value){if(($actual[$field]??null)!==$value){throw new RuntimeException('Outcome mismatch');}}
                foreach($changes['attributes'] as $selection){$attribute=$parent->get_attributes()[sanitize_title($selection['name'])];
                    if(self::option_name($attribute,$current->get_attributes('edit')[sanitize_title($selection['name'])]??'')!==$selection['option']){throw new RuntimeException('Outcome mismatch');}}
            }
            $data=['product_id'=>$parentId,'changes'=>$changes,'current_version'=>$facts['version']];
            if($action===self::CreateAction){$data['variation_id']=$product->get_id();$data['variation_version']=YoRo_Commerce_Discovery_Service::product_version($current);}
            return array_merge(['success'=>true,'message'=>'Variable product updated.','read_only'=>false,'undoable'=>false,'data'=>$data],$data);
        } catch(Throwable $error){return new WP_Error('local_execution_outcome_unknown','Read the variable product again; do not repeat this change.');}
    }

    public static function invalid()
    {
        throw new InvalidArgumentException("Use bounded variable-product attributes, valid defaults and a new specific variation combination.");
    }
}
