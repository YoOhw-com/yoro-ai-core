<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_DB {

	const DB_VERSION = '0.1.11';
	const DB_VERSION_OPTION = 'yoro_ai_core_db_version';

	const LOGS_TABLE = 'yoro_ai_logs';
	const POLICIES_TABLE = 'yoro_ai_policies';

	public static function logs_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::LOGS_TABLE;
	}

	public static function policies_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::POLICIES_TABLE;
	}

    public static function stock_adjustments_table_name() { global $wpdb; return $wpdb->prefix . 'yoro_stock_adjustments'; }

	public static function create_tables() {
		global $wpdb;

		$table        = self::logs_table_name();
		$table_policy = self::policies_table_name();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			command TEXT NOT NULL,
			action VARCHAR(191) DEFAULT '',
			params LONGTEXT NULL,
			preview TINYINT(1) DEFAULT 0,
			confirm TINYINT(1) DEFAULT 0,
			trace_id VARCHAR(100) DEFAULT '',
			status VARCHAR(50) DEFAULT '',
			result LONGTEXT NULL,
			telemetry LONGTEXT NULL,
			undo_data LONGTEXT NULL,
			undone TINYINT(1) DEFAULT 0,
			undone_at DATETIME NULL,
			error TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY action (action),
			KEY user_id (user_id),
			KEY trace_id (trace_id),
			KEY created_at (created_at),
			KEY undone (undone)
		) {$charset};";

		$sql2 = "CREATE TABLE {$table_policy} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			action VARCHAR(191) NOT NULL,
			role VARCHAR(50) DEFAULT '',
			allow TINYINT(1) DEFAULT 1,
			max_items INT DEFAULT NULL,
			require_confirm TINYINT(1) DEFAULT NULL,
			requires_preview TINYINT(1) NOT NULL DEFAULT 0,
			requires_confirmation TINYINT(1) NOT NULL DEFAULT 0,
			allow_ai TINYINT(1) NOT NULL DEFAULT 1,
			allow_direct TINYINT(1) NOT NULL DEFAULT 1,
			allow_write TINYINT(1) NOT NULL DEFAULT 1,
			allowed_risk_levels TEXT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY action (action),
			KEY role (role)
		) {$charset};";

		dbDelta( $sql );
		dbDelta( $sql2 );

        self::create_stock_adjustments_table();

		self::maybe_upgrade_log_columns();
		self::cleanup_local_history_commands();
		self::maybe_upgrade_policy_columns();
		self::seed_default_policies();
		self::cleanup_legacy_runtime_user_meta();

		delete_option( 'yoro_workflow_ttl' );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

    private static function create_stock_adjustments_table() {
        global $wpdb;$charset=$wpdb->get_charset_collate();require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $stock_table=self::stock_adjustments_table_name();
        dbDelta("CREATE TABLE {$stock_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            adjustment_id CHAR(36) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            envelope_hash CHAR(64) NOT NULL,
            data LONGTEXT NOT NULL,
            occurred_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY adjustment_id (adjustment_id),
            KEY product_history (product_id,id)
        ) ENGINE=InnoDB {$charset};");
    }

	public static function maybe_upgrade() {
		$installed_version = get_option( self::DB_VERSION_OPTION, '' );

        if ($installed_version==='0.1.10' && self::table_exists(self::logs_table_name()) && self::table_exists(self::policies_table_name())) {
            self::create_stock_adjustments_table();
            if (self::table_exists(self::stock_adjustments_table_name())) {update_option(self::DB_VERSION_OPTION,self::DB_VERSION);}
            return;
        }
		if ( self::DB_VERSION !== $installed_version || ! self::tables_exist() ) {
			self::create_tables();
		}
	}

	public static function tables_exist() {
		$logs_table     = self::logs_table_name();
		$policies_table = self::policies_table_name();

		return self::table_exists( $logs_table ) && self::table_exists( $policies_table ) && self::table_exists(self::stock_adjustments_table_name());
	}

	private static function maybe_upgrade_policy_columns() {
		// dbDelta() in create_tables() applies the canonical policy table schema.
	}

	private static function maybe_upgrade_log_columns() {
		// dbDelta() in create_tables() applies the canonical log table schema.
	}

	public static function cleanup_local_history_commands() {
		global $wpdb;

		$table = self::logs_table_name();

		if ( ! self::table_exists( $table ) ) {
			return [
				'action_rows'     => 0,
				'transcript_rows' => 0,
			];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time cleanup for the plugin's custom log table.
		$action_rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET command = CONCAT(%s, action)
				WHERE action IS NOT NULL
				AND action <> ''
				AND command IS NOT NULL
				AND command <> ''
				AND command NOT LIKE %s
				AND command NOT LIKE %s
				AND command NOT LIKE %s",
				'action:',
				'direct:%',
				'action:%',
				'proactive:%'
			)
		);

		$transcript_rows = $wpdb->query(
			"UPDATE {$table}
			SET command = ''
			WHERE (action IS NULL OR action = '')
			AND command IS NOT NULL
			AND command <> ''"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return [
			'action_rows'     => false === $action_rows ? 0 : absint( $action_rows ),
			'transcript_rows' => false === $transcript_rows ? 0 : absint( $transcript_rows ),
		];
	}

	public static function seed_default_policies() {
		global $wpdb;

		$table = self::policies_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Counts rows in the plugin's known custom policy table before seeding defaults.
		$exists = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

		if ( $exists > 0 ) {
			return;
		}

		$defaults = [
			[
				'action'                => 'orders.update_status',
				'role'                  => 'administrator',
				'allow'                 => 1,
				'max_items'             => 20,
				'requires_preview'      => 0,
				'requires_confirmation' => 1,
				'allow_ai'              => 1,
				'allow_direct'          => 1,
				'allow_write'           => 1,
				'allowed_risk_levels'   => wp_json_encode( [ 'low', 'medium', 'high' ] ),
				'updated_at'            => current_time( 'mysql' ),
			],
			[
				'action'                => 'orders.update_status',
				'role'                  => 'shop_manager',
				'allow'                 => 1,
				'max_items'             => 10,
				'requires_preview'      => 0,
				'requires_confirmation' => 1,
				'allow_ai'              => 1,
				'allow_direct'          => 1,
				'allow_write'           => 1,
				'allowed_risk_levels'   => wp_json_encode( [ 'low', 'medium' ] ),
				'updated_at'            => current_time( 'mysql' ),
			],
		];

		foreach ( $defaults as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Seeds a plugin-owned custom policy row.
			$wpdb->insert( $table, $row );
		}
	}

	private static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Checks existence of a plugin-owned custom table.
		return $table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
	}

	private static function cleanup_legacy_runtime_user_meta() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes legacy user meta keys owned by this plugin.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
				'_yoro_conversation_memory',
				'_yoro_proactive_state'
			)
		);

		delete_option( 'yoro_memory_enabled' );
	}
}
