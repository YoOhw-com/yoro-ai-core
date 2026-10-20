<?php

if (!defined('ABSPATH')) { exit; }

/** Complete order-line flows; calculation shares the existing bounded report cursor. */
final class YoRo_Commerce_Insights
{
    public const ReadActions = ['reports.get_product_sales','reports.get_category_sales','reports.get_coupon_performance','reports.get_refund_analysis'];
    public const ManifestExtension = 'yoro.early_access_commerce_insights';
    public const Measures = ['quantity','sales','discounts','refunds','net_sales','refund_tax','refund_shipping','unallocated_refunds'];

    public static function manifest(): array
    {
        return ['version'=>1,'actions'=>self::ReadActions,'coverage'=>'all_matching_orders','batch_size'=>100,
            'max_period_days'=>366,'report_execution_seconds'=>1800,'max_rows'=>50,'customer_data'=>'excluded','result_version'=>1];
    }

    public static function input_properties(string $action): array
    {
        $date=['type'=>'string','pattern'=>'^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$'];
        return ['window'=>['type'=>'string','enum'=>YoRo_Store_Report_Period::Windows],'date_basis'=>['type'=>'string','enum'=>['created','paid']],
            'anchor_date'=>$date,'date_from'=>$date,'date_to'=>$date,'comparison'=>['type'=>'string','enum'=>YoRo_Store_Report_Period::Comparisons],
            'comparison_from'=>$date,'comparison_to'=>$date,'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50]];
    }

    public static function valid_params(string $action,$params): bool
    {
        if (!in_array($action,self::ReadActions,true)||!is_array($params)||array_diff(array_keys($params),array_keys(self::input_properties($action)))
            ||!is_int($params['limit']??null)||$params['limit']<1||$params['limit']>50||!isset($params['window'])) { return false; }
        $period=array_diff_key($params,['limit'=>true]);
        try { return YoRo_Store_Report_Period::parameters($period)===$period; } catch (Throwable $error) { return false; }
    }

    private static function object(array $properties): array
    { return ['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false]; }

    public static function result_schema(string $action): array
    {
        $int=['type'=>'integer','minimum'=>0,'maximum'=>2147483647];
        $money=['type'=>'string','pattern'=>'^-?(?:0|[1-9][0-9]{0,9})\\.[0-9]{6}$'];
        $currency=['type'=>'string','pattern'=>'^[A-Z]{3}$'];
        $window=self::object(['key'=>['type'=>'string','maxLength'=>40],'label'=>['type'=>'string','maxLength'=>100],
            'start'=>['type'=>'string','format'=>'date-time'],'end'=>['type'=>'string','format'=>'date-time'],'timezone'=>['type'=>'string','maxLength'=>100]]);
        $period=self::object(['window'=>$window,'scanned'=>$int,'refunds_scanned'=>$int,'group_count'=>$int,'rows_complete'=>['type'=>'boolean'],
            'totals'=>['type'=>'array','maxItems'=>32,'items'=>self::object(['currency'=>$currency,...array_fill_keys(self::Measures,$money)])],
            'rows'=>['type'=>'array','maxItems'=>50,'items'=>self::object(['id'=>['type'=>'string','maxLength'=>160],'product_id'=>$int,'name'=>['type'=>'string','maxLength'=>160],'currency'=>$currency,...array_fill_keys(self::Measures,$money)])],
            'daily'=>['type'=>'array','maxItems'=>11712,'items'=>self::object(['date'=>['type'=>'string','pattern'=>'^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],'currency'=>$currency,...array_fill_keys(self::Measures,$money)])]]);
        return self::object(['version'=>['type'=>'integer','enum'=>[1]],'action'=>['type'=>'string','enum'=>[$action]],
            'generated_at'=>['type'=>'string','format'=>'date-time'],'completed_at'=>['type'=>'string','format'=>'date-time'],
            'date_basis'=>['type'=>'string','enum'=>['created','paid']],'refund_basis'=>['type'=>'string','enum'=>['refund_created_date']],
            'category_basis'=>['type'=>'string','enum'=>['current_catalog_membership_overlapping']],
            'chart_resolution'=>['type'=>'string','enum'=>['day','month','none']],
            'period_context'=>self::object(['week_starts_on'=>['type'=>'integer','minimum'=>0,'maximum'=>6],
                'comparison_mode'=>['type'=>'string','enum'=>YoRo_Store_Report_Period::Comparisons],'period_to_date'=>['type'=>'boolean'],'comparison_shorter'=>['type'=>'boolean']]),
            'periods'=>['type'=>'array','minItems'=>2,'maxItems'=>2,'items'=>$period]]);
    }

    private static function zero(): array { return array_fill_keys(self::Measures,0); }

    private static function add(array &$target,array $delta): void
    {
        foreach ($delta as $key=>$value) {
            if (!is_int($value)||!isset($target[$key])||abs($value)>YoRo_Complete_Store_Report::MAX_UNITS
                ||abs($target[$key])>YoRo_Complete_Store_Report::MAX_UNITS-abs($value)) { throw new RuntimeException('insight_numeric_capacity'); }
            $target[$key]+=$value;
        }
        $target['net_sales']=$target['sales']-$target['refunds'];
        if (abs($target['net_sales'])>YoRo_Complete_Store_Report::MAX_UNITS) { throw new RuntimeException('insight_numeric_capacity'); }
    }

    private static function metric($value,bool $absolute=false): int
    {
        $units=YoRo_Complete_Store_Report::units((string)$value,true);
        return $absolute?abs($units):$units;
    }

    private static function record(array &$period,string $currency,string $day,array $delta,array $groups): void
    {
        $period['totals'][$currency]=$period['totals'][$currency]??self::zero();
        if (count($period['totals'])>32) { throw new RuntimeException('insight_currency_capacity'); }
        self::add($period['totals'][$currency],$delta);
        $point=$day.':'.$currency;$period['daily'][$point]=$period['daily'][$point]??['date'=>$day,'currency'=>$currency,'metrics'=>self::zero()];
        self::add($period['daily'][$point]['metrics'],$delta);
        foreach ($groups as $group) {
            $key=$currency.':'.$group['id'];
            $period['rows'][$key]=$period['rows'][$key]??[...$group,'currency'=>$currency,'metrics'=>self::zero()];
            if (count($period['rows'])>10000) { throw new RuntimeException('insight_group_capacity'); }
            self::add($period['rows'][$key]['metrics'],$delta);
        }
    }

    private static function groups($item,string $action): array
    {
        $productId=(int)($item->get_variation_id()?:$item->get_product_id());
        if ($action===self::ReadActions[1]) {
            $ids=wc_get_product_term_ids((int)$item->get_product_id(),'product_cat');
            if (is_wp_error($ids)) { throw new RuntimeException('insight_category_unavailable'); }
            $groups=[];
            foreach ($ids?:[0] as $id) {
                $term=$id?get_term($id,'product_cat'):null;
                if (is_wp_error($term)) { throw new RuntimeException('insight_category_unavailable'); }
                $groups[]=['id'=>(string)$id,'product_id'=>0,'name'=>mb_substr(sanitize_text_field($term?$term->name:'Uncategorized or unavailable'),0,160)];
            }
            return $groups;
        }
        return [['id'=>(string)$productId,'product_id'=>$productId,'name'=>mb_substr(sanitize_text_field($item->get_name()),0,160)]];
    }

    private static function consume(array &$state,$order,string $action): void
    {
        if ($state['phase']>3) { return; }
        $index=$state['phase']%2;$refund=$state['phase']>=2;
        $state['insights']=$state['insights']??[['totals'=>[],'rows'=>[],'daily'=>[]],['totals'=>[],'rows'=>[],'daily'=>[]]];
        $period=&$state['insights'][$index];$currency=$order->get_currency();
        if (!preg_match('/\A[A-Z]{3}\z/D',$currency)) { throw new RuntimeException('insight_currency_invalid'); }
        $date=$refund?$order->get_date_created():$order->{$state['basis']==='paid'?'get_date_paid':'get_date_created'}();
        if (!$date) { throw new RuntimeException('insight_date_invalid'); }
        $day=(clone $date)->setTimezone(wp_timezone())->format('Y-m-d');
        if (!$refund&&!in_array($order->get_status(),['processing','completed','refunded'],true)) { return; }
        if ($action===self::ReadActions[2]) {
            if ($refund) { return; }
            foreach ($order->get_items('coupon') as $coupon) {
                $raw=(string)$coupon->get_code();$code=sanitize_text_field($raw);
                if ($raw!==$code||mb_strlen($code)>150) {throw new RuntimeException('insight_coupon_capacity');}
                if ($code==='') { throw new RuntimeException('insight_coupon_invalid'); }
                self::record($period,$currency,$day,['quantity'=>1000000,'discounts'=>self::metric($coupon->get_discount())],
                    [['id'=>$code,'product_id'=>0,'name'=>$code]]);
            }
            return;
        }
        if ($action===self::ReadActions[3]&&!$refund) { return; }
        $parent=$refund?wc_get_order($order->get_parent_id()):$order;
        if ($refund&&(!$parent||$parent->get_type()!=='shop_order'||$parent->get_currency()!==$currency)) { throw new RuntimeException('insight_refund_parent_unavailable'); }
        $allocated=0;
        foreach ($order->get_items('line_item') as $item) {
            $source=$refund?$parent->get_item((int)$item->get_meta('_refunded_item_id',true)):$item;
            if (!$source) { continue; }
            $amount=self::metric($item->get_total(),$refund);
            $delta=$refund?['quantity'=>-self::metric($item->get_quantity(),true),'refunds'=>$amount]
                :['quantity'=>self::metric($item->get_quantity()),'sales'=>$amount,'discounts'=>self::metric($item->get_subtotal())-$amount];
            self::record($period,$currency,$day,$delta,self::groups($source,$action));$allocated+=$refund?$amount:0;
        }
        if ($refund) {
            $tax=self::metric($order->get_total_tax(),true);$shipping=self::metric($order->get_shipping_total(),true);
            $unallocated=self::metric($order->get_amount(),true)-$allocated-$tax-$shipping;
            if ($unallocated<0) { throw new RuntimeException('insight_refund_inconsistent'); }
            self::record($period,$currency,$day,['refund_tax'=>$tax,'refund_shipping'=>$shipping,'unallocated_refunds'=>$unallocated],[]);
        }
    }

    private static function decimals(array $metrics): array
    { return array_map(static fn($value)=>YoRo_Complete_Store_Report::decimal($value),$metrics); }

    private static function finish(array $state,string $action,int $limit): array|WP_Error
    {
        $periods=[];
        foreach ([0,1] as $index) {
            $base=$state['periods'][$index];
            if ($base['invalid_orders']) { return new WP_Error('insight_invalid_data','Some order records could not be assessed.'); }
            $part=$state['insights'][$index]??['totals'=>[],'rows'=>[],'daily'=>[]];
            ksort($part['totals']);ksort($part['daily']);
            $rows=array_values($part['rows']);
            usort($rows,static function($a,$b)use($action){$metric=$action===self::ReadActions[2]?'discounts':($action===self::ReadActions[3]?'refunds':'net_sales');
                return strcmp($a['currency'],$b['currency'])?:($b['metrics'][$metric]<=>$a['metrics'][$metric])?:strcmp($a['id'],$b['id']);});
            $totals=[];foreach($part['totals'] as $currency=>$metrics){$totals[]=['currency'=>$currency,'metrics'=>self::decimals($metrics)];}
            $rows=array_slice($rows,0,$limit);foreach($rows as &$row){$row['metrics']=self::decimals($row['metrics']);}unset($row);
            $daily=array_values($part['daily']);foreach($daily as &$point){$point['metrics']=self::decimals($point['metrics']);}unset($point);
            $periods[]=['window'=>$state['windows'][$index],'scanned'=>$base['scanned'],'refunds_scanned'=>$base['refunds_scanned'],
                'group_count'=>count($part['rows']),'rows_complete'=>count($part['rows'])<=count($rows),'totals'=>$totals,'rows'=>$rows,'daily'=>$daily];
        }
        $context=$state['period_context'];unset($context['chart_granularity']);
        $data=['version'=>1,'action'=>$action,'generated_at'=>gmdate('c',$state['as_of']),'completed_at'=>gmdate('c',$state['completed_at']),
            'date_basis'=>$state['basis'],'refund_basis'=>'refund_created_date','category_basis'=>'current_catalog_membership_overlapping',
            'chart_resolution'=>'day','period_context'=>$context,'periods'=>$periods];
        if (strlen(wp_json_encode($data))>42000) {
            $data['chart_resolution']='month';
            foreach($data['periods'] as &$period){$months=[];foreach($period['daily'] as $point){$date=substr($point['date'],0,7).'-01';$key=$date.':'.$point['currency'];
                    $months[$key]=$months[$key]??['date'=>$date,'currency'=>$point['currency'],'metrics'=>self::zero()];
                    self::add($months[$key]['metrics'],array_map(static fn($value)=>self::metric($value),$point['metrics']));}
                $period['daily']=array_values($months);foreach($period['daily'] as &$point){$point['metrics']=self::decimals($point['metrics']);}unset($point);}unset($period);
        }
        if (strlen(wp_json_encode($data))>42000) { $data['chart_resolution']='none';foreach($data['periods'] as &$period){$period['daily']=[];}unset($period); }
        while(strlen(wp_json_encode($data))>42000&&($data['periods'][0]['rows']||$data['periods'][1]['rows'])) {
            foreach($data['periods'] as &$period){array_pop($period['rows']);$period['rows_complete']=$period['group_count']===count($period['rows']);}unset($period);
        }
        if (strlen(wp_json_encode($data))>42000) { throw new RuntimeException('insight_payload_capacity'); }
        foreach ($data['periods'] as &$part) { foreach (['totals','rows','daily'] as $kind) { foreach ($part[$kind] as &$record) { $record += $record['metrics']; unset($record['metrics']); } unset($record); } } unset($part);
        return $data;
    }

    public static function execute(array $operation,$guard=null): array|WP_Error
    {
        $action=$operation['action']??'';$params=$operation['params']??null;
        $allowed=static fn()=>current_user_can('yoro_view_store_data')&&current_user_can('manage_woocommerce')
            &&(!is_callable($guard)||$guard())&&(!isset($operation['expires_at'])||strtotime($operation['expires_at'])>time());
        if (!self::valid_params($action,$params)||!$allowed()) { return new WP_Error('insight_access_denied','Report access is unavailable.'); }
        $limit=$params['limit'];unset($operation['params']['limit']);
        try {
            $data=YoRo_Complete_Store_Report::run($operation,$allowed,['scope_key'=>hash('sha256',wp_json_encode([$action,$params])),
                'predicate'=>static fn()=> '1=1','matches'=>static fn()=>true,'summary_only'=>true,
                'consume'=>static function(&$state,$order)use($action){self::consume($state,$order,$action);},
                'finish'=>static fn($state)=>self::finish($state,$action,$limit)]);
            if (is_wp_error($data)||isset($data['deferred'])) { return $data; }
            if (!$allowed()) { return new WP_Error('insight_access_expired','Report access expired.'); }
            $message='Complete order-line flows. Refunds use their own creation dates; currencies stay separate. Rankings may show only the displayed groups.';
            return ['success'=>true,'action'=>$action,'message'=>$message,'summary'=>$message,'data'=>$data,'read_only'=>true,'undoable'=>false,
                'result_type'=>'commerce_insights','display_type'=>'commerce_insights','assistant_summary'=>$message,'extensions'=>(object)[]];
        } catch(Throwable $error) { return new WP_Error('insight_unavailable','The complete report could not be assessed. Request a current report.'); }
    }

    public static function register(): void
    {
        foreach (self::ReadActions as $action) {
            $properties=self::input_properties($action);$input=[];foreach($properties as $key=>$property){$input[$key]=$property['type'];}
            YoRo_Action_Registry::register($action,['label'=>ucwords(str_replace(['reports.get_','_'],['',' '],$action)),
                'description'=>'Complete order-line sales, discount and refund flows with separate currencies and period comparison.',
                'category'=>'reports','permission'=>'yoro_view_store_data','callback'=>static function($params)use($action){unset($params['_yoro_confirmed'],$params['_yoro_preview']);return self::execute(['action'=>$action,'params'=>$params]);},
                'risk_level'=>'low','write_action'=>false,'effect'=>'read','target_type'=>'store','max_items'=>50,'input_schema'=>$input,
                'canonical_input_schema'=>$properties,'required_params'=>['window','limit'],'capabilities'=>['reports','read']]);
        }
    }
}
