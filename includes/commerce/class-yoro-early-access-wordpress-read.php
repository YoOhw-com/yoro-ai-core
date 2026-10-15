<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Published content, image-library metadata and local configuration facts only. */
final class YoRo_Early_Access_WordPress_Read {
    const ACTIONS = [ 'content.search', 'content.get', 'media.search', 'media.get', 'site.get_info', 'site.get_health' ];
    const IMAGE_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ];

    public static function manifest() {
        return [ 'version' => 1, 'actions' => self::ACTIONS, 'max_items' => 20, 'scan_limit' => 100,
            'content' => 'published_posts_pages_plain_text', 'media' => 'images_without_private_parents',
            'health' => 'local_configuration_only', 'image_types' => self::IMAGE_TYPES ];
    }

    public static function valid_params( $action, $params ) {
        if ( ! in_array( $action, self::ACTIONS, true ) || ! is_array( $params ) ) { return false; }
        if ( str_starts_with( $action, 'site.' ) ) { return [] === $params; }
        if ( in_array( $action, [ 'content.get', 'media.get' ], true ) ) {
            $key = 'content.get' === $action ? 'content_id' : 'media_id';
            return [ $key ] === array_keys( $params ) && is_int( $params[$key] ) && $params[$key] > 0;
        }
        $field = 'content.search' === $action ? 'post_type' : 'mime_type';
        if ( array_diff( array_keys( $params ), [ 'query', 'limit', $field ] ) || ! is_int( $params['limit'] ?? null )
            || $params['limit'] < 1 || $params['limit'] > 20 || ! is_string( $params['query'] ?? null )
            || strlen( $params['query'] ) > 640 || 1 !== preg_match( '//u', $params['query'] ) || mb_strlen( $params['query'] ) > 160
            || preg_match( '/[\x00-\x1f\x7f<>]/', $params['query'] ) ) { return false; }
        return ! isset( $params[$field] ) || in_array( $params[$field], 'post_type' === $field ? [ 'post', 'page' ] : self::IMAGE_TYPES, true );
    }

    public static function execute( array $operation ) {
        $action = $operation['action'] ?? ''; $params = $operation['params'] ?? [];
        if ( ! self::valid_params( $action, $params ) || ! current_user_can( 'yoro_view_store_data' ) || ! current_user_can( 'read' ) ) { return self::unavailable(); }
        $meta = [ 'version' => 1, 'scan_limit' => 100, 'scanned' => 0, 'has_more_candidates' => false, 'returned_truncated' => false, 'generated_at' => gmdate( 'c' ) ];
        if ( 'site.get_info' === $action ) {
            $data = [ 'site' => self::site_info() ];
        } elseif ( 'site.get_health' === $action ) {
            if ( ! current_user_can( 'manage_options' ) ) { return self::unavailable(); }
            $data = [ 'health' => self::site_health() ];
        } else {
            $media = str_starts_with( $action, 'media.' ); $detail = str_ends_with( $action, '.get' );
            if ( $media && ! current_user_can( 'upload_files' ) ) { return self::unavailable(); }
            if ( $detail ) {
                $post = get_post( $params[$media ? 'media_id' : 'content_id'] );
                if ( ! ( $media ? self::readable_image( $post ) : self::readable_content( $post ) ) ) { return self::unavailable(); }
                $meta['scanned'] = 1;
                $data = [ 'item' => $media ? self::image( $post, true ) : self::content( $post, true ) ];
            } else {
                $query = [ 'post_type' => $media ? 'attachment' : ( $params['post_type'] ?? [ 'post', 'page' ] ),
                    'post_status' => $media ? 'inherit' : 'publish', 'has_password' => false, 's' => $params['query'],
                    'posts_per_page' => 101, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true,
                    'ignore_sticky_posts' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ];
                if ( $media ) { $query['post_mime_type'] = $params['mime_type'] ?? self::IMAGE_TYPES; }
                $ids = ( new WP_Query( $query ) )->posts; $meta['has_more_candidates'] = count( $ids ) > 100; $ids = array_slice( $ids, 0, 100 ); $items = [];
                foreach ( $ids as $id ) {
                    $meta['scanned']++; $post = get_post( $id );
                    if ( ! ( $media ? self::readable_image( $post ) : self::readable_content( $post ) ) ) { continue; }
                    if ( count( $items ) === $params['limit'] ) { $meta['returned_truncated'] = true; break; }
                    $items[] = $media ? self::image( $post, false ) : self::content( $post, false );
                }
                $meta['has_more_candidates'] = $meta['has_more_candidates'] || $meta['scanned'] < count( $ids ) || $meta['returned_truncated'];
                $data = [ 'items' => $items, 'count' => count( $items ) ];
            }
        }
        $message = 'Bounded WordPress read completed. Published content is plain text; private content and arbitrary metadata are excluded. Configuration checks are not a complete site health assessment.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message, 'data' => $data,
            'read_only' => true, 'undoable' => false, 'result_type' => 'wordpress_read', 'display_type' => 'wordpress_read', 'assistant_summary' => $message,
            'extensions' => [ 'yoro.wordpress_read' => $meta ] ];
    }

    private static function readable_content( $post ) {
        return $post instanceof WP_Post && in_array( $post->post_type, [ 'post', 'page' ], true )
            && in_array( $post->post_type, YoRo_WordPress_Content_Actions::allowed_post_types(), true )
            && 'publish' === $post->post_status && '' === $post->post_password && current_user_can( 'read_post', $post->ID );
    }

    private static function readable_image( $post ) {
        if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || 'inherit' !== $post->post_status
            || '' !== $post->post_password || ! in_array( $post->post_mime_type, self::IMAGE_TYPES, true ) || ! current_user_can( 'read_post', $post->ID ) ) { return false; }
        if ( ! $post->post_parent ) { return true; }
        $parent = get_post( $post->post_parent );
        return $parent instanceof WP_Post && 'publish' === $parent->post_status && '' === $parent->post_password
            && is_post_type_viewable( $parent->post_type ) && current_user_can( 'read_post', $parent->ID );
    }

    private static function content( WP_Post $post, $detail ) {
        $raw = YoRo_WordPress_Content_Actions::present_post( $post ); $limit = $detail ? 8000 : 320;
        $plain = self::plain( strip_shortcodes( $post->post_content ), PHP_INT_MAX );
        return [ 'content_id' => (int) $post->ID, 'post_type' => $post->post_type, 'status' => 'publish',
            'title' => self::plain( $post->post_title, 256 ), 'content' => mb_strcut( $plain, 0, $limit, 'UTF-8' ),
            'version' => $raw['version'], 'modified_at' => $raw['modified_at'], 'url' => self::url( $raw['url'], (int) $post->ID ) ?: '',
            'extensions' => [ 'yoro.wordpress_read' => [ 'content_truncated' => strlen( $plain ) > $limit ] ] ];
    }

    private static function image( WP_Post $post, $detail ) {
        $raw = YoRo_WordPress_Content_Actions::present_post( $post ); $metadata = wp_get_attachment_metadata( $post->ID );
        $dimension = static function( $key ) use ( $metadata ) { $n = is_array( $metadata ) ? ( $metadata[$key] ?? null ) : null; return is_int( $n ) && $n > 0 && $n <= 100000 ? $n : null; };
        $alt = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
        return [ 'media_id' => (int) $post->ID, 'title' => self::plain( $post->post_title, 256 ), 'mime_type' => $post->post_mime_type,
            'url' => self::url( wp_get_attachment_url( $post->ID ) ) ?: false, 'version' => $raw['version'],
            'caption' => self::plain( $post->post_excerpt, $detail ? 1000 : 160 ), 'description' => $detail ? self::plain( $post->post_content, 2000 ) : '',
            'alt_text' => is_string( $alt ) ? self::plain( $alt, 256 ) : '',
            'extensions' => [ 'yoro.wordpress_read' => [ 'width' => $dimension( 'width' ), 'height' => $dimension( 'height' ),
                'preview_url' => self::url( wp_get_attachment_image_url( $post->ID, 'medium' ) ) ?: false ] ] ];
    }

    private static function site_info() {
        $site = YoRo_WordPress_Site_Actions::get_info()['data'];
        foreach ( [ 'name' => 256, 'description' => 500, 'wordpress_version' => 80, 'php_version' => 80, 'locale' => 80, 'timezone' => 80,
            'environment' => 80, 'active_theme' => 120, 'permalink_structure' => 120 ] as $key => $limit ) { $site[$key] = self::plain( $site[$key], $limit ); }
        foreach ( [ 'home_url', 'site_url' ] as $key ) { $site[$key] = self::url( $site[$key] ) ?: ''; }
        $site['modules'] = array_values( array_intersect( $site['modules'], [ 'wordpress.core', 'wordpress.content', 'wordpress.taxonomy', 'wordpress.comments', 'wordpress.media', 'wordpress.extensions', 'wordpress.users', 'woocommerce' ] ) );
        return $site;
    }

    private static function site_health() {
        $values = [ 'https' => wp_is_using_https() ? 'good' : 'critical',
            'debug_display' => defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'recommended' : 'good',
            'cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'recommended' : 'good',
            'search_visibility' => get_option( 'blog_public' ) ? 'good' : 'recommended' ];
        $labels = [ 'https' => [ 'good' => 'WordPress is configured to use HTTPS.', 'critical' => 'WordPress is not configured to use HTTPS.' ],
            'debug_display' => [ 'good' => 'Debug display is disabled.', 'recommended' => 'Debug display is enabled.' ],
            'cron' => [ 'good' => 'WP-Cron is enabled; scheduler execution is not verified.', 'recommended' => 'WP-Cron is disabled; an external scheduler is required and is not verified here.' ],
            'search_visibility' => [ 'good' => 'Search engine indexing is allowed.', 'recommended' => 'Search engine indexing is discouraged.' ] ];
        $checks = []; foreach ( $values as $id => $value ) { $checks[] = [ 'id' => $id, 'status' => $value, 'summary' => $labels[$id][$value] ]; }
        return [ 'status' => in_array( 'critical', $values, true ) ? 'critical' : ( in_array( 'recommended', $values, true ) ? 'recommended' : 'good' ), 'checks' => $checks ];
    }

    private static function plain( $value, $limit ) {
        if ( ! is_string( $value ) ) { return ''; }
        $value = wp_strip_all_tags( html_entity_decode( wp_check_invalid_utf8( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $value = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value );
        return mb_strcut( trim( $value ), 0, $limit, 'UTF-8' );
    }

    private static function url( $value, $content_id = null ) {
        if ( ! is_string( $value ) || strlen( $value ) > 1024 || ! filter_var( $value, FILTER_VALIDATE_URL ) ) { return false; }
        $parts = wp_parse_url( $value ); $home = wp_parse_url( home_url() );
        $query_allowed = ! isset( $parts['query'] ) || ( is_int( $content_id ) && in_array( $parts['query'], [ 'p=' . $content_id, 'page_id=' . $content_id ], true ) );
        return 'https' === ( $parts['scheme'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && $query_allowed && ! isset( $parts['fragment'] )
            && strtolower( $parts['host'] ?? '' ) === strtolower( $home['host'] ?? '' ) && ( $parts['port'] ?? null ) === ( $home['port'] ?? null ) ? $value : false;
    }

    private static function unavailable() { return new WP_Error( 'wordpress_read_unavailable', 'The WordPress read is unavailable.' ); }
}
