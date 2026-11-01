<?php
/** Source projection assertions only; no WordPress, transport, provider or browser engine. */
define( 'ABSPATH', __DIR__ );
define( 'YORO_AI_CORE_FILE', dirname( __DIR__, 2 ) . '/yoro-ai-core.php' );
function plugins_url( $path, $file ) { return 'https://store.test/plugin/' . $path; }
function rest_url( $path ) { return 'https://store.test/' . $path; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain ) { return esc_html( $value ); }
function esc_attr__( $value, $domain ) { return esc_html( $value ); }
require dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-support-context.php';
require dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-support-view.php';
$checks = 0;
function verify( $condition ) { global $checks; if ( ! $condition ) { throw new RuntimeException( 'Appearance projection failed at ' . ( $checks + 1 ) ); } ++$checks; }
$a = YoRo_Support_Context::preferences( [] )['appearance'];
verify( $a['name'] === 'YoRo' && $a['color'] === '#2b3a20' && $a['icon'] === 'yoro-animated' );
$custom = YoRo_Support_Context::preferences( [ 'preferences' => [ 'appearance' => [ 'name' => 'My brand', 'color' => '#123456', 'icon' => 'chat' ] ] ] )['appearance'];
verify( $custom['name'] === 'My brand' && $custom['color'] === '#123456' && $custom['icon'] === 'chat' );
foreach ( [ '#ffffff', '#12', 'red', '#ffffff;display:none', null ] as $color ) { verify( YoRo_Support_View::color( $color ) === '#2b3a20' ); }
verify( YoRo_Support_View::color( '#123456' ) === '#123456' );
foreach ( [ 'yoro-animated', 'yoro-static', 'chat', 'https://evil.test/x.svg', '<svg onload=evil>' ] as $icon ) {
    ob_start(); YoRo_Support_View::widget( [ 'contact' => '<b>contact</b>', 'preferences' => [ 'appearance' => [ 'icon' => $icon, 'name' => '<b>brand</b>' ] ] ] ); $html = ob_get_clean();
    verify( ! str_contains( $html, 'evil' ) && ! str_contains( $html, '<b>' ) && str_contains( $html, '&lt;b&gt;brand' ) );
    verify( substr_count( $html, 'class="ys-support-icon"' ) === 2 );
    verify( 'chat' === $icon ? ! str_contains( $html, '<img' ) : substr_count( $html, 'data-support-icon' ) === 2 );
    if ( 'yoro-static' === $icon ) { verify( ! str_contains( $html, '3-cycles.svg' ) ); }
    elseif ( 'chat' !== $icon ) { verify( substr_count( $html, '3-cycles.svg' ) === 2 ); }
}
verify( hash_file( 'sha256', dirname( __DIR__, 2 ) . '/assets/images/yoro-c-64-2x-3-cycles.svg' ) === 'c3e16c4c15d382ec9658d2c4dfe9fb0448538bc6d487cdb4ef0906f10dfcb39d' );
verify( hash_file( 'sha256', dirname( __DIR__, 2 ) . '/assets/images/yoro-c-64-static.svg' ) === 'af69c4224952ff0e32e6b9667199e694d79e9a878eb5666712a1ce4017d36be1' );
echo json_encode( [ 'suite' => 'appearance-source', 'assertions' => $checks, 'result' => 'passed', 'browser' => 'NOT_EVALUATED', 'network' => 0 ] ) . "\n";
