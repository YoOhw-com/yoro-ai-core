<?php

/** Offline owner doubles exercise the new reads without touching the installed WordPress store. */
$test=dirname(__DIR__).'/unit/subscriptions-diagnostics-test.php';
$process=proc_open([PHP_BINARY,$test],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process)){throw new RuntimeException('Subscription diagnostics fixture process unavailable.');}
$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
if($status!==0||!str_contains($output,'Subscription diagnostics:')){throw new RuntimeException('Subscription diagnostics fixtures failed: '.$output.$errors);}
$registered=YoRo_Action_Registry::all();
foreach(YoRo_Subscriptions_Read::DiagnosticActions as $action){
 if(YoRo_Subscriptions_Read::diagnostics_supported()){
  if(!isset($registered[$action])||!empty($registered[$action]['write_action'])||$registered[$action]['effect']!=='read'){throw new RuntimeException('Diagnostics registration must be read only.');}
 }elseif(isset($registered[$action])){throw new RuntimeException('Unsupported owner cannot register diagnostics.');}
}
$path=getenv('YORO_SHARED_COMMERCE_FIXTURES');if($path){$spec=dirname($path).'/subscriptions-diagnostics-v1.json';if(!is_file($spec)||hash_file('sha256',$spec)!==hash_file('sha256',dirname(__DIR__,2).'/includes/commerce/subscriptions-diagnostics-v1.json')){throw new RuntimeException('Exact API/Core diagnostics specification mismatch.');}}
echo $output;
echo "Subscription diagnostics registration and exact-pair specification checks passed.\n";
