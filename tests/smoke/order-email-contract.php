<?php
/** Disposable WordPress/WooCommerce, captured transport only. Never real delivery. */
if (!defined('ABSPATH') || !class_exists('WooCommerce')) { return; }
if (str_ends_with((string)wp_parse_url(home_url(), PHP_URL_HOST), 'yorohq.com')) { throw new RuntimeException('Disposable fixture required.'); }
$checks=0;$assert=static function($ok,$why)use(&$checks){++$checks;if(!$ok){throw new RuntimeException($why);}};
require_once ABSPATH.WPINC.'/PHPMailer/PHPMailer.php';
require_once ABSPATH.WPINC.'/PHPMailer/Exception.php';
require_once ABSPATH.WPINC.'/class-wp-phpmailer.php';
if(!class_exists('YoRo_Captured_Order_Mailer')) {
    class YoRo_Captured_Order_Mailer extends WP_PHPMailer {
        public array $captured=[];
        public function send(){ $this->captured[]=['to'=>$this->getToAddresses(),'subject'=>$this->Subject,'body'=>$this->Body,'cc'=>$this->getCcAddresses(),'bcc'=>$this->getBccAddresses(),'attachments'=>$this->getAttachments()];return true; }
    }
}
// Woo email constructors register persistent multipart callbacks on phpmailer_init;
// snapshot sender and send-guard hooks without treating that native setup as a leak.
$mailHooks=static function():array{
    $snapshot=[];
    foreach(['wp_mail_content_type','wp_mail_from','wp_mail_from_name','wp_mail','woocommerce_mail_callback','woocommerce_email_sent']as$hook){
        $snapshot[$hook]=$GLOBALS['wp_filter'][$hook]->callbacks??[];
    }
    return $snapshot;
};
$mailHookSentinels=[];
global $wpdb,$phpmailer;$oldMailer=$phpmailer;$oldUser=get_current_user_id();$phpmailer=new YoRo_Captured_Order_Mailer(true);$capture=$phpmailer;
$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
    YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    YoRo_Order_Email::register();
    $order=wc_create_order();$order->set_billing_email('buyer@example.invalid');$order->save();$id=$order->get_id();$capture->captured=[];
    // Read email context before any status notification can lazily initialize Woo's mailer.
    $coldContext=YoRo_Order_Email::context($id);
    $assert($coldContext['recipient']==='buyer@example.invalid'&&$coldContext['html_body']===''&&class_exists('WC_Email',false)&&false!==has_action('woocommerce_email_header')&&false!==has_action('woocommerce_email_footer')&&count($capture->captured)===0,'A pending-order context initializes Woo mail classes and template hooks without sending.');
    $order->set_status('processing');$order->save();$capture->captured=[];
    $params=static function($action,$changes)use($id){$selectors=array_intersect_key($changes,array_flip(['draft_id','template','note']));if($action==='orders.add_customer_note'){$selectors['template']='customer_note';}$s=YoRo_Order_Email::context($id,$selectors);return ['order_id'=>$id,'changes'=>$changes,'expected_version'=>$s['version'],'recipient'=>$s['recipient'],...(in_array($action,['orders.send_native_email','orders.add_customer_note'],true)?['subject'=>$s['subject'],'html_body'=>$s['html_body']]:[])];};
    $execute=static function($action,$params,$fence=null){$operation=['action'=>$action,'params'=>$params,'user_content_slots'=>YoRo_Order_Email::slots($params),'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1','authorization'=>['execution_permit'=>'disposable'],'extensions'=>['yoro.mcp_early_access_commerce_write'=>['source_operation_id'=>'envelope_'.str_repeat('a',64),'source_result_hash'=>str_repeat('b',64),'expected_version'=>$params['expected_version']]]];return YoRo_Early_Access_Commerce_Write::execute($operation,$fence);};
    $seed=YoRo_Order_Email::context($id,['subject'=>'Seed subject','body'=>'Seed body']);$assert($seed['composer']['body']==='Seed body'&&$seed['drafts']===[]&&$seed['html_body']==='','Composer seed stays ephemeral and native view is deferred.');
    $assert(!YoRo_Order_Email::valid_params('orders.save_authored_email_draft',['order_id'=>$id]),'Draft writes are unavailable.');
    $unconfirmed=$params('orders.send_authored_email',['subject'=>'Exact subject','body'=>'Exact body']);
    $assert(is_wp_error(YoRo_Order_Email::write($unconfirmed,'orders.send_authored_email')),'Direct unconfirmed callback cannot send.');
    $assert(is_wp_error($execute('orders.send_authored_email',$unconfirmed,static fn()=>false)),'Final fence rejects sends.');
    foreach(['wp_mail_content_type','wp_mail_from','wp_mail_from_name']as$hook){
        $mailHookSentinels[$hook]=static fn($value)=>$value;
        add_filter($hook,$mailHookSentinels[$hook],7);
    }
    $mailHookBaseline=$mailHooks();
    $send=$params('orders.send_authored_email',['subject'=>'Edited subject','body'=>'Edited body']);$assert(!is_wp_error($execute('orders.send_authored_email',$send)),'Exact authored send accepted.');
    $assert(count($capture->captured)===1&&$capture->captured[0]['to'][0][0]==='buyer@example.invalid'&&$capture->captured[0]['subject']==='Edited subject'&&str_contains($capture->captured[0]['body'],'Edited body')&&str_contains($capture->captured[0]['body'],'template_header')&&str_contains($capture->captured[0]['body'],'template_footer'),'Actual final transport matches the preview exactly.');
    $assert($mailHooks()===$mailHookBaseline,'Successful authored send restores every pre-existing mail callback.');
    $assert(is_wp_error($execute('orders.send_authored_email',$send))&&count($capture->captured)===1,'Old version cannot resend.');
    $native=$params('orders.send_native_email',['template'=>'processing']);$assert(!is_wp_error($execute('orders.send_native_email',$native)),'Native processing email accepted: '.wp_json_encode($native));
    $assert(count($capture->captured)===2&&$capture->captured[1]['body']===$native['html_body']&&$capture->captured[1]['subject']===$native['subject'],'Native final content equals rendered reviewed content.');
    $assert($mailHooks()===$mailHookBaseline,'Successful native send restores every pre-existing mail callback.');
    $note=$params('orders.add_customer_note',['note'=>'Customer-visible exact note']);$result=$execute('orders.add_customer_note',$note);$assert(!is_wp_error($result)&&$result['data']['outcome']==='note_created','Native customer-visible note succeeds: '.wp_json_encode($result));
    $assert(count($capture->captured)===3&&$capture->captured[2]['body']===$note['html_body'],'One note creates exactly one captured native notification.');
    $cc=static fn($headers)=>$headers."\r\nCc: other@example.invalid";add_filter('woocommerce_email_headers',$cc);try{$denied=false;try{YoRo_Order_Email::context($id);}catch(Throwable $e){$denied=true;}$assert($denied,'Configured CC makes exact one-recipient path unavailable.');}finally{remove_filter('woocommerce_email_headers',$cc);}
    $tampered=$params('orders.send_native_email',['template'=>'processing']);
    $alter=static fn($atts)=>[...$atts,'subject'=>'Altered after review'];add_filter('wp_mail',$alter,10);
    try{$unknown=$execute('orders.send_native_email',$tampered);$assert(is_wp_error($unknown)&&count($capture->captured)===3,'Final content mutation is denied without another dispatch.');}finally{remove_filter('wp_mail',$alter,10);}
    $assert(is_wp_error($execute('orders.send_native_email',$tampered))&&count($capture->captured)===3,'Unknown attempt cannot repeat with its old version.');
    $assert($mailHooks()===$mailHookBaseline,'Interrupted native send removes only its temporary mail callbacks and preserves the exact pre-existing hooks.');
    foreach($mailHookSentinels as$hook=>$callback){
        $assert(7===has_filter($hook,$callback),'Interrupted native send preserves the pre-existing '.$hook.' sentinel.');
    }
    $history=YoRo_Order_Email::context($id)['history'];$assert($history[0]['outcome']==='unknown'&&count($history)===4&&$history[1]['outcome']==='mailer_accepted','Only observed sends are recorded; inbox delivery is not claimed.');
    $payment=wc_create_order();$payment->set_billing_email('payment@example.invalid');$payment->set_total('25');$payment->set_payment_method('bacs');$payment->save();
    $invoice=YoRo_Order_Email::context($payment->get_id(),['template'=>'order_details']);$assert($invoice['native_eligible']&&str_contains(html_entity_decode($invoice['html_body']),$payment->get_checkout_payment_url()),'Native unpaid order details include the actual order payment link.');
    update_option('woocommerce_bacs_settings',['enabled'=>'yes','title'=>'Bank transfer','instructions'=>'Disposable bank transfer instructions']);
    WC()->payment_gateways()->payment_gateways()['bacs']->instructions='Disposable bank transfer instructions';
    add_filter('woocommerce_email_enabled_customer_on_hold_order','__return_false');try{$payment->set_status('on-hold');$payment->save();}finally{remove_filter('woocommerce_email_enabled_customer_on_hold_order','__return_false');}
    $invoice=YoRo_Order_Email::context($payment->get_id(),['template'=>'order_details']);$assert(str_contains($invoice['html_body'],'Disposable bank transfer instructions'),'Order details use actual native gateway instructions, without returning raw gateway settings.');
    if(getenv('YR0180_NATIVE_PREVIEW_FILE')){file_put_contents(getenv('YR0180_NATIVE_PREVIEW_FILE'),wp_json_encode(['source'=>'Disposable native WooCommerce order-details preview; not a live recipient or send authority','recipient'=>$invoice['recipient'],'subject'=>$invoice['subject'],'html_body'=>$invoice['html_body'],'order_id'=>$invoice['order_id'],'version'=>$invoice['version']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
    $rich='<p>Kính gửi <strong>Buyer</strong><br><em>Thank you</em></p><ul><li>One item</li></ul><p><a href="https://example.invalid/order?a=1&amp;b=2">View order</a></p>';
    $richParams=$params('orders.send_authored_email',['subject'=>'Rich Woo email','body'=>$rich,'body_format'=>'safe_html']);
    $richBefore=count($capture->captured);$richResult=$execute('orders.send_authored_email',$richParams);$assert(!is_wp_error($richResult)&&count($capture->captured)===$richBefore+1,'Rich authored mail uses the actual Woo sender once: '.wp_json_encode($richResult));
    $body=end($capture->captured)['body'];$assert(str_contains($body,'<strong>Buyer</strong>')&&str_contains($body,'template_header')&&str_contains($body,'template_footer'),'Formatting survives inside the actual styled Woo site template.');
    $runtime=['connector_key'=>'woocommerce','connector_connection_id'=>'connection_email_dialect_smoke','execution_mode'=>'remote_agent','protocol_version'=>1];
    $manifest=YoRo_Commerce_Contract_Builder::build_capability_manifest($runtime,1);
    $assert($manifest['extensions']['woocommerce.runtime']['commerce_features'][YoRo_Order_Email::ManifestExtension]===YoRo_Order_Email::manifest()&&YoRo_Order_Email::manifest()['version']===3&&YoRo_Order_Email::manifest()['result_version']===1,'Actual commerce manifest advertises exact email capability version3 without changing result_version.');
    $headings='<h1>Order update</h1><h2>Next steps</h2><h3>Details</h3><p>☐ Pack item</p><p>☑ Payment recorded</p>';
    $assert(10===has_filter('wp_mail','wp_staticize_emoji_for_email'),'The stock WordPress emoji mail filter remains enabled at its original priority.');
    $headingParams=$params('orders.send_authored_email',['subject'=>'Semantic headings and checklist','body'=>$headings,'body_format'=>'safe_html']);
    $prepared=YoRo_Order_Email::prepare($headingParams,'orders.send_authored_email');$assert(!is_wp_error($prepared),'Actual Woo template prepares the canonical heading and static checklist content.');
    $checklistScope=static function($atts)use($assert){
        $unrelated=['to'=>'unrelated@example.invalid','subject'=>'Unrelated notification','message'=>'<p>☑ unrelated</p>','headers'=>[],'attachments'=>[]];$found=false;
        foreach(($GLOBALS['wp_filter']['wp_mail']->callbacks[9]??[])as$row){if($row['function'] instanceof Closure){
            foreach([$unrelated,[...$atts,'to'=>'unrelated@example.invalid'],[...$atts,'subject'=>'Different subject'],[...$atts,'message'=>'<p>☑ arbitrary template</p>']]as$other){$assert($row['function']($other)===$other,'Scoped checklist encoding never changes mail with another recipient, subject or message.');}$found=true;
        }}
        $assert($found,'Authored send installs its own bounded checklist callback.');return $atts;
    };add_filter('wp_mail',$checklistScope,8);
    $headingBefore=count($capture->captured);try{$headingResult=$execute('orders.send_authored_email',$headingParams);}finally{remove_filter('wp_mail',$checklistScope,8);}
    $assert(!is_wp_error($headingResult)&&count($capture->captured)===$headingBefore+1,'Semantic heading/checklist sends once through the existing confirmed executor: '.wp_json_encode($headingResult));
    $delivered=end($capture->captured);$assert($delivered['body']===$prepared['authored']['html_body']&&$delivered['subject']===$headingParams['changes']['subject']&&$delivered['to']===[['buyer@example.invalid','']]&&$delivered['cc']===[]&&$delivered['bcc']===[]&&$delivered['attachments']===[],'Final Woo transport retains exact prepared HTML, subject and sole recipient.');
    $document=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
    try{$document->loadHTML('<?xml encoding="UTF-8">'.$delivered['body'],LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    $xpath=new DOMXPath($document);$container=$xpath->query('//*[@id="yoro-authored-email-body"]')->item(0);
    $actual=[];foreach($container->childNodes as$node){$actual[]=[$node->nodeName,$node->textContent];}
    $assert($actual===[['h1','Order update'],['h2','Next steps'],['h3','Details'],['p','☐ Pack item'],['p','☑ Payment recorded']]&&$xpath->query('.//input|.//form|.//img|.//script',$container)->length===0,'Actual Woo styling preserves real h1/h2/h3 semantics and literal static checklist paragraphs.');
    $assert($headingResult['data']['content_hash']===YoRo_Order_Email::content_hash($headingParams,'orders.send_authored_email')&&YoRo_Order_Email::context($id)['history'][0]['content_hash']===$headingResult['data']['content_hash']&&!$headingResult['data']['inbox_delivery_verified'],'Exact content hash and observed history survive heading/checklist send without an inbox claim.');
    $assert(is_wp_error($execute('orders.send_authored_email',$headingParams))&&count($capture->captured)===$headingBefore+1,'Heading/checklist content cannot resend using the consumed source version.');
    $assert($mailHooks()===$mailHookBaseline,'Heading/checklist send preserves pre-existing Woo mail callbacks.');
    if($path=getenv('YR0180_HEADING_CAPTURE_FILE')){file_put_contents($path,wp_json_encode(['manifest'=>YoRo_Order_Email::manifest(),'authored_body'=>$headings,'prepared_html'=>$prepared['authored']['html_body'],'capture'=>$delivered,'result'=>$headingResult],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    $attackParams=$params('orders.send_authored_email',['subject'=>'Heading mutation denied','body'=>$headings,'body_format'=>'safe_html']);
    $alterHeading=static function($atts){$atts['message']=str_replace('Next steps','Tampered heading',$atts['message']);return $atts;};add_filter('wp_mail',$alterHeading,10);$attackHooks=$mailHooks();$before=count($capture->captured);
    try{$assert(is_wp_error($execute('orders.send_authored_email',$attackParams))&&count($capture->captured)===$before,'A site mail filter cannot change approved heading/checklist content.');$assert($mailHooks()===$attackHooks,'Failed heading/checklist send removes only its own callback and preserves every pre-existing hook.');}finally{remove_filter('wp_mail',$alterHeading,10);}
    $assert($mailHooks()===$mailHookBaseline,'Heading/checklist failure restores the original hook order.');
    $stale=$params('orders.send_authored_email',['subject'=>'Wrapper-bound','body'=>'Do not send after template changes']);
    $changedFooter=static function(){echo '<p>Changed site footer</p>';};add_action('woocommerce_email_footer',$changedFooter,99);
    $before=count($capture->captured);
    try{$assert(is_wp_error($execute('orders.send_authored_email',$stale))&&count($capture->captured)===$before,'Changed Woo wrapper invalidates pending content before mail.');}finally{remove_action('woocommerce_email_footer',$changedFooter,99);}
    $assert(wc_get_order($id)->get_status('edit')==='processing','Email and note sends never flip the order status.');
    echo "Native order email smoke passed; ".$checks." assertions, ".count($capture->captured)." captured deliveries, 0 real deliveries.\n";
} finally {foreach($mailHookSentinels as$hook=>$callback){remove_filter($hook,$callback,7);}$wpdb->query('ROLLBACK');wp_cache_flush();$phpmailer=$oldMailer;wp_set_current_user($oldUser);}
