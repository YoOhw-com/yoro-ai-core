<?php
/** Disposable-only witness of full coupon claim validation, fenced execution and provenance rejection. */
if (!defined('ABSPATH') || !class_exists('WooCommerce')) { throw new RuntimeException('Disposable WooCommerce runtime required.'); }
if (str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')) { throw new RuntimeException('Never run transport fixtures on canary or production.'); }
$assert=static function($condition,$message){if(!$condition){throw new RuntimeException($message);}};
$original=get_current_user_id();$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
$block=static function(){throw new RuntimeException('The disposable coupon transport must not make network requests.');};
$client=new class extends YoRo_Connector_Client {
    public $operation; public $steps=[]; public $results=[];
    public function early_access_request($step,$id='',$payload=[],$token='',$generation=0) {
        $this->steps[]=$step;
        if($step==='claim'){return ['success'=>true,'delivery'=>['delivery_id'=>'coupon-fixture-delivery','claim_token'=>str_repeat('a',80),'lease_generation'=>1,'operations'=>[$this->operation]]];}
        if($token!==str_repeat('a',80)||$generation!==1||$id!==$this->operation['envelope_id']){throw new RuntimeException('Coupon lease binding was lost.');}
        if($step==='result'){$this->results[]=$payload;}
        return ['success'=>true];
    }
};
$acquire=new ReflectionMethod(YoRo_Connector_Runtime::class,'acquire_lock');
$release=new ReflectionMethod(YoRo_Connector_Runtime::class,'release_lock');
$validate=new ReflectionMethod(YoRo_Connector_Runtime::class,'validate_early_access_operation');
$lock=null;global $wpdb;$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int)$admins[0]);
    YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data']]);
    $subject=YoRo_WordPress_Actor::current_payload()['subject'];$installation=YoRo_AI_Settings::get_installation_uuid();$expires=gmdate('c',time()+300);
    $key='disposable-coupon-transport-key';$encrypt=new ReflectionMethod(YoRo_AI_Settings::class,'encrypt_ai_api_site_key');
    update_option(YoRo_AI_Settings::OPTION_AI_API_URL,'https://r2-api.yorohq.com/api/v1',false);
    update_option(YoRo_AI_Settings::OPTION_AI_API_SITE_KEY,$encrypt->invoke(null,$key),false);
    YoRo_AI_Settings::update_ai_api_status(['organization'=>['id'=>1],'account_connection'=>['connected'=>true,'status'=>'active'],
        'site'=>['id'=>1,'status'=>'active','url'=>home_url()],
        'connection'=>['id'=>1,'installation_uuid'=>$installation,'status'=>'active','environment'=>'staging','plugin_version'=>YORO_AI_CORE_VERSION],
        'credential'=>['id'=>1,'type'=>'wordpress_connection','site_connection_id'=>1,'expires_at'=>$expires],
        'connector_runtime'=>['connector_key'=>'woocommerce','connector_connection_id'=>'coupon-fixture','execution_mode'=>'remote_agent','protocol_version'=>1,
            'delivery_protocol'=>'pull_v1','connector_version'=>YORO_AI_CORE_VERSION,'capability_manifest'=>['current'=>true,'manifest_id'=>'coupon-manifest','revision'=>1,'expires_at'=>$expires],'cutover'=>['cutover_allowed'=>false]],
        'early_access_credential_fingerprint'=>hash('sha256',$key),
        'early_access_onboarding'=>['status'=>'available','source'=>'api_handshake','site_id'=>1,'installation_uuid'=>$installation,'connection_healthy'=>true,'compatible'=>true,
            'checked_at'=>gmdate('c'),'expires_at'=>$expires,'future_endpoint'=>'https://r2-api.yorohq.com/mcp/early-access','runtime_enabled'=>true,
            'server_url'=>'https://r2-api.yorohq.com/mcp/early-access','grant_status'=>'granted']]);
    update_option(YoRo_Connector_Snapshot_Publisher::OPTION_MANIFEST_STATE,['manifest_id'=>'coupon-manifest','revision'=>1,'expires_at'=>$expires]);
    $assert(YoRo_Connector_Runtime::early_access_read_allowed()&&!YoRo_Connector_Runtime::live_cutover_allowed(),'Only the bounded read lane is available.');
    $coupon=new WC_Coupon();$coupon->set_code('yr0178-claim-'.wp_generate_uuid4());$coupon->set_status('publish');$coupon->set_discount_type('percent');$coupon->set_amount('10');$coupon->save();
    $before=YoRo_Coupon_Management::version(new WC_Coupon($coupon->get_id()));
    add_filter('pre_http_request',$block);$lock=$acquire->invoke(null);$assert($lock!==null,'The existing runner lock is required.');
    $runtime=new YoRo_Connector_Runtime($client);
    $export=[];
    foreach(['coupons.search'=>['code'=>$coupon->get_code(),'page'=>1],'coupons.get_details'=>['coupon_id'=>$coupon->get_id()]] as $action=>$params){
        $identity=hash('sha256',wp_generate_uuid4());
        $operation=['contract_type'=>'yoro.commerce.operation_envelope','schema_version'=>1,'envelope_id'=>'envelope_'.$identity,
            'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1','position'=>1,'connector_key'=>'woocommerce','connector_connection_id'=>'coupon-fixture',
            'manifest_id'=>'coupon-manifest','manifest_revision'=>1,'idempotency_key'=>'operation_'.$identity,'execution_mode'=>'remote_agent','action'=>$action,'params'=>$params,
            'actor'=>['contract_type'=>'yoro.commerce.actor_context','schema_version'=>1,'actor_type'=>'user','subject'=>'fixture-identity','operator_id'=>'fixture-operator','workspace_id'=>'1',
                'connector_connection_id'=>'coupon-fixture','role'=>'store_admin','permissions'=>['yoro_view_store_data'],'authenticated_at'=>gmdate('c'),'assertion_id'=>'fixture-assertion',
                'extensions'=>['wordpress.identity'=>['subject_version'=>1,'wordpress_subject'=>$subject]]],
            'plan_hash'=>str_repeat('a',64),'requested_at'=>gmdate('c'),'expires_at'=>$expires,'trace_id'=>'trace_'.$identity,
            'extensions'=>['yoro.mcp_early_access_product_read'=>['version'=>1,'action'=>$action,'read_contract_version'=>1,'read_family'=>'coupons',
                'params_hash'=>YoRo_Early_Access_Product_Read::params_hash($params),'resource'=>'https://r2-api.yorohq.com/mcp/early-access','profile'=>'early_access','binding_version'=>2,
                'installation_uuid'=>$installation,'site_id'=>1,'site_key_id'=>1]]];
        $assert(true===YoRo_Commerce_Contract_Validator::validate_operation_envelope($operation),'The full coupon envelope passes canonical shape validation.');
        $valid=$validate->invoke($runtime,$operation);
        $assert(!is_wp_error($valid)&&$valid['user_id']===(int)$admins[0],'Full coupon runtime authority accepts its explicit family: '.(is_wp_error($valid)?$valid->get_error_code():''));
        foreach([null,'orders','unknown'] as $family){
            $bad=$operation;
            if($family===null){unset($bad['extensions']['yoro.mcp_early_access_product_read']['read_family']);}
            else{$bad['extensions']['yoro.mcp_early_access_product_read']['read_family']=$family;}
            $assert(is_wp_error($validate->invoke($runtime,$bad)),'Absent or foreign coupon family cannot cross full runtime validation.');
            $client->operation=$bad;$client->steps=[];$client->results=[];$denied=$runtime->run_early_access_once();
            $assert(is_wp_error($denied)&&$client->steps===['claim']&&$client->results===[],'Invalid coupon family stops before acknowledgement, execution or publication.');
        }
        $client->operation=$operation;$client->steps=[];$client->results=[];$result=$runtime->run_early_access_once();
        $assert(!is_wp_error($result)&&($result['success']??false),'The claimed coupon read completes the real runner: '.(is_wp_error($result)?$result->get_error_code():''));
        $assert($client->steps===['claim','ack','lease','result']&&count($client->results)===1&&$client->results[0]['status']==='executed','Coupon claim uses the existing fenced transport.');
        $assert((array)$client->results[0]['result']['extensions']===[],'Coupon publication excludes WordPress execution internals.');
        $data=$client->results[0]['result']['data'];
        $assert(($action==='coupons.search'?$data['coupons'][0]['id']:$data['coupon']['id'])===$coupon->get_id(),'The requested coupon family result reaches publication.');
        $export[]=['action'=>$action,'params'=>$params,'wire'=>$client->results[0]];
    }
    $assert(YoRo_Coupon_Management::version(new WC_Coupon($coupon->get_id()))===$before,'Coupon read transport leaves business data unchanged.');
    $export_path=getenv('YORO_COUPON_RESULT_EXPORT');
    if(is_string($export_path)&&$export_path!==''){file_put_contents($export_path,wp_json_encode($export,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
    echo "Coupon full-envelope runtime validation, family provenance and fenced claim-ack-lease-result passed.\n";
} finally {
    remove_filter('pre_http_request',$block);if($lock!==null){$release->invoke(null,$lock);}$wpdb->query('ROLLBACK');wp_cache_flush();wp_set_current_user($original);
}
