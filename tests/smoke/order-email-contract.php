<?php
/** Disposable WordPress/WooCommerce, captured transport only. Never real delivery. */
if (!defined('ABSPATH') || !class_exists('WooCommerce')) { return; }
if (str_ends_with((string)wp_parse_url(home_url(), PHP_URL_HOST), 'yorohq.com')) { throw new RuntimeException('Disposable fixture required.'); }
$assert=static function($ok,$why){if(!$ok){throw new RuntimeException($why);}};
require_once ABSPATH.WPINC.'/PHPMailer/PHPMailer.php';
require_once ABSPATH.WPINC.'/PHPMailer/Exception.php';
require_once ABSPATH.WPINC.'/class-wp-phpmailer.php';
if(!class_exists('YoRo_Captured_Order_Mailer')) {
    class YoRo_Captured_Order_Mailer extends WP_PHPMailer {
        public array $captured=[];
        public function send(){ $this->captured[]=['to'=>$this->getToAddresses(),'subject'=>$this->Subject,'body'=>$this->Body,'cc'=>$this->getCcAddresses(),'bcc'=>$this->getBccAddresses(),'attachments'=>$this->getAttachments()];return true; }
    }
}
global $wpdb,$phpmailer;$oldMailer=$phpmailer;$oldUser=get_current_user_id();$phpmailer=new YoRo_Captured_Order_Mailer(true);$capture=$phpmailer;
$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
    YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    YoRo_Order_Email::register();
    $order=wc_create_order();$order->set_billing_email('buyer@example.invalid');$order->set_status('processing');$order->save();$id=$order->get_id();$capture->captured=[];
    $params=static function($action,$changes)use($id){$selectors=array_intersect_key($changes,array_flip(['draft_id','template','note']));if($action==='orders.add_customer_note'){$selectors['template']='customer_note';}$s=YoRo_Order_Email::context($id,$selectors);return ['order_id'=>$id,'changes'=>$changes,'expected_version'=>$s['version'],'recipient'=>$s['recipient'],...(in_array($action,['orders.send_native_email','orders.add_customer_note'],true)?['subject'=>$s['subject'],'html_body'=>$s['html_body']]:[])];};
    $execute=static function($action,$params,$fence=null){$operation=['action'=>$action,'params'=>$params,'user_content_slots'=>YoRo_Order_Email::slots($params),'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1','authorization'=>['execution_permit'=>'disposable'],'extensions'=>['yoro.mcp_early_access_commerce_write'=>['source_operation_id'=>'envelope_'.str_repeat('a',64),'source_result_hash'=>str_repeat('b',64),'expected_version'=>$params['expected_version']]]];return YoRo_Early_Access_Commerce_Write::execute($operation,$fence);};
    $draft=$params('orders.save_authored_email_draft',['subject'=>'Exact subject','body'=>"Exact body\nSecond line"]);
    $assert(is_wp_error(YoRo_Order_Email::write($draft,'orders.save_authored_email_draft')),'Unconfirmed callbacks cannot save.');
    $assert(is_wp_error($execute('orders.save_authored_email_draft',$draft,static fn()=>false)),'Final fence rejects writes.');
    $result=$execute('orders.save_authored_email_draft',$draft);$assert(!is_wp_error($result),'Draft saves through actual executor: '.wp_json_encode($result));
    $draftId=$result['data']['record_id'];$reopened=YoRo_Order_Email::context($id,['draft_id'=>$draftId]);$assert($reopened['draft']['body']==="Exact body\nSecond line"&&count($capture->captured)===0,'Reopen exact persisted content without mail.');
    $updated=$params('orders.update_authored_email_draft',['draft_id'=>$draftId,'subject'=>'Edited subject','body'=>'Edited body']);$assert(!is_wp_error($execute('orders.update_authored_email_draft',$updated)),'Draft update persists.');
    $send=$params('orders.send_authored_email',['subject'=>'Edited subject','body'=>'Edited body']);$assert(!is_wp_error($execute('orders.send_authored_email',$send)),'Exact authored send accepted.');
    $assert(count($capture->captured)===1&&$capture->captured[0]['to'][0][0]==='buyer@example.invalid'&&$capture->captured[0]['subject']==='Edited subject'&&$capture->captured[0]['body']==='<div style="white-space:pre-wrap">Edited body</div>','Actual final transport matches the preview exactly.');
    $assert(is_wp_error($execute('orders.send_authored_email',$send))&&count($capture->captured)===1,'Old version cannot resend.');
    $native=$params('orders.send_native_email',['template'=>'processing']);$assert(!is_wp_error($execute('orders.send_native_email',$native)),'Native processing email accepted: '.wp_json_encode($native));
    $assert(count($capture->captured)===2&&$capture->captured[1]['body']===$native['html_body']&&$capture->captured[1]['subject']===$native['subject'],'Native final content equals rendered reviewed content.');
    $note=$params('orders.add_customer_note',['note'=>'Customer-visible exact note']);$result=$execute('orders.add_customer_note',$note);$assert(!is_wp_error($result)&&$result['data']['outcome']==='note_created','Native customer-visible note succeeds: '.wp_json_encode($result));
    $assert(count($capture->captured)===3&&$capture->captured[2]['body']===$note['html_body'],'One note creates exactly one captured native notification.');
    $cc=static fn($headers)=>$headers."\r\nCc: other@example.invalid";add_filter('woocommerce_email_headers',$cc);try{$assert(!YoRo_Order_Email::context($id)['native_eligible'],'Configured CC makes exact one-recipient native path unavailable.');}finally{remove_filter('woocommerce_email_headers',$cc);}
    $tampered=$params('orders.send_native_email',['template'=>'processing']);
    $alter=static fn($atts)=>[...$atts,'subject'=>'Altered after review'];add_filter('wp_mail',$alter,10);
    try{$unknown=$execute('orders.send_native_email',$tampered);$assert(is_wp_error($unknown)&&count($capture->captured)===3,'Final content mutation is denied without another dispatch.');}finally{remove_filter('wp_mail',$alter,10);}
    $assert(is_wp_error($execute('orders.send_native_email',$tampered))&&count($capture->captured)===3,'Unknown attempt cannot repeat with its old version.');
    $assert(false===has_filter('wp_mail_content_type')&&false===has_filter('wp_mail_from')&&false===has_filter('wp_mail_from_name'),'Interrupted native send leaves no mail filter behind.');
    $history=YoRo_Order_Email::context($id)['history'];$assert($history[0]['outcome']==='unknown'&&count($history)===4&&$history[1]['outcome']==='mailer_accepted','Only observed sends are recorded; inbox delivery is not claimed.');
    $payment=wc_create_order();$payment->set_billing_email('payment@example.invalid');$payment->set_total('25');$payment->set_payment_method('bacs');$payment->save();
    $invoice=YoRo_Order_Email::context($payment->get_id());$assert($invoice['native_eligible']&&str_contains(html_entity_decode($invoice['html_body']),$payment->get_checkout_payment_url()),'Native unpaid order details include the actual order payment link.');
    update_option('woocommerce_bacs_settings',['enabled'=>'yes','title'=>'Bank transfer','instructions'=>'Disposable bank transfer instructions']);
    WC()->payment_gateways()->payment_gateways()['bacs']->instructions='Disposable bank transfer instructions';
    add_filter('woocommerce_email_enabled_customer_on_hold_order','__return_false');try{$payment->set_status('on-hold');$payment->save();}finally{remove_filter('woocommerce_email_enabled_customer_on_hold_order','__return_false');}
    $invoice=YoRo_Order_Email::context($payment->get_id());$assert(str_contains($invoice['html_body'],'Disposable bank transfer instructions'),'Order details use actual native gateway instructions, without returning raw gateway settings.');
    $assert(wc_get_order($id)->get_status('edit')==='processing','Email and note sends never flip the order status.');
    echo "Native order email smoke passed; 3 captured deliveries, 0 real deliveries.\n";
} finally {$wpdb->query('ROLLBACK');wp_cache_flush();$phpmailer=$oldMailer;wp_set_current_user($oldUser);}
