<?php
if(!defined('ABSPATH')){exit(1);}
if(str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')){throw new RuntimeException('Disposable local WordPress only.');}
$assert=static function($ok,$message){if(!$ok){throw new RuntimeException($message);}};
$original=get_current_user_id();$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);wp_set_current_user((int)$admins[0]);
YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
add_filter('pre_wp_mail',static fn()=>true);add_filter('pre_http_request',static fn()=>new WP_Error('test_no_network','No network during local tests.'));
global $wpdb;
// Preserve this named draft fixture and its adjustment history, rather than deleting audit records.
$product=new WC_Product_Simple();$product->set_name('YR0178 stock concurrency '.wp_generate_uuid4());$product->set_status('draft');$product->set_manage_stock(true);$product->set_stock_quantity(10);$product->set_regular_price('10');$product->save();
$id=$product->get_id();wc_update_product_stock($id,10,'set');clean_post_cache($id);$product=wc_get_product($id);$params=['product_id'=>$id,'changes'=>['delta'=>5,'reason'=>'Local acceptance fixture received units'],'expected_stock_quantity'=>10,'expected_version'=>YoRo_Commerce_Discovery_Service::product_version($product),'adjustment_id'=>wp_generate_uuid4()];
$op=['action'=>YoRo_Stock_Adjustment::Action,'params'=>$params,'envelope_id'=>'local_fixture_'.wp_generate_uuid4(),'idempotency_key'=>wp_generate_uuid4(),'workflow_id'=>'workflow_stock_fixture','operation_id'=>'operation_stock_fixture','authorization'=>['execution_permit'=>true],'extensions'=>['yoro.mcp_early_access_commerce_write'=>['source_operation_id'=>'envelope_'.str_repeat('a',64),'source_result_hash'=>str_repeat('b',64),'expected_version'=>$params['expected_version']]]];
$check=YoRo_Stock_Adjustment::prepare($params);if(is_wp_error($check)){throw new RuntimeException($check->get_error_code().' '.wp_json_encode(['valid'=>YoRo_Stock_Adjustment::valid_params($params),'permissions'=>[current_user_can('manage_woocommerce'),current_user_can('yoro_execute_actions'),current_user_can('edit_post',$id)],'stock'=>$product->get_stock_quantity('edit'),'manage'=>$product->get_manage_stock('edit'),'owner'=>$product->get_stock_managed_by_id(),'class'=>$product->get_data_store()->get_current_class_name(),'same_version'=>YoRo_Commerce_Discovery_Service::product_version(wc_get_product($id))===$params['expected_version']]));}
$lockProof=null;
$race=static function($locked)use($id,&$lockProof,$assert,$wpdb){
 if($locked!==$id){return;}
 $script='$c=json_decode(stream_get_contents(STDIN),true);$db=new mysqli($c["host"],$c["user"],$c["password"],$c["database"],0,$c["socket"]);mysqli_report(MYSQLI_REPORT_OFF);$db->query("SET SESSION innodb_lock_wait_timeout=1");$ok=$db->query("UPDATE `".$c["table"]."` SET meta_value=meta_value-3 WHERE post_id=".(int)$c["id"]." AND meta_key=\'_stock\'");echo json_encode(["ok"=>$ok,"errno"=>$db->errno]);';
 $process=proc_open([PHP_BINARY,'-r',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process)){throw new RuntimeException('Concurrent MySQL process unavailable.');}
 fwrite($pipes[0],wp_json_encode(['host'=>DB_HOST,'user'=>DB_USER,'password'=>DB_PASSWORD,'database'=>DB_NAME,'socket'=>ini_get('mysqli.default_socket'),'table'=>$wpdb->postmeta,'id'=>$id]));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
 $assert($code===0&&$err==='','Native stock race process completes without exposing credentials.');$lockProof=json_decode($out,true,16,JSON_THROW_ON_ERROR);$assert($lockProof['errno']===1205&&$lockProof['ok']===false,'A second native stock UPDATE cannot pass the adjustment row lock.');
};
add_action('yoro_stock_adjustment_locked',$race);
$result=YoRo_Early_Access_Commerce_Write::execute($op,static fn()=>true);remove_action('yoro_stock_adjustment_locked',$race);
if(is_wp_error($result)){throw new RuntimeException($result->get_error_code());}
$assert($result['data']['stock_quantity']===15&&$result['data']['before_quantity']===10&&$lockProof['errno']===1205,'Signed stock change commits exactly once with the locked native race.');
$recovered=YoRo_Stock_Adjustment::recover($op);$assert(!is_wp_error($recovered)&&$recovered['data']===$result['data'],'A committed adjustment can be recovered after an interrupted outer journal.');
$repeat=YoRo_Stock_Adjustment::with_operation($op,static fn()=>true,static fn()=>YoRo_Stock_Adjustment::write([...$params,'_yoro_confirmed'=>true,'_yoro_preview'=>false]));$assert($repeat['data']===$result['data']&&wc_get_product($id)->get_stock_quantity()===15,'Exact local replay does not repeat the delta.');
$other=$op;$other['envelope_id'].='different';$assert(is_wp_error(YoRo_Stock_Adjustment::recover($other)),'A different envelope cannot adopt the existing adjustment.');
wc_update_product_stock($id,3,'decrease');$assert(wc_get_product($id)->get_stock_quantity()===12,'Native checkout updates continue after commit.');
$assert(is_wp_error(YoRo_Stock_Adjustment::prepare($params)),'A stale expected quantity/version cannot overwrite native stock changes.');
$history=YoRo_Commerce_Operations::execute(['action'=>YoRo_Commerce_Operations::ReadActions[1],'params'=>['product_id'=>$id,'before_id'=>0]]);
$assert(!is_wp_error($history)&&count($history['data']['rows'])===1&&$history['data']['rows'][0]['stock_quantity']===15,'History includes the YoRo adjustment and excludes native checkout movements.');
// Third-party constructors may contact providers. Stored-only diagnostics must not invoke them.
$constructors=0;
class YoRo_Diagnostic_Gateway_Trap extends WC_Payment_Gateway {public function __construct(){throw new RuntimeException('Gateway constructor must not run.');}}
class YoRo_Diagnostic_Shipping_Trap extends WC_Shipping_Method {public function __construct($instance_id=0){throw new RuntimeException('Shipping constructor must not run.');}}
$gateways=static fn($classes)=>[...$classes,'YoRo_Diagnostic_Gateway_Trap'];$shipping=static fn($classes)=>[...$classes,'yoro_diagnostic_trap'=>'YoRo_Diagnostic_Shipping_Trap'];
add_filter('woocommerce_payment_gateways',$gateways);add_filter('woocommerce_shipping_methods',$shipping);
$wpdb->query('START TRANSACTION');
$wpdb->insert($wpdb->prefix.'woocommerce_shipping_zone_methods',['zone_id'=>0,'method_id'=>'yoro_diagnostic_trap','method_order'=>99,'is_enabled'=>1]);
$wpdb->insert($wpdb->prefix.'woocommerce_shipping_zone_methods',['zone_id'=>0,'method_id'=>'flat_rate','method_order'=>98,'is_enabled'=>1]);$instance=(int)$wpdb->insert_id;
update_option('woocommerce_flat_rate_'.$instance.'_settings',['title'=>'Safe fixture method','cost'=>'10 + [fee percent="2"]','client_secret'=>'DO_NOT_EXPOSE_SECRET'],false);
update_option('woocommerce_bacs_settings',['title'=>'Safe fixture bank method','enabled'=>'yes','client_secret'=>'DO_NOT_EXPOSE_SECRET','account_number'=>'DO_NOT_EXPOSE_BANK'],false);
try {
foreach(array_slice(YoRo_Commerce_Operations::ReadActions,2)as$action){$data=YoRo_Commerce_Operations::execute(['action'=>$action,'params'=>[]]);if(is_wp_error($data)){throw new RuntimeException($action.': '.$data->get_error_code());}$assert($data['read_only']===true&&strlen(wp_json_encode($data))<49152&&!str_contains(wp_json_encode($data),'DO_NOT_EXPOSE'),'Stored configuration stays read-only, bounded and excludes secrets.');}
}finally{$wpdb->query('ROLLBACK');wp_cache_flush();remove_filter('woocommerce_payment_gateways',$gateways);remove_filter('woocommerce_shipping_methods',$shipping);}

$off=static fn()=>'no';add_filter('pre_option_woocommerce_manage_stock',$off);$current=wc_get_product($id);$disabled=[...$params,'expected_stock_quantity'=>12,'expected_version'=>YoRo_Commerce_Discovery_Service::product_version($current),'adjustment_id'=>wp_generate_uuid4()];$assert(is_wp_error(YoRo_Stock_Adjustment::prepare($disabled)),'Global stock off fails before mutation.');remove_filter('pre_option_woocommerce_manage_stock',$off);
foreach(['COMMIT','START TRANSACTION','ROLLBACK']as$command){
 clean_post_cache($id);$current=wc_get_product($id);$before=(int)$current->get_stock_quantity();$faultParams=[...$params,'changes'=>['delta'=>1,'reason'=>'Transaction fault fixture'],'expected_stock_quantity'=>$before,'expected_version'=>YoRo_Commerce_Discovery_Service::product_version($current),'adjustment_id'=>wp_generate_uuid4()];$faultOp=[...$op,'params'=>$faultParams,'envelope_id'=>'fault_'.wp_generate_uuid4()];
 $hook=static function()use($wpdb,$command){$wpdb->query($command);};add_action('woocommerce_product_set_stock',$hook,99);$suppressed=$wpdb->suppress_errors(true);
 try{$fault=YoRo_Stock_Adjustment::with_operation($faultOp,static fn()=>true,static fn()=>YoRo_Stock_Adjustment::write([...$faultParams,'_yoro_confirmed'=>true,'_yoro_preview'=>false]));}finally{remove_action('woocommerce_product_set_stock',$hook,99);$wpdb->suppress_errors($suppressed);}
 $assert(is_wp_error($fault)&&$fault->get_error_code()==='local_execution_outcome_unknown','A hook that replaces the original transaction never returns success.');
 $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.YoRo_DB::stock_adjustments_table_name().' WHERE adjustment_id=%s',$faultParams['adjustment_id']));$assert($count===0,'An interrupted transaction is not represented as a completed adjustment.');wc_update_product_stock($id,12,'set');
}
wp_set_current_user(0);$assert(is_wp_error(YoRo_Commerce_Operations::execute(['action'=>YoRo_Commerce_Operations::ReadActions[1],'params'=>['product_id'=>$id,'before_id'=>0]])),'History requires current store permissions.');wp_set_current_user($original);
echo wp_json_encode(['suite'=>'commerce_operations','result'=>'PASS','fixture_product_id'=>$id,'native_stock_lock_errno'=>$lockProof['errno'],'once_only_delta'=>5,'final_stock_after_native_update'=>12,'history_scope'=>'yoro_adjustments_only','stored_configuration_reads'=>3,'constructor_trap'=>'PASS','secrets_excluded'=>true,'transaction_faults'=>['COMMIT','START TRANSACTION','ROLLBACK'],'global_stock_off'=>'denied']).PHP_EOL;
