<?php
/** Source-loaded passive REST projection. Run only in disposable WordPress fixtures. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$failures = [];
$assert = static function( $condition, $message ) use ( &$failures ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$option_names = [ YoRo_AI_Settings::OPTION_AI_API_URL, YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, YoRo_AI_Settings::OPTION_AI_API_STATUS, YoRo_AI_Settings::OPTION_AI_API_ENABLED, YoRo_AI_Settings::OPTION_INSTALLATION_UUID, 'home' ];
$previous = [];
foreach ( $option_names as $name ) { $previous[ $name ] = get_option( $name, false ); }
$previous_user = get_current_user_id();
$previous_cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
$token = '';
$http_filter = null;
$write_filter = null;
$query_filter = null;
$subscriber = 0;
try {
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
    $admin = (int) $admins[0];
    $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin, time() + 3600, 'logged_in' );
    $token = wp_parse_auth_cookie( '', 'logged_in' )['token'];
    wp_set_current_user( $admin );
    $nonce = wp_create_nonce( 'wp_rest' );
    $key = 'disposable-onboarding-refresh-key';
    $encrypt = new ReflectionMethod( YoRo_AI_Settings::class, 'encrypt_ai_api_site_key' );
    update_option( YoRo_AI_Settings::OPTION_AI_API_URL, 'https://fixture-api.test/api/v1', false );
    update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $encrypt->invoke( null, $key ), false );
    update_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, 'yes', false );
    $uuid = YoRo_AI_Settings::get_installation_uuid();
    $payload = [ 'success' => true, 'site' => [ 'id' => 1, 'url' => home_url() ], 'organization' => [ 'id' => 1 ],
        'account_connection' => [ 'connected' => true, 'status' => 'active' ],
        'connection' => [ 'id' => 1, 'status' => 'active', 'installation_uuid' => $uuid, 'plugin_version' => YORO_AI_CORE_VERSION ],
        // This credential would trigger rotation through the general handshake().
        'credential' => [ 'id' => 1, 'type' => 'wordpress_connection', 'site_connection_id' => 1, 'expires_at' => gmdate( 'c', time() + 600 ) ],
        'actor_access' => [ 'assigned' => true, 'status' => 'active', 'role' => 'administrator', 'permissions' => [ 'forged-new-permission' ] ],
        'connector_runtime' => [ 'connector_version' => YORO_AI_CORE_VERSION, 'protocol_version' => 1,
            'capability_manifest' => [ 'current' => true, 'manifest_id' => 'manifest-refresh', 'revision' => 1, 'expires_at' => gmdate( 'c', time() + 600 ) ] ],
        'early_access_onboarding' => [ 'status' => 'available', 'source' => 'api_handshake', 'site_id' => 1, 'installation_uuid' => $uuid,
            'connection_healthy' => true, 'compatible' => true, 'checked_at' => gmdate( 'c' ), 'expires_at' => gmdate( 'c', time() + 300 ),
            'future_endpoint' => 'https://fixture-api.test/mcp/early-access', 'runtime_enabled' => true,
            'server_url' => 'https://fixture-api.test/mcp/early-access', 'grant_status' => 'granted' ] ];
    $base = $payload;
    $http_code = 200; $transport_error = false; $change_context = false; $remove_installation = false; $requests = [];
    $http_filter = static function( $pre, $args, $url ) use ( &$payload, &$http_code, &$transport_error, &$change_context, &$remove_installation, &$requests ) {
        $requests[] = [ 'url' => $url, 'args' => $args ];
        if ( $change_context ) { global $wpdb; $wpdb->update( $wpdb->options, [ 'option_value' => 'https://changed-fixture.test/api/v1' ], [ 'option_name' => YoRo_AI_Settings::OPTION_AI_API_URL ] ); }
        if ( $remove_installation ) { delete_option( YoRo_AI_Settings::OPTION_INSTALLATION_UUID ); }
        return $transport_error ? new WP_Error( 'http_request_failed', 'secret raw timeout detail' ) : [ 'response' => [ 'code' => $http_code ], 'body' => wp_json_encode( $payload ) ];
    };
    add_filter( 'pre_http_request', $http_filter, 10, 3 );
    $server = rest_get_server();
    $routes = $server->get_routes();
    $assert( isset( $routes['/yoro-ai-core/v1/ai-api/onboarding-status'] ), 'The passive route must be registered.' );
    $request = static function( $nonce_value = null, $context = null ) use ( $nonce ) {
        $request = new WP_REST_Request( 'POST', '/yoro-ai-core/v1/ai-api/onboarding-status' );
        $request->set_header( 'X-WP-Nonce', null === $nonce_value ? $nonce : $nonce_value );
        $request->set_param( 'context', null === $context ? YoRo_AI_Settings::get_early_access_refresh_context() : $context );
        return $request;
    };
    $assert( true === YoRo_REST_API::onboarding_refresh_permission_check( $request() ), 'Cookie, nonce and MANAGE_CONNECTION must authorize the exact seam.' );
    $count = count( $requests );
    foreach ( [ '', 'invalid-nonce' ] as $bad_nonce ) {
        $response = $server->dispatch( $request( $bad_nonce ) );
        $assert( $response->get_status() >= 400, 'Missing/invalid REST nonce must fail.' );
    }
    $cookie = $_COOKIE[ LOGGED_IN_COOKIE ]; unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
    $assert( $server->dispatch( $request() )->get_status() >= 400, 'An authenticated user without logged-in cookie cannot pass.' );
    $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
    wp_set_current_user( 0 );
    $assert( $server->dispatch( $request() )->get_status() >= 400, 'Anonymous calls must fail.' );
    $subscriber = wp_create_user( 'yr0201_refresh_' . wp_generate_password( 8, false ), wp_generate_password( 24 ), 'yr0201-refresh@example.test' );
    $subscriber = is_wp_error( $subscriber ) ? 0 : $subscriber;
    $assert( $subscriber > 0, 'Disposable low-privilege fixture must exist.' );
    $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $subscriber, time() + 3600, 'logged_in' );
    wp_set_current_user( $subscriber );
    $subscriber_nonce = wp_create_nonce( 'wp_rest' );
    $assert( $server->dispatch( $request( $subscriber_nonce ) )->get_status() >= 400, 'A user without MANAGE_CONNECTION must fail.' );
    $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
    wp_set_current_user( $admin );
    $assert( $count === count( $requests ), 'Rejected calls must cause no API request.' );
    $before_options = [];
    foreach ( $option_names as $name ) { $before_options[ $name ] = get_option( $name, false ); }
    $before_meta = get_user_meta( $admin );
    $writes = [];
    $write_filter = static function( $value, $option, $old ) use ( &$writes ) { if ( $value !== $old ) { $writes[] = $option; } return $value; };
    add_filter( 'pre_update_option', $write_filter, 10, 3 );
    $sql_writes = [];
    $query_filter = static function( $query ) use ( &$sql_writes ) {
        if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i', $query ) ) { $sql_writes[] = $query; }
        return $query;
    };
    add_filter( 'query', $query_filter );
    foreach ( [ 'granted', 'not_granted', 'revoked', 'unknown' ] as $grant_status ) {
        $payload = $base; $payload['early_access_onboarding']['grant_status'] = $grant_status;
        $response = $server->dispatch( $request() ); $data = $response->get_data();
        $assert( 200 === $response->get_status() && $grant_status === ( $data['projection']['grant_status'] ?? null ), 'Fresh validated grant fact must survive: ' . $grant_status );
        $assert( 'no-store, private' === ( $response->get_headers()['Cache-Control'] ?? '' ), 'Refresh responses must not be cached.' );
        $assert( ! array_diff( array_keys( $data['projection'] ), [ 'status', 'grant_status', 'runtime_enabled', 'connection_healthy', 'compatible', 'checked_at', 'expires_at' ] ), 'Return only browser projection fields.' );
        $serialized = wp_json_encode( $data );
        foreach ( [ $key, 'forged-new-permission', 'organization', 'credential', 'installation_uuid', 'secret raw' ] as $secret ) {
            $assert( ! str_contains( $serialized, $secret ), 'Browser response must omit private fields: ' . $secret );
        }
    }
    remove_filter( 'query', $query_filter ); $query_filter = null;
    $assert( [] === $sql_writes, 'Successful passive refresh has no database writes, including grants and commerce.' );
    $assert( [] === $writes, 'Passive refresh must not write settings, credentials, grant, actor or commerce options.' );
    $assert( $before_meta === get_user_meta( $admin ), 'Passive refresh must not record actor access.' );
    foreach ( $before_options as $name => $value ) { $assert( $value === get_option( $name, false ), 'Passive refresh preserves option: ' . $name ); }
    remove_filter( 'pre_update_option', $write_filter, 10 ); $write_filter = null;
    foreach ( $requests as $sent ) {
        $assert( 'https://fixture-api.test/api/v1/sites/onboarding-status' === strtok( $sent['url'], '?' ), 'Passive refresh uses only the read-only status endpoint.' );
        $assert( 'GET' === $sent['args']['method'] && 0 === $sent['args']['redirection'], 'Status reads use bounded GET without redirects.' );
        $assert( 15 === $sent['args']['timeout'], 'Existing upstream request timeout stays bounded.' );
    }
    $cached = [ 'early_access_onboarding' => array_merge( $base['early_access_onboarding'], [ 'expires_at' => gmdate( 'c', time() - 1 ) ] ) ];
    update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $cached, false );
    $payload = $base;
    $assert( 'granted' === $server->dispatch( $request() )->get_data()['projection']['grant_status'], 'Stale cached status must be replaced by fresh transport facts.' );
    $payload['early_access_onboarding']['site_id'] = 99;
    $assert( 'unknown' === $server->dispatch( $request() )->get_data()['projection']['status'], 'Malformed fresh transport must not fall back to a cached verified grant.' );
    $mutations = [ ['site_id', 2], ['installation_uuid', 'foreign'], ['server_url', 'https://foreign.test/mcp/early-access'],
        ['future_endpoint', 'https://foreign.test/mcp/early-access'], ['expires_at', gmdate( 'c', time() - 1 )],
        ['checked_at', gmdate( 'c', time() + 60 )], ['expires_at', gmdate( 'c', time() + 301 )], ['runtime_enabled', 'true'],
        ['connection_healthy', false], ['compatible', false], ['grant_status', 'forged'] ];
    foreach ( $mutations as [ $field, $value ] ) {
        $payload = $base; $payload['early_access_onboarding'][ $field ] = $value;
        $data = $server->dispatch( $request() )->get_data();
        $assert( 'unknown' === ( $data['projection']['status'] ?? null ) && ! isset( $data['projection']['grant_status'] ), 'Malformed/stale/context-mismatched projection fails closed: ' . $field );
    }
    $payload = $base; unset( $payload['early_access_onboarding']['grant_status'] );
    $data = $server->dispatch( $request() )->get_data();
    $assert( ! isset( $data['projection']['grant_status'] ), 'Missing grant facts never establish sign-in or connected state.' );
    $payload = $base; $payload['early_access_onboarding']['runtime_enabled'] = false; $payload['early_access_onboarding']['server_url'] = ''; $payload['early_access_onboarding']['grant_status'] = 'unknown';
    $data = $server->dispatch( $request() )->get_data();
    $assert( false === $data['projection']['runtime_enabled'] && 'unknown' === $data['projection']['grant_status'], 'Runtime-disabled remains distinct and never connected.' );
    foreach ( [ 'connection', 'credential', 'connector_runtime' ] as $missing_context ) {
        $payload = $base; unset( $payload[ $missing_context ] );
        $data = $server->dispatch( $request() )->get_data();
        $assert( 'unknown' === $data['projection']['status'], 'Missing handshake context cannot assert access: ' . $missing_context );
    }
    foreach ( [ 401, 403, 429, 500, 503 ] as $code ) {
        $http_code = $code; $payload = [ 'message' => 'private upstream detail including ' . $key ];
        $data = $server->dispatch( $request() )->get_data();
        $assert( 'unavailable' === $data['state'] && [] === $data['projection'] && ! str_contains( wp_json_encode( $data ), $key ), 'Generic HTTP error is neutral and secretless: ' . $code );
    }
    $transport_error = true; $data = $server->dispatch( $request() )->get_data();
    $assert( 'unavailable' === $data['state'] && [] === $data['projection'], 'Timeout/offline must remain unavailable.' );
    $transport_error = false; $http_code = 200; $payload = $base;
    $assert( 'granted' === $server->dispatch( $request() )->get_data()['projection']['grant_status'], 'A fresh healthy result recovers after a transport failure.' );
    $count = count( $requests ); $data = $server->dispatch( $request( null, 'old-page-context' ) )->get_data();
    $assert( 'connection_changed' === $data['state'] && $count === count( $requests ), 'Obsolete page context stops before API I/O.' );
    $change_context = true; $data = $server->dispatch( $request() )->get_data();
    $assert( 'connection_changed' === $data['state'] && [] === $data['projection'], 'Changing connection during I/O invalidates the response.' );
    $change_context = false;
    update_option( YoRo_AI_Settings::OPTION_AI_API_URL, 'https://fixture-api.test/api/v1', false );
    $remove_installation = true;
    $data = $server->dispatch( $request() )->get_data();
    $assert( 'connection_changed' === $data['state'] && false === get_option( YoRo_AI_Settings::OPTION_INSTALLATION_UUID, false ), 'A concurrent installation deletion must not recreate an identity while normalizing the response.' );
    $remove_installation = false;
    update_option( YoRo_AI_Settings::OPTION_INSTALLATION_UUID, $uuid, false );
    if ( $export = getenv( 'YR0201_ONBOARDING_STATUS_EXPORT' ) ) {
        $wire = json_decode( file_get_contents( $export ), true, 512, JSON_THROW_ON_ERROR );
        update_option( 'home', $wire['site_url'], false );
        update_option( YoRo_AI_Settings::OPTION_INSTALLATION_UUID, $wire['installation_uuid'], false );
        update_option( YoRo_AI_Settings::OPTION_AI_API_URL, 'https://r2-api.yorohq.com/api/v1', false );
        update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $encrypt->invoke( null, $wire['site_key'] ), false );
        foreach ( [ 'granted', 'unknown', 'revoked', 'not_granted' ] as $grant_status ) {
            $payload = $wire['responses'][ $grant_status ];
            $data = $server->dispatch( $request() )->get_data();
            $assert( $grant_status === ( $data['projection']['grant_status'] ?? null ), 'Actual API GET response must pass the actual Core client and normalizer: ' . $grant_status );
        }
        echo "Exact source API GET -> Core REST/client/normalizer conformance passed for granted/unknown/revoked/not_granted.\n";
        update_option( 'home', $previous['home'], false );
        update_option( YoRo_AI_Settings::OPTION_INSTALLATION_UUID, $uuid, false );
        update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $encrypt->invoke( null, $key ), false );
    }
    update_option( YoRo_AI_Settings::OPTION_AI_API_URL, 'https://fixture-api.test/api/v1', false );
    update_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, 'no', false );
    $count = count( $requests ); $data = $server->dispatch( $request() )->get_data();
    $assert( 'connection_required' === $data['state'] && $count === count( $requests ), 'Disabled WordPress connection cannot perform passive API I/O.' );
    update_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, 'yes', false );
    delete_option( YoRo_AI_Settings::OPTION_INSTALLATION_UUID );
    $count = count( $requests ); $data = $server->dispatch( $request() )->get_data();
    $assert( 'connection_required' === $data['state'] && $count === count( $requests ) && false === get_option( YoRo_AI_Settings::OPTION_INSTALLATION_UUID, false ), 'A passive refresh cannot create a missing installation identity.' );
    update_option( YoRo_AI_Settings::OPTION_INSTALLATION_UUID, 'invalid-disposable-installation', false );
    $count = count( $requests ); $server->dispatch( $request() );
    $assert( $count === count( $requests ) && 'invalid-disposable-installation' === get_option( YoRo_AI_Settings::OPTION_INSTALLATION_UUID ), 'Passive actor assertion must not repair an invalid installation.' );
    update_option( YoRo_AI_Settings::OPTION_INSTALLATION_UUID, $uuid, false );
    update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, '', false );
    $count = count( $requests ); $data = $server->dispatch( $request() )->get_data();
    $assert( 'connection_required' === $data['state'] && $count === count( $requests ), 'Missing WordPress credentials require local connection repair without API I/O.' );
} finally {
    if ( $http_filter ) { remove_filter( 'pre_http_request', $http_filter, 10 ); }
    if ( $write_filter ) { remove_filter( 'pre_update_option', $write_filter, 10 ); }
    if ( $query_filter ) { remove_filter( 'query', $query_filter ); }
    foreach ( $previous as $name => $value ) { false === $value ? delete_option( $name ) : update_option( $name, $value, false ); }
    if ( $token ) { WP_Session_Tokens::get_instance( $admin )->destroy( $token ); }
    if ( $subscriber ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $subscriber ); }
    if ( null === $previous_cookie ) { unset( $_COOKIE[ LOGGED_IN_COOKIE ] ); } else { $_COOKIE[ LOGGED_IN_COOKIE ] = $previous_cookie; }
    wp_set_current_user( $previous_user );
}
if ( $failures ) { throw new RuntimeException( implode( "\n", $failures ) ); }
echo "YoRo source-loaded passive onboarding REST permission/nonce/projection/side-effect/recovery contracts passed. External OAuth/API NOT_RUN.\n";
