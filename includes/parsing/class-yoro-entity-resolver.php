<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Entity_Resolver {

	public static function resolve( $parsed, $client_context = [] ) {
		if ( ! is_array( $parsed ) ) {
			return $parsed;
		}

		$params   = isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [];
		$entities = isset( $parsed['entities'] ) && is_array( $parsed['entities'] ) ? $parsed['entities'] : [];

		if ( ! empty( $parsed['original_command'] ) ) {
			$params['original_command'] = $parsed['original_command'];
		}

		$action = isset( $parsed['action'] ) ? sanitize_text_field( $parsed['action'] ) : '';

		$skip_context_order_resolution = in_array(
			$action,
			[
				'orders.search',
				'orders.find_by_customer',
				'customers.get_summary',
				'customers.get_service_queue',
				'operations.get_summary',
				'operations.get_attention_queue',
				'operations.get_fulfillment_queue',
				'products.get_inventory_queue',
				'store.get_business_summary',
				'store.get_daily_briefing',
				'store.get_insights',
				'store.get_morning_briefing',
				'workflow.fulfillment_followup_preview',
				'workflow.customer_followup_preview',
				'workflow.operations_attention_preview',
				'workflow.inventory_pressure_preview',
				'workflow.business_summary_preview',
			],
			true
		);

		if ( $skip_context_order_resolution ) {
			$client_context = array_merge(
				$client_context,
				[
					'current_order_id'         => 0,
					'last_referenced_order_id' => 0,
					'resolved_order_ids'       => [],
				]
			);

			unset(
				$params['order_id'],
				$params['order_ids'],
				$entities['order_id'],
				$entities['order_ids']
			);
		}

		$order_ids = self::resolve_order_ids( $params, $entities, $client_context );

		if ( ! empty( $order_ids ) ) {
			$params = YoRo_Order_Helper::apply_order_ids_to_params(
				array_merge(
					$params,
					[
						'order_ids' => $order_ids,
					]
				)
			);

			$entities['order_ids'] = $params['order_ids'];

			if ( ! empty( $params['order_id'] ) ) {
				$entities['order_id'] = $params['order_id'];
			}
		}

		if ( ! empty( $order_ids ) && class_exists( 'YoRo_Entity_Graph' ) ) {
			$graph = YoRo_Entity_Graph::from_order_ids( $order_ids );

			if ( ! empty( $graph ) ) {
				$entities['graph'] = $graph;

				if ( ! empty( $graph['emails'][0] ) ) {
					$entities['email'] = $graph['emails'][0];
				}

				if ( ! empty( $graph['phones'][0] ) ) {
					$entities['phone'] = $graph['phones'][0];
				}

				if ( ! empty( $graph['customer_ids'][0] ) ) {
					$entities['customer_id'] = absint( $graph['customer_ids'][0] );
				}
			}
		}

		$params = YoRo_Order_Helper::apply_status_to_params(
			$params,
			$parsed['normalized_command'] ?? ''
		);

		if ( ! empty( $params['status'] ) ) {
			$params['status'] = YoRo_Order_Helper::normalize_status( $params['status'] );
		}

		if ( ! empty( $params['note'] ) ) {
			$params['note']   = sanitize_textarea_field( $params['note'] );
			$entities['note'] = $params['note'];
		}

		$parsed['params']   = $params;
		$parsed['entities'] = $entities;

		return $parsed;
	}

	private static function resolve_order_ids( $params, $entities, $client_context ) {
		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = $params['order_ids'];
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ $params['order_id'] ];
		} elseif ( ! empty( $entities['order_ids'] ) && is_array( $entities['order_ids'] ) ) {
			$order_ids = $entities['order_ids'];
		} elseif ( ! empty( $entities['order_id'] ) ) {
			$order_ids = [ $entities['order_id'] ];
		}

		if (
			empty( $order_ids ) &&
			! empty( $params['original_command'] )
		) {
			$order_ids = YoRo_Order_Helper::extract_order_ids_from_text( $params['original_command'] );
		}

		if (
			empty( $order_ids ) &&
			! empty( $entities['original_command'] )
		) {
			$order_ids = YoRo_Order_Helper::extract_order_ids_from_text( $entities['original_command'] );
		}

		if (
			empty( $order_ids ) &&
			! empty( $client_context['resolved_reference'] ) &&
			is_array( $client_context['resolved_reference'] ) &&
			'order' === sanitize_key( $client_context['resolved_reference']['target_type'] ?? '' ) &&
			! empty( $client_context['resolved_reference']['target_ids'] ) &&
			is_array( $client_context['resolved_reference']['target_ids'] ) &&
			(float) ( $client_context['resolved_reference']['confidence'] ?? 0 ) >= 0.7
		) {
			$order_ids = $client_context['resolved_reference']['target_ids'];
		}

		if (
			empty( $order_ids ) &&
			! empty( $client_context['resolved_order_ids'] ) &&
			is_array( $client_context['resolved_order_ids'] )
		) {
			$order_ids = $client_context['resolved_order_ids'];
		}

		if (
			empty( $order_ids ) &&
			! empty( $client_context['current_order_id'] )
		) {
			$order_ids = [ $client_context['current_order_id'] ];
		}

		if (
			empty( $order_ids ) &&
			! empty( $client_context['last_referenced_order_id'] )
		) {
			$order_ids = [ $client_context['last_referenced_order_id'] ];
		}

		return array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $order_ids )
				)
			)
		);
	}
}
