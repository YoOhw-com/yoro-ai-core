<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Workflow_Prompt {

	public static function build( array $payload ) {
		return wp_json_encode( $payload );
	}

	public static function build_request( $prompt ) {
		return [
			'model' => YoRo_AI_Settings::get_openai_model(),
			'input' => [
				[
					'role'    => 'system',
					'content' => implode(
						"\n",
						[
							'You are YoRo, a WooCommerce operations planning engine.',
							'Return JSON only.',
							'Build a safe workflow plan using only the available actions provided in the user payload.',
							'Never invent action names.',
							'Prefer read-only steps before write steps.',
							'If the request can be handled by only one simple action, you may still return a single-step workflow.',
							'If the command is ambiguous, unsafe, or missing critical identifiers, return matched=false.',
							'Do not include markdown.',
						]
					),
				],
				[
					'role'    => 'user',
					'content' => (string) $prompt,
				],
			],
			'text' => [
				'format' => [
					'type'   => 'json_schema',
					'name'   => 'yoro_workflow_plan',
					'strict' => true,
					'schema' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'matched'        => [ 'type' => 'boolean' ],
							'confidence'     => [ 'type' => 'number' ],
							'reason'         => [ 'type' => 'string' ],
							'workflow_label' => [ 'type' => 'string' ],
							'actions'        => [
								'type'  => 'array',
								'items' => [
									'type'                 => 'object',
									'additionalProperties' => false,
									'properties'           => [
										'id'       => [ 'type' => 'string' ],
										'label'    => [ 'type' => 'string' ],
										'action'   => [ 'type' => 'string' ],
										'params'   => self::get_workflow_step_params_schema(),
										'when'     => self::get_workflow_step_when_schema(),
										'capture'  => self::get_workflow_step_capture_schema(),
										'on_error' => [
											'type' => 'string',
											'enum' => [ 'stop', 'continue', 'rollback', 'skip_remaining' ],
										],
										'retry'    => [
											'type'                 => 'object',
											'additionalProperties' => false,
											'properties'           => [
												'max_attempts' => [ 'type' => 'integer' ],
											],
											'required' => [ 'max_attempts' ],
										],
									],
									'required' => [ 'id', 'label', 'action', 'params', 'when', 'capture', 'on_error', 'retry' ],
								],
							],
						],
						'required' => [ 'matched', 'confidence', 'reason', 'workflow_label', 'actions' ],
					],
				],
			],
		];
	}

	private static function get_workflow_step_params_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'order_id'    => [ 'anyOf' => [ [ 'type' => 'integer' ], [ 'type' => 'string' ] ] ],
				'order_ids'   => [ 'type' => 'array', 'items' => [ 'anyOf' => [ [ 'type' => 'integer' ], [ 'type' => 'string' ] ] ] ],
				'status'      => [ 'type' => 'string' ],
				'note'        => [ 'type' => 'string' ],
				'instruction' => [ 'type' => 'string' ],
				'subject'     => [ 'type' => 'string' ],
				'body'        => [ 'type' => 'string' ],
				'template'    => [ 'type' => 'string' ],
				'template_id' => [ 'type' => 'string' ],
				'draft_id'    => [ 'type' => 'string' ],
				'limit'       => [ 'type' => 'integer' ],
				'email'       => [ 'type' => 'string' ],
				'phone'       => [ 'type' => 'string' ],
				'customer_id' => [ 'type' => 'integer' ],
			],
			'required' => [ 'order_id', 'order_ids', 'status', 'note', 'instruction', 'subject', 'body', 'template', 'template_id', 'draft_id', 'limit', 'email', 'phone', 'customer_id' ],
		];
	}

	private static function get_workflow_step_when_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'all' => [ 'type' => 'array', 'items' => self::get_workflow_condition_schema() ],
				'any' => [ 'type' => 'array', 'items' => self::get_workflow_condition_schema() ],
			],
			'required' => [ 'all', 'any' ],
		];
	}

	private static function get_workflow_condition_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'source'   => [ 'type' => 'string' ],
				'operator' => [
					'type' => 'string',
					'enum' => [ 'not_empty', 'empty', 'equals', 'not_equals', 'contains', 'greater_than', 'less_than' ],
				],
				'value'    => [
					'anyOf' => [
						[ 'type' => 'string' ],
						[ 'type' => 'number' ],
						[ 'type' => 'integer' ],
						[ 'type' => 'boolean' ],
					],
				],
			],
			'required' => [ 'source', 'operator', 'value' ],
		];
	}

	private static function get_workflow_step_capture_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'email_subject'     => [ 'type' => 'string' ],
				'email_body'        => [ 'type' => 'string' ],
				'loaded_orders'     => [ 'type' => 'string' ],
				'order'             => [ 'type' => 'string' ],
				'orders'            => [ 'type' => 'string' ],
				'customer_email'    => [ 'type' => 'string' ],
				'customer_phone'    => [ 'type' => 'string' ],
				'refundable_amount' => [ 'type' => 'string' ],
			],
			'required' => [ 'email_subject', 'email_body', 'loaded_orders', 'order', 'orders', 'customer_email', 'customer_phone', 'refundable_amount' ],
		];
	}
}
