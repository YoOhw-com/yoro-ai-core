<?php
/** Bounded published-content and image reads on a disposable WordPress database. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'YoRo_Early_Access_WordPress_Read' ) ) { YoRo_AI_Core::init(); }
if ( str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'yorohq.com' ) ) { throw new RuntimeException( 'Disposable fixtures only.' ); }
$assert = static function( $ok, $why ) { if ( ! $ok ) { throw new RuntimeException( $why ); } };
$original = get_current_user_id(); $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
add_filter( 'pre_wp_mail', '__return_true' );
$block = static function() { throw new RuntimeException( 'WordPress reads cannot make outbound requests.' ); };
add_filter( 'pre_http_request', $block );
global $wpdb; $wpdb->query( 'START TRANSACTION' );
try {
    wp_set_current_user( (int) $admins[0] );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data', 'yoro_execute_actions' ] ] );
    $tag = 'YR0178WP' . str_replace( '-', '', wp_generate_uuid4() );
    $create = static function( $extra = [] ) use ( $tag ) {
        return wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $tag,
            'post_content' => '<p>Public text</p>[yr0178_secret_shortcode]SECRET-SHORTCODE[/yr0178_secret_shortcode]', ...$extra ], true );
    };
    add_shortcode( 'yr0178_secret_shortcode', static function() { throw new RuntimeException( 'Shortcodes must not execute.' ); } );
    $published = $create(); $page = $create( [ 'post_type' => 'page' ] );
    $private = $create( [ 'post_status' => 'private', 'post_content' => 'PRIVATE-CONTENT' ] );
    $draft = $create( [ 'post_status' => 'draft', 'post_content' => 'PRIVATE-CONTENT' ] );
    $protected = $create( [ 'post_password' => 'PRIVATE-PASSWORD', 'post_content' => 'PRIVATE-CONTENT' ] );
    $run = static function( $action, $params = [] ) { return YoRo_Early_Access_WordPress_Read::execute( [ 'action' => $action, 'params' => $params ] ); };
    $r = $run( 'content.search', [ 'query' => $tag, 'limit' => 20 ] );
    $assert( ! is_wp_error( $r ) && 2 === $r['data']['count'], 'Search includes only published unprotected posts/pages.' );
    $assert( [ (int) $page, (int) $published ] === array_column( $r['data']['items'], 'content_id' ), 'Result order is stable descending candidate ID.' );
    foreach ( $r['data']['items'] as $row ) {
        $assert( false === strpos( $row['content'], '<' ) && false === strpos( $row['content'], 'SECRET-SHORTCODE' ) && false === strpos( wp_json_encode( $row ), 'PRIVATE-' ), 'HTML and shortcode contents never execute or enter plain-text projection.' );
    }
    foreach ( [ $private, $draft, $protected ] as $id ) { $assert( is_wp_error( $run( 'content.get', [ 'content_id' => (int) $id ] ) ), 'Even an administrator cannot read excluded content.' ); }
    $detail = $run( 'content.get', [ 'content_id' => (int) $published ] );
    $canonical = YoRo_WordPress_Content_Actions::present_post( get_post( $published ) );
    $assert( $detail['data']['item']['version'] === $canonical['version'] && $detail['data']['item']['modified_at'] === $canonical['modified_at'], 'Canonical identity and version are retained.' );
    wp_update_post( [ 'ID' => $published, 'post_content' => str_repeat( 'é', 5000 ) ] );
    $detail = $run( 'content.get', [ 'content_id' => (int) $published ] );
    $assert( 8000 === strlen( $detail['data']['item']['content'] ) && $detail['data']['item']['extensions']['yoro.wordpress_read']['content_truncated'] && 1 === preg_match( '//u', $detail['data']['item']['content'] ), 'Detail text is bounded without splitting UTF-8.' );
    $image = static function( $parent, $type = 'image/png' ) use ( $create ) {
        $id = $create( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $parent, 'post_mime_type' => $type, 'post_excerpt' => '<b>Public caption</b>' ] );
        update_post_meta( $id, '_wp_attached_file', 'yr0178-fixture.png' );
        update_post_meta( $id, '_wp_attachment_metadata', [ 'width' => 32, 'height' => 32, 'file' => 'PRIVATE-PATH', 'image_meta' => [ 'camera' => 'PRIVATE-EXIF' ] ] );
        update_post_meta( $id, '_wp_attachment_image_alt', 'Synthetic image' );
        return $id;
    };
    $valid = $image( $page ); $unattached = $image( 0 );
    foreach ( [ $image( $private ), $image( $draft ), $image( $protected ), $image( 99999999 ), $image( 0, 'image/svg+xml' ), $image( 0, 'application/pdf' ) ] as $id ) {
        $assert( is_wp_error( $run( 'media.get', [ 'media_id' => (int) $id ] ) ), 'Private/draft/password/missing parents and unsupported media are excluded.' );
    }
    $r = $run( 'media.search', [ 'query' => $tag, 'limit' => 20 ] );
    $assert( ! is_wp_error( $r ) && 2 === $r['data']['count'] && [ (int) $unattached, (int) $valid ] === array_column( $r['data']['items'], 'media_id' ), 'Image search uses the same privacy filter as details.' );
    $r = $run( 'media.get', [ 'media_id' => (int) $valid ] );
    $assert( 32 === $r['data']['item']['extensions']['yoro.wordpress_read']['width'] && false === strpos( wp_json_encode( $r ), 'PRIVATE-' ), 'Only allowlisted image fields leave the connector.' );
    update_post_meta( $valid, '_wp_attachment_image_alt', [ 'PRIVATE-ARRAY' ] );
    $assert( '' === $run( 'media.get', [ 'media_id' => (int) $valid ] )['data']['item']['alt_text'], 'Malformed alt metadata is ignored.' );
    $foreign = static function() { return 'https://foreign.example/private.png'; };
    add_filter( 'wp_get_attachment_url', $foreign );
    $assert( false === $run( 'media.get', [ 'media_id' => (int) $valid ] )['data']['item']['url'], 'Cross-origin media URLs are not exposed.' );
    remove_filter( 'wp_get_attachment_url', $foreign );
    $assert( ! is_wp_error( $run( 'site.get_info' ) ) && 4 === count( $run( 'site.get_health' )['data']['health']['checks'] ), 'Local configuration reads complete without outbound requests.' );
    $health = $run( 'site.get_health' )['data']['health'];
    $expected_debug = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'recommended' : 'good';
    $assert( $expected_debug === $health['checks'][1]['status'], 'Inactive debug display defaults do not create a false alert.' );
    $limited = wp_insert_user( [ 'user_login' => 'yr0178-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
    ( new WP_User( $limited ) )->add_cap( 'yoro_view_store_data' );
    wp_set_current_user( $limited );
    YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_viewer', 'permissions' => [ 'yoro_view_store_data' ] ] );
    $assert( ! is_wp_error( $run( 'content.get', [ 'content_id' => (int) $page ] ) ) && ! is_wp_error( $run( 'site.get_info' ) ), 'Public reads still require native read and YoRo permissions.' );
    $assert( is_wp_error( $run( 'media.get', [ 'media_id' => (int) $valid ] ) ) && is_wp_error( $run( 'site.get_health' ) ), 'Native upload and configuration permissions are enforced independently.' );
    wp_set_current_user( (int) $admins[0] );
    foreach ( [ [ 'content.get', [ 'content_id' => '1' ] ], [ 'site.get_health', [ 'query' => 'all' ] ], [ 'content.search', [ 'query' => '<script>', 'limit' => 1 ] ], [ 'media.search', [ 'query' => '', 'limit' => 21 ] ] ] as $bad ) { $assert( is_wp_error( $run( $bad[0], $bad[1] ) ), 'Invalid inputs fail closed.' ); }
    for ( $i = 0; $i < 101; $i++ ) { $create(); }
    $r = $run( 'content.search', [ 'query' => $tag, 'limit' => 3 ] ); $meta = $r['extensions']['yoro.wordpress_read'];
    $assert( 3 === $r['data']['count'] && 4 === $meta['scanned'] && $meta['has_more_candidates'] && $meta['returned_truncated'], 'The search stops after the extra eligible candidate and reports an honest bounded snapshot.' );
    echo "YoRo WordPress published-content, image privacy, native permission, configuration and bounded scan contracts passed.\n";
} finally {
    $wpdb->query( 'ROLLBACK' ); wp_cache_flush(); wp_set_current_user( $original ); remove_shortcode( 'yr0178_secret_shortcode' ); remove_filter( 'pre_wp_mail', '__return_true' ); remove_filter( 'pre_http_request', $block );
}

require_once __DIR__.'/early-access-settings-read-contract.php';
