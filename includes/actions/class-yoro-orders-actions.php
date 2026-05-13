<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Orders_Actions {

	public static function register() {
		YoRo_Action_Registry::register(
			'orders.search',
			[
				'label'       => __( 'Search orders', 'yoro-agent-core' ),
				'description' => __( 'Search WooCommerce orders by status, limit, and date.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'search_orders' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'status'          => 'string|array',
					'limit'           => 'integer',
					'email'           => 'string',
					'phone'           => 'string',
					'payment_method'  => 'string',
					'date_from'       => 'string',
					'date_to'         => 'string',
					'order_id'        => 'integer',
					'customer_name'   => 'string',
					'billing_country' => 'string',
					'total_min'       => 'float',
					'total_max'       => 'float',
				],
				'preview_callback' => [ __CLASS__, 'preview_search_orders' ],
				'max_items'        => 50,
				'capabilities' => [
					'orders',
					'read',
					'search',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.get_details',
			[
				'label'       => __( 'Get order details', 'yoro-agent-core' ),
				'description' => __( 'Get basic details for selected WooCommerce orders.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_order_details' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'order_ids' => 'array<int>',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_order_details' ],
				'max_items'        => 20,
				'capabilities' => [
					'orders',
					'read',
					'search',
				],
			]
		);

		YoRo_Action_Registry::register(
			'operations.get_summary',
			[
				'label'       => __( 'Get operations summary', 'yoro-agent-core' ),
				'description' => __( 'Summarize order flow, backlog, and aged queues for store operations.', 'yoro-agent-core' ),
				'category'    => 'operations',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_operations_summary' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'window' => 'string',
					'focus'  => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_operations_summary' ],
				'max_items'        => 1,
				'capabilities' => [
					'operations',
					'orders',
					'read',
					'reporting',
				],
			]
		);

		YoRo_Action_Registry::register(
			'operations.get_attention_queue',
			[
				'label'       => __( 'Get operations attention queue', 'yoro-agent-core' ),
				'description' => __( 'List operational order queues that need store team attention, without fraud scoring.', 'yoro-agent-core' ),
				'category'    => 'operations',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_operations_attention_queue' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'limit'                => 'integer',
					'high_value_threshold' => 'float',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_operations_attention_queue' ],
				'max_items'        => 50,
				'capabilities' => [
					'operations',
					'orders',
					'read',
					'reporting',
				],
			]
		);

		YoRo_Action_Registry::register(
			'operations.get_fulfillment_queue',
			[
				'label'       => __( 'Get fulfillment queue', 'yoro-agent-core' ),
				'description' => __( 'List processing orders that may need fulfillment or shipping attention.', 'yoro-agent-core' ),
				'category'    => 'operations',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_fulfillment_queue' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'limit' => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_fulfillment_queue' ],
				'max_items'        => 50,
				'capabilities' => [
					'operations',
					'orders',
					'read',
					'reporting',
					'fulfillment',
				],
			]
		);

		YoRo_Action_Registry::register(
			'products.get_inventory_queue',
			[
				'label'       => __( 'Get inventory queue', 'yoro-agent-core' ),
				'description' => __( 'List WooCommerce products that may need inventory attention.', 'yoro-agent-core' ),
				'category'    => 'products',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_inventory_queue' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'limit' => 'integer',
					'focus' => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_inventory_queue' ],
				'max_items'        => 50,
				'capabilities' => [
					'products',
					'inventory',
					'read',
					'reporting',
				],
			]
		);

		YoRo_Action_Registry::register(
			'store.get_morning_briefing',
			[
				'label'       => __( 'Get morning briefing', 'yoro-agent-core' ),
				'description' => __( 'Prepare a proactive morning briefing that combines yesterday performance with today\'s live store pressure.', 'yoro-agent-core' ),
				'category'    => 'store',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_morning_briefing' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'window'           => 'string',
					'yesterday_window' => 'string',
					'limit'            => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_morning_briefing' ],
				'max_items'        => 1,
				'capabilities' => [
					'store',
					'operations',
					'customers',
					'products',
					'read',
					'reporting',
					'briefing',
					'proactive',
				],
			]
		);

		YoRo_Action_Registry::register(
			'store.get_daily_briefing',
			[
				'label'       => __( 'Get daily briefing', 'yoro-agent-core' ),
				'description' => __( 'Summarize the current store picture across operations, attention, fulfillment, customer follow-up, and inventory.', 'yoro-agent-core' ),
				'category'    => 'store',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_daily_briefing' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'window' => 'string',
					'limit'  => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_daily_briefing' ],
				'max_items'        => 1,
				'capabilities' => [
					'store',
					'operations',
					'customers',
					'products',
					'read',
					'reporting',
					'briefing',
				],
			]
		);

		YoRo_Action_Registry::register(
			'store.get_business_summary',
			[
				'label'       => __( 'Get business summary', 'yoro-agent-core' ),
				'description' => __( 'Summarize store business performance, operating pressure, customer activity, and product signals.', 'yoro-agent-core' ),
				'category'    => 'store',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_business_summary' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'window' => 'string',
					'focus'  => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_business_summary' ],
				'max_items'        => 1,
				'capabilities' => [
					'store',
					'orders',
					'customers',
					'products',
					'operations',
					'read',
					'reporting',
				],
			]
		);

		YoRo_Action_Registry::register(
			'store.get_insights',
			[
				'label'       => __( 'Get store insights', 'yoro-agent-core' ),
				'description' => __( 'Summarize read-only order, customer, and product insights for the store.', 'yoro-agent-core' ),
				'category'    => 'store',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_store_insights' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'window' => 'string',
					'limit'  => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_store_insights' ],
				'max_items'        => 50,
				'capabilities' => [
					'store',
					'orders',
					'customers',
					'products',
					'read',
					'reporting',
				],
			]
		);

		YoRo_Action_Registry::register(
			'customers.get_summary',
			[
				'label'       => __( 'Get customer summary', 'yoro-agent-core' ),
				'description' => __( 'Summarize customer order history by email or phone.', 'yoro-agent-core' ),
				'category'    => 'customers',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_customer_summary' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'email' => 'string',
					'phone' => 'string',
					'limit' => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_customer_summary' ],
				'max_items'        => 50,
				'capabilities' => [
					'customers',
					'read',
					'search',
				],
			]
		);

		YoRo_Action_Registry::register(
			'customers.get_service_queue',
			[
				'label'       => __( 'Get customer service queue', 'yoro-agent-core' ),
				'description' => __( 'List WooCommerce orders that may need customer follow-up, without sending messages.', 'yoro-agent-core' ),
				'category'    => 'customers',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_customer_service_queue' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'limit' => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_customer_service_queue' ],
				'max_items'        => 50,
				'capabilities' => [
					'customers',
					'orders',
					'read',
					'reporting',
					'customer_communication',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.find_by_customer',
			[
				'label'       => __( 'Find orders by customer', 'yoro-agent-core' ),
				'description' => __( 'Find WooCommerce orders by customer email, phone, or customer ID.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'find_orders_by_customer' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'email'       => 'string',
					'phone'       => 'string',
					'customer_id' => 'integer',
					'limit'       => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_find_orders_by_customer' ],
				'max_items'        => 50,
				'capabilities' => [
					'orders',
					'read',
					'search',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.find_by_identity',
			[
				'label'       => __( 'Find orders by identity', 'yoro-agent-core' ),
				'description' => __( 'Find WooCommerce orders sharing email, phone, IP, or device identifier.', 'yoro-agent-core' ),
				'category'    => 'orders',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'find_orders_by_identity' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'order_id' => 'integer',
					'limit'    => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_find_orders_by_identity' ],
				'max_items'        => 50,
				'capabilities' => [
					'orders',
					'read',
					'search',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.refund_preview',
			[
				'label'            => __( 'Refund preview', 'yoro-agent-core' ),
				'description'      => __( 'Preview refundable amount, refunded amount, and line item refund context for a WooCommerce order.', 'yoro-agent-core' ),
				'category'         => 'orders',
				'permission'       => 'manage_woocommerce',
				'callback'         => [ __CLASS__, 'refund_preview' ],
				'risk_level'       => 'low',
				'write_action'     => false,
				'input_schema'     => [
					'order_id' => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_refund_preview' ],
				'max_items'        => 1,
				'capabilities' => [
					'orders',
					'financial',
					'preview',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.email_customer_preview',
			[
				'label'            => __( 'Preview customer email', 'yoro-agent-core' ),
				'description'      => __( 'Preview a customer email generated from an order template without sending it.', 'yoro-agent-core' ),
				'category'         => 'orders',
				'permission'       => 'manage_woocommerce',
				'callback'         => [ __CLASS__, 'email_customer_preview' ],
				'risk_level'       => 'low',
				'write_action'     => false,
				'input_schema'     => [
					'order_id' => 'integer',
					'template' => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_email_customer_preview' ],
				'max_items'        => 1,
				'capabilities' => [
					'orders',
					'email',
					'customer_communication',
					'preview',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.email_customer_template',
			[
				'label'                 => __( 'Send customer email', 'yoro-agent-core' ),
				'description'           => __( 'Send a customer email generated from an order template.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'email_customer_template' ],
				'requires_confirmation' => true,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'input_schema'          => [
					'order_id' => 'integer',
					'template' => 'string',
				],
				'preview_callback'      => [ __CLASS__, 'preview_email_customer_template' ],
				'max_items'             => 1,
				'capabilities' => [
					'email',
					'customer_communication',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'email_templates.save',
			[
				'label'        => __( 'Save email template', 'yoro-agent-core' ),
				'description'  => __( 'Save a reusable customer email template.', 'yoro-agent-core' ),
				'category'     => 'email_templates',
				'permission'   => 'manage_woocommerce',
				'callback'     => [ __CLASS__, 'save_email_template' ],
				'write_action' => true,
				'input_schema' => [
					'name'    => 'string',
					'subject' => 'string',
					'body'    => 'string',
				],
				'capabilities' => [
					'email',
					'draft',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'email_templates.list',
			[
				'label'        => __( 'List email templates', 'yoro-agent-core' ),
				'description'  => __( 'Get all reusable customer email templates.', 'yoro-agent-core' ),
				'category'     => 'email_templates',
				'permission'   => 'manage_woocommerce',
				'callback'     => [ __CLASS__, 'list_email_templates' ],
				'write_action' => false,
				'capabilities' => [
					'email_templates',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'email_templates.delete',
			[
				'label'                 => __( 'Delete email template', 'yoro-agent-core' ),
				'description'           => __( 'Delete a reusable email template.', 'yoro-agent-core' ),
				'category'              => 'email_templates',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'delete_email_template' ],
				'write_action'          => true,
				'requires_confirmation' => true,
				'input_schema'          => [
					'template_id' => 'string',
				],
				'capabilities' => [
					'email_templates',
					'destructive',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.use_email_template',
			[
				'label'        => __( 'Use email template', 'yoro-agent-core' ),
				'description'  => __( 'Apply a reusable template to an order email.', 'yoro-agent-core' ),
				'category'     => 'orders',
				'permission'   => 'manage_woocommerce',
				'callback'     => [ __CLASS__, 'use_email_template' ],
				'write_action' => false,
				'input_schema' => [
					'order_id'    => 'integer',
					'template_id' => 'string',
				],
				'capabilities' => [
					'orders',
					'email',
					'customer_communication',
					'preview',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.compose_customer_email',
			[
				'label'            => __( 'Compose customer email', 'yoro-agent-core' ),
				'description'      => __( 'Use AI to compose a customer email from order context without sending it.', 'yoro-agent-core' ),
				'category'         => 'orders',
				'permission'       => 'manage_woocommerce',
				'callback'         => [ __CLASS__, 'compose_customer_email' ],
				'risk_level'       => 'low',
				'write_action'     => false,
				'input_schema'     => [
					'order_id'     => 'integer',
					'instruction'  => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_compose_customer_email' ],
				'max_items'        => 1,
				'capabilities' => [
					'orders',
					'email',
					'customer_communication',
					'ai',
					'preview',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.save_customer_email_draft',
			[
				'label'            => __( 'Save customer email draft', 'yoro-agent-core' ),
				'description'      => __( 'Save a reviewed customer email draft to the order.', 'yoro-agent-core' ),
				'category'         => 'orders',
				'permission'       => 'manage_woocommerce',
				'callback'         => [ __CLASS__, 'save_customer_email_draft' ],
				'risk_level'       => 'low',
				'write_action'     => true,
				'input_schema'     => [
					'order_id' => 'integer',
					'subject'  => 'string',
					'body'     => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_save_customer_email_draft' ],
				'max_items'        => 1,
				'capabilities' => [
					'email',
					'draft',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.get_customer_email_drafts',
			[
				'label'            => __( 'Get customer email drafts', 'yoro-agent-core' ),
				'description'      => __( 'Load saved customer email drafts for an order.', 'yoro-agent-core' ),
				'category'         => 'orders',
				'permission'       => 'manage_woocommerce',
				'callback'         => [ __CLASS__, 'get_customer_email_drafts' ],
				'risk_level'       => 'low',
				'write_action'     => false,
				'input_schema'     => [
					'order_id' => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_customer_email_drafts' ],
				'max_items'        => 1,
				'capabilities' => [
					'orders',
					'email',
					'draft',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.update_customer_email_draft',
			[
				'label'                 => __( 'Update customer email draft', 'yoro-agent-core' ),
				'description'           => __( 'Update an existing customer email draft for an order.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'update_customer_email_draft' ],
				'requires_confirmation' => false,
				'risk_level'            => 'low',
				'write_action'          => true,
				'input_schema'          => [
					'order_id' => 'integer',
					'draft_id' => 'string',
					'subject'  => 'string',
					'body'     => 'string',
				],
				'preview_callback'      => [ __CLASS__, 'preview_update_customer_email_draft' ],
				'max_items'             => 1,
				'capabilities' => [
					'email',
					'draft',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.delete_customer_email_draft',
			[
				'label'                 => __( 'Delete customer email draft', 'yoro-agent-core' ),
				'description'           => __( 'Delete a saved customer email draft from an order.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'delete_customer_email_draft' ],
				'requires_confirmation' => true,
				'risk_level'            => 'low',
				'write_action'          => true,
				'input_schema'          => [
					'order_id'  => 'integer',
					'draft_id'  => 'string',
				],
				'preview_callback'      => [ __CLASS__, 'preview_delete_customer_email_draft' ],
				'max_items'             => 1,
				'capabilities' => [
					'orders',
					'destructive',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.render_customer_email_preview',
			[
				'label'            => __( 'Render customer email preview', 'yoro-agent-core' ),
				'description'      => __( 'Render a WooCommerce-styled customer email preview from reviewed subject and body.', 'yoro-agent-core' ),
				'category'         => 'orders',
				'permission'       => 'manage_woocommerce',
				'callback'         => [ __CLASS__, 'render_customer_email_preview' ],
				'risk_level'       => 'low',
				'write_action'     => false,
				'input_schema'     => [
					'order_id' => 'integer',
					'subject'  => 'string',
					'body'     => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_render_customer_email_preview' ],
				'max_items'        => 1,
				'capabilities' => [
					'orders',
					'email',
					'customer_communication',
					'preview',
					'read',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.send_customer_email',
			[
				'label'                 => __( 'Send customer email', 'yoro-agent-core' ),
				'description'           => __( 'Send a reviewed custom email to the customer for an order.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'send_customer_email' ],
				'requires_confirmation' => true,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'input_schema'          => [
					'order_id' => 'integer',
					'subject'  => 'string',
					'body'     => 'string',
				],
				'preview_callback'      => [ __CLASS__, 'preview_send_customer_email' ],
				'max_items'             => 1,
				'capabilities' => [
					'orders',
					'email',
					'customer_communication',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.add_private_note_template',
			[
				'label'                 => __( 'Add private note template', 'yoro-agent-core' ),
				'description'           => __( 'Add a predefined internal note to a WooCommerce order.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'add_private_note_template' ],
				'requires_confirmation' => false,
				'risk_level'            => 'low',
				'write_action'          => true,
				'input_schema'          => [
					'order_id'  => 'integer',
					'order_ids' => 'array<int>',
					'template'  => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_add_private_note_template' ],
				'max_items'        => 20,
				'capabilities' => [
					'orders',
					'notes',
					'write',
					'bulk',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.hold',
			[
				'label'                 => __( 'Put orders on hold', 'yoro-agent-core' ),
				'description'           => __( 'Change selected WooCommerce orders to on-hold.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'hold_orders' ],
				'requires_confirmation' => true,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'input_schema'          => [
					'order_ids' => 'array<int>',
				],
				'preview_callback' => [ __CLASS__, 'preview_hold_orders' ],
				'max_items'        => 20,
				'auto_resolve' => [
					'order_ids' => [
						'params.order_ids',
						'context.order_ids',
						'parsed.entities.order_ids',
					],
				],
				'capabilities' => [
					'orders',
					'write',
					'bulk',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.add_note',
			[
				'label'                 => __( 'Add order note', 'yoro-agent-core' ),
				'description'           => __( 'Add an internal note to a WooCommerce order.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'add_order_note' ],
				'requires_confirmation' => false,
				'risk_level'            => 'low',
				'write_action'          => true,
				'input_schema' => [
					'order_id'  => 'integer',
					'order_ids' => 'array<int>',
					'note'      => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_add_order_note' ],
				'max_items'        => 20,
				'auto_resolve' => [
					'order_ids' => [
						'params.order_ids',
						'context.order_ids',
						'parsed.entities.order_ids',
					],
				],
				'capabilities' => [
					'orders',
					'notes',
					'write',
					'bulk',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.remove_note',
			[
				'label'                 => __( 'Remove order note', 'yoro-agent-core' ),
				'description'           => __( 'Remove YoRo-created internal notes from WooCommerce orders.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'remove_order_note' ],
				'requires_confirmation' => false,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'input_schema'          => [
					'note_ids' => 'array<int>',
				],
				'max_items'             => 20,
				'capabilities' => [
					'orders',
					'destructive',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.update_status',
			[
				'label'                 => __( 'Update order status', 'yoro-agent-core' ),
				'description'           => __( 'Change selected WooCommerce orders to a specific status.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'update_order_status' ],
				'requires_confirmation' => true,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'input_schema'          => [
					'order_ids' => 'array<int>',
					'status'    => 'string',
					'note'      => 'string',
				],
				'preview_callback'      => [ __CLASS__, 'preview_update_order_status' ],
				'max_items'             => 20,
				'auto_resolve' => [
					'order_ids' => [
						'params.order_ids',
						'context.order_ids',
						'parsed.entities.order_ids',
					],
				],
				'capabilities' => [
					'orders',
					'write',
					'bulk',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.restore_statuses',
			[
				'label'                 => __( 'Restore order statuses', 'yoro-agent-core' ),
				'description'           => __( 'Restore WooCommerce orders to their previous statuses.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'restore_order_statuses' ],
				'requires_confirmation' => false,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'input_schema'          => [
					'statuses' => 'array',
				],
				'max_items'             => 20,
				'capabilities' => [
					'orders',
					'write',
					'bulk',
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.bulk_update_status_with_note',
			[
				'label'                 => __( 'Bulk update status with note', 'yoro-agent-core' ),
				'description'           => __( 'Change selected WooCommerce orders to a specific status and add an internal note.', 'yoro-agent-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'bulk_update_status_with_note' ],
				'requires_confirmation' => true,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'input_schema'          => [
					'order_ids' => 'array<int>',
					'status'    => 'string',
					'note'      => 'string',
				],
				'preview_callback'      => [ __CLASS__, 'preview_bulk_update_status_with_note' ],
				'max_items'             => 20,
				'auto_resolve' => [
					'order_ids' => [
						'params.order_ids',
						'context.order_ids',
						'parsed.entities.order_ids',
					],
				],
				'capabilities' => [
					'orders',
					'write',
					'bulk',
				],
			]
		);

		YoRo_Action_Registry::register(
			'assistant.submit_developer_request',
			[
				'label'       => __( 'Submit developer request', 'yoro-agent-core' ),
				'description' => __( 'Save a product-gap or unsupported-capability request for later developer review.', 'yoro-agent-core' ),
				'category'    => 'assistant',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'submit_developer_request' ],
				'risk_level'  => 'low',
				'write_action'=> true,
				'input_schema'=> [
					'request_text'              => 'string',
					'original_command'          => 'string',
					'unsupported_capability'    => 'string',
					'conversation_family'       => 'string',
					'closest_supported_surface' => 'string',
					'context_order_id'          => 'integer',
				],
				'max_items' => 1,
				'capabilities' => [
					'assistant',
					'feedback',
					'write',
				],
			]
		);
	}

	public static function search_orders( $params ) {
		return YoRo_Orders_Read_Service::search_orders( $params );
	}

	public static function get_order_details( $params ) {
		return YoRo_Orders_Read_Service::get_order_details( $params );
	}

	public static function get_operations_summary( $params ) {
		return YoRo_Orders_Read_Service::get_operations_summary( $params );
	}

	public static function get_operations_attention_queue( $params ) {
		return YoRo_Orders_Read_Service::get_operations_attention_queue( $params );
	}

	public static function get_fulfillment_queue( $params ) {
		return YoRo_Orders_Read_Service::get_fulfillment_queue( $params );
	}

	public static function get_inventory_queue( $params ) {
		return YoRo_Orders_Read_Service::get_inventory_queue( $params );
	}

	public static function get_store_insights( $params ) {
		return YoRo_Orders_Read_Service::get_store_insights( $params );
	}

	public static function get_business_summary( $params ) {
		return YoRo_Orders_Read_Service::get_business_summary( $params );
	}

	public static function get_morning_briefing( $params ) {
		return YoRo_Orders_Read_Service::get_morning_briefing( $params );
	}

	public static function get_daily_briefing( $params ) {
		return YoRo_Orders_Read_Service::get_daily_briefing( $params );
	}

	public static function refund_preview( $params ) {
		return YoRo_Orders_Read_Service::refund_preview( $params );
	}

	public static function email_customer_preview( $params ) {
		return YoRo_Orders_Email_Service::email_customer_preview( $params );
	}

	public static function email_customer_template( $params ) {
		return YoRo_Orders_Email_Service::email_customer_template( $params );
	}

	public static function use_email_template( $params ) {
		return YoRo_Orders_Email_Service::use_email_template( $params );
	}

	public static function compose_customer_email( $params ) {
		return YoRo_Orders_Email_Service::compose_customer_email( $params );
	}

	public static function save_email_template( $params ) {
		return YoRo_Orders_Email_Service::save_email_template( $params );
	}

	public static function list_email_templates() {
		return YoRo_Orders_Email_Service::list_email_templates();
	}

	public static function delete_email_template( $params ) {
		return YoRo_Orders_Email_Service::delete_email_template( $params );
	}

	public static function save_customer_email_draft( $params ) {
		return YoRo_Orders_Email_Service::save_customer_email_draft( $params );
	}

	public static function get_customer_email_drafts( $params ) {
		return YoRo_Orders_Email_Service::get_customer_email_drafts( $params );
	}

	public static function update_customer_email_draft( $params ) {
		return YoRo_Orders_Email_Service::update_customer_email_draft( $params );
	}

	public static function delete_customer_email_draft( $params ) {
		return YoRo_Orders_Email_Service::delete_customer_email_draft( $params );
	}

	public static function render_customer_email_preview( $params ) {
		return YoRo_Orders_Email_Service::render_customer_email_preview( $params );
	}

	public static function send_customer_email( $params ) {
		return YoRo_Orders_Email_Service::send_customer_email( $params );
	}

	public static function get_customer_summary( $params ) {
		return YoRo_Orders_Read_Service::get_customer_summary( $params );
	}

	public static function get_customer_service_queue( $params ) {
		return YoRo_Orders_Read_Service::get_customer_service_queue( $params );
	}

	public static function find_orders_by_customer( $params ) {
		return YoRo_Orders_Read_Service::find_orders_by_customer( $params );
	}

	public static function find_orders_by_identity( $params ) {
		return YoRo_Orders_Read_Service::find_orders_by_identity( $params );
	}

	public static function add_private_note_template( $params ) {
		return YoRo_Orders_Write_Service::add_private_note_template( $params );
	}

	public static function hold_orders( $params ) {
		return YoRo_Orders_Write_Service::hold_orders( $params );
	}

	public static function add_order_note( $params ) {
		return YoRo_Orders_Write_Service::add_order_note( $params );
	}

	public static function remove_order_note( $params ) {
		return YoRo_Orders_Write_Service::remove_order_note( $params );
	}

	public static function preview_search_orders( $params ) {
		return YoRo_Orders_Read_Service::preview_search_orders( $params );
	}

	public static function preview_get_order_details( $params ) {
		return YoRo_Orders_Read_Service::preview_get_order_details( $params );
	}

	public static function preview_get_operations_summary( $params ) {
		return YoRo_Orders_Read_Service::preview_get_operations_summary( $params );
	}

	public static function preview_get_operations_attention_queue( $params ) {
		return YoRo_Orders_Read_Service::preview_get_operations_attention_queue( $params );
	}

	public static function preview_get_fulfillment_queue( $params ) {
		return YoRo_Orders_Read_Service::preview_get_fulfillment_queue( $params );
	}

	public static function preview_get_inventory_queue( $params ) {
		return YoRo_Orders_Read_Service::preview_get_inventory_queue( $params );
	}

	public static function preview_get_store_insights( $params ) {
		return YoRo_Orders_Read_Service::preview_get_store_insights( $params );
	}

	public static function preview_get_business_summary( $params ) {
		return YoRo_Orders_Read_Service::preview_get_business_summary( $params );
	}

	public static function preview_get_morning_briefing( $params ) {
		return YoRo_Orders_Read_Service::preview_get_morning_briefing( $params );
	}

	public static function preview_get_daily_briefing( $params ) {
		return YoRo_Orders_Read_Service::preview_get_daily_briefing( $params );
	}

	public static function preview_refund_preview( $params ) {
		return YoRo_Orders_Read_Service::preview_refund_preview( $params );
	}

	public static function preview_email_customer_preview( $params ) {
		return YoRo_Orders_Email_Service::preview_email_customer_preview( $params );
	}

	public static function preview_email_customer_template( $params ) {
		return YoRo_Orders_Email_Service::preview_email_customer_template( $params );
	}

	public static function preview_compose_customer_email( $params ) {
		return YoRo_Orders_Email_Service::preview_compose_customer_email( $params );
	}

	public static function preview_save_customer_email_draft( $params ) {
		return YoRo_Orders_Email_Service::preview_save_customer_email_draft( $params );
	}

	public static function preview_get_customer_email_drafts( $params ) {
		return YoRo_Orders_Email_Service::preview_get_customer_email_drafts( $params );
	}

	public static function preview_update_customer_email_draft( $params ) {
		return YoRo_Orders_Email_Service::preview_update_customer_email_draft( $params );
	}

	public static function preview_delete_customer_email_draft( $params ) {
		return YoRo_Orders_Email_Service::preview_delete_customer_email_draft( $params );
	}

	public static function preview_render_customer_email_preview( $params ) {
		return YoRo_Orders_Email_Service::preview_render_customer_email_preview( $params );
	}

	public static function preview_send_customer_email( $params ) {
		return YoRo_Orders_Email_Service::preview_send_customer_email( $params );
	}

	public static function preview_hold_orders( $params ) {
		return YoRo_Orders_Write_Service::preview_hold_orders( $params );
	}

	public static function preview_add_order_note( $params ) {
		return YoRo_Orders_Write_Service::preview_add_order_note( $params );
	}

	public static function update_order_status( $params ) {
		return YoRo_Orders_Write_Service::update_order_status( $params );
	}

	public static function restore_order_statuses( $params ) {
		return YoRo_Orders_Write_Service::restore_order_statuses( $params );
	}

	public static function bulk_update_status_with_note( $params ) {
		return YoRo_Orders_Write_Service::bulk_update_status_with_note( $params );
	}

	public static function submit_developer_request( $params ) {
		$params = is_array( $params ) ? $params : [];
		$request_text = sanitize_text_field( $params['request_text'] ?? '' );
		$order_id     = absint( $params['context_order_id'] ?? 0 );
		$user_id      = get_current_user_id();
		$user         = $user_id ? get_userdata( $user_id ) : null;
		$user_name    = $user ? sanitize_text_field( $user->display_name ) : '';
		$user_email   = $user ? sanitize_email( $user->user_email ) : '';
		$site_name    = sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$site_url     = esc_url_raw( home_url( '/' ) );
		$family       = sanitize_key( $params['conversation_family'] ?? '' );
		$surface      = sanitize_key( $params['closest_supported_surface'] ?? '' );
		$original     = sanitize_textarea_field( $params['original_command'] ?? '' );
		$capability   = sanitize_text_field( $params['unsupported_capability'] ?? '' );
		$family_label = self::format_developer_request_family_label( $family );
		$surface_label = self::format_developer_request_surface_label( $surface );
		$plugin_version = defined( 'YORO_AGENT_CORE_VERSION' ) ? sanitize_text_field( YORO_AGENT_CORE_VERSION ) : '';
		$wp_version     = function_exists( 'get_bloginfo' ) ? sanitize_text_field( get_bloginfo( 'version' ) ) : '';
		$wc_version     = defined( 'WC_VERSION' ) ? sanitize_text_field( WC_VERSION ) : '';

		if ( '' === $request_text ) {
			return new WP_Error(
				'yoro_empty_developer_request',
				__( 'Developer request text is required.', 'yoro-agent-core' )
			);
		}

		$subject = sprintf(
			/* translators: 1: family label, 2: site name */
			__( '[YoRo][%1$s] Developer request from %2$s', 'yoro-agent-core' ),
			$family_label,
			$site_name
		);

		$body_lines = [
			'YoRo developer request',
			'',
			'Requested capability / issue',
			'----------------------------',
			$request_text,
		];

		if ( '' !== $original ) {
			$body_lines[] = '';
			$body_lines[] = 'Original user wording';
			$body_lines[] = '---------------------';
			$body_lines[] = $original;
		}

		if ( '' !== $capability ) {
			$body_lines[] = '';
			$body_lines[] = 'Unsupported capability';
			$body_lines[] = '----------------------';
			$body_lines[] = $capability;
		}

		$body_lines[] = '';
		$body_lines[] = 'Context';
		$body_lines[] = '-------';
		$body_lines[] = 'Conversation family: ' . $family_label;
		$body_lines[] = 'Closest supported surface: ' . $surface_label;

		if ( '' !== $user_email ) {
			$body_lines[] = 'Requester email: ' . $user_email;
		}

		$body_lines[] = 'Requested by: ' . ( '' !== $user_name ? $user_name : 'Unknown user' );
		$body_lines[] = 'Submitted at: ' . current_time( 'mysql' );

		if ( $order_id > 0 ) {
			$body_lines[] = 'Order context: #' . $order_id;
			$body_lines[] = 'Order admin URL: ' . admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		}

		$body_lines[] = '';
		$body_lines[] = 'Environment';
		$body_lines[] = '-----------';
		$body_lines[] = 'Site: ' . $site_name;
		$body_lines[] = 'Site URL: ' . ( '' !== $site_url ? $site_url : 'unknown' );
		$body_lines[] = 'Plugin version: ' . ( '' !== $plugin_version ? $plugin_version : 'unknown' );
		$body_lines[] = 'WordPress version: ' . ( '' !== $wp_version ? $wp_version : 'unknown' );
		$body_lines[] = 'WooCommerce version: ' . ( '' !== $wc_version ? $wc_version : 'unknown' );
		$body_lines[] = 'User ID: ' . absint( $user_id );

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		if ( '' !== $user_email ) {
			$headers[] = 'Reply-To: ' . $user_name . ' <' . $user_email . '>';
		}

		$sent = wp_mail(
			'dev@yoohw.co',
			$subject,
			implode( "\n", $body_lines ),
			$headers
		);

		if ( ! $sent ) {
			return new WP_Error(
				'yoro_developer_request_send_failed',
				__( 'I could not send that developer request by email.', 'yoro-agent-core' )
			);
		}

		$suggestions = [];

		if ( $order_id > 0 ) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => sprintf( __( 'Show order #%d details', 'yoro-agent-core' ), $order_id ),
				'value' => 'Show order details for this order',
			];
		}

		$suggestions[] = [
			'type'  => 'command',
			'label' => __( 'Show morning briefing', 'yoro-agent-core' ),
			'value' => 'Show morning briefing',
		];
		$suggestions[] = [
			'type'  => 'command',
			'label' => __( 'Show operations attention queue', 'yoro-agent-core' ),
			'value' => 'Show operations attention queue',
		];

		return [
			'success'            => true,
			'summary'            => __( 'I sent that request to the developers.', 'yoro-agent-core' ),
			'message'            => __( 'I sent that request to the developers.', 'yoro-agent-core' ),
			'assistant_followup' => __( 'If you want, I can take you back to the order, queue, or briefing now.', 'yoro-agent-core' ),
			'result_type'        => 'generic',
			'display_type'       => 'generic',
			'count'              => 1,
			'data'               => [
				'developer_request' => [
					'request_text'              => $request_text,
					'original_command'          => $original,
					'unsupported_capability'    => $capability,
					'conversation_family'       => $family,
					'closest_supported_surface' => $surface,
					'context_order_id'          => $order_id,
					'submitted_to'              => 'dev@yoohw.co',
				],
			],
			'suggestions'        => array_slice( $suggestions, 0, 3 ),
		];
	}

	public static function preview_update_order_status( $params ) {
		return YoRo_Orders_Write_Service::preview_update_order_status( $params );
	}

	private static function format_developer_request_family_label( $family ) {
		$family = sanitize_key( $family );

		if ( '' === $family ) {
			return 'General';
		}

		return ucwords( str_replace( '_', ' ', $family ) );
	}

	private static function format_developer_request_surface_label( $surface ) {
		$surface = sanitize_key( $surface );

		if ( '' === $surface ) {
			return 'Unknown';
		}

		return ucwords( str_replace( '_', ' ', $surface ) );
	}

	public static function preview_bulk_update_status_with_note( $params ) {
		return YoRo_Orders_Write_Service::preview_bulk_update_status_with_note( $params );
	}

	public static function preview_get_customer_summary( $params ) {
		return YoRo_Orders_Read_Service::preview_get_customer_summary( $params );
	}

	public static function preview_get_customer_service_queue( $params ) {
		return YoRo_Orders_Read_Service::preview_get_customer_service_queue( $params );
	}

	public static function preview_find_orders_by_customer( $params ) {
		return YoRo_Orders_Read_Service::preview_find_orders_by_customer( $params );
	}

	public static function preview_find_orders_by_identity( $params ) {
		return YoRo_Orders_Read_Service::preview_find_orders_by_identity( $params );
	}

	public static function get_email_templates() {
		return YoRo_Orders_Email_Service::get_email_templates();
	}

	public static function save_email_templates( $templates ) {
		YoRo_Orders_Email_Service::save_email_templates( $templates );
	}

	public static function preview_add_private_note_template( $params ) {
		return YoRo_Orders_Write_Service::preview_add_private_note_template( $params );
	}

}
