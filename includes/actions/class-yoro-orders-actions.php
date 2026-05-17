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
				'ai_description'   => __( 'Search WooCommerce orders by status, customer, order number, payment method, country, total range, or date range.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show the latest 10 orders',
					'Show pending orders',
					'Find order #1234',
					'Xem 5 đơn mới nhất',
					'Tìm đơn #1234',
				],
				'required_params'     => [],
				'param_hints'         => [
					'status' => __( 'Use WooCommerce status slugs like pending, processing, completed, on-hold, cancelled, refunded, or failed.', 'yoro-agent-core' ),
					'limit'  => __( 'Use a small numeric limit for list requests such as 5, 10, or 20.', 'yoro-agent-core' ),
					'order_id' => __( 'Use the numeric WooCommerce order ID when the person references a specific order.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order they want when they ask for a specific order but do not provide an order number or reference.', 'yoro-agent-core' ),
				],
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
				'ai_description'   => __( 'Open the details for one or more specific WooCommerce orders.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show order #1234',
					'Open the details for order 1234',
					'Xem chi tiết đơn #1234',
				],
				'required_params'     => [ 'order_ids' ],
				'param_hints'         => [
					'order_ids' => __( 'Use one or more numeric WooCommerce order IDs.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_ids' => __( 'Ask which order they want when no specific order reference is available.', 'yoro-agent-core' ),
				],
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
				'ai_description'   => __( 'Show a read-only operations overview for a time window, including order flow, backlog, and current store pressure.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'How is the store doing today?',
					'Show operations summary for this week',
					'Tình hình vận hành hôm nay thế nào?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'window' => __( 'Normalize relative windows such as today, this week, or this month into store reporting windows.', 'yoro-agent-core' ),
					'focus'  => __( 'Use overview when the person wants a general operations summary.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [],
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
				'ai_description'   => __( 'Open the operations attention queue that highlights orders needing manual review or operational follow-up.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'What needs attention right now?',
					'Show the operations attention queue',
					'Which orders need manual review first?',
					'What looks urgent on the operations side?',
					'Có việc gì cần xử lý gấp không?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'limit' => __( 'Use a small numeric limit when the person asks for a shortlist.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [],
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
				'ai_description'   => __( 'Open the fulfillment queue for orders that may need shipping, tracking, or processing attention.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show fulfillment queue',
					'What orders are waiting to ship?',
					'Show me shipment blockers',
					'What looks stuck in shipping?',
					'Đơn nào đang chờ giao hàng?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'limit' => __( 'Use a small numeric limit when the person asks for a shortlist.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [],
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
				'ai_description'   => __( 'Open the inventory queue for products that may be low on stock, out of stock, or otherwise need inventory attention.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show inventory queue',
					'What products are low on stock?',
					'Sản phẩm nào cần chú ý tồn kho?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'limit' => __( 'Use a small numeric limit when the person asks for only the top inventory issues.', 'yoro-agent-core' ),
					'focus' => __( 'Use focus to narrow the queue to a specific inventory concern when the person names one.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [],
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
				'ai_description'   => __( 'Prepare the morning store briefing that combines recent performance with the main operational queues for today.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show morning briefing',
					'Brief me this morning',
					'Cho tôi briefing buổi sáng',
				],
				'required_params'     => [],
				'param_hints'         => [
					'window' => __( 'Use a short current window such as today unless the person explicitly asks for a different period.', 'yoro-agent-core' ),
					'yesterday_window' => __( 'Use a prior-day comparison window for morning briefings.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [],
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
				'ai_description'   => __( 'Prepare a store briefing or store snapshot for the requested time window across operations, customers, fulfillment, and inventory.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show today\'s briefing',
					'Give me a weekly brief',
					'Cho tôi xem briefing hôm nay',
				],
				'required_params'     => [],
				'param_hints'         => [
					'window' => __( 'Normalize windows like today, this week, or this month into supported reporting windows.', 'yoro-agent-core' ),
					'limit'  => __( 'Keep the result compact for briefing requests.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [],
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
				'ai_description'   => __( 'Show a business-health or business-performance summary for the requested time window, including revenue, customer activity, product signals, and overall commercial direction.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'How is our business doing this month?',
					'Show business summary for this week',
					'Tóm tắt tình hình kinh doanh tuần này',
					'Tình hình kinh doanh dạo này như thế nào?',
					'Tình hình kinh doanh tuần này thế nào?',
					'Cho tôi xem tình hình kinh doanh tuần này',
					'¿Cómo va el negocio esta semana?',
					'Muéstrame el resumen del negocio de esta semana',
					'¿Cómo está yendo el negocio últimamente?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'window' => __( 'Normalize relative reporting windows like this week or this month.', 'yoro-agent-core' ),
					'focus'  => __( 'Use overview unless the person explicitly narrows to one business dimension.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [],
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
				'ai_description'   => __( 'Show read-only store insights, trends, and signals for the requested reporting window.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show store insights for this week',
					'What patterns do you see this month?',
					'Cho tôi xem insight cửa hàng tuần này',
				],
				'required_params'     => [],
				'param_hints'         => [
					'window' => __( 'Normalize relative reporting windows like this week or this month.', 'yoro-agent-core' ),
					'limit'  => __( 'Keep insight lists compact unless the person asks for more.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [],
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
				'ai_description'   => __( 'Show a customer summary based on email or phone, including order history and recent activity.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show customer summary for john@example.com',
					'Find the customer with phone 555-1234',
					'Tóm tắt khách hàng john@example.com',
				],
				'required_params'     => [],
				'param_hints'         => [
					'email' => __( 'Use the customer email when it is explicitly mentioned.', 'yoro-agent-core' ),
					'phone' => __( 'Use the customer phone number when that is the clearest identifier.', 'yoro-agent-core' ),
					'limit' => __( 'Keep the result compact unless the person asks for a larger history.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'email' => __( 'Ask for the customer email or phone when the person wants a customer summary but provides no customer identifier.', 'yoro-agent-core' ),
					'phone' => __( 'Ask for the customer phone when there is no usable email or customer reference.', 'yoro-agent-core' ),
				],
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
				'ai_description'   => __( 'Open the customer-service queue for orders that likely need customer follow-up, without sending anything.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show customer service queue',
					'Who needs customer follow-up?',
					'Which customers need a reply?',
					'Who should we answer first today?',
					'Khách nào cần chăm sóc tiếp?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'limit' => __( 'Use a small numeric limit when the person asks for a shortlist.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [],
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
				'ai_description'   => __( 'Find WooCommerce orders for a customer using email, phone number, or customer ID.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show orders for john@example.com',
					'Find orders for customer 123',
					'Tìm đơn của khách john@example.com',
				],
				'required_params'     => [],
				'param_hints'         => [
					'email'       => __( 'Use the customer email when it is explicitly mentioned.', 'yoro-agent-core' ),
					'phone'       => __( 'Use the phone number when it is the clearest customer identifier.', 'yoro-agent-core' ),
					'customer_id' => __( 'Use the numeric customer ID when the person provides it directly.', 'yoro-agent-core' ),
					'limit'       => __( 'Use a small numeric limit for list requests.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'email' => __( 'Ask which customer they mean when there is no clear email, phone, or customer ID.', 'yoro-agent-core' ),
				],
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
				'ai_description'   => __( 'Find related orders that share identity signals with a specific order, such as email, phone, IP, or device identifiers.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Find related orders for order #1234',
					'Show identity matches for this order',
				],
				'required_params'     => [ 'order_id' ],
				'param_hints'         => [
					'order_id' => __( 'Use the reference order ID whose identity signals should be compared.', 'yoro-agent-core' ),
					'limit'    => __( 'Keep identity-match lists compact unless the person asks for more.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order should be used for identity matching when no target order is available.', 'yoro-agent-core' ),
				],
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
				'ai_description'   => __( 'Preview refund context for a specific order, including refundable amount and refunded amount, without changing store data.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Review refund for order #1234',
					'Show refund preview for this order',
				],
				'required_params'     => [ 'order_id' ],
				'param_hints'         => [
					'order_id' => __( 'Use the target order ID for refund review.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order should be reviewed for refund context when no order target is clear.', 'yoro-agent-core' ),
				],
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
				'ai_description'   => __( 'Preview a template-based customer email for a specific order without sending it.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Preview the shipping delay template for order #1234',
					'Show a customer email preview for this order',
				],
				'required_params'     => [ 'order_id', 'template' ],
				'param_hints'         => [
					'order_id' => __( 'Use the order ID the template email should reference.', 'yoro-agent-core' ),
					'template' => __( 'Use the template slug or template name the person wants to preview.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order the template preview is for when there is no clear order target.', 'yoro-agent-core' ),
					'template' => __( 'Ask which template should be previewed when the person wants a template email preview but does not name the template.', 'yoro-agent-core' ),
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
				'ai_description'        => __( 'Send a template-based customer email for a specific order after confirmation.', 'yoro-agent-core' ),
				'ai_examples'           => [
					'Send the shipping delay template for order #1234',
					'Send a template email to the customer for this order',
				],
				'required_params'       => [ 'order_id', 'template' ],
				'param_hints'           => [
					'order_id' => __( 'Use the order ID tied to the outgoing template email.', 'yoro-agent-core' ),
					'template' => __( 'Use the template slug or template name that should be sent.', 'yoro-agent-core' ),
				],
				'clarification_hints'   => [
					'order_id' => __( 'Ask which order should receive the template email when no order target is clear.', 'yoro-agent-core' ),
					'template' => __( 'Ask which email template should be sent when the person does not name one.', 'yoro-agent-core' ),
				],
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
				'ai_description'      => __( 'Save a reusable customer email template with a name, subject, and body.', 'yoro-agent-core' ),
				'ai_examples'         => [
					'Save this as template "shipping delay"',
					'Create an email template named payment follow-up',
				],
				'required_params'     => [ 'name', 'subject', 'body' ],
				'param_hints'         => [
					'name'    => __( 'Use a short reusable template name.', 'yoro-agent-core' ),
					'subject' => __( 'Use the reviewed email subject line.', 'yoro-agent-core' ),
					'body'    => __( 'Use the reviewed email body text.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'name'    => __( 'Ask what the template should be called when the person wants to save a template but gives no name.', 'yoro-agent-core' ),
					'subject' => __( 'Ask for the template subject when it is missing.', 'yoro-agent-core' ),
					'body'    => __( 'Ask for the template body when it is missing.', 'yoro-agent-core' ),
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
				'ai_description'      => __( 'List the reusable customer email templates available in the store.', 'yoro-agent-core' ),
				'ai_examples'         => [
					'Show saved email templates',
					'List customer email templates',
				],
				'required_params'     => [],
				'param_hints'         => [],
				'clarification_hints' => [],
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
				'ai_description'        => __( 'Delete a reusable customer email template after confirmation.', 'yoro-agent-core' ),
				'ai_examples'           => [
					'Delete the shipping delay template',
					'Remove template payment follow-up',
				],
				'required_params'       => [ 'template_id' ],
				'param_hints'           => [
					'template_id' => __( 'Use the saved template ID or slug.', 'yoro-agent-core' ),
				],
				'clarification_hints'   => [
					'template_id' => __( 'Ask which template should be deleted when the person does not identify one clearly.', 'yoro-agent-core' ),
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
				'ai_description'      => __( 'Apply a saved reusable email template to a specific order email context without sending it.', 'yoro-agent-core' ),
				'ai_examples'         => [
					'Use the shipping delay template for order #1234',
					'Apply template payment follow-up to this order',
				],
				'required_params'     => [ 'order_id', 'template_id' ],
				'param_hints'         => [
					'order_id'    => __( 'Use the target order ID.', 'yoro-agent-core' ),
					'template_id' => __( 'Use the saved template ID or slug.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_id'    => __( 'Ask which order should use the template when no order target is clear.', 'yoro-agent-core' ),
					'template_id' => __( 'Ask which saved template should be applied when the person does not identify one.', 'yoro-agent-core' ),
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
				'ai_description'   => __( 'Compose a customer email for a specific order from a natural-language goal, without sending it.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Write an email for order #1234 about a shipping delay',
					'Draft a payment confirmation email for order 1234',
					'Viết email cho đơn #1234 để báo chậm giao hàng',
				],
				'required_params'     => [ 'order_id', 'instruction' ],
				'param_hints'         => [
					'order_id'    => __( 'Use the specific order ID the email is about.', 'yoro-agent-core' ),
					'instruction' => __( 'Use the natural-language goal of the email, such as shipping delay, payment confirmation, or address verification.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_id'    => __( 'Ask which order the email is for when no specific order is available.', 'yoro-agent-core' ),
					'instruction' => __( 'Ask what the email should be about when the person names an order but does not provide the email goal.', 'yoro-agent-core' ),
				],
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
				'ai_description'   => __( 'Save a reviewed customer email draft for a specific order.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Save this email draft for order #1234',
					'Save the reviewed customer email draft',
				],
				'required_params'     => [ 'order_id', 'subject', 'body' ],
				'param_hints'         => [
					'order_id' => __( 'Use the order ID tied to the current draft.', 'yoro-agent-core' ),
					'subject'  => __( 'Use the reviewed draft subject line.', 'yoro-agent-core' ),
					'body'     => __( 'Use the reviewed draft body text.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order the draft belongs to when no draft context is active.', 'yoro-agent-core' ),
					'subject'  => __( 'Ask the person to review or regenerate the draft when subject is missing.', 'yoro-agent-core' ),
					'body'     => __( 'Ask the person to review or regenerate the draft when body is missing.', 'yoro-agent-core' ),
				],
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
				'ai_description'   => __( 'Load saved customer email drafts for a specific order.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Show email drafts for order #1234',
					'Load saved customer email drafts for this order',
				],
				'required_params'     => [ 'order_id' ],
				'param_hints'         => [
					'order_id' => __( 'Use the order ID tied to the saved drafts.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order the drafts belong to when no order target is available.', 'yoro-agent-core' ),
				],
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
				'ai_description'        => __( 'Update a saved customer email draft for a specific order.', 'yoro-agent-core' ),
				'ai_examples'           => [
					'Update this email draft',
					'Revise the saved draft for order #1234',
				],
				'required_params'       => [ 'order_id', 'draft_id', 'subject', 'body' ],
				'param_hints'           => [
					'order_id' => __( 'Use the target order ID for the draft.', 'yoro-agent-core' ),
					'draft_id' => __( 'Use the saved draft ID.', 'yoro-agent-core' ),
					'subject'  => __( 'Use the revised draft subject line.', 'yoro-agent-core' ),
					'body'     => __( 'Use the revised draft body text.', 'yoro-agent-core' ),
				],
				'clarification_hints'   => [
					'order_id' => __( 'Ask which order the draft belongs to when there is no active draft context.', 'yoro-agent-core' ),
					'draft_id' => __( 'Ask which draft should be updated when more than one draft exists or the draft is unclear.', 'yoro-agent-core' ),
				],
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
				'ai_description'        => __( 'Delete a saved customer email draft for a specific order after confirmation.', 'yoro-agent-core' ),
				'ai_examples'           => [
					'Delete this email draft',
					'Remove the saved draft for order #1234',
				],
				'required_params'       => [ 'order_id', 'draft_id' ],
				'param_hints'           => [
					'order_id' => __( 'Use the target order ID for the draft.', 'yoro-agent-core' ),
					'draft_id' => __( 'Use the saved draft ID.', 'yoro-agent-core' ),
				],
				'clarification_hints'   => [
					'order_id' => __( 'Ask which order the draft belongs to when there is no active draft context.', 'yoro-agent-core' ),
					'draft_id' => __( 'Ask which draft should be deleted when the draft is unclear.', 'yoro-agent-core' ),
				],
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
				'ai_description'   => __( 'Render a reviewed customer email preview for a specific order without sending it.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Preview this custom email for order #1234',
					'Render the reviewed customer email preview',
				],
				'required_params'     => [ 'order_id', 'subject', 'body' ],
				'param_hints'         => [
					'order_id' => __( 'Use the target order ID for the email preview.', 'yoro-agent-core' ),
					'subject'  => __( 'Use the reviewed email subject line.', 'yoro-agent-core' ),
					'body'     => __( 'Use the reviewed email body text.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order the preview belongs to when there is no active draft or order context.', 'yoro-agent-core' ),
					'subject'  => __( 'Ask for the reviewed subject if it is missing.', 'yoro-agent-core' ),
					'body'     => __( 'Ask for the reviewed body if it is missing.', 'yoro-agent-core' ),
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
				'ai_description'        => __( 'Send a reviewed custom customer email for a specific order after confirmation.', 'yoro-agent-core' ),
				'ai_examples'           => [
					'Send this email to the customer',
					'Send the reviewed email for order #1234',
					'Gửi email này cho khách',
				],
				'required_params'       => [ 'order_id', 'subject', 'body' ],
				'param_hints'           => [
					'order_id' => __( 'Use the specific order ID for the outgoing email.', 'yoro-agent-core' ),
					'subject'  => __( 'Use the reviewed subject line.', 'yoro-agent-core' ),
					'body'     => __( 'Use the reviewed body text.', 'yoro-agent-core' ),
				],
				'clarification_hints'   => [
					'order_id' => __( 'Ask which order the email belongs to when no active draft or order context is present.', 'yoro-agent-core' ),
					'subject'  => __( 'Ask the person to preview or review the email before sending when subject is missing.', 'yoro-agent-core' ),
					'body'     => __( 'Ask the person to preview or review the email before sending when body is missing.', 'yoro-agent-core' ),
				],
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
				'ai_description'   => __( 'Add a predefined internal note template to one or more orders.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Add the verification note template to order #1234',
					'Use the private note template for these orders',
					'Thêm mẫu ghi chú nội bộ vào đơn #1234',
				],
				'required_params'     => [ 'order_ids', 'template' ],
				'param_hints'         => [
					'order_ids' => __( 'Use one or more target order IDs resolved from the command or current context.', 'yoro-agent-core' ),
					'template'  => __( 'Use the template key or template name that matches the requested private note.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_ids' => __( 'Ask which order should receive the private note template when no order target is clear.', 'yoro-agent-core' ),
					'template'  => __( 'Ask which private note template to use when the person wants a template note but did not name one.', 'yoro-agent-core' ),
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
				'ai_description'      => __( 'Put one or more orders on hold after confirmation.', 'yoro-agent-core' ),
				'ai_examples'         => [
					'Put order #1234 on hold',
					'Hold this order',
					'Giữ đơn #1234',
				],
				'required_params'     => [ 'order_ids' ],
				'param_hints'         => [
					'order_ids' => __( 'Use one or more order IDs resolved from the command or conversation context.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_ids' => __( 'Ask which order should be put on hold when no clear order target is available.', 'yoro-agent-core' ),
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
				'ai_description'      => __( 'Add an internal order note for one or more orders.', 'yoro-agent-core' ),
				'ai_examples'         => [
					'Add note to order #1234: customer asked for a callback',
					'Add a note to this order',
					'Thêm ghi chú vào đơn #1234: khách muốn gọi lại',
				],
				'required_params'     => [ 'order_ids', 'note' ],
				'param_hints'         => [
					'order_ids' => __( 'Use one or more target order IDs.', 'yoro-agent-core' ),
					'note'      => __( 'Use the exact internal note text the person wants to save.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_ids' => __( 'Ask which order should receive the note when no target order is clear.', 'yoro-agent-core' ),
					'note'      => __( 'Ask what note should be saved when the person wants to add a note but did not provide the note text.', 'yoro-agent-core' ),
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
				'ai_description'        => __( 'Remove one or more YoRo-created internal order notes.', 'yoro-agent-core' ),
				'ai_examples'           => [
					'Remove the last note from this order',
					'Delete these internal notes',
					'Xóa ghi chú nội bộ này',
				],
				'required_params'       => [ 'note_ids' ],
				'param_hints'           => [
					'note_ids' => __( 'Use the exact note IDs from a prior action result or undo context.', 'yoro-agent-core' ),
				],
				'clarification_hints'   => [
					'note_ids' => __( 'Ask which note should be removed when there is no clear prior note target or undo context.', 'yoro-agent-core' ),
				],
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
				'ai_description'      => __( 'Update the status of one or more orders after confirmation.', 'yoro-agent-core' ),
				'ai_examples'         => [
					'Mark order #1234 as completed',
					'Update order #1234 to on hold',
					'Chuyển đơn #1234 sang hoàn tất',
				],
				'required_params'     => [ 'order_ids', 'status' ],
				'param_hints'         => [
					'order_ids' => __( 'Use one or more target order IDs.', 'yoro-agent-core' ),
					'status'    => __( 'Use WooCommerce status slugs like pending, processing, completed, on-hold, cancelled, refunded, or failed.', 'yoro-agent-core' ),
					'note'      => __( 'Use note only when the person explicitly wants a status update with an internal note.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_ids' => __( 'Ask which order should change status when no target order is clear.', 'yoro-agent-core' ),
					'status'    => __( 'Ask what status to apply when the person wants to change status but does not name the new status.', 'yoro-agent-core' ),
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
				'ai_exposed'            => false,
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
				'ai_description'      => __( 'Update the status of one or more orders and save an internal note in the same confirmed action.', 'yoro-agent-core' ),
				'ai_examples'         => [
					'Mark order #1234 as completed with note: packed and verified',
					'Update order #1234 to on hold with note: customer needs verification',
					'Chuyển đơn #1234 sang hoàn tất và thêm ghi chú: đã đóng gói',
				],
				'required_params'     => [ 'order_ids', 'status', 'note' ],
				'param_hints'         => [
					'order_ids' => __( 'Use one or more target order IDs.', 'yoro-agent-core' ),
					'status'    => __( 'Use a valid WooCommerce status slug.', 'yoro-agent-core' ),
					'note'      => __( 'Use the internal note text that should be saved alongside the status change.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'order_ids' => __( 'Ask which order should change status when no target order is clear.', 'yoro-agent-core' ),
					'status'    => __( 'Ask what status should be applied when it is missing.', 'yoro-agent-core' ),
					'note'      => __( 'Ask what note should be saved when the person wants a combined status-and-note action but did not provide the note text.', 'yoro-agent-core' ),
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
				'ai_description'   => __( 'Save an unsupported feature or integration request so developers can review it later.', 'yoro-agent-core' ),
				'ai_examples'      => [
					'Send this unsupported integration request to the developers',
					'Log this feature request for the dev team',
					'Gửi yêu cầu tính năng này cho đội phát triển',
				],
				'required_params'     => [ 'request_text' ],
				'param_hints'         => [
					'request_text'              => __( 'Use a short plain-language description of the unsupported feature or integration request.', 'yoro-agent-core' ),
					'original_command'          => __( 'Preserve the original user command when available for developer context.', 'yoro-agent-core' ),
					'unsupported_capability'    => __( 'Use the clearest unsupported capability label inferred from the conversation.', 'yoro-agent-core' ),
					'conversation_family'       => __( 'Use the unsupported-request family key when one was inferred.', 'yoro-agent-core' ),
					'closest_supported_surface' => __( 'Use the closest currently supported product surface when one is known.', 'yoro-agent-core' ),
					'context_order_id'          => __( 'Use the current order ID only when the unsupported request is tied to a specific order.', 'yoro-agent-core' ),
				],
				'clarification_hints' => [
					'request_text' => __( 'Ask what should be passed to the developers when the unsupported request text is missing.', 'yoro-agent-core' ),
				],
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
