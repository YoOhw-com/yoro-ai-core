<?php
/** Native disposable MySQL/WP/Woo runner proof. Never a canary/production harness. */

/** Separate connections prove the production SQL with only disposable credentials over stdin. */
if (($argv[1] ?? '') === 'mysql-worker') {
    define('ABSPATH', __DIR__); define('ARRAY_A', 'ARRAY_A');
    function wp_json_encode($value) { return json_encode($value); }
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    mysqli_report(MYSQLI_REPORT_OFF);
    class YoRo_Yr0196_Disposable_Mysqli {
        public string $last_error=''; public string $options; private mysqli $db;
        public function __construct(private array $input) {
            $host=$input['host'];$port=3306;$socket=null;
            if(preg_match('/\\A(.+):([0-9]+)\\z/D',$host,$match)){$host=$match[1];$port=(int)$match[2];}
            elseif(str_contains($host,':/')){[$host,$socket]=explode(':',$host,2);}
            $this->db=new mysqli($host,$input['user'],$input['password'],$input['database'],$port,$socket);
            if($this->db->connect_errno){exit(80);}
            $this->options=$input['table'];if(!preg_match('/\\A[A-Za-z0-9_]+\\z/D',$this->options)){exit(81);}
        }
        public function suppress_errors($value){return false;}
        public function prepare($sql,...$values){foreach($values as$value){$sql=preg_replace('/%s/',"'".$this->db->real_escape_string($value)."'",$sql,1);}return $sql;}
        public function query($sql){
            $result=$this->db->query($sql);$this->last_error=$this->db->error;
            if($result===false){return false;}
            if(str_starts_with($sql,'INSERT')&&$this->input['mode']==='crash'){exit(77);}
            return $this->db->affected_rows;
        }
        public function get_var($sql){$result=$this->db->query($sql);$this->last_error=$this->db->error;return $result instanceof mysqli_result?($result->fetch_row()[0]??null):null;}
        public function get_row($sql,$format){$result=$this->db->query($sql);$this->last_error=$this->db->error;return $result instanceof mysqli_result?$result->fetch_assoc():null;}
    }
    $GLOBALS['wpdb']=new YoRo_Yr0196_Disposable_Mysqli($input);
    require dirname(__DIR__,2).'/includes/commerce/class-yoro-yr0196-canary-context.php';
    try{(new ReflectionMethod(YoRo_YR0196_Canary_Context::class,'consume'))->invoke(null,['id'=>$input['permit_id']],['fixture'=>'process-durable-proof']);echo 'stored';}
    catch(Throwable $error){echo 'denied';}
    exit;
}

if (!defined('ABSPATH')) { exit(1); }
if (!class_exists('WooCommerce')) { echo "YR-0196 owning WooCommerce proof requires the disposable Woo job.\n"; return; }
if (str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')) { throw new RuntimeException('Disposable host required.'); }
if (!class_exists('YoRo_Connector_Runtime')) { YoRo_AI_Core::init(); }
$checks=0;$assert=static function($ok,$why)use(&$checks){++$checks;if(!$ok){throw new RuntimeException($why);}};
require_once ABSPATH.WPINC.'/PHPMailer/PHPMailer.php';require_once ABSPATH.WPINC.'/PHPMailer/Exception.php';require_once ABSPATH.WPINC.'/class-wp-phpmailer.php';
class YoRo_Yr0196_No_Transport extends WP_PHPMailer {
    public int $attempts=0;
    public function send(){++$this->attempts;throw new RuntimeException('No real transport is available in this disposable proof.');}
}
global $wpdb,$phpmailer;
$originalUser=get_current_user_id();$originalMailer=$phpmailer;$phpmailer=new YoRo_Yr0196_No_Transport(true);$sentinel=$phpmailer;
$options=[YoRo_AI_Settings::OPTION_AI_API_URL,YoRo_AI_Settings::OPTION_AI_API_SITE_KEY,YoRo_AI_Settings::OPTION_AI_API_STATUS,YoRo_Connector_Snapshot_Publisher::OPTION_MANIFEST_STATE];
$originalOptions=[];foreach($options as$key){$originalOptions[$key]=get_option($key,null);}
$block=static function(){throw new RuntimeException('No external HTTP in a disposable verification contract.');};
add_filter('pre_http_request',$block);
$orders=[];$lock=null;$permitIds=[];$journalOperations=[];
$acquire=new ReflectionMethod(YoRo_Connector_Runtime::class,'acquire_lock');$release=new ReflectionMethod(YoRo_Connector_Runtime::class,'release_lock');
try {
    wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
    YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    $subject=YoRo_WordPress_Actor::current_payload()['subject'];$installation=YoRo_AI_Settings::get_installation_uuid();$expires=gmdate('c',time()+300);$run=bin2hex(random_bytes(16));
    $encrypt=new ReflectionMethod(YoRo_AI_Settings::class,'encrypt_ai_api_site_key');$key='yr0196-disposable-key';
    update_option(YoRo_AI_Settings::OPTION_AI_API_URL,'https://r2-api.yorohq.com/api/v1',false);
    update_option(YoRo_AI_Settings::OPTION_AI_API_SITE_KEY,$encrypt->invoke(null,$key),false);
    $projection=['status'=>'available','source'=>'api_handshake','site_id'=>1,'installation_uuid'=>$installation,'connection_healthy'=>true,'compatible'=>true,'checked_at'=>gmdate('c'),'expires_at'=>$expires,
        'future_endpoint'=>'https://r2-api.yorohq.com/mcp/early-access','runtime_enabled'=>true,'server_url'=>'https://r2-api.yorohq.com/mcp/early-access','grant_status'=>'granted','confirmed_commerce_writes_enabled'=>true];
    $status=['organization'=>['id'=>1],'account_connection'=>['connected'=>true,'status'=>'active'],'site'=>['id'=>1,'status'=>'active','url'=>home_url()],
        'connection'=>['id'=>1,'installation_uuid'=>$installation,'status'=>'active','environment'=>'staging','plugin_version'=>YORO_AI_CORE_VERSION],
        'credential'=>['id'=>1,'type'=>'wordpress_connection','site_connection_id'=>1,'expires_at'=>$expires],
        'connector_runtime'=>['connector_key'=>'woocommerce','connector_connection_id'=>'yr0196-disposable','execution_mode'=>'remote_agent','protocol_version'=>1,'delivery_protocol'=>'pull_v1','connector_version'=>YORO_AI_CORE_VERSION,
            'capability_manifest'=>['current'=>true,'manifest_id'=>'yr0196-manifest','revision'=>1,'expires_at'=>$expires],'cutover'=>['cutover_allowed'=>false]],
        'early_access_credential_fingerprint'=>hash('sha256',$key),'early_access_onboarding'=>$projection];
    YoRo_AI_Settings::update_ai_api_status($status);update_option(YoRo_Connector_Snapshot_Publisher::OPTION_MANIFEST_STATE,['manifest_id'=>'yr0196-manifest','revision'=>1,'expires_at'=>$expires]);
    $assert(YoRo_Connector_Runtime::early_access_write_allowed()&&!YoRo_Connector_Runtime::live_cutover_allowed(),'Only the existing bounded delivery lane is active.');
    $authority=['profile'=>'early_access','resource'=>$projection['server_url'],'binding_version'=>2,'oauth_client_id'=>'yr0196-client','grant_id'=>'yr0196-grant','grant_version'=>1,'token_id'=>'yr0196-token','user_id'=>101,'site_id'=>1,'wordpress_identity_id'=>9,
        'connection_id'=>1,'installation_uuid'=>$installation,'site_key_id'=>1,'site_key_hash'=>hash('sha256',$key),'manifest_id'=>'yr0196-manifest','manifest_revision'=>1,'manifest_hash'=>str_repeat('a',64),
        'actor_role'=>'store_admin','actor_capabilities_hash'=>str_repeat('b',64),'resource_scopes_hash'=>str_repeat('c',64)];
    // Fixture creation is shielded separately. No pre_wp_mail hook exists when the source seam runs.
    add_filter('pre_wp_mail','__return_true');
    for($i=0;$i<6;$i++){
        $order=new WC_Order();$order->set_status($i<2?'processing':'on-hold');$order->set_billing_email('buyer@example.invalid');$order->set_currency('USD');$order->set_total('12.30');
        $order->update_meta_data(YoRo_YR0196_Canary_Context::Marker,['task_id'=>'YR-0196','run_id'=>$run,'owner_user_id'=>101,'site_id'=>1,'wordpress_identity_id'=>9]);$order->save();$orders[]=$order->get_id();
    }
    remove_filter('pre_wp_mail','__return_true');
    $make=static function(string $action,array $params)use($authority,$subject,$expires){
        $identity=hash('sha256',wp_generate_uuid4());$version=$params['expected_version']??($params['expected_versions'][0]['expected_version']??YoRo_Order_Batch::version($params));
        return ['contract_type'=>'yoro.commerce.operation_envelope','schema_version'=>1,'envelope_id'=>'envelope_'.$identity,'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1','position'=>1,'connector_key'=>'woocommerce','connector_connection_id'=>'yr0196-disposable',
            'manifest_id'=>'yr0196-manifest','manifest_revision'=>1,'idempotency_key'=>'operation_'.$identity,'execution_mode'=>'remote_agent','action'=>$action,'params'=>$params,
            ...(in_array($action,YoRo_Order_Email::WriteActions,true)?['user_content_slots'=>YoRo_Order_Email::slots($params)]:[]),
            'actor'=>['contract_type'=>'yoro.commerce.actor_context','schema_version'=>1,'actor_type'=>'user','subject'=>'fixture-identity','operator_id'=>'fixture-operator','workspace_id'=>'1','connector_connection_id'=>'yr0196-disposable','role'=>'store_admin','permissions'=>['yoro_execute_actions'],
                'authenticated_at'=>gmdate('c'),'assertion_id'=>'fixture-assertion','extensions'=>['wordpress.identity'=>['subject_version'=>1,'wordpress_subject'=>$subject]]],
            'plan_hash'=>str_repeat('a',64),'preview_hash'=>str_repeat('b',64),'authorization'=>['execution_permit'=>'permit_'.str_repeat('a',64),'permit_expires_at'=>$expires],
            'requested_at'=>gmdate('c'),'expires_at'=>$expires,'trace_id'=>'trace_'.$identity,
            'extensions'=>['yoro.mcp_early_access_commerce_write'=>[...$authority,'version'=>1,'action'=>$action,'source_operation_id'=>'envelope_'.str_repeat('d',64),'source_result_hash'=>str_repeat('e',64),'expected_version'=>$version,'params_hash'=>YoRo_Early_Access_Product_Read::params_hash($params)]]];
    };
    $permits=[];$mailOperations=[];$deadline=time()+600;
    $bind=static function(array $op,string $mode,array $targets,?array $mail=null)use(&$permits,&$permitIds,$run,$deadline){
        $hash=YoRo_YR0196_Canary_Context::operation_hash($op);$id=hash('sha256',$hash.'|'.$run);$permit=['id'=>$id,'operation_hash'=>$hash,'target_ids'=>$targets,'mode'=>$mode];if($mail!==null){$permit['mail']=$mail;}
        $permits[]=$permit;$permitIds[]=$id;
        $op['extensions']['yoro.mcp_early_access_commerce_write']['yr0196_canary']=['version'=>1,'task_id'=>'YR-0196','run_id'=>$run,'permit_id'=>$id,'operation_hash'=>$hash,'mode'=>$mode,'expires_at'=>$deadline,'permit_hash'=>YoRo_Early_Access_Product_Read::params_hash($permit),
            'envelope_id'=>$op['envelope_id'],'params_hash'=>YoRo_Early_Access_Product_Read::params_hash($op['params'])];
        return $op;
    };
    foreach(['orders.send_authored_email','orders.send_native_email']as$index=>$action){
        $source=YoRo_Order_Email::context($orders[$index],$index===1?['template'=>'processing']:[]);
        $params=['order_id'=>$orders[$index],'recipient'=>$source['recipient'],'expected_version'=>$source['version'],'changes'=>$index===0?['body'=>'Exact private body','subject'=>'Private fixture']:['template'=>'processing'],
            ...($index===1?['subject'=>$source['subject'],'html_body'=>$source['html_body']]:[])];
        $prepared=YoRo_Order_Email::prepare($params,$action);$assert(!is_wp_error($prepared),'Both final renderings pass existing content guards.');
        $mail=['recipient'=>$source['recipient'],'subject_hash'=>hash('sha256',$params['subject']??$params['changes']['subject']),'body_hash'=>hash('sha256',$index===1?$source['html_body']:$prepared['authored']['html_body']),
            'from_address'=>$source['settings']['from_address'],'from_name_hash'=>hash('sha256',$source['settings']['from_name']),'content_type'=>'text/html'];
        $mailOperation=$make($action,$params);
        $mailOperation['extensions']['yoro.mcp_early_access_commerce_write']['yr0196_preview']=['version'=>1,'task_id'=>'YR-0196','run_id'=>$run,'mode'=>'capture','expires_at'=>$deadline,'params_hash'=>YoRo_Early_Access_Product_Read::params_hash($params)];
        $mailOperations[]=$bind($mailOperation,'capture',[$orders[$index]],$mail);
    }
    $items=[];foreach(array_slice($orders,2,3)as$id){$items[]=['order_id'=>$id,'expected_version'=>YoRo_Order_Presenter::order_version(wc_get_order($id)),'status'=>'pending'];}
    $batch=$bind($make(YoRo_Order_Batch::Action,['items'=>$items]),'batch',array_column($items,'order_id'));
    foreach($items as$index=>$item){$child=YoRo_Order_Batch::child_operation($batch,$index);$hash=YoRo_YR0196_Canary_Context::operation_hash($child);$id=hash('sha256',$hash.'|'.$run);$permitIds[]=$id;$permits[]=['id'=>$id,'operation_hash'=>$hash,'target_ids'=>[$item['order_id']],'mode'=>$index===1?'fault':'observe'];}
    $deniedId=$orders[5];$deniedParams=['order_ids'=>[$deniedId],'status'=>'pending','expected_versions'=>[['order_id'=>$deniedId,'expected_version'=>YoRo_Order_Presenter::order_version(wc_get_order($deniedId))]]];
    $deniedOperation=$make('orders.update_status',$deniedParams);
    define('YORO_YR0196_CANARY',['version'=>1,'enabled'=>true,'task_id'=>'YR-0196','run_id'=>$run,'expires_at'=>$deadline,'authority'=>$authority,'permits'=>$permits]);
    $assert((YoRo_Order_Email::manifest()['private_canary']??null)===['version'=>1,'task_id'=>'YR-0196','run_id'=>$run,'expires_at'=>$deadline],'Only an enabled private v1 task publishes its exact readiness marker.');
    $client=new class extends YoRo_Connector_Client {
        public array $operation=[];public array $results=[];
        public function early_access_has_budget($requests=4,$continuation=false){return true;}
        public function early_access_write_request($step,$id='',$payload=[],$token='',$generation=0){
            if($step==='claim'){return ['success'=>true,'delivery'=>['delivery_id'=>'yr0196-fixture-delivery','claim_token'=>str_repeat('a',80),'lease_generation'=>1,'operations'=>[$this->operation]]];}
            if($id!==$this->operation['envelope_id']||$token!==str_repeat('a',80)||$generation!==1){throw new RuntimeException('Exact fenced delivery required.');}
            if($step==='result'){$this->results[]=$payload;}return ['success'=>true];
        }
    };
    $lock=$acquire->invoke(null);$assert($lock!==null,'Native runner holds the database session fence.');$runtime=new YoRo_Connector_Runtime($client);
    foreach($mailOperations as$op){
        $client->operation=$op;$journalOperations[]=$op;$r=$runtime->run_early_access_write_once();$last=end($client->results);
        $assert(!is_wp_error($r)&&($last['result']['data']['outcome']??null)==='captured_not_delivered','Actual runner stores truthful private capture: '.wp_json_encode($last));
        $captureId=$last['result']['data']['record_id'];$row=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",'yoro_yr0196_permit_'.substr($captureId,8)));
        $saved=json_decode($row,true);$assert(($saved['mail']['recipient']??null)==='buyer@example.invalid'&&$saved['operation_hash']===YoRo_YR0196_Canary_Context::operation_hash($op),'Native database contains exact private capture and consumed permit together.');
        $runtime->run_early_access_write_once();$assert(end($client->results)===$last,'Completed journal replays without recapture.');
    }
    $client->operation=$batch;$journalOperations[]=$batch;foreach($items as$i=>$item){$journalOperations[]=YoRo_Order_Batch::child_operation($batch,$i);}
    $runtime->run_early_access_write_once();$last=end($client->results);$rows=$last['result']['data']['items']??[];
    $assert(array_column($rows,'status')===['updated','unknown','not_attempted'],'A genuine second child effect interrupts before completion, preserving partial results: '.wp_json_encode($last));
    $assert(wc_get_order($items[0]['order_id'])->get_status()==='pending'&&wc_get_order($items[1]['order_id'])->get_status()==='pending'&&wc_get_order($items[2]['order_id'])->get_status()==='on-hold','Native effects actually persisted before the fault; later child stayed untouched.');
    $runtime->run_early_access_write_once();$assert(end($client->results)===$last,'Unknown child and parent journal replay never repeats the effect.');
    $client->operation=$deniedOperation;$journalOperations[]=$deniedOperation;$runtime->run_early_access_write_once();
    $assert((end($client->results)['error']['code']??null)==='local_execution_outcome_unknown'&&wc_get_order($deniedId)->get_status()==='on-hold','Owned marker without a permit cannot fall through to the mutator.');
    $slot=new ReflectionProperty(YoRo_Connector_Runtime::class,'canary_context');$assert($slot->getValue()===null,'Runner always clears private context after success and exceptions.');
    // Exercise the actual MySQL transaction guard independently of journals (which must never be reset).
    $context=YoRo_YR0196_Canary_Context::admit($mailOperations[0],get_current_user_id(),static fn()=>true);$context->before($mailOperations[0]);
    $capture=new ReflectionMethod(YoRo_YR0196_Canary_Context::class,'consume');$fresh=['id'=>hash('sha256',$run.'outer-transaction')];$permitIds[]=$fresh['id'];
    $wpdb->query('START TRANSACTION');$outerDenied=false;$callerKey='yoro_yr0196_caller_'.$run;
    $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",$callerKey,'uncommitted')); 
    try{$capture->invoke(null,$fresh,['fixture'=>'outer-transaction']);}catch(Throwable $error){$outerDenied=true;}finally{$wpdb->query('ROLLBACK');}
    $assert($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",$callerKey))===null,'The guard never committed unrelated caller transaction data.');
    $assert($outerDenied&&$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",'yoro_yr0196_permit_'.$fresh['id']))===null,'Native MySQL outer transaction is rejected without inserting a permit or committing caller data.');

    $workerPath=dirname((new ReflectionClass(YoRo_Order_Email::class))->getFileName(),3).'/tests/smoke/yr0196-canary-context-contract.php';
    $startWorker=static function(string $id,string $mode='normal')use($wpdb,$workerPath){
        $pipes=[];$process=proc_open([PHP_BINARY,$workerPath,'mysql-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)){throw new RuntimeException('Disposable process execution is unavailable.');}
        fwrite($pipes[0],json_encode(['host'=>DB_HOST,'user'=>DB_USER,'password'=>DB_PASSWORD,'database'=>DB_NAME,'table'=>$wpdb->options,'permit_id'=>$id,'mode'=>$mode],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        return [$process,$pipes];
    };
    $finishWorker=static function(array $job){[$process,$pipes]=$job;$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($code!==0&&$code!==77){throw new RuntimeException('Disposable MySQL worker failed: '.$code.' '.$errors);}return [$code,$output];};
    $contended=hash('sha256',$run.'native-contention');$permitIds[]=$contended;
    $first=$startWorker($contended);$second=$startWorker($contended);[, $one]=$finishWorker($first);[, $two]=$finishWorker($second);
    $assert(($one==='stored'&&$two==='denied')||($one==='denied'&&$two==='stored'),'Native MySQL connections contend for exactly one durable insert.');
    [, $again]=$finishWorker($startWorker($contended));$assert($again==='denied','New MySQL process cannot reuse consumed permission.');
    $interrupted=hash('sha256',$run.'native-crash');$permitIds[]=$interrupted;
    [$crashCode]=$finishWorker($startWorker($interrupted,'crash'));$assert($crashCode===77,'Native process exits immediately after committed insert, before source readback.');
    [, $again]=$finishWorker($startWorker($interrupted));$assert($again==='denied','Native committed consumption survives process crash and restart.');

    $assert($sentinel->attempts===0&&false===has_filter('pre_wp_mail'),'Neither authored nor native capture used transport or a pre-mail interception hook.');
    echo 'YR-0196 native MySQL/WP/Woo runner passed ('.$checks.' assertions, zero mail/network).'."\n";
} finally {
    if($lock!==null){$release->invoke(null,$lock);}remove_filter('pre_http_request',$block);
    add_filter('pre_wp_mail','__return_true');foreach($orders as$id){$order=wc_get_order($id);if($order){$order->delete(true);}}remove_filter('pre_wp_mail','__return_true');
    foreach($permitIds as$id){delete_option('yoro_yr0196_permit_'.$id);}
    foreach($journalOperations as$op){YoRo_Connector_Execution_Journal::clear($op);}
    foreach($originalOptions as$key=>$value){if($value===null){delete_option($key);}else{update_option($key,$value,false);}}
    wp_set_current_user($originalUser);$phpmailer=$originalMailer;wp_cache_flush();
}
