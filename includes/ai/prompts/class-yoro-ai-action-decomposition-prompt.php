<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Action_Decomposition_Prompt {

	public static function build_request( array $payload ) {
		return [
			'model' => YoRo_AI_Settings::get_openai_model(),
			'input' => [
				[
					'role'    => 'system',
					'content' => implode(
						"\n",
						[
							'You are YoRo, a WooCommerce action decomposition engine.',
							'Return JSON only.',
							'Your job is to read one user command and decompose it into supported action requests plus unsupported requests.',
							'Never invent action names. Use only action names from supported_actions.',
							'Do not build an execution plan. Only identify requested actions in the user command.',
							'Preserve user intent even when part of the command is unsupported.',
							'If the request includes both supported and unsupported parts, include both.',
							'When a part is unsupported, put it in unsupported_requests instead of forcing it into supported_actions.',
							'For email authoring flows, prefer one requested action with action="orders.compose_customer_email" plus params.delivery_mode = "preview", "save_draft", or "send".',
							'Do not output orders.save_customer_email_draft or orders.send_customer_email as standalone requested actions in decomposition.',
							'Keep params compact and conservative.',
							'If you are unsure, leave params empty or add the field name to missing_params.',
							'Prefer one action per user-requested intent, not internal dependency steps.',
						]
					),
				],
				[
					'role'    => 'user',
					'content' => wp_json_encode( $payload ),
				],
			],
			'text' => [
				'format' => [
					'type'   => 'json_schema',
					'name'   => 'yoro_action_decomposition',
					'strict' => true,
					'schema' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'matched'              => [ 'type' => 'boolean' ],
							'confidence'           => [ 'type' => 'number' ],
							'reason'               => [ 'type' => 'string' ],
							'requested_actions'    => [
								'type'  => 'array',
								'items' => [
									'type'                 => 'object',
									'additionalProperties' => false,
									'properties'           => [
										'id'         => [ 'type' => 'string' ],
										'label'      => [ 'type' => 'string' ],
										'action'     => [ 'type' => 'string' ],
										'confidence' => [ 'type' => 'number' ],
										'params'     => [
											'type'                 => 'object',
											'additionalProperties' => false,
											'properties'           => [
												'order_id'      => [ 'anyOf' => [ [ 'type' => 'integer' ], [ 'type' => 'string' ] ] ],
												'order_ids'     => [ 'type' => 'array', 'items' => [ 'anyOf' => [ [ 'type' => 'integer' ], [ 'type' => 'string' ] ] ] ],
												'status'        => [ 'type' => 'string' ],
												'note'          => [ 'type' => 'string' ],
												'instruction'   => [ 'type' => 'string' ],
												'delivery_mode' => [ 'type' => 'string', 'enum' => [ 'preview', 'save_draft', 'send' ] ],
											],
											'required' => [ 'order_id', 'order_ids', 'status', 'note', 'instruction', 'delivery_mode' ],
										],
									],
									'required' => [ 'id', 'label', 'action', 'confidence', 'params' ],
								],
							],
							'unsupported_requests' => [
								'type'  => 'array',
								'items' => [
									'type'                 => 'object',
									'additionalProperties' => false,
									'properties'           => [
										'key'    => [ 'type' => 'string' ],
										'label'  => [ 'type' => 'string' ],
										'reason' => [ 'type' => 'string' ],
									],
									'required' => [ 'key', 'label', 'reason' ],
								],
							],
							'missing_params'       => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
						],
						'required' => [ 'matched', 'confidence', 'reason', 'requested_actions', 'unsupported_requests', 'missing_params' ],
					],
				],
			],
		];
	}
}
