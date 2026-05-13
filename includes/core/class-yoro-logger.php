<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Logger {

	public static function log( $data ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[YoRo Agent] ' . wp_json_encode( $data ) );
		}

		return self::log_to_db( $data );
	}

	private static function log_to_db( $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'yoro_agent_logs';
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
			'intent_name'           => sanitize_key( $runtime['intent_name'] ?? $data['intent_name'] ?? '' ),
			'confidence'            => isset( $runtime['confidence'] ) ? (float) $runtime['confidence'] : ( isset( $data['confidence'] ) ? (float) $data['confidence'] : null ),
			'ai_feature'            => sanitize_key( $ai_meta['feature'] ?? '' ),
			'ai_provider'           => sanitize_key( $ai_meta['provider'] ?? '' ),
			'ai_model'              => sanitize_text_field( $ai_meta['model'] ?? '' ),
			'prompt_version'        => sanitize_text_field( $ai_meta['prompt_version'] ?? '' ),
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
		];
	}

	private static function normalize_ai_meta( $meta ) {
		if ( ! is_array( $meta ) ) {
			return [];
		}

		return [
			'feature'        => sanitize_key( $meta['feature'] ?? '' ),
			'provider'       => sanitize_key( $meta['provider'] ?? '' ),
			'model'          => sanitize_text_field( $meta['model'] ?? '' ),
			'prompt_version' => sanitize_text_field( $meta['prompt_version'] ?? '' ),
			'trace_id'       => sanitize_text_field( $meta['trace_id'] ?? '' ),
		];
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

		$table = $wpdb->prefix . 'yoro_agent_logs';

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

		$table = $wpdb->prefix . 'yoro_agent_logs';

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

		$table   = $wpdb->prefix . 'yoro_agent_logs';
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
				'created_at'     => sanitize_text_field( $row['created_at'] ?? '' ),
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

		$table = $wpdb->prefix . 'yoro_agent_logs';

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

		$table   = $wpdb->prefix . 'yoro_agent_logs';
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
				'created_at'    => sanitize_text_field( $row['created_at'] ?? '' ),
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
