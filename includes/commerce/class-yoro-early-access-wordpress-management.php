<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Finite ordinary WordPress projections. The existing runner owns claims and the durable write journal. */
final class YoRo_Early_Access_WordPress_Management {
    const WriteActions = ['wordpress.management.comments.reply', 'wordpress.management.comments.update_status', 'wordpress.management.content.assign_terms', 'wordpress.management.content.bulk_update_status', 'wordpress.management.content.create_draft', 'wordpress.management.content.publish', 'wordpress.management.content.restore', 'wordpress.management.content.restore_revision', 'wordpress.management.content.schedule', 'wordpress.management.content.set_featured_image', 'wordpress.management.content.trash', 'wordpress.management.content.update_draft', 'wordpress.management.media.update_metadata', 'wordpress.management.media.upload', 'wordpress.management.plugins.activate', 'wordpress.management.plugins.deactivate', 'wordpress.management.plugins.update', 'wordpress.management.site.update_core', 'wordpress.management.site.update_settings', 'wordpress.management.themes.activate', 'wordpress.management.themes.update', 'wordpress.management.users.update_role'];
    const ReadActions = ['wordpress.management.comments.list', 'wordpress.management.content.list_revisions', 'wordpress.management.content.preview_changes', 'wordpress.management.content.preview_revision', 'wordpress.management.plugins.list', 'wordpress.management.site.get_update_status', 'wordpress.management.taxonomies.list', 'wordpress.management.terms.list', 'wordpress.management.themes.list', 'wordpress.management.users.list'];
    const Extension = 'yoro.early_access_wordpress_management';
    private static $definition;

    public static function definition() {
        if ( null === self::$definition ) {
            self::$definition = json_decode( file_get_contents( __DIR__ . '/wordpress-management-v1.json' ), true );
        }
        return self::$definition;
    }

    public static function wire_action( $public ) { return 'wordpress.management.' . self::public_action( $public ); }

    public static function public_action( $action ) {
        return str_starts_with( $action, 'wordpress.management.' ) ? substr( $action, strlen( 'wordpress.management.' ) ) : $action;
    }

    public static function owns( $action ) { return in_array( $action, array_merge( self::ReadActions, self::WriteActions ), true ) || null !== self::source_write( $action ); }

    public static function data_schema( $action ) {
        $write = self::source_write( $action );
        if ( $write ) {
            $schema = self::definition()['outputs'][str_replace( '.', '.prepare_', self::public_action( $write ) )];
            $schema['properties']['action']['enum'] = [ $write ];
            return $schema;
        }
        return self::definition()['outputs'][self::public_action( $action )];
    }

    public static function source_action( $action ) {
        return 'wordpress.management.prepare_' . str_replace( '.', '_', self::public_action( $action ) );
    }

    public static function source_write( $action ) {
        foreach ( self::WriteActions as $write ) {
            if ( self::source_action( $write ) === $action ) { return $write; }
        }
        return null;
    }

    public static function manifest() {
        $definition = self::definition();
        return [ 'version' => 1, 'actions' => array_map( [ __CLASS__, 'wire_action' ], $definition['public_actions'] ), 'public_tool_names' => $definition['public_actions'],
            'source_actions' => array_map( [ __CLASS__, 'source_action' ], self::WriteActions ),
            'max_items' => 20, 'max_targets' => 10, 'requires_confirmation' => true,
            'source_version' => 1, 'result_version' => 1, 'consent_version' => 1 ];
    }

    public static function admin_action( $action ) {
        return isset( self::definition()['actions'][self::public_action( $action )] ) && ! empty( self::definition()['actions'][self::public_action( $action )]['admin'] );
    }

    public static function admin_advertised( $action ) {
        return self::admin_action( $action ) && yoro_ai_core_has_woocommerce()
            && YoRo_WordPress_Admin_Actions::connector_policy_ready();
    }

    public static function register() {
        foreach ( self::definition()['actions'] as $public => $definition ) {
            $native = YoRo_Action_Registry::get( $public );
            if ( ! is_array( $native ) ) { continue; }
            $name = self::wire_action( $public );
            $schema = in_array( $name, self::WriteActions, true ) ? $definition['params'] : $definition['input'];
            $native['category'] = 'wordpress_management';
            $native['description'] = 'Finite ordinary WordPress action for ' . $public . '.';
            $native['input_schema'] = $schema['properties']; $native['canonical_input_schema'] = $schema['properties'];
            $native['required_params'] = $schema['required'];
            $native['max_targets'] = $definition['max_targets']; $native['max_items'] = $definition['max_targets'];
            $native['ai_exposed'] = ! self::admin_action( $name ) || self::admin_advertised( $name );
            YoRo_Action_Registry::register( $name, $native );
        }
        foreach ( self::WriteActions as $action ) {
            $source = self::source_action( $action );
            YoRo_Action_Registry::register( $source, [ 'label' => 'Prepare ' . $action,
                'description' => 'Bounded internal current source for exactly ' . $action . '.',
                'category' => 'wordpress_management_source', 'callback' => static function( $params ) use ( $action ) { return self::prepare( $action, $params['intent'] ?? [] ); },
                'input_schema' => [ 'intent' => self::definition()['actions'][self::public_action( $action )]['input'] ],
                'required_params' => [ 'intent' ], 'output_schema' => [ 'success' => 'boolean' ],
                'effect' => 'read', 'reversibility' => 'full', 'write_action' => false,
                'max_targets' => 1, 'max_items' => 1, 'ai_exposed' => true,
                'capabilities' => [ 'wordpress_management', 'read' ] ] );
        }
    }

    public static function valid_params( $action, $params ) {
        $write = self::source_write( $action );
        if ( $write ) {
            return is_array( $params ) && [ 'intent' ] === array_keys( $params ) && self::valid_intent( $write, $params['intent'] );
        }
        return in_array( $action, self::ReadActions, true ) && self::valid_intent( $action, $params );
    }

    public static function valid_intent( $action, $intent ) {
        $schema = self::definition()['actions'][self::public_action( $action )]['input'] ?? null;
        return is_array( $schema ) && self::valid_value( $intent, $schema );
    }

    /** Closed finite declaration validation before calling a native callback. */
    private static function valid_value( $value, array $schema ) {
        $type = $schema['type'] ?? '';
        if ( 'object' === $type ) {
            if ( ! is_array( $value ) || ( [] !== $value && array_is_list( $value ) )
                || array_diff( array_keys( $value ), array_keys( $schema['properties'] ) )
                || array_diff( $schema['required'], array_keys( $value ) ) ) { return false; }
            foreach ( $value as $key => $child ) { if ( ! self::valid_value( $child, $schema['properties'][$key] ) ) { return false; } }
            return true;
        }
        if ( 'array' === $type ) {
            if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > ( $schema['maxItems'] ?? 20 ) || count( $value ) < ( $schema['minItems'] ?? 0 ) ) { return false; }
            if ( ! empty( $schema['uniqueItems'] ) && count( array_unique( $value, SORT_REGULAR ) ) !== count( $value ) ) { return false; }
            foreach ( $value as $child ) { if ( ! self::valid_value( $child, $schema['items'] ) ) { return false; } }
            return true;
        }
        if ( 'integer' === $type ) { return is_int( $value ) && $value >= ( $schema['minimum'] ?? PHP_INT_MIN ) && $value <= ( $schema['maximum'] ?? PHP_INT_MAX ); }
        if ( 'string' !== $type || ! is_string( $value ) || 1 !== preg_match( '//u', $value )
            || strlen( $value ) > 4 * ( $schema['maxLength'] ?? 200 ) || mb_strlen( $value ) > ( $schema['maxLength'] ?? 200 )
            || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) { return false; }
        if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) { return false; }
        if ( isset( $schema['pattern'] ) && 1 !== preg_match( '~' . $schema['pattern'] . '~D', $value ) ) { return false; }
        if ( 'uuid' === ( $schema['format'] ?? '' ) && 1 !== preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/Di', $value ) ) { return false; }
        return true;
    }

    private static function denied( $message = 'Current WordPress management authority is unavailable.' ) {
        return new WP_Error( 'wordpress_management_unavailable', $message );
    }

    private static function guard( $action ) {
        $action = self::public_action( $action );
        $spec = self::definition()['actions'][$action] ?? null;
        if ( ! $spec || ! current_user_can( 'yoro_view_store_data' ) ) { return self::denied(); }
        if ( ( ! empty( $spec['requires_editor'] ) || in_array( self::wire_action( $action ), self::WriteActions, true ) ) && ! current_user_can( 'yoro_execute_actions' ) ) { return self::denied(); }
        if ( ! empty( $spec['admin'] ) ) {
            if ( ! self::admin_advertised( $action ) || ! current_user_can( 'manage_options' ) ) { return self::denied( 'Administration policy, a backup within 24 hours and current native permissions are required.' ); }
            $capability = [ 'plugins.activate' => 'activate_plugins', 'plugins.deactivate' => 'deactivate_plugins', 'plugins.update' => 'update_plugins', 'plugins.list' => 'activate_plugins',
                'themes.activate' => 'switch_themes', 'themes.update' => 'update_themes', 'themes.list' => 'switch_themes',
                'site.get_update_status' => 'update_core', 'site.update_core' => 'update_core', 'site.update_settings' => 'manage_options',
                'users.list' => 'list_users', 'users.update_role' => 'promote_users' ][$action] ?? '';
            if ( ! $capability || ! current_user_can( $capability ) ) { return self::denied(); }
        }
        return true;
    }

    public static function execute( array $operation ) {
        $action = $operation['action'] ?? '';
        $params = $operation['params'] ?? [];
        if ( ! self::valid_params( $action, $params ) ) { return self::denied(); }
        $write = self::source_write( $action );
        $guard = self::guard( self::public_action( $write ?: $action ) );
        if ( is_wp_error( $guard ) ) { return $guard; }
        $result = $write ? self::prepare( $write, $params['intent'] ) : self::read( self::public_action( $action ), $params );
        if ( is_wp_error( $result ) ) { return $result; }
        $message = $write ? 'Current WordPress source prepared. Nothing has changed.' : 'Bounded WordPress management read completed.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message,
            'data' => $write ? $result : $result, 'read_only' => true, 'undoable' => false,
            'result_type' => 'wordpress_management', 'display_type' => 'wordpress_management', 'assistant_summary' => $message, 'extensions' => (object) [] ];
    }

    private static function read( $action, array $params ) {
        $bounded = self::bounded_read( $action, $params );
        if ( null !== $bounded ) { return $bounded; }
        if ( str_starts_with( $action, 'content.' ) ) {
            $post = get_post( $params['content_id'] );
            if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) { return self::denied(); }
        }
        if ( 'content.list_revisions' === $action ) {
            $limit = $params['limit'] ?? 20;
            $query = [ 'posts_per_page' => $limit + 1, 'order' => 'DESC', 'orderby' => 'ID', 'check_enabled' => false ];
            $before = $params['before_id'] ?? null;
            $query['_yoro_wordpress_management'] = true;
            $filter = static function( $where, $request ) use ( $before ) {
                global $wpdb;
                return $request->get( '_yoro_wordpress_management' ) && null !== $before ? $where . $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $before ) : $where;
            };
            add_filter( 'posts_where', $filter, 10, 2 );
            try { $revisions = wp_get_post_revisions( $post->ID, $query ); }
            finally { remove_filter( 'posts_where', $filter, 10 ); }
            $rows = [];
            foreach ( $revisions as $revision ) {
                if ( isset( $params['before_id'] ) && $revision->ID >= $params['before_id'] ) { continue; }
                $rows[] = [ 'revision_id' => (int) $revision->ID, 'content_id' => (int) $post->ID,
                    'title' => sanitize_text_field( $revision->post_title ), 'modified_at' => mysql_to_rfc3339( $revision->post_modified_gmt ) ];
            }
            return self::page( $rows, $limit, 'revision_id' );
        }
        $callback = YoRo_Action_Registry::get( $action )['callback'] ?? null;
        if ( ! is_callable( $callback ) ) { return self::denied(); }
        $result = call_user_func( $callback, $params );
        if ( is_wp_error( $result ) || empty( $result['success'] ) ) { return is_wp_error( $result ) ? $result : self::denied(); }
        $data = $result['data'] ?? [];
        if ( in_array( $action, [ 'content.preview_changes', 'content.preview_revision' ], true ) ) {
            $fields = 'content.preview_changes' === $action ? [ 'content_id', 'expected_version', 'title', 'content', 'preview_hash' ] : [ 'revision_id', 'content_id', 'title', 'content', 'excerpt', 'modified_at' ];
            return self::project( $data, $fields );
        }
        $fields = [
            'taxonomies.list' => [ 'name', 'label', 'hierarchical', 'post_types' ],
            'terms.list' => [ 'term_id', 'name', 'slug', 'taxonomy', 'parent_id', 'count' ],
            'comments.list' => [ 'comment_id', 'content_id', 'parent_id', 'author', 'content', 'status', 'version' ],
            'plugins.list' => [ 'plugin', 'name', 'version', 'active', 'update_available' ],
            'themes.list' => [ 'theme', 'name', 'version', 'active', 'update_available' ],
            'users.list' => [ 'user_id', 'display_name', 'roles', 'registered_at', 'version' ],
        ];
        if ( 'site.get_update_status' === $action ) {
            return [ 'core' => array_slice( (array) ( $data['core'] ?? [] ), 0, 20 ), 'plugins' => array_slice( (array) ( $data['plugins'] ?? [] ), 0, 20 ),
                'themes' => array_slice( (array) ( $data['themes'] ?? [] ), 0, 20 ), 'checked_at' => sanitize_text_field( $data['checked_at'] ?? '' ), 'coverage' => 'bounded_snapshot' ];
        }
        $rows = [];
        foreach ( array_slice( (array) $data, 0, 21 ) as $item ) {
            if ( ! is_array( $item ) ) { return self::denied(); }
            $rows[] = self::project( $item, $fields[$action] );
        }
        $key = [ 'terms.list' => 'term_id', 'comments.list' => 'comment_id', 'users.list' => 'user_id' ][$action] ?? null;
        return self::page( $rows, $params['limit'] ?? 20, $key );
    }

    private static function project( array $data, array $fields ) {
        $row = array_intersect_key( $data, array_flip( $fields ) );
        foreach ( $row as $key => $value ) {
            if ( is_string( $value ) ) { $row[$key] = 'content' === $key ? wp_kses_post( $value ) : sanitize_text_field( $value ); }
        }
        return $row;
    }

    private static function page( array $rows, $limit, $key ) {
        $next = count( $rows ) > $limit;
        $rows = array_slice( $rows, 0, $limit );
        return [ 'items' => $rows, 'count' => count( $rows ), 'has_next_page' => $next,
            'next_cursor' => $next && $key ? (int) end( $rows )[$key] : null, 'coverage' => 'bounded_snapshot', 'timezone' => wp_timezone_string() ];
    }

    public static function prepare( $action, array $intent ) {
        $wire = self::wire_action( self::public_action( $action ) );
        $action = self::public_action( $wire );
        if ( ! in_array( self::wire_action( $action ), self::WriteActions, true ) || ! self::valid_intent( $action, $intent ) ) { return self::denied(); }
        $guard = self::guard( $action );
        if ( is_wp_error( $guard ) ) { return $guard; }
        $params = $intent;
        $before = [];
        $after = [];
        $id = $intent['content_id'] ?? $intent['media_id'] ?? $intent['comment_id'] ?? $intent['user_id'] ?? 0;
        $name = str_replace( '_', ' ', explode( '.', $action )[1] );
        $effects = 'Only the reviewed target changes. Normal WordPress hooks may run.';
        if ( 'content.create_draft' === $action ) {
            $type = $intent['post_type'] ?? 'post';
            $object = get_post_type_object( $type );
            if ( ! in_array( $type, YoRo_WordPress_Content_Actions::allowed_post_types(), true ) || ! $object
                || ! current_user_can( $object->cap->create_posts ?? 'edit_posts' ) ) { return self::denied(); }
            $params = [ 'post_type' => $type, 'title' => sanitize_text_field( $intent['title'] ), 'content' => wp_kses_post( $intent['content'] ) ];
            $after = [ 'post_type' => $type, 'title' => $params['title'], 'content' => $params['content'], 'status' => 'draft' ];
            $name = $params['title'];
            $effects = 'Creates one draft. It is not publicly published.';
        } elseif ( 'content.bulk_update_status' === $action ) {
            $params = [ 'items' => [], 'status' => $intent['status'] ];
            $before = [ 'items' => [] ];
            $after = [ 'items' => [] ];
            foreach ( $intent['content_ids'] as $content_id ) {
                $source = YoRo_WordPress_Content_Actions::management_source( 'content', $content_id );
                if ( is_wp_error( $source ) || 'trash' === $source['status'] ) { return self::denied(); }
                if ( 'private' === $intent['status'] ) {
                    $object = get_post_type_object( $source['post_type'] );
                    if ( ! $object || ! current_user_can( $object->cap->publish_posts ) ) { return self::denied(); }
                }
                $params['items'][] = [ 'content_id' => $content_id, 'expected_version' => $source['version'] ];
                $before['items'][] = [ 'content_id' => $content_id, 'status' => $source['status'], 'version' => $source['version'] ];
                $after['items'][] = [ 'content_id' => $content_id, 'status' => $intent['status'] ];
            }
            $effects = 'Non-atomic batch of exactly ' . count( $intent['content_ids'] ) . ' targets. Each target has its own outcome. Unknown and not-attempted targets must never be repeated automatically.';
        } elseif ( str_starts_with( $action, 'content.' ) ) {
            $source = YoRo_WordPress_Content_Actions::management_source( 'content', $id );
            if ( is_wp_error( $source ) ) { return $source; }
            $name = $source['title'];
            $params['expected_version'] = $source['version'];
            $before = $source;
            $after = $source;
            if ( 'content.update_draft' === $action ) {
                if ( 'draft' !== $source['status'] || ! array_intersect( [ 'title', 'content' ], array_keys( $intent ) ) ) { return self::denied(); }
                foreach ( [ 'title', 'content' ] as $field ) {
                    if ( array_key_exists( $field, $intent ) ) { $params[$field] = 'title' === $field ? sanitize_text_field( $intent[$field] ) : wp_kses_post( $intent[$field] ); $after[$field] = $params[$field]; }
                }
            } elseif ( in_array( $action, [ 'content.publish', 'content.schedule' ], true ) ) {
                $object = get_post_type_object( $source['post_type'] );
                if ( ! $object || ! current_user_can( $object->cap->publish_posts ) || ! in_array( $source['status'], [ 'draft', 'pending', 'private', 'future', 'publish' ], true ) ) { return self::denied(); }
                $after['status'] = 'content.publish' === $action ? 'publish' : 'future';
                if ( 'content.publish' === $action ) {
                    $preview = YoRo_WordPress_Content_Actions::preview_changes( [ 'content_id' => $id, 'expected_version' => $source['version'] ] );
                    if ( is_wp_error( $preview ) || ( $preview['data']['content'] ?? null ) !== $source['content'] ) { return self::denied(); }
                    $params['expected_preview_hash'] = $preview['data']['preview_hash'];
                } else {
                    $date = DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i:sP', $intent['publish_at'] ); $errors = DateTimeImmutable::getLastErrors();
                    if ( false === $date || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d\\TH:i:sP' ) !== str_replace( 'Z', '+00:00', $intent['publish_at'] ) ) { return self::denied(); }
                    $timestamp = $date->getTimestamp();
                    if ( false === $timestamp || $timestamp <= time() + MINUTE_IN_SECONDS ) { return self::denied(); }
                    $after['publish_at'] = wp_date( DATE_ATOM, $timestamp, wp_timezone() );
                }
                $effects = 'content.publish' === $action ? 'Publishes this exact content publicly. Normal publication hooks and configured notifications may run.' : 'Schedules public publication at the shown time in the site time zone. Normal scheduled publication hooks may run.';
            } elseif ( 'content.trash' === $action ) {
                if ( ! current_user_can( 'delete_post', $id ) || 'trash' === $source['status'] ) { return self::denied(); }
                $after['status'] = 'trash';
                $effects = 'Moves this content to trash and removes its current public visibility. This does not permanently delete it.';
            } elseif ( 'content.restore' === $action ) {
                if ( 'trash' !== $source['status'] ) { return self::denied(); }
                $previous = get_post_meta( $id, '_wp_trash_meta_status', true );
                $restored = apply_filters( 'wp_untrash_post_status', 'draft', $id, $previous );
                if ( ! in_array( $restored, [ 'draft', 'pending', 'private', 'publish', 'future' ], true ) ) { return self::denied(); }
                if ( in_array( $restored, [ 'publish', 'private', 'future' ], true ) ) {
                    $object = get_post_type_object( $source['post_type'] );
                    if ( ! $object || ! current_user_can( $object->cap->publish_posts ) ) { return self::denied(); }
                }
                $after['status'] = $restored;
                $effects = 'Restores this content with actual resulting status: ' . $restored . '. Public visibility follows that status.';
            } elseif ( 'content.restore_revision' === $action ) {
                $revision = YoRo_WordPress_Content_Actions::preview_revision( $intent );
                if ( is_wp_error( $revision ) ) { return $revision; }
                $before['revision_id'] = $intent['revision_id'];
                foreach ( [ 'title', 'content', 'excerpt' ] as $field ) { $after[$field] = $revision['data'][$field]; }
                $effects = 'Restores this exact revision while retaining current visibility (' . $source['status'] . '). If published, the restored content becomes public immediately.';
            } elseif ( 'content.assign_terms' === $action ) {
                $taxonomy = get_taxonomy( $intent['taxonomy'] );
                if ( ! $taxonomy || ! is_object_in_taxonomy( $source['post_type'], $intent['taxonomy'] ) || ! current_user_can( $taxonomy->cap->assign_terms ) ) { return self::denied(); }
                foreach ( $intent['term_ids'] as $term_id ) { $term = get_term( $term_id, $intent['taxonomy'] ); if ( ! $term || is_wp_error( $term ) ) { return self::denied(); } }
                $existing = wp_get_object_terms( $id, $intent['taxonomy'], [ 'fields' => 'ids' ] );
                if ( is_wp_error( $existing ) || count( $existing ) > 20 ) { return self::denied(); }
                $before['term_ids'] = array_map( 'intval', $existing ); $before['taxonomy'] = $intent['taxonomy'];
                $after['term_ids'] = $intent['term_ids']; $after['taxonomy'] = $intent['taxonomy'];
            } elseif ( 'content.set_featured_image' === $action ) {
                $media = YoRo_WordPress_Content_Actions::management_source( 'media', $intent['media_id'] );
                if ( is_wp_error( $media ) || ! wp_attachment_is_image( $intent['media_id'] ) ) { return self::denied(); }
                $before['media_id'] = (int) get_post_thumbnail_id( $id );
                $after['media_id'] = $intent['media_id'];
                $after['media_version'] = $media['version'];
            }
        } elseif ( str_starts_with( $action, 'comments.' ) ) {
            $source = YoRo_WordPress_Content_Actions::management_source( 'comment', $id );
            if ( is_wp_error( $source ) ) { return $source; }
            $before = $source; $after = $source; $name = 'Comment ' . $id;
            $params['expected_version'] = $source['version'];
            if ( 'comments.reply' === $action ) {
                $params['content'] = wp_kses_post( $intent['content'] );
                if ( '' === trim( wp_strip_all_tags( $params['content'] ) ) ) { return self::denied(); }
                $after = [ 'content_id' => $source['content_id'], 'parent_id' => $id, 'content' => $params['content'], 'status' => 'approved', 'author' => sanitize_text_field( wp_get_current_user()->display_name ) ];
                $effects = 'Posts a PUBLIC reply under ' . $after['author'] . '. Normal WordPress comment hooks and configured notifications may run.';
            } else { $after['status'] = $intent['status']; $effects = 'Changes comment moderation and public visibility. Normal WordPress moderation hooks may run.'; }
        } elseif ( 'media.update_metadata' === $action ) {
            $source = YoRo_WordPress_Content_Actions::management_source( 'media', $id );
            if ( is_wp_error( $source ) || count( $intent ) < 2 ) { return self::denied(); }
            $before = $source; $after = $source; $name = $source['title'];
            $params['expected_version'] = $source['version'];
            foreach ( [ 'title', 'caption', 'description', 'alt_text' ] as $field ) {
                if ( isset( $intent[$field] ) ) { $params[$field] = 'description' === $field ? wp_kses_post( $intent[$field] ) : sanitize_text_field( $intent[$field] ); $after[$field] = $params[$field]; }
            }
        } elseif ( 'media.upload' === $action ) {
            if ( ! current_user_can( 'upload_files' ) ) { return self::denied(); }
            $after = array_intersect_key( $params, array_flip( [ 'filename', 'mime_type', 'byte_size', 'sha256', 'alt_text' ] ) );
            $name = $params['filename'];
            $effects = 'Uploads this file as a new media item. Its attachment URL may be publicly accessible.';
        } else {
            $source = YoRo_WordPress_Admin_Actions::management_source( $action, $intent );
            if ( is_wp_error( $source ) ) { return $source; }
            $before = $source['before']; $after = $source['after']; $params = $source['params']; $name = $source['name']; $effects = $source['effects'];
        }
        $data = [ 'action' => $wire, 'intent_hash' => YoRo_Early_Access_Product_Read::params_hash( $intent ), 'target_id' => $id,
            'target_name' => sanitize_text_field( $name ), 'params' => $params, 'before' => $before, 'after' => $after,
            'effects' => $effects, 'timezone' => wp_timezone_string() ];
        if ( strlen( wp_json_encode( $data ) ) > 55000 ) { return self::denied( 'The exact source exceeds the bounded editor size. Narrow the requested content.' ); }
        $data['version'] = YoRo_Early_Access_Product_Read::params_hash( $data );
        return $data;
    }
    public static function ordinary_operation( array $operation ) {
        $write = in_array( $operation['action'] ?? '', self::WriteActions, true );
        $facts = $operation['extensions'][$write ? 'yoro.mcp_early_access_commerce_write' : 'yoro.mcp_early_access_product_read'] ?? null;
        $action = self::source_write( $operation['action'] ?? '' ) ?: ( $operation['action'] ?? '' );
        return is_array( $facts ) && 1 === ( $facts['version'] ?? null ) && 'early_access' === ( $facts['profile'] ?? null )
            && 2 === ( $facts['binding_version'] ?? null ) && 1 === ( $facts['wordpress_management_version'] ?? null )
            && ( self::definition()['actions'][self::public_action( $action )]['scope'] ?? null ) === ( $facts['wordpress_required_scope'] ?? null )
            && ( $facts['action'] ?? null ) === ( $operation['action'] ?? null )
            && ( $write || 'wordpress_management' === ( $facts['read_family'] ?? null ) );
    }

    public static function operation_guard( array $operation ) {
        $action = self::public_action( self::source_write( $operation['action'] ?? '' ) ?: ( $operation['action'] ?? '' ) );
        $guard = self::guard( $action );
        if ( is_wp_error( $guard ) ) { return $guard; }
        $params = $operation['params'] ?? [];
        $intent = self::source_write( $operation['action'] ?? '' ) ? ( $params['intent'] ?? [] ) : ( $operation['extensions']['yoro.mcp_early_access_commerce_write']['wordpress_intent'] ?? $params );
        if ( ! self::valid_intent( $action, $intent ) ) { return self::denied(); }
        if ( str_starts_with( $action, 'content.' ) ) {
            if ( 'content.create_draft' === $action ) {
                $type = get_post_type_object( $intent['post_type'] ?? 'post' );
                if ( ! $type || ! current_user_can( $type->cap->create_posts ?? 'edit_posts' ) ) { return self::denied(); }
            } else {
                foreach ( $intent['content_ids'] ?? [ $intent['content_id'] ?? 0 ] as $id ) {
                    if ( ! current_user_can( 'edit_post', $id ) || ! in_array( get_post_type( $id ), [ 'post', 'page' ], true ) ) { return self::denied(); }
                }
            }
        }
        if ( str_starts_with( $action, 'comments.' ) && ! current_user_can( 'moderate_comments' ) ) { return self::denied(); }
        if ( str_starts_with( $action, 'media.' ) && ( ! current_user_can( 'upload_files' ) || ( isset( $intent['media_id'] ) && ! current_user_can( 'edit_post', $intent['media_id'] ) ) ) ) { return self::denied(); }
        return true;
    }

    public static function valid_write_params( $action, $params, $facts ) {
        return in_array( $action, self::WriteActions, true ) && is_array( $params ) && is_array( $facts )
            && 1 === ( $facts['wordpress_management_version'] ?? null ) && ( self::definition()['actions'][self::public_action( $action )]['scope'] ?? null ) === ( $facts['wordpress_required_scope'] ?? null )
            && is_array( $facts['wordpress_intent'] ?? null ) && self::valid_intent( $action, $facts['wordpress_intent'] )
            && self::valid_value( $params, self::definition()['actions'][self::public_action( $action )]['params'] )
            && is_string( $facts['expected_version'] ?? null ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $facts['expected_version'] )
            && is_string( $facts['source_operation_id'] ?? null ) && 1 === preg_match( '/\Aenvelope_[a-f0-9]{64}\z/D', $facts['source_operation_id'] )
            && is_string( $facts['source_result_hash'] ?? null ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $facts['source_result_hash'] );
    }

    public static function execute_write( array $operation, ?Closure $fence ) {
        $wire = $operation['action'] ?? '';
        $action = self::public_action( $wire );
        $params = $operation['params'] ?? [];
        $facts = $operation['extensions']['yoro.mcp_early_access_commerce_write'] ?? [];
        if ( ! self::ordinary_operation( $operation ) || ! self::valid_write_params( $wire, $params, $facts ) || ! $fence
            || empty( $operation['authorization']['execution_permit'] ) || is_wp_error( self::operation_guard( $operation ) ) ) { return self::denied(); }
        $source = self::prepare( $wire, $facts['wordpress_intent'] );
        if ( is_wp_error( $source ) ) { return $source; }
        if ( ! hash_equals( $source['version'], $facts['expected_version'] ) || YoRo_Early_Access_Product_Read::params_hash( $source['params'] ) !== YoRo_Early_Access_Product_Read::params_hash( $params ) ) {
            return self::denied( 'The exact target, preview or offered update changed after preparation.' );
        }
        $journal = YoRo_Connector_Execution_Journal::begin( $operation );
        if ( 'executing' !== ( $journal['state'] ?? null ) || ! $fence() ) { return self::denied( 'The durable marker and current execution fence are required.' ); }
        if ( 'content.bulk_update_status' === $action ) { return self::execute_batch( $operation, $source, $fence ); }
        $callback = YoRo_Action_Registry::get( $action )['callback'] ?? null;
        if ( ! is_callable( $callback ) ) { return self::denied(); }
        // The durable marker exists before the first native side effect. No result after this boundary is replayable.
        try { $result = 'media.upload' === $action ? YoRo_Connector_Media_Client::with_ordinary_binding( $params['media_reference_id'], $facts['media_download_url'] ?? null, static fn() => call_user_func( $callback, $params ) ) : call_user_func( $callback, $params ); }
        catch ( Throwable $error ) { return new WP_Error( 'local_execution_outcome_unknown', 'The local outcome is unknown. Do not repeat this change.' ); }
        if ( is_wp_error( $result ) || empty( $result['success'] ) ) { return new WP_Error( 'local_execution_outcome_unknown', 'The native action stopped without a verified complete result. Do not repeat it.' ); }
        $observed = self::observe( $action, $params, $result, $source );
        if ( is_wp_error( $observed ) ) { return $observed; }
        $id = $params['content_id'] ?? $params['media_id'] ?? $params['comment_id'] ?? $params['user_id'] ?? 0;
        $data = [ 'target_id' => $id, 'new_target_id' => $observed['new_target_id'], 'current_version' => $observed['version'],
            'outcomes' => [ [ 'target_id' => $id, 'status' => 'executed', 'current_version' => $observed['version'] ] ], 'count' => 1, 'updated_count' => 1, 'non_atomic' => false ];
        return self::write_result( $wire, $data );
    }

    private static function write_result( $action, array $data ) {
        $message = 'Confirmed WordPress workflow completed. Inspect every target outcome; unknown outcomes must never be repeated automatically.';
        return [ 'success' => true, 'action' => $action, 'message' => $message, 'summary' => $message, 'assistant_summary' => $message,
            'data' => $data, 'read_only' => false, 'undoable' => false, 'result_type' => 'wordpress_management', 'display_type' => 'wordpress_management', 'extensions' => (object) [] ];
    }

    private static function observe( $action, array $params, array $result, array $source ) {
        $data = $result['data'] ?? [];
        $new = null; $version = null; $valid = true;
        if ( str_starts_with( $action, 'content.' ) ) {
            $id = $params['content_id'] ?? ( $data['content_id'] ?? 0 );
            $current = YoRo_WordPress_Content_Actions::management_source( 'content', $id );
            if ( is_wp_error( $current ) ) { $valid = false; }
            else {
                $version = $current['version'];
                foreach ( [ 'title', 'content', 'status', 'post_type' ] as $field ) {
                    if ( array_key_exists( $field, $source['after'] ) && ( $current[$field] ?? null ) !== $source['after'][$field] ) { $valid = false; }
                }
                if ( 'content.assign_terms' === $action ) {
                    $terms = wp_get_object_terms( $id, $params['taxonomy'], [ 'fields' => 'ids' ] );
                    $expected = $params['term_ids']; if ( ! is_wp_error( $terms ) ) { $terms = array_map( 'intval', $terms ); sort( $terms ); sort( $expected ); }
                    $valid = $valid && ! is_wp_error( $terms ) && $terms === $expected;
                }
                if ( 'content.set_featured_image' === $action ) { $valid = $valid && (int) get_post_thumbnail_id( $id ) === $params['media_id']; }
                if ( 'content.create_draft' === $action ) { $new = $id > 0 ? $id : null; $valid = $valid && $new; }
            }
        } elseif ( str_starts_with( $action, 'media.' ) ) {
            $id = $params['media_id'] ?? ( $data['media_id'] ?? 0 );
            $current = YoRo_WordPress_Content_Actions::management_source( 'media', $id );
            if ( is_wp_error( $current ) ) { $valid = false; }
            else { $version = $current['version']; foreach ( [ 'title', 'caption', 'description', 'alt_text' ] as $field ) { if ( isset( $params[$field] ) && ( $current[$field] ?? null ) !== $params[$field] ) { $valid = false; } } }
            if ( 'media.upload' === $action ) { $new = $id > 0 ? $id : null; $valid = $valid && $new && ( $current['mime_type'] ?? null ) === $params['mime_type']; }
        } elseif ( str_starts_with( $action, 'comments.' ) ) {
            $id = 'comments.reply' === $action ? ( $data['comment_id'] ?? 0 ) : $params['comment_id'];
            $comment = get_comment( $id );
            if ( ! $comment instanceof WP_Comment ) { $valid = false; }
            else {
                $current = YoRo_WordPress_Content_Actions::present_comment( $comment ); $version = $current['version'];
                $valid = 'comments.reply' === $action ? ( $current['parent_id'] === $params['comment_id'] && $current['content'] === $params['content'] && 'approved' === $current['status'] )
                    : $current['status'] === ( [ 'approve' => 'approved', 'hold' => 'unapproved' ][$params['status']] ?? $params['status'] );
            }
        } else {
            $after = YoRo_WordPress_Admin_Actions::management_observation( $action, $params );
            if ( is_wp_error( $after ) ) { $valid = false; }
            else {
                foreach ( $source['after'] as $field => $value ) { if ( ( $after[$field] ?? null ) !== $value ) { $valid = false; } }
                $version = YoRo_Early_Access_Product_Read::params_hash( $after );
            }
        }
        return $valid ? [ 'new_target_id' => $new, 'version' => $version ] : new WP_Error( 'local_execution_outcome_unknown', 'The exact reviewed outcome could not be verified. Do not repeat this change.' );
    }

    private static function execute_batch( array $operation, array $source, Closure $fence ) {
        $rows = []; $stopped = false;
        foreach ( $operation['params']['items'] as $item ) {
            $id = $item['content_id']; $row = [ 'target_id' => $id, 'status' => 'not_attempted', 'current_version' => null ];
            if ( $stopped || ! $fence() ) { $stopped = true; $rows[] = $row; continue; }
            $child = $operation; $child['envelope_id'] = 'envelope_' . hash( 'sha256', $operation['envelope_id'] . '|wordpress-content|' . $id );
            $child['operation_id'] = $operation['operation_id'] . '_' . $id; $child['params'] = [ 'items' => [ $item ], 'status' => $operation['params']['status'] ];
            $journal = YoRo_Connector_Execution_Journal::begin( $child );
            if ( 'completed' === ( $journal['state'] ?? null ) ) {
                $saved = $journal['result'] ?? null;
                if ( ! is_array( $saved ) || ( $saved['target_id'] ?? null ) !== $id || ! in_array( $saved['status'] ?? null, [ 'executed', 'unknown', 'not_attempted' ], true ) ) { $row['status'] = 'unknown'; $stopped = true; }
                else { $row = $saved; $stopped = 'executed' !== $row['status']; }
                $rows[] = $row; continue;
            }
            if ( 'new' !== ( $journal['state'] ?? null ) ) { $row['status'] = 'unknown'; $stopped = true; $rows[] = $row; continue; }
            if ( ! $fence() ) { $stopped = true; $rows[] = $row; continue; }
            try { $result = YoRo_WordPress_Content_Actions::bulk_update_status( $child['params'] ); }
            catch ( Throwable $error ) { $result = self::denied(); }
            $current = YoRo_WordPress_Content_Actions::management_source( 'content', $id );
            $row['status'] = ! is_wp_error( $result ) && ! empty( $result['success'] ) && ! is_wp_error( $current ) && $current['status'] === $operation['params']['status'] ? 'executed' : 'unknown';
            $row['current_version'] = ! is_wp_error( $current ) ? $current['version'] : null;
            $completed = YoRo_Connector_Execution_Journal::complete( $child, $row );
            if ( 'completed' !== ( $completed['state'] ?? null ) ) { $row['status'] = 'unknown'; }
            $stopped = 'executed' !== $row['status']; $rows[] = $row;
        }
        return self::write_result( $operation['action'], [ 'target_id' => 0, 'new_target_id' => null, 'current_version' => null,
            'outcomes' => $rows, 'count' => count( $rows ), 'updated_count' => count( array_filter( $rows, static fn( $row ) => 'executed' === $row['status'] ) ), 'non_atomic' => true ] );
    }

    private static function bounded_read( $action, array $params ) {
        global $wpdb;
        $limit = $params['limit'] ?? 20;
        $before = $params['before_id'] ?? null;
        if ( 'taxonomies.list' === $action ) {
            $rows = [];
            foreach ( [ 'category', 'post_tag' ] as $name ) {
                $taxonomy = get_taxonomy( $name );
                if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) ) { continue; }
                $types = array_values( array_intersect( (array) $taxonomy->object_type, [ 'post', 'page' ] ) );
                if ( isset( $params['post_type'] ) && ! in_array( $params['post_type'], $types, true ) ) { continue; }
                $rows[] = [ 'name' => $name, 'label' => sanitize_text_field( $taxonomy->label ), 'hierarchical' => (bool) $taxonomy->hierarchical, 'post_types' => $types ];
            }
            return self::page( $rows, 20, null );
        }
        if ( 'terms.list' === $action ) {
            $taxonomy = get_taxonomy( $params['taxonomy'] );
            if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) ) { return self::denied(); }
            $filter = static function( $clauses, $taxonomies, $args ) use ( $before, $wpdb ) {
                if ( empty( $args['_yoro_wordpress_management'] ) ) { return $clauses; }
                $clauses['orderby'] = 'ORDER BY t.term_id'; $clauses['order'] = 'DESC';
                if ( null !== $before ) { $clauses['where'] .= $wpdb->prepare( ' AND t.term_id < %d', $before ); }
                return $clauses;
            };
            add_filter( 'terms_clauses', $filter, 10, 3 );
            try { $items = get_terms( [ 'taxonomy' => $params['taxonomy'], 'search' => $params['query'] ?? '', 'number' => $limit + 1, 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'DESC', '_yoro_wordpress_management' => true ] ); }
            finally { remove_filter( 'terms_clauses', $filter, 10 ); }
            if ( is_wp_error( $items ) ) { return $items; }
            $rows = array_map( static fn( $term ) => [ 'term_id' => (int) $term->term_id, 'name' => sanitize_text_field( $term->name ), 'slug' => sanitize_title( $term->slug ), 'taxonomy' => $term->taxonomy, 'parent_id' => (int) $term->parent, 'count' => (int) $term->count ], $items );
            return self::page( $rows, $limit, 'term_id' );
        }
        if ( 'comments.list' === $action ) {
            if ( ! current_user_can( 'moderate_comments' ) || ( isset( $params['content_id'] ) && ! current_user_can( 'read_post', $params['content_id'] ) ) ) { return self::denied(); }
            $filter = static function( $clauses, $query ) use ( $before, $wpdb ) {
                if ( empty( $query->query_vars['_yoro_wordpress_management'] ) ) { return $clauses; }
                $clauses['orderby'] = 'comment_ID DESC';
                if ( null !== $before ) { $clauses['where'] .= $wpdb->prepare( ' AND comment_ID < %d', $before ); }
                return $clauses;
            };
            add_filter( 'comments_clauses', $filter, 10, 2 );
            try { $items = get_comments( [ 'number' => $limit + 1, 'status' => $params['status'] ?? 'all', 'post_id' => $params['content_id'] ?? 0, 'orderby' => 'comment_ID', 'order' => 'DESC', 'cache_domain' => 'yoro-wordpress-management-' . ( $before ?? 'first' ), '_yoro_wordpress_management' => true ] ); }
            finally { remove_filter( 'comments_clauses', $filter, 10 ); }
            $rows = [];
            foreach ( $items as $comment ) {
                if ( ! $comment instanceof WP_Comment || ! current_user_can( 'read_post', $comment->comment_post_ID ) ) { return self::denied( 'The selected comment page includes content outside current read permissions. Choose an exact readable content target.' ); }
                $rows[] = self::project( YoRo_WordPress_Content_Actions::present_comment( $comment ), [ 'comment_id', 'content_id', 'parent_id', 'author', 'content', 'status', 'version' ] );
            }
            return self::page( $rows, $limit, 'comment_id' );
        }
        if ( 'users.list' === $action ) {
            if ( ! current_user_can( 'list_users' ) ) { return self::denied(); }
            $filter = static function( $query ) use ( $before, $wpdb ) {
                if ( empty( $query->query_vars['_yoro_wordpress_management'] ) ) { return; }
                $query->query_orderby = "ORDER BY {$wpdb->users}.ID DESC";
                if ( null !== $before ) { $query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID < %d", $before ); }
            };
            add_action( 'pre_user_query', $filter, 10, 1 );
            try { $query = new WP_User_Query( [ 'number' => $limit + 1, 'search' => empty( $params['query'] ) ? '' : '*' . sanitize_text_field( $params['query'] ) . '*', 'orderby' => 'ID', 'order' => 'DESC', 'count_total' => false, '_yoro_wordpress_management' => true ] ); }
            finally { remove_action( 'pre_user_query', $filter, 10 ); }
            $rows = [];
            foreach ( $query->get_results() as $user ) {
                $row = YoRo_WordPress_Admin_Actions::management_user( $user );
                if ( is_wp_error( $row ) ) { return $row; }
                $rows[] = $row;
            }
            return self::page( $rows, $limit, 'user_id' );
        }
        return null;
    }

}
