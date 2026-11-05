<?php

if (!defined('ABSPATH')) { exit; }

/** Characterized owner reads only. Never traverse the persistent related-order cache. */
final class YoRo_Subscriptions_Read
{
    public const OwnerVersions = ['8.8.1','9.3.0'];
    public const ReadActions = ['subscriptions.get_status','subscriptions.search','subscriptions.get_details','subscriptions.get_order_context','subscriptions.get_related_orders','subscriptions.get_summary','subscriptions.get_renewal_report'];
    public const DiagnosticActions = ['subscriptions.get_timeline','subscriptions.get_payment_health','subscriptions.get_attention_queue'];
    public const AllActions = [...self::ReadActions,...self::DiagnosticActions];
    public const DiagnosticsExtension = 'yoro.subscriptions_diagnostics';
    public const Reports = ['subscriptions.get_summary','subscriptions.get_renewal_report'];
    public const Extension = 'yoro.subscriptions_read';
    public const ProductTypes = ['subscription','variable-subscription','subscription_variation'];

    public static function specification(): array
    {
        static $spec;
        return $spec ??= json_decode(file_get_contents(__DIR__.'/subscriptions-read-v1.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    public static function supported(): bool
    {
        if (!class_exists('WC_Subscriptions') || !in_array(WC_Subscriptions::$version,self::OwnerVersions,true) || !class_exists('WC_Subscription') || !class_exists('WC_Subscriptions_Product')) { return false; }
        foreach (['wcs_get_subscription'=>[1,1],'wcs_get_subscriptions'=>[1,1],'wcs_get_subscription_ids_for_order'=>[1,2],'wc_get_orders'=>[1,1]] as $function=>$signature) {
            if (!function_exists($function)) { return false; } $reflection=new ReflectionFunction($function);
            if ($reflection->getNumberOfRequiredParameters()!==$signature[0] || $reflection->getNumberOfParameters()!==$signature[1]) { return false; }
        }
        foreach (['get_date'=>[1,3],'get_billing_period'=>[0,1],'get_billing_interval'=>[0,1],'get_requires_manual_renewal'=>[0,1],'get_parent_id'=>[0,1],'get_items'=>[0,1],'get_date_created'=>[0,1]] as $method=>$signature) {
            if (!method_exists('WC_Subscription',$method)) { return false; } $reflection=new ReflectionMethod('WC_Subscription',$method);
            if (!$reflection->isPublic() || $reflection->isStatic() || $reflection->getNumberOfRequiredParameters()!==$signature[0] || $reflection->getNumberOfParameters()!==$signature[1]) { return false; }
        }
        foreach (['get_period','get_interval','get_length','get_trial_length','get_trial_period','get_sign_up_fee'] as $method) {
            if (!method_exists('WC_Subscriptions_Product',$method)) { return false; } $reflection=new ReflectionMethod('WC_Subscriptions_Product',$method);
            if (!$reflection->isPublic() || !$reflection->isStatic() || $reflection->getNumberOfRequiredParameters()!==1 || $reflection->getNumberOfParameters()!==1) { return false; }
        }
        return true;
    }

    public static function manifest(?bool $supported = null): array
    {
        return ['version'=>1,'actions'=>($supported??self::supported())?self::ReadActions:[self::ReadActions[0]],'owner'=>'woocommerce-subscriptions','owner_versions'=>self::OwnerVersions,'batch_size'=>100,'max_items'=>20,'max_relation_ids_per_order'=>200,'report_execution_seconds'=>1800,'max_period_days'=>366,'pagination'=>'anchored_offset_mutation_guard','customer_data'=>'ids_only','result_version'=>1];
    }

    /** Reuse configured native signing material without entering wp_salt's option-writing fallback. */
    private static function diagnostics_key(): ?string
    {
        if(!defined('AUTH_KEY')||!defined('AUTH_SALT')||!is_string(AUTH_KEY)||!is_string(AUTH_SALT)||trim(AUTH_KEY)===''||trim(AUTH_SALT)===''||AUTH_KEY===AUTH_SALT||trim(AUTH_KEY)==='put your unique phrase here'||trim(AUTH_SALT)==='put your unique phrase here'){return null;}
        return hash('sha256',"yoro.subscriptions_diagnostics.v1\0".AUTH_KEY."\0".AUTH_SALT,true);
    }
    public static function diagnostics_supported(): bool { return self::supported()&&self::diagnostics_key()!==null; }
    private static function diagnostic_revision(array $operation,string $physicalRevision,string $started,int $anchor): string
    {
        $key=self::diagnostics_key();if($key===null){throw new RuntimeException('subscription_cursor_signer_unavailable');}
        $facts=$operation['extensions']['yoro.mcp_early_access_product_read']??[];$params=$operation['params'];
        return hash_hmac('sha256',wp_json_encode([$operation['action'],get_current_blog_id(),get_current_user_id(),$facts['site_id']??null,$facts['installation_uuid']??null,$facts['wordpress_identity_id']??null,$facts['profile']??null,$facts['resource']??null,$facts['oauth_client_id']??null,$facts['user_id']??null,$params['subscription_id']??null,$params['record_type']??'orders',$params['limit'],$physicalRevision,$started,$anchor]),$key);
    }
    public static function diagnostics_specification(): array
    {
        static $spec;
        return $spec ??= json_decode(file_get_contents(__DIR__.'/subscriptions-diagnostics-v1.json'), true, 64, JSON_THROW_ON_ERROR);
    }
    public static function diagnostics_manifest(): array
    {
        return ['version'=>1,'actions'=>self::DiagnosticActions,'base_extension'=>self::Extension,'owner_versions'=>self::OwnerVersions,'batch_size'=>100,'max_items'=>20,'pagination'=>'signed_scan_start_anchored_offset_mutation_guard','result_version'=>1,'cursor_signer'=>'configured_non_default_native_auth_key_salt_v1'];
    }
    private static function action_specification(string $action): array { return in_array($action,self::DiagnosticActions,true)?self::diagnostics_specification():self::specification(); }
    public static function input_properties(string $action): array { return self::action_specification($action)['properties'][$action] ?? []; }
    public static function result_schema(string $action): array { return self::action_specification($action)['results'][$action]; }

    public static function valid_params(string $action, $params): bool
    {
        if (!in_array($action,self::AllActions,true) || !is_array($params) || array_diff(array_keys($params),array_keys(self::input_properties($action)))) { return false; }
        foreach ($params as $key=>$value) {
            $schema=self::input_properties($action)[$key];
            if (($schema['type']==='integer' && (!is_int($value)||$value<($schema['minimum']??0)||$value>($schema['maximum']??PHP_INT_MAX)))
                || ($schema['type']==='string' && (!is_string($value)||(isset($schema['enum'])&&!in_array($value,$schema['enum'],true))||(isset($schema['pattern'])&&preg_match('~'.$schema['pattern'].'~D',$value)!==1)))) { return false; }
            if (in_array($key,['created_from','created_to','next_payment_from','next_payment_to','anchor_date','date_from','date_to'],true)) {
                $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC')); if (!$date||$date->format('Y-m-d')!==$value) { return false; }
            }
        }
        foreach (['created','next_payment'] as $kind) { if (isset($params[$kind.'_from'],$params[$kind.'_to']) && $params[$kind.'_from']>$params[$kind.'_to']) { return false; } }
        if (isset($params['offset']) && $params['offset']>0 && (!isset($params['anchor_id'],$params['revision']))) { return false; }
        if (isset($params['anchor_id']) !== isset($params['revision'])) { return false; }
        if (isset(self::input_properties($action)['limit']) && !isset($params['limit'],$params['offset'])) { return false; }
        if (isset(self::input_properties($action)['subscription_id']) && !isset($params['subscription_id'])) { return false; }
        if ($action==='subscriptions.get_order_context' && !isset($params['order_id'])) { return false; }
        if (in_array($action,self::Reports,true)) {
            try { $period=array_intersect_key($params,array_flip(['window','anchor_date','date_from','date_to'])); if (!isset($period['window'])||YoRo_Store_Report_Period::parameters($period)!==$period) { return false; } }
            catch (Throwable $error) { return false; }
        }
        if (in_array($action,self::DiagnosticActions,true)) {
            if (($params['offset']??0)>0 && !isset($params['scan_started_at'])) { return false; }
            if (($params['offset']??0)===0 && (isset($params['scan_started_at'])||isset($params['anchor_id'])||isset($params['revision']))) { return false; }
            if (isset($params['scan_started_at'])) {
                try { $observed=new DateTimeImmutable($params['scan_started_at']); if($observed->format('c')!==$params['scan_started_at']||$observed->getTimestamp()>time()){return false;} } catch(Throwable $error){return false;}
            }
        }
        return true;
    }

    private static function revision(): string { return hash('sha256',YoRo_Complete_Store_Report::current_revision()); }
    private static function allowed($object): bool
    {
        if (!$object instanceof WC_Order && !$object instanceof WC_Order_Refund) { return false; }
        $type=$object->get_type();
        if ($object->get_id()<1 || !in_array($type,['shop_subscription','shop_order','shop_order_refund'],true)) { return false; }
        if ($type==='shop_order_refund') {
            $parentId=$object->get_parent_id();$parent=$parentId>0?wc_get_order($parentId):false;
            return $object instanceof WC_Order_Refund && $parent instanceof WC_Order && $parent->get_type()==='shop_order'
                && $parent->get_id()===$parentId && current_user_can('read_shop_order',$parentId);
        }
        return $object instanceof WC_Order && current_user_can('read_shop_order',$object->get_id());
    }
    private static function subscription(int $id): WC_Subscription
    {
        $object=wcs_get_subscription($id);
        if (!$object instanceof WC_Subscription || !self::allowed($object) || $object->get_id()!==$id || !in_array($object->get_status(),self::specification()['statuses'],true)) { throw new RuntimeException('subscription_unavailable'); }
        return $object;
    }
    private static function order(int $id): WC_Order
    {
        $order=wc_get_order($id);if (!self::allowed($order)||$order->get_type()!=='shop_order'||$order->get_id()!==$id) { throw new RuntimeException('subscription_order_unavailable'); } return $order;
    }
    private static function date($date): ?string { return $date instanceof DateTimeInterface ? $date->format('c') : null; }
    private static function schedule(WC_Subscription $subscription,string $key): ?string
    {
        $raw=$subscription->get_date($key,'gmt'); if (!$raw || $raw==='0' || $raw==='0000-00-00 00:00:00') { return null; }
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,new DateTimeZone('UTC'));
        if (!$date||$date->format('Y-m-d H:i:s')!==$raw) { throw new RuntimeException('subscription_date_invalid'); } return $date->format('c');
    }
    private static function money($value): string { return YoRo_Complete_Store_Report::decimal(YoRo_Complete_Store_Report::units((string)$value)); }
    private static function currency($value): string { if (!is_string($value)||preg_match('/\A[A-Z]{3}\z/D',$value)!==1) { throw new RuntimeException('subscription_currency_invalid'); } return $value; }
    public static function product_facts($product): array
    {
        if (!self::supported() || !$product instanceof WC_Product || !in_array($product->get_type(),self::ProductTypes,true)) { throw new RuntimeException('subscription_product_unsupported'); }
        if ($product->get_type()==='variable-subscription') { return ['billing_period'=>null,'billing_interval'=>null,'duration'=>null,'trial_period'=>null,'trial_length'=>null,'sign_up_fee'=>null,'price_basis'=>'current_product_not_existing_agreement','terms_scope'=>'variation_specific']; }
        $facts=['billing_period'=>WC_Subscriptions_Product::get_period($product),'billing_interval'=>(int)WC_Subscriptions_Product::get_interval($product),
            'duration'=>(int)WC_Subscriptions_Product::get_length($product),'trial_period'=>WC_Subscriptions_Product::get_trial_period($product),
            'trial_length'=>(int)WC_Subscriptions_Product::get_trial_length($product),'sign_up_fee'=>self::money(WC_Subscriptions_Product::get_sign_up_fee($product)),'price_basis'=>'current_product_not_existing_agreement','terms_scope'=>$product->get_type()==='subscription_variation'?'variation':'product'];
        if ($facts['trial_length']===0 && $facts['trial_period']==='') { $facts['trial_period']=null; }
        if (!in_array($facts['billing_period'],['day','week','month','year'],true)||!in_array($facts['trial_period'],['day','week','month','year',null],true)||$facts['billing_interval']<1||$facts['billing_interval']>120||$facts['duration']<0||$facts['trial_length']<0) { throw new RuntimeException('subscription_product_invalid'); }
        return $facts;
    }
    private static function row(WC_Subscription $subscription,bool $details=false,array $relations=[]): array
    {
        $items=[];$raw=$subscription->get_items('line_item');if(count($raw)>200){throw new RuntimeException('subscription_item_capacity');}
        if($details){foreach(array_slice($raw,0,20) as $item){$quantity=(string)$item->get_quantity();if(preg_match('/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,6})?\z/D',$quantity)!==1){throw new RuntimeException('subscription_quantity_invalid');}
            $items[]=['product_id'=>(int)$item->get_product_id(),'variation_id'=>(int)$item->get_variation_id(),'name'=>mb_substr(sanitize_text_field($item->get_name()),0,160),'quantity'=>$quantity,'total'=>self::money($item->get_total())];}}
        return ['id'=>$subscription->get_id(),'status'=>$subscription->get_status(),'customer_id'=>(int)$subscription->get_customer_id(),'parent_order_id'=>(int)$subscription->get_parent_id(),
            'currency'=>self::currency($subscription->get_currency()),'agreement_total'=>self::money($subscription->get_total()),'billing_period'=>$subscription->get_billing_period('edit'),'billing_interval'=>(int)$subscription->get_billing_interval('edit'),
            'payment_mode_basis'=>'stored_requires_manual_renewal','payment_mode'=>$subscription->get_requires_manual_renewal('edit')?'manual_configured':'automatic_configured','created_at'=>self::date($subscription->get_date_created()),'start_at'=>self::schedule($subscription,'start'),
            'trial_end_at'=>self::schedule($subscription,'trial_end'),'next_payment_at'=>self::schedule($subscription,'next_payment'),'end_at'=>self::schedule($subscription,'end'),
            'items'=>$items,'items_truncated'=>count($raw)>count($items),'relation_kinds'=>$relations];
    }
    /** The reverse owner read does not enter the subscription persistent related-order cache. */
    private static function reverse(WC_Order $order): array
    {
        $relations=[];
        foreach (['renewal','switch','resubscribe'] as $kind) {
            $ids=wcs_get_subscription_ids_for_order($order,[$kind]);if(!is_array($ids)||count($ids)>200){throw new RuntimeException('subscription_relation_capacity');}
            foreach($ids as $id){if(!is_int($id)||$id<1){throw new RuntimeException('subscription_relation_invalid');}self::subscription($id);$relations[$id][]=$kind;}
        }
        return $relations;
    }
    private static function matches(WC_Subscription $subscription,array $params): bool
    {
        if((isset($params['status'])&&$subscription->get_status()!==$params['status'])||(isset($params['customer_id'])&&$subscription->get_customer_id()!==$params['customer_id'])){return false;}
        if(isset($params['product_id'])){$found=false;$items=$subscription->get_items('line_item');if(count($items)>200){throw new RuntimeException('subscription_item_capacity');}foreach($items as $item){if(in_array($params['product_id'],[(int)$item->get_product_id(),(int)$item->get_variation_id()],true)){$found=true;break;}}if(!$found){return false;}}
        foreach(['created'=>self::date($subscription->get_date_created()),'next_payment'=>self::schedule($subscription,'next_payment')] as $kind=>$raw){
            if(!isset($params[$kind.'_from'])&&!isset($params[$kind.'_to'])){continue;}if($raw===null){return false;}$day=(new DateTimeImmutable($raw))->setTimezone(wp_timezone())->format('Y-m-d');
            if((isset($params[$kind.'_from'])&&$day<$params[$kind.'_from'])||(isset($params[$kind.'_to'])&&$day>$params[$kind.'_to'])){return false;}}
        return true;
    }
    private static function batch(string $type,int $offset,int $limit=100): array
    {
        $args=['type'=>$type,'status'=>'any','limit'=>$limit,'offset'=>$offset,'orderby'=>'ID','order'=>'ASC','return'=>'objects'];
        $rows=$type==='shop_subscription'?wcs_get_subscriptions(['subscriptions_per_page'=>$limit,'offset'=>$offset,'orderby'=>'ID','order'=>'ASC','subscription_status'=>'any']):wc_get_orders($args);
        if(!is_array($rows)||count($rows)>$limit){throw new RuntimeException('subscription_query_invalid');}return array_values($rows);
    }
    private static function anchor(string $type): int
    {
        $rows=$type==='shop_subscription'?wcs_get_subscriptions(['subscriptions_per_page'=>1,'orderby'=>'ID','order'=>'DESC','subscription_status'=>'any']):wc_get_orders(['type'=>$type,'status'=>'any','limit'=>1,'orderby'=>'ID','order'=>'DESC','return'=>'objects']);
        if(!is_array($rows)||count($rows)>1){throw new RuntimeException('subscription_anchor_invalid');}return $rows?(int)reset($rows)->get_id():0;
    }
    private static function scan(array $operation): array
    {
        $action=$operation['action'];$params=$operation['params'];$related=$action==='subscriptions.get_related_orders';$type=$related?'shop_order':'shop_subscription';
        $revision=self::revision();if(isset($params['revision'])&&!hash_equals($revision,$params['revision'])){throw new RuntimeException('subscription_scan_changed');}
        $anchor=$params['anchor_id']??self::anchor($type);$seed=$related?self::subscription($params['subscription_id']):null;$order=$action==='subscriptions.get_order_context'?self::order($params['order_id']):null;$reverse=$order?self::reverse($order):[];
        $rows=self::batch($type,$params['offset']);$result=[];$scanned=0;$last=0;$more=count($rows)===100;
        foreach($rows as $object){if($object->get_id()>$anchor){$more=false;break;}if($object->get_id()<=$last||!self::allowed($object)){throw new RuntimeException('subscription_scan_invalid');}$last=$object->get_id();$scanned++;
            if($related){$relations=self::reverse($object)[$seed->get_id()]??[];if($seed->get_parent_id()===$object->get_id()){array_unshift($relations,'parent');}if(!$relations){continue;}
                $row=['id'=>$object->get_id(),'status'=>$object->get_status(),'currency'=>self::currency($object->get_currency()),'total'=>self::money($object->get_total()),'created_at'=>self::date($object->get_date_created()),'paid_at'=>self::date($object->get_date_paid()),'relation_kinds'=>$relations];
            }else{if(!$object instanceof WC_Subscription||!in_array($object->get_status(),self::specification()['statuses'],true)){throw new RuntimeException('subscription_scan_unsupported_record');}
                $relations=$order?($reverse[$object->get_id()]??[]):[];if($order&&$object->get_parent_id()===$order->get_id()){array_unshift($relations,'parent');}if(($order&&!$relations)||!self::matches($object,$params)){continue;}$row=self::row($object,false,$relations);}
            $result[]=$row;if(count($result)===$params['limit']){$more=$object->get_id()<$anchor;break;}}
        if(!hash_equals($revision,self::revision())){throw new RuntimeException('subscription_scan_changed');}
        if ($last >= $anchor) { $more=false; }
        return [$related?'orders':'subscriptions'=>$result,'count'=>count($result),'page_info'=>['anchor_id'=>$anchor,'next_offset'=>$more?$params['offset']+$scanned:null,'has_more'=>$more,'revision'=>$revision,'scanned'=>$scanned,'coverage'=>'bounded_scan_current_state']];
    }
    /** One bounded owner batch, with separate stored schedule and recorded native facts. */
    private static function diagnostic(array $operation): array
    {
        $action=$operation['action'];$params=$operation['params'];$observed=gmdate('c');$started=$params['scan_started_at']??$observed;
        $revision=self::revision();
        $attention=$action===self::DiagnosticActions[2];$recordType=$params['record_type']??'orders';
        $type=$attention?'shop_subscription':($recordType==='refunds'?'shop_order_refund':'shop_order');
        $anchor=$params['anchor_id']??self::anchor($type);$signedRevision=self::diagnostic_revision($operation,$revision,$started,$anchor);
        if(isset($params['revision'])&&!hash_equals($signedRevision,$params['revision'])){throw new RuntimeException('subscription_cursor_changed');}
        $seed=$attention?null:self::subscription($params['subscription_id']);
        $batch=self::batch($type,$params['offset']);$rows=[];$scanned=0;$last=0;$more=count($batch)===100;
        foreach($batch as $object){
            if(!is_object($object)||!self::allowed($object)){throw new RuntimeException('subscription_diagnostic_denied');}
            $id=$object->get_id();if($id>$anchor){$more=false;break;}if($id<=$last||$object->get_type()!==$type){throw new RuntimeException('subscription_diagnostic_invalid');}$last=$id;$scanned++;
            if($attention){
                if(!$object instanceof WC_Subscription||!in_array($object->get_status(),self::specification()['statuses'],true)){throw new RuntimeException('subscription_diagnostic_invalid');}
                $row=self::row($object);$reasons=[];
                if($row['status']==='on-hold'){$reasons[]='on_hold';}
                if($row['status']==='pending-cancel'){$reasons[]='pending_cancellation';}
                if($row['status']==='active'&&$row['next_payment_at']!==null&&strtotime($row['next_payment_at'])<strtotime($started)){$reasons[]='stored_next_payment_overdue';}
                if(!$reasons){continue;}$row['reasons']=$reasons;
            }else{
                $order=$recordType==='refunds'?self::order($object->get_parent_id()):self::order($id);
                $relations=self::reverse($order)[$seed->get_id()]??[];
                if($seed->get_parent_id()===$order->get_id()){array_unshift($relations,'parent');}
                if(!$relations||($action===self::DiagnosticActions[1]&&!in_array('renewal',$relations,true))){continue;}
                if($recordType==='refunds'&&(!$object instanceof WC_Order_Refund||$object->get_currency()!==$order->get_currency())){throw new RuntimeException('subscription_diagnostic_refund_invalid');}
                $row=['id'=>$id,'kind'=>$recordType==='refunds'?'refund':'order','parent_order_id'=>$recordType==='refunds'?$order->get_id():0,
                    'status'=>$object->get_status(),'currency'=>self::currency($object->get_currency()),'total'=>self::money($recordType==='refunds'?$object->get_amount():$object->get_total()),
                    'created_at'=>self::date($object->get_date_created()),'paid_at'=>$recordType==='refunds'?null:self::date($order->get_date_paid()),'relation_kinds'=>$relations];
            }
            $rows[]=$row;if(count($rows)===$params['limit']){$more=$id<$anchor;break;}
        }
        if(!hash_equals($revision,self::revision())){throw new RuntimeException('subscription_scan_changed');}
        if($last>=$anchor){$more=false;}
        $data=['observed_at'=>$observed,'scan_started_at'=>$started,'timezone'=>wp_timezone_string(),'not_assessed'=>self::diagnostics_specification()['not_assessed'],
            'page_info'=>['anchor_id'=>$anchor,'next_offset'=>$more?$params['offset']+$scanned:null,'has_more'=>$more,'revision'=>$signedRevision,'scanned'=>$scanned,'coverage'=>'bounded_scan_current_state'],'count'=>count($rows)];
        if($attention){return [...$data,'subscriptions'=>$rows];}
        $data=[...$data,'subscription'=>self::row($seed),'records'=>$rows,'record_type'=>$recordType];
        if($action===self::DiagnosticActions[1]){$data['renewal_facts_in_page']=['orders'=>count($rows),'failed_current_status'=>count(array_filter($rows,static fn($row)=>$row['status']==='failed')),'with_recorded_paid_date'=>count(array_filter($rows,static fn($row)=>$row['paid_at']!==null))];}
        return $data;
    }
    private static function in_window($date,array $window): bool
    {return $date instanceof DateTimeInterface && $date->getTimestamp()>=strtotime($window['start']) && $date->getTimestamp()<=strtotime($window['end']);}
    private static function add_money(array &$row,string $field,$value): void
    {
        $delta=YoRo_Complete_Store_Report::units((string)$value);$old=$row[$field]??0;
        if($delta<0||$old>YoRo_Complete_Store_Report::MAX_UNITS-$delta){throw new RuntimeException('subscription_money_capacity');}$row[$field]=$old+$delta;
    }
    /** Finalize uses the existing durable report checkpoint/expiry/guard loop with owner-only batches. */
    private static function report(array $operation,$guard): array|WP_Error
    {
        $action=$operation['action'];$params=$operation['params'];$filters=array_diff_key($params,array_flip(['window','anchor_date','date_from','date_to']));
        $operation['params']=array_intersect_key($params,array_flip(['window','anchor_date','date_from','date_to']));$operation['params']['comparison']='none';
        $selection=['scope_key'=>hash('sha256',wp_json_encode([$action,$filters])),'predicate'=>static fn()=> '1=0','matches'=>static fn()=>false,'summary_only'=>true,
            'finalize'=>static function(array &$state)use($action,$filters){
                if(!isset($state['subscriptions'])){$summary=$action===self::Reports[0];$state['subscriptions']=['offset'=>0,'phase'=>0,'anchors'=>[self::anchor($summary?'shop_subscription':'shop_order'),self::anchor('shop_order_refund')],
                    'scanned'=>0,'subscription_count'=>0,'created_in_period'=>0,'manual_configured_count'=>0,'automatic_configured_count'=>0,'status_counts'=>array_fill_keys(array_map(static fn($s)=>str_replace('-','_',$s),self::specification()['statuses']),0),'currencies'=>[],
                    'created_orders'=>0,'paid_orders'=>0,'failed_created_orders'=>0,'refund_records'=>0];}
                $s=&$state['subscriptions'];$summary=$action===self::Reports[0];$type=$summary?'shop_subscription':($s['phase']===0?'shop_order':'shop_order_refund');$rows=self::batch($type,$s['offset']);$last=0;$done=count($rows)<100;
                foreach($rows as $object){if($object->get_id()>$s['anchors'][$s['phase']]){$done=true;break;}if($object->get_id()<=$last||!self::allowed($object)){throw new RuntimeException('subscription_report_invalid_record');}$last=$object->get_id();$s['offset']++;$s['scanned']++;
                    if($summary){if(!$object instanceof WC_Subscription||!in_array($object->get_status(),self::specification()['statuses'],true)){throw new RuntimeException('subscription_report_unsupported_record');}if(!self::matches($object,$filters)){continue;}
                        $s['subscription_count']++;$s['created_in_period']+=(int)self::in_window($object->get_date_created(),$state['windows'][0]);$s[$object->get_requires_manual_renewal('edit')?'manual_configured_count':'automatic_configured_count']++;$s['status_counts'][str_replace('-','_',$object->get_status())]++;
                        $currency=self::currency($object->get_currency());$s['currencies'][$currency]??=['subscription_count'=>0,'agreement_totals'=>0];$s['currencies'][$currency]['subscription_count']++;self::add_money($s['currencies'][$currency],'agreement_totals',$object->get_total());
                    }else{$order=$s['phase']===0?$object:self::order($object->get_parent_id());$relations=self::reverse($order);$renewal=false;foreach($relations as $kinds){if(in_array('renewal',$kinds,true)){$renewal=true;break;}}if(!$renewal){continue;}
                        $currency=self::currency($order->get_currency());$s['currencies'][$currency]??=['created_orders'=>0,'paid_orders'=>0,'refund_records'=>0,'created_order_totals'=>0,'recorded_paid_order_totals'=>0,'recorded_refunds'=>0];$money=&$s['currencies'][$currency];
                        if($s['phase']===0){if(self::in_window($order->get_date_created(),$state['windows'][0])){$s['created_orders']++;$money['created_orders']++;$s['failed_created_orders']+=(int)($order->get_status()==='failed');self::add_money($money,'created_order_totals',$order->get_total());}
                            if(self::in_window($order->get_date_paid(),$state['windows'][0])){$s['paid_orders']++;$money['paid_orders']++;self::add_money($money,'recorded_paid_order_totals',$order->get_total());}}
                        elseif(self::in_window($object->get_date_created(),$state['windows'][0])){if($object->get_currency()!==$currency){throw new RuntimeException('subscription_refund_currency');}$s['refund_records']++;$money['refund_records']++;self::add_money($money,'recorded_refunds',$object->get_amount());}unset($money);}
                    if(count($s['currencies'])>32){throw new RuntimeException('subscription_currency_capacity');}}
                if($done){$s['phase']++;$s['offset']=0;}return $done&&($summary||$s['phase']>1);
            },
            'finish'=>static function(array $state)use($action){$s=$state['subscriptions'];$currencies=[];ksort($s['currencies']);foreach($s['currencies'] as $currency=>$row){foreach($row as $key=>&$value){if(in_array($key,['agreement_totals','created_order_totals','recorded_paid_order_totals','recorded_refunds'],true)){$value=YoRo_Complete_Store_Report::decimal($value);}}unset($value);$currencies[]=['currency'=>$currency,...$row];}
                $data=['window'=>$state['windows'][0],'generated_at'=>gmdate('c',$state['as_of']),'completed_at'=>gmdate('c',$state['completed_at']),'coverage'=>'all_matching_records_non_atomic_snapshot','week_starts_on'=>(int)$state['period_context']['week_starts_on'],'scanned'=>$s['scanned'],'not_assessed'=>self::specification()['not_assessed'],'currencies'=>$currencies];
                return $action===self::Reports[0]?[...$data,'date_basis'=>'subscription_created_date','status_basis'=>'current_status_all_matching_subscriptions','agreement_amount_basis'=>'current_agreement_totals_unscaled_not_revenue',...array_intersect_key($s,array_flip(['subscription_count','created_in_period','manual_configured_count','automatic_configured_count','status_counts']))]:[...$data,'date_basis'=>'order_created_paid_and_refund_created_independently','denominator'=>'distinct_renewal_orders_not_payment_attempts',...array_intersect_key($s,array_flip(['created_orders','paid_orders','failed_created_orders','refund_records']))];}];
        return YoRo_Complete_Store_Report::run($operation,$guard,$selection);
    }
    public static function execute(array $operation,$guard=null): array|WP_Error
    {
        try{$action=$operation['action']??'';$params=$operation['params']??null;
            $allowed=static fn()=>($action===self::ReadActions[0]||self::supported())&&current_user_can('yoro_view_store_data')&&current_user_can('manage_woocommerce')&&(!is_callable($guard)||$guard())&&(!isset($operation['expires_at'])||strtotime($operation['expires_at'])>time());
            if(!self::valid_params($action,$params)||!$allowed()||(in_array($action,self::DiagnosticActions,true)&&!self::diagnostics_supported())){throw new RuntimeException('subscriptions_denied');}
            if(isset($params['product_id'])&&(!wc_get_product($params['product_id'])||!current_user_can('read_post',$params['product_id']))){throw new RuntimeException('subscription_product_unavailable');}
            if(isset($params['customer_id'])&&!get_userdata($params['customer_id'])){throw new RuntimeException('subscription_customer_unavailable');}
            $data=match($action){'subscriptions.get_status'=>['available'=>self::supported(),'reason'=>self::supported()?'characterized':(class_exists('WC_Subscriptions')?'owner_version_or_api_uncharacterized':'owner_not_active_or_absent'),'owner'=>'woocommerce-subscriptions','owner_version'=>class_exists('WC_Subscriptions')?mb_substr((string)WC_Subscriptions::$version,0,40):null,'timezone'=>wp_timezone_string(),'statuses'=>self::specification()['statuses'],'actions'=>self::manifest()['actions'],'read_only'=>true],
                'subscriptions.get_details'=>['subscription'=>self::row(self::subscription($params['subscription_id']),true)],
                'subscriptions.search','subscriptions.get_order_context','subscriptions.get_related_orders'=>self::scan($operation),'subscriptions.get_timeline','subscriptions.get_payment_health','subscriptions.get_attention_queue'=>self::diagnostic($operation),default=>self::report($operation,$allowed)};
            if(is_wp_error($data)||isset($data['deferred'])){return $data;}if(!$allowed()){throw new RuntimeException('subscriptions_expired');}
            $message='Subscription read completed. Current state is not historical events; recorded money is not settlement. No subscription, order, payment or schedule was changed.';
            return ['success'=>true,'action'=>$action,'message'=>$message,'summary'=>$message,'data'=>$data,'read_only'=>true,'undoable'=>false,'result_type'=>'subscriptions','display_type'=>'subscriptions','assistant_summary'=>$message,'extensions'=>(object)[]];
        }catch(Throwable $error){return new WP_Error('subscriptions_unavailable','The complete authorized subscription read could not be assessed. Request current supported records.');}
    }
    public static function execute_read(array $operation,$guard=null): array|WP_Error { return self::execute($operation,$guard); }
    public static function register(): void
    {
        static $initialized=false; if (!$initialized) { $initialized=true; foreach (['woocommerce_before_subscription_object_save','woocommerce_after_subscription_object_save','woocommerce_before_delete_subscription','woocommerce_trash_subscription','woocommerce_untrash_subscription'] as $hook) { add_action($hook,static function(){ YoRo_Complete_Store_Report::invalidate(); },10,0); } }
        foreach([...self::manifest()['actions'],...(self::diagnostics_supported()?self::DiagnosticActions:[])] as $action){$properties=self::input_properties($action);$required=isset($properties['limit'])?['limit','offset']:[];
            if(isset($properties['subscription_id'])){$required[]='subscription_id';}if(isset($properties['order_id'])){$required[]='order_id';}if(in_array($action,self::Reports,true)){$required[]='window';}
            YoRo_Action_Registry::register($action,['label'=>$action,'description'=>'Read-only characterized subscription records. Bounded scans require continuation; reports cover complete matching records.','category'=>'subscriptions','permission'=>'yoro_view_store_data','callback'=>static function($params)use($action){unset($params['_yoro_confirmed'],$params['_yoro_preview']);return self::execute(['action'=>$action,'params'=>$params]);},'risk_level'=>'low','write_action'=>false,'effect'=>'read','target_type'=>'subscription','max_items'=>20,'input_schema'=>array_map(static fn($property)=>$property['type'],$properties),'canonical_input_schema'=>$properties,'required_params'=>$required,'capabilities'=>['subscriptions','read']]);}
    }
}
