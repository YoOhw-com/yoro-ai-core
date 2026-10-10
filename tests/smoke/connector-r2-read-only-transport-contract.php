<?php
/** Disposable WordPress 7.x/Woo HTTP witness. Never run against the physical canary. */
if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}
if ( ! class_exists( 'YoRo_Connector_Runtime' ) ) {
    YoRo_AI_Core::init();
}
if ( 'https://r2-api.yorohq.com' !== YoRo_Connector_Client::R2_ORIGIN ) {
    throw new RuntimeException( 'R2 transport must pin the exact custom origin.' );
}
if ( YoRo_Connector_Runtime::r2_read_allowed() ) {
    throw new RuntimeException( 'The R2 source contract must begin default OFF.' );
}
$root = getenv( 'YR0166_DISPOSABLE_WP' );
if ( ! is_string( $root ) || realpath( $root ) !== realpath( ABSPATH ) ) {
    echo "YoRo R2 default OFF contract passed.\n";
    return;
}
if ( 'http://yr0166.invalid' !== untrailingslashit( home_url() ) ) {
    throw new RuntimeException( 'Only the explicitly owned disposable WordPress fixture is eligible.' );
}
$export = getenv( 'YR0166_CORE_EXPORT' );
if ( is_string( $export ) && '' !== $export ) {
    wp_set_current_user( 1 );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data' ] ] );
    $manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( [ 'connector_key' => 'wordpress',
        'connector_connection_id' => 'yr0166_disposable_connection', 'execution_mode' => 'remote_agent', 'protocol_version' => 1 ], 1 );
    file_put_contents( $export, wp_json_encode( [ 'manifest' => $manifest, 'actor' => YoRo_WordPress_Actor::current_payload(),
        'installation_uuid' => YoRo_AI_Settings::get_installation_uuid() ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    echo "YoRo R2 source-loaded manifest and read actor exported.\n";
    return;
}
$packet_path = getenv( 'YR0166_HTTP_PACKET' );
$packet = is_string( $packet_path ) ? json_decode( file_get_contents( $packet_path ), true ) : null;
if ( ! is_array( $packet ) ) {
    throw new RuntimeException( 'The disposable API HTTP packet is required.' );
}
$home = get_option( 'home' );
$status = get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS );
$manifest_state = get_option( YoRo_Connector_Snapshot_Publisher::OPTION_MANIFEST_STATE );
$product = new WC_Product_Simple();
$product->set_name( 'YR0166 safe product' );
$product->set_sku( 'YR0166.SAFE-1' );
$product->set_status( 'publish' );
$product->set_regular_price( '12.30' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 4 );
$product->save();
$snapshot = static function () {
    global $wpdb;
    $receipt = [];
    foreach ( [ $wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'wc_product_meta_lookup', $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_order_addresses', $wpdb->prefix . 'wc_customer_lookup' ] as $table ) {
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            $receipt[ $table ] = hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ) ) );
        }
    }
    return $receipt;
};
try {
    update_option( 'home', 'https://wp-canary-us.yorohq.com' );
    $installation = YoRo_AI_Settings::get_installation_uuid();
    if ( $installation !== $packet['installation_uuid'] ) {
        throw new RuntimeException( 'The API packet has another fixture installation.' );
    }
    define( 'YORO_R2_PRODUCT_READ_ENABLED', true );
    define( 'YORO_R2_PRODUCT_READ_KEY', $packet['plain_key'] );
    define( 'YORO_R2_INSTALLATION_UUID', $installation );
    YoRo_AI_Settings::update_ai_api_status( [
        'organization' => [ 'id' => $packet['organization_id'] ], 'account_connection' => [ 'connected' => true, 'status' => 'active' ], 'site' => [ 'id' => $packet['site_id'], 'status' => 'active' ],
        'connection' => [ 'id' => $packet['connection_id'], 'installation_uuid' => $installation, 'status' => 'active', 'environment' => 'staging' ],
        'credential' => [ 'id' => $packet['key_id'], 'type' => 'wordpress_connection', 'site_connection_id' => $packet['connection_id'], 'expires_at' => gmdate( 'c', time() + 600 ) ],
        'connector_runtime' => [ 'connector_key' => 'wordpress', 'connector_connection_id' => $packet['connector_connection_id'],
            'execution_mode' => 'remote_agent', 'protocol_version' => 1, 'delivery_protocol' => 'pull_v1',
            'cutover' => [ 'cutover_allowed' => false ] ],
    ] );
    update_option( YoRo_Connector_Snapshot_Publisher::OPTION_MANIFEST_STATE, [ 'manifest_id' => $packet['manifest_id'],
        'revision' => $packet['manifest_revision'], 'expires_at' => gmdate( 'c', time() + 600 ) ], false );
    $requests = [];
    $observed_operation = null;
    $bridge = static function ( $pre, $args, $url ) use ( &$requests, &$observed_operation, $packet ) {
        if ( 0 !== strpos( $url, YoRo_Connector_Client::R2_ORIGIN . '/api/v1/r2/product-reads/' ) ) {
            return $pre;
        }
        if ( 'https://r2-api.yorohq.com' !== substr( $url, 0, strlen( 'https://r2-api.yorohq.com' ) )
            || 0 !== $args['redirection'] || true !== $args['sslverify'] || 65536 !== $args['limit_response_size'] ) {
            throw new RuntimeException( 'R2 outbound origin, TLS or redirect boundary widened.' );
        }
        $requests[] = parse_url( $url, PHP_URL_PATH );
        $args['headers']['Host'] = parse_url( YoRo_Connector_Client::R2_ORIGIN, PHP_URL_HOST );
        $args['sslcertificates'] = $packet['tls_ca'];
        $response = wp_remote_request( 'https://127.0.0.1:' . $packet['tls_port'] . parse_url( $url, PHP_URL_PATH ), $args );
        if ( ! is_wp_error( $response ) && str_ends_with( $url, '/claim' ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $observed_operation = $body['delivery']['operations'][0] ?? null;
            if ( 'crash' === $packet['mode'] && is_array( $observed_operation ) ) {
                YoRo_Connector_Execution_Journal::begin( $observed_operation );
            }
        }
        return $response;
    };
    add_filter( 'pre_http_request', $bridge, 10, 3 );
    $before = $snapshot();
    $result = YoRo_Connector_Runtime::r2_tick();
    if ( is_wp_error( $result ) || true !== ( $result['success'] ?? null ) || $before !== $snapshot() ) {
        throw new RuntimeException( is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'The network read mutated business state or failed.' );
    }
    if ( 4 !== count( $requests ) || ! str_ends_with( $requests[0], '/claim' ) || ! str_ends_with( $requests[1], '/ack' )
        || ! str_ends_with( $requests[2], '/lease' ) || ! str_ends_with( $requests[3], '/result' ) || YoRo_Connector_Runtime::live_cutover_allowed() ) {
        throw new RuntimeException( 'The dedicated HTTP sequence or generic cutover invariant failed.' );
    }
    if ( ! is_array( $observed_operation ) || 'completed' !== ( YoRo_Connector_Execution_Journal::begin( $observed_operation )['state'] ?? '' ) ) {
        throw new RuntimeException( 'The exact R2 completed journal must replay.' );
    }
    $tampered = $observed_operation;
    $tampered['params']['sku'] = 'OTHER';
    if ( 'conflict' !== ( YoRo_Connector_Execution_Journal::begin( $tampered )['state'] ?? '' ) ) {
        throw new RuntimeException( 'The R2 journal must reject another envelope under the same idempotency key.' );
    }
    $validate = new ReflectionMethod( YoRo_Connector_Runtime::class, 'validate_r2_operation' );
    $runtime = new YoRo_Connector_Runtime();
    if ( 'https://r2-api.yorohq.com/mcp/early-access' !== ( $observed_operation['extensions']['yoro.mcp_r2_product_read']['resource'] ?? null ) ) {
        throw new RuntimeException( 'The source-loaded API operation must bind the exact custom resource.' );
    }
    foreach ( [ 'https://yoro-ai-production-r2-nonproduction-7sh7ol.laravel.cloud/mcp/early-access',
        'https://api.yorohq.com/mcp/early-access', 'http://r2-api.yorohq.com/mcp/early-access',
        'https://r2-api.yorohq.com:8443/mcp/early-access', 'https://r2-api.yorohq.com/mcp' ] as $resource ) {
        $bad = $observed_operation;
        $bad['extensions']['yoro.mcp_r2_product_read']['resource'] = $resource;
        if ( ! is_wp_error( $validate->invoke( $runtime, $bad ) ) ) {
            throw new RuntimeException( 'A legacy or foreign R2 operation resource must fail closed.' );
        }
    }
    foreach ( [ [ 'sku', 'OTHER' ], [ 'type', 'variation' ], [ 'status', 'draft' ], [ 'limit', 2 ] ] as $change ) {
        $bad = $observed_operation;
        $bad['params'][ $change[0] ] = $change[1];
        if ( ! is_wp_error( $validate->invoke( $runtime, $bad ) ) ) {
            throw new RuntimeException( 'A widened R2 product operation must fail closed.' );
        }
    }
    update_option( 'home', 'https://other.yr0166.invalid' );
    if ( YoRo_Connector_Runtime::r2_read_allowed() ) {
        throw new RuntimeException( 'The R2 lane must reject another host.' );
    }
    update_option( 'home', 'https://wp-canary-us.yorohq.com' );
    remove_filter( 'pre_http_request', $bridge, 10 );
    echo "YoRo R2 true HTTPS Core/API claim-ack-renew-result/journal replay-crash witness passed; zero Woo business mutations.\n";
} finally {
    update_option( 'home', $home );
    update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $status );
    update_option( YoRo_Connector_Snapshot_Publisher::OPTION_MANIFEST_STATE, $manifest_state );
    $product->delete( true );
}
