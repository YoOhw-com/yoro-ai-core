<?php

use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;

if(!defined('ABSPATH')) {exit;}

/** Native allocations and recoverable cancellation. No carrier, payment or delivery claim. */
final class YoRo_Fulfillment_Management
{
    public const ReadActions=['fulfillment.get_details'];
    public const WriteActions=['fulfillment.create','fulfillment.update','fulfillment.cancel'];
    public const ManifestExtension='yoro.early_access_fulfillment_management';

    private static function schema(): array {static $schema;return $schema??=json_decode(self::SchemaJson,true,512,JSON_THROW_ON_ERROR);}
    private static function invalid(string $reason='unsupported_integration'): never {throw new RuntimeException($reason);}
    public static function supported(): bool
    {
        return YoRo_Order_Management::tracking_available()&&method_exists(Fulfillment::class,'set_props_from_storage')&&method_exists(Fulfillment::class,'snapshot_meta');
    }
    public static function manifest(): array
    {
        $manifest=self::schema()['manifest'];$manifest['native_supported']=self::supported();
        if(!$manifest['native_supported']) {$manifest['actions']=self::ReadActions;}
        return $manifest;
    }
    public static function properties(string $action): array {return self::schema()['write_properties'][$action]??[];}
    public static function input_properties(string $action): array
    {
        if($action===self::ReadActions[0]) {return self::schema()['read_properties'];}
        return ['order_id'=>['type'=>'integer','minimum'=>1],'changes'=>['type'=>'object','properties'=>self::properties($action),'required'=>self::schema()['required_changes'][$action],'additionalProperties'=>false],
            'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']];
    }
    public static function result_schema(string $action): array {return $action===self::ReadActions[0]?self::schema()['context_schema']:self::schema()['result_schemas'][$action];}
    private static function items($rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)||!$rows||count($rows)>20) {self::invalid('bounds_exceeded');}
        $result=[];
        foreach($rows as$row) {
            if(!is_array($row)||count($row)!==2||array_diff(array_keys($row),['item_id','quantity'])||!is_int($row['item_id']??null)||$row['item_id']<1||!is_int($row['quantity']??null)||$row['quantity']<1||$row['quantity']>1000000||isset($result[$row['item_id']])) {self::invalid();}
            $result[$row['item_id']]=['item_id'=>$row['item_id'],'quantity'=>$row['quantity']];
        }
        ksort($result,SORT_NUMERIC);return array_values($result);
    }
    public static function changes($input,string $action): array
    {
        if(!in_array($action,self::WriteActions,true)||!is_array($input)||!$input||array_is_list($input)||array_diff(array_keys($input),array_keys(self::properties($action)))) {self::invalid();}
        $changes=$input;$changes['notify_customer']=$changes['notify_customer']??false;
        if(array_diff(self::schema()['required_changes'][$action],array_keys($changes))||!is_bool($changes['notify_customer'])) {self::invalid();}
        if($action!=='fulfillment.create'&&(!is_int($changes['fulfillment_id'])||$changes['fulfillment_id']<1)) {self::invalid();}
        foreach(['items','split_items']as$key) {if(array_key_exists($key,$changes)) {$changes[$key]=self::items($changes[$key]);}}
        if(isset($changes['status'])&&!in_array($changes['status'],['unfulfilled','fulfilled'],true)) {self::invalid();}
        if($action==='fulfillment.update'&&!array_key_exists('items',$changes)&&!array_key_exists('status',$changes)) {self::invalid();}
        if(isset($changes['split_items'])&&(!isset($changes['items'])||isset($changes['status'])&&$changes['status']!=='unfulfilled'||$changes['notify_customer'])) {self::invalid();}
        return YoRo_Order_Edit::canonical($changes);
    }
    public static function valid_params(string $action,$params): bool
    {
        if(!is_array($params)||!is_int($params['order_id']??null)||$params['order_id']<1) {return false;}
        if($action===self::ReadActions[0]) {return !array_diff(array_keys($params),['order_id','page'])&&(!isset($params['page'])||is_int($params['page'])&&$params['page']>=1&&$params['page']<=1000);}
        try{return count($params)===3&&!array_diff(array_keys($params),['order_id','changes','expected_version'])&&is_string($params['expected_version']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$params['expected_version'])===1&&self::changes($params['changes']??null,$action);}catch(Throwable $error){return false;}
    }
    private static function order(int $id): WC_Order
    {
        $order=wc_get_order($id);
        if(!$order instanceof WC_Order||$order->get_type()!=='shop_order'||!current_user_can('manage_woocommerce')||!current_user_can('yoro_view_store_data')||!current_user_can('yoro_execute_actions')||!current_user_can('edit_shop_order',$id)) {self::invalid();}
        return $order;
    }
    /** SQL bounds run before native metadata hydration, including cancelled history. */
    private static function rows(int $id,int $page): array
    {
        global $wpdb;$table=$wpdb->prefix.'wc_order_fulfillments';$meta=$wpdb->prefix.'wc_order_fulfillment_meta';
        $count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE entity_type=%s AND entity_id=%s",WC_Order::class,(string)$id));
        if($wpdb->last_error!==''||!is_numeric($count)) {self::invalid();}
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE entity_type=%s AND entity_id=%s ORDER BY fulfillment_id ASC LIMIT 21 OFFSET %d",WC_Order::class,(string)$id,($page-1)*20),ARRAY_A);
        if($wpdb->last_error!==''||!is_array($rows)) {self::invalid();}
        $records=[];$facts=[];$bounded=true;
        foreach(array_slice($rows,0,20)as$row) {
            $stats=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS n,COALESCE(SUM(LENGTH(meta_key)+LENGTH(meta_value)),0) AS bytes FROM $meta WHERE fulfillment_id=%d",(int)$row['fulfillment_id']),ARRAY_A);
            if($wpdb->last_error!==''||!$stats||(int)$stats['n']>40||(int)$stats['bytes']>16384) {$bounded=false;continue;}
            $raw=$wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_key,meta_value FROM $meta WHERE fulfillment_id=%d ORDER BY meta_id ASC LIMIT 41",(int)$row['fulfillment_id']),ARRAY_A);
            if($wpdb->last_error!==''||!is_array($raw)) {self::invalid();}
            $record=new Fulfillment();$record->set_id((int)$row['fulfillment_id']);$record->set_props_from_storage($row);$record->apply_changes();$record->set_object_read(true);$record->read_meta_data(true);$record->snapshot_meta();
            $records[(int)$row['fulfillment_id']]=$record;$facts[]=['row'=>$row,'meta'=>$raw];
        }
        return ['count'=>(int)$count,'records'=>$records,'facts'=>$facts,'bounded'=>$bounded,'has_next'=>count($rows)>20];
    }
    private static function native_items(Fulfillment $record): array
    {
        $items=$record->get_items();if(!is_array($items)||count($items)>20) {self::invalid('bounds_exceeded');}
        $rows=[];foreach($items as$item) {if(!is_array($item)||count($item)!==2||array_diff(array_keys($item),['item_id','qty'])) {self::invalid();}$rows[]=['item_id'=>$item['item_id'],'quantity'=>$item['qty']];}
        return self::items($rows);
    }
    private static function date(?string $date): ?string {return $date===null?null:(new DateTimeImmutable($date,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');}
    private static function package(Fulfillment $record): array
    {
        $carrier=$record->get_shipment_provider()??'';$number=$record->get_tracking_number()??'';
        foreach([$carrier,$number]as$text) {if(!is_string($text)||strlen($text)>100||preg_match('/[\x00-\x1f\x7f<>]/',$text)||!preg_match('//u',$text)) {self::invalid();}}
        $status=$record->get_status();if(!in_array($status,['unfulfilled','fulfilled'],true)||$record->get_is_fulfilled()!==($status==='fulfilled')) {self::invalid();}
        return ['fulfillment_id'=>$record->get_id(),'status'=>$record->get_date_deleted()!==null?'cancelled':$status,'items'=>self::native_items($record),'tracking'=>['carrier'=>$carrier,'tracking_number'=>$number],
            'created_at'=>null,'fulfilled_at'=>self::date($record->get_date_fulfilled()),'updated_at'=>self::date($record->get_date_updated()),'cancelled_at'=>self::date($record->get_date_deleted())];
    }
    private static function state(int $id,int $page=1): array
    {
        $order=self::order($id);$supported=self::supported();$rows=$supported?self::rows($id,$page):['count'=>0,'records'=>[],'facts'=>[],'bounded'=>true,'has_next'=>false];
        $complete=$rows['count']<=20&&$rows['bounded'];$orderCount=0;global $wpdb;
        $orderCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id=%d AND order_item_type='line_item'",$id));
        if($wpdb->last_error!=='') {self::invalid();}
        $complete=$complete&&$orderCount<=20;$packages=[];$allocation=[];$invalid=false;
        foreach($rows['records']as$record) {
            try{$package=self::package($record);$packages[]=$package;
                if($package['status']!=='cancelled') {foreach($package['items']as$item) {$allocation[$item['item_id']]=($allocation[$item['item_id']]??0)+$item['quantity'];}}
                if($record->is_locked()) {$invalid=true;}
            }catch(Throwable $error){$complete=false;}
        }
        $items=[];
        if($complete) {foreach($order->get_items('line_item')as$id=>$item) {
            $quantity=$item->get_quantity('edit');if(!is_numeric($quantity)||(int)$quantity!=$quantity||$quantity<1||$quantity>1000000||($item->get_variation_id('edit')?:$item->get_product_id('edit'))<1||($allocation[$id]??0)>$quantity||$order->get_qty_refunded_for_item($id)!=0) {$invalid=true;continue;}
            $items[]=['item_id'=>(int)$id,'product_id'=>$item->get_variation_id('edit')?:$item->get_product_id('edit'),'name'=>mb_strcut(wp_strip_all_tags($item->get_name('edit')),0,200,'UTF-8'),'quantity'=>(int)$quantity,'allocated_quantity'=>$allocation[$id]??0,'remaining_quantity'=>(int)$quantity-($allocation[$id]??0)];
        }$ids=array_column($items,'item_id');if(array_diff(array_keys($allocation),$ids)) {$invalid=true;}}
        $reason=!$supported?'unsupported_native_fulfillments':(!$complete?'bounds_exceeded':($invalid?'unsupported_integration':(!in_array($order->get_status('edit'),['pending','on-hold','processing','completed'],true)||wc_get_orders(['type'=>'shop_order_refund','parent'=>$order->get_id(),'limit'=>1,'return'=>'ids'])?'unsupported_order_state':null)));
        $version=YoRo_Order_Edit::hash(['order'=>[$order->get_id(),$order->get_status('edit'),$order->get_currency('edit'),$order->get_total('edit'),$order->get_customer_id('edit'),$order->get_date_modified('edit')?->format(DATE_ATOM),$items,$orderCount],'packages'=>$rows['facts'],'count'=>$rows['count'],'supported'=>$supported,'caps'=>[current_user_can('manage_woocommerce'),current_user_can('edit_shop_order',$order->get_id()),current_user_can('yoro_execute_actions')]]);
        $data=['target'=>['id'=>$order->get_id(),'version'=>$version],'supported'=>$supported,'editable'=>$reason===null,'reason'=>$reason,'items'=>$items,'items_truncated'=>!$complete,'packages'=>$packages,'packages_truncated'=>!$rows['bounded']||$rows['count']>20||count($packages)!==count($rows['records']),
            'page_info'=>['page'=>$page,'per_page'=>20,'has_next_page'=>$rows['has_next']&&$page<1000,'next_page'=>$rows['has_next']&&$page<1000?$page+1:null],'notification_supported'=>$supported];
        if(strlen(wp_json_encode($data))>49152) {self::invalid('bounds_exceeded');}
        return ['order'=>$order,'records'=>$rows['records'],'data'=>$data];
    }
    public static function execute_read(array $operation): array|WP_Error
    {
        $params=$operation['params']??[];if(!self::valid_params($operation['action']??'',$params)) {return new WP_Error('fulfillment_read_unavailable','Use an exact order and bounded page.');}
        try{return self::result(self::ReadActions[0],self::state($params['order_id'],$params['page']??1)['data'],true);}catch(Throwable $error){return new WP_Error('fulfillment_read_unavailable','The bounded native fulfillment source is unavailable.');}
    }
    public static function prepare(array $params,string $action): array|WP_Error
    {
        unset($params['_yoro_confirmed'],$params['_yoro_preview']);
        try{
            if(!self::valid_params($action,$params)) {self::invalid();}$state=self::state($params['order_id']);$changes=self::changes($params['changes'],$action);
            if(!$state['data']['editable']||!hash_equals($state['data']['target']['version'],$params['expected_version'])) {self::invalid();}
            $record=$action==='fulfillment.create'?null:($state['records'][$changes['fulfillment_id']]??null);
            if($action!=='fulfillment.create'&&(!$record||$record->get_date_deleted()!==null||$record->is_locked())) {self::invalid();}
            if($action==='fulfillment.cancel'&&$record->get_status()!=='unfulfilled') {self::invalid();}
            if($action==='fulfillment.update'&&isset($changes['items'])&&$record->get_status()!=='unfulfilled') {self::invalid();}
            if(isset($changes['split_items'])) {
                if($record->get_status()!=='unfulfilled'||count($state['records'])>=20) {self::invalid();}
                $old=array_column(self::native_items($record),'quantity','item_id');$sum=[];
                foreach([...$changes['items'],...$changes['split_items']]as$item){$sum[$item['item_id']]=($sum[$item['item_id']]??0)+$item['quantity'];}
                ksort($old,SORT_NUMERIC);ksort($sum,SORT_NUMERIC);if($old!==$sum) {self::invalid();}
            }
            if($changes['notify_customer']&&($action!=='fulfillment.update'||$record->get_status()!=='unfulfilled'||($changes['status']??null)!=='fulfilled'||isset($changes['split_items']))) {self::invalid();}
            $available=array_column($state['data']['items'],'remaining_quantity','item_id');
            if($record) {foreach(self::native_items($record)as$item) {$available[$item['item_id']]=($available[$item['item_id']]??0)+$item['quantity'];}}
            foreach($changes['items']??[]as$item) {if(!isset($available[$item['item_id']])||$item['quantity']>$available[$item['item_id']]) {self::invalid();}}
            if($action==='fulfillment.create'&&count($state['records'])>=20) {self::invalid('bounds_exceeded');}
            if($action==='fulfillment.update'&&!isset($changes['split_items'])&&(!isset($changes['items'])||$changes['items']===self::native_items($record))&&(!isset($changes['status'])||$changes['status']===$record->get_status())) {self::invalid();}
            return [...$state,'changes'=>$changes,'record'=>$record];
        }catch(Throwable $error){return new WP_Error('fulfillment_write_unavailable','Use a fresh complete editable native fulfillment source and exact available quantities.');}
    }
    public static function preview(array $params,string $action): array|WP_Error
    {
        $state=self::prepare($params,$action);if(is_wp_error($state)) {return $state;}
        return ['success'=>true,'read_only'=>true,'undoable'=>false,'order_id'=>$params['order_id'],'before'=>$state['data'],'after'=>$state['changes'],'effects'=>[
            $action==='fulfillment.cancel'?'Cancel the unfulfilled native record; keep its row and metadata as history and release its allocations.':(isset($state['changes']['split_items'])?'Save the retained allocation, then create one separate unfulfilled native record; partial failure stops as unknown.':'Save only the reviewed native allocations and status.'),
            $state['changes']['notify_customer']?'Request the normal configured WooCommerce fulfillment notification after the confirmed transition; delivery is not verified.':'No customer notification is requested.','No carrier request, label purchase, payment or delivery verification is performed.']];
    }
    private static function save_items(Fulfillment $record,array $items): void {$record->set_items(array_map(static fn($item)=>['item_id'=>$item['item_id'],'qty'=>$item['quantity']],$items));}
    public static function write(array $params,string $action): array|WP_Error
    {
        if(($params['_yoro_confirmed']??null)!==true||($params['_yoro_preview']??false)!==false) {return new WP_Error('fulfillment_confirmation_required','Use the confirmed native fulfillment executor.');}
        $state=self::prepare($params,$action);if(is_wp_error($state)) {return $state;}$changes=$state['changes'];$created=null;
        try{
            $record=$state['record'];$savedId=$record?->get_id();$previousStatus=$record?->get_status();$orderId=$state['order']->get_id();$status=$state['order']->get_status('edit');
            if($action==='fulfillment.create') {$record=new Fulfillment();$record->set_entity_type(WC_Order::class);$record->set_entity_id((string)$orderId);$record->set_status('unfulfilled');self::save_items($record,$changes['items']);$record->save();$created=$record->get_id();$savedId=$created;}
            elseif($action==='fulfillment.cancel') {$record->delete(false);}
            else {
                if(isset($changes['items'])) {self::save_items($record,$changes['items']);}if(isset($changes['status'])) {$record->set_status($changes['status']);}$record->save();
                if(isset($changes['split_items'])) {$split=new Fulfillment();$split->set_entity_type(WC_Order::class);$split->set_entity_id((string)$orderId);$split->set_status('unfulfilled');self::save_items($split,$changes['split_items']);$split->save();$created=$split->get_id();}
            }
            // Read from native storage before triggering any explicitly requested notification.
            $after=self::state($orderId);if($after['order']->get_status('edit')!==$status||!$after['data']['editable']) {self::invalid();}
            $saved=$after['records'][$savedId]??null;if(!$saved) {self::invalid();}
            if($action==='fulfillment.cancel') {if($saved->get_date_deleted()===null) {self::invalid();}}
            elseif(self::native_items($saved)!==($changes['items']??self::native_items($state['record']))||$saved->get_status()!==($changes['status']??$previousStatus??'unfulfilled')) {self::invalid();}
            if($created!==null) {$new=$after['records'][$created]??null;if(!$new||$new->get_date_deleted()!==null||$new->get_status()!=='unfulfilled'||self::native_items($new)!==($changes['split_items']??$changes['items'])) {self::invalid();}}
            if($changes['notify_customer']) {do_action('woocommerce_fulfillment_created_notification',$orderId,$saved,wc_get_order($orderId));}
            $final=self::state($orderId);if($final['data']['target']['version']!==$after['data']['target']['version']) {self::invalid();}
            return self::result($action,['order_id'=>$orderId,'changes'=>$changes,'created_fulfillment_id'=>$created,'current_version'=>$final['data']['target']['version']],false);
        }catch(Throwable $error){return new WP_Error('local_execution_outcome_unknown','Read native fulfillment history and remaining quantities; do not repeat this change.');}
    }
    private static function result(string $action,array $data,bool $read): array {return ['success'=>true,'action'=>$action,'message'=>'Native fulfillment record read or confirmed change completed.','summary'=>'Native fulfillment record read or confirmed change completed.','data'=>$data,'read_only'=>$read,'undoable'=>false,'result_type'=>'fulfillment_management','display_type'=>'fulfillment_management','assistant_summary'=>'Native fulfillment records do not verify carrier dispatch or delivery.','extensions'=>(object)[]];}
    public static function register(): void
    {
        foreach(self::manifest()['actions']as$action) {$write=in_array($action,self::WriteActions,true);$properties=self::input_properties($action);$input=[];foreach($properties as$field=>$schema){$input[$field]=$schema['type'];}
            YoRo_Action_Registry::register($action,['label'=>match($action){'fulfillment.create'=>'Create fulfillment','fulfillment.update'=>'Edit fulfillment','fulfillment.cancel'=>'Cancel unfulfilled package',default=>'Read native fulfillment records'},'description'=>'Bounded native allocations and recoverable cancellation; no carrier or delivery verification.','category'=>'orders','permission'=>$write?'yoro_execute_actions':'yoro_view_store_data','callback'=>$write?static fn($params)=>self::write($params,$action):static fn($params)=>self::execute_read(['action'=>$action,'params'=>$params]),'preview_callback'=>$write?static fn($params)=>self::preview($params,$action):static fn($params)=>self::execute_read(['action'=>$action,'params'=>$params]),'risk_level'=>$write?'medium':'low','write_action'=>$write,'requires_confirmation'=>$write,'effect'=>$write?'mutate':'read','reversibility'=>'none','target_type'=>'order','max_items'=>1,'input_schema'=>$input,'canonical_input_schema'=>$properties,'required_params'=>$write?array_keys($properties):['order_id'],'concurrency_strategy'=>$write?'optimistic_version':'','capabilities'=>['orders',$write?'write':'read']]);
        }
    }

    private const SchemaJson=<<<'YR0178_FULFILLMENT_SCHEMA'
{"manifest":{"version":1,"actions":["fulfillment.get_details","fulfillment.create","fulfillment.update","fulfillment.cancel"],"max_items":20,"max_targets":1,"requires_source_read":true,"requires_confirmation":true,"result_version":1,"native_supported":true},"read_properties":{"order_id":{"type":"integer","minimum":1},"page":{"type":"integer","minimum":1,"maximum":1000}},"write_properties":{"fulfillment.create":{"items":{"type":"array","minItems":1,"maxItems":20,"items":{"type":"object","properties":{"item_id":{"type":"integer","minimum":1},"quantity":{"type":"integer","minimum":1,"maximum":1000000}},"required":["item_id","quantity"],"additionalProperties":false}},"notify_customer":{"type":"boolean"}},"fulfillment.update":{"fulfillment_id":{"type":"integer","minimum":1},"items":{"type":"array","minItems":1,"maxItems":20,"items":{"type":"object","properties":{"item_id":{"type":"integer","minimum":1},"quantity":{"type":"integer","minimum":1,"maximum":1000000}},"required":["item_id","quantity"],"additionalProperties":false}},"split_items":{"type":"array","minItems":1,"maxItems":20,"items":{"type":"object","properties":{"item_id":{"type":"integer","minimum":1},"quantity":{"type":"integer","minimum":1,"maximum":1000000}},"required":["item_id","quantity"],"additionalProperties":false}},"status":{"type":"string","enum":["unfulfilled","fulfilled"]},"notify_customer":{"type":"boolean"}},"fulfillment.cancel":{"fulfillment_id":{"type":"integer","minimum":1},"notify_customer":{"type":"boolean"}}},"required_changes":{"fulfillment.create":["items","notify_customer"],"fulfillment.update":["fulfillment_id","notify_customer"],"fulfillment.cancel":["fulfillment_id","notify_customer"]},"context_schema":{"type":"object","properties":{"target":{"type":"object","properties":{"id":{"type":"integer","minimum":1},"version":{"type":"string","pattern":"^[a-f0-9]{64}$"}},"required":["id","version"],"additionalProperties":false},"supported":{"type":"boolean"},"editable":{"type":"boolean"},"reason":{"type":["string","null"],"enum":[null,"unsupported_native_fulfillments","unsupported_order_state","bounds_exceeded","unsupported_integration"]},"items":{"type":"array","maxItems":20,"items":{"type":"object","properties":{"item_id":{"type":"integer","minimum":1},"product_id":{"type":"integer","minimum":1},"name":{"type":"string","maxLength":200},"quantity":{"type":"integer","minimum":1,"maximum":1000000},"allocated_quantity":{"type":"integer","minimum":0,"maximum":1000000},"remaining_quantity":{"type":"integer","minimum":0,"maximum":1000000}},"required":["item_id","product_id","name","quantity","allocated_quantity","remaining_quantity"],"additionalProperties":false}},"items_truncated":{"type":"boolean"},"packages":{"type":"array","maxItems":20,"items":{"type":"object","properties":{"fulfillment_id":{"type":"integer","minimum":1},"status":{"type":"string","enum":["unfulfilled","fulfilled","cancelled"]},"items":{"type":"array","minItems":0,"maxItems":20,"items":{"type":"object","properties":{"item_id":{"type":"integer","minimum":1},"quantity":{"type":"integer","minimum":1,"maximum":1000000}},"required":["item_id","quantity"],"additionalProperties":false}},"tracking":{"type":"object","properties":{"carrier":{"type":"string","maxLength":100},"tracking_number":{"type":"string","maxLength":100}},"required":["carrier","tracking_number"],"additionalProperties":false},"created_at":{"type":["string","null"],"format":"date-time"},"updated_at":{"type":["string","null"],"format":"date-time"},"fulfilled_at":{"type":["string","null"],"format":"date-time"},"cancelled_at":{"type":["string","null"],"format":"date-time"}},"required":["fulfillment_id","status","items","tracking","created_at","updated_at","fulfilled_at","cancelled_at"],"additionalProperties":false}},"packages_truncated":{"type":"boolean"},"page_info":{"type":"object","properties":{"page":{"type":"integer","minimum":1,"maximum":1000},"per_page":{"type":"integer","enum":[20]},"has_next_page":{"type":"boolean"},"next_page":{"type":["integer","null"],"minimum":2,"maximum":1000}},"required":["page","per_page","has_next_page","next_page"],"additionalProperties":false},"notification_supported":{"type":"boolean"}},"required":["target","supported","editable","reason","items","items_truncated","packages","packages_truncated","page_info","notification_supported"],"additionalProperties":false},"result_schemas":{"fulfillment.create":{"type":"object","properties":{"order_id":{"type":"integer","minimum":1},"changes":{"type":"object","properties":{"items":{"type":"array","minItems":1,"maxItems":20,"items":{"type":"object","properties":{"item_id":{"type":"integer","minimum":1},"quantity":{"type":"integer","minimum":1,"maximum":1000000}},"required":["item_id","quantity"],"additionalProperties":false}},"notify_customer":{"type":"boolean"}},"required":["items","notify_customer"],"additionalProperties":false},"created_fulfillment_id":{"type":["integer","null"],"minimum":1},"current_version":{"type":"string","pattern":"^[a-f0-9]{64}$"}},"required":["order_id","changes","created_fulfillment_id","current_version"],"additionalProperties":false},"fulfillment.update":{"type":"object","properties":{"order_id":{"type":"integer","minimum":1},"changes":{"type":"object","properties":{"fulfillment_id":{"type":"integer","minimum":1},"items":{"type":"array","minItems":1,"maxItems":20,"items":{"type":"object","properties":{"item_id":{"type":"integer","minimum":1},"quantity":{"type":"integer","minimum":1,"maximum":1000000}},"required":["item_id","quantity"],"additionalProperties":false}},"split_items":{"type":"array","minItems":1,"maxItems":20,"items":{"type":"object","properties":{"item_id":{"type":"integer","minimum":1},"quantity":{"type":"integer","minimum":1,"maximum":1000000}},"required":["item_id","quantity"],"additionalProperties":false}},"status":{"type":"string","enum":["unfulfilled","fulfilled"]},"notify_customer":{"type":"boolean"}},"required":["fulfillment_id","notify_customer"],"additionalProperties":false},"created_fulfillment_id":{"type":["integer","null"],"minimum":1},"current_version":{"type":"string","pattern":"^[a-f0-9]{64}$"}},"required":["order_id","changes","created_fulfillment_id","current_version"],"additionalProperties":false},"fulfillment.cancel":{"type":"object","properties":{"order_id":{"type":"integer","minimum":1},"changes":{"type":"object","properties":{"fulfillment_id":{"type":"integer","minimum":1},"notify_customer":{"type":"boolean"}},"required":["fulfillment_id","notify_customer"],"additionalProperties":false},"created_fulfillment_id":{"type":["integer","null"],"minimum":1},"current_version":{"type":"string","pattern":"^[a-f0-9]{64}$"}},"required":["order_id","changes","created_fulfillment_id","current_version"],"additionalProperties":false}}}
YR0178_FULFILLMENT_SCHEMA;
}
