<?php
/** Disposable WooCommerce only. Exact confirmed order statuses with durable child replay. */
if (!defined('ABSPATH')) { exit(1); }
$plugin_root = dirname(__DIR__, 2);
if (!class_exists('YoRo_AI_Core')) { require_once $plugin_root.'/yoro-ai-core.php'; }
YoRo_AI_Core::init();
if (!class_exists('YoRo_Order_Batch')) { require_once $plugin_root.'/includes/actions/class-yoro-order-batch.php'; }
if (!class_exists('WooCommerce')) { throw new RuntimeException('WooCommerce is required for the order batch contract.'); }
if (str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), 'yorohq.com')) { throw new RuntimeException('Never run write fixtures on canary or production.'); }
$assert = static function($ok,$why) { if (!$ok) { throw new RuntimeException($why); } };
$original = get_current_user_id();
$admins = get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
add_filter('pre_wp_mail','__return_true');
$disable_email = static fn()=>false;
foreach (['new_order','cancelled_order','failed_order','customer_on_hold_order','customer_processing_order','customer_completed_order'] as $mail) {
    add_filter('woocommerce_email_enabled_'.$mail,$disable_email);
}
global $wpdb;
$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int)$admins[0]);
    YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    $orders = [];
    for ($index=0;$index<3;$index++) {
        $order = new WC_Order(); $order->set_status('pending'); $order->set_currency('USD'); $order->set_total('12.30'); $order->save();
        $orders[] = $order->get_id();
    }
    $make = static function(array $statuses) use($orders) {
        $items = [];
        foreach ($statuses as $index=>$status) {
            $id = $orders[$index];
            $items[] = ['order_id'=>$id,'expected_version'=>YoRo_Order_Presenter::order_version(wc_get_order($id)),'status'=>$status];
        }
        $params = ['items'=>$items]; $identity = hash('sha256',wp_generate_uuid4());
        return ['envelope_id'=>'envelope_'.$identity,'idempotency_key'=>'order-batch-'.$identity,'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1',
            'action'=>YoRo_Order_Batch::Action,'params'=>$params,'authorization'=>['execution_permit'=>'disposable'],
            'extensions'=>['yoro.mcp_early_access_commerce_write'=>['source_operation_id'=>'envelope_'.str_repeat('a',64),
                'source_result_hash'=>str_repeat('b',64),'expected_version'=>YoRo_Order_Batch::version($params)]]];
    };
    $execute = static fn(array $operation,?Closure $fence=null)=>YoRo_Early_Access_Commerce_Write::execute($operation,$fence);
    $batch = $make(['processing','completed','on-hold']);
    $preview = YoRo_Order_Batch::preview($batch['params']);
    $assert(!is_wp_error($preview) && $preview['non_atomic'] && count($preview['items'])===3 && wc_get_order($orders[0])->get_status()==='pending','Preview shows each target without saving.');
    $result = $execute($batch);
    $assert(!is_wp_error($result) && $result['data']['updated_count']===3 && array_column($result['data']['items'],'status')===['updated','updated','updated'],'Each explicit confirmed status gets its own verified proof: '.wp_json_encode($result));
    $assert(wc_get_order($orders[0])->get_status()==='processing' && wc_get_order($orders[2])->get_status()==='on-hold','Only the requested statuses persist.');
    $replay = $execute($batch);
    $assert(!is_wp_error($replay) && $replay['data']===$result['data'],'Completed child markers replay identical outcomes without another mutation.');
    $child = YoRo_Order_Batch::child_operation($batch,0);
    $assert($child['action']==='orders.update_status' && $child['params']===['order_ids'=>[$orders[0]],'status'=>'processing','expected_versions'=>[['order_id'=>$orders[0],'expected_version'=>$batch['params']['items'][0]['expected_version']]]]
        && $child['extensions']['yoro.mcp_early_access_commerce_write']['params_hash']===YoRo_Early_Access_Product_Read::params_hash($child['params']),'Child lineage binds the exact action, target, status and expected version.');
    $conflict = $make(['on-hold','processing','completed']);
    $changed = wc_get_order($orders[1]); $changed->set_billing_city('Concurrent contact change'); $changed->save();
    $mixed = $execute($conflict);
    $assert(!is_wp_error($mixed) && array_column($mixed['data']['items'],'status')===['updated','failed','updated'] && $mixed['data']['items'][1]['code']==='version_conflict'
        && wc_get_order($orders[1])->get_status()==='completed','A field-version conflict fails one target while other explicit targets continue.');
    $permission = $make(['processing','on-hold']);
    $deny = static function($allcaps,$caps,$args) use($orders) {
        if (in_array($args[0],['edit_shop_order','edit_post'],true) && (int)($args[2]??0)===(int)$orders[0]) {
            foreach ($caps as $cap) { $allcaps[$cap]=false; }
        }
        return $allcaps;
    };
    add_filter('user_has_cap',$deny,PHP_INT_MAX,3);
    try { $denied = $execute($permission); } finally { remove_filter('user_has_cap',$deny,PHP_INT_MAX); }
    $assert(!is_wp_error($denied) && $denied['data']['items'][0]['code']==='not_allowed' && $denied['data']['items'][1]['status']==='updated','Every order retains its edit permission boundary: '.wp_json_encode($denied));
    $crashed = $make(['processing','cancelled','failed']);
    $assert(YoRo_Connector_Execution_Journal::begin(YoRo_Order_Batch::child_operation($crashed,1))['state']==='new','An interrupted child has a durable executing marker.');
    $unknown = $execute($crashed);
    $assert(!is_wp_error($unknown) && array_column($unknown['data']['items'],'status')===['updated','unknown','not_attempted']
        && wc_get_order($orders[1])->get_status()==='on-hold' && wc_get_order($orders[2])->get_status()==='completed','An executing marker never repeats its mutation and stops later targets.');
    $fenced = $make(['on-hold','cancelled','failed']); $calls = 0;
    $stopped = $execute($fenced,static function()use(&$calls){return ++$calls<=2;});
    $assert(!is_wp_error($stopped) && array_column($stopped['data']['items'],'status')===['updated','not_attempted','not_attempted'],'The final fence stops remaining order mutations.');
    $uncertain = $make(['processing','cancelled','failed']);
    $lose = static fn($result,$action,$params)=>$action==='orders.update_status' && ($params['order_ids']??null)===[$orders[1]]?new WP_Error('local_execution_outcome_unknown','Disposable post-save uncertainty'):$result;
    add_filter('yoro_after_action_execute',$lose,10,3);
    try { $saved_unknown = $execute($uncertain); } finally { remove_filter('yoro_after_action_execute',$lose,10); }
    $assert(!is_wp_error($saved_unknown) && array_column($saved_unknown['data']['items'],'status')===['updated','unknown','not_attempted']
        && wc_get_order($orders[1])->get_status()==='cancelled','Post-save uncertainty never fabricates success or runs later targets.');
    $again = $execute($uncertain);
    $assert(!is_wp_error($again) && wp_json_encode($again['data'])===wp_json_encode($saved_unknown['data']),'Unknown completed child outcomes remain unknown on replay.');
    $substituted = $batch; $substituted['extensions']['yoro.mcp_early_access_commerce_write']['source_result_hash']=str_repeat('c',64);
    $substitution = $execute($substituted);
    $assert(!is_wp_error($substitution) && array_column($substitution['data']['items'],'status')===['unknown','not_attempted','not_attempted'],'Changed source provenance cannot reuse existing child proof.');
    $bad = $make(['processing']); $bad['params']['items'][]=$bad['params']['items'][0];
    $assert(!YoRo_Order_Batch::valid_params($bad['params']),'Duplicate IDs fail closed.');
    $bad['params']['items']=array_map(static fn($id)=>['order_id'=>$id,'status'=>'processing','expected_version'=>str_repeat('a',64)],range(1,11));
    $assert(!YoRo_Order_Batch::valid_params($bad['params']),'Eleven distinct targets fail closed.');
    $bad=$make(['processing']); $bad['params']['items'][0]['status']='refunded';
    $assert(!YoRo_Order_Batch::valid_params($bad['params']),'Batch status changes cannot issue refunds.');
    $bad=$make(['processing']); $bad['extensions']['yoro.mcp_early_access_commerce_write']['expected_version']=str_repeat('f',64);
    $assert(is_wp_error($execute($bad)),'The aggregate version binds ordered target versions.');
    $assert(is_wp_error(YoRo_Order_Batch::execute($make(['processing'])['params'])),'Bare params cannot bypass confirmed parent identity.');
    echo "Order batch contract passed: bounded statuses, source versions/permissions, durable replay/unknown, fencing and non-atomic outcomes.\n";
} finally {
    $wpdb->query('ROLLBACK'); wp_cache_flush(); wp_set_current_user($original); remove_filter('pre_wp_mail','__return_true');
    foreach (['new_order','cancelled_order','failed_order','customer_on_hold_order','customer_processing_order','customer_completed_order'] as $mail) { remove_filter('woocommerce_email_enabled_'.$mail,$disable_email); }
}
