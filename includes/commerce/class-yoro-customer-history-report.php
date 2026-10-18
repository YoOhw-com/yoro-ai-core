<?php

if (!defined('ABSPATH')) { exit; }

/** Complete customer order reads. Guest contacts stay in the local selector closure. */
final class YoRo_Customer_History_Report
{
    public const ReadActions=['customers.get_order_history','customers.get_period_summary'];
    public const ManifestExtension='yoro.early_access_customer_history';
    public const SummaryMoney=['order_totals','discounts','tax','shipping','fees','refunds','refund_tax','refund_shipping','order_totals_less_period_refunds','net_ex_tax_shipping'];

    public static function manifest(): array
    {
        return ['version'=>1,'actions'=>self::ReadActions,'max_items'=>20,'batch_size'=>100,'max_period_days'=>366,'report_execution_seconds'=>1800,
            'pagination'=>'anchored_id_keyset','guest_matching'=>['email','international_phone'],'customer_data'=>'redacted','result_version'=>1];
    }

    private static function object(array $properties,?array $required=null): array
    {return ['type'=>'object','properties'=>$properties,'required'=>$required??array_keys($properties),'additionalProperties'=>false];}

    public static function properties(string $action): array
    {
        $selector=self::object(['customer_id'=>['type'=>'integer','minimum'=>1],'guest_order_id'=>['type'=>'integer','minimum'=>1],'match'=>['type'=>'string','enum'=>['email','phone']]],[]);
        if($action===self::ReadActions[0]) {return ['selector'=>$selector,'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>20],'anchor_id'=>['type'=>'integer','minimum'=>0],'before_id'=>['type'=>'integer','minimum'=>1]];}
        if($action!==self::ReadActions[1]) {return [];}
        $date=['type'=>'string','pattern'=>'^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$'];
        return ['selector'=>$selector,'window'=>['type'=>'string','enum'=>YoRo_Store_Report_Period::Windows],'date_basis'=>['type'=>'string','enum'=>['created','paid']],
            'anchor_date'=>$date,'date_from'=>$date,'date_to'=>$date];
    }

    public static function input_properties(string $action): array {return self::properties($action);}

    public static function coverage_schema(): array
    {
        return self::object(['kind'=>['type'=>'string','enum'=>['registered_customer','shared_guest_contact']],
            'match_basis'=>['type'=>'string','enum'=>['customer_id','email','international_phone']],'identity_verified'=>['type'=>'boolean','enum'=>[false]],'complete'=>['type'=>'boolean']]);
    }

    public static function result_schema(string $action,?array $order_schema=null): array
    {
        if($action===self::ReadActions[0]) {
            return self::object(['orders'=>['type'=>'array','maxItems'=>20,'items'=>$order_schema??self::order_schema()],'count'=>['type'=>'integer','minimum'=>0,'maximum'=>20],
                'page_info'=>self::object(['anchor_id'=>['type'=>'integer','minimum'=>0],'next_before_id'=>['type'=>['integer','null'],'minimum'=>1],'has_next_page'=>['type'=>'boolean']]),'coverage'=>self::coverage_schema()]);
        }
        $money=['type'=>'string','pattern'=>'^-?(?:0|[1-9][0-9]{0,11})\\.[0-9]{6}$'];
        return self::object(['window'=>self::object(['key'=>['type'=>'string','enum'=>YoRo_Store_Report_Period::Windows],'label'=>['type'=>'string','maxLength'=>100],
            'start'=>['type'=>'string','format'=>'date-time'],'end'=>['type'=>'string','format'=>'date-time'],'timezone'=>['type'=>'string','maxLength'=>100]]),
            'generated_at'=>['type'=>'string','format'=>'date-time'],'completed_at'=>['type'=>'string','format'=>'date-time'],'date_basis'=>['type'=>'string','enum'=>['created','paid']],
            'refund_basis'=>['type'=>'string','enum'=>['refund_created_date']],'coverage'=>self::coverage_schema(),'scanned'=>['type'=>'integer','minimum'=>0],
            'refunds_scanned'=>['type'=>'integer','minimum'=>0],'status_counts'=>self::object(array_fill_keys(array_map(static fn($status)=>str_replace('-','_',$status),YoRo_Early_Access_Order_Read::STATUSES),['type'=>'integer','minimum'=>0])),
            'currencies'=>['type'=>'array','maxItems'=>32,'items'=>self::object(['currency'=>['type'=>'string','pattern'=>'^[A-Z]{3}$'],'order_count'=>['type'=>'integer','minimum'=>0],...array_fill_keys(self::SummaryMoney,$money)])]]);
    }

    private static function order_schema(): array
    {
        $money=['type'=>'string','pattern'=>'^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?$'];$currency=['type'=>'string','pattern'=>'^[A-Z]{3}$'];$bool=['type'=>'boolean'];
        return self::object(['id'=>['type'=>'integer','minimum'=>1],'number'=>['type'=>'string'],'status'=>['type'=>'string','enum'=>YoRo_Early_Access_Order_Read::STATUSES],
            'status_label'=>['type'=>'string'],'total'=>$money,'currency'=>$currency,'created_at'=>['type'=>'string','format'=>'date-time'],'version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
            'payment'=>self::object(['status'=>['type'=>'string','enum'=>['paid','unpaid','failed','refunded']],'paid'=>$bool,'failed'=>$bool,'refunded'=>$bool,'amount'=>$money,'currency'=>$currency]),
            'fulfillment'=>self::object(['status'=>['type'=>'string','enum'=>['completed','not_confirmed']],'fulfilled'=>$bool,'requires_shipping'=>$bool,'tracking_count'=>['type'=>'integer','enum'=>[0]]]),
            'signals'=>['type'=>'array','maxItems'=>0,'items'=>self::object([])],'suggested_next_steps'=>['type'=>'array','maxItems'=>0,'items'=>self::object([])],
            'extensions'=>['type'=>'object']]);
    }

    public static function valid_params(string $action,$params): bool
    {
        if(!in_array($action,self::ReadActions,true)||!is_array($params)||array_diff(array_keys($params),array_keys(self::properties($action)))||!self::valid_selector($params['selector']??null)){return false;}
        if($action===self::ReadActions[0]) {
            return is_int($params['limit']??null)&&$params['limit']>=1&&$params['limit']<=20
                &&(!array_key_exists('anchor_id',$params)||(is_int($params['anchor_id'])&&$params['anchor_id']>=0))
                &&(!array_key_exists('before_id',$params)||(is_int($params['before_id'])&&$params['before_id']>=1&&isset($params['anchor_id'])&&$params['before_id']<=$params['anchor_id']));
        }
        if(!isset($params['window'])) {return false;}
        try{$period=array_diff_key($params,['selector'=>true]);return YoRo_Store_Report_Period::parameters($period)===$period;}catch(Throwable $error){return false;}
    }

    private static function valid_selector($selector): bool
    {
        return is_array($selector)&&((count($selector)===1&&is_int($selector['customer_id']??null)&&$selector['customer_id']>0)
            ||(count($selector)===2&&!array_diff(array_keys($selector),['guest_order_id','match'])&&is_int($selector['guest_order_id']??null)&&$selector['guest_order_id']>0&&in_array($selector['match']??null,['email','phone'],true)));
    }

    private static function readable($order): bool
    {return $order instanceof WC_Order&&!$order instanceof WC_Order_Refund&&$order->get_type()==='shop_order'&&$order->get_date_created()&&in_array($order->get_status(),YoRo_Early_Access_Order_Read::STATUSES,true);}

    private static function phone(string $raw): ?string
    {
        if(strlen($raw)>32||preg_match('/\A\+[0-9 ().-]+\z/D',$raw)!==1){return null;}
        $digits=preg_replace('/[^0-9]/','',$raw);return preg_match('/\A[1-9][0-9]{1,14}\z/D',$digits)===1?$digits:null;
    }

    private static function resolve(array $selector): array
    {
        if(isset($selector['customer_id'])) {return ['customer_id'=>$selector['customer_id'],'kind'=>'registered_customer','match_basis'=>'customer_id'];}
        $seed=wc_get_order($selector['guest_order_id']);if(!self::readable($seed)||$seed->get_customer_id()!==0){throw new RuntimeException('customer_reference_unavailable');}
        if($selector['match']==='email') {
            $email=strtolower((string)$seed->get_billing_email('edit'));if(strlen($email)>254||!is_email($email)||sanitize_email($email)!==$email){throw new RuntimeException('customer_reference_email_unavailable');}
            return ['guest_order_id'=>$seed->get_id(),'contact'=>$email,'kind'=>'shared_guest_contact','match_basis'=>'email'];
        }
        $phone=self::phone((string)$seed->get_billing_phone('edit'));if($phone===null){throw new RuntimeException('customer_reference_international_phone_required');}
        return ['guest_order_id'=>$seed->get_id(),'contact'=>$phone,'kind'=>'shared_guest_contact','match_basis'=>'international_phone'];
    }

    private static function matches(WC_Order|WC_Order_Refund $order,array $selection): bool
    {
        if($order instanceof WC_Order_Refund){$order=wc_get_order($order->get_parent_id());}
        if(!self::readable($order)){return false;}
        if(isset($selection['customer_id'])){return $order->get_customer_id()===$selection['customer_id'];}
        $contact=$selection['match_basis']==='email'?strtolower((string)$order->get_billing_email('edit')):self::phone((string)$order->get_billing_phone('edit'));
        return $order->get_customer_id()===0&&$contact===$selection['contact'];
    }

    /** IDs/table names come only from Woo's scoped query. Values are prepared, never interpolated input. */
    private static function predicate(array $selection,string $id,bool $hpos,$query=null,bool $refund=false): string
    {
        global $wpdb;
        $orders=$hpos?$query->get_table_name('orders'):$wpdb->posts;$meta=$hpos?null:$wpdb->postmeta;
        $target=$refund?($hpos?$orders.'.parent_order_id':$orders.'.post_parent'):$id;
        $table=$hpos?$orders:$wpdb->posts;$customer=$hpos?'ych_order.customer_id':$wpdb->prepare("(SELECT ych_m.meta_value FROM {$meta} ych_m WHERE ych_m.post_id=ych_order.ID AND ych_m.meta_key=%s LIMIT 1)",'_customer_user');
        $where=['ych_order.'.($hpos?'id':'ID').' = '.$target,$hpos?"ych_order.type='shop_order'":"ych_order.post_type='shop_order'"];
        if(isset($selection['customer_id'])) {$where[]=$wpdb->prepare("COALESCE({$customer},0) = %d",$selection['customer_id']);}
        else {
            $where[]="COALESCE({$customer},0) = 0";
            if($selection['match_basis']==='email') {
                $contact=$hpos?'ych_order.billing_email':$wpdb->prepare("(SELECT ych_m.meta_value FROM {$meta} ych_m WHERE ych_m.post_id=ych_order.ID AND ych_m.meta_key=%s LIMIT 1)",'_billing_email');
                $where[]=$wpdb->prepare("BINARY LOWER(COALESCE({$contact},'')) = BINARY %s",$selection['contact']);
            }else{
                $contact=$hpos?$wpdb->prepare('(SELECT ych_a.phone FROM '.$query->get_table_name('addresses')." ych_a WHERE ych_a.order_id=ych_order.id AND ych_a.address_type=%s LIMIT 1)",'billing')
                    :$wpdb->prepare("(SELECT ych_m.meta_value FROM {$meta} ych_m WHERE ych_m.post_id=ych_order.ID AND ych_m.meta_key=%s LIMIT 1)",'_billing_phone');
                $digits="COALESCE({$contact},'')";foreach(['+',' ','(',')','.','-'] as $remove){$digits=$wpdb->prepare("REPLACE({$digits},%s,'')",$remove);}
                $where[]=$wpdb->prepare("LENGTH({$contact})<=32 AND {$contact} REGEXP %s AND BINARY {$digits}=BINARY %s",'^[+][0-9 ().-]+$',$selection['contact']);
            }
        }
        return 'EXISTS (SELECT 1 FROM '.$table.' ych_order WHERE '.implode(' AND ',$where).')';
    }

    private static function anchor(): int
    {
        global $wpdb;$hpos=\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();$table=$hpos?$wpdb->prefix.'wc_orders':$wpdb->posts;
        $id=$wpdb->get_var("SELECT MAX(ID) FROM {$table}");if($wpdb->last_error!==''){throw new RuntimeException('customer_history_anchor_unavailable');}return (int)$id;
    }

    private static function query_history(array $selection,int $anchor,?int $before,int $limit): array
    {
        global $wpdb;$token=wp_generate_uuid4();$applied=false;
        $where=static fn($id,$hpos,$query)=>$wpdb->prepare("{$id}<=%d",$anchor).($before!==null?$wpdb->prepare(" AND {$id}<%d",$before):'').' AND ('.self::predicate($selection,$id,$hpos,$query).')';
        $hpos=static function($clauses,$query,$vars)use($token,&$applied,$where){if(($vars['yoro_customer_cursor']??null)===$token){$clauses['where'].=' AND ('.$where($query->get_table_name('orders').'.id',true,$query).')';$applied=true;}return $clauses;};
        $cpt=static function($sql,$query)use($token,&$applied,$where){if($query->get('yoro_customer_cursor')===$token){global $wpdb;$sql.=' AND ('.$where($wpdb->posts.'.ID',false,null).')';$applied=true;}return $sql;};
        $args=static function($args,$vars)use($token){if(($vars['yoro_customer_cursor']??null)===$token){$args['yoro_customer_cursor']=$token;$args['suppress_filters']=false;}return $args;};
        add_filter('woocommerce_orders_table_query_clauses',$hpos,10,3);add_filter('woocommerce_order_data_store_cpt_get_orders_query',$args,10,2);add_filter('posts_where',$cpt,10,2);
        try{$rows=wc_get_orders(['type'=>'shop_order','status'=>YoRo_Early_Access_Order_Read::STATUSES,'limit'=>$limit+1,'orderby'=>'ID','order'=>'DESC','yoro_customer_cursor'=>$token]);if(!$applied||$wpdb->last_error!==''||!is_array($rows)){throw new RuntimeException('customer_history_selector_not_applied');}return $rows;}
        finally{remove_filter('woocommerce_orders_table_query_clauses',$hpos,10);remove_filter('woocommerce_order_data_store_cpt_get_orders_query',$args,10);remove_filter('posts_where',$cpt,10);}
    }

    private static function coverage(array $selection,bool $complete): array
    {return ['kind'=>$selection['kind'],'match_basis'=>$selection['match_basis'],'identity_verified'=>false,'complete'=>$complete];}

    private static function history(array $params,array $selection): array
    {
        $current=self::anchor();$anchor=$params['anchor_id']??$current;if($anchor>$current){throw new RuntimeException('customer_history_anchor_invalid');}
        $rows=self::query_history($selection,$anchor,$params['before_id']??null,$params['limit']);$hasNext=count($rows)>$params['limit'];$orders=[];
        foreach(array_slice($rows,0,$params['limit']) as $order){if(!self::matches($order,$selection)){throw new RuntimeException('customer_history_selector_mismatch');}$row=YoRo_Early_Access_Order_Read::search_row($order);
            if(!preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D',$row['total'])||!preg_match('/\A[A-Z]{3}\z/D',$row['currency'])){throw new RuntimeException('customer_history_invalid_money');}$orders[]=$row;}
        return ['orders'=>$orders,'count'=>count($orders),'page_info'=>['anchor_id'=>$anchor,'next_before_id'=>$hasNext?$orders[count($orders)-1]['id']:null,'has_next_page'=>$hasNext],'coverage'=>self::coverage($selection,!$hasNext)];
    }

    private static function summary(array $operation,array $selection,$guard): array|WP_Error
    {
        unset($operation['params']['selector']);$operation['params']['comparison']='none';
        $config=['scope_key'=>hash('sha256',wp_json_encode($selection)),'predicate'=>static fn($id,$hpos,$query,$refund)=>self::predicate($selection,$id,$hpos,$query,$refund),
            'matches'=>static fn($order)=>self::matches($order,$selection),'summary_only'=>true,'finish'=>static function(array $state)use($selection){
                $period=$state['periods'][0];if($period['invalid_orders']>0){return new WP_Error('customer_summary_invalid_data','Some selected order records could not be assessed.');}
                $currencies=[];ksort($period['currencies']);foreach($period['currencies'] as $currency=>$money){$row=['currency'=>$currency,'order_count'=>$money['order_count']];
                    foreach(array_slice(self::SummaryMoney,0,8) as $field){$row[$field]=YoRo_Complete_Store_Report::decimal($money[$field]);}
                    $row['order_totals_less_period_refunds']=YoRo_Complete_Store_Report::decimal($money['order_totals']-$money['refunds']);
                    $row['net_ex_tax_shipping']=YoRo_Complete_Store_Report::decimal($money['order_totals']-$money['tax']-$money['shipping']-$money['refunds']+$money['refund_tax']+$money['refund_shipping']);$currencies[]=$row;}
                $statusCounts=[];foreach($period['status_counts'] as $status=>$count){$statusCounts[str_replace('-','_',$status)]=$count;}
                return ['window'=>$state['windows'][0],'generated_at'=>gmdate('c',$state['as_of']),'completed_at'=>gmdate('c',$state['completed_at']),'date_basis'=>$state['basis'],
                    'refund_basis'=>'refund_created_date','coverage'=>self::coverage($selection,true),'scanned'=>$period['scanned'],'refunds_scanned'=>$period['refunds_scanned'],'status_counts'=>$statusCounts,'currencies'=>$currencies];
            }];
        return YoRo_Complete_Store_Report::run($operation,$guard,$config);
    }

    public static function execute(array $operation,$guard=null): array|WP_Error
    {
        try{
            $action=$operation['action']??'';$params=$operation['params']??null;
            $guarded=static fn()=>current_user_can('yoro_view_store_data')&&current_user_can('manage_woocommerce')&&(!is_callable($guard)||$guard())
                &&(!isset($operation['expires_at'])||strtotime($operation['expires_at'])>time());
            if(!self::valid_params($action,$params)||!$guarded()){return new WP_Error('customer_history_denied','Customer history access is unavailable or expired.');}
            $selection=self::resolve($params['selector']);$data=$action===self::ReadActions[0]?self::history($params,$selection):self::summary($operation,$selection,$guarded);
            if(is_wp_error($data)||isset($data['deferred'])){return $data;}
            if(!$guarded()){return new WP_Error('customer_history_denied','Customer history access expired.');}
            $message='Selected order records only. Shared guest contact does not verify a person; no customer messages are sent.';
            return ['success'=>true,'action'=>$action,'message'=>$message,'summary'=>$message,'data'=>$data,'read_only'=>true,'undoable'=>false,
                'result_type'=>$action===self::ReadActions[0]?'customer_order_history':'customer_period_summary','display_type'=>$action===self::ReadActions[0]?'customer_order_history':'customer_period_summary','assistant_summary'=>$message,'extensions'=>(object)[]];
        }catch(Throwable $error){return new WP_Error('customer_history_unavailable','The complete customer read could not be assessed. Check the selector and request a current read.');}
    }

    public static function execute_read(array $operation,$guard=null): array|WP_Error {return self::execute($operation,$guard);}

    public static function register(): void
    {
        foreach(self::ReadActions as $action){$properties=self::properties($action);$input=[];foreach($properties as $key=>$property){$input[$key]=$property['type'];}
            YoRo_Action_Registry::register($action,['label'=>$action===self::ReadActions[0]?'Customer order history':'Customer period summary','description'=>'Complete selected order records with locally resolved guest contact and explicit coverage.',
                'category'=>'customers','permission'=>'yoro_view_store_data','callback'=>static function($params)use($action){unset($params['_yoro_confirmed'],$params['_yoro_preview']);return self::execute(['action'=>$action,'params'=>$params]);},
                'risk_level'=>'low','write_action'=>false,'effect'=>'read','target_type'=>'customer','max_items'=>20,'input_schema'=>$input,'canonical_input_schema'=>$properties,
                'required_params'=>$action===self::ReadActions[0]?['selector','limit']:['selector','window'],'capabilities'=>['customers','orders','read']]);}
    }
}
