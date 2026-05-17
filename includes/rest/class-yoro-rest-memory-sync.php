<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Memory_Sync {

	public static function maybe_update( $user_id, $command, $parsed, $result, $plan = [] ) {
		if ( ! $user_id || ! is_array( $result ) ) {
			return;
		}

		if ( method_exists( 'YoRo_Conversation_Memory', 'remember_runtime_state' ) ) {
			YoRo_Conversation_Memory::remember_runtime_state(
				$user_id,
				is_array( $parsed ) ? $parsed : [],
				$result,
				is_array( $plan ) ? $plan : [],
				! empty( $parsed['entities'] ) && is_array( $parsed['entities'] ) ? $parsed['entities'] : []
			);
		} else {
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
		}

		$conversation_update = [
			'last_command' => $command,
			'last_action'  => $parsed['action'] ?? '',
		];
		$entity_update          = [];
		$workflow_update        = [];
		$structured_state       = YoRo_Conversation_Memory::get_structured_state( $user_id );
		$current_entity_context = isset( $structured_state['entity_context'] ) && is_array( $structured_state['entity_context'] )
			? $structured_state['entity_context']
			: [];
		$current_workflow_state = isset( $structured_state['workflow_state'] ) && is_array( $structured_state['workflow_state'] )
			? $structured_state['workflow_state']
			: [];
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
			$workflow_step   = sanitize_key( $result['workflow']['step'] ?? '' );
			$workflow_status = sanitize_key( $result['workflow']['status'] ?? '' );

			if ( in_array( $workflow_step, [ 'executed', 'failed', 'cancelled' ], true ) || in_array( $workflow_status, [ 'executed', 'failed', 'cancelled' ], true ) ) {
				$workflow_update['current_workflow']   = [];
				$workflow_update['last_workflow_result'] = $result['workflow'];
				$workflow_update['workflow_history'] = self::append_workflow_history_entry(
					$current_workflow_state['workflow_history'] ?? [],
					$result['workflow']
				);
			} else {
				$workflow_update['current_workflow'] = $result['workflow'];
			}
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

	private static function append_workflow_history_entry( $existing_history, $workflow, $limit = 10 ) {
		$existing_history = is_array( $existing_history ) ? array_values( $existing_history ) : [];
		$workflow         = is_array( $workflow ) ? $workflow : [];

		if ( empty( $workflow ) ) {
			return $existing_history;
		}

		$entry = [
			'id'                 => sanitize_text_field( $workflow['id'] ?? '' ),
			'type'               => sanitize_key( $workflow['type'] ?? '' ),
			'definition_id'      => sanitize_key( $workflow['definition_id'] ?? '' ),
			'label'              => sanitize_text_field( $workflow['label'] ?? '' ),
			'status'             => sanitize_key( $workflow['status'] ?? $workflow['step'] ?? '' ),
			'current_step_id'    => sanitize_key( $workflow['current_step_id'] ?? '' ),
			'current_step_index' => isset( $workflow['current_step_index'] ) ? (int) $workflow['current_step_index'] : -1,
			'updated_at'         => sanitize_text_field( $workflow['updated_at'] ?? current_time( 'mysql' ) ),
		];

		array_unshift( $existing_history, $entry );

		$existing_history = array_values(
			array_filter(
				$existing_history,
				function ( $item ) {
					return is_array( $item ) && ! empty( $item['id'] );
				}
			)
		);

		if ( $limit > 0 ) {
			$existing_history = array_slice( $existing_history, 0, absint( $limit ) );
		}

		return $existing_history;
	}
}
