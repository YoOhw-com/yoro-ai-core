<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Logger {

	public static function log( $data ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[YoRo AI] ' . wp_json_encode( $data ) );
		}

		return self::log_to_db( $data );
	}

	private static function log_to_db( $data ) {
		global $wpdb;

		$table = YoRo_DB::logs_table_name();
		$columns = self::get_table_columns( $table );
		$telemetry = self::normalize_telemetry( $data );

		$insert_data = [
			'user_id'    => $data['user_id'] ?? get_current_user_id(),
			'command'    => $data['command'] ?? '',
			'action'     => $data['action'] ?? '',
			'params'     => maybe_serialize( $data['params'] ?? [] ),
			'preview'    => ! empty( $data['preview'] ) ? 1 : 0,
			'confirm'    => ! empty( $data['confirm'] ) ? 1 : 0,
			'status'     => $data['status'] ?? '',
			'result'     => maybe_serialize( $data['result'] ?? [] ),
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
		$risk    = isset( $data['risk'] ) && is_array( $data['risk'] ) ? $data['risk'] : [];
		$policy  = isset( $data['policy'] ) && is_array( $data['policy'] ) ? $data['policy'] : [];
		$result  = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : [];
		$ai_meta = self::normalize_ai_meta(
			$runtime['ai_meta'] ?? $data['ai_meta'] ?? $result['meta'] ?? []
		);

		if ( empty( $risk ) && ! empty( $runtime['risk_snapshot'] ) && is_array( $runtime['risk_snapshot'] ) ) {
			$risk = $runtime['risk_snapshot'];
		}

		if ( empty( $policy ) && ! empty( $runtime['policy_snapshot'] ) && is_array( $runtime['policy_snapshot'] ) ) {
			$policy = $runtime['policy_snapshot'];
		}

		$plan = isset( $runtime['plan_snapshot'] ) && is_array( $runtime['plan_snapshot'] )
			? $runtime['plan_snapshot']
			: [];
		$runtime_snapshot = isset( $runtime['runtime_snapshot'] ) && is_array( $runtime['runtime_snapshot'] )
			? $runtime['runtime_snapshot']
			: [];
		$duration_ms = isset( $data['duration_ms'] ) ? absint( $data['duration_ms'] ) : null;
		$retrieved_examples = self::normalize_retrieved_examples(
			$runtime['retrieved_examples'] ?? $data['retrieved_examples'] ?? []
		);
		$route_candidates = self::normalize_route_candidates(
			$runtime['route_candidates'] ?? $data['route_candidates'] ?? []
		);
		$stage_timings = isset( $runtime['stage_timings'] ) && is_array( $runtime['stage_timings'] )
			? $runtime['stage_timings']
			: [];
		$ai_requests = isset( $runtime['ai_requests'] ) && is_array( $runtime['ai_requests'] )
			? $runtime['ai_requests']
			: [];
		$ai_total_ms = isset( $runtime['ai_total_ms'] ) ? absint( $runtime['ai_total_ms'] ) : 0;

		if ( null === $duration_ms && ! empty( $runtime['request_started_at'] ) ) {
			$duration_ms = max( 0, (int) round( ( microtime( true ) - (float) $runtime['request_started_at'] ) * 1000 ) );
		}

		return [
			'trace_id'              => sanitize_text_field( $data['trace_id'] ?? $runtime['trace_id'] ?? '' ),
			'ai_trace_id'           => sanitize_text_field( $ai_meta['trace_id'] ?? '' ),
			'status'                => sanitize_key( $data['status'] ?? '' ),
			'action'                => sanitize_text_field( $data['action'] ?? '' ),
			'execution_source'      => sanitize_key( $runtime['execution_source'] ?? '' ),
			'parser_source'         => sanitize_key( $runtime['parser_source'] ?? $data['parser_source'] ?? '' ),
			'parser_rule'           => sanitize_text_field( $runtime['parser_rule'] ?? $data['parser_rule'] ?? '' ),
			'resolution_path'       => sanitize_key( $runtime['resolution_path'] ?? $data['resolution_path'] ?? $result['resolution_path'] ?? '' ),
			'router_mode'           => sanitize_key( $runtime['router_mode'] ?? $data['router_mode'] ?? $result['router_mode'] ?? '' ),
			'catalog_action_count'  => isset( $runtime['catalog_action_count'] )
				? absint( $runtime['catalog_action_count'] )
				: ( isset( $data['catalog_action_count'] )
					? absint( $data['catalog_action_count'] )
					: absint( $result['catalog_action_count'] ?? 0 ) ),
			'clarify_reason'        => sanitize_key( $runtime['clarify_reason'] ?? $data['clarify_reason'] ?? $result['clarify_reason'] ?? '' ),
			'fallback_reason'       => sanitize_key( $runtime['fallback_reason'] ?? $data['fallback_reason'] ?? $result['fallback_reason'] ?? '' ),
			'params_trimmed_count'  => isset( $runtime['params_trimmed_count'] )
				? absint( $runtime['params_trimmed_count'] )
				: ( isset( $data['params_trimmed_count'] )
					? absint( $data['params_trimmed_count'] )
					: absint( $result['params_trimmed_count'] ?? 0 ) ),
			'intent_name'           => sanitize_key( $runtime['intent_name'] ?? $data['intent_name'] ?? '' ),
			'intent_family'         => sanitize_key( $runtime['intent_family'] ?? $data['intent_family'] ?? '' ),
			'language'              => sanitize_key( $runtime['language'] ?? $data['language'] ?? $result['language'] ?? '' ),
			'confidence'            => isset( $runtime['confidence'] ) ? (float) $runtime['confidence'] : ( isset( $data['confidence'] ) ? (float) $data['confidence'] : null ),
			'ai_feature'            => sanitize_key( $ai_meta['feature'] ?? '' ),
			'ai_provider'           => self::normalize_public_routing_provider( $ai_meta['provider'] ?? '' ),
			'ai_model'              => self::normalize_public_ai_model( $ai_meta['model'] ?? '' ),
			'prompt_version'        => sanitize_text_field( $ai_meta['prompt_version'] ?? '' ),
			'routing_provider'      => self::normalize_public_routing_provider( $ai_meta['routing_provider'] ?? '' ),
			'yoro_ai_api_request_id' => sanitize_text_field( $ai_meta['yoro_ai_api_request_id'] ?? '' ),
			'yoro_ai_api_plan'      => sanitize_key( $ai_meta['yoro_ai_api_plan'] ?? '' ),
			'yoro_ai_api_usage_period' => sanitize_text_field( $ai_meta['yoro_ai_api_usage_period'] ?? '' ),
			'yoro_ai_api_usage_limit' => isset( $ai_meta['yoro_ai_api_usage_limit'] ) ? absint( $ai_meta['yoro_ai_api_usage_limit'] ) : null,
			'yoro_ai_api_usage_used' => isset( $ai_meta['yoro_ai_api_usage_used'] ) ? absint( $ai_meta['yoro_ai_api_usage_used'] ) : null,
			'yoro_ai_api_usage_remaining' => isset( $ai_meta['yoro_ai_api_usage_remaining'] ) ? absint( $ai_meta['yoro_ai_api_usage_remaining'] ) : null,
			'yoro_ai_api_provider_ms' => $ai_meta['yoro_ai_api_provider_ms'],
			'yoro_ai_api_response_parse_ms' => $ai_meta['yoro_ai_api_response_parse_ms'],
			'yoro_ai_api_route_total_ms' => $ai_meta['yoro_ai_api_route_total_ms'],
			'yoro_ai_api_plan_gate_ms' => $ai_meta['yoro_ai_api_plan_gate_ms'],
			'yoro_ai_api_router_ms' => $ai_meta['yoro_ai_api_router_ms'],
			'yoro_ai_api_laravel_total_ms' => $ai_meta['yoro_ai_api_laravel_total_ms'],
			'yoro_ai_api_input_tokens' => $ai_meta['yoro_ai_api_input_tokens'],
			'yoro_ai_api_output_tokens' => $ai_meta['yoro_ai_api_output_tokens'],
			'yoro_ai_api_total_tokens' => $ai_meta['yoro_ai_api_total_tokens'],
			'yoro_ai_api_cached_tokens' => $ai_meta['yoro_ai_api_cached_tokens'],
			'yoro_ai_api_reasoning_tokens' => $ai_meta['yoro_ai_api_reasoning_tokens'],
			'yoro_ai_api_cache_hit' => $ai_meta['yoro_ai_api_cache_hit'],
			'yoro_ai_api_reasoning_effort' => $ai_meta['yoro_ai_api_reasoning_effort'],
			'yoro_ai_api_max_output_tokens' => $ai_meta['yoro_ai_api_max_output_tokens'],
			'yoro_ai_api_prompt_cache_key' => $ai_meta['yoro_ai_api_prompt_cache_key'],
			'yoro_ai_api_action_catalog_count' => $ai_meta['yoro_ai_api_action_catalog_count'],
			'yoro_ai_api_compact_action_catalog_count' => $ai_meta['yoro_ai_api_compact_action_catalog_count'],
			'yoro_ai_api_router_profile' => $ai_meta['yoro_ai_api_router_profile'],
			'yoro_ai_api_catalog_scope' => $ai_meta['yoro_ai_api_catalog_scope'],
			'yoro_ai_api_request_bytes' => $ai_meta['yoro_ai_api_request_bytes'],
			'yoro_ai_api_profile_router_enabled' => $ai_meta['yoro_ai_api_profile_router_enabled'] ?? null,
			'yoro_ai_api_profile_router_applied' => $ai_meta['yoro_ai_api_profile_router_applied'] ?? null,
			'yoro_ai_api_profile_router_provider_ms' => $ai_meta['yoro_ai_api_profile_router_provider_ms'] ?? null,
			'yoro_ai_api_profile_router_response_parse_ms' => $ai_meta['yoro_ai_api_profile_router_response_parse_ms'] ?? null,
			'yoro_ai_api_profile_router_total_ms' => $ai_meta['yoro_ai_api_profile_router_total_ms'] ?? null,
			'yoro_ai_api_profile_router_profile' => $ai_meta['yoro_ai_api_profile_router_profile'] ?? '',
			'yoro_ai_api_profile_router_catalog_scope' => $ai_meta['yoro_ai_api_profile_router_catalog_scope'] ?? '',
			'yoro_ai_api_profile_router_confidence' => $ai_meta['yoro_ai_api_profile_router_confidence'] ?? null,
			'yoro_ai_api_profile_router_reason' => $ai_meta['yoro_ai_api_profile_router_reason'] ?? '',
			'yoro_ai_api_profile_router_input_tokens' => $ai_meta['yoro_ai_api_profile_router_input_tokens'] ?? null,
			'yoro_ai_api_profile_router_output_tokens' => $ai_meta['yoro_ai_api_profile_router_output_tokens'] ?? null,
			'yoro_ai_api_profile_router_cached_tokens' => $ai_meta['yoro_ai_api_profile_router_cached_tokens'] ?? null,
			'yoro_ai_api_profile_router_cache_hit' => $ai_meta['yoro_ai_api_profile_router_cache_hit'] ?? null,
			'yoro_ai_api_profile_router_original_action_count' => $ai_meta['yoro_ai_api_profile_router_original_action_count'] ?? null,
			'yoro_ai_api_profile_router_filtered_action_count' => $ai_meta['yoro_ai_api_profile_router_filtered_action_count'] ?? null,
			'yoro_ai_api_profile_router_request_bytes' => $ai_meta['yoro_ai_api_profile_router_request_bytes'] ?? null,
			'yoro_ai_api_profile_router_error' => $ai_meta['yoro_ai_api_profile_router_error'] ?? '',
			'retrieved_examples'    => $retrieved_examples,
			'route_candidates'      => $route_candidates,
			'plan_type'             => sanitize_key( $plan['type'] ?? '' ),
			'preview'               => ! empty( $data['preview'] ) || ! empty( $runtime_snapshot['preview'] ),
			'confirm'               => ! empty( $data['confirm'] ) || ! empty( $runtime_snapshot['confirm'] ),
			'requires_confirmation' => ! empty( $runtime_snapshot['requires_confirmation'] ) || ! empty( $result['requires_confirmation'] ),
			'risk_level'            => sanitize_key( $risk['risk_level'] ?? '' ),
			'risk_reasons'          => isset( $risk['reasons'] ) && is_array( $risk['reasons'] ) ? array_values( $risk['reasons'] ) : [],
			'policy_reasons'        => isset( $policy['reasons'] ) && is_array( $policy['reasons'] ) ? array_values( $policy['reasons'] ) : [],
			'result_success'        => array_key_exists( 'success', $result ) ? (bool) $result['success'] : null,
			'result_type'           => sanitize_key( $result['result_type'] ?? '' ),
			'display_type'          => sanitize_key( $result['display_type'] ?? '' ),
			'read_only'             => array_key_exists( 'read_only', $result ) ? (bool) $result['read_only'] : null,
			'undoable'              => ! empty( $result['undoable'] ) || ! empty( $data['undo_data'] ),
			'count'                 => isset( $result['count'] ) ? absint( $result['count'] ) : 0,
			'error_code'            => sanitize_key( $result['error']['code'] ?? $data['error_code'] ?? '' ),
			'duration_ms'           => $duration_ms,
			'stage_timings'         => $stage_timings,
			'ai_requests'           => $ai_requests,
			'ai_total_ms'           => $ai_total_ms,
		];
	}

	private static function normalize_ai_meta( $meta ) {
		if ( ! is_array( $meta ) ) {
			return [];
		}

		$normalized = [
			'feature'        => sanitize_key( $meta['feature'] ?? '' ),
			'provider'       => self::normalize_public_routing_provider( $meta['provider'] ?? '' ),
			'model'          => self::normalize_public_ai_model( $meta['model'] ?? '' ),
			'prompt_version' => sanitize_text_field( $meta['prompt_version'] ?? '' ),
			'trace_id'       => sanitize_text_field( $meta['trace_id'] ?? '' ),
			'routing_provider' => self::normalize_public_routing_provider( $meta['routing_provider'] ?? '' ),
			'yoro_ai_api_parser_source' => sanitize_key( $meta['yoro_ai_api_parser_source'] ?? '' ),
			'yoro_ai_api_request_id' => sanitize_text_field( $meta['yoro_ai_api_request_id'] ?? '' ),
			'yoro_ai_api_plan' => sanitize_key( $meta['yoro_ai_api_plan'] ?? '' ),
			'yoro_ai_api_usage_period' => sanitize_text_field( $meta['yoro_ai_api_usage_period'] ?? '' ),
			'yoro_ai_api_usage_limit' => isset( $meta['yoro_ai_api_usage_limit'] ) ? absint( $meta['yoro_ai_api_usage_limit'] ) : null,
			'yoro_ai_api_usage_used' => isset( $meta['yoro_ai_api_usage_used'] ) ? absint( $meta['yoro_ai_api_usage_used'] ) : null,
			'yoro_ai_api_usage_remaining' => isset( $meta['yoro_ai_api_usage_remaining'] ) ? absint( $meta['yoro_ai_api_usage_remaining'] ) : null,
			'yoro_ai_api_provider_ms' => isset( $meta['yoro_ai_api_provider_ms'] ) ? absint( $meta['yoro_ai_api_provider_ms'] ) : ( isset( $meta['yoro_ai_api_openai_ms'] ) ? absint( $meta['yoro_ai_api_openai_ms'] ) : null ),
			'yoro_ai_api_response_parse_ms' => isset( $meta['yoro_ai_api_response_parse_ms'] ) ? absint( $meta['yoro_ai_api_response_parse_ms'] ) : null,
			'yoro_ai_api_route_total_ms' => isset( $meta['yoro_ai_api_route_total_ms'] ) ? absint( $meta['yoro_ai_api_route_total_ms'] ) : null,
			'yoro_ai_api_plan_gate_ms' => isset( $meta['yoro_ai_api_plan_gate_ms'] ) ? absint( $meta['yoro_ai_api_plan_gate_ms'] ) : null,
			'yoro_ai_api_router_ms' => isset( $meta['yoro_ai_api_router_ms'] ) ? absint( $meta['yoro_ai_api_router_ms'] ) : null,
			'yoro_ai_api_laravel_total_ms' => isset( $meta['yoro_ai_api_laravel_total_ms'] ) ? absint( $meta['yoro_ai_api_laravel_total_ms'] ) : null,
			'yoro_ai_api_input_tokens' => isset( $meta['yoro_ai_api_input_tokens'] ) ? absint( $meta['yoro_ai_api_input_tokens'] ) : null,
			'yoro_ai_api_output_tokens' => isset( $meta['yoro_ai_api_output_tokens'] ) ? absint( $meta['yoro_ai_api_output_tokens'] ) : null,
			'yoro_ai_api_total_tokens' => isset( $meta['yoro_ai_api_total_tokens'] ) ? absint( $meta['yoro_ai_api_total_tokens'] ) : null,
			'yoro_ai_api_cached_tokens' => isset( $meta['yoro_ai_api_cached_tokens'] ) ? absint( $meta['yoro_ai_api_cached_tokens'] ) : null,
			'yoro_ai_api_reasoning_tokens' => isset( $meta['yoro_ai_api_reasoning_tokens'] ) ? absint( $meta['yoro_ai_api_reasoning_tokens'] ) : null,
			'yoro_ai_api_cache_hit' => ! empty( $meta['yoro_ai_api_cache_hit'] ),
			'yoro_ai_api_reasoning_effort' => sanitize_key( $meta['yoro_ai_api_reasoning_effort'] ?? '' ),
			'yoro_ai_api_max_output_tokens' => isset( $meta['yoro_ai_api_max_output_tokens'] ) ? absint( $meta['yoro_ai_api_max_output_tokens'] ) : null,
			'yoro_ai_api_prompt_cache_key' => sanitize_text_field( $meta['yoro_ai_api_prompt_cache_key'] ?? '' ),
			'yoro_ai_api_action_catalog_count' => isset( $meta['yoro_ai_api_action_catalog_count'] ) ? absint( $meta['yoro_ai_api_action_catalog_count'] ) : null,
			'yoro_ai_api_compact_action_catalog_count' => isset( $meta['yoro_ai_api_compact_action_catalog_count'] ) ? absint( $meta['yoro_ai_api_compact_action_catalog_count'] ) : null,
			'yoro_ai_api_router_profile' => sanitize_key( $meta['yoro_ai_api_router_profile'] ?? '' ),
			'yoro_ai_api_catalog_scope' => sanitize_key( $meta['yoro_ai_api_catalog_scope'] ?? '' ),
			'yoro_ai_api_request_bytes' => isset( $meta['yoro_ai_api_request_bytes'] ) ? absint( $meta['yoro_ai_api_request_bytes'] ) : null,
		];

		foreach ( $meta as $key => $value ) {
			$key = self::normalize_public_meta_key( $key );

			if ( '' === $key || 0 !== strpos( $key, 'yoro_ai_api_' ) || array_key_exists( $key, $normalized ) ) {
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$normalized[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$normalized[ $key ] = self::normalize_public_meta_value( $key, $value );
			}
		}

		return $normalized;
	}

	private static function normalize_public_meta_key( $key ) {
		$key = sanitize_key( (string) $key );

		switch ( $key ) {
			case 'yoro_ai_api_openai_ms':
				return 'yoro_ai_api_provider_ms';
			case 'yoro_ai_api_profile_router_openai_ms':
				return 'yoro_ai_api_profile_router_provider_ms';
			default:
				return $key;
		}
	}

	private static function normalize_public_meta_value( $key, $value ) {
		if ( in_array( $key, [ 'provider', 'routing_provider' ], true ) ) {
			return self::normalize_public_routing_provider( $value );
		}

		if ( in_array( $key, [ 'model', 'yoro_ai_api_model' ], true ) ) {
			return self::normalize_public_ai_model( $value );
		}

		return sanitize_text_field( $value );
	}

	private static function normalize_public_routing_provider( $provider ) {
		$provider = sanitize_key( (string) $provider );

		return 'mock' === $provider ? 'mock' : 'yoro_ai_api';
	}

	private static function normalize_public_ai_model( $model ) {
		return '' === trim( (string) $model ) ? '' : 'yoro-ai-api';
	}

	private static function normalize_retrieved_examples( $examples ) {
		if ( ! is_array( $examples ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $examples as $example ) {
			if ( ! is_array( $example ) ) {
				continue;
			}

			$command = sanitize_text_field( $example['command'] ?? '' );

			if ( '' === $command ) {
				continue;
			}

			$normalized[] = [
				'command' => $command,
				'intent'  => sanitize_key( $example['intent'] ?? '' ),
				'action'  => sanitize_text_field( $example['action'] ?? '' ),
				'source'  => sanitize_key( $example['source'] ?? '' ),
				'score'   => isset( $example['score'] ) ? round( (float) $example['score'], 3 ) : null,
			];

			if ( count( $normalized ) >= 5 ) {
				break;
			}
		}

		return $normalized;
	}

	private static function normalize_route_candidates( $candidates ) {
		$candidates = is_array( $candidates ) ? $candidates : [];
		$intents    = isset( $candidates['intents'] ) && is_array( $candidates['intents'] ) ? $candidates['intents'] : [];
		$actions    = isset( $candidates['actions'] ) && is_array( $candidates['actions'] ) ? $candidates['actions'] : [];
		$normalize_sources = static function ( $sources ) {
			if ( ! is_array( $sources ) ) {
				return [];
			}

			return array_values(
				array_filter(
					array_unique(
						array_map( 'sanitize_key', $sources )
					)
				)
			);
		};

		$normalized_intents = [];

		foreach ( $intents as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}

			$normalized_intents[] = [
				'name'            => sanitize_key( $item['name'] ?? '' ),
				'aggregate_score' => isset( $item['aggregate_score'] ) ? round( (float) $item['aggregate_score'], 3 ) : null,
				'top_match_score' => isset( $item['top_match_score'] ) ? round( (float) $item['top_match_score'], 3 ) : null,
				'match_count'     => isset( $item['match_count'] ) ? absint( $item['match_count'] ) : 0,
				'sources'         => $normalize_sources( $item['sources'] ?? [] ),
			];

			if ( count( $normalized_intents ) >= 5 ) {
				break;
			}
		}

		$normalized_actions = [];

		foreach ( $actions as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}

			$normalized_actions[] = [
				'name'            => sanitize_text_field( $item['name'] ?? '' ),
				'intent'          => sanitize_key( $item['intent'] ?? '' ),
				'aggregate_score' => isset( $item['aggregate_score'] ) ? round( (float) $item['aggregate_score'], 3 ) : null,
				'top_match_score' => isset( $item['top_match_score'] ) ? round( (float) $item['top_match_score'], 3 ) : null,
				'match_count'     => isset( $item['match_count'] ) ? absint( $item['match_count'] ) : 0,
				'sources'         => $normalize_sources( $item['sources'] ?? [] ),
			];

			if ( count( $normalized_actions ) >= 5 ) {
				break;
			}
		}

		return [
			'intents' => $normalized_intents,
			'actions' => $normalized_actions,
		];
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

	public static function get_logs_by_ai_trace_id( $ai_trace_id, $limit = 50 ) {
		global $wpdb;

		$ai_trace_id = sanitize_text_field( (string) $ai_trace_id );
		$limit       = min( max( absint( $limit ), 1 ), 100 );

		if ( '' === $ai_trace_id ) {
			return [];
		}

		$table   = YoRo_DB::logs_table_name();
		$columns = self::get_table_columns( $table );

		if ( ! is_array( $columns ) || ! in_array( 'telemetry', $columns, true ) ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, command, action, status, trace_id, telemetry, result
				FROM {$table}
				WHERE telemetry LIKE %s
				ORDER BY id DESC
				LIMIT %d",
				'%"ai_trace_id":"' . $wpdb->esc_like( $ai_trace_id ) . '"%',
				$limit
			),
			ARRAY_A
		);

		$items = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$telemetry = [];
			$result    = [];

			if ( ! empty( $row['telemetry'] ) ) {
				$decoded = json_decode( $row['telemetry'], true );

				if ( is_array( $decoded ) ) {
					$telemetry = $decoded;
				}
			}

			if ( ! empty( $row['result'] ) ) {
				$maybe_result = maybe_unserialize( $row['result'] );

				if ( is_array( $maybe_result ) ) {
					$result = $maybe_result;
				}
			}

			$items[] = [
				'id'             => absint( $row['id'] ?? 0 ),
				'created_at'     => YoRo_Date_Helper::format_mysql( $row['created_at'] ?? '' ),
				'command'        => sanitize_text_field( $row['command'] ?? '' ),
				'action'         => sanitize_text_field( $row['action'] ?? '' ),
				'status'         => sanitize_key( $row['status'] ?? '' ),
				'trace_id'       => sanitize_text_field( $row['trace_id'] ?? '' ),
				'ai_trace_id'    => sanitize_text_field( $telemetry['ai_trace_id'] ?? '' ),
				'ai_feature'     => sanitize_key( $telemetry['ai_feature'] ?? '' ),
				'ai_model'       => sanitize_text_field( $telemetry['ai_model'] ?? '' ),
				'prompt_version' => sanitize_text_field( $telemetry['prompt_version'] ?? '' ),
				'parser_source'  => sanitize_key( $telemetry['parser_source'] ?? '' ),
				'confidence'     => isset( $telemetry['confidence'] ) ? (float) $telemetry['confidence'] : null,
				'result_type'    => sanitize_key( $telemetry['result_type'] ?? '' ),
				'display_type'   => sanitize_key( $telemetry['display_type'] ?? '' ),
				'telemetry'      => $telemetry,
				'result'         => $result,
			];
		}

		return $items;
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

	public static function build_real_query_evaluation_pack( $limit = 100 ) {
		global $wpdb;

		$table   = YoRo_DB::logs_table_name();
		$limit   = min( max( absint( $limit ), 20 ), 300 );
		$columns = self::get_table_columns( $table );

		if ( ! is_array( $columns ) || ! in_array( 'telemetry', $columns, true ) ) {
			return [
				'available' => false,
				'reason'    => 'telemetry_unavailable',
			];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, command, action, status, result, telemetry
				FROM {$table}
				WHERE command <> ''
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$pack = [
			'available'        => true,
			'sample_size'      => 0,
			'deduped_size'     => 0,
			'by_intent'        => [],
			'by_source'        => [],
			'by_status'        => [],
			'queries'          => [],
			'suggested_cases'  => [],
		];

		$seen = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$command = trim( (string) ( $row['command'] ?? '' ) );

			if ( '' === $command || 0 === strpos( $command, 'direct:' ) ) {
				continue;
			}

			$pack['sample_size']++;

			$telemetry = [];
			if ( ! empty( $row['telemetry'] ) ) {
				$decoded = json_decode( $row['telemetry'], true );
				if ( is_array( $decoded ) ) {
					$telemetry = $decoded;
				}
			}

			$intent_name   = sanitize_key( $telemetry['intent_name'] ?? '' );
			$parser_source = sanitize_key( $telemetry['parser_source'] ?? '' );
			$status        = self::normalize_pack_status( $row['status'] ?? '', $telemetry );
			$action        = sanitize_text_field( $row['action'] ?? '' );
			$confidence    = isset( $telemetry['confidence'] ) ? (float) $telemetry['confidence'] : null;
			$result_data   = [];
			$key_command   = self::normalize_command_template( $command );

			if ( ! empty( $row['result'] ) ) {
				$maybe_result = maybe_unserialize( $row['result'] );
				if ( is_array( $maybe_result ) ) {
					$result_data = $maybe_result;
				}
			}

			if ( ! $action ) {
				$action = sanitize_text_field( $telemetry['action'] ?? '' );
			}

			if ( ! $action && ! empty( $result_data['action'] ) ) {
				$action = sanitize_text_field( $result_data['action'] );
			}

			if ( ! $action ) {
				$action = self::infer_action_from_command( $command );
			}

			if ( ! $intent_name ) {
				$intent_name = sanitize_key( self::infer_intent_name_for_pack( $action ) );
			}

			if ( ! $parser_source ) {
				$parser_source = self::infer_parser_source_for_pack( $action, $intent_name, $status );
			}

			if ( isset( $seen[ $key_command ] ) ) {
				continue;
			}

			$seen[ $key_command ] = true;
			$pack['deduped_size']++;

			if ( $intent_name ) {
				if ( ! isset( $pack['by_intent'][ $intent_name ] ) ) {
					$pack['by_intent'][ $intent_name ] = 0;
				}
				$pack['by_intent'][ $intent_name ]++;
			}

			if ( $parser_source ) {
				if ( ! isset( $pack['by_source'][ $parser_source ] ) ) {
					$pack['by_source'][ $parser_source ] = 0;
				}
				$pack['by_source'][ $parser_source ]++;
			}

			if ( $status ) {
				if ( ! isset( $pack['by_status'][ $status ] ) ) {
					$pack['by_status'][ $status ] = 0;
				}
				$pack['by_status'][ $status ]++;
			}

			$entry = [
				'command'       => $command,
				'command_template' => $key_command,
				'intent'        => $intent_name,
				'action'        => $action,
				'parser_source' => $parser_source,
				'status'        => $status,
				'confidence'    => $confidence,
				'created_at'    => YoRo_Date_Helper::format_mysql( $row['created_at'] ?? '' ),
			];

			$pack['queries'][] = $entry;
			$pack['suggested_cases'][] = [
				'domain'          => self::infer_domain_from_intent_or_action( $intent_name, $action ),
				'name'            => self::build_case_name_from_command( $key_command ),
				'command'         => $key_command,
				'expected_intent' => $intent_name,
				'expected_action' => $action,
			];
		}

		arsort( $pack['by_intent'] );
		arsort( $pack['by_source'] );
		arsort( $pack['by_status'] );

		$pack['by_intent'] = array_slice( $pack['by_intent'], 0, 12, true );
		$pack['by_source'] = array_slice( $pack['by_source'], 0, 8, true );
		$pack['by_status'] = array_slice( $pack['by_status'], 0, 8, true );
		$pack['queries']   = array_slice( $pack['queries'], 0, 40 );
		$pack['suggested_cases'] = array_slice( $pack['suggested_cases'], 0, 40 );

		return $pack;
	}

	private static function infer_domain_from_intent_or_action( $intent_name, $action ) {
		$intent_name = sanitize_key( $intent_name );
		$action      = sanitize_text_field( $action );

		$map = [
			'business_summary'        => 'business',
			'operations_overview'     => 'operations',
			'operations_attention'    => 'attention',
			'fulfillment_review'      => 'fulfillment',
			'inventory_review'        => 'inventory',
			'customer_service_review' => 'customer_service',
			'search_orders'           => 'orders',
			'get_order_details'       => 'orders',
			'find_customer_orders'    => 'orders',
			'update_order_status'     => 'write_actions',
			'hold_order'              => 'write_actions',
			'add_note'                => 'write_actions',
			'email_customer'          => 'write_actions',
		];

		if ( isset( $map[ $intent_name ] ) ) {
			return $map[ $intent_name ];
		}

		if ( 0 === strpos( $action, 'orders.' ) ) {
			return 'orders';
		}

		if ( 0 === strpos( $action, 'customers.' ) ) {
			return 'customers';
		}

		return 'general';
	}

	private static function build_case_name_from_command( $command ) {
		$command = trim( preg_replace( '/\s+/', ' ', (string) $command ) );
		$words   = preg_split( '/\s+/', $command );
		$words   = array_slice( is_array( $words ) ? $words : [], 0, 6 );

		return implode( ' ', $words );
	}

	private static function normalize_pack_status( $status, $telemetry ) {
		$status = sanitize_key( $status );

		if ( 'success' === $status && ! empty( $telemetry['result_success'] ) ) {
			return 'executed';
		}

		if ( 'success' === $status ) {
			return 'executed';
		}

		return $status;
	}

	private static function normalize_command_template( $command ) {
		$command = trim( preg_replace( '/\s+/', ' ', (string) $command ) );
		$command = preg_replace( '/#\d+\b/', '#{id}', $command );
		$command = preg_replace( '/\border\s+\d+\b/i', 'order #{id}', $command );

		return $command;
	}

	private static function infer_action_from_command( $command ) {
		$normalized = strtolower( trim( wp_strip_all_tags( (string) $command ) ) );

		if ( preg_match( '/\b(mark|move|set)\b.*\b(completed|processing|on hold|on-hold|pending)\b/', $normalized ) ) {
			return 'orders.update_status';
		}

		if ( preg_match( '/\bhold\b.*\border\b/', $normalized ) ) {
			return 'orders.hold';
		}

		if ( preg_match( '/\badd note\b|\bnote\b/', $normalized ) ) {
			return 'orders.add_note';
		}

		if ( false !== strpos( $normalized, 'business' ) ) {
			return 'store.get_business_summary';
		}

		if ( false !== strpos( $normalized, 'inventory queue' ) ) {
			return 'products.get_inventory_queue';
		}

		return '';
	}

	private static function infer_intent_name_for_pack( $action ) {
		if ( ! $action || ! class_exists( 'YoRo_Intent_Router' ) ) {
			return '';
		}

		return YoRo_Intent_Router::map_action_to_intent_name( $action );
	}

	private static function infer_parser_source_for_pack( $action, $intent_name, $status ) {
		if ( 'executed' !== $status ) {
			return '';
		}

		if ( $intent_name || $action ) {
			return 'inferred';
		}

		return '';
	}
}
