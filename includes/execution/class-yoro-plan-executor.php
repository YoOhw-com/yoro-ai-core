<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Plan_Executor {

	public static function execute( $plan, $confirm, $preview ) {

		if ( ! is_array( $plan ) || empty( $plan['type'] ) ) {
			return new WP_Error(
				'yoro_invalid_plan',
				__( 'Invalid execution plan.', 'yoro-agent-core' )
			);
		}

		$type = sanitize_key( $plan['type'] );

		do_action(
			'yoro_before_plan_execute',
			$plan,
			[
				'confirm' => $confirm,
				'preview' => $preview,
				'type'    => $type,
			]
		);

		if ( 'single' === $type ) {
			return self::execute_single( $plan, $confirm, $preview );
		}

		if ( 'sequence' === $type ) {
			return self::execute_sequence( $plan, $confirm, $preview );
		}

		return new WP_Error(
			'yoro_unknown_plan_type',
			__( 'Unknown plan type.', 'yoro-agent-core' )
		);
	}

	private static function execute_single( $plan, $confirm, $preview ) {
		$action = $plan['action'] ?? '';
		$params = isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : [];

		if ( $preview ) {
			return YoRo_Action_Executor::execute( $action, $params, $confirm, $preview );
		}

		$pre_undo = self::build_pre_execution_undo_for_step( $action, $params );

		$result = YoRo_Action_Executor::execute( $action, $params, $confirm, $preview );

		if ( is_wp_error( $result ) ) {
			return [
				'success'   => false,
				'action'    => $action,
				'message'   => $result->get_error_message(),
				'error'     => [
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				],
				'processed' => [],
				'skipped'   => [],
				'errors'    => [
					[
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					],
				],
			];
		}

		if ( is_array( $result ) && empty( $result['requires_confirmation'] ) ) {
			$post_undo = self::build_post_execution_undo_for_step( $action, $params, $result );
			$undo      = $post_undo ?: $pre_undo;

			if ( $undo ) {
				$undo_steps = [];

				self::append_undo_step(
					$undo_steps,
					array_merge(
						$undo,
						[
							'source' => $post_undo ? 'post_execution' : 'pre_execution',
						]
					)
				);

				$result['undoable']  = ! empty( $undo_steps );
				$result['undo_plan'] = $undo_steps;
			}
		}

		if ( is_array( $result ) ) {
			$undo_plan = isset( $result['undo_plan'] ) && is_array( $result['undo_plan'] )
				? $result['undo_plan']
				: [];

			$result['undo_plan'] = $undo_plan;
			$result['undoable']  = ! empty( $undo_plan );
		}

		return $result;
	}

	private static function execute_sequence( $plan, $confirm, $preview ) {

		$steps = $plan['steps'] ?? [];

		if ( empty( $steps ) ) {
			return new WP_Error(
				'yoro_empty_plan',
				__( 'Plan has no steps.', 'yoro-agent-core' )
			);
		}

		// PREVIEW
		if ( $preview ) {
			$results = [];

			foreach ( $steps as $step ) {
				$action = sanitize_text_field( $step['action'] ?? '' );
				$params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];

				if ( empty( $action ) ) {
					continue;
				}

				$preview_result = YoRo_Action_Executor::execute(
					$action,
					$params,
					false,
					true
				);

				$results[] = [
					'id'       => $step['id'] ?? '',
					'label'    => $step['label'] ?? $action,
					'action'   => $action,
					'params'   => $params,
					'when'     => $step['when'] ?? [],
					'capture'  => $step['capture'] ?? [],
					'on_error' => $step['on_error'] ?? 'stop',
					'retry'    => $step['retry'] ?? [ 'max_attempts' => 1 ],
					'result'   => $preview_result,
				];
			}

			$workflow = isset( $plan['workflow'] ) && is_array( $plan['workflow'] )
				? $plan['workflow']
				: [];

			if ( ! empty( $workflow ) && class_exists( 'YoRo_Workflow_Runner' ) ) {
				$workflow = YoRo_Workflow_Runner::apply_sequence_result(
					$workflow,
					[
						'preview' => true,
						'actions' => $results,
					]
				);
			}

			return [
				'success'      => true,
				'preview'      => true,
				'multi_action' => true,
				'action'       => $plan['action'] ?? 'multi_action',
				'workflow'     => $workflow,
				'summary'      => __( 'Multi-step preview generated.', 'yoro-agent-core' ),
				'message'      => __( 'Multi-step preview generated.', 'yoro-agent-core' ),
				'actions'      => $results,
				'processed'    => [],
				'skipped'      => [],
				'errors'       => [],
				'count'        => count( $results ),
				'read_only'    => true,
				'undoable'     => false,
				'undo_plan'    => [],
				'result_type'  => 'multi_action_preview',
				'display_type' => 'multi_action',
			];
		}

		// CONFIRM REQUIRED
		if ( ! $confirm && self::sequence_requires_confirmation( $steps ) ) {
			$workflow = isset( $plan['workflow'] ) && is_array( $plan['workflow'] )
				? $plan['workflow']
				: [];

			if ( ! empty( $workflow ) && class_exists( 'YoRo_Workflow_Runner' ) ) {
				$workflow = YoRo_Workflow_Runner::apply_sequence_result(
					$workflow,
					[
						'requires_confirmation' => true,
						'actions'               => $steps,
					]
				);
			}

			$confirmation_copy = self::describe_sequence_confirmation( $workflow, $steps );

			return [
				'success'               => false,
				'requires_confirmation' => true,
				'multi_action'          => true,
				'action'                => $plan['action'] ?? 'multi_action',
				'workflow'              => $workflow,
				'summary'               => sanitize_text_field( $confirmation_copy['summary'] ?? '' ),
				'message'               => sanitize_text_field( $confirmation_copy['message'] ?? __( 'This multi-step action requires confirmation.', 'yoro-agent-core' ) ),
				'actions'               => $steps,
				'processed'             => [],
				'skipped'               => [],
				'errors'                => [],
				'count'                 => count( $steps ),
				'read_only'             => true,
				'undoable'              => false,
				'undo_plan'             => [],
				'result_type'           => 'confirmation',
				'display_type'          => 'confirmation',
			];
		}

		// EXECUTE
		$results    = [];
		$undo_steps = [];
		$context    = [];
		$workflow_errors = [];
		$workflow = isset( $plan['workflow'] ) && is_array( $plan['workflow'] )
			? $plan['workflow']
			: [];

		if ( ! empty( $workflow ) && class_exists( 'YoRo_Workflow_Runner' ) ) {
			$workflow = YoRo_Workflow_Runner::begin_sequence_execution( $workflow );
		}

		foreach ( $steps as $index => $step ) {

			$action = sanitize_text_field( $step['action'] ?? '' );
			$params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];

			if ( empty( $action ) ) {
				continue;
			}

			if ( ! self::should_run_step( $step, $context, $plan['parsed'] ?? [] ) ) {
				$results[] = [
					'id'       => $step['id'] ?? '',
					'label'    => $step['label'] ?? $action,
					'action'   => $action,
					'params'   => $params,
					'when'     => $step['when'] ?? [],
					'capture'  => $step['capture'] ?? [],
					'on_error' => $step['on_error'] ?? 'stop',
					'retry'    => $step['retry'] ?? [ 'max_attempts' => 1 ],
					'skipped'  => true,
					'reason'   => __( 'Step condition was not met.', 'yoro-agent-core' ),
					'result'   => [
						'success'      => true,
						'skipped'      => true,
						'action'       => $action,
						'message'      => __( 'Step skipped because its condition was not met.', 'yoro-agent-core' ),
						'display_type' => 'workflow_step_skipped',
						'read_only'    => true,
					],
				];

				continue;
			}

			$params = self::resolve_params(
				$action,
				$params,
				$context,
				$plan['parsed'] ?? []
			);

			if ( ! empty( $workflow ) && class_exists( 'YoRo_Workflow_Runner' ) ) {
				$workflow = YoRo_Workflow_Runner::mark_step_executing( $workflow, $step, $index );
			}

			do_action( 'yoro_workflow_step_start', $workflow, $step, $params, $plan );

			$pre_undo = self::build_pre_execution_undo_for_step( $action, $params );

			$retry = isset( $step['retry'] ) && is_array( $step['retry'] )
				? $step['retry']
				: [ 'max_attempts' => 1 ];

			$max_attempts = isset( $retry['max_attempts'] )
				? max( 1, absint( $retry['max_attempts'] ) )
				: 1;

			$attempt = 0;
			$result  = null;

			while ( $attempt < $max_attempts ) {
				$attempt++;

				$result = YoRo_Action_Executor::execute(
					$action,
					$params,
					true,
					false
				);

				if ( ! self::is_step_failure( $result ) ) {
					break;
				}
			}

			do_action( 'yoro_workflow_step_finish', $workflow, $step, $params, $result, $plan );

			if ( self::is_step_failure( $result ) ) {
				$error_message = is_wp_error( $result )
					? $result->get_error_message()
					: ( $result['message'] ?? __( 'Workflow step failed.', 'yoro-agent-core' ) );

				$error_code = is_wp_error( $result )
					? $result->get_error_code()
					: ( $result['error']['code'] ?? 'workflow_step_failed' );

				$error_data = is_wp_error( $result )
					? $result->get_error_data()
					: ( $result['error']['data'] ?? null );

				$failed_step = [
					'id'       => $step['id'] ?? '',
					'label'    => $step['label'] ?? $action,
					'action'   => $action,
					'params'   => $params,
					'when'     => $step['when'] ?? [],
					'capture'  => $step['capture'] ?? [],
					'on_error' => $step['on_error'] ?? 'stop',
					'retry'    => $step['retry'] ?? [ 'max_attempts' => 1 ],
					'attempts' => $attempt,
					'failed'   => true,
					'result'   => [
						'success' => false,
						'action'  => $action,
						'message' => $error_message,
						'error'   => [
							'code'    => $error_code,
							'message' => $error_message,
							'data'    => $error_data,
						],
					],
				];

				$workflow_errors[] = [
					'code'    => $error_code,
					'message' => $error_message,
					'action'  => $action,
					'step_id' => $step['id'] ?? '',
				];

				$results[] = $failed_step;

				$error_policy = self::normalize_on_error_policy( $step['on_error'] ?? 'stop' );

				if ( 'continue' === $error_policy ) {
					continue;
				}

				if ( 'skip_remaining' === $error_policy ) {
					if ( ! empty( $workflow ) && class_exists( 'YoRo_Workflow_Runner' ) ) {
						$workflow = YoRo_Workflow_Runner::apply_sequence_result(
							$workflow,
							[
								'success' => false,
								'actions' => $results,
							]
						);
					}

					return [
						'success'        => false,
						'multi_action'   => true,
						'action'         => $plan['action'] ?? 'multi_action',
						'workflow'       => $workflow,
						'summary'        => __( 'Workflow stopped after a failed step.', 'yoro-agent-core' ),
						'message'        => __( 'A workflow step failed, so remaining steps were skipped.', 'yoro-agent-core' ),
						'failed_action'  => $action,
						'step_attempts'  => $attempt,
						'actions'        => $results,
						'undo_plan'      => $undo_steps,
						'rollback'       => [],
						'processed'      => self::collect_processed_ids( $results ),
						'skipped'        => [],
						'errors'         => [
							[
								'code'    => $error_code,
								'message' => $error_message,
							],
						],
						'count'          => count( $results ),
						'read_only'      => false,
						'undoable'       => ! empty( $undo_steps ),
						'result_type'    => 'multi_action',
						'display_type'   => 'multi_action',
					];
				}

				$rollback_results = [];

				if ( 'rollback' === $error_policy ) {
					$rollback_results = self::rollback( $undo_steps );
				}

				if ( ! empty( $workflow ) && class_exists( 'YoRo_Workflow_Runner' ) ) {
					$workflow = YoRo_Workflow_Runner::apply_sequence_result(
						$workflow,
						[
							'success' => false,
							'actions' => $results,
						]
					);
				}

				return [
					'success'        => false,
					'multi_action'   => true,
					'action'         => $action,
					'workflow'       => $workflow,
					'message'        => $error_message,
					'failed_action'  => $action,
					'step_attempts'  => $attempt,
					'error_policy'   => $error_policy,
					'error'          => [
						'code'    => $error_code,
						'message' => $error_message,
						'data'    => $error_data,
					],
					'actions'        => $results,
					'undo_plan'      => $undo_steps,
					'rollback'       => $rollback_results,
					'processed'      => self::collect_processed_ids( $results ),
					'skipped'        => [],
					'errors'         => [
						[
							'code'    => $error_code,
							'message' => $error_message,
						],
					],
					'count'          => count( $results ),
					'read_only'      => false,
					'undoable'       => ! empty( $undo_steps ),
					'result_type'    => 'multi_action',
					'display_type'   => 'multi_action',
				];
			}

			$context = self::capture_step_output(
				$context,
				$step,
				$result
			);

			$context['previous_success'] = ! empty( $result['success'] );
			$context['previous_action']  = $action;

			/*
			* Capture order IDs from search result for the next steps.
			*/
			if ( 'orders.search' === $action && ! empty( $result['orders'] ) && is_array( $result['orders'] ) ) {
				$order_ids = [];

				foreach ( $result['orders'] as $order ) {
					if ( ! empty( $order['id'] ) ) {
						$order_ids[] = absint( $order['id'] );
					}
				}

				$order_ids = array_values( array_filter( array_unique( $order_ids ) ) );

				if ( ! empty( $order_ids ) ) {
					$context['order_ids'] = $order_ids;
				}
			}

			$post_undo = self::build_post_execution_undo_for_step( $action, $params, $result );

			$undo = $post_undo ?: $pre_undo;

			if ( $undo ) {
				self::append_undo_step(
					$undo_steps,
					array_merge(
						$undo,
						[
							'source' => $post_undo ? 'post_execution' : 'pre_execution',
						]
					)
				);
			}

			$results[] = [
				'id'       => $step['id'] ?? '',
				'label'    => $step['label'] ?? $action,
				'action'   => $action,
				'params'   => $params,
				'when'     => $step['when'] ?? [],
				'capture'  => $step['capture'] ?? [],
				'on_error' => $step['on_error'] ?? 'stop',
				'retry'    => $step['retry'] ?? [ 'max_attempts' => 1 ],
				'attempts' => $attempt,
				'result'   => $result,
			];
		}

		if ( ! empty( $workflow ) && class_exists( 'YoRo_Workflow_Runner' ) ) {
			$workflow = YoRo_Workflow_Runner::apply_sequence_result(
				$workflow,
				[
					'success' => true,
					'actions' => $results,
				]
			);
		}

		$completion_copy = self::describe_sequence_completion( $workflow, $workflow_errors );

		return [
			'success'      => true,
			'multi_action' => true,
			'action'       => $plan['action'] ?? 'multi_action',
			'workflow'     => $workflow,
			'summary' => $completion_copy['summary'],
			'message' => $completion_copy['message'],
			'actions'      => $results,
			'processed'    => self::collect_processed_ids( $results ),
			'skipped'      => [],
			'errors'       => $workflow_errors,
			'count'        => count( $results ),
			'read_only'    => false,
			'undoable'     => ! empty( $undo_steps ),
			'undo_plan'    => $undo_steps,
			'result_type'  => 'multi_action',
			'display_type' => 'multi_action',
		];
	}

	private static function describe_sequence_confirmation( $workflow, $steps ) {
		$workflow      = is_array( $workflow ) ? $workflow : [];
		$definition_id = sanitize_key( $workflow['definition_id'] ?? $workflow['type'] ?? '' );
		$branch        = isset( $workflow['branch'] ) && is_array( $workflow['branch'] ) ? $workflow['branch'] : [];
		$delivery_mode = sanitize_key( $branch['delivery_mode'] ?? '' );
		$email_mode    = sanitize_key( $branch['email_delivery_mode'] ?? '' );

		if ( 'write_email_for_order' === $definition_id ) {
			if ( 'save_draft' === $delivery_mode ) {
				return [
					'summary' => __( 'Confirm draft save', 'yoro-agent-core' ),
					'message' => __( 'Review this email workflow before YoRo saves the draft to the order.', 'yoro-agent-core' ),
				];
			}

			if ( 'send' === $delivery_mode ) {
				return [
					'summary' => __( 'Confirm email send', 'yoro-agent-core' ),
					'message' => __( 'Review this email workflow before YoRo sends the message to the customer.', 'yoro-agent-core' ),
				];
			}
		}

		if ( 'verify_customer_before_fulfillment' === $definition_id ) {
			if ( 'save_draft' === $email_mode ) {
				return [
					'summary' => __( 'Confirm verification workflow', 'yoro-agent-core' ),
					'message' => __( 'Review this verification workflow before YoRo holds the order, adds the note, and saves the verification email draft.', 'yoro-agent-core' ),
				];
			}

			if ( 'send' === $email_mode ) {
				return [
					'summary' => __( 'Confirm verification workflow', 'yoro-agent-core' ),
					'message' => __( 'Review this verification workflow before YoRo holds the order, adds the note, and sends the verification email.', 'yoro-agent-core' ),
				];
			}
		}

		if ( 'refund_review' === $definition_id ) {
			if ( 'save_draft' === $delivery_mode ) {
				return [
					'summary' => __( 'Confirm refund review workflow', 'yoro-agent-core' ),
					'message' => __( 'Review this refund workflow before YoRo saves the refund follow-up email draft.', 'yoro-agent-core' ),
				];
			}

			if ( 'send' === $delivery_mode ) {
				return [
					'summary' => __( 'Confirm refund review workflow', 'yoro-agent-core' ),
					'message' => __( 'Review this refund workflow before YoRo sends the refund follow-up email.', 'yoro-agent-core' ),
				];
			}
		}

		return [
			'summary' => '',
			'message' => __( 'This multi-step action requires confirmation.', 'yoro-agent-core' ),
		];
	}

	private static function describe_sequence_completion( $workflow, $workflow_errors ) {
		$workflow      = is_array( $workflow ) ? $workflow : [];
		$definition_id = sanitize_key( $workflow['definition_id'] ?? $workflow['type'] ?? '' );
		$branch        = isset( $workflow['branch'] ) && is_array( $workflow['branch'] ) ? $workflow['branch'] : [];
		$delivery_mode = sanitize_key( $branch['delivery_mode'] ?? '' );
		$email_mode    = sanitize_key( $branch['email_delivery_mode'] ?? '' );
		$has_errors    = ! empty( $workflow_errors );

		if ( $has_errors ) {
			return [
				'summary' => __( 'Workflow completed with errors.', 'yoro-agent-core' ),
				'message' => __( 'Workflow completed, but one or more steps failed.', 'yoro-agent-core' ),
			];
		}

		if ( 'write_email_for_order' === $definition_id ) {
			if ( 'save_draft' === $delivery_mode ) {
				return [
					'summary' => __( 'Email draft saved.', 'yoro-agent-core' ),
					'message' => __( 'YoRo wrote the email and saved the draft on the order.', 'yoro-agent-core' ),
				];
			}

			if ( 'send' === $delivery_mode ) {
				return [
					'summary' => __( 'Email sent.', 'yoro-agent-core' ),
					'message' => __( 'YoRo wrote the email and sent it to the customer.', 'yoro-agent-core' ),
				];
			}

			return [
				'summary' => __( 'Email prepared.', 'yoro-agent-core' ),
				'message' => __( 'YoRo wrote the email and left it ready for review.', 'yoro-agent-core' ),
			];
		}

		if ( 'verify_customer_before_fulfillment' === $definition_id ) {
			if ( 'save_draft' === $email_mode ) {
				return [
					'summary' => __( 'Verification workflow completed.', 'yoro-agent-core' ),
					'message' => __( 'YoRo held the order, added the verification note, and saved the verification email draft.', 'yoro-agent-core' ),
				];
			}

			if ( 'send' === $email_mode ) {
				return [
					'summary' => __( 'Verification workflow completed.', 'yoro-agent-core' ),
					'message' => __( 'YoRo held the order, added the verification note, and sent the verification email.', 'yoro-agent-core' ),
				];
			}
		}

		if ( 'refund_review' === $definition_id ) {
			if ( 'save_draft' === $delivery_mode ) {
				return [
					'summary' => __( 'Refund review completed.', 'yoro-agent-core' ),
					'message' => __( 'YoRo reviewed the refund and saved the follow-up email draft.', 'yoro-agent-core' ),
				];
			}

			if ( 'send' === $delivery_mode ) {
				return [
					'summary' => __( 'Refund review completed.', 'yoro-agent-core' ),
					'message' => __( 'YoRo reviewed the refund and sent the follow-up email.', 'yoro-agent-core' ),
				];
			}

			return [
				'summary' => __( 'Refund review completed.', 'yoro-agent-core' ),
				'message' => __( 'YoRo reviewed the refundable amount and order details.', 'yoro-agent-core' ),
			];
		}

		return [
			'summary' => __( 'Multi-step action completed.', 'yoro-agent-core' ),
			'message' => __( 'Multi-step action completed.', 'yoro-agent-core' ),
		];
	}

	private static function rollback( $undo_steps ) {
		$results = [];

		foreach ( array_reverse( $undo_steps ) as $step ) {
			$step_action = sanitize_text_field( $step['action'] ?? '' );
			$step_params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];

			if ( empty( $step_action ) ) {
				continue;
			}

			$result = YoRo_Action_Executor::execute(
				$step_action,
				$step_params,
				true,
				false,
				[
					'is_undo' => true,
				]
			);

			$results[] = [
				'action' => $step_action,
				'params' => $step_params,
				'result' => is_wp_error( $result )
					? [
						'success' => false,
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					]
					: $result,
			];
		}

		return $results;
	}

	private static function build_pre_execution_undo_for_step( $action, $params ) {

		switch ( $action ) {

			case 'orders.hold':
			case 'orders.update_status':
			case 'orders.bulk_update_status_with_note':
				$order_ids = self::extract_order_ids_from_params( $params );

				if ( empty( $order_ids ) ) {
					return null;
				}

				$previous_statuses = [];

				foreach ( $order_ids as $order_id ) {
					$order = wc_get_order( $order_id );

					if ( ! $order ) {
						continue;
					}

					$previous_statuses[ $order_id ] = $order->get_status();
				}

				if ( empty( $previous_statuses ) ) {
					return null;
				}

				return [
					'action' => 'orders.restore_statuses',
					'params' => [
						'statuses' => $previous_statuses,
					],
				];
		}

		return null;
	}

	private static function build_post_execution_undo_for_step( $action, $params, $result ) {

		switch ( $action ) {

			case 'orders.add_note':
				$note_ids = ! empty( $result['note_ids'] ) && is_array( $result['note_ids'] )
					? array_values( array_unique( array_filter( array_map( 'absint', $result['note_ids'] ) ) ) )
					: [];

				if ( empty( $note_ids ) ) {
					return null;
				}

				return [
					'action' => 'orders.remove_note',
					'params' => [
						'note_ids' => $note_ids,
					],
				];
		}

		return null;
	}

	private static function extract_order_ids_from_params( $params ) {
		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = array_map( 'absint', $params['order_ids'] );
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ absint( $params['order_id'] ) ];
		}

		return array_values( array_filter( array_unique( $order_ids ) ) );
	}

	private static function normalize_undo_step( $step, $index = 0 ) {
		if ( empty( $step ) || ! is_array( $step ) || empty( $step['action'] ) ) {
			return null;
		}

		return [
			'id'        => $step['id'] ?? 'undo_step_' . ( $index + 1 ),
			'action'    => sanitize_text_field( $step['action'] ),
			'params'    => isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
			'source'    => sanitize_key( $step['source'] ?? 'runtime' ),
			'created_at'=> current_time( 'mysql' ),
		];
	}

	private static function append_undo_step( &$undo_steps, $step ) {
		$normalized = self::normalize_undo_step( $step, count( $undo_steps ) );

		if ( ! $normalized ) {
			return;
		}

		$undo_steps[] = $normalized;
	}

	private static function resolve_params( $action, $params, $context, $parsed ) {

		$params = is_array( $params ) ? $params : [];
		$context = is_array( $context ) ? $context : [];
		$parsed = is_array( $parsed ) ? $parsed : [];

		/*
		* First pass: resolve workflow/context placeholders.
		*
		* Supports:
		* - {{email_subject}}
		* - {{email_body}}
		* - {{context.email_subject}}
		* - {{order_id}}
		* - nested arrays
		*/
		$params = self::replace_context_placeholders( $params, $context, $parsed );

		/*
		* Second pass: resolve simple placeholders that may not be handled
		* by replace_context_placeholders(), especially AI workflow captures.
		*/
		foreach ( $params as $key => $value ) {
			if ( is_string( $value ) ) {
				if (
					preg_match(
						'/^\{\{([a-zA-Z0-9_\.\-]+)\}\}$/',
						$value,
						$matches
					)
				) {
					$placeholder = (string) $matches[1];

					$context_key = $placeholder;

					if ( 0 === strpos( $context_key, 'context.' ) ) {
						$context_key = substr( $context_key, 8 );
					}

					if ( array_key_exists( $context_key, $context ) ) {
						$params[ $key ] = $context[ $context_key ];
					} elseif ( array_key_exists( $context_key, $parsed ) ) {
						$params[ $key ] = $parsed[ $context_key ];
					} elseif ( ! empty( $parsed['params'] ) && is_array( $parsed['params'] ) && array_key_exists( $context_key, $parsed['params'] ) ) {
						$params[ $key ] = $parsed['params'][ $context_key ];
					}
				} else {
					/*
					* Some AI workflow plans emit bare context aliases like:
					* - email_subject
					* - email_body
					* - context.email_subject
					*
					* Treat those as resolvable workflow tokens instead of
					* literal final values.
					*/
					$context_key = trim( $value );

					if ( 0 === strpos( $context_key, 'context.' ) ) {
						$context_key = substr( $context_key, 8 );
					}

					if (
						preg_match( '/^[a-zA-Z0-9_\.\-]+$/', $context_key ) &&
						array_key_exists( $context_key, $context )
					) {
						$params[ $key ] = $context[ $context_key ];
					} elseif (
						preg_match( '/^[a-zA-Z0-9_\.\-]+$/', $context_key ) &&
						array_key_exists( $context_key, $parsed )
					) {
						$params[ $key ] = $parsed[ $context_key ];
					} elseif (
						preg_match( '/^[a-zA-Z0-9_\.\-]+$/', $context_key ) &&
						! empty( $parsed['params'] ) &&
						is_array( $parsed['params'] ) &&
						array_key_exists( $context_key, $parsed['params'] )
					) {
						$params[ $key ] = $parsed['params'][ $context_key ];
					}
				}
			}
		}

		$registered = YoRo_Action_Registry::get( $action );

		if ( ! $registered || empty( $registered['auto_resolve'] ) ) {
			return $params;
		}

		foreach ( $registered['auto_resolve'] as $param_key => $sources ) {

			if ( ! is_array( $sources ) ) {
				$sources = [ $sources ];
			}

			if ( ! empty( $params[ $param_key ] ) ) {
				continue;
			}

			foreach ( $sources as $source ) {

				$value = self::resolve_source( $source, $params, $context, $parsed );

				if ( ! empty( $value ) ) {
					$params[ $param_key ] = $value;
					break;
				}
			}
		}

		return $params;
	}

	private static function resolve_source( $source, $params, $context, $parsed ) {

		// params.*
		if ( 0 === strpos( $source, 'params.' ) ) {
			$key = substr( $source, 7 );
			return $params[ $key ] ?? null;
		}

		// context.*
		if ( 0 === strpos( $source, 'context.' ) ) {
			$key = substr( $source, 8 );
			return $context[ $key ] ?? null;
		}

		// parsed.entities.*
		if ( 0 === strpos( $source, 'parsed.entities.' ) ) {
			$key = substr( $source, 16 );
			return $parsed['entities'][ $key ] ?? null;
		}

		return null;
	}

	private static function collect_processed_ids( $results ) {
		$processed = [];

		foreach ( $results as $item ) {
			if ( empty( $item['result']['processed'] ) || ! is_array( $item['result']['processed'] ) ) {
				continue;
			}

			foreach ( $item['result']['processed'] as $id ) {
				$processed[] = absint( $id );
			}
		}

		return array_values(
			array_filter(
				array_unique( $processed )
			)
		);
	}

	private static function sequence_requires_confirmation( $steps ) {
		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return true;
		}

		foreach ( $steps as $step ) {
			$action_name = sanitize_text_field( $step['action'] ?? '' );

			if ( empty( $action_name ) ) {
				continue;
			}

			$registered = YoRo_Action_Registry::get( $action_name );

			if ( ! $registered ) {
				return true;
			}

			if ( ! empty( $registered['requires_confirmation'] ) ) {
				return true;
			}

			if ( ! empty( $registered['write_action'] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function capture_step_output( $context, $step, $result ) {
		$context = is_array( $context ) ? $context : [];
		$step    = is_array( $step ) ? $step : [];
		$result  = is_array( $result ) ? $result : [];

		$step_id = ! empty( $step['id'] ) ? sanitize_key( $step['id'] ) : '';

		if ( $step_id ) {
			if ( empty( $context['steps'] ) || ! is_array( $context['steps'] ) ) {
				$context['steps'] = [];
			}

			$context['steps'][ $step_id ] = [
				'action' => sanitize_text_field( $step['action'] ?? '' ),
				'result' => $result,
			];
		}

		$capture = isset( $step['capture'] ) && is_array( $step['capture'] )
			? $step['capture']
			: [];

		foreach ( $capture as $key => $path ) {
			$key  = sanitize_key( $key );
			$path = is_string( $path ) ? trim( $path ) : '';

			if ( '' === $key || '' === $path ) {
				continue;
			}

			$value = self::get_value_by_path(
				[
					'result' => $result,
					'step'   => $step,
				],
				$path
			);

			if ( null !== $value ) {
				$context[ $key ] = $value;
			}
		}

		return $context;
	}

	private static function get_value_by_path( $data, $path ) {
		if ( ! is_array( $data ) || ! is_string( $path ) || '' === $path ) {
			return null;
		}

		$path = preg_replace( '/^\$\./', '', $path );
		$path = preg_replace( '/^\./', '', $path );

		$segments = array_filter(
			explode( '.', $path ),
			static function( $segment ) {
				return '' !== $segment;
			}
		);

		$current = $data;

		foreach ( $segments as $segment ) {
			if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
				$current = $current[ $segment ];
				continue;
			}

			return null;
		}

		return $current;
	}

	private static function replace_context_placeholders( $value, $context, $parsed = [] ) {
		if ( is_array( $value ) ) {
			$replaced = [];

			foreach ( $value as $key => $item ) {
				$replaced[ $key ] = self::replace_context_placeholders( $item, $context, $parsed );
			}

			return $replaced;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( ! preg_match_all( '/{{\s*([^}]+)\s*}}/', $value, $matches ) ) {
			return $value;
		}

		$result = $value;

		foreach ( $matches[1] as $placeholder ) {
			$placeholder = trim( $placeholder );

			$resolved = self::get_value_by_path(
				[
					'context' => $context,
					'parsed'  => $parsed,
				],
				$placeholder
			);

			if ( null === $resolved ) {
				continue;
			}

			if ( $value === '{{' . $placeholder . '}}' ) {
				return $resolved;
			}

			if ( is_scalar( $resolved ) ) {
				$result = str_replace(
					'{{' . $placeholder . '}}',
					(string) $resolved,
					$result
				);
			}
		}

		return $result;
	}

	private static function should_run_step( $step, $context, $parsed = [] ) {
		$when = isset( $step['when'] ) && is_array( $step['when'] )
			? $step['when']
			: [];

		if ( empty( $when ) ) {
			return true;
		}

		$context = is_array( $context ) ? $context : [];
		$parsed  = is_array( $parsed ) ? $parsed : [];
		$step    = is_array( $step ) ? $step : [];

		/*
		* New B6 shape:
		*
		* [
		*   'all' => [
		*     [
		*       'source'   => 'context.email_subject',
		*       'operator' => 'not_empty',
		*     ],
		*   ],
		* ]
		*/
		if ( isset( $when['all'] ) && is_array( $when['all'] ) ) {
			foreach ( $when['all'] as $condition ) {
				if ( ! self::evaluate_when_condition( $condition, $step, $context, $parsed ) ) {
					return false;
				}
			}
		}

		if ( isset( $when['any'] ) && is_array( $when['any'] ) ) {
			foreach ( $when['any'] as $condition ) {
				if ( self::evaluate_when_condition( $condition, $step, $context, $parsed ) ) {
					return true;
				}
			}

			return false;
		}

		if ( isset( $when['source'], $when['operator'] ) ) {
			return self::evaluate_when_condition( $when, $step, $context, $parsed );
		}

		/*
		* Legacy shape:
		*
		* [
		*   'path'     => 'context.xxx',
		*   'operator' => 'truthy',
		*   'value'    => true,
		* ]
		*/
		$path = isset( $when['path'] ) ? trim( (string) $when['path'] ) : '';

		if ( '' === $path ) {
			return true;
		}

		$operator = isset( $when['operator'] )
			? sanitize_key( $when['operator'] )
			: 'truthy';

		$expected = $when['value'] ?? true;

		$actual = self::get_value_by_path(
			[
				'context' => $context,
				'parsed'  => $parsed,
				'step'    => $step,
			],
			$path
		);

		return self::compare_condition_value( $actual, $operator, $expected );
	}

	private static function evaluate_when_condition( $condition, $step, $context, $parsed = [] ) {
		if ( ! is_array( $condition ) ) {
			return false;
		}

		$source = isset( $condition['source'] )
			? trim( (string) $condition['source'] )
			: '';

		$operator = isset( $condition['operator'] )
			? sanitize_key( $condition['operator'] )
			: 'truthy';

		$expected = $condition['value'] ?? true;

		if ( '' === $source ) {
			return false;
		}

		$actual = self::get_value_by_path(
			[
				'context' => is_array( $context ) ? $context : [],
				'parsed'  => is_array( $parsed ) ? $parsed : [],
				'step'    => is_array( $step ) ? $step : [],
			],
			$source
		);

		return self::compare_condition_value( $actual, $operator, $expected );
	}

	private static function compare_condition_value( $actual, $operator, $expected ) {
		switch ( $operator ) {
			case 'truthy':
				return ! empty( $actual );

			case 'falsy':
				return empty( $actual );

			case 'exists':
				return null !== $actual;

			case 'not_exists':
				return null === $actual;

			case 'equals':
			case 'eq':
				return $actual == $expected;

			case 'not_equals':
			case 'neq':
				return $actual != $expected;

			case 'contains':
				if ( is_array( $actual ) ) {
					return in_array( $expected, $actual, true );
				}

				return false !== strpos( (string) $actual, (string) $expected );

			case 'not_contains':
				if ( is_array( $actual ) ) {
					return ! in_array( $expected, $actual, true );
				}

				return false === strpos( (string) $actual, (string) $expected );

			case 'gt':
			case 'greater_than':
			case '>':
				return (float) $actual > (float) $expected;

			case 'gte':
			case 'greater_than_or_equal':
			case '>=':
				return (float) $actual >= (float) $expected;

			case 'lt':
			case 'less_than':
			case '<':
				return (float) $actual < (float) $expected;

			case 'lte':
			case 'less_than_or_equal':
			case '<=':
				return (float) $actual <= (float) $expected;
		}

		return true;
	}

	private static function is_step_failure( $result ) {
		if ( is_wp_error( $result ) ) {
			return true;
		}

		if ( is_array( $result ) && isset( $result['success'] ) && false === (bool) $result['success'] ) {
			return true;
		}

		return false;
	}

	private static function normalize_on_error_policy( $policy ) {
		$policy = sanitize_key( (string) $policy );

		$allowed = [
			'stop',
			'continue',
			'rollback',
			'skip_remaining',
		];

		if ( ! in_array( $policy, $allowed, true ) ) {
			return 'stop';
		}

		return $policy;
	}
}
