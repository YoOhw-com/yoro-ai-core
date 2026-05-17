<?php

defined( 'ABSPATH' ) || exit;

class YoRo_Conversation_Memory {

	const META_KEY = '_yoro_conversation_memory';

	public static function get( $user_id ) {
		if ( 'yes' !== get_option( 'yoro_memory_enabled', 'yes' ) ) {
			return self::default_state();
		}

		$data = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $data ) ) {
			return self::default_state();
		}

		return self::normalize_state( $data );
	}

	public static function update( $user_id, $data ) {
		if ( 'yes' !== get_option( 'yoro_memory_enabled', 'yes' ) ) {
			return;
		}

		if ( ! $user_id || ! is_array( $data ) ) {
			return;
		}

		$current = self::get( $user_id );
		$merged  = self::merge_state( $current, $data );

		update_user_meta( $user_id, self::META_KEY, $merged );
	}

	public static function reset( $user_id ) {
		delete_user_meta( $user_id, self::META_KEY );
	}

	public static function start_workflow( $user_id, $action, $params, $missing, $type = 'generic' ) {
		self::set_workflow(
			$user_id,
			[
				'type'       => $type,
				'action'     => $action,
				'step'       => 'collect_missing_params',
				'params'     => is_array( $params ) ? $params : [],
				'missing'    => is_array( $missing ) ? $missing : [],
				'updated_at' => current_time( 'mysql' ),
			]
		);
	}

	public static function set_workflow( $user_id, $workflow ) {
		if ( 'yes' !== get_option( 'yoro_memory_enabled', 'yes' ) ) {
			return;
		}

		if ( ! $user_id || ! is_array( $workflow ) ) {
			return;
		}

		self::update(
			$user_id,
			[
				'workflow_state' => [
					'current_workflow' => self::normalize_workflow( $workflow ),
				],
			]
		);
	}

	public static function update_workflow( $user_id, $data ) {
		if ( 'yes' !== get_option( 'yoro_memory_enabled', 'yes' ) ) {
			return;
		}

		if ( ! $user_id || ! is_array( $data ) ) {
			return;
		}

		$workflow = self::get_workflow( $user_id );

		if ( empty( $workflow ) ) {
			return;
		}

		$workflow = array_merge( $workflow, $data );
		$workflow['updated_at'] = current_time( 'mysql' );

		self::set_workflow( $user_id, $workflow );
	}

	public static function clear_workflow( $user_id ) {
		if ( ! $user_id ) {
			return;
		}

		self::update(
			$user_id,
			[
				'workflow_state' => [
					'current_workflow' => [],
				],
			]
		);
	}

	public static function get_workflow( $user_id ) {
		$memory = self::get( $user_id );

		return isset( $memory['workflow_state']['current_workflow'] ) && is_array( $memory['workflow_state']['current_workflow'] )
			? $memory['workflow_state']['current_workflow']
			: [];
	}

	public static function get_order_context( $user_id ) {
		$memory  = self::get( $user_id );
		$context = $memory['entity_context'] ?? [];
		$current_order_id = self::get_current_order_id( $user_id );

		return [
			'current_order_id'         => $current_order_id,
			'last_orders'              => isset( $context['last_orders'] ) && is_array( $context['last_orders'] ) ? $context['last_orders'] : [],
			'last_order_ids'           => isset( $context['last_order_ids'] ) && is_array( $context['last_order_ids'] ) ? $context['last_order_ids'] : [],
			'recent_order_ids'         => isset( $context['recent_order_ids'] ) && is_array( $context['recent_order_ids'] ) ? $context['recent_order_ids'] : [],
			'last_referenced_order_id' => absint( $context['last_referenced_order_id'] ?? 0 ),
			'last_entity_graph'        => isset( $context['last_entity_graph'] ) && is_array( $context['last_entity_graph'] ) ? $context['last_entity_graph'] : [],
			'entity_graph'             => isset( $context['entity_graph'] ) && is_array( $context['entity_graph'] ) ? $context['entity_graph'] : [],
			'entity_focus'             => isset( $context['entity_focus'] ) && is_array( $context['entity_focus'] ) ? $context['entity_focus'] : [ 'type' => '', 'id' => 0 ],
		];
	}

	public static function get_entity_context( $user_id ) {
		$memory = self::get( $user_id );

		return isset( $memory['entity_context'] ) && is_array( $memory['entity_context'] )
			? $memory['entity_context']
			: self::entity_context_defaults();
	}

	public static function get_current_order_id( $user_id ) {
		$entity_context = self::get_entity_context( $user_id );
		$entity_focus   = isset( $entity_context['entity_focus'] ) && is_array( $entity_context['entity_focus'] )
			? $entity_context['entity_focus']
			: [];

		if (
			! empty( $entity_focus['type'] ) &&
			'order' === $entity_focus['type'] &&
			! empty( $entity_focus['id'] )
		) {
			return absint( $entity_focus['id'] );
		}

		return absint( $entity_context['last_referenced_order_id'] ?? 0 );
	}

	public static function get_execution_context( $user_id ) {
		$memory       = self::get( $user_id );
		$conversation = $memory['conversation'] ?? [];

		return [
			'last_action'      => sanitize_text_field( $conversation['last_action'] ?? '' ),
			'last_command'     => sanitize_text_field( $conversation['last_command'] ?? '' ),
			'last_result_type' => sanitize_key( $conversation['last_result_type'] ?? '' ),
			'active_followup_type' => sanitize_key( $conversation['active_followup_type'] ?? '' ),
			'last_action_params' => isset( $conversation['last_action_params'] ) && is_array( $conversation['last_action_params'] )
				? $conversation['last_action_params']
				: [],
			'updated_at'       => sanitize_text_field( $conversation['updated_at'] ?? '' ),
		];
	}

	public static function get_conversation_state( $user_id ) {
		$memory = self::get( $user_id );

		return isset( $memory['conversation'] ) && is_array( $memory['conversation'] )
			? $memory['conversation']
			: self::conversation_defaults();
	}

	public static function get_morning_briefing_state( $user_id ) {
		$conversation = self::get_conversation_state( $user_id );
		$state = isset( $conversation['morning_briefing_state'] ) && is_array( $conversation['morning_briefing_state'] )
			? $conversation['morning_briefing_state']
			: [];

		return wp_parse_args(
			$state,
			[
				'last_shown_date'     => '',
				'last_dismissed_date' => '',
				'last_signature'      => '',
				'updated_at'          => '',
			]
		);
	}

	public static function mark_morning_briefing_shown( $user_id, $date_key, $signature = '' ) {
		if ( ! $user_id ) {
			return;
		}

		self::update(
			$user_id,
			[
				'conversation' => [
					'morning_briefing_state' => [
						'last_shown_date'     => sanitize_text_field( $date_key ),
						'last_dismissed_date' => '',
						'last_signature'      => sanitize_text_field( $signature ),
						'updated_at'          => current_time( 'mysql' ),
					],
				],
			]
		);
	}

	public static function dismiss_morning_briefing( $user_id, $date_key ) {
		if ( ! $user_id ) {
			return;
		}

		$current_state = self::get_morning_briefing_state( $user_id );

		self::update(
			$user_id,
			[
				'conversation' => [
					'morning_briefing_state' => [
						'last_shown_date'     => sanitize_text_field( $current_state['last_shown_date'] ?? '' ),
						'last_dismissed_date' => sanitize_text_field( $date_key ),
						'last_signature'      => sanitize_text_field( $current_state['last_signature'] ?? '' ),
						'updated_at'          => current_time( 'mysql' ),
					],
				],
			]
		);
	}

	public static function get_workflow_state( $user_id ) {
		$memory = self::get( $user_id );

		return isset( $memory['workflow_state'] ) && is_array( $memory['workflow_state'] )
			? $memory['workflow_state']
			: self::workflow_state_defaults();
	}

	public static function get_pending_confirmation( $user_id ) {
		$memory = self::get( $user_id );

		return isset( $memory['pending_confirmation'] ) && is_array( $memory['pending_confirmation'] )
			? $memory['pending_confirmation']
			: self::pending_confirmation_defaults();
	}

	public static function get_structured_state( $user_id ) {
		$memory = self::get( $user_id );

		return [
			'conversation'         => isset( $memory['conversation'] ) && is_array( $memory['conversation'] )
				? $memory['conversation']
				: self::conversation_defaults(),
			'entity_context'       => isset( $memory['entity_context'] ) && is_array( $memory['entity_context'] )
				? $memory['entity_context']
				: self::entity_context_defaults(),
			'workflow_state'       => isset( $memory['workflow_state'] ) && is_array( $memory['workflow_state'] )
				? $memory['workflow_state']
				: self::workflow_state_defaults(),
			'pending_confirmation' => isset( $memory['pending_confirmation'] ) && is_array( $memory['pending_confirmation'] )
				? $memory['pending_confirmation']
				: self::pending_confirmation_defaults(),
		];
	}

	public static function get_runtime_context( $user_id ) {
		$structured_state      = self::get_structured_state( $user_id );
		$conversation_state    = $structured_state['conversation'];
		$workflow_state        = $structured_state['workflow_state'];
		$pending_confirmation  = $structured_state['pending_confirmation'];

		return array_merge(
			self::get_order_context( $user_id ),
			$conversation_state,
			[
				'current_workflow'      => isset( $workflow_state['current_workflow'] ) && is_array( $workflow_state['current_workflow'] )
					? $workflow_state['current_workflow']
					: [],
				'last_workflow_result'  => isset( $workflow_state['last_workflow_result'] ) && is_array( $workflow_state['last_workflow_result'] )
					? $workflow_state['last_workflow_result']
					: [],
				'workflow_history'      => isset( $workflow_state['workflow_history'] ) && is_array( $workflow_state['workflow_history'] )
					? $workflow_state['workflow_history']
					: [],
				'pending_confirmation' => $pending_confirmation,
				'conversation'         => $conversation_state,
				'entity_context'       => $structured_state['entity_context'],
				'workflow_state'       => $workflow_state,
			]
		);
	}

	public static function set_pending_confirmation( $user_id, $data ) {
		if ( ! $user_id || ! is_array( $data ) ) {
			return;
		}

		self::update(
			$user_id,
			[
				'pending_confirmation' => self::normalize_pending_confirmation( $data ),
			]
		);
	}

	public static function clear_pending_confirmation( $user_id, $token = '' ) {
		if ( ! $user_id ) {
			return;
		}

		$current = self::get_pending_confirmation( $user_id );

		if ( $token && ! empty( $current['token'] ) && $current['token'] !== $token ) {
			return;
		}

		self::update(
			$user_id,
			[
				'pending_confirmation' => [],
			]
		);
	}

	public static function remember_plan( $user_id, $plan ) {
		if ( ! $user_id || ! is_array( $plan ) ) {
			return;
		}

		self::update(
			$user_id,
			[
				'conversation' => [
					'last_plan' => self::compact_plan( $plan ),
				],
			]
		);
	}

	public static function remember_runtime_state( $user_id, $parsed, $result, $plan = [], $entities = [] ) {
		if ( ! $user_id || ! is_array( $result ) ) {
			return;
		}

		$structured_state       = self::get_structured_state( $user_id );
		$existing_conversation  = isset( $structured_state['conversation'] ) && is_array( $structured_state['conversation'] )
			? $structured_state['conversation']
			: [];
		$existing_entity_state  = isset( $structured_state['entity_context'] ) && is_array( $structured_state['entity_context'] )
			? $structured_state['entity_context']
			: [];

		$conversation = [
			'last_result'        => self::compact_result( $result ),
			'last_action_params' => is_array( $parsed['params'] ?? null ) ? $parsed['params'] : [],
			'last_briefing'      => isset( $existing_conversation['last_briefing'] ) && is_array( $existing_conversation['last_briefing'] )
				? $existing_conversation['last_briefing']
				: [],
			'last_business_followup' => isset( $existing_conversation['last_business_followup'] ) && is_array( $existing_conversation['last_business_followup'] )
				? $existing_conversation['last_business_followup']
				: [],
			'last_attention_followup' => isset( $existing_conversation['last_attention_followup'] ) && is_array( $existing_conversation['last_attention_followup'] )
				? $existing_conversation['last_attention_followup']
				: [],
			'last_service_followup' => isset( $existing_conversation['last_service_followup'] ) && is_array( $existing_conversation['last_service_followup'] )
				? $existing_conversation['last_service_followup']
				: [],
			'last_fulfillment_followup' => isset( $existing_conversation['last_fulfillment_followup'] ) && is_array( $existing_conversation['last_fulfillment_followup'] )
				? $existing_conversation['last_fulfillment_followup']
				: [],
			'last_inventory_followup' => isset( $existing_conversation['last_inventory_followup'] ) && is_array( $existing_conversation['last_inventory_followup'] )
				? $existing_conversation['last_inventory_followup']
				: [],
			'morning_briefing_state' => isset( $existing_conversation['morning_briefing_state'] ) && is_array( $existing_conversation['morning_briefing_state'] )
				? $existing_conversation['morning_briefing_state']
				: [],
			'active_followup_type' => sanitize_key( $existing_conversation['active_followup_type'] ?? '' ),
		];

		$workflow_state = [];
		$entity_update  = [];

		if ( ! empty( $plan ) && is_array( $plan ) ) {
			$conversation['last_plan'] = self::compact_plan( $plan );
		}

		if ( ! empty( $result['workflow'] ) && is_array( $result['workflow'] ) ) {
			$workflow_state['last_workflow_result'] = self::compact_result( $result );
		}

		if ( ! empty( $result['email'] ) && is_array( $result['email'] ) ) {
			$conversation['last_email_subject'] = sanitize_text_field( $result['email']['subject'] ?? '' );
			$conversation['last_email_body']    = wp_kses_post( $result['email']['body'] ?? '' );
		}

		if ( ! empty( $result['draft'] ) && is_array( $result['draft'] ) ) {
			$conversation['last_draft']    = $result['draft'];
			$conversation['last_draft_id'] = sanitize_text_field( $result['draft']['id'] ?? $result['draft']['draft_id'] ?? '' );
		}

		if ( ! empty( $result['errors'] ) && is_array( $result['errors'] ) ) {
			$conversation['last_failed_step'] = self::extract_failed_step( $result );
		}

		if ( ! empty( $entities ) && is_array( $entities ) ) {
			$graph = isset( $entities['graph'] ) && is_array( $entities['graph'] )
				? $entities['graph']
				: [];

			$entity_update['entity_graph']      = $graph;
			$entity_update['last_entity_graph'] = $graph;
			$entity_update['entity_focus']      = self::detect_entity_focus( $entities );
		}

		if ( 'daily_briefing' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_briefing'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'briefing';
		}

		if ( 'business_summary' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_business_followup'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'business';
		}

		if ( 'operations_attention_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_attention_followup'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'operations_attention';
		}

		if ( 'customer_service_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_service_followup'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'customer_service';
		}

		if ( 'fulfillment_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_fulfillment_followup'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'fulfillment';
		}

		if ( 'inventory_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_inventory_followup'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'inventory';
		}

		if (
			'rule.follow_up.briefing.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_briefing'] ) &&
			is_array( $conversation['last_briefing'] ) &&
			! empty( $conversation['last_briefing']['recommended_sequence'] ) &&
			is_array( $conversation['last_briefing']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_briefing']['recommended_sequence'] );
			$sequence_index = isset( $parsed['briefing_sequence_index'] ) ? absint( $parsed['briefing_sequence_index'] ) : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_briefing']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_briefing']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_briefing']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'briefing';
		}

		if (
			'rule.follow_up.business.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_business_followup'] ) &&
			is_array( $conversation['last_business_followup'] ) &&
			! empty( $conversation['last_business_followup']['recommended_sequence'] ) &&
			is_array( $conversation['last_business_followup']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_business_followup']['recommended_sequence'] );
			$sequence_index = isset( $parsed['business_sequence_index'] ) ? (int) $parsed['business_sequence_index'] : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_business_followup']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_business_followup']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_business_followup']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'business';
		}

		if (
			'rule.follow_up.attention.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_attention_followup'] ) &&
			is_array( $conversation['last_attention_followup'] ) &&
			! empty( $conversation['last_attention_followup']['recommended_sequence'] ) &&
			is_array( $conversation['last_attention_followup']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_attention_followup']['recommended_sequence'] );
			$sequence_index = isset( $parsed['attention_sequence_index'] ) ? (int) $parsed['attention_sequence_index'] : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_attention_followup']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_attention_followup']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_attention_followup']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'operations_attention';
		}

		if (
			'rule.follow_up.service.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_service_followup'] ) &&
			is_array( $conversation['last_service_followup'] ) &&
			! empty( $conversation['last_service_followup']['recommended_sequence'] ) &&
			is_array( $conversation['last_service_followup']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_service_followup']['recommended_sequence'] );
			$sequence_index = isset( $parsed['service_sequence_index'] ) ? (int) $parsed['service_sequence_index'] : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_service_followup']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_service_followup']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_service_followup']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'customer_service';
		}

		if (
			'rule.follow_up.fulfillment.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_fulfillment_followup'] ) &&
			is_array( $conversation['last_fulfillment_followup'] ) &&
			! empty( $conversation['last_fulfillment_followup']['recommended_sequence'] ) &&
			is_array( $conversation['last_fulfillment_followup']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_fulfillment_followup']['recommended_sequence'] );
			$sequence_index = isset( $parsed['fulfillment_sequence_index'] ) ? (int) $parsed['fulfillment_sequence_index'] : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_fulfillment_followup']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_fulfillment_followup']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_fulfillment_followup']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'fulfillment';
		}

		if (
			'rule.follow_up.inventory.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_inventory_followup'] ) &&
			is_array( $conversation['last_inventory_followup'] ) &&
			! empty( $conversation['last_inventory_followup']['recommended_sequence'] ) &&
			is_array( $conversation['last_inventory_followup']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_inventory_followup']['recommended_sequence'] );
			$sequence_index = isset( $parsed['inventory_sequence_index'] ) ? (int) $parsed['inventory_sequence_index'] : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_inventory_followup']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_inventory_followup']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_inventory_followup']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'inventory';
		}

		if ( empty( $entity_update ) ) {
			$entity_update['entity_graph']      = isset( $existing_entity_state['entity_graph'] ) && is_array( $existing_entity_state['entity_graph'] )
				? $existing_entity_state['entity_graph']
				: [];
			$entity_update['last_entity_graph'] = isset( $existing_entity_state['last_entity_graph'] ) && is_array( $existing_entity_state['last_entity_graph'] )
				? $existing_entity_state['last_entity_graph']
				: [];
			$entity_update['entity_focus']      = isset( $existing_entity_state['entity_focus'] ) && is_array( $existing_entity_state['entity_focus'] )
				? $existing_entity_state['entity_focus']
				: [ 'type' => '', 'id' => 0 ];
		}

		$payload = [
			'conversation'   => $conversation,
			'entity_context' => $entity_update,
		];

		if ( ! empty( $workflow_state ) ) {
			$payload['workflow_state'] = $workflow_state;
		}

		self::update( $user_id, $payload );
	}

	public static function remember_result( $user_id, $parsed, $result, $plan = [] ) {
		if ( ! $user_id || ! is_array( $result ) ) {
			return;
		}

		$existing_conversation = self::get_conversation_state( $user_id );

		$conversation = [
			'last_result'        => self::compact_result( $result ),
			'last_action_params' => is_array( $parsed['params'] ?? null ) ? $parsed['params'] : [],
			'last_briefing'      => isset( $existing_conversation['last_briefing'] ) && is_array( $existing_conversation['last_briefing'] )
				? $existing_conversation['last_briefing']
				: [],
			'last_business_followup' => isset( $existing_conversation['last_business_followup'] ) && is_array( $existing_conversation['last_business_followup'] )
				? $existing_conversation['last_business_followup']
				: [],
			'last_attention_followup' => isset( $existing_conversation['last_attention_followup'] ) && is_array( $existing_conversation['last_attention_followup'] )
				? $existing_conversation['last_attention_followup']
				: [],
			'last_service_followup' => isset( $existing_conversation['last_service_followup'] ) && is_array( $existing_conversation['last_service_followup'] )
				? $existing_conversation['last_service_followup']
				: [],
			'last_fulfillment_followup' => isset( $existing_conversation['last_fulfillment_followup'] ) && is_array( $existing_conversation['last_fulfillment_followup'] )
				? $existing_conversation['last_fulfillment_followup']
				: [],
			'last_inventory_followup' => isset( $existing_conversation['last_inventory_followup'] ) && is_array( $existing_conversation['last_inventory_followup'] )
				? $existing_conversation['last_inventory_followup']
				: [],
			'morning_briefing_state' => isset( $existing_conversation['morning_briefing_state'] ) && is_array( $existing_conversation['morning_briefing_state'] )
				? $existing_conversation['morning_briefing_state']
				: [],
			'active_followup_type' => sanitize_key( $existing_conversation['active_followup_type'] ?? '' ),
		];

		$workflow_state = [];

		if ( ! empty( $plan ) && is_array( $plan ) ) {
			$conversation['last_plan'] = self::compact_plan( $plan );
		}

		if ( ! empty( $result['workflow'] ) && is_array( $result['workflow'] ) ) {
			$workflow_state['last_workflow_result'] = self::compact_result( $result );
		}

		if ( ! empty( $result['email'] ) && is_array( $result['email'] ) ) {
			$conversation['last_email_subject'] = sanitize_text_field( $result['email']['subject'] ?? '' );
			$conversation['last_email_body']    = wp_kses_post( $result['email']['body'] ?? '' );
		}

		if ( ! empty( $result['draft'] ) && is_array( $result['draft'] ) ) {
			$conversation['last_draft']    = $result['draft'];
			$conversation['last_draft_id'] = sanitize_text_field( $result['draft']['id'] ?? $result['draft']['draft_id'] ?? '' );
		}

		if ( ! empty( $result['errors'] ) && is_array( $result['errors'] ) ) {
			$conversation['last_failed_step'] = self::extract_failed_step( $result );
		}

		if ( 'daily_briefing' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_briefing'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'briefing';
		}

		if ( 'business_summary' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_business_followup'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'business';
		}

		if ( 'operations_attention_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_attention_followup'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'operations_attention';
		}

		if ( 'customer_service_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_service_followup'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'customer_service';
		}

		if ( 'fulfillment_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_fulfillment_followup'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'fulfillment';
		}

		if ( 'inventory_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$conversation['last_inventory_followup'] = self::compact_result( $result );
			$conversation['active_followup_type'] = 'inventory';
		}

		if (
			'rule.follow_up.briefing.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_briefing'] ) &&
			is_array( $conversation['last_briefing'] ) &&
			! empty( $conversation['last_briefing']['recommended_sequence'] ) &&
			is_array( $conversation['last_briefing']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_briefing']['recommended_sequence'] );
			$sequence_index = isset( $parsed['briefing_sequence_index'] ) ? absint( $parsed['briefing_sequence_index'] ) : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_briefing']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_briefing']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_briefing']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'briefing';
		}

		if (
			'rule.follow_up.business.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_business_followup'] ) &&
			is_array( $conversation['last_business_followup'] ) &&
			! empty( $conversation['last_business_followup']['recommended_sequence'] ) &&
			is_array( $conversation['last_business_followup']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_business_followup']['recommended_sequence'] );
			$sequence_index = isset( $parsed['business_sequence_index'] ) ? (int) $parsed['business_sequence_index'] : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_business_followup']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_business_followup']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_business_followup']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'business';
		}

		if (
			'rule.follow_up.attention.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_attention_followup'] ) &&
			is_array( $conversation['last_attention_followup'] ) &&
			! empty( $conversation['last_attention_followup']['recommended_sequence'] ) &&
			is_array( $conversation['last_attention_followup']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_attention_followup']['recommended_sequence'] );
			$sequence_index = isset( $parsed['attention_sequence_index'] ) ? (int) $parsed['attention_sequence_index'] : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_attention_followup']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_attention_followup']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_attention_followup']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'operations_attention';
		}

		if (
			'rule.follow_up.service.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_service_followup'] ) &&
			is_array( $conversation['last_service_followup'] ) &&
			! empty( $conversation['last_service_followup']['recommended_sequence'] ) &&
			is_array( $conversation['last_service_followup']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_service_followup']['recommended_sequence'] );
			$sequence_index = isset( $parsed['service_sequence_index'] ) ? (int) $parsed['service_sequence_index'] : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_service_followup']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_service_followup']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_service_followup']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'customer_service';
		}

		if (
			'rule.follow_up.fulfillment.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_fulfillment_followup'] ) &&
			is_array( $conversation['last_fulfillment_followup'] ) &&
			! empty( $conversation['last_fulfillment_followup']['recommended_sequence'] ) &&
			is_array( $conversation['last_fulfillment_followup']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_fulfillment_followup']['recommended_sequence'] );
			$sequence_index = isset( $parsed['fulfillment_sequence_index'] ) ? (int) $parsed['fulfillment_sequence_index'] : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_fulfillment_followup']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_fulfillment_followup']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_fulfillment_followup']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'fulfillment';
		}

		if (
			'rule.follow_up.inventory.recommendation' === sanitize_text_field( $parsed['parser_rule'] ?? '' ) &&
			isset( $conversation['last_inventory_followup'] ) &&
			is_array( $conversation['last_inventory_followup'] ) &&
			! empty( $conversation['last_inventory_followup']['recommended_sequence'] ) &&
			is_array( $conversation['last_inventory_followup']['recommended_sequence'] )
		) {
			$sequence = array_values( $conversation['last_inventory_followup']['recommended_sequence'] );
			$sequence_index = isset( $parsed['inventory_sequence_index'] ) ? (int) $parsed['inventory_sequence_index'] : -1;
			$resolved_command = sanitize_text_field( $parsed['resolved_followup_command'] ?? '' );

			if ( $sequence_index < 0 && '' !== $resolved_command ) {
				foreach ( $sequence as $index => $sequence_item ) {
					$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

					if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $resolved_command ) ) {
						$sequence_index = (int) $index;
						break;
					}
				}
			}

			if ( $sequence_index >= 0 ) {
				$conversation['last_inventory_followup']['recommendation_cursor'] = $sequence_index;
			}

			$conversation['last_inventory_followup']['last_recommendation_action'] = sanitize_text_field( $parsed['action'] ?? '' );
			$conversation['last_inventory_followup']['last_recommendation_command'] = $resolved_command;
			$conversation['active_followup_type'] = 'inventory';
		}

		$payload = [
			'conversation' => $conversation,
		];

		if ( ! empty( $workflow_state ) ) {
			$payload['workflow_state'] = $workflow_state;
		}

		self::update( $user_id, $payload );
	}

	public static function remember_entity_graph( $user_id, $entities ) {
		if ( ! $user_id || empty( $entities ) || ! is_array( $entities ) ) {
			return;
		}

		$graph = isset( $entities['graph'] ) && is_array( $entities['graph'] )
			? $entities['graph']
			: [];

		self::update(
			$user_id,
			[
				'entity_context' => [
					'entity_graph'      => $graph,
					'last_entity_graph' => $graph,
					'entity_focus'      => self::detect_entity_focus( $entities ),
				],
			]
		);
	}

	private static function normalize_state( $data ) {
		$data = is_array( $data ) ? $data : [];

		$state = [
			'conversation'         => self::conversation_defaults(),
			'entity_context'       => self::entity_context_defaults(),
			'workflow_state'       => self::workflow_state_defaults(),
			'pending_confirmation' => self::pending_confirmation_defaults(),
		];

		if ( ! empty( $data['conversation'] ) && is_array( $data['conversation'] ) ) {
			$state['conversation'] = array_merge( $state['conversation'], $data['conversation'] );
		} else {
			$state['conversation'] = array_merge(
				$state['conversation'],
				[
					'last_plan'          => isset( $data['last_plan'] ) && is_array( $data['last_plan'] ) ? $data['last_plan'] : [],
					'last_result'        => isset( $data['last_result'] ) && is_array( $data['last_result'] ) ? $data['last_result'] : [],
					'last_failed_step'   => isset( $data['last_failed_step'] ) && is_array( $data['last_failed_step'] ) ? $data['last_failed_step'] : [],
					'last_draft'         => isset( $data['last_draft'] ) && is_array( $data['last_draft'] ) ? $data['last_draft'] : [],
					'last_draft_id'      => sanitize_text_field( $data['last_draft_id'] ?? '' ),
					'last_email_subject' => sanitize_text_field( $data['last_email_subject'] ?? '' ),
					'last_email_body'    => wp_kses_post( $data['last_email_body'] ?? '' ),
					'last_action_params' => isset( $data['last_action_params'] ) && is_array( $data['last_action_params'] ) ? $data['last_action_params'] : [],
					'last_action'        => sanitize_text_field( $data['last_action'] ?? '' ),
					'last_command'       => sanitize_textarea_field( $data['last_command'] ?? '' ),
					'last_result_type'   => sanitize_key( $data['last_result_type'] ?? '' ),
					'last_briefing'      => isset( $data['last_briefing'] ) && is_array( $data['last_briefing'] ) ? $data['last_briefing'] : [],
					'last_business_followup' => isset( $data['last_business_followup'] ) && is_array( $data['last_business_followup'] ) ? $data['last_business_followup'] : [],
					'last_attention_followup' => isset( $data['last_attention_followup'] ) && is_array( $data['last_attention_followup'] ) ? $data['last_attention_followup'] : [],
					'last_service_followup' => isset( $data['last_service_followup'] ) && is_array( $data['last_service_followup'] ) ? $data['last_service_followup'] : [],
					'last_fulfillment_followup' => isset( $data['last_fulfillment_followup'] ) && is_array( $data['last_fulfillment_followup'] ) ? $data['last_fulfillment_followup'] : [],
					'last_inventory_followup' => isset( $data['last_inventory_followup'] ) && is_array( $data['last_inventory_followup'] ) ? $data['last_inventory_followup'] : [],
					'morning_briefing_state' => isset( $data['morning_briefing_state'] ) && is_array( $data['morning_briefing_state'] ) ? $data['morning_briefing_state'] : [],
					'active_followup_type' => sanitize_key( $data['active_followup_type'] ?? '' ),
					'updated_at'         => sanitize_text_field( $data['updated_at'] ?? '' ),
				]
			);
		}

		if ( ! empty( $data['entity_context'] ) && is_array( $data['entity_context'] ) ) {
			$state['entity_context'] = array_merge( $state['entity_context'], $data['entity_context'] );
		} else {
			$state['entity_context'] = array_merge(
				$state['entity_context'],
				[
					'last_orders'              => isset( $data['last_orders'] ) && is_array( $data['last_orders'] ) ? $data['last_orders'] : [],
					'last_order_ids'           => isset( $data['last_order_ids'] ) && is_array( $data['last_order_ids'] ) ? $data['last_order_ids'] : [],
					'recent_order_ids'         => isset( $data['recent_order_ids'] ) && is_array( $data['recent_order_ids'] ) ? $data['recent_order_ids'] : [],
					'last_referenced_order_id' => absint( $data['last_referenced_order_id'] ?? 0 ),
					'last_entity_graph'        => isset( $data['last_entity_graph'] ) && is_array( $data['last_entity_graph'] ) ? $data['last_entity_graph'] : [],
					'entity_graph'             => isset( $data['entity_graph'] ) && is_array( $data['entity_graph'] ) ? $data['entity_graph'] : [],
					'entity_focus'             => isset( $data['entity_focus'] ) && is_array( $data['entity_focus'] ) ? $data['entity_focus'] : [ 'type' => '', 'id' => 0 ],
				]
			);
		}

		if ( ! empty( $data['workflow_state'] ) && is_array( $data['workflow_state'] ) ) {
			$state['workflow_state'] = array_merge( $state['workflow_state'], $data['workflow_state'] );
		} else {
			$state['workflow_state'] = array_merge(
				$state['workflow_state'],
				[
					'current_workflow'    => isset( $data['current_workflow'] ) && is_array( $data['current_workflow'] ) ? $data['current_workflow'] : [],
					'last_workflow_result'=> isset( $data['last_workflow_result'] ) && is_array( $data['last_workflow_result'] ) ? $data['last_workflow_result'] : [],
					'workflow_history'    => isset( $data['workflow_history'] ) && is_array( $data['workflow_history'] ) ? $data['workflow_history'] : [],
				]
			);
		}

		if ( ! empty( $data['pending_confirmation'] ) && is_array( $data['pending_confirmation'] ) ) {
			$state['pending_confirmation'] = array_merge( $state['pending_confirmation'], $data['pending_confirmation'] );
		} else {
			$legacy_token = sanitize_text_field(
				$data['pending_plan_token'] ?? $data['workflow_state_token'] ?? ''
			);

			if ( $legacy_token ) {
				$state['pending_confirmation'] = array_merge(
					$state['pending_confirmation'],
					[
						'token'    => $legacy_token,
						'required' => true,
					]
				);
			}
		}

		$state['workflow_state']['current_workflow']    = self::normalize_workflow( $state['workflow_state']['current_workflow'] );
		$state['workflow_state']['last_workflow_result'] = isset( $state['workflow_state']['last_workflow_result'] ) && is_array( $state['workflow_state']['last_workflow_result'] )
			? $state['workflow_state']['last_workflow_result']
			: [];
		$state['workflow_state']['workflow_history'] = isset( $state['workflow_state']['workflow_history'] ) && is_array( $state['workflow_state']['workflow_history'] )
			? $state['workflow_state']['workflow_history']
			: [];

		$state['pending_confirmation'] = self::normalize_pending_confirmation( $state['pending_confirmation'] );

		return self::project_legacy_state( $state );
	}

	private static function merge_state( $current, $data ) {
		$state = self::normalize_state( $current );
		$data  = is_array( $data ) ? $data : [];

		if ( isset( $data['conversation'] ) && is_array( $data['conversation'] ) ) {
			$state['conversation'] = array_merge( $state['conversation'], $data['conversation'] );
		}

		if ( isset( $data['entity_context'] ) && is_array( $data['entity_context'] ) ) {
			$state['entity_context'] = array_merge( $state['entity_context'], $data['entity_context'] );
		}

		if ( isset( $data['workflow_state'] ) && is_array( $data['workflow_state'] ) ) {
			$state['workflow_state'] = array_merge( $state['workflow_state'], $data['workflow_state'] );
		}

		if ( array_key_exists( 'pending_confirmation', $data ) ) {
			$state['pending_confirmation'] = ! empty( $data['pending_confirmation'] ) && is_array( $data['pending_confirmation'] )
				? array_merge( $state['pending_confirmation'], $data['pending_confirmation'] )
				: self::pending_confirmation_defaults();
		}

		self::apply_legacy_update( $state, $data );

		$state['conversation']['updated_at'] = current_time( 'mysql' );
		$state['workflow_state']['current_workflow'] = self::normalize_workflow( $state['workflow_state']['current_workflow'] );
		$state['pending_confirmation'] = self::normalize_pending_confirmation( $state['pending_confirmation'] );

		return self::project_legacy_state( $state );
	}

	private static function apply_legacy_update( &$state, $data ) {
		$conversation_keys = [
			'last_plan',
			'last_result',
			'last_failed_step',
			'last_draft',
			'last_draft_id',
			'last_email_subject',
			'last_email_body',
			'last_action_params',
			'last_action',
			'last_command',
			'last_result_type',
			'last_briefing',
			'last_business_followup',
			'last_attention_followup',
			'last_service_followup',
			'last_fulfillment_followup',
			'last_inventory_followup',
			'morning_briefing_state',
			'active_followup_type',
			'updated_at',
		];

		foreach ( $conversation_keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$state['conversation'][ $key ] = $data[ $key ];
			}
		}

		$entity_keys = [
			'last_orders',
			'last_order_ids',
			'recent_order_ids',
			'last_referenced_order_id',
			'last_entity_graph',
			'entity_graph',
			'entity_focus',
		];

		foreach ( $entity_keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$state['entity_context'][ $key ] = $data[ $key ];
			}
		}

		if ( array_key_exists( 'current_workflow', $data ) ) {
			$state['workflow_state']['current_workflow'] = $data['current_workflow'];
		}

		if ( array_key_exists( 'last_workflow_result', $data ) ) {
			$state['workflow_state']['last_workflow_result'] = $data['last_workflow_result'];
		}

		if ( array_key_exists( 'workflow_history', $data ) ) {
			$state['workflow_state']['workflow_history'] = $data['workflow_history'];
		}
	}

	private static function project_legacy_state( $state ) {
		$state = wp_parse_args(
			$state,
			[
				'conversation'         => self::conversation_defaults(),
				'entity_context'       => self::entity_context_defaults(),
				'workflow_state'       => self::workflow_state_defaults(),
				'pending_confirmation' => self::pending_confirmation_defaults(),
			]
		);

		$state['current_workflow']        = $state['workflow_state']['current_workflow'];
		$state['last_workflow_result']    = $state['workflow_state']['last_workflow_result'];
		$state['workflow_history']        = $state['workflow_state']['workflow_history'];
		$state['last_orders']             = $state['entity_context']['last_orders'];
		$state['last_order_ids']          = $state['entity_context']['last_order_ids'];
		$state['recent_order_ids']        = $state['entity_context']['recent_order_ids'];
		$state['last_referenced_order_id']= $state['entity_context']['last_referenced_order_id'];
		$state['current_order_id']        = self::detect_current_order_id( $state['entity_context'] );
		$state['last_entity_graph']       = $state['entity_context']['last_entity_graph'];
		$state['entity_graph']            = $state['entity_context']['entity_graph'];
		$state['entity_focus']            = $state['entity_context']['entity_focus'];
		$state['last_plan']               = $state['conversation']['last_plan'];
		$state['last_result']             = $state['conversation']['last_result'];
		$state['last_failed_step']        = $state['conversation']['last_failed_step'];
		$state['last_draft']              = $state['conversation']['last_draft'];
		$state['last_draft_id']           = $state['conversation']['last_draft_id'];
		$state['last_email_subject']      = $state['conversation']['last_email_subject'];
		$state['last_email_body']         = $state['conversation']['last_email_body'];
		$state['last_action_params']      = $state['conversation']['last_action_params'];
		$state['last_action']             = $state['conversation']['last_action'];
		$state['last_command']            = $state['conversation']['last_command'];
		$state['last_result_type']        = $state['conversation']['last_result_type'];
		$state['last_briefing']           = $state['conversation']['last_briefing'];
		$state['last_business_followup']  = $state['conversation']['last_business_followup'];
		$state['last_attention_followup'] = $state['conversation']['last_attention_followup'];
		$state['last_service_followup']   = $state['conversation']['last_service_followup'];
		$state['last_fulfillment_followup'] = $state['conversation']['last_fulfillment_followup'];
		$state['last_inventory_followup'] = $state['conversation']['last_inventory_followup'];
		$state['morning_briefing_state']  = $state['conversation']['morning_briefing_state'];
		$state['active_followup_type']    = $state['conversation']['active_followup_type'];
		$state['updated_at']              = $state['conversation']['updated_at'];
		$state['pending_plan_token']      = sanitize_text_field( $state['pending_confirmation']['token'] ?? '' );
		$state['workflow_state_token']    = sanitize_text_field( $state['pending_confirmation']['token'] ?? '' );

		return $state;
	}

	private static function normalize_workflow( $workflow ) {
		if ( empty( $workflow ) || ! is_array( $workflow ) ) {
			return [];
		}

		return wp_parse_args(
			$workflow,
			[
				'id'           => 'wf_' . wp_generate_uuid4(),
				'type'         => '',
				'definition_id'=> '',
				'label'        => '',
				'description'  => '',
				'category'     => '',
				'status'       => '',
				'surface'      => '',
				'action'       => '',
				'step'         => '',
				'current_step_id'    => '',
				'current_step_index' => -1,
				'order_id'     => 0,
				'product_id'   => 0,
				'subject'      => '',
				'body'         => '',
				'draft_id'     => '',
				'steps'        => [],
				'resolved_entities' => [],
				'input_context'=> [],
				'artifacts'    => [],
				'plan'         => [],
				'params'       => [],
				'missing'      => [],
				'last_command' => '',
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			]
		);
	}

	private static function normalize_pending_confirmation( $pending ) {
		if ( empty( $pending ) || ! is_array( $pending ) ) {
			return self::pending_confirmation_defaults();
		}

		return wp_parse_args(
			$pending,
			[
				'token'         => '',
				'command'       => '',
				'action'        => '',
				'parser_source' => '',
				'trace_id'      => '',
				'created_at'    => '',
				'expires_at'    => '',
				'required'      => false,
			]
		);
	}

	private static function compact_plan( $plan ) {
		return [
			'type'          => sanitize_key( $plan['type'] ?? '' ),
			'action'        => sanitize_text_field( $plan['action'] ?? '' ),
			'steps'         => isset( $plan['steps'] ) && is_array( $plan['steps'] ) ? $plan['steps'] : [],
			'params'        => isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : [],
			'workflow'      => isset( $plan['workflow'] ) && is_array( $plan['workflow'] ) ? $plan['workflow'] : [],
			'parser_source' => sanitize_key( $plan['parser_source'] ?? '' ),
			'updated_at'    => current_time( 'mysql' ),
		];
	}

	private static function compact_result( $result ) {
		$compact = [
			'success'      => ! empty( $result['success'] ),
			'action'       => sanitize_text_field( $result['action'] ?? '' ),
			'summary'      => sanitize_text_field( $result['summary'] ?? '' ),
			'message'      => sanitize_text_field( $result['message'] ?? '' ),
			'display_type' => sanitize_key( $result['display_type'] ?? '' ),
			'result_type'  => sanitize_key( $result['result_type'] ?? '' ),
			'conversation_topic' => sanitize_key( $result['conversation_topic'] ?? '' ),
			'conversation_family' => sanitize_key( $result['conversation_family'] ?? '' ),
			'closest_supported_surface' => sanitize_key( $result['closest_supported_surface'] ?? '' ),
			'developer_handoff_offer' => ! empty( $result['developer_handoff_offer'] ),
			'handoff_request_text' => sanitize_text_field( $result['handoff_request_text'] ?? '' ),
			'processed'    => isset( $result['processed'] ) && is_array( $result['processed'] ) ? array_map( 'absint', $result['processed'] ) : [],
			'skipped'      => isset( $result['skipped'] ) && is_array( $result['skipped'] ) ? $result['skipped'] : [],
			'errors'       => isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : [],
			'orders'       => isset( $result['orders'] ) && is_array( $result['orders'] ) ? array_slice( $result['orders'], 0, 20 ) : [],
			'order'        => isset( $result['order'] ) && is_array( $result['order'] ) ? $result['order'] : [],
			'email'        => isset( $result['email'] ) && is_array( $result['email'] ) ? $result['email'] : [],
			'draft'        => isset( $result['draft'] ) && is_array( $result['draft'] ) ? $result['draft'] : [],
			'updated_at'   => current_time( 'mysql' ),
		];

		if ( 'daily_briefing' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$compact['briefing_label'] = sanitize_text_field( $result['briefing_label'] ?? '' );
			$compact['briefing_context'] = isset( $result['briefing_context'] ) && is_array( $result['briefing_context'] )
				? $result['briefing_context']
				: [];
			$compact['priority_drilldowns'] = isset( $result['priority_drilldowns'] ) && is_array( $result['priority_drilldowns'] )
				? array_slice( $result['priority_drilldowns'], 0, 5 )
				: [];
			$compact['recommended_sequence'] = isset( $result['recommended_sequence'] ) && is_array( $result['recommended_sequence'] )
				? array_slice( $result['recommended_sequence'], 0, 5 )
				: [];
			$compact['recommendation_cursor'] = isset( $result['recommendation_cursor'] ) ? (int) $result['recommendation_cursor'] : -1;
			$compact['last_recommendation_action'] = sanitize_text_field( $result['last_recommendation_action'] ?? '' );
			$compact['last_recommendation_command'] = sanitize_text_field( $result['last_recommendation_command'] ?? '' );
			$compact['priority_items'] = isset( $result['priority_items'] ) && is_array( $result['priority_items'] )
				? array_slice( $result['priority_items'], 0, 3 )
				: [];
		}

		if ( 'business_summary' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$compact['business_context'] = isset( $result['business_context'] ) && is_array( $result['business_context'] )
				? $result['business_context']
				: [];
			$compact['recommended_sequence'] = isset( $result['recommended_sequence'] ) && is_array( $result['recommended_sequence'] )
				? array_slice( $result['recommended_sequence'], 0, 5 )
				: [];
			$compact['business_actions'] = isset( $result['business_actions'] ) && is_array( $result['business_actions'] )
				? array_slice( $result['business_actions'], 0, 5 )
				: [];
			$compact['priority_items'] = isset( $result['priority_items'] ) && is_array( $result['priority_items'] )
				? array_slice( $result['priority_items'], 0, 3 )
				: [];
			$compact['recommendation_cursor'] = isset( $result['recommendation_cursor'] ) ? (int) $result['recommendation_cursor'] : -1;
			$compact['last_recommendation_action'] = sanitize_text_field( $result['last_recommendation_action'] ?? '' );
			$compact['last_recommendation_command'] = sanitize_text_field( $result['last_recommendation_command'] ?? '' );
		}

		if ( 'customer_service_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$compact['service_context'] = isset( $result['service_context'] ) && is_array( $result['service_context'] )
				? $result['service_context']
				: [];
			$compact['queues'] = isset( $result['queues'] ) && is_array( $result['queues'] )
				? array_slice( $result['queues'], 0, 5 )
				: [];
			$compact['recommended_sequence'] = isset( $result['recommended_sequence'] ) && is_array( $result['recommended_sequence'] )
				? array_slice( $result['recommended_sequence'], 0, 5 )
				: [];
			$compact['service_actions'] = isset( $result['service_actions'] ) && is_array( $result['service_actions'] )
				? array_slice( $result['service_actions'], 0, 5 )
				: [];
			$compact['priority_items'] = isset( $result['priority_items'] ) && is_array( $result['priority_items'] )
				? array_slice( $result['priority_items'], 0, 3 )
				: [];
			$compact['recommendation_cursor'] = isset( $result['recommendation_cursor'] ) ? (int) $result['recommendation_cursor'] : -1;
			$compact['last_recommendation_action'] = sanitize_text_field( $result['last_recommendation_action'] ?? '' );
			$compact['last_recommendation_command'] = sanitize_text_field( $result['last_recommendation_command'] ?? '' );
		}

		if ( 'fulfillment_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$compact['fulfillment_context'] = isset( $result['fulfillment_context'] ) && is_array( $result['fulfillment_context'] )
				? $result['fulfillment_context']
				: [];
			$compact['queues'] = isset( $result['queues'] ) && is_array( $result['queues'] )
				? array_slice( $result['queues'], 0, 5 )
				: [];
			$compact['recommended_sequence'] = isset( $result['recommended_sequence'] ) && is_array( $result['recommended_sequence'] )
				? array_slice( $result['recommended_sequence'], 0, 5 )
				: [];
			$compact['fulfillment_actions'] = isset( $result['fulfillment_actions'] ) && is_array( $result['fulfillment_actions'] )
				? array_slice( $result['fulfillment_actions'], 0, 5 )
				: [];
			$compact['priority_items'] = isset( $result['priority_items'] ) && is_array( $result['priority_items'] )
				? array_slice( $result['priority_items'], 0, 3 )
				: [];
			$compact['recommendation_cursor'] = isset( $result['recommendation_cursor'] ) ? (int) $result['recommendation_cursor'] : -1;
			$compact['last_recommendation_action'] = sanitize_text_field( $result['last_recommendation_action'] ?? '' );
			$compact['last_recommendation_command'] = sanitize_text_field( $result['last_recommendation_command'] ?? '' );
		}

		if ( 'operations_attention_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$compact['attention_context'] = isset( $result['attention_context'] ) && is_array( $result['attention_context'] )
				? $result['attention_context']
				: [];
			$compact['queues'] = isset( $result['queues'] ) && is_array( $result['queues'] )
				? array_slice( $result['queues'], 0, 5 )
				: [];
			$compact['recommended_sequence'] = isset( $result['recommended_sequence'] ) && is_array( $result['recommended_sequence'] )
				? array_slice( $result['recommended_sequence'], 0, 5 )
				: [];
			$compact['attention_actions'] = isset( $result['attention_actions'] ) && is_array( $result['attention_actions'] )
				? array_slice( $result['attention_actions'], 0, 5 )
				: [];
			$compact['priority_items'] = isset( $result['priority_items'] ) && is_array( $result['priority_items'] )
				? array_slice( $result['priority_items'], 0, 3 )
				: [];
			$compact['recommendation_cursor'] = isset( $result['recommendation_cursor'] ) ? (int) $result['recommendation_cursor'] : -1;
			$compact['last_recommendation_action'] = sanitize_text_field( $result['last_recommendation_action'] ?? '' );
			$compact['last_recommendation_command'] = sanitize_text_field( $result['last_recommendation_command'] ?? '' );
		}

		if ( 'inventory_queue' === sanitize_key( $result['result_type'] ?? '' ) ) {
			$compact['inventory_context'] = isset( $result['inventory_context'] ) && is_array( $result['inventory_context'] )
				? $result['inventory_context']
				: [];
			$compact['queues'] = isset( $result['queues'] ) && is_array( $result['queues'] )
				? array_slice( $result['queues'], 0, 5 )
				: [];
			$compact['recommended_sequence'] = isset( $result['recommended_sequence'] ) && is_array( $result['recommended_sequence'] )
				? array_slice( $result['recommended_sequence'], 0, 5 )
				: [];
			$compact['inventory_actions'] = isset( $result['inventory_actions'] ) && is_array( $result['inventory_actions'] )
				? array_slice( $result['inventory_actions'], 0, 5 )
				: [];
			$compact['priority_items'] = isset( $result['priority_items'] ) && is_array( $result['priority_items'] )
				? array_slice( $result['priority_items'], 0, 3 )
				: [];
			$compact['recommendation_cursor'] = isset( $result['recommendation_cursor'] ) ? (int) $result['recommendation_cursor'] : -1;
			$compact['last_recommendation_action'] = sanitize_text_field( $result['last_recommendation_action'] ?? '' );
			$compact['last_recommendation_command'] = sanitize_text_field( $result['last_recommendation_command'] ?? '' );
		}

		return $compact;
	}

	private static function detect_entity_focus( $entities ) {
		if ( ! empty( $entities['order_id'] ) ) {
			return [
				'type' => 'order',
				'id'   => absint( $entities['order_id'] ),
			];
		}

		if ( ! empty( $entities['customer_id'] ) ) {
			return [
				'type' => 'customer',
				'id'   => absint( $entities['customer_id'] ),
			];
		}

		return [
			'type' => '',
			'id'   => 0,
		];
	}

	private static function detect_current_order_id( $entity_context ) {
		$entity_context = is_array( $entity_context ) ? $entity_context : [];
		$entity_focus   = isset( $entity_context['entity_focus'] ) && is_array( $entity_context['entity_focus'] )
			? $entity_context['entity_focus']
			: [];

		if (
			! empty( $entity_focus['type'] ) &&
			'order' === $entity_focus['type'] &&
			! empty( $entity_focus['id'] )
		) {
			return absint( $entity_focus['id'] );
		}

		return absint( $entity_context['last_referenced_order_id'] ?? 0 );
	}

	private static function extract_failed_step( $result ) {
		$actions = isset( $result['actions'] ) && is_array( $result['actions'] )
			? $result['actions']
			: [];

		foreach ( $actions as $step ) {
			if ( ! empty( $step['failed'] ) ) {
				return $step;
			}
		}

		return [];
	}

	private static function conversation_defaults() {
		return [
			'last_plan'          => [],
			'last_result'        => [],
			'last_failed_step'   => [],
			'last_draft'         => [],
			'last_draft_id'      => '',
			'last_email_subject' => '',
			'last_email_body'    => '',
			'last_action_params' => [],
			'last_action'        => '',
			'last_command'       => '',
			'last_result_type'   => '',
			'last_briefing'      => [],
			'last_business_followup' => [],
			'last_attention_followup' => [],
			'last_service_followup' => [],
			'last_fulfillment_followup' => [],
			'last_inventory_followup' => [],
			'morning_briefing_state' => [],
			'active_followup_type' => '',
			'updated_at'         => '',
		];
	}

	private static function entity_context_defaults() {
		return [
			'last_orders'              => [],
			'last_order_ids'           => [],
			'recent_order_ids'         => [],
			'last_referenced_order_id' => 0,
			'last_entity_graph'        => [],
			'entity_graph'             => [],
			'entity_focus'             => [
				'type' => '',
				'id'   => 0,
			],
		];
	}

	private static function workflow_state_defaults() {
		return [
			'current_workflow'     => [],
			'last_workflow_result' => [],
			'workflow_history'     => [],
		];
	}

	private static function pending_confirmation_defaults() {
		return [
			'token'         => '',
			'command'       => '',
			'action'        => '',
			'parser_source' => '',
			'trace_id'      => '',
			'created_at'    => '',
			'expires_at'    => '',
			'required'      => false,
		];
	}

	private static function default_state() {
		return self::project_legacy_state(
			[
				'conversation'         => self::conversation_defaults(),
				'entity_context'       => self::entity_context_defaults(),
				'workflow_state'       => self::workflow_state_defaults(),
				'pending_confirmation' => self::pending_confirmation_defaults(),
			]
		);
	}
}
