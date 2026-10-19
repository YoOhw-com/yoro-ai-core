<?php
/** Actual runtime/journal with an in-process delivery client; no business result is mocked. */
function yoro_native_write_fixture_client() {
return new class extends YoRo_Connector_Client {
    public $operation; public $results = []; public $operations = []; public $steps = []; public $after_lease = null; public $budget_drop_step = null; public $budget_remaining = null;
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
        if ( 'claim' === $step ) { $this->operations[$this->operation['envelope_id']]=['action'=>$this->operation['action'],'params'=>$this->operation['params']]; return [ 'success' => true, 'delivery' => [ 'delivery_id' => 'fixture-delivery', 'claim_token' => str_repeat( 'a', 80 ), 'lease_generation' => 1, 'operations' => [ $this->operation ] ] ]; }
        if ( str_repeat( 'a', 80 ) !== $token || 1 !== $generation || $id !== $this->operation['envelope_id'] ) { throw new RuntimeException( 'The fenced lease was lost.' ); }
        if ( 'lease' === $step && null !== $this->after_lease ) { ( $this->after_lease )(); }
        if ( 'result' === $step ) { $this->results[] = $payload; }
        return [ 'success' => true ];
    }
};
}
function yoro_native_write_fixture_setup(): void {
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
}
function yoro_native_write_fixture_envelope(string $action,array $params): array {
$subject=YoRo_WordPress_Actor::current_payload()['subject'];$installation=YoRo_AI_Settings::get_installation_uuid();$expires=gmdate('c',time()+300);$identity=hash('sha256',wp_generate_uuid4());
        return ['contract_type'=>'yoro.commerce.operation_envelope','schema_version'=>1,'envelope_id'=>'envelope_'.$identity,
            'workflow_id'=>wp_generate_uuid4(),'operation_id'=>'operation_1','position'=>1,'connector_key'=>'woocommerce','connector_connection_id'=>'confirmed-commerce-fixture',
            'manifest_id'=>'confirmed-manifest','manifest_revision'=>1,'idempotency_key'=>'operation_'.$identity,'execution_mode'=>'remote_agent','action'=>$action,'params'=>$params,
            'actor'=>['contract_type'=>'yoro.commerce.actor_context','schema_version'=>1,'actor_type'=>'user','subject'=>'fixture-identity','operator_id'=>'fixture-operator',
                'workspace_id'=>'1','connector_connection_id'=>'confirmed-commerce-fixture','role'=>'store_admin','permissions'=>['yoro_execute_actions'],
                'authenticated_at'=>gmdate('c'),'assertion_id'=>'fixture-assertion','extensions'=>['wordpress.identity'=>['subject_version'=>1,'wordpress_subject'=>$subject]]],
            'plan_hash'=>str_repeat('a',64),'preview_hash'=>str_repeat('b',64),'authorization'=>['execution_permit'=>'permit_'.str_repeat('a',64),'permit_expires_at'=>$expires],
            'requested_at'=>gmdate('c'),'expires_at'=>$expires,'trace_id'=>'trace_'.$identity,
            
            'extensions'=>['yoro.mcp_early_access_commerce_write'=>['version'=>1,'action'=>$action,'source_operation_id'=>'envelope_'.str_repeat('c',64),'source_result_hash'=>str_repeat('d',64),
                'expected_version'=>$params['expected_version'],'params_hash'=>YoRo_Early_Access_Product_Read::params_hash($params),'resource'=>'https://r2-api.yorohq.com/mcp/early-access',
                'profile'=>'early_access','binding_version'=>2,'installation_uuid'=>$installation,'site_id'=>1,'site_key_id'=>1]]];
}
