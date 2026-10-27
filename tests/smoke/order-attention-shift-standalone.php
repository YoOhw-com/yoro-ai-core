<?php
/** Deterministic isolated provider fixture. No WordPress database, store or mail transport. */
if (! defined('ABSPATH')) {
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
class WP_Error { public function __construct(public $code, public $message) {} }
class WC_Order {
    public function __construct(public int $id, public string $status, public int $age, public bool $shipping = true) {}
    public function get_id(){return $this->id;}
    public function get_type(){return 'shop_order';}
    public function get_status(){return $this->status;}
    public function get_date_created(){return new ShiftDate(time()-$this->age*3600);}
    public function get_total(){return '12.30';}
    public function get_currency(){return 'USD';}
}
class ShiftDate { public function __construct(public int $timestamp){} public function getTimestamp(){return $this->timestamp;} public function date($format){return gmdate($format,$this->timestamp);} }
class YoRo_Early_Access_Order_Read { const STATUSES=['pending','processing','on-hold','completed','cancelled','refunded','failed']; }
class YoRo_Orders_Read_Service { public static function fulfillment_order_requires_shipping($order){return $order->shipping;} }
function wp_json_encode($value){return json_encode($value);}
function wp_salt($scheme){return 'disposable-fixture-key-not-a-live-secret';}
function wp_generate_uuid4(){return bin2hex(random_bytes(16));}
function get_current_blog_id(){return 1;}
function get_current_user_id(){return $GLOBALS['fixture_actor'];}
function current_user_can($capability){return $GLOBALS['fixture_actor']>0;}
function wc_shipping_enabled(){return true;}
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['fixture_filters'][$name][]=$callback;}
function remove_filter($name,$callback,$priority=10){$GLOBALS['fixture_filters'][$name]=array_values(array_filter($GLOBALS['fixture_filters'][$name]??[],fn($value)=>$value!==$callback));}
function fixture_filter($name,$value,...$args){foreach($GLOBALS['fixture_filters'][$name]??[]as$callback){$value=$callback($value,...$args);}return $value;}
class ShiftWpdb { public $posts='wp_posts'; public $last_error=''; public function prepare($sql,...$args){foreach($args as$arg){$sql=preg_replace('/%d/',(string)(int)$arg,$sql,1);}return $sql;} }
class ShiftQuery { public function __construct(public array $args){} public function get($key){return $this->args[$key]??null;} public function get_table_name($name){return 'wp_wc_orders';} }
function wc_get_orders($args){
    $rows=array_values(array_filter($GLOBALS['fixture_orders'],fn($order)=>in_array($order->status,$args['status'],true)));
    usort($rows,fn($left,$right)=>$args['order']==='DESC'?$right->id<=>$left->id:$left->id<=>$right->id);
    if(isset($args['yoro_attention_cursor'])){
        $query=new ShiftQuery($args);
        if($GLOBALS['fixture_storage']==='hpos'){$clauses=fixture_filter('woocommerce_orders_table_query_clauses',['where'=>'1=1'],$query,$args);$where=$clauses['where'];}
        else{$cpt=fixture_filter('woocommerce_order_data_store_cpt_get_orders_query',[],$args);$where=fixture_filter('posts_where','1=1',new ShiftQuery($cpt));}
        if($GLOBALS['fixture_apply']&&preg_match('/(?:ID|id)>(\d+) AND (?:wp_posts\.ID|wp_wc_orders\.id)<=(\d+)/',$where,$match)){$rows=array_values(array_filter($rows,fn($order)=>$order->id>(int)$match[1]&&$order->id<=(int)$match[2]));}
        elseif(!$GLOBALS['fixture_apply']){$GLOBALS['wpdb']->last_error='fixture unavailable';}
        else{throw new RuntimeException('Fixture predicate was not applied: '.$where);}
    }
    $rows=array_slice($rows,0,$args['limit']);return ($args['return']??null)==='ids'?array_map(fn($order)=>$order->id,$rows):$rows;
}
require __DIR__.'/../../includes/commerce/class-yoro-early-access-order-queue-read.php';
$assert=static function($ok,$message){if(!$ok){throw new RuntimeException($message);}};
$GLOBALS['wpdb']=new ShiftWpdb();$GLOBALS['fixture_filters']=[];$GLOBALS['fixture_actor']=42;$GLOBALS['fixture_apply']=true;
$operation=['action'=>'operations.get_attention_queue','params'=>['limit'=>20],
    'extensions'=>['yoro.mcp_early_access_product_read'=>['site_id'=>3,'installation_uuid'=>'fixture-installation','wordpress_identity_id'=>9,'profile'=>'early_access','oauth_client_id'=>'fixture-client','user_id'=>8,'order_attention_shift'=>['version'=>1,'cursor'=>'']]]];
$read=static function($cursor='')use($operation){$op=$operation;$op['extensions']['yoro.mcp_early_access_product_read']['order_attention_shift']['cursor']=$cursor;return YoRo_Early_Access_Order_Queue_Read::execute($op);};
foreach(['cpt','hpos']as$storage){
    $GLOBALS['fixture_storage']=$storage;$GLOBALS['fixture_orders']=[];
    for($i=1;$i<=100;$i++){$GLOBALS['fixture_orders'][]=new WC_Order($i,'pending',1);}
    for($i=101;$i<=145;$i++){$GLOBALS['fixture_orders'][]=new WC_Order($i,'processing',96);}
    $first=$read();$assert(is_array($first)&&$first['data']['count']===0&&$first['extensions']['yoro.order_queue']['scanned']===100&&$first['data']['page_info']['has_next_page'],'Empty first hundred must expose continuation.');
    $GLOBALS['fixture_orders'][]=new WC_Order(146,'processing',96);
    $GLOBALS['fixture_orders'][0]->age=96;
    $cursor=$first['data']['page_info']['next_cursor'];$ids=[];$pages=[];
    do{$page=$read($cursor);$assert(is_array($page),'Continuation must execute.');$pages[]=$page;$ids=array_merge($ids,array_column($page['data']['items'],'order_id'));$cursor=$page['data']['page_info']['next_cursor'];}while($cursor!==null);
    $assert($ids===range(101,145),'Every displayed match beyond 20 and 100 is retained, excluding newer inserts.');
    $assert(count($pages)===3&&$pages[0]['extensions']['yoro.order_queue']['scanned']===20,'Stop scanning when result limit reached, before losing another match.');
    $assert(str_contains($pages[0]['data']['items'][0]['reason'],'created at least 72 hours ago')&&!str_contains($pages[0]['data']['items'][0]['reason'],'Processing for'),'Creation age must never imply status duration.');
    $refresh=$read();$assert($refresh['data']['items'][0]['order_id']===1,'Refresh reconciles changes to already-scanned orders.');
    $assert($refresh['extensions']['yoro.order_queue']['anchor_id']===146,'Refresh sees newer inserts.');
    $bad=$read(substr($first['data']['page_info']['next_cursor'],0,-1).'x');$assert($bad instanceof WP_Error,'Tampered cursor rejected.');
    $GLOBALS['fixture_actor']=99;$bad=$read($first['data']['page_info']['next_cursor']);$assert($bad instanceof WP_Error,'Foreign actor cursor rejected.');$GLOBALS['fixture_actor']=42;
    $body=json_decode(base64_decode(strtr(explode('.',$first['data']['page_info']['next_cursor'])[0],'-_','+/')),true);$body['expires']=time()-1;$encoded=rtrim(strtr(base64_encode(json_encode($body)),'+/','-_'),'=');
    $expired=$read($encoded.'.'.hash_hmac('sha256',$encoded,wp_salt('auth')));$assert($expired instanceof WP_Error&&str_contains($expired->message,'saved work'),'Expired cursor has honest retained-work refresh path.');
    $foreign=$operation;$foreign['extensions']['yoro.mcp_early_access_product_read']['profile']='public';$foreign['extensions']['yoro.mcp_early_access_product_read']['order_attention_shift']['cursor']=$first['data']['page_info']['next_cursor'];
    $assert(YoRo_Early_Access_Order_Queue_Read::execute($foreign)instanceof WP_Error,'Cross-profile cursor rejected.');
    $GLOBALS['fixture_apply']=false;$assert($read()instanceof WP_Error,'Unverified datastore selection fails closed.');$GLOBALS['fixture_apply']=true;$GLOBALS['wpdb']->last_error='';
    $assert(array_sum(array_map('count',$GLOBALS['fixture_filters']))===0,'Scoped query hooks removed after success and failure.');
    if(isset($argv[1])){file_put_contents($argv[1],json_encode(['manifest'=>YoRo_Early_Access_Order_Queue_Read::shift_manifest(),'empty'=>$first,'page'=>$pages[0],'last'=>$pages[2]],JSON_PRETTY_PRINT));}
}
$GLOBALS['fixture_actor']=0;$assert($read()instanceof WP_Error,'Revoked actor cannot scan.');
echo "Order-attention shift standalone passed: CPT/HPOS predicates, >100 scanned, >20 matches, empty segment, current refresh, insertion, actor/profile/tamper/expiry, fail-closed hooks; no live DB/mail.\n";

} else {
    echo "Order-attention standalone fixture uses a separate PHP process; live WordPress was not changed.\n";
}
