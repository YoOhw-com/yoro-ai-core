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
function wp_timezone(){return new DateTimeZone($GLOBALS['test_timezone']??'UTC');}
function wc_get_order_status_name($status){return $status;}
function get_option($key,$default=''){return $key==='blogname'?'Fixture store':$default;}
function get_locale(){return 'en_US';}
function wc_format_datetime($v) {return '2026-10-21';}
function add_filter($hook,$fn,$priority=10,$argc=1) {$GLOBALS['filters'][$hook][$priority][]=[$fn,$argc];}
function add_action(...$args) {add_filter(...$args);}
function remove_filter($hook,$fn,$priority=10) {$GLOBALS['filters'][$hook][$priority]=array_values(array_filter($GLOBALS['filters'][$hook][$priority]??[],static fn($row)=>$row[0]!==$fn));}
function remove_action(...$args) {remove_filter(...$args);}
function has_filter($hook) {foreach($GLOBALS['filters'][$hook]??[] as $p=>$rows){if($rows){return $p;}}return false;}
function apply_filters($hook,$value,...$args) {$rows=$GLOBALS['filters'][$hook]??[];ksort($rows);foreach($rows as $group){foreach($group as [$fn,$argc]){$value=$fn(...array_slice([$value,...$args],0,$argc));}}return $value;}
function do_action($hook,...$args) {$rows=$GLOBALS['filters'][$hook]??[];ksort($rows);foreach($rows as $group){foreach($group as [$fn,$argc]){$fn(...array_slice($args,0,$argc));}}}
class FixtureMailer {
 public $Subject; public $Body; public string $From="store@example.test"; public string $FromName="Fixture store";
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
 public function get_billing_phone(...$args){return $GLOBALS['billing_phone']??'';}public function get_shipping_phone(...$args){return '';}
 public function get_items(...$args){return [];} public function get_refunds(){return [];}
 public function get_customer_id(...$args){return 0;} public function get_date_paid(...$args){return null;} public function get_date_completed(...$args){return null;}
 public function get_currency(...$args){return 'USD';} public function get_payment_method(...$args){return '';} public function get_payment_method_title(...$args){return '';}
 public function get_billing_first_name(...$args){return 'Buyer';} public function get_billing_last_name(...$args){return '';}
 public function get_discount_total(...$args){return '0';}public function get_shipping_total(...$args){return '0';}public function get_total_tax(...$args){return '0';}public function get_total(...$args){return '0';}
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
function wc_get_order($id) {$GLOBALS['order_reads']=($GLOBALS['order_reads']??0)+1;return $id===1?$GLOBALS['order']:false;}
class YoRo_Order_Presenter {public static function order_version($order){return hash('sha256',$order->recipient.'|'.$order->status);}}
class YoRo_Early_Access_Product_Read {
 public static function params_hash(array $params) {return hash('sha256',json_encode(self::sort($params),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));}
 private static function sort($v){if(!is_array($v)){return $v;}if(!array_is_list($v)){ksort($v);}foreach($v as &$row){$row=self::sort($row);}return $v;}
}
class WC_Email {
 public $object;public $recipient;public array $placeholders=[];public string $customer_note='';
 public string $email_type = "html"; public function __construct(public string $id = "") {}
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
add_action('woocommerce_email_header',static function($subject){echo '<header>'.$subject.'</header>';});add_action('woocommerce_email_footer',static function(){echo '<footer>'.($GLOBALS['wrapper_footer']??'Fixture store').'</footer>';});
$GLOBALS['order']=new WC_Order;$GLOBALS['wc']=new FixtureWoo;$GLOBALS['uuid']=0;$GLOBALS['captured']=[];
$checks=0;$assert=static function($condition,$message)use(&$checks){$checks++;if(!$condition){throw new RuntimeException($message);}};
$assert(YoRo_Order_Email::manifest()===['version'=>2,'actions'=>[...YoRo_Order_Email::ReadActions,...YoRo_Order_Email::WriteActions],'max_targets'=>1,'requires_source_read'=>true,'requires_confirmation'=>true,'result_version'=>1,'recipient'=>'current_order_billing','composer'=>'yoro_bounded_email'],'Only the existing email capability version advertises the expanded HTML dialect.');
$make=static function($action,$changes){$selectors=array_intersect_key($changes,array_flip(['draft_id','template','note']));if($action==='orders.add_customer_note'){$selectors['template']='customer_note';}$s=YoRo_Order_Email::context(1,$selectors);return ['_yoro_confirmed'=>true,'_yoro_preview'=>false,'order_id'=>1,'changes'=>YoRo_Order_Email::changes($changes,$action),'expected_version'=>$s['version'],'recipient'=>$s['recipient'],...(in_array($action,['orders.send_native_email','orders.add_customer_note'],true)?['subject'=>$s['subject'],'html_body'=>$s['html_body']]:[])];};
$GLOBALS['order']->meta['_yoro_customer_email_drafts']=[['id'=>'legacy','body'=>'Historic data stays untouched']];
$context=YoRo_Order_Email::context(1,['subject'=>'Seed subject','body'=>"Seed body\nSecond line"]);
$assert($context['composer']===['subject'=>'Seed subject','body'=>"Seed body\nSecond line",'body_format'=>'plain_text']&&$context['drafts']===[]&&$context['draft']===null&&$context['html_body']==='','Context opens an ephemeral composer and omits native email/drafts.');
$assert($context['version']===YoRo_Order_Email::context(1)['version'],'Seed content does not fabricate source or send authority.');
$assert(!YoRo_Order_Email::valid_params('orders.save_authored_email_draft',['order_id'=>1]),'Draft writes are unavailable.');
$assert($GLOBALS['order']->meta['_yoro_customer_email_drafts'][0]['id']==='legacy','Historical stored records remain untouched.');
$send=$make('orders.send_authored_email',['subject'=>'Edited subject','body'=>'Edited final body']);
$r=YoRo_Order_Email::write($send,'orders.send_authored_email');
$assert(!is_wp_error($r)&&count($GLOBALS['captured'])===1&&$GLOBALS['captured'][0]['to']==='buyer@example.test'&&$GLOBALS['captured'][0]['subject']==='Edited subject','Exactly one authored email reaches the captured transport.');
$assert($GLOBALS['captured'][0]['message']==='<header>Edited subject</header><div id="yoro-authored-email-body"><div style="white-space:pre-wrap">Edited final body</div></div><footer>Fixture store</footer>'&&!$r['data']['inbox_delivery_verified'],'The dispatched content is exact and never claims inbox delivery.');
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
$GLOBALS['mail_return']=true;
$rich='<p>Hello <strong>Buyer</strong><br><em>Thanks</em></p><ul><li>One</li></ul><p><a href="https://example.test/order?a=1&amp;b=2">View order</a></p>';
$richParams=$make('orders.send_authored_email',['subject'=>'Rich mail','body'=>$rich,'body_format'=>'safe_html']);
$r=YoRo_Order_Email::write($richParams,'orders.send_authored_email');
$assert(!is_wp_error($r)&&str_contains(end($GLOBALS['captured'])['message'],$rich),'Supported formatting is preserved exactly inside the Woo wrapper.');
$headings='<h1>Order update</h1><h2>Next steps</h2><h3>Details</h3><p>☐ Pack item</p><p>☑ Payment recorded</p>';
$headingParams=$make('orders.send_authored_email',['subject'=>'Heading mail','body'=>$headings,'body_format'=>'safe_html']);
$before=count($GLOBALS['captured']);$unconfirmed=$headingParams;unset($unconfirmed['_yoro_confirmed']);
$assert(is_wp_error(YoRo_Order_Email::write($unconfirmed,'orders.send_authored_email'))&&count($GLOBALS['captured'])===$before,'Headings and static checklists grant no confirmation authority.');
$r=YoRo_Order_Email::write($headingParams,'orders.send_authored_email');
$assert(!is_wp_error($r)&&count($GLOBALS['captured'])===$before+1&&str_contains(html_entity_decode(end($GLOBALS['captured'])['message'],ENT_QUOTES|ENT_HTML5,'UTF-8'),$headings),'All three semantic heading levels and literal checklist text survive the exact captured wrapper.');
$assert($r['data']['content_hash']===YoRo_Order_Email::content_hash($headingParams,'orders.send_authored_email')&&YoRo_Order_Email::context(1)['history'][0]['content_hash']===$r['data']['content_hash'],'Heading/checklist bytes bind the unchanged content hash and durable observed record.');
$different=$headingParams;$different['changes']['body']=str_replace(['<h2>','</h2>'],['<p>','</p>'],$headings);
$assert(YoRo_Order_Email::content_hash($different,'orders.send_authored_email')!==$r['data']['content_hash'],'Changing a semantic heading changes the exact confirmed content hash.');
$different['changes']['body']=str_replace('☐','☑',$headings);
$assert(YoRo_Order_Email::content_hash($different,'orders.send_authored_email')!==$r['data']['content_hash'],'Changing a static checklist marker changes the exact confirmed content hash.');
$extra=$headingParams;$extra['changes']['checklist']=true;
$assert(!YoRo_Order_Email::valid_params('orders.send_authored_email',array_diff_key($extra,array_flip(['_yoro_confirmed','_yoro_preview']))),'Heading/checklist formatting adds no new wire fields.');
$bounded='<p>'.str_repeat('é',5996).'x</p>';
$assert(strlen($bounded)===12000&&YoRo_Order_Email::safeHtml($bounded)===$bounded,'The existing 12000-byte UTF-8 boundary is inclusive.');
$depth=str_repeat('<strong>',20).'x'.str_repeat('</strong>',20);
$assert(YoRo_Order_Email::safeHtml($depth)===$depth,'The existing depth20 boundary remains inclusive.');
$nodes='<p>'.str_repeat('<br>',1998).'x</p>';
$assert(YoRo_Order_Email::safeHtml($nodes)===$nodes,'The existing 2000-node boundary remains inclusive.');
foreach(['<script>x</script>','<p onclick="x">Hi</p>','<a href="http://example.test">Hi</a>','<a href="javascript:alert(1)">Hi</a>','<img src="https://example.test/x">','<p style="color:red">Hi</p>','<P>Hi</P>','<p>Hi</p><!--x-->','<p><strong>Broken</p>','<h1 class="title">Hi</h1>','<h2 style="color:red">Hi</h2>','<h3 data-check="true">Hi</h3>','<h1 onclick="x">Hi</h1>','<h4>Hi</h4>','<H1>Hi</H1>','<h2>Hi','<form><p>☐ item</p></form>','<p><input type="checkbox">item</p>','<p>&#x2610; item</p>','<h1>Hi'.chr(0).'</h1>','<h1>'.chr(0xff).'</h1>',substr($bounded,0,-4).'x</p>',str_repeat('<strong>',21).'x'.str_repeat('</strong>',21),'<p>'.str_repeat('<br>',1999).'x</p>']as$bad){
 $rejected=false;try{YoRo_Order_Email::changes(['subject'=>'Unsafe','body'=>$bad,'body_format'=>'safe_html'],'orders.send_authored_email');}catch(Throwable $e){$rejected=true;}$assert($rejected,'Unsafe/noncanonical rich body fails closed: '.$bad);
}
$restyled=$make('orders.send_authored_email',['subject'=>'Semantic heading','body'=>$headings,'body_format'=>'safe_html']);$before=count($GLOBALS['captured']);
$filter=static fn($html)=>str_replace(['<h2>','</h2>'],['<p>','</p>'],$html);add_filter('woocommerce_mail_content',$filter,10);
try{$assert(is_wp_error(YoRo_Order_Email::write($restyled,'orders.send_authored_email'))&&count($GLOBALS['captured'])===$before,'Site hooks cannot flatten an approved heading into a paragraph.');}finally{remove_filter('woocommerce_mail_content',$filter,10);}
$stale=$make('orders.send_authored_email',['subject'=>'Wrapper-bound','body'=>'Exact body']);$GLOBALS['wrapper_footer']='Changed template';
$before=count($GLOBALS['captured']);$assert(is_wp_error(YoRo_Order_Email::write($stale,'orders.send_authored_email'))&&count($GLOBALS['captured'])===$before,'Changed Woo template invalidates source before transport.');
$before=count($GLOBALS['captured']);
$modified=$make('orders.send_authored_email',['subject'=>'Filtered body','body'=>'Exact approved text']);
$filter=static fn($html)=>str_replace('Exact approved text','Changed by site hook',$html);add_filter('woocommerce_mail_content',$filter,10);
try{$assert(is_wp_error(YoRo_Order_Email::write($modified,'orders.send_authored_email'))&&count($GLOBALS['captured'])===$before,'Site styling hooks cannot replace approved body text.');}finally{remove_filter('woocommerce_mail_content',$filter,10);}
$unicode=<<<'HTML'
<p>Kính gửi O'Brien &amp; &quot;Buyer&quot;</p><p><a href="https://example.test/order?q=O&#039;Brien">Xem đơn</a></p>
HTML;
// Text quotes are literal in the editor canonical dialect.
$unicode=str_replace('&quot;','"',$unicode);
$unicodeParams=$make('orders.send_authored_email',['subject'=>'Đơn hàng','body'=>$unicode,'body_format'=>'safe_html']);
$assert(!is_wp_error(YoRo_Order_Email::write($unicodeParams,'orders.send_authored_email')),'Unicode and apostrophes survive Woo styling without content rewriting.');
$crlf=$make('orders.send_authored_email',['subject'=>'Plain line endings','body'=>"First line\r\nSecond line"]);
$assert(!is_wp_error(YoRo_Order_Email::write($crlf,'orders.send_authored_email')),'Plain text CRLF line breaks retain their semantic line boundaries inside Woo.');
$GLOBALS['allowed']=false;
$assert(is_wp_error(YoRo_Order_Email::read(['order_id'=>1])),'Revoked local permission denies email context.');
echo json_encode(['result'=>'passed','assertions'=>$checks,'captured_mail'=>count($GLOBALS['captured']),'real_mail'=>0]),"\n";
