<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_WordPress_Site_Actions {

	public static function register() {
		self::register_action( 'site.get_info', 'Get WordPress site information', [ __CLASS__, 'get_info' ], [], [] );
		self::register_action( 'site.get_health', 'Get WordPress site health summary', [ __CLASS__, 'get_health' ], [], [] );
		self::register_action( 'site.get_update_status', 'Get WordPress update status', [ __CLASS__, 'get_update_status' ], [], [] );
		self::register_action( 'taxonomies.list', 'List WordPress taxonomies', [ __CLASS__, 'list_taxonomies' ], [ 'post_type' => 'string' ], [] );
		self::register_action( 'terms.list', 'List WordPress taxonomy terms', [ __CLASS__, 'list_terms' ], [ 'taxonomy' => 'string', 'query' => 'string', 'limit' => 'integer' ], [ 'taxonomy' ], 100 );
		self::register_action( 'plugins.list', 'List installed WordPress plugins', [ __CLASS__, 'list_plugins' ], [], [], 200 );
		self::register_action( 'themes.list', 'List installed WordPress themes', [ __CLASS__, 'list_themes' ], [], [], 100 );
	}

	public static function modules() {
		$modules = [
			'wordpress.core',
			'wordpress.content',
			'wordpress.taxonomy',
			'wordpress.comments',
			'wordpress.media',
			'wordpress.extensions',
			'wordpress.users',
		];

		if ( yoro_ai_core_has_woocommerce() ) {
			$modules[] = 'woocommerce';
		}

		return $modules;
	}

	private static function register_action( $name, $label, $callback, $schema, $required, $max_targets = 1 ) {
		YoRo_Action_Registry::register(
			$name,
			[
				'label'                 => __( $label, 'yoro-ai-core' ),
				'description'           => __( $label . ' through the managed connector.', 'yoro-ai-core' ),
				'category'              => 'wordpress_site',
				'callback'              => $callback,
				'input_schema'          => $schema,
				'output_schema'         => [ 'success' => 'boolean' ],
				'requires_confirmation' => false,
				'risk_level'            => 'low',
				'write_action'          => false,
				'effect'                => 'read',
				'reversibility'         => 'full',
				'external_side_effect'  => false,
				'target_type'           => strtok( $name, '.' ),
				'max_targets'           => max( 1, absint( $max_targets ) ),
				'max_items'             => max( 1, absint( $max_targets ) ),
				'ai_exposed'            => true,
				'ai_description'        => __( $label, 'yoro-ai-core' ),
				'required_params'       => $required,
				'capabilities'          => [ 'wordpress_core', 'read' ],
			]
		);
	}

	public static function get_info() {
		global $wp_version;

		return self::success(
			[
				'name'                => get_bloginfo( 'name' ),
				'description'         => get_bloginfo( 'description' ),
				'home_url'            => home_url(),
				'site_url'            => site_url(),
				'wordpress_version'   => $wp_version,
				'php_version'         => PHP_VERSION,
				'locale'              => get_locale(),
				'timezone'            => wp_timezone_string(),
				'environment'         => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				'is_multisite'        => is_multisite(),
				'active_theme'        => get_stylesheet(),
				'permalink_structure' => get_option( 'permalink_structure' ),
				'modules'             => self::modules(),
			],
			'WordPress site information loaded.'
		);
	}

	public static function get_health() {
		$permission = self::require_capability( 'manage_options', 'site health' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$https = wp_is_using_https();
		$checks = [
			[
				'id'       => 'https',
				'status'   => $https ? 'good' : 'critical',
				'summary'  => $https ? 'The site uses HTTPS.' : 'The site is not using HTTPS.',
			],
			[
				'id'       => 'debug_display',
				'status'   => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'recommended' : 'good',
				'summary'  => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'Debug display is enabled.' : 'Debug output is not displayed publicly.',
			],
			[
				'id'       => 'cron',
				'status'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'recommended' : 'good',
				'summary'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'WP-Cron is disabled; an external scheduler is required.' : 'WP-Cron is enabled.',
			],
			[
				'id'       => 'search_visibility',
				'status'   => get_option( 'blog_public' ) ? 'good' : 'recommended',
				'summary'  => get_option( 'blog_public' ) ? 'Search engines may index the site.' : 'Search engine indexing is discouraged.',
			],
		];

		return self::success(
			[
				'status' => in_array( 'critical', wp_list_pluck( $checks, 'status' ), true ) ? 'critical' : 'good',
				'checks' => $checks,
			],
			'WordPress site health summary loaded.'
		);
	}

	public static function get_update_status() {
		$permission = self::require_capability( 'update_core', 'update status' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$core_updates = array_values(
			array_filter(
				(array) get_core_updates(),
				static function ( $update ) {
					return is_object( $update ) && 'upgrade' === ( $update->response ?? '' );
				}
			)
		);
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates = get_site_transient( 'update_themes' );

		return self::success(
			[
				'core' => array_map(
					static function ( $update ) {
						return [
							'version'  => sanitize_text_field( $update->current ?? '' ),
							'locale'   => sanitize_text_field( $update->locale ?? '' ),
							'response' => sanitize_key( $update->response ?? '' ),
						];
					},
					$core_updates
				),
				'plugins' => array_map(
					static function ( $file, $update ) {
						return [
							'plugin'      => sanitize_text_field( $file ),
							'new_version' => sanitize_text_field( $update->new_version ?? '' ),
						];
					},
					array_keys( (array) ( $plugin_updates->response ?? [] ) ),
					array_values( (array) ( $plugin_updates->response ?? [] ) )
				),
				'themes' => array_map(
					static function ( $stylesheet, $update ) {
						return [
							'theme'       => sanitize_text_field( $stylesheet ),
							'new_version' => sanitize_text_field( $update['new_version'] ?? '' ),
						];
					},
					array_keys( (array) ( $theme_updates->response ?? [] ) ),
					array_values( (array) ( $theme_updates->response ?? [] ) )
				),
				'checked_at' => gmdate( 'c', max(
					absint( $plugin_updates->last_checked ?? 0 ),
					absint( $theme_updates->last_checked ?? 0 )
				) ),
			],
			'Cached WordPress update status loaded.'
		);
	}

	public static function list_taxonomies( $params ) {
		$args = [ 'show_ui' => true ];
		$taxonomies = get_taxonomies( $args, 'objects' );
		$post_type = sanitize_key( $params['post_type'] ?? '' );

		$items = [];
		foreach ( $taxonomies as $taxonomy ) {
			if ( '' !== $post_type && ! in_array( $post_type, (array) $taxonomy->object_type, true ) ) {
				continue;
			}

			$items[] = [
				'name'         => $taxonomy->name,
				'label'        => $taxonomy->label,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'post_types'   => array_values( (array) $taxonomy->object_type ),
				'rest_base'    => sanitize_text_field( $taxonomy->rest_base ?: $taxonomy->name ),
			];
		}

		return self::success( $items, 'WordPress taxonomies loaded.' );
	}

	public static function list_terms( $params ) {
		$taxonomy = sanitize_key( $params['taxonomy'] ?? '' );
		$object = get_taxonomy( $taxonomy );

		if ( ! $object || ! current_user_can( $object->cap->manage_terms ) ) {
			return new WP_Error( 'yoro_terms_read_forbidden', 'The WordPress actor cannot access this taxonomy.' );
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'search'     => sanitize_text_field( $params['query'] ?? '' ),
				'number'     => min( 100, max( 1, absint( $params['limit'] ?? 50 ) ) ),
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return self::success(
			array_map(
				static function ( $term ) {
					return [
						'term_id'     => (int) $term->term_id,
						'name'        => $term->name,
						'slug'        => $term->slug,
						'taxonomy'    => $term->taxonomy,
						'parent_id'   => (int) $term->parent,
						'count'       => (int) $term->count,
						'description' => $term->description,
					];
				},
				$terms
			),
			'WordPress terms loaded.'
		);
	}

	public static function list_plugins() {
		$permission = self::require_capability( 'activate_plugins', 'installed plugins' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$updates = get_site_transient( 'update_plugins' );
		$items = [];

		foreach ( get_plugins() as $file => $plugin ) {
			$update = $updates->response[ $file ] ?? null;
			$items[] = [
				'plugin'            => $file,
				'name'              => sanitize_text_field( $plugin['Name'] ?? $file ),
				'version'           => sanitize_text_field( $plugin['Version'] ?? '' ),
				'active'            => is_plugin_active( $file ),
				'network_active'    => is_multisite() && is_plugin_active_for_network( $file ),
				'update_available'  => is_object( $update ),
				'available_version' => is_object( $update ) ? sanitize_text_field( $update->new_version ?? '' ) : '',
			];
		}

		return self::success( $items, 'Installed WordPress plugins loaded.' );
	}

	public static function list_themes() {
		$permission = self::require_capability( 'switch_themes', 'installed themes' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$updates = get_site_transient( 'update_themes' );
		$active = get_stylesheet();
		$items = [];

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$update = $updates->response[ $stylesheet ] ?? null;
			$items[] = [
				'theme'             => $stylesheet,
				'name'              => $theme->get( 'Name' ),
				'version'           => $theme->get( 'Version' ),
				'active'            => $stylesheet === $active,
				'update_available'  => is_array( $update ),
				'available_version' => is_array( $update ) ? sanitize_text_field( $update['new_version'] ?? '' ) : '',
			];
		}

		return self::success( $items, 'Installed WordPress themes loaded.' );
	}

	private static function require_capability( $capability, $resource ) {
		if ( current_user_can( $capability ) ) {
			return true;
		}

		return new WP_Error(
			'yoro_wordpress_read_forbidden',
			sprintf( 'The WordPress actor cannot access %s.', sanitize_text_field( $resource ) )
		);
	}

	private static function success( $data, $message ) {
		return [ 'success' => true, 'message' => $message, 'data' => $data ];
	}
}
