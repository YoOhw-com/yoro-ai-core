<?php
/** Source-only transport/cookie/CSRF/output boundary regressions. */
define('ABSPATH', __DIR__); define('DAY_IN_SECONDS',86400);
$GLOBALS['home']='https://public-store.test'; $GLOBALS['sitekey']='synthetic-server-site-key';
$GLOBALS['settings']=['enabled'=>true,'revision'=>1,'content_ids'=>[11],'contact'=>'Public contact'];
$GLOBALS['requests']=[];
function home_url(){return $GLOBALS['home'];}
function wp_salt($name){return 'synthetic-'.$name;}
function wp_json_encode($value){return json_encode($value);}
function wp_parse_url($value,$component=-1){return parse_url($value,$component);}
function is_ssl(){return true;}
function get_current_blog_id(){return 1;}
function get_option($name,$default=null){return $GLOBALS['settings'];}
function wp_strip_all_tags($value){return strip_tags($value);}
function is_wp_error($value){return $value instanceof WP_Error;}
function wp_remote_request($url,$args){$GLOBALS['requests'][]=[$url,$args];return ['code'=>200,'body'=>'{"success":true}'];}
function wp_remote_retrieve_response_code($response){return $response['code'];}
function wp_remote_retrieve_body($response){return $response['body'];}
class WP_Error{public function __construct(public $code,public $message,public $data=[]){}}
class YoRo_AI_Settings{public static function get_ai_api_site_key(){return $GLOBALS['sitekey'];}public static function get_ai_api_url(){return 'https://api.example.test/api/v1';}}
class YoRo_WordPress_Actor{public static function assertion($key){return 'synthetic-operator-assertion';}}
class SyntheticRequest{public function __construct(public array $headers=[]){ }public function get_header($name){return $this->headers[$name]??'';}}
require dirname(__DIR__,2).'/includes/ai/clients/class-yoro-ai-api-endpoint-resolver.php';
require dirname(__DIR__,2).'/includes/customer-support/class-yoro-support-transport.php';
require dirname(__DIR__,2).'/includes/customer-support/class-yoro-customer-support.php';
$count=0;function check($condition,$why){global$count;++$count;if(!$condition){throw new RuntimeException($why);}}
$session=['session_id'=>'synthetic-session','credential'=>'synthetic-private-support-bearer','revision'=>1,'expires'=>time()+86400];
$cookie=YoRo_Support_Transport::seal($session);
check(!str_contains($cookie,$session['credential'])&&YoRo_Support_Transport::open($cookie)===$session,'Support bearer encrypted in HttpOnly cookie envelope.');
check(YoRo_Support_Transport::open($cookie.'tampered')===[],'Tampered cookie rejected.');
$GLOBALS['sitekey']='rotated-site-key';check(YoRo_Support_Transport::open($cookie)===[],'Site key rotation invalidates cookie.');$GLOBALS['sitekey']='synthetic-server-site-key';
$GLOBALS['home']='https://another-site.test';check(YoRo_Support_Transport::open($cookie)===[],'Cookie is site-bound.');$GLOBALS['home']='https://public-store.test';
$expired=$session;$expired['expires']=time()-1;check(YoRo_Support_Transport::open(YoRo_Support_Transport::seal($expired))===[],'Absolute expiry enforced server-side.');
check(strlen(YoRo_Support_Transport::csrf($cookie))===64,'Per-cookie CSRF proof.');
check(YoRo_Customer_Support::permission(new SyntheticRequest(['Origin'=>'https://public-store.test']))===true,'Same-origin HTTPS guest permitted to request support.');
foreach(['','null','https://attacker.test','https://public-store.test.attacker.test','http://public-store.test','https://public-store.test:8443']as$origin){check(is_wp_error(YoRo_Customer_Support::permission(new SyntheticRequest(['Origin'=>$origin]))),'Missing/cross-origin request denied.');}
$GLOBALS['settings']['enabled']=false;check(is_wp_error(YoRo_Customer_Support::permission(new SyntheticRequest(['Origin'=>'https://public-store.test']))),'Site disabled default gate.');$GLOBALS['settings']['enabled']=true;
YoRo_Support_Transport::request('POST','/turns',['message'=>'public fixture'],$session,false);
[$url,$args]=$GLOBALS['requests'][0];
check($url==='https://api.example.test/api/v1/customer-support/turns'&&$args['redirection']===0&&$args['sslverify']===true,'Pinned HTTPS endpoint and no redirect credential forwarding.');
check(!isset($args['headers']['X-YoRo-Actor-Assertion'])&&$args['headers']['Authorization']==='Bearer synthetic-server-site-key','Guest channel does not impersonate operator.');
check($args['headers']['X-YoRo-Support-Credential']===$session['credential']&&strlen($args['headers']['X-YoRo-Support-Abuse'])===64,'Bound server session and HMAC-only abuse metadata.');
YoRo_Support_Transport::request('GET','/settings',[],[],true);check(isset($GLOBALS['requests'][1][1]['headers']['X-YoRo-Actor-Assertion']),'Settings path carries current operator assertion.');
$snapshot=['sources'=>[['id'=>'content:11','title'=>'Safe title','url'=>'https://public-store.test/faq']]];
$safe=YoRo_Customer_Support::safe_answer(['text'=>'<script>x</script>plain','sources'=>[['id'=>'order:123','url'=>'javascript:evil'],['id'=>'content:11','url'=>'https://attacker.test']]],$snapshot);
check($safe['text']==='xplain'&&count($safe['sources'])===1&&$safe['sources'][0]['url']==='https://public-store.test/faq','Safe text and citations resolved only from current server facts.');
$js=file_get_contents(dirname(__DIR__,2).'/assets/js/yoro-support.js');
check(!str_contains($js,'innerHTML')&&!str_contains($js,'localStorage')&&!str_contains($js,'sessionStorage')&&!str_contains($js,'indexedDB'),'No HTML injection or persistent browser transcript.');
check(str_contains($js,'crypto.randomUUID()')&&str_contains($js,'retryCount < 2'),'Stable UUID and finite explicit retries.');
$php=file_get_contents(dirname(__DIR__,2).'/includes/customer-support/class-yoro-customer-support.php');
check(str_contains($php,"'httponly' => true")&&str_contains($php,"'secure' => true")&&str_contains($php,"'samesite' => 'Strict'"),'Secure HttpOnly SameSite cookie flags.');
check(str_contains($php,"current_user_can( 'manage_options' )")&&str_contains($php,"current_user_can( 'yoro_manage_connection' )")&&str_contains($php,"check_admin_referer( 'yoro_support_settings' )"),'Settings require both current capabilities and CSRF.');
echo json_encode(['result'=>'passed','assertions'=>$count],JSON_THROW_ON_ERROR),"\n";
