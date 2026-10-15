<?php
/** Disposable locked runner witness. No staging/production keys or network requests. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Connector_Runtime' ) ) { YoRo_AI_Core::init(); }
if ( ! class_exists( 'WooCommerce' ) ) { echo "Confirmed commerce runner unavailable without WooCommerce.\n"; return; }
if ( str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'yorohq.com' ) ) { throw new RuntimeException( 'Never run write transport fixtures on canary or production.' ); }
$assert = static function( $ok, $why ) { if ( ! $ok ) { throw new RuntimeException( $why ); } };
$original = get_current_user_id();
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$block = static function() { throw new RuntimeException( 'The disposable write runner must not make a network request.' ); };
$client = new class extends YoRo_Connector_Client {
    public $operation; public $results = []; public $steps = [];
    public function early_access_write_request( $step, $id = '', $payload = [], $token = '', $generation = 0 ) {
        $this->steps[] = $step;
        if ( 'claim' === $step ) { return [ 'success' => true, 'delivery' => [ 'delivery_id' => 'fixture-delivery', 'claim_token' => str_repeat( 'a', 80 ), 'lease_generation' => 1, 'operations' => [ $this->operation ] ] ]; }
        if ( str_repeat( 'a', 80 ) !== $token || 1 !== $generation || $id !== $this->operation['envelope_id'] ) { throw new RuntimeException( 'The fenced lease was lost.' ); }
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
    $product = new WC_Product_Simple(); $product->set_name( 'Disposable write runner product' ); $product->set_status( 'publish' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 5 ); $product->save(); $product = wc_get_product( $product->get_id() );
    $version = YoRo_Commerce_Discovery_Service::product_version( $product );
    $params = [ 'product_id' => $product->get_id(), 'stock_quantity' => 7, 'expected_version' => $version ];
    $identity = hash( 'sha256', wp_generate_uuid4() );
    $client->operation = [ 'contract_type' => 'yoro.commerce.operation_envelope', 'schema_version' => 1, 'envelope_id' => 'envelope_' . $identity,
        'workflow_id' => wp_generate_uuid4(), 'operation_id' => 'operation_1', 'position' => 1, 'connector_key' => 'woocommerce',
        'connector_connection_id' => 'confirmed-commerce-fixture', 'manifest_id' => 'confirmed-manifest', 'manifest_revision' => 1,
        'idempotency_key' => 'operation_' . $identity, 'execution_mode' => 'remote_agent', 'action' => 'products.update_stock', 'params' => $params,
        'actor' => [ 'contract_type' => 'yoro.commerce.actor_context', 'schema_version' => 1, 'actor_type' => 'user', 'subject' => 'fixture-identity', 'operator_id' => 'fixture-operator',
            'workspace_id' => '1', 'connector_connection_id' => 'confirmed-commerce-fixture', 'role' => 'store_admin', 'permissions' => [ 'yoro_execute_actions' ],
            'authenticated_at' => gmdate( 'c' ), 'assertion_id' => 'fixture-assertion', 'extensions' => [ 'wordpress.identity' => [ 'subject_version' => 1, 'wordpress_subject' => $subject ] ] ],
        'plan_hash' => str_repeat( 'a', 64 ), 'preview_hash' => str_repeat( 'b', 64 ),
        'authorization' => [ 'execution_permit' => 'permit_' . str_repeat( 'a', 64 ), 'permit_expires_at' => $expires ],
        'requested_at' => gmdate( 'c' ), 'expires_at' => $expires, 'trace_id' => 'trace_' . $identity,
        'extensions' => [ 'yoro.mcp_early_access_commerce_write' => [ 'version' => 1, 'action' => 'products.update_stock',
            'source_operation_id' => 'envelope_' . str_repeat( 'c', 64 ), 'source_result_hash' => str_repeat( 'd', 64 ),
            'expected_version' => $version, 'params_hash' => YoRo_Early_Access_Product_Read::params_hash( $params ),
            'resource' => 'https://r2-api.yorohq.com/mcp/early-access', 'profile' => 'early_access', 'binding_version' => 2,
            'installation_uuid' => $installation, 'site_id' => 1, 'site_key_id' => 1 ] ] ];
    add_filter( 'pre_http_request', $block );
    $lock = $acquire->invoke( null ); $assert( null !== $lock, 'The runner owns its existing database lock.' );
    $runtime = new YoRo_Connector_Runtime( $client );
    for ( $attempt = 0; $attempt < 2; $attempt++ ) {
        $r = $runtime->run_early_access_write_once();
        $assert( ! is_wp_error( $r ) && true === ( $r['success'] ?? null ), 'The fenced runner delivers a terminal result: ' . ( is_wp_error( $r ) ? $r->get_error_code() : '' ) );
        $assert( 'executed' === end( $client->results )['status'] && 7 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'A completed journal replays without another mutation or stale-version failure.' );
    }
    $assert( [ 'claim', 'ack', 'lease', 'result', 'claim', 'ack', 'lease', 'result' ] === $client->steps, 'Both attempts use only the bounded fenced lane.' );
    $crash_id = hash( 'sha256', $identity . '|crash' );
    $client->operation['envelope_id'] = 'envelope_' . $crash_id; $client->operation['idempotency_key'] = 'operation_' . $crash_id;
    $client->operation['params']['expected_version'] = YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $product->get_id() ) );
    $client->operation['params']['stock_quantity'] = 9;
    $client->operation['extensions']['yoro.mcp_early_access_commerce_write']['expected_version'] = $client->operation['params']['expected_version'];
    $client->operation['extensions']['yoro.mcp_early_access_commerce_write']['params_hash'] = YoRo_Early_Access_Product_Read::params_hash( $client->operation['params'] );
    // Simulate a crash after the mutation but before journal completion; the callback must never be rerun.
    YoRo_Connector_Execution_Journal::begin( $client->operation );
    wc_update_product_stock( $product->get_id(), 9, 'set' );
    $r = $runtime->run_early_access_write_once();
    $last = end( $client->results );
    $assert( ! is_wp_error( $r ) && 'local_execution_outcome_unknown' === ( $last['error']['code'] ?? '' ) && 9 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'Interrupted journal authority produces unknown without replaying the write callback.' );
    $status['early_access_onboarding']['confirmed_commerce_writes_enabled'] = false;
    YoRo_AI_Settings::update_ai_api_status( $status );
    $assert( is_wp_error( $runtime->run_early_access_write_once() ), 'Runtime revocation stops the writer while the read lane remains available.' );
    echo "YoRo confirmed commerce runner fencing, journal replay, unknown outcome and runtime revocation passed.\n";
} finally {
    remove_filter( 'pre_http_request', $block );
    if ( null !== $lock ) { $release->invoke( null, $lock ); }
    $wpdb->query( 'ROLLBACK' ); wp_cache_flush(); wp_set_current_user( $original );
}
