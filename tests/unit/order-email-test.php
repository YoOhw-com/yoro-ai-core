<?php
/** Standalone disposable connector tests. No WordPress, database, network or real mail is used. */
define('ABSPATH', __DIR__);
class WP_Error { public function __construct(public string $code, public string $message) {} }
function is_wp_error($v) {return $v instanceof WP_Error;}
function is_email($v) {return is_string($v) && filter_var($v,FILTER_VALIDATE_EMAIL)!==false;}
function current_user_can(...$args) {return $GLOBALS['allowed']??true;}
function get_current_user_id() {return 1;}
function wp_generate_uuid4() {return sprintf('00000000-0000-4000-8000-%012d',++$GLOBALS['uuid']);}
function wp_json_encode($v,...$args) {return json_encode($v,...$args);}
function wp_specialchars_decode($v) {return htmlspecialchars_decode($v,ENT_QUOTES);}
function wc_format_datetime($v) {return '2026-10-21';}
function add_filter($hook,$fn,$priority=10,$argc=1) {$GLOBALS['filters'][$hook][$priority][]=[$fn,$argc];}
function add_action(...$args) {add_filter(...$args);}
function remove_filter($hook,$fn,$priority=10) {$GLOBALS['filters'][$hook][$priority]=array_values(array_filter($GLOBALS['filters'][$hook][$priority]??[],static fn($row)=>$row[0]!==$fn));}
function remove_action(...$args) {remove_filter(...$args);}
function has_filter($hook) {foreach($GLOBALS['filters'][$hook]??[] as $p=>$rows){if($rows){return $p;}}return false;}
function apply_filters($hook,$value,...$args) {$rows=$GLOBALS['filters'][$hook]??[];ksort($rows);foreach($rows as $group){foreach($group as [$fn,$argc]){$value=$fn(...array_slice([$value,...$args],0,$argc));}}return $value;}
function do_action($hook,...$args) {$rows=$GLOBALS['filters'][$hook]??[];ksort($rows);foreach($rows as $group){foreach($group as [$fn,$argc]){$fn(...array_slice($args,0,$argc));}}}
class FixtureMailer {
 public $Subject; public $Body;
 public function __construct(public array $args) {$this->Subject=$args['subject'];$this->Body=$args['message'];}
 public function getToAddresses() {return array_map(static fn($email)=>[$email,''],(array)$this->args['to']);}
 public function getCcAddresses() {return $GLOBALS['inject_transport_cc']??[];}
 public function getBccAddresses() {return [];}
 public function getAttachments() {return (array)$this->args['attachments'];}
}
function wp_mail($to,$subject,$message,$headers=[],$attachments=[]) {
 $args=apply_filters('wp_mail',compact('to','subject','message','headers','attachments'));
 do_action('phpmailer_init',new FixtureMailer($args));
 $GLOBALS['captured'][]=$args;
 if($GLOBALS['throw_after_capture']??false){throw new RuntimeException('Interrupted after mail transport');}
 return $GLOBALS['mail_return']??true;
}
class WC_Order {
 public array $meta=[];public string $recipient='buyer@example.test';public string $status='processing';public array $notes=[];
 public function get_id() {return 1;}
 public function get_type() {return 'shop_order';}
 public function get_billing_email(...$args) {return $this->recipient;}
 public function get_status(...$args) {return $this->status;}
 public function get_date_created() {return new DateTimeImmutable('2026-10-21');}
 public function get_order_number() {return '1001';}
 public function get_meta($key,...$args) {return $this->meta[$key]??'';}
 public function update_meta_data($key,$value) {$this->meta[$key]=$value;}
 public function save_meta_data() {if($GLOBALS['fail_persist']??false){$this->meta=[];}}
 public function read_meta_data(...$args) {}
 public function add_order_note($note,$customer,$byUser) {$id=count($this->notes)+1;$this->notes[$id]=['content'=>$note,'customer'=>$customer];$email=WC()->mailer()->emails['WC_Email_Customer_Note'];$email->object=$this;$email->recipient=$this->recipient;$email->customer_note=$note;$email->send($email->get_recipient(),$email->get_subject(),$email->get_content(),$email->get_headers(),[]);return $id;}
}
function wc_get_order($id) {return $id===1?$GLOBALS['order']:false;}
class YoRo_Order_Presenter {public static function order_version($order){return hash('sha256',$order->recipient.'|'.$order->status);}}
class YoRo_Early_Access_Product_Read {
 public static function params_hash(array $params) {return hash('sha256',json_encode(self::sort($params),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));}
 private static function sort($v){if(!is_array($v)){return $v;}if(!array_is_list($v)){ksort($v);}foreach($v as &$row){$row=self::sort($row);}return $v;}
}
class WC_Email {
 public $object;public $recipient;public array $placeholders=[];public string $customer_note='';
 public function __construct(public string $id) {}
 public function setup_locale() {} public function restore_locale() {}
 public function is_enabled() {return $GLOBALS['native_enabled']??true;}
 public function get_recipient() {return apply_filters('woocommerce_email_recipient_'.$this->id,$this->recipient);}
 public function get_subject() {return $this->id==='customer_note'?'A note about order 1001':'Order 1001';}
 public function get_content() {return '<p>Native '.$this->id.' '.htmlspecialchars($this->customer_note).'</p>';}
 public function style_inline($v) {return $v;}
 public function get_headers() {return apply_filters('woocommerce_email_headers',"Content-Type: text/html; charset=UTF-8");}
 public function get_attachments() {return [];}
 public function get_content_type() {return 'text/html';}
 public function get_from_name() {return 'Fixture store';} public function get_from_address() {return 'store@example.test';}
 public function send($to,$subject,$message,$headers,$attachments){$ok=wp_mail($to,$subject,apply_filters('woocommerce_mail_content',$this->style_inline($message)),$headers,$attachments);do_action('woocommerce_email_sent',$ok,$this->id,$this);return $ok;}
}
class FixtureWooMailer {
 public array $emails=[];
 public function __construct(){foreach(['WC_Email_Customer_Invoice'=>'customer_invoice','WC_Email_Customer_Processing_Order'=>'customer_processing_order','WC_Email_Customer_Completed_Order'=>'customer_completed_order','WC_Email_Customer_Note'=>'customer_note']as$cls=>$id){$this->emails[$cls]=new WC_Email($id);}}
 public function get_emails(){return $this->emails;}
}
class FixtureWoo {public $emails;public function __construct(){$this->emails=new FixtureWooMailer;}public function mailer(){return $this->emails;}}
function WC(){return $GLOBALS['wc'];}
require dirname(__DIR__,2).'/includes/actions/class-yoro-order-email.php';
$GLOBALS['filters']=[];$GLOBALS['order']=new WC_Order;$GLOBALS['wc']=new FixtureWoo;$GLOBALS['uuid']=0;$GLOBALS['captured']=[];
$checks=0;$assert=static function($condition,$message)use(&$checks){$checks++;if(!$condition){throw new RuntimeException($message);}};
$make=static function($action,$changes){$selectors=array_intersect_key($changes,array_flip(['draft_id','template','note']));if($action==='orders.add_customer_note'){$selectors['template']='customer_note';}$s=YoRo_Order_Email::context(1,$selectors);return ['_yoro_confirmed'=>true,'_yoro_preview'=>false,'order_id'=>1,'changes'=>YoRo_Order_Email::changes($changes,$action),'expected_version'=>$s['version'],'recipient'=>$s['recipient'],...(in_array($action,['orders.send_native_email','orders.add_customer_note'],true)?['subject'=>$s['subject'],'html_body'=>$s['html_body']]:[])];};
$save=$make('orders.save_authored_email_draft',['subject'=>'Saved final subject','body'=>"Saved final\nbody"]);
$r=YoRo_Order_Email::write($save,'orders.save_authored_email_draft');$assert(!is_wp_error($r)&&$r['data']['outcome']==='draft_saved'&&count($GLOBALS['captured'])===0,'Saving the exact draft never sends.');
$draftId=$r['data']['record_id'];$context=YoRo_Order_Email::context(1,['draft_id'=>$draftId]);
$assert($context['draft']['body']==="Saved final\nbody"&&$context['draft']['subject']==='Saved final subject','Reopening preserves exact authored subject and body.');
$oldSend=$make('orders.send_authored_email',['subject'=>'Saved final subject','body'=>"Saved final\nbody"]);
$update=$make('orders.update_authored_email_draft',['draft_id'=>$draftId,'subject'=>'Edited subject','body'=>'Edited final body']);
$r=YoRo_Order_Email::write($update,'orders.update_authored_email_draft');$assert(!is_wp_error($r),'Updating a saved draft succeeds.');
$assert(is_wp_error(YoRo_Order_Email::write($oldSend,'orders.send_authored_email'))&&count($GLOBALS['captured'])===0,'Editing a saved draft invalidates the older send version.');
$send=$make('orders.send_authored_email',['subject'=>'Edited subject','body'=>'Edited final body']);
$r=YoRo_Order_Email::write($send,'orders.send_authored_email');
$assert(!is_wp_error($r)&&count($GLOBALS['captured'])===1&&$GLOBALS['captured'][0]['to']==='buyer@example.test'&&$GLOBALS['captured'][0]['subject']==='Edited subject','Exactly one authored email reaches the captured transport.');
$assert($GLOBALS['captured'][0]['message']==='<div style="white-space:pre-wrap">Edited final body</div>'&&!$r['data']['inbox_delivery_verified'],'The dispatched content is exact and never claims inbox delivery.');
$assert(is_wp_error(YoRo_Order_Email::write($send,'orders.send_authored_email'))&&count($GLOBALS['captured'])===1,'A stale send cannot be repeated.');
$wrong=$make('orders.send_authored_email',['subject'=>'No override','body'=>'Exact body']);$wrong['recipient']='other@example.test';
$assert(is_wp_error(YoRo_Order_Email::write($wrong,'orders.send_authored_email'))&&count($GLOBALS['captured'])===1,'Current billing recipient cannot be overridden.');
$cc=static fn($atts)=>[...$atts,'headers'=>["Content-Type: text/html","Cc: other@example.test"]];add_filter('wp_mail',$cc,10);
$bad=$make('orders.send_authored_email',['subject'=>'No CC','body'=>'No extra recipients']);$r=YoRo_Order_Email::write($bad,'orders.send_authored_email');
$assert(is_wp_error($r)&&count($GLOBALS['captured'])===1,'Final mail header injection cannot dispatch.');remove_filter('wp_mail',$cc,10);
$assert(YoRo_Order_Email::context(1)['history'][0]['outcome']==='unknown','Unknown attempts remain durably observed and are not silently retried.');
$native=$make('orders.send_native_email',['template'=>'processing']);$r=YoRo_Order_Email::write($native,'orders.send_native_email');
$assert(!is_wp_error($r)&&count($GLOBALS['captured'])===2&&$GLOBALS['captured'][1]['message']===$native['html_body'],'Compatible native processing email preserves the exact preview.');
$ineligible=$make('orders.send_native_email',['template'=>'completed']);
$assert(is_wp_error(YoRo_Order_Email::write($ineligible,'orders.send_native_email'))&&count($GLOBALS['captured'])===2,'Completed mail cannot be sent for a processing order.');
$note=$make('orders.add_customer_note',['note'=>'Customer-visible exact note']);$r=YoRo_Order_Email::write($note,'orders.add_customer_note');
$assert(!is_wp_error($r)&&$r['data']['outcome']==='note_created'&&count($GLOBALS['captured'])===3&&$GLOBALS['order']->notes[1]['customer']===1,'A customer note remains distinct and sends its one exact native notification.');
$GLOBALS['throw_after_capture']=true;$unknown=$make('orders.send_authored_email',['subject'=>'Unknown attempt','body'=>'Do not resend']);$r=YoRo_Order_Email::write($unknown,'orders.send_authored_email');
$assert(is_wp_error($r)&&$r->code==='local_execution_outcome_unknown'&&count($GLOBALS['captured'])===4,'Interrupted transport reports unknown without a resend.');
$assert(is_wp_error(YoRo_Order_Email::write($unknown,'orders.send_authored_email'))&&count($GLOBALS['captured'])===4,'Interrupted attempts cannot be automatically repeated with the old source.');
$GLOBALS['throw_after_capture']=false;$GLOBALS['mail_return']=false;
$failed=$make('orders.send_authored_email',['subject'=>'Rejected mail','body'=>'Mailer refusal']);$r=YoRo_Order_Email::write($failed,'orders.send_authored_email');
$assert(is_wp_error($r)&&$r->code==='order_mail_failed'&&YoRo_Order_Email::context(1)['history'][0]['outcome']==='failed','Known mailer rejection is available in bounded history without claiming delivery.');
$assert(is_wp_error(YoRo_Order_Email::write($failed,'orders.send_authored_email'))&&count($GLOBALS['captured'])===5,'A failed attempt cannot silently repeat with its old source version.');
$GLOBALS['mail_return']=true;$GLOBALS['allowed']=false;
$assert(is_wp_error(YoRo_Order_Email::read(['order_id'=>1])),'Revoked local permission denies email context.');
echo json_encode(['result'=>'passed','assertions'=>$checks,'captured_mail'=>count($GLOBALS['captured']),'real_mail'=>0]),"\n";
