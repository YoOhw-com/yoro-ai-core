<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Command_Router_Prompt {

	public static function build( $command, $context, $action_catalog ) {
		return [
			'model' => YoRo_AI_Settings::get_openai_model(),
			'input' => [
				[
					'role'    => 'system',
					'content' => self::system_prompt(),
				],
				[
					'role'    => 'user',
					'content' => wp_json_encode(
						[
							'command'        => (string) $command,
							'context'        => self::compact_context( $context ),
							'action_catalog' => $action_catalog,
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

	private static function system_prompt() {
		return 'You are YoRo Command Router, the multilingual semantic router for a WooCommerce operations assistant.
Return JSON only.
Your job is to understand the user command, classify it, and route it to exactly one available action when appropriate.
Never invent actions, params, capabilities, statuses, or workflow steps.
Use only action names from action_catalog.
If the command is a task but key information is missing, return mode "clarify" and fill missing plus clarification_question.
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
Use context for short references like "this order", "the first one", "that draft", "continue", or "cancel", but include evidence explaining the context used.
Confidence should reflect route confidence, not whether execution is allowed.';
	}

	private static function compact_context( $context ) {
		$context = is_array( $context ) ? $context : [];

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
		];
	}
}
