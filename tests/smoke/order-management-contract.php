<?php
/** Disposable WooCommerce only; every saved fixture is rolled back. Never enable fulfillment features here. */
if (!defined('ABSPATH')||!class_exists('WooCommerce')) {throw new RuntimeException('Disposable WooCommerce required.');}
if (str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')) {throw new RuntimeException('Never run order write fixtures on canary or production.');}
if (!class_exists('YoRo_Order_Management')) {require_once dirname(__DIR__,2).'/includes/actions/class-yoro-order-management.php';}
$assert=static function($ok,$message){if(!$ok){throw new RuntimeException($message);}};
$original=get_current_user_id();$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
$notifications=0;$notify=static function()use(&$notifications){++$notifications;return true;};add_filter('pre_wp_mail',$notify);
global $wpdb;$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int)$admins[0]);YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    YoRo_Order_Management::register();
    $order=new WC_Order();$order->set_status('processing');$order->set_currency('USD');$order->set_billing_first_name('Old name');$order->set_billing_email('old@example.test');$order->set_billing_country('US');$order->set_billing_state('CA');$order->set_customer_note('Original buyer note');$order->save();$id=$order->get_id();$notifications=0;
    $params=static fn($changes)=>['order_id'=>$id,'changes'=>$changes,'expected_version'=>YoRo_Order_Presenter::order_version(wc_get_order($id))];
    $operation=static fn($action,$params)=>['action'=>$action,'params'=>$params,'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1','authorization'=>['execution_permit'=>'disposable'], 'extensions'=>['yoro.mcp_early_access_commerce_write'=>['source_operation_id'=>'envelope_'.str_repeat('a',64),'source_result_hash'=>str_repeat('b',64),'expected_version'=>$params['expected_version']]]];
    $execute=static fn($action,$params)=>YoRo_Early_Access_Commerce_Write::execute($operation($action,$params));
    $is_failure=static fn($result)=>is_wp_error($result)||(is_array($result)&&empty($result['success']));
    $patch=$params(['billing'=>['first_name'=>'New name','email'=>'new@example.test','phone'=>'+1 415 555 0123'],'shipping'=>['city'=>'Seattle','country'=>'US','state'=>'WA','postcode'=>'98101']]);
    $preview=YoRo_Order_Management::preview($patch,'orders.update_contacts');
    $assert(!is_wp_error($preview)&&$preview['before']['billing']['first_name']==='Old name'&&wc_get_order($id)->get_billing_first_name()==='Old name','Exact contact preview never saves.');
    $assert(is_wp_error(YoRo_Order_Management::execute_contacts($patch)),'Direct unconfirmed callbacks never save.');
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($operation('orders.update_contacts',$patch),static fn()=>false)),'A lost execution fence rejects the contact correction.');
    $saved=$execute('orders.update_contacts',$patch);
    $assert(!$is_failure($saved)&&$saved['data']['changes']===$patch['changes']&&array_keys($saved['data'])===['order_id','changes','current_version'],'Confirmed contact result contains only the requested patch. '.wp_json_encode($saved));
    $fresh=wc_get_order($id);$assert($fresh->get_billing_first_name()==='New name'&&$fresh->get_shipping_city()==='Seattle'&&$fresh->get_status()==='processing','WooCommerce contacts persist without status changes.');
    $assert($is_failure($execute('orders.update_contacts',$patch)),'A stale source version cannot replay contact changes.');
    $assert(is_wp_error(YoRo_Order_Management::preview($params(['billing'=>['first_name'=>'New name']]),'orders.update_contacts')),'No-op contact patches reject.');
    foreach([['billing'=>[]],['billing'=>['email'=>'not-an-email']],['shipping'=>['email'=>'unsupported@example.test']],['billing'=>['phone'=>'bad phone']],['billing'=>['country'=>'XX']],['shipping'=>['country'=>'US','state'=>'WRONG']],['billing'=>['first_name'=>'<b>unsafe</b>']],['billing'=>['city'=>str_repeat('é',128)]],['billing'=>['first_name'=>' Leading']],['shipping'=>['postcode'=>'invalid!']]] as $bad) {
        $assert(is_wp_error(YoRo_Order_Management::preview($params($bad),'orders.update_contacts')),'Invalid or unsupported contact values reject: '.wp_json_encode($bad));
    }
    $deny=static function($caps,$cap,$user,$args)use($id){return in_array($cap,['edit_shop_order','edit_post'],true)&&($args[0]??null)===$id?['do_not_allow']:$caps;};add_filter('map_meta_cap',$deny,PHP_INT_MAX,4);
    $assert(is_wp_error(YoRo_Order_Management::preview($params(['billing'=>['city'=>'Forbidden']]),'orders.update_contacts')),'Per-order edit permission is mandatory.');remove_filter('map_meta_cap',$deny,PHP_INT_MAX);
    $note=$params(['customer_note'=>"Buyer instructions\nLeave at reception"]);$oldNote=wc_get_order($id)->get_customer_note();
    $preview=YoRo_Order_Management::preview($note,'orders.update_customer_note');$assert(!is_wp_error($preview)&&wc_get_order($id)->get_customer_note()===$oldNote,'Customer note preview is non-mutating.');
    $saved=$execute('orders.update_customer_note',$note);$assert(!$is_failure($saved)&&wc_get_order($id)->get_customer_note()===$note['changes']['customer_note'],'Buyer note updates through normal confirmed executor.');
    $assert(!$is_failure($execute('orders.update_customer_note',$params(['customer_note'=>''])))&&wc_get_order($id)->get_customer_note()==='','Explicit empty note clears it.');
    foreach([['customer_note'=>str_repeat('é',1001)],['customer_note'=>'<script>bad</script>'],['customer_note'=>"bad\0note"],['customer_note'=>'valid','notify_customer'=>true]] as $bad){$assert(is_wp_error(YoRo_Order_Management::preview($params($bad),'orders.update_customer_note')),'Invalid note values fail before save.');}
    $fresh=wc_get_order($id);$fresh->set_customer_note(str_repeat('x',2001));$fresh->save();$details=YoRo_Order_Management::details(wc_get_order($id));
    $assert(strlen($details['customer_note'])===2000&&$details['customer_note_truncated'],'Long saved notes explicitly disclose shortening.');
    $assert(is_wp_error(YoRo_Order_Management::preview($params(['customer_note'=>'Replacement']),'orders.update_customer_note')),'A shortened source note cannot authorize overwrite.');
    $fresh=wc_get_order($id);$fresh->set_customer_note('');$fresh->save();
    $refund_ids=[];
    for($n=0;$n<21;++$n){$refund=new WC_Order_Refund();$refund->set_parent_id($id);$refund->set_currency('USD');$refund->set_amount('1.234567');$refund->set_reason($n===20?str_repeat('r',1001):'Partial refund');$refund->save();$refund_ids[]=$refund->get_id();}
    $newest=new WC_Order_Refund(end($refund_ids));
    for($n=0;$n<21;++$n){$item=new WC_Order_Item_Product();$item->set_name('Refunded product '.$n);$item->set_quantity(-1);$item->set_total('-1.234567');$item->set_taxes(['total'=>['1'=>'-0.123456'],'subtotal'=>['1'=>'-0.123456']]);$newest->add_item($item);}$newest->save();
    $read=YoRo_Order_Management::execute_read(['action'=>'orders.get_refunds','params'=>['order_id'=>$id,'page'=>1],'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1']);
    $assert(!is_wp_error($read)&&$read['data']['count']===20&&$read['data']['page_info']===['page'=>1,'has_next_page'=>true,'next_page'=>2]&&(array)$read['extensions']===[],'Refund history uses bounded canonical paging without legacy internals. '.(is_wp_error($read)?$read->get_error_message():wp_json_encode($read)));
    $row=$read['data']['refunds'][0];$assert($row['amount']==='1.234567'&&$row['reason_truncated']&&strlen($row['reason'])===1000&&$row['items_truncated']&&count($row['items'])===20,'Precise amount and content/item coverage remain explicit.');
    $assert($row['items'][0]['quantity']===-1&&$row['items'][0]['total']==='-1.234567'&&$row['items'][0]['tax']==='-0.12'&&!array_key_exists('created_by',$row),'Refund line values remain signed exact decimals with no author leak: '.wp_json_encode($row['items'][0]));
    $page2=YoRo_Order_Management::get_refunds(['order_id'=>$id,'page'=>2]);$assert(!is_wp_error($page2)&&$page2['data']['count']===1&&$page2['data']['page_info']===['page'=>2,'has_next_page'=>false,'next_page'=>null],'Terminal refund page is explicit.');
    $financial=[];
    foreach(array_slice($refund_ids,1) as $refundId) {
        $large=new WC_Order_Refund($refundId);$large->set_reason(str_repeat('é',500));
        foreach($large->get_items() as $oldItem) {$large->remove_item($oldItem->get_id());}
        for($n=0;$n<20;++$n){$item=new WC_Order_Item_Product();$item->set_name(str_repeat('W',200));$item->set_quantity(-1);$item->set_total('-1.234567');$item->set_taxes(['total'=>['1'=>'-0.12'],'subtotal'=>['1'=>'-0.12']]);$large->add_item($item);}
        $large->save();$financial[$large->get_id()]=['amount'=>$large->get_amount('edit'),'currency'=>$large->get_currency('edit')];
    }
    $bounded=YoRo_Order_Management::get_refunds(['order_id'=>$id,'page'=>1]);
    $assert(!is_wp_error($bounded)&&strlen(wp_json_encode($bounded['data']))<=49152,'Worst-case twenty refunds with twenty 200-byte names and 1000-byte reasons stay within the data budget.');
    $assert($bounded['data']['count']===20&&$bounded['data']['page_info']===['page'=>1,'has_next_page'=>true,'next_page'=>2],'Budget compaction preserves every refund row and exact paging.');
    $reasonsShortened=0;
    foreach($bounded['data']['refunds'] as $refund) {
        $assert(isset($financial[$refund['id']])&&$refund['amount']===$financial[$refund['id']]['amount']&&$refund['currency']===$financial[$refund['id']]['currency'],'Compaction never changes refund IDs, currency or exact amounts.');
        $assert($refund['items_truncated']&&$refund['items']===[],'Optional items are removed explicitly before any reason shortening.');
        if($refund['reason']!==str_repeat('é',500)) {$assert($refund['reason_truncated'],'Every shortened reason is disclosed.');++$reasonsShortened;}
    }
    $assert($reasonsShortened>0,'Escaped Unicode worst case exercises the reason budget fallback.');
    foreach([['order_id'=>$id,'page'=>0],['order_id'=>$id,'page'=>1001],['order_id'=>(string)$id],['order_id'=>$id,'created_by'=>1]] as $bad){$assert(is_wp_error(YoRo_Order_Management::get_refunds($bad)),'Refund selectors reject unknown fields and unbounded paging.');}
    $details=YoRo_Order_Management::details(wc_get_order($id));
    if(!YoRo_Order_Management::tracking_available()) {
        $assert($details['tracking_supported']===false&&$details['tracking']===[]&&!in_array('fulfillment.set_tracking',YoRo_Order_Management::manifest()['actions'],true),'Unsupported native storage is not advertised.');
        $assert(is_wp_error(YoRo_Order_Management::preview($params(['fulfillment_id'=>0,'carrier'=>'UPS','tracking_number'=>'1Z123456']),'fulfillment.set_tracking')),'Tracking writes reject without changing feature or storage.');
        echo "Native tracking unavailable: unsupported advertisement and mutation denial passed; positive native CRUD not exercised.\n";
    } else {
        $assert(in_array('fulfillment.set_tracking',YoRo_Order_Management::manifest()['actions'],true),'Native tracking writes are advertised only with complete supported storage.');
        $product=new WC_Product_Simple();$product->set_name('Physical native fulfillment fixture');$product->set_virtual(false);$product->set_status('publish');$product->set_regular_price('25');$product->save();
        $shipping=new WC_Order();$shipping->set_status('processing');$shipping->add_product($product,2);$shipping->save();$shippingId=$shipping->get_id();$notifications=0;
        $trackingParams=static fn($changes)=>['order_id'=>$shippingId,'changes'=>$changes,'expected_version'=>YoRo_Order_Presenter::order_version(wc_get_order($shippingId))];
        $beforeFingerprint=YoRo_Order_Management::tracking_fingerprint(wc_get_order($shippingId));
        $create=$trackingParams(['fulfillment_id'=>0,'carrier'=>'UPS','tracking_number'=>'1Z123456']);
        $preview=YoRo_Order_Management::preview($create,'fulfillment.set_tracking');
        $assert(!is_wp_error($preview)&&YoRo_Order_Management::tracking_fingerprint(wc_get_order($shippingId))===$beforeFingerprint,'Native tracking preview never creates a fulfillment.');
        $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($operation('fulfillment.set_tracking',$create),static fn()=>false)),'A lost execution fence cannot create native tracking.');
        $saved=$execute('fulfillment.set_tracking',$create);
        $assert(!$is_failure($saved),'Create unfulfilled native tracking through confirmed executor: '.wp_json_encode($saved));
        $fulfillmentId=$saved['data']['changes']['fulfillment_id'];$native=new Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment($fulfillmentId);
        $assert($fulfillmentId>0&&$native->get_status()==='unfulfilled'&&!$native->get_is_fulfilled()&&$native->get_date_fulfilled()===null&&$native->get_tracking_url()===null,'A new stored tracking record is unfulfilled, has no dispatch date and no arbitrary URL.');
        $assert($native->get_entity_type()===WC_Order::class&&$native->get_entity_id()===(string)$shippingId&&count($native->get_items())===1&&$native->get_items()[0]['qty']===2,'Native record owns exact pending physical order items.');
        $staleNative=$execute('fulfillment.set_tracking',$create);$assert(is_wp_error($staleNative)&&$staleNative->get_error_code()==='order_version_conflict','An old order version cannot recreate tracking after native state changes.');
        $afterFingerprint=YoRo_Order_Management::tracking_fingerprint(wc_get_order($shippingId));
        $assert($beforeFingerprint!==$afterFingerprint&&$afterFingerprint['complete']&&count($afterFingerprint['records'])===1,'Native tracking fingerprint detects new records and binds items and target.');
        $nativeDetails=YoRo_Order_Management::details(wc_get_order($shippingId));
        $assert($nativeDetails['tracking_supported']&&!$nativeDetails['tracking_truncated']&&$nativeDetails['tracking']===[['fulfillment_id'=>$fulfillmentId,'carrier'=>'UPS','tracking_number'=>'1Z123456']],'Details expose only bounded native tracking fields.');
        $trackingRead=YoRo_Early_Access_Order_Tracking_Read::execute(['action'=>'fulfillment.get_tracking','params'=>['order_id'=>$shippingId]]);
        $assert(!is_wp_error($trackingRead)&&$trackingRead['data']['shipments'][0]['tracking_number']==='1Z123456','View tracking reads the newly stored native fulfillment without a carrier request.');
        $update=$trackingParams(['fulfillment_id'=>$fulfillmentId,'carrier'=>'UPS','tracking_number'=>'1Z999999']);
        $saved=$execute('fulfillment.set_tracking',$update);$assert(!$is_failure($saved),'Exact owned native tracking updates. '.wp_json_encode($saved));
        $assert(YoRo_Order_Management::tracking_fingerprint(wc_get_order($shippingId))!==$afterFingerprint,'Fingerprint detects changes to existing native tracking.');
        $assert(is_wp_error(YoRo_Order_Management::preview($trackingParams(['fulfillment_id'=>$fulfillmentId,'carrier'=>'UPS','tracking_number'=>'1Z999999']),'fulfillment.set_tracking')),'No-op native tracking rejects.');
        $assert(is_wp_error(YoRo_Order_Management::preview($trackingParams(['fulfillment_id'=>0,'carrier'=>'UPS','tracking_number'=>'1ZNEW123']),'fulfillment.set_tracking')),'Already assigned pending items cannot be duplicated in another fulfillment.');
        foreach([['fulfillment_id'=>$fulfillmentId,'carrier'=>'https://example.test','tracking_number'=>'123456'],['fulfillment_id'=>$fulfillmentId,'carrier'=>'UPS','tracking_number'=>'https://example.test'],['fulfillment_id'=>$fulfillmentId,'carrier'=>'UPS','tracking_number'=>'123456','tracking_url'=>'https://example.test'],['fulfillment_id'=>PHP_INT_MAX,'carrier'=>'UPS','tracking_number'=>'123456']] as $bad) {$assert(is_wp_error(YoRo_Order_Management::preview($trackingParams($bad),'fulfillment.set_tracking')),'URLs, unknown fields and foreign/missing records reject.');}
        $native=new Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment($fulfillmentId);$native->set_locked(true,'Fixture lock');$native->save();
        $assert(is_wp_error(YoRo_Order_Management::preview($trackingParams(['fulfillment_id'=>$fulfillmentId]),'fulfillment.remove_tracking')),'Locked native records cannot be changed.');
        $native->set_locked(false);$native->save();$items=$native->get_items();
        $saved=$execute('fulfillment.remove_tracking',$trackingParams(['fulfillment_id'=>$fulfillmentId]));$assert(!$is_failure($saved),'Removing tracking preserves the native record. '.wp_json_encode($saved));
        $native=new Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment($fulfillmentId);
        $assert($native->get_id()===$fulfillmentId&&$native->get_tracking_number()===null&&$native->get_shipment_provider()===null&&$native->get_tracking_url()===null&&$native->get_items()===$items&&$native->get_status()==='unfulfilled','Removal clears only native tracking fields; no fulfillment deletion or dispatch.');
        $assert($notifications===0,'Native tracking create/update/remove requests no customer notification.');
        $native->set_tracking_number(str_repeat('x',101));$native->save();$nativeDetails=YoRo_Order_Management::details(wc_get_order($shippingId));
        $assert($nativeDetails['tracking_truncated']&&strlen($nativeDetails['tracking'][0]['tracking_number'])===100,'Oversized tracking explicitly discloses shortening.');
        $assert(is_wp_error(YoRo_Order_Management::preview($trackingParams(['fulfillment_id'=>$fulfillmentId]),'fulfillment.remove_tracking')),'A shortened tracking source cannot authorize a mutation.');
        $foreign=$params(['fulfillment_id'=>$fulfillmentId,'carrier'=>'UPS','tracking_number'=>'123456']);
        $assert(is_wp_error(YoRo_Order_Management::preview($foreign,'fulfillment.set_tracking')),'A fulfillment belonging to another order is never selected by ID alone.');
        $overflow=new WC_Order();$overflow->set_status('processing');$lineId=$overflow->add_product($product,100);$overflow->save();$notifications=0;
        $firstId=0;
        for($n=0;$n<21;++$n){$record=new Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment();$record->set_entity_type(WC_Order::class);$record->set_entity_id((string)$overflow->get_id());$record->set_status('unfulfilled');$record->set_items([['item_id'=>$lineId,'qty'=>1]]);$record->set_tracking_number('BOUNDED'.$n);$record->save();if(!$firstId){$firstId=$record->get_id();}}
        $bounded=YoRo_Order_Management::details(wc_get_order($overflow->get_id()));
        $assert($bounded['tracking_supported']&&$bounded['tracking_truncated']&&count($bounded['tracking'])===20,'More than twenty native records is disclosed with a bounded projection.');
        $tooMany=['order_id'=>$overflow->get_id(),'changes'=>['fulfillment_id'=>$firstId],'expected_version'=>YoRo_Order_Presenter::order_version(wc_get_order($overflow->get_id()))];
        $assert(is_wp_error(YoRo_Order_Management::preview($tooMany,'fulfillment.remove_tracking')),'Incomplete native record coverage cannot authorize mutations.');
        $record=new Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment($firstId);$record->set_items(array_fill(0,21,['item_id'=>$lineId,'qty'=>1]));$record->save();
        $fingerprint=YoRo_Order_Management::tracking_fingerprint(wc_get_order($overflow->get_id()));
        $assert(!$fingerprint['complete']&&count($fingerprint['records'][0]['items'])===20,'Native item fingerprinting is bounded and explicitly incomplete beyond twenty items.');
        $assert($notifications===0,'Native bounded storage fixtures do not request customer notifications.');
        echo "Native fulfillment create/update/remove, no dispatch/email/URL, pending items, locking, bounds and fingerprint contracts passed.\n";
    }
    $assert($notifications===0,'Order corrections and refund reads do not send customer email.');
    wp_set_current_user(0);$assert(is_wp_error(YoRo_Order_Management::get_refunds(['order_id'=>$id])),'Unauthenticated refund history is denied.');
    echo "Order contacts/customer note/refund history permissions, source versions, exact decimals, bounds and confirmed execution passed.\n";
}finally{$wpdb->query('ROLLBACK');wp_cache_flush();wp_set_current_user($original);remove_filter('pre_wp_mail',$notify);}
