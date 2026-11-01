<?php
/** Source-only five-tab Settings fixture; synthetic API/settings, no site, mail or provider effects. */
define( 'ABSPATH', __DIR__ );
define( 'YORO_AI_CORE_FILE', dirname( __DIR__, 2 ) . '/yoro-ai-core.php' );
function plugins_url( $path, $file ) {
    if ( ! in_array( $path, [ 'assets/images/yoro-c-64-static.svg', 'assets/images/yoro-c-64-2x-3-cycles.svg' ], true ) ) { throw new RuntimeException( 'Unexpected fixture asset.' ); }
    return 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) );
}
$settings = [ 'enabled' => true, 'revision' => 7, 'protocol' => 2, 'content_ids' => [ 11 ], 'contact' => 'Contact the store at support@example.test',
    'readiness' => [ 'mail' => false ], 'preferences' => [ 'features' => [ 'orders' => true, 'quote' => true, 'coupons' => true, 'payments' => true ],
    'appearance' => [ 'welcome' => 'A little help, every step of the way.' ], 'sources' => [ [ 'id' => 11, 'kind' => 'size', 'product_ids' => [] ] ] ] ];
function get_option( $name, $default = null ) { return $GLOBALS['settings']; }
function update_option( ...$args ) { return true; }
function current_user_can( $capability ) { return ! ( $GLOBALS['deny_capability'] ?? false ); }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain ) { return esc_html( $value ); }
function esc_attr__( $value, $domain ) { return esc_html( $value ); }
function admin_url( $path ) { return 'https://fixture.invalid/wp-admin/' . $path; }
function wp_create_nonce( $action ) { return 'synthetic-preview-nonce'; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="synthetic-preview-nonce">'; }
function checked( $one, $two = true, $echo = true ) { return $one === $two ? 'checked' : ''; }
function disabled( $one, $two = true, $echo = true ) { return $one === $two ? 'disabled' : ''; }
function selected( $one, $two = true, $echo = true ) { return $one === $two ? 'selected' : ''; }
function get_post( $id ) { return (object) [ 'post_title' => 'Approved size & care <guide>' ]; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function sanitize_textarea_field( $value ) { return strip_tags( $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
class WP_Error {}
class YoRo_Support_Facts { public static function content( ...$args ) { return [ 'id' => 'content:11' ]; } }
class YoRo_Support_Transport { public static function request( ...$args ) { return [ 'settings' => $GLOBALS['settings'], 'usage' => [ 'completed_count' => 24, 'inference_calls' => 27 ] ]; } }
require dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-customer-support.php';
require dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-support-admin.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); YoRo_Support_Admin::render(); $html = ob_get_clean();
if ( substr_count( $html, 'class="ys-admin-panel"' ) !== 5 || ! str_contains( $html, 'name="revision" value="7"' ) || str_contains( $html, 'care <guide>' ) ) { throw new RuntimeException( 'Five tabs, revision binding and escaping required.' ); }
$values = new ReflectionMethod( YoRo_Support_Admin::class, 'values' );
$_POST = [ 'revision' => '6' ];
if ( $values->invoke( null, $settings )['revision'] !== 6 ) { throw new RuntimeException( 'A stale form must preserve its stale revision for API rejection.' ); }
$_POST = [ 'revision' => '7', 'appearance' => [ 'name' => 'Custom store', 'color' => '#Ab12CD', 'icon' => 'chat' ] ];
$custom = $values->invoke( null, $settings )['preferences']['appearance'];
if ( $custom['name'] !== 'Custom store' || $custom['color'] !== '#Ab12CD' || $custom['icon'] !== 'chat' ) { throw new RuntimeException( 'Custom appearance lost on save.' ); }
unset( $_POST['appearance']['icon'] );
$settings['preferences']['appearance']['icon'] = 'yoro-static';
if ( $values->invoke( null, $settings )['preferences']['appearance']['icon'] !== 'yoro-static' ) { throw new RuntimeException( 'Old form reset saved icon.' ); }
if ( ! str_contains( $html, 'id="ys-accent-picker" type="color"' ) || ! str_contains( $html, 'name="appearance[icon]"' ) || ! str_contains( $html, 'value="YoRo"' ) ) { throw new RuntimeException( 'Default name and accessible native appearance controls required.' ); }
$GLOBALS['deny_capability'] = true;
try { YoRo_Support_Admin::render(); throw new LogicException( 'Missing capability accepted.' ); }
catch ( RuntimeException $error ) { if ( $error->getMessage() !== 'Permission denied.' ) { throw $error; } }
fwrite( STDERR, "Settings source assertions passed: five panels, escaped source, optimistic revision, capabilities. Browser rendering NOT_EVALUATED.\n" );
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chat Support Settings · source fixture</title><style>body{font:14px system-ui;background:#f0f0f1;color:#1d2327;margin:24px}.button{padding:8px 14px;border:1px solid #bdc5c9;border-radius:5px;background:white}.button-primary{background:#2b3a20;color:white}.nav-tab-wrapper{display:flex;gap:8px}.nav-tab{padding:12px;border:0}.nav-tab-active{background:white}.notice{background:white;padding:10px}';
readfile( dirname( __DIR__, 2 ) . '/assets/css/yoro-support-admin.css' ); echo '</style><p>Source fixture · sample data · no API, email or AI requests.</p>' . $html . '<script>';
echo "window.fetch=async(url,options)=>({ok:true,json:async()=>({success:true,data:{results:[{id:options.body.get('type')==='products'?47:11,title:options.body.get('type')==='products'?'Sample public product':'Sample approved guide',type:options.body.get('type')==='products'?'simple':'page'}]}})});document.getElementById('yoro-support-settings').addEventListener('submit',event=>{event.preventDefault();window.alert('Sample fixture: settings are not saved.');});";
readfile( dirname( __DIR__, 2 ) . '/assets/js/yoro-support-admin.js' ); echo '</script></html>';
