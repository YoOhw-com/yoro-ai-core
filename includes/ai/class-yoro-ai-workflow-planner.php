<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Workflow_Planner {

	public static function parse( $command, $client_context = [] ) {
		if ( 'yes' !== get_option( 'yoro_ai_dynamic_workflows_enabled', 'no' ) ) {
			return null;
		}

		if ( ! class_exists( 'YoRo_AI_Parser' ) ) {
			return null;
		}

		$prompt = self::build_prompt( $command, $client_context );

		$response = YoRo_AI_Parser::parse_workflow_plan( $prompt );

		if ( is_wp_error( $response ) || empty( $response ) ) {
			return null;
		}

		$data = YoRo_AI_Workflow_Result_Normalizer::decode( $response );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$parsed = self::normalize_ai_plan(
			$data,
			$command,
			$client_context
		);

		if ( ! $parsed ) {
			return null;
		}

		if ( class_exists( 'YoRo_AI_Workflow_Validator' ) ) {

			$validation = YoRo_AI_Workflow_Validator::validate( $parsed );

			if ( is_wp_error( $validation ) ) {

				YoRo_Logger::log([
					'type'  => 'ai_workflow_validation_failed',
					'error' => $validation->get_error_message(),
				]);

				return null;
			}
		}

		return $parsed;
	}

	private static function build_prompt( $command, $client_context = [] ) {
		$actions = [];
		$scope = self::detect_workflow_scope( $command, $client_context );
		$allowed_action_names = self::get_allowed_actions_for_scope( $scope );

		foreach ( YoRo_Action_Registry::all() as $name => $action ) {
			if ( ! in_array( $name, $allowed_action_names, true ) ) {
				continue;
			}

			if ( empty( $action['capabilities'] ) || ! is_array( $action['capabilities'] ) ) {
				continue;
			}

			$actions[] = [
				'name'                  => $name,
				'label'                 => $action['label'] ?? $name,
				'description'           => $action['description'] ?? '',
				'input_schema'          => $action['input_schema'] ?? [],
				'risk_level'            => $action['risk_level'] ?? 'low',
				'write_action'          => ! empty( $action['write_action'] ),
				'requires_confirmation' => ! empty( $action['requires_confirmation'] ),
				'capabilities'          => $action['capabilities'] ?? [],
			];
		}

		$context_order_id = absint(
			$client_context['current_order_id']
				?? $client_context['last_referenced_order_id']
				?? 0
		);

		$context_order_ids = [];

		if ( ! empty( $client_context['resolved_order_ids'] ) && is_array( $client_context['resolved_order_ids'] ) ) {
			$context_order_ids = $client_context['resolved_order_ids'];
		} elseif ( ! empty( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] ) ) {
			$context_order_ids = $client_context['last_order_ids'];
		} elseif ( $context_order_id > 0 ) {
			$context_order_ids = [ $context_order_id ];
		}

		$context_order_ids = array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $context_order_ids )
				)
			)
		);

		return YoRo_AI_Workflow_Prompt::build(
			[
				'instruction' => 'Build a safe WooCommerce workflow plan. Return JSON only. Never invent actions. Use only available_actions. Do not use actions outside allowed_action_names. Follow action_dependency_rules strictly. Use condition_rules when a later step depends on captured context from previous steps. If an action requires context from a previous action, ensure the workflow includes the required previous step and capture flow. Prefer read-only steps before write steps. Never generate semantically incomplete workflows. For uncertain or risky commands, set matched=false.',
				'command' => $command,
				'workflow_scope' => $scope,
				'allowed_action_names' => $allowed_action_names,
				'context' => [
					'order_id'  => $context_order_id,
					'order_ids' => $context_order_ids,
				],
				'client_context' => [
					'current_order_id'         => absint( $client_context['current_order_id'] ?? 0 ),
					'last_referenced_order_id' => absint( $client_context['last_referenced_order_id'] ?? 0 ),
					'last_order_ids'           => isset( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] )
						? array_values( array_map( 'absint', $client_context['last_order_ids'] ) )
						: [],
					'resolved_order_ids'       => isset( $client_context['resolved_order_ids'] ) && is_array( $client_context['resolved_order_ids'] )
						? array_values( array_map( 'absint', $client_context['resolved_order_ids'] ) )
						: [],
				],
				'available_actions' => $actions,
				'action_dependency_rules' => self::get_action_dependency_rules(),
				'condition_rules' => self::get_condition_rules(),
				'output_schema' => [
					'matched' => 'boolean',
					'confidence' => 'number 0..1',
					'reason' => 'string',
					'workflow_label' => 'string',
					'actions' => [
						[
							'id' => 'string',
							'label' => 'string',
							'action' => 'registered action name',
							'params' => 'object',
							'when' => 'object optional',
							'capture' => 'object optional',
							'on_error' => 'stop|continue|rollback|skip_remaining',
							'retry' => [
								'max_attempts' => 'integer 1..3',
							],
						],
					],
				],
			]
		);
	}

	private static function normalize_ai_plan( $data, $command, $client_context = [] ) {
		if ( empty( $data['matched'] ) || empty( $data['actions'] ) || ! is_array( $data['actions'] ) ) {
			return null;
		}

		$client_context = is_array( $client_context ) ? $client_context : [];

		$context_order_id = absint(
			$client_context['current_order_id']
				?? $client_context['last_referenced_order_id']
				?? 0
		);

		$context_order_ids = [];

		if ( ! empty( $client_context['resolved_order_ids'] ) && is_array( $client_context['resolved_order_ids'] ) ) {
			$context_order_ids = $client_context['resolved_order_ids'];
		} elseif ( ! empty( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] ) ) {
			$context_order_ids = $client_context['last_order_ids'];
		} elseif ( $context_order_id > 0 ) {
			$context_order_ids = [ $context_order_id ];
		}

		$context_order_ids = array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $context_order_ids )
				)
			)
		);

		$steps = [];

		foreach ( $data['actions'] as $index => $step ) {
			$action = sanitize_text_field( $step['action'] ?? '' );

			if ( empty( $action ) ) {
				YoRo_Logger::log(
					[
						'type'   => 'workflow_step_dropped',
						'reason' => 'empty_action',
						'index'  => $index,
						'step'   => $step,
					]
				);

				continue;
			}

			if ( ! YoRo_Action_Registry::get( $action ) ) {
				YoRo_Logger::log(
					[
						'type'   => 'workflow_step_dropped',
						'reason' => 'unregistered_action',
						'index'  => $index,
						'action' => $action,
						'step'   => $step,
					]
				);

				continue;
			}

			$params = (
				isset( $step['params'] ) &&
				is_array( $step['params'] ) &&
				array_values( $step['params'] ) !== $step['params']
			)
				? $step['params']
				: [];

			$when = (
				isset( $step['when'] ) &&
				is_array( $step['when'] ) &&
				array_values( $step['when'] ) !== $step['when']
			)
				? $step['when']
				: [];

			$capture = (
				isset( $step['capture'] ) &&
				is_array( $step['capture'] ) &&
				array_values( $step['capture'] ) !== $step['capture']
			)
				? $step['capture']
				: [];

			$params  = self::remove_empty_values( $params );
			$when    = self::remove_empty_values( $when );
			$capture = self::remove_empty_values( $capture );

			/*
			* Auto inject common order context if AI omitted params.
			*/
			if (
				empty( $params['order_id'] ) &&
				$context_order_id > 0
			) {
				$params['order_id'] = $context_order_id;
			}

			if (
				empty( $params['order_ids'] ) &&
				! empty( $context_order_ids )
			) {
				$params['order_ids'] = $context_order_ids;
			}

			if (
				'orders.compose_customer_email' === $action &&
				empty( $params['instruction'] )
			) {
				$params['instruction'] = sanitize_text_field( $command );
			}

			if ( 'orders.compose_customer_email' === $action && empty( $capture ) ) {
				$capture = [
					'email_subject' => 'result.email.subject',
					'email_body'    => 'result.email.body',
				];
			}

			if ( 'orders.render_customer_email_preview' === $action ) {
				if ( empty( $params['subject'] ) ) {
					$params['subject'] = '{{context.email_subject}}';
				}

				if ( empty( $params['body'] ) ) {
					$params['body']    = '{{context.email_body}}';
				}
			}

			if ( 'orders.save_customer_email_draft' === $action ) {
				if ( empty( $params['subject'] ) ) {
					$params['subject'] = '{{context.email_subject}}';
				}

				if ( empty( $params['body'] ) ) {
					$params['body']    = '{{context.email_body}}';
				}
			}

			if (
				in_array(
					$action,
					[
						'orders.render_customer_email_preview',
						'orders.save_customer_email_draft',
					],
					true
				) &&
				empty( $when )
			) {
				$when = [
					'all' => [
						[
							'source'   => 'context.email_subject',
							'operator' => 'not_empty',
						],
						[
							'source'   => 'context.email_body',
							'operator' => 'not_empty',
						],
					],
				];
			}

			/*
			* Normalize retry.
			*/
			$max_attempts = isset( $step['retry']['max_attempts'] )
				? min( max( 1, absint( $step['retry']['max_attempts'] ) ), 3 )
				: 1;

			/*
			* Normalize on_error.
			*/
			$on_error = ! empty( $step['on_error'] )
				? sanitize_key( $step['on_error'] )
				: 'stop';

			if ( ! in_array( $on_error, [ 'stop', 'continue', 'rollback', 'skip_remaining' ], true ) ) {
				$on_error = 'stop';
			}

			$steps[] = [
				'id'       => ! empty( $step['id'] )
					? sanitize_key( $step['id'] )
					: 'ai_step_' . ( $index + 1 ),

				'label'    => ! empty( $step['label'] )
					? sanitize_text_field( $step['label'] )
					: $action,

				'action'   => $action,

				'params'   => $params,

				'when'     => $when,

				'capture'  => $capture,

				'on_error' => $on_error,

				'retry'    => [
					'max_attempts' => $max_attempts,
				],
			];
		}

		if ( empty( $steps ) ) {
			return null;
		}

		$confidence = isset( $data['confidence'] )
			? (float) $data['confidence']
			: 0.5;
		$meta = class_exists( 'YoRo_AI_Parser' ) && method_exists( 'YoRo_AI_Parser', 'build_meta' )
			? YoRo_AI_Parser::build_meta(
				'workflow_plan',
				[
					'prompt_version' => YoRo_AI_Parser::PROMPT_VERSION_WORKFLOW,
				]
			)
			: [];

		$workflow_label = self::build_workflow_label(
			$data,
			$command,
			$steps
		);

		$reason = sanitize_text_field( $data['reason'] ?? '' );

		return [
			'success'            => true,
			'matched'            => true,

			'action'             => 'workflow.dynamic',

			'workflow_template'  => 'dynamic',

			'workflow_label'     => $workflow_label,

			'workflow'           => [
				'id'          => 'dynamic',
				'label'       => $workflow_label,
				'description' => $reason,
				'category'    => 'ai',
				'risk_level'  => 'medium',
			],

			'params'             => [
				'original_command' => $command,
				'order_id'         => $context_order_id,
				'order_ids'        => $context_order_ids,
			],

			'actions'            => $steps,

			'parser_source'      => 'ai_workflow',

			'parser_rule'        => 'ai.dynamic_workflow',

			'confidence'         => $confidence,

			'reason'             => $reason,

			'meta'               => $meta,

			'original_command'   => $command,

			'normalized_command' => strtolower( $command ),

			'intent'             => [
				'name'       => 'workflow_dynamic',
				'action'     => 'workflow.dynamic',
				'confidence' => $confidence,
				'source'     => 'ai_workflow',
			],

			'context'            => [
				'order_id'  => $context_order_id,
				'order_ids' => $context_order_ids,
			],
		];
	}

	private static function remove_empty_values( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$item = self::remove_empty_values( $item );
			}

			if (
				$item === '' ||
				$item === null ||
				$item === [] ||
				$item === 0 ||
				$item === '0'
			) {
				unset( $value[ $key ] );
				continue;
			}

			$value[ $key ] = $item;
		}

		return $value;
	}

	private static function get_action_dependency_rules() {

		return [

			'orders.compose_customer_email' => [
				'requires_before' => [
					'orders.get_details',
				],

				'provides' => [
					'email.subject',
					'email.body',
				],
			],

			'orders.render_customer_email_preview' => [
				'requires_before' => [
					'orders.compose_customer_email',
				],

				'requires_context' => [
					'email_subject',
					'email_body',
				],
			],

			'orders.save_customer_email_draft' => [
				'requires_before' => [
					'orders.compose_customer_email',
				],

				'requires_context' => [
					'email_subject',
					'email_body',
				],
			],

			'orders.send_customer_email' => [
				'requires_before' => [
					'orders.compose_customer_email',
				],

				'requires_context' => [
					'email_subject',
					'email_body',
				],
			],
		];
	}

	private static function build_workflow_label( $data, $command, $steps ) {
		$label = isset( $data['workflow_label'] )
			? trim( sanitize_text_field( $data['workflow_label'] ) )
			: '';

		$generic_labels = [
			'',
			'email',
			'order',
			'orders',
			'workflow',
			'ai planned workflow',
		];

		if (
			$label &&
			! in_array( strtolower( $label ), $generic_labels, true )
		) {
			return $label;
		}

		$actions = [];

		foreach ( $steps as $step ) {
			if ( ! empty( $step['action'] ) ) {
				$actions[] = $step['action'];
			}
		}

		if (
			in_array( 'orders.hold', $actions, true ) &&
			(
				in_array( 'orders.add_note', $actions, true ) ||
				in_array( 'orders.add_private_note_template', $actions, true )
			) &&
			in_array( 'orders.compose_customer_email', $actions, true )
		) {
			return __( 'Hold, verify, and draft customer email', 'yoro-ai-core' );
		}

		if (
			in_array( 'orders.compose_customer_email', $actions, true ) &&
			in_array( 'orders.save_customer_email_draft', $actions, true )
		) {
			return __( 'Draft and save customer email', 'yoro-ai-core' );
		}

		if ( in_array( 'orders.refund_preview', $actions, true ) ) {
			return __( 'Refund review workflow', 'yoro-ai-core' );
		}

		if ( in_array( 'orders.find_by_identity', $actions, true ) ) {
			return __( 'Suspicious order review', 'yoro-ai-core' );
		}

		if ( in_array( 'orders.update_status', $actions, true ) ) {
			return __( 'Update order status workflow', 'yoro-ai-core' );
		}

		return __( 'Order management workflow', 'yoro-ai-core' );
	}

	private static function detect_workflow_scope( $command, $client_context = [] ) {
		$normalized = strtolower( (string) $command );

		$order_write_markers = [
			'add note',
			'internal note',
			'private note',
			'order note',
			'note to',
			'note for',
			'write a note',
			'leave a note',
			'put on hold',
			'place on hold',
			'hold order',
			'update status',
			'change status',
			'mark as',
		];

		foreach ( $order_write_markers as $marker ) {
			if ( false !== strpos( $normalized, $marker ) ) {
				return 'order_single';
			}
		}

		$email_markers = [
			'email',
			'draft',
			'write',
			'message',
			'contact',
			'confirm',
			'shipping address',
			'address confirmation',
		];

		foreach ( $email_markers as $marker ) {
			if ( false !== strpos( $normalized, $marker ) ) {
				return 'email';
			}
		}

		$refund_markers = [
			'refund',
			'refundable',
			'refund review',
		];

		foreach ( $refund_markers as $marker ) {
			if ( false !== strpos( $normalized, $marker ) ) {
				return 'refund';
			}
		}

		$review_markers = [
			'review',
			'check',
			'analyze',
			'suspicious',
			'risk',
			'fraud',
		];

		foreach ( $review_markers as $marker ) {
			if ( false !== strpos( $normalized, $marker ) ) {
				return 'review';
			}
		}

		return 'general';
	}

	private static function get_allowed_actions_for_scope( $scope ) {
		$common = [
			'orders.get_details',
			'orders.search',
			'orders.find_by_customer',
			'orders.find_by_identity',
		];

		$scopes = [
			'email' => [
				'orders.get_details',
				'orders.hold',
				'orders.add_note',
				'orders.add_private_note_template',
				'orders.compose_customer_email',
				'orders.render_customer_email_preview',
				'orders.save_customer_email_draft',
			],

			'refund' => [
				'orders.get_details',
				'orders.refund_preview',
			],

			'review' => [
				'orders.get_details',
				'orders.search',
				'orders.find_by_customer',
				'orders.find_by_identity',
				'orders.compose_customer_email',
				'orders.render_customer_email_preview',
				'orders.save_customer_email_draft',
				'orders.add_note',
				'orders.hold',
			],

			'order_single' => [
				'orders.get_details',
				'orders.add_note',
				'orders.add_private_note_template',
				'orders.hold',
				'orders.update_status',
			],

			'general' => $common,
		];

		return $scopes[ $scope ] ?? $common;
	}

	private static function get_condition_rules() {
		return [
			'supported_shape' => [
				'all' => [
					[
						'source'   => 'context.email_subject',
						'operator' => 'not_empty',
					],
				],
				'any' => [
					[
						'source'   => 'context.order_status',
						'operator' => 'equals',
						'value'    => 'processing',
					],
				],
			],

			'supported_sources' => [
				'context.order_id',
				'context.order_ids',
				'context.order_status',
				'context.email_subject',
				'context.email_body',
				'context.previous_success',
				'context.previous_action',
			],

			'supported_operators' => [
				'equals',
				'not_equals',
				'in',
				'not_in',
				'empty',
				'not_empty',
				'greater_than',
				'greater_or_equal',
				'less_than',
				'less_or_equal',
			],

			'guidance' => [
				'Use when only when it materially improves safety.',
				'For save draft after compose email, add conditions requiring context.email_subject and context.email_body to be not_empty.',
				'Do not invent unsupported condition sources.',
			],
		];
	}
}
