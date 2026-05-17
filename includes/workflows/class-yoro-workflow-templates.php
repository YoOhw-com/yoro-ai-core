<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Workflow_Templates {

	public static function all() {
		$templates = [
			'review_suspicious_order' => [
				'id'          => 'review_suspicious_order',
				'label'       => __( 'Review suspicious order', 'yoro-agent-core' ),
				'description' => __( 'Review an order, find related identity matches, then add a suspicious order note.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_id' => 'integer',
				],
				'steps'       => [
					[
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'action' => 'orders.find_by_identity',
						'params' => [
							'order_id' => '{{order_id}}',
							'limit'    => 10,
						],
					],
					[
						'action' => 'orders.add_private_note_template',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
							'template'  => 'suspicious',
						],
					],
				],
			],

			'verify_customer_before_fulfillment' => [
				'id'          => 'verify_customer_before_fulfillment',
				'label'       => __( 'Verify customer before fulfillment', 'yoro-agent-core' ),
				'description' => __( 'Put an order on hold, add a verification note, then branch into preview, save-draft, or send for the verification email.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_id' => 'integer',
				],
				'steps'       => [
					[
						'action' => 'orders.hold',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'action' => 'orders.add_private_note_template',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
							'template'  => 'needs_verification',
						],
					],
					[
						'action' => 'orders.compose_customer_email',
						'params' => [
							'order_id'    => '{{order_id}}',
							'instruction' => 'Ask the customer to confirm their order details before fulfillment. Keep it polite and concise.',
						],
					],
				],
			],

			'refund_review' => [
				'id'          => 'refund_review',
				'label'       => __( 'Refund review', 'yoro-agent-core' ),
				'description' => __( 'Review refundable amount and order details, then optionally branch into a refund follow-up email flow.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'risk_level'  => 'low',
				'params'      => [
					'order_id' => 'integer',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-agent-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
						'capture' => [
							'loaded_orders' => 'result.orders',
						],
					],
					[
						'id'     => 'review_refund',
						'label'  => __( 'Review refundable amount', 'yoro-agent-core' ),
						'action' => 'orders.refund_preview',
						'params' => [
							'order_id' => '{{order_id}}',
						],
					],
				],
			],
			'hold_and_add_note' => [
				'id'          => 'hold_and_add_note',
				'label'       => __( 'Hold and add note', 'yoro-agent-core' ),
				'description' => __( 'Review an order, put it on hold, then add the requested internal note.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_id' => 'integer',
					'note'     => 'string',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-agent-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'hold_order',
						'label'  => __( 'Put order on hold', 'yoro-agent-core' ),
						'action' => 'orders.hold',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'add_note',
						'label'  => __( 'Add internal note', 'yoro-agent-core' ),
						'action' => 'orders.add_note',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
							'note'      => '{{note}}',
						],
					],
				],
			],
			'update_status_with_note' => [
				'id'          => 'update_status_with_note',
				'label'       => __( 'Update status with note', 'yoro-agent-core' ),
				'description' => __( 'Review an order, update its status, then save the requested note in the same workflow.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_id' => 'integer',
					'status'   => 'string',
					'note'     => 'string',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-agent-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'update_status',
						'label'  => __( 'Update order status with note', 'yoro-agent-core' ),
						'action' => 'orders.bulk_update_status_with_note',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
							'status'    => '{{status}}',
							'note'      => '{{note}}',
						],
					],
				],
			],
			'write_email_for_order' => [
				'id'          => 'write_email_for_order',
				'label'       => __( 'Write email for order', 'yoro-agent-core' ),
				'description' => __( 'Load order details, compose a customer email, then branch into preview, save-draft, or send flow based on the requested outcome.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'risk_level'  => 'low',
				'params'      => [
					'order_id'    => 'integer',
					'instruction' => 'string',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-agent-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'compose_email',
						'label'  => __( 'Compose customer email', 'yoro-agent-core' ),
						'action' => 'orders.compose_customer_email',
						'params' => [
							'order_id'    => '{{order_id}}',
							'instruction' => '{{instruction}}',
						],
					],
				],
			],
			'write_and_save_email_draft_for_order' => [
				'id'          => 'write_and_save_email_draft_for_order',
				'label'       => __( 'Write and save email draft', 'yoro-agent-core' ),
				'description' => __( 'Load order details, compose a customer email, then save it as a draft on the order.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_id'    => 'integer',
					'instruction' => 'string',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-agent-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'compose_email',
						'label'  => __( 'Compose customer email', 'yoro-agent-core' ),
						'action' => 'orders.compose_customer_email',
						'params' => [
							'order_id'    => '{{order_id}}',
							'instruction' => '{{instruction}}',
						],
						'capture' => [
							'email_subject' => 'result.email.subject',
							'email_body'    => 'result.email.body',
						],
					],
					[
						'id'     => 'save_draft',
						'label'  => __( 'Save customer email draft', 'yoro-agent-core' ),
						'action' => 'orders.save_customer_email_draft',
						'params' => [
							'order_id' => '{{order_id}}',
							'subject'  => '{{context.email_subject}}',
							'body'     => '{{context.email_body}}',
						],
					],
				],
			],
			'write_and_send_email_for_order' => [
				'id'          => 'write_and_send_email_for_order',
				'label'       => __( 'Write and send email', 'yoro-agent-core' ),
				'description' => __( 'Load order details, compose a customer email, then send it to the customer.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_id'    => 'integer',
					'instruction' => 'string',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-agent-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'compose_email',
						'label'  => __( 'Compose customer email', 'yoro-agent-core' ),
						'action' => 'orders.compose_customer_email',
						'params' => [
							'order_id'    => '{{order_id}}',
							'instruction' => '{{instruction}}',
						],
						'capture' => [
							'email_subject' => 'result.email.subject',
							'email_body'    => 'result.email.body',
						],
					],
					[
						'id'     => 'send_email',
						'label'  => __( 'Send customer email', 'yoro-agent-core' ),
						'action' => 'orders.send_customer_email',
						'params' => [
							'order_id' => '{{order_id}}',
							'subject'  => '{{context.email_subject}}',
							'body'     => '{{context.email_body}}',
						],
					],
				],
			],
		];

		return apply_filters( 'yoro_workflow_templates', $templates );
	}

	public static function get( $template_id ) {
		$template_id = sanitize_key( $template_id );
		$templates   = self::all();

		return $templates[ $template_id ] ?? null;
	}

	public static function to_public_array() {
		$data = [];

		foreach ( self::all() as $id => $template ) {
			$data[] = [
				'id'          => $id,
				'label'       => $template['label'] ?? $id,
				'description' => $template['description'] ?? '',
				'category'    => $template['category'] ?? 'general',
				'risk_level'  => $template['risk_level'] ?? 'low',
				'params'      => $template['params'] ?? [],
				'steps_count' => ! empty( $template['steps'] ) && is_array( $template['steps'] ) ? count( $template['steps'] ) : 0,
			];
		}

		return $data;
	}

	public static function build_parsed( $template_id, $params, $command = '' ) {
		$template = self::get( $template_id );
		$definition = class_exists( 'YoRo_Workflow_Definition_Registry' )
			? YoRo_Workflow_Definition_Registry::get( $template_id )
			: null;

		if ( ! $template ) {
			return new WP_Error(
				'yoro_workflow_template_not_found',
				__( 'Workflow template not found.', 'yoro-agent-core' )
			);
		}

		$params = is_array( $params ) ? $params : [];
		$params = self::sanitize_params( $params );

		$missing = self::get_missing_params( $template, $params );

		if ( ! empty( $missing ) ) {
			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'workflow.' . sanitize_key( $template_id ),
				'workflow_template'  => sanitize_key( $template_id ),
				'params'             => $params,
				'missing'            => $missing,
				'message'            => self::build_missing_message( $template, $missing ),
				'parser_source'      => 'rule',
				'parser_rule'        => 'rule.workflow_template.missing_params',
				'confidence'         => 1.0,
				'original_command'   => $command,
				'normalized_command' => strtolower( $command ),
			];
		}

		$steps = [];
		$step_source = self::resolve_step_source( $template_id, $template, $definition, $params );

		foreach ( $step_source as $step ) {
			if ( empty( $step['action'] ) ) {
				continue;
			}

			$steps[] = self::normalize_step(
				$step,
				$params,
				count( $steps )
			);
		}

		if ( empty( $steps ) ) {
			return new WP_Error(
				'yoro_workflow_template_empty',
				__( 'Workflow template has no valid steps.', 'yoro-agent-core' )
			);
		}

		$current_workflow = class_exists( 'YoRo_Workflow_Runner' )
			? YoRo_Workflow_Runner::build_sequence_workflow(
				$template_id,
				$steps,
				$params,
				$command
			)
			: [];

		return [
			'success'           => true,
			'matched'           => true,
			'action'            => 'workflow.' . sanitize_key( $template_id ),
			'workflow_template' => sanitize_key( $template_id ),
			'workflow_label'    => sanitize_text_field( $definition['label'] ?? $template['label'] ?? $template_id ),
			'workflow'          => [
				'id'            => sanitize_key( $template_id ),
				'definition_id' => sanitize_key( $definition['id'] ?? $template_id ),
				'label'         => sanitize_text_field( $definition['label'] ?? $template['label'] ?? $template_id ),
				'description'   => sanitize_text_field( $definition['description'] ?? $template['description'] ?? '' ),
				'category'      => sanitize_key( $definition['category'] ?? $template['category'] ?? 'general' ),
				'risk_level'    => sanitize_key( $definition['risk_level'] ?? $template['risk_level'] ?? 'low' ),
			],
			'params'            => $params,
			'actions'           => $steps,
			'current_workflow'  => $current_workflow,
			'parser_source'     => 'rule',
			'parser_rule'       => 'rule.workflow_template.' . sanitize_key( $template_id ),
			'confidence'        => 1.0,
			'original_command'  => $command,
		];
	}

	private static function sanitize_params( $params ) {
		$clean = [];

		foreach ( $params as $key => $value ) {
			$key = sanitize_key( $key );

			if ( 'order_id' === $key ) {
				$clean[ $key ] = absint( $value );
				continue;
			}

			if ( 'order_ids' === $key && is_array( $value ) ) {
				$clean[ $key ] = array_values( array_filter( array_unique( array_map( 'absint', $value ) ) ) );
				continue;
			}

			if ( 'delivery_mode' === $key ) {
				$mode = sanitize_key( $value );

				if ( ! in_array( $mode, [ 'preview', 'save_draft', 'send' ], true ) ) {
					$mode = 'preview';
				}

				$clean[ $key ] = $mode;
				continue;
			}

			if ( 'email_delivery_mode' === $key ) {
				$mode = sanitize_key( $value );

				if ( ! in_array( $mode, [ 'preview', 'save_draft', 'send' ], true ) ) {
					$mode = 'preview';
				}

				$clean[ $key ] = $mode;
				continue;
			}

			$clean[ $key ] = is_scalar( $value ) ? sanitize_text_field( $value ) : $value;
		}

		return $clean;
	}

	private static function resolve_step_source( $template_id, $template, $definition, $params ) {
		$step_source = ! empty( $definition['steps'] ) && is_array( $definition['steps'] )
			? $definition['steps']
			: $template['steps'];

		if ( 'write_email_for_order' !== sanitize_key( $template_id ) ) {
			$template_id = sanitize_key( $template_id );

			if ( 'verify_customer_before_fulfillment' === $template_id ) {
				return self::apply_verification_email_branch( $step_source, $params );
			}

			if ( 'refund_review' === $template_id ) {
				return self::apply_refund_followup_branch( $step_source, $params );
			}

			return $step_source;
		}

		$delivery_mode = sanitize_key( $params['delivery_mode'] ?? 'preview' );

		if ( ! in_array( $delivery_mode, [ 'save_draft', 'send' ], true ) ) {
			return $step_source;
		}

		$branch_step = [
			'id'     => 'save_draft',
			'label'  => __( 'Save customer email draft', 'yoro-agent-core' ),
			'action' => 'orders.save_customer_email_draft',
			'params' => [
				'order_id' => '{{order_id}}',
				'subject'  => '{{context.email_subject}}',
				'body'     => '{{context.email_body}}',
			],
		];

		if ( 'send' === $delivery_mode ) {
			$branch_step = [
				'id'     => 'send_email',
				'label'  => __( 'Send customer email', 'yoro-agent-core' ),
				'action' => 'orders.send_customer_email',
				'params' => [
					'order_id' => '{{order_id}}',
					'subject'  => '{{context.email_subject}}',
					'body'     => '{{context.email_body}}',
				],
			];
		}

		$step_source = array_values( (array) $step_source );

		foreach ( $step_source as $index => $step ) {
			if ( 'compose_email' !== sanitize_key( $step['id'] ?? '' ) ) {
				continue;
			}

			$step_source[ $index ]['capture'] = [
				'email_subject' => 'result.email.subject',
				'email_body'    => 'result.email.body',
			];
			$step_source[] = $branch_step;
			break;
		}

		return $step_source;
	}

	private static function apply_verification_email_branch( $step_source, $params ) {
		$email_delivery_mode = sanitize_key( $params['email_delivery_mode'] ?? 'preview' );

		if ( ! in_array( $email_delivery_mode, [ 'save_draft', 'send' ], true ) ) {
			return $step_source;
		}

		$branch_step = [
			'id'     => 'save_draft',
			'label'  => __( 'Save verification email draft', 'yoro-agent-core' ),
			'action' => 'orders.save_customer_email_draft',
			'params' => [
				'order_id' => '{{order_id}}',
				'subject'  => '{{context.email_subject}}',
				'body'     => '{{context.email_body}}',
			],
		];

		if ( 'send' === $email_delivery_mode ) {
			$branch_step = [
				'id'     => 'send_email',
				'label'  => __( 'Send verification email', 'yoro-agent-core' ),
				'action' => 'orders.send_customer_email',
				'params' => [
					'order_id' => '{{order_id}}',
					'subject'  => '{{context.email_subject}}',
					'body'     => '{{context.email_body}}',
				],
			];
		}

		$step_source = array_values( (array) $step_source );

		foreach ( $step_source as $index => $step ) {
			if ( 'orders.compose_customer_email' !== sanitize_text_field( $step['action'] ?? '' ) ) {
				continue;
			}

			$step_source[ $index ]['capture'] = [
				'email_subject' => 'result.email.subject',
				'email_body'    => 'result.email.body',
			];
			$step_source[] = $branch_step;
			break;
		}

		return $step_source;
	}

	private static function apply_refund_followup_branch( $step_source, $params ) {
		$delivery_mode = sanitize_key( $params['delivery_mode'] ?? 'preview' );
		$instruction   = sanitize_textarea_field( $params['instruction'] ?? '' );

		if ( '' === $instruction && ! in_array( $delivery_mode, [ 'save_draft', 'send' ], true ) ) {
			return $step_source;
		}

		$step_source = array_values( (array) $step_source );
		$step_source[] = [
			'id'     => 'compose_email',
			'label'  => __( 'Compose refund follow-up email', 'yoro-agent-core' ),
			'action' => 'orders.compose_customer_email',
			'params' => [
				'order_id'    => '{{order_id}}',
				'instruction' => $instruction,
			],
			'capture' => [
				'email_subject' => 'result.email.subject',
				'email_body'    => 'result.email.body',
			],
		];

		if ( 'preview' === $delivery_mode ) {
			return $step_source;
		}

		$step_source[] = [
			'id'     => 'save_draft',
			'label'  => __( 'Save refund follow-up draft', 'yoro-agent-core' ),
			'action' => 'orders.save_customer_email_draft',
			'params' => [
				'order_id' => '{{order_id}}',
				'subject'  => '{{context.email_subject}}',
				'body'     => '{{context.email_body}}',
			],
		];

		if ( 'send' === $delivery_mode ) {
			$step_source[ count( $step_source ) - 1 ] = [
				'id'     => 'send_email',
				'label'  => __( 'Send refund follow-up email', 'yoro-agent-core' ),
				'action' => 'orders.send_customer_email',
				'params' => [
					'order_id' => '{{order_id}}',
					'subject'  => '{{context.email_subject}}',
					'body'     => '{{context.email_body}}',
				],
			];
		}

		return $step_source;
	}

	private static function get_missing_params( $template, $params ) {
		$required = isset( $template['params'] ) && is_array( $template['params'] ) ? array_keys( $template['params'] ) : [];
		$missing  = [];

		foreach ( $required as $key ) {
			if ( empty( $params[ $key ] ) ) {
				$missing[] = sanitize_key( $key );
			}
		}

		return $missing;
	}

	private static function build_missing_message( $template, $missing ) {
		if ( in_array( 'order_id', $missing, true ) ) {
			return __( 'Which order should I run this workflow for?', 'yoro-agent-core' );
		}

		return __( 'This workflow needs more information before it can run.', 'yoro-agent-core' );
	}

	private static function normalize_step( $step, $params, $index = 0 ) {
		$action = sanitize_text_field( $step['action'] ?? '' );

		$normalized = [
			'id'          => ! empty( $step['id'] )
				? sanitize_key( $step['id'] )
				: 'step_' . ( absint( $index ) + 1 ),
			'label'       => ! empty( $step['label'] )
				? sanitize_text_field( $step['label'] )
				: $action,
			'action'      => $action,
			'params'      => self::replace_placeholders(
				isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
				$params
			),
			'when'        => isset( $step['when'] ) && is_array( $step['when'] )
				? self::replace_placeholders( $step['when'], $params )
				: [],
			'capture'     => isset( $step['capture'] ) && is_array( $step['capture'] )
				? $step['capture']
				: [],
			'on_error'    => ! empty( $step['on_error'] )
				? sanitize_key( $step['on_error'] )
				: 'stop',
			'retry'       => isset( $step['retry'] ) && is_array( $step['retry'] )
				? [
					'max_attempts' => isset( $step['retry']['max_attempts'] ) ? max( 1, absint( $step['retry']['max_attempts'] ) ) : 1,
				]
				: [
					'max_attempts' => 1,
				],
			'rollback'    => isset( $step['rollback'] ) && is_array( $step['rollback'] )
				? $step['rollback']
				: [],
			'meta'        => isset( $step['meta'] ) && is_array( $step['meta'] )
				? $step['meta']
				: [],
		];

		return $normalized;
	}

	private static function replace_placeholders( $value, $params ) {
		if ( is_array( $value ) ) {
			$replaced = [];

			foreach ( $value as $k => $v ) {
				$replaced[ $k ] = self::replace_placeholders( $v, $params );
			}

			return $replaced;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( preg_match( '/^\{\{([a-z0-9_]+)\}\}$/i', $value, $m ) ) {
			$key = sanitize_key( $m[1] );

			return $params[ $key ] ?? '';
		}

		return preg_replace_callback(
			'/\{\{([a-z0-9_]+)\}\}/i',
			function( $m ) use ( $params ) {
				$key = sanitize_key( $m[1] );

				return isset( $params[ $key ] ) && is_scalar( $params[ $key ] )
					? (string) $params[ $key ]
					: '';
			},
			$value
		);
	}
}
