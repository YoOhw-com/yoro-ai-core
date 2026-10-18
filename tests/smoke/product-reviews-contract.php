<?php
/** Disposable WordPress/WooCommerce review fixtures; all changes roll back and network/mail are blocked. */
if (!defined('ABSPATH')) {exit(1);}
$plugin_root=dirname(__DIR__,2);
if(!class_exists('YoRo_AI_Core')) {require_once $plugin_root.'/yoro-ai-core.php';}
YoRo_AI_Core::init();
if(!class_exists('YoRo_Product_Reviews')) {require_once $plugin_root.'/includes/actions/class-yoro-product-reviews.php';}
YoRo_Product_Reviews::register();
if(!class_exists('WooCommerce')) {throw new RuntimeException('WooCommerce is required.');}
if(str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'yorohq.com')) {throw new RuntimeException('Never run review fixtures on canary or production.');}
$assert = static function( $ok, $why ) { if ( ! $ok ) { throw new RuntimeException( $why ); } };
$original = get_current_user_id();
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$block = static function() { throw new RuntimeException( 'The disposable write runner must not make a network request.' ); };
add_filter( 'pre_wp_mail', '__return_true' );
$client = new class extends YoRo_Connector_Client {
    public $operation; public $results = []; public $steps = []; public $after_lease = null; public $budget_drop_step = null; public $budget_remaining = null;
    public $next_attempts = 0;
    public function early_access_request( $step, $id = '', $payload = [], $token = '', $generation = 0 ) {
        if ( 'next' !== $step ) { throw new RuntimeException( 'Unexpected read transport in the write fixture.' ); }
        $this->next_attempts++;
        return new WP_Error( 'yoro_http_error', 'Legacy server fixture.', [ 'status' => 404 ] );
    }
    public function early_access_has_budget( $requests = 4 ) { return null === $this->budget_remaining ? parent::early_access_has_budget( $requests ) : $this->budget_remaining >= $requests; }
    public function early_access_write_request( $step, $id = '', $payload = [], $token = '', $generation = 0 ) {
        $this->steps[] = $step;
        if ( count( $this->steps ) === $this->budget_drop_step ) { $this->budget_remaining = max( 0, 3 - $this->budget_drop_step ); }
        if ( 'claim' === $step ) { return [ 'success' => true, 'delivery' => [ 'delivery_id' => 'fixture-delivery', 'claim_token' => str_repeat( 'a', 80 ), 'lease_generation' => 1, 'operations' => [ $this->operation ] ] ]; }
        if ( str_repeat( 'a', 80 ) !== $token || 1 !== $generation || $id !== $this->operation['envelope_id'] ) { throw new RuntimeException( 'The fenced lease was lost.' ); }
        if ( 'lease' === $step && null !== $this->after_lease ) { ( $this->after_lease )(); }
        if ( 'result' === $step ) { $this->results[] = $payload; }
        return [ 'success' => true ];
    }
};
$acquire = new ReflectionMethod( YoRo_Connector_Runtime::class, 'acquire_lock' );
$release = new ReflectionMethod( YoRo_Connector_Runtime::class, 'release_lock' );
global $wpdb; $wpdb->query( 'START TRANSACTION' ); $lock = null;
try {
    wp_set_current_user( (int) $admins[0] );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data', 'yoro_execute_actions' ] ] );
    $subject = YoRo_WordPress_Actor::current_payload()['subject'];
    $installation = YoRo_AI_Settings::get_installation_uuid(); $expires = gmdate( 'c', time() + 300 );
    $key = 'disposable-commerce-fixture-key';
    $encrypt = new ReflectionMethod( YoRo_AI_Settings::class, 'encrypt_ai_api_site_key' );
    update_option( YoRo_AI_Settings::OPTION_AI_API_URL, 'https://r2-api.yorohq.com/api/v1', false );
    update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $encrypt->invoke( null, $key ), false );
    $projection = [ 'status' => 'available', 'source' => 'api_handshake', 'site_id' => 1, 'installation_uuid' => $installation,
        'connection_healthy' => true, 'compatible' => true, 'checked_at' => gmdate( 'c' ), 'expires_at' => $expires,
        'future_endpoint' => 'https://r2-api.yorohq.com/mcp/early-access', 'runtime_enabled' => true,
        'server_url' => 'https://r2-api.yorohq.com/mcp/early-access', 'grant_status' => 'granted', 'confirmed_commerce_writes_enabled' => true ];
    $status = [ 'organization' => [ 'id' => 1 ], 'account_connection' => [ 'connected' => true, 'status' => 'active' ],
        'site' => [ 'id' => 1, 'status' => 'active', 'url' => home_url() ],
        'connection' => [ 'id' => 1, 'installation_uuid' => $installation, 'status' => 'active', 'environment' => 'staging', 'plugin_version' => YORO_AI_CORE_VERSION ],
        'credential' => [ 'id' => 1, 'type' => 'wordpress_connection', 'site_connection_id' => 1, 'expires_at' => $expires ],
        'connector_runtime' => [ 'connector_key' => 'woocommerce', 'connector_connection_id' => 'confirmed-commerce-fixture', 'execution_mode' => 'remote_agent',
            'protocol_version' => 1, 'delivery_protocol' => 'pull_v1', 'connector_version' => YORO_AI_CORE_VERSION,
            'capability_manifest' => [ 'current' => true, 'manifest_id' => 'confirmed-manifest', 'revision' => 1, 'expires_at' => $expires ], 'cutover' => [ 'cutover_allowed' => false ] ],
        'early_access_credential_fingerprint' => hash( 'sha256', $key ), 'early_access_onboarding' => $projection ];
    YoRo_AI_Settings::update_ai_api_status( $status );
    update_option( YoRo_Connector_Snapshot_Publisher::OPTION_MANIFEST_STATE, [ 'manifest_id' => 'confirmed-manifest', 'revision' => 1, 'expires_at' => $expires ] );
    $assert( YoRo_Connector_Runtime::early_access_write_allowed() && ! YoRo_Connector_Runtime::live_cutover_allowed(), 'Only the explicitly enabled bounded lane is active.' );
    $product=new WC_Product_Simple();$product->set_name('Disposable review product');$product->set_status('publish');$product->set_regular_price('10');$product->save();
    $other=new WC_Product_Simple();$other->set_name('Other review product');$other->set_status('publish');$other->save();
    $makeReview=static fn($productId,$body='Public review')=>wp_insert_comment(['comment_post_ID'=>$productId,'comment_type'=>'review','comment_parent'=>0,
        'comment_content'=>$body,'comment_author'=>'Public buyer','comment_author_email'=>'PRIVATE-EMAIL@example.test','comment_author_IP'=>'192.0.2.55','comment_agent'=>'PRIVATE-AGENT','comment_approved'=>1]);
    $reviewId=$makeReview($product->get_id(),'<script>untrusted()</script>Good product.');
    update_comment_meta($reviewId,'rating',5);update_comment_meta($reviewId,'verified',1);
    $otherId=$makeReview($other->get_id());
    $secondId=$makeReview($product->get_id(),'Second review');
    $first=YoRo_Product_Reviews::read(['product_id'=>$product->get_id(),'limit'=>1],'products.search_reviews');
    $assert(!is_wp_error($first)&&$first['data']['count']===1&&$first['data']['reviews'][0]['review_id']===$secondId&&$first['data']['page_info']['has_next_page'],'Search uses product-only keyset pagination.');
    $page=$first['data']['page_info'];$second=YoRo_Product_Reviews::read(['product_id'=>$product->get_id(),'limit'=>1,'anchor_id'=>$page['anchor_id'],'before_id'=>$page['next_before_id']],'products.search_reviews');
    $assert(!is_wp_error($second)&&$second['data']['reviews'][0]['review_id']===$reviewId&&!$second['data']['page_info']['has_next_page'],'The next page returns older matching reviews without duplication or other products.');
    $detail=YoRo_Product_Reviews::read(['review_id'=>$reviewId],'products.get_review');
    $encoded=wp_json_encode($detail);
    $assert(!is_wp_error($detail)&&$detail['data']['review']['rating']===5&&$detail['data']['review']['verified']===true
        &&!str_contains($encoded,'<script>')&&!str_contains($encoded,'PRIVATE-EMAIL')&&!str_contains($encoded,'PRIVATE-AGENT')&&!str_contains($encoded,'192.0.2.55'),'Projection strips markup and excludes private comment fields.');
    $longId=$makeReview($product->get_id(),str_repeat('é',1100));$long=YoRo_Product_Reviews::read(['review_id'=>$longId],'products.get_review');
    $assert($long['data']['review']['content_truncated']&&strlen($long['data']['review']['content'])<=2000&&preg_match('//u',$long['data']['review']['content'])===1,'Long review content is bounded without splitting UTF-8.');
    $post=wp_insert_post(['post_title'=>'Not a product','post_status'=>'publish','post_type'=>'post']);$wrongProduct=$makeReview($post);
    $wrongType=wp_insert_comment(['comment_post_ID'=>$product->get_id(),'comment_type'=>'comment','comment_content'=>'Not a review','comment_approved'=>1]);
    $wrongParent=wp_insert_comment(['comment_post_ID'=>$product->get_id(),'comment_type'=>'review','comment_parent'=>$reviewId,'comment_content'=>'Nested review','comment_approved'=>1]);
    foreach([$wrongProduct,$wrongType,$wrongParent] as $invalid){$assert(is_wp_error(YoRo_Product_Reviews::read(['review_id'=>$invalid],'products.get_review')),'Non-product, non-review and nested review targets fail closed.');}
    $params=['review_id'=>$reviewId,'product_id'=>$product->get_id(),'changes'=>['content'=>"Thank you.\nWe appreciate your feedback."],'expected_version'=>YoRo_Product_Reviews::version(get_comment($reviewId))];
    $preview=YoRo_Product_Reviews::preview($params,'products.reply_review');
    $assert(!is_wp_error($preview)&&$preview['after']===$params['changes']&&str_contains($preview['message'],'publicly')&&(int)get_comments(['parent'=>$reviewId,'type'=>'comment','count'=>true])===0,'Public reply preview is exact and creates no comment.');
    $assert(is_wp_error(YoRo_Product_Reviews::write($params,'products.reply_review')),'Bare unconfirmed callbacks cannot post public replies.');
    $foreign=$params;$foreign['product_id']=$other->get_id();$assert(is_wp_error(YoRo_Product_Reviews::prepare($foreign,'products.reply_review')),'A derived product binding cannot be substituted.');
    $injected=$params;$injected['changes']['author']='Customer impersonation';$assert(is_wp_error(YoRo_Product_Reviews::preview($injected,'products.reply_review')),'A caller cannot supply reply author identity.');
    $version=$params['expected_version'];update_comment_meta($reviewId,'rating',4);
    $assert(YoRo_Product_Reviews::version(get_comment($reviewId))!==$version&&is_wp_error(YoRo_Product_Reviews::prepare($params,'products.reply_review')),'Rating metadata changes invalidate the preview source.');
    update_comment_meta($reviewId,'rating',5);
    $version=YoRo_Product_Reviews::version(get_comment($reviewId));update_comment_meta($reviewId,'verified',0);
    $assert(YoRo_Product_Reviews::version(get_comment($reviewId))!==$version,'Verified metadata changes invalidate the review version.');update_comment_meta($reviewId,'verified',1);
    foreach(['<b>Public HTML</b>',str_repeat('x',2001),'',"bad\0reply"] as $body){$invalid=['review_id'=>$reviewId,'product_id'=>$product->get_id(),'changes'=>['content'=>$body],'expected_version'=>YoRo_Product_Reviews::version(get_comment($reviewId))];$assert(is_wp_error(YoRo_Product_Reviews::preview($invalid,'products.reply_review')),'Public replies reject markup, oversize, empty or unsafe content.');}
    $version=YoRo_Product_Reviews::version(get_comment($reviewId));wp_update_comment(['comment_ID'=>$reviewId,'comment_parent'=>$secondId]);
    $assert(YoRo_Product_Reviews::version(get_comment($reviewId))!==$version&&is_wp_error(YoRo_Product_Reviews::prepare($params,'products.reply_review')),'Parent reassignment invalidates the review identity and source version.');
    wp_update_comment(['comment_ID'=>$reviewId,'comment_parent'=>0]);
    $deny=static function($allcaps,$caps,$args){if(in_array($args[0],['moderate_comments','edit_comment','edit_post'],true)){foreach($caps as $cap){$allcaps[$cap]=false;}}return $allcaps;};
    add_filter('user_has_cap',$deny,PHP_INT_MAX,3);
    try{$assert(is_wp_error(YoRo_Product_Reviews::read(['status'=>'unapproved'],'products.search_reviews')),'Unpublished review statuses require moderation permission.');$denied=YoRo_Product_Reviews::prepare(['review_id'=>$reviewId,'product_id'=>$product->get_id(),'changes'=>['status'=>'spam'],'expected_restore_status'=>null,'expected_version'=>YoRo_Product_Reviews::version(get_comment($reviewId))],'products.moderate_review');}
    finally{remove_filter('user_has_cap',$deny,PHP_INT_MAX);}
    $assert(is_wp_error($denied),'Actual WordPress moderation/edit capabilities remain mandatory.');
    $make=static function($action,$changes)use($reviewId,$subject,$installation,$expires){
        $current=get_comment($reviewId);$params=['review_id'=>$reviewId,'product_id'=>(int)$current->comment_post_ID,'changes'=>$changes,'expected_version'=>YoRo_Product_Reviews::version($current),
            ...($action==='products.moderate_review'?['expected_restore_status'=>$changes['status']==='restore'?((string)get_comment_meta($reviewId,'_wp_trash_meta_status',true)==='1'?'approved':'unapproved'):null]:[])];$identity=hash('sha256',wp_generate_uuid4());
        return ['contract_type'=>'yoro.commerce.operation_envelope','schema_version'=>1,'envelope_id'=>'envelope_'.$identity,
            'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1','position'=>1,'connector_key'=>'woocommerce','connector_connection_id'=>'confirmed-commerce-fixture',
            'manifest_id'=>'confirmed-manifest','manifest_revision'=>1,'idempotency_key'=>'operation_'.$identity,'execution_mode'=>'remote_agent','action'=>$action,'params'=>$params,
            'actor'=>['contract_type'=>'yoro.commerce.actor_context','schema_version'=>1,'actor_type'=>'user','subject'=>'fixture-identity','operator_id'=>'fixture-operator',
                'workspace_id'=>'1','connector_connection_id'=>'confirmed-commerce-fixture','role'=>'store_admin','permissions'=>['yoro_execute_actions'],
                'authenticated_at'=>gmdate('c'),'assertion_id'=>'fixture-assertion','extensions'=>['wordpress.identity'=>['subject_version'=>1,'wordpress_subject'=>$subject]]],
            'plan_hash'=>str_repeat('a',64),'preview_hash'=>str_repeat('b',64),'authorization'=>['execution_permit'=>'permit_'.str_repeat('a',64),'permit_expires_at'=>$expires],
            'requested_at'=>gmdate('c'),'expires_at'=>$expires,'trace_id'=>'trace_'.$identity,
            ...($action==='products.reply_review'?['user_content_slots'=>[['field'=>'content','content'=>$changes['content'],'content_scope'=>'public_review_reply','provenance'=>'user_goal']]]:[]),
            'extensions'=>['yoro.mcp_early_access_commerce_write'=>['version'=>1,'action'=>$action,'source_operation_id'=>'envelope_'.str_repeat('c',64),'source_result_hash'=>str_repeat('d',64),
                'expected_version'=>$params['expected_version'],'params_hash'=>YoRo_Early_Access_Product_Read::params_hash($params),'resource'=>'https://r2-api.yorohq.com/mcp/early-access',
                'profile'=>'early_access','binding_version'=>2,'installation_uuid'=>$installation,'site_id'=>1,'site_key_id'=>1]]];
    };
    $postedCount=0;$notificationCalls=['moderator'=>0,'author'=>0];
    $onModerator=static function($notify,$id)use(&$notificationCalls,$reviewId){if((int)get_comment($id)->comment_parent===$reviewId){$notificationCalls['moderator']++;}return $notify;};
    $onAuthor=static function($notify,$id)use(&$notificationCalls,$reviewId){if((int)get_comment($id)->comment_parent===$reviewId){$notificationCalls['author']++;}return $notify;};
    add_filter('notify_moderator',$onModerator,PHP_INT_MAX,2);add_filter('notify_post_author',$onAuthor,PHP_INT_MAX,2);
    $onPosted=static function($id,$approved,$data)use(&$postedCount,$reviewId){if((int)$data['comment_parent']===$reviewId){$postedCount++;}};
    add_action('comment_post',$onPosted,PHP_INT_MAX,3);
    $client->operation=$make('products.reply_review',['content'=>"Thank you. It's helpful.\nReference C:\\notes stays literal."]);
    add_filter('pre_http_request',$block);
    $lock=$acquire->invoke(null);$assert($lock!==null,'The real connector runner owns its database lock.');
    $runtime=new YoRo_Connector_Runtime($client);
    $result=$runtime->run_early_access_write_once();
    $assert(!is_wp_error($result)&&($client->results[count($client->results)-1]['status']??null)==='executed','The confirmed public reply runs through the real parent journal and fenced transport: '.wp_json_encode($result));
    $replies=get_comments(['parent'=>$reviewId,'type'=>'comment','status'=>'all']);$assert(count($replies)===1&&$replies[0]->comment_content===$client->operation['params']['changes']['content']&&(int)$replies[0]->user_id===get_current_user_id(),'The reply is exact public content authored by the actual operator.');
    $notifications=$notificationCalls;$assert($notifications['moderator']>0&&$notifications['author']>0,'Normal configured WordPress notification callbacks run under the fixture mail guard.');
    $result=$runtime->run_early_access_write_once();
    $assert(!is_wp_error($result)&&(int)get_comments(['parent'=>$reviewId,'type'=>'comment','count'=>true])===1&&$postedCount===1&&$notificationCalls===$notifications,'The configured comment_post hook runs once, and completed journal replay cannot post or notify again.');
    remove_action('comment_post',$onPosted,PHP_INT_MAX);remove_filter('notify_moderator',$onModerator,PHP_INT_MAX);remove_filter('notify_post_author',$onAuthor,PHP_INT_MAX);
    $client->operation=$make('products.reply_review',['content'=>'Never repeat an uncertain public reply.']);
    YoRo_Connector_Execution_Journal::begin($client->operation);$result=$runtime->run_early_access_write_once();
    $last=end($client->results);
    $assert(!is_wp_error($result)&&($last['error']['code']??null)==='local_execution_outcome_unknown'&&(int)get_comments(['parent'=>$reviewId,'type'=>'comment','count'=>true])===1,'An executing parent journal reports unknown and never repeats the public mutation.');
    $fenced=$make('products.reply_review',['content'=>'Fence must prevent this reply.']);$outcome=YoRo_Early_Access_Commerce_Write::execute($fenced,static fn()=>false);
    $assert(is_wp_error($outcome)&&(int)get_comments(['parent'=>$reviewId,'type'=>'comment','count'=>true])===1,'A lost final fence cannot post a reply.');
    foreach(['spam','restore','trash','restore'] as $target){
        $operation=$make('products.moderate_review',['status'=>$target]);
        if($target==='restore'){$hiddenReply=$make('products.reply_review',['content'=>'A hidden source must not receive public replies.']);$assert(is_wp_error(YoRo_Product_Reviews::prepare($hiddenReply['params'],'products.reply_review')),'Public replies require an approved parent review.');$wrong=$operation['params'];$wrong['expected_restore_status']='unapproved';$assert(is_wp_error(YoRo_Product_Reviews::prepare($wrong,'products.moderate_review')),'A substituted restore destination is denied before mutation.');}
        $outcome=YoRo_Early_Access_Commerce_Write::execute($operation,static fn()=>true);
        $assert(!is_wp_error($outcome)&&wp_get_comment_status($reviewId)===($target==='restore'?'approved':$target),'Native moderation and restore preserve the prior explicit status: '.wp_json_encode($outcome));
    }
    $uncertainBody='A public reply saved before a downstream hook failed.';
    $client->operation=$make('products.reply_review',['content'=>$uncertainBody]);
    $failAfterSave=static function($id,$comment)use($uncertainBody){if($comment->comment_content===$uncertainBody){throw new RuntimeException('Disposable post-save failure.');}};
    add_action('wp_insert_comment',$failAfterSave,PHP_INT_MAX,2);
    try{$result=$runtime->run_early_access_write_once();}finally{remove_action('wp_insert_comment',$failAfterSave,PHP_INT_MAX);}
    $last=end($client->results);
    $assert(!is_wp_error($result)&&($last['error']['code']??null)==='local_execution_outcome_unknown'&&(int)get_comments(['parent'=>$reviewId,'type'=>'comment','count'=>true])===2,'A hook failure after persistence retains the saved reply and reports an uncertain outcome.');
    $result=$runtime->run_early_access_write_once();
    $assert(!is_wp_error($result)&&(int)get_comments(['parent'=>$reviewId,'type'=>'comment','count'=>true])===2,'An uncertain persisted reply is never posted again by journal replay.');
    $detail=YoRo_Product_Reviews::read(['review_id'=>$reviewId],'products.get_review');
    $assert(count($detail['data']['replies'])===2&&$detail['data']['replies'][0]['review_id']===$reviewId&&$detail['data']['replies'][0]['product_id']===$product->get_id(),'Read replies remain bound to the exact product and root review.');
    $replyCount=static fn()=>(int)get_comments(['parent'=>$reviewId,'type'=>'comment','status'=>'any','count'=>true]);
    $before=$replyCount();$rejectedBody='WordPress policy rejects this exact reply.';$policyCalls=0;
    $reject=static function($approved,$data)use($rejectedBody,&$policyCalls){if($data['comment_content']===$rejectedBody){$policyCalls++;return new WP_Error('fixture_policy_rejected','Disposable comment policy denied this reply.');}return $approved;};
    $client->operation=$make('products.reply_review',['content'=>$rejectedBody]);
    add_filter('pre_comment_approved',$reject,PHP_INT_MAX,2);
    try{$result=$runtime->run_early_access_write_once();$last=end($client->results);$assert(!is_wp_error($result)&&$last['status']==='failed'&&($last['error']['code']??null)==='confirmed_change_failed'&&$replyCount()===$before&&$policyCalls===1,'A WordPress policy error before insert is a known canonical failure with no reply.');
        $runtime->run_early_access_write_once();$assert($replyCount()===$before&&$policyCalls===1,'A rejected journal replay neither retries policy nor inserts a reply.');}
    finally{remove_filter('pre_comment_approved',$reject,PHP_INT_MAX);}
    foreach([0=>'unapproved','spam'=>'spam'] as $approved=>$expectedStatus){
        $before=$replyCount();$body='WordPress policy retains a '.$expectedStatus.' reply.';$policyCalls=0;
        $retain=static function($status,$data)use($body,$approved,&$policyCalls){if($data['comment_content']===$body){$policyCalls++;return $approved;}return $status;};
        $client->operation=$make('products.reply_review',['content'=>$body]);
        add_filter('pre_comment_approved',$retain,PHP_INT_MAX,2);
        try{$result=$runtime->run_early_access_write_once();$last=end($client->results);$saved=get_comments(['parent'=>$reviewId,'type'=>'comment','status'=>'any','number'=>1,'orderby'=>'comment_ID','order'=>'DESC']);
            $assert(!is_wp_error($result)&&($last['error']['code']??null)==='local_execution_outcome_unknown'&&$replyCount()===$before+1&&$saved[0]->comment_content===$body&&wp_get_comment_status($saved[0])===$expectedStatus,'WordPress '.$expectedStatus.' policy is preserved, never reported as an exact public success.');
            $calls=$policyCalls;$runtime->run_early_access_write_once();$assert($replyCount()===$before+1&&$policyCalls===$calls,'An uncertain '.$expectedStatus.' reply is never posted or reprocessed again.');
            $detail=YoRo_Product_Reviews::read(['review_id'=>$reviewId],'products.get_review');$projected=array_values(array_filter($detail['data']['replies'],static fn($row)=>$row['reply_id']===(int)$saved[0]->comment_ID));
            if($expectedStatus==='unapproved'){$assert(count($projected)===1&&$projected[0]['status']===$expectedStatus,'A fresh review read exposes the held reply status.');}}
        finally{remove_filter('pre_comment_approved',$retain,PHP_INT_MAX);}
    }
    $before=$replyCount();$originalBody='The exact approved public reply.';$changedBody='WordPress policy changed this reply.';$preprocessCalls=0;
    $mutate=static function($data)use($originalBody,$changedBody,&$preprocessCalls){if($data['comment_content']===$originalBody){$preprocessCalls++;$data['comment_content']=$changedBody;}return $data;};
    $client->operation=$make('products.reply_review',['content'=>$originalBody]);
    add_filter('preprocess_comment',$mutate,PHP_INT_MAX);
    try{$result=$runtime->run_early_access_write_once();$last=end($client->results);$saved=get_comments(['parent'=>$reviewId,'type'=>'comment','status'=>'any','number'=>1,'orderby'=>'comment_ID','order'=>'DESC']);
        $assert(!is_wp_error($result)&&($last['error']['code']??null)==='local_execution_outcome_unknown'&&$replyCount()===$before+1&&$saved[0]->comment_content===$changedBody,'Preprocessing runs normally; changed persisted content cannot claim the confirmed exact public outcome.');
        $runtime->run_early_access_write_once();$assert($replyCount()===$before+1&&$preprocessCalls===1,'A policy-modified reply is never repeated by journal replay.');}
    finally{remove_filter('preprocess_comment',$mutate,PHP_INT_MAX);}
    $before=$replyCount();$hookBody='The reply persisted before comment_post failed.';$hookCalls=0;
    $failCommentPost=static function($id,$approved,$data)use($hookBody,&$hookCalls){if($data['comment_content']===$hookBody){$hookCalls++;throw new RuntimeException('Disposable comment_post failure.');}};
    $client->operation=$make('products.reply_review',['content'=>$hookBody]);
    add_action('comment_post',$failCommentPost,PHP_INT_MAX,3);
    try{$result=$runtime->run_early_access_write_once();$last=end($client->results);$assert(!is_wp_error($result)&&($last['error']['code']??null)==='local_execution_outcome_unknown'&&$replyCount()===$before+1,'A comment_post exception after persistence is an unknown outcome, never a known no-effect failure.');
        $runtime->run_early_access_write_once();$assert($replyCount()===$before+1&&$hookCalls===1,'A persisted reply and its failing comment_post hook are not repeated.');}
    finally{remove_action('comment_post',$failCommentPost,PHP_INT_MAX);}
    echo "Product reviews contract passed: product-only keyset reads, safe projection, complete versions/capabilities, explicit public previews, native restore, WordPress reply policy/hooks, fenced runtime journal replay and unknown-stop.\n";
} finally {
    if($lock!==null){$release->invoke(null,$lock);}
    remove_filter('pre_http_request',$block);remove_filter('pre_wp_mail','__return_true');
    if(isset($onPosted)){remove_action('comment_post',$onPosted,PHP_INT_MAX);remove_filter('notify_moderator',$onModerator,PHP_INT_MAX);remove_filter('notify_post_author',$onAuthor,PHP_INT_MAX);}
    $wpdb->query('ROLLBACK');wp_cache_flush();wp_set_current_user($original);
}
