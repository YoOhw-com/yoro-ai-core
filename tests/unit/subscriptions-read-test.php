<?php
namespace Automattic\WooCommerce\Utilities { class OrderUtil { public static function custom_orders_table_usage_is_enabled(){return $GLOBALS['hpos'];} } }
namespace {
define('ABSPATH','/disposable/');define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);
$GLOBALS['options']=[];$GLOBALS['filters']=[];$GLOBALS['allowed']=true;$GLOBALS['denied_ids']=[];$GLOBALS['hpos']=false;$GLOBALS['queries']=[];$GLOBALS['objects']=[];$GLOBALS['mutate_on_query']=false;
$GLOBALS['principal_id']=1;
// Both characterized owners register subscription read_post as read_shop_order.
$GLOBALS['capabilities']=['yoro_view_store_data'=>true,'manage_woocommerce'=>true,'read_shop_order'=>true,'read_post'=>true,'yoro_execute_actions'=>true,'edit_post'=>true];
$GLOBALS['capability_checks']=[];
class WP_Error {public function __construct(public string $code,public string $message){}public function get_error_code(){return $this->code;}}
function is_wp_error($value){return $value instanceof WP_Error;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_generate_uuid4(){static $n=0;return sprintf('00000000-0000-4000-8000-%012d',++$n);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;return true;}
function add_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;return true;}
function delete_option($key){unset($GLOBALS['options'][$key]);}
function maybe_serialize($value){return serialize($value);}
function get_current_user_id(){return $GLOBALS['principal_id'];}
function home_url(){return 'https://disposable.example';}
function wp_timezone_string(){return 'Asia/Ho_Chi_Minh';}
function wp_timezone(){return new \DateTimeZone(wp_timezone_string());}
function wp_date($format){return (new \DateTimeImmutable('now',wp_timezone()))->format($format);}
function get_woocommerce_currency(){return 'USD';}
function current_user_can($cap,...$args){
 $GLOBALS['capability_checks'][]=[$cap,$args];
 if(!get_current_user_id()||!$GLOBALS['allowed']||empty($GLOBALS['capabilities'][$cap])){return false;}
 if(in_array($cap,['read_shop_order','read_post','edit_post'],true)){
  $id=$args[0]??null;
  return is_int($id)&&$id>0&&isset(($cap==='read_shop_order'?$GLOBALS['objects']:$GLOBALS['products'])[$id])&&!in_array($id,$GLOBALS['denied_ids'],true);
 }
 return $args===[];
}
function get_userdata($id){return $id===8?(object)['ID'=>8]:false;}
function wc_get_product($id){return $GLOBALS['products'][$id]??false;}
function sanitize_text_field($value){return trim(strip_tags($value));}
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['filters'][$name][$priority][]=$callback;}
function add_action($name,$callback,$priority=10,$args=1){add_filter($name,$callback,$priority,$args);}
function remove_filter($name,$callback,$priority=10){foreach($GLOBALS['filters'][$name][$priority]??[] as $i=>$value){if($value===$callback){unset($GLOBALS['filters'][$name][$priority][$i]);}}}
function apply_filters($name,$value,...$args){$groups=$GLOBALS['filters'][$name]??[];ksort($groups);foreach($groups as $callbacks){foreach($callbacks as $callback){$value=$callback($value,...$args);}}return $value;}
function do_action($name,...$args){foreach($GLOBALS['filters'][$name]??[] as $callbacks){foreach($callbacks as $callback){$callback(...$args);}}}
function wc_get_order_statuses(){return ['wc-pending'=>'Pending','wc-processing'=>'Processing','wc-completed'=>'Completed','wc-failed'=>'Failed','wc-refunded'=>'Refunded'];}
class FakeWpdb {
 public $options='options',$posts='posts',$prefix='wp_',$last_error='';
 public function prepare($query,...$args){foreach($args as $arg){$query=preg_replace('/%[sd]/',"'".(string)$arg."'",$query,1);}return $query;}
 public function get_var($query){if(str_contains($query,'MAX(ID)')){return max([0,...array_keys($GLOBALS['objects'])]);}preg_match("/option_name = '([^']+)'/",$query,$m);$value=$GLOBALS['options'][$m[1]??'']??null;return str_contains($query,'SELECT option_value')&&is_array($value)?serialize($value):$value;}
}
$GLOBALS['wpdb']=new FakeWpdb;
class YoRo_AI_Settings {public static function get_ai_api_url(){return 'https://disposable-api.example';}}
class YoRo_Early_Access_Order_Read {public const STATUSES=['pending','processing','on-hold','completed','cancelled','refunded','failed'];}
class WC_Subscriptions {public static $version='8.8.1';}
class WC_Subscriptions_Product {public static function get_period($p){return 'month';}public static function get_interval($p){return 1;}public static function get_length($p){return 0;}public static function get_trial_length($p){return 0;}public static function get_trial_period($p){return 'day';}public static function get_sign_up_fee($p){return '2.000001';}}
class WC_Order {
 public array $relations=[];public string $currency='USD',$status='completed',$total='1.000001';public ?\DateTimeImmutable $created,$paid;
 public function __construct(public int $id,public string $type='shop_order',public int $parent=0){$this->created=new \DateTimeImmutable('today 01:00:00',wp_timezone());$this->paid=$this->created;}
 public function get_id(){return $this->id;}public function get_type(){return $this->type;}public function get_parent_id($context='view'){return $this->parent;}public function get_status(){return $this->status;}public function get_currency(){return $this->currency;}public function get_total(){return $this->total;}public function get_date_created($context='view'){return $this->created;}public function get_date_paid(){return $this->paid;}public function save(){throw new \RuntimeException('DOMAIN WRITE');}public function update_status(){throw new \RuntimeException('DOMAIN WRITE');}
}
class WC_Order_Refund extends WC_Order {public function get_amount(){return $this->total;}}
class SubscriptionItem {public function get_product_id(){return 10;}public function get_variation_id(){return 0;}public function get_name(){return 'Subscription product';}public function get_quantity(){return 1;}public function get_total(){return '1.000001';}}
class WC_Subscription extends WC_Order {
 public int $customer=8,$item_count=1;public bool $manual=false;public ?string $next=null;
 public function __construct($id){parent::__construct($id,'shop_subscription',9000);$this->status='active';}
 public function get_date($key,$timezone='gmt',$exclude=[]){return $key==='start'?$this->created->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'):($key==='next_payment'?($this->next??'0'):'0');}
 public function get_billing_period($context='view'){return 'month';}public function get_billing_interval($context='view'){return 1;}public function get_requires_manual_renewal($context='view'){return $this->manual;}public function is_manual(){throw new \RuntimeException('GATEWAY CONSTRUCTOR');}public function get_items($type='line_item'){return array_fill(0,$this->item_count,new SubscriptionItem);}
 public function get_customer_id(){return $this->customer;}public function get_related_orders(...$args){throw new \RuntimeException('PERSISTENT CACHE WRITE');}public function get_paginated_related_orders(...$args){throw new \RuntimeException('PERSISTENT CACHE WRITE');}public function get_date_paid(){throw new \RuntimeException('SUBSCRIPTION DERIVED PAYMENT CACHE');}
}
function wcs_get_subscription($id){return ($GLOBALS['objects'][$id]??null) instanceof WC_Subscription?$GLOBALS['objects'][$id]:false;}
function wcs_get_subscription_ids_for_order($order,$kinds=['any']){if(array_diff($kinds,['renewal','switch','resubscribe'])){throw new \RuntimeException('UNBOUNDED PARENT QUERY');}$ids=[];foreach($kinds as $kind){$ids=[...$ids,...($order->relations[$kind]??[])];}return $ids;}
function owner_query(array $args){$GLOBALS['queries'][]=$args;if(($args['limit']??0)<1||($args['limit']??0)>100){throw new \RuntimeException('UNBOUNDED QUERY');}$objects=array_values(array_filter($GLOBALS['objects'],fn($o)=>$o->get_type()===$args['type']));usort($objects,fn($a,$b)=>($args['order']==='DESC'?-1:1)*($a->get_id()<=>$b->get_id()));$objects=array_slice($objects,$args['offset']??0,$args['limit']);if($GLOBALS['mutate_on_query']){$GLOBALS['options'][YoRo_Complete_Store_Report::MUTATION_OPTION]=wp_generate_uuid4();$GLOBALS['mutate_on_query']=false;}return $objects;}
function wcs_get_subscriptions($args){if(isset($args['product_id'])||isset($args['customer_id'])){throw new \RuntimeException('UNBOUNDED OWNER FILTER COMBINATION');}return owner_query(['type'=>'shop_subscription','limit'=>$args['subscriptions_per_page'],'offset'=>$args['offset']??0,'order'=>$args['order'],'orderby'=>$args['orderby']]);}
function wc_get_orders($args){
 if(isset($args['yoro_report_cursor'])){if($GLOBALS['hpos']){apply_filters('woocommerce_orders_table_query_clauses',['where'=>''],new class{function get_table_name($name){return 'wp_orders';}},$args);}else{$queryArgs=apply_filters('woocommerce_order_data_store_cpt_get_orders_query',[],$args);apply_filters('posts_where','',new class($queryArgs){function __construct(private $args){}function get($key){return $this->args[$key]??null;}});}return [];}
 return owner_query($args);
}
function wc_get_order($id){return $GLOBALS['objects'][$id]??false;}

function __($text,...$args){return $text;}
function absint($value){return abs((int)$value);}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower($value));}
function sanitize_textarea_field($value){return trim(strip_tags($value));}
function wp_strip_all_tags($value){return strip_tags($value);}
function wp_parse_url($url){return parse_url($url);}
function esc_url_raw($url){return $url;}
function get_edit_post_link($id,$context='display'){return 'https://disposable.example/wp-admin/post.php?post='.$id.'&action=edit';}
function get_posts($args){return ($args['post_parent']??null)===11?[12]:[];}
function update_meta_cache($type,$ids){return [];}
function wc_get_products($args){if(($args['type']??null)!=='variation'){throw new \RuntimeException('Use Woo variation query alias; WCS owns product class resolution.');}return (object)['products'=>[$GLOBALS['products'][12]],'total'=>1,'max_num_pages'=>1];}
class WC_Product {
 public string $status='publish';
 public function __construct(public int $id,public string $type='subscription',public int $parent=0){}
 public function get_id(){return $this->id;}public function get_type(){return $this->type;}public function is_type($type){return in_array($this->type,(array)$type,true);}public function get_status(){return $this->status;}public function get_parent_id(){return $this->parent;}
 public function get_attributes($context='view'){return [];}public function get_default_attributes(){return [];}public function get_image_id(){return 0;}public function get_permalink(){return 'https://disposable.example/product/'.$this->id;}
 public function get_description(){return 'Subscription product.';}public function get_short_description(){return 'A product, not an agreement.';}public function get_name(){return 'Product';}public function get_sku(){return 'SUB-'.$this->id;}public function get_price(){return '9.99';}public function get_stock_quantity(){return null;}public function get_stock_status(){return 'instock';}public function managing_stock(){return false;}public function save(){throw new \RuntimeException('DOMAIN PRODUCT WRITE');}
}
class WC_Product_Variable extends WC_Product {}
class YoRo_Commerce_Discovery_Service {public static function product_version($product){return hash('sha256',(string)$product->get_id());}}
$GLOBALS['products']=[10=>new WC_Product(10),11=>new WC_Product_Variable(11,'variable-subscription'),12=>new WC_Product(12,'subscription_variation',11)];

require __DIR__.'/../../includes/commerce/class-yoro-store-report-period.php';
require __DIR__.'/../../includes/commerce/class-yoro-complete-store-report.php';
require __DIR__.'/../../includes/commerce/class-yoro-subscriptions-read.php';
$assert=function($condition,$message){if(!$condition){throw new \RuntimeException($message);} $GLOBALS['assertions']=($GLOBALS['assertions']??0)+1;};
$run=function($action,$params=[],$guard=null,$expiresAt=null){return YoRo_Subscriptions_Read::execute(['action'=>$action,'params'=>$params,'envelope_id'=>'envelope_'.str_repeat('a',64),'expires_at'=>$expiresAt??gmdate('c',time()+1800)],$guard);};
$assert(YoRo_Subscriptions_Read::supported(),'Characterized signatures and version support.');
foreach(['8.8.0','9.2.0','8.8.1-dev'] as $version){WC_Subscriptions::$version=$version;$assert(!YoRo_Subscriptions_Read::supported(),'Uncharacterized owner fails closed.');$status=$run('subscriptions.get_status');$assert($status['data']['available']===false&&$status['data']['actions']===['subscriptions.get_status'],'Unsupported owner exposes only truthful status probe.');$assert(is_wp_error($run('subscriptions.search',['limit'=>20,'offset'=>0])),'Business reads unavailable.');}WC_Subscriptions::$version='8.8.1';
$versionResults=[];
foreach (['8.8.1','9.3.0'] as $ownerVersion) {
WC_Subscriptions::$version=$ownerVersion;
$status=$run('subscriptions.get_status');$assert($status['data']['available']===true&&$status['data']['owner_version']===$ownerVersion&&count($status['data']['actions'])===7,'Both characterized owners expose exactly seven read actions.');
for($i=1;$i<=310;$i++){$s=new WC_Subscription($i);$s->manual=$i%2===0;$s->currency=$i%2===0?'EUR':'USD';$GLOBALS['objects'][$i]=$s;}
$GLOBALS['objects'][9000]=new WC_Order(9000);$GLOBALS['objects'][9001]=new WC_Order(9001);$GLOBALS['objects'][9001]->relations=['renewal'=>[1,2],'switch'=>[1]];
$GLOBALS['objects'][9002]=new WC_Order(9002);$GLOBALS['objects'][9002]->relations=['renewal'=>[1]];$GLOBALS['objects'][9002]->status='failed';$GLOBALS['objects'][9002]->paid=null;$GLOBALS['objects'][9002]->currency='EUR';
$refund=new WC_Order_Refund(9010,'shop_order_refund',9001);$refund->total='0.000001';$GLOBALS['objects'][9010]=$refund;
foreach([false,true] as $hpos){$GLOBALS['hpos']=$hpos;$GLOBALS['options']=[];
 $assert(current_user_can('read_shop_order',1)&&!current_user_can('read_shop_subscription',1),'Native subscription read uses the registered order capability, without an invented subscription capability.');
 $assert(!current_user_can('read_shop_order')&&!current_user_can('read_shop_order',999999),'Native object capability requires a known positive object ID.');
 $assert(!is_wp_error($run('subscriptions.get_details',['subscription_id'=>1])),'Legitimate native order permission permits subscription details for each owner/storage fixture.');
 $actionParams=[
  'subscriptions.get_status'=>[],
  'subscriptions.search'=>['limit'=>20,'offset'=>0],
  'subscriptions.get_details'=>['subscription_id'=>1],
  'subscriptions.get_order_context'=>['order_id'=>9001,'limit'=>20,'offset'=>0],
  'subscriptions.get_related_orders'=>['subscription_id'=>1,'limit'=>20,'offset'=>0],
  'subscriptions.get_summary'=>['window'=>'today'],
  'subscriptions.get_renewal_report'=>['window'=>'today'],
 ];
 $assert(array_keys($actionParams)===YoRo_Subscriptions_Read::ReadActions&&YoRo_Subscriptions_Read::Reports===['subscriptions.get_summary','subscriptions.get_renewal_report'],'Exactly seven reads and two reports retain their public identities.');
 foreach($actionParams as $action=>$input){
  $GLOBALS['principal_id']=0;$assert(is_wp_error($run($action,$input)),'No principal denies '.$action);$GLOBALS['principal_id']=1;
  foreach(['yoro_view_store_data','manage_woocommerce'] as $cap){$GLOBALS['capabilities'][$cap]=false;$assert(is_wp_error($run($action,$input)),'Missing '.$cap.' denies '.$action);$GLOBALS['capabilities'][$cap]=true;}
  $assert(is_wp_error($run($action,$input,null,gmdate('c',time()-60))),'Expired operation denies '.$action);
  $assert(is_wp_error($run($action,$input,fn()=>false)),'External permission intersection denies '.$action);
  if($action==='subscriptions.get_status'){continue;}
  $GLOBALS['options']=[];$GLOBALS['capabilities']['read_shop_order']=false;
  $assert(is_wp_error($run($action,$input)),'Missing native object capability denies '.$action);$GLOBALS['capabilities']['read_shop_order']=true;
  $GLOBALS['options']=[];$GLOBALS['denied_ids']=[1];
  $assert(is_wp_error($run($action,$input)),'Native subscription object denial fails closed for '.$action);$GLOBALS['denied_ids']=[];
  WC_Subscriptions::$version='9.3.1';$assert(is_wp_error($run($action,$input)),'Uncharacterized owner denies '.$action);WC_Subscriptions::$version=$ownerVersion;
 }
 foreach([0,-1] as $invalidId){$GLOBALS['objects'][1]->id=$invalidId;$assert(is_wp_error($run('subscriptions.get_details',['subscription_id'=>1])),'Nonpositive owner object ID denies details.');}$GLOBALS['objects'][1]->id=1;
 $GLOBALS['objects'][1]->id=2;$assert(is_wp_error($run('subscriptions.get_details',['subscription_id'=>1])),'Mismatched owner object ID denies details.');$GLOBALS['objects'][1]->id=1;
 $GLOBALS['objects'][1]->type='unsupported_order';$assert(is_wp_error($run('subscriptions.get_details',['subscription_id'=>1])),'Unknown object type denies details.');$GLOBALS['objects'][1]->type='shop_subscription';
 $GLOBALS['denied_ids']=[9000];$assert(is_wp_error($run('subscriptions.get_order_context',['order_id'=>9000,'limit'=>20,'offset'=>0])),'Native parent-order denial fails closed.');$GLOBALS['denied_ids']=[9001];
 $assert(is_wp_error($run('subscriptions.get_order_context',['order_id'=>9001,'limit'=>20,'offset'=>0])),'Native renewal-order denial fails closed.');$GLOBALS['denied_ids']=[];
 $objectAllowed=new \ReflectionMethod(YoRo_Subscriptions_Read::class,'allowed');
 $GLOBALS['denied_ids']=[9010];$assert($objectAllowed->invoke(null,$refund),'Refund read authorization follows its permitted parent, not the refund ID.');
 $GLOBALS['denied_ids']=[9001];$assert(!$objectAllowed->invoke(null,$refund),'Denied native refund parent cannot be bypassed by a permitted refund ID.');$GLOBALS['denied_ids']=[];
 $assert(!$objectAllowed->invoke(null,new \stdClass),'Non-order owner object fails closed.');
 $assert(is_wp_error($run('subscriptions.get_order_context',['order_id'=>9010,'limit'=>20,'offset'=>0])),'Refund type cannot be used as order context.');
 $GLOBALS['objects'][9001]->id=9002;$assert(is_wp_error($run('subscriptions.get_order_context',['order_id'=>9001,'limit'=>20,'offset'=>0])),'Mismatched owner order ID denies context.');$GLOBALS['objects'][9001]->id=9001;
 $parentContext=$run('subscriptions.get_order_context',['order_id'=>9000,'limit'=>20,'offset'=>0]);$assert(!is_wp_error($parentContext)&&$parentContext['data']['subscriptions'][0]['relation_kinds']===['parent'],'Parent context works with the registered native capability.');
 $GLOBALS['capabilities']['read_post']=false;$assert(!is_wp_error($run('subscriptions.get_details',['subscription_id'=>1])),'Subscription permission does not fall back to read_post.');
 $assert(is_wp_error($run('subscriptions.search',['limit'=>20,'offset'=>0,'product_id'=>10])),'Product filter retains its separate native product permission.');$GLOBALS['capabilities']['read_post']=true;
 $GLOBALS['options']=[];$GLOBALS['capability_checks']=[];
 $all=[];$params=['limit'=>17,'offset'=>0];for($i=0;$i<50;$i++){$r=$run('subscriptions.search',$params);$assert(!is_wp_error($r),'Search works with owner queries.');$all=[...$all,...array_column($r['data']['subscriptions'],'id')];$p=$r['data']['page_info'];if(!$p['has_more']){break;}$params=['limit'=>17,'offset'=>$p['next_offset'],'anchor_id'=>$p['anchor_id'],'revision'=>$p['revision']];}$assert(count($all)===310&&count(array_unique($all))===310,'All310subscriptions traverse finite batches without duplicates.');
 $r=$run('subscriptions.get_details',['subscription_id'=>1]);$assert(!is_wp_error($r)&&$r['data']['subscription']['agreement_total']==='1.000001'&&$r['data']['subscription']['next_payment_at']===null,'Exact decimal agreement and missing date preserved.');
 $context=$run('subscriptions.get_order_context',['order_id'=>9001,'limit'=>20,'offset'=>0]);$assert($context['data']['subscriptions'][0]['relation_kinds']===['renewal','switch'],'Owner reverse relation kinds.');
 $related=$run('subscriptions.get_related_orders',['subscription_id'=>1,'limit'=>20,'offset'=>0]);$assert(array_column($related['data']['orders'],'id')===[9000,9001,9002],'Parent and reverse relations bounded.');
 $bad=['limit'=>20,'offset'=>1];$assert(is_wp_error($run('subscriptions.search',$bad)),'Cannot resume without frozen anchor/revision.');$bad=['limit'=>20,'offset'=>0,'anchor_id'=>310,'revision'=>str_repeat('a',64)];$assert(is_wp_error($run('subscriptions.search',$bad)),'Changed mutation revision fails closed.');
 $GLOBALS['objects'][1]->next='2026-11-01 17:00:00';$search=$run('subscriptions.search',['limit'=>20,'offset'=>0,'status'=>'active','customer_id'=>8,'product_id'=>10,'next_payment_from'=>'2026-11-02','next_payment_to'=>'2026-11-02']);$assert(array_column($search['data']['subscriptions'],'id')===[1],'AND filters use store timezone for UTC next payment.');$GLOBALS['objects'][1]->next=null;
 $GLOBALS['objects'][1]->item_count=201;$assert(is_wp_error($run('subscriptions.get_details',['subscription_id'=>1])),'Oversized item record rejects instead of pretending complete.');$GLOBALS['objects'][1]->item_count=1;
 $GLOBALS['objects'][9001]->relations['renewal']=array_fill(0,201,1);$assert(is_wp_error($run('subscriptions.get_order_context',['order_id'=>9001,'limit'=>20,'offset'=>0])),'Per-order relation cardinality bounded.');$GLOBALS['objects'][9001]->relations['renewal']=[1,2];
 $GLOBALS['denied_ids']=[1];$assert(is_wp_error($run('subscriptions.get_details',['subscription_id'=>1])),'Object permission enforced.');$GLOBALS['denied_ids']=[];
 $GLOBALS['allowed']=false;$assert(is_wp_error($run('subscriptions.get_status')),'Store and YoRo permission enforced.');$GLOBALS['allowed']=true;
 $slow=fn()=>0.001;add_filter('yoro_complete_report_seconds',$slow);$summary=null;$deferred=0;for($i=0;$i<80;$i++){$r=$run('subscriptions.get_summary',['window'=>'today']);$assert(!is_wp_error($r),'Resumable report execution.');if(isset($r['deferred'])){$deferred++;continue;}$summary=$r;break;}$assert($summary!==null&&$summary['data']['subscription_count']===310&&$summary['data']['created_in_period']===310,'Complete current snapshot and created events are distinct explicit metrics.');$currencies=array_column($summary['data']['currencies'],null,'currency');$assert($currencies['USD']['agreement_totals']==='155.000155'&&$currencies['EUR']['agreement_totals']==='155.000155','Exact decimals never mix currencies or normalize recurring periods.');
 $report=null;for($i=0;$i<80;$i++){$r=$run('subscriptions.get_renewal_report',['window'=>'today']);$assert(!is_wp_error($r),'Renewal report executes.');if(!isset($r['deferred'])){$report=$r;break;}}$assert($report!==null&&$report['data']['created_orders']===2&&$report['data']['paid_orders']===1&&$report['data']['failed_created_orders']===1&&$report['data']['refund_records']===1,'Renewal denominator is distinct orders, excludes attempts, independent refund records.');$currencies=array_column($report['data']['currencies'],null,'currency');$assert($currencies['USD']['recorded_paid_order_totals']==='1.000001'&&$currencies['USD']['recorded_refunds']==='0.000001','Paid/refund recorded exact amounts.');
 $assert(in_array('historical_churn',$report['data']['not_assessed'],true)&&!str_contains(json_encode([$summary,$report]),'PRIVATE'),'Unsupported historical metrics and private-free output.');
 $assert(in_array(['read_shop_order',[9001]],$GLOBALS['capability_checks'],true)&&!in_array(['read_shop_order',[9010]],$GLOBALS['capability_checks'],true),'Refund authorization uses its parent order ID, never the refund ID.');
 $assert(!in_array('read_shop_subscription',array_column($GLOBALS['capability_checks'],0),true),'Successful reads and reports never request an unregistered subscription capability.');
 $assert(is_wp_error($run('subscriptions.get_summary',['window'=>'today'],fn()=>false)),'Cached result never bypasses guard.');remove_filter('yoro_complete_report_seconds',$slow);
 $GLOBALS['options']=[];$GLOBALS['mutate_on_query']=true;$assert(is_wp_error($run('subscriptions.get_summary',['window'=>'today'])),'Mutation during report cannot return complete stale totals.');
foreach($GLOBALS['queries'] as $query){$assert($query['limit']>=1&&$query['limit']<=100&&!isset($query['meta_query']),'Only bounded public owner queries.');}
$assert(!str_contains(file_get_contents(__DIR__.'/../../includes/commerce/class-yoro-subscriptions-read.php'),'->get_meta('),'Adapter never reconstructs owner metadata.');
require_once __DIR__.'/../../includes/actions/class-yoro-product-edit.php';
require_once __DIR__.'/../../includes/actions/class-yoro-variable-product.php';
require_once __DIR__.'/../../includes/actions/class-yoro-product-lifecycle.php';
require_once __DIR__.'/../../includes/commerce/class-yoro-early-access-product-read.php';
foreach ([10,11,12] as $id) {
 $product=$GLOBALS['products'][$id];
 $assert(YoRo_Early_Access_Product_Read::product($product,true)===null,'Subscription products stay outside legacy unnegotiated reads.');
 $row=YoRo_Early_Access_Product_Read::product($product,true,false,true);
 $assert($row['extensions']['woocommerce.subscription_product']['sign_up_fee']===($id===11?null:'2.000001')&&!isset($row['extensions']['woocommerce.product']['edit_fields'])&&!isset($row['extensions']['woocommerce.product_lifecycle']),'Subscription read exposes bounded terms without write context.');
 $assert(is_wp_error(YoRo_Product_Edit::prepare(['product_id'=>$id,'changes'=>['name'=>'Changed'],'expected_version'=>str_repeat('a',64)])),'Subscription product edit preparation rejects type.');
 foreach(['products.duplicate'=>['name'=>'Copy'],'products.update_lifecycle'=>['featured'=>true]] as $action=>$changes){$assert(is_wp_error(YoRo_Product_Lifecycle::prepare(['product_id'=>$id,'changes'=>$changes,'expected_version'=>str_repeat('a',64)],$action)),'Lifecycle and duplication cannot adopt subscription read support.');}
}
$fixtureContext=YoRo_Variable_Product::context($GLOBALS['products'][11],true);
$variable=YoRo_Variable_Product::read(['action'=>'products.get_variable_details','params'=>['product_id'=>11,'page'=>1,'limit'=>20],'extensions'=>['yoro.mcp_early_access_product_read'=>['subscription_product_version'=>1]]]);
$assert(!is_wp_error($variable)&&$variable['data']['variable']['editable']===false&&$variable['data']['products'][0]['type']==='variable-subscription','Variable subscription read has distinct noneditable context.');
$assert(is_wp_error(YoRo_Variable_Product::read(['action'=>'products.get_variable_details','params'=>['product_id'=>11,'page'=>1,'limit'=>20]])),'Variable subscription requires negotiation.');
echo 'Subscriptions '.$ownerVersion.' '.($hpos?'HPOS':'CPT').": native permission positives/negatives, reads, reports and products passed.\n";
}
$versionResults[$ownerVersion]=['status'=>$run('subscriptions.get_status'),'search'=>$run('subscriptions.search',['limit'=>20,'offset'=>0]),'context'=>$context,'related'=>$related,'summary'=>$summary,'renewal'=>$report,'variable'=>$variable,'details'=>$run('subscriptions.get_details',['subscription_id'=>1])];

}
require __DIR__.'/subscriptions-owner-admission-test.php';
if ($export=getenv('YORO_SUBSCRIPTIONS_RESULT_EXPORT')) { file_put_contents($export,json_encode(['versions'=>$versionResults,'summary'=>$summary,'renewal'=>$report,'variable'=>$variable,'details'=>$run('subscriptions.get_details',['subscription_id'=>1])],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }
echo 'Subscriptions owner-signature fixtures, CPT/HPOS query routes,310records, timezone, currency, reports, cardinality, mutation, permissions and no-domain-write guards: '.$GLOBALS['assertions']." assertions passed.\n";
}
