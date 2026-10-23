<?php
/** Actual isolated Woo/WordPress sources. Never connects to production or sends mail. */
if (!defined('ABSPATH')||!class_exists('WooCommerce')) {return;}
$host=(string)wp_parse_url(home_url(),PHP_URL_HOST);
if(PHP_SAPI!=='cli'||(!str_ends_with($host,'.invalid')&&!in_array($host,['yoro-plugin-smoke.test','yoro-woocommerce-exact-pair.test'],true))){throw new RuntimeException('Disposable local .invalid fixture required.');}
$checks=0;$assert=static function($ok,$reason)use(&$checks){++$checks;if(!$ok){throw new RuntimeException($reason);}};
global $wpdb;$old=get_current_user_id();$network=0;$mail=0;
$block=static function()use(&$network){++$network;return new WP_Error('fixture_no_network','No external traffic.');};
$blockMail=static function($args)use(&$mail){++$mail;throw new RuntimeException('Unexpected mail in read-only fixture.');};add_filter('pre_http_request',$block);$fixtureMail=static fn()=>true;add_filter('pre_wp_mail',$fixtureMail);
$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
    YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    $email='service_'.wp_generate_uuid4().'@example.invalid';$orders=[];
    for($n=0;$n<7;++$n){$order=wc_create_order();$order->set_billing_email($email);$order->set_status('processing');$order->set_total('12.50');$order->save();$orders[]=$order;}
    $current=end($orders);$registered=wc_create_order();$registered->set_customer_id((int)get_current_user_id());$registered->set_billing_email($email);$registered->set_total('99');$registered->save();
    $ids=[];foreach(['publish','publish','draft']as$status){$ids[]=(int)wp_insert_post(['post_type'=>'page','post_status'=>$status,'post_title'=>'Policy <unsafe>','post_content'=>str_repeat('Policy wording. ',200),'post_password'=>count($ids)===1?'protected':'']);}
    for($n=0;$n<6;++$n){$refund=wc_create_refund(['order_id'=>$current->get_id(),'amount'=>'0.10','reason'=>'PRIVATE REFUND REASON','refund_payment'=>false,'restock_items'=>false]);$assert(!is_wp_error($refund),'Recorded refund fixture exists without financial transport.');}
    $current=wc_get_order($current->get_id());$args=['context_profile'=>'customer_service_v1','history_access'=>'authorized','policy_access'=>'authorized','policy_content_ids'=>$ids];
    remove_filter('pre_wp_mail',$fixtureMail);add_filter('wp_mail',$blockMail);
    $before=$wpdb->num_queries;$started=microtime(true);$base=YoRo_Order_Email::context($current->get_id());$basicSql=$wpdb->num_queries-$before;$basicSeconds=microtime(true)-$started;
    $before=$wpdb->num_queries;$started=microtime(true);$rich=YoRo_Order_Email::context($current->get_id(),$args);$sql=$wpdb->num_queries-$before;$seconds=microtime(true)-$started;$service=$rich['service_context'];
    $assert(count($service['related_orders'])===5&&!$service['history_coverage']['complete'],'Related history is bounded at five with keyset coverage.');
    $assert(!in_array($registered->get_id(),array_column($service['related_orders'],'order_id'),true),'Shared guest email does not include registered customer orders.');
    $assert($service['history_coverage']['match_basis']==='email'&&!$service['history_coverage']['identity_verified'],'Guest contact is not verified identity.');
    $assert(count($service['refunds'])===5&&$service['refund_coverage']['total']===6&&$service['refund_coverage']['truncated']&&!$service['refund_coverage']['settlement_verified'],'Recorded refunds are bounded and do not prove settlement.');
    $assert(count($service['policies'])===1&&$service['policies'][0]['content_id']===$ids[0]&&mb_strlen($service['policies'][0]['excerpt'])<=2000,'Policy eligibility uses published unprotected same-site content only.');
    $assert(!$service['policy_coverage']['complete']&&$service['policy_coverage']['availability']==='unavailable','Skipped policy records are explicit incomplete coverage.');
    $assert(!str_contains(wp_json_encode($service),'PRIVATE REFUND REASON')&&!str_contains(wp_json_encode($service),$email),'Enrichment excludes private reasons and customer contacts.');
    $digest=YoRo_Early_Access_Product_Read::params_hash(array_diff_key($service,['context_digest'=>true]));$assert($service['context_digest']===$digest,'Full enrichment and observed time bind exact context digest.');
    $base=YoRo_Order_Email::context($current->get_id());$assert($base['version']===$rich['version']&&!isset($base['service_context']),'Legacy send freshness and absent-profile roots are preserved.');
    $none=YoRo_Order_Email::context($current->get_id(),['context_profile'=>'customer_service_v1','history_access'=>'unavailable','policy_access'=>'unavailable_authority','policy_content_ids'=>[]]);
    $assert($none['service_context']['related_orders']===[]&&$none['service_context']['policies']===[],'Optional authority absence has no bypass.');
    $assert($network===0&&$mail===0,'Read enrichment has zero external requests and mail attempts.');
    if($path=getenv('YR0180_SERVICE_METRICS_FILE')){file_put_contents($path,wp_json_encode(['samples'=>1,'basic_sql_queries'=>$basicSql,'basic_seconds'=>$basicSeconds,'rich_sql_queries'=>$sql,'rich_seconds'=>$seconds,'history_limit'=>5,'policy_lookups'=>3,'refunds_returned'=>5,'mail_attempts'=>$mail,'network_attempts'=>$network,'data'=>$rich],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
    echo wp_json_encode(['result'=>'passed','service_assertions'=>$checks,'rich_sql_queries'=>$sql,'rich_seconds'=>$seconds,'network_attempts'=>$network,'mail_attempts'=>$mail]),"\n";
} finally {$wpdb->query('ROLLBACK');wp_set_current_user($old);remove_filter('pre_http_request',$block);remove_filter('wp_mail',$blockMail);remove_filter('pre_wp_mail',$fixtureMail);}
