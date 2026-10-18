<?php
if (!defined('ABSPATH')) { exit; }

/** Bounded stored coupon settings; no live eligibility evaluation. */
final class YoRo_Coupon_Management
{
    public const ReadActions = ['coupons.search','coupons.get_details'];
    public const WriteActions = ['coupons.create_managed','coupons.update'];
    public const ManifestExtension = 'yoro.early_access_coupon_management';
    public const Fields = ['discount_type', 'amount', 'description', 'date_expires', 'usage_limit', 'usage_limit_per_user', 'individual_use', 'free_shipping', 'exclude_sale_items', 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'minimum_amount', 'maximum_amount', 'status'];
    public static function manifest(): array
    {
        return ['version'=>1,'actions'=>[...self::ReadActions,...self::WriteActions],'max_items'=>20,'max_targets'=>1,'requires_source_read'=>true,'requires_confirmation'=>true,'result_version'=>1];
    }
    public static function validCode(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 100 && trim($value) === $value && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x20\x7f<>]|%[a-f0-9]{2}/i', $value) !== 1;
    }

    public static function properties(bool $create = false): array
    {
        $decimal = ['type'=>'string','pattern'=>'^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?$'];
        $ids = ['type'=>'array','maxItems'=>20,'uniqueItems'=>true,'items'=>['type'=>'integer','minimum'=>1]];
        return [...($create ? ['code'=>['type'=>'string','minLength'=>1,'maxLength'=>100]] : []),
            'discount_type'=>['type'=>'string','enum'=>['percent','fixed_cart','fixed_product']], 'amount'=>$decimal,
            'description'=>['type'=>'string','maxLength'=>1000], 'date_expires'=>['type'=>['string','null'],'pattern'=>'^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
            'usage_limit'=>['type'=>['integer','null'],'minimum'=>1,'maximum'=>1000000000], 'usage_limit_per_user'=>['type'=>['integer','null'],'minimum'=>1,'maximum'=>1000000000],
            'individual_use'=>['type'=>'boolean'],'free_shipping'=>['type'=>'boolean'],'exclude_sale_items'=>['type'=>'boolean'],
            'product_ids'=>$ids,'excluded_product_ids'=>$ids,'product_categories'=>$ids,'excluded_product_categories'=>$ids,
            'minimum_amount'=>['type'=>'string','pattern'=>'^(?:|(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?)$'],
            'maximum_amount'=>['type'=>'string','pattern'=>'^(?:|(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?)$'], 'status'=>['type'=>'string','enum'=>['publish','draft']]];
    }

    public static function changes(mixed $changes, bool $create = false): array
    {
        if (!is_array($changes) || $changes === [] || array_is_list($changes) || array_diff(array_keys($changes), [...self::Fields, ...($create ? ['code'] : [])])) { self::invalid(); }
        if ($create && (!isset($changes['code'], $changes['discount_type'], $changes['amount']) || !self::validCode($changes['code']))) { self::invalid(); }
        foreach ($changes as $field=>$value) {
            $valid = match ($field) {
                'code' => self::validCode($value),
                'discount_type' => in_array($value, ['percent','fixed_cart','fixed_product'], true),
                'amount', 'minimum_amount', 'maximum_amount' => is_string($value) && preg_match('/\A(?:'.($field === 'amount' ? '' : '|').'(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?)\z/D', $value) === 1,
                'description' => is_string($value) && strlen($value) <= 1000 && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]|%[a-f0-9]{2}/i', $value) !== 1,
                'date_expires' => YoRo_Product_Edit::validDate($value),
                'usage_limit','usage_limit_per_user' => $value === null || (is_int($value) && $value > 0 && $value <= 1000000000),
                'individual_use','free_shipping','exclude_sale_items' => is_bool($value),
                'product_ids','excluded_product_ids','product_categories','excluded_product_categories' => is_array($value) && array_is_list($value) && count($value) <= 20 && count(array_unique($value, SORT_REGULAR)) === count($value) && count(array_filter($value, fn ($id) => is_int($id) && $id > 0)) === count($value),
                'status' => in_array($value, ['publish','draft'], true), default => false,
            };
            if (!$valid) { self::invalid(); }
            if (in_array($field, ['product_ids','excluded_product_ids','product_categories','excluded_product_categories'], true)) { sort($changes[$field], SORT_NUMERIC); }
            if (in_array($field, ['amount','minimum_amount','maximum_amount'], true)) { $changes[$field] = YoRo_Product_Edit::normalizedPrice($value); }
        }
        return $changes;
    }

    public static function effective(array $changes, array $row, bool $create = false): array
    {
        $changes = self::changes($changes, $create);
        if ($create && !array_key_exists('status', $changes)) { $changes['status']='draft'; }
        $before = $create ? self::defaults() : ($row['fields'] ?? []);
        if (array_diff(self::Fields, array_keys($before)) || (!$create && array_diff(array_keys($changes), $row['editable_fields'] ?? []))) { self::invalid(); }
        $after = array_replace($before, $changes);
        if (($after['discount_type'] === 'percent' && self::compare($after['amount'], '100') > 0)
            || ($after['minimum_amount'] !== '' && $after['maximum_amount'] !== '' && self::compare($after['minimum_amount'], $after['maximum_amount']) > 0)
            || (!$create && array_filter($changes, static fn ($v,$k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH) === [])) { self::invalid(); }
        return $changes;
    }

    public static function defaults(): array
    {
        return ['discount_type'=>'fixed_cart','amount'=>'0','description'=>'','date_expires'=>null,'usage_limit'=>null,'usage_limit_per_user'=>null,'individual_use'=>false,'free_shipping'=>false,'exclude_sale_items'=>false,'product_ids'=>[],'excluded_product_ids'=>[],'product_categories'=>[],'excluded_product_categories'=>[],'minimum_amount'=>'','maximum_amount'=>'','status'=>'draft'];
    }

    public static function compare(string $a, string $b): int
    {
        $key = static function ($v) { $p=explode('.', $v); return str_pad($p[0],12,'0',STR_PAD_LEFT).str_pad($p[1] ?? '',6,'0'); };
        return strcmp($key($a), $key($b));
    }

    public static function register(): void
    {
        foreach ([...self::ReadActions, ...self::WriteActions] as $action) {
            $write = in_array($action, self::WriteActions, true);
            $create = $action === 'coupons.create_managed';
            $properties = $write ? ['coupon_id'=>['type'=>'integer','minimum'=>$create?0:1,'maximum'=>$create?0:PHP_INT_MAX],
                'changes'=>['type'=>'object','properties'=>self::properties($create),'required'=>$create?['code','discount_type','amount']:[],'additionalProperties'=>false],
                'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']]
                : ($action==='coupons.search' ? ['code'=>['type'=>'string','minLength'=>1,'maxLength'=>100],'page'=>['type'=>'integer','minimum'=>1,'maximum'=>10000]] : ['coupon_id'=>['type'=>'integer','minimum'=>1]]);
            $callback = match($action) {'coupons.search'=>'search','coupons.get_details'=>'get_details','coupons.create_managed'=>'execute_create','coupons.update'=>'execute_update'};
            $input=[]; foreach($properties as $field=>$definition) { $input[$field]=$definition['type']; }
            YoRo_Action_Registry::register($action, ['label'=>$create?'Create coupon':ucwords(str_replace(['coupons.','_'],['',' '],$action)).' coupon',
                'description'=>'Read or manage stored coupon settings. Cart and customer eligibility are not evaluated.', 'category'=>'coupons',
                'permission'=>$write?'yoro_execute_actions':'manage_woocommerce','callback'=>[self::class,$callback],
                'preview_callback'=>$write?[self::class,$create?'preview_create':'preview_update']:[self::class,$callback],
                'risk_level'=>$write?'medium':'low','write_action'=>$write,'requires_confirmation'=>$write,'effect'=>$write?'mutate':'read',
                'reversibility'=>'none','target_type'=>'coupon','max_items'=>$action==='coupons.search'?20:1,
                'input_schema'=>$input,'canonical_input_schema'=>$properties,'required_params'=>$write?['coupon_id','changes','expected_version']:($action==='coupons.get_details'?['coupon_id']:[]),
                'concurrency_strategy'=>$write?'optimistic_version':'','capabilities'=>['coupons',$write?'write':'read']]);
        }
    }

    public static function result_schema(string $action): array
    {
        $object = static fn ($properties, $required = null) => ['type'=>'object','properties'=>$properties,'required'=>$required ?? array_keys($properties),'additionalProperties'=>false];
        $row=$object(['id'=>['type'=>'integer','minimum'=>1],'code'=>['type'=>'string','minLength'=>1,'maxLength'=>100],
            'version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'fields'=>$object(self::properties()),
            'editable_fields'=>['type'=>'array','uniqueItems'=>true,'maxItems'=>count(self::Fields),'items'=>['type'=>'string','enum'=>self::Fields]]]);
        if($action==='coupons.search') {
            return $object(['coupons'=>['type'=>'array','maxItems'=>20,'items'=>$row],
                'coupon_page'=>$object(['page'=>['type'=>'integer','minimum'=>1,'maximum'=>10000],'has_more'=>['type'=>'boolean']]),
                'source'=>$object(['id'=>['type'=>'integer','enum'=>[0]],'version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'query'=>['type'=>['string','null'],'maxLength'=>100],'page'=>['type'=>'integer','minimum'=>1,'maximum'=>10000]]),
                'timezone'=>['type'=>'string','maxLength'=>64],'eligibility_verified'=>['type'=>'boolean','enum'=>[false]]]);
        }
        if($action==='coupons.get_details') { return $object(['coupon'=>$row,'timezone'=>['type'=>'string','maxLength'=>64],'eligibility_verified'=>['type'=>'boolean','enum'=>[false]]]); }
        return $object(['coupon_id'=>['type'=>'integer','minimum'=>1],'changes'=>$object(self::properties($action==='coupons.create_managed'),[]),'current_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']]);
    }

    public static function valid_params(string $action, $params): bool
    {
        if(!is_array($params)) { return false; }
        if($action==='coupons.search') { return !array_diff(array_keys($params),['code','page']) && (!array_key_exists('code',$params) || self::validCode($params['code']))
            && (!array_key_exists('page',$params) || (is_int($params['page']) && $params['page']>=1 && $params['page']<=10000)); }
        if($action==='coupons.get_details') { return count($params)===1 && is_int($params['coupon_id']??null) && $params['coupon_id']>0; }
        if(!in_array($action,self::WriteActions,true) || count($params)!==3 || array_diff(array_keys($params),['coupon_id','changes','expected_version']) || !is_int($params['coupon_id']??null)
            || ($action==='coupons.create_managed' ? $params['coupon_id']!==0 : $params['coupon_id']<1) || !is_string($params['expected_version']??null) || preg_match('/\A[a-f0-9]{64}\z/D',$params['expected_version'])!==1) { return false; }
        try { self::changes($params['changes']??null,$action==='coupons.create_managed'); return true; } catch(Throwable $error) { return false; }
    }

    public static function execute_read(array $operation): array|WP_Error
    {
        $action=$operation['action']??''; $params=$operation['params']??null;
        if(!in_array($action,self::ReadActions,true) || !self::valid_params($action,$params) || !current_user_can('manage_woocommerce')) { return new WP_Error('coupon_read_invalid','Coupon read authority is unavailable.'); }
        $execution=YoRo_Action_Executor::execute($action,$params,false,false,['connector_remote_agent'=>true,'workflow_id'=>$operation['workflow_id']??'','operation_id'=>$operation['operation_id']??'']);
        if(is_wp_error($execution) || !is_array($execution) || empty($execution['success'])) { return is_wp_error($execution)?$execution:new WP_Error('coupon_read_unavailable','Coupon settings are unavailable.'); }
        return YoRo_Commerce_Contract_Builder::canonical_execution_payload($execution,$action);
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Use bounded coupon settings and current actor permissions.');
    }

    public static function row(WC_Coupon $coupon): array
    {
        $fields=[]; $editable=self::Fields;
        foreach(self::Fields as $field) {
            $getter='get_'.$field; $value=$coupon->$getter('edit');
            if(in_array($field,['amount','minimum_amount','maximum_amount'],true)) { $value=YoRo_Product_Edit::normalizedPrice((string)$value); }
            elseif($field==='date_expires') { $value=$value ? wp_date('Y-m-d',$value->getTimestamp(),wp_timezone()) : null; }
            elseif(in_array($field,['usage_limit','usage_limit_per_user'],true)) { $value=$value ? (int)$value : null; }
            elseif(in_array($field,['product_ids','excluded_product_ids','product_categories','excluded_product_categories'],true)) {
                $value=array_values(array_map('intval',$value)); sort($value,SORT_NUMERIC);
                if(count($value)>20) { $editable=array_diff($editable,[$field]); $value=array_slice($value,0,20); }
            } elseif(in_array($field,['individual_use','free_shipping','exclude_sale_items'],true)) { $value=(bool)$value; }
            elseif($field==='description') {
                if(strlen($value)>1000 || preg_match('/[<>]|%[a-f0-9]{2}/i',$value)) { $editable=array_diff($editable,[$field]); }
                $value=mb_strcut(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|%[a-f0-9]{2}/i','',wp_strip_all_tags($value)),0,1000,'UTF-8');
            }
            $fields[$field]=$value;
        }
        return ['id'=>(int)$coupon->get_id(),'code'=>$coupon->get_code('edit'),'version'=>self::version($coupon),'fields'=>$fields,'editable_fields'=>array_values($editable)];
    }

    public static function version(WC_Coupon $coupon): string
    {
        $data=$coupon->get_data();
        foreach(['date_created','date_modified','date_expires'] as $field) { $data[$field]=isset($data[$field]) && $data[$field] ? $data[$field]->getTimestamp() : null; }
        return hash('sha256',wp_json_encode($data));
    }

    private static function search_data(array $params): array
    {
        if(array_diff(array_keys($params),['code','page']) || !is_int($params['page']??1) || ($params['page']??1)<1 || ($params['page']??1)>10000
            || (array_key_exists('code',$params) && !self::validCode($params['code']))) { self::invalid(); }
        $page=$params['page']??1; $rows=[]; $hasMore=false;
        if(isset($params['code'])) {
            $matches=self::code_ids($params['code']);
            if(count($matches)>1) { self::invalid(); }
            $id=$matches[0]??0;
            if($id && $page===1) { $coupon=new WC_Coupon($id); if(in_array($coupon->get_status(),['publish','draft'],true) && current_user_can('edit_post',$id)) { $rows[]=self::row($coupon); } }
        } else {
            $query=new WP_Query(['post_type'=>'shop_coupon','post_status'=>['publish','draft'],'posts_per_page'=>21,'offset'=>($page-1)*20,
                'orderby'=>'ID','order'=>'DESC','fields'=>'ids','no_found_rows'=>true,'suppress_filters'=>false]);
            $hasMore=count($query->posts)>20;
            foreach(array_slice($query->posts,0,20) as $id) { if(current_user_can('edit_post',$id)) { $rows[]=self::row(new WC_Coupon($id)); } }
        }
        $version=hash('sha256',wp_json_encode(['query'=>$params['code']??null,'page'=>$page,'rows'=>$rows]));
        return ['coupons'=>$rows,'coupon_page'=>['page'=>$page,'has_more'=>$hasMore], 'source'=>['id'=>0,'version'=>$version,'query'=>$params['code']??null,'page'=>$page], 'timezone'=>wp_timezone_string(),'eligibility_verified'=>false];
    }

    public static function search($params): array|WP_Error
    {
        try { $params=array_diff_key((array)$params,array_flip(['_yoro_confirmed','_yoro_preview'])); if(!current_user_can('manage_woocommerce')) { self::invalid(); } return self::result('coupons.search',self::search_data((array)$params),true); }
        catch(Throwable $error) { return new WP_Error('coupon_read_invalid','Coupon settings are unavailable.'); }
    }

    public static function get_details($params): array|WP_Error
    {
        try {
            $params=array_diff_key((array)$params,array_flip(['_yoro_confirmed','_yoro_preview']));
            if(array_keys((array)$params)!==['coupon_id'] || !is_int($params['coupon_id']) || $params['coupon_id']<1 || !current_user_can('manage_woocommerce') || !current_user_can('edit_post',$params['coupon_id'])) { self::invalid(); }
            $coupon=new WC_Coupon($params['coupon_id']); if(!$coupon->get_id() || !in_array($coupon->get_status(),['publish','draft'],true)) { self::invalid(); }
            return self::result('coupons.get_details',['coupon'=>self::row($coupon),'timezone'=>wp_timezone_string(),'eligibility_verified'=>false],true);
        } catch(Throwable $error) { return new WP_Error('coupon_read_invalid','Coupon settings are unavailable.'); }
    }

    public static function prepare(array $params, bool $create): array|WP_Error
    {
        try {
            if(array_diff(array_keys($params),['coupon_id','changes','expected_version','_yoro_confirmed','_yoro_preview']) || !is_int($params['coupon_id']??null)
                || ($create ? $params['coupon_id']!==0 : $params['coupon_id']<1) || !is_string($params['expected_version']??null) || preg_match('/\A[a-f0-9]{64}\z/D',$params['expected_version'])!==1
                || !current_user_can('yoro_execute_actions') || !current_user_can('manage_woocommerce')) { self::invalid(); }
            $changes=self::changes($params['changes']??null,$create);
            $type=get_post_type_object('shop_coupon');
            if(!$type || !current_user_can($create ? $type->cap->create_posts : 'edit_post', $params['coupon_id'])) { self::invalid(); }
            $coupon=$create ? new WC_Coupon() : new WC_Coupon($params['coupon_id']);
            if(!$create && (!$coupon->get_id() || !in_array($coupon->get_status(),['publish','draft'],true))) { self::invalid(); }
            $before=$create ? self::defaults() : self::row($coupon)['fields'];
            $version=$create ? self::search_data(['code'=>$changes['code'],'page'=>1])['source']['version'] : self::version($coupon);
            if(!hash_equals($version,$params['expected_version'])) { return new WP_Error('coupon_version_conflict','Coupon settings changed. Read them again before previewing.'); }
            if($create && self::code_ids($changes['code'])!==[]) { self::invalid(); }
            $changes=self::effective($changes,$create?[]:self::row($coupon),$create);
            if(($changes['status']??null)==='publish' && !current_user_can($type->cap->publish_posts)) { self::invalid(); }
            foreach(['product_ids','excluded_product_ids'] as $field) {
                if(!array_key_exists($field,$changes)) { continue; }
                if(!current_user_can('edit_products')) { self::invalid(); }
                foreach($changes[$field] as $id) { if(!wc_get_product($id) || !current_user_can('edit_post',$id)) { self::invalid(); } }
            }
            foreach(['product_categories','excluded_product_categories'] as $field) {
                if(!array_key_exists($field,$changes)) { continue; } $taxonomy=get_taxonomy('product_cat');
                if(!$taxonomy || !current_user_can($taxonomy->cap->assign_terms)) { self::invalid(); }
                foreach($changes[$field] as $id) { if(!get_term($id,'product_cat') instanceof WP_Term || !current_user_can('assign_term',$id)) { self::invalid(); } }
            }
            foreach($changes as $field=>$value) {
                $setter='set_'.$field;
                if($field==='date_expires' && $value!==null) { $date=new DateTimeImmutable($value.' 23:59:59',wp_timezone()); if($date->format('Y-m-d')!==$value) { self::invalid(); } $value=$date->getTimestamp(); }
                $coupon->$setter($value===null ? '' : $value);
            }
            if($create && !array_key_exists('status',$changes)) { $coupon->set_status('draft'); }
            return ['coupon'=>$coupon,'changes'=>$changes,'before'=>$before];
        } catch(Throwable $error) { return new WP_Error('coupon_change_invalid','The coupon change is invalid or its code is already in use.'); }
    }

    public static function preview_create($params): array|WP_Error { return self::preview((array)$params,true); }
    public static function preview_update($params): array|WP_Error { return self::preview((array)$params,false); }
    private static function preview(array $params,bool $create): array|WP_Error
    {
        $prepared=self::prepare($params,$create); if(is_wp_error($prepared)) { return $prepared; }
        return ['success'=>true,'read_only'=>true,'undoable'=>false,'coupon_id'=>$params['coupon_id'],'before'=>array_intersect_key($prepared['before'],$prepared['changes']),
            'after'=>$prepared['changes'],'message'=>'Review stored coupon settings. Draft disables this coupon; cart and customer eligibility are not evaluated.'];
    }
    public static function execute_create($params): array|WP_Error { return self::execute((array)$params,true); }
    public static function execute_update($params): array|WP_Error { return self::execute((array)$params,false); }
    private static function execute(array $params,bool $create): array|WP_Error
    {
        $prepared=self::prepare($params,$create); if(is_wp_error($prepared)) { return $prepared; } $coupon=$prepared['coupon'];
        try { $coupon->save(); clean_post_cache($coupon->get_id()); $fresh=new WC_Coupon($coupon->get_id()); $row=self::row($fresh);
            foreach($prepared['changes'] as $field=>$value) { if(($field==='code' ? $row['code'] : $row['fields'][$field])!==($field==='code' ? wc_format_coupon_code($value) : $value)) { throw new RuntimeException('Saved coupon mismatch'); } }
            return self::result($create?'coupons.create_managed':'coupons.update',['coupon_id'=>$fresh->get_id(),'changes'=>array_intersect_key(['code'=>$row['code'],...$row['fields']],$prepared['changes']), 'current_version'=>$row['version']],false);
        } catch(Throwable $error) { return new WP_Error('local_execution_outcome_unknown','Read coupon settings again; do not repeat this change.'); }
    }
    private static function result(string $action,array $data,bool $read): array
    {
        $message='Stored coupon settings only. Cart and customer eligibility are not evaluated.';
        return ['success'=>true,'action'=>$action,'message'=>$message,'summary'=>$message,'data'=>$data,'read_only'=>$read,'undoable'=>false,
            'result_type'=>'coupon_management','display_type'=>'coupon_management','assistant_summary'=>$message,'extensions'=>(object)[]];
    }

    /** WooCommerce's public code helper excludes drafts; uniqueness must include disabled coupons. */
    private static function code_ids(string $code): array
    {
        $query=new WP_Query(['post_type'=>'shop_coupon','post_status'=>array_keys(get_post_stati()),'title'=>wc_format_coupon_code($code),
            'posts_per_page'=>2,'fields'=>'ids','no_found_rows'=>true,'suppress_filters'=>false,'orderby'=>'ID','order'=>'ASC']);
        return array_values(array_map('intval',$query->posts));
    }
}

final class YoRo_Early_Access_Coupon_Read
{
    public static function valid_params(string $action, $params): bool
    {
        return YoRo_Coupon_Management::valid_params($action,$params);
    }
    public static function execute(array $operation): array|WP_Error
    {
        return YoRo_Coupon_Management::execute_read($operation);
    }
}

