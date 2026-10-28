<?php
/** Disposable process-backed SQLite tests. MySQL/WordPress admission is covered by the owning smoke contract. */
if (($argv[1] ?? '') === 'worker') {
    ob_start(); require __DIR__.'/order-email-test.php'; ob_end_clean();
    require dirname(__DIR__,2).'/includes/commerce/class-yoro-connector-runtime.php';
    $GLOBALS['order']=new WC_Order(); $GLOBALS['order']->recipient='buyer@example.invalid'; $GLOBALS['wc']=new FixtureWoo();
    $GLOBALS['allowed']=true; $GLOBALS['captured']=[]; unset($GLOBALS['wrapper_footer']);
    $mode=$argv[3]; $mutation=$argv[4] ?? ''; $expiry=(int)$argv[5];
    class Yr0196SqliteOptions {
        public string $options='options'; private string $probe=''; public string $last_error=''; public PDO $pdo;
        public function __construct(string $path, public string $mode) {
            $this->pdo=new PDO('sqlite:'.$path); $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA busy_timeout=5000'); $this->pdo->exec('PRAGMA synchronous=FULL');
            if($mode==='transaction'){$this->pdo->beginTransaction();}
        }
        public function prepare($sql,...$values){return [str_replace("%s","?",$sql),$values];}
        public function suppress_errors($value){return false;}
        public function get_row($sql,$format){return ['Code'=>1305,'Message'=>'SAVEPOINT '.$this->probe.' does not exist'];}
        public function query($query){
            $this->last_error='';
            if(is_string($query)&&str_starts_with($query,'SAVEPOINT ')){$this->probe=substr($query,10);return 0;}
            if(is_string($query)&&str_starts_with($query,'RELEASE SAVEPOINT ')){if($this->pdo->inTransaction()){return 0;}$this->last_error='expected missing savepoint';return false;}
            if($this->mode==='storage_failure'){$this->last_error='disposable write fault';return false;}
            try{$statement=$this->pdo->prepare($query[0]);$statement->execute($query[1]);$count=$statement->rowCount();}
            catch(Throwable $error){$this->last_error='disposable storage rejected';return false;}
            if($this->mode==='crash'){exit(77);}
            return $count;
        }
        public function get_var($query){
            $this->last_error='';
            if($query==='SELECT @@session.autocommit'){return '1';}
            if($query==='SELECT @@global.innodb_flush_log_at_trx_commit'){return '1';}
            if(is_array($query)&&str_starts_with($query[0],'SELECT ENGINE')){return 'InnoDB';}
            try{$statement=$this->pdo->prepare($query[0]);$statement->execute($query[1]);return $statement->fetchColumn();}
            catch(Throwable $error){$this->last_error='disposable read fault';return false;}
        }
    }
    define('ARRAY_A','ARRAY_A');
    $GLOBALS['wpdb']=new Yr0196SqliteOptions($argv[2],$mode);
    $fields=['profile','resource','binding_version','oauth_client_id','grant_id','grant_version','token_id','user_id','site_id','wordpress_identity_id','connection_id','installation_uuid','site_key_id','site_key_hash','manifest_id','manifest_revision','manifest_hash','actor_role','actor_capabilities_hash','resource_scopes_hash'];
    $authority=array_fill_keys($fields,'fixture');
    $authority=array_replace($authority,['profile'=>'early_access','binding_version'=>2,'user_id'=>101,'site_id'=>1,'wordpress_identity_id'=>9]);
    $run=str_repeat('a',32);$marker=['task_id'=>'YR-0196','run_id'=>$run,'owner_user_id'=>101,'site_id'=>1,'wordpress_identity_id'=>9];
    $GLOBALS['order']->meta[YoRo_YR0196_Canary_Context::Marker]=$marker;
    $action=$mode==='native'?'orders.send_native_email':'orders.send_authored_email';
    $flags=$make($action,$action==='orders.send_native_email'?['template'=>'processing']:['subject'=>'Private fixture','body'=>'Exact private body']);
    $params=array_diff_key($flags,array_flip(['_yoro_confirmed','_yoro_preview']));
    $prepared=YoRo_Order_Email::prepare($params,$action);$source=$prepared['source'];
    $mail=['recipient'=>$source['recipient'],'subject_hash'=>hash('sha256',$params['subject']??$params['changes']['subject']),
        'body_hash'=>hash('sha256',$action==='orders.send_native_email'?$source['html_body']:$prepared['authored']['html_body']),
        'from_address'=>$source['settings']['from_address'],'from_name_hash'=>hash('sha256',$source['settings']['from_name']),'content_type'=>'text/html'];
    $operation=['action'=>$action,'params'=>$params,'envelope_id'=>'envelope_'.str_repeat('b',64),'workflow_id'=>'fixture-workflow','operation_id'=>'operation_1','idempotency_key'=>'fixture-idempotency',
        'preview_hash'=>str_repeat('c',64),'authorization'=>['execution_permit'=>'fixture-permit'],
        'extensions'=>['yoro.mcp_early_access_commerce_write'=>[...$authority,'source_operation_id'=>'envelope_'.str_repeat('d',64),'source_result_hash'=>str_repeat('e',64)]]];
    $hash=YoRo_YR0196_Canary_Context::operation_hash($operation);
    $permit=['id'=>str_repeat('f',64),'operation_hash'=>$hash,'target_ids'=>[1],'mode'=>'capture','mail'=>$mail];
    $config=['version'=>1,'enabled'=>true,'task_id'=>'YR-0196','run_id'=>$run,'expires_at'=>$expiry,'authority'=>$authority,'permits'=>[$permit]];
    $binding=['version'=>1,'task_id'=>'YR-0196','run_id'=>$run,'permit_id'=>$permit['id'],'operation_hash'=>$hash,'mode'=>'capture','expires_at'=>$expiry,'permit_hash'=>YoRo_Early_Access_Product_Read::params_hash($permit),
        'envelope_id'=>$operation['envelope_id'],'params_hash'=>YoRo_Early_Access_Product_Read::params_hash($params)];
    $operation['extensions']['yoro.mcp_early_access_commerce_write']['yr0196_canary']=$binding;
    if($mutation==='private_version'){$config['version']=2;}
    elseif($mutation==='disabled'){$config['enabled']=false;}
    elseif($mutation==='expired'){$config['expires_at']=time()-1;}
    elseif($mutation==='ambiguous'){$config['permits'][]=$permit;}
    elseif($mutation==='owner_marker'){$GLOBALS['order']->meta[YoRo_YR0196_Canary_Context::Marker]['owner_user_id']=102;}
    elseif($mutation==='target'){$config['permits'][0]['target_ids']=[2];}
    elseif($mutation==='content'){$config['permits'][0]['mail']['body_hash']=str_repeat('0',64);}
    elseif($mutation==='sender'){$config['permits'][0]['mail']['from_address']='foreign@example.invalid';}
    elseif($mutation==='operation'){$operation['operation_id']='foreign';}
    elseif($mutation==='source'){$operation['extensions']['yoro.mcp_early_access_commerce_write']['source_result_hash']=str_repeat('0',64);}
    elseif($mutation==='preview'){$operation['preview_hash']=str_repeat('0',64);}
    elseif(in_array($mutation,$fields,true)){$config['authority'][$mutation]='foreign';}
    define('YORO_YR0196_CANARY',$config);
    $slot=new ReflectionProperty(YoRo_Connector_Runtime::class,'canary_context');
    $result=null;$denied=false;
    try {
        if($mutation!=='no_runner'){
            $context=YoRo_YR0196_Canary_Context::admit($operation,1,static fn()=>$mutation!=='fence');
            $context->before($operation);$slot->setValue(null,$context);
        }
        $result=YoRo_Order_Email::write($flags,$action);
        $denied=is_wp_error($result);if($denied&&getenv("YR0196_DEBUG")){fwrite(STDERR,$result->code." ".$result->message."\n");}
    } catch(Throwable $error){$denied=true;if(getenv("YR0196_DEBUG")){fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");}}
    finally{$slot->setValue(null,null);}
    echo json_encode(['denied'=>$denied,'outcome'=>is_array($result)?$result['data']['outcome']:null,'mail_calls'=>count($GLOBALS['captured']),
        'history'=>array_column(YoRo_Order_Email::context(1)['history'],'outcome'),'context_cleared'=>$slot->getValue()===null]),"\n";
    exit;
}
$root=sys_get_temp_dir().'/yr0196-store-'.bin2hex(random_bytes(8));mkdir($root,0700);$path=$root.'/options.sqlite';
$pdo=new PDO('sqlite:'.$path);$pdo->exec('PRAGMA journal_mode=WAL');$pdo->exec('CREATE TABLE options(option_name TEXT PRIMARY KEY, option_value TEXT NOT NULL, autoload TEXT NOT NULL)');
$checks=0;$assert=static function($ok,$why)use(&$checks){++$checks;if(!$ok){throw new RuntimeException($why);}};
$expiry=time()+600;
$start=static function($mode,$mutation='')use($path,$expiry){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'worker',$path,$mode,$mutation,(string)$expiry],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$process,$pipes];};
$finish=static function($job){[$process,$pipes]=$job;$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($code!==0&&$code!==77){throw new RuntimeException($stderr.$stdout);}return [$code,json_decode(trim($stdout),true)];};
$run=static fn($mode,$mutation='')=>$finish($start($mode,$mutation));
$clear=static fn()=>$pdo->exec('DELETE FROM options');
foreach(['profile','resource','binding_version','oauth_client_id','grant_id','grant_version','token_id','user_id','site_id','wordpress_identity_id','connection_id','installation_uuid','site_key_id','site_key_hash','manifest_id','manifest_revision','manifest_hash','actor_role','actor_capabilities_hash','resource_scopes_hash','private_version','disabled','expired','ambiguous','owner_marker','target','content','sender','operation','source','preview','fence','no_runner']as$mutation){
    [$code,$r]=$run('authored',$mutation);$assert($r['denied']&&$r['mail_calls']===0&&$r['history']===[]&&$r['context_cleared'],'Denial before effect: '.$mutation);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM options')->fetchColumn()===0,'Denial consumes no capture: '.$mutation);
}
foreach(['authored','native']as$mode){
    $clear();[$code,$r]=$run($mode);$assert(!$r['denied']&&$r['outcome']==='captured_not_delivered'&&$r['mail_calls']===0&&$r['history']===[],'Both lanes capture truthfully without transport: '.$mode);
    [$code,$r]=$run($mode);$assert($r['denied']&&$r['mail_calls']===0&&(int)$pdo->query('SELECT COUNT(*) FROM options')->fetchColumn()===1,'Restart cannot recapture or send: '.$mode);
}
$clear();$a=$start('authored');$b=$start('authored');[, $one]=$finish($a);[, $two]=$finish($b);
$assert((int)$one['denied']+(int)$two['denied']===1&&$one['mail_calls']===0&&$two['mail_calls']===0&&(int)$pdo->query('SELECT COUNT(*) FROM options')->fetchColumn()===1,'Separate processes contend for exactly one durable capture.');
$clear();[$code]=$run('crash');$assert($code===77&&(int)$pdo->query('SELECT COUNT(*) FROM options')->fetchColumn()===1,'Committed consumption survives abrupt process exit before readback/completion.');
[, $r]=$run('authored');$assert($r['denied']&&$r['mail_calls']===0,'Restart after crash neither resends nor duplicates.');
foreach(['storage_failure','transaction']as$mode){$clear();[, $r]=$run($mode);$assert($r['denied']&&$r['mail_calls']===0&&$r['history']===[]&&(int)$pdo->query('SELECT COUNT(*) FROM options')->fetchColumn()===0,'Unavailable/non-durable storage denies before effects: '.$mode);}
$clear();$pdo=null;foreach(glob($path.'*')as$file){unlink($file);}rmdir($root);
echo json_encode(['result'=>'passed','assertions'=>$checks,'storage'=>'disposable process-backed SQLite, synchronous FULL','real_mail'=>0]),"\n";
