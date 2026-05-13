<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_DB {

	const DB_VERSION = '0.1.3';
	const DB_VERSION_OPTION = 'yoro_agent_core_db_version';

	public static function create_tables() {
		global $wpdb;

		$table   = $wpdb->prefix . 'yoro_agent_logs';
		$table_policy = $wpdb->prefix . 'yoro_agent_policies';
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

		self::maybe_upgrade_log_columns();
		self::maybe_upgrade_policy_columns();
		self::seed_default_policies();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		$installed_version = get_option( self::DB_VERSION_OPTION, '' );

		if ( self::DB_VERSION !== $installed_version || ! self::tables_exist() ) {
			self::create_tables();
		}
	}

	public static function tables_exist() {
		global $wpdb;

		$logs_table     = $wpdb->prefix . 'yoro_agent_logs';
		$policies_table = $wpdb->prefix . 'yoro_agent_policies';

		$logs_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table )
		);

		$policies_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $policies_table )
		);

		return ( $logs_exists === $logs_table && $policies_exists === $policies_table );
	}

	private static function maybe_upgrade_policy_columns() {
		global $wpdb;

		$table = $wpdb->prefix . 'yoro_agent_policies';

		$columns = $wpdb->get_col( "DESC {$table}", 0 );

		if ( ! is_array( $columns ) ) {
			return;
		}

		$add_column = function( $column, $definition ) use ( $wpdb, $table, $columns ) {
			if ( ! in_array( $column, $columns, true ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$definition}" );
			}
		};

		$add_column( 'requires_preview', 'requires_preview TINYINT(1) NOT NULL DEFAULT 0' );
		$add_column( 'requires_confirmation', 'requires_confirmation TINYINT(1) NOT NULL DEFAULT 0' );
		$add_column( 'allow_ai', 'allow_ai TINYINT(1) NOT NULL DEFAULT 1' );
		$add_column( 'allow_direct', 'allow_direct TINYINT(1) NOT NULL DEFAULT 1' );
		$add_column( 'allow_write', 'allow_write TINYINT(1) NOT NULL DEFAULT 1' );
		$add_column( 'allowed_risk_levels', 'allowed_risk_levels TEXT NULL' );
		$add_column( 'updated_at', 'updated_at DATETIME NULL' );

		$wpdb->query(
			"UPDATE {$table}
			SET allowed_risk_levels = " . $wpdb->prepare( '%s', wp_json_encode( [ 'low', 'medium', 'high' ] ) ) . "
			WHERE allowed_risk_levels IS NULL OR allowed_risk_levels = ''"
		);
	}

	private static function maybe_upgrade_log_columns() {
		global $wpdb;

		$table = $wpdb->prefix . 'yoro_agent_logs';

		$columns = $wpdb->get_col( "DESC {$table}", 0 );

		if ( ! is_array( $columns ) ) {
			return;
		}

		$add_column = function( $column, $definition ) use ( $wpdb, $table, $columns ) {
			if ( ! in_array( $column, $columns, true ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$definition}" );
			}
		};

		$add_column( 'trace_id', "trace_id VARCHAR(100) DEFAULT '' AFTER confirm" );
		$add_column( 'telemetry', 'telemetry LONGTEXT NULL AFTER result' );

		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$has_trace_index = false;

		if ( is_array( $indexes ) ) {
			foreach ( $indexes as $index ) {
				if ( ! empty( $index['Key_name'] ) && 'trace_id' === $index['Key_name'] ) {
					$has_trace_index = true;
					break;
				}
			}
		}

		if ( ! $has_trace_index ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY trace_id (trace_id)" );
		}
	}

	public static function seed_default_policies() {
		global $wpdb;

		$table = $wpdb->prefix . 'yoro_agent_policies';

		$exists = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

		if ( $exists > 0 ) {
			return;
		}

		$defaults = [
			[
				'action'                => 'orders.hold',
				'role'                  => 'administrator',
				'allow'                 => 1,
				'max_items'             => 50,
				'requires_preview'      => 0,
				'requires_confirmation' => 1,
				'allow_ai'              => 1,
				'allow_direct'          => 1,
				'allow_write'           => 1,
				'allowed_risk_levels'   => wp_json_encode( [ 'low', 'medium', 'high' ] ),
				'updated_at'            => current_time( 'mysql' ),
			],
			[
				'action'                => 'orders.hold',
				'role'                  => 'shop_manager',
				'allow'                 => 1,
				'max_items'             => 20,
				'requires_preview'      => 0,
				'requires_confirmation' => 1,
				'allow_ai'              => 1,
				'allow_direct'          => 1,
				'allow_write'           => 1,
				'allowed_risk_levels'   => wp_json_encode( [ 'low', 'medium' ] ),
				'updated_at'            => current_time( 'mysql' ),
			],
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
			$wpdb->insert( $table, $row );
		}
	}
}
