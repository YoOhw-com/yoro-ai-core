<?php
/** SOURCE_TEST: real scope guard and email preparation; downstream WordPress/mail are disposable fixtures. */
if (!class_exists('YoRo_Order_Email')) {
    ob_start(); require __DIR__.'/order-email-test.php'; ob_end_clean();
}
foreach (['catalog-management','product-authoring','fulfillment-management','order-edit','product-reviews','product-lifecycle','order-batch','order-management','product-batch','coupon-management','variable-product'] as $component) {
    require_once dirname(__DIR__,2).'/includes/actions/class-yoro-'.$component.'.php';
}
require_once dirname(__DIR__,2).'/includes/commerce/class-yoro-stock-adjustment.php';
require_once dirname(__DIR__,2).'/includes/commerce/class-yoro-early-access-commerce-write.php';
if (defined('YR0207_SCOPE_BOOTSTRAP_ONLY')) { return; }
function sanitize_textarea_field($value) {return trim(strip_tags($value));}

$GLOBALS['order']=new WC_Order(); $GLOBALS['order']->recipient='buyer@example.invalid';
$GLOBALS['wc']=new FixtureWoo(); $GLOBALS['allowed']=true; $GLOBALS['captured']=[];
unset($GLOBALS['wrapper_footer']);
if (in_array($argv[1]??'',['context-native','context-authored'],true)) {
    echo json_encode(YoRo_Order_Email::context(1,$argv[1]==='context-native'?['template'=>'processing']:[]),JSON_THROW_ON_ERROR),"\n";
    exit;
}
$guard=static function(array $operation): array {
    $before=count($GLOBALS['captured']); $fences=0;
    $result=YoRo_Early_Access_Commerce_Write::execute($operation,static function()use(&$fences){++$fences;return false;});
    return ['error'=>is_wp_error($result)?$result->code:null,'fences'=>$fences,'mail_calls'=>count($GLOBALS['captured'])-$before,
        'notes'=>count($GLOBALS['order']->notes),'history'=>count($GLOBALS['order']->meta['_yoro_observed_order_mail']??[])];
};
if (($argv[1]??'')==='guard') {
    $operation=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    echo json_encode($guard($operation),JSON_THROW_ON_ERROR),"\n";
    exit;
}
$checks=0;
$assert=static function($condition,$why)use(&$checks){++$checks;if(!$condition){throw new RuntimeException($why);}};
$operation=static function(string $action,array $changes)use($make): array {
    $flags=$make($action,$changes);
    $params=array_diff_key($flags,array_flip(['_yoro_confirmed','_yoro_preview']));
    return ['action'=>$action,'params'=>$params,'authorization'=>['execution_permit'=>'source-test-permit'],
        'extensions'=>['yoro.mcp_early_access_commerce_write'=>['expected_version'=>$params['expected_version'],
            'source_operation_id'=>'envelope_'.str_repeat('d',64),'source_result_hash'=>str_repeat('e',64)]]];
};
$verify=static function(array $op,string $error,string $label)use($guard,$assert): void {
    $r=$guard($op);
    $assert($r['error']===$error&&$r['fences']===(int)($error==='yoro_connector_runner_lock_lost'),$label.': '.json_encode($r));
    $assert($r['mail_calls']===0&&$r['notes']===0&&$r['history']===0,'No effect before scope/fence denial: '.$label);
};
$native=$operation('orders.send_native_email',['template'=>'processing']);
$verify($native,'yoro_connector_runner_lock_lost','Native omitted slots pass real scope and preparation');
$verify([...$native,'user_content_slots'=>[]],'yoro_connector_runner_lock_lost','Native explicit empty list');
foreach ([null,false,0,'',new stdClass(),(object)['0'=>[]],['field'=>'body'],[1=>[]],[['field'=>'body','content'=>'tampered']]] as $slots) {
    $verify([...$native,'user_content_slots'=>$slots],'confirmed_email_scope_invalid','Native present malformed/extra slots');
}
foreach (['orders.send_authored_email'=>['subject'=>'Exact subject','body'=>"Exact body\nSecond line"], 'orders.add_customer_note'=>['note'=>'Exact customer note']] as $action=>$changes) {
    $op=$operation($action,$changes);$slots=YoRo_Order_Email::slots($op['params']);
    $verify([...$op,'user_content_slots'=>$slots],'yoro_connector_runner_lock_lost','Exact authored slots: '.$action);
    $verify($op,'confirmed_email_scope_invalid','Required slots omitted: '.$action);
    foreach ([[],null,[...$slots,$slots[0]]] as $bad) {$verify([...$op,'user_content_slots'=>$bad],'confirmed_email_scope_invalid','Missing/extra authored slots: '.$action);}
    foreach (['content','content_scope','provenance','field'] as $field) {
        foreach(array_keys($slots)as$index){$bad=$slots;$bad[$index][$field]='foreign';$verify([...$op,'user_content_slots'=>$bad],'confirmed_email_scope_invalid','Altered '.$field.' slot '.$index.': '.$action);}
    }
}
$internal=['action'=>'orders.add_note','params'=>['order_ids'=>[1],'note'=>'Exact internal note'],
    'authorization'=>$native['authorization'],'extensions'=>$native['extensions']];
$internal['extensions']['yoro.mcp_early_access_commerce_write']['expected_version']=YoRo_Order_Presenter::order_version($GLOBALS['order']);
$slots=[['field'=>'note','content'=>'Exact internal note','content_scope'=>'internal_note','provenance'=>'user_goal']];
$verify([...$internal,'user_content_slots'=>$slots],'yoro_connector_runner_lock_lost','Exact separate internal-note guard');
$verify($internal,'confirmed_note_scope_invalid','Internal-note omission remains denied');
foreach(['empty','content','content_scope','provenance','extra']as$mutation){
    $bad=$slots;
    if($mutation==='empty'){$bad=[];}elseif($mutation==='extra'){$bad[]=$slots[0];}else{$bad[0][$mutation]='foreign';}
    $verify([...$internal,'user_content_slots'=>$bad],'confirmed_note_scope_invalid','Internal-note unchanged: '.$mutation);
}
foreach (['permit','permission','version','recipient','body','target','fence'] as $mutation) {
    $bad=$native;$expected='confirmed_commerce_denied';
    if($mutation==='permit'){unset($bad['authorization']['execution_permit']);}
    elseif($mutation==='permission'){$GLOBALS['allowed']=false;}
    elseif($mutation==='version'){$bad['params']['expected_version']=str_repeat('0',64);$bad['extensions']['yoro.mcp_early_access_commerce_write']['expected_version']=str_repeat('0',64);$expected='order_email_conflict';}
    elseif($mutation==='recipient'){$bad['params']['recipient']='foreign@example.invalid';$expected='order_email_conflict';}
    elseif($mutation==='body'){$bad['params']['html_body']='<p>Foreign</p>';$expected='order_email_conflict';}
    elseif($mutation==='target'){$bad['params']['order_id']=2;$expected='order_email_conflict';}
    else{$expected='yoro_connector_runner_lock_lost';}
    $verify($bad,$expected,'Existing authority/source guard: '.$mutation);$GLOBALS['allowed']=true;
}
echo json_encode(['result'=>'passed','assertions'=>$checks,'evidence'=>'SOURCE_TEST','real_mail'=>0]),"\n";
