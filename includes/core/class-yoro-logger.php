<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Logger {

	const SUPPORT_DIAGNOSTIC_LIMIT = 100;

	const SUPPORT_DIAGNOSTIC_TELEMETRY_KEYS = [
		'ai_trace_id',
		'parser_source',
		'router_mode',
		'intent_name',
		'intent_family',
		'language',
		'confidence',
		'ai_feature',
		'ai_provider',
		'ai_model',
		'prompt_version',
		'yoro_ai_api_request_id',
		'yoro_ai_api_plan',
		'yoro_ai_api_usage_period',
		'yoro_ai_api_usage_remaining',
		'plan_type',
		'preview',
		'confirm',
		'requires_confirmation',
		'risk_level',
		'result_success',
		'result_type',
		'display_type',
		'count',
		'duration_ms',
	];

	public static function log( $data ) {
		$data = is_array( $data ) ? $data : [];

		return self::log_to_db( $data );
	}

	public static function get_support_diagnostics( $limit = self::SUPPORT_DIAGNOSTIC_LIMIT ) {
		global $wpdb;

		$table   = YoRo_DB::logs_table_name();
		$columns = self::get_table_columns( $table );
		$limit   = min( self::SUPPORT_DIAGNOSTIC_LIMIT, max( 1, absint( $limit ) ) );
		$selected_columns = array_values(
			array_intersect(
				[ 'id', 'trace_id', 'action', 'status', 'preview', 'confirm', 'telemetry', 'created_at' ],
				$columns
			)
		);

		if ( empty( $selected_columns ) || ! in_array( 'id', $selected_columns, true ) ) {
			return [];
		}

		$select_sql = implode(
			', ',
			array_map(
				static function ( $column ) {
					return '`' . $column . '`';
				},
				$selected_columns
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB -- Reads only an allowlisted projection from the plugin-owned log table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$select_sql} FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB

		return array_values(
			array_map(
				[ __CLASS__, 'support_diagnostic_row' ],
				is_array( $rows ) ? $rows : []
			)
		);
	}

	private static function support_diagnostic_row( $row ) {
		$row       = is_array( $row ) ? $row : [];
		$telemetry = [];

		if ( is_array( $row['telemetry'] ?? null ) ) {
			$telemetry = $row['telemetry'];
		} elseif ( is_string( $row['telemetry'] ?? null ) && '' !== trim( $row['telemetry'] ) ) {
			$decoded = json_decode( $row['telemetry'], true );
			$telemetry = is_array( $decoded ) ? $decoded : [];
		}

		$trace_id = sanitize_text_field( (string) ( $row['trace_id'] ?? $telemetry['trace_id'] ?? '' ) );

		return [
			'id'         => absint( $row['id'] ?? 0 ),
			'trace_id'   => substr( $trace_id, 0, 160 ),
			'action'     => substr( sanitize_text_field( (string) ( $row['action'] ?? '' ) ), 0, 160 ),
			'status'     => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'preview'    => ! empty( $row['preview'] ),
			'confirm'    => ! empty( $row['confirm'] ),
			'created_at' => substr( sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ), 0, 32 ),
			'telemetry'  => self::support_diagnostic_telemetry( $telemetry ),
		];
	}

	private static function support_diagnostic_telemetry( $telemetry ) {
		$telemetry = is_array( $telemetry ) ? $telemetry : [];
		$safe      = [];

		foreach ( self::SUPPORT_DIAGNOSTIC_TELEMETRY_KEYS as $key ) {
			if ( ! array_key_exists( $key, $telemetry ) ) {
				continue;
			}

			$value = self::support_diagnostic_value( $telemetry[ $key ] );

			if ( null !== $value ) {
				$safe[ $key ] = $value;
			}
		}

		return $safe;
	}

	private static function support_diagnostic_value( $value ) {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return substr( sanitize_text_field( $value ), 0, 200 );
		}

		return null;
	}

	private static function log_to_db( $data ) {
		global $wpdb;

		$table = YoRo_DB::logs_table_name();
		$columns = self::get_table_columns( $table );
		$telemetry = self::normalize_telemetry( $data );
		$params    = self::compact_params( $data['params'] ?? [] );
		$result    = self::compact_result( $data['result'] ?? [] );

		$insert_data = [
			'user_id'    => $data['user_id'] ?? get_current_user_id(),
			'command'    => self::compact_history_command( $data ),
			'action'     => $data['action'] ?? '',
			'params'     => maybe_serialize( $params ),
			'preview'    => ! empty( $data['preview'] ) ? 1 : 0,
			'confirm'    => ! empty( $data['confirm'] ) ? 1 : 0,
			'status'     => $data['status'] ?? '',
			'result'     => maybe_serialize( $result ),
			'undo_data'  => ! empty( $data['undo_data'] ) ? wp_json_encode( $data['undo_data'] ) : null,
			'error'      => $data['error'] ?? '',
			'created_at' => current_time( 'mysql' ),
		];

		$formats = [
			'user_id'    => '%d',
			'command'    => '%s',
			'action'     => '%s',
			'params'     => '%s',
			'preview'    => '%d',
			'confirm'    => '%d',
			'status'     => '%s',
			'result'     => '%s',
			'undo_data'  => '%s',
			'error'      => '%s',
			'created_at' => '%s',
		];

		if ( in_array( 'trace_id', $columns, true ) ) {
			$insert_data['trace_id'] = $telemetry['trace_id'];
			$formats['trace_id'] = '%s';
		}

		if ( in_array( 'telemetry', $columns, true ) ) {
			$insert_data['telemetry'] = wp_json_encode( $telemetry );
			$formats['telemetry'] = '%s';
		}

		$ordered_formats = [];

		foreach ( array_keys( $insert_data ) as $key ) {
			$ordered_formats[] = $formats[ $key ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB -- Stores a local action-history row in the plugin's custom table.
		$inserted = $wpdb->insert( $table, $insert_data, $ordered_formats );

		return $inserted ? absint( $wpdb->insert_id ) : 0;
	}

	private static function compact_history_command( $data ) {
		$data = is_array( $data ) ? $data : [];
		$action = sanitize_text_field( $data['action'] ?? '' );
		$command = sanitize_textarea_field( $data['command'] ?? '' );

		if ( '' === $action ) {
			return '';
		}

		if ( 0 === strpos( $command, 'direct:' ) || 0 === strpos( $command, 'action:' ) || 0 === strpos( $command, 'proactive:' ) ) {
			return $command;
		}

		return 'action:' . $action;
	}

	private static function compact_params( $params ) {
		$params = is_array( $params ) ? $params : [];

		$allowed = [
			'order_id',
			'order_ids',
			'status',
			'limit',
			'email',
			'customer_id',
			'template',
			'note',
			'instruction',
		];

		$compact = [];

		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$compact[ $key ] = self::compact_log_value( $params[ $key ] );
			}
		}

		return $compact;
	}

	private static function compact_result( $result ) {
		$result = is_array( $result ) ? $result : [];

		$compact = [
			'success'      => array_key_exists( 'success', $result ) ? (bool) $result['success'] : null,
			'summary'      => self::compact_log_text( $result['summary'] ?? '' ),
			'message'      => self::compact_log_text( $result['message'] ?? '' ),
			'result_type'  => sanitize_key( $result['result_type'] ?? '' ),
			'display_type' => sanitize_key( $result['display_type'] ?? '' ),
			'count'        => isset( $result['count'] ) ? absint( $result['count'] ) : 0,
			'read_only'    => array_key_exists( 'read_only', $result ) ? (bool) $result['read_only'] : null,
			'undoable'     => ! empty( $result['undoable'] ),
		];

		if ( ! empty( $result['order'] ) && is_array( $result['order'] ) ) {
			$compact['order'] = [
				'id'     => isset( $result['order']['id'] ) ? absint( $result['order']['id'] ) : 0,
				'number' => sanitize_text_field( $result['order']['number'] ?? '' ),
				'status' => sanitize_key( $result['order']['status'] ?? '' ),
			];
		}

		if ( ! empty( $result['orders'] ) && is_array( $result['orders'] ) ) {
			$compact['orders'] = array_slice(
				array_map(
					function( $order ) {
						$order = is_array( $order ) ? $order : [];

						return [
							'id'     => isset( $order['id'] ) ? absint( $order['id'] ) : 0,
							'number' => sanitize_text_field( $order['number'] ?? '' ),
							'status' => sanitize_key( $order['status'] ?? '' ),
						];
					},
					$result['orders']
				),
				0,
				20
			);
		}

		if ( ! empty( $result['errors'] ) && is_array( $result['errors'] ) ) {
			$compact['errors'] = array_slice(
				array_map( [ __CLASS__, 'compact_log_value' ], $result['errors'] ),
				0,
				5
			);
		}

		return array_filter(
			$compact,
			function( $value ) {
				return null !== $value && [] !== $value && '' !== $value;
			}
		);
	}

	private static function compact_log_value( $value ) {
		if ( is_array( $value ) ) {
			return array_slice(
				array_map( [ __CLASS__, 'compact_log_value' ], $value ),
				0,
				50
			);
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		return self::compact_log_text( $value );
	}

	private static function compact_log_text( $value, $max_length = 500 ) {
		$value = sanitize_textarea_field( (string) $value );

		if ( strlen( $value ) > $max_length ) {
			$value = substr( $value, 0, $max_length ) . '...';
		}

		return $value;
	}

	private static function get_table_columns( $table ) {
		static $cache = [];

		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB -- Reads the schema of the plugin's known custom table.
		$columns = $wpdb->get_col( "DESC {$table}", 0 );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB
		$cache[ $table ] = is_array( $columns ) ? $columns : [];

		return $cache[ $table ];
	}

	private static function normalize_telemetry( $data ) {
		$data    = is_array( $data ) ? $data : [];
		$runtime = isset( $data['runtime'] ) && is_array( $data['runtime'] ) ? $data['runtime'] : [];
		$result  = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : [];
		$ai_meta = is_array( $runtime['ai_meta'] ?? null )
			? $runtime['ai_meta']
			: ( is_array( $data['ai_meta'] ?? null )
				? $data['ai_meta']
				: ( is_array( $result['meta'] ?? null ) ? $result['meta'] : [] ) );
		$runtime_snapshot = isset( $runtime['runtime_snapshot'] ) && is_array( $runtime['runtime_snapshot'] )
			? $runtime['runtime_snapshot']
			: [];
		$risk = isset( $data['risk'] ) && is_array( $data['risk'] ) ? $data['risk'] : [];

		if ( empty( $risk ) && ! empty( $runtime['risk_snapshot'] ) && is_array( $runtime['risk_snapshot'] ) ) {
			$risk = $runtime['risk_snapshot'];
		}

		$duration_ms = isset( $data['duration_ms'] ) ? absint( $data['duration_ms'] ) : null;

		if ( null === $duration_ms && ! empty( $runtime['request_started_at'] ) ) {
			$duration_ms = max( 0, (int) round( ( microtime( true ) - (float) $runtime['request_started_at'] ) * 1000 ) );
		}

		return [
			'trace_id'              => sanitize_text_field( $data['trace_id'] ?? $runtime['trace_id'] ?? '' ),
			'ai_trace_id'           => sanitize_text_field( $ai_meta['trace_id'] ?? '' ),
			'status'                => sanitize_key( $data['status'] ?? '' ),
			'action'                => sanitize_text_field( $data['action'] ?? '' ),
			'parser_source'         => sanitize_key( $runtime['parser_source'] ?? $data['parser_source'] ?? '' ),
			'parser_rule'           => sanitize_text_field( $runtime['parser_rule'] ?? $data['parser_rule'] ?? '' ),
			'router_mode'           => sanitize_key( $runtime['router_mode'] ?? $data['router_mode'] ?? '' ),
			'intent_name'           => sanitize_key( $runtime['intent_name'] ?? $data['intent_name'] ?? '' ),
			'intent_family'         => sanitize_key( $runtime['intent_family'] ?? $data['intent_family'] ?? '' ),
			'language'              => sanitize_key( $runtime['language'] ?? $data['language'] ?? $result['language'] ?? '' ),
			'confidence'            => isset( $runtime['confidence'] ) ? (float) $runtime['confidence'] : ( isset( $data['confidence'] ) ? (float) $data['confidence'] : null ),
			'ai_feature'            => sanitize_key( $ai_meta['feature'] ?? '' ),
			'ai_provider'           => self::normalize_public_routing_provider( $ai_meta['provider'] ?? '' ),
			'ai_model'              => self::normalize_public_ai_model( $ai_meta['model'] ?? '' ),
			'prompt_version'        => sanitize_text_field( $ai_meta['prompt_version'] ?? '' ),
			'yoro_ai_api_request_id' => sanitize_text_field( $ai_meta['yoro_ai_api_request_id'] ?? '' ),
			'yoro_ai_api_plan'      => sanitize_key( $ai_meta['yoro_ai_api_plan'] ?? '' ),
			'yoro_ai_api_usage_period' => sanitize_text_field( $ai_meta['yoro_ai_api_usage_period'] ?? '' ),
			'yoro_ai_api_usage_remaining' => isset( $ai_meta['yoro_ai_api_usage_remaining'] ) ? absint( $ai_meta['yoro_ai_api_usage_remaining'] ) : null,
			'plan_type'             => sanitize_key( $runtime['plan_snapshot']['type'] ?? '' ),
			'preview'               => ! empty( $data['preview'] ) || ! empty( $runtime_snapshot['preview'] ),
			'confirm'               => ! empty( $data['confirm'] ) || ! empty( $runtime_snapshot['confirm'] ),
			'requires_confirmation' => ! empty( $runtime_snapshot['requires_confirmation'] ) || ! empty( $result['requires_confirmation'] ),
			'risk_level'            => sanitize_key( $risk['risk_level'] ?? '' ),
			'result_success'        => array_key_exists( 'success', $result ) ? (bool) $result['success'] : null,
			'result_type'           => sanitize_key( $result['result_type'] ?? '' ),
			'display_type'          => sanitize_key( $result['display_type'] ?? '' ),
			'count'                 => isset( $result['count'] ) ? absint( $result['count'] ) : 0,
			'duration_ms'           => $duration_ms,
		];
	}

	private static function normalize_public_routing_provider( $provider ) {
		return 'yoro_ai_api';
	}

	private static function normalize_public_ai_model( $model ) {
		return '' === trim( (string) $model ) ? '' : 'yoro-ai-api';
	}

	public static function get_last_executed( $user_id ) {
		global $wpdb;

		$table = YoRo_DB::logs_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB -- Reads undo data from the plugin's custom log table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE user_id = %d
				AND status = %s
				AND undone = 0
				AND undo_data IS NOT NULL
				ORDER BY id DESC
				LIMIT 1",
				$user_id,
				'executed'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB

		if ( ! $row ) {
			return null;
		}

		if ( ! empty( $row['undo_data'] ) ) {
			$decoded = json_decode( $row['undo_data'], true );

			if ( is_array( $decoded ) ) {
				$row['undo_data'] = $decoded;
			}
		}

		return $row;
	}

	public static function get_executed_by_id( $log_id, $user_id ) {
		global $wpdb;

		$table = YoRo_DB::logs_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB -- Reads undo data from the plugin's custom log table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE id = %d
				AND user_id = %d
				AND status = %s
				AND undone = 0
				AND undo_data IS NOT NULL
				LIMIT 1",
				absint( $log_id ),
				absint( $user_id ),
				'executed'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB

		if ( ! $row ) {
			return null;
		}

		if ( ! empty( $row['undo_data'] ) ) {
			$decoded = json_decode( $row['undo_data'], true );

			if ( is_array( $decoded ) ) {
				$row['undo_data'] = $decoded;
			}
		}

		return $row;
	}

	public static function mark_undone( $log_id ) {
		global $wpdb;

		$table = YoRo_DB::logs_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB -- Updates a single row in the plugin's custom log table.
		return $wpdb->update(
			$table,
			[
				'undone'    => 1,
				'undone_at' => current_time( 'mysql' ),
			],
			[
				'id' => absint( $log_id ),
			],
			[
				'%d',
				'%s',
			],
			[
				'%d',
			]
		);
	}
}
