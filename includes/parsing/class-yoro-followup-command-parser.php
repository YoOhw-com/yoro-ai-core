<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Followup_Command_Parser {

	public static function parse_write( $command, $normalized, $client_context = [] ) {

		$parsed = self::parse_cancel_workflow( $command, $normalized, $client_context );

		if ( $parsed ) {
			return $parsed;
		}

		$parsers = [
			'parse_follow_up_email_customer',
			'parse_follow_up_bulk_order_note',
			'parse_follow_up_order_note',
			'parse_follow_up_update_status',
			'parse_follow_up_orders',
		];

		foreach ( $parsers as $parser ) {
			$parsed = self::$parser( $command, $normalized, $client_context );

			if ( $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	private static function parse_cancel_workflow( $command, $normalized, $client_context = [] ) {
		$workflow = isset( $client_context['current_workflow'] ) && is_array( $client_context['current_workflow'] )
			? $client_context['current_workflow']
			: [];

		$has_cancel_anchor = (
			false !== strpos( $normalized, 'cancel' ) ||
			false !== strpos( $normalized, 'discard' ) ||
			false !== strpos( $normalized, 'stop' ) ||
			false !== strpos( $normalized, 'abort' ) ||
			false !== strpos( $normalized, 'never mind' ) ||
			false !== strpos( $normalized, 'nevermind' ) ||
			false !== strpos( $normalized, 'forget it' )
		);

		if ( ! $has_cancel_anchor ) {
			return null;
		}

		$has_workflow_anchor = (
			false !== strpos( $normalized, 'workflow' ) ||
			false !== strpos( $normalized, 'email' ) ||
			false !== strpos( $normalized, 'draft' ) ||
			false !== strpos( $normalized, 'preview' ) ||
			! empty( $workflow )
		);

		if ( ! $has_workflow_anchor ) {
			return null;
		}

		return self::parsed_action(
			'workflow.cancel',
			[],
			$command,
			$normalized,
			'rule.workflow.cancel'
		);
	}

	private static function parse_follow_up_email_customer( $command, $normalized, $client_context ) {
		if (
			false === strpos( $normalized, 'email' ) ||
			(
				false === strpos( $normalized, 'this customer' ) &&
				false === strpos( $normalized, 'that customer' ) &&
				false === strpos( $normalized, 'same customer' )
			)
		) {
			return null;
		}

		$order_id = 0;

		if ( ! empty( $client_context['current_order_id'] ) ) {
			$order_id = absint( $client_context['current_order_id'] );
		}

		if ( ! $order_id && ! empty( $client_context['last_referenced_order_id'] ) ) {
			$order_id = absint( $client_context['last_referenced_order_id'] );
		}

		if ( ! $order_id && ! empty( $client_context['last_order_ids'][0] ) ) {
			$order_id = absint( $client_context['last_order_ids'][0] );
		}

		if ( ! $order_id ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'message'            => __( 'I do not have enough order context yet. Please open or select an order first.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		$instruction = trim( preg_replace(
			'/^(?:write|compose|draft|prepare|email|send)?\s*(?:an?\s+)?email\s+(?:to\s+)?(?:this|that|same)\s+customer\s*/i',
			'',
			$command
		) );

		if ( empty( $instruction ) ) {
			$instruction = $command;
		}

		return self::parsed_action(
			'orders.compose_customer_email',
			[
				'order_id'    => $order_id,
				'instruction' => sanitize_textarea_field( $instruction ),
			],
			$command,
			$normalized,
			'rule.follow_up.customer.compose_email'
		);
	}

	public static function parse_read( $command, $normalized, $client_context = [] ) {
		$parsers = [
			'parse_contextual_workflow_preview',
			'parse_follow_up_business_recommendation',
			'parse_follow_up_attention_recommendation',
			'parse_follow_up_fulfillment_recommendation',
			'parse_follow_up_inventory_recommendation',
			'parse_follow_up_service_recommendation',
			'parse_follow_up_briefing_recommendation',
			'parse_follow_up_briefing_drilldown',
			'parse_follow_up_find_customer_orders',
			'parse_follow_up_order_details',
		];

		foreach ( $parsers as $parser ) {
			$parsed = self::$parser( $command, $normalized, $client_context );

			if ( $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	private static function parse_contextual_workflow_preview( $command, $normalized, $client_context ) {
		$legacy_preview_phrases = [
			'preview the next follow-up',
			'preview the next follow up',
			'preview next follow-up',
			'preview next follow up',
			'preview the next recommendation',
			'preview next recommendation',
			'next recommendation',
		];

		$wants_preview = in_array( $normalized, $legacy_preview_phrases, true );

		if ( ! $wants_preview ) {
			return null;
		}

		$active_followup_type = sanitize_key( $client_context['active_followup_type'] ?? '' );
		$last_result_type = sanitize_key( $client_context['last_result_type'] ?? '' );

		$family = '';

		if ( in_array( $active_followup_type, [ 'business', 'operations_attention', 'fulfillment', 'inventory', 'customer_service' ], true ) ) {
			$family = $active_followup_type;
		} elseif ( in_array( $last_result_type, [ 'business', 'operations_attention', 'fulfillment', 'inventory', 'customer_service' ], true ) ) {
			$family = $last_result_type;
		}

		if ( '' === $family ) {
			return null;
		}

		$map = [
			'business' => [
				'action' => 'workflow.business_summary_preview',
				'context_key' => 'last_business_followup',
				'type' => 'recommended_sequence',
			],
			'operations_attention' => [
				'action' => 'workflow.operations_attention_preview',
				'context_key' => 'last_attention_followup',
				'type' => 'queues',
			],
			'fulfillment' => [
				'action' => 'workflow.fulfillment_followup_preview',
				'context_key' => 'last_fulfillment_followup',
				'type' => 'queues',
			],
			'inventory' => [
				'action' => 'workflow.inventory_pressure_preview',
				'context_key' => 'last_inventory_followup',
				'type' => 'queues',
			],
			'customer_service' => [
				'action' => 'workflow.customer_followup_preview',
				'context_key' => 'last_service_followup',
				'type' => 'queues',
			],
		];

		$definition = $map[ $family ] ?? [];

		if ( empty( $definition['action'] ) || empty( $definition['context_key'] ) ) {
			return null;
		}

		$followup_state = isset( $client_context[ $definition['context_key'] ] ) && is_array( $client_context[ $definition['context_key'] ] )
			? $client_context[ $definition['context_key'] ]
			: [];

		$has_context = (
			'recommended_sequence' === $definition['type'] &&
			! empty( $followup_state['recommended_sequence'] ) &&
			is_array( $followup_state['recommended_sequence'] )
		) || (
			'queues' === $definition['type'] &&
			! empty( $followup_state['queues'] ) &&
			is_array( $followup_state['queues'] )
		);

		if ( ! $has_context ) {
			return null;
		}

		return self::parsed_action(
			$definition['action'],
			[
				'original_command' => $command,
			],
			$command,
			$normalized,
			'rule.follow_up.workflow_preview.contextual'
		);
	}

	private static function parse_follow_up_business_recommendation( $command, $normalized, $client_context ) {
		$active_followup_type = sanitize_key( $client_context['active_followup_type'] ?? '' );
		$last_result_type = sanitize_key( $client_context['last_result_type'] ?? '' );

		if ( '' !== $active_followup_type ) {
			if ( 'business' !== $active_followup_type ) {
				return null;
			}
		} elseif ( 'business' !== $last_result_type ) {
			return null;
		}

		if (
			false === strpos( $normalized, 'do' ) &&
			false === strpos( $normalized, 'run' ) &&
			false === strpos( $normalized, 'start' ) &&
			false === strpos( $normalized, 'take' ) &&
			false === strpos( $normalized, 'follow' ) &&
			false === strpos( $normalized, 'continue' )
		) {
			return null;
		}

		if ( ! self::looks_like_briefing_recommendation_reference( $normalized ) ) {
			return null;
		}

		$last_followup = isset( $client_context['last_business_followup'] ) && is_array( $client_context['last_business_followup'] )
			? $client_context['last_business_followup']
			: [];

		if ( empty( $last_followup ) ) {
			$last_result = isset( $client_context['last_result'] ) && is_array( $client_context['last_result'] )
				? $client_context['last_result']
				: [];

			if ( 'business' === $last_result_type ) {
				$last_followup = $last_result;
			}
		}

		$sequence = isset( $last_followup['recommended_sequence'] ) && is_array( $last_followup['recommended_sequence'] )
			? array_values( $last_followup['recommended_sequence'] )
			: [];
		$current_index = -1;

		if ( isset( $last_followup['recommendation_cursor'] ) ) {
			$current_index = (int) $last_followup['recommendation_cursor'];

			if ( $current_index < 0 ) {
				$current_index = -1;
			}
		}

		if ( $current_index < 0 ) {
			$current_index = self::infer_followup_recommendation_index_from_last_action( $sequence, $client_context, 'last_business_followup' );
		}

		if ( empty( $sequence ) ) {
			return null;
		}

		$index = self::resolve_briefing_recommendation_index( $normalized, $sequence, $current_index );

		if ( null === $index || empty( $sequence[ $index ]['command'] ) ) {
			return null;
		}

		$resolved_command = sanitize_text_field( $sequence[ $index ]['command'] );
		$resolved = YoRo_Parser_Engine::parse( $resolved_command, $client_context );

		if ( is_wp_error( $resolved ) || ! is_array( $resolved ) ) {
			return null;
		}

		$resolved['parser_rule'] = 'rule.follow_up.business.recommendation';
		$resolved['parser_source'] = 'rule';
		$resolved['original_command'] = $command;
		$resolved['normalized_command'] = $normalized;
		$resolved['resolved_followup_command'] = $resolved_command;
		$resolved['business_sequence_index'] = $index;

		if ( ! empty( $resolved['intent'] ) && is_array( $resolved['intent'] ) ) {
			$resolved['intent']['source'] = 'rule';
		}

		return $resolved;
	}

	private static function parse_follow_up_attention_recommendation( $command, $normalized, $client_context ) {
		$active_followup_type = sanitize_key( $client_context['active_followup_type'] ?? '' );
		$last_result_type = sanitize_key( $client_context['last_result_type'] ?? '' );

		if ( '' !== $active_followup_type ) {
			if ( 'operations_attention' !== $active_followup_type ) {
				return null;
			}
		} elseif ( 'operations_attention' !== $last_result_type ) {
			return null;
		}

		if (
			false === strpos( $normalized, 'do' ) &&
			false === strpos( $normalized, 'run' ) &&
			false === strpos( $normalized, 'start' ) &&
			false === strpos( $normalized, 'take' ) &&
			false === strpos( $normalized, 'follow' ) &&
			false === strpos( $normalized, 'continue' )
		) {
			return null;
		}

		if ( ! self::looks_like_briefing_recommendation_reference( $normalized ) ) {
			return null;
		}

		$last_followup = isset( $client_context['last_attention_followup'] ) && is_array( $client_context['last_attention_followup'] )
			? $client_context['last_attention_followup']
			: [];

		if ( empty( $last_followup ) ) {
			$last_result = isset( $client_context['last_result'] ) && is_array( $client_context['last_result'] )
				? $client_context['last_result']
				: [];

			if ( 'operations_attention' === $last_result_type ) {
				$last_followup = $last_result;
			}
		}

		$sequence = isset( $last_followup['recommended_sequence'] ) && is_array( $last_followup['recommended_sequence'] )
			? array_values( $last_followup['recommended_sequence'] )
			: [];
		$current_index = -1;

		if ( isset( $last_followup['recommendation_cursor'] ) ) {
			$current_index = (int) $last_followup['recommendation_cursor'];

			if ( $current_index < 0 ) {
				$current_index = -1;
			}
		}

		if ( $current_index < 0 ) {
			$current_index = self::infer_followup_recommendation_index_from_last_action( $sequence, $client_context, 'last_attention_followup' );
		}

		if ( empty( $sequence ) ) {
			return null;
		}

		$index = self::resolve_briefing_recommendation_index( $normalized, $sequence, $current_index );

		if ( null === $index || empty( $sequence[ $index ]['command'] ) ) {
			return null;
		}

		$resolved_command = sanitize_text_field( $sequence[ $index ]['command'] );
		$resolved = YoRo_Parser_Engine::parse( $resolved_command, $client_context );

		if ( is_wp_error( $resolved ) || ! is_array( $resolved ) ) {
			return null;
		}

		$resolved['parser_rule'] = 'rule.follow_up.attention.recommendation';
		$resolved['parser_source'] = 'rule';
		$resolved['original_command'] = $command;
		$resolved['normalized_command'] = $normalized;
		$resolved['resolved_followup_command'] = $resolved_command;
		$resolved['attention_sequence_index'] = $index;

		if ( ! empty( $resolved['intent'] ) && is_array( $resolved['intent'] ) ) {
			$resolved['intent']['source'] = 'rule';
		}

		return $resolved;
	}

	private static function parse_follow_up_fulfillment_recommendation( $command, $normalized, $client_context ) {
		$active_followup_type = sanitize_key( $client_context['active_followup_type'] ?? '' );
		$last_result_type = sanitize_key( $client_context['last_result_type'] ?? '' );

		if ( '' !== $active_followup_type ) {
			if ( 'fulfillment' !== $active_followup_type ) {
				return null;
			}
		} elseif ( 'fulfillment' !== $last_result_type ) {
			return null;
		}

		if (
			false === strpos( $normalized, 'do' ) &&
			false === strpos( $normalized, 'run' ) &&
			false === strpos( $normalized, 'start' ) &&
			false === strpos( $normalized, 'take' ) &&
			false === strpos( $normalized, 'follow' ) &&
			false === strpos( $normalized, 'continue' )
		) {
			return null;
		}

		if ( ! self::looks_like_briefing_recommendation_reference( $normalized ) ) {
			return null;
		}

		$last_followup = isset( $client_context['last_fulfillment_followup'] ) && is_array( $client_context['last_fulfillment_followup'] )
			? $client_context['last_fulfillment_followup']
			: [];

		if ( empty( $last_followup ) ) {
			$last_result = isset( $client_context['last_result'] ) && is_array( $client_context['last_result'] )
				? $client_context['last_result']
				: [];

			if ( 'fulfillment' === $last_result_type ) {
				$last_followup = $last_result;
			}
		}

		$sequence = isset( $last_followup['recommended_sequence'] ) && is_array( $last_followup['recommended_sequence'] )
			? array_values( $last_followup['recommended_sequence'] )
			: [];
		$current_index = -1;

		if ( isset( $last_followup['recommendation_cursor'] ) ) {
			$current_index = (int) $last_followup['recommendation_cursor'];

			if ( $current_index < 0 ) {
				$current_index = -1;
			}
		}

		if ( $current_index < 0 ) {
			$current_index = self::infer_followup_recommendation_index_from_last_action( $sequence, $client_context, 'last_fulfillment_followup' );
		}

		if ( empty( $sequence ) ) {
			return null;
		}

		$index = self::resolve_briefing_recommendation_index( $normalized, $sequence, $current_index );

		if ( null === $index || empty( $sequence[ $index ]['command'] ) ) {
			return null;
		}

		$resolved_command = sanitize_text_field( $sequence[ $index ]['command'] );
		$resolved = YoRo_Parser_Engine::parse( $resolved_command, $client_context );

		if ( is_wp_error( $resolved ) || ! is_array( $resolved ) ) {
			return null;
		}

		$resolved['parser_rule'] = 'rule.follow_up.fulfillment.recommendation';
		$resolved['parser_source'] = 'rule';
		$resolved['original_command'] = $command;
		$resolved['normalized_command'] = $normalized;
		$resolved['resolved_followup_command'] = $resolved_command;
		$resolved['fulfillment_sequence_index'] = $index;

		if ( ! empty( $resolved['intent'] ) && is_array( $resolved['intent'] ) ) {
			$resolved['intent']['source'] = 'rule';
		}

		return $resolved;
	}

	private static function parse_follow_up_inventory_recommendation( $command, $normalized, $client_context ) {
		$active_followup_type = sanitize_key( $client_context['active_followup_type'] ?? '' );
		$last_result_type = sanitize_key( $client_context['last_result_type'] ?? '' );

		if ( '' !== $active_followup_type ) {
			if ( 'inventory' !== $active_followup_type ) {
				return null;
			}
		} elseif ( 'inventory' !== $last_result_type ) {
			return null;
		}

		if (
			false === strpos( $normalized, 'do' ) &&
			false === strpos( $normalized, 'run' ) &&
			false === strpos( $normalized, 'start' ) &&
			false === strpos( $normalized, 'take' ) &&
			false === strpos( $normalized, 'follow' ) &&
			false === strpos( $normalized, 'continue' )
		) {
			return null;
		}

		if ( ! self::looks_like_briefing_recommendation_reference( $normalized ) ) {
			return null;
		}

		$last_followup = isset( $client_context['last_inventory_followup'] ) && is_array( $client_context['last_inventory_followup'] )
			? $client_context['last_inventory_followup']
			: [];

		if ( empty( $last_followup ) ) {
			$last_result = isset( $client_context['last_result'] ) && is_array( $client_context['last_result'] )
				? $client_context['last_result']
				: [];

			if ( 'inventory' === $last_result_type ) {
				$last_followup = $last_result;
			}
		}

		$sequence = isset( $last_followup['recommended_sequence'] ) && is_array( $last_followup['recommended_sequence'] )
			? array_values( $last_followup['recommended_sequence'] )
			: [];
		$current_index = -1;

		if ( isset( $last_followup['recommendation_cursor'] ) ) {
			$current_index = (int) $last_followup['recommendation_cursor'];

			if ( $current_index < 0 ) {
				$current_index = -1;
			}
		}

		if ( $current_index < 0 ) {
			$current_index = self::infer_followup_recommendation_index_from_last_action( $sequence, $client_context, 'last_inventory_followup' );
		}

		if ( empty( $sequence ) ) {
			return null;
		}

		$index = self::resolve_briefing_recommendation_index( $normalized, $sequence, $current_index );

		if ( null === $index || empty( $sequence[ $index ]['command'] ) ) {
			return null;
		}

		$resolved_command = sanitize_text_field( $sequence[ $index ]['command'] );
		$resolved = YoRo_Parser_Engine::parse( $resolved_command, $client_context );

		if ( is_wp_error( $resolved ) || ! is_array( $resolved ) ) {
			return null;
		}

		$resolved['parser_rule'] = 'rule.follow_up.inventory.recommendation';
		$resolved['parser_source'] = 'rule';
		$resolved['original_command'] = $command;
		$resolved['normalized_command'] = $normalized;
		$resolved['resolved_followup_command'] = $resolved_command;
		$resolved['inventory_sequence_index'] = $index;

		if ( ! empty( $resolved['intent'] ) && is_array( $resolved['intent'] ) ) {
			$resolved['intent']['source'] = 'rule';
		}

		return $resolved;
	}

	private static function parse_follow_up_service_recommendation( $command, $normalized, $client_context ) {
		$active_followup_type = sanitize_key( $client_context['active_followup_type'] ?? '' );
		$last_result_type = sanitize_key( $client_context['last_result_type'] ?? '' );

		if ( 'briefing' === $last_result_type ) {
			return null;
		}

		if ( '' !== $active_followup_type ) {
			if ( 'customer_service' !== $active_followup_type ) {
				return null;
			}
		} elseif ( 'customer_service' !== $last_result_type ) {
			return null;
		}

		if (
			false === strpos( $normalized, 'do' ) &&
			false === strpos( $normalized, 'run' ) &&
			false === strpos( $normalized, 'start' ) &&
			false === strpos( $normalized, 'take' ) &&
			false === strpos( $normalized, 'follow' ) &&
			false === strpos( $normalized, 'continue' )
		) {
			return null;
		}

		if ( ! self::looks_like_briefing_recommendation_reference( $normalized ) ) {
			return null;
		}

		$last_service_followup = isset( $client_context['last_service_followup'] ) && is_array( $client_context['last_service_followup'] )
			? $client_context['last_service_followup']
			: [];

		if ( empty( $last_service_followup ) ) {
			$last_result = isset( $client_context['last_result'] ) && is_array( $client_context['last_result'] )
				? $client_context['last_result']
				: [];

			if ( 'customer_service' === $last_result_type ) {
				$last_service_followup = $last_result;
			}
		}

		$sequence = isset( $last_service_followup['recommended_sequence'] ) && is_array( $last_service_followup['recommended_sequence'] )
			? array_values( $last_service_followup['recommended_sequence'] )
			: [];
		$current_index = -1;

		if ( isset( $last_service_followup['recommendation_cursor'] ) ) {
			$current_index = (int) $last_service_followup['recommendation_cursor'];

			if ( $current_index < 0 ) {
				$current_index = -1;
			}
		}

		if ( $current_index < 0 ) {
			$current_index = self::infer_followup_recommendation_index_from_last_action( $sequence, $client_context, 'last_service_followup' );
		}

		if ( empty( $sequence ) ) {
			return null;
		}

		$index = self::resolve_briefing_recommendation_index( $normalized, $sequence, $current_index );

		if ( null === $index || empty( $sequence[ $index ]['command'] ) ) {
			return null;
		}

		$resolved_command = sanitize_text_field( $sequence[ $index ]['command'] );
		$resolved = YoRo_Parser_Engine::parse( $resolved_command, $client_context );

		if ( is_wp_error( $resolved ) || ! is_array( $resolved ) ) {
			return null;
		}

		$resolved['parser_rule'] = 'rule.follow_up.service.recommendation';
		$resolved['parser_source'] = 'rule';
		$resolved['original_command'] = $command;
		$resolved['normalized_command'] = $normalized;
		$resolved['resolved_followup_command'] = $resolved_command;
		$resolved['service_sequence_index'] = $index;

		if ( ! empty( $resolved['intent'] ) && is_array( $resolved['intent'] ) ) {
			$resolved['intent']['source'] = 'rule';
		}

		return $resolved;
	}

	private static function parse_follow_up_briefing_recommendation( $command, $normalized, $client_context ) {
		if (
			false === strpos( $normalized, 'do' ) &&
			false === strpos( $normalized, 'run' ) &&
			false === strpos( $normalized, 'start' ) &&
			false === strpos( $normalized, 'take' ) &&
			false === strpos( $normalized, 'follow' ) &&
			false === strpos( $normalized, 'continue' )
		) {
			return null;
		}

		if ( ! self::looks_like_briefing_recommendation_reference( $normalized ) ) {
			return null;
		}

		$last_briefing = isset( $client_context['last_briefing'] ) && is_array( $client_context['last_briefing'] )
			? $client_context['last_briefing']
			: [];

		if ( empty( $last_briefing ) ) {
			$last_result_type = sanitize_key( $client_context['last_result_type'] ?? '' );
			$last_result = isset( $client_context['last_result'] ) && is_array( $client_context['last_result'] )
				? $client_context['last_result']
				: [];

			if ( 'briefing' === $last_result_type ) {
				$last_briefing = $last_result;
			}
		}

		$sequence = isset( $last_briefing['recommended_sequence'] ) && is_array( $last_briefing['recommended_sequence'] )
			? array_values( $last_briefing['recommended_sequence'] )
			: [];
		$current_index = -1;

		if ( isset( $last_briefing['recommendation_cursor'] ) ) {
			$current_index = (int) $last_briefing['recommendation_cursor'];

			if ( $current_index < 0 ) {
				$current_index = -1;
			}
		}

		if ( $current_index < 0 ) {
			$current_index = self::infer_briefing_recommendation_index_from_last_action( $sequence, $client_context );
		}

		if ( empty( $sequence ) ) {
			return null;
		}

		$index = self::resolve_briefing_recommendation_index( $normalized, $sequence, $current_index );

		if ( null === $index || empty( $sequence[ $index ]['command'] ) ) {
			return null;
		}

		$resolved_command = sanitize_text_field( $sequence[ $index ]['command'] );
		$resolved = YoRo_Parser_Engine::parse( $resolved_command, $client_context );

		if ( is_wp_error( $resolved ) || ! is_array( $resolved ) ) {
			return null;
		}

		$resolved['parser_rule'] = 'rule.follow_up.briefing.recommendation';
		$resolved['parser_source'] = 'rule';
		$resolved['original_command'] = $command;
		$resolved['normalized_command'] = $normalized;
		$resolved['resolved_followup_command'] = $resolved_command;
		$resolved['briefing_sequence_index'] = $index;

		if ( ! empty( $resolved['intent'] ) && is_array( $resolved['intent'] ) ) {
			$resolved['intent']['source'] = 'rule';
		}

		return $resolved;
	}

	private static function parse_follow_up_briefing_drilldown( $command, $normalized, $client_context ) {
		if (
			false === strpos( $normalized, 'open' ) &&
			false === strpos( $normalized, 'show' ) &&
			false === strpos( $normalized, 'view' ) &&
			false === strpos( $normalized, 'check' ) &&
			false === strpos( $normalized, 'review' )
		) {
			return null;
		}

		if ( ! self::looks_like_briefing_reference( $normalized ) ) {
			return null;
		}

		$last_briefing = isset( $client_context['last_briefing'] ) && is_array( $client_context['last_briefing'] )
			? $client_context['last_briefing']
			: [];

		if ( empty( $last_briefing ) ) {
			$last_result_type = sanitize_key( $client_context['last_result_type'] ?? '' );
			$last_result = isset( $client_context['last_result'] ) && is_array( $client_context['last_result'] )
				? $client_context['last_result']
				: [];

			if ( 'briefing' === $last_result_type ) {
				$last_briefing = $last_result;
			}
		}

		$drilldowns = isset( $last_briefing['priority_drilldowns'] ) && is_array( $last_briefing['priority_drilldowns'] )
			? array_values( $last_briefing['priority_drilldowns'] )
			: [];

		if ( empty( $drilldowns ) ) {
			return null;
		}

		$index = self::resolve_briefing_drilldown_index( $normalized, $drilldowns );

		if ( null === $index || empty( $drilldowns[ $index ]['command'] ) ) {
			return null;
		}

		$resolved_command = sanitize_text_field( $drilldowns[ $index ]['command'] );
		$resolved = YoRo_Parser_Engine::parse( $resolved_command, $client_context );

		if ( is_wp_error( $resolved ) || ! is_array( $resolved ) ) {
			return null;
		}

		$resolved['parser_rule'] = 'rule.follow_up.briefing.drilldown';
		$resolved['parser_source'] = 'rule';
		$resolved['original_command'] = $command;
		$resolved['normalized_command'] = $normalized;
		$resolved['resolved_followup_command'] = $resolved_command;

		if ( ! empty( $resolved['intent'] ) && is_array( $resolved['intent'] ) ) {
			$resolved['intent']['source'] = 'rule';
		}

		return $resolved;
	}

	private static function parse_follow_up_bulk_order_note( $command, $normalized, $client_context ) {
		if ( false === strpos( $normalized, 'note' ) ) {
			return null;
		}

		if ( ! preg_match( '/(?:add\s+note|note)\s+(?:to\s+)?(?:those orders|these orders|all of them|all these|them)\s*:\s*(.+)$/i', $command, $matches ) ) {
			return null;
		}

		$note = sanitize_textarea_field( $matches[1] );

		if ( '' === $note ) {
			return null;
		}

		$order_ids = self::resolve_order_ids_from_context( $normalized, $client_context );

		if ( empty( $order_ids ) ) {
			return self::clarification_response(
				$command,
				$normalized,
				__( 'Which orders should I add the note to?', 'yoro-agent-core' ),
				self::build_order_reference_options( $client_context, $note )
			);
		}

		return self::parsed_action(
			'orders.add_note',
			[
				'order_ids' => $order_ids,
				'note'      => $note,
			],
			$command,
			$normalized,
			'rule.follow_up.orders.bulk_add_note'
		);
	}

	private static function parse_follow_up_order_note( $command, $normalized, $client_context ) {
		if ( false === strpos( $normalized, 'note' ) ) {
			return null;
		}

		$note = '';

		if ( preg_match( '/(?:add\s+note|note)\s+(?:to\s+)?(?:that\s+order|this\s+order|the\s+order|the\s+((?:first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|forth|firth|\d+(?:st|nd|rd|th)?)\s+one)|((?:first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|forth|firth|\d+(?:st|nd|rd|th)?)\s+one)|the\s+last\s+one|last\s+one)\s*:\s*(.+)$/i', $command, $matches ) ) {
			$note = sanitize_textarea_field( end( $matches ) );
		}

		if ( '' === $note ) {
			return null;
		}

		$order_id = self::resolve_order_reference_from_context( $normalized, $client_context );

		if ( ! $order_id ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'message'            => __( 'Which order should I add the note to?', 'yoro-agent-core' ),
				'options'            => self::build_order_reference_options( $client_context, $note ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return self::parsed_action(
			'orders.add_note',
			YoRo_Order_Helper::apply_order_ids_to_params(
				[
					'order_id' => $order_id,
					'note'     => $note,
				]
			),
			$command,
			$normalized,
			'rule.follow_up.orders.add_note'
		);
	}

	private static function parse_follow_up_update_status( $command, $normalized, $client_context ) {
		if (
			false === strpos( $normalized, 'change' ) &&
			false === strpos( $normalized, 'mark' ) &&
			false === strpos( $normalized, 'update' ) &&
			false === strpos( $normalized, 'move' ) &&
			false === strpos( $normalized, 'set' )
		) {
			return null;
		}

		$status = YoRo_Order_Helper::detect_status_from_text( $normalized );

		if ( empty( $status ) ) {
			$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
		}

		$order_ids = self::resolve_order_ids_from_context( $normalized, $client_context );

		if ( empty( $order_ids ) ) {
			return self::clarification_response(
				$command,
				$normalized,
				__( 'Which orders should I update?', 'yoro-agent-core' ),
				self::build_order_reference_options( $client_context )
			);
		}

		return self::parsed_action(
			'orders.update_status',
			[
				'order_ids' => $order_ids,
				'status'    => $status,
				'note'      => __( 'Order status updated by YoRo Agent.', 'yoro-agent-core' ),
			],
			$command,
			$normalized,
			'rule.follow_up.orders.update_status'
		);
	}

	private static function parse_follow_up_orders( $command, $normalized, $client_context ) {
		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? $client_context['last_orders']
			: [];

		if ( empty( $last_orders ) ) {
			return null;
		}

		if ( false === strpos( $normalized, 'hold' ) ) {
			return null;
		}

		$order_ids = self::resolve_order_ids_from_context( $normalized, $client_context );

		if ( empty( $order_ids ) ) {
			return null;
		}

		return self::parsed_action(
			'orders.hold',
			[
				'order_ids' => $order_ids,
			],
			$command,
			$normalized,
			'rule.follow_up.orders.hold'
		);
	}

	private static function parse_follow_up_order_details( $command, $normalized, $client_context ) {
		if ( false !== strpos( $normalized, 'note' ) ) {
			return null;
		}

		$order_ids = self::resolve_order_ids_from_context( $normalized, $client_context );

		if ( ! self::looks_like_follow_up_order_details_anchor_request( $normalized, ! empty( $order_ids ) ) ) {
			return null;
		}

		if ( empty( $order_ids ) ) {
			return null;
		}

		return self::parsed_action(
			'orders.get_details',
			[
				'order_ids' => $order_ids,
			],
			$command,
			$normalized,
			'rule.follow_up.orders.get_details'
		);
	}

	private static function parse_follow_up_find_customer_orders( $command, $normalized, $client_context ) {
		if ( ! self::looks_like_follow_up_customer_orders_anchor_request( $normalized ) ) {
			return null;
		}

		$graph = isset( $client_context['last_entity_graph'] ) && is_array( $client_context['last_entity_graph'] )
			? $client_context['last_entity_graph']
			: [];

		$email = ! empty( $graph['emails'][0] ) ? sanitize_email( $graph['emails'][0] ) : '';
		$phone = ! empty( $graph['phones'][0] ) ? sanitize_text_field( $graph['phones'][0] ) : '';

		if ( empty( $email ) && empty( $phone ) ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'message'            => __( 'I do not have enough customer context yet. Please open or select an order first.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		$params = [
			'limit' => 20,
		];

		if ( $email ) {
			$params['email'] = $email;
		} elseif ( $phone ) {
			$params['phone'] = $phone;
		}

		return self::parsed_action(
			'orders.find_by_customer',
			$params,
			$command,
			$normalized,
			'rule.follow_up.customer.find_orders'
		);
	}

	private static function resolve_order_reference_from_context( $normalized, $client_context ) {
		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? $client_context['last_orders']
			: [];

		if (
			false !== strpos( $normalized, 'that order' ) ||
			false !== strpos( $normalized, 'this order' ) ||
			false !== strpos( $normalized, 'the order' )
		) {
			if ( ! empty( $client_context['current_order_id'] ) ) {
				return absint( $client_context['current_order_id'] );
			}

			if ( ! empty( $client_context['last_referenced_order_id'] ) ) {
				return absint( $client_context['last_referenced_order_id'] );
			}

			if ( ! empty( $last_orders[0]['id'] ) ) {
				return absint( $last_orders[0]['id'] );
			}
		}

		return 0;
	}

	private static function resolve_briefing_drilldown_index( $normalized, $drilldowns ) {
		if (
			false !== strpos( $normalized, 'top priority' ) ||
			false !== strpos( $normalized, 'main priority' ) ||
			false !== strpos( $normalized, 'first view' )
		) {
			return 0;
		}

		if (
			false !== strpos( $normalized, 'second view' ) ||
			false !== strpos( $normalized, 'next view' )
		) {
			return isset( $drilldowns[1] ) ? 1 : null;
		}

		if (
			false !== strpos( $normalized, 'third one' ) ||
			false !== strpos( $normalized, 'third view' )
		) {
			return isset( $drilldowns[2] ) ? 2 : null;
		}

		if (
			false !== strpos( $normalized, 'that view' ) ||
			false !== strpos( $normalized, 'current view' )
		) {
			return 0;
		}

		return null;
	}

	private static function resolve_briefing_recommendation_index( $normalized, $sequence, $current_index = -1 ) {
		if (
			false !== strpos( $normalized, 'do the next recommendation' ) ||
			false !== strpos( $normalized, 'run the next recommendation' ) ||
			false !== strpos( $normalized, 'next recommendation' )
		) {
			if ( $current_index < 0 ) {
				return isset( $sequence[1] ) ? 1 : ( isset( $sequence[0] ) ? 0 : null );
			}

			$next_index = $current_index + 1;

			return isset( $sequence[ $next_index ] ) ? $next_index : null;
		}

		if (
			false !== strpos( $normalized, 'top recommendation' ) ||
			false !== strpos( $normalized, 'first recommendation' )
		) {
			return 0;
		}

		if (
			false !== strpos( $normalized, 'second recommendation' )
		) {
			return isset( $sequence[1] ) ? 1 : null;
		}

		if (
			false !== strpos( $normalized, 'third recommendation' )
		) {
			return isset( $sequence[2] ) ? 2 : null;
		}

		if ( false !== strpos( $normalized, 'that recommendation' ) ) {
			return 0;
		}

		return null;
	}

	private static function looks_like_briefing_reference( $normalized ) {
		return (
			false !== strpos( $normalized, 'top priority' ) ||
			false !== strpos( $normalized, 'main priority' ) ||
			false !== strpos( $normalized, 'first view' ) ||
			false !== strpos( $normalized, 'second view' ) ||
			false !== strpos( $normalized, 'third view' ) ||
			false !== strpos( $normalized, 'next view' ) ||
			false !== strpos( $normalized, 'that view' ) ||
			false !== strpos( $normalized, 'current view' )
		);
	}

	private static function looks_like_briefing_recommendation_reference( $normalized ) {
		return (
			false !== strpos( $normalized, 'recommendation' ) ||
			false !== strpos( $normalized, 'recommended action' ) ||
			false !== strpos( $normalized, 'that recommendation' )
		);
	}

	private static function infer_briefing_recommendation_index_from_last_action( $sequence, $client_context ) {
		$last_briefing = isset( $client_context['last_briefing'] ) && is_array( $client_context['last_briefing'] )
			? $client_context['last_briefing']
			: [];
		$last_action = sanitize_text_field(
			$last_briefing['last_recommendation_action'] ?? $client_context['last_action'] ?? ''
		);
		$last_command = sanitize_text_field(
			$last_briefing['last_recommendation_command'] ?? ''
		);

		if ( '' === $last_action && '' === $last_command ) {
			return -1;
		}

		if ( empty( $sequence ) ) {
			return -1;
		}

		if ( '' !== $last_command ) {
			foreach ( $sequence as $index => $sequence_item ) {
				$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

				if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $last_command ) ) {
					return (int) $index;
				}
			}
		}

		foreach ( $sequence as $index => $sequence_item ) {
			$command = sanitize_text_field( $sequence_item['command'] ?? '' );

			if ( '' === $command ) {
				continue;
			}

			$resolved = YoRo_Parser_Engine::parse( $command, $client_context );

			if (
				is_array( $resolved ) &&
				! empty( $resolved['action'] ) &&
				sanitize_text_field( $resolved['action'] ) === $last_action
			) {
				return (int) $index;
			}
		}

		return -1;
	}

	private static function infer_followup_recommendation_index_from_last_action( $sequence, $client_context, $state_key ) {
		$followup_state = isset( $client_context[ $state_key ] ) && is_array( $client_context[ $state_key ] )
			? $client_context[ $state_key ]
			: [];
		$last_action = sanitize_text_field(
			$followup_state['last_recommendation_action'] ?? $client_context['last_action'] ?? ''
		);
		$last_command = sanitize_text_field(
			$followup_state['last_recommendation_command'] ?? ''
		);

		if ( '' === $last_action && '' === $last_command ) {
			return -1;
		}

		if ( empty( $sequence ) ) {
			return -1;
		}

		if ( '' !== $last_command ) {
			foreach ( $sequence as $index => $sequence_item ) {
				$sequence_command = sanitize_text_field( $sequence_item['command'] ?? '' );

				if ( '' !== $sequence_command && strtolower( $sequence_command ) === strtolower( $last_command ) ) {
					return (int) $index;
				}
			}
		}

		foreach ( $sequence as $index => $sequence_item ) {
			$command = sanitize_text_field( $sequence_item['command'] ?? '' );

			if ( '' === $command ) {
				continue;
			}

			$resolved = YoRo_Parser_Engine::parse( $command, $client_context );

			if (
				is_array( $resolved ) &&
				! empty( $resolved['action'] ) &&
				sanitize_text_field( $resolved['action'] ) === $last_action
			) {
				return (int) $index;
			}
		}

		return -1;
	}

	private static function resolve_current_order_ids_from_context( $client_context ) {
		if ( ! empty( $client_context['current_order_id'] ) ) {
			$order_id = absint( $client_context['current_order_id'] );

			if ( $order_id > 0 ) {
				return [ $order_id ];
			}
		}

		if ( ! empty( $client_context['last_referenced_order_id'] ) ) {
			$order_id = absint( $client_context['last_referenced_order_id'] );

			if ( $order_id > 0 ) {
				return [ $order_id ];
			}
		}

		return [];
	}

	private static function resolve_order_ids_from_context( $normalized, $client_context ) {
		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? $client_context['last_orders']
			: [];

		$last_order_ids = isset( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] )
			? array_map( 'absint', $client_context['last_order_ids'] )
			: [];

		$current_order_ids = self::resolve_current_order_ids_from_context( $client_context );

		if (
			false !== strpos( $normalized, 'this order' ) ||
			false !== strpos( $normalized, 'that order' ) ||
			false !== strpos( $normalized, 'the order' )
		) {
			if ( ! empty( $current_order_ids ) ) {
				return $current_order_ids;
			}
		}

		if (
			false !== strpos( $normalized, 'all of them' ) ||
			false !== strpos( $normalized, 'all these' ) ||
			false !== strpos( $normalized, 'those orders' ) ||
			false !== strpos( $normalized, 'these orders' ) ||
			false !== strpos( $normalized, 'them' )
		) {
			if ( ! empty( $last_orders ) ) {
				return array_values( array_filter( array_map( 'absint', wp_list_pluck( $last_orders, 'id' ) ) ) );
			}

			return array_values( array_filter( $last_order_ids ) );
		}

		return [];
	}

	private static function build_order_reference_options( $client_context, $note = '' ) {
		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? array_slice( $client_context['last_orders'], 0, 5 )
			: [];

		$options = [];

		foreach ( $last_orders as $order ) {
			if ( empty( $order['id'] ) ) {
				continue;
			}

			$label = '#' . absint( $order['id'] );

			if ( ! empty( $order['status'] ) ) {
				$label .= ' — ' . sanitize_text_field( $order['status'] );
			}

			$options[] = [
				'label'   => $label,
				'command' => 'Add note to order ' . absint( $order['id'] ) . ': ' . $note,
			];
		}

		return $options;
	}

	private static function parsed_action( $action, $params, $command, $normalized, $parser_rule ) {
		$registered_action = YoRo_Action_Registry::get( $action );

		return [
			'success'              => true,
			'matched'              => true,
			'action'               => $action,
			'intent' => [
				'name'   => self::intent_name_from_action( $action ),
				'action' => $action,
			],
			'params'               => $params,
			'parser_rule'          => $parser_rule,
			'action_meta'          => [
				'label'                 => $registered_action['label'] ?? '',
				'risk_level'            => $registered_action['risk_level'] ?? 'low',
				'requires_confirmation' => ! empty( $registered_action['requires_confirmation'] ),
				'write_action'          => ! empty( $registered_action['write_action'] ),
			],
			'original_command'     => $command,
			'normalized_command'   => $normalized,
		];
	}

	private static function intent_name_from_action( $action ) {
		$map = [
			'orders.compose_customer_email' => 'email_customer',
			'orders.add_note'               => 'add_note',
			'orders.hold'                   => 'hold_order',
			'orders.update_status'          => 'update_order_status',
			'orders.search'                 => 'search_orders',
			'orders.get_details'            => 'get_order_details',
			'orders.find_by_customer'       => 'find_customer_orders',
		];

		return $map[ $action ] ?? sanitize_key( str_replace( '.', '_', $action ) );
	}

	private static function clarification_response( $command, $normalized, $message, $options = [] ) {
		return [
			'success'            => false,
			'matched'            => false,
			'stage'              => 'clarify',
			'message'            => $message,
			'options'            => $options,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function looks_like_follow_up_order_details_anchor_request( $normalized, $has_order_context = false ) {
		if ( ! $has_order_context ) {
			return false;
		}

		if (
			false !== strpos( $normalized, 'refund' ) ||
			false !== strpos( $normalized, 'email' ) ||
			false !== strpos( $normalized, 'note' ) ||
			false !== strpos( $normalized, 'status' ) ||
			false !== strpos( $normalized, 'shipment' ) ||
			false !== strpos( $normalized, 'shipping label' )
		) {
			return false;
		}

		return (
			false !== strpos( $normalized, 'this order' ) ||
			false !== strpos( $normalized, 'that order' ) ||
			false !== strpos( $normalized, 'same order' ) ||
			false !== strpos( $normalized, 'order detail' ) ||
			false !== strpos( $normalized, 'order details' )
		);
	}

	private static function looks_like_follow_up_customer_orders_anchor_request( $normalized ) {
		$has_customer_anchor = (
			false !== strpos( $normalized, 'this customer' ) ||
			false !== strpos( $normalized, 'same customer' ) ||
			false !== strpos( $normalized, 'that customer' )
		);

		if ( ! $has_customer_anchor ) {
			return false;
		}

		return (
			false !== strpos( $normalized, 'order' ) ||
			false !== strpos( $normalized, 'orders' )
		);
	}
}
