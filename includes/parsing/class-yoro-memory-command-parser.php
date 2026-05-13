<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Memory_Command_Parser {

	public static function parse( $command, $normalized, $client_context = [] ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return null;
		}

		$memory = YoRo_Conversation_Memory::get_runtime_context( $user_id );

		$parsed = self::parse_send_that_draft(
			$command,
			$normalized,
			$memory
		);

		if ( $parsed ) {
			return $parsed;
		}

		$parsed = self::parse_continue_previous_workflow(
			$command,
			$normalized,
			$memory
		);

		if ( $parsed ) {
			return $parsed;
		}

		$parsed = self::parse_retry_failed_step(
			$command,
			$normalized,
			$memory
		);

		if ( $parsed ) {
			return $parsed;
		}

		$parsed = self::parse_do_same_for_other_orders(
			$command,
			$normalized,
			$memory,
			$client_context
		);

		if ( $parsed ) {
			return $parsed;
		}

		return null;
	}

	private static function parse_send_that_draft( $command, $normalized, $memory ) {
		if ( ! in_array( $normalized, [ 'send that draft', 'send the draft', 'send this draft' ], true ) ) {
			return null;
		}

		$order_id = absint(
			$memory['last_action_params']['order_id']
				?? $memory['last_referenced_order_id']
				?? 0
		);

		$subject = trim(
			(string) (
				$memory['last_email_subject']
					?? ''
			)
		);

		$body = trim(
			(string) (
				$memory['last_email_body']
					?? ''
			)
		);

		if ( ! $order_id || ! $subject || ! $body ) {
			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'orders.send_customer_email',
				'message'            => __( 'I could not find a recent customer email draft to send.', 'yoro-agent-core' ),
				'params'             => [],
				'parser_source'      => 'memory',
				'parser_rule'        => 'memory.send_that_draft.missing_context',
				'confidence'         => 1.0,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return [
			'success'            => true,
			'matched'            => true,
			'action'             => 'orders.send_customer_email',
			'params'             => [
				'order_id' => $order_id,
				'subject'  => $subject,
				'body'     => $body,
			],
			'parser_source'      => 'memory',
			'parser_rule'        => 'memory.send_that_draft',
			'confidence'         => 1.0,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function parse_continue_previous_workflow( $command, $normalized, $memory ) {
		$workflow = isset( $memory['current_workflow'] ) && is_array( $memory['current_workflow'] )
			? $memory['current_workflow']
			: [];

		if ( ! in_array( $normalized, [ 'continue previous workflow', 'continue workflow', 'resume workflow' ], true ) ) {
			return null;
		}

		if (
			empty( $workflow['action'] ) ||
			empty( $workflow['params'] )
		) {
			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'message'            => __( 'There is no previous workflow ready to continue.', 'yoro-agent-core' ),
				'parser_source'      => 'memory',
				'parser_rule'        => 'memory.continue_workflow.missing_context',
				'confidence'         => 1.0,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return [
			'success'            => true,
			'matched'            => true,
			'action'             => sanitize_text_field( $workflow['action'] ),
			'params'             => is_array( $workflow['params'] ) ? $workflow['params'] : [],
			'parser_source'      => 'memory',
			'parser_rule'        => 'memory.continue_previous_workflow',
			'confidence'         => 1.0,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function parse_retry_failed_step( $command, $normalized, $memory ) {
		if ( ! in_array( $normalized, [ 'retry failed step', 'retry the failed step' ], true ) ) {
			return null;
		}

		$failed = isset( $memory['last_failed_step'] ) && is_array( $memory['last_failed_step'] )
			? $memory['last_failed_step']
			: [];

		if ( empty( $failed['action'] ) ) {
			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'message'            => __( 'I could not find a failed workflow step to retry.', 'yoro-agent-core' ),
				'parser_source'      => 'memory',
				'parser_rule'        => 'memory.retry_failed_step.missing_context',
				'confidence'         => 1.0,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return [
			'success'            => true,
			'matched'            => true,
			'action'             => sanitize_text_field( $failed['action'] ),
			'params'             => isset( $failed['params'] ) && is_array( $failed['params'] )
				? $failed['params']
				: [],
			'parser_source'      => 'memory',
			'parser_rule'        => 'memory.retry_failed_step',
			'confidence'         => 1.0,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function parse_do_same_for_other_orders( $command, $normalized, $memory, $client_context ) {
		if ( ! in_array( $normalized, [ 'do the same for the other orders', 'same for other orders' ], true ) ) {
			return null;
		}

		$last_result = ! empty( $client_context['last_result'] ) && is_array( $client_context['last_result'] )
			? $client_context['last_result']
			: [];

		if ( empty( $last_result ) && isset( $memory['last_result'] ) && is_array( $memory['last_result'] ) ) {
			$last_result = $memory['last_result'];
		}

		$last_action = sanitize_text_field(
			$last_result['action'] ?? $client_context['last_action'] ?? $memory['last_action'] ?? ''
		);

		$params = ! empty( $client_context['last_action_params'] ) && is_array( $client_context['last_action_params'] )
			? $client_context['last_action_params']
			: [];

		if ( empty( $params ) && isset( $memory['last_action_params'] ) && is_array( $memory['last_action_params'] ) ) {
			$params = $memory['last_action_params'];
		}

		$order_ids = self::resolve_other_order_ids_from_context( $memory, $client_context );

		$current_order_id = absint(
			$client_context['current_order_id']
				?? $memory['current_order_id']
				?? $client_context['last_referenced_order_id']
				?? $memory['last_referenced_order_id']
				?? 0
		);

		$order_ids = array_values(
			array_filter(
				array_diff(
					$order_ids,
					[ $current_order_id ]
				)
			)
		);

		if (
			empty( $last_action ) ||
			empty( $order_ids )
		) {
			return null;
		}

		$params['order_ids'] = $order_ids;

		unset( $params['order_id'] );

		return [
			'success'            => true,
			'matched'            => true,
			'action'             => $last_action,
			'params'             => $params,
			'parser_source'      => 'memory',
			'parser_rule'        => 'memory.do_same_for_other_orders',
			'confidence'         => 1.0,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function resolve_other_order_ids_from_context( $memory, $client_context ) {
		$candidates = [];

		if ( ! empty( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] ) ) {
			$candidates = $client_context['last_order_ids'];
		} elseif ( ! empty( $memory['last_order_ids'] ) && is_array( $memory['last_order_ids'] ) ) {
			$candidates = $memory['last_order_ids'];
		}

		$candidates = array_values( array_filter( array_map( 'absint', $candidates ) ) );

		if ( count( $candidates ) > 1 ) {
			return $candidates;
		}

		$recent_order_ids = [];

		if ( ! empty( $client_context['recent_order_ids'] ) && is_array( $client_context['recent_order_ids'] ) ) {
			$recent_order_ids = $client_context['recent_order_ids'];
		} elseif ( ! empty( $memory['recent_order_ids'] ) && is_array( $memory['recent_order_ids'] ) ) {
			$recent_order_ids = $memory['recent_order_ids'];
		} elseif (
			! empty( $memory['entity_context']['recent_order_ids'] ) &&
			is_array( $memory['entity_context']['recent_order_ids'] )
		) {
			$recent_order_ids = $memory['entity_context']['recent_order_ids'];
		}

		$recent_order_ids = array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $recent_order_ids )
				)
			)
		);

		return ! empty( $recent_order_ids ) ? $recent_order_ids : $candidates;
	}
}
