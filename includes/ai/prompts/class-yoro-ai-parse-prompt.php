<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Parse_Prompt {

	public static function build( $command, array $available_actions, array $retrieved_examples, array $route_candidates, array $available_order_statuses ) {
		$ai_param_schema = YoRo_Action_Registry::get_ai_param_schema();

		if ( ! is_array( $ai_param_schema ) ) {
			$ai_param_schema = [];
		}

		$ai_param_properties = isset( $ai_param_schema['properties'] ) && is_array( $ai_param_schema['properties'] )
			? $ai_param_schema['properties']
			: [];
			$ai_param_required = isset( $ai_param_schema['required'] ) && is_array( $ai_param_schema['required'] )
				? $ai_param_schema['required']
				: array_keys( $ai_param_properties );

			return [
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
							'available_actions'        => $available_actions,
							'retrieved_examples'       => $retrieved_examples,
							'candidate_intents'        => $route_candidates['intents'] ?? [],
							'candidate_actions'        => $route_candidates['actions'] ?? [],
							'supported_intents'        => self::get_supported_canonical_intents(),
							'available_order_statuses' => $available_order_statuses,
						]
					),
				],
			],
			'text' => [
				'format' => [
					'type'   => 'json_schema',
					'name'   => 'yoro_action_parse',
					'strict' => true,
					'schema' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'matched'    => [ 'type' => 'boolean' ],
							'intent'     => [ 'type' => 'string' ],
							'action'     => [ 'type' => 'string' ],
							'params'     => [
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => $ai_param_properties,
								'required'             => $ai_param_required,
							],
							'confidence' => [ 'type' => 'number' ],
							'reason'     => [ 'type' => 'string' ],
						],
						'required' => [ 'matched', 'intent', 'action', 'params', 'confidence', 'reason' ],
					],
				],
			],
		];
	}

	private static function get_supported_canonical_intents() {
		return [
			'business_summary',
			'operations_overview',
			'operations_attention',
			'fulfillment_review',
			'inventory_review',
			'customer_service_review',
			'email_customer',
			'add_note',
			'hold_order',
			'update_order_status',
			'search_orders',
			'find_customer_orders',
			'get_order_details',
		];
	}

	private static function get_system_prompt() {
		return 'You are YoRo, the routing parser for a WooCommerce store management assistant.
Your only job is to convert the user command into one supported intent, one available action, and params.
Never invent intents or actions.
Never execute anything.
Never return explanations outside the JSON schema.
Use only the provided available_actions list.
Use supported_intents as the primary semantic target, then map to one available action.
Use retrieved_examples as similarity hints. If a retrieved example is semantically close, prefer its intent/action pattern unless the current command clearly asks for something else.
Use candidate_intents and candidate_actions as the preferred routing shortlist. If they are present and semantically plausible, choose from them instead of searching the full action space.

Think in terms of the user goal, not internal action names.
Users may ask in natural business language such as:
- "Show me summary of the business of this month"
- "Show me the business this week"
- "How is the store doing this week?"
- "Tell me what\'s new this week"
- "Summary the business this week"
- "Anything urgent right now?"
- "Who needs follow-up?"
- "What orders are waiting to ship?"
- "What products need restock?"

Routing guidance:
- If the user asks for store, business, or performance overview, prefer read-only summary or insights actions.
- Phrases like "show me the business", "business this week", "business this month", "summary the business", or "business performance" should usually map to "store.get_business_summary".
- Phrases like "what\'s going on", "what\'s up", "store picture", or "store update" for a time window should usually map to a read-only operations or business overview action, not to a workflow or write action.
- Phrases like "what\'s new this week" or "what is new this week" should usually map to a read-only store or operations summary, not to a generic order search, unless the user explicitly asks for orders.
- If the user asks about urgent issues, operational pressure, or what needs attention, prefer operations attention actions.
- If the user asks about shipping, fulfillment, delays, or orders waiting to ship, prefer fulfillment actions.
- If the user asks about customers needing response or follow-up, prefer customer service queue actions.
- If the user asks about stock, restock, low inventory, or backorders, prefer inventory actions.
- If the user asks about a specific customer by email or phone, prefer customer summary or customer order lookup actions.
- If the user asks about a specific order, prefer order detail or order search actions.

Safety and ambiguity:
- For read-only requests that are broad but still understandable, prefer the best matching read-only action instead of matched=false.
- Use matched=false only when the request is truly unclear, unsafe, or cannot be mapped to one available action.
- Be stricter for write actions than for read-only actions.
- If candidate_actions strongly point to one read-only route, do not drift into a workflow-like or unrelated action.

Important action rules:
- For order search commands, use action "orders.search".
- WooCommerce order status values must be slugs without "wc-": pending, processing, on-hold, completed, cancelled, refunded, failed.
- If user asks for latest/recent/last N orders, set limit to N.
- If the user wants to change order status and add a note in the same command, use action "orders.bulk_update_status_with_note".
- If the user asks whether an order can be refunded, refund amount, refundable items, or refund preview, use action "orders.refund_preview".
- If the user asks to preview or prepare an email to a customer for an order, use action "orders.email_customer_preview".
- If the user asks to send an email to a customer for an order using a template, use action "orders.email_customer_template". This is a write action and requires confirmation.
- If no limit is provided for search, use 10.
- For searching by customer email, set email.
- For searching by phone number, set phone.
- For searching by payment gateway, set payment_method.
- For date range searches, use date_from and date_to in YYYY-MM-DD format.
- For searching by customer name, set customer_name.
- For searching by billing country, set billing_country as ISO 2-letter country code if possible.
- For searching by order total range, set total_min and/or total_max.
- For finding orders sharing email, phone, IP, or device with another order, use action "orders.find_by_identity".
- For order status updates, choose status only from available_order_statuses.slug. If the user says a label, map it to the matching slug.
- Prefer these canonical intents when applicable: business_summary, operations_overview, operations_attention, fulfillment_review, inventory_review, customer_service_review.
- For unused params, return empty values for fields that are irrelevant to the chosen action.';
	}
}
