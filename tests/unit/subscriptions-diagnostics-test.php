<?php
/** Disposable owner doubles only; never boots WordPress or a database. Legacy assertions run unchanged. */
define('AUTH_KEY','DISPOSABLE-ONLY-subscriptions-diagnostics-key');
define('AUTH_SALT','DISPOSABLE-ONLY-subscriptions-diagnostics-salt');
$GLOBALS['diagnostic_blog_id']=1;
function get_current_blog_id(){return $GLOBALS['diagnostic_blog_id'];}
require __DIR__.'/subscriptions-read-test.php';
$captures=[];
foreach(['8.8.1','9.3.0'] as $ownerVersion){foreach([false,true] as $hpos){
 WC_Subscriptions::$version=$ownerVersion;$GLOBALS['hpos']=$hpos;$GLOBALS['options']=[];$GLOBALS['objects']=[];$GLOBALS['denied_ids']=[];$GLOBALS['queries']=[];
 for($i=1;$i<=310;$i++){$s=new WC_Subscription($i);$s->status=$i%3===0?'on-hold':($i%3===1?'pending-cancel':'active');$s->next=$i%3===2?gmdate('Y-m-d H:i:s',time()-3600):null;$GLOBALS['objects'][$i]=$s;}
 $GLOBALS['objects'][9000]=new WC_Order(9000);
 $GLOBALS['objects'][9001]=new WC_Order(9001);$GLOBALS['objects'][9001]->relations=['renewal'=>[1]];
 $GLOBALS['objects'][9002]=new WC_Order(9002);$GLOBALS['objects'][9002]->relations=['renewal'=>[1],'switch'=>[1]];$GLOBALS['objects'][9002]->status='failed';$GLOBALS['objects'][9002]->paid=null;
 $GLOBALS['objects'][9010]=new WC_Order_Refund(9010,'shop_order_refund',9001);$GLOBALS['objects'][9010]->total='-0.000001';
 $attention='subscriptions.get_attention_queue';$timeline='subscriptions.get_timeline';$health='subscriptions.get_payment_health';
 $all=[];$params=['limit'=>17,'offset'=>0];$observed=null;
 for($i=0;$i<40;$i++){$r=$run($attention,$params);$assert(!is_wp_error($r),'Attention bounded owner read.');$d=$r['data'];$observed??=$d['scan_started_at'];$assert($d['scan_started_at']===$observed&&strtotime($d['observed_at'])>=strtotime($observed),'Continuation retains signed scan start and actual current observation.');$all=[...$all,...array_column($d['subscriptions'],'id')];$p=$d['page_info'];if(!$p['has_more']){break;}$params=['limit'=>17,'offset'=>$p['next_offset'],'anchor_id'=>$p['anchor_id'],'revision'=>$p['revision'],'scan_started_at'=>$observed];}
 $assert(count($all)===310&&count(array_unique($all))===310,'All310attention candidates continue without duplicate or hidden scan.');
 foreach([1=>'pending_cancellation',2=>'stored_next_payment_overdue',3=>'on_hold'] as $id=>$reason){$row=$run($attention,['limit'=>3,'offset'=>0])['data']['subscriptions'][$id-1];$assert($row['reasons']===[$reason],'Reason grounded in status or applicable stored schedule.');}
 $t=$run($timeline,['subscription_id'=>1,'limit'=>1,'offset'=>0]);$assert(!is_wp_error($t)&&count($t['data']['records'])===1&&$t['data']['page_info']['has_more'],'Timeline returns bounded recorded rows and continuation.');
 $assert($t['data']['subscription']['next_payment_at']===null&&$t['data']['records'][0]['kind']==='order','Stored schedule is separate from recorded order facts.');
 $assert(abs(strtotime($t['data']['observed_at'])-time())<=1,'Actual observation is server-owned current time.');
 $cursor=['subscription_id'=>1,'limit'=>1,'offset'=>$t['data']['page_info']['next_offset'],'anchor_id'=>$t['data']['page_info']['anchor_id'],'revision'=>$t['data']['page_info']['revision'],'scan_started_at'=>$t['data']['scan_started_at']];
 $continued=$run($timeline,$cursor);$assert(!is_wp_error($continued)&&$continued['data']['records'][0]['id']===9001,'Authentic signed continuation works.');
 foreach([['scan_started_at'=>gmdate('c',strtotime($cursor['scan_started_at'])-1)],['scan_started_at'=>gmdate('c',time()+60)],['anchor_id'=>$cursor['anchor_id']+1],['limit'=>2],['subscription_id'=>2],['record_type'=>'refunds']] as $patch){$assert(is_wp_error($run($timeline,array_replace($cursor,$patch))),'Modified scan basis/anchor/limit/target/type fails HMAC integrity.');}
 $assert(is_wp_error($run($health,$cursor)),'Signed cursor cannot change action.');
 $GLOBALS['principal_id']=2;$assert(is_wp_error($run($timeline,$cursor)),'Signed cursor cannot change native actor.');$GLOBALS['principal_id']=1;
 $GLOBALS['diagnostic_blog_id']=2;$assert(is_wp_error($run($timeline,$cursor)),'Signed cursor cannot change native site.');$GLOBALS['diagnostic_blog_id']=1;
 $assert(is_wp_error(YoRo_Subscriptions_Read::execute(['action'=>$timeline,'params'=>$cursor,'extensions'=>['yoro.mcp_early_access_product_read'=>['site_id'=>99]]])),'Signed cursor cannot change API site facts.');
 foreach([['observed_at'=>'1900-01-01T00:00:00+00:00'],['scan_started_at'=>'1900-01-01T00:00:00+00:00'],['anchor_id'=>$cursor['anchor_id'],'revision'=>$cursor['revision']]] as $patch){$assert(is_wp_error($run($timeline,['subscription_id'=>1,'limit'=>1,'offset'=>0,...$patch])),'First page never accepts caller supplied observation or cursor basis.');}
 $oldCursor=$cursor;$oldCursor['scan_started_at']=gmdate('c',time()-3600);$physical=(new ReflectionMethod(YoRo_Subscriptions_Read::class,'revision'))->invoke(null);$oldCursor['revision']=(new ReflectionMethod(YoRo_Subscriptions_Read::class,'diagnostic_revision'))->invoke(null,['action'=>$timeline,'params'=>$oldCursor],$physical,$oldCursor['scan_started_at'],$oldCursor['anchor_id']);
 $oldRead=$run($timeline,$oldCursor);$assert(!is_wp_error($oldRead)&&$oldRead['data']['scan_started_at']===$oldCursor['scan_started_at']&&strtotime($oldRead['data']['observed_at'])>strtotime($oldCursor['scan_started_at']),'Authentic prior scan basis does not relabel current observation or invent a cursor age limit.');
 $assert(is_wp_error($run($timeline,$oldCursor,fn()=>false))&&is_wp_error($run($timeline,$oldCursor,null,gmdate('c',time()-1))),'Authentic old cursor cannot extend cancelled or expired read authority.');
 $refund=$run($timeline,['subscription_id'=>1,'record_type'=>'refunds','limit'=>20,'offset'=>0]);$assert($refund['data']['records'][0]['kind']==='refund'&&$refund['data']['records'][0]['parent_order_id']===9001&&$refund['data']['records'][0]['total']==='0.000001'&&$refund['data']['records'][0]['paid_at']===null,'Native sibling refund amount/date and parent auth, no derived paid accessor.');
 $h=$run($health,['subscription_id'=>1,'limit'=>20,'offset'=>0]);$assert(!is_wp_error($h)&&array_column($h['data']['records'],'id')===[9001,9002]&&$h['data']['renewal_facts_in_page']===['orders'=>2,'failed_current_status'=>1,'with_recorded_paid_date'=>1],'Health only bounded native renewal-order page facts.');
 $assert(in_array('gateway_readiness',$h['data']['not_assessed'],true)&&in_array('payment_attempts',$h['data']['not_assessed'],true),'Unavailable collection evidence stays not_assessed.');
 foreach([1,9001,9002] as $deny){$GLOBALS['denied_ids']=[$deny];foreach([$timeline,$health] as $action){$assert(is_wp_error($run($action,['subscription_id'=>1,'limit'=>20,'offset'=>0])),'Denied seed or related object fails complete diagnostic, never skips.');}}$GLOBALS['denied_ids']=[9001];$assert(is_wp_error($run($timeline,['subscription_id'=>1,'record_type'=>'refunds','limit'=>20,'offset'=>0])),'Refund parent permission denied.');$GLOBALS['denied_ids']=[1];$assert(is_wp_error($run($attention,['limit'=>20,'offset'=>0])),'Denied attention candidate is not silently skipped.');$GLOBALS['denied_ids']=[];
 foreach([$attention,$timeline,$health] as $action){$base=['limit'=>20,'offset'=>0,...($action===$attention?[]:['subscription_id'=>1])];
  foreach([['offset'=>1],['limit'=>21],['limit'=>'20'],['anchor_id'=>310],['offset'=>1,'anchor_id'=>310,'revision'=>str_repeat('a',64),'scan_started_at'=>gmdate('c')],['scan_started_at'=>'2026-02-30T00:00:00+00:00'],['scan_started_at'=>gmdate('c',time()+10)],['email'=>'PRIVATE']] as $bad){$assert(is_wp_error($run($action,array_replace($base,$bad))),'Malformed and changed continuation fails closed.');}
  $GLOBALS['mutate_on_query']=true;$assert(is_wp_error($run($action,$base)),'Mutation within diagnostic rejects snapshot.');
  $assert(is_wp_error($run($action,$base,fn()=>false)),'Cancellation/guard cannot extend read authority.');
  $assert(is_wp_error($run($action,$base,null,gmdate('c',time()-1))),'Expired authority denies diagnostic.');
 }
 $GLOBALS['objects'][1]->status='cancelled';$GLOBALS['objects'][1]->next=gmdate('Y-m-d H:i:s',time()-100);$GLOBALS['objects'][2]->status='expired';$d=$run($attention,['limit'=>20,'offset'=>0])['data'];$assert(!in_array(1,array_column($d['subscriptions'],'id'),true)&&!in_array(2,array_column($d['subscriptions'],'id'),true),'Cancelled/expired schedules do not imply attention overdue.');
 foreach($GLOBALS['objects'] as $o){if($o instanceof WC_Subscription){$o->status='active';$o->next=null;}}
 $empty=$run($attention,['limit'=>20,'offset'=>0]);$assert($empty['data']['count']===0&&$empty['data']['page_info']['scanned']===100&&$empty['data']['page_info']['has_more'],'Empty matching page retains honest bounded continuation.');
 foreach($GLOBALS['queries'] as $query){$assert($query['limit']<=100,'No unbounded hidden diagnostic query.');}
 $captures[$ownerVersion][$hpos?'hpos':'cpt']=['timeline'=>$t,'refunds'=>$refund,'health'=>$h,'attention'=>$empty];
 echo 'Diagnostics '.$ownerVersion.' '.($hpos?'HPOS':'CPT')." passed.\n";
}}
WC_Subscriptions::$version='9.3.1';foreach(YoRo_Subscriptions_Read::DiagnosticActions as $action){$assert(is_wp_error($run($action,['limit'=>20,'offset'=>0,...($action==='subscriptions.get_attention_queue'?[]:['subscription_id'=>1])])),'Unsupported owner denies all diagnostics.');}
if($export=getenv('YORO_SUBSCRIPTIONS_DIAGNOSTICS_EXPORT')){file_put_contents($export,json_encode($captures,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
require __DIR__.'/subscriptions-diagnostics-signer-test.php';
echo 'Subscription diagnostics: '.$GLOBALS['assertions']." cumulative assertions passed.\n";
