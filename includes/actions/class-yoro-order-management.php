<?php

use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;
use Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils;
use Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

if (!defined('ABSPATH')) { exit; }

/** Bounded order corrections, native tracking storage and read-only refund history. */
final class YoRo_Order_Management
{
    public const WriteActions = ['orders.update_contacts', 'orders.update_customer_note', 'fulfillment.set_tracking', 'fulfillment.remove_tracking'];
    public const ReadActions = ['orders.get_refunds'];
    public const ManifestExtension = 'yoro.early_access_order_management';
    public const AddressFields = ['first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone'];
    public const TrackingActions = ['fulfillment.set_tracking','fulfillment.remove_tracking'];

    public static function manifest(): array
    {
        $tracking=self::tracking_available();
        return ['version'=>1,'actions'=>array_values(array_filter([...self::ReadActions,...self::WriteActions],static fn($action)=>$tracking||!in_array($action,self::TrackingActions,true))),
            'max_items'=>20,'max_targets'=>1,'requires_source_read'=>true,'requires_confirmation'=>true,'result_version'=>1,'tracking_supported'=>$tracking];
    }

    public static function tracking_available(): bool
    {
        try {
            if (!class_exists(FeaturesUtil::class)||!class_exists(Fulfillment::class)||!class_exists(FulfillmentsDataStore::class)
                || 'yes'!==get_option('woocommerce_feature_fulfillments_enabled','no')||!FeaturesUtil::feature_is_enabled('fulfillments')) { return false; }
            foreach (['get_items','set_items','get_tracking_number','set_tracking_number','get_shipment_provider','set_shipment_provider','get_tracking_url','set_tracking_url','is_locked','get_date_deleted'] as $method) {
                if(!method_exists(Fulfillment::class,$method)) { return false; }
            }
            $store=WC_Data_Store::load('order-fulfillment');
            if(!$store||$store->get_current_class_name()!==FulfillmentsDataStore::class) { return false; }
            global $wpdb;
            foreach (['wc_order_fulfillments','wc_order_fulfillment_meta'] as $suffix) {
                $table=$wpdb->prefix.$suffix;
                if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table) { return false; }
            }
            return true;
        } catch(Throwable $error) { return false; }
    }

    public static function properties(string $action): array
    {
        $text=['type'=>'string','maxLength'=>200];
        $address=array_fill_keys(self::AddressFields,$text);
        $address['country']=['type'=>'string','maxLength'=>2];
        $address['email']=['type'=>'string','maxLength'=>254];
        $address['phone']=['type'=>'string','maxLength'=>32];
        $object=static fn($properties)=>['type'=>'object','properties'=>$properties,'required'=>[],'minProperties'=>1,'additionalProperties'=>false];
        return match($action) {
            'orders.update_contacts'=>['billing'=>$object($address),'shipping'=>$object(array_diff_key($address,['email'=>true]))],
            'orders.update_customer_note'=>['customer_note'=>['type'=>'string','maxLength'=>2000]],
            'fulfillment.set_tracking'=>['fulfillment_id'=>['type'=>'integer','minimum'=>0],'carrier'=>['type'=>'string','minLength'=>1,'maxLength'=>100],'tracking_number'=>['type'=>'string','minLength'=>1,'maxLength'=>100]],
            'fulfillment.remove_tracking'=>['fulfillment_id'=>['type'=>'integer','minimum'=>1]],
            default=>[],
        };
    }

    public static function input_properties(string $action): array
    {
        if($action==='orders.get_refunds') { return ['order_id'=>['type'=>'integer','minimum'=>1],'page'=>['type'=>'integer','minimum'=>1,'maximum'=>1000]]; }
        $required=match($action) {'orders.update_contacts'=>[], 'orders.update_customer_note'=>['customer_note'], 'fulfillment.set_tracking'=>['fulfillment_id','carrier','tracking_number'], 'fulfillment.remove_tracking'=>['fulfillment_id'], default=>self::invalid()};
        return ['order_id'=>['type'=>'integer','minimum'=>1],
            'changes'=>['type'=>'object','properties'=>self::properties($action),'required'=>$required,'additionalProperties'=>false],
            'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']];
    }

    public static function result_schema(string $action): array
    {
        $object=static fn($properties,$required=null)=>['type'=>'object','properties'=>$properties,'required'=>$required??array_keys($properties),'additionalProperties'=>false];
        if($action!=='orders.get_refunds') {
            $properties=self::properties($action);
            if($action==='fulfillment.set_tracking') {$properties['fulfillment_id']['minimum']=1;}
            return $object(['order_id'=>['type'=>'integer','minimum'=>1],'changes'=>$object($properties,$action==='orders.update_contacts'?[]:array_keys($properties)),
                'current_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']]);
        }
        $unsigned=['type'=>'string','pattern'=>'^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?$'];
        $signed=['type'=>'string','pattern'=>'^-?(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?$'];
        $item=$object(['item_id'=>['type'=>'integer','minimum'=>1],'name'=>['type'=>'string','maxLength'=>200],
            'quantity'=>['type'=>'integer','minimum'=>-1000000,'maximum'=>0],'total'=>$signed,'tax'=>$signed]);
        $refund=$object(['id'=>['type'=>'integer','minimum'=>1],'created_at'=>['type'=>'string','format'=>'date-time'],
            'reason'=>['type'=>'string','maxLength'=>1000],'reason_truncated'=>['type'=>'boolean'],'amount'=>$unsigned,'currency'=>['type'=>'string','pattern'=>'^[A-Z]{3}$'],
            'items'=>['type'=>'array','maxItems'=>20,'items'=>$item],'items_truncated'=>['type'=>'boolean']]);
        return $object(['order_id'=>['type'=>'integer','minimum'=>1],'refunds'=>['type'=>'array','maxItems'=>20,'items'=>$refund],'count'=>['type'=>'integer','minimum'=>0,'maximum'=>20],
            'page_info'=>$object(['page'=>['type'=>'integer','minimum'=>1,'maximum'=>1000],'has_next_page'=>['type'=>'boolean'],'next_page'=>['type'=>['integer','null'],'minimum'=>2,'maximum'=>1000]])]);
    }

    public static function register(): void
    {
        foreach(self::manifest()['actions'] as $action) {
            $write=in_array($action,self::WriteActions,true);
            $method=match($action){'orders.update_contacts'=>'execute_contacts','orders.update_customer_note'=>'execute_customer_note','fulfillment.set_tracking'=>'execute_set_tracking','fulfillment.remove_tracking'=>'execute_remove_tracking','orders.get_refunds'=>'get_refunds'};
            $preview=match($action){'orders.update_contacts'=>'preview_contacts','orders.update_customer_note'=>'preview_customer_note','fulfillment.set_tracking'=>'preview_set_tracking','fulfillment.remove_tracking'=>'preview_remove_tracking',default=>'get_refunds'};
            $properties=self::input_properties($action);$input=[];foreach($properties as $field=>$property){$input[$field]=$property['type'];}
            YoRo_Action_Registry::register($action,['label'=>match($action){'orders.update_contacts'=>'Edit order contacts','orders.update_customer_note'=>'Edit customer note','fulfillment.set_tracking'=>'Set stored tracking','fulfillment.remove_tracking'=>'Remove stored tracking',default=>'Read refunds'},
                'description'=>'Bounded order settings and stored records. No payment, dispatch or delivery is verified.','category'=>'orders',
                'permission'=>$write?'yoro_execute_actions':'yoro_view_store_data','callback'=>[self::class,$method],'preview_callback'=>[self::class,$preview],
                'risk_level'=>$write?'medium':'low','write_action'=>$write,'requires_confirmation'=>$write,'effect'=>$write?'mutate':'read','reversibility'=>'none','target_type'=>'order',
                'max_items'=>$write?1:20,'input_schema'=>$input,'canonical_input_schema'=>$properties,'required_params'=>$write?['order_id','changes','expected_version']:['order_id'],
                'concurrency_strategy'=>$write?'optimistic_version':'','capabilities'=>['orders',$write?'write':'read']]);
        }
    }

    private static function invalid(): never { throw new InvalidArgumentException('Use bounded order fields and a fresh supported order source.'); }

    private static function plain($value,int $limit,bool $multiline=false,bool $empty=true): bool
    {
        return is_string($value)&&($empty||$value!=='')&&strlen($value)<=$limit&&preg_match('//u',$value)===1
            &&preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]|%[a-f0-9]{2}/i':'/[\x00-\x1f\x7f<>]|%[a-f0-9]{2}/i',$value)!==1;
    }

    public static function changes($changes,string $action): array
    {
        if(!in_array($action,self::WriteActions,true)||!is_array($changes)||$changes===[]||array_is_list($changes)||array_diff(array_keys($changes),array_keys(self::properties($action)))) {self::invalid();}
        if($action==='orders.update_contacts') {
            foreach($changes as $kind=>$address) {
                if(!is_array($address)||$address===[]||array_is_list($address)||array_diff(array_keys($address),self::AddressFields)||($kind==='shipping'&&array_key_exists('email',$address))) {self::invalid();}
                foreach($address as $field=>$value) {
                    if(!self::plain($value,$field==='country'?2:($field==='phone'?32:($field==='email'?254:200)))||trim($value)!==$value) {self::invalid();}
                    if($field==='email'&&$value!==''&&(!is_email($value)||sanitize_email($value)!==$value)) {self::invalid();}
                    if($field==='phone'&&$value!==''&&!WC_Validation::is_phone_format($value)) {self::invalid();}
                    if($field==='country'&&$value!==''&&(!preg_match('/\A[A-Z]{2}\z/D',$value)||!isset(WC()->countries->get_countries()[$value]))) {self::invalid();}
                }
            }
        } elseif($action==='orders.update_customer_note') {
            if(array_keys($changes)!==['customer_note']||!self::plain($changes['customer_note'],2000,true)) {self::invalid();}
        } else {
            $required=$action==='fulfillment.set_tracking'?['fulfillment_id','carrier','tracking_number']:['fulfillment_id'];
            if(array_diff($required,array_keys($changes))||!is_int($changes['fulfillment_id'])||$changes['fulfillment_id']<($action==='fulfillment.set_tracking'?0:1)) {self::invalid();}
            foreach(array_diff($required,['fulfillment_id']) as $field) {
                if(!self::plain($changes[$field],100,false,false)||trim($changes[$field])!==$changes[$field]||preg_match('~[:/\\\\]~',$changes[$field])) {self::invalid();}
            }
            if($action==='fulfillment.set_tracking'&&(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9-]{4,62}[A-Za-z0-9]\z/D',$changes['tracking_number'])||!preg_match('/[0-9]/',$changes['tracking_number']))) {self::invalid();}
        }
        return $changes;
    }

    public static function valid_params(string $action,$params): bool
    {
        if(!is_array($params)) {return false;}
        if($action==='orders.get_refunds') {return !array_diff(array_keys($params),['order_id','page'])&&is_int($params['order_id']??null)&&$params['order_id']>0&&(!array_key_exists('page',$params)||(is_int($params['page'])&&$params['page']>=1&&$params['page']<=1000));}
        if(!in_array($action,self::WriteActions,true)||count($params)!==3||array_diff(array_keys($params),['order_id','changes','expected_version'])||!is_int($params['order_id']??null)||$params['order_id']<1
            ||!is_string($params['expected_version']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$params['expected_version'])!==1) {return false;}
        try {self::changes($params['changes']??null,$action);return true;}catch(Throwable $error){return false;}
    }

    private static function order(int $id,bool $write): WC_Order
    {
        $order=wc_get_order($id);
        if(!$order instanceof WC_Order||$order->get_type()!=='shop_order'||!current_user_can('manage_woocommerce')||!current_user_can('edit_shop_order',$id)||($write&&!current_user_can('yoro_execute_actions'))) {self::invalid();}
        return $order;
    }

    /** Native rows are bounded before loading metadata. Overflow never authorizes tracking mutation. */
    private static function records(WC_Order $order): array
    {
        if(!self::tracking_available()) {return ['supported'=>false,'complete'=>true,'records'=>[]];}
        global $wpdb;
        $ids=$wpdb->get_col($wpdb->prepare("SELECT fulfillment_id FROM {$wpdb->prefix}wc_order_fulfillments WHERE entity_type = %s AND entity_id = %s AND date_deleted IS NULL ORDER BY fulfillment_id ASC LIMIT 21",WC_Order::class,(string)$order->get_id()));
        if($wpdb->last_error!=='') {throw new RuntimeException('Native fulfillment storage is unavailable.');}
        $complete=count($ids)<=20;$records=[];$allocated=[];
        foreach(array_slice($ids,0,20) as $id) {
            $record=new Fulfillment((int)$id);
            if($record->get_entity_type()!==WC_Order::class||$record->get_entity_id()!==(string)$order->get_id()||$record->get_date_deleted()!==null) {self::invalid();}
            $items=$record->get_items();
            if(count($items)>20) {$complete=false;}
            foreach(array_slice($items,0,20) as $item) {
                if(!is_array($item)||array_diff(array_keys($item),['item_id','qty'])||!is_int($item['item_id']??null)||$item['item_id']<1||!is_int($item['qty']??null)||$item['qty']<1||$item['qty']>1000000) {$complete=false;continue;}
                $line=$order->get_item($item['item_id']);
                if(!$line instanceof WC_Order_Item_Product||$line->get_order_id()!==$order->get_id()) {$complete=false;continue;}
                $allocated[$item['item_id']]=($allocated[$item['item_id']]??0)+$item['qty'];
                if($allocated[$item['item_id']]>$line->get_quantity('edit')) {$complete=false;}
            }
            $records[]=$record;
        }
        return ['supported'=>true,'complete'=>$complete,'records'=>$records];
    }

    public static function tracking_fingerprint(WC_Order $order): array
    {
        try {
            $state=self::records($order);$rows=[];
            foreach($state['records'] as $record) {
                $rows[]=['id'=>$record->get_id(),'entity_type'=>$record->get_entity_type(),'entity_id'=>$record->get_entity_id(),'status'=>$record->get_status(),'is_fulfilled'=>$record->get_is_fulfilled(),
                    'date_updated'=>$record->get_date_updated(),'date_deleted'=>$record->get_date_deleted(),'locked'=>$record->is_locked(),
                    'items'=>array_slice($record->get_items(),0,20),'carrier'=>$record->get_shipment_provider(),'tracking_number'=>$record->get_tracking_number(),'tracking_url'=>$record->get_tracking_url()];
            }
            return ['supported'=>$state['supported'],'complete'=>$state['complete'],'records'=>$rows];
        } catch(Throwable $error) {return ['supported'=>false,'complete'=>false,'records'=>[]];}
    }

    public static function details(WC_Order $order): array
    {
        $raw=(string)$order->get_customer_note('edit');$note=self::display_text($raw,2000);
        $tracking=[];$truncated=false;$supported=self::tracking_available();
        try {
            $state=self::records($order);$supported=$state['supported'];$truncated=!$state['complete'];
            foreach($state['records'] as $record) {
                $carrier=(string)$record->get_shipment_provider();$number=(string)$record->get_tracking_number();
                if(!self::plain($carrier,100)||!self::plain($number,100)||strlen($carrier)>100||strlen($number)>100) {$truncated=true;}
                $tracking[]=['fulfillment_id'=>$record->get_id(),'carrier'=>self::display_text($carrier,100),'tracking_number'=>self::display_text($number,100)];
            }
        }catch(Throwable $error){$supported=false;$truncated=true;$tracking=[];}
        return ['version'=>1,'customer_note'=>$note,'customer_note_truncated'=>$note!==$raw,'tracking_supported'=>$supported,'tracking'=>$tracking,'tracking_truncated'=>$truncated];
    }

    private static function pending_items(WC_Order $order,array $records): array
    {
        if(!in_array($order->get_status(),['pending','on-hold','processing'],true)||count($order->get_items())>20
            ||wc_get_orders(['type'=>'shop_order_refund','parent'=>$order->get_id(),'limit'=>1,'return'=>'ids'])!==[]) {self::invalid();}
        $pending=FulfillmentUtils::get_pending_items($order,$records,false);$items=[];
        foreach($pending as $row) {
            $item=$row['item'];$product=$item->get_product();$qty=$row['qty'];
            if(!$product||!is_int($qty)||$qty<1||$qty>1000000) {self::invalid();}
            if(!$product->needs_shipping()) {continue;}
            $items[]=['item_id'=>(int)$row['item_id'],'qty'=>$qty];
        }
        if($items===[]||count($items)>20) {self::invalid();}
        return $items;
    }

    public static function prepare(array $params,string $action): array|WP_Error
    {
        try {
            $params=array_diff_key($params,array_flip(['_yoro_confirmed','_yoro_preview']));
            if(!self::valid_params($action,$params)) {self::invalid();}
            $order=self::order($params['order_id'],true);
            if(!hash_equals(YoRo_Order_Presenter::order_version($order),$params['expected_version'])) {return new WP_Error('order_version_conflict','The order changed. Read it again before previewing another change.');}
            $changes=self::changes($params['changes'],$action);$before=[];$different=false;$fulfillment=null;
            if($action==='orders.update_contacts') {
                foreach($changes as $kind=>$address) {
                    foreach($address as $field=>$value) {
                        $getter='get_'.$kind.'_'.$field;$setter='set_'.$kind.'_'.$field;
                        if(!method_exists($order,$getter)||!method_exists($order,$setter)) {self::invalid();}
                        $before[$kind][$field]=$order->$getter('edit');$different=$different||$before[$kind][$field]!==$value;
                        $order->$setter($value);
                    }
                    $country=$order->{'get_'.$kind.'_country'}('edit');$state=$order->{'get_'.$kind.'_state'}('edit');$postcode=$order->{'get_'.$kind.'_postcode'}('edit');
                    if($country!==''&&!isset(WC()->countries->get_countries()[$country])) {self::invalid();}
                    $states=$country!==''?WC()->countries->get_states($country):[];
                    if($state!==''&&(($country==='')||(is_array($states)&&$states!==[]&&!isset($states[$state])))) {self::invalid();}
                    if($postcode!==''&&$country!==''&&!WC_Validation::is_postcode($postcode,$country)) {self::invalid();}
                    if(array_key_exists('phone',$address)&&$address['phone']!==''&&!WC_Validation::is_phone($address['phone'],$country)) {self::invalid();}
                }
            }elseif($action==='orders.update_customer_note') {
                if(self::details($order)['customer_note_truncated']) {self::invalid();}
                $before=['customer_note'=>$order->get_customer_note('edit')];$different=$before!==$changes;$order->set_customer_note($changes['customer_note']);
            } else {
                $state=self::records($order);$details=self::details($order);
                if(!$state['supported']||!$state['complete']||$details['tracking_truncated']) {self::invalid();}
                if($changes['fulfillment_id']===0) {
                    $fulfillment=new Fulfillment();$fulfillment->set_entity_type(WC_Order::class);$fulfillment->set_entity_id((string)$order->get_id());$fulfillment->set_status('unfulfilled');
                    $fulfillment->set_items(self::pending_items($order,$state['records']));$before=[];$different=true;
                } else {
                    foreach($state['records'] as $record) {if($record->get_id()===$changes['fulfillment_id']) {$fulfillment=$record;break;}}
                    if(!$fulfillment||$fulfillment->is_locked()) {self::invalid();}
                    $before=['fulfillment_id'=>$fulfillment->get_id(),'carrier'=>(string)$fulfillment->get_shipment_provider(),'tracking_number'=>(string)$fulfillment->get_tracking_number()];
                    $different=$action==='fulfillment.remove_tracking'?($before['carrier']!==''||$before['tracking_number']!==''||$fulfillment->get_tracking_url()!==null):$before!==$changes;
                }
                $fulfillment->set_shipment_provider($action==='fulfillment.remove_tracking'?'':$changes['carrier']);
                $fulfillment->set_tracking_number($action==='fulfillment.remove_tracking'?'':$changes['tracking_number']);$fulfillment->set_tracking_url('');
            }
            if(!$different) {self::invalid();}
            return ['order'=>$order,'changes'=>$changes,'before'=>$before,'fulfillment'=>$fulfillment];
        } catch(Throwable $error) {return new WP_Error('order_management_invalid','The order change is invalid or unavailable for the current actor and native store capabilities.');}
    }

    public static function preview(array $params,string $action): array|WP_Error
    {
        $prepared=self::prepare($params,$action);if(is_wp_error($prepared)) {return $prepared;}
        return ['success'=>true,'read_only'=>true,'undoable'=>false,'order_id'=>$prepared['order']->get_id(),'before'=>$prepared['before'],'after'=>$prepared['changes'],
            'message'=>'Review the exact stored order change. Tracking changes never mark dispatch or send customer notifications.'];
    }
    public static function preview_contacts($params): array|WP_Error {return self::preview((array)$params,'orders.update_contacts');}
    public static function preview_customer_note($params): array|WP_Error {return self::preview((array)$params,'orders.update_customer_note');}
    public static function preview_set_tracking($params): array|WP_Error {return self::preview((array)$params,'fulfillment.set_tracking');}
    public static function preview_remove_tracking($params): array|WP_Error {return self::preview((array)$params,'fulfillment.remove_tracking');}
    public static function execute_contacts($params): array|WP_Error {return self::execute((array)$params,'orders.update_contacts');}
    public static function execute_customer_note($params): array|WP_Error {return self::execute((array)$params,'orders.update_customer_note');}
    public static function execute_set_tracking($params): array|WP_Error {return self::execute((array)$params,'fulfillment.set_tracking');}
    public static function execute_remove_tracking($params): array|WP_Error {return self::execute((array)$params,'fulfillment.remove_tracking');}

    private static function execute(array $params,string $action): array|WP_Error
    {
        if(($params['_yoro_confirmed']??null)!==true||($params['_yoro_preview']??false)!==false) {return new WP_Error('order_confirmation_required','Execute through the confirmed order executor.');}
        $prepared=self::prepare($params,$action);if(is_wp_error($prepared)) {return $prepared;}
        $order=$prepared['order'];$changes=$prepared['changes'];
        try {
            if($prepared['fulfillment']) {
                $record=$prepared['fulfillment'];$status=$record->get_status();$fulfilled=$record->get_is_fulfilled();$date_fulfilled=$record->get_date_fulfilled();$items=$record->get_items();$record->save();$fresh=new Fulfillment($record->get_id());
                if($fresh->get_entity_type()!==WC_Order::class||$fresh->get_entity_id()!==(string)$order->get_id()||$fresh->get_status()!==$status||$fresh->get_is_fulfilled()!==$fulfilled||$fresh->get_date_fulfilled()!==$date_fulfilled||$fresh->get_items()!==$items||$fresh->get_tracking_url()!==null
                    ||(string)$fresh->get_tracking_number()!==($action==='fulfillment.remove_tracking'?'':$changes['tracking_number'])
                    ||(string)$fresh->get_shipment_provider()!==($action==='fulfillment.remove_tracking'?'':$changes['carrier'])) {throw new RuntimeException('Native tracking save could not be verified.');}
                $changes['fulfillment_id']=$fresh->get_id();
            } else {
                $order->save();$fresh=wc_get_order($order->get_id());
                if(!$fresh instanceof WC_Order) {throw new RuntimeException('Order save could not be verified.');}
                foreach($changes as $kind=>$value) {
                    if(is_array($value)){foreach($value as $field=>$expected){$getter='get_'.$kind.'_'.$field;if($fresh->$getter('edit')!==$expected){throw new RuntimeException('Order contact mismatch.');}}}
                    elseif($fresh->get_customer_note('edit')!==$value){throw new RuntimeException('Customer note mismatch.');}
                }
            }
            $order=wc_get_order($order->get_id());
            return self::result($action,['order_id'=>$order->get_id(),'changes'=>$changes,'current_version'=>YoRo_Order_Presenter::order_version($order)],false);
        } catch(Throwable $error) {return new WP_Error('local_execution_outcome_unknown','Read the order again; do not repeat this change.');}
    }

    private static function display_text(string $value,int $limit): string
    {
        $value=preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/','',wp_strip_all_tags($value));
        return mb_strcut($value,0,$limit,'UTF-8');
    }

    private static function decimal($value,bool $signed=false): string
    {
        $value=(string)$value;
        if(preg_match($signed?'/\A-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D':'/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D',$value)!==1) {self::invalid();}
        if(str_contains($value,'.')) {$value=rtrim(rtrim($value,'0'),'.');}
        return $value==='-0'?'0':$value;
    }

    public static function get_refunds($params): array|WP_Error
    {
        try {
            $params=array_diff_key((array)$params,array_flip(['_yoro_confirmed','_yoro_preview']));
            if(!self::valid_params('orders.get_refunds',$params)) {self::invalid();}
            $order=self::order($params['order_id'],false);$page=$params['page']??1;
            $refunds=wc_get_orders(['type'=>'shop_order_refund','parent'=>$order->get_id(),'limit'=>21,'offset'=>($page-1)*20,'orderby'=>'ID','order'=>'DESC']);
            $has_next=count($refunds)>20&&$page<1000;$rows=[];
            foreach(array_slice($refunds,0,20) as $refund) {
                if(!$refund instanceof WC_Order_Refund||$refund->get_parent_id()!==$order->get_id()) {self::invalid();}
                $items=$refund->get_items('line_item');$item_rows=[];
                foreach(array_slice($items,0,20,true) as $item) {
                    $quantity=$item->get_quantity('edit');
                    if(!is_int($quantity)||$quantity>0||$quantity < -1000000) {self::invalid();}
                    $item_rows[]=['item_id'=>$item->get_id(),'name'=>self::display_text($item->get_name(),200),'quantity'=>$quantity,'total'=>self::decimal($item->get_total('edit'),true),'tax'=>self::decimal($item->get_total_tax('edit'),true)];
                }
                $reason=(string)$refund->get_reason('edit');$shown=self::display_text($reason,1000);$date=$refund->get_date_created();if(!$date) {self::invalid();}
                $rows[]=['id'=>$refund->get_id(),'created_at'=>gmdate('c',$date->getTimestamp()),'reason'=>$shown,'reason_truncated'=>$shown!==$reason,'amount'=>self::decimal($refund->get_amount('edit')),
                    'currency'=>$refund->get_currency('edit'),'items'=>$item_rows,'items_truncated'=>count($items)>20];
            }
            $data=['order_id'=>$order->get_id(),'refunds'=>$rows,'count'=>count($rows),'page_info'=>['page'=>$page,'has_next_page'=>$has_next,'next_page'=>$has_next?$page+1:null]];
            return self::result('orders.get_refunds',self::bounded_refunds($data),true);
        } catch(Throwable $error) {return new WP_Error('order_refunds_unavailable','Stored refund history is unavailable for this order and actor.');}
    }

    /** Retain every financial row and paging field before optional line and reason detail. */
    private static function bounded_refunds(array $data): array
    {
        while(strlen(wp_json_encode($data))>49152) {
            $largest=null;$bytes=0;
            foreach($data['refunds'] as $index=>$refund) {
                $size=$refund['items']===[]?0:strlen(wp_json_encode($refund['items']));
                if($size>$bytes) {$largest=$index;$bytes=$size;}
            }
            if($largest!==null) {array_pop($data['refunds'][$largest]['items']);$data['refunds'][$largest]['items_truncated']=true;continue;}
            foreach($data['refunds'] as $index=>$refund) {
                $size=$refund['reason']===''?0:strlen(wp_json_encode($refund['reason']));
                if($size>$bytes) {$largest=$index;$bytes=$size;}
            }
            if($largest===null) {self::invalid();}
            $reason=$data['refunds'][$largest]['reason'];
            $data['refunds'][$largest]['reason']=mb_strcut($reason,0,max(0,strlen($reason)-128),'UTF-8');
            $data['refunds'][$largest]['reason_truncated']=true;
        }
        return $data;
    }

    public static function execute_read(array $operation): array|WP_Error
    {
        $action=$operation['action']??'';$params=$operation['params']??null;
        if(!in_array($action,self::ReadActions,true)||!self::valid_params($action,$params)) {return new WP_Error('order_refunds_invalid','Refund read selectors are invalid.');}
        $execution=YoRo_Action_Executor::execute($action,$params,false,false,['connector_remote_agent'=>true,'workflow_id'=>$operation['workflow_id']??'','operation_id'=>$operation['operation_id']??'']);
        if(is_wp_error($execution)||!is_array($execution)||empty($execution['success'])) {return is_wp_error($execution)?$execution:new WP_Error('order_refunds_unavailable','Refund history read did not complete.');}
        return self::result($action,$execution['data'],true);
    }

    private static function result(string $action,array $data,bool $read): array
    {
        $message=$read?'Stored refund history only. No payment, gateway settlement or refund creation is verified.':'Stored order settings updated. Dispatch and delivery are not verified; no customer notification was requested.';
        return ['success'=>true,'action'=>$action,'message'=>$message,'summary'=>$message,'data'=>$data,'read_only'=>$read,'undoable'=>false,'result_type'=>$read?'order_refunds':'commerce_write',
            'display_type'=>$read?'order_refunds':'commerce_write','assistant_summary'=>$message,'extensions'=>(object)[]];
    }
}
