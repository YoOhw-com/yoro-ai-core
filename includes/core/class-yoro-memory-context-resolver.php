<?php

defined( 'ABSPATH' ) || exit;

class YoRo_Memory_Context_Resolver {

	public static function resolve( $command, $context = [] ) {
		$context = is_array( $context ) ? $context : [];
		$command = is_string( $command ) ? trim( $command ) : '';
		$normalized = function_exists( 'remove_accents' )
			? strtolower( remove_accents( $command ) )
			: strtolower( $command );

		$reference = self::build_empty_reference();
		$workflow  = isset( $context['current_workflow'] ) && is_array( $context['current_workflow'] )
			? $context['current_workflow']
			: [];

		$workflow_reference = self::resolve_from_active_workflow( $workflow );

		if ( ! empty( $workflow_reference['resolved'] ) ) {
			$reference = $workflow_reference;
		} else {
			$explicit_order_ids = class_exists( 'YoRo_Order_Helper' )
				? YoRo_Order_Helper::extract_order_ids_from_text( $command )
				: [];

			if ( ! empty( $explicit_order_ids ) ) {
				$reference = self::order_reference(
					$explicit_order_ids,
					'explicit_command',
					1.0,
					'The command explicitly names the target order.'
				);
			} elseif ( self::looks_like_contextual_order_ordinal_reference( $normalized ) ) {
				$reference = self::resolve_contextual_order_ordinal_reference( $normalized, $context );
			} elseif ( self::looks_like_contextual_order_list_reference( $normalized ) ) {
				$reference = self::resolve_contextual_order_list_reference( $context );
			} elseif ( self::looks_like_contextual_order_reference( $normalized ) ) {
				$reference = self::resolve_contextual_order_reference( $context );
			}
		}

		$context['memory_reference'] = $reference;
		$context['reference_resolution'] = $reference;

		if ( ! empty( $reference['resolved'] ) && 'order' === $reference['target_type'] && ! empty( $reference['target_ids'] ) ) {
			$order_ids = array_values(
				array_filter(
					array_unique(
						array_map( 'absint', $reference['target_ids'] )
					)
				)
			);

			if ( ! empty( $order_ids ) ) {
				$context['resolved_reference'] = $reference;
				$context['resolved_order_ids'] = $order_ids;
			}
		}

		$context['memory_context'] = [
			'active_workflow' => self::compact_workflow( $workflow ),
			'active_entity'   => self::compact_active_entity( $context ),
			'last_result'     => self::compact_last_result( $context ),
			'reference'       => $reference,
		];

		return $context;
	}

	private static function resolve_from_active_workflow( $workflow ) {
		if ( empty( $workflow ) || ! is_array( $workflow ) ) {
			return self::build_empty_reference();
		}

		if ( 'collect_missing_params' !== sanitize_key( $workflow['step'] ?? '' ) ) {
			return self::build_empty_reference();
		}

		$params = isset( $workflow['params'] ) && is_array( $workflow['params'] )
			? $workflow['params']
			: [];

		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = $params['order_ids'];
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ $params['order_id'] ];
		} elseif ( ! empty( $workflow['order_id'] ) ) {
			$order_ids = [ $workflow['order_id'] ];
		}

		if ( empty( $order_ids ) ) {
			return self::build_empty_reference();
		}

		return self::order_reference(
			$order_ids,
			'active_workflow',
			1.0,
			'The active workflow is waiting for missing parameters and already owns the target order.'
		);
	}

	private static function resolve_contextual_order_reference( $context ) {
		$entity_focus = isset( $context['entity_focus'] ) && is_array( $context['entity_focus'] )
			? $context['entity_focus']
			: [];

		if (
			'order' === sanitize_key( $entity_focus['type'] ?? '' ) &&
			! empty( $entity_focus['id'] )
		) {
			return self::order_reference(
				[ $entity_focus['id'] ],
				'entity_focus',
				0.85,
				'The command uses a contextual order reference and the active entity focus is an order.'
			);
		}

		if ( ! empty( $context['current_order_id'] ) ) {
			return self::order_reference(
				[ $context['current_order_id'] ],
				'current_order',
				0.78,
				'The command uses a contextual order reference and there is a current order in memory.'
			);
		}

		if ( ! empty( $context['last_referenced_order_id'] ) ) {
			return self::order_reference(
				[ $context['last_referenced_order_id'] ],
				'last_referenced_order',
				0.7,
				'The command uses a contextual order reference and there is a recently referenced order.'
			);
		}

		return self::build_empty_reference();
	}

	private static function resolve_contextual_order_list_reference( $context ) {
		$order_ids = self::last_order_ids_from_context( $context );

		if ( empty( $order_ids ) ) {
			return self::build_empty_reference();
		}

		return self::order_reference(
			$order_ids,
			'last_order_list',
			0.92,
			'The command uses a plural contextual order reference and there is a recent order list in memory.'
		);
	}

	private static function resolve_contextual_order_ordinal_reference( $normalized, $context ) {
		$order_ids = self::last_order_ids_from_context( $context );

		if ( empty( $order_ids ) ) {
			return self::build_empty_reference();
		}

		$index = self::resolve_order_list_reference_index( $normalized, count( $order_ids ) );

		if ( null === $index || empty( $order_ids[ $index ] ) ) {
			return self::build_empty_reference();
		}

		return self::order_reference(
			[ $order_ids[ $index ] ],
			'last_order_list_ordinal',
			0.94,
			'The command uses an ordinal reference to a recent order list.'
		);
	}

	private static function last_order_ids_from_context( $context ) {
		$context = is_array( $context ) ? $context : [];
		$order_ids = [];

		if ( ! empty( $context['last_orders'] ) && is_array( $context['last_orders'] ) ) {
			foreach ( $context['last_orders'] as $order ) {
				if ( ! is_array( $order ) ) {
					continue;
				}

				$order_id = absint( $order['id'] ?? 0 );

				if ( $order_id > 0 ) {
					$order_ids[] = $order_id;
				}
			}
		}

		if ( empty( $order_ids ) && ! empty( $context['last_order_ids'] ) && is_array( $context['last_order_ids'] ) ) {
			$order_ids = array_map( 'absint', $context['last_order_ids'] );
		}

		return array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $order_ids )
				)
			)
		);
	}

	private static function resolve_order_list_reference_index( $normalized, $available_count ) {
		$available_count = absint( $available_count );

		if ( $available_count <= 0 ) {
			return null;
		}

		$ordinal_map = [
			'first'   => 0,
			'second'  => 1,
			'third'   => 2,
			'fourth'  => 3,
			'fifth'   => 4,
			'sixth'   => 5,
			'seventh' => 6,
			'eighth'  => 7,
			'ninth'   => 8,
			'tenth'   => 9,
		];

		foreach ( $ordinal_map as $word => $index ) {
			if ( preg_match( '/\b(?:the\s+)?' . preg_quote( $word, '/' ) . '\s+(?:one|order|item|result)\b/i', $normalized ) ) {
				return $index < $available_count ? $index : null;
			}
		}

		if ( preg_match( '/\b(?:the\s+)?last\s+(?:one|order|item|result)\b/i', $normalized ) ) {
			return $available_count - 1;
		}

		$vietnamese_map = [
			0 => [ 'dau tien', 'thu nhat', 'thu 1', 'so 1' ],
			1 => [ 'thu hai', 'thu 2', 'so 2' ],
			2 => [ 'thu ba', 'thu 3', 'so 3' ],
			3 => [ 'thu tu', 'thu 4', 'so 4' ],
			4 => [ 'thu nam', 'thu 5', 'so 5' ],
			5 => [ 'thu sau', 'thu 6', 'so 6' ],
			6 => [ 'thu bay', 'thu 7', 'so 7' ],
			7 => [ 'thu tam', 'thu 8', 'so 8' ],
			8 => [ 'thu chin', 'thu 9', 'so 9' ],
			9 => [ 'thu muoi', 'thu 10', 'so 10' ],
		];

		foreach ( $vietnamese_map as $index => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( '/\b(?:(?:don hang|ket qua|cai|don|muc)\s+)?' . preg_quote( $pattern, '/' ) . '\b/i', $normalized ) ) {
					return $index < $available_count ? $index : null;
				}
			}
		}

		if ( preg_match( '/\b(?:(?:don hang|ket qua|cai|don|muc)\s+)?cuoi cung\b/i', $normalized ) ) {
			return $available_count - 1;
		}

		if ( preg_match( '/\b(\d+)(?:st|nd|rd|th)\s+(?:one|order|item|result)\b/i', $normalized, $matches ) ) {
			$index = max( 1, absint( $matches[1] ?? 0 ) ) - 1;

			return $index < $available_count ? $index : null;
		}

		return null;
	}

	private static function looks_like_contextual_order_list_reference( $normalized ) {
		if ( '' === $normalized ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:all\s+(?:of\s+)?(?:them|these|those)|them|these|those|these\s+orders|those\s+orders|selected\s+orders|all\s+orders)\b/i',
			$normalized
		);
	}

	private static function looks_like_contextual_order_reference( $normalized ) {
		if ( '' === $normalized ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(this|that|same|current|selected|opened)\s+order\b|\b(this|that|same)\s+one\b|\bit\b/i',
			$normalized
		);
	}

	private static function looks_like_contextual_order_ordinal_reference( $normalized ) {
		if ( '' === $normalized ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:the\s+)?(?:first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|last)\s+(?:one|order|item|result)\b|\b\d+(?:st|nd|rd|th)\s+(?:one|order|item|result)\b|\b(?:(?:don hang|ket qua|cai|don|muc)\s+)?(?:dau tien|thu nhat|thu hai|thu ba|thu tu|thu nam|thu sau|thu bay|thu tam|thu chin|thu muoi|thu\s+\d+|so\s+\d+|cuoi cung)\b/i',
			$normalized
		);
	}

	private static function order_reference( $order_ids, $source, $confidence, $reason ) {
		$order_ids = array_values(
			array_filter(
				array_unique(
					array_map( 'absint', (array) $order_ids )
				)
			)
		);

		if ( empty( $order_ids ) ) {
			return self::build_empty_reference();
		}

		return [
			'resolved'               => true,
			'target_type'            => 'order',
			'target_ids'             => $order_ids,
			'source'                 => sanitize_key( $source ),
			'confidence'             => (float) $confidence,
			'reason'                 => sanitize_text_field( $reason ),
			'requires_clarification' => false,
		];
	}

	private static function build_empty_reference() {
		return [
			'resolved'               => false,
			'target_type'            => '',
			'target_ids'             => [],
			'source'                 => 'none',
			'confidence'             => 0.0,
			'reason'                 => '',
			'requires_clarification' => false,
		];
	}

	private static function compact_workflow( $workflow ) {
		if ( empty( $workflow ) || ! is_array( $workflow ) ) {
			return [];
		}

		return [
			'id'      => sanitize_text_field( $workflow['id'] ?? '' ),
			'type'    => sanitize_key( $workflow['type'] ?? '' ),
			'action'  => sanitize_text_field( $workflow['action'] ?? '' ),
			'step'    => sanitize_key( $workflow['step'] ?? '' ),
			'order_id' => absint( $workflow['order_id'] ?? 0 ),
			'missing' => isset( $workflow['missing'] ) && is_array( $workflow['missing'] )
				? array_values( array_filter( array_map( 'sanitize_key', $workflow['missing'] ) ) )
				: [],
		];
	}

	private static function compact_active_entity( $context ) {
		$entity_focus = isset( $context['entity_focus'] ) && is_array( $context['entity_focus'] )
			? $context['entity_focus']
			: [];

		return [
			'type' => sanitize_key( $entity_focus['type'] ?? '' ),
			'id'   => absint( $entity_focus['id'] ?? 0 ),
		];
	}

	private static function compact_last_result( $context ) {
		return [
			'type'   => sanitize_key( $context['last_result_type'] ?? '' ),
			'action' => sanitize_text_field( $context['last_action'] ?? '' ),
		];
	}
}
