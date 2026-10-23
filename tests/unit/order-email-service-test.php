<?php
/** Standalone bounded provider contracts; captured transport only, no database/network/real mail. */
require __DIR__.'/order-email-facts-test.php';

final class YoRo_Customer_History_Report {
    public static array $calls=[];
    public static function execute_read(array $operation): array|WP_Error {
        self::$calls[]=$operation;
        if ($GLOBALS['history_denied']??false) {return new WP_Error('denied','Unavailable');}
        $rows=[];
        for ($i=1;$i<=5;$i++) {$rows[]=['id'=>$i,'status'=>'processing','currency'=>'KWD','total'=>'33.875','created_at'=>'2026-10-21T00:00:00+00:00'];}
        return ['success'=>true,'data'=>['orders'=>$rows,'coverage'=>['match_basis'=>isset($operation['params']['selector']['customer_id'])?'customer_id':'email','complete'=>false]]];
    }
}
class WP_Post {
    public string $post_type='page';public string $post_status='publish';public string $post_password='';
    public string $post_content='<p>Published policy excerpt.</p>';public string $post_title='Returns';
    public function __construct(public int $ID) {}
}
final class YoRo_WordPress_Content_Actions {
    public static function allowed_post_types(): array {return ['post','page'];}
    public static function present_post($post): array {return ['version'=>hash('sha256',$post->post_content),'modified_at'=>'2026-10-21T00:00:00+00:00','url'=>'https://example.test/?p='.$post->ID];}
}
function get_post($id) {$GLOBALS['policy_reads']=($GLOBALS['policy_reads']??0)+1;return $GLOBALS['policy_posts'][$id]??null;}
function wp_check_invalid_utf8($value) {return $value;}
function wp_strip_all_tags($value) {return strip_tags($value);}
function strip_shortcodes($value) {return preg_replace('/\[[^\]]*\]/','',$value);}
function home_url($path='') {return 'https://example.test'.$path;}
function wp_parse_url($url,$part=-1) {return $part===-1?parse_url($url):parse_url($url,$part);}
function get_permalink($id) {return 'https://example.test/?p='.$id;}
require dirname(__DIR__,2).'/includes/commerce/class-yoro-early-access-wordpress-read.php';

$GLOBALS['order']=$order=new FactsOrder;$order->items=[new FactsLine];$GLOBALS['allowed']=true;$GLOBALS['products']=[1=>new WC_Product];
$GLOBALS['policy_posts']=[42=>new WP_Post(42),43=>new WP_Post(43),44=>new WP_Post(44)];
$GLOBALS['policy_posts'][42]->post_content='<h1>'.str_repeat('界',2100).'</h1>';
$GLOBALS['policy_posts'][43]->post_password='private';$GLOBALS['policy_posts'][44]->post_status='draft';
$selectors=['context_profile'=>'customer_service_v1','history_access'=>'authorized','policy_access'=>'authorized','policy_content_ids'=>[42,43,44]];
$checks=0;$assert=static function($ok,$reason)use(&$checks){++$checks;if(!$ok){throw new RuntimeException($reason);}};
$start=hrtime(true);$base=YoRo_Order_Email::context(1);$enriched=YoRo_Order_Email::context(1,$selectors);$service=$enriched['service_context'];
$assert($enriched['version']===$base['version'],'Order source version preserves existing fresh Send revalidation.');
$assert($service['source_version']===$base['version']&&$service['context_digest']===YoRo_Early_Access_Product_Read::params_hash(array_diff_key($service,['context_digest'=>true])),'Separate immutable digest binds every richer source and observation.');
$assert(YoRo_Customer_History_Report::$calls[0]===['action'=>'customers.get_order_history','params'=>['selector'=>['guest_order_id'=>1,'match'=>'email'],'limit'=>5]],'Guest history uses only exact seed reference matching and bounded keyset provider.');
$assert(count($service['related_orders'])===5&&!$service['history_coverage']['complete']&&!$service['history_coverage']['identity_verified']&&$service['history_coverage']['lifetime_total']===null,'History is bounded, incomplete and does not verify identity or lifetime totals.');
$assert(count($service['policies'])===1&&mb_strlen($service['policies'][0]['excerpt'])===2000&&$service['policies'][0]['truncated'],'Only public unprotected policy records have bounded character excerpts.');
$assert($GLOBALS['policy_reads']===3&&$service['policy_coverage']['availability']==='unavailable'&&!$service['policy_coverage']['complete'],'At most three explicit lookups with truthful unavailable coverage.');
$assert(!str_contains(json_encode($service),'private')&&!str_contains(json_encode($service),'example.test'),'No password or raw policy URLs are exposed.');
$before=[count(YoRo_Customer_History_Report::$calls),$GLOBALS['policy_reads']];
$unavailable=YoRo_Order_Email::context(1,['context_profile'=>'customer_service_v1','history_access'=>'unavailable','policy_access'=>'unavailable_authority','policy_content_ids'=>[]])['service_context'];
$assert([count(YoRo_Customer_History_Report::$calls),$GLOBALS['policy_reads']]===$before&&$unavailable['related_orders']===[]&&$unavailable['policies']===[],'Missing optional authority performs no optional reads.');
$assert($unavailable['history_coverage']['availability']==='unavailable'&&$unavailable['policy_coverage']['availability']==='unavailable_authority','Missing authority remains explicit.');
YoRo_Order_Email::context(1);YoRo_Order_Email::context(1,['context_profile'=>'email_facts_v1']);
$assert([count(YoRo_Customer_History_Report::$calls),$GLOBALS['policy_reads']]===$before,'Legacy/basic reads never enrich history or policy.');
$order->customer=99;YoRo_Order_Email::context(1,$selectors);
$assert(end(YoRo_Customer_History_Report::$calls)['params']['selector']===['customer_id'=>99],'Registered customer uses exact account ID, never shared contact.');
$GLOBALS['allowed']=false;$assert(YoRo_Early_Access_WordPress_Read::policy_excerpt(42)===null,'Current local permission revocation denies published policies.');$GLOBALS['allowed']=true;
echo json_encode(['result'=>'passed','service_assertions'=>$checks,'elapsed_ms'=>(hrtime(true)-$start)/1e6,'rich_history_reads'=>2,'max_policy_lookups_per_rich_read'=>3,'basic_optional_reads'=>0,'real_mail'=>0]),"\n";
