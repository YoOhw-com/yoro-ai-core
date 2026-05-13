<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Intent_Prompt {

	public static function build( $command, array $intent_context, array $retrieved_examples, array $route_candidates, array $available_order_statuses ) {
		return [
			'model' => YoRo_AI_Settings::get_openai_model(),
			'input' => [
				[
					'role'    => 'system',
					'content' => self::get_system_prompt(),
				],
				[
					'role'    => 'user',
					'content' => wp_json_encode(
						[
							'command'                  => $command,
							'context'                  => $intent_context,
							'available_order_statuses' => $available_order_statuses,
							'candidate_intents'        => $route_candidates['intents'] ?? [],
							'candidate_actions'        => $route_candidates['actions'] ?? [],
							'supported_intents'        => [
								'email_customer',
								'add_note',
								'hold_order',
								'update_order_status',
								'search_orders',
								'find_customer_orders',
								'get_order_details',
							],
							'retrieved_examples'       => $retrieved_examples,
						]
					),
				],
			],
			'text' => [
				'format' => [
					'type'   => 'json_schema',
					'name'   => 'yoro_intent_parse',
					'strict' => true,
					'schema' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'matched'    => [ 'type' => 'boolean' ],
							'intent'     => [ 'type' => 'string' ],
							'params'     => [
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => [
									'order_id'    => [ 'type' => 'integer' ],
									'order_ids'   => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
									'status'      => [ 'type' => 'string' ],
									'note'        => [ 'type' => 'string' ],
									'instruction' => [ 'type' => 'string' ],
									'limit'       => [ 'type' => 'integer' ],
									'email'       => [ 'type' => 'string' ],
									'phone'       => [ 'type' => 'string' ],
								],
								'required' => [ 'order_id', 'order_ids', 'status', 'note', 'instruction', 'limit', 'email', 'phone' ],
							],
							'confidence' => [ 'type' => 'number' ],
							'reason'     => [ 'type' => 'string' ],
						],
						'required' => [ 'matched', 'intent', 'params', 'confidence', 'reason' ],
					],
				],
			],
		];
	}

	private static function get_system_prompt() {
		return 'You are YoRo Intent Parser for a WooCommerce store management assistant.
Your job is to convert the user command into one supported intent and params.
Do not execute anything.
Do not invent intents.
Use context only to resolve references like this customer, that order, first one, those orders.
Return only valid JSON matching the schema.

The supported intents below are mostly order-centric legacy intents.
Only match one when the user is clearly asking for an order-specific action.
If the user is asking for store overview, business performance, urgent issues, shipping queue, customer follow-up queue, or inventory attention, do not force these legacy intents. Return matched=false so higher-level routing can handle it.

Supported intents:
- email_customer: compose an email draft for a customer/order.
- add_note: add an internal order note.
- hold_order: put order(s) on hold.
- update_order_status: change order status.
- search_orders: search/list orders.
- find_customer_orders: find orders from the same customer.
- get_order_details: show order details.
When retrieved_examples are present, use them to interpret paraphrases and natural business language more flexibly.
When candidate_intents or candidate_actions are present, treat them as the preferred shortlist and avoid drifting into unrelated legacy intents.
Do not force a legacy order-centric intent when the retrieved examples suggest a higher-level store overview intent.';
	}
}
