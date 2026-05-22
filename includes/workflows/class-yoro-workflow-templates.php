<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Workflow_Templates {

	private const MAX_MULTI_ORDER_EMAIL_COUNT = 5;
	private const PARSER_SOURCE_GUARD         = 'guard';
	private const PARSER_RULE_PREFIX_GUARD    = 'guard.workflow_template.';

	public static function all() {
		$templates = [
			'review_suspicious_order' => [
				'id'          => 'review_suspicious_order',
				'label'       => __( 'Review suspicious order', 'yoro-ai-core' ),
				'description' => __( 'Review an order, find related identity matches, then add a suspicious order note.', 'yoro-ai-core' ),
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
				'label'       => __( 'Verify customer before fulfillment', 'yoro-ai-core' ),
				'description' => __( 'Put an order on hold, add a verification note, then branch into preview, save-draft, or send for the verification email.', 'yoro-ai-core' ),
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
				'label'       => __( 'Refund review', 'yoro-ai-core' ),
				'description' => __( 'Review refundable amount and order details, then optionally branch into a refund follow-up email flow.', 'yoro-ai-core' ),
				'category'    => 'orders',
				'risk_level'  => 'low',
				'params'      => [
					'order_id' => 'integer',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-ai-core' ),
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
						'label'  => __( 'Review refundable amount', 'yoro-ai-core' ),
						'action' => 'orders.refund_preview',
						'params' => [
							'order_id' => '{{order_id}}',
						],
					],
				],
			],
			'hold_and_add_note' => [
				'id'          => 'hold_and_add_note',
				'label'       => __( 'Hold and add note', 'yoro-ai-core' ),
				'description' => __( 'Review an order, put it on hold, then add the requested internal note.', 'yoro-ai-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_id' => 'integer',
					'note'     => 'string',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-ai-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'hold_order',
						'label'  => __( 'Put order on hold', 'yoro-ai-core' ),
						'action' => 'orders.hold',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'add_note',
						'label'  => __( 'Add internal note', 'yoro-ai-core' ),
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
				'label'       => __( 'Update status with note', 'yoro-ai-core' ),
				'description' => __( 'Review an order, update its status, then save the requested note in the same workflow.', 'yoro-ai-core' ),
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
						'label'  => __( 'Load order details', 'yoro-ai-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'update_status',
						'label'  => __( 'Update order status with note', 'yoro-ai-core' ),
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
				'label'       => __( 'Write email for order', 'yoro-ai-core' ),
				'description' => __( 'Load order details, compose a customer email, then branch into preview, save-draft, or send flow based on the requested outcome.', 'yoro-ai-core' ),
				'category'    => 'orders',
				'risk_level'  => 'low',
				'params'      => [
					'order_id'    => 'integer',
					'instruction' => 'string',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-ai-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'compose_email',
						'label'  => __( 'Compose customer email', 'yoro-ai-core' ),
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
				'label'       => __( 'Write and save email draft', 'yoro-ai-core' ),
				'description' => __( 'Load order details, compose a customer email, then save it as a draft on the order.', 'yoro-ai-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_id'    => 'integer',
					'instruction' => 'string',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-ai-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'compose_email',
						'label'  => __( 'Compose customer email', 'yoro-ai-core' ),
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
						'label'  => __( 'Save customer email draft', 'yoro-ai-core' ),
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
				'label'       => __( 'Write and send email', 'yoro-ai-core' ),
				'description' => __( 'Load order details, compose a customer email, then send it to the customer.', 'yoro-ai-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_id'    => 'integer',
					'instruction' => 'string',
				],
				'steps'       => [
					[
						'id'     => 'load_order',
						'label'  => __( 'Load order details', 'yoro-ai-core' ),
						'action' => 'orders.get_details',
						'params' => [
							'order_ids' => [ '{{order_id}}' ],
						],
					],
					[
						'id'     => 'compose_email',
						'label'  => __( 'Compose customer email', 'yoro-ai-core' ),
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
						'label'  => __( 'Send customer email', 'yoro-ai-core' ),
						'action' => 'orders.send_customer_email',
						'params' => [
							'order_id' => '{{order_id}}',
							'subject'  => '{{context.email_subject}}',
							'body'     => '{{context.email_body}}',
						],
					],
				],
			],
			'write_email_for_orders' => [
				'id'          => 'write_email_for_orders',
				'label'       => __( 'Write emails for orders', 'yoro-ai-core' ),
				'description' => __( 'Load each selected order, compose a customer email for each one, then branch into preview, save-draft, or send flow based on the requested outcome.', 'yoro-ai-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_ids'    => 'array<int>',
					'instruction'  => 'string',
				],
				'steps'       => [],
			],
			'write_and_save_email_drafts_for_orders' => [
				'id'          => 'write_and_save_email_drafts_for_orders',
				'label'       => __( 'Write and save email drafts for orders', 'yoro-ai-core' ),
				'description' => __( 'Load each selected order, compose a customer email for each one, then save each result as a draft on the order.', 'yoro-ai-core' ),
				'category'    => 'orders',
				'risk_level'  => 'medium',
				'params'      => [
					'order_ids'    => 'array<int>',
					'instruction'  => 'string',
				],
				'steps'       => [],
			],
			'write_and_send_emails_for_orders' => [
				'id'          => 'write_and_send_emails_for_orders',
				'label'       => __( 'Write and send emails for orders', 'yoro-ai-core' ),
				'description' => __( 'Load each selected order, compose a customer email for each one, then send each reviewed email to the customer.', 'yoro-ai-core' ),
				'category'    => 'orders',
				'risk_level'  => 'high',
				'params'      => [
					'order_ids'    => 'array<int>',
					'instruction'  => 'string',
				],
				'steps'       => [],
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
				__( 'Workflow template not found.', 'yoro-ai-core' )
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
				'parser_source'      => self::PARSER_SOURCE_GUARD,
				'parser_rule'        => self::PARSER_RULE_PREFIX_GUARD . 'missing_params',
				'confidence'         => 1.0,
				'original_command'   => $command,
				'normalized_command' => strtolower( $command ),
			];
		}

		$order_limit_error = self::maybe_build_multi_order_email_limit_result( $template_id, $template, $params, $command, $definition );

		if ( is_array( $order_limit_error ) ) {
			return $order_limit_error;
		}

		$recipient_guard_error = self::maybe_build_multi_order_email_recipient_result( $template_id, $template, $params, $command, $definition );

		if ( is_array( $recipient_guard_error ) ) {
			return $recipient_guard_error;
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
				__( 'Workflow template has no valid steps.', 'yoro-ai-core' )
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
			'parser_source'     => self::PARSER_SOURCE_GUARD,
			'parser_rule'       => self::PARSER_RULE_PREFIX_GUARD . sanitize_key( $template_id ),
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

		if ( in_array( sanitize_key( $template_id ), [ 'write_email_for_orders', 'write_and_save_email_drafts_for_orders', 'write_and_send_emails_for_orders' ], true ) ) {
			return self::build_multi_order_email_steps( $template_id, $params );
		}

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
			'label'  => __( 'Save customer email draft', 'yoro-ai-core' ),
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
				'label'  => __( 'Send customer email', 'yoro-ai-core' ),
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
			'label'  => __( 'Save verification email draft', 'yoro-ai-core' ),
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
				'label'  => __( 'Send verification email', 'yoro-ai-core' ),
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
			'label'  => __( 'Compose refund follow-up email', 'yoro-ai-core' ),
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
			'label'  => __( 'Save refund follow-up draft', 'yoro-ai-core' ),
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
				'label'  => __( 'Send refund follow-up email', 'yoro-ai-core' ),
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
		if ( in_array( 'order_ids', $missing, true ) ) {
			return __( 'Which orders should I run this workflow for?', 'yoro-ai-core' );
		}

		if ( in_array( 'order_id', $missing, true ) ) {
			return __( 'Which order should I run this workflow for?', 'yoro-ai-core' );
		}

		return __( 'This workflow needs more information before it can run.', 'yoro-ai-core' );
	}

	private static function maybe_build_multi_order_email_limit_result( $template_id, $template, $params, $command, $definition ) {
		$template_id = sanitize_key( $template_id );

		if ( ! in_array( $template_id, [ 'write_email_for_orders', 'write_and_save_email_drafts_for_orders', 'write_and_send_emails_for_orders' ], true ) ) {
			return null;
		}

		$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_values( array_filter( array_unique( array_map( 'absint', $params['order_ids'] ) ) ) )
			: [];
		$limit = self::get_multi_order_email_limit();

		if ( $limit <= 0 || count( $order_ids ) <= $limit ) {
			return null;
		}

		return [
			'success'            => false,
			'matched'            => true,
			'stage'              => 'clarify',
			'action'             => 'workflow.' . $template_id,
			'workflow_template'  => $template_id,
			'workflow_label'     => sanitize_text_field( $definition['label'] ?? $template['label'] ?? $template_id ),
			'params'             => $params,
			'missing'            => [],
			'message'            => self::build_multi_order_email_limit_message( $limit, count( $order_ids ) ),
			'parser_source'      => self::PARSER_SOURCE_GUARD,
			'parser_rule'        => self::PARSER_RULE_PREFIX_GUARD . 'order_limit',
			'confidence'         => 1.0,
			'original_command'   => $command,
			'normalized_command' => strtolower( $command ),
		];
	}

	private static function maybe_build_multi_order_email_recipient_result( $template_id, $template, $params, $command, $definition ) {
		$template_id = sanitize_key( $template_id );

		if ( ! in_array( $template_id, [ 'write_email_for_orders', 'write_and_save_email_drafts_for_orders', 'write_and_send_emails_for_orders' ], true ) ) {
			return null;
		}

		$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_values( array_filter( array_unique( array_map( 'absint', $params['order_ids'] ) ) ) )
			: [];

		if ( count( $order_ids ) <= 1 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$recipient_emails = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$email = sanitize_email( $order->get_billing_email() );

			if ( '' === $email ) {
				continue;
			}

			$recipient_emails[ strtolower( $email ) ] = true;
		}

		if ( count( $recipient_emails ) <= 1 ) {
			return null;
		}

		return [
			'success'            => false,
			'matched'            => true,
			'stage'              => 'clarify',
			'action'             => 'workflow.' . $template_id,
			'workflow_template'  => $template_id,
			'workflow_label'     => sanitize_text_field( $definition['label'] ?? $template['label'] ?? $template_id ),
			'params'             => $params,
			'missing'            => [],
			'message'            => self::build_multi_order_email_recipient_message(),
			'parser_source'      => self::PARSER_SOURCE_GUARD,
			'parser_rule'        => self::PARSER_RULE_PREFIX_GUARD . 'recipient_mismatch',
			'confidence'         => 1.0,
			'original_command'   => $command,
			'normalized_command' => strtolower( $command ),
		];
	}

	private static function get_multi_order_email_limit() {
		$limit = (int) apply_filters( 'yoro_multi_order_email_workflow_limit', self::MAX_MULTI_ORDER_EMAIL_COUNT );

		return max( 1, $limit );
	}

	private static function build_multi_order_email_limit_message( $limit, $requested_count ) {
		return sprintf(
			/* translators: 1: maximum orders per workflow, 2: requested order count */
			__( 'I can only prepare customer emails for up to %1$d orders at a time, but this request targets %2$d orders. Please split the batch into smaller groups.', 'yoro-ai-core' ),
			absint( $limit ),
			absint( $requested_count )
		);
	}

	private static function build_multi_order_email_recipient_message() {
		return __( 'I can only prepare customer emails in one batch when all selected orders belong to the same recipient email. These orders belong to different recipients, so please split the request by customer.', 'yoro-ai-core' );
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

	private static function build_multi_order_email_steps( $template_id, $params ) {
		$template_id = sanitize_key( $template_id );
		$params = is_array( $params ) ? $params : [];
		$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_values( array_filter( array_unique( array_map( 'absint', $params['order_ids'] ) ) ) )
			: [];
		$instruction = sanitize_textarea_field( $params['instruction'] ?? '' );
		$steps = [];

		if ( empty( $order_ids ) || '' === $instruction ) {
			return $steps;
		}

		foreach ( $order_ids as $order_id ) {
			$subject_key = 'email_subject_' . $order_id;
			$body_key    = 'email_body_' . $order_id;

			$steps[] = [
				'id'     => 'load_order_' . $order_id,
				'label'  => sprintf( __( 'Load order #%d details', 'yoro-ai-core' ), $order_id ),
				'action' => 'orders.get_details',
				'params' => [
					'order_ids' => [ $order_id ],
				],
			];

			$compose_step = [
				'id'     => 'compose_email_' . $order_id,
				'label'  => sprintf( __( 'Compose customer email for order #%d', 'yoro-ai-core' ), $order_id ),
				'action' => 'orders.compose_customer_email',
				'params' => [
					'order_id'    => $order_id,
					'instruction' => self::build_per_order_multi_email_instruction( $instruction, $order_id ),
				],
			];

			if ( in_array( $template_id, [ 'write_and_save_email_drafts_for_orders', 'write_and_send_emails_for_orders' ], true ) ) {
				$compose_step['capture'] = [
					$subject_key => 'result.email.subject',
					$body_key    => 'result.email.body',
				];
			}

			$steps[] = $compose_step;

			if ( 'write_and_save_email_drafts_for_orders' === $template_id ) {
				$steps[] = [
					'id'     => 'save_draft_' . $order_id,
					'label'  => sprintf( __( 'Save email draft for order #%d', 'yoro-ai-core' ), $order_id ),
					'action' => 'orders.save_customer_email_draft',
					'params' => [
						'order_id' => $order_id,
						'subject'  => '{{context.' . $subject_key . '}}',
						'body'     => '{{context.' . $body_key . '}}',
					],
				];
			} elseif ( 'write_and_send_emails_for_orders' === $template_id ) {
				$steps[] = [
					'id'     => 'send_email_' . $order_id,
					'label'  => sprintf( __( 'Send customer email for order #%d', 'yoro-ai-core' ), $order_id ),
					'action' => 'orders.send_customer_email',
					'params' => [
						'order_id' => $order_id,
						'subject'  => '{{context.' . $subject_key . '}}',
						'body'     => '{{context.' . $body_key . '}}',
					],
				];
			}
		}

		return $steps;
	}

	private static function build_per_order_multi_email_instruction( $instruction, $order_id ) {
		$instruction = sanitize_textarea_field( $instruction );
		$order_id    = absint( $order_id );

		if ( '' === $instruction || $order_id <= 0 ) {
			return $instruction;
		}

		return sprintf(
			/* translators: 1: order id, 2: original user instruction */
			__( 'Write the email only for order #%1$d. Treat any references to other orders in the original request as internal batch context, not customer-facing content. Do not mention any other order number or order details that are not for this order. Customer request: %2$s', 'yoro-ai-core' ),
			$order_id,
			$instruction
		);
	}
}
