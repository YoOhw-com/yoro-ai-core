<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Router_Context_Decision_Hydrator {

	public static function hydrate( $decision, $command, $normalized, $client_context = [] ) {
		$decision = self::hydrate_order_target_params(
			$decision,
			$client_context
		);

		$decision = self::hydrate_queue_preview_decision(
			$decision,
			$command,
			$normalized,
			$client_context
		);

		return $decision;
	}

	private static function hydrate_order_target_params( $decision, $client_context = [] ) {
		$decision = is_array( $decision ) ? $decision : [];
		$order_ids = self::context_resolved_order_ids( $client_context );

		if ( empty( $order_ids ) ) {
			return $decision;
		}

		$action = sanitize_text_field( $decision['action'] ?? '' );

		if ( self::action_accepts_context_order_target( $action ) ) {
			$params = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
			$decision['params'] = self::hydrate_order_target_params_for_action( $action, $params, $order_ids );
			$decision = self::hydrate_order_target_semantic_frame( $decision, $order_ids );
		}

		if ( ! empty( $decision['action_plan']['steps'] ) && is_array( $decision['action_plan']['steps'] ) ) {
			foreach ( $decision['action_plan']['steps'] as &$step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}

				$step_action = sanitize_text_field( $step['action'] ?? '' );

				if ( ! self::action_accepts_context_order_target( $step_action ) ) {
					continue;
				}

				$step['params'] = self::hydrate_order_target_params_for_action(
					$step_action,
					isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
					$order_ids
				);
			}
			unset( $step );
		}

		return $decision;
	}

	private static function hydrate_order_target_semantic_frame( $decision, $order_ids ) {
		if ( empty( $decision['semantic_frame'] ) || ! is_array( $decision['semantic_frame'] ) ) {
			return $decision;
		}

		if ( empty( $decision['semantic_frame']['constraints'] ) || ! is_array( $decision['semantic_frame']['constraints'] ) ) {
			$decision['semantic_frame']['constraints'] = [];
		}

		$order_ids = array_values( array_filter( array_map( 'absint', (array) $order_ids ) ) );

		if ( empty( $order_ids ) ) {
			return $decision;
		}

		if ( empty( $decision['semantic_frame']['constraints']['order_ids'] ) ) {
			$decision['semantic_frame']['constraints']['order_ids'] = $order_ids;
		}

		if ( empty( $decision['semantic_frame']['constraints']['order_id'] ) ) {
			$decision['semantic_frame']['constraints']['order_id'] = absint( $order_ids[0] );
		}

		return $decision;
	}

	private static function hydrate_order_target_params_for_action( $action, $params, $order_ids ) {
		$action = sanitize_text_field( $action );
		$params = is_array( $params ) ? $params : [];
		$order_ids = array_values( array_filter( array_map( 'absint', (array) $order_ids ) ) );

		if ( empty( $order_ids ) ) {
			return $params;
		}

		if ( 'orders.compose_customer_email' === $action ) {
			if ( empty( $params['order_id'] ) ) {
				$params['order_id'] = absint( $order_ids[0] );
			}

			return $params;
		}

		if ( empty( $params['order_ids'] ) || ! is_array( $params['order_ids'] ) ) {
			$params['order_ids'] = $order_ids;
		}

		if ( empty( $params['order_id'] ) ) {
			$params['order_id'] = absint( $order_ids[0] );
		}

		return $params;
	}

	private static function context_resolved_order_ids( $client_context = [] ) {
		$client_context = is_array( $client_context ) ? $client_context : [];
		$order_ids = [];

		if ( ! empty( $client_context['resolved_order_ids'] ) && is_array( $client_context['resolved_order_ids'] ) ) {
			$order_ids = $client_context['resolved_order_ids'];
		}

		if (
			empty( $order_ids ) &&
			! empty( $client_context['resolved_reference'] ) &&
			is_array( $client_context['resolved_reference'] ) &&
			'order' === sanitize_key( $client_context['resolved_reference']['target_type'] ?? '' ) &&
			! empty( $client_context['resolved_reference']['target_ids'] ) &&
			is_array( $client_context['resolved_reference']['target_ids'] )
		) {
			$order_ids = $client_context['resolved_reference']['target_ids'];
		}

		if (
			empty( $order_ids ) &&
			! empty( $client_context['memory_context']['reference'] ) &&
			is_array( $client_context['memory_context']['reference'] ) &&
			'order' === sanitize_key( $client_context['memory_context']['reference']['target_type'] ?? '' ) &&
			! empty( $client_context['memory_context']['reference']['target_ids'] ) &&
			is_array( $client_context['memory_context']['reference']['target_ids'] )
		) {
			$order_ids = $client_context['memory_context']['reference']['target_ids'];
		}

		return array_values(
			array_filter(
				array_unique(
					array_map( 'absint', (array) $order_ids )
				)
			)
		);
	}

	private static function action_accepts_context_order_target( $action ) {
		return in_array(
			sanitize_text_field( $action ),
			[
				'orders.add_note',
				'orders.bulk_update_status_with_note',
				'orders.compose_customer_email',
				'orders.hold',
				'orders.update_status',
				'orders.get_details',
				'orders.refund_preview',
				'orders.email_customer_preview',
				'orders.email_customer_template',
				'orders.use_email_template',
				'orders.save_customer_email_draft',
				'orders.send_customer_email',
			],
			true
		);
	}

	private static function hydrate_queue_preview_decision( $decision, $command, $normalized, $client_context = [] ) {
		$decision = is_array( $decision ) ? $decision : [];
		$action   = sanitize_text_field( $decision['action'] ?? '' );
		$mode     = sanitize_key( $decision['mode'] ?? '' );
		$params   = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$missing  = isset( $decision['missing'] ) && is_array( $decision['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $decision['missing'] ) ) )
			: [];
		$client_context = is_array( $client_context ) ? $client_context : [];

		$preview_map = [
			'workflow.fulfillment_followup_preview' => [
				'context_key'     => 'last_fulfillment_followup',
				'context_field'   => 'queues',
				'clarify_message' => __( 'Open the fulfillment queue first so I can choose the right fulfillment item before preparing a follow-up.', 'yoro-ai-core' ),
				'confidence'      => 0.92,
			],
			'workflow.customer_followup_preview' => [
				'context_key'     => 'last_service_followup',
				'context_field'   => 'queues',
				'clarify_message' => __( 'Open the customer service queue first so I can choose the right follow-up item before preparing a reply.', 'yoro-ai-core' ),
				'confidence'      => 0.92,
			],
			'workflow.operations_attention_preview' => [
				'context_key'     => 'last_attention_followup',
				'context_field'   => 'queues',
				'clarify_message' => __( 'Open the operations attention queue first so I can choose the right attention item before preparing the next review step.', 'yoro-ai-core' ),
				'confidence'      => 0.92,
			],
			'workflow.inventory_pressure_preview' => [
				'context_key'     => 'last_inventory_followup',
				'context_field'   => 'queues',
				'clarify_message' => __( 'Open the inventory queue first so I can choose the right stock issue before preparing the next review step.', 'yoro-ai-core' ),
				'confidence'      => 0.92,
			],
			'workflow.business_summary_preview' => [
				'context_key'     => 'last_business_followup',
				'context_field'   => 'recommended_sequence',
				'clarify_message' => __( 'Open the business summary first so I can choose the right business signal before preparing the next review step.', 'yoro-ai-core' ),
				'confidence'      => 0.92,
			],
		];

		if ( empty( $preview_map[ $action ] ) ) {
			return $decision;
		}

		$internal_missing_keys = [ 'queue_key', 'topic_key', 'reference_index', 'reference_selector' ];
		$has_internal_missing  = ! empty( array_intersect( $missing, $internal_missing_keys ) );
		$action_context        = isset( $client_context[ $preview_map[ $action ]['context_key'] ] ) && is_array( $client_context[ $preview_map[ $action ]['context_key'] ] )
			? $client_context[ $preview_map[ $action ]['context_key'] ]
			: [];
		$has_context_choices   = ! empty( $action_context[ $preview_map[ $action ]['context_field'] ] ) && is_array( $action_context[ $preview_map[ $action ]['context_field'] ] );
		$has_explicit_selector = (
			! empty( $params['queue_key'] ) ||
			! empty( $params['topic_key'] ) ||
			isset( $params['reference_index'] ) ||
			! empty( $params['reference_selector'] )
		);

		if ( $has_context_choices && ( 'clarify' === $mode || $has_internal_missing ) ) {
			$decision['mode']   = 'task_request';
			$decision['missing'] = array_values(
				array_filter(
					$missing,
					static function( $item ) use ( $internal_missing_keys ) {
						return ! in_array( $item, $internal_missing_keys, true );
					}
				)
			);
			$decision['clarification_question'] = '';

			if ( empty( $decision['summary'] ) ) {
				$decision['summary'] = __( 'You asked me to prepare the next guided workflow follow-up.', 'yoro-ai-core' );
			}

			if ( empty( $decision['reasoning_summary'] ) ) {
				$decision['reasoning_summary'] = __( 'The required queue context is already available, so I can choose the next guided item without asking for an internal selector.', 'yoro-ai-core' );
			}

			if ( empty( $decision['evidence'] ) || ! is_array( $decision['evidence'] ) ) {
				$decision['evidence'] = [
					'matched_terms'      => [],
					'referenced_context' => [],
					'notes'              => '',
				];
			}

			$decision['evidence']['notes'] = sanitize_text_field(
				trim(
					implode(
						' ',
						array_filter(
							[
								$decision['evidence']['notes'] ?? '',
								__( 'Queue-based workflow preview was grounded from active follow-up context instead of an internal selector.', 'yoro-ai-core' ),
							]
						)
					)
				)
			);

			return $decision;
		}

		if (
			'clarify' === $mode && (
				$has_internal_missing ||
				( ! $has_context_choices && ! $has_explicit_selector )
			)
		) {
			$decision['missing'] = [];
			$decision['clarification_question'] = $preview_map[ $action ]['clarify_message'];
			$decision['summary'] = $preview_map[ $action ]['clarify_message'];
			$decision['confidence'] = max(
				isset( $decision['confidence'] ) ? (float) $decision['confidence'] : 0,
				(float) $preview_map[ $action ]['confidence']
			);

			if ( empty( $decision['reasoning_summary'] ) ) {
				$decision['reasoning_summary'] = __( 'This workflow needs active queue context, so the right next item should be selected from the queue instead of asking for an internal key.', 'yoro-ai-core' );
			}

			return $decision;
		}

		if ( 'task_request' === $mode && ! $has_context_choices && ! $has_explicit_selector ) {
			$decision['mode'] = 'clarify';
			$decision['missing'] = [];
			$decision['clarification_question'] = $preview_map[ $action ]['clarify_message'];
			$decision['summary'] = $preview_map[ $action ]['clarify_message'];
			$decision['confidence'] = max(
				isset( $decision['confidence'] ) ? (float) $decision['confidence'] : 0,
				(float) $preview_map[ $action ]['confidence']
			);

			if ( empty( $decision['reasoning_summary'] ) ) {
				$decision['reasoning_summary'] = __( 'This workflow preview can only choose the next item from active queue context or an explicit selector.', 'yoro-ai-core' );
			}
		}

		return $decision;
	}
}
