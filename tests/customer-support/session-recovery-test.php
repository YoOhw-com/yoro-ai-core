<?php
/** Executable Core session/turn transport integration with synthetic API responses; no WordPress or network. */
define('ABSPATH', __DIR__); define('DAY_IN_SECONDS', 86400);
$GLOBALS['requests'] = []; $GLOBALS['responses'] = [];
function absint($value) { return abs((int)$value); }
function home_url() { return 'https://public-store.test'; }
function wp_salt($name) { return 'synthetic-session-'.$name; }
function wp_json_encode($value) { return json_encode($value); }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wc_get_product($id=0) { return null; }
function remove_accents($value) { return iconv('UTF-8', 'ASCII//TRANSLIT', $value); }
function wp_parse_url($value,$component=-1) { return parse_url($value,$component); }
function get_current_blog_id() { return 1; }
function is_ssl() { return true; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($name,$default=null) { return $GLOBALS['support_fixture_settings'] ?? ['enabled'=>true,'revision'=>1,'content_ids'=>[],'contact'=>'Public contact']; }
function wp_remote_request($url,$args) {
    $GLOBALS['requests'][]=[$args['method'],parse_url($url,PHP_URL_PATH),$args];
    if (!$GLOBALS['responses']) { throw new RuntimeException('Unexpected HTTP transport call.'); }
    $response=array_shift($GLOBALS['responses']);
    return is_callable($response)?$response($url,$args):$response;
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
    public static string $key = 'synthetic-server-only-key';
    public static function get_ai_api_site_key() {
        if (!empty($GLOBALS['trace_transport_attempts'])) {
            $caller=debug_backtrace(0,2)[1]??[];
            if (($caller['class']??'')==='YoRo_Support_Transport'&&($caller['function']??'')==='request') {
                $GLOBALS['transport_attempts'][]=[$caller['args'][0],$caller['args'][1]];
            }
        }
        return self::$key;
    }
}
class SyntheticSupportRequest {
    public function __construct(public array $params=[],public array $headers=[]) {}
    public function get_header($name) { return $this->headers[$name]??''; }
    public function get_params() { return $this->params; }
    public function get_param($name) { return $this->params[$name]??null; }
}
require dirname(__DIR__,2).'/includes/ai/clients/class-yoro-ai-api-endpoint-resolver.php';
require dirname(__DIR__,2).'/includes/customer-support/class-yoro-support-transport.php';
require dirname(__DIR__,2).'/includes/customer-support/class-yoro-support-facts.php';
require dirname(__DIR__,2).'/includes/customer-support/class-yoro-support-context.php';
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
// Definitive local session loss must remain distinct from transient transport failure.
foreach (['revision', 'disconnect', 'missing'] as $loss) {
    foreach (['verification', 'context', 'turn'] as $route) {
        [$cookie,$csrf]=existing_session();
        $GLOBALS['support_fixture_settings']=['enabled'=>true,'protocol'=>2,'revision'=>$loss==='revision'?2:1,'content_ids'=>[],'contact'=>'Public contact'];
        YoRo_AI_Settings::$key=$loss==='disconnect'?'':'synthetic-server-only-key';
        if ($loss==='missing') { unset($_COOKIE['__Host-yoro_support_1']); }
        $params=match($route) {
            'verification'=>['action'=>'current'], 'context'=>['kind'=>'orders'],
            'turn'=>['turn_id'=>'12345678-1234-4123-8123-123456789abc','message'=>'Synthetic public question'],
        };
        $result=YoRo_Customer_Support::$route(new SyntheticSupportRequest($params,['Origin'=>home_url(),'X-YoRo-Support-CSRF'=>$csrf]));
        check(is_wp_error($result)&&$result->get_error_code()==='support_session_inactive','G010 definitive '.$loss.' loss has a browser-consumable inactive code on '.$route.'.');
        check(($result->data['status']??null)===403&&count($GLOBALS['requests'])===0,'Definitive local loss denies before any transport/admission.');
    }
}
YoRo_AI_Settings::$key='synthetic-server-only-key';
$GLOBALS['support_fixture_settings']=['enabled'=>true,'protocol'=>2,'revision'=>1,'content_ids'=>[],'contact'=>'Public contact'];
[$cookie,$csrf]=existing_session();$GLOBALS['responses']=[response_fixture(403,'support_session_unavailable')];
$result=YoRo_Customer_Support::verification(new SyntheticSupportRequest(['action'=>'current'],['X-YoRo-Support-CSRF'=>$csrf]));
check(is_wp_error($result)&&$result->get_error_code()==='support_session_inactive'&&($result->data['status']??null)===403,'Known upstream inactive session is preserved by current verification.');
check(count($GLOBALS['requests'])===1&&$GLOBALS['requests'][0][0]==='GET'&&$_COOKIE['__Host-yoro_support_1']===$cookie,'Inactive status never resets/adopts/retries session or changes cookie.');
foreach ([new WP_Error('network_failed','Synthetic outage'),response_fixture(503,'support_provider_failed')] as $failure) {
    [$cookie,$csrf]=existing_session();$GLOBALS['responses']=[$failure];
    $result=YoRo_Customer_Support::verification(new SyntheticSupportRequest(['action'=>'current'],['X-YoRo-Support-CSRF'=>$csrf]));
    check(is_wp_error($result)&&$result->get_error_code()==='support_unavailable','Transient current-session failure is not labelled definitive inactive.');
    check(count($GLOBALS['requests'])===1&&$_COOKIE['__Host-yoro_support_1']===$cookie,'Transient denial preserves original cookie and performs no automatic admission.');
}
[$cookie,$csrf]=existing_session();
$result=YoRo_Customer_Support::verification(new SyntheticSupportRequest(['action'=>'current'],['X-YoRo-Support-CSRF'=>'forged']));
check(is_wp_error($result)&&$result->get_error_code()==='support_unavailable'&&count($GLOBALS['requests'])===0,'Existing CSRF denial stays fail closed without transport.');
$GLOBALS['support_fixture_settings']['protocol']=1;
$result=YoRo_Customer_Support::verification(new SyntheticSupportRequest(['action'=>'current'],['X-YoRo-Support-CSRF'=>$csrf]));
check(is_wp_error($result)&&$result->get_error_code()==='support_unavailable'&&count($GLOBALS['requests'])===0,'Existing local protocol guard stays fail closed.');
$GLOBALS['support_fixture_settings']['protocol']=2;$GLOBALS['responses']=[['status'=>200,'body'=>['protocol'=>1]]];
$result=YoRo_Customer_Support::verification(new SyntheticSupportRequest(['action'=>'current'],['X-YoRo-Support-CSRF'=>$csrf]));
check(is_wp_error($result)&&$result->get_error_code()==='support_unavailable'&&count($GLOBALS['requests'])===1,'Unexpected upstream protocol cannot expose private context.');
// Mid-turn authorization uses the actual Context/Transport/controller, with a read-card fixture only.
class WC_Order {
    public function get_type(){return 'shop_order';} public function get_billing_email($context='edit'){return 'synthetic@example.invalid';}
    public function get_items(){return [];} public function get_order_number(){return $GLOBALS['mid_card_marker']??'PRIVATE_CARD';}
    public function get_date_created(){return null;} public function get_status(){return 'processing';} public function is_paid(){return true;}
    public function get_total(){return '1.00';} public function get_currency(){return 'USD';} public function get_total_refunded(){return '0.00';}
}
function wc_get_order($id){return new WC_Order();} function wc_get_order_status_name($status){return $status;}
function wc_format_decimal($value,$decimals=2){return number_format((float)$value,$decimals,'.','');} function wc_get_price_decimals(){return 2;}
function is_email($email){return filter_var($email,FILTER_VALIDATE_EMAIL)!==false;}
function mid_auth_ok(): array { return ['status'=>200,'body'=>['protocol'=>2,'purpose'=>'orders','session_id'=>'old-session','revision'=>1,'access_id'=>'synthetic-access','email'=>'synthetic@example.invalid','expires_at'=>gmdate('c',time()+600)]]; }
function mid_setup(): array {
    [$cookie,$csrf]=existing_session();YoRo_AI_Settings::$key='synthetic-server-only-key';
    $GLOBALS['support_fixture_settings']=['enabled'=>true,'protocol'=>2,'revision'=>1,'content_ids'=>[],'contact'=>'Public contact','preferences'=>['features'=>['content'=>false,'products'=>false,'orders'=>true]]];
    $GLOBALS['mid_card_marker']='PRIVATE_CARD';$session=YoRo_Support_Transport::open($cookie);
    $context=['kind'=>'order','access_id'=>'synthetic-access','reference'=>'opaque-private-reference','expires_at'=>gmdate('c',time()+300)];
    $reference=YoRo_Support_Transport::seal(['order_id'=>1,'session_id'=>$session['session_id'],'credential'=>'synthetic-access','email_binding'=>hash('sha256','synthetic@example.invalid'),'expires'=>time()+300]);
    $token=YoRo_Support_Transport::seal(array_replace($session,['expires'=>time()+300,'context'=>$context,'params'=>['reference'=>$reference]]));
    $GLOBALS['responses']=[mid_auth_ok()];$snapshot=YoRo_Support_Context::snapshot($GLOBALS['support_fixture_settings'],$session,'Synthetic question',null,$token);
    check(!is_wp_error($snapshot)&&$snapshot['context']['access_id']==='synthetic-access','Real sealed private Context snapshot passes initial API authorization.');
    $GLOBALS['requests']=[];$GLOBALS['responses']=[];
    return [$csrf,$token,$snapshot];
}
function mid_turn($csrf,$token) { return YoRo_Customer_Support::turn(new SyntheticSupportRequest(['turn_id'=>'12345678-1234-4123-8123-123456789abc','message'=>'Synthetic private question','context_token'=>$token],['Origin'=>home_url(),'X-YoRo-Support-CSRF'=>$csrf])); }
function mid_queue($phase,$snapshot,$failure,$cancel_failure=false): array {
    $completed=['status'=>200,'body'=>['status'=>'completed','snapshot'=>$snapshot,'answer'=>['text'=>'PRIVATE_MIDPHASE_ANSWER','sources'=>[]]]];
    $validate=['status'=>200,'body'=>['status'=>'validate','snapshot'=>$snapshot]];
    $cancel=$cancel_failure?response_fixture(503,'support_provider_failed'):['status'=>200,'body'=>['status'=>'cancelled']];
    return match($phase) {
        'facts'=>[mid_auth_ok(),['status'=>200,'body'=>['status'=>'facts','query'=>'Synthetic refresh']],$failure,$cancel],
        'validate'=>[mid_auth_ok(),$validate,$failure,$cancel],
        'replay'=>[mid_auth_ok(),$completed,$failure,$cancel],
        'complete'=>[mid_auth_ok(),$validate,mid_auth_ok(),$failure],
        'final-complete'=>[mid_auth_ok(),$validate,mid_auth_ok(),$completed,$failure],
        'final-replay'=>[mid_auth_ok(),$completed,mid_auth_ok(),$failure],
    };
}
$midphase_results=[];
foreach (['facts','validate','replay','complete','final-complete','final-replay'] as $phase) {
    foreach (['verification','session','provider','network','unknown403','context'] as $kind) {
        [$csrf,$token,$snapshot]=mid_setup();
        $failure=match($kind) {'verification'=>response_fixture(403,'support_verification_required'),'session'=>response_fixture(403,'support_session_unavailable'),'provider'=>response_fixture(503,'support_provider_failed'),'network'=>new WP_Error('network_failed','Synthetic outage'),'unknown403'=>response_fixture(403,'support_access_denied'),'context'=>response_fixture(422,'support_context_invalid')};
        $expected=match($kind) {'verification'=>'support_verification_required','session'=>'support_session_inactive',default=>'support_unavailable'};
        $queue=mid_queue($phase,$snapshot,$failure);$GLOBALS['responses']=$queue;$result=mid_turn($csrf,$token);
        check(is_wp_error($result)&&$result->get_error_code()===$expected,'Midphase '.$phase.'/'.$kind.' preserves definitive signal and keeps transient failure generic.');
        $cancelled=array_values(array_filter($GLOBALS['requests'],fn($request)=>str_ends_with($request[1],'/cancel')));
        check(count($cancelled)==(in_array($phase,['facts','validate','replay'],true)?1:0),'Required cancellation placement/count is preserved before returning a midphase failure.');
        check(count($GLOBALS['requests'])===count($queue)&&$GLOBALS['responses']===[],'Actual raw HTTP fault path performs exactly its existing phase requests, no admission or inference retry.');
        check(!str_contains(json_encode($result),'PRIVATE_MIDPHASE_ANSWER')&&!str_contains(json_encode($result),'PRIVATE_CARD'),'A denied midphase response contains no private answer/card.');
        $midphase_results[]=['phase'=>$phase,'kind'=>$kind,'code'=>$result->get_error_code(),'status'=>$result->data['status']??null,'cancel_count'=>count($cancelled)];
    }
}
foreach (['facts','validate','replay'] as $phase) {
    foreach (['provider','session','local-key'] as $cleanup_kind) {
        [$csrf,$token,$snapshot]=mid_setup();$GLOBALS['responses']=mid_queue($phase,$snapshot,response_fixture(403,'support_verification_required'),true);
        if ($cleanup_kind==='session') { $GLOBALS['responses'][3]=response_fixture(403,'support_session_unavailable'); }
        if ($cleanup_kind==='local-key') { $GLOBALS['responses'][3]=static function(){YoRo_AI_Settings::$key='';return response_fixture(503,'support_provider_failed');}; }
        $result=mid_turn($csrf,$token);
        check(is_wp_error($result)&&$result->get_error_code()==='support_verification_required'&&$GLOBALS['responses']===[],'Cancellation failure or later local loss cannot erase the original definitive private denial.');
        YoRo_AI_Settings::$key='synthetic-server-only-key';
    }
}
foreach (['validate','completed'] as $status) {
    [$csrf,$token,$snapshot]=mid_setup();$completed=['status'=>200,'body'=>['status'=>'completed','snapshot'=>$snapshot,'answer'=>['text'=>'PRIVATE_MIDPHASE_ANSWER','sources'=>[]]]];
    $GLOBALS['responses']=[mid_auth_ok(),$status==='validate'?['status'=>200,'body'=>['status'=>'validate','snapshot'=>$snapshot]]:$completed,mid_auth_ok()];
    if ($status==='validate') { $GLOBALS['responses'][]=$completed; } $GLOBALS['responses'][]=mid_auth_ok();$result=mid_turn($csrf,$token);
    check($result instanceof WP_REST_Response&&$result->data['private']===true&&$result->data['answer']['text']==='PRIVATE_MIDPHASE_ANSWER','Unchanged successful completion/replay still returns only its revalidated private answer.');
    check($GLOBALS['responses']===[]&&count(array_filter($GLOBALS['requests'],fn($request)=>str_ends_with($request[1],'/turns')))===1,'Success performs one admitted turn and no duplicate inference.');
}
// Local settings change and unknown snapshot mismatch remain aborts; only known invalidation clears authority.
foreach (['settings','mismatch'] as $change) {
    [$csrf,$token,$snapshot]=mid_setup();$second=static function() use($change) { if($change==='settings'){$GLOBALS['support_fixture_settings']['revision']++;}else{$GLOBALS['mid_card_marker']='CHANGED_CARD';}return mid_auth_ok(); };
    $GLOBALS['responses']=[mid_auth_ok(),['status'=>200,'body'=>['status'=>'validate','snapshot'=>$snapshot]],$second,['status'=>200,'body'=>['status'=>'cancelled']]];$result=mid_turn($csrf,$token);
    check(is_wp_error($result)&&$result->get_error_code()===($change==='settings'?'support_session_inactive':'support_unavailable'),'Settings invalidation is definitive; unrelated changed snapshot remains generic and denied.');
    check($GLOBALS['responses']===[]&&str_ends_with($GLOBALS['requests'][3][1],'/cancel'),'Source/settings abort always performs its required cancellation.');
}
// A local credential disappearing inside a real HTTP callback is definitive, not a network outage.
foreach (['initial','facts','validate','complete','final'] as $phase) {
    [$csrf,$token,$snapshot]=mid_setup();$GLOBALS['trace_transport_attempts']=true;$GLOBALS['transport_attempts']=[];
    $completed=['status'=>200,'body'=>['status'=>'completed','snapshot'=>$snapshot,'answer'=>['text'=>'PRIVATE_MIDPHASE_ANSWER','sources'=>[]]]];
    $validate=['status'=>200,'body'=>['status'=>'validate','snapshot'=>$snapshot]];
    $lose=static function($response) { return static function() use($response) { YoRo_AI_Settings::$key='';return $response; }; };
    $GLOBALS['responses']=match($phase) {
        'initial'=>[$lose(mid_auth_ok())],
        'facts'=>[mid_auth_ok(),$lose(['status'=>200,'body'=>['status'=>'facts','query'=>'Synthetic refresh']])],
        'validate'=>[mid_auth_ok(),$lose($validate)],
        'complete'=>[mid_auth_ok(),$validate,mid_auth_ok(),$lose($completed)],
        'final'=>[mid_auth_ok(),$completed,mid_auth_ok(),$lose(mid_auth_ok())],
    };
    $expected_http=count($GLOBALS['responses']);$result=mid_turn($csrf,$token);
    check(is_wp_error($result)&&$result->get_error_code()==='support_session_inactive','Local blank-key loss at '.$phase.' remains definitive after the initial guard.');
    check(count($GLOBALS['requests'])===$expected_http&&$GLOBALS['responses']===[],'No credential-bearing HTTP is sent after a known local disconnect.');
    $cancel_attempts=array_filter($GLOBALS['transport_attempts'],fn($attempt)=>str_ends_with($attempt[1],'/cancel'));
    check(count($cancel_attempts)==(in_array($phase,['facts','validate'],true)?1:0),'Existing cancellation is attempted even when disconnected transport must refuse to send.');
    $GLOBALS['trace_transport_attempts']=false;YoRo_AI_Settings::$key='synthetic-server-only-key';
}
// Cleanup cannot discard a definitive signal first observed while cancelling an unrelated mismatch.
foreach (['session','verification','local-key'] as $cleanup_loss) {
    [$csrf,$token,$snapshot]=mid_setup();
    $change_card=static function() { $GLOBALS['mid_card_marker']='CHANGED_CARD';return mid_auth_ok(); };
    $cleanup=match($cleanup_loss) {
        'session'=>response_fixture(403,'support_session_unavailable'),
        'verification'=>response_fixture(403,'support_verification_required'),
        'local-key'=>static function() { YoRo_AI_Settings::$key='';return ['status'=>200,'body'=>['status'=>'cancelled']]; },
    };
    $GLOBALS['responses']=[mid_auth_ok(),['status'=>200,'body'=>['status'=>'validate','snapshot'=>$snapshot]],$change_card,$cleanup];$result=mid_turn($csrf,$token);
    $expected=$cleanup_loss==='verification'?'support_verification_required':'support_session_inactive';
    check(is_wp_error($result)&&$result->get_error_code()===$expected,'An existing cancel response/local state preserves definitive loss even when the original abort was only a mismatch.');
    check($GLOBALS['responses']===[]&&count($GLOBALS['requests'])===4&&str_ends_with($GLOBALS['requests'][3][1],'/cancel'),'Cleanup classification adds no cancel, admission or inference request.');
    YoRo_AI_Settings::$key='synthetic-server-only-key';
}
echo json_encode(['result'=>'passed','assertions'=>$count,'midphase_cases'=>$midphase_results,'native_wordpress'=>'NOT_EVALUATED'],JSON_THROW_ON_ERROR),"\n";
