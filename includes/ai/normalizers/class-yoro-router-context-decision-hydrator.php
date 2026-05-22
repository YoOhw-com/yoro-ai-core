<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Router_Context_Decision_Hydrator {

	public static function hydrate( $decision, $command, $normalized, $client_context = [] ) {
		$decision = self::hydrate_queue_preview_decision(
			$decision,
			$command,
			$normalized,
			$client_context
		);

		return $decision;
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
