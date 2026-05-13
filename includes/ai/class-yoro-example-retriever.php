<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Example_Retriever {

	public static function get_examples_for_command( $command, $limit = 5 ) {
		$scored = self::get_scored_examples( $command, $limit );

		return array_map(
			function( $example ) {
				return [
					'command' => sanitize_text_field( $example['command'] ?? '' ),
					'intent'  => sanitize_key( $example['intent'] ?? '' ),
					'action'  => sanitize_text_field( $example['action'] ?? '' ),
					'source'  => sanitize_key( $example['source'] ?? 'curated' ),
				];
			},
			$scored
		);
	}

	public static function get_route_candidates( $command, $limit = 5 ) {
		$scored            = self::get_scored_examples( $command, max( $limit, 6 ) );
		$intent_candidates = [];
		$action_candidates = [];

		foreach ( $scored as $example ) {
			$score  = isset( $example['score'] ) ? (float) $example['score'] : 0;
			$intent = sanitize_key( $example['intent'] ?? '' );
			$action = sanitize_text_field( $example['action'] ?? '' );

			if ( '' !== $intent ) {
				if ( ! isset( $intent_candidates[ $intent ] ) ) {
					$intent_candidates[ $intent ] = [
						'name'            => $intent,
						'aggregate_score' => 0,
						'top_match_score' => 0,
						'match_count'     => 0,
						'sources'         => [],
					];
				}

				$intent_candidates[ $intent ]['aggregate_score'] += $score;
				$intent_candidates[ $intent ]['top_match_score'] = max( $intent_candidates[ $intent ]['top_match_score'], $score );
				$intent_candidates[ $intent ]['match_count']++;
				$intent_candidates[ $intent ]['sources'][] = sanitize_key( $example['source'] ?? '' );
			}

			if ( '' !== $action ) {
				if ( ! isset( $action_candidates[ $action ] ) ) {
					$action_candidates[ $action ] = [
						'name'            => $action,
						'intent'          => $intent,
						'aggregate_score' => 0,
						'top_match_score' => 0,
						'match_count'     => 0,
						'sources'         => [],
					];
				}

				$action_candidates[ $action ]['aggregate_score'] += $score;
				$action_candidates[ $action ]['top_match_score'] = max( $action_candidates[ $action ]['top_match_score'], $score );
				$action_candidates[ $action ]['match_count']++;

				if ( '' !== $intent && empty( $action_candidates[ $action ]['intent'] ) ) {
					$action_candidates[ $action ]['intent'] = $intent;
				}

				$action_candidates[ $action ]['sources'][] = sanitize_key( $example['source'] ?? '' );
			}
		}

		$intent_candidates = array_values( $intent_candidates );
		$action_candidates = array_values( $action_candidates );

		usort(
			$intent_candidates,
			function ( $a, $b ) {
				return ( $b['aggregate_score'] ?? 0 ) <=> ( $a['aggregate_score'] ?? 0 );
			}
		);

		usort(
			$action_candidates,
			function ( $a, $b ) {
				return ( $b['aggregate_score'] ?? 0 ) <=> ( $a['aggregate_score'] ?? 0 );
			}
		);

		$intent_candidates = array_slice( $intent_candidates, 0, $limit );
		$action_candidates = array_slice( $action_candidates, 0, $limit );

		return [
			'intents' => array_map(
				function ( $item ) {
					return [
						'name'            => sanitize_key( $item['name'] ?? '' ),
						'aggregate_score' => round( (float) ( $item['aggregate_score'] ?? 0 ), 3 ),
						'top_match_score' => round( (float) ( $item['top_match_score'] ?? 0 ), 3 ),
						'match_count'     => absint( $item['match_count'] ?? 0 ),
						'sources'         => array_values( array_filter( array_unique( array_map( 'sanitize_key', $item['sources'] ?? [] ) ) ) ),
					];
				},
				$intent_candidates
			),
			'actions' => array_map(
				function ( $item ) {
					return [
						'name'            => sanitize_text_field( $item['name'] ?? '' ),
						'intent'          => sanitize_key( $item['intent'] ?? '' ),
						'aggregate_score' => round( (float) ( $item['aggregate_score'] ?? 0 ), 3 ),
						'top_match_score' => round( (float) ( $item['top_match_score'] ?? 0 ), 3 ),
						'match_count'     => absint( $item['match_count'] ?? 0 ),
						'sources'         => array_values( array_filter( array_unique( array_map( 'sanitize_key', $item['sources'] ?? [] ) ) ) ),
					];
				},
				$action_candidates
			),
		];
	}

	private static function get_scored_examples( $command, $limit = 5 ) {
		$command = self::normalize_text( $command );
		$limit   = min( max( absint( $limit ), 1 ), 10 );
		$pool    = array_merge(
			self::get_curated_examples(),
			self::get_real_query_examples()
		);

		$scored = [];

		foreach ( $pool as $example ) {
			$example_command = self::normalize_text( $example['command'] ?? '' );

			if ( '' === $example_command ) {
				continue;
			}

			$score = self::score_similarity( $command, $example_command );

			if ( $score <= 0 ) {
				continue;
			}

			$example['score'] = $score;
			$scored[]         = $example;
		}

		usort(
			$scored,
			function( $a, $b ) {
				return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
			}
		);

		return array_slice( $scored, 0, $limit );
	}

	private static function get_curated_examples() {
		return [
			[
				'command' => 'Show me the business this week',
				'intent'  => 'business_summary',
				'action'  => 'store.get_business_summary',
				'source'  => 'curated',
			],
			[
				'command' => 'Give me a business update for this week',
				'intent'  => 'business_summary',
				'action'  => 'store.get_business_summary',
				'source'  => 'curated',
			],
			[
				'command' => 'How is our business this week?',
				'intent'  => 'business_summary',
				'action'  => 'store.get_business_summary',
				'source'  => 'curated',
			],
			[
				'command' => 'What is going on this week?',
				'intent'  => 'operations_overview',
				'action'  => 'operations.get_summary',
				'source'  => 'curated',
			],
			[
				'command' => 'Give me the store picture for today',
				'intent'  => 'operations_overview',
				'action'  => 'operations.get_summary',
				'source'  => 'curated',
			],
			[
				'command' => 'What does the store look like this month?',
				'intent'  => 'operations_overview',
				'action'  => 'operations.get_summary',
				'source'  => 'curated',
			],
			[
				'command' => 'Can you give me the weekly store picture?',
				'intent'  => 'operations_overview',
				'action'  => 'operations.get_summary',
				'source'  => 'curated',
			],
			[
				'command' => 'Show me today\'s briefing',
				'intent'  => 'store_briefing',
				'action'  => 'store.get_daily_briefing',
				'source'  => 'curated',
			],
			[
				'command' => 'Show me the weekly briefing',
				'intent'  => 'store_briefing',
				'action'  => 'store.get_daily_briefing',
				'source'  => 'curated',
			],
			[
				'command' => 'Give me the monthly briefing',
				'intent'  => 'store_briefing',
				'action'  => 'store.get_daily_briefing',
				'source'  => 'curated',
			],
			[
				'command' => 'Show me the brief of this month',
				'intent'  => 'store_briefing',
				'action'  => 'store.get_daily_briefing',
				'source'  => 'curated',
			],
			[
				'command' => 'Give me a weekly store snapshot',
				'intent'  => 'store_briefing',
				'action'  => 'store.get_daily_briefing',
				'source'  => 'curated',
			],
			[
				'command' => 'What is the store readout for this week?',
				'intent'  => 'store_briefing',
				'action'  => 'store.get_daily_briefing',
				'source'  => 'curated',
			],
			[
				'command' => 'Give me the monthly store wrap-up',
				'intent'  => 'store_briefing',
				'action'  => 'store.get_daily_briefing',
				'source'  => 'curated',
			],
			[
				'command' => 'Anything urgent right now?',
				'intent'  => 'operations_attention',
				'action'  => 'operations.get_attention_queue',
				'source'  => 'curated',
			],
			[
				'command' => 'Show me shipment blockers',
				'intent'  => 'fulfillment_review',
				'action'  => 'operations.get_fulfillment_queue',
				'source'  => 'curated',
			],
			[
				'command' => 'What looks stuck in shipping?',
				'intent'  => 'fulfillment_review',
				'action'  => 'operations.get_fulfillment_queue',
				'source'  => 'curated',
			],
			[
				'command' => 'What seems blocked in fulfillment?',
				'intent'  => 'fulfillment_review',
				'action'  => 'operations.get_fulfillment_queue',
				'source'  => 'curated',
			],
			[
				'command' => 'What should we restock first?',
				'intent'  => 'inventory_review',
				'action'  => 'products.get_inventory_queue',
				'source'  => 'curated',
			],
			[
				'command' => 'Which customers need a reply?',
				'intent'  => 'customer_service_review',
				'action'  => 'customers.get_service_queue',
				'source'  => 'curated',
			],
			[
				'command' => 'Who should we reply to first?',
				'intent'  => 'customer_service_review',
				'action'  => 'customers.get_service_queue',
				'source'  => 'curated',
			],
			[
				'command' => 'Mark order #123 as completed',
				'intent'  => 'update_order_status',
				'action'  => 'orders.update_status',
				'source'  => 'curated',
			],
		];
	}

	private static function get_real_query_examples() {
		if ( ! class_exists( 'YoRo_Logger' ) ) {
			return [];
		}

		$pack = YoRo_Logger::build_real_query_evaluation_pack( 80 );

		if ( empty( $pack['available'] ) || empty( $pack['queries'] ) || ! is_array( $pack['queries'] ) ) {
			return [];
		}

		$examples = [];

		foreach ( $pack['queries'] as $query ) {
			$intent = sanitize_key( $query['intent'] ?? '' );
			$action = sanitize_text_field( $query['action'] ?? '' );

			if ( '' === $intent && '' === $action ) {
				continue;
			}

			$examples[] = [
				'command' => sanitize_text_field( $query['command'] ?? '' ),
				'intent'  => $intent,
				'action'  => $action,
				'source'  => 'real_query',
			];
		}

		return $examples;
	}

	private static function score_similarity( $needle, $haystack ) {
		if ( '' === $needle || '' === $haystack ) {
			return 0;
		}

		if ( $needle === $haystack ) {
			return 1.0;
		}

		$needle_tokens  = self::tokenize( $needle );
		$haystack_tokens = self::tokenize( $haystack );

		if ( empty( $needle_tokens ) || empty( $haystack_tokens ) ) {
			return 0;
		}

		$overlap_count  = count( array_intersect( $needle_tokens, $haystack_tokens ) );
		$union_count    = count( array_unique( array_merge( $needle_tokens, $haystack_tokens ) ) );
		$jaccard        = $union_count > 0 ? ( $overlap_count / $union_count ) : 0;
		$coverage       = count( $needle_tokens ) > 0 ? ( $overlap_count / count( $needle_tokens ) ) : 0;
		$prefix_bonus   = 0;
		$contain_bonus  = 0;
		$semantic_bonus = self::semantic_bonus( $needle_tokens, $haystack_tokens );

		if ( false !== strpos( $haystack, $needle ) || false !== strpos( $needle, $haystack ) ) {
			$contain_bonus = 0.18;
		}

		if ( ! empty( $needle_tokens[0] ) && ! empty( $haystack_tokens[0] ) && $needle_tokens[0] === $haystack_tokens[0] ) {
			$prefix_bonus = 0.08;
		}

		return min(
			1.0,
			( $jaccard * 0.45 ) +
			( $coverage * 0.35 ) +
			$contain_bonus +
			$prefix_bonus +
			$semantic_bonus
		);
	}

	private static function semantic_bonus( $needle_tokens, $haystack_tokens ) {
		$needle_tokens   = is_array( $needle_tokens ) ? $needle_tokens : [];
		$haystack_tokens = is_array( $haystack_tokens ) ? $haystack_tokens : [];
		$common          = array_intersect( $needle_tokens, $haystack_tokens );
		$bonus           = 0;

		$concept_groups = [
			[ 'fulfillment', 'shipping', 'shipment', 'delivery' ],
			[ 'blocked', 'blocker', 'blockers', 'stuck', 'delayed', 'delay' ],
			[ 'reply', 'answer', 'respond', 'response', 'followup', 'follow-up' ],
			[ 'business', 'performance', 'summary', 'overview', 'update' ],
			[ 'store', 'operations', 'overview', 'picture', 'health', 'summary' ],
			[ 'brief', 'briefing', 'snapshot', 'readout', 'wrapup', 'wrap-up', 'wrap', 'summary' ],
			[ 'inventory', 'stock', 'restock', 'backorder', 'backorders' ],
		];

		foreach ( $concept_groups as $group ) {
			$needle_hit   = count( array_intersect( $needle_tokens, $group ) ) > 0;
			$haystack_hit = count( array_intersect( $haystack_tokens, $group ) ) > 0;

			if ( $needle_hit && $haystack_hit && 0 === count( array_intersect( $common, $group ) ) ) {
				$bonus += 0.06;
			}
		}

		return min( 0.18, $bonus );
	}

	private static function tokenize( $text ) {
		$text = self::normalize_text( $text );
		$tokens = array_values(
			array_filter(
				array_unique(
					preg_split( '/\s+/', $text )
				)
			)
		);

		return self::expand_semantic_tokens( $tokens );
	}

	private static function normalize_text( $text ) {
		$text = strtolower( trim( wp_strip_all_tags( (string) $text ) ) );
		$text = str_replace(
			[ '’', '`', '‘', '“', '”' ],
			[ '\'', '\'', '\'', '"', '"' ],
			$text
		);
		$text = preg_replace( '/#\d+\b/', '#{id}', $text );
		$text = preg_replace( '/\border\s+\d+\b/i', 'order #{id}', $text );
		$text = preg_replace( '/\s+/', ' ', $text );

		return $text;
	}

	private static function expand_semantic_tokens( $tokens ) {
		$tokens = is_array( $tokens ) ? $tokens : [];

		$alias_map = [
			'fulfillment' => [ 'shipping', 'shipment', 'delivery' ],
			'shipping'    => [ 'fulfillment', 'shipment', 'delivery' ],
			'shipment'    => [ 'shipping', 'fulfillment', 'delivery' ],
			'delivery'    => [ 'shipping', 'shipment', 'fulfillment' ],
			'blocked'     => [ 'blockers', 'blocker', 'stuck', 'delayed', 'delay' ],
			'blocker'     => [ 'blocked', 'blockers', 'stuck', 'delayed' ],
			'blockers'    => [ 'blocked', 'blocker', 'stuck', 'delayed' ],
			'stuck'       => [ 'blocked', 'blocker', 'blockers', 'delayed' ],
			'reply'       => [ 'respond', 'response', 'answer', 'followup', 'follow-up' ],
			'answer'      => [ 'reply', 'respond', 'response', 'followup', 'follow-up' ],
			'respond'     => [ 'reply', 'answer', 'response', 'followup', 'follow-up' ],
			'followup'    => [ 'follow-up', 'reply', 'answer', 'respond' ],
			'follow-up'   => [ 'followup', 'reply', 'answer', 'respond' ],
			'customer'    => [ 'customers' ],
			'customers'   => [ 'customer' ],
			'brief'       => [ 'briefing', 'snapshot', 'readout', 'wrapup', 'wrap-up' ],
			'briefing'    => [ 'brief', 'snapshot', 'readout', 'wrapup', 'wrap-up' ],
			'snapshot'    => [ 'brief', 'briefing', 'readout', 'wrapup', 'wrap-up' ],
			'readout'     => [ 'brief', 'briefing', 'snapshot', 'wrapup', 'wrap-up' ],
			'wrapup'      => [ 'brief', 'briefing', 'snapshot', 'readout', 'wrap-up' ],
			'wrap-up'     => [ 'brief', 'briefing', 'snapshot', 'readout', 'wrapup' ],
		];

		$expanded = $tokens;

		foreach ( $tokens as $token ) {
			if ( ! isset( $alias_map[ $token ] ) ) {
				continue;
			}

			$expanded = array_merge( $expanded, $alias_map[ $token ] );
		}

		return array_values(
			array_filter(
				array_unique( $expanded )
			)
		);
	}
}
