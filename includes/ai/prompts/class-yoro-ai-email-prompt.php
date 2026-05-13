<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Email_Prompt {

	public static function build( array $order_context, $instruction ) {
		return [
			'model' => YoRo_AI_Settings::get_openai_model(),
			'input' => [
				[
					'role'    => 'system',
					'content' => 'You write concise, professional WooCommerce customer support emails. Return JSON only. Do not include markdown. Do not mention internal risk systems or admin-only information. The email must be polite, clear, and action-oriented. Do not add a sender signature, support-team signature, store-signoff block, or closing name because the system will add the final signature separately.',
				],
				[
					'role'    => 'user',
					'content' => wp_json_encode(
						[
							'instruction'   => $instruction,
							'order_context' => $order_context,
							'output_schema' => [
								'subject' => 'string',
								'body'    => 'plain text email body',
							],
						]
					),
				],
			],
			'text' => [
				'format' => [
					'type'   => 'json_schema',
					'name'   => 'yoro_customer_email',
					'strict' => true,
					'schema' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'subject' => [ 'type' => 'string' ],
							'body'    => [ 'type' => 'string' ],
						],
						'required' => [ 'subject', 'body' ],
					],
				],
			],
		];
	}
}
