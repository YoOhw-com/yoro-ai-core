<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Runtime_Log {

	public static function build_context( $trace_id, $plan, $runtime, $extra = [] ) {
		$runtime = is_array( $runtime ) ? $runtime : [];
		$extra   = is_array( $extra ) ? $extra : [];
		$retrieved_examples = self::normalize_retrieved_examples( $extra['retrieved_examples'] ?? [] );
		$route_candidates = self::normalize_route_candidates( $extra['route_candidates'] ?? [] );
		$ai_meta = self::normalize_public_ai_meta( $extra['ai_meta'] ?? [] );
		unset( $extra['retrieved_examples'] );
		unset( $extra['route_candidates'] );
		unset( $extra['ai_meta'] );

		$data = [
			'trace_id'           => sanitize_text_field( $trace_id ),
			'execution_source'   => sanitize_key( $extra['execution_source'] ?? '' ),
			'parser_source'      => sanitize_key( $extra['parser_source'] ?? '' ),
			'parser_rule'        => sanitize_text_field( $extra['parser_rule'] ?? '' ),
			'resolution_path'    => sanitize_key( $extra['resolution_path'] ?? '' ),
			'router_mode'        => sanitize_key( $extra['router_mode'] ?? '' ),
			'catalog_action_count' => isset( $extra['catalog_action_count'] ) ? absint( $extra['catalog_action_count'] ) : 0,
			'clarify_reason'     => sanitize_key( $extra['clarify_reason'] ?? '' ),
			'fallback_reason'    => sanitize_key( $extra['fallback_reason'] ?? '' ),
			'params_trimmed_count' => isset( $extra['params_trimmed_count'] ) ? absint( $extra['params_trimmed_count'] ) : 0,
			'intent_name'        => sanitize_key( $extra['intent_name'] ?? '' ),
			'intent_family'      => sanitize_key( $extra['intent_family'] ?? '' ),
			'language'           => sanitize_key( $extra['language'] ?? '' ),
			'confidence'         => isset( $extra['confidence'] ) && null !== $extra['confidence'] ? (float) $extra['confidence'] : null,
			'ai_meta'            => $ai_meta,
			'routing_provider'   => self::normalize_public_routing_provider( $ai_meta['routing_provider'] ?? '' ),
			'yoro_ai_api_request_id' => sanitize_text_field( $ai_meta['yoro_ai_api_request_id'] ?? '' ),
			'yoro_ai_api_plan'   => sanitize_key( $ai_meta['yoro_ai_api_plan'] ?? '' ),
			'yoro_ai_api_usage_period' => sanitize_text_field( $ai_meta['yoro_ai_api_usage_period'] ?? '' ),
			'yoro_ai_api_usage_limit' => isset( $ai_meta['yoro_ai_api_usage_limit'] ) ? absint( $ai_meta['yoro_ai_api_usage_limit'] ) : null,
			'yoro_ai_api_usage_used' => isset( $ai_meta['yoro_ai_api_usage_used'] ) ? absint( $ai_meta['yoro_ai_api_usage_used'] ) : null,
			'yoro_ai_api_usage_remaining' => isset( $ai_meta['yoro_ai_api_usage_remaining'] ) ? absint( $ai_meta['yoro_ai_api_usage_remaining'] ) : null,
			'yoro_ai_api_provider_ms' => isset( $ai_meta['yoro_ai_api_provider_ms'] ) ? absint( $ai_meta['yoro_ai_api_provider_ms'] ) : null,
			'yoro_ai_api_response_parse_ms' => isset( $ai_meta['yoro_ai_api_response_parse_ms'] ) ? absint( $ai_meta['yoro_ai_api_response_parse_ms'] ) : null,
			'yoro_ai_api_route_total_ms' => isset( $ai_meta['yoro_ai_api_route_total_ms'] ) ? absint( $ai_meta['yoro_ai_api_route_total_ms'] ) : null,
			'yoro_ai_api_plan_gate_ms' => isset( $ai_meta['yoro_ai_api_plan_gate_ms'] ) ? absint( $ai_meta['yoro_ai_api_plan_gate_ms'] ) : null,
			'yoro_ai_api_router_ms' => isset( $ai_meta['yoro_ai_api_router_ms'] ) ? absint( $ai_meta['yoro_ai_api_router_ms'] ) : null,
			'yoro_ai_api_laravel_total_ms' => isset( $ai_meta['yoro_ai_api_laravel_total_ms'] ) ? absint( $ai_meta['yoro_ai_api_laravel_total_ms'] ) : null,
			'yoro_ai_api_input_tokens' => isset( $ai_meta['yoro_ai_api_input_tokens'] ) ? absint( $ai_meta['yoro_ai_api_input_tokens'] ) : null,
			'yoro_ai_api_output_tokens' => isset( $ai_meta['yoro_ai_api_output_tokens'] ) ? absint( $ai_meta['yoro_ai_api_output_tokens'] ) : null,
			'yoro_ai_api_total_tokens' => isset( $ai_meta['yoro_ai_api_total_tokens'] ) ? absint( $ai_meta['yoro_ai_api_total_tokens'] ) : null,
			'yoro_ai_api_cached_tokens' => isset( $ai_meta['yoro_ai_api_cached_tokens'] ) ? absint( $ai_meta['yoro_ai_api_cached_tokens'] ) : null,
			'yoro_ai_api_reasoning_tokens' => isset( $ai_meta['yoro_ai_api_reasoning_tokens'] ) ? absint( $ai_meta['yoro_ai_api_reasoning_tokens'] ) : null,
			'yoro_ai_api_cache_hit' => ! empty( $ai_meta['yoro_ai_api_cache_hit'] ),
			'yoro_ai_api_reasoning_effort' => sanitize_key( $ai_meta['yoro_ai_api_reasoning_effort'] ?? '' ),
			'yoro_ai_api_max_output_tokens' => isset( $ai_meta['yoro_ai_api_max_output_tokens'] ) ? absint( $ai_meta['yoro_ai_api_max_output_tokens'] ) : null,
			'yoro_ai_api_prompt_cache_key' => sanitize_text_field( $ai_meta['yoro_ai_api_prompt_cache_key'] ?? '' ),
			'yoro_ai_api_action_catalog_count' => isset( $ai_meta['yoro_ai_api_action_catalog_count'] ) ? absint( $ai_meta['yoro_ai_api_action_catalog_count'] ) : null,
			'yoro_ai_api_compact_action_catalog_count' => isset( $ai_meta['yoro_ai_api_compact_action_catalog_count'] ) ? absint( $ai_meta['yoro_ai_api_compact_action_catalog_count'] ) : null,
			'yoro_ai_api_router_profile' => sanitize_key( $ai_meta['yoro_ai_api_router_profile'] ?? '' ),
			'yoro_ai_api_catalog_scope' => sanitize_key( $ai_meta['yoro_ai_api_catalog_scope'] ?? '' ),
			'yoro_ai_api_request_bytes' => isset( $ai_meta['yoro_ai_api_request_bytes'] ) ? absint( $ai_meta['yoro_ai_api_request_bytes'] ) : null,
			'retrieved_examples' => $retrieved_examples,
			'route_candidates'   => $route_candidates,
			'request_started_at' => isset( $extra['request_started_at'] ) ? (float) $extra['request_started_at'] : 0,
			'plan_snapshot'      => is_array( $plan ) ? $plan : [],
			'risk_snapshot'      => isset( $runtime['risk'] ) && is_array( $runtime['risk'] ) ? $runtime['risk'] : [],
			'policy_snapshot'    => isset( $runtime['policy'] ) && is_array( $runtime['policy'] ) ? $runtime['policy'] : [],
			'runtime_snapshot'   => [
				'preview'                      => ! empty( $runtime['preview'] ),
				'confirm'                      => ! empty( $runtime['confirm'] ),
				'requires_confirmation'        => ! empty( $runtime['requires_confirmation'] ),
				'policy_requires_confirmation' => ! empty( $runtime['policy_requires_confirmation'] ),
				'risk_requires_confirmation'   => ! empty( $runtime['risk_requires_confirmation'] ),
				'reason'                       => sanitize_key( $runtime['reason'] ?? '' ),
			],
			'stage_timings'      => isset( $extra['stage_timings'] ) && is_array( $extra['stage_timings'] ) ? $extra['stage_timings'] : [],
			'ai_requests'        => isset( $extra['ai_requests'] ) && is_array( $extra['ai_requests'] ) ? $extra['ai_requests'] : [],
			'ai_total_ms'        => isset( $extra['ai_total_ms'] ) ? absint( $extra['ai_total_ms'] ) : 0,
		];

		foreach ( $ai_meta as $key => $value ) {
			$key = self::normalize_public_meta_key( $key );

			if ( '' === $key || 0 !== strpos( $key, 'yoro_ai_api_' ) || array_key_exists( $key, $data ) ) {
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$data[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$data[ $key ] = self::normalize_public_meta_value( $key, $value );
			}
		}

		return array_merge( $data, $extra );
	}

	private static function normalize_public_ai_meta( $meta ) {
		if ( ! is_array( $meta ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $meta as $key => $value ) {
			$key = self::normalize_public_meta_key( $key );

			if ( '' === $key ) {
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
			return 'yoro-ai-api';
		}

		return sanitize_text_field( $value );
	}

	private static function normalize_public_routing_provider( $provider ) {
		return 'yoro_ai_api';
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
				'score'   => isset( $example['score'] ) ? (float) $example['score'] : null,
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
				'aggregate_score' => isset( $item['aggregate_score'] ) ? (float) $item['aggregate_score'] : null,
				'top_match_score' => isset( $item['top_match_score'] ) ? (float) $item['top_match_score'] : null,
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
				'aggregate_score' => isset( $item['aggregate_score'] ) ? (float) $item['aggregate_score'] : null,
				'top_match_score' => isset( $item['top_match_score'] ) ? (float) $item['top_match_score'] : null,
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
}
