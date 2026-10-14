<?php
/** Source-loaded account UI and fail-closed status. Only disposable WordPress fixtures. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Admin_Page' ) ) { require_once dirname( __DIR__, 2 ) . '/includes/admin/class-yoro-admin-page.php'; }
$failures = [];
$assert = static function( $condition, $message ) use ( &$failures ) { if ( ! $condition ) { $failures[] = $message; } };
$options = [ YoRo_AI_Settings::OPTION_AI_API_URL, YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, YoRo_AI_Settings::OPTION_AI_API_STATUS ];
$previous = [];
foreach ( $options as $name ) { $previous[ $name ] = get_option( $name, false ); }
$user_id = get_current_user_id();
try {
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
    wp_set_current_user( $admins[0] );
    $key = 'disposable-early-access-key';
    $encrypt = new ReflectionMethod( YoRo_AI_Settings::class, 'encrypt_ai_api_site_key' );
    update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, $encrypt->invoke( null, $key ), false );
    update_option( YoRo_AI_Settings::OPTION_AI_API_URL, 'https://fixture-api.test/api/v1', false );
    $status = [ 'connected' => true, 'service_available' => true, 'site' => [ 'id' => 1, 'url' => home_url() ],
        'organization' => [ 'id' => 1 ], 'account_connection' => [ 'connected' => true, 'status' => 'active' ],
        'connection' => [ 'id' => 1, 'status' => 'active', 'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(), 'plugin_version' => YORO_AI_CORE_VERSION ],
        'credential' => [ 'id' => 1, 'type' => 'wordpress_connection', 'site_connection_id' => 1, 'expires_at' => gmdate( 'c', time() + 600 ) ],
        'connector_runtime' => [ 'connector_version' => YORO_AI_CORE_VERSION, 'protocol_version' => 1, 'capability_manifest' => [ 'current' => true, 'manifest_id' => 'manifest-ui-fixture', 'revision' => 1, 'expires_at' => gmdate( 'c', time() + 600 ) ] ],
        'early_access_credential_fingerprint' => hash( 'sha256', $key ) ];
    $projection = [ 'status' => 'available', 'source' => 'api_handshake', 'site_id' => 1, 'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(),
        'connection_healthy' => true, 'compatible' => true, 'checked_at' => gmdate( 'c' ), 'expires_at' => gmdate( 'c', time() + 300 ),
        'future_endpoint' => 'https://fixture-api.test/mcp/early-access', 'runtime_enabled' => true, 'server_url' => 'https://fixture-api.test/mcp/early-access', 'grant_status' => 'not_granted' ];
    $status['early_access_onboarding'] = $projection;
    update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $status, false );
    $assert( YoRo_AI_Settings::get_early_access_onboarding()['runtime_enabled'], 'Verified API projection must retain current connectability.' );
    ob_start(); YoRo_Admin_Page::render_page(); $html = ob_get_clean();
    $assert( strpos( $html, '>ChatGPT Plugin<' ) < strpos( $html, '>Early Access<' ), 'Capsule tab order must match the approved design.' );
    $assert( false !== strpos( $html, 'id="yoro-tab-early-access" role="tab" aria-selected="true"' ), 'Early Access must be default.' );
    $assert( false === strpos( $html, 'Kết nối ChatGPT' ) && false !== strpos( $html, 'assets/images/yoro-menu-icon.svg' ), 'The English plugin tab and bundled YoRo brand icon must be present.' );
    $assert( false !== strpos( $html, 'No separate site grant is required.' ) && false === strpos( $html, 'explicitly grant product read access' ), 'Account sign-in must replace the separate grant step.' );
    $assert( false === strpos( $html, 'yoro-chat-thread' ) && false === strpos( $html, 'yoro-ai-command' ), 'The connection page must not render native chat.' );
    $assert( false !== strpos( $html, 'value="https://fixture-api.test/mcp/early-access"' ) && false !== strpos( $html, 'Sign in from ChatGPT' ) && false === strpos( $html, 'not_granted' ), 'URL availability must be separate from OAuth site consent.' );
    $assert( false !== strpos( $html, 'No separate Early Access registration or approval is required.' ), 'Open Early Access must explain that enrollment is unnecessary.' );
    $assert( false !== strpos( $html, 'awaiting OpenAI review' ), 'The reviewed plugin surface must remain distinct from the custom Early Access connection.' );
    $assert( false === strpos( $html, 'Request pending activation' ) && false === strpos( $html, 'Manage request' ), 'The connection page must not require enrollment.' );
    ob_start(); YoRo_Admin_Page::render_settings_page(); $settings_html = ob_get_clean();
    $assert( false !== strpos( $settings_html, 'MCP Server URL:' ) && false === strpos( $settings_html, 'Runtime disabled.' ) && false === strpos( $settings_html, 'Manage request' ), 'Settings must reflect live connectability without legacy enrollment copy.' );
    $assert( false === strpos( $html, $key ), 'The connection page must not disclose the WordPress key.' );
    $granted = $projection; $granted['grant_status'] = 'granted';
    $status['early_access_onboarding'] = $granted; update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $status, false );
    ob_start(); YoRo_Admin_Page::render_page(); $authorized = ob_get_clean();
    $assert( false !== strpos( $authorized, 'ChatGPT connection: Connected' ) && false !== strpos( $authorized, 'id="yoro-server-url"' ), 'Current runtime-enabled grant must still show authorized access and its verified URL.' );
    $dark_grant = $granted; $dark_grant['runtime_enabled'] = false; $dark_grant['server_url'] = '';
    $normalized_dark = YoRo_AI_Settings::normalize_early_access_onboarding( $dark_grant, $status );
    $assert( false === $normalized_dark['runtime_enabled'] && 'granted' !== ( $normalized_dark['grant_status'] ?? 'unknown' ), 'DARK must reject an inconsistent usable-grant projection.' );
    $status['early_access_onboarding'] = $dark_grant; update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $status, false );
    ob_start(); YoRo_Admin_Page::render_page(); $dark_authorized = ob_get_clean();
    $assert( false === strpos( $dark_authorized, 'ChatGPT connection: Connected' ) && false === strpos( $dark_authorized, 'id="yoro-server-url"' ) && false === strpos( $dark_authorized, 'data-yoro-copy-url' ), 'DARK must never display active ChatGPT authority or a connectable URL.' );
    $assert( false !== strpos( $dark_authorized, 'Open YoRo account' ) && $key === YoRo_AI_Settings::get_ai_api_site_key(), 'DARK must retain grant management and the separate WordPress connection key.' );
    $dark_grant['grant_status'] = 'unknown';
    $assert( 'available' === YoRo_AI_Settings::normalize_early_access_onboarding( $dark_grant, $status )['status'], 'A consistent DARK projection must preserve site readiness without implying grant access.' );
    $url = new ReflectionMethod( YoRo_Admin_Page::class, 'settings_url' );
    parse_str( wp_parse_url( $url->invoke( null, [ 'yoro_connect_callback' => 1 ] ), PHP_URL_QUERY ), $callback );
    $assert( 'early-access' === ( $callback['yoro_admin_panel'] ?? '' ), 'Hosted-connect success/error callbacks must return to Early Access.' );
    foreach ( [ ['server_url', 'https://foreign.test/mcp/early-access'], ['runtime_enabled', 'true'], ['expires_at', gmdate( 'c', time() - 1 )], ['site_id', 2], ['installation_uuid', 'foreign'], ['grant_status', 'forged'] ] as $change ) {
        $bad = $projection; $bad[ $change[0] ] = $change[1];
        $assert( 'unknown' === YoRo_AI_Settings::normalize_early_access_onboarding( $bad, $status )['status'], 'Malformed or stale status must fail closed: ' . $change[0] );
    }
    $legacy = $projection; $legacy['runtime_enabled'] = false; $legacy['status'] = 'requested_pending'; unset( $legacy['server_url'], $legacy['grant_status'] );
    $assert( false === YoRo_AI_Settings::normalize_early_access_onboarding( $legacy, $status )['runtime_enabled'], 'Historic requested_pending must remain inert.' );
    $status['early_access_onboarding'] = $legacy; update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $status, false );
    ob_start(); YoRo_Admin_Page::render_page(); $dark = ob_get_clean();
    $assert( false === strpos( $dark, 'id="yoro-server-url"' ), 'DARK or inert intent must not show a connectable URL.' );
    update_option( YoRo_AI_Settings::OPTION_AI_API_URL, '', false );
    ob_start(); YoRo_Admin_Page::render_page(); $first_connect = ob_get_clean();
    $debug_editor = defined( 'WP_DEBUG' ) && WP_DEBUG && ! YoRo_AI_Settings::is_ai_api_url_locked();
    $assert( $debug_editor === ( false !== strpos( $first_connect, 'id="yoro-account-api-url" type="url"' ) ), 'The endpoint editor must preserve the existing debug and lock guards.' );
    $assert( $debug_editor || false !== strpos( $first_connect, 'YoRo connection is not configured.' ), 'Unconfigured production must remain clearly unavailable.' );
    update_option( YoRo_AI_Settings::OPTION_AI_API_URL, 'https://fixture-api.test/api/v1', false );
    global $wp_scripts;
    $saved_scripts = $wp_scripts; $wp_scripts = new WP_Scripts();
    YoRo_Admin_Page::enqueue_assets( 'toplevel_page_yoro-ai-core' );
    $assert( [ 'yoro-onboarding' ] === $wp_scripts->queue, 'Only the connection page JS may enqueue; native chat assets stay dormant.' );
    $wp_scripts = $saved_scripts;
    $output = getenv( 'YR0174_UI_RENDER' );
    if ( is_string( $output ) && str_starts_with( $output, sys_get_temp_dir() . '/yr0174-' ) ) {
        $root = dirname( __DIR__, 2 );
        file_put_contents( $output, '<!doctype html><html lang="en"><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{background:#f6f6ef;margin:0}button,input{font:inherit} .button{display:inline-block;text-decoration:none;border:1px solid #cbd0bd;padding:6px 16px;background:white;color:#30362d;cursor:pointer}' . file_get_contents( $root . '/assets/css/yoro-ai-admin.css' ) . '</style><body>' . $html . '<script>' . file_get_contents( $root . '/assets/js/yoro-onboarding.js' ) . '</script></body></html>' );
    }
} finally {
    foreach ( $previous as $name => $value ) { false === $value ? delete_option( $name ) : update_option( $name, $value, false ); }
    wp_set_current_user( $user_id );
}
if ( $failures ) { throw new RuntimeException( implode( "\n", $failures ) ); }
echo "YoRo Early Access source UI/status/callback/asset contract passed. External E2E NOT_RUN.\n";
