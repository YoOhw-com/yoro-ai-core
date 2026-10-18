<?php

if (!defined('ABSPATH')) { exit; }

/** Confirmed, non-atomic price/stock batch with durable per-item mutation markers. */
final class YoRo_Product_Batch
{
    public const Action = 'products.batch_update';
    public const Fields = ['regular_price','sale_price','date_on_sale_from','date_on_sale_to','manage_stock','stock_quantity','stock_status','backorders','low_stock_amount'];
    public const MaxTargets = 10;

    public static function manifest(): array
    {
        return ['version'=>1,'action'=>self::Action,'max_targets'=>self::MaxTargets,'fields'=>self::Fields,
            'requires_source_read'=>true,'requires_confirmation'=>true,'result_version'=>1];
    }

    public static function properties(): array
    {
        return array_intersect_key(YoRo_Product_Edit::properties(), array_flip(self::Fields));
    }

    public static function item_schema(): array
    {
        $properties = ['product_id'=>['type'=>'integer','minimum'=>1], 'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
            'changes'=>['type'=>'object','properties'=>self::properties(),'minProperties'=>1,'required'=>[],'additionalProperties'=>false]];
        return ['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false];
    }

    public static function result_schema(): array
    {
        $fields = ['product_id'=>['type'=>'integer','minimum'=>1], 'status'=>['type'=>'string','enum'=>['updated','failed','unknown','not_attempted']],
            'changes'=>['type'=>'object','properties'=>self::properties(),'required'=>[],'additionalProperties'=>false],
            'current_version'=>['type'=>['string','null'],'pattern'=>'^[a-f0-9]{64}$'],
            'code'=>['type'=>'string','enum'=>['updated','version_conflict','not_allowed','invalid_change','outcome_unknown','not_attempted']]];
        $properties = ['items'=>['type'=>'array','minItems'=>1,'maxItems'=>self::MaxTargets,'items'=>['type'=>'object','properties'=>$fields,'required'=>array_keys($fields),'additionalProperties'=>false]],
            'count'=>['type'=>'integer','minimum'=>1,'maximum'=>self::MaxTargets], 'updated_count'=>['type'=>'integer','minimum'=>0,'maximum'=>self::MaxTargets]];
        return ['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false];
    }

    public static function valid_params($params): bool
    {
        if (!is_array($params) || array_keys($params) !== ['items'] || !is_array($params['items']) || !array_is_list($params['items'])
            || !$params['items'] || count($params['items']) > self::MaxTargets) { return false; }
        $seen = [];
        foreach ($params['items'] as $item) {
            if (!is_array($item) || count($item) !== 3 || array_diff(array_keys($item), ['product_id','changes','expected_version'])
                || !is_int($item['product_id'] ?? null) || $item['product_id'] < 1 || isset($seen[$item['product_id']])
                || !is_string($item['expected_version'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D',$item['expected_version'])
                || !is_array($item['changes'] ?? null) || array_diff(array_keys($item['changes']),self::Fields)) { return false; }
            try { YoRo_Product_Edit::changes($item['changes']); } catch(Throwable $error) { return false; }
            $seen[$item['product_id']] = true;
        }
        return true;
    }

    public static function version(array $params): string
    {
        return YoRo_Early_Access_Product_Read::params_hash(array_map(static fn($item) => [
            'product_id'=>$item['product_id'],'expected_version'=>$item['expected_version']],$params['items']));
    }

    public static function register(): void
    {
        YoRo_Action_Registry::register(self::Action, ['label'=>'Batch update products',
            'description'=>'Confirm up to ten explicit price/stock changes; each target has an independent outcome.', 'category'=>'products',
            'permission'=>'yoro_execute_actions','callback'=>[self::class,'execute'],'preview_callback'=>[self::class,'preview'],
            'risk_level'=>'medium','write_action'=>true,'requires_confirmation'=>true,'effect'=>'mutate','reversibility'=>'none',
            'target_type'=>'product','max_items'=>self::MaxTargets,'input_schema'=>['items'=>'array'],
            'canonical_input_schema'=>['items'=>['type'=>'array','minItems'=>1,'maxItems'=>self::MaxTargets,'items'=>self::item_schema()]],
            'required_params'=>['items'],'concurrency_strategy'=>'per_item_optimistic_version',
            'ai_description'=>'Apply an explicitly confirmed ordered batch of at most ten product price and stock patches. It is non-atomic; unknown outcomes must be checked, never retried automatically.',
            'capabilities'=>['products','inventory','write']]);
    }

    /** Bare parameter calls have no immutable parent identity for durable item markers. */
    public static function execute($params)
    {
        return new WP_Error('batch_confirmed_envelope_required','A confirmed connector envelope is required for a product batch.');
    }

    public static function preview($params)
    {
        $params = array_diff_key((array)$params,array_flip(['_yoro_confirmed','_yoro_preview']));
        if (!self::valid_params($params) || !current_user_can('yoro_execute_actions')) { return new WP_Error('batch_invalid','The selected product batch is invalid.'); }
        $items = [];
        foreach ($params['items'] as $item) {
            $preview = YoRo_Product_Edit::preview($item);
            if (is_wp_error($preview)) { return $preview; }
            $items[] = ['product_id'=>$item['product_id'],'expected_version'=>$item['expected_version'],'before'=>$preview['before'],'after'=>$preview['after']];
        }
        return ['success'=>true,'read_only'=>true,'undoable'=>false,'message'=>'Review every product change. This batch is not atomic; earlier changes remain if a later item fails.',
            'items'=>$items,'non_atomic'=>true];
    }

    /** Derive immutable per-item lineage without broadening any child mutation contract. */
    public static function child_operation(array $parent, int $index): array
    {
        $item = $parent['params']['items'][$index];
        $identity = YoRo_Early_Access_Product_Read::params_hash(['envelope_id'=>$parent['envelope_id'], 'idempotency_key'=>$parent['idempotency_key'],
            'index'=>$index,'item'=>$item]);
        $child = $parent;
        $child['envelope_id'] = 'envelope_'.$identity;
        $child['idempotency_key'] = 'product_batch_item_'.$identity;
        $child['operation_id'] = 'batch_item_'.$index;
        $child['action'] = YoRo_Product_Edit::Action;
        $child['params'] = $item;
        $child['extensions']['yoro.mcp_early_access_commerce_write']['expected_version'] = $item['expected_version'];
        $child['extensions']['yoro.mcp_early_access_commerce_write']['action'] = YoRo_Product_Edit::Action;
        $child['extensions']['yoro.mcp_early_access_commerce_write']['params_hash'] = YoRo_Early_Access_Product_Read::params_hash($item);
        $child['extensions']['yoro.local_product_batch'] = ['parent_envelope_id'=>$parent['envelope_id'], 'parent_idempotency_key'=>$parent['idempotency_key'],
            'parent_hash'=>YoRo_Early_Access_Product_Read::params_hash($parent), 'index'=>$index,'item_hash'=>YoRo_Early_Access_Product_Read::params_hash($item)];
        return $child;
    }

    private static function row(int $id, string $status, string $code): array
    {
        return ['product_id'=>$id,'status'=>$status,'changes'=>(object)[],'current_version'=>null,'code'=>$code];
    }

    public static function execute_operation(array $operation, ?Closure $fence = null)
    {
        $params = $operation['params'] ?? [];
        if (!self::valid_params($params) || !is_string($operation['envelope_id'] ?? null) || !preg_match('/\Aenvelope_[a-f0-9]{64}\z/D',$operation['envelope_id'])
            || !is_string($operation['idempotency_key'] ?? null) || $operation['idempotency_key'] === '' || strlen($operation['idempotency_key']) > 255
            || !current_user_can('yoro_execute_actions') || empty($operation['authorization']['execution_permit'])) {
            return new WP_Error('batch_invalid','A confirmed product batch with durable parent identity is required.');
        }
        $rows = []; $stopped = false;
        foreach ($params['items'] as $index=>$item) {
            if ($stopped || ($fence !== null && !$fence())) {
                $rows[] = self::row($item['product_id'],'not_attempted','not_attempted'); $stopped = true; continue;
            }
            $child = self::child_operation($operation,$index);
            $journal = YoRo_Connector_Execution_Journal::begin($child);
            if (($journal['state'] ?? null) === 'completed') {
                $row = $journal['result'] ?? null;
                if (!self::valid_row($row,$item)) { $row = self::row($item['product_id'],'unknown','outcome_unknown'); }
            } elseif (($journal['state'] ?? null) !== 'new') {
                $row = self::row($item['product_id'],'unknown','outcome_unknown');
            } else {
                $row = self::run_item($child,$item,$fence);
                $completion = YoRo_Connector_Execution_Journal::complete($child,$row);
                if (($completion['state'] ?? null) !== 'completed') { $row = self::row($item['product_id'],'unknown','outcome_unknown'); }
            }
            $rows[] = $row;
            if (in_array($row['status'],['unknown','not_attempted'],true)) { $stopped = true; }
        }
        $message = 'Product batch completed. Review each item; the batch is not atomic.';
        return ['success'=>true,'action'=>self::Action,'message'=>$message,'summary'=>$message,
            'data'=>['items'=>$rows,'count'=>count($rows),'updated_count'=>count(array_filter($rows,static fn($row)=>$row['status']==='updated'))],
            'read_only'=>false,'undoable'=>false,'result_type'=>'product_batch','display_type'=>'product_batch','assistant_summary'=>$message,'extensions'=>(object)[]];
    }

    private static function run_item(array $child, array $item, ?Closure $fence): array
    {
        if (!current_user_can('edit_post',$item['product_id'])) { return self::row($item['product_id'],'failed','not_allowed'); }
        $prepared = YoRo_Product_Edit::prepare($item);
        if (is_wp_error($prepared)) {
            return self::row($item['product_id'],'failed',$prepared->get_error_code()==='product_version_conflict'?'version_conflict':'invalid_change');
        }
        if (YoRo_Early_Access_Product_Read::params_hash($prepared['changes']) !== YoRo_Early_Access_Product_Read::params_hash($item['changes'])) {
            return self::row($item['product_id'],'failed','invalid_change');
        }
        try { $result = YoRo_Early_Access_Commerce_Write::execute($child,$fence); }
        catch(Throwable $error) { return self::row($item['product_id'],'unknown','outcome_unknown'); }
        if (is_wp_error($result)) {
            $error = $result->get_error_code();
            if ($error === 'yoro_connector_runner_lock_lost') { return self::row($item['product_id'],'not_attempted','not_attempted'); }
            if ($error === 'local_execution_outcome_unknown') { return self::row($item['product_id'],'unknown','outcome_unknown'); }
            return self::row($item['product_id'],'failed',in_array($error,['confirmed_target_version_conflict','product_version_conflict'],true)?'version_conflict':
                (in_array($error,['yoro_permission_denied','confirmed_commerce_denied'],true)?'not_allowed':'invalid_change'));
        }
        $row = ['product_id'=>$item['product_id'],'status'=>'updated','changes'=>$result['data']['changes'] ?? [],
            'current_version'=>$result['data']['current_version'] ?? null,'code'=>'updated'];
        return self::valid_row($row,$item) ? $row : self::row($item['product_id'],'unknown','outcome_unknown');
    }

    private static function valid_row($row, array $item): bool
    {
        if (!is_array($row) || count($row)!==5 || array_diff(array_keys($row),['product_id','status','changes','current_version','code'])
            || ($row['product_id'] ?? null)!==$item['product_id']) { return false; }
        if (($row['status'] ?? null)==='updated') {
            return ($row['code'] ?? null)==='updated' && is_string($row['current_version'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D',$row['current_version'])
                && is_array($row['changes'] ?? null) && YoRo_Early_Access_Product_Read::params_hash($row['changes'])===YoRo_Early_Access_Product_Read::params_hash($item['changes']);
        }
        $codes=['failed'=>['version_conflict','not_allowed','invalid_change'],'unknown'=>['outcome_unknown'],'not_attempted'=>['not_attempted']];
        return isset($codes[$row['status'] ?? '']) && in_array($row['code'] ?? null,$codes[$row['status']],true)
            && array_key_exists('current_version',$row) && $row['current_version']===null && (array)($row['changes'] ?? null)===[];
    }
}
