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
Prefer context.memory_context.reference over loose context fields when resolving what "this", "that", "it", "same order", or a short follow-up refers to.
If context.memory_context.reference.resolved is true and confidence is 0.7 or higher, use its target_type, target_ids, source, and reason as the primary reference evidence.
If the reference source is active_workflow, preserve the workflow target exactly and only infer the missing parameter from the new message.
Do not use current_order_id or last_order_ids to override a stronger memory_context.reference.
If memory_context.reference is unresolved and the command does not clearly identify a target, ask one clarification instead of guessing.
Confidence should reflect route confidence, not whether execution is allowed.';
	}

	private static function compact_context( $context ) {
		$context = is_array( $context ) ? $context : [];
		$memory_context = isset( $context['memory_context'] ) && is_array( $context['memory_context'] )
			? $context['memory_context']
			: [];

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
