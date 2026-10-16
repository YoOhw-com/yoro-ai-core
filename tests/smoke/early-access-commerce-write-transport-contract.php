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
    $client->budget_remaining = 3;
    $budget_result = $runtime->run_early_access_write_once();
    $assert( ! is_wp_error( $budget_result ) && ! empty( $budget_result['throttled'] ) && [] === $client->steps, 'Insufficient pipeline capacity must stop before claim.' );
    $journal_key = new ReflectionMethod( YoRo_Connector_Execution_Journal::class, 'key' );
    foreach ( [ 1, 2, 3 ] as $drop ) {
        $client->budget_remaining = null; $client->budget_drop_step = $drop; $client->steps = [];
        $budget_result = $runtime->run_early_access_write_once();
        $assert( is_wp_error( $budget_result ) && 'yoro_connector_transport_budget' === $budget_result->get_error_code(), 'Quota lost after claim/ack/lease must stop before execution.' );
        $assert( count( $client->steps ) === $drop && 5 === wc_get_product( $product->get_id() )->get_stock_quantity() && false === get_option( $journal_key->invoke( null, $client->operation ) ), 'Insufficient callback capacity must not mutate or begin a journal.' );
    }
    $client->budget_remaining = null; $client->budget_drop_step = null; $client->steps = [];
    // A slow read may finish after the soft deadline; it must not start a new write.
    $release->invoke( null, $lock ); $lock = null; $virtual_time = 0.0;
    $deadline_runtime = new class( $client, static function() use ( &$virtual_time ) { return $virtual_time; } ) extends YoRo_Connector_Runtime {
        public $after_read;
        public function run_early_access_once() { ( $this->after_read )(); return [ 'success' => true, 'empty' => true ]; }
        public function run_early_access_write_once() { throw new RuntimeException( 'A write started after the session deadline.' ); }
    };
    $deadline_runtime->after_read = static function() use ( &$virtual_time ) { $virtual_time = 60.0; };
    $round_method = new ReflectionMethod( YoRo_Connector_Runtime::class, 'early_access_session_round' );
    $round_result = $round_method->invoke( $deadline_runtime, false, 55.0 );
    $assert( 1 === $client->next_attempts, 'A legacy server is probed once before falling back to the lane deadline guard.' );
    $assert( [ 'reads' => 0, 'writes' => 0 ] === $round_result && 60.0 === $virtual_time, 'A late read finishes safely but cannot start another lane.' );
    $lock = $acquire->invoke( null );
    for ( $attempt = 0; $attempt < 2; $attempt++ ) {
        $r = $runtime->run_early_access_write_once();
        $assert( ! is_wp_error( $r ) && true === ( $r['success'] ?? null ), 'The fenced runner delivers a terminal result: ' . ( is_wp_error( $r ) ? $r->get_error_code() : '' ) );
        $assert( 'executed' === end( $client->results )['status'] && 7 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'A completed journal replays without another mutation or stale-version failure.' );
    }
    $assert( [ 'claim', 'ack', 'lease', 'result', 'claim', 'ack', 'lease', 'result' ] === $client->steps, 'Both attempts use only the bounded fenced lane.' );
    $product_operation = $client->operation;
    $order = new WC_Order(); $order->set_status( 'on-hold' ); $order->set_total( 0 ); $order->save();
    $order_version = YoRo_Order_Presenter::order_version( wc_get_order( $order->get_id() ) );
    $order_identity = hash( 'sha256', $identity . '|order-status' );
    $client->operation['envelope_id'] = 'envelope_' . $order_identity; $client->operation['idempotency_key'] = 'operation_' . $order_identity;
    $client->operation['action'] = 'orders.update_status';
    $client->operation['params'] = [ 'order_ids' => [ $order->get_id() ], 'status' => 'pending',
        'expected_versions' => [ [ 'order_id' => $order->get_id(), 'expected_version' => $order_version ] ] ];
    $client->operation['extensions']['yoro.mcp_early_access_commerce_write']['action'] = 'orders.update_status';
    $client->operation['extensions']['yoro.mcp_early_access_commerce_write']['expected_version'] = $order_version;
    // Independent API wire witness: object keys sort recursively; list order stays intact.
    $canonical_params = [ 'expected_versions' => [ [ 'expected_version' => $order_version, 'order_id' => $order->get_id() ] ],
        'order_ids' => [ $order->get_id() ], 'status' => 'pending' ];
    $client->operation['extensions']['yoro.mcp_early_access_commerce_write']['params_hash'] = hash( 'sha256', wp_json_encode( $canonical_params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) );
    $tampered_order = $client->operation; $client->operation['params']['status'] = 'completed';
    $steps_before = count( $client->steps );
    $r = $runtime->run_early_access_write_once();
    $assert( is_wp_error( $r ) && 'confirmed_commerce_authority_invalid' === $r->get_error_code()
        && count( $client->steps ) === $steps_before + 1 && 'on-hold' === wc_get_order( $order->get_id() )->get_status(), 'A changed status fails the wire hash before acknowledgement or mutation.' );
    $client->operation = $tampered_order;
    for ( $attempt = 0; $attempt < 2; $attempt++ ) {
        $r = $runtime->run_early_access_write_once();
        $assert( ! is_wp_error( $r ) && 'executed' === end( $client->results )['status']
            && 'pending' === wc_get_order( $order->get_id() )->get_status(), 'The actual runner accepts the API nested-object hash and replays its completed journal.' );
    }
    $client->operation = $product_operation;
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
    $base_operation = $client->operation;
    $fresh = static function( $suffix ) use ( $base_operation, $product ) {
        $op = $base_operation; $id = hash( 'sha256', $base_operation['envelope_id'] . $suffix );
        $op['envelope_id'] = 'envelope_' . $id; $op['idempotency_key'] = 'operation_' . $id;
        $op['params']['stock_quantity'] = 12;
        $op['params']['expected_version'] = YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $product->get_id() ) );
        $op['extensions']['yoro.mcp_early_access_commerce_write']['expected_version'] = $op['params']['expected_version'];
        $op['extensions']['yoro.mcp_early_access_commerce_write']['params_hash'] = YoRo_Early_Access_Product_Read::params_hash( $op['params'] );
        return $op;
    };
    foreach ( [ false, true ] as $cache_only ) {
        $client->operation = $fresh( 'journal-failure-' . (int) $cache_only );
        // First simulate an unsuccessful INSERT, then a false-positive option-cache write.
        $fault = static function( $sql ) use ( $cache_only ) {
            if ( str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, 'yoro_connector_journal_' ) ) {
                return $cache_only ? 'SELECT 1' : 'SELECT 1 WHERE 1 = 0';
            }
            return $sql;
        };
        add_filter( 'query', $fault );
        try { $r = $runtime->run_early_access_write_once(); } finally { remove_filter( 'query', $fault ); }
        $assert( is_wp_error( $r ) && 'yoro_connector_journal_unavailable' === $r->get_error_code() && 9 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'A failed or cache-only journal write never reaches the mutator.' );
    }
    $client->operation = $fresh( 'journal-completion-failure' );
    $fail_completion = static function( $sql ) {
        return str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, 'yoro_connector_journal_' ) ? 'SELECT 1 WHERE 1 = 0' : $sql;
    };
    add_filter( 'query', $fail_completion );
    try { $r = $runtime->run_early_access_write_once(); } finally { remove_filter( 'query', $fail_completion ); }
    $assert( is_wp_error( $r ) && 'yoro_connector_journal_unavailable' === $r->get_error_code() && 12 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'A completion persistence failure cannot publish a successful outcome.' );
    $journal_key = new ReflectionMethod( YoRo_Connector_Execution_Journal::class, 'key' );
    $key_name = $journal_key->invoke( null, $client->operation );
    $entry = get_option( $key_name ); $entry['expires_at'] = time() - 1; update_option( $key_name, $entry, false );
    $assert( 'executing' === YoRo_Connector_Execution_Journal::begin( $client->operation )['state'], 'Expiry never resets a prior write marker.' );
    $tampered = $client->operation; $tampered['params']['stock_quantity'] = 15;
    $assert( 'conflict' === YoRo_Connector_Execution_Journal::begin( $tampered )['state'], 'The marker binds the entire exact write envelope.' );
    $r = $runtime->run_early_access_write_once();
    $assert( ! is_wp_error( $r ) && 'local_execution_outcome_unknown' === ( end( $client->results )['error']['code'] ?? '' ) && 12 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'Completion failure recovers as unknown, without repeating the callback.' );
    $client->operation = $fresh( 'lost-lock-after-lease' );
    $client->after_lease = static function() use ( $lock ) {
        global $wpdb;
        $name = new ReflectionProperty( YoRo_Connector_Runtime::class, 'held_lock_name' );
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name->getValue() ) );
    };
    $r = $runtime->run_early_access_write_once();
    $client->after_lease = null;
    $assert( is_wp_error( $r ) && 'yoro_connector_runner_lock_lost' === $r->get_error_code() && 12 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'Losing GET_LOCK in the lease response never reaches the write callback.' );
    $release->invoke( null, $lock ); $lock = $acquire->invoke( null );
    $status['early_access_onboarding']['confirmed_commerce_writes_enabled'] = false;
    YoRo_AI_Settings::update_ai_api_status( $status );
    $assert( is_wp_error( $runtime->run_early_access_write_once() ), 'Runtime revocation stops the writer while the read lane remains available.' );
    echo "YoRo confirmed commerce runner fencing, journal replay, unknown outcome and runtime revocation passed.\n";
} finally {
    remove_filter( 'pre_http_request', $block );
    remove_filter( 'pre_wp_mail', '__return_true' );
    if ( null !== $lock ) { $release->invoke( null, $lock ); }
    $wpdb->query( 'ROLLBACK' ); wp_cache_flush(); wp_set_current_user( $original );
}
