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
		$order_ids = isset( $context['last_order_ids'] ) && is_array( $context['last_order_ids'] )
			? array_values(
				array_filter(
					array_unique(
						array_map( 'absint', $context['last_order_ids'] )
					)
				)
			)
			: [];

		if ( empty( $order_ids ) && ! empty( $context['last_orders'] ) && is_array( $context['last_orders'] ) ) {
			$order_ids = array_values(
				array_filter(
					array_unique(
						array_map(
							static function( $order ) {
								return is_array( $order ) ? absint( $order['id'] ?? 0 ) : 0;
							},
							$context['last_orders']
						)
					)
				)
			);
		}

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
			'order_id'=> absint( $workflow['order_id'] ?? 0 ),
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
