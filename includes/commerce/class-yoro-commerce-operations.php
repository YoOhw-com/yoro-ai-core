<?php
if(!defined('ABSPATH')){exit;}

/** Stored configuration diagnostics and complete, bounded inventory analysis. */
final class YoRo_Commerce_Operations {
    public const ReadActions=['products.get_restock_report','products.get_stock_adjustments','checkout.get_shipping_configuration','checkout.get_tax_configuration','checkout.get_payment_methods'];
    public const ManifestExtension='yoro.early_access_commerce_operations';
    public static function manifest(): array {return ['version'=>1,'actions'=>self::ReadActions,'max_rows'=>50,'history_page_size'=>20,'batch_size'=>100,'report_execution_seconds'=>1800,'configuration'=>'stored_only','payments'=>'no_gateway_requests','result_version'=>1];}
    private static function object(array $properties): array{return ['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false];}
    public static function input_properties(string $action): array {
        if($action===self::ReadActions[0]){return [...array_diff_key(YoRo_Commerce_Insights::input_properties(''),array_flip(['comparison','comparison_from','comparison_to'])), 'focus'=>['type'=>'string','enum'=>['all','restock','slow_moving']]];}
        if($action===self::ReadActions[1]){return ['product_id'=>['type'=>'integer','minimum'=>1],'before_id'=>['type'=>'integer','minimum'=>0]];}
        return [];
    }
    public static function result_schema(string $action): array {
        $id=['type'=>'integer','minimum'=>0,'maximum'=>2147483647];$text=['type'=>'string','maxLength'=>500];$bool=['type'=>'boolean'];$decimal=['type'=>'string','pattern'=>'^-?(?:0|[1-9][0-9]{0,9})\\.[0-9]{6}$'];
        $base=['version'=>['type'=>'integer','enum'=>[1]],'action'=>['type'=>'string','enum'=>[$action]],'generated_at'=>['type'=>'string','format'=>'date-time']];
        if($action===self::ReadActions[0]){return self::object([...$base,'window'=>self::object(['key'=>['type'=>'string','maxLength'=>40],'label'=>['type'=>'string','maxLength'=>100],'start'=>['type'=>'string','format'=>'date-time'],'end'=>['type'=>'string','format'=>'date-time'],'timezone'=>['type'=>'string','maxLength'=>100]]),'date_basis'=>['type'=>'string','enum'=>['created','paid']],
            'week_starts_on'=>['type'=>'integer','minimum'=>0,'maximum'=>6],'days'=>['type'=>'integer','minimum'=>1,'maximum'=>366],'stock_management_enabled'=>['type'=>'boolean'],'coverage'=>['type'=>'string','enum'=>['all_catalog_stock_owners']],'products_scanned'=>$id,'group_count'=>$id,'rows_complete'=>$bool,'focus'=>['type'=>'string','enum'=>['all','restock','slow_moving']],
            'rows'=>['type'=>'array','maxItems'=>50,'items'=>self::object(['product_id'=>['type'=>'integer','minimum'=>1],'name'=>['type'=>'string','maxLength'=>160],'sku'=>['type'=>'string','maxLength'=>100],
                'stock_quantity'=>['type'=>'integer','minimum'=>-1000000000,'maximum'=>1000000000],'net_units'=>$decimal,'units_per_day'=>$decimal,'estimated_days_cover'=>['type'=>['string','null'],'pattern'=>'^(?:0|[1-9][0-9]{0,9})\\.[0-9]{6}$'],
                'low_stock_threshold'=>['type'=>'integer','minimum'=>0,'maximum'=>1000000000],'priority'=>['type'=>'string','enum'=>['out_of_stock','low_stock','reorder_soon','slow_moving','healthy']]])]]);}
        if($action===self::ReadActions[1]){return self::object([...$base,'product_id'=>['type'=>'integer','minimum'=>1],'scope'=>['type'=>'string','enum'=>['yoro_adjustments_only']],
            'rows'=>['type'=>'array','maxItems'=>20,'items'=>self::object(['history_id'=>['type'=>'integer','minimum'=>1],...YoRo_Stock_Adjustment::result_schema()['properties']])],'has_next_page'=>$bool,'next_before_id'=>['type'=>['integer','null'],'minimum'=>1]]);}
        if($action===self::ReadActions[2]){return self::object([...$base,'shipping_enabled'=>$bool,'truncated'=>$bool,'zones'=>['type'=>'array','maxItems'=>50,'items'=>self::object(['id'=>$id,'name'=>$text,
            'locations'=>['type'=>'array','maxItems'=>30,'items'=>self::object(['type'=>$text,'code'=>$text])],
            'methods'=>['type'=>'array','maxItems'=>20,'items'=>self::object(['instance_id'=>$id,'id'=>$text,'title'=>$text,'enabled'=>$bool,'supported'=>$bool,'cost'=>$text,'tax_status'=>$text,'requires'=>$text,'min_amount'=>$text,'ignore_discounts'=>$bool])]])]]);}
        if($action===self::ReadActions[3]){return self::object([...$base,'tax_enabled'=>$bool,'prices_include_tax'=>$bool,'tax_based_on'=>['type'=>'string','enum'=>['shipping','billing','base']],'truncated'=>$bool,
            'classes'=>['type'=>'array','maxItems'=>100,'items'=>$text],'rates'=>['type'=>'array','maxItems'=>100,'items'=>self::object(['id'=>$id,'country'=>$text,'state'=>$text,'name'=>$text,'rate'=>['type'=>'string','pattern'=>'^[0-9]{1,3}(?:\\.[0-9]{1,6})?$'],'class'=>$text,'priority'=>$id,'compound'=>$bool,'shipping'=>$bool,'locations'=>['type'=>'array','maxItems'=>30,'items'=>self::object(['type'=>$text,'code'=>$text])]])]]);}
        return self::object([...$base,'availability'=>['type'=>'string','enum'=>['configured_not_checkout_eligibility']],'coverage'=>['type'=>'string','enum'=>['native_and_saved_gateway_order']],'truncated'=>$bool,'methods'=>['type'=>'array','maxItems'=>50,'items'=>self::object(['id'=>$text,'title'=>$text,'enabled'=>$bool])]]);
    }
    public static function valid_params(string $action,$params): bool {
        if(!in_array($action,self::ReadActions,true)||!is_array($params)||array_diff(array_keys($params),array_keys(self::input_properties($action)))){return false;}
        if($action===self::ReadActions[0]){if(!isset($params['window'])||!is_int($params['limit']??null)||$params['limit']<1||$params['limit']>50||!in_array($params['focus']??null,['all','restock','slow_moving'],true)){return false;}try{$period=array_diff_key($params,['limit'=>true,'focus'=>true]);return YoRo_Store_Report_Period::parameters([...$period,'comparison'=>'none'])===[...$period,'comparison'=>'none'];}catch(Throwable $error){return false;}}
        if($action===self::ReadActions[1]){return count($params)===2&&is_int($params['product_id']??null)&&$params['product_id']>0&&is_int($params['before_id']??null)&&$params['before_id']>=0;}
        return $params===[];
    }
    public static function register(): void {
        foreach(self::ReadActions as $action){$properties=self::input_properties($action);$input=[];foreach($properties as $key=>$schema){$input[$key]=$schema['type'];}
            YoRo_Action_Registry::register($action,['label'=>str_replace(['.','_'],' ',$action),'description'=>'Bounded inventory analysis and stored checkout diagnostics. No checkout, payment or configuration mutation.',
                'category'=>'commerce','permission'=>'yoro_view_store_data','callback'=>static fn($params)=>self::read($action,array_diff_key((array)$params,['_yoro_preview'=>true,'_yoro_confirmed'=>true])),
                'risk_level'=>'low','write_action'=>false,'requires_confirmation'=>false,'effect'=>'read','reversibility'=>'full','target_type'=>'store','max_items'=>50,'input_schema'=>$input,'canonical_input_schema'=>$properties,'required_params'=>$action===self::ReadActions[0]?['window','limit','focus']:($action===self::ReadActions[1]?['product_id','before_id']:[]),'capabilities'=>['commerce','read']]);}
    }
    private static function text($value,int $max=500): string{return mb_substr(sanitize_text_field((string)$value),0,$max);}
    private static function result(string $action,array $data): array {$message='Stored commerce information read. Estimates and configured settings are not a live checkout quotation.';return ['success'=>true,'action'=>$action,'message'=>$message,'summary'=>$message,'data'=>$data,'read_only'=>true,'undoable'=>false,'result_type'=>'commerce_operations','display_type'=>'commerce_operations','assistant_summary'=>$message,'extensions'=>(object)[]];}
    public static function execute(array $operation,$guard=null): array|WP_Error {
        $action=$operation['action']??'';$params=$operation['params']??null;
        if(!self::valid_params($action,$params)||!current_user_can('yoro_view_store_data')||!current_user_can('manage_woocommerce')){return new WP_Error('commerce_operations_denied','Commerce information is unavailable.');}
        if($action===self::ReadActions[0]){
            $scope=hash('sha256',wp_json_encode([$action,$params]));$report=$operation;$report['params']=array_diff_key($params,['limit'=>true,'focus'=>true]);$report['params']['comparison']='none';
            $data=YoRo_Complete_Store_Report::run($report,static fn()=>current_user_can('manage_woocommerce')&&(!is_callable($guard)||$guard()),['scope_key'=>$scope,'predicate'=>static fn()=> '1=1','matches'=>static fn()=>true,'summary_only'=>true,
                'consume'=>[self::class,'consume'],'finalize'=>static fn(&$state)=>self::inventory_batch($state,$params),'finish'=>static fn($state)=>self::inventory_result($state,$params)]);
            return is_wp_error($data)||!empty($data['deferred'])?$data:self::result($action,$data);
        }
        return self::read($action,$params);
    }
    public static function read(string $action,array $params): array|WP_Error {
        if(!self::valid_params($action,$params)||!current_user_can('yoro_view_store_data')||!current_user_can('manage_woocommerce')){return new WP_Error('commerce_operations_denied','Commerce information is unavailable.');}
        if($action===self::ReadActions[0]){return new WP_Error('restock_runner_required','Use the bounded report runner.');}
        try{$base=['version'=>1,'action'=>$action,'generated_at'=>gmdate('c')];
            $data=match($action){self::ReadActions[1]=>self::history($params),self::ReadActions[2]=>self::shipping(),self::ReadActions[3]=>self::taxes(),self::ReadActions[4]=>self::payments()};
            if(strlen(wp_json_encode([...$base,...$data]))>42000){throw new RuntimeException('configuration_capacity');}return self::result($action,[...$base,...$data]);
        }catch(Throwable $error){return new WP_Error('commerce_configuration_unavailable','The stored configuration cannot be safely represented.');}
    }
    private static function history(array $params): array {
        global $wpdb;$product=wc_get_product($params['product_id']);if(!$product||!current_user_can('edit_post',$product->get_id())){throw new RuntimeException('history_denied');}
        $table=YoRo_DB::stock_adjustments_table_name();$rows=$wpdb->get_results($wpdb->prepare("SELECT id,data FROM {$table} WHERE product_id=%d AND (%d=0 OR id<%d) ORDER BY id DESC LIMIT 21",$params['product_id'],$params['before_id'],$params['before_id']),ARRAY_A);
        if($wpdb->last_error!==''){throw new RuntimeException('history_unavailable');}$more=count($rows)>20;$rows=array_slice($rows,0,20);$items=[];
        foreach($rows as $row){$data=json_decode($row['data'],true,32,JSON_THROW_ON_ERROR);if(($data['product_id']??null)!==$params['product_id']){throw new RuntimeException('history_identity');}$items[]=['history_id'=>(int)$row['id'],...$data];}
        return ['product_id'=>$params['product_id'],'scope'=>'yoro_adjustments_only','rows'=>$items,'has_next_page'=>$more,'next_before_id'=>$more?(int)end($rows)['id']:null];
    }
    public static function consume(array &$state,$order): void {
        if(!in_array($state['phase'],[0,2],true)){return;}$refund=$state['phase']===2;if(!$refund&&!in_array($order->get_status(),['processing','completed','refunded'],true)){return;}
        $parent=$refund?wc_get_order($order->get_parent_id()):$order;if(!$parent){throw new RuntimeException('restock_refund_unavailable');}
        foreach($order->get_items('line_item') as $item){$source=$refund?$parent->get_item((int)$item->get_meta('_refunded_item_id',true)):$item;if(!$source){continue;}$product=wc_get_product($source->get_variation_id()?:$source->get_product_id());if(!$product){continue;}
            $id=$product->get_stock_managed_by_id();$units=YoRo_Complete_Store_Report::units((string)$item->get_quantity(),true);$units=$refund?-abs($units):$units;
            $state['stock_units'][$id]=($state['stock_units'][$id]??0)+$units;if(count($state['stock_units'])>10000||abs($state['stock_units'][$id])>YoRo_Complete_Store_Report::MAX_UNITS){throw new RuntimeException('restock_capacity');}}
    }
    public static function inventory_batch(array &$state,array $params): bool {
        global $wpdb;if(!isset($state['inventory'])){$state['inventory']=['cursor'=>0,'anchor'=>(int)$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"),'scanned'=>0,'count'=>0,'rows'=>[]];}
        if($wpdb->last_error!==''){throw new RuntimeException('restock_catalog_anchor_unavailable');}
        $part=&$state['inventory'];$ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND ID<=%d AND post_type IN ('product','product_variation') AND post_status='publish' ORDER BY ID ASC LIMIT 100",$part['cursor'],$part['anchor']));if($wpdb->last_error!==''){throw new RuntimeException('restock_catalog_unavailable');}
        $window=$state['windows'][0];$days=(new DateTimeImmutable(substr($window['start'],0,10)))->diff(new DateTimeImmutable(substr($window['end'],0,10)))->days+1;
        foreach($ids as $id){$part['cursor']=(int)$id;$part['scanned']++;$product=wc_get_product($id);if(!$product){throw new RuntimeException('restock_product_unavailable');}
            if(!in_array($product->get_type(),['simple','variable','variation'],true)||$product->get_manage_stock('edit')!==true||!$product->managing_stock()||$product->get_stock_managed_by_id()!==$product->get_id()){continue;}
            if($product->is_type('variation')&&(!($parent=wc_get_product($product->get_parent_id()))||$parent->get_status()!=='publish')){continue;}
            $quantity=$product->get_stock_quantity('edit');if(!is_int($quantity)||abs($quantity)>1000000000){throw new RuntimeException('restock_fractional_stock');}
            $units=$state['stock_units'][$id]??0;$velocity=(int)floor(max(0,$units)/$days);$cover=$velocity>0?(int)min(9000000000000000,floor(max(0,$quantity)*1000000000000/$velocity)):null;
            $threshold=(int)wc_get_low_stock_amount($product);if($threshold<0||$threshold>1000000000){throw new RuntimeException('restock_threshold');}
            $priority=$quantity<=0?'out_of_stock':($quantity<=$threshold?'low_stock':($velocity>0&&$cover<14000000?'reorder_soon':($units<=0?'slow_moving':'healthy')));
            if(($params['focus']==='restock'&&!in_array($priority,['out_of_stock','low_stock','reorder_soon'],true))||($params['focus']==='slow_moving'&&$priority!=='slow_moving')){continue;}
            $part['count']++;$part['rows'][]=['product_id'=>(int)$id,'name'=>self::text($product->get_name(),160),'sku'=>self::text($product->get_sku(),100),'stock_quantity'=>$quantity,'net_units'=>YoRo_Complete_Store_Report::decimal($units),'units_per_day'=>YoRo_Complete_Store_Report::decimal($velocity),'estimated_days_cover'=>$cover===null?null:YoRo_Complete_Store_Report::decimal($cover),'low_stock_threshold'=>$threshold,'priority'=>$priority];
            usort($part['rows'],static function($a,$b){$rank=['out_of_stock'=>0,'low_stock'=>1,'reorder_soon'=>2,'slow_moving'=>3,'healthy'=>4];return ($rank[$a['priority']]<=>$rank[$b['priority']])?:((float)($a['estimated_days_cover']??PHP_INT_MAX)<=>(float)($b['estimated_days_cover']??PHP_INT_MAX))?:($a['product_id']<=>$b['product_id']);});$part['rows']=array_slice($part['rows'],0,$params['limit']);
        }
        return count($ids)<100;
    }
    private static function inventory_result(array $state,array $params): array|WP_Error {
        if($state['periods'][0]['invalid_orders']){return new WP_Error('restock_invalid_orders','Some orders could not be assessed.');}
        $part=$state['inventory'];$window=$state['windows'][0];$days=(new DateTimeImmutable(substr($window['start'],0,10)))->diff(new DateTimeImmutable(substr($window['end'],0,10)))->days+1;
        return ['version'=>1,'action'=>self::ReadActions[0],'generated_at'=>gmdate('c',$state['as_of']),'window'=>$window,'date_basis'=>$state['basis'],'week_starts_on'=>$state['period_context']['week_starts_on'],'days'=>$days,'stock_management_enabled'=>get_option('woocommerce_manage_stock')==='yes','coverage'=>'all_catalog_stock_owners','products_scanned'=>$part['scanned'],'group_count'=>$part['count'],'rows_complete'=>$part['count']===count($part['rows']),'focus'=>$params['focus'],'rows'=>$part['rows']];
    }
    private static function stored_option(string $name): array {
        global $wpdb;$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name));
        if($wpdb->last_error!==''||($raw!==null&&strlen($raw)>262144)){throw new RuntimeException('stored_setting_unavailable');}
        if($raw===null){return [];}$value=is_serialized($raw)?unserialize($raw,['allowed_classes'=>false]):null;
        if(!is_array($value)){throw new RuntimeException('stored_setting_invalid');}return $value;
    }
    private static function setting(array $settings,string $key,string $default=''): string {
        $value=$settings[$key]??$default;if(!is_scalar($value)&&$value!==null){throw new RuntimeException('stored_scalar_invalid');}return self::text($value);
    }
    private static function shipping(): array {
        global $wpdb;$zones=$wpdb->get_results("SELECT zone_id,zone_name FROM {$wpdb->prefix}woocommerce_shipping_zones ORDER BY zone_order,zone_id LIMIT 50",ARRAY_A);
        if($wpdb->last_error!==''){throw new RuntimeException('shipping_configuration_unavailable');}$truncated=count($zones)>49;$zones=array_slice($zones,0,49);$zones[]=['zone_id'=>0,'zone_name'=>'Rest of the world'];$result=[];
        foreach($zones as $zone){$id=(int)$zone['zone_id'];$raw=$wpdb->get_results($wpdb->prepare("SELECT location_type,location_code FROM {$wpdb->prefix}woocommerce_shipping_zone_locations WHERE zone_id=%d ORDER BY location_id LIMIT 31",$id),ARRAY_A);if($wpdb->last_error!==''){throw new RuntimeException('shipping_locations_unavailable');}$truncated=$truncated||count($raw)>30;$locations=[];foreach(array_slice($raw,0,30)as$location){$locations[]=['type'=>self::text($location['location_type']),'code'=>self::text($location['location_code'])];}
            $raw=$wpdb->get_results($wpdb->prepare("SELECT instance_id,method_id,is_enabled FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id=%d ORDER BY method_order,instance_id LIMIT 21",$id),ARRAY_A);if($wpdb->last_error!==''){throw new RuntimeException('shipping_methods_unavailable');}$truncated=$truncated||count($raw)>20;$methods=[];
            foreach(array_slice($raw,0,20)as$method){$methodId=(string)$method['method_id'];$supported=in_array($methodId,['flat_rate','free_shipping','local_pickup'],true);$settings=$supported?self::stored_option('woocommerce_'.$methodId.'_'.(int)$method['instance_id'].'_settings'):[];
                $defaults=['flat_rate'=>'Flat rate','free_shipping'=>'Free shipping','local_pickup'=>'Local pickup'];$methods[]=['instance_id'=>(int)$method['instance_id'],'id'=>self::text($methodId),'title'=>$supported?self::setting($settings,'title',$defaults[$methodId]):self::text($methodId),'enabled'=>$method['is_enabled']==='1','supported'=>$supported,
                    'cost'=>$supported?self::setting($settings,'cost'):'','tax_status'=>$supported?self::setting($settings,'tax_status'):'','requires'=>$supported?self::setting($settings,'requires'):'','min_amount'=>$supported?self::setting($settings,'min_amount'):'','ignore_discounts'=>$supported&&self::setting($settings,'ignore_discounts','no')==='yes'];}
            $result[]=['id'=>$id,'name'=>self::text($zone['zone_name']),'locations'=>$locations,'methods'=>$methods];}
        return ['shipping_enabled'=>wc_shipping_enabled(),'truncated'=>$truncated,'zones'=>$result];
    }
    private static function taxes(): array {
        global $wpdb;$table=$wpdb->prefix.'woocommerce_tax_rates';$rows=$wpdb->get_results("SELECT tax_rate_id,tax_rate_country,tax_rate_state,tax_rate,tax_rate_name,tax_rate_priority,tax_rate_compound,tax_rate_shipping,tax_rate_class FROM {$table} ORDER BY tax_rate_id LIMIT 101",ARRAY_A);if($wpdb->last_error!==''){throw new RuntimeException('tax_configuration_unavailable');}
        $truncated=count($rows)>100;$result=[];foreach(array_slice($rows,0,100)as$row){$id=(int)$row['tax_rate_id'];$locations=$wpdb->get_results($wpdb->prepare("SELECT location_type,location_code FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id=%d ORDER BY location_id LIMIT 31",$id),ARRAY_A);if($wpdb->last_error!==''){throw new RuntimeException('tax_location_unavailable');}$truncated=$truncated||count($locations)>30;
            $result[]=['id'=>$id,'country'=>self::text($row['tax_rate_country']),'state'=>self::text($row['tax_rate_state']),'name'=>self::text($row['tax_rate_name']),'rate'=>(string)$row['tax_rate'],'class'=>self::text($row['tax_rate_class']),'priority'=>(int)$row['tax_rate_priority'],'compound'=>$row['tax_rate_compound']==='1','shipping'=>$row['tax_rate_shipping']==='1','locations'=>array_map(static fn($location)=>['type'=>self::text($location['location_type']),'code'=>self::text($location['location_code'])],array_slice($locations,0,30))];}
        $classes=WC_Tax::get_tax_classes();$truncated=$truncated||count($classes)>99;
        return ['tax_enabled'=>wc_tax_enabled(),'prices_include_tax'=>wc_prices_include_tax(),'tax_based_on'=>(string)get_option('woocommerce_tax_based_on','shipping'),'truncated'=>$truncated,'classes'=>['Standard',...array_map([self::class,'text'],array_slice($classes,0,99))],'rates'=>$result];
    }
    private static function payments(): array {
        // No gateway classes are instantiated. Secrets, account data and provider APIs are excluded.
        $native=['bacs'=>'Direct bank transfer','cheque'=>'Check payments','cod'=>'Cash on delivery','paypal'=>'PayPal Standard'];$order=self::stored_option('woocommerce_gateway_order');
        $ids=array_values(array_unique([...array_keys($order),...array_keys($native)]));$truncated=count($ids)>50;$rows=[];
        foreach(array_slice($ids,0,50)as$id){if(!is_string($id)||preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D',$id)!==1){throw new RuntimeException('payment_id_invalid');}$settings=self::stored_option('woocommerce_'.$id.'_settings');$rows[]=['id'=>$id,'title'=>self::setting($settings,'title',$native[$id]??$id),'enabled'=>self::setting($settings,'enabled','no')==='yes'];}
        return ['availability'=>'configured_not_checkout_eligibility','coverage'=>'native_and_saved_gateway_order','truncated'=>$truncated,'methods'=>$rows];
    }
}
