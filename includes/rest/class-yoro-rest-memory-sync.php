<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Memory_Sync {

	public static function maybe_update( $user_id, $command, $parsed, $result, $plan = [] ) {
		if ( ! $user_id || ! is_array( $result ) ) {
			return;
		}

		if ( method_exists( 'YoRo_Conversation_Memory', 'remember_plan' ) && is_array( $plan ) && ! empty( $plan ) ) {
			YoRo_Conversation_Memory::remember_plan( $user_id, $plan );
		}

		if ( method_exists( 'YoRo_Conversation_Memory', 'remember_result' ) ) {
			YoRo_Conversation_Memory::remember_result(
				$user_id,
				is_array( $parsed ) ? $parsed : [],
				$result,
				is_array( $plan ) ? $plan : []
			);
		}

		if (
			method_exists( 'YoRo_Conversation_Memory', 'remember_entity_graph' ) &&
			! empty( $parsed['entities'] ) &&
			is_array( $parsed['entities'] )
		) {
			YoRo_Conversation_Memory::remember_entity_graph(
				$user_id,
				$parsed['entities']
			);
		}

		$conversation_update = [
			'last_command' => $command,
			'last_action'  => $parsed['action'] ?? '',
		];
		$entity_update          = [];
		$workflow_update        = [];
		$current_entity_context = YoRo_Conversation_Memory::get_entity_context( $user_id );
		$recent_order_ids       = isset( $current_entity_context['recent_order_ids'] ) && is_array( $current_entity_context['recent_order_ids'] )
			? $current_entity_context['recent_order_ids']
			: [];
		$new_recent_order_ids   = [];

		if (
			! empty( $parsed['entities']['graph'] ) &&
			is_array( $parsed['entities']['graph'] )
		) {
			$entity_update['last_entity_graph'] = $parsed['entities']['graph'];
		}

		if (
			! empty( $result['multi_action'] ) &&
			! empty( $result['actions'] ) &&
			is_array( $result['actions'] )
		) {
			foreach ( $result['actions'] as $item ) {
				$step_result = isset( $item['result'] ) && is_array( $item['result'] ) ? $item['result'] : [];

				if ( ! empty( $step_result['orders'] ) && is_array( $step_result['orders'] ) ) {
					$last_orders = array_map(
						function( $order ) {
							return [
								'id'     => isset( $order['id'] ) ? absint( $order['id'] ) : 0,
								'number' => isset( $order['number'] ) ? sanitize_text_field( $order['number'] ) : '',
								'status' => isset( $order['status'] ) ? sanitize_key( $order['status'] ) : '',
							];
						},
						$step_result['orders']
					);

					$last_order_ids = array_values(
						array_filter(
							array_map(
								function( $o ) {
									return $o['id'] ?? 0;
								},
								$last_orders
							)
						)
					);

					$entity_update['last_orders']              = $last_orders;
					$entity_update['last_order_ids']           = $last_order_ids;
					$conversation_update['last_result_type']   = 'orders';
					$entity_update['last_referenced_order_id'] = ! empty( $last_order_ids[0] ) ? absint( $last_order_ids[0] ) : 0;
					$new_recent_order_ids                      = array_merge( $new_recent_order_ids, $last_order_ids );

					break;
				}
			}
		}

		if ( isset( $result['orders'] ) && is_array( $result['orders'] ) ) {
			$last_orders = array_map(
				function( $order ) {
					return [
						'id'     => isset( $order['id'] ) ? absint( $order['id'] ) : 0,
						'number' => isset( $order['number'] ) ? sanitize_text_field( $order['number'] ) : '',
						'status' => isset( $order['status'] ) ? sanitize_key( $order['status'] ) : '',
					];
				},
				$result['orders']
			);

			$last_order_ids = array_values(
				array_filter(
					array_map(
						function( $o ) {
							return $o['id'] ?? 0;
						},
						$last_orders
					)
				)
			);

			$entity_update['last_orders']              = $last_orders;
			$entity_update['last_order_ids']           = $last_order_ids;
			$conversation_update['last_result_type']   = 'orders';
			$entity_update['last_referenced_order_id'] = ! empty( $last_order_ids[0] ) ? absint( $last_order_ids[0] ) : 0;
			$new_recent_order_ids                      = array_merge( $new_recent_order_ids, $last_order_ids );
		}

		if ( 'daily_briefing' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation_update['last_result_type'] = 'briefing';
		}

		if ( 'business_summary' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation_update['last_result_type'] = 'business';
		}

		if ( 'operations_attention_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation_update['last_result_type'] = 'operations_attention';
		}

		if ( 'customer_service_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation_update['last_result_type'] = 'customer_service';
		}

		if ( 'fulfillment_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation_update['last_result_type'] = 'fulfillment';
		}

		if ( 'inventory_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation_update['last_result_type'] = 'inventory';
		}

		if ( ! empty( $parsed['params']['order_ids'] ) && is_array( $parsed['params']['order_ids'] ) ) {
			$order_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', $parsed['params']['order_ids'] )
					)
				)
			);

			if ( ! empty( $order_ids ) ) {
				$entity_update['last_order_ids']           = $order_ids;
				$entity_update['last_referenced_order_id'] = absint( reset( $order_ids ) );
				$new_recent_order_ids                      = array_merge( $new_recent_order_ids, $order_ids );
			}
		}

		if ( ! empty( $parsed['params']['order_id'] ) ) {
			$entity_update['last_order_ids']           = [ absint( $parsed['params']['order_id'] ) ];
			$entity_update['last_referenced_order_id'] = absint( $parsed['params']['order_id'] );
			$new_recent_order_ids[]                    = absint( $parsed['params']['order_id'] );
		}

		if ( ! empty( $result['workflow'] ) && is_array( $result['workflow'] ) ) {
			$workflow_update['current_workflow'] = $result['workflow'];
		}

		if ( ! empty( $new_recent_order_ids ) ) {
			$entity_update['recent_order_ids'] = self::merge_recent_order_ids(
				$recent_order_ids,
				$new_recent_order_ids
			);
		}

		YoRo_Conversation_Memory::update(
			$user_id,
			[
				'conversation'   => $conversation_update,
				'entity_context' => $entity_update,
				'workflow_state' => $workflow_update,
			]
		);
	}

	private static function merge_recent_order_ids( $existing_order_ids, $new_order_ids, $limit = 10 ) {
		$existing_order_ids = is_array( $existing_order_ids ) ? $existing_order_ids : [];
		$new_order_ids      = is_array( $new_order_ids ) ? $new_order_ids : [];

		$merged = array_merge(
			array_values( array_filter( array_map( 'absint', $new_order_ids ) ) ),
			array_values( array_filter( array_map( 'absint', $existing_order_ids ) ) )
		);

		$merged = array_values( array_filter( array_unique( $merged ) ) );

		if ( $limit > 0 ) {
			$merged = array_slice( $merged, 0, absint( $limit ) );
		}

		return $merged;
	}
}
