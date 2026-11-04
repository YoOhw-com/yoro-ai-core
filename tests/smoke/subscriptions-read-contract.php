<?php

/** Runs owner-signature fixtures in an isolated child; this process never constructs an owner gateway. */
$test=dirname(__DIR__).'/unit/subscriptions-read-test.php';
$process=proc_open([PHP_BINARY,$test],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process)){throw new RuntimeException('Subscription fixture process unavailable.');}
$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
if($status!==0||!str_contains($output,'assertions passed.')){throw new RuntimeException('Subscription owner fixtures failed: '.$output.$errors);}
$marker=YoRo_Subscriptions_Read::manifest();
$registered=YoRo_Action_Registry::all();
foreach($marker['actions'] as $action){if(!isset($registered[$action])||!empty($registered[$action]['write_action'])||$registered[$action]['effect']!=='read'){throw new RuntimeException('Subscription registration must match negotiated read-only actions.');}}
if(!YoRo_Subscriptions_Read::supported()){foreach(array_slice(YoRo_Subscriptions_Read::ReadActions,1) as $action){if(isset($registered[$action])){throw new RuntimeException('Uncharacterized owner cannot expose subscription business reads.');}}}
$path=getenv('YORO_SHARED_COMMERCE_FIXTURES');if($path){$spec=dirname($path).'/subscriptions-read-v1.json';if(!is_file($spec)||hash_file('sha256',$spec)!==hash_file('sha256',dirname(__DIR__,2).'/includes/commerce/subscriptions-read-v1.json')){throw new RuntimeException('Exact API/Core subscriptions specification mismatch.');}}
echo $output;
echo "Subscription source registration, owner negotiation and exact contract parity passed.\n";
