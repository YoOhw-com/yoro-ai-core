<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Workflow_Actions {

	public static function register_actions() {
		YoRo_Action_Registry::register(
			'workflow.fulfillment_followup_preview',
			[
				'label'       => 'Prepare fulfillment follow-up preview',
				'description' => 'Select the next fulfillment item and build a guided follow-up preview without changing store data.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'prepare_fulfillment_followup_preview' ],
				'risk_level'  => 'medium',
				'write_action'=> false,
				'input_schema'=> [
					'queue_key'         => 'string',
					'original_command'  => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_fulfillment_followup_preview' ],
				'max_items'        => 1,
				'ai_description'   => 'Prepare the next fulfillment follow-up preview from the active fulfillment queue and current store context.',
				'ai_examples'      => [
					'Prepare the next fulfillment follow-up',
					'Preview the next shipping follow-up',
				],
				'required_params'     => [],
				'param_hints'         => [
					'original_command' => 'Keep the original user wording for preview context when available.',
				],
				'clarification_hints' => [
					'queue_key' => 'If there is no active fulfillment queue context, ask the person to open the fulfillment queue first instead of asking for an internal queue key.',
				],
				'capabilities' => [
					'workflow',
					'fulfillment',
					'preview',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.customer_followup_preview',
			[
				'label'       => 'Prepare customer follow-up preview',
				'description' => 'Select the next customer follow-up item and build a guided reply preview without changing store data.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'prepare_customer_followup_preview' ],
				'risk_level'  => 'medium',
				'write_action'=> false,
				'input_schema'=> [
					'queue_key'        => 'string',
					'original_command' => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_customer_followup_preview' ],
				'max_items'        => 1,
				'ai_description'   => 'Prepare the next customer follow-up preview from the active customer-service queue and current store context.',
				'ai_examples'      => [
					'Prepare the next customer follow-up',
					'Preview the next customer reply workflow',
				],
				'required_params'     => [],
				'param_hints'         => [
					'original_command' => 'Keep the original user wording for preview context when available.',
				],
				'clarification_hints' => [
					'queue_key' => 'If there is no active customer-service queue context, ask the person to open that queue first instead of asking for an internal queue key.',
				],
				'capabilities' => [
					'workflow',
					'customers',
					'preview',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.operations_attention_preview',
			[
				'label'       => 'Prepare operations attention preview',
				'description' => 'Select the next operations attention item and build a guided review preview without changing store data.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'prepare_operations_attention_preview' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'queue_key'        => 'string',
					'original_command' => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_operations_attention_preview' ],
				'max_items'        => 1,
				'ai_description'   => 'Prepare the next guided operations-attention preview from the active operations attention queue.',
				'ai_examples'      => [
					'Prepare the next operations attention follow-up',
					'Preview the next operations item to review',
				],
				'required_params'     => [],
				'param_hints'         => [
					'original_command' => 'Keep the original user wording for preview context when available.',
				],
				'clarification_hints' => [
					'queue_key' => 'If there is no active operations attention queue context, ask the person to open that queue first instead of asking for an internal queue key.',
				],
				'capabilities' => [
					'workflow',
					'operations',
					'preview',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.inventory_pressure_preview',
			[
				'label'       => 'Prepare inventory pressure preview',
				'description' => 'Select the next inventory pressure item and build a guided review preview without changing store data.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'prepare_inventory_pressure_preview' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'queue_key'        => 'string',
					'original_command' => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_inventory_pressure_preview' ],
				'max_items'        => 1,
				'ai_description'   => 'Prepare the next guided inventory-pressure preview from the active inventory queue.',
				'ai_examples'      => [
					'Prepare the next inventory follow-up',
					'Preview the next inventory item to review',
				],
				'required_params'     => [],
				'param_hints'         => [
					'original_command' => 'Keep the original user wording for preview context when available.',
				],
				'clarification_hints' => [
					'queue_key' => 'If there is no active inventory queue context, ask the person to open that queue first instead of asking for an internal queue key.',
				],
				'capabilities' => [
					'workflow',
					'inventory',
					'preview',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.business_summary_preview',
			[
				'label'       => 'Prepare business summary preview',
				'description' => 'Select the next business signal and build a guided review preview without changing store data.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'prepare_business_summary_preview' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'original_command' => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_business_summary_preview' ],
				'max_items'        => 1,
				'ai_description'   => 'Prepare the next guided business-summary preview from the current business summary context.',
				'ai_examples'      => [
					'Prepare the next business summary follow-up',
					'Preview the next business signal to review',
				],
				'required_params'     => [],
				'param_hints'         => [
					'original_command' => 'Keep the original user wording for preview context when available.',
				],
				'clarification_hints' => [
					'queue_key' => 'If there is no active business summary context, ask the person to open the business summary first instead of asking for an internal queue key.',
				],
				'capabilities' => [
					'workflow',
					'business',
					'preview',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.write_email_for_order',
			[
				'label'       => 'Write email for order workflow',
				'description' => 'Load an order, compose a customer email, then continue through the guided email workflow.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'order_id'         => 'integer',
					'instruction'      => 'string',
					'delivery_mode'    => 'string',
					'original_command' => 'string',
				],
				'max_items'      => 1,
				'ai_description' => 'Start the guided workflow that loads an order, composes a customer email, and then previews, saves a draft, or sends it depending on delivery_mode.',
				'ai_examples'    => [
					'Write a payment follow-up email for order 6864',
					'Draft a customer email for this order',
				],
				'required_params' => [ 'order_id', 'instruction' ],
				'param_hints'     => [
					'instruction'   => 'Summarize the email goal in a short natural-language phrase such as payment follow-up or shipping delay apology.',
					'delivery_mode' => 'Use preview by default unless the person clearly asks to save as draft or send.',
				],
				'capabilities' => [
					'workflow',
					'orders',
					'email',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.write_and_save_email_draft_for_order',
			[
				'label'       => 'Write and save email draft workflow',
				'description' => 'Load an order, compose a customer email, and save the result as a draft on the order.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'risk_level'  => 'medium',
				'write_action'=> false,
				'input_schema'=> [
					'order_id'         => 'integer',
					'instruction'      => 'string',
					'delivery_mode'    => 'string',
					'original_command' => 'string',
				],
				'max_items'      => 1,
				'ai_description' => 'Start the guided workflow that loads an order, composes a customer email, and saves it as a draft.',
				'ai_examples'    => [
					'Write a payment follow-up email for order 6864 and save as draft',
					'Draft an apology email for this order and save it',
				],
				'required_params' => [ 'order_id', 'instruction' ],
				'param_hints'     => [
					'instruction'   => 'Summarize the email goal in a short natural-language phrase.',
					'delivery_mode' => 'Set to save_draft when the person explicitly asks to save the authored email as a draft.',
				],
				'capabilities' => [
					'workflow',
					'orders',
					'email',
					'draft',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.write_and_send_email_for_order',
			[
				'label'       => 'Write and send email workflow',
				'description' => 'Load an order, compose a customer email, and send it to the customer.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'risk_level'  => 'medium',
				'write_action'=> false,
				'input_schema'=> [
					'order_id'         => 'integer',
					'instruction'      => 'string',
					'delivery_mode'    => 'string',
					'original_command' => 'string',
				],
				'max_items'      => 1,
				'ai_description' => 'Start the guided workflow that loads an order, composes a customer email, and sends it after confirmation.',
				'ai_examples'    => [
					'Write a payment follow-up email for order 6864 and send it',
					'Send a short apology email for this order',
				],
				'required_params' => [ 'order_id', 'instruction' ],
				'param_hints'     => [
					'instruction'   => 'Summarize the email goal in a short natural-language phrase.',
					'delivery_mode' => 'Set to send when the person explicitly asks to send the authored email.',
				],
				'capabilities' => [
					'workflow',
					'orders',
					'email',
					'send',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.verify_customer_before_fulfillment',
			[
				'label'       => 'Verify customer before fulfillment workflow',
				'description' => 'Guide the order-verification workflow before fulfillment or shipping.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'risk_level'  => 'medium',
				'write_action'=> false,
				'input_schema'=> [
					'order_id'         => 'integer',
					'email_delivery_mode' => 'string',
					'original_command' => 'string',
				],
				'max_items'      => 1,
				'ai_description' => 'Start the guided workflow that verifies a customer or order details before fulfillment.',
				'ai_examples'    => [
					'Verify this customer before fulfillment',
					'Check the customer details before shipping order 6864',
				],
				'required_params' => [ 'order_id' ],
				'capabilities' => [
					'workflow',
					'orders',
					'verification',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.refund_review',
			[
				'label'       => 'Refund review workflow',
				'description' => 'Guide the refund review workflow for a specific order.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'risk_level'  => 'medium',
				'write_action'=> false,
				'input_schema'=> [
					'order_id'         => 'integer',
					'instruction'      => 'string',
					'delivery_mode'    => 'string',
					'original_command' => 'string',
				],
				'max_items'      => 1,
				'ai_description' => 'Start the guided refund review workflow for a specific order.',
				'ai_examples'    => [
					'Review the refund for order 6864',
					'Prepare a refund review for this order',
				],
				'required_params' => [ 'order_id' ],
				'capabilities' => [
					'workflow',
					'orders',
					'refund',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.review_suspicious_order',
			[
				'label'       => 'Review suspicious order workflow',
				'description' => 'Guide the suspicious-order review workflow for a specific order.',
				'category'    => 'workflow',
				'permission'  => 'manage_woocommerce',
				'risk_level'  => 'medium',
				'write_action'=> false,
				'input_schema'=> [
					'order_id'         => 'integer',
					'original_command' => 'string',
				],
				'max_items'      => 1,
				'ai_description' => 'Start the guided suspicious-order review workflow for a specific order.',
				'ai_examples'    => [
					'Review suspicious order 6864',
					'Investigate this risky order',
				],
				'required_params' => [ 'order_id' ],
				'capabilities' => [
					'workflow',
					'orders',
					'risk',
				],
			]
		);

		YoRo_Action_Registry::register(
			'workflow.cancel',
			[
				'label'                 => 'Cancel workflow',
				'description'           => 'Cancel current workflow',
				'callback'              => [ __CLASS__, 'cancel' ],
				'permission'            => 'manage_woocommerce',
				'risk_level'            => 'low',
				'requires_confirmation' => false,
				'write_action'          => false,
				'input_schema' => [],
				'max_items'    => 1,
				'ai_description'   => 'Cancel the current active workflow or guided preview flow.',
				'ai_examples'      => [
					'Cancel this workflow',
					'Stop this flow',
					'Hủy workflow này',
				],
				'required_params'     => [],
				'param_hints'         => [],
				'clarification_hints' => [],
				'capabilities' => [
					'workflow',
					'state',
				],
			]
		);
	}

	public static function prepare_fulfillment_followup_preview( $params ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error(
				'yoro_missing_user',
				__( 'User context is required to prepare this workflow.', 'yoro-agent-core' )
			);
		}

		$conversation = YoRo_Conversation_Memory::get_conversation_state( $user_id );
		$followup     = isset( $conversation['last_fulfillment_followup'] ) && is_array( $conversation['last_fulfillment_followup'] )
			? $conversation['last_fulfillment_followup']
			: [];

		if ( empty( $followup['queues'] ) || ! is_array( $followup['queues'] ) ) {
			return new WP_Error(
				'yoro_missing_fulfillment_context',
				__( 'Open the fulfillment queue first so I can select the right follow-up item.', 'yoro-agent-core' )
			);
		}

		$current_workflow = YoRo_Conversation_Memory::get_workflow( $user_id );
		if (
			! empty( $params['_yoro_confirmed'] ) &&
			YoRo_Workflow_Runner::is_confirmable_preview_workflow(
				$current_workflow,
				'fulfillment_followup',
				'workflow.fulfillment_followup_preview'
			)
		) {
			return self::execute_fulfillment_followup_after_confirmation( $current_workflow );
		}

		if ( ! empty( $params['_yoro_confirmed'] ) ) {
			return new WP_Error(
				'yoro_workflow_stale',
				__( 'This fulfillment follow-up preview is stale. Please generate a new preview before confirming.', 'yoro-agent-core' )
			);
		}

		$selected = self::select_fulfillment_followup_candidate( $followup, $params );

		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		$selected_item     = $selected['selected_item'];
		$queue_source      = $selected['queue_source'];
		$selection_reason  = $selected['selection_reason'];
		$email_preview     = $selected['email_preview'];
		$email_subject     = sanitize_text_field( $email_preview['email']['subject'] ?? '' );
		$email_body        = sanitize_textarea_field( $email_preview['email']['body'] ?? '' );
		$confirmation_scope = sprintf(
			/* translators: %s: order number */
			__( 'Create or update a shipping-delay draft for order #%s.', 'yoro-agent-core' ),
			$selected_item['order_number']
		);

		$next_after_confirm = [
			'action' => 'orders.save_customer_email_draft',
			'label'  => __( 'Save reviewed customer email draft', 'yoro-agent-core' ),
			'params' => [
				'order_id' => $selected_item['order_id'],
				'subject'  => $email_subject,
				'body'     => $email_body,
			],
		];

		$workflow = YoRo_Workflow_Runner::build_followup_workflow(
			'fulfillment_followup',
			[
				'order_id'         => $selected_item['order_id'],
				'selected_item'    => $selected_item,
				'queue_source'     => $queue_source,
				'selection_reason' => $selection_reason,
				'email_preview'    => $email_preview,
				'subject'          => $email_subject,
				'body'             => $email_body,
				'original_command' => $params['original_command'] ?? '',
				'proposed_action'  => [
					'action' => 'orders.save_customer_email_draft',
					'label'  => __( 'Save reviewed customer email draft', 'yoro-agent-core' ),
				],
				'draft_summary'    => [
					'template'     => 'shipping_delay',
					'subject'      => $email_subject,
					'body_preview' => wp_trim_words( $email_body, 40 ),
				],
				'next_after_confirm' => $next_after_confirm,
			]
		);

		YoRo_Conversation_Memory::set_workflow( $user_id, $workflow );

		$result = [
			'success'      => true,
			'action'       => 'workflow.fulfillment_followup_preview',
			'summary'      => sprintf(
				/* translators: %s: order number */
				__( 'Fulfillment follow-up preview prepared for order #%s.', 'yoro-agent-core' ),
				$selected_item['order_number']
			),
			'message'      => sprintf(
				/* translators: %s: order number */
				__( 'Fulfillment follow-up preview prepared for order #%s.', 'yoro-agent-core' ),
				$selected_item['order_number']
			),
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'workflow'     => $workflow,
			'workflow_id'  => 'fulfillment_followup_v1',
			'workflow_version' => 'v1',
			'preview_ready' => true,
			'changes_store' => false,
			'requires_confirmation' => true,
			'confirmation_required_for_execute' => true,
			'selected_item'   => $selected_item,
			'selection_reason'=> $selection_reason,
			'queue_source'    => $queue_source,
			'proposed_action' => [
				'action' => 'orders.save_customer_email_draft',
				'label'  => __( 'Save reviewed customer email draft', 'yoro-agent-core' ),
				'template' => 'shipping_delay',
			],
			'draft_summary' => [
				'template'     => 'shipping_delay',
				'subject'      => $email_subject,
				'body_preview' => wp_trim_words( $email_body, 40 ),
			],
			'confirmation_scope' => $confirmation_scope,
			'next_after_confirm' => $next_after_confirm,
			'risk_level'         => 'medium',
			'risk_reasons'       => [
				__( 'This workflow will prepare customer-facing follow-up content for a live order.', 'yoro-agent-core' ),
				__( 'A later execution step should require confirmation before any draft is saved.', 'yoro-agent-core' ),
			],
			'email_preview' => [
				'template'  => sanitize_key( $email_preview['email']['template'] ?? 'shipping_delay' ),
				'to'        => ! empty( $email_preview['email']['to'] )
					? YoRo_Order_Presenter::mask_email( $email_preview['email']['to'] )
					: '',
				'subject'   => $email_subject,
				'body'      => $email_body,
				'html_body' => wp_kses_post( $email_preview['email']['html_body'] ?? '' ),
			],
			'order'        => isset( $email_preview['order'] ) && is_array( $email_preview['order'] )
				? $email_preview['order']
				: [],
			'result_type'  => 'fulfillment_followup_workflow_preview',
			'display_type' => 'fulfillment_followup_workflow_preview',
		];

		return self::annotate_admin_copilot_workflow_result(
			$result,
			'fulfillment_followup_workflow',
			[
				'workflow.fulfillment_followup_preview',
				$next_after_confirm['action'],
			]
		);
	}

	public static function preview_fulfillment_followup_preview( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Prepare fulfillment follow-up workflow preview',
			'queue_key'     => ! empty( $params['queue_key'] ) ? sanitize_key( $params['queue_key'] ) : '',
			'changes_store' => false,
		];
	}

	public static function prepare_customer_followup_preview( $params ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error(
				'yoro_missing_user',
				__( 'User context is required to prepare this workflow.', 'yoro-agent-core' )
			);
		}

		$conversation = YoRo_Conversation_Memory::get_conversation_state( $user_id );
		$followup     = isset( $conversation['last_service_followup'] ) && is_array( $conversation['last_service_followup'] )
			? $conversation['last_service_followup']
			: [];

		$current_workflow = YoRo_Conversation_Memory::get_workflow( $user_id );
		if (
			! empty( $params['_yoro_confirmed'] ) &&
			YoRo_Workflow_Runner::is_confirmable_preview_workflow(
				$current_workflow,
				'customer_followup',
				'workflow.customer_followup_preview'
			)
		) {
			return self::execute_customer_followup_after_confirmation( $current_workflow );
		}

		if ( ! empty( $params['_yoro_confirmed'] ) ) {
			return new WP_Error(
				'yoro_workflow_stale',
				__( 'This customer follow-up preview is stale. Please generate a new preview before confirming.', 'yoro-agent-core' )
			);
		}

		if ( empty( $followup['queues'] ) || ! is_array( $followup['queues'] ) ) {
			return new WP_Error(
				'yoro_missing_customer_service_context',
				__( 'Open the customer service queue first so I can select the right follow-up item.', 'yoro-agent-core' )
			);
		}

		$selected = self::select_customer_followup_candidate( $followup, $params );

		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		$selected_item      = $selected['selected_item'];
		$queue_source       = $selected['queue_source'];
		$selection_reason   = $selected['selection_reason'];
		$email_preview      = $selected['email_preview'];
		$email_subject      = sanitize_text_field( $email_preview['email']['subject'] ?? '' );
		$email_body         = sanitize_textarea_field( $email_preview['email']['body'] ?? '' );
		$existing_draft_id  = sanitize_key( $selected_item['existing_draft_id'] ?? '' );
		$is_existing_draft  = '' !== $existing_draft_id;
		$confirmation_scope = $is_existing_draft
			? sprintf(
				__( 'Update the saved customer draft for order #%s.', 'yoro-agent-core' ),
				$selected_item['order_number']
			)
			: sprintf(
				__( 'Create a customer follow-up draft for order #%s.', 'yoro-agent-core' ),
				$selected_item['order_number']
			);

		$next_after_confirm = [
			'action' => $is_existing_draft ? 'orders.update_customer_email_draft' : 'orders.save_customer_email_draft',
			'label'  => $is_existing_draft
				? __( 'Update reviewed customer email draft', 'yoro-agent-core' )
				: __( 'Save reviewed customer email draft', 'yoro-agent-core' ),
			'params' => [
				'order_id' => $selected_item['order_id'],
				'subject'  => $email_subject,
				'body'     => $email_body,
			],
		];

		if ( $is_existing_draft ) {
			$next_after_confirm['params']['draft_id'] = $existing_draft_id;
		}

		$workflow = YoRo_Workflow_Runner::build_followup_workflow(
			'customer_followup',
			[
				'order_id'         => $selected_item['order_id'],
				'selected_item'    => $selected_item,
				'queue_source'     => $queue_source,
				'selection_reason' => $selection_reason,
				'email_preview'    => $email_preview,
				'subject'          => $email_subject,
				'body'             => $email_body,
				'draft_id'         => $existing_draft_id,
				'original_command' => $params['original_command'] ?? '',
				'proposed_action'  => [
					'action' => $next_after_confirm['action'],
					'label'  => $next_after_confirm['label'],
				],
				'draft_summary'    => [
					'template'     => 'customer_followup',
					'subject'      => $email_subject,
					'body_preview' => wp_trim_words( $email_body, 40 ),
				],
				'next_after_confirm' => $next_after_confirm,
			]
		);

		YoRo_Conversation_Memory::set_workflow( $user_id, $workflow );

		$result = [
			'success'      => true,
			'action'       => 'workflow.customer_followup_preview',
			'summary'      => sprintf(
				__( 'Customer follow-up preview prepared for order #%s.', 'yoro-agent-core' ),
				$selected_item['order_number']
			),
			'message'      => sprintf(
				__( 'Customer follow-up preview prepared for order #%s.', 'yoro-agent-core' ),
				$selected_item['order_number']
			),
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'workflow'     => $workflow,
			'workflow_id'  => 'customer_followup_v1',
			'workflow_version' => 'v1',
			'preview_ready' => true,
			'changes_store' => false,
			'requires_confirmation' => true,
			'confirmation_required_for_execute' => true,
			'selected_item'   => $selected_item,
			'selection_reason'=> $selection_reason,
			'queue_source'    => $queue_source,
			'proposed_action' => [
				'action'   => $next_after_confirm['action'],
				'label'    => $next_after_confirm['label'],
				'template' => 'customer_followup',
			],
			'draft_summary' => [
				'template'     => 'customer_followup',
				'subject'      => $email_subject,
				'body_preview' => wp_trim_words( $email_body, 40 ),
			],
			'confirmation_scope' => $confirmation_scope,
			'next_after_confirm' => $next_after_confirm,
			'risk_level'         => 'medium',
			'risk_reasons'       => [
				__( 'This workflow will prepare customer-facing follow-up content for a live order.', 'yoro-agent-core' ),
				__( 'A later execution step should require confirmation before any draft is created or updated.', 'yoro-agent-core' ),
			],
			'email_preview' => [
				'template'  => sanitize_key( $email_preview['email']['template'] ?? 'ai_custom' ),
				'to'        => ! empty( $email_preview['email']['to'] )
					? YoRo_Order_Presenter::mask_email( $email_preview['email']['to'] )
					: '',
				'subject'   => $email_subject,
				'body'      => $email_body,
				'html_body' => wp_kses_post( $email_preview['email']['html_body'] ?? '' ),
			],
			'order'        => isset( $email_preview['order'] ) && is_array( $email_preview['order'] )
				? $email_preview['order']
				: [],
			'result_type'  => 'customer_followup_workflow_preview',
			'display_type' => 'customer_followup_workflow_preview',
		];

		return self::annotate_admin_copilot_workflow_result(
			$result,
			'customer_followup_workflow',
			[
				'workflow.customer_followup_preview',
				$next_after_confirm['action'],
			]
		);
	}

	public static function preview_customer_followup_preview( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Prepare customer follow-up workflow preview',
			'queue_key'     => ! empty( $params['queue_key'] ) ? sanitize_key( $params['queue_key'] ) : '',
			'changes_store' => false,
		];
	}

	public static function prepare_operations_attention_preview( $params ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error(
				'yoro_missing_user',
				__( 'User context is required to prepare this workflow.', 'yoro-agent-core' )
			);
		}

		$conversation = YoRo_Conversation_Memory::get_conversation_state( $user_id );
		$followup     = isset( $conversation['last_attention_followup'] ) && is_array( $conversation['last_attention_followup'] )
			? $conversation['last_attention_followup']
			: [];

		if ( empty( $followup['queues'] ) || ! is_array( $followup['queues'] ) ) {
			return new WP_Error(
				'yoro_missing_operations_attention_context',
				__( 'Open the operations attention queue first so I can select the right attention item.', 'yoro-agent-core' )
			);
		}

		$selected = self::select_operations_attention_candidate( $followup, $params );

		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		$selected_item     = $selected['selected_item'];
		$queue_source      = $selected['queue_source'];
		$selection_reason  = $selected['selection_reason'];
		$proposed_action   = $selected['proposed_action'];
		$next_without_confirm = $selected['next_without_confirm'];
		$preview_summary   = $selected['preview_summary'];

		$workflow = YoRo_Workflow_Runner::build_preview_workflow(
			'operations_attention',
			[
				'order_id'            => $selected_item['order_id'],
				'selected_item'       => $selected_item,
				'queue_source'        => $queue_source,
				'selection_reason'    => $selection_reason,
				'proposed_action'     => $proposed_action,
				'preview_summary'     => $preview_summary,
				'next_without_confirm'=> $next_without_confirm,
				'original_command'    => $params['original_command'] ?? '',
			]
		);

		YoRo_Conversation_Memory::set_workflow( $user_id, $workflow );

		$result = [
			'success'        => true,
			'action'         => 'workflow.operations_attention_preview',
			'summary'        => sprintf(
				__( 'Operations attention preview prepared for order #%s.', 'yoro-agent-core' ),
				$selected_item['order_number']
			),
			'message'        => sprintf(
				__( 'Operations attention preview prepared for order #%s.', 'yoro-agent-core' ),
				$selected_item['order_number']
			),
			'read_only'      => true,
			'undoable'       => false,
			'undo_plan'      => [],
			'data'           => [],
			'meta'           => [],
			'processed'      => [],
			'skipped'        => [],
			'errors'         => [],
			'count'          => 1,
			'workflow'       => $workflow,
			'workflow_id'    => 'operations_attention_v1',
			'workflow_version' => 'v1',
			'preview_ready'  => true,
			'changes_store'  => false,
			'requires_confirmation' => false,
			'selected_item'  => $selected_item,
			'selection_reason' => $selection_reason,
			'queue_source'   => $queue_source,
			'proposed_action'=> $proposed_action,
			'preview_summary'=> $preview_summary,
			'next_without_confirm' => $next_without_confirm,
			'risk_level'     => 'low',
			'risk_reasons'   => [],
			'result_type'    => 'operations_attention_workflow_preview',
			'display_type'   => 'operations_attention_workflow_preview',
		];

		return self::annotate_admin_copilot_workflow_result(
			$result,
			'operations_attention_workflow',
			[
				'workflow.operations_attention_preview',
				sanitize_text_field( $next_without_confirm['action'] ?? '' ),
			]
		);
	}

	public static function preview_operations_attention_preview( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Prepare operations attention workflow preview',
			'queue_key'     => ! empty( $params['queue_key'] ) ? sanitize_key( $params['queue_key'] ) : '',
			'changes_store' => false,
		];
	}

	public static function prepare_inventory_pressure_preview( $params ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error(
				'yoro_missing_user',
				__( 'User context is required to prepare this workflow.', 'yoro-agent-core' )
			);
		}

		$conversation = YoRo_Conversation_Memory::get_conversation_state( $user_id );
		$followup     = isset( $conversation['last_inventory_followup'] ) && is_array( $conversation['last_inventory_followup'] )
			? $conversation['last_inventory_followup']
			: [];

		if ( empty( $followup['queues'] ) || ! is_array( $followup['queues'] ) ) {
			return new WP_Error(
				'yoro_missing_inventory_context',
				__( 'Open the inventory queue first so I can select the right stock issue.', 'yoro-agent-core' )
			);
		}

		$selected = self::select_inventory_pressure_candidate( $followup, $params );

		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		$selected_item        = $selected['selected_item'];
		$queue_source         = $selected['queue_source'];
		$selection_reason     = $selected['selection_reason'];
		$proposed_action      = $selected['proposed_action'];
		$next_without_confirm = $selected['next_without_confirm'];
		$preview_summary      = $selected['preview_summary'];

		$workflow = YoRo_Workflow_Runner::build_preview_workflow(
			'inventory_pressure',
			[
				'product_id'          => $selected_item['product_id'],
				'selected_item'       => $selected_item,
				'queue_source'        => $queue_source,
				'selection_reason'    => $selection_reason,
				'proposed_action'     => $proposed_action,
				'preview_summary'     => $preview_summary,
				'next_without_confirm'=> $next_without_confirm,
				'original_command'    => $params['original_command'] ?? '',
			]
		);

		YoRo_Conversation_Memory::set_workflow( $user_id, $workflow );

		return [
			'success'          => true,
			'action'           => 'workflow.inventory_pressure_preview',
			'summary'          => sprintf(
				__( 'Inventory pressure preview prepared for product #%s.', 'yoro-agent-core' ),
				$selected_item['product_id']
			),
			'message'          => sprintf(
				__( 'Inventory pressure preview prepared for product #%s.', 'yoro-agent-core' ),
				$selected_item['product_id']
			),
			'read_only'        => true,
			'undoable'         => false,
			'undo_plan'        => [],
			'data'             => [],
			'meta'             => [],
			'processed'        => [],
			'skipped'          => [],
			'errors'           => [],
			'count'            => 1,
			'workflow'         => $workflow,
			'workflow_id'      => 'inventory_pressure_v1',
			'workflow_version' => 'v1',
			'preview_ready'    => true,
			'changes_store'    => false,
			'requires_confirmation' => false,
			'selected_item'    => $selected_item,
			'selection_reason' => $selection_reason,
			'queue_source'     => $queue_source,
			'proposed_action'  => $proposed_action,
			'preview_summary'  => $preview_summary,
			'next_without_confirm' => $next_without_confirm,
			'risk_level'       => 'low',
			'risk_reasons'     => [],
			'result_type'      => 'inventory_pressure_workflow_preview',
			'display_type'     => 'inventory_pressure_workflow_preview',
		];
	}

	public static function preview_inventory_pressure_preview( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Prepare inventory pressure workflow preview',
			'queue_key'     => ! empty( $params['queue_key'] ) ? sanitize_key( $params['queue_key'] ) : '',
			'changes_store' => false,
		];
	}

	public static function prepare_business_summary_preview( $params ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error(
				'yoro_missing_user',
				__( 'User context is required to prepare this workflow.', 'yoro-agent-core' )
			);
		}

		$conversation = YoRo_Conversation_Memory::get_conversation_state( $user_id );
		$followup     = isset( $conversation['last_business_followup'] ) && is_array( $conversation['last_business_followup'] )
			? $conversation['last_business_followup']
			: [];

		if ( empty( $followup['recommended_sequence'] ) || ! is_array( $followup['recommended_sequence'] ) ) {
			return new WP_Error(
				'yoro_missing_business_context',
				__( 'Open the business summary first so I can choose the right business signal.', 'yoro-agent-core' )
			);
		}

		$selected = self::select_business_summary_candidate( $followup, $params );

		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		$selected_item        = $selected['selected_item'];
		$selection_reason     = $selected['selection_reason'];
		$proposed_action      = $selected['proposed_action'];
		$next_without_confirm = $selected['next_without_confirm'];
		$preview_summary      = $selected['preview_summary'];

		$workflow = YoRo_Workflow_Runner::build_preview_workflow(
			'business_summary',
			[
				'selected_item'       => $selected_item,
				'selection_reason'    => $selection_reason,
				'proposed_action'     => $proposed_action,
				'preview_summary'     => $preview_summary,
				'next_without_confirm'=> $next_without_confirm,
				'original_command'    => $params['original_command'] ?? '',
			]
		);

		YoRo_Conversation_Memory::set_workflow( $user_id, $workflow );

		return [
			'success'          => true,
			'action'           => 'workflow.business_summary_preview',
			'summary'          => sanitize_text_field(
				sprintf(
					__( 'Business summary preview prepared for signal: %s.', 'yoro-agent-core' ),
					$selected_item['title']
				)
			),
			'message'          => sanitize_text_field(
				sprintf(
					__( 'Business summary preview prepared for signal: %s.', 'yoro-agent-core' ),
					$selected_item['title']
				)
			),
			'read_only'        => true,
			'undoable'         => false,
			'undo_plan'        => [],
			'data'             => [],
			'meta'             => [],
			'processed'        => [],
			'skipped'          => [],
			'errors'           => [],
			'count'            => 1,
			'workflow'         => $workflow,
			'workflow_id'      => 'business_summary_v1',
			'workflow_version' => 'v1',
			'preview_ready'    => true,
			'changes_store'    => false,
			'requires_confirmation' => false,
			'selected_item'    => $selected_item,
			'selection_reason' => $selection_reason,
			'proposed_action'  => $proposed_action,
			'preview_summary'  => $preview_summary,
			'next_without_confirm' => $next_without_confirm,
			'risk_level'       => 'low',
			'risk_reasons'     => [],
			'result_type'      => 'business_summary_workflow_preview',
			'display_type'     => 'business_summary_workflow_preview',
		];
	}

	public static function preview_business_summary_preview( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Prepare business summary workflow preview',
			'changes_store' => false,
		];
	}

	public static function cancel( $params ) {
		$user_id = get_current_user_id();

		$workflow = YoRo_Conversation_Memory::get_workflow( $user_id );

		if ( empty( $workflow ) ) {
			return [
				'success' => false,
				'message' => __( 'No active workflow to cancel.', 'yoro-agent-core' ),
			];
		}

		$workflow = class_exists( 'YoRo_Workflow_Runner' )
			? YoRo_Workflow_Runner::cancel_workflow( $workflow )
			: $workflow;

		YoRo_Conversation_Memory::clear_workflow( $user_id );

		return [
			'success'      => true,
			'action'       => 'workflow.cancel',
			'summary'      => __( 'Workflow cancelled.', 'yoro-agent-core' ),
			'message'      => __( 'Workflow cancelled.', 'yoro-agent-core' ),
			'workflow'     => $workflow,
			'result_type'  => 'assistant_message',
			'display_type' => 'assistant_message',
			'read_only'    => true,
		];
	}

	private static function annotate_admin_copilot_workflow_result( $result, $surface, $actions ) {
		return $result;
	}

	private static function select_fulfillment_followup_candidate( $followup, $params ) {
		$preferred_key = ! empty( $params['queue_key'] ) ? sanitize_key( $params['queue_key'] ) : '';
		$queues        = isset( $followup['queues'] ) && is_array( $followup['queues'] )
			? array_values( $followup['queues'] )
			: [];
		$case_rankings = isset( $followup['case_rankings'] ) && is_array( $followup['case_rankings'] )
			? array_values( $followup['case_rankings'] )
			: [];

		if ( empty( $queues ) ) {
			return new WP_Error(
				'yoro_missing_fulfillment_queue_data',
				__( 'Fulfillment queue data is missing. Open the fulfillment queue again and retry.', 'yoro-agent-core' )
			);
		}

		$queue_map = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' !== $key ) {
				$queue_map[ $key ] = $queue;
			}
		}

		$priority = [
			'processing_without_tracking',
			'processing_older_than_48h',
			'missing_shipping_address',
			'recent_ready_to_fulfill',
		];

		if ( $preferred_key && in_array( $preferred_key, $priority, true ) ) {
			array_unshift( $priority, $preferred_key );
			$priority = array_values( array_unique( $priority ) );
		}

		$last_error = null;
		$selected_queue = null;
		$selected_order = null;

		if ( ! empty( $case_rankings ) ) {
			foreach ( $case_rankings as $ranked_case ) {
				$ranked_topic = sanitize_key( $ranked_case['topic'] ?? '' );
				$ranked_order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];

				if ( '' === $ranked_topic || empty( $ranked_order['id'] ) ) {
					continue;
				}

				if ( $preferred_key && $preferred_key !== $ranked_topic ) {
					continue;
				}

				if ( empty( $queue_map[ $ranked_topic ] ) || ! is_array( $queue_map[ $ranked_topic ] ) ) {
					continue;
				}

				$selected_queue = $queue_map[ $ranked_topic ];
				$selected_order = $ranked_order;
				break;
			}
		}

		if ( ! empty( $selected_queue ) && ! empty( $selected_order['id'] ) ) {
			$order_id = absint( $selected_order['id'] ?? 0 );
			$email_preview = YoRo_Orders_Email_Service::email_customer_preview(
				[
					'order_id' => $order_id,
					'template' => 'shipping_delay',
				]
			);

			if ( ! is_wp_error( $email_preview ) ) {
				return [
					'selected_item'    => [
						'order_id'          => $order_id,
						'order_number'      => sanitize_text_field( $selected_order['number'] ?? (string) $order_id ),
						'status'            => sanitize_key( $selected_order['status'] ?? '' ),
						'age_hours'         => isset( $selected_order['age_hours'] ) ? absint( $selected_order['age_hours'] ) : 0,
						'queue_key'         => sanitize_key( $selected_queue['key'] ?? '' ),
						'customer_email'    => sanitize_text_field( $selected_order['billing_email'] ?? '' ),
						'shipping_name'     => sanitize_text_field( $selected_order['shipping_name'] ?? '' ),
						'tracking_detected' => ! empty( $selected_order['tracking_detected'] ),
						'reason'            => sanitize_text_field( $selected_order['fulfillment_reason'] ?? '' ),
					],
					'queue_source'     => [
						'key'         => sanitize_key( $selected_queue['key'] ?? '' ),
						'label'       => sanitize_text_field( $selected_queue['label'] ?? '' ),
						'description' => sanitize_text_field( $selected_queue['description'] ?? '' ),
					],
					'selection_reason' => self::build_fulfillment_selection_reason( sanitize_key( $selected_queue['key'] ?? '' ), $selected_order ),
					'email_preview'    => $email_preview,
				];
			}

			$last_error = $email_preview;
		}

		foreach ( $priority as $queue_key ) {
			if ( empty( $queue_map[ $queue_key ] ) || empty( $queue_map[ $queue_key ]['orders'] ) || ! is_array( $queue_map[ $queue_key ]['orders'] ) ) {
				continue;
			}

			$queue = $queue_map[ $queue_key ];

			foreach ( $queue['orders'] as $order ) {
				$order_id = absint( $order['id'] ?? 0 );

				if ( ! $order_id ) {
					continue;
				}

				$email_preview = YoRo_Orders_Email_Service::email_customer_preview(
					[
						'order_id' => $order_id,
						'template' => 'shipping_delay',
					]
				);

				if ( is_wp_error( $email_preview ) ) {
					$last_error = $email_preview;
					continue;
				}

				return [
					'selected_item'    => [
						'order_id'          => $order_id,
						'order_number'      => sanitize_text_field( $order['number'] ?? (string) $order_id ),
						'status'            => sanitize_key( $order['status'] ?? '' ),
						'age_hours'         => isset( $order['age_hours'] ) ? absint( $order['age_hours'] ) : 0,
						'queue_key'         => $queue_key,
						'customer_email'    => sanitize_text_field( $order['billing_email'] ?? '' ),
						'shipping_name'     => sanitize_text_field( $order['shipping_name'] ?? '' ),
						'tracking_detected' => ! empty( $order['tracking_detected'] ),
						'reason'            => sanitize_text_field( $order['fulfillment_reason'] ?? '' ),
					],
					'queue_source'     => [
						'key'         => $queue_key,
						'label'       => sanitize_text_field( $queue['label'] ?? '' ),
						'description' => sanitize_text_field( $queue['description'] ?? '' ),
					],
					'selection_reason' => self::build_fulfillment_selection_reason( $queue_key, $order ),
					'email_preview'    => $email_preview,
				];
			}
		}

		if ( is_wp_error( $last_error ) ) {
			return $last_error;
		}

		return new WP_Error(
			'yoro_no_fulfillment_candidate',
			__( 'I could not find a fulfillment item that is ready for a follow-up preview yet.', 'yoro-agent-core' )
		);
	}

	private static function build_fulfillment_selection_reason( $queue_key, $order ) {
		$order_number = sanitize_text_field( $order['number'] ?? $order['id'] ?? '' );

		switch ( $queue_key ) {
			case 'processing_without_tracking':
				return sprintf(
					/* translators: %s: order number */
					__( 'Order #%s was selected because it is processing, has no tracking signal, and is the top fulfillment risk in the current queue.', 'yoro-agent-core' ),
					$order_number
				);
			case 'processing_older_than_48h':
				return sprintf(
					/* translators: %s: order number */
					__( 'Order #%s was selected because it has been processing for more than 48 hours and still appears in the aging fulfillment backlog.', 'yoro-agent-core' ),
					$order_number
				);
			case 'missing_shipping_address':
				return sprintf(
					/* translators: %s: order number */
					__( 'Order #%s was selected because its shipping address still looks incomplete and blocks normal fulfillment follow-up.', 'yoro-agent-core' ),
					$order_number
				);
			case 'recent_ready_to_fulfill':
			default:
				return sprintf(
					/* translators: %s: order number */
					__( 'Order #%s was selected because it is one of the newest fulfillment candidates still waiting for an outbound shipping follow-up.', 'yoro-agent-core' ),
					$order_number
				);
		}
	}

	private static function select_customer_followup_candidate( $followup, $params ) {
		$preferred_key = ! empty( $params['queue_key'] ) ? sanitize_key( $params['queue_key'] ) : '';
		$queues        = isset( $followup['queues'] ) && is_array( $followup['queues'] )
			? array_values( $followup['queues'] )
			: [];
		$case_rankings = isset( $followup['case_rankings'] ) && is_array( $followup['case_rankings'] )
			? array_values( $followup['case_rankings'] )
			: [];

		if ( empty( $queues ) ) {
			return new WP_Error(
				'yoro_missing_customer_service_queue_data',
				__( 'Customer service queue data is missing. Open the customer service queue again and retry.', 'yoro-agent-core' )
			);
		}

		$queue_map = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' !== $key ) {
				$queue_map[ $key ] = $queue;
			}
		}

		$priority = [
			'saved_email_drafts',
			'on_hold_followup',
			'pending_payment_followup',
			'failed_checkout_followup',
		];

		if ( $preferred_key && in_array( $preferred_key, $priority, true ) ) {
			array_unshift( $priority, $preferred_key );
			$priority = array_values( array_unique( $priority ) );
		}

		$selected_queue = null;
		$selected_order = null;

		if ( ! empty( $case_rankings ) ) {
			foreach ( $case_rankings as $ranked_case ) {
				$ranked_topic = sanitize_key( $ranked_case['topic'] ?? '' );
				$ranked_order = isset( $ranked_case['order'] ) && is_array( $ranked_case['order'] ) ? $ranked_case['order'] : [];

				if ( '' === $ranked_topic || empty( $ranked_order['id'] ) ) {
					continue;
				}

				if ( $preferred_key && $preferred_key !== $ranked_topic ) {
					continue;
				}

				if ( empty( $queue_map[ $ranked_topic ] ) || ! is_array( $queue_map[ $ranked_topic ] ) ) {
					continue;
				}

				$selected_queue = $queue_map[ $ranked_topic ];
				$selected_order = $ranked_order;
				break;
			}
		}

		if ( empty( $selected_queue ) || empty( $selected_order['id'] ) ) {
			foreach ( $priority as $queue_key ) {
				if ( empty( $queue_map[ $queue_key ]['orders'] ) || ! is_array( $queue_map[ $queue_key ]['orders'] ) ) {
					continue;
				}

				foreach ( $queue_map[ $queue_key ]['orders'] as $order ) {
					if ( empty( $order['id'] ) ) {
						continue;
					}

					$selected_queue = $queue_map[ $queue_key ];
					$selected_order = $order;
					break 2;
				}
			}
		}

		if ( empty( $selected_queue ) || empty( $selected_order['id'] ) ) {
			return new WP_Error(
				'yoro_no_customer_followup_candidate',
				__( 'I could not find a customer follow-up item that is ready for a guided preview yet.', 'yoro-agent-core' )
			);
		}

		$order_id        = absint( $selected_order['id'] ?? 0 );
		$order_number    = sanitize_text_field( $selected_order['number'] ?? (string) $order_id );
		$queue_key       = sanitize_key( $selected_queue['key'] ?? '' );
		$followup_reason = sanitize_text_field( $selected_order['followup_reason'] ?? $selected_queue['description'] ?? '' );
		$order_object    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$existing_draft  = self::get_latest_order_email_draft( $order_object );
		$email_preview   = self::build_customer_followup_email_preview( $selected_order, $queue_key, $existing_draft );

		if ( is_wp_error( $email_preview ) ) {
			return $email_preview;
		}

		$selected_item = [
			'order_id'          => $order_id,
			'order_number'      => $order_number,
			'status'            => sanitize_key( $selected_order['status'] ?? '' ),
			'queue_key'         => $queue_key,
			'customer_email'    => sanitize_text_field( $selected_order['billing_email'] ?? '' ),
			'customer_name'     => sanitize_text_field( $selected_order['customer_name'] ?? '' ),
			'existing_draft_id' => sanitize_key( $existing_draft['id'] ?? '' ),
			'reason'            => $followup_reason,
			'age_hours'         => absint( $selected_order['age_hours'] ?? 0 ),
		];

		return [
			'selected_item'    => $selected_item,
			'queue_source'     => [
				'key'         => $queue_key,
				'label'       => sanitize_text_field( $selected_queue['label'] ?? '' ),
				'description' => sanitize_text_field( $selected_queue['description'] ?? '' ),
			],
			'selection_reason' => self::build_customer_followup_selection_reason( $selected_item, $queue_key ),
			'email_preview'    => $email_preview,
		];
	}

	private static function build_customer_followup_selection_reason( $selected_item, $queue_key ) {
		$order_number = sanitize_text_field( $selected_item['order_number'] ?? '' );

		switch ( $queue_key ) {
			case 'saved_email_drafts':
				return sprintf(
					__( 'Order #%s was selected because a saved customer email draft is already waiting for review, making it the fastest follow-up to complete next.', 'yoro-agent-core' ),
					$order_number
				);
			case 'on_hold_followup':
				return sprintf(
					__( 'Order #%s was selected because it has been on hold for more than 48 hours and is still part of the heaviest customer follow-up backlog.', 'yoro-agent-core' ),
					$order_number
				);
			case 'pending_payment_followup':
				return sprintf(
					__( 'Order #%s was selected because payment is still pending and it remains in the pending-payment outreach queue.', 'yoro-agent-core' ),
					$order_number
				);
			case 'failed_checkout_followup':
			default:
				return sprintf(
					__( 'Order #%s was selected because it is part of the recent failed checkout support queue and may need proactive customer help.', 'yoro-agent-core' ),
					$order_number
				);
		}
	}

	private static function build_customer_followup_email_preview( $selected_order, $queue_key, $existing_draft = [] ) {
		$order_id        = absint( $selected_order['id'] ?? 0 );
		$order_number    = sanitize_text_field( $selected_order['number'] ?? $order_id );
		$customer_name   = sanitize_text_field( $selected_order['customer_name'] ?? '' );
		$followup_reason = sanitize_text_field( $selected_order['followup_reason'] ?? '' );

		if ( ! empty( $existing_draft['subject'] ) && ! empty( $existing_draft['body'] ) ) {
			return YoRo_Orders_Email_Service::render_customer_email_preview(
				[
					'order_id' => $order_id,
					'subject'  => sanitize_text_field( $existing_draft['subject'] ),
					'body'     => sanitize_textarea_field( $existing_draft['body'] ),
					'draft_id' => sanitize_key( $existing_draft['id'] ?? '' ),
				]
			);
		}

		switch ( $queue_key ) {
			case 'pending_payment_followup':
				$subject = sprintf(
					__( 'Payment reminder for order #%s', 'yoro-agent-core' ),
					$order_number
				);
				$body = sprintf(
					__( 'Hi %1$s, we are following up on order #%2$s from YoPlay because payment is still pending. If you still want this order, please complete payment when convenient, or reply if you need help finishing checkout.', 'yoro-agent-core' ),
					$customer_name ? $customer_name : __( 'there', 'yoro-agent-core' ),
					$order_number
				);
				break;
			case 'failed_checkout_followup':
				$subject = sprintf(
					__( 'Need help with order #%s?', 'yoro-agent-core' ),
					$order_number
				);
				$body = sprintf(
					__( 'Hi %1$s, we noticed checkout did not complete for order #%2$s. If you still want the order, reply here and our team can help you finish the purchase.', 'yoro-agent-core' ),
					$customer_name ? $customer_name : __( 'there', 'yoro-agent-core' ),
					$order_number
				);
				break;
			case 'on_hold_followup':
			default:
				$subject = sprintf(
					__( 'Update on your order #%s', 'yoro-agent-core' ),
					$order_number
				);
				$body = sprintf(
					__( 'Hi %1$s, we are following up about order #%2$s from YoPlay. The order is still on hold, and we wanted to check whether you need anything from us to move it forward. If you have questions, just reply and we will help.', 'yoro-agent-core' ),
					$customer_name ? $customer_name : __( 'there', 'yoro-agent-core' ),
					$order_number
				);
				break;
		}

		if ( $followup_reason ) {
			$body .= ' ' . $followup_reason;
		}

		return YoRo_Orders_Email_Service::render_customer_email_preview(
			[
				'order_id' => $order_id,
				'subject'  => $subject,
				'body'     => $body,
			]
		);
	}

	private static function get_latest_order_email_draft( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return [];
		}

		$drafts = $order->get_meta( '_yoro_customer_email_drafts', true );

		if ( ! is_array( $drafts ) || empty( $drafts ) ) {
			return [];
		}

		foreach ( $drafts as $draft ) {
			if ( ! is_array( $draft ) ) {
				continue;
			}

			$id      = ! empty( $draft['id'] ) ? sanitize_key( $draft['id'] ) : '';
			$subject = ! empty( $draft['subject'] ) ? sanitize_text_field( $draft['subject'] ) : '';
			$body    = ! empty( $draft['body'] ) ? sanitize_textarea_field( $draft['body'] ) : '';

			if ( '' === $id || '' === $subject || '' === $body ) {
				continue;
			}

			return [
				'id'         => $id,
				'subject'    => $subject,
				'body'       => $body,
				'created_at' => YoRo_Date_Helper::format_mysql( $draft['created_at'] ?? '' ),
				'updated_at' => YoRo_Date_Helper::format_mysql( $draft['updated_at'] ?? '' ),
			];
		}

		return [];
	}

	private static function select_operations_attention_candidate( $followup, $params ) {
		$preferred_key = ! empty( $params['queue_key'] ) ? sanitize_key( $params['queue_key'] ) : '';
		$queues        = isset( $followup['queues'] ) && is_array( $followup['queues'] )
			? array_values( $followup['queues'] )
			: [];

		if ( empty( $queues ) ) {
			return new WP_Error(
				'yoro_missing_operations_attention_queue_data',
				__( 'Operations attention queue data is missing. Open the attention queue again and retry.', 'yoro-agent-core' )
			);
		}

		$queue_map = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' !== $key ) {
				$queue_map[ $key ] = $queue;
			}
		}

		$priority = [
			'pending_payment_older_than_24h',
			'processing_older_than_72h',
			'on_hold_older_than_48h',
			'recent_failed_orders',
			'high_value_open_orders',
		];

		if ( $preferred_key && in_array( $preferred_key, $priority, true ) ) {
			array_unshift( $priority, $preferred_key );
			$priority = array_values( array_unique( $priority ) );
		}

		$selected_queue = null;
		$selected_order = null;

		foreach ( $priority as $queue_key ) {
			if ( empty( $queue_map[ $queue_key ]['orders'] ) || ! is_array( $queue_map[ $queue_key ]['orders'] ) ) {
				continue;
			}

			foreach ( $queue_map[ $queue_key ]['orders'] as $order ) {
				if ( empty( $order['id'] ) ) {
					continue;
				}

				$selected_queue = $queue_map[ $queue_key ];
				$selected_order = $order;
				break 2;
			}
		}

		if ( empty( $selected_queue ) || empty( $selected_order['id'] ) ) {
			return new WP_Error(
				'yoro_no_operations_attention_candidate',
				__( 'I could not find an operations attention item that is ready for a guided preview yet.', 'yoro-agent-core' )
			);
		}

		$order_id     = absint( $selected_order['id'] ?? 0 );
		$order_number = sanitize_text_field( $selected_order['number'] ?? (string) $order_id );
		$queue_key    = sanitize_key( $selected_queue['key'] ?? '' );

		$selected_item = [
			'order_id'       => $order_id,
			'order_number'   => $order_number,
			'status'         => sanitize_key( $selected_order['status'] ?? '' ),
			'queue_key'      => $queue_key,
			'customer_email' => sanitize_text_field( $selected_order['billing_email'] ?? '' ),
			'customer_name'  => sanitize_text_field( $selected_order['customer_name'] ?? '' ),
			'reason'         => sanitize_text_field( $selected_queue['description'] ?? '' ),
			'age_hours'      => absint( $selected_order['age_hours'] ?? 0 ),
		];

		$next_without_confirm = self::build_operations_attention_next_step( $selected_item, $queue_key );

		return [
			'selected_item'    => $selected_item,
			'queue_source'     => [
				'key'         => $queue_key,
				'label'       => sanitize_text_field( $selected_queue['label'] ?? '' ),
				'description' => sanitize_text_field( $selected_queue['description'] ?? '' ),
			],
			'selection_reason' => self::build_operations_attention_selection_reason( $selected_item, $queue_key ),
			'proposed_action'  => [
				'action' => sanitize_text_field( $next_without_confirm['action'] ?? '' ),
				'label'  => sanitize_text_field( $next_without_confirm['label'] ?? '' ),
			],
			'preview_summary'  => sanitize_text_field( $next_without_confirm['summary'] ?? '' ),
			'next_without_confirm' => $next_without_confirm,
		];
	}

	private static function build_operations_attention_selection_reason( $selected_item, $queue_key ) {
		$order_number = sanitize_text_field( $selected_item['order_number'] ?? '' );

		switch ( $queue_key ) {
			case 'pending_payment_older_than_24h':
				return sprintf(
					__( 'Order #%s was selected because payment is still pending and it is the clearest attention item to resolve first.', 'yoro-agent-core' ),
					$order_number
				);
			case 'processing_older_than_72h':
				return sprintf(
					__( 'Order #%s was selected because it has remained in processing long enough to indicate operational follow-through risk.', 'yoro-agent-core' ),
					$order_number
				);
			case 'on_hold_older_than_48h':
				return sprintf(
					__( 'Order #%s was selected because it has been on hold for more than 48 hours and likely needs customer follow-up next.', 'yoro-agent-core' ),
					$order_number
				);
			case 'recent_failed_orders':
				return sprintf(
					__( 'Order #%s was selected because it is part of the recent failed-order queue and may need customer recovery next.', 'yoro-agent-core' ),
					$order_number
				);
			case 'high_value_open_orders':
			default:
				return sprintf(
					__( 'Order #%s was selected because it is a high-value open order that still sits inside the operations attention backlog.', 'yoro-agent-core' ),
					$order_number
				);
		}
	}

	private static function build_operations_attention_next_step( $selected_item, $queue_key ) {
		switch ( $queue_key ) {
			case 'pending_payment_older_than_24h':
				return [
					'action'  => 'customers.get_service_queue',
					'label'   => __( 'Open customer service queue', 'yoro-agent-core' ),
					'command' => 'Show customer service queue',
					'summary' => __( 'Open the customer service queue next so YoRo can review pending-payment outreach candidates from the customer side.', 'yoro-agent-core' ),
				];
			case 'processing_older_than_72h':
				return [
					'action'  => 'operations.get_fulfillment_queue',
					'label'   => __( 'Open fulfillment queue', 'yoro-agent-core' ),
					'command' => 'Show fulfillment queue',
					'summary' => __( 'Open the fulfillment queue next to confirm whether this attention item is turning into a shipping or aging-processing follow-up.', 'yoro-agent-core' ),
				];
			case 'on_hold_older_than_48h':
			case 'recent_failed_orders':
				return [
					'action'  => 'customers.get_service_queue',
					'label'   => __( 'Review customer follow-up queue', 'yoro-agent-core' ),
					'command' => 'Show customer service queue',
					'summary' => __( 'Open the customer service queue next so YoRo can continue from the customer follow-up owner instead of staying at the attention layer.', 'yoro-agent-core' ),
				];
			case 'high_value_open_orders':
			default:
				return [
					'action'  => 'operations.get_summary',
					'label'   => __( 'Review operations summary', 'yoro-agent-core' ),
					'command' => 'Show operations summary for today',
					'summary' => __( 'Open the operations summary next to review the broader store impact before choosing a narrower workflow owner.', 'yoro-agent-core' ),
				];
		}
	}

	private static function select_inventory_pressure_candidate( $followup, $params ) {
		$preferred_key = ! empty( $params['queue_key'] ) ? sanitize_key( $params['queue_key'] ) : '';
		$case_rankings = isset( $followup['case_rankings'] ) && is_array( $followup['case_rankings'] )
			? array_values( $followup['case_rankings'] )
			: [];
		$queues        = isset( $followup['queues'] ) && is_array( $followup['queues'] )
			? array_values( $followup['queues'] )
			: [];

		if ( empty( $queues ) && empty( $case_rankings ) ) {
			return new WP_Error(
				'yoro_missing_inventory_queue_data',
				__( 'Inventory queue data is missing. Open the inventory queue again and retry.', 'yoro-agent-core' )
			);
		}

		if ( ! empty( $case_rankings ) ) {
			$selected_ranking = null;

			if ( $preferred_key ) {
				foreach ( $case_rankings as $ranked_case ) {
					if ( $preferred_key === sanitize_key( $ranked_case['topic'] ?? '' ) ) {
						$selected_ranking = $ranked_case;
						break;
					}
				}
			}

			if ( ! is_array( $selected_ranking ) && ! empty( $case_rankings[0] ) && is_array( $case_rankings[0] ) ) {
				$selected_ranking = $case_rankings[0];
			}

			if ( is_array( $selected_ranking ) ) {
				$queue_key = sanitize_key( $selected_ranking['topic'] ?? '' );
				$selected_product = isset( $selected_ranking['product'] ) && is_array( $selected_ranking['product'] )
					? $selected_ranking['product']
					: [];
				$product_id = absint( $selected_product['id'] ?? 0 );

				if ( $product_id > 0 ) {
					$selected_item = [
						'product_id'       => $product_id,
						'name'             => sanitize_text_field( $selected_product['name'] ?? '' ),
						'sku'              => sanitize_text_field( $selected_product['sku'] ?? '' ),
						'stock_status'     => sanitize_key( $selected_product['stock_status'] ?? '' ),
						'stock_quantity'   => is_numeric( $selected_product['stock_quantity'] ?? null ) ? (int) $selected_product['stock_quantity'] : '',
						'topic_key'        => $queue_key,
						'open_order_count' => absint( $selected_product['open_order_count'] ?? 0 ),
						'edit_url'         => esc_url_raw( $selected_product['edit_url'] ?? '' ),
						'reason'           => sanitize_text_field( $selected_ranking['queue_description'] ?? '' ),
					];

					$next_without_confirm = self::build_inventory_pressure_next_step( $selected_item, $queue_key );

					return [
						'selected_item'       => $selected_item,
						'queue_source'        => [
							'key'         => $queue_key,
							'label'       => sanitize_text_field( $selected_ranking['queue_label'] ?? '' ),
							'description' => sanitize_text_field( $selected_ranking['queue_description'] ?? '' ),
						],
						'selection_reason'    => self::build_inventory_pressure_selection_reason( $selected_item, $queue_key ),
						'proposed_action'     => [
							'action' => sanitize_text_field( $next_without_confirm['action'] ?? '' ),
							'label'  => sanitize_text_field( $next_without_confirm['label'] ?? '' ),
						],
						'preview_summary'     => sanitize_text_field( $next_without_confirm['summary'] ?? '' ),
						'next_without_confirm'=> $next_without_confirm,
					];
				}
			}
		}

		$queue_map = [];

		foreach ( $queues as $queue ) {
			$key = sanitize_key( $queue['key'] ?? '' );

			if ( '' !== $key ) {
				$queue_map[ $key ] = $queue;
			}
		}

		$priority = [
			'out_of_stock',
			'on_backorder',
			'low_stock',
			'low_stock_in_processing_orders',
		];

		if ( $preferred_key && in_array( $preferred_key, $priority, true ) ) {
			array_unshift( $priority, $preferred_key );
			$priority = array_values( array_unique( $priority ) );
		}

		$selected_queue = null;
		$selected_product = null;

		foreach ( $priority as $queue_key ) {
			if ( empty( $queue_map[ $queue_key ]['products'] ) || ! is_array( $queue_map[ $queue_key ]['products'] ) ) {
				continue;
			}

			foreach ( $queue_map[ $queue_key ]['products'] as $product ) {
				if ( empty( $product['id'] ) ) {
					continue;
				}

				$selected_queue = $queue_map[ $queue_key ];
				$selected_product = $product;
				break 2;
			}
		}

		if ( empty( $selected_queue ) || empty( $selected_product['id'] ) ) {
			return new WP_Error(
				'yoro_no_inventory_pressure_candidate',
				__( 'I could not find an inventory item that is ready for a guided preview yet.', 'yoro-agent-core' )
			);
		}

		$product_id = absint( $selected_product['id'] ?? 0 );
		$queue_key  = sanitize_key( $selected_queue['key'] ?? '' );

		$selected_item = [
			'product_id'      => $product_id,
			'name'            => sanitize_text_field( $selected_product['name'] ?? '' ),
			'sku'             => sanitize_text_field( $selected_product['sku'] ?? '' ),
			'stock_status'    => sanitize_key( $selected_product['stock_status'] ?? '' ),
			'stock_quantity'  => is_numeric( $selected_product['stock_quantity'] ?? null ) ? (int) $selected_product['stock_quantity'] : '',
			'topic_key'       => $queue_key,
			'open_order_count'=> absint( $selected_product['open_order_count'] ?? 0 ),
			'edit_url'        => esc_url_raw( $selected_product['edit_url'] ?? '' ),
			'reason'          => sanitize_text_field( $selected_queue['description'] ?? '' ),
		];

		$next_without_confirm = self::build_inventory_pressure_next_step( $selected_item, $queue_key );

		return [
			'selected_item'       => $selected_item,
			'queue_source'        => [
				'key'         => $queue_key,
				'label'       => sanitize_text_field( $selected_queue['label'] ?? '' ),
				'description' => sanitize_text_field( $selected_queue['description'] ?? '' ),
			],
			'selection_reason'    => self::build_inventory_pressure_selection_reason( $selected_item, $queue_key ),
			'proposed_action'     => [
				'action' => sanitize_text_field( $next_without_confirm['action'] ?? '' ),
				'label'  => sanitize_text_field( $next_without_confirm['label'] ?? '' ),
			],
			'preview_summary'     => sanitize_text_field( $next_without_confirm['summary'] ?? '' ),
			'next_without_confirm'=> $next_without_confirm,
		];
	}

	private static function build_inventory_pressure_selection_reason( $selected_item, $queue_key ) {
		$product_name = sanitize_text_field( $selected_item['name'] ?? '' );

		switch ( $queue_key ) {
			case 'out_of_stock':
				return sprintf(
					__( '%s was selected because it is already out of stock and represents the clearest inventory pressure to review first.', 'yoro-agent-core' ),
					$product_name
				);
			case 'on_backorder':
				return sprintf(
					__( '%s was selected because it is already on backorder and may now be feeding directly into fulfillment pressure.', 'yoro-agent-core' ),
					$product_name
				);
			case 'low_stock_in_processing_orders':
				return sprintf(
					__( '%s was selected because recent processing demand is already consuming the remaining stock and makes it the most actionable inventory follow-up next.', 'yoro-agent-core' ),
					$product_name
				);
			case 'low_stock':
			default:
				return sprintf(
					__( '%s was selected because its stock is already low and it is the cleanest candidate for the next inventory review step.', 'yoro-agent-core' ),
					$product_name
				);
		}
	}

	private static function build_inventory_pressure_next_step( $selected_item, $queue_key ) {
		switch ( $queue_key ) {
			case 'out_of_stock':
				return [
					'action'  => 'products.get_inventory_queue',
					'label'   => __( 'Review restock priorities', 'yoro-agent-core' ),
					'command' => 'What should we restock first?',
					'summary' => __( 'Review the restock priorities next so YoRo can stay inside inventory pressure without simply reopening the same queue view.', 'yoro-agent-core' ),
				];
			case 'on_backorder':
			case 'low_stock_in_processing_orders':
				return [
					'action'  => 'operations.get_fulfillment_queue',
					'label'   => __( 'Check fulfillment pressure', 'yoro-agent-core' ),
					'command' => 'What seems blocked in fulfillment?',
					'summary' => __( 'Check fulfillment pressure next so YoRo can confirm whether inventory risk is already turning into shipping or aging-processing backlog.', 'yoro-agent-core' ),
				];
			case 'low_stock':
			default:
				return [
					'action'  => 'store.get_business_summary',
					'label'   => __( 'Review broader store picture', 'yoro-agent-core' ),
					'command' => 'Can you give me a monthly store picture?',
					'summary' => __( 'Review the broader store picture next so YoRo can weigh this low-stock signal against the wider commercial and operating context.', 'yoro-agent-core' ),
				];
		}
	}

	private static function select_business_summary_candidate( $followup, $params ) {
		$priority_items = isset( $followup['priority_items'] ) && is_array( $followup['priority_items'] )
			? array_values( $followup['priority_items'] )
			: [];
		$sequence       = isset( $followup['recommended_sequence'] ) && is_array( $followup['recommended_sequence'] )
			? array_values( $followup['recommended_sequence'] )
			: [];
		$context        = isset( $followup['business_context'] ) && is_array( $followup['business_context'] )
			? $followup['business_context']
			: [];

		if ( empty( $priority_items ) || empty( $sequence ) ) {
			return new WP_Error(
				'yoro_missing_business_summary_data',
				__( 'Business summary data is missing. Open the business summary again and retry.', 'yoro-agent-core' )
			);
		}

		$selected_priority = $priority_items[0];
		$next_step = $sequence[0];

		$selected_item = [
			'topic_key'    => sanitize_key( $selected_priority['topic'] ?? $context['top_topic'] ?? 'overview' ),
			'title'        => sanitize_text_field( $selected_priority['title'] ?? __( 'Business signal', 'yoro-agent-core' ) ),
			'message'      => sanitize_text_field( $selected_priority['message'] ?? '' ),
			'tone'         => sanitize_key( $selected_priority['tone'] ?? 'neutral' ),
			'window_key'   => sanitize_key( $context['window_key'] ?? 'last_7_days' ),
			'focus'        => sanitize_key( $context['focus'] ?? 'overview' ),
			'order_count'  => absint( $context['order_count'] ?? 0 ),
			'gross_sales'  => sanitize_text_field( $context['gross_sales'] ?? '' ),
			'attention_total' => absint( $context['attention_total'] ?? 0 ),
		];

		$next_without_confirm = [
			'action'  => self::map_business_topic_to_action( sanitize_key( $next_step['topic'] ?? '' ) ),
			'label'   => sanitize_text_field( $next_step['label'] ?? '' ),
			'command' => sanitize_text_field( $next_step['command'] ?? '' ),
			'summary' => sanitize_text_field( $next_step['reason'] ?? '' ),
		];

		return [
			'selected_item'       => $selected_item,
			'selection_reason'    => self::build_business_summary_selection_reason( $selected_item ),
			'proposed_action'     => [
				'action' => sanitize_text_field( $next_without_confirm['action'] ?? '' ),
				'label'  => sanitize_text_field( $next_without_confirm['label'] ?? '' ),
			],
			'preview_summary'     => sanitize_text_field( $next_without_confirm['summary'] ?? '' ),
			'next_without_confirm'=> $next_without_confirm,
		];
	}

	private static function build_business_summary_selection_reason( $selected_item ) {
		$title = sanitize_text_field( $selected_item['title'] ?? __( 'Business signal', 'yoro-agent-core' ) );
		$topic = sanitize_key( $selected_item['topic_key'] ?? '' );

		if ( 'operations_pressure' === $topic ) {
			return sprintf(
				__( '%s was selected because live queue pressure is currently the strongest force shaping the business picture.', 'yoro-agent-core' ),
				$title
			);
		}

		if ( 'in_flight_activity' === $topic ) {
			return sprintf(
				__( '%s was selected because a large share of current activity is still unresolved and needs operational follow-through before revenue fully converts.', 'yoro-agent-core' ),
				$title
			);
		}

		return sprintf(
			__( '%s was selected because it is the clearest business signal to review before drilling into the next owner queue.', 'yoro-agent-core' ),
			$title
		);
	}

	private static function map_business_topic_to_action( $topic ) {
		switch ( $topic ) {
			case 'operations_attention':
				return 'operations.get_attention_queue';
			case 'fulfillment':
				return 'operations.get_fulfillment_queue';
			case 'inventory':
				return 'products.get_inventory_queue';
			case 'customer_service':
				return 'customers.get_service_queue';
			case 'operations_overview':
				return 'operations.get_summary';
			case 'business_summary':
			default:
				return 'store.get_business_summary';
		}
	}

	private static function execute_fulfillment_followup_after_confirmation( $workflow ) {
		$execution = YoRo_Workflow_Runner::execute_confirmed_workflow( $workflow );

		if ( is_wp_error( $execution ) ) {
			return $execution;
		}

		$user_id = get_current_user_id();
		$result  = is_array( $execution['result'] ) ? $execution['result'] : [];
		$workflow = is_array( $execution['workflow'] ) ? $execution['workflow'] : $workflow;

		$result['workflow'] = $workflow;
		$result['workflow_id'] = 'fulfillment_followup_v1';
		$result['workflow_version'] = 'v1';
		$result['workflow_executed'] = true;
		$result['selection_reason'] = sanitize_text_field( $workflow['artifacts']['selection_reason'] ?? $workflow['params']['selection_reason'] ?? '' );
		$result['selected_item'] = isset( $workflow['artifacts']['selected_item'] ) && is_array( $workflow['artifacts']['selected_item'] )
			? $workflow['artifacts']['selected_item']
			: [];
		$result['queue_source'] = isset( $workflow['artifacts']['queue_source'] ) && is_array( $workflow['artifacts']['queue_source'] )
			? $workflow['artifacts']['queue_source']
			: [ 'key' => sanitize_key( $workflow['params']['queue_key'] ?? '' ) ];

		YoRo_Conversation_Memory::clear_workflow( $user_id );

		return $result;
	}

	private static function execute_customer_followup_after_confirmation( $workflow ) {
		$execution = YoRo_Workflow_Runner::execute_confirmed_workflow( $workflow );

		if ( is_wp_error( $execution ) ) {
			return $execution;
		}

		$user_id = get_current_user_id();
		$result  = is_array( $execution['result'] ) ? $execution['result'] : [];
		$workflow = is_array( $execution['workflow'] ) ? $execution['workflow'] : $workflow;

		$result['workflow'] = $workflow;
		$result['workflow_id']       = 'customer_followup_v1';
		$result['workflow_version']  = 'v1';
		$result['workflow_executed'] = true;
		$result['selection_reason']  = sanitize_text_field( $workflow['artifacts']['selection_reason'] ?? $workflow['params']['selection_reason'] ?? '' );
		$result['selected_item']     = isset( $workflow['artifacts']['selected_item'] ) && is_array( $workflow['artifacts']['selected_item'] )
			? $workflow['artifacts']['selected_item']
			: [];
		$result['queue_source']      = isset( $workflow['artifacts']['queue_source'] ) && is_array( $workflow['artifacts']['queue_source'] )
			? $workflow['artifacts']['queue_source']
			: [ 'key' => sanitize_key( $workflow['params']['queue_key'] ?? '' ) ];

		YoRo_Conversation_Memory::clear_workflow( $user_id );

		return $result;
	}
}
