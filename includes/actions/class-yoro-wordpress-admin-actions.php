<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_WordPress_Admin_Actions {

	const POLICY_OPTION = 'yoro_connector_admin_actions_enabled';
	const BACKUP_ATTESTED_OPTION = 'yoro_connector_backup_attested_at';
	const BACKUP_MAX_AGE = DAY_IN_SECONDS;

	public static function register() {
		self::register_action( 'plugins.activate', 'Activate a WordPress plugin', 'activate_plugin', [ 'plugin' => 'string', 'expected_version' => 'string' ], [ 'plugin', 'expected_version' ], 'high' );
		self::register_action( 'plugins.deactivate', 'Deactivate a WordPress plugin', 'deactivate_plugin', [ 'plugin' => 'string', 'expected_version' => 'string' ], [ 'plugin', 'expected_version' ], 'high' );
		self::register_action( 'plugins.update', 'Update a WordPress plugin', 'update_plugin', [ 'plugin' => 'string', 'expected_version' => 'string' ], [ 'plugin', 'expected_version' ], 'critical' );
		self::register_action( 'themes.activate', 'Activate a WordPress theme', 'activate_theme', [ 'theme' => 'string', 'expected_version' => 'string' ], [ 'theme', 'expected_version' ], 'critical' );
		self::register_action( 'themes.update', 'Update a WordPress theme', 'update_theme', [ 'theme' => 'string', 'expected_version' => 'string' ], [ 'theme', 'expected_version' ], 'critical' );
		self::register_action( 'site.update_core', 'Update WordPress core', 'update_core', [ 'expected_version' => 'string', 'target_version' => 'string' ], [ 'expected_version', 'target_version' ], 'critical' );
		self::register_action( 'site.update_settings', 'Update allowlisted WordPress settings', 'update_settings', [ 'settings' => 'object', 'expected_version' => 'string' ], [ 'settings', 'expected_version' ], 'high' );
		self::register_read_action( 'users.list', 'List WordPress users and roles', 'list_users', [ 'query' => 'string', 'limit' => 'integer' ], [], 100 );
		self::register_action( 'users.update_role', 'Update a WordPress user role', 'update_user_role', [ 'user_id' => 'integer', 'role' => 'string', 'expected_version' => 'string' ], [ 'user_id', 'role', 'expected_version' ], 'critical' );
	}

	public static function connector_policy_ready() {
		$attested_at = absint( get_option( self::BACKUP_ATTESTED_OPTION, 0 ) );

		return 'yes' === get_option( self::POLICY_OPTION, 'no' )
			&& $attested_at >= time() - self::BACKUP_MAX_AGE;
	}

	private static function register_action( $name, $label, $callback, $schema, $required, $risk ) {
		YoRo_Action_Registry::register(
			$name,
			[
				'label'                 => __( $label, 'yoro-ai-core' ),
				'description'           => __( $label . ' through a fail-closed administration policy.', 'yoro-ai-core' ),
				'category'              => 'wordpress_admin',
				'callback'              => [ __CLASS__, $callback ],
				'input_schema'          => $schema,
				'output_schema'         => [ 'success' => 'boolean' ],
				'requires_confirmation' => true,
				'risk_level'            => $risk,
				'write_action'          => true,
				'effect'                => 'mutate',
				'reversibility'         => 'partial',
				'external_side_effect'  => false,
				'target_type'           => strtok( $name, '.' ),
				'max_targets'           => 1,
				'max_items'             => 1,
				'ai_exposed'            => true,
				'ai_description'        => __( $label, 'yoro-ai-core' ),
				'required_params'       => $required,
				'capabilities'          => [ 'wordpress_admin', 'write' ],
			]
		);
	}

	private static function register_read_action( $name, $label, $callback, $schema, $required, $max_items ) {
		YoRo_Action_Registry::register(
			$name,
			[
				'label'                 => __( $label, 'yoro-ai-core' ),
				'description'           => __( $label . ' through the managed connector.', 'yoro-ai-core' ),
				'category'              => 'wordpress_admin',
				'callback'              => [ __CLASS__, $callback ],
				'input_schema'          => $schema,
				'output_schema'         => [ 'success' => 'boolean' ],
				'requires_confirmation' => false,
				'risk_level'            => 'medium',
				'write_action'          => false,
				'effect'                => 'read',
				'reversibility'         => 'full',
				'external_side_effect'  => false,
				'target_type'           => 'users',
				'max_targets'           => $max_items,
				'max_items'             => $max_items,
				'ai_exposed'            => true,
				'ai_description'        => __( $label, 'yoro-ai-core' ),
				'required_params'       => $required,
				'capabilities'          => [ 'wordpress_users', 'read' ],
			]
		);
	}

	public static function activate_plugin( $params ) {
		$guard = self::guard( 'activate_plugins' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = plugin_basename( sanitize_text_field( $params['plugin'] ?? '' ) );
		$plugins = get_plugins();

		if ( ! isset( $plugins[ $plugin ] ) || ! hash_equals( (string) $plugins[ $plugin ]['Version'], (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_plugin_version_conflict', 'The plugin is missing or its installed version changed.' );
		}

		$result = activate_plugin( $plugin, '', false, true );

		return is_wp_error( $result ) ? $result : self::success( [ 'plugin' => $plugin, 'active' => true ], 'Plugin activated.' );
	}

	public static function deactivate_plugin( $params ) {
		$guard = self::guard( 'deactivate_plugins' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = plugin_basename( sanitize_text_field( $params['plugin'] ?? '' ) );
		$plugins = get_plugins();

		if ( plugin_basename( YORO_AI_CORE_FILE ) === $plugin ) {
			return new WP_Error( 'yoro_plugin_protected', 'The connector cannot deactivate itself.' );
		}
		if ( ! isset( $plugins[ $plugin ] ) || ! hash_equals( (string) $plugins[ $plugin ]['Version'], (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_plugin_version_conflict', 'The plugin is missing or its installed version changed.' );
		}
		if ( ! is_plugin_active( $plugin ) ) {
			return new WP_Error( 'yoro_plugin_not_active', 'The requested plugin is not active.' );
		}

		deactivate_plugins( $plugin, false, false );

		return self::success( [ 'plugin' => $plugin, 'active' => is_plugin_active( $plugin ) ], 'Plugin deactivation completed.' );
	}

	public static function update_plugin( $params ) {
		$guard = self::guard( 'update_plugins' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$plugin = plugin_basename( sanitize_text_field( $params['plugin'] ?? '' ) );
		$plugins = get_plugins();

		if ( ! isset( $plugins[ $plugin ] ) || ! hash_equals( (string) $plugins[ $plugin ]['Version'], (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_plugin_version_conflict', 'The plugin is missing or its installed version changed.' );
		}

		$result = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $plugin );

		return true === $result ? self::success( [ 'plugin' => $plugin ], 'Plugin updated.' ) : new WP_Error( 'yoro_plugin_update_failed', 'The plugin update did not complete.' );
	}

	public static function activate_theme( $params ) {
		$guard = self::guard( 'switch_themes' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$theme = sanitize_key( $params['theme'] ?? '' );
		$installed = wp_get_theme( $theme );
		if ( ! $installed->exists() || ! hash_equals( (string) $installed->get( 'Version' ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_theme_version_conflict', 'The theme is missing or its installed version changed.' );
		}

		switch_theme( $theme );

		return self::success( [ 'theme' => get_stylesheet() ], 'Theme activated.' );
	}

	public static function update_theme( $params ) {
		$guard = self::guard( 'update_themes' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$theme = sanitize_key( $params['theme'] ?? '' );
		$installed = wp_get_theme( $theme );

		if ( ! $installed->exists() || ! hash_equals( (string) $installed->get( 'Version' ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_theme_version_conflict', 'The theme is missing or its installed version changed.' );
		}

		$result = ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $theme );

		return true === $result ? self::success( [ 'theme' => $theme ], 'Theme updated.' ) : new WP_Error( 'yoro_theme_update_failed', 'The theme update did not complete.' );
	}

	public static function update_core( $params ) {
		global $wp_version;
		$guard = self::guard( 'update_core' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( ! hash_equals( (string) $wp_version, (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_core_version_conflict', 'The installed WordPress version changed.' );
		}

		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$target = sanitize_text_field( $params['target_version'] ?? '' );
		$update = null;
		foreach ( (array) get_core_updates() as $candidate ) {
			if ( is_object( $candidate ) && $target === (string) ( $candidate->current ?? '' ) && 'upgrade' === ( $candidate->response ?? '' ) ) {
				$update = $candidate;
				break;
			}
		}
		if ( ! is_object( $update ) ) {
			return new WP_Error( 'yoro_core_update_unavailable', 'The requested WordPress core update is not available.' );
		}

		$result = ( new Core_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $update );

		return is_wp_error( $result ) || false === $result ? new WP_Error( 'yoro_core_update_failed', 'The WordPress core update did not complete.' ) : self::success( [ 'target_version' => $target ], 'WordPress core updated.' );
	}

	public static function update_settings( $params ) {
		$guard = self::guard( 'manage_options' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$settings = is_array( $params['settings'] ?? null ) ? $params['settings'] : [];
		$allowed = [ 'blogname', 'blogdescription', 'timezone_string', 'date_format', 'time_format', 'start_of_week', 'posts_per_page', 'default_comment_status' ];
		$current = self::settings_snapshot( $allowed );

		if ( ! hash_equals( self::hash( $current ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_settings_version_conflict', 'The allowlisted WordPress settings changed.' );
		}
		if ( empty( $settings ) || array_diff( array_keys( $settings ), $allowed ) ) {
			return new WP_Error( 'yoro_settings_not_allowed', 'Only explicitly allowlisted WordPress settings may be changed.' );
		}

		foreach ( $settings as $key => $value ) {
			update_option( $key, self::sanitize_setting( $key, $value ) );
		}

		return self::success( [ 'settings' => self::settings_snapshot( $allowed ), 'version' => self::hash( self::settings_snapshot( $allowed ) ) ], 'WordPress settings updated.' );
	}

	public static function list_users( $params ) {
		if ( ! current_user_can( 'list_users' ) ) {
			return new WP_Error( 'yoro_users_read_forbidden', 'The WordPress actor cannot list users.' );
		}

		$query = new WP_User_Query(
			[
				'search' => '' !== trim( (string) ( $params['query'] ?? '' ) ) ? '*' . sanitize_text_field( $params['query'] ) . '*' : '',
				'number' => min( 100, max( 1, absint( $params['limit'] ?? 50 ) ) ),
				'orderby' => 'registered',
				'order' => 'DESC',
			]
		);

		return self::success(
			array_map( [ __CLASS__, 'present_user' ], $query->get_results() ),
			'WordPress users loaded.'
		);
	}

	public static function update_user_role( $params ) {
		$guard = self::guard( 'promote_users' );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$user = get_user_by( 'id', absint( $params['user_id'] ?? 0 ) );
		$role = sanitize_key( $params['role'] ?? '' );
		$allowed_roles = [ 'editor', 'author', 'contributor', 'subscriber' ];

		if ( ! $user instanceof WP_User || ! hash_equals( self::user_version( $user ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_user_version_conflict', 'The user is missing or their role assignment changed.' );
		}
		if ( $user->ID === get_current_user_id() || ! in_array( $role, $allowed_roles, true ) || ! get_role( $role ) ) {
			return new WP_Error( 'yoro_user_role_not_allowed', 'This role assignment is not allowed.' );
		}

		$user->set_role( $role );

		return self::success( self::present_user( get_user_by( 'id', $user->ID ) ), 'WordPress user role updated.' );
	}

	private static function guard( $capability ) {
		if ( ! current_user_can( $capability ) || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'yoro_admin_action_forbidden', 'The WordPress actor cannot perform this administration action.' );
		}
		if ( 'yes' !== get_option( self::POLICY_OPTION, 'no' ) ) {
			return new WP_Error( 'yoro_admin_policy_disabled', 'WordPress administration actions are disabled by policy.' );
		}
		if ( ! self::connector_policy_ready() ) {
			return new WP_Error( 'yoro_backup_attestation_required', 'A backup attestation from the last 24 hours is required.' );
		}

		return true;
	}

	private static function settings_snapshot( $keys ) {
		$snapshot = [];
		foreach ( $keys as $key ) {
			$snapshot[ $key ] = get_option( $key );
		}

		return $snapshot;
	}

	private static function sanitize_setting( $key, $value ) {
		if ( in_array( $key, [ 'start_of_week', 'posts_per_page' ], true ) ) {
			return absint( $value );
		}
		if ( 'default_comment_status' === $key ) {
			return in_array( sanitize_key( $value ), [ 'open', 'closed' ], true ) ? sanitize_key( $value ) : 'closed';
		}

		return sanitize_text_field( $value );
	}

	private static function present_user( $user ) {
		return [
			'user_id'      => (int) $user->ID,
			'display_name' => $user->display_name,
			'roles'        => array_values( $user->roles ),
			'registered_at'=> mysql_to_rfc3339( $user->user_registered ),
			'version'      => self::user_version( $user ),
		];
	}

	private static function user_version( $user ) {
		return self::hash( [ $user->ID, array_values( $user->roles ), $user->user_status ] );
	}

	private static function hash( $value ) {
		return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function success( $data, $message ) {
		return [ 'success' => true, 'message' => $message, 'data' => $data ];
	}
}
