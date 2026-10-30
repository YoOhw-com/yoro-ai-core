<?php
/** Executable Core session/turn transport integration with synthetic API responses; no WordPress or network. */
define('ABSPATH', __DIR__); define('DAY_IN_SECONDS', 86400);
$GLOBALS['requests'] = []; $GLOBALS['responses'] = [];
function absint($value) { return abs((int)$value); }
function home_url() { return 'https://public-store.test'; }
function wp_salt($name) { return 'synthetic-session-'.$name; }
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_url($value,$component=-1) { return parse_url($value,$component); }
function get_current_blog_id() { return 1; }
function is_ssl() { return true; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($name,$default=null) { return ['enabled'=>true,'revision'=>1,'content_ids'=>[],'contact'=>'Public contact']; }
function wp_remote_request($url,$args) {
    $GLOBALS['requests'][]=[$args['method'],parse_url($url,PHP_URL_PATH),$args];
    if (!$GLOBALS['responses']) { throw new RuntimeException('Unexpected HTTP transport call.'); }
    return array_shift($GLOBALS['responses']);
}
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return json_encode($response['body']); }
class WP_Error {
    public function __construct(public $code,public $message,public $data=[]) {}
    public function get_error_code() { return $this->code; }
}
class WP_REST_Response {
    public array $headers=[];
    public function __construct(public array $data) {}
    public function header($name,$value) { $this->headers[$name]=$value; }
}
class YoRo_AI_Settings {
    public static function get_ai_api_url() { return 'https://api.example.test/api/v1'; }
    public static function get_ai_api_site_key() { return 'synthetic-server-only-key'; }
}
class SyntheticSupportRequest {
    public function __construct(public array $params=[],public array $headers=[]) {}
    public function get_header($name) { return $this->headers[$name]??''; }
    public function get_param($name) { return $this->params[$name]??null; }
}
require dirname(__DIR__,2).'/includes/ai/clients/class-yoro-ai-api-endpoint-resolver.php';
require dirname(__DIR__,2).'/includes/customer-support/class-yoro-support-transport.php';
require dirname(__DIR__,2).'/includes/customer-support/class-yoro-support-facts.php';
require dirname(__DIR__,2).'/includes/customer-support/class-yoro-customer-support.php';
$count=0;
function check($condition,$why) { global $count; ++$count; if(!$condition){throw new RuntimeException($why);} }
function existing_session(): array {
    $session=['session_id'=>'old-session','credential'=>'old-server-credential','revision'=>1,'expires'=>time()+3600];
    $cookie=YoRo_Support_Transport::seal($session); $_COOKIE['__Host-yoro_support_1']=$cookie;
    $GLOBALS['requests']=[]; $GLOBALS['responses']=[];
    return [$cookie,YoRo_Support_Transport::csrf($cookie)];
}
function response_fixture($status,$code) { return ['status'=>$status,'body'=>['code'=>$code]]; }
foreach (['idle-expired','revoked-session'] as $reason) {
    [$cookie,$csrf]=existing_session();
    $GLOBALS['responses']=[response_fixture(403,'support_session_unavailable')];
    $turn=new SyntheticSupportRequest(['turn_id'=>'12345678-1234-4123-8123-123456789abc','message'=>'Public question'],['Origin'=>home_url(),'X-YoRo-Support-CSRF'=>$csrf]);
    check(YoRo_Customer_Support::permission($turn)===true,'Same-origin policy checked before turn.');
    check(is_wp_error(YoRo_Customer_Support::turn($turn)),'Inactive session turn safely denies.');
    check(count($GLOBALS['requests'])===1&&$GLOBALS['requests'][0][0]==='POST'&&str_ends_with($GLOBALS['requests'][0][1],'/turns'),'Denied turn never creates a replacement session or retries inference.');
    $GLOBALS['responses']=[response_fixture(403,'support_session_unavailable'),['status'=>200,'body'=>['session_id'=>'fresh-session','credential'=>'fresh-server-credential','revision'=>1]]];
    $reset=new SyntheticSupportRequest(['reset'=>true],['Origin'=>home_url(),'X-YoRo-Support-CSRF'=>$csrf]);
    $result=YoRo_Customer_Support::session($reset);
    check($result instanceof WP_REST_Response&&$result->data['csrf']!==$csrf,'Explicit reset recovers inactive session without a page reload.');
    check(count($GLOBALS['requests'])===3&&$GLOBALS['requests'][1][0]==='DELETE'&&str_ends_with($GLOBALS['requests'][2][1],'/sessions'),'Recovery follows authoritative old-session denial with one fresh API admission.');
    check(!str_contains(json_encode($result->data),'fresh-server-credential'),'Browser DTO never exposes support bearer.');
    check(($result->headers['Cache-Control']??'')==='no-store, private','Fresh CSRF response never cacheable.');
}
foreach ([new WP_Error('network_failed','Synthetic outage'),response_fixture(503,'support_provider_failed'),response_fixture(403,'support_access_denied'),response_fixture(401,'invalid_site_key')] as $failure) {
    foreach ([false,true] as $reset) {
        [$cookie,$csrf]=existing_session();$GLOBALS['responses']=[$failure];
        $result=YoRo_Customer_Support::session(new SyntheticSupportRequest(['reset'=>$reset],['X-YoRo-Support-CSRF'=>$csrf]));
        check(is_wp_error($result)&&count($GLOBALS['requests'])===1,'Network/upstream/site-authorization failure does not silently replace a session.');
        check($_COOKIE['__Host-yoro_support_1']===$cookie,'Failure preserves original browser session envelope.');
    }
}
[$cookie,$csrf]=existing_session();
check(is_wp_error(YoRo_Customer_Support::session(new SyntheticSupportRequest(['reset'=>true],['X-YoRo-Support-CSRF'=>'forged']))),'Reset requires per-cookie CSRF.');
check(count($GLOBALS['requests'])===0,'Forged reset has no transport side effects.');
[$cookie,$csrf]=existing_session();$GLOBALS['responses']=[['status'=>200,'body'=>['revision'=>1]]];
$result=YoRo_Customer_Support::session(new SyntheticSupportRequest());
check($result instanceof WP_REST_Response&&$result->data['csrf']===$csrf&&count($GLOBALS['requests'])===1,'Current active session is validated and reused.');
echo json_encode(['result'=>'passed','assertions'=>$count],JSON_THROW_ON_ERROR),"\n";
