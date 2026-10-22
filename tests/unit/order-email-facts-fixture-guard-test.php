<?php
/** Exercise the real smoke entry guard without booting Woo, opening a DB or sending mail. */
$smoke=dirname(__DIR__).'/smoke/order-email-facts-contract.php';
$cases=[
    'http://yr0180.invalid'=>true,
    'http://yoro-plugin-smoke.test'=>true,
    'http://yoro-woocommerce-exact-pair.test'=>true,
    'https://yorohq.com'=>false,
    'https://store.example.com'=>false,
    'http://yoro-woocommerce-exact-pair.test.example.com'=>false,
    'http://not-yoro-woocommerce-exact-pair.test'=>false,
    'http://yoro-plugin-smoke.test.example.com'=>false,
    'http://localhost'=>false,
];
$checks=0;
foreach($cases as$url=>$allowed){
    // An allowed host reaches the first include; a rejected host must stop before it.
    $code='define("ABSPATH","/nonexistent-yoro-fixture-guard/");define("WPINC","wp-includes");class WooCommerce{};'
        .'function home_url(){return '.var_export($url,true).';}function wp_parse_url($url,$part){return parse_url($url,$part);}'
        .'set_error_handler(static function(){throw new RuntimeException("fixture_guard_passed");});'
        .'try{require '.var_export($smoke,true).';}catch(Throwable $error){echo $error->getMessage();}';
    $process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('Could not start isolated fixture guard test.');}
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    $expected=$allowed?'fixture_guard_passed':'Disposable local .invalid fixture required.';
    if($exit!==0||$stderr!==''||$stdout!==$expected){throw new RuntimeException('Unexpected smoke admission for '.$url.': '.$stdout.$stderr);}
    ++$checks;
}
echo json_encode(['result'=>'passed','fixture_guard_cases'=>$checks,'database_connections'=>0,'real_mail'=>0]),"\n";
