<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Workflow_Runner {

	const STATUS_DRAFTING         = 'drafting';
	const STATUS_PREVIEW_READY    = 'preview_ready';
	const STATUS_READY_TO_EXECUTE = 'ready_to_execute';
	const STATUS_EXECUTING        = 'executing';
	const STATUS_EXECUTED         = 'executed';
	const STATUS_FAILED           = 'failed';
	const STATUS_CANCELLED        = 'cancelled';

	public static function build_followup_workflow( $definition_id, $args = [] ) {
		$definition = YoRo_Workflow_Definition_Registry::get( $definition_id );

		if ( empty( $definition ) ) {
			return [];
		}

		$args               = is_array( $args ) ? $args : [];
		$next_after_confirm = isset( $args['next_after_confirm'] ) && is_array( $args['next_after_confirm'] )
			? $args['next_after_confirm']
			: [];
		$steps              = self::build_followup_steps( $definition, $next_after_confirm );
		$current_step_index = count( $steps ) > 1 ? 1 : 0;
		$current_step       = $steps[ $current_step_index ] ?? [];
		$selected_item      = isset( $args['selected_item'] ) && is_array( $args['selected_item'] )
			? $args['selected_item']
			: [];
		$queue_source       = isset( $args['queue_source'] ) && is_array( $args['queue_source'] )
			? $args['queue_source']
			: [];
		$email_preview      = isset( $args['email_preview'] ) && is_array( $args['email_preview'] )
			? $args['email_preview']
			: [];

		return [
			'id'                 => 'wf_' . wp_generate_uuid4(),
			'type'               => sanitize_key( $definition['id'] ?? $definition_id ),
			'definition_id'      => sanitize_key( $definition['id'] ?? $definition_id ),
			'label'              => sanitize_text_field( $definition['label'] ?? $definition_id ),
			'description'        => sanitize_text_field( $definition['description'] ?? '' ),
			'category'           => sanitize_key( $definition['category'] ?? 'workflow' ),
				'status'             => self::STATUS_PREVIEW_READY,
			'surface'            => sanitize_key( $definition['surface'] ?? 'admin_copilot' ),
			'action'             => sanitize_text_field( $definition['preview_action'] ?? '' ),
				'step'               => self::STATUS_PREVIEW_READY,
			'current_step_id'    => sanitize_key( $current_step['id'] ?? '' ),
			'current_step_index' => $current_step_index,
			'order_id'           => absint( $args['order_id'] ?? $selected_item['order_id'] ?? 0 ),
			'subject'            => sanitize_text_field( $args['subject'] ?? '' ),
			'body'               => sanitize_textarea_field( $args['body'] ?? '' ),
			'draft_id'           => sanitize_key( $args['draft_id'] ?? '' ),
			'steps'              => $steps,
			'resolved_entities'  => [
				'order_id'  => absint( $args['order_id'] ?? $selected_item['order_id'] ?? 0 ),
				'queue_key' => sanitize_key( $queue_source['key'] ?? '' ),
			],
			'input_context'      => [
				'queue_key'        => sanitize_key( $queue_source['key'] ?? '' ),
				'original_command' => sanitize_textarea_field( $args['original_command'] ?? '' ),
			],
			'artifacts'          => [
				'selected_item'    => $selected_item,
				'selection_reason' => sanitize_text_field( $args['selection_reason'] ?? '' ),
				'queue_source'     => $queue_source,
				'email_preview'    => $email_preview,
				'draft_summary'    => isset( $args['draft_summary'] ) && is_array( $args['draft_summary'] ) ? $args['draft_summary'] : [],
				'proposed_action'  => isset( $args['proposed_action'] ) && is_array( $args['proposed_action'] ) ? $args['proposed_action'] : [],
			],
			'plan'               => [
				'type'          => 'workflow',
				'definition_id' => sanitize_key( $definition['id'] ?? $definition_id ),
				'steps'         => $steps,
			],
			'params'             => [
				'queue_key'          => sanitize_key( $queue_source['key'] ?? '' ),
				'selected_item'      => $selected_item,
				'selection_reason'   => sanitize_text_field( $args['selection_reason'] ?? '' ),
				'proposed_action'    => isset( $args['proposed_action'] ) && is_array( $args['proposed_action'] ) ? $args['proposed_action'] : [],
				'next_after_confirm' => $next_after_confirm,
			],
			'missing'            => [],
			'last_command'       => sanitize_textarea_field( $args['original_command'] ?? '' ),
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		];
	}

	public static function build_preview_workflow( $definition_id, $args = [] ) {
		$definition = YoRo_Workflow_Definition_Registry::get( $definition_id );

		if ( empty( $definition ) ) {
			return [];
		}

		$args          = is_array( $args ) ? $args : [];
		$steps         = self::build_preview_only_steps( $definition );
		$selected_item = isset( $args['selected_item'] ) && is_array( $args['selected_item'] ) ? $args['selected_item'] : [];
		$queue_source  = isset( $args['queue_source'] ) && is_array( $args['queue_source'] ) ? $args['queue_source'] : [];

		return [
			'id'                 => 'wf_' . wp_generate_uuid4(),
			'type'               => sanitize_key( $definition['id'] ?? $definition_id ),
			'definition_id'      => sanitize_key( $definition['id'] ?? $definition_id ),
			'label'              => sanitize_text_field( $definition['label'] ?? $definition_id ),
			'description'        => sanitize_text_field( $definition['description'] ?? '' ),
			'category'           => sanitize_key( $definition['category'] ?? 'workflow' ),
				'status'             => self::STATUS_PREVIEW_READY,
			'surface'            => sanitize_key( $definition['surface'] ?? 'admin_copilot' ),
			'action'             => sanitize_text_field( $definition['preview_action'] ?? '' ),
				'step'               => self::STATUS_PREVIEW_READY,
			'current_step_id'    => sanitize_key( $steps[0]['id'] ?? 'preview' ),
			'current_step_index' => 0,
			'order_id'           => absint( $args['order_id'] ?? $selected_item['order_id'] ?? 0 ),
			'product_id'         => absint( $args['product_id'] ?? $selected_item['product_id'] ?? 0 ),
			'steps'              => $steps,
			'resolved_entities'  => [
				'order_id'   => absint( $args['order_id'] ?? $selected_item['order_id'] ?? 0 ),
				'product_id' => absint( $args['product_id'] ?? $selected_item['product_id'] ?? 0 ),
				'queue_key'  => sanitize_key( $queue_source['key'] ?? '' ),
			],
			'input_context'      => [
				'queue_key'        => sanitize_key( $queue_source['key'] ?? '' ),
				'original_command' => sanitize_textarea_field( $args['original_command'] ?? '' ),
			],
			'artifacts'          => [
				'selected_item'        => $selected_item,
				'selection_reason'     => sanitize_text_field( $args['selection_reason'] ?? '' ),
				'queue_source'         => $queue_source,
				'proposed_action'      => isset( $args['proposed_action'] ) && is_array( $args['proposed_action'] ) ? $args['proposed_action'] : [],
				'preview_summary'      => sanitize_text_field( $args['preview_summary'] ?? '' ),
				'next_without_confirm' => isset( $args['next_without_confirm'] ) && is_array( $args['next_without_confirm'] ) ? $args['next_without_confirm'] : [],
			],
			'plan'               => [
				'type'          => 'workflow',
				'definition_id' => sanitize_key( $definition['id'] ?? $definition_id ),
				'steps'         => $steps,
			],
			'params'             => [
				'queue_key'            => sanitize_key( $queue_source['key'] ?? '' ),
				'selected_item'        => $selected_item,
				'selection_reason'     => sanitize_text_field( $args['selection_reason'] ?? '' ),
				'proposed_action'      => isset( $args['proposed_action'] ) && is_array( $args['proposed_action'] ) ? $args['proposed_action'] : [],
				'next_without_confirm' => isset( $args['next_without_confirm'] ) && is_array( $args['next_without_confirm'] ) ? $args['next_without_confirm'] : [],
			],
			'missing'            => [],
			'last_command'       => sanitize_textarea_field( $args['original_command'] ?? '' ),
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		];
	}

	public static function build_sequence_workflow( $definition_id, $steps, $params = [], $command = '', $options = [] ) {
		$definition = YoRo_Workflow_Definition_Registry::get( $definition_id );

		if ( empty( $definition ) ) {
			return [];
		}

		$steps          = is_array( $steps ) ? array_values( $steps ) : [];
		$params         = is_array( $params ) ? $params : [];
		$options        = is_array( $options ) ? $options : [];
		$normalized     = [];
		$current_step   = '';
		$current_index  = -1;

		foreach ( $steps as $index => $step ) {
			$step_id = sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) );

			if ( '' === $current_step ) {
				$current_step  = $step_id;
				$current_index = $index;
			}

			$normalized[] = [
				'id'                    => $step_id,
				'label'                 => sanitize_text_field( $step['label'] ?? ( $step['action'] ?? $step_id ) ),
				'kind'                  => sanitize_key( $step['kind'] ?? 'write' ),
				'action'                => sanitize_text_field( $step['action'] ?? '' ),
				'params'                => isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
				'when'                  => isset( $step['when'] ) && is_array( $step['when'] ) ? $step['when'] : [],
				'capture'               => isset( $step['capture'] ) && is_array( $step['capture'] ) ? $step['capture'] : [],
				'on_error'              => ! empty( $step['on_error'] ) ? sanitize_key( $step['on_error'] ) : 'stop',
				'retry'                 => isset( $step['retry'] ) && is_array( $step['retry'] ) ? $step['retry'] : [ 'max_attempts' => 1 ],
				'rollback'              => isset( $step['rollback'] ) && is_array( $step['rollback'] ) ? $step['rollback'] : [],
				'meta'                  => isset( $step['meta'] ) && is_array( $step['meta'] ) ? $step['meta'] : [],
				'requires_confirmation' => true,
				'status'                => 'pending',
			];
		}

		$artifacts = isset( $options['artifacts'] ) && is_array( $options['artifacts'] )
			? $options['artifacts']
			: [];
		$input_context = isset( $options['input_context'] ) && is_array( $options['input_context'] )
			? $options['input_context']
			: [];
		$resolved_entities = isset( $options['resolved_entities'] ) && is_array( $options['resolved_entities'] )
			? $options['resolved_entities']
			: [];
		$branch = isset( $options['branch'] ) && is_array( $options['branch'] )
			? $options['branch']
			: [];

		return [
			'id'                 => 'wf_' . wp_generate_uuid4(),
			'type'               => sanitize_key( $definition['id'] ?? $definition_id ),
			'definition_id'      => sanitize_key( $definition['id'] ?? $definition_id ),
			'label'              => sanitize_text_field( $options['label'] ?? $definition['label'] ?? $definition_id ),
			'description'        => sanitize_text_field( $options['description'] ?? $definition['description'] ?? '' ),
			'category'           => sanitize_key( $definition['category'] ?? 'workflow' ),
				'status'             => self::STATUS_READY_TO_EXECUTE,
			'surface'            => sanitize_key( $definition['surface'] ?? 'admin_copilot' ),
			'action'             => 'workflow.' . sanitize_key( $definition['id'] ?? $definition_id ),
				'step'               => self::STATUS_READY_TO_EXECUTE,
			'current_step_id'    => $current_step,
			'current_step_index' => $current_index,
			'order_id'           => absint( $params['order_id'] ?? 0 ),
			'product_id'         => absint( $params['product_id'] ?? 0 ),
			'steps'              => $normalized,
			'resolved_entities'  => [
				'order_id'   => absint( $resolved_entities['order_id'] ?? $params['order_id'] ?? 0 ),
				'product_id' => absint( $resolved_entities['product_id'] ?? $params['product_id'] ?? 0 ),
			],
			'input_context'      => [
				'original_command' => sanitize_textarea_field( $input_context['original_command'] ?? $command ),
			],
			'artifacts'          => $artifacts,
			'plan'               => [
				'type'          => 'workflow',
				'definition_id' => sanitize_key( $definition['id'] ?? $definition_id ),
				'steps'         => $normalized,
			],
			'params'             => $params,
			'branch'             => [
				'delivery_mode'       => sanitize_key( $branch['delivery_mode'] ?? $params['delivery_mode'] ?? '' ),
				'email_delivery_mode' => sanitize_key( $branch['email_delivery_mode'] ?? $params['email_delivery_mode'] ?? '' ),
			],
			'missing'            => [],
			'last_command'       => sanitize_textarea_field( $command ),
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		];
	}

	public static function is_confirmable_preview_workflow( $workflow, $definition_id, $preview_action ) {
		if ( empty( $workflow ) || ! is_array( $workflow ) ) {
			return false;
		}

		return sanitize_key( $workflow['type'] ?? '' ) === sanitize_key( $definition_id )
			&& 'preview_ready' === sanitize_key( $workflow['step'] ?? '' )
			&& sanitize_text_field( $workflow['action'] ?? '' ) === sanitize_text_field( $preview_action );
	}

	public static function execute_confirmed_workflow( $workflow ) {
		$workflow = is_array( $workflow ) ? $workflow : [];

		$step_index = isset( $workflow['current_step_index'] ) ? absint( $workflow['current_step_index'] ) : -1;
		$steps      = isset( $workflow['steps'] ) && is_array( $workflow['steps'] ) ? array_values( $workflow['steps'] ) : [];
		$step       = $steps[ $step_index ] ?? [];
		$action     = sanitize_text_field( $step['action'] ?? '' );
		$params     = isset( $step['params'] ) && is_array( $step['params'] )
			? $step['params']
			: [];
		$resolved_order_id = absint( $workflow['resolved_entities']['order_id'] ?? $workflow['order_id'] ?? 0 );

		if ( '' === $action || empty( $params ) ) {
			return new WP_Error(
				'yoro_workflow_stale',
				__( 'This workflow preview is stale. Please generate a new preview before confirming.', 'yoro-agent-core' )
			);
		}

		if (
			! in_array( $action, [ 'orders.save_customer_email_draft', 'orders.update_customer_email_draft' ], true ) ||
			empty( $params['order_id'] ) ||
			empty( $params['subject'] ) ||
			empty( $params['body'] ) ||
			( $resolved_order_id > 0 && absint( $params['order_id'] ) !== $resolved_order_id )
		) {
			return new WP_Error(
				'yoro_workflow_stale',
				__( 'This workflow preview is stale. Please generate a new preview before confirming.', 'yoro-agent-core' )
			);
		}

		if ( 'orders.update_customer_email_draft' === $action && empty( $params['draft_id'] ) ) {
			return new WP_Error(
				'yoro_workflow_stale',
				__( 'This workflow preview is stale. Please generate a new preview before confirming.', 'yoro-agent-core' )
			);
		}

		$result = self::execute_single_step_action( $workflow, $action, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$steps[ $step_index ]['status']       = 'completed';
		$steps[ $step_index ]['completed_at'] = current_time( 'mysql' );

		$workflow['steps'] = $steps;
		$workflow          = self::transition_workflow(
			$workflow,
			self::STATUS_EXECUTED,
			[
				'current_step_id'    => sanitize_key( $step['id'] ?? '' ),
				'current_step_index' => $step_index,
			]
		);

		return [
			'workflow' => $workflow,
			'result'   => $result,
			'step'     => $step,
		];
	}

	public static function apply_sequence_result( $workflow, $result ) {
		$workflow = is_array( $workflow ) ? $workflow : [];
		$result   = is_array( $result ) ? $result : [];
		$steps    = isset( $workflow['steps'] ) && is_array( $workflow['steps'] ) ? array_values( $workflow['steps'] ) : [];
		$actions  = isset( $result['actions'] ) && is_array( $result['actions'] ) ? array_values( $result['actions'] ) : [];

		$current_step_id    = '';
		$current_step_index = -1;

		foreach ( $steps as $index => $step ) {
			$step_id = sanitize_key( $step['id'] ?? '' );

			foreach ( $actions as $action_result ) {
				if ( $step_id !== sanitize_key( $action_result['id'] ?? '' ) ) {
					continue;
				}

				if ( ! empty( $action_result['failed'] ) || ( ! empty( $action_result['result'] ) && empty( $action_result['result']['success'] ) ) ) {
					$steps[ $index ]['status'] = 'failed';
					$current_step_id           = $step_id;
					$current_step_index        = $index;
				} elseif ( ! empty( $action_result['skipped'] ) ) {
					$steps[ $index ]['status'] = 'skipped';
				} else {
					$steps[ $index ]['status'] = 'completed';
				}

				$steps[ $index ]['updated_at'] = current_time( 'mysql' );
				break;
			}
		}

		if ( '' === $current_step_id ) {
			foreach ( $steps as $index => $step ) {
				if ( 'pending' === sanitize_key( $step['status'] ?? '' ) ) {
					$current_step_id    = sanitize_key( $step['id'] ?? '' );
					$current_step_index = $index;
					break;
				}
			}
		}

		$workflow['steps'] = $steps;

		if ( ! empty( $result['preview'] ) ) {
			return self::transition_workflow(
				$workflow,
				self::STATUS_PREVIEW_READY,
				[
					'steps'              => $steps,
					'current_step_id'    => $current_step_id,
					'current_step_index' => $current_step_index,
				]
			);
		}

		if ( ! empty( $result['requires_confirmation'] ) ) {
			return self::transition_workflow(
				$workflow,
				self::STATUS_READY_TO_EXECUTE,
				[
					'steps'              => $steps,
					'current_step_id'    => $current_step_id,
					'current_step_index' => $current_step_index,
				]
			);
		}

		if ( ! empty( $result['success'] ) ) {
			return self::transition_workflow(
				$workflow,
				self::STATUS_EXECUTED,
				[
					'steps'              => $steps,
					'current_step_id'    => $current_step_id,
					'current_step_index' => $current_step_index,
				]
			);
		}

		return self::transition_workflow(
			$workflow,
			self::STATUS_FAILED,
			[
				'steps'              => $steps,
				'current_step_id'    => $current_step_id,
				'current_step_index' => $current_step_index,
			]
		);
	}

	public static function begin_sequence_execution( $workflow ) {
		$workflow = is_array( $workflow ) ? $workflow : [];
		$steps    = isset( $workflow['steps'] ) && is_array( $workflow['steps'] ) ? array_values( $workflow['steps'] ) : [];

		$current_step_id    = sanitize_key( $workflow['current_step_id'] ?? '' );
		$current_step_index = isset( $workflow['current_step_index'] ) ? (int) $workflow['current_step_index'] : -1;

		if ( $current_step_index < 0 && ! empty( $steps ) ) {
			$current_step_index = 0;
			$current_step_id    = sanitize_key( $steps[0]['id'] ?? '' );
		}

		return self::transition_workflow(
			$workflow,
			self::STATUS_EXECUTING,
			[
				'current_step_id'    => $current_step_id,
				'current_step_index' => $current_step_index,
				'steps'              => $steps,
			]
		);
	}

	public static function mark_step_executing( $workflow, $step, $index ) {
		$workflow = is_array( $workflow ) ? $workflow : [];
		$steps    = isset( $workflow['steps'] ) && is_array( $workflow['steps'] ) ? array_values( $workflow['steps'] ) : [];
		$step_id  = sanitize_key( $step['id'] ?? '' );
		$index    = (int) $index;

		if ( isset( $steps[ $index ] ) ) {
			$steps[ $index ]['status']     = self::STATUS_EXECUTING;
			$steps[ $index ]['updated_at'] = current_time( 'mysql' );
		}

		return self::transition_workflow(
			$workflow,
			self::STATUS_EXECUTING,
			[
				'steps'              => $steps,
				'current_step_id'    => $step_id,
				'current_step_index' => $index,
			]
		);
	}

	public static function cancel_workflow( $workflow ) {
		$workflow = is_array( $workflow ) ? $workflow : [];

		return self::transition_workflow(
			$workflow,
			self::STATUS_CANCELLED,
			[]
		);
	}

	public static function transition_workflow( $workflow, $next_status, $context = [] ) {
		$workflow    = is_array( $workflow ) ? $workflow : [];
		$context     = is_array( $context ) ? $context : [];
		$next_status = sanitize_key( $next_status );
		$previous    = sanitize_key( $workflow['status'] ?? $workflow['step'] ?? '' );

		if ( isset( $context['steps'] ) && is_array( $context['steps'] ) ) {
			$workflow['steps'] = $context['steps'];
		}

		if ( array_key_exists( 'current_step_id', $context ) ) {
			$workflow['current_step_id'] = sanitize_key( $context['current_step_id'] );
		}

		if ( array_key_exists( 'current_step_index', $context ) ) {
			$workflow['current_step_index'] = (int) $context['current_step_index'];
		}

		$workflow['status']     = $next_status;
		$workflow['step']       = $next_status;
		$workflow['updated_at'] = current_time( 'mysql' );

		do_action(
			'yoro_workflow_transition',
			$workflow,
			$previous,
			$next_status,
			$context
		);

		return $workflow;
	}

	private static function build_followup_steps( $definition, $next_after_confirm ) {
		$definition_steps = isset( $definition['steps'] ) && is_array( $definition['steps'] ) ? array_values( $definition['steps'] ) : [];
		$steps            = [];

		foreach ( $definition_steps as $index => $step ) {
			$action = sanitize_text_field( $step['action'] ?? '' );
			$params = [];

			if ( 1 === $index ) {
				$action = sanitize_text_field( $next_after_confirm['action'] ?? $action );
				$params = isset( $next_after_confirm['params'] ) && is_array( $next_after_confirm['params'] )
					? $next_after_confirm['params']
					: [];
			}

			$steps[] = [
				'id'                    => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
				'label'                 => sanitize_text_field( $step['label'] ?? $action ),
				'kind'                  => sanitize_key( $step['kind'] ?? 'write' ),
				'action'                => $action,
				'params'                => $params,
				'requires_confirmation' => ! empty( $step['requires_confirmation'] ),
				'status'                => 0 === $index ? 'completed' : 'pending',
			];
		}

		return $steps;
	}

	private static function build_preview_only_steps( $definition ) {
		$definition_steps = isset( $definition['steps'] ) && is_array( $definition['steps'] ) ? array_values( $definition['steps'] ) : [];
		$steps            = [];

		foreach ( $definition_steps as $index => $step ) {
			$steps[] = [
				'id'                    => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
				'label'                 => sanitize_text_field( $step['label'] ?? ( $step['action'] ?? '' ) ),
				'kind'                  => sanitize_key( $step['kind'] ?? 'preview' ),
				'action'                => sanitize_text_field( $step['action'] ?? '' ),
				'params'                => [],
				'requires_confirmation' => ! empty( $step['requires_confirmation'] ),
				'status'                => 'completed',
				'completed_at'          => current_time( 'mysql' ),
			];
		}

		return $steps;
	}

	private static function execute_single_step_action( $workflow, $action, $params ) {
		$plan = [
			'type'   => 'single',
			'action' => sanitize_text_field( $action ),
			'params' => is_array( $params ) ? $params : [],
			'parsed' => [
				'success' => true,
				'matched' => true,
				'action'  => sanitize_text_field( $action ),
				'params'  => is_array( $params ) ? $params : [],
			],
		];

		$runtime = YoRo_Runtime_Guard::prepare(
			$plan,
			[
				'source'  => 'direct',
				'route'   => 'workflow',
				'preview' => false,
				'confirm' => true,
				'surface' => sanitize_key( $workflow['surface'] ?? '' ),
			]
		);

		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}

		if ( ! empty( $runtime['preview'] ) || ! empty( $runtime['requires_confirmation'] ) ) {
			return new WP_Error(
				'yoro_workflow_boundary_blocked',
				__( 'This workflow step now requires a fresh preview or confirmation before execution.', 'yoro-agent-core' )
			);
		}

		return YoRo_Plan_Executor::execute( $plan, true, false );
	}
}
