<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Semantic_Retrieval_Helper {

	public static function build_contextual_read_only_fallback( $command, $normalized, $client_context = [] ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return null;
		}

		$contextual_surface = self::resolve_contextual_read_only_surface( $client_context );

		if ( empty( $contextual_surface ) || ! self::is_contextual_read_only_refresh_request( $normalized ) ) {
			return null;
		}

		$action         = $contextual_surface['action'] ?? '';
		$intent_name    = $contextual_surface['intent_name'] ?? '';
		$default_window = $contextual_surface['default_window'] ?? 'today';
		$confidence     = 0.88;

		if ( in_array( $action, [ 'store.get_daily_briefing', 'store.get_morning_briefing' ], true ) ) {
			return [
				'success'            => true,
				'matched'            => true,
				'action'             => $action,
				'params'             => [
					'window' => self::detect_window( $normalized, $default_window ),
				],
				'parser_rule'        => 'guard.contextual_read_only_surface',
				'parser_source'      => 'guard',
				'confidence'         => $confidence,
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'intent'             => [
					'name'       => $intent_name,
					'confidence' => $confidence,
					'source'     => 'guard',
				],
			];
		}

		if ( 'store.get_business_summary' === $action ) {
			return [
				'success'            => true,
				'matched'            => true,
				'action'             => $action,
				'params'             => [
					'window' => self::detect_window( $normalized, $default_window ),
					'focus'  => self::detect_business_focus( $normalized ),
				],
				'parser_rule'        => 'guard.contextual_read_only_surface',
				'parser_source'      => 'guard',
				'confidence'         => $confidence,
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'intent'             => [
					'name'       => $intent_name,
					'confidence' => $confidence,
					'source'     => 'guard',
				],
			];
		}

		if ( 'operations.get_summary' === $action ) {
			return [
				'success'            => true,
				'matched'            => true,
				'action'             => $action,
				'params'             => [
					'window' => self::detect_window( $normalized, $default_window ),
					'focus'  => 'overview',
				],
				'parser_rule'        => 'guard.contextual_read_only_surface',
				'parser_source'      => 'guard',
				'confidence'         => $confidence,
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'intent'             => [
					'name'       => $intent_name,
					'confidence' => $confidence,
					'source'     => 'guard',
				],
			];
		}

		return null;
	}

	public static function build_direct_operations_overview_fallback( $command, $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized || ! self::is_direct_operations_overview_request( $normalized ) ) {
			return null;
		}

		$default_window = self::is_store_update_request( $normalized ) ? 'last_7_days' : 'today';

		return [
			'success'            => true,
			'matched'            => true,
			'action'             => 'operations.get_summary',
			'params'             => [
				'window' => self::detect_window( $normalized, $default_window ),
				'focus'  => 'overview',
			],
			'parser_rule'        => 'semantic.operations_overview',
			'parser_source'      => 'semantic_recovery',
			'confidence'         => 0.88,
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'intent'             => [
				'name'       => 'operations_overview',
				'confidence' => 0.88,
				'source'     => 'semantic_recovery',
			],
		];
	}

	public static function detect_window( $normalized, $default ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( false !== strpos( $normalized, 'today' ) ) {
			return 'today';
		}

		if ( false !== strpos( $normalized, 'yesterday' ) ) {
			return 'yesterday';
		}

		if (
			false !== strpos( $normalized, 'last 30 days' ) ||
			false !== strpos( $normalized, 'last thirty days' ) ||
			false !== strpos( $normalized, 'monthly' )
		) {
			return 'last_30_days';
		}

		if ( false !== strpos( $normalized, 'this month' ) ) {
			return 'this_month';
		}

		if (
			false !== strpos( $normalized, 'last 7 days' ) ||
			false !== strpos( $normalized, 'last seven days' ) ||
			false !== strpos( $normalized, 'weekly' )
		) {
			return 'last_7_days';
		}

		if ( false !== strpos( $normalized, 'this week' ) ) {
			return 'this_week';
		}

		return $default;
	}

	public static function detect_limit( $command, $default ) {
		$command = is_string( $command ) ? $command : '';
		$patterns = [
			'/\b(?:top|first|latest|recent|last|newest|lestet|show)\s+(\d{1,2})\b/i',
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
			'/\b(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				return min( max( absint( $matches[1] ), 1 ), 20 );
			}
		}

		return $default;
	}

	public static function detect_business_focus( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( false !== strpos( $normalized, 'operations' ) || false !== strpos( $normalized, 'backlog' ) ) {
			return 'operations';
		}

		if ( false !== strpos( $normalized, 'customers' ) || false !== strpos( $normalized, 'customer' ) ) {
			return 'customers';
		}

		if ( false !== strpos( $normalized, 'products' ) || false !== strpos( $normalized, 'inventory' ) ) {
			return 'products';
		}

		return 'overview';
	}

	public static function detect_operations_focus( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( false !== strpos( $normalized, 'backlog' ) || false !== strpos( $normalized, 'queue' ) ) {
			return 'backlog';
		}

		return 'overview';
	}

	public static function detect_inventory_focus( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( false !== strpos( $normalized, 'low stock' ) ) {
			return 'low_stock';
		}

		if ( false !== strpos( $normalized, 'out of stock' ) || false !== strpos( $normalized, 'out-of-stock' ) ) {
			return 'out_of_stock';
		}

		if ( false !== strpos( $normalized, 'backorder' ) ) {
			return 'backorder';
		}

		return 'overview';
	}

	public static function is_unsupported_analytics_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		$unsupported_markers = [
			'vip customers',
			'spent more than',
			'conversion rate',
			'best selling products',
			'best-selling products',
			'best selling product',
			'best-selling product',
		];

		foreach ( $unsupported_markers as $marker ) {
			if ( false !== strpos( $normalized, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	public static function is_semantic_store_briefing_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		if ( ! preg_match( '/\b(?:brief|briefing|brief me|brief us|snapshot|readout)\b/i', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:business summary|business overview|operations summary|operations overview|store insights|insights)\b/i', $normalized ) ) {
			return false;
		}

		return true;
	}

	public static function is_semantic_business_summary_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		if ( self::is_unsupported_analytics_request( $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:brief|briefing|snapshot|readout)\b/i', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:operations summary|operations overview|attention queue|fulfillment queue|customer service queue|inventory queue|store insights|insights)\b/i', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:business summary|business overview|business performance)\b/i', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:our\s+business|the\s+business|business)\b/i', $normalized ) ) {
			return (bool) preg_match(
				'/\b(?:how\s+is|how(?:\'?s| is)|show|summary|get|give|tell|update|details|doing|performance|about)\b/i',
				$normalized
			);
		}

		return false;
	}

	public static function is_semantic_operations_overview_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:how\s+is\s+the\s+store\s+doing|what\s+does\s+the\s+store\s+look\s+like|what(?:\'?s| is)?\s+new)\b/i',
			$normalized
		);
	}

	private static function is_direct_operations_overview_request( $normalized ) {
		return self::is_semantic_operations_overview_request( $normalized ) ||
			self::is_general_what_should_we_do_request( $normalized ) ||
			self::is_operations_overview_today_request( $normalized ) ||
			self::is_store_update_request( $normalized );
	}

	private static function is_general_what_should_we_do_request( $normalized ) {
		return (bool) preg_match(
			'/\bwhat(?:\'?s| is)?\s+(?:should\s+)?we\s+do\b.*\b(?:today|now)\b/i',
			$normalized
		);
	}

	private static function is_operations_overview_today_request( $normalized ) {
		return (bool) preg_match(
			'/\bwhat(?:\'?s| is)?\s+going\s+on\b.*\b(?:today|right now|now)\b/i',
			$normalized
		);
	}

	private static function is_store_update_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:store\s+update|update\s+on\s+the\s+store|update\s+for\s+the\s+store)\b/i',
			$normalized
		);
	}

	public static function is_contextual_read_only_refresh_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		if ( preg_match( '/\b(?:order|orders|customer|customers|email|draft|template|note|status|refund|hold|cancel|send|write|compose)\b/i', $normalized ) ) {
			return false;
		}

		$word_count = str_word_count( preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $normalized ) );
		$window = self::detect_window( $normalized, '' );
		$has_refresh_anchor = (bool) preg_match( '/\b(?:again|refresh|latest|update|current|now|today|weekly|monthly)\b/i', $normalized );

		if ( '' !== $window && $word_count <= 4 ) {
			return true;
		}

		if ( $has_refresh_anchor && $word_count <= 3 ) {
			return true;
		}

		return false;
	}

	private static function resolve_contextual_read_only_surface( $client_context = [] ) {
		$active_followup_type = sanitize_key( $client_context['active_followup_type'] ?? '' );
		$last_result_type     = sanitize_key( $client_context['last_result_type'] ?? '' );

		$candidates = array_values(
			array_filter(
				[
					$active_followup_type,
					$last_result_type,
				]
			)
		);

		$map = [
			'briefing' => [
				'action'         => 'store.get_daily_briefing',
				'intent_name'    => 'store_briefing',
				'default_window' => 'today',
			],
			'morning_briefing' => [
				'action'         => 'store.get_morning_briefing',
				'intent_name'    => 'store_briefing',
				'default_window' => 'today',
			],
			'business' => [
				'action'         => 'store.get_business_summary',
				'intent_name'    => 'business_summary',
				'default_window' => 'last_7_days',
			],
			'business_summary' => [
				'action'         => 'store.get_business_summary',
				'intent_name'    => 'business_summary',
				'default_window' => 'last_7_days',
			],
			'operations' => [
				'action'         => 'operations.get_summary',
				'intent_name'    => 'operations_overview',
				'default_window' => 'last_7_days',
			],
			'operations_summary' => [
				'action'         => 'operations.get_summary',
				'intent_name'    => 'operations_overview',
				'default_window' => 'last_7_days',
			],
		];

		foreach ( $candidates as $candidate ) {
			if ( ! empty( $map[ $candidate ] ) ) {
				return $map[ $candidate ];
			}
		}

		return [];
	}
}
