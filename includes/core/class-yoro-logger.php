<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Logger {

	public static function log( $data ) {
		$data = is_array( $data ) ? $data : [];

		if ( self::should_write_local_debug_log() ) {
			error_log( '[YoRo AI] ' . wp_json_encode( self::compact_debug_log( $data ) ) );
		}

		return self::log_to_db( $data );
	}

	private static function should_write_local_debug_log() {
		$enabled = defined( 'YORO_AI_LOCAL_DEBUG_LOG' ) && YORO_AI_LOCAL_DEBUG_LOG;

		if ( function_exists( 'apply_filters' ) ) {
			$enabled = (bool) apply_filters( 'yoro_ai_local_debug_log', $enabled );
		}

		return $enabled;
	}

	private static function compact_debug_log( $data ) {
		$runtime = isset( $data['runtime'] ) && is_array( $data['runtime'] ) ? $data['runtime'] : [];
		$result  = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : [];
		$timings = isset( $runtime['stage_timings'] ) && is_array( $runtime['stage_timings'] ) ? $runtime['stage_timings'] : [];

		$summary = [
			'trace_id'     => sanitize_text_field( $data['trace_id'] ?? $runtime['trace_id'] ?? '' ),
			'status'       => sanitize_key( $data['status'] ?? $runtime['status'] ?? '' ),
			'action'       => sanitize_text_field( $data['action'] ?? $runtime['action'] ?? '' ),
			'preview'      => ! empty( $data['preview'] ) || ! empty( $runtime['runtime_snapshot']['preview'] ),
			'confirm'      => ! empty( $data['confirm'] ) || ! empty( $runtime['runtime_snapshot']['confirm'] ),
			'result_type'  => sanitize_key( $result['result_type'] ?? '' ),
			'display_type' => sanitize_key( $result['display_type'] ?? '' ),
			'count'        => isset( $result['count'] ) ? absint( $result['count'] ) : null,
			'total_ms'     => isset( $timings['total_ms'] ) ? absint( $timings['total_ms'] ) : null,
		];

		return array_filter(
			$summary,
			function( $value ) {
				return null !== $value && [] !== $value && '' !== $value;
			}
		);
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

		$columns = $wpdb->get_col( "DESC {$table}", 0 );
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
