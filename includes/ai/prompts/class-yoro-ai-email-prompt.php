<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Email_Prompt {

		public static function build( array $order_context, $instruction ) {
			return [
				'input' => [
				[
					'role'    => 'system',
					'content' => 'You write concise, professional WooCommerce customer support emails. Return JSON only. Do not include markdown. Do not mention internal risk systems or admin-only information. The email must be polite, clear, and action-oriented. Do not add a sender signature, support-team signature, store-signoff block, or closing name because the system will add the final signature separately. You must follow the fulfillment facts exactly: if tracking_detected is false, do not say the order has shipped, is dispatched, is on the way, has been handed to the carrier, or give delivery timing as if shipment already happened. In that case use cautious wording such as being prepared for shipment, being processed, or will ship soon. If requires_shipping is false, do not mention shipping, carrier, dispatch, or tracking. If shipping_complete is false or ready_to_fulfill is false, avoid overpromising shipment progress and keep the wording conservative. Mention tracking only when tracking_detected is true.',
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
