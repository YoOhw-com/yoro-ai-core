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
		unset( $extra['retrieved_examples'] );
		unset( $extra['route_candidates'] );

		$data = [
			'trace_id'           => sanitize_text_field( $trace_id ),
			'execution_source'   => sanitize_key( $extra['execution_source'] ?? '' ),
			'parser_source'      => sanitize_key( $extra['parser_source'] ?? '' ),
			'parser_rule'        => sanitize_text_field( $extra['parser_rule'] ?? '' ),
			'intent_name'        => sanitize_key( $extra['intent_name'] ?? '' ),
			'confidence'         => isset( $extra['confidence'] ) && null !== $extra['confidence'] ? (float) $extra['confidence'] : null,
			'ai_meta'            => isset( $extra['ai_meta'] ) && is_array( $extra['ai_meta'] ) ? $extra['ai_meta'] : [],
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
		];

		return array_merge( $data, $extra );
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
