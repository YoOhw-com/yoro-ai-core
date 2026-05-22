<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_DB {

	const DB_VERSION = '0.1.4';
	const DB_VERSION_OPTION = 'yoro_ai_core_db_version';
	const LEGACY_DB_VERSION_OPTION = 'yoro_agent_core_db_version';
	const LEGACY_NOTE_TEXT_MIGRATED_OPTION = 'yoro_ai_core_legacy_note_text_migrated_v2';

	const LOGS_TABLE = 'yoro_ai_logs';
	const POLICIES_TABLE = 'yoro_ai_policies';
	const LEGACY_LOGS_TABLE = 'yoro_agent_logs';
	const LEGACY_POLICIES_TABLE = 'yoro_agent_policies';

	public static function logs_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::LOGS_TABLE;
	}

	public static function policies_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::POLICIES_TABLE;
	}

	public static function create_tables() {
		global $wpdb;

		self::migrate_legacy_storage();

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

		self::maybe_upgrade_log_columns();
		self::maybe_upgrade_policy_columns();
		self::seed_default_policies();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		self::migrate_legacy_storage();

		$installed_version = get_option( self::DB_VERSION_OPTION, '' );

		if ( self::DB_VERSION !== $installed_version || ! self::tables_exist() ) {
			self::create_tables();
		}
	}

	public static function tables_exist() {
		$logs_table     = self::logs_table_name();
		$policies_table = self::policies_table_name();

		return self::table_exists( $logs_table ) && self::table_exists( $policies_table );
	}

	private static function maybe_upgrade_policy_columns() {
		global $wpdb;

		$table = self::policies_table_name();

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

		$table = self::logs_table_name();

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

		$table = self::policies_table_name();

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

	private static function legacy_logs_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::LEGACY_LOGS_TABLE;
	}

	private static function legacy_policies_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::LEGACY_POLICIES_TABLE;
	}

	private static function migrate_legacy_storage() {
		static $migrated = false;

		if ( $migrated ) {
			return;
		}

		$migrated = true;

		self::migrate_legacy_table( self::legacy_logs_table_name(), self::logs_table_name() );
		self::migrate_legacy_table( self::legacy_policies_table_name(), self::policies_table_name() );
		self::migrate_legacy_db_version_option();
		self::migrate_legacy_order_note_text();
	}

	private static function migrate_legacy_table( $legacy_table, $new_table ) {
		global $wpdb;

		$legacy_exists = self::table_exists( $legacy_table );

		if ( ! $legacy_exists ) {
			return;
		}

		if ( ! self::table_exists( $new_table ) ) {
			$wpdb->query(
				'RENAME TABLE ' . self::quote_identifier( $legacy_table ) . ' TO ' . self::quote_identifier( $new_table )
			);

			return;
		}

		$legacy_count = absint( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::quote_identifier( $legacy_table ) ) );

		if ( 0 === $legacy_count ) {
			$wpdb->query( 'DROP TABLE ' . self::quote_identifier( $legacy_table ) );
			return;
		}

		$conflicts = self::count_matching_primary_ids( $legacy_table, $new_table );

		if ( 0 === $conflicts ) {
			self::copy_legacy_rows( $legacy_table, $new_table );
		}

		if ( 0 === self::count_unmigrated_primary_ids( $legacy_table, $new_table ) ) {
			$wpdb->query( 'DROP TABLE ' . self::quote_identifier( $legacy_table ) );
		}
	}

	private static function migrate_legacy_db_version_option() {
		$legacy_version = get_option( self::LEGACY_DB_VERSION_OPTION, null );

		if ( null !== $legacy_version && false === get_option( self::DB_VERSION_OPTION, false ) ) {
			update_option( self::DB_VERSION_OPTION, $legacy_version );
		}

		delete_option( self::LEGACY_DB_VERSION_OPTION );
	}

	private static function migrate_legacy_order_note_text() {
		global $wpdb;

		if ( 'yes' === get_option( self::LEGACY_NOTE_TEXT_MIGRATED_OPTION, 'no' ) ) {
			return;
		}

		$replacements = [
			'YoRo Agent note:'                         => 'YoRo AI note:',
			'Order status updated by YoRo Agent.'      => 'Order status updated by YoRo AI.',
			'YoRo Agent status update:'                => 'YoRo AI status update:',
			'Order placed on hold by YoRo Agent.'      => 'Order placed on hold by YoRo AI.',
			'YoRo Agent sent a custom customer email'  => 'YoRo AI sent a custom customer email',
			'YoRo Agent'                               => 'YoRo AI',
		];

		foreach ( $replacements as $from => $to ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->comments}
					SET comment_content = REPLACE(comment_content, %s, %s)
					WHERE comment_type = %s
					AND comment_content LIKE %s",
					$from,
					$to,
					'order_note',
					'%' . $wpdb->esc_like( $from ) . '%'
				)
			);
		}

		update_option( self::LEGACY_NOTE_TEXT_MIGRATED_OPTION, 'yes', false );
	}

	private static function table_exists( $table ) {
		global $wpdb;

		return $table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
	}

	private static function get_table_columns( $table ) {
		global $wpdb;

		$columns = $wpdb->get_col( 'DESC ' . self::quote_identifier( $table ), 0 );

		return is_array( $columns ) ? $columns : [];
	}

	private static function copy_legacy_rows( $legacy_table, $new_table ) {
		global $wpdb;

		$legacy_columns = self::get_table_columns( $legacy_table );
		$new_columns    = self::get_table_columns( $new_table );
		$columns        = array_values( array_intersect( $legacy_columns, $new_columns ) );

		if ( empty( $columns ) ) {
			return false;
		}

		$quoted_columns = implode( ', ', array_map( [ __CLASS__, 'quote_identifier' ], $columns ) );

		$result = $wpdb->query(
			'INSERT IGNORE INTO ' . self::quote_identifier( $new_table ) . " ({$quoted_columns}) " .
			"SELECT {$quoted_columns} FROM " . self::quote_identifier( $legacy_table )
		);

		return false !== $result;
	}

	private static function count_matching_primary_ids( $legacy_table, $new_table ) {
		global $wpdb;

		$legacy_columns = self::get_table_columns( $legacy_table );
		$new_columns    = self::get_table_columns( $new_table );

		if ( ! in_array( 'id', $legacy_columns, true ) || ! in_array( 'id', $new_columns, true ) ) {
			return 0;
		}

		return absint(
			$wpdb->get_var(
				'SELECT COUNT(*) FROM ' . self::quote_identifier( $legacy_table ) . ' legacy_table ' .
				'INNER JOIN ' . self::quote_identifier( $new_table ) . ' new_table ON legacy_table.id = new_table.id'
			)
		);
	}

	private static function count_unmigrated_primary_ids( $legacy_table, $new_table ) {
		global $wpdb;

		$legacy_columns = self::get_table_columns( $legacy_table );
		$new_columns    = self::get_table_columns( $new_table );

		if ( ! in_array( 'id', $legacy_columns, true ) || ! in_array( 'id', $new_columns, true ) ) {
			return 1;
		}

		return absint(
			$wpdb->get_var(
				'SELECT COUNT(*) FROM ' . self::quote_identifier( $legacy_table ) . ' legacy_table ' .
				'LEFT JOIN ' . self::quote_identifier( $new_table ) . ' new_table ON legacy_table.id = new_table.id ' .
				'WHERE new_table.id IS NULL'
			)
		);
	}

	private static function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
	}
}
