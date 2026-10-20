<?php
if (!defined('ABSPATH')) {exit;}

/** A signed, journaled adjustment serialized with WooCommerce's native stock UPDATE. */
final class YoRo_Stock_Adjustment {
    public const Action='products.adjust_stock';
    public const ManifestExtension='yoro.early_access_stock_adjustment';
    private static ?array $operation=null;
    private static ?Closure $fence=null;

    public static function manifest(): array {return ['version'=>1,'actions'=>[self::Action],'max_targets'=>1,'requires_source_read'=>true,'requires_confirmation'=>true,'stock_ownership'=>'self_managed','history'=>'yoro_adjustments_only','result_version'=>1];}
    public static function object(array $properties): array {return ['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false];}
    public static function changes_properties(): array {return ['delta'=>['type'=>'integer','minimum'=>-1000000000,'maximum'=>1000000000],'reason'=>['type'=>'string','minLength'=>1,'maxLength'=>500]];}
    public static function input_properties(): array {return ['product_id'=>['type'=>'integer','minimum'=>1],'changes'=>self::object(self::changes_properties()),'expected_stock_quantity'=>['type'=>'integer','minimum'=>-1000000000,'maximum'=>1000000000],'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'adjustment_id'=>['type'=>'string','pattern'=>'^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$']];}
    public static function result_schema(): array {return self::object(['product_id'=>['type'=>'integer','minimum'=>1],'adjustment_id'=>['type'=>'string','pattern'=>'^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$'],
        'before_quantity'=>['type'=>'integer','minimum'=>-1000000000,'maximum'=>1000000000],'stock_quantity'=>['type'=>'integer','minimum'=>-1000000000,'maximum'=>1000000000],
        ...self::changes_properties(),'current_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'occurred_at'=>['type'=>'string','format'=>'date-time']]);}
    public static function valid_params($params): bool {
        return is_array($params)&&count($params)===5&&!array_diff(array_keys($params),array_keys(self::input_properties()))&&is_int($params['product_id']??null)&&$params['product_id']>0
            &&is_int($params['expected_stock_quantity']??null)&&abs($params['expected_stock_quantity'])<=1000000000
            &&is_string($params['expected_version']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$params['expected_version'])===1
            &&is_string($params['adjustment_id']??null)&&wp_is_uuid($params['adjustment_id'])
            &&is_array($params['changes']??null)&&count($params['changes'])===2&&!array_diff(array_keys($params['changes']),['delta','reason'])
            &&is_int($params['changes']['delta']??null)&&$params['changes']['delta']!==0&&abs($params['changes']['delta'])<=1000000000
            &&is_string($params['changes']['reason']??null)&&trim($params['changes']['reason'])===$params['changes']['reason']&&$params['changes']['reason']!==''&&mb_strlen($params['changes']['reason'])<=500
            &&preg_match('//u',$params['changes']['reason'])===1&&preg_match('/[\x00-\x1f\x7f<>]|%[a-f0-9]{2}/i',$params['changes']['reason'])!==1;
    }
    public static function prepare(array $params): array|WP_Error {
        if (!self::valid_params($params)||!current_user_can('manage_woocommerce')||!current_user_can('yoro_execute_actions')||!current_user_can('edit_post',$params['product_id'])) {return new WP_Error('stock_adjustment_denied','Stock adjustment is unavailable.');}
        $product=wc_get_product($params['product_id']);
        if (!$product||!in_array($product->get_type(),['simple','variation'],true)||!in_array($product->get_status(),['publish','draft'],true)
            ||$product->get_manage_stock('edit')!==true||!$product->managing_stock()||$product->get_stock_managed_by_id()!==$product->get_id()||!is_int($product->get_stock_quantity('edit'))
            ||$product->get_stock_quantity('edit')!==$params['expected_stock_quantity']||!hash_equals(YoRo_Commerce_Discovery_Service::product_version($product),$params['expected_version'])) {return new WP_Error('stock_adjustment_conflict','Stock changed. Read the product again before previewing.');}
        $after=$params['expected_stock_quantity']+$params['changes']['delta'];
        if(abs($after)>1000000000||($after<0&&!$product->backorders_allowed())||!in_array($product->get_data_store()->get_current_class_name(),['WC_Product_Data_Store_CPT','WC_Product_Variation_Data_Store_CPT'],true)) {return new WP_Error('stock_adjustment_unsupported','This stock adjustment is unsupported.');}
        return ['product'=>$product,'after'=>$after];
    }
    public static function register(): void {
        YoRo_Action_Registry::register(self::Action,['label'=>'Adjust stock with a reason','description'=>'One signed quantity adjustment to self-managed stock, with a durable YoRo history entry. Native WooCommerce stock hooks apply.',
            'category'=>'products','permission'=>'yoro_execute_actions','callback'=>[self::class,'write'],'preview_callback'=>static function($params){$check=self::prepare((array)$params);return is_wp_error($check)?$check:['success'=>true,'before'=>['stock_quantity'=>$params['expected_stock_quantity']],'after'=>['stock_quantity'=>$check['after'],...$params['changes']]];},
            'risk_level'=>'medium','write_action'=>true,'requires_confirmation'=>true,'effect'=>'mutate','reversibility'=>'none','target_type'=>'product','max_items'=>1,
            'input_schema'=>['product_id'=>'integer','changes'=>'object','expected_stock_quantity'=>'integer','expected_version'=>'string','adjustment_id'=>'string'],
            'canonical_input_schema'=>self::input_properties(),'required_params'=>array_keys(self::input_properties()),'concurrency_strategy'=>'optimistic_version','capabilities'=>['products','write']]);
    }
    public static function with_operation(array $operation,?Closure $fence,Closure $callback) {
        if(self::$operation!==null){return new WP_Error('stock_adjustment_nested','Nested adjustment is unavailable.');}
        self::$operation=$operation;self::$fence=$fence;
        try{return $callback();}finally{self::$operation=null;self::$fence=null;}
    }
    private static function data(array $operation): ?array {
        global $wpdb;$table=YoRo_DB::stock_adjustments_table_name();$params=$operation['params'];
        $row=$wpdb->get_row($wpdb->prepare("SELECT envelope_hash,data FROM {$table} WHERE adjustment_id=%s",$params['adjustment_id']),ARRAY_A);
        if($wpdb->last_error!==''){throw new RuntimeException('stock_history_unavailable');}
        if(!$row){return null;}
        if(!hash_equals($row['envelope_hash'],hash('sha256',wp_json_encode($operation)))){throw new RuntimeException('stock_adjustment_identity_conflict');}
        $data=json_decode($row['data'],true,32,JSON_THROW_ON_ERROR);
        if(($data['product_id']??null)!==$params['product_id']||($data['adjustment_id']??null)!==$params['adjustment_id']||($data['delta']??null)!==$params['changes']['delta']||($data['reason']??null)!==$params['changes']['reason']){throw new RuntimeException('stock_adjustment_result_conflict');}
        return $data;
    }
    public static function recover(array $operation): array|WP_Error|null {
        try {if(($operation['action']??null)!==self::Action||!self::valid_params($operation['params']??null)){return null;}if(!current_user_can('manage_woocommerce')||!current_user_can('yoro_execute_actions')||!current_user_can('edit_post',$operation['params']['product_id'])){return new WP_Error('stock_adjustment_denied','Stock history is unavailable.');}$data=self::data($operation);return $data?self::result($data):null;}
        catch(Throwable $error){return new WP_Error('local_execution_outcome_unknown','Read the current stock and YoRo adjustment history; do not repeat this change.');}
    }
    private static function in_transaction(): bool {
        global $wpdb;$marker='yoro_stock_probe_'.bin2hex(random_bytes(8));$suppressed=$wpdb->suppress_errors(true);
        try {if($wpdb->query('SAVEPOINT '.$marker)===false){throw new RuntimeException('transaction_probe_unavailable');}return $wpdb->query('RELEASE SAVEPOINT '.$marker)!==false;}
        finally {$wpdb->suppress_errors($suppressed);}
    }
    public static function write(array $params): array|WP_Error {
        global $wpdb;$operation=self::$operation;$started=false;$committed=false;$marker='yoro_stock_tx_'.bin2hex(random_bytes(8));
        if(($params['_yoro_confirmed']??null)!==true||($params['_yoro_preview']??false)!==false||!$operation){return new WP_Error('stock_adjustment_confirmation_required','Use the confirmed stock executor.');}
        $params=array_diff_key($params,['_yoro_confirmed'=>true,'_yoro_preview'=>true]);
        if($params!==$operation['params']){return new WP_Error('stock_adjustment_identity_conflict','The confirmed adjustment does not match.');}
        try {
            if($prior=self::data($operation)){return self::result($prior);}
            if(self::in_transaction()){throw new RuntimeException('nested_transaction_unsupported');}
            $tables=[$wpdb->posts,$wpdb->postmeta,$wpdb->prefix.'wc_product_meta_lookup',$wpdb->options,YoRo_DB::stock_adjustments_table_name()];
            foreach($tables as $table){$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(strtolower((string)$engine)!=='innodb'||$wpdb->last_error!==''){throw new RuntimeException('transactional_stock_unavailable');}}
            if($wpdb->query('START TRANSACTION')===false){throw new RuntimeException('stock_transaction_unavailable');}$started=true;if($wpdb->query('SAVEPOINT '.$marker)===false){throw new RuntimeException('stock_transaction_marker_unavailable');}
            $global=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",'woocommerce_manage_stock'));if($wpdb->last_error!==''||$global!=='yes'){throw new RuntimeException('stock_management_disabled');}
            if(!$wpdb->get_row($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE",$params['product_id']))||$wpdb->last_error!==''){throw new RuntimeException('stock_product_unavailable');}
            $meta=$wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id FOR UPDATE",$params['product_id']),ARRAY_A);
            if($wpdb->last_error!==''){throw new RuntimeException('stock_lock_unavailable');}
            $stocks=array_values(array_filter($meta,static fn($row)=>$row['meta_key']==='_stock'));
            if(count($stocks)!==1||!preg_match('/\A-?(?:0|[1-9][0-9]{0,9})(?:\.0{1,12})?\z/D',(string)$stocks[0]['meta_value'])||(int)$stocks[0]['meta_value']!==$params['expected_stock_quantity']){throw new RuntimeException('stock_adjustment_conflict');}
            clean_post_cache($params['product_id']);$prepared=self::prepare($params);if(is_wp_error($prepared)){throw new RuntimeException('stock_adjustment_conflict');}
            if(self::$fence&&!((self::$fence)())){throw new RuntimeException('stock_fence_lost');}
            do_action('yoro_stock_adjustment_locked',$params['product_id']);
            $quantity=wc_update_product_stock($prepared['product'],abs($params['changes']['delta']),$params['changes']['delta']>0?'increase':'decrease');
            if($wpdb->query('RELEASE SAVEPOINT '.$marker)===false||$wpdb->query('SAVEPOINT '.$marker)===false){throw new RuntimeException('stock_transaction_interrupted');}
            $actual=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock'",$params['product_id']));
            if($wpdb->last_error!==''||!is_numeric($quantity)||(int)$quantity!==$prepared['after']||!is_string($actual)||preg_match('/\A-?(?:0|[1-9][0-9]{0,9})(?:\.0{1,12})?\z/D',$actual)!==1||(int)$actual!==$prepared['after']){throw new RuntimeException('stock_save_mismatch');}
            clean_post_cache($params['product_id']);$fresh=wc_get_product($params['product_id']);if(!$fresh||$fresh->get_stock_quantity('edit')!==$prepared['after']){throw new RuntimeException('stock_result_unavailable');}
            $data=['product_id'=>$params['product_id'],'adjustment_id'=>$params['adjustment_id'],'before_quantity'=>$params['expected_stock_quantity'],'stock_quantity'=>$prepared['after'],...$params['changes'],
                'current_version'=>YoRo_Commerce_Discovery_Service::product_version($fresh),'occurred_at'=>gmdate('c')];
            $saved=$wpdb->insert(YoRo_DB::stock_adjustments_table_name(),['adjustment_id'=>$params['adjustment_id'],'product_id'=>$params['product_id'],'actor_id'=>get_current_user_id(),'envelope_hash'=>hash('sha256',wp_json_encode($operation)),'data'=>wp_json_encode($data),'occurred_at'=>gmdate('Y-m-d H:i:s')],['%s','%d','%d','%s','%s','%s']);
            if($wpdb->query('RELEASE SAVEPOINT '.$marker)===false||$wpdb->query('SAVEPOINT '.$marker)===false){throw new RuntimeException('stock_transaction_interrupted');}
            if($saved!==1){throw new RuntimeException('stock_history_not_saved');}
            if($wpdb->query('COMMIT')===false){throw new RuntimeException('stock_commit_unknown');}$committed=true;
            if(self::data($operation)!==$data){throw new RuntimeException('stock_commit_unverified');}
            return self::result($data);
        }catch(Throwable $error){if($started&&!$committed){$wpdb->query('ROLLBACK');}return new WP_Error('local_execution_outcome_unknown','Read the current stock and YoRo adjustment history; do not repeat this change.');}
        finally{if($params['product_id']??null){clean_post_cache($params['product_id']);wc_delete_product_transients($params['product_id']);}}
    }
    public static function result(array $data): array { $message='Confirmed stock adjustment saved with its reason. History covers YoRo adjustments only.';return ['success'=>true,'action'=>self::Action,'message'=>$message,'summary'=>$message,'data'=>$data,'read_only'=>false,'undoable'=>false,'result_type'=>'commerce_write','display_type'=>'commerce_write','assistant_summary'=>$message,'extensions'=>(object)[]]; }
}
