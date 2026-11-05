<?php
/** Isolated fake native configuration values only. Never invokes wp_salt or WordPress storage. */
$cases=[
 ['missing',[],false],['missing_salt',['AUTH_KEY'=>'DISPOSABLE-private-key'],false],['missing_key',['AUTH_SALT'=>'DISPOSABLE-private-salt'],false],
 ['empty_key',['AUTH_KEY'=>'','AUTH_SALT'=>'DISPOSABLE-private-salt'],false],['empty_salt',['AUTH_KEY'=>'DISPOSABLE-private-key','AUTH_SALT'=>''],false],
 ['non_string',['AUTH_KEY'=>123,'AUTH_SALT'=>'DISPOSABLE-private-salt'],false],['duplicate',['AUTH_KEY'=>'DISPOSABLE-same','AUTH_SALT'=>'DISPOSABLE-same'],false],
 ['default_key',['AUTH_KEY'=>'put your unique phrase here','AUTH_SALT'=>'DISPOSABLE-private-salt'],false],['default_salt',['AUTH_KEY'=>'DISPOSABLE-private-key','AUTH_SALT'=>'put your unique phrase here'],false],
 ['configured',['AUTH_KEY'=>'DISPOSABLE-private-key','AUTH_SALT'=>'DISPOSABLE-private-salt'],true],
];
foreach($cases as [$label,$constants,$expected]){
 $code="define('ABSPATH','/disposable/');";foreach($constants as $name=>$value){$code.='define('.var_export($name,true).','.var_export($value,true).');';}
 $code.='require '.var_export(dirname(__DIR__,2).'/includes/commerce/class-yoro-subscriptions-read.php',true).';';
 $code.='$key=(new ReflectionMethod(YoRo_Subscriptions_Read::class,"diagnostics_key"))->invoke(null); if(($key!==null)!=='.($expected?'true':'false').'){exit(1);} echo "PASS";';
 $process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process)){throw new RuntimeException('Signer fixture process unavailable.');}
 $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
 if($exit!==0||$out!=='PASS'||$error!==''){throw new RuntimeException('Signer configuration fixture failed: '.$label);}
}
echo "Subscription diagnostics signer: 10 isolated configured/missing/default cases passed; no secret output or storage fallback.\n";
