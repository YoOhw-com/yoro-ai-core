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

		$resume = self::resolve_workflow_resume_target( $workflow );

		if ( empty( $resume['action'] ) ) {
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
			'action'             => sanitize_text_field( $resume['action'] ),
			'params'             => isset( $resume['params'] ) && is_array( $resume['params'] ) ? $resume['params'] : [],
			'parser_source'      => 'memory',
			'parser_rule'        => sanitize_text_field( $resume['parser_rule'] ?? 'memory.continue_previous_workflow' ),
			'confidence'         => 1.0,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function parse_retry_failed_step( $command, $normalized, $memory ) {
		if ( ! in_array( $normalized, [ 'retry failed step', 'retry the failed step' ], true ) ) {
			return null;
		}

		$failed = self::resolve_failed_step_target( $memory );

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

	private static function resolve_workflow_resume_target( $workflow ) {
		$workflow = is_array( $workflow ) ? $workflow : [];

		if ( empty( $workflow['action'] ) ) {
			return [];
		}

		$workflow_step   = sanitize_key( $workflow['step'] ?? '' );
		$workflow_status = sanitize_key( $workflow['status'] ?? '' );

		if ( in_array( $workflow_step, [ 'collect_missing_params', 'ready_to_execute', 'preview_ready' ], true ) ) {
			return [
				'action'      => sanitize_text_field( $workflow['action'] ),
				'params'      => self::build_resume_params_from_workflow( $workflow ),
				'parser_rule' => 'memory.continue_previous_workflow',
			];
		}

		if ( 'failed' === $workflow_step || 'failed' === $workflow_status ) {
			return [];
		}

		if ( ! empty( $workflow['params'] ) && is_array( $workflow['params'] ) ) {
			return [
				'action'      => sanitize_text_field( $workflow['action'] ),
				'params'      => $workflow['params'],
				'parser_rule' => 'memory.continue_previous_workflow.legacy',
			];
		}

		return [];
	}

	private static function build_resume_params_from_workflow( $workflow ) {
		$workflow     = is_array( $workflow ) ? $workflow : [];
		$input_context = isset( $workflow['input_context'] ) && is_array( $workflow['input_context'] )
			? $workflow['input_context']
			: [];
		$params       = isset( $workflow['params'] ) && is_array( $workflow['params'] )
			? $workflow['params']
			: [];

		$resume = array_merge( $params, $input_context );

		if ( empty( $resume['original_command'] ) && ! empty( $workflow['last_command'] ) ) {
			$resume['original_command'] = sanitize_textarea_field( $workflow['last_command'] );
		}

		return $resume;
	}

	private static function resolve_failed_step_target( $memory ) {
		$memory    = is_array( $memory ) ? $memory : [];
		$workflow  = isset( $memory['current_workflow'] ) && is_array( $memory['current_workflow'] )
			? $memory['current_workflow']
			: [];
		$last_workflow_result = isset( $memory['last_workflow_result'] ) && is_array( $memory['last_workflow_result'] )
			? $memory['last_workflow_result']
			: [];

		$failed_target = self::extract_failed_step_from_workflow( $workflow );

		if ( ! empty( $failed_target['action'] ) ) {
			return $failed_target;
		}

		$failed_target = self::extract_failed_step_from_workflow(
			isset( $last_workflow_result['workflow'] ) && is_array( $last_workflow_result['workflow'] )
				? $last_workflow_result['workflow']
				: []
		);

		if ( ! empty( $failed_target['action'] ) ) {
			return $failed_target;
		}

		$failed = isset( $memory['last_failed_step'] ) && is_array( $memory['last_failed_step'] )
			? $memory['last_failed_step']
			: [];

		return is_array( $failed ) ? $failed : [];
	}

	private static function extract_failed_step_from_workflow( $workflow ) {
		$workflow = is_array( $workflow ) ? $workflow : [];

		if ( 'failed' !== sanitize_key( $workflow['step'] ?? '' ) && 'failed' !== sanitize_key( $workflow['status'] ?? '' ) ) {
			return [];
		}

		$steps = isset( $workflow['steps'] ) && is_array( $workflow['steps'] )
			? array_values( $workflow['steps'] )
			: [];

		$current_step_index = isset( $workflow['current_step_index'] ) ? (int) $workflow['current_step_index'] : -1;
		$current_step_id    = sanitize_key( $workflow['current_step_id'] ?? '' );

		if ( isset( $steps[ $current_step_index ] ) && ! empty( $steps[ $current_step_index ]['action'] ) ) {
			return [
				'action' => sanitize_text_field( $steps[ $current_step_index ]['action'] ),
				'params' => isset( $steps[ $current_step_index ]['params'] ) && is_array( $steps[ $current_step_index ]['params'] )
					? $steps[ $current_step_index ]['params']
					: [],
			];
		}

		foreach ( $steps as $step ) {
			if ( $current_step_id && $current_step_id !== sanitize_key( $step['id'] ?? '' ) ) {
				continue;
			}

			if ( 'failed' === sanitize_key( $step['status'] ?? '' ) && ! empty( $step['action'] ) ) {
				return [
					'action' => sanitize_text_field( $step['action'] ),
					'params' => isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
				];
			}
		}

		return [];
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
