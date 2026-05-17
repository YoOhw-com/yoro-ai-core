<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Command_Router_Prompt {

	public static function build( $command, $context, $action_catalog, $router_hints = [] ) {
		$prompt_catalog = self::compact_action_catalog_for_prompt( $action_catalog, $router_hints );
		$system_prompt  = self::system_prompt( $router_hints );

		return [
			'model' => YoRo_AI_Settings::get_openai_model(),
			'input' => [
				[
					'role'    => 'system',
					'content' => $system_prompt,
				],
				[
					'role'    => 'user',
					'content' => wp_json_encode(
						[
							'command'        => (string) $command,
							'context'        => self::compact_context( $context, $router_hints ),
							'router_hints'   => self::compact_router_hints( $router_hints ),
							'action_catalog' => $prompt_catalog,
						]
					),
				],
			],
			'text' => [
				'format' => [
					'type'   => 'json_schema',
					'name'   => 'yoro_command_decision',
					'strict' => true,
					'schema' => YoRo_Command_Decision_Normalizer::schema( $action_catalog ),
				],
			],
		];
	}

	private static function compact_action_catalog_for_prompt( $action_catalog, $router_hints = [] ) {
		$action_catalog = is_array( $action_catalog ) ? array_values( $action_catalog ) : [];
		$router_profile = sanitize_key( $router_hints['router_profile'] ?? '' );
		$is_email_profile = in_array(
			$router_profile,
			[ 'order_email_preview', 'order_email_save_draft', 'order_email_send' ],
			true
		);
		$is_order_read_profile = 'order_read' === $router_profile;
		$is_orders_search_profile = 'orders_search' === $router_profile;
		$is_customer_profile = in_array(
			$router_profile,
			[ 'customer_summary', 'customer_orders' ],
			true
		);
		$is_email_template_profile = 'email_template_list' === $router_profile;
		$is_store_summary_profile = in_array(
			$router_profile,
			[ 'business_summary', 'operations_overview' ],
			true
		);
		$is_queue_profile = in_array(
			$router_profile,
			[ 'operations_attention', 'fulfillment_review', 'customer_service_review', 'inventory_review' ],
			true
		);
		$is_email_send_profile = 'order_email_send' === $router_profile;

		$compact = [];

		foreach ( $action_catalog as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$examples = isset( $item['examples'] ) && is_array( $item['examples'] )
				? array_values( array_filter( array_map( 'sanitize_text_field', $item['examples'] ) ) )
				: [];

			if ( $is_email_profile || $is_order_read_profile || $is_orders_search_profile || $is_customer_profile || $is_email_template_profile || $is_store_summary_profile || $is_queue_profile ) {
				$examples = array_values( array_slice( $examples, 0, 1 ) );
			}

			if ( $is_email_send_profile ) {
				$compact[] = [
					'name'            => sanitize_text_field( $item['name'] ?? '' ),
					'description'     => sanitize_text_field( $item['description'] ?? '' ),
					'intent_family'   => sanitize_key( $item['intent_family'] ?? '' ),
					'input_schema'    => isset( $item['input_schema'] ) && is_array( $item['input_schema'] ) ? $item['input_schema'] : [],
					'required_params' => isset( $item['required_params'] ) && is_array( $item['required_params'] ) ? array_values( array_map( 'sanitize_key', $item['required_params'] ) ) : [],
					'examples'        => $examples,
				];
				continue;
			}

			if ( $is_order_read_profile ) {
				$compact[] = [
					'name'            => sanitize_text_field( $item['name'] ?? '' ),
					'description'     => sanitize_text_field( $item['description'] ?? '' ),
					'intent_family'   => sanitize_key( $item['intent_family'] ?? '' ),
					'input_schema'    => isset( $item['input_schema'] ) && is_array( $item['input_schema'] ) ? $item['input_schema'] : [],
					'required_params' => isset( $item['required_params'] ) && is_array( $item['required_params'] ) ? array_values( array_map( 'sanitize_key', $item['required_params'] ) ) : [],
					'examples'        => $examples,
				];
				continue;
			}

			if ( $is_orders_search_profile ) {
				$compact[] = [
					'name'            => sanitize_text_field( $item['name'] ?? '' ),
					'description'     => sanitize_text_field( $item['description'] ?? '' ),
					'intent_family'   => sanitize_key( $item['intent_family'] ?? '' ),
					'input_schema'    => isset( $item['input_schema'] ) && is_array( $item['input_schema'] ) ? $item['input_schema'] : [],
					'required_params' => isset( $item['required_params'] ) && is_array( $item['required_params'] ) ? array_values( array_map( 'sanitize_key', $item['required_params'] ) ) : [],
					'examples'        => $examples,
				];
				continue;
			}

			if ( $is_customer_profile || $is_email_template_profile ) {
				$compact[] = [
					'name'            => sanitize_text_field( $item['name'] ?? '' ),
					'description'     => sanitize_text_field( $item['description'] ?? '' ),
					'intent_family'   => sanitize_key( $item['intent_family'] ?? '' ),
					'input_schema'    => isset( $item['input_schema'] ) && is_array( $item['input_schema'] ) ? $item['input_schema'] : [],
					'required_params' => isset( $item['required_params'] ) && is_array( $item['required_params'] ) ? array_values( array_map( 'sanitize_key', $item['required_params'] ) ) : [],
					'examples'        => $examples,
				];
				continue;
			}

			if ( $is_store_summary_profile || $is_queue_profile ) {
				$compact[] = [
					'name'            => sanitize_text_field( $item['name'] ?? '' ),
					'description'     => sanitize_text_field( $item['description'] ?? '' ),
					'intent_family'   => sanitize_key( $item['intent_family'] ?? '' ),
					'family_policy'   => sanitize_text_field( $item['family_policy'] ?? '' ),
					'input_schema'    => isset( $item['input_schema'] ) && is_array( $item['input_schema'] ) ? $item['input_schema'] : [],
					'required_params' => isset( $item['required_params'] ) && is_array( $item['required_params'] ) ? array_values( array_map( 'sanitize_key', $item['required_params'] ) ) : [],
					'examples'        => $examples,
				];
				continue;
			}

			$compact[] = [
				'name'                => sanitize_text_field( $item['name'] ?? '' ),
				'label'               => sanitize_text_field( $item['label'] ?? '' ),
				'description'         => sanitize_text_field( $item['description'] ?? '' ),
				'intent_family'       => sanitize_key( $item['intent_family'] ?? '' ),
				'family_policy'       => sanitize_text_field( $item['family_policy'] ?? '' ),
				'input_schema'        => isset( $item['input_schema'] ) && is_array( $item['input_schema'] ) ? $item['input_schema'] : [],
				'required_params'     => isset( $item['required_params'] ) && is_array( $item['required_params'] ) ? array_values( array_map( 'sanitize_key', $item['required_params'] ) ) : [],
				'param_hints'         => self::compact_string_map( $item['param_hints'] ?? [], $is_email_profile ? 1 : 3 ),
				'clarification_hints' => self::compact_string_map( $item['clarification_hints'] ?? [], $is_email_profile ? 1 : 3 ),
				'examples'            => $examples,
			];
		}

		return $compact;
	}

	private static function compact_string_map( $map, $max_items = 3 ) {
		$map = is_array( $map ) ? $map : [];
		$max_items = max( 0, absint( $max_items ) );
		$compact = [];
		$count = 0;

		foreach ( $map as $key => $value ) {
			if ( $count >= $max_items ) {
				break;
			}

			$key = sanitize_key( $key );

			if ( '' === $key ) {
				continue;
			}

			$compact[ $key ] = sanitize_text_field( $value );
			$count++;
		}

		return $compact;
	}

	private static function system_prompt( $router_hints = [] ) {
		$router_hints = is_array( $router_hints ) ? $router_hints : [];
		$router_profile = sanitize_key( $router_hints['router_profile'] ?? '' );

		if ( in_array( $router_profile, [ 'order_email_preview', 'order_email_save_draft', 'order_email_send' ], true ) ) {
			return self::email_order_system_prompt( $router_profile );
		}

		if ( 'order_read' === $router_profile ) {
			return self::order_read_system_prompt();
		}

		if ( 'orders_search' === $router_profile ) {
			return self::orders_search_system_prompt();
		}

		if ( in_array( $router_profile, [ 'customer_summary', 'customer_orders' ], true ) ) {
			return self::customer_lookup_system_prompt( $router_profile );
		}

		if ( 'email_template_list' === $router_profile ) {
			return self::email_template_list_system_prompt();
		}

		if ( in_array( $router_profile, [ 'business_summary', 'operations_overview' ], true ) ) {
			return self::store_summary_system_prompt( $router_profile );
		}

		if ( in_array( $router_profile, [ 'operations_attention', 'fulfillment_review', 'customer_service_review', 'inventory_review' ], true ) ) {
			return self::queue_system_prompt( $router_profile );
		}

		return 'You are YoRo Command Router, the multilingual semantic router for a WooCommerce operations assistant.
Return JSON only.
Your job is to understand the user command, classify it, and route it to exactly one available action when appropriate.
Never invent actions, params, capabilities, statuses, or workflow steps.
Use only action names from action_catalog.
Choose the action first from action_catalog, then use that chosen action item as the only source of truth for params.
Use the chosen action item\'s description, intent_family, family_policy, examples, input_schema, required_params, param_hints, and clarification_hints.
Return intent_family as a stable language-agnostic semantic family whenever one clearly applies.
Keep intent_family aligned with the chosen action family instead of inventing a new semantic label for each wording variant.
Treat examples in action_catalog as reference cues, not as an exhaustive phrase dictionary. The command may be in any language as long as it maps to the same internal family and action contract.
Only use params keys that belong to the chosen action\'s input_schema.
For schema compatibility, set unrelated or unused params to null instead of guessing values.
If the chosen action has no meaningful params for this command, keep params present but leave its action-specific fields null.
If the command is a task but key information is missing, return mode "clarify" and fill missing plus clarification_question.
If a required param for the chosen action is missing or unresolved, return mode "clarify", keep action set to that action, list only the truly missing required params in missing, and ask one short clarification question guided by clarification_hints.
If the command is small talk or a capability question, return mode "chat_only".
If the command asks for something outside WooCommerce store operations, return mode "out_of_scope".
If the command asks for a store capability YoRo does not support, return mode "unsupported_request".
Write response copy in natural first-person/second-person language: use "I" for YoRo and "you" for the person.
Never write classifier-style summaries such as "Chat mode activated", "User asked", "The user wants", or "no WooCommerce task".
For greetings, answer briefly and invite a store-operations request.
For identity questions, answer naturally. If you do not know the person’s identity from store context, say so without using the word "user".
Detect the user language and write clarification_question, summary, and suggestions in that language when practical.
Normalize internal params to YoRo/WooCommerce values, for example WooCommerce status slugs such as pending, processing, completed, on-hold, cancelled, refunded, failed.
For write actions, still route to the correct action when concrete enough; backend confirmation will protect execution.
Do not treat confirmation-gated write actions as already executed.
Prefer asking one precise clarification over guessing when target order, status, note body, email body, draft, template, or customer identity is missing.
For customer email requests, treat a natural-language goal as a valid instruction whenever it is already present in the command, even if the person did not dictate a full email body.
When a specific order and an email goal are both present, prefer task_request with params.instruction filled from that goal instead of asking for clarification.
Map any supported language to the same internal action and intent_family contracts.
Use family_policy plus the chosen action metadata to keep semantic distinctions stable across languages.
Do not rely on English wording alone when action_catalog and context already make the route clear.
Treat briefing language as store briefing by default unless the command explicitly asks for a different named surface such as business summary, operations summary, or insights.
Do not ask for optional filter params such as limit, window, focus, or similar internal filters unless the command explicitly depends on them.
Use these routing invariants consistently:
- briefing-style requests map to the store briefing family unless the person explicitly names a different summary or insights surface.
- business-health requests map to the business summary family.
- operations-picture requests map to the operations overview family.
- insight/pattern/trend requests map to the insights surface.
- queue requests map to the queue family that best matches the operational need.
If the person asks how the business is doing, how the business is going, what the business looks like over a recent period, or asks for the current state of the business in any language, treat that as a business summary request unless they explicitly ask for a different surface.
If the person asks for the current state of the store as a whole in any language, prefer operations overview only when the wording is about live operational picture rather than commercial/business health.
If the wording mixes a time window with briefing-style language but does not explicitly request summary, overview, or insights, prefer store briefing.
For read-only briefing, queue, summary, or insights actions, keep params minimal and avoid adding optional filters unless the command explicitly names them.
Use these queue distinctions consistently:
- urgency or manual-review load maps to operations attention.
- shipping blockers or waiting-to-ship load maps to fulfillment review.
- reply load or follow-up load maps to customer service review.
For operations summary and business summary, use focus "overview" only when the person wants a general overview and did not narrow to a specific dimension.
For inventory queue, only use focus when the person explicitly names a stock concern such as low stock, restock pressure, or out-of-stock issues.
Never ask the person for internal selector params such as queue_key, topic_key, reference_index, or reference_selector.
For guided queue-based preview workflows, if the needed queue context is already present then route directly to the workflow action without clarification.
If the needed queue context is not present, ask the person to open the relevant queue or summary first instead of asking for an internal selector key.
Use context for short references like "this order", "the first one", "that draft", "continue", or "cancel", but include evidence explaining the context used.
Prefer context.memory_context.reference over loose context fields when resolving what "this", "that", "it", "same order", or a short follow-up refers to.
If context.memory_context.reference.resolved is true and confidence is 0.7 or higher, use its target_type, target_ids, source, and reason as the primary reference evidence.
If the reference source is active_workflow, preserve the workflow target exactly and only infer the missing parameter from the new message.
Do not use current_order_id or last_order_ids to override a stronger memory_context.reference.
If memory_context.reference is unresolved and the command does not clearly identify a target, ask one clarification instead of guessing.
Use router_hints as trusted backend narrowing hints. When router_hints.catalog_scope is "single_order_reference", keep the route inside single-order order-management surfaces unless the command clearly asks for a broader store-level surface.
When router_hints.catalog_scope is "single_order_targeted", strongly prefer exact-order actions such as details, note, hold, status, refund, or direct customer-email work before considering broader search-like surfaces.
When router_hints.router_profile is present, treat it as the preferred sub-surface for this command.
When router_hints.candidate_actions is present and semantically plausible, choose from that shortlist before considering the rest of action_catalog.
When router_hints.candidate_intent_families is present, keep the chosen intent_family inside that shortlist unless the command clearly overrides it.
For single-order targeted write profiles, keep the route narrow:
- router_profile "order_note" should strongly prefer orders.add_note when the command already includes note content or clearly asks to save an internal note.
- router_profile "order_status" should strongly prefer orders.update_status or orders.bulk_update_status_with_note when the command clearly names a status.
- router_profile "order_refund" should strongly prefer orders.refund_preview for direct refund-preview intent and workflow.refund_review for explicit refund-review workflow wording.
- router_profile "order_email_preview" should strongly prefer direct compose or preview-style customer-email actions and preserve a natural-language email goal as instruction when one is present.
- router_profile "order_email_save_draft" should strongly prefer workflow.write_and_save_email_draft_for_order or workflow.write_email_for_order with delivery_mode save_draft when the person gives a natural-language email goal, not direct send surfaces.
- router_profile "order_email_send" should strongly prefer workflow.write_and_send_email_for_order or workflow.write_email_for_order with delivery_mode send when the person gives a natural-language email goal, not draft surfaces.
When router_profile already makes the surface clear, do not widen back out to unrelated single-order actions unless the command clearly contradicts that profile.
When router_hints.resolved_order_ids contains exactly one order and the command does not ask for a broader list, strongly prefer a single-order action over broad search or store-wide summaries.
Confidence should reflect route confidence, not whether execution is allowed.';
	}

	private static function email_order_system_prompt( $router_profile ) {
		$router_profile = sanitize_key( $router_profile );

		$profile_rule = '- Prefer the action that best matches the explicit email outcome.';

		if ( 'order_email_preview' === $router_profile ) {
			$profile_rule = '- Prefer orders.compose_customer_email for a plain write/compose request with a natural-language email goal.';
		} elseif ( 'order_email_save_draft' === $router_profile ) {
			$profile_rule = '- Prefer workflow.write_and_save_email_draft_for_order when the person explicitly asks to save the authored email as a draft.';
		} elseif ( 'order_email_send' === $router_profile ) {
			$profile_rule = '- Prefer workflow.write_and_send_email_for_order when the person explicitly asks to send the authored email. Do not choose preview, draft, or template surfaces unless the command explicitly asks for them.';
		}

		return 'You are YoRo Command Router for single-order customer-email requests.
Return JSON only.
Choose exactly one action from action_catalog.
This request already targets a single order and already belongs to the customer-email surface.
Use the chosen action item as the only source of truth for params.
Keep intent_family aligned with the chosen action family.
Treat examples as reference cues, not as an exhaustive phrase dictionary.
The command may be in any language; map it to the same internal action contract.
Only use params keys that belong to the chosen action input_schema.
If a required param for the chosen action is missing, return mode "clarify", keep action set to that action, and ask one short clarification question.
For customer email requests, treat a natural-language email goal as a valid instruction whenever it is already present in the command.
When a specific order and an email goal are both present, prefer task_request instead of clarification.
Use router_hints as trusted narrowing hints.
' . $profile_rule . '
Do not widen to unrelated order-management, queue, briefing, or store-summary actions.
Confidence should reflect route confidence, not whether execution is allowed.';
	}

	private static function order_read_system_prompt() {
		return 'You are YoRo Command Router for single-order read-only requests.
Return JSON only.
Choose exactly one action from action_catalog.
This request already targets a single order and already belongs to the read-only order surface.
Use the chosen action item as the only source of truth for params.
Keep intent_family aligned with the chosen action family.
The command may be in any language; map it to the same internal action contract.
Only use params keys that belong to the chosen action input_schema.
Prefer orders.get_details for show/open/view/read-detail commands that target one explicit order.
Only choose adjacent read-only identity/detail surfaces if the wording clearly asks for related matches or identity-based inspection instead of order details.
Do not widen to write, refund, email, queue, or store-summary surfaces unless the command clearly contradicts this profile.
Confidence should reflect route confidence, not whether execution is allowed.';
	}

	private static function orders_search_system_prompt() {
		return 'You are YoRo Command Router for read-only order-list and order-search requests.
Return JSON only.
Choose exactly one action from action_catalog.
This request already belongs to the order-search surface.
Use the chosen action item as the only source of truth for params.
Keep intent_family aligned with the chosen action family.
The command may be in any language; map it to the same internal action contract.
Only use params keys that belong to the chosen action input_schema.
Prefer orders.search for latest, recent, pending, processing, completed, or list-orders phrasing.
Keep params minimal and only set optional filters such as limit or status when the command explicitly asks for them.
Do not widen to single-order details, customer summary, queue, email, or write surfaces unless the command clearly contradicts this profile.
Confidence should reflect route confidence, not whether execution is allowed.';
	}

	private static function customer_lookup_system_prompt( $router_profile ) {
		$router_profile = sanitize_key( $router_profile );
		$profile_rule = '- Prefer customers.get_summary for customer profile, customer summary, customer overview, or customer record requests.';

		if ( 'customer_orders' === $router_profile ) {
			$profile_rule = '- Prefer orders.find_by_customer for requests to show orders or order history for a customer.';
		}

		return 'You are YoRo Command Router for customer lookup requests.
Return JSON only.
Choose exactly one action from action_catalog.
This request already belongs to a customer lookup surface.
Use the chosen action item as the only source of truth for params.
Keep intent_family aligned with the chosen action family.
The command may be in any language; map it to the same internal action contract.
Only use params keys that belong to the chosen action input_schema.
' . $profile_rule . '
Use email, phone, or customer_id only when the command or context clearly provides them.
If the command says "this customer" or "that customer" without a usable identifier, return clarify instead of guessing.
Do not widen to general order search, queue, email, or store-summary surfaces unless the command clearly contradicts this profile.
Confidence should reflect route confidence, not whether execution is allowed.';
	}

	private static function email_template_list_system_prompt() {
		return 'You are YoRo Command Router for email-template listing requests.
Return JSON only.
Choose exactly one action from action_catalog.
This request already belongs to the reusable email-template list surface.
Use the chosen action item as the only source of truth for params.
Keep intent_family aligned with the chosen action family.
The command may be in any language; map it to the same internal action contract.
Prefer email_templates.list for requests to show, list, browse, or view saved email templates.
Do not widen to template save/delete, order-email compose, queue, or store-summary surfaces unless the command clearly contradicts this profile.
Confidence should reflect route confidence, not whether execution is allowed.';
	}

	private static function store_summary_system_prompt( $router_profile ) {
		$router_profile = sanitize_key( $router_profile );
		$profile_rule = '- Prefer the business summary surface when the wording is about business health, performance, sales, or revenue.';

		if ( 'operations_overview' === $router_profile ) {
			$profile_rule = '- Prefer the operations overview surface when the wording is about the store picture, briefing, operational update, or what is new right now.';
		}

		return 'You are YoRo Command Router for store-level read-only summary requests.
Return JSON only.
Choose exactly one action from action_catalog.
This request already belongs to a store-summary surface.
Use the chosen action item as the only source of truth for params.
Keep intent_family aligned with the chosen action family.
The command may be in any language; map it to the same internal summary contract.
Only use params keys that belong to the chosen action input_schema.
Do not ask for optional filters unless the command explicitly depends on them.
Prefer read-only summary, overview, briefing, or insights surfaces only.
' . $profile_rule . '
Do not widen to order-management, queue, or email surfaces unless the command clearly contradicts this profile.
Confidence should reflect route confidence, not whether execution is allowed.';
	}

	private static function queue_system_prompt( $router_profile ) {
		$router_profile = sanitize_key( $router_profile );
		$profile_rule = '- Prefer the operations attention queue when the wording is about urgency, attention, or priorities.';

		if ( 'fulfillment_review' === $router_profile ) {
			$profile_rule = '- Prefer the fulfillment queue when the wording is about shipping blockers, waiting-to-ship work, or fulfillment backlog.';
		} elseif ( 'customer_service_review' === $router_profile ) {
			$profile_rule = '- Prefer the customer service queue when the wording is about follow-up, replies, or customer response load.';
		} elseif ( 'inventory_review' === $router_profile ) {
			$profile_rule = '- Prefer the inventory queue when the wording is about low stock, restock, inventory pressure, or backorders.';
		}

		return 'You are YoRo Command Router for store-level queue requests.
Return JSON only.
Choose exactly one action from action_catalog.
This request already belongs to a queue-style operational surface.
Use the chosen action item as the only source of truth for params.
Keep intent_family aligned with the chosen action family.
The command may be in any language; map it to the same internal queue contract.
Only use params keys that belong to the chosen action input_schema.
Do not ask for optional filters unless the command explicitly depends on them.
' . $profile_rule . '
Do not widen to summary, order-management, or email surfaces unless the command clearly contradicts this profile.
Confidence should reflect route confidence, not whether execution is allowed.';
	}

	private static function compact_context( $context, $router_hints = [] ) {
		$context = is_array( $context ) ? $context : [];
		$router_hints = is_array( $router_hints ) ? $router_hints : [];
		$router_profile = sanitize_key( $router_hints['router_profile'] ?? '' );
		$memory_context = isset( $context['memory_context'] ) && is_array( $context['memory_context'] )
			? $context['memory_context']
			: [];

		if ( 'order_email_send' === $router_profile ) {
			return [
				'current_order_id' => absint( $context['current_order_id'] ?? 0 ),
				'has_current_workflow' => ! empty( $context['current_workflow'] ),
				'current_workflow' => isset( $context['current_workflow'] ) && is_array( $context['current_workflow'] )
					? [
						'type'   => sanitize_key( $context['current_workflow']['type'] ?? '' ),
						'action' => sanitize_text_field( $context['current_workflow']['action'] ?? '' ),
						'step'   => sanitize_key( $context['current_workflow']['step'] ?? '' ),
					]
					: [],
				'memory_context' => [
					'reference' => self::compact_memory_reference( $memory_context['reference'] ?? [] ),
				],
			];
		}

		if ( 'order_read' === $router_profile ) {
			return [
				'has_current_workflow' => ! empty( $context['current_workflow'] ),
				'memory_context' => [
					'reference' => self::compact_memory_reference( $memory_context['reference'] ?? [] ),
				],
			];
		}

		if ( 'orders_search' === $router_profile ) {
			return [
				'last_result_type' => sanitize_key( $context['last_result_type'] ?? '' ),
				'memory_context' => [
					'reference' => self::compact_memory_reference( $memory_context['reference'] ?? [] ),
				],
			];
		}

		if ( in_array( $router_profile, [ 'customer_summary', 'customer_orders', 'email_template_list' ], true ) ) {
			return [
				'last_result_type' => sanitize_key( $context['last_result_type'] ?? '' ),
				'memory_context' => [
					'reference' => self::compact_memory_reference( $memory_context['reference'] ?? [] ),
				],
			];
		}

		if ( in_array( $router_profile, [ 'business_summary', 'operations_overview' ], true ) ) {
			return [
				'last_result_type' => sanitize_key( $context['last_result_type'] ?? '' ),
				'has_current_workflow' => ! empty( $context['current_workflow'] ),
				'memory_context' => [
					'reference' => self::compact_memory_reference( $memory_context['reference'] ?? [] ),
				],
			];
		}

		if ( in_array( $router_profile, [ 'operations_attention', 'fulfillment_review', 'customer_service_review', 'inventory_review' ], true ) ) {
			return [
				'last_result_type' => sanitize_key( $context['last_result_type'] ?? '' ),
				'memory_context' => [
					'reference' => self::compact_memory_reference( $memory_context['reference'] ?? [] ),
				],
			];
		}

		return [
			'current_order_id' => absint( $context['current_order_id'] ?? 0 ),
			'last_order_ids' => isset( $context['last_order_ids'] ) && is_array( $context['last_order_ids'] )
				? array_values( array_slice( array_filter( array_map( 'absint', $context['last_order_ids'] ) ), 0, 10 ) )
				: [],
			'last_result_type' => sanitize_key( $context['last_result_type'] ?? '' ),
			'active_followup_type' => sanitize_key( $context['active_followup_type'] ?? '' ),
			'has_current_workflow' => ! empty( $context['current_workflow'] ),
			'current_workflow' => isset( $context['current_workflow'] ) && is_array( $context['current_workflow'] )
				? [
					'type'   => sanitize_key( $context['current_workflow']['type'] ?? '' ),
					'action' => sanitize_text_field( $context['current_workflow']['action'] ?? '' ),
					'step'   => sanitize_key( $context['current_workflow']['step'] ?? '' ),
				]
				: [],
			'memory_context' => self::compact_memory_context( $memory_context ),
		];
	}

	private static function compact_router_hints( $router_hints ) {
		$router_hints = is_array( $router_hints ) ? $router_hints : [];
		$router_profile = sanitize_key( $router_hints['router_profile'] ?? '' );

		if ( 'order_email_send' === $router_profile ) {
			return [
				'catalog_scope' => sanitize_key( $router_hints['catalog_scope'] ?? 'default' ),
				'router_profile' => $router_profile,
				'resolved_order_ids' => isset( $router_hints['resolved_order_ids'] ) && is_array( $router_hints['resolved_order_ids'] )
					? array_values( array_filter( array_map( 'absint', $router_hints['resolved_order_ids'] ) ) )
					: [],
				'candidate_actions' => isset( $router_hints['candidate_actions'] ) && is_array( $router_hints['candidate_actions'] )
					? array_values( array_filter( array_map( 'sanitize_text_field', $router_hints['candidate_actions'] ) ) )
					: [],
			];
		}

		if ( 'order_read' === $router_profile ) {
			return [
				'catalog_scope' => sanitize_key( $router_hints['catalog_scope'] ?? 'default' ),
				'router_profile' => $router_profile,
				'resolved_order_ids' => isset( $router_hints['resolved_order_ids'] ) && is_array( $router_hints['resolved_order_ids'] )
					? array_values( array_filter( array_map( 'absint', $router_hints['resolved_order_ids'] ) ) )
					: [],
				'candidate_actions' => isset( $router_hints['candidate_actions'] ) && is_array( $router_hints['candidate_actions'] )
					? array_values( array_filter( array_map( 'sanitize_text_field', $router_hints['candidate_actions'] ) ) )
					: [],
			];
		}

		if ( 'orders_search' === $router_profile ) {
			return [
				'catalog_scope' => sanitize_key( $router_hints['catalog_scope'] ?? 'default' ),
				'router_profile' => $router_profile,
				'candidate_actions' => isset( $router_hints['candidate_actions'] ) && is_array( $router_hints['candidate_actions'] )
					? array_values( array_filter( array_map( 'sanitize_text_field', $router_hints['candidate_actions'] ) ) )
					: [],
			];
		}

		if ( in_array( $router_profile, [ 'customer_summary', 'customer_orders', 'email_template_list' ], true ) ) {
			return [
				'catalog_scope' => sanitize_key( $router_hints['catalog_scope'] ?? 'default' ),
				'router_profile' => $router_profile,
				'candidate_actions' => isset( $router_hints['candidate_actions'] ) && is_array( $router_hints['candidate_actions'] )
					? array_values( array_filter( array_map( 'sanitize_text_field', $router_hints['candidate_actions'] ) ) )
					: [],
			];
		}

		if ( in_array( $router_profile, [ 'business_summary', 'operations_overview' ], true ) ) {
			return [
				'catalog_scope' => sanitize_key( $router_hints['catalog_scope'] ?? 'default' ),
				'router_profile' => $router_profile,
				'candidate_actions' => isset( $router_hints['candidate_actions'] ) && is_array( $router_hints['candidate_actions'] )
					? array_values( array_filter( array_map( 'sanitize_text_field', $router_hints['candidate_actions'] ) ) )
					: [],
			];
		}

		if ( in_array( $router_profile, [ 'operations_attention', 'fulfillment_review', 'customer_service_review', 'inventory_review' ], true ) ) {
			return [
				'catalog_scope' => sanitize_key( $router_hints['catalog_scope'] ?? 'default' ),
				'router_profile' => $router_profile,
				'candidate_actions' => isset( $router_hints['candidate_actions'] ) && is_array( $router_hints['candidate_actions'] )
					? array_values( array_filter( array_map( 'sanitize_text_field', $router_hints['candidate_actions'] ) ) )
					: [],
			];
		}

		return [
			'catalog_scope' => sanitize_key( $router_hints['catalog_scope'] ?? 'default' ),
			'router_profile' => $router_profile,
			'catalog_total_action_count' => absint( $router_hints['catalog_total_action_count'] ?? 0 ),
			'resolved_order_ids' => isset( $router_hints['resolved_order_ids'] ) && is_array( $router_hints['resolved_order_ids'] )
				? array_values( array_filter( array_map( 'absint', $router_hints['resolved_order_ids'] ) ) )
				: [],
			'candidate_actions' => isset( $router_hints['candidate_actions'] ) && is_array( $router_hints['candidate_actions'] )
				? array_values( array_filter( array_map( 'sanitize_text_field', $router_hints['candidate_actions'] ) ) )
				: [],
			'candidate_intent_families' => isset( $router_hints['candidate_intent_families'] ) && is_array( $router_hints['candidate_intent_families'] )
				? array_values( array_filter( array_map( 'sanitize_key', $router_hints['candidate_intent_families'] ) ) )
				: [],
		];
	}

	private static function compact_memory_context( $memory_context ) {
		$memory_context = is_array( $memory_context ) ? $memory_context : [];

		return [
			'active_workflow' => self::compact_memory_workflow( $memory_context['active_workflow'] ?? [] ),
			'active_entity'   => self::compact_memory_entity( $memory_context['active_entity'] ?? [] ),
			'last_result'     => self::compact_memory_last_result( $memory_context['last_result'] ?? [] ),
			'reference'       => self::compact_memory_reference( $memory_context['reference'] ?? [] ),
		];
	}

	private static function compact_memory_workflow( $workflow ) {
		$workflow = is_array( $workflow ) ? $workflow : [];

		return [
			'type'     => sanitize_key( $workflow['type'] ?? '' ),
			'action'   => sanitize_text_field( $workflow['action'] ?? '' ),
			'step'     => sanitize_key( $workflow['step'] ?? '' ),
			'order_id' => absint( $workflow['order_id'] ?? 0 ),
			'missing'  => isset( $workflow['missing'] ) && is_array( $workflow['missing'] )
				? array_values( array_filter( array_map( 'sanitize_key', $workflow['missing'] ) ) )
				: [],
		];
	}

	private static function compact_memory_entity( $entity ) {
		$entity = is_array( $entity ) ? $entity : [];

		return [
			'type' => sanitize_key( $entity['type'] ?? '' ),
			'id'   => absint( $entity['id'] ?? 0 ),
		];
	}

	private static function compact_memory_last_result( $last_result ) {
		$last_result = is_array( $last_result ) ? $last_result : [];

		return [
			'type'   => sanitize_key( $last_result['type'] ?? '' ),
			'action' => sanitize_text_field( $last_result['action'] ?? '' ),
		];
	}

	private static function compact_memory_reference( $reference ) {
		$reference = is_array( $reference ) ? $reference : [];

		return [
			'resolved'               => ! empty( $reference['resolved'] ),
			'target_type'            => sanitize_key( $reference['target_type'] ?? '' ),
			'target_ids'             => isset( $reference['target_ids'] ) && is_array( $reference['target_ids'] )
				? array_values( array_filter( array_map( 'absint', $reference['target_ids'] ) ) )
				: [],
			'source'                 => sanitize_key( $reference['source'] ?? 'none' ),
			'confidence'             => isset( $reference['confidence'] ) ? (float) $reference['confidence'] : 0.0,
			'reason'                 => sanitize_text_field( $reference['reason'] ?? '' ),
			'requires_clarification' => ! empty( $reference['requires_clarification'] ),
		];
	}
}
