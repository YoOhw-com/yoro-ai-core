<?php
/** Real native option/schema reads; writes below are transaction-bound disposable fixtures only. */
if (!defined('ABSPATH')) { exit(1); }
if (!class_exists('YoRo_Early_Access_Settings_Read')) { YoRo_AI_Core::init(); }
if (str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')) { throw new RuntimeException('Disposable fixtures only.'); }
$assert=static function($ok,$why){if(!$ok){throw new RuntimeException($why);}};
$original=get_current_user_id();global $wpdb;$wpdb->query('START TRANSACTION');
$block=static function(){throw new RuntimeException('Settings reads cannot make outbound requests.');};add_filter('pre_http_request',$block);add_filter('pre_wp_mail','__return_true');
try{
    $admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);wp_set_current_user((int)$admins[0]);
    YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    $definition=YoRo_Early_Access_Settings_Read::definition();
    register_initial_settings();$native_wp=get_registered_settings();
    foreach($definition['wordpress_keys']as$key){$assert(($native_wp[$key]['type']??null)===$definition['fields'][$key][1],'WordPress schema type matches the native registered setting.');}
    update_option('blogname','G018 settings fixture');update_option('blogdescription','A bounded staging fixture');update_option('timezone_string','UTC');update_option('date_format','Y-m-d');update_option('time_format','H:i');update_option('start_of_week',1);update_option('posts_per_page',10);update_option('default_comment_status','closed');
    update_option('yr0180_settings_secret','SECRET-CANARY-NOT-EXPOSED');
    $run=static fn($action,$params=[])=>YoRo_Early_Access_Settings_Read::execute(['action'=>$action,'params'=>$params]);
    foreach(['site.get_settings','site.get_settings_schema']as$action){
        $r=$run($action);$assert(!is_wp_error($r)&&$r['data']['status']==='supported','Native WP settings action completes.');
        $assert(array_column($r['data']['settings'],'key')===$definition['wordpress_keys'],'Exact eight WP keys only.');
        foreach($r['data']['settings']as$row){$assert($row['type']===$definition['fields'][$row['key']][1]&&$row['read_only']===true,'Schema types remain exact.');if(str_contains($action,'schema')){$assert($row['value']===null,'Schema does not read values.');}else{$v=get_option($row['key']);if($row['type']==='integer'){$v=(int)$v;}$assert($row['value']===$v,'Values match native option API.');}}
        $assert(!str_contains(wp_json_encode($r),'SECRET-CANARY'),'Arbitrary option secret never leaves Core.');
        $assert(YoRo_Commerce_Contract_Builder::strict_output_validation_ready($action),'Settings strict schema is complete.');
    }
    $woo=yoro_ai_core_has_woocommerce();
    if($woo){
        foreach(['woocommerce_currency_pos'=>'right_space','woocommerce_price_thousand_sep'=>',','woocommerce_price_decimal_sep'=>'.','woocommerce_currency'=>'USD','woocommerce_price_num_decimals'=>2]as$key=>$value){update_option($key,$value);}
        foreach(['commerce.settings.get','commerce.settings.get_schema']as$action){$r=$run($action);$assert(!is_wp_error($r)&&$r['data']['status']==='supported','Native WC settings action completes.');$assert(array_column($r['data']['settings'],'key')===$definition['woocommerce_keys'],'Exact WC keys and context only.');foreach($r['data']['settings']as$row){if($r['data']['kind']==='values'){$value=get_option($row['key']);if($row['type']==='integer'){$value=(int)$value;}$assert($row['value']===$value,'Woo values match native options.');}}
        }
        require_once WC_ABSPATH.'includes/admin/settings/class-wc-settings-page.php';require_once WC_ABSPATH.'includes/admin/settings/class-wc-settings-general.php';
        if($dir=getenv('G018_SETTINGS_NATIVE_EXPORT')){foreach(YoRo_Early_Access_Settings_Read::ACTIONS as$selected){file_put_contents($dir.'/'.str_replace('.','-',$selected).'-native.json',wp_json_encode($run($selected)));}file_put_contents($dir.'/manifest.json',wp_json_encode(YoRo_Early_Access_Settings_Read::manifest()));}
        $native=(new WC_Settings_General())->get_settings('');
        $currency=array_values(array_filter($native,static fn($row)=>($row['id']??'')==='woocommerce_currency_pos'))[0];
        $assert(array_keys($currency['options'])===$definition['fields']['woocommerce_currency_pos'][5],'WC currency-position enum equals native version schema.');
    }else{$r=$run('commerce.settings.get');$assert(!is_wp_error($r)&&$r['data']['status']==='not_installed'&&$r['data']['settings']===[],'Woo absent has an explicit no-values result.');}
    foreach(['site.get_settings','site.get_settings_schema','commerce.settings.get','commerce.settings.get_schema']as$action){$assert(is_wp_error($run($action,['option'=>'admin_email'])),'Unknown selector fails closed.');}
    if($woo){
        $manager=wp_insert_user(['user_login'=>'yr0180-settings-manager-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(),'role'=>'shop_manager']);(new WP_User($manager))->add_cap('yoro_view_store_data');wp_set_current_user($manager);
        YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data']]);
        $assert(!is_wp_error($run('commerce.settings.get'))&&!is_wp_error($run('commerce.settings.get_schema')),'Native Woo settings manager reads allowed Woo settings.');
        $assert(is_wp_error($run('site.get_settings'))&&is_wp_error($run('site.get_settings_schema')),'Woo manager gains no WP manage_options permission.');
    }
    $limited=wp_insert_user(['user_login'=>'yr0180-settings-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(),'role'=>'subscriber']);(new WP_User($limited))->add_cap('yoro_view_store_data');wp_set_current_user($limited);
    YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_viewer','permissions'=>['yoro_view_store_data']]);
    foreach(YoRo_Early_Access_Settings_Read::ACTIONS as$action){$assert(is_wp_error($run($action)),'Schema and values both require native current settings permission.');}
    wp_set_current_user((int)$admins[0]);YoRo_WordPress_Actor::record_access(['assigned'=>true,'status'=>'active','role'=>'store_admin','permissions'=>['yoro_view_store_data','yoro_execute_actions']]);
    update_option('posts_per_page',10001);$assert($run('site.get_settings')['data']['status']==='unsupported','Out-of-contract native value does not leak or get misrepresented.');
    echo 'YoRo settings read native '.($woo?'WordPress/WooCommerce':'plain WordPress').' contracts passed.'.PHP_EOL;
}finally{$wpdb->query('ROLLBACK');wp_cache_flush();wp_set_current_user($original);remove_filter('pre_http_request',$block);remove_filter('pre_wp_mail','__return_true');}
