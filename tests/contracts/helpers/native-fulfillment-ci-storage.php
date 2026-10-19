<?php
/**
 * Test-only native storage setup for the isolated hosted WooCommerce smoke database.
 * Woo's initializer drops storage and clears fulfillment status metadata. Therefore it
 * is never called on existing/partial storage or any existing fulfillment marker.
 * Created empty native tables remain; DDL is not described as transaction rollback.
 */
function yoro_native_fulfillment_ci_storage(): void
{
    global $wpdb;$tables=[];
    foreach(['wc_order_fulfillments','wc_order_fulfillment_meta']as$suffix){$table=$wpdb->prefix.$suffix;$exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));if($wpdb->last_error!==''){throw new RuntimeException('Cannot prove native test storage.');}$tables[$table]=$exists===$table;}
    if(count(array_filter($tables))===2){return;}
    $runner=realpath((string)getenv('RUNNER_TEMP'));$wordpress=realpath(ABSPATH);
    $isolation=[
        'cli'=>PHP_SAPI==='cli',
        'github_actions_exact'=>getenv('GITHUB_ACTIONS')==='true',
        'db_name_exact'=>defined('DB_NAME')&&DB_NAME==='wordpress',
        'db_host_exact'=>defined('DB_HOST')&&DB_HOST==='127.0.0.1:3306',
        // Bind the installer-owned root, independent of request scheme and URL filters.
        'home_allowlisted'=>in_array(untrailingslashit((string)get_option('home')),['http://yoro-plugin-smoke.test','http://yoro-woocommerce-exact-pair.test','http://yoro-smoke.test'],true),
        'runner_realpath_exists'=>$runner!==false,
        'wordpress_realpath_exists'=>$wordpress!==false,
        'wordpress_under_runner'=>$runner!==false&&$wordpress!==false&&str_starts_with($wordpress,$runner.DIRECTORY_SEPARATOR),
        'native_tables_absent'=>count(array_filter($tables))===0,
    ];
    if(in_array(false,$isolation,true)){throw new RuntimeException('Positive native fulfillment fixtures require existing tables or the exact isolated hosted smoke environment. Isolation: '.json_encode($isolation,JSON_THROW_ON_ERROR));}
    if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$wpdb->prefix)){throw new RuntimeException('Ambiguous test table prefix.');}
    $marker=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s",'woocommerce_fulfillments_db_tables_created'));
    if($wpdb->last_error!==''||(int)$marker!==0||!in_array(get_option('woocommerce_feature_fulfillments_enabled','no'),['no',false],true)){throw new RuntimeException('Existing native test configuration must not be initialized.');}
    foreach([$wpdb->postmeta,$wpdb->prefix.'wc_orders_meta']as$table){
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));if($wpdb->last_error!==''){throw new RuntimeException('Cannot prove test metadata preimage.');}
        if($exists!==$table){continue;}
        $found=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE meta_key=%s",'_fulfillment_status'));
        if($wpdb->last_error!==''||(int)$found!==0){throw new RuntimeException('Existing fulfillment metadata forbids test initialization.');}
    }
    // The database is isolated by RUNNER_TEMP, exact test home and the fresh-empty proof.
    $features=wc_get_container()->get(Automattic\WooCommerce\Internal\Features\FeaturesController::class);
    try{
        $features->change_feature_enable('fulfillments',true);
        wc_get_container()->get(Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentsController::class)->initialize_fulfillments();
        foreach(array_keys($tables)as$table){$exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));if($wpdb->last_error!==''||$exists!==$table||(int)$wpdb->get_var("SELECT COUNT(*) FROM $table")!==0||$wpdb->last_error!==''){throw new RuntimeException('Native smoke storage was not created empty.');}}
        if(!get_option('woocommerce_fulfillments_db_tables_created',false)){throw new RuntimeException('Native smoke storage marker was not saved.');}
    }finally{$features->change_feature_enable('fulfillments',false);}
}
