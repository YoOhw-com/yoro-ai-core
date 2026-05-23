<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Command_Shape_Extractor {

	const VERSION = 1;

	public static function extract( $command, $context = [] ) {
		$command    = is_string( $command ) ? trim( $command ) : '';
		$context    = is_array( $context ) ? $context : [];
		$normalized = self::normalize_command( $command );

		$explicit_order_ids      = self::extract_explicit_order_ids( $command );
		$quantity_scope          = self::detect_quantity_scope( $command, $normalized );
		$quantity                = ! empty( $quantity_scope['value'] ) ? absint( $quantity_scope['value'] ) : self::detect_quantity( $command, $normalized );
		$status                  = self::detect_order_status( $normalized );
		$age_filter              = self::detect_age_filter( $normalized );
		$date_filter             = self::detect_date_filter( $normalized );
		$money_filter            = self::detect_money_filter( $normalized );
		$customer_identity       = self::detect_customer_identity( $command, $normalized );
		$payment_method          = self::detect_payment_method( $command, $normalized );
		$shipping_state          = self::detect_shipping_state( $command, $normalized );
		$has_latest_order_shorthand = self::looks_like_latest_order_shorthand( $normalized );
		$has_order_word          = (bool) preg_match( '/\borders?\b/u', $normalized );
		$has_plural_order_word   = (bool) preg_match( '/\borders\b/u', $normalized );
		$has_order_search_verb   = (bool) preg_match( '/\b(?:show|open|view|get|list|find|search|review|browse|load)\b/u', $normalized );
		$has_contextual_order_ref = self::has_contextual_order_reference( $normalized );
		$has_recent_list_ref      = self::has_recent_order_list_reference( $normalized );
		$query_action             = self::detect_query_action( $command, $normalized, $context );
		$write_intent             = self::detect_write_intent( $command, $normalized );
		$workflow_intent          = self::detect_workflow_intent( $command, $normalized, $explicit_order_ids );
		$write_missing_target     = self::detect_write_missing_target( $write_intent, $explicit_order_ids, $query_action );
		$signals                  = [];

		if ( $has_order_word ) {
			$signals[] = 'order_word';
		}

		if ( $has_plural_order_word ) {
			$signals[] = 'plural_order_word';
		}

		if ( $has_order_search_verb ) {
			$signals[] = 'order_search_verb';
		}

		if ( null !== $quantity ) {
			$signals[] = 'quantity';
		}

		if ( ! empty( $quantity_scope ) ) {
			$signals[] = 'quantity_scope';
		}

		if ( $has_latest_order_shorthand ) {
			$signals[] = 'order_list_shorthand';
		}

		if ( '' !== $status ) {
			$signals[] = 'status';
		}

		if ( ! empty( $age_filter ) ) {
			$signals[] = 'age_filter';
		}

		if ( ! empty( $date_filter ) ) {
			$signals[] = 'date_filter';
		}

		if ( ! empty( $money_filter ) ) {
			$signals[] = 'money_filter';
		}

		if ( ! empty( $customer_identity ) ) {
			$signals[] = 'customer_identity';
		}

		if ( ! empty( $payment_method ) ) {
			$signals[] = 'payment_method';
		}

		if ( ! empty( $shipping_state ) ) {
			$signals[] = 'shipping_state';
		}

		if ( ! empty( $explicit_order_ids ) ) {
			$signals[] = 'explicit_order_id';
		}

		if ( $has_contextual_order_ref ) {
			$signals[] = 'contextual_order_reference';
		}

		if ( $has_recent_list_ref ) {
			$signals[] = 'recent_order_list_reference';
		}

		if ( ! empty( $query_action ) ) {
			$signals[] = 'query_action';
			$signals[] = 'write_action';
		}

		if ( ! empty( $write_intent ) ) {
			$signals[] = 'write_intent';
			$signals[] = 'write_action';
		}

		if ( ! empty( $workflow_intent ) ) {
			$signals[] = 'workflow_intent';

			if ( ! empty( $workflow_intent['kind'] ) ) {
				$signals[] = sanitize_key( $workflow_intent['kind'] );
			}
		}

		if ( ! empty( $write_missing_target ) ) {
			$signals[] = 'missing_target';
			$signals[] = 'write_missing_target';
		}

		$target_type = 'unknown';
		$operation_hint = '';
		$surface = self::detect_surface( $normalized );
		$context_order_allowed = false;

		if ( 'orders' === $surface ) {
			if ( ! empty( $workflow_intent ) && ! empty( $explicit_order_ids ) ) {
				$target_type = sanitize_key( $workflow_intent['target_type'] ?? 'order_workflow_explicit' );
				$operation_hint = sanitize_key( $workflow_intent['operation_hint'] ?? 'workflow' );
				$context_order_allowed = true;
			} elseif ( ! empty( $query_action ) ) {
				$target_type = 'order_query_selection';
				$operation_hint = 'query_and_apply';
				$context_order_allowed = false;

				if ( null === $quantity && ! empty( $query_action['limit'] ) ) {
					$quantity = absint( $query_action['limit'] );
				}

				if ( empty( $quantity_scope ) && ! empty( $query_action['limit'] ) ) {
					$quantity_scope = [
						'scope'  => 'latest',
						'value'  => absint( $query_action['limit'] ),
						'source' => 'query_action',
					];
				}
			} elseif ( ! empty( $explicit_order_ids ) ) {
				$target_type = 1 === count( $explicit_order_ids ) ? 'single_order_explicit' : 'order_list_explicit';
				$operation_hint = 1 === count( $explicit_order_ids ) ? 'detail_or_order_action' : 'multi_order_action';
				$context_order_allowed = true;
			} elseif ( $has_recent_list_ref ) {
				$target_type = 'recent_order_list';
				$operation_hint = 'multi_order_action';
				$context_order_allowed = false;
			} elseif ( ! empty( $write_missing_target ) ) {
				$target_type = 'order_write_missing_target';
				$operation_hint = 'write_missing_target';
				$context_order_allowed = false;

				if ( null === $quantity && ! empty( $write_intent['quantity'] ) ) {
					$quantity = absint( $write_intent['quantity'] );
				}

				if ( empty( $quantity_scope ) && ! empty( $write_intent['quantity'] ) ) {
					$quantity_scope = [
						'scope'  => 'write_target_count',
						'value'  => absint( $write_intent['quantity'] ),
						'source' => 'write_intent',
					];
				}
			} elseif ( self::looks_like_order_list_search( $normalized, $quantity, $status, $date_filter, $money_filter, $customer_identity, $payment_method, $shipping_state, $has_order_word, $has_plural_order_word, $has_order_search_verb, $has_contextual_order_ref, $has_latest_order_shorthand ) ) {
				$target_type = 'order_list';
				$operation_hint = 'search';
				$context_order_allowed = false;
			} elseif ( $has_contextual_order_ref ) {
				$target_type = 'single_order_contextual';
				$operation_hint = 'detail_or_order_action';
				$context_order_allowed = true;
			}
		}

		return [
			'version'               => self::VERSION,
			'surface'               => $surface,
			'target_type'           => $target_type,
			'operation_hint'        => $operation_hint,
			'quantity'              => null !== $quantity ? absint( $quantity ) : null,
			'quantity_scope'        => $quantity_scope,
			'status'                => $status,
			'age_filter'            => $age_filter,
			'date_filter'           => $date_filter,
			'money_filter'          => $money_filter,
			'customer_identity'     => $customer_identity,
			'payment_method'        => $payment_method,
			'shipping_state'        => $shipping_state,
			'explicit_order_ids'    => $explicit_order_ids,
			'context_order_allowed' => $context_order_allowed,
			'query_action'          => $query_action,
			'write_intent'          => $write_intent,
			'workflow_intent'       => $workflow_intent,
			'write_missing_target'  => $write_missing_target,
			'signals'               => array_values( array_unique( array_filter( $signals ) ) ),
		];
	}

	public static function is_order_list_search_shape( $shape ) {
		$shape = is_array( $shape ) ? $shape : [];

		return 'orders' === sanitize_key( $shape['surface'] ?? '' ) &&
			'order_list' === sanitize_key( $shape['target_type'] ?? '' ) &&
			'search' === sanitize_key( $shape['operation_hint'] ?? '' );
	}

	public static function compact_for_prompt( $shape ) {
		$shape = is_array( $shape ) ? $shape : [];

		return [
			'version'               => absint( $shape['version'] ?? self::VERSION ),
			'surface'               => sanitize_key( $shape['surface'] ?? 'unknown' ),
			'target_type'           => sanitize_key( $shape['target_type'] ?? 'unknown' ),
			'operation_hint'        => sanitize_key( $shape['operation_hint'] ?? '' ),
			'quantity'              => isset( $shape['quantity'] ) && null !== $shape['quantity'] ? absint( $shape['quantity'] ) : null,
			'quantity_scope'        => self::compact_quantity_scope( $shape['quantity_scope'] ?? [] ),
			'status'                => sanitize_key( $shape['status'] ?? '' ),
			'age_filter'            => self::compact_age_filter( $shape['age_filter'] ?? [] ),
			'date_filter'           => self::compact_date_filter( $shape['date_filter'] ?? [] ),
			'money_filter'          => self::compact_money_filter( $shape['money_filter'] ?? [] ),
			'customer_identity'     => self::compact_customer_identity( $shape['customer_identity'] ?? [] ),
			'payment_method'        => self::compact_payment_method( $shape['payment_method'] ?? [] ),
			'shipping_state'        => self::compact_shipping_state( $shape['shipping_state'] ?? [] ),
			'explicit_order_ids'    => isset( $shape['explicit_order_ids'] ) && is_array( $shape['explicit_order_ids'] )
				? array_values( array_filter( array_map( 'absint', $shape['explicit_order_ids'] ) ) )
				: [],
			'context_order_allowed' => ! empty( $shape['context_order_allowed'] ),
			'query_action'          => self::compact_query_action( $shape['query_action'] ?? [] ),
			'write_intent'          => self::compact_write_intent( $shape['write_intent'] ?? [] ),
			'workflow_intent'       => self::compact_workflow_intent( $shape['workflow_intent'] ?? [] ),
			'write_missing_target'  => self::compact_write_missing_target( $shape['write_missing_target'] ?? [] ),
			'signals'               => isset( $shape['signals'] ) && is_array( $shape['signals'] )
				? array_values( array_filter( array_map( 'sanitize_key', $shape['signals'] ) ) )
				: [],
		];
	}

	public static function is_order_query_action_shape( $shape ) {
		$shape = is_array( $shape ) ? $shape : [];

		return 'orders' === sanitize_key( $shape['surface'] ?? '' ) &&
			'order_query_selection' === sanitize_key( $shape['target_type'] ?? '' ) &&
			'query_and_apply' === sanitize_key( $shape['operation_hint'] ?? '' ) &&
			! empty( $shape['query_action'] ) &&
			is_array( $shape['query_action'] );
	}

	public static function is_order_write_missing_target_shape( $shape ) {
		$shape = is_array( $shape ) ? $shape : [];

		return 'orders' === sanitize_key( $shape['surface'] ?? '' ) &&
			'order_write_missing_target' === sanitize_key( $shape['target_type'] ?? '' ) &&
			'write_missing_target' === sanitize_key( $shape['operation_hint'] ?? '' ) &&
			(
				( ! empty( $shape['write_missing_target'] ) && is_array( $shape['write_missing_target'] ) ) ||
				( ! empty( $shape['write_intent'] ) && is_array( $shape['write_intent'] ) )
			);
	}

	private static function looks_like_latest_order_shorthand( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		return (bool) preg_match( '/^(?:latest|recent|last|newest|lestet)\s+\d{1,2}$/i', $normalized );
	}

	private static function detect_surface( $normalized ) {
		if ( preg_match( '/\borders?\b/u', $normalized ) ) {
			return 'orders';
		}

		if ( self::looks_like_latest_order_shorthand( $normalized ) ) {
			return 'orders';
		}

		if ( preg_match( '/\b(?:customer|customers|email|phone)\b/u', $normalized ) ) {
			return 'customers';
		}

		if ( preg_match( '/\b(?:product|products|inventory|stock|sku)\b/u', $normalized ) ) {
			return 'inventory';
		}

		if ( preg_match( '/\b(?:briefing|summary|business|store|operations|queue|fulfillment)\b/u', $normalized ) ) {
			return 'store';
		}

		return 'unknown';
	}

	private static function looks_like_order_list_search( $normalized, $quantity, $status, $date_filter, $money_filter, $customer_identity, $payment_method, $shipping_state, $has_order_word, $has_plural_order_word, $has_order_search_verb, $has_contextual_order_ref, $has_latest_order_shorthand = false ) {
		if ( $has_latest_order_shorthand && ! $has_contextual_order_ref ) {
			return true;
		}

		if ( ! $has_order_word || $has_contextual_order_ref ) {
			return false;
		}

		$date_filter = is_array( $date_filter ) ? $date_filter : [];
		$has_date_filter = ! empty( $date_filter['window_key'] );
		$money_filter = is_array( $money_filter ) ? $money_filter : [];
		$has_money_filter = ! empty( $money_filter['operator'] );
		$customer_identity = is_array( $customer_identity ) ? $customer_identity : [];
		$has_customer_identity = ! empty( $customer_identity['type'] );
		$payment_method = is_array( $payment_method ) ? $payment_method : [];
		$has_payment_method = ! empty( $payment_method['method'] );
		$shipping_state = is_array( $shipping_state ) ? $shipping_state : [];
		$has_shipping_state = ! empty( $shipping_state['state'] );

		if (
			preg_match( '/\b(?:attention|urgent|manual\s+review|needs?\s+review|needing\s+attention|need\s+attention)\b/u', $normalized ) &&
			'' === $status &&
			! $has_date_filter &&
			! $has_money_filter &&
			! $has_customer_identity &&
			! $has_payment_method &&
			! $has_shipping_state
		) {
			return false;
		}

		if ( $has_plural_order_word && ( $has_order_search_verb || null !== $quantity || '' !== $status || $has_date_filter || $has_money_filter || $has_customer_identity || $has_payment_method || $has_shipping_state ) ) {
			return true;
		}

		if ( null !== $quantity && '' !== $status ) {
			return true;
		}

		if ( '' !== $status && $has_order_search_verb ) {
			return true;
		}

		if ( $has_date_filter && $has_order_search_verb ) {
			return true;
		}

		if ( $has_money_filter && $has_order_search_verb ) {
			return true;
		}

		if ( $has_customer_identity && $has_order_search_verb ) {
			return true;
		}

		if ( $has_payment_method && $has_order_search_verb ) {
			return true;
		}

		if ( $has_shipping_state && $has_order_search_verb ) {
			return true;
		}

		return (bool) preg_match(
			'/\b(?:latest|recent|last|newest|pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b/u',
			$normalized
		);
	}

	private static function detect_quantity( $command, $normalized ) {
		$patterns = [
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(?:me\s+)?(\d{1,2})\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold|new|latest|recent|last|newest)?\s*orders?\b/i',
			'/\b(?:hold|put|place|set|move|mark|change|update)\s+(?:the\s+)?(?:latest|recent|last|newest)\s+(\d{1,2})\s+orders?\b/i',
			'/\b(\d{1,2})\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold|new|latest|recent|last|newest)\s+orders?\b/i',
			'/\b(?:first|top|latest|recent|last|newest)\s+(\d{1,2})\s+orders?\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				return min( max( absint( $matches[1] ), 1 ), 50 );
			}
		}

		if ( preg_match( '/\b(?:a|an|one)\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+order\b/i', $normalized ) ) {
			return 1;
		}

		return null;
	}

	private static function detect_quantity_scope( $command, $normalized ) {
		$command    = trim( (string) $command );
		$normalized = trim( (string) $normalized );

		if ( '' === $command ) {
			return [];
		}

		$has_latest_order_shorthand = self::looks_like_latest_order_shorthand( $normalized );

		if ( false === strpos( $normalized, 'order' ) && ! $has_latest_order_shorthand ) {
			return [];
		}

		$status_pattern = '(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)';

		$patterns = [
			[
				'scope'   => 'all',
				'pattern' => '/\b(?:show|get|open|list|find|search|load|browse|review)\s+(?:me\s+)?(?:all|every)\s+(?:' . $status_pattern . '\s+)?orders?\b/i',
				'value'   => 50,
			],
			[
				'scope'   => 'single',
				'pattern' => '/\b(?:show|open|view|get|list|find|search|review)\b.*\b(?:a|an|one)\s+(?:' . $status_pattern . ')\s+order\b/i',
				'value'   => 1,
			],
			[
				'scope'   => 'first',
				'pattern' => '/\bfirst\s+(\d{1,2})\s+orders?\b/i',
			],
			[
				'scope'   => 'top',
				'pattern' => '/\b(?:limit|top)\s+(\d{1,2})\s+orders?\b/i',
			],
			[
				'scope'   => 'latest',
				'pattern' => '/\b(?:latest|recent|last|newest|lestet)\s+(\d{1,2})\s+orders?\b/i',
			],
			[
				'scope'   => 'latest',
				'pattern' => '/\b(?:show|get|open|list|find|search|load|browse|review)\s+(?:me\s+)?(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\s+orders?\b/i',
			],
			[
				'scope'   => 'latest',
				'pattern' => '/^\s*(?:latest|recent|last|newest|lestet)\s+(\d{1,2})\s*$/i',
			],
			[
				'scope'   => 'status_count',
				'pattern' => '/\b(\d{1,2})\s+' . $status_pattern . '\s+orders?\b/i',
			],
			[
				'scope'   => 'status_count',
				'pattern' => '/\b(?:show|get|open|list|find|search|load|browse|review)\s+(?:me\s+)?(\d{1,2})\s+' . $status_pattern . '\s+orders?\b/i',
			],
			[
				'scope'   => 'count',
				'pattern' => '/\b(?:show|get|open|list|find|search|load|browse|review)\s+(?:me\s+)?(\d{1,2})\s+orders?\b/i',
			],
		];

		foreach ( $patterns as $entry ) {
			if ( ! preg_match( $entry['pattern'], $command, $matches ) ) {
				continue;
			}

			$value = isset( $entry['value'] ) ? absint( $entry['value'] ) : absint( $matches[1] ?? 0 );

			if ( $value <= 0 ) {
				continue;
			}

			return [
				'scope'  => sanitize_key( $entry['scope'] ),
				'value'  => min( max( $value, 1 ), 50 ),
				'source' => sanitize_text_field( $matches[0] ?? '' ),
			];
		}

		return [];
	}

	private static function detect_order_status( $normalized ) {
		if ( ! class_exists( 'YoRo_Order_Helper' ) ) {
			return '';
		}

		if (
			false !== strpos( $normalized, 'email' ) &&
			preg_match( '/\b(?:draft|save\s+as\s+draft|save\s+draft)\b/u', $normalized ) &&
			! preg_match( '/\b(?:status|order\s+status)\b/u', $normalized )
		) {
			return '';
		}

		$status = YoRo_Order_Helper::detect_status_from_search_semantics( $normalized );

		if ( '' === $status ) {
			return '';
		}

		$status = YoRo_Order_Helper::normalize_status_slug( $status );

		return YoRo_Order_Helper::is_valid_order_status( $status ) ? $status : '';
	}

	private static function detect_age_filter( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( '' === $normalized ) {
			return [];
		}

		if (
			! preg_match(
				'/\b(?:more\s+than|over|older\s+than|stuck\s+for|waiting\s+for)\s+(\d{1,3})\s+(hours?|hrs?|days?|weeks?)\b/u',
				$normalized,
				$matches
			)
		) {
			return [];
		}

		$value = absint( $matches[1] ?? 0 );
		$unit  = sanitize_key( $matches[2] ?? '' );

		if ( $value <= 0 ) {
			return [];
		}

		if ( in_array( $unit, [ 'week', 'weeks' ], true ) ) {
			$canonical_unit = 'weeks';
			$hours = $value * 7 * 24;
		} elseif ( in_array( $unit, [ 'day', 'days' ], true ) ) {
			$canonical_unit = 'days';
			$hours = $value * 24;
		} else {
			$canonical_unit = 'hours';
			$hours = $value;
		}

		return [
			'operator' => 'older_than',
			'value'    => $value,
			'unit'     => $canonical_unit,
			'hours'    => min( max( absint( $hours ), 1 ), 8760 ),
		];
	}

	private static function detect_date_filter( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( '' === $normalized || ! class_exists( 'YoRo_Date_Helper' ) ) {
			return [];
		}

		$window_key = YoRo_Date_Helper::infer_window_key_from_text( $normalized );

		if ( '' === $window_key ) {
			$window_key = YoRo_Date_Helper::normalize_window_key( $normalized );
		}

		if ( '' === $window_key ) {
			return [];
		}

		return [
			'type'       => 'window',
			'window_key' => $window_key,
			'text'       => self::extract_date_filter_text( $normalized, $window_key ),
		];
	}

	private static function extract_date_filter_text( $normalized, $window_key ) {
		$window_key = sanitize_key( $window_key );
		$patterns = [
			'today'        => '/\b(?:today)\b|h[oô]m\s+nay/iu',
			'yesterday'    => '/\b(?:yesterday)\b|h[oô]m\s+qua/iu',
			'this_week'    => '/\b(?:this\s+week)\b|tu[aầ]n\s+n[aà]y/iu',
			'last_week'    => '/\b(?:last\s+week)\b|tu[aầ]n\s+tr[uư][oớ]c/iu',
			'last_7_days'  => '/\b(?:(?:last|past)\s+7\s+days|past\s+week)\b|7\s+ng[aà]y\s+qua/iu',
			'this_month'   => '/\b(?:this\s+month)\b|th[aá]ng\s+n[aà]y/iu',
			'last_month'   => '/\b(?:last\s+month)\b|th[aá]ng\s+tr[uư][oớ]c/iu',
			'last_30_days' => '/\b(?:(?:last|past)\s+30\s+days|past\s+month)\b|30\s+ng[aà]y\s+qua/iu',
		];

		if ( empty( $patterns[ $window_key ] ) ) {
			return '';
		}

		if ( preg_match( $patterns[ $window_key ], $normalized, $matches ) ) {
			return sanitize_text_field( $matches[0] ?? '' );
		}

		return '';
	}

	private static function detect_money_filter( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( '' === $normalized || false === strpos( $normalized, 'order' ) ) {
			return [];
		}

		$amount_pattern = '(?:\$|usd\s*)?\s*([0-9][0-9,]*(?:\.[0-9]+)?)';

		if (
			preg_match(
				'/\b(?:total|amount|value|order\s+total|order\s+value)?\s*(?:between|from)\s+' . $amount_pattern . '\s*(?:and|to|-)\s*' . $amount_pattern . '\b/iu',
				$normalized,
				$matches
			)
		) {
			$text = sanitize_text_field( $matches[0] ?? '' );

			if (
				! self::money_filter_has_money_context( $normalized, $text ) ||
				self::money_filter_is_time_unit( $normalized, $text )
			) {
				return [];
			}

			$min = self::parse_money_amount( $matches[1] ?? '' );
			$max = self::parse_money_amount( $matches[2] ?? '' );

			if ( null !== $min && null !== $max ) {
				return [
					'operator' => 'between',
					'min'      => min( $min, $max ),
					'max'      => max( $min, $max ),
					'currency' => self::detect_money_currency( $normalized ),
					'text'     => $text,
				];
			}
		}

		$comparisons = [
			'above' => '/\b(?:total|amount|value|order\s+total|order\s+value)?\s*(?:over|above|greater\s+than|more\s+than|at\s+least|>=)\s+' . $amount_pattern . '\b/iu',
			'below' => '/\b(?:total|amount|value|order\s+total|order\s+value)?\s*(?:under|below|less\s+than|at\s+most|<=)\s+' . $amount_pattern . '\b/iu',
		];

		foreach ( $comparisons as $operator => $pattern ) {
			if ( ! preg_match( $pattern, $normalized, $matches ) ) {
				continue;
			}

			$text = sanitize_text_field( $matches[0] ?? '' );

			if ( ! self::money_filter_has_money_context( $normalized, $text ) ) {
				continue;
			}

			if ( self::money_filter_is_time_unit( $normalized, $text ) ) {
				continue;
			}

			$value = self::parse_money_amount( $matches[1] ?? '' );

			if ( null === $value ) {
				continue;
			}

			return [
				'operator' => $operator,
				'value'    => $value,
				'currency' => self::detect_money_currency( $normalized ),
				'text'     => $text,
			];
		}

		return [];
	}

	private static function parse_money_amount( $value ) {
		$value = str_replace( ',', '', trim( (string) $value ) );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$amount = (float) $value;

		if ( $amount <= 0 ) {
			return null;
		}

		return round( $amount, 2 );
	}

	private static function detect_money_currency( $normalized ) {
		if ( preg_match( '/\busd\b|\$/iu', (string) $normalized ) ) {
			return 'USD';
		}

		return '';
	}

	private static function money_filter_has_money_context( $normalized, $text ) {
		return (bool) preg_match( '/\$|\busd\b|\b(?:total|amount|value|high[- ]value|order\s+total|order\s+value)\b/iu', (string) $normalized . ' ' . (string) $text );
	}

	private static function money_filter_is_time_unit( $normalized, $text ) {
		$offset = strpos( (string) $normalized, (string) $text );

		if ( false === $offset ) {
			return false;
		}

		$tail = substr( (string) $normalized, $offset + strlen( (string) $text ), 16 );

		return (bool) preg_match( '/^\s*(?:hours?|hrs?|days?|weeks?|months?)\b/iu', $tail );
	}

	private static function detect_customer_identity( $command, $normalized ) {
		$command    = trim( (string) $command );
		$normalized = trim( (string) $normalized );

		if ( '' === $command || false === strpos( $normalized, 'order' ) ) {
			return [];
		}

		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $command, $matches ) ) {
			$email = sanitize_email( $matches[0] ?? '' );

			if ( '' !== $email ) {
				return [
					'type'  => 'email',
					'email' => $email,
					'text'  => sanitize_text_field( $matches[0] ?? '' ),
				];
			}
		}

		if ( preg_match( '/\bcustomer\s*(?:id|#)\s*:?\s*(\d{1,10})\b/i', $command, $matches ) ) {
			$customer_id = absint( $matches[1] ?? 0 );

			if ( $customer_id > 0 ) {
				return [
					'type'        => 'customer_id',
					'customer_id' => $customer_id,
					'text'        => sanitize_text_field( $matches[0] ?? '' ),
				];
			}
		}

		$phone_patterns = [
			'/\b(?:phone|tel|mobile)\s*:?\s*([+\d][\d\s().\-]{5,})/i',
			'/\borders?\s+(?:for|from|by)\s+([+\d][\d\s().\-]{7,})$/i',
		];

		foreach ( $phone_patterns as $pattern ) {
			if ( ! preg_match( $pattern, $command, $matches ) ) {
				continue;
			}

			$phone = self::sanitize_customer_identity_phone( $matches[1] ?? '' );

			if ( '' !== $phone ) {
				return [
					'type'  => 'phone',
					'phone' => $phone,
					'text'  => sanitize_text_field( $matches[1] ?? '' ),
				];
			}
		}

		$name_patterns = [
			'/\borders?\s+(?:for|from|by)\s+(?:customer\s+)?([a-zA-Z][a-zA-Z\s.\'\-]{1,80})$/i',
			'/\b([a-zA-Z][a-zA-Z\s.\'\-]{1,80})[\'’]s\s+orders?\b/i',
		];

		foreach ( $name_patterns as $pattern ) {
			if ( ! preg_match( $pattern, $command, $matches ) ) {
				continue;
			}

			$name = self::sanitize_customer_identity_name( $matches[1] ?? '' );

			if ( '' !== $name ) {
				return [
					'type'          => 'customer_name',
					'customer_name' => $name,
					'text'          => sanitize_text_field( $matches[1] ?? '' ),
				];
			}
		}

		return [];
	}

	private static function sanitize_customer_identity_phone( $value ) {
		$value  = sanitize_text_field( trim( (string) $value ) );
		$digits = preg_replace( '/\D+/', '', $value );

		if ( strlen( $digits ) < 7 ) {
			return '';
		}

		return $value;
	}

	private static function sanitize_customer_identity_name( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value, " \t\n\r\0\x0B'’\"" );

		if ( '' === $value ) {
			return '';
		}

		$blocked = [
			'customer',
			'orders',
			'order',
			'pending',
			'processing',
			'completed',
			'complete',
			'cancelled',
			'canceled',
			'refunded',
			'failed',
			'this customer',
			'that customer',
			'same customer',
		];

		return in_array( strtolower( $value ), $blocked, true ) ? '' : $value;
	}

	private static function detect_payment_method( $command, $normalized ) {
		$command    = trim( (string) $command );
		$normalized = trim( (string) $normalized );

		if ( '' === $command || false === strpos( $normalized, 'order' ) ) {
			return [];
		}

		$patterns = [
			'/\b(?:payment\s+method|payment|gateway|paid\s+by|pay\s+by|via)\s*:?\s*([a-zA-Z0-9_\-\s]{2,40})\b/i',
			'/\b(stripe|credit\s+card|card|visa|mastercard|paypal|pay\s+pal|cod|cash\s+on\s+delivery|cash|bacs|bank\s+transfer|direct\s+bank\s+transfer|wire\s+transfer|cheque|check)\s+orders?\b/i',
			'/\borders?\s+(?:paid\s+by|pay\s+by|via|with)\s+(stripe|credit\s+card|card|visa|mastercard|paypal|pay\s+pal|cod|cash\s+on\s+delivery|cash|bacs|bank\s+transfer|direct\s+bank\s+transfer|wire\s+transfer|cheque|check)\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $command, $matches ) ) {
				continue;
			}

			$text   = sanitize_text_field( $matches[1] ?? '' );
			$method = self::normalize_payment_method_value( $text );

			if ( '' === $method ) {
				continue;
			}

			return [
				'method' => $method,
				'text'   => $text,
			];
		}

		return [];
	}

	private static function normalize_payment_method_value( $value ) {
		$value = strtolower( trim( wp_strip_all_tags( (string) $value ) ) );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value, " \t\n\r\0\x0B'’\"" );

		if ( '' === $value ) {
			return '';
		}

		$map = [
			'stripe'               => 'stripe',
			'credit card'          => 'stripe',
			'card'                 => 'stripe',
			'visa'                 => 'stripe',
			'mastercard'           => 'stripe',
			'paypal'               => 'paypal',
			'pay pal'              => 'paypal',
			'cod'                  => 'cod',
			'cash on delivery'     => 'cod',
			'cash'                 => 'cod',
			'bacs'                 => 'bacs',
			'bank transfer'        => 'bacs',
			'direct bank transfer' => 'bacs',
			'wire transfer'        => 'bacs',
			'cheque'               => 'cheque',
			'check'                => 'cheque',
		];

		return $map[ $value ] ?? '';
	}

	private static function detect_shipping_state( $command, $normalized ) {
		$command    = trim( (string) $command );
		$normalized = trim( (string) $normalized );

		if ( '' === $command || false === strpos( $normalized, 'order' ) ) {
			return [];
		}

		$patterns = [
			'/\b(?:shipping\s+state|ship(?:ping)?\s+to|shipping\s+to|deliver(?:y|ing)?\s+to)\s*:?\s*([a-zA-Z][a-zA-Z\s.\'-]{1,40})\b/i',
			'/\borders?\s+(?:shipping|ship(?:ping)?|deliver(?:y|ing)?)\s+(?:to|in)\s+([a-zA-Z][a-zA-Z\s.\'-]{1,40})\b/i',
			'/\borders?\s+in\s+([A-Z]{2})\b/',
		];

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $command, $matches ) ) {
				continue;
			}

			$text  = self::sanitize_shipping_state_text( $matches[1] ?? '' );
			$state = self::normalize_us_state_value( $text );

			if ( '' === $state ) {
				continue;
			}

			return [
				'country' => 'US',
				'state'   => $state,
				'text'    => $text,
			];
		}

		return [];
	}

	private static function sanitize_shipping_state_text( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value, " \t\n\r\0\x0B'’\"" );
		$value = preg_replace( '/\s+(?:orders?|order|this\s+week|today|yesterday)$/i', '', $value );

		return trim( $value );
	}

	private static function normalize_us_state_value( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^[A-Z]{2}$/', $value ) ) {
			return strtoupper( $value );
		}

		$key = strtolower( preg_replace( '/[^a-z]+/i', ' ', $value ) );
		$key = trim( preg_replace( '/\s+/', ' ', $key ) );

		$map = [
			'alabama' => 'AL',
			'alaska' => 'AK',
			'arizona' => 'AZ',
			'arkansas' => 'AR',
			'california' => 'CA',
			'colorado' => 'CO',
			'connecticut' => 'CT',
			'delaware' => 'DE',
			'florida' => 'FL',
			'georgia' => 'GA',
			'hawaii' => 'HI',
			'idaho' => 'ID',
			'illinois' => 'IL',
			'indiana' => 'IN',
			'iowa' => 'IA',
			'kansas' => 'KS',
			'kentucky' => 'KY',
			'louisiana' => 'LA',
			'maine' => 'ME',
			'maryland' => 'MD',
			'massachusetts' => 'MA',
			'michigan' => 'MI',
			'minnesota' => 'MN',
			'mississippi' => 'MS',
			'missouri' => 'MO',
			'montana' => 'MT',
			'nebraska' => 'NE',
			'nevada' => 'NV',
			'new hampshire' => 'NH',
			'new jersey' => 'NJ',
			'new mexico' => 'NM',
			'new york' => 'NY',
			'north carolina' => 'NC',
			'north dakota' => 'ND',
			'ohio' => 'OH',
			'oklahoma' => 'OK',
			'oregon' => 'OR',
			'pennsylvania' => 'PA',
			'rhode island' => 'RI',
			'south carolina' => 'SC',
			'south dakota' => 'SD',
			'tennessee' => 'TN',
			'texas' => 'TX',
			'utah' => 'UT',
			'vermont' => 'VT',
			'virginia' => 'VA',
			'washington' => 'WA',
			'west virginia' => 'WV',
			'wisconsin' => 'WI',
			'wyoming' => 'WY',
			'district of columbia' => 'DC',
			'washington dc' => 'DC',
		];

		return $map[ $key ] ?? '';
	}

	private static function detect_query_action( $command, $normalized, $context = [] ) {
		if ( '' === $normalized || false === strpos( $normalized, 'order' ) ) {
			return [];
		}

		$limit = self::detect_query_action_limit( $command, $normalized );

		if ( $limit <= 0 ) {
			return [];
		}

		$scope_is_latest = (bool) preg_match(
			'/\b(?:latest|recent|last|newest)\s+\d{1,2}\s+orders?\b|\b(?:hold|put|place|set|move|mark|change|update)\s+(?:the\s+)?(?:latest|recent|last|newest)\s+\d{1,2}\s+orders?\b/i',
			$normalized
		);

		if ( ! $scope_is_latest ) {
			return [];
		}

		$has_read_intent = (bool) preg_match(
			'/\b(?:show|list|search|find|get|view)\b/i',
			$normalized
		);
		$is_on_hold_status_query = preg_match(
			'/\b(?:on-hold|on hold)\s+orders?\b/i',
			$normalized
		) || preg_match(
			'/\border(?:s)?\s+(?:on-hold|on hold)\b/i',
			$normalized
		);

		if ( $has_read_intent && $is_on_hold_status_query && ! preg_match( '/^\s*(?:hold|put|place|set|move|mark)\b/i', $normalized ) ) {
			return [];
		}

		if (
			preg_match( '/^\s*hold\b/i', $normalized ) ||
			(
				preg_match( '/\b(?:put|place|set|move|mark)\b/i', $normalized ) &&
				preg_match( '/\b(?:on-hold|on hold)\b/i', $normalized )
			)
		) {
			return [
				'scope'         => 'latest',
				'limit'         => $limit,
				'action'        => 'orders.hold',
				'target_status' => 'on-hold',
				'search_status' => [ 'pending', 'processing', 'on-hold' ],
			];
		}

		if (
			preg_match( '/\b(?:change|mark|move|set|update)\b/i', $normalized ) &&
			preg_match( '/\b(?:to|as|status)\b/i', $normalized )
		) {
			$status = '';

			if ( class_exists( 'YoRo_Order_Helper' ) ) {
				$status = YoRo_Order_Helper::detect_status_from_text( $normalized );

				if ( empty( $status ) ) {
					$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
				}

				if ( ! empty( $status ) ) {
					$status = YoRo_Order_Helper::normalize_status_slug( $status );
				}
			}

			if ( ! self::is_valid_status( $status ) ) {
				return [];
			}

			$note = self::extract_note( $command );

			return [
				'scope'         => 'latest',
				'limit'         => $limit,
				'action'        => '' !== $note ? 'orders.bulk_update_status_with_note' : 'orders.update_status',
				'target_status' => $status,
				'note'          => $note,
				'search_status' => [],
			];
		}

		return [];
	}

	private static function detect_write_intent( $command, $normalized ) {
		if ( '' === $normalized || false === strpos( $normalized, 'order' ) ) {
			return [];
		}

		if ( preg_match( '/^\s*(?:(?:add|leave)\s+(?:a\s+)?note|note)\b/i', $normalized ) ) {
			$note = self::extract_note( $command );

			return [
				'scope'         => 'unspecified_selection',
				'quantity'      => null,
				'action'        => 'orders.add_note',
				'target_status' => '',
				'note'          => $note,
				'missing'       => [],
			];
		}

		if ( ! preg_match( '/^\s*(?:refund|cancel|complete|hold|update|change|mark|move|set|put|place)\b/i', $normalized ) ) {
			return [];
		}

		$quantity = self::detect_write_intent_quantity( $command );

		$action = '';
		$status = '';

		if ( preg_match( '/^\s*refund(?:ed)?\b/i', $normalized ) ) {
			$action = 'orders.update_status';
			$status = 'refunded';
		} elseif ( preg_match( '/^\s*cancel(?:led|ed)?\b/i', $normalized ) ) {
			$action = 'orders.update_status';
			$status = 'cancelled';
		} elseif ( preg_match( '/^\s*complete(?:d)?\b/i', $normalized ) ) {
			$action = 'orders.update_status';
			$status = 'completed';
		} elseif (
			preg_match( '/^\s*(?:hold|put|place)\b/i', $normalized ) ||
			(
				preg_match( '/^\s*(?:set|move|mark)\b/i', $normalized ) &&
				preg_match( '/\b(?:on-hold|on hold|hold)\b/i', $normalized )
			)
		) {
			$action = 'orders.hold';
			$status = 'on-hold';
		} elseif ( preg_match( '/^\s*(?:update|change|mark|move|set)\b/i', $normalized ) ) {
			if ( class_exists( 'YoRo_Order_Helper' ) ) {
				$status = YoRo_Order_Helper::detect_status_from_text( $normalized );

				if ( empty( $status ) ) {
					$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
				}

				if ( ! empty( $status ) ) {
					$status = YoRo_Order_Helper::normalize_status_slug( $status );
				}
			}

			if ( ! self::is_valid_status( $status ) ) {
				return [];
			}

			$action = 'on-hold' === $status ? 'orders.hold' : 'orders.update_status';
		}

		if ( '' === $action ) {
			return [];
		}

		if ( 'orders.update_status' === $action && ! self::is_valid_status( $status ) ) {
			return [];
		}

		return [
			'scope'         => 'unspecified_selection',
			'quantity'      => $quantity > 0 ? $quantity : null,
			'action'        => $action,
			'target_status' => sanitize_key( $status ),
			'missing'       => [ 'order_ids' ],
		];
	}

	private static function detect_write_missing_target( $write_intent, $explicit_order_ids, $query_action = [] ) {
		$write_intent       = is_array( $write_intent ) ? $write_intent : [];
		$explicit_order_ids = is_array( $explicit_order_ids ) ? array_filter( array_map( 'absint', $explicit_order_ids ) ) : [];
		$query_action       = is_array( $query_action ) ? $query_action : [];

		if ( empty( $write_intent ) || ! empty( $query_action ) ) {
			return [];
		}

		$action  = sanitize_text_field( $write_intent['action'] ?? '' );
		$missing = [];

		if ( empty( $explicit_order_ids ) ) {
			$missing[] = 'order_ids';
		}

		if ( 'orders.add_note' === $action && '' === sanitize_textarea_field( $write_intent['note'] ?? '' ) ) {
			$missing[] = 'note';
		}

		if ( empty( $missing ) ) {
			return [];
		}

		return [
			'action'        => $action,
			'target_status' => sanitize_key( $write_intent['target_status'] ?? '' ),
			'quantity'      => ! empty( $write_intent['quantity'] ) ? absint( $write_intent['quantity'] ) : null,
			'missing'       => array_values( array_unique( $missing ) ),
			'reason'        => in_array( 'order_ids', $missing, true ) ? 'missing_explicit_order_ids' : 'missing_required_content',
		];
	}

	private static function detect_workflow_intent( $command, $normalized, $explicit_order_ids = [] ) {
		$command            = is_string( $command ) ? trim( $command ) : '';
		$normalized         = is_string( $normalized ) ? trim( $normalized ) : '';
		$text               = strtolower( trim( $command . ' ' . $normalized ) );
		$explicit_order_ids = is_array( $explicit_order_ids ) ? array_values( array_filter( array_map( 'absint', $explicit_order_ids ) ) ) : [];

		if ( '' === $text || empty( $explicit_order_ids ) ) {
			return [];
		}

		if (
			( preg_match( '/\b(?:refund|refundable)\b/i', $text ) || preg_match( '/\b(?:hoàn tiền|hoan tien)\b/u', $text ) )
		) {
			$delivery_mode = self::detect_delivery_mode( $text );
			$requires_workflow = self::requires_refund_workflow( $text );
			$is_direct_multi_write = count( $explicit_order_ids ) > 1 && self::looks_like_direct_multi_order_refund_write( $text );
			$action = $is_direct_multi_write ? 'orders.update_status' : ( $requires_workflow ? 'workflow.refund_review' : 'orders.refund_preview' );

			return [
				'kind'               => 'refund_review',
				'action'             => $action,
				'target_type'        => $is_direct_multi_write ? 'order_list_explicit' : 'order_workflow_explicit',
				'operation_hint'     => $is_direct_multi_write ? 'explicit_refund_write' : ( $requires_workflow ? 'workflow' : 'refund_preview' ),
				'delivery_mode'      => $delivery_mode,
				'target_status'      => $is_direct_multi_write ? 'refunded' : '',
				'direct_write'       => $is_direct_multi_write,
				'requires_workflow'  => $requires_workflow,
			];
		}

		if (
			( preg_match( '/\b(?:verify|verification|confirm)\b/i', $text ) || preg_match( '/\b(?:xác minh|xac minh|xác nhận|xac nhan)\b/u', $text ) ) &&
			( preg_match( '/\b(?:address|identity|details|shipping|ship|fulfillment)\b/i', $text ) || preg_match( '/\b(?:địa chỉ|dia chi|giao hàng|giao hang)\b/u', $text ) )
		) {
			return [
				'kind'                => 'verify_customer_before_fulfillment',
				'action'              => 'workflow.verify_customer_before_fulfillment',
				'target_type'         => 'order_workflow_explicit',
				'operation_hint'      => 'workflow',
				'email_delivery_mode' => self::detect_delivery_mode( $text ),
			];
		}

		return [];
	}

	private static function detect_delivery_mode( $text ) {
		$text = is_string( $text ) ? strtolower( trim( $text ) ) : '';

		if ( preg_match( '/\b(?:save(?:\s+it)?(?:\s+as)?\s+draft|save\s+as\s+draft|draft)\b/i', $text ) ) {
			return 'save_draft';
		}

		if ( preg_match( '/\b(?:send|email customer|notify customer)\b/i', $text ) || preg_match( '/\b(?:gửi|gui)\b/u', $text ) ) {
			return 'send';
		}

		return 'preview';
	}

	private static function requires_refund_workflow( $text ) {
		$text = is_string( $text ) ? strtolower( trim( $text ) ) : '';

		if ( '' === $text ) {
			return false;
		}

		return (bool) (
			preg_match( '/\brefund\s+workflow\b/i', $text ) ||
			preg_match( '/\b(?:follow-up|follow up)\b/i', $text ) ||
			preg_match( '/\b(?:email|draft|save(?:\s+it)?(?:\s+as)?\s+draft|save\s+as\s+draft|send|notify customer|email customer)\b/i', $text ) ||
			preg_match( '/\b(?:gửi|gui|nháp|nhap)\b/u', $text )
		);
	}

	private static function looks_like_direct_multi_order_refund_write( $text ) {
		$text = is_string( $text ) ? strtolower( trim( $text ) ) : '';

		if ( '' === $text || ! preg_match( '/^\s*(?:refund|refunded|hoàn tiền|hoan tien)\b/u', $text ) ) {
			return false;
		}

		return ! preg_match( '/\b(?:preview|review|check|inspect|look at|open|prepare|workflow|follow-up|follow up|email|draft|send|notify customer|xem trước|xem truoc)\b/u', $text );
	}

	private static function detect_write_intent_quantity( $command ) {
		$patterns = [
			'/^\s*(?:refund|cancel|complete|hold)\w*\s+(\d{1,2})\s+orders?\b/i',
			'/^\s*(?:update|change|mark|move|set|put|place)\s+(\d{1,2})\s+orders?\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, (string) $command, $matches ) ) {
				return min( max( absint( $matches[1] ), 1 ), 50 );
			}
		}

		return 0;
	}

	private static function detect_query_action_limit( $command, $normalized ) {
		$patterns = [
			'/\b(?:hold|put|place|set|move|mark|change|update)\s+(?:the\s+)?(?:latest|recent|last|newest)\s+(\d{1,2})\s+orders?\b/i',
			'/\b(?:latest|recent|last|newest)\s+(\d{1,2})\s+orders?\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, (string) $command, $matches ) ) {
				return min( max( absint( $matches[1] ), 1 ), 50 );
			}
		}

		return 0;
	}

	private static function extract_note( $command ) {
		$command = (string) $command;

		if ( preg_match( '/\b(?:add|leave)\s+(?:a\s+)?note\s+to\s+(?:this|that|the|current)\s+order\s*:\s*(.+)$/i', $command, $matches ) ) {
			return sanitize_textarea_field( trim( $matches[1] ?? '' ) );
		}

		if ( preg_match( '/\b(?:add|leave)\s+(?:a\s+)?note\s+to\s+order\s+#?\d+\s*:\s*(.+)$/i', $command, $matches ) ) {
			return sanitize_textarea_field( trim( $matches[1] ?? '' ) );
		}

		if ( preg_match( '/\b(?:add|leave)\s+(?:a\s+)?note\s*:\s*(.+)$/i', $command, $matches ) ) {
			return sanitize_textarea_field( trim( $matches[1] ?? '' ) );
		}

		if ( preg_match( '/\bwith\s+note\s*:?\s*(.+)$/i', $command, $matches ) ) {
			return sanitize_textarea_field( trim( $matches[1] ?? '' ) );
		}

		if ( preg_match( '/\bnote\s*:\s*(.+)$/i', $command, $matches ) ) {
			return sanitize_textarea_field( trim( $matches[1] ?? '' ) );
		}

		return '';
	}

	private static function compact_query_action( $query_action ) {
		$query_action = is_array( $query_action ) ? $query_action : [];

		if ( empty( $query_action ) ) {
			return [];
		}

		return [
			'scope'         => sanitize_key( $query_action['scope'] ?? '' ),
			'limit'         => isset( $query_action['limit'] ) ? absint( $query_action['limit'] ) : null,
			'action'        => sanitize_text_field( $query_action['action'] ?? '' ),
			'target_status' => sanitize_key( $query_action['target_status'] ?? '' ),
			'note'          => sanitize_textarea_field( $query_action['note'] ?? '' ),
			'search_status' => isset( $query_action['search_status'] ) && is_array( $query_action['search_status'] )
				? array_values( array_filter( array_map( 'sanitize_key', $query_action['search_status'] ) ) )
				: [],
		];
	}

	private static function compact_age_filter( $age_filter ) {
		$age_filter = is_array( $age_filter ) ? $age_filter : [];
		$hours      = isset( $age_filter['hours'] ) ? absint( $age_filter['hours'] ) : 0;

		if ( $hours <= 0 ) {
			return [];
		}

		return [
			'operator' => 'older_than',
			'value'    => isset( $age_filter['value'] ) ? absint( $age_filter['value'] ) : 0,
			'unit'     => sanitize_key( $age_filter['unit'] ?? '' ),
			'hours'    => min( max( $hours, 1 ), 8760 ),
		];
	}

	private static function compact_date_filter( $date_filter ) {
		$date_filter = is_array( $date_filter ) ? $date_filter : [];
		$window_key  = sanitize_key( $date_filter['window_key'] ?? '' );

		if ( '' === $window_key ) {
			return [];
		}

		return [
			'type'       => 'window',
			'window_key' => $window_key,
			'text'       => sanitize_text_field( $date_filter['text'] ?? '' ),
		];
	}

	private static function compact_money_filter( $money_filter ) {
		$money_filter = is_array( $money_filter ) ? $money_filter : [];
		$operator     = sanitize_key( $money_filter['operator'] ?? '' );

		if ( '' === $operator ) {
			return [];
		}

		$compact = [
			'operator' => $operator,
			'currency' => sanitize_text_field( $money_filter['currency'] ?? '' ),
			'text'     => sanitize_text_field( $money_filter['text'] ?? '' ),
		];

		if ( 'between' === $operator ) {
			$compact['min'] = isset( $money_filter['min'] ) ? round( (float) $money_filter['min'], 2 ) : 0.0;
			$compact['max'] = isset( $money_filter['max'] ) ? round( (float) $money_filter['max'], 2 ) : 0.0;
		} else {
			$compact['value'] = isset( $money_filter['value'] ) ? round( (float) $money_filter['value'], 2 ) : 0.0;
		}

		return $compact;
	}

	private static function compact_quantity_scope( $quantity_scope ) {
		$quantity_scope = is_array( $quantity_scope ) ? $quantity_scope : [];
		$scope          = sanitize_key( $quantity_scope['scope'] ?? '' );
		$value          = isset( $quantity_scope['value'] ) ? absint( $quantity_scope['value'] ) : 0;

		if ( '' === $scope || $value <= 0 ) {
			return [];
		}

		return [
			'scope'  => $scope,
			'value'  => min( max( $value, 1 ), 50 ),
			'source' => sanitize_text_field( $quantity_scope['source'] ?? '' ),
		];
	}

	private static function compact_customer_identity( $customer_identity ) {
		$customer_identity = is_array( $customer_identity ) ? $customer_identity : [];
		$type              = sanitize_key( $customer_identity['type'] ?? '' );

		if ( '' === $type ) {
			return [];
		}

		$compact = [
			'type' => $type,
			'text' => sanitize_text_field( $customer_identity['text'] ?? '' ),
		];

		if ( 'email' === $type && ! empty( $customer_identity['email'] ) ) {
			$compact['email'] = sanitize_email( $customer_identity['email'] );
		} elseif ( 'phone' === $type && ! empty( $customer_identity['phone'] ) ) {
			$compact['phone'] = sanitize_text_field( $customer_identity['phone'] );
		} elseif ( 'customer_id' === $type && ! empty( $customer_identity['customer_id'] ) ) {
			$compact['customer_id'] = absint( $customer_identity['customer_id'] );
		} elseif ( 'customer_name' === $type && ! empty( $customer_identity['customer_name'] ) ) {
			$compact['customer_name'] = sanitize_text_field( $customer_identity['customer_name'] );
		}

		return count( $compact ) > 2 ? $compact : [];
	}

	private static function compact_payment_method( $payment_method ) {
		$payment_method = is_array( $payment_method ) ? $payment_method : [];
		$method         = sanitize_key( $payment_method['method'] ?? '' );

		if ( '' === $method ) {
			return [];
		}

		return [
			'method' => $method,
			'text'   => sanitize_text_field( $payment_method['text'] ?? '' ),
		];
	}

	private static function compact_shipping_state( $shipping_state ) {
		$shipping_state = is_array( $shipping_state ) ? $shipping_state : [];
		$state          = strtoupper( sanitize_text_field( $shipping_state['state'] ?? '' ) );

		if ( '' === $state || ! preg_match( '/^[A-Z]{2}$/', $state ) ) {
			return [];
		}

		return [
			'country' => strtoupper( sanitize_text_field( $shipping_state['country'] ?? 'US' ) ),
			'state'   => $state,
			'text'    => sanitize_text_field( $shipping_state['text'] ?? '' ),
		];
	}

	private static function compact_write_intent( $write_intent ) {
		$write_intent = is_array( $write_intent ) ? $write_intent : [];

		if ( empty( $write_intent ) ) {
			return [];
		}

		return [
			'scope'         => sanitize_key( $write_intent['scope'] ?? '' ),
			'quantity'      => isset( $write_intent['quantity'] ) ? absint( $write_intent['quantity'] ) : null,
			'action'        => sanitize_text_field( $write_intent['action'] ?? '' ),
			'target_status' => sanitize_key( $write_intent['target_status'] ?? '' ),
			'missing'       => isset( $write_intent['missing'] ) && is_array( $write_intent['missing'] )
				? array_values( array_filter( array_map( 'sanitize_key', $write_intent['missing'] ) ) )
				: [],
		];
	}

	private static function compact_workflow_intent( $workflow_intent ) {
		$workflow_intent = is_array( $workflow_intent ) ? $workflow_intent : [];
		$kind            = sanitize_key( $workflow_intent['kind'] ?? '' );
		$action          = sanitize_text_field( $workflow_intent['action'] ?? '' );

		if ( '' === $kind || '' === $action ) {
			return [];
		}

		$compact = [
			'kind'           => $kind,
			'action'         => $action,
			'operation_hint' => sanitize_key( $workflow_intent['operation_hint'] ?? '' ),
			'target_type'    => sanitize_key( $workflow_intent['target_type'] ?? '' ),
		];

		if ( ! empty( $workflow_intent['delivery_mode'] ) ) {
			$compact['delivery_mode'] = sanitize_key( $workflow_intent['delivery_mode'] );
		}

		if ( ! empty( $workflow_intent['email_delivery_mode'] ) ) {
			$compact['email_delivery_mode'] = sanitize_key( $workflow_intent['email_delivery_mode'] );
		}

		if ( ! empty( $workflow_intent['target_status'] ) ) {
			$compact['target_status'] = sanitize_key( $workflow_intent['target_status'] );
		}

		if ( array_key_exists( 'direct_write', $workflow_intent ) ) {
			$compact['direct_write'] = ! empty( $workflow_intent['direct_write'] );
		}

		if ( array_key_exists( 'requires_workflow', $workflow_intent ) ) {
			$compact['requires_workflow'] = ! empty( $workflow_intent['requires_workflow'] );
		}

		return array_filter(
			$compact,
			static function( $value ) {
				return '' !== $value && [] !== $value && null !== $value;
			}
		);
	}

	private static function compact_write_missing_target( $write_missing_target ) {
		$write_missing_target = is_array( $write_missing_target ) ? $write_missing_target : [];

		if ( empty( $write_missing_target ) ) {
			return [];
		}

		return [
			'action'        => sanitize_text_field( $write_missing_target['action'] ?? '' ),
			'target_status' => sanitize_key( $write_missing_target['target_status'] ?? '' ),
			'quantity'      => isset( $write_missing_target['quantity'] ) && null !== $write_missing_target['quantity'] ? absint( $write_missing_target['quantity'] ) : null,
			'missing'       => isset( $write_missing_target['missing'] ) && is_array( $write_missing_target['missing'] )
				? array_values( array_filter( array_map( 'sanitize_key', $write_missing_target['missing'] ) ) )
				: [],
			'reason'        => sanitize_key( $write_missing_target['reason'] ?? '' ),
		];
	}

	private static function is_valid_status( $status ) {
		if ( ! class_exists( 'YoRo_Order_Helper' ) ) {
			return false;
		}

		return YoRo_Order_Helper::is_valid_order_status( $status );
	}

	private static function extract_explicit_order_ids( $command ) {
		if ( class_exists( 'YoRo_Order_Helper' ) ) {
			return array_values(
				array_filter(
					array_unique(
						array_map(
							'absint',
							YoRo_Order_Helper::extract_order_ids_from_text( (string) $command )
						)
					)
				)
			);
		}

		if ( ! preg_match_all( '/\border\s*#?\s*(\d{2,})\b/i', (string) $command, $matches ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $matches[1] ?? [] )
				)
			)
		);
	}

	private static function has_contextual_order_reference( $normalized ) {
		return (bool) preg_match(
			'/\b(?:this|that|same|current)\s+order\b|\b(?:it|that one|this one)\b/u',
			$normalized
		);
	}

	private static function has_recent_order_list_reference( $normalized ) {
		return (bool) preg_match(
			'/\b(?:these|those|them|the above|that list|these orders|those orders|recent orders|last orders)\b/u',
			$normalized
		);
	}

	private static function normalize_command( $command ) {
		$command = strtolower( trim( wp_strip_all_tags( (string) $command ) ) );
		$command = str_replace( [ '’', '“', '”' ], [ "'", '"', '"' ], $command );
		$command = preg_replace( '/\s+/', ' ', $command );

		return trim( (string) $command );
	}
}
