<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Orders_Actions {

	public static function register() {
        YoRo_Action_Registry::register('products.update_details', [
            'label'=>'Edit product', 'description'=>'Edit supported fields of one published product.',
            'category'=>'products','permission'=>'yoro_execute_actions','callback'=>[YoRo_Product_Edit::class,'execute'],
            'preview_callback'=>[YoRo_Product_Edit::class,'preview'],'risk_level'=>'medium','write_action'=>true,
            'requires_confirmation'=>true,'effect'=>'mutate','reversibility'=>'none','target_type'=>'product','max_items'=>1,
            'input_schema'=>['product_id'=>'integer','changes'=>'object','expected_version'=>'string'],
            'canonical_input_schema'=>['product_id'=>['type'=>'integer','minimum'=>1],
                'changes'=>['type'=>'object','properties'=>YoRo_Product_Edit::properties(),'required'=>[],'additionalProperties'=>false],
                'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']],
            'required_params'=>['product_id','changes','expected_version'],'concurrency_strategy'=>'optimistic_version',
            'ai_description'=>'Edit the name, regular/sale price, SKU, global unique ID and stock settings for one product after explicit confirmation. Omitted fields are unchanged.',
            'capabilities'=>['products','inventory','write']]);
		YoRo_Action_Registry::register(
			'orders.search',
			[
				'label'       => __( 'Search orders', 'yoro-ai-core' ),
				'description' => __( 'Search store orders by status, limit, and date.', 'yoro-ai-core' ),
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
					'date_window'     => 'string',
					'time_window'     => 'object',
					'date_from'       => 'string',
					'date_to'         => 'string',
					'min_age_hours'   => 'integer',
					'order_id'        => 'integer',
					'customer_id'     => 'integer',
					'customer_name'   => 'string',
					'billing_country' => 'string',
					'shipping_state'  => 'string',
					'total_min'       => 'float',
					'total_max'       => 'float',
				],
				'preview_callback' => [ __CLASS__, 'preview_search_orders' ],
				'max_items'        => 50,
				'ai_description'   => __( 'Search store orders by status, customer, order number, payment method, country, total range, or date range.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Show the latest 10 orders',
					'Show pending orders',
					'Show pending orders this week',
					'Find order #1234',
					'Xem 5 đơn mới nhất',
					'Tuần này có đơn nào đang pending không?',
					'Tìm đơn #1234',
				],
				'required_params'     => [],
				'param_hints'         => [
					'status' => __( 'Use store order status slugs like pending, processing, completed, on-hold, cancelled, refunded, or failed.', 'yoro-ai-core' ),
					'limit'  => __( 'Use a small numeric limit for list requests such as 5, 10, or 20.', 'yoro-ai-core' ),
					'order_id' => __( 'Use the numeric store order ID when the person references a specific order.', 'yoro-ai-core' ),
					'date_window' => __( 'Use canonical reporting windows such as today, yesterday, this_week, last_week, this_month, last_month, this_year, last_year, last_N_days/weeks/months/years, month_YYYY_MM, quarter_YYYY_qN, year_YYYY, or range_YYYY_MM_DD_to_YYYY_MM_DD instead of guessing date_from/date_to.', 'yoro-ai-core' ),
					'time_window' => __( 'Structured AI date contract with canonical_key, source_text, timezone, granularity, and date_field. Mirror canonical_key into date_window.', 'yoro-ai-core' ),
					'min_age_hours' => __( 'Use when the person asks for orders older than a specific duration, such as more than 3 days.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order they want when they ask for a specific order but do not provide an order number or reference.', 'yoro-ai-core' ),
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
				'label'       => __( 'Get order details', 'yoro-ai-core' ),
				'description' => __( 'Get basic details for selected store orders.', 'yoro-ai-core' ),
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
				'ai_description'   => __( 'Open the details for one or more specific store orders.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Show order #1234',
					'Open the details for order 1234',
					'Xem chi tiết đơn #1234',
				],
				'required_params'     => [ 'order_ids' ],
				'param_hints'         => [
					'order_ids' => __( 'Use one or more numeric store order IDs.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_ids' => __( 'Ask which order they want when no specific order reference is available.', 'yoro-ai-core' ),
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
				'label'       => __( 'Get operations report', 'yoro-ai-core' ),
				'description' => __( 'Summarize the operations part of the store report: order flow, backlog, and aged queues.', 'yoro-ai-core' ),
				'category'    => 'operations',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_operations_summary' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'window' => 'string',
					'time_window' => 'object',
					'focus'  => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_operations_summary' ],
				'max_items'        => 1,
				'ai_description'   => __( 'Use as the operations provider for the store report when the person asks about order flow, backlog, aged queues, workload, or current store pressure.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'How is the store doing today?',
					'Show operations report for this week',
					'Tình hình vận hành hôm nay thế nào?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'window' => __( 'Use canonical reporting windows such as today, yesterday, this_week, last_week, this_month, last_month, this_year, last_year, last_N_days/weeks/months/years, month_YYYY_MM, quarter_YYYY_qN, year_YYYY, or range_YYYY_MM_DD_to_YYYY_MM_DD.', 'yoro-ai-core' ),
					'time_window' => __( 'Structured AI date contract with canonical_key, source_text, timezone, granularity, and date_field. Mirror canonical_key into window.', 'yoro-ai-core' ),
					'focus'  => __( 'Use overview when the person wants a general operations report.', 'yoro-ai-core' ),
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
				'label'       => __( 'Get operations attention queue', 'yoro-ai-core' ),
				'description' => __( 'List operational order queues that need store team attention, without fraud scoring.', 'yoro-ai-core' ),
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
				'ai_description'   => __( 'Open the operations attention queue that highlights orders needing manual review or operational follow-up.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'What needs attention right now?',
					'Show the operations attention queue',
					'Which orders need manual review first?',
					'What looks urgent on the operations side?',
					'Có việc gì cần xử lý gấp không?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'limit' => __( 'Use a small numeric limit when the person asks for a shortlist.', 'yoro-ai-core' ),
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
				'label'       => __( 'Get fulfillment queue', 'yoro-ai-core' ),
				'description' => __( 'List processing orders that may need fulfillment or shipping attention.', 'yoro-ai-core' ),
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
				'ai_description'   => __( 'Open the fulfillment queue for orders that may need shipping, tracking, or processing attention.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Show fulfillment queue',
					'What orders are waiting to ship?',
					'Show me shipment blockers',
					'What looks stuck in shipping?',
					'Đơn nào đang chờ giao hàng?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'limit' => __( 'Use a small numeric limit when the person asks for a shortlist.', 'yoro-ai-core' ),
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
				'label'       => __( 'Get inventory queue', 'yoro-ai-core' ),
				'description' => __( 'List store products that may need inventory attention.', 'yoro-ai-core' ),
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
				'ai_description'   => __( 'Open the inventory queue for products that may be low on stock, out of stock, or otherwise need inventory attention.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Show inventory queue',
					'What products are low on stock?',
					'Sản phẩm nào cần chú ý tồn kho?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'limit' => __( 'Use a small numeric limit when the person asks for only the top inventory issues.', 'yoro-ai-core' ),
					'focus' => __( 'Use focus to narrow the queue to a specific inventory concern when the person names one.', 'yoro-ai-core' ),
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
			'products.search',
			[
				'label'       => __( 'Search products', 'yoro-ai-core' ),
				'description' => __( 'Search WooCommerce products by text, SKU, type, status, or stock status.', 'yoro-ai-core' ),
				'category'    => 'products',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'search_products' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'query'        => 'string',
					'sku'          => 'string',
					'status'       => 'string',
					'type'         => 'string',
					'stock_status' => 'string',
					'limit'        => 'integer',
				],
				'max_items'    => 50,
				'ai_description' => __( 'Find products or variations from the connected store catalog without changing them.', 'yoro-ai-core' ),
				'required_params' => [],
				'capabilities' => [ 'products', 'read', 'search' ],
			]
		);

		YoRo_Action_Registry::register(
			'products.get_details',
			[
				'label'       => __( 'Get product details', 'yoro-ai-core' ),
				'description' => __( 'Read details for selected WooCommerce products or variations.', 'yoro-ai-core' ),
				'category'    => 'products',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_product_details' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [ 'product_ids' => 'array<int>' ],
				'max_items'   => 20,
				'ai_description' => __( 'Open one or more exact products or variations by their connector catalog IDs.', 'yoro-ai-core' ),
				'required_params' => [ 'product_ids' ],
				'capabilities' => [ 'products', 'read' ],
			]
		);

		YoRo_Action_Registry::register(
			'orders.get_timeline',
			[
				'label'       => __( 'Get order timeline', 'yoro-ai-core' ),
				'description' => __( 'Read status milestones and order notes for one order.', 'yoro-ai-core' ),
				'category'    => 'orders',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_order_timeline' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [ 'order_id' => 'integer', 'limit' => 'integer' ],
				'max_items'   => 50,
				'ai_description' => __( 'Review the timeline for one specific order, keeping internal notes distinct from customer-visible notes.', 'yoro-ai-core' ),
				'required_params' => [ 'order_id' ],
				'capabilities' => [ 'orders', 'read', 'timeline' ],
			]
		);

		YoRo_Action_Registry::register(
			'fulfillment.get_tracking',
			[
				'label'       => __( 'Get fulfillment tracking', 'yoro-ai-core' ),
				'description' => __( 'Read available shipment tracking data for one order.', 'yoro-ai-core' ),
				'category'    => 'fulfillment',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_fulfillment_tracking' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [ 'order_id' => 'integer' ],
				'max_items'   => 20,
				'ai_description' => __( 'Read carrier and tracking details already stored by the WooCommerce connector for one order.', 'yoro-ai-core' ),
				'required_params' => [ 'order_id' ],
				'capabilities' => [ 'orders', 'fulfillment', 'tracking', 'read' ],
			]
		);

		YoRo_Action_Registry::register(
			'products.update_stock',
			[
				'label'                 => __( 'Update product stock', 'yoro-ai-core' ),
				'description'           => __( 'Update the managed stock quantity for a specific product or variation.', 'yoro-ai-core' ),
				'category'              => 'products',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'update_product_stock' ],
				'risk_level'            => 'medium',
				'write_action'          => true,
				'requires_confirmation' => true,
				'input_schema'          => [
					'product_id'     => 'integer',
					'product_sku'    => 'string',
					'global_unique_id' => 'string',
					'stock_quantity' => 'integer',
					'expected_version' => 'string',
				],
				'canonical_input_schema' => [
					'product_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
					'product_sku'      => [ 'type' => 'string' ],
					'global_unique_id' => [ 'type' => 'string' ],
					'stock_quantity'   => [ 'type' => 'integer', 'minimum' => 0 ],
					'expected_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				],
				'concurrency_strategy' => 'optimistic_version',
				'product_identifier_types' => self::product_identifier_types(),
				'preview_callback'      => [ __CLASS__, 'preview_update_product_stock' ],
				'max_items'             => 1,
				'ai_description'        => __( 'Set the stock quantity for one specific WooCommerce product or variation. Variable parent products with parent stock management enabled use shared parent stock; specific variations can use their own stock quantity. This changes product inventory and must be confirmed before execution.', 'yoro-ai-core' ),
				'ai_examples'           => [
					'Set product #123 stock to 8',
					'Set variation #456 stock to 8',
					'Set SKU HOODIE-BLUE stock to 12',
					'Set GTIN 00012345678905 stock to 0',
					'Update stock for product 123 to 0',
					'Cập nhật tồn kho sản phẩm #123 thành 8',
				],
				'required_params'       => [ 'product_id', 'stock_quantity', 'expected_version' ],
				'param_hints'           => [
					'product_id'     => __( 'Use the specific WooCommerce product or variation ID when available.', 'yoro-ai-core' ),
					'product_sku'    => __( 'Use the specific product or variation SKU when the user identifies the target by SKU.', 'yoro-ai-core' ),
					'global_unique_id' => __( 'Use the specific product global unique ID, such as GTIN, UPC, EAN, or ISBN.', 'yoro-ai-core' ),
					'stock_quantity' => __( 'Use the target stock quantity as a non-negative integer.', 'yoro-ai-core' ),
					'expected_version' => __( 'Copy the opaque version token from the matching structured product context returned by the latest product read. Never invent or ask the person for this token.', 'yoro-ai-core' ),
				],
				'clarification_hints'   => [
					'product_id'     => __( 'Ask which product or variation should be updated when no target is clear. For variable products, ask for the variation unless the person explicitly wants shared parent stock.', 'yoro-ai-core' ),
					'stock_quantity' => __( 'Ask what stock quantity should be set when the target quantity is missing.', 'yoro-ai-core' ),
				],
				'capabilities'          => [
					'products',
					'inventory',
					'stock',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'products.update_stock_status',
			[
				'label'                 => __( 'Update product stock status', 'yoro-ai-core' ),
				'description'           => __( 'Update the manual stock status for a specific product or variation that does not manage stock quantity.', 'yoro-ai-core' ),
				'category'              => 'products',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'update_product_stock_status' ],
				'risk_level'            => 'medium',
				'write_action'          => true,
				'requires_confirmation' => true,
				'input_schema'          => [
					'product_id'       => 'integer',
					'product_sku'      => 'string',
					'global_unique_id' => 'string',
					'stock_status'     => 'string',
					'expected_version'  => 'string',
				],
				'canonical_input_schema' => [
					'product_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
					'product_sku'      => [ 'type' => 'string' ],
					'global_unique_id' => [ 'type' => 'string' ],
					'stock_status'     => [ 'type' => 'string' ],
					'expected_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				],
				'concurrency_strategy' => 'optimistic_version',
				'product_identifier_types' => self::product_identifier_types(),
				'preview_callback'      => [ __CLASS__, 'preview_update_product_stock_status' ],
				'max_items'             => 1,
				'ai_description'        => __( 'Set the manual stock status for one specific WooCommerce product or variation to one of the store configured stock statuses. Use this only when the user asks for stock status/availability, not a numeric stock quantity. Products or variations that manage stock quantity derive stock status from quantity instead.', 'yoro-ai-core' ),
				'ai_examples'           => [
					'Set product #123 stock status to out of stock',
					'Set variation #456 stock status to out of stock',
					'Set SKU HOODIE-BLUE stock status to out of stock',
					'Set GTIN 00012345678905 stock status to on backorder',
					'Mark product 123 as on backorder',
					'Cập nhật trạng thái tồn kho sản phẩm #123 thành hết hàng',
				],
				'required_params'       => [ 'product_id', 'stock_status', 'expected_version' ],
				'param_hints'           => [
					'product_id'       => __( 'Use the specific WooCommerce product or variation ID when available.', 'yoro-ai-core' ),
					'product_sku'      => __( 'Use the specific product or variation SKU when the user identifies the target by SKU.', 'yoro-ai-core' ),
					'global_unique_id' => __( 'Use the specific product global unique ID, such as GTIN, UPC, EAN, or ISBN.', 'yoro-ai-core' ),
					'stock_status'     => __( 'Use one of the store configured product stock status keys.', 'yoro-ai-core' ),
					'expected_version' => __( 'Copy the opaque version token from the matching structured product context returned by the latest product read. Never invent or ask the person for this token.', 'yoro-ai-core' ),
				],
				'clarification_hints'   => [
					'product_id'   => __( 'Ask which product or variation should be updated when no target is clear. For variable products, ask for the variation because parent stock status is derived from variation or shared stock behavior.', 'yoro-ai-core' ),
					'stock_status' => __( 'Ask which configured stock status should be applied when the status is missing.', 'yoro-ai-core' ),
				],
				'capabilities'          => [
					'products',
					'inventory',
					'stock_status',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'products.restore_stock_state',
			[
				'label'                 => __( 'Restore product stock state', 'yoro-ai-core' ),
				'description'           => __( 'Restore the saved stock state during a bounded YoRo undo.', 'yoro-ai-core' ),
				'category'              => 'products',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'restore_product_stock_state' ],
				'risk_level'            => 'medium',
				'write_action'          => true,
				'ai_exposed'            => false,
				'concurrency_strategy'  => 'optimistic_version',
				'input_schema'          => [
					'product_id'       => 'integer',
					'expected_version'  => 'string',
					'manage_stock'      => 'string|boolean',
					'stock_quantity'    => 'integer|null',
					'stock_status'      => 'string',
				],
				'canonical_input_schema' => [
					'product_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
					'expected_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
					'manage_stock'     => [ 'type' => [ 'string', 'boolean' ] ],
					'stock_quantity'   => [ 'type' => [ 'integer', 'null' ] ],
					'stock_status'     => [ 'type' => 'string' ],
				],
				'required_params'       => [ 'product_id', 'expected_version', 'manage_stock', 'stock_quantity', 'stock_status' ],
				'max_items'             => 1,
				'capabilities'          => [ 'products', 'inventory', 'write', 'undo' ],
			]
		);

		YoRo_Action_Registry::register(
			'coupons.lookup',
			[
				'label'            => __( 'Look up coupon', 'yoro-ai-core' ),
				'description'      => __( 'Look up a WooCommerce coupon by code or coupon ID.', 'yoro-ai-core' ),
				'category'         => 'coupons',
				'permission'       => 'manage_woocommerce',
				'callback'         => [ __CLASS__, 'lookup_coupon' ],
				'risk_level'       => 'low',
				'write_action'     => false,
				'input_schema'     => [
					'coupon_id'   => 'integer',
					'coupon_code' => 'string',
					'limit'       => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_lookup_coupon' ],
				'max_items'        => 1,
				'ai_description'   => __( 'Look up one specific WooCommerce coupon, discount code, promo code, or promotion by coupon code or coupon ID.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Look up coupon SUMMER10',
					'Check discount code SUMMER10',
					'Tra cứu mã giảm giá SUMMER10',
				],
				'required_params'     => [ 'coupon_code' ],
				'param_hints'         => [
					'coupon_code' => __( 'Use the exact coupon, discount, or promo code when provided.', 'yoro-ai-core' ),
					'coupon_id'   => __( 'Use the numeric WooCommerce coupon ID when provided.', 'yoro-ai-core' ),
					'limit'       => __( 'Keep coupon lookup to one coupon unless the catalog adds a list/search action.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'coupon_code' => __( 'Ask which coupon code should be looked up when no coupon code or coupon ID is provided.', 'yoro-ai-core' ),
				],
				'capabilities'        => [
					'coupons',
					'discounts',
					'read',
					'search',
				],
			]
		);

		YoRo_Action_Registry::register(
			'coupons.create',
			[
				'label'                 => __( 'Create coupon', 'yoro-ai-core' ),
				'description'           => __( 'Create a WooCommerce coupon with a code, discount type, and amount.', 'yoro-ai-core' ),
				'category'              => 'coupons',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'create_coupon' ],
				'risk_level'            => 'medium',
				'write_action'          => true,
				'concurrency_strategy'  => 'create_only',
				'requires_confirmation' => true,
				'input_schema'          => [
					'coupon_code'          => 'string',
					'discount_type'        => 'string',
					'discount_amount'      => 'number',
					'description'          => 'string',
					'expiry_date'          => 'string',
					'usage_limit'          => 'integer',
					'usage_limit_per_user' => 'integer',
					'individual_use'       => 'boolean',
					'free_shipping'        => 'boolean',
				],
				'preview_callback'      => [ __CLASS__, 'preview_create_coupon' ],
				'max_items'             => 1,
				'ai_description'        => __( 'Create one new WooCommerce coupon, discount code, promo code, or promotion. Requires coupon_code, discount_type, and discount_amount, and must be approved before execution.', 'yoro-ai-core' ),
				'ai_examples'           => [
					'Create coupon SUMMER10 for 10 percent off',
					'Create discount code VIP20 for 20% off',
					'Tạo mã giảm giá SUMMER10 giảm 10 phần trăm',
				],
				'required_params'       => [ 'coupon_code', 'discount_type', 'discount_amount' ],
				'param_hints'           => [
					'coupon_code'     => __( 'Use the exact coupon, discount, or promo code to create.', 'yoro-ai-core' ),
					'discount_type'   => __( 'Use percent, fixed_cart, or fixed_product.', 'yoro-ai-core' ),
					'discount_amount' => __( 'Use a positive numeric discount amount. Percent coupons cannot exceed 100.', 'yoro-ai-core' ),
				],
				'clarification_hints'   => [
					'coupon_code'     => __( 'Ask which coupon code should be created when the code is missing.', 'yoro-ai-core' ),
					'discount_type'   => __( 'Ask whether the discount is percent, fixed cart, or fixed product when the discount type is missing.', 'yoro-ai-core' ),
					'discount_amount' => __( 'Ask what discount amount should be used when the amount is missing.', 'yoro-ai-core' ),
				],
				'capabilities'          => [
					'coupons',
					'discounts',
					'promotions',
					'write',
				],
			]
		);

		YoRo_Action_Registry::register(
			'store.get_daily_briefing',
			[
				'label'       => __( 'Get store report overview', 'yoro-ai-core' ),
				'description' => __( 'Summarize the broad store report overview across operations, attention, fulfillment, customer follow-up, and inventory for the requested reporting window.', 'yoro-ai-core' ),
				'category'    => 'store',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_daily_briefing' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'window' => 'string',
					'time_window' => 'object',
					'limit'  => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_daily_briefing' ],
				'max_items'        => 1,
				'ai_description'   => __( 'Use as the overview provider for the store report when the person asks for a quick check-in, today store report, broad store snapshot, or what to look at now.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Show today\'s store report',
					'Give me a weekly store report',
					'Cho tôi xem báo cáo cửa hàng hôm nay',
				],
				'required_params'     => [],
				'param_hints'         => [
					'window' => __( 'Use canonical reporting windows such as today, yesterday, this_week, last_week, this_month, last_month, this_year, last_year, last_N_days/weeks/months/years, month_YYYY_MM, quarter_YYYY_qN, year_YYYY, or range_YYYY_MM_DD_to_YYYY_MM_DD.', 'yoro-ai-core' ),
					'time_window' => __( 'Structured AI date contract with canonical_key, source_text, timezone, granularity, and date_field. Mirror canonical_key into window.', 'yoro-ai-core' ),
					'limit'  => __( 'Keep the result compact for overview report requests.', 'yoro-ai-core' ),
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
				'label'       => __( 'Get sales report', 'yoro-ai-core' ),
				'description' => __( 'Summarize the sales part of the store report: revenue, orders, customer activity, product demand, and operating pressure.', 'yoro-ai-core' ),
				'category'    => 'store',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_business_summary' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'window' => 'string',
					'time_window' => 'object',
					'focus'  => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_business_summary' ],
				'max_items'        => 1,
				'ai_description'   => __( 'Use as the sales provider for the store report when the person asks about revenue, sales, business health, customer activity, product demand, or commercial direction.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Show sales report this month',
					'Show sales report for this week',
					'Cho tôi xem báo cáo doanh thu tuần này',
					'Doanh thu 4 ngày qua thế nào?',
					'¿Cómo van las ventas esta semana?',
					'Muéstrame el reporte de ventas de esta semana',
				],
				'required_params'     => [],
				'param_hints'         => [
					'window' => __( 'Use calendar windows like this_week, this_month, last_year, month_YYYY_MM, quarter_YYYY_qN, year_YYYY, or range_YYYY_MM_DD_to_YYYY_MM_DD when the person names a calendar/from-to period; use rolling windows like last_N_days/weeks/months/years for custom recent ranges.', 'yoro-ai-core' ),
					'time_window' => __( 'Structured AI date contract with canonical_key, source_text, timezone, granularity, and date_field. Mirror canonical_key into window.', 'yoro-ai-core' ),
					'focus'  => __( 'Use overview unless the person explicitly narrows to one business dimension.', 'yoro-ai-core' ),
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
				'label'       => __( 'Get store signal report', 'yoro-ai-core' ),
				'description' => __( 'Summarize the signals part of the store report across orders, customers, products, and inventory.', 'yoro-ai-core' ),
				'category'    => 'store',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_store_insights' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'window' => 'string',
					'time_window' => 'object',
					'limit'  => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_store_insights' ],
				'max_items'        => 50,
				'ai_description'   => __( 'Use as the signal provider for the store report when the person asks for insights, trends, patterns, signals, or a grouped read across orders, customers, and products.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Show store signals for this week',
					'What patterns do you see this month?',
					'Cho tôi xem tín hiệu cửa hàng tuần này',
				],
				'required_params'     => [],
				'param_hints'         => [
					'window' => __( 'Use calendar windows like this_week, this_month, last_year, month_YYYY_MM, quarter_YYYY_qN, year_YYYY, or range_YYYY_MM_DD_to_YYYY_MM_DD when the person names a calendar/from-to period; use rolling windows like last_N_days/weeks/months/years for custom recent ranges.', 'yoro-ai-core' ),
					'time_window' => __( 'Structured AI date contract with canonical_key, source_text, timezone, granularity, and date_field. Mirror canonical_key into window.', 'yoro-ai-core' ),
					'limit'  => __( 'Keep insight lists compact unless the person asks for more.', 'yoro-ai-core' ),
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
			'customers.lookup',
			[
				'label'       => __( 'Look up customer', 'yoro-ai-core' ),
				'description' => __( 'Look up a customer profile by email, phone, customer ID, or name.', 'yoro-ai-core' ),
				'category'    => 'customers',
				'permission'  => 'manage_woocommerce',
				'callback'    => [ __CLASS__, 'get_customer_summary' ],
				'risk_level'  => 'low',
				'write_action'=> false,
				'input_schema'=> [
					'email'         => 'string',
					'phone'         => 'string',
					'customer_id'   => 'integer',
					'customer_name' => 'string',
					'order_id'      => 'integer',
					'limit'         => 'integer',
				],
				'preview_callback' => [ __CLASS__, 'preview_get_customer_summary' ],
				'max_items'        => 50,
				'ai_description'   => __( 'Look up one customer profile by email, phone, customer ID, or name, including compact order history and recent activity.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Look up customer john@example.com',
					'Find the customer with phone 555-1234',
					'Tra cứu khách hàng john@example.com',
				],
				'required_params'     => [],
				'param_hints'         => [
					'email'         => __( 'Use the customer email when it is explicitly mentioned.', 'yoro-ai-core' ),
					'phone'         => __( 'Use the customer phone number when that is the clearest identifier.', 'yoro-ai-core' ),
					'customer_id'   => __( 'Use the customer ID when it is explicitly mentioned.', 'yoro-ai-core' ),
					'customer_name' => __( 'Use the customer name when no stronger identifier is provided.', 'yoro-ai-core' ),
					'order_id'      => __( 'Use the explicit order ID to resolve the customer through the same frozen workflow capability.', 'yoro-ai-core' ),
					'limit'         => __( 'Keep the result compact unless the person asks for a larger history.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'email' => __( 'Ask for a customer email, phone, ID, or name when the person wants a customer lookup but provides no customer identifier.', 'yoro-ai-core' ),
					'phone' => __( 'Ask for the customer phone when there is no usable email, ID, name, or customer reference.', 'yoro-ai-core' ),
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
				'label'       => __( 'Get customer service queue', 'yoro-ai-core' ),
				'description' => __( 'List store orders that may need customer follow-up, without sending messages.', 'yoro-ai-core' ),
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
				'ai_description'   => __( 'Open the customer-service queue for orders that likely need customer follow-up, without sending anything.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Show customer service queue',
					'Who needs customer follow-up?',
					'Which customers need a reply?',
					'Who should we answer first today?',
					'Khách nào cần chăm sóc tiếp?',
				],
				'required_params'     => [],
				'param_hints'         => [
					'limit' => __( 'Use a small numeric limit when the person asks for a shortlist.', 'yoro-ai-core' ),
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
				'label'       => __( 'Find orders by customer', 'yoro-ai-core' ),
				'description' => __( 'Find store orders by customer email, phone, customer ID, or customer name.', 'yoro-ai-core' ),
				'category'    => 'orders',
				'permission'  => 'manage_woocommerce',
					'callback'    => [ __CLASS__, 'find_orders_by_customer' ],
					'risk_level'  => 'low',
					'write_action'=> false,
					'input_schema'=> [
						'email'         => 'string',
						'phone'         => 'string',
						'customer_id'   => 'integer',
						'customer_name' => 'string',
						'order_id'      => 'integer',
						'limit'         => 'integer',
					],
					'preview_callback' => [ __CLASS__, 'preview_find_orders_by_customer' ],
					'max_items'        => 50,
					'ai_description'   => __( 'Find store orders for a customer using email, phone number, customer ID, or customer name.', 'yoro-ai-core' ),
					'ai_examples'      => [
						'Show orders for john@example.com',
						'Find orders for customer 123',
						'Show orders for customer John Doe',
						'Tìm đơn của khách john@example.com',
					],
					'required_params'     => [],
					'param_hints'         => [
						'email'         => __( 'Use the customer email when it is explicitly mentioned.', 'yoro-ai-core' ),
						'phone'         => __( 'Use the phone number when it is the clearest customer identifier.', 'yoro-ai-core' ),
						'customer_id'   => __( 'Use the numeric customer ID when the person provides it directly.', 'yoro-ai-core' ),
						'customer_name' => __( 'Use the customer name when the person provides a name instead of an email, phone, or customer ID.', 'yoro-ai-core' ),
						'order_id'      => __( 'Use the explicit order ID to resolve customer identity within this read-only action.', 'yoro-ai-core' ),
						'limit'         => __( 'Use a small numeric limit for list requests.', 'yoro-ai-core' ),
					],
					'clarification_hints' => [
						'email' => __( 'Ask which customer they mean when there is no clear email, phone, customer ID, or customer name.', 'yoro-ai-core' ),
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
				'label'       => __( 'Find orders by identity', 'yoro-ai-core' ),
				'description' => __( 'Find store orders sharing email, phone, IP, or device identifier.', 'yoro-ai-core' ),
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
				'ai_description'   => __( 'Find related orders that share identity signals with a specific order, such as email, phone, IP, or device identifiers.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Find related orders for order #1234',
					'Show identity matches for this order',
				],
				'required_params'     => [ 'order_id' ],
				'param_hints'         => [
					'order_id' => __( 'Use the reference order ID whose identity signals should be compared.', 'yoro-ai-core' ),
					'limit'    => __( 'Keep identity-match lists compact unless the person asks for more.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order should be used for identity matching when no target order is available.', 'yoro-ai-core' ),
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
				'label'            => __( 'Refund preview', 'yoro-ai-core' ),
				'description'      => __( 'Preview refundable amount, refunded amount, and line item refund context for a store order.', 'yoro-ai-core' ),
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
				'ai_description'   => __( 'Preview refund context for a specific order, including refundable amount and refunded amount, without changing store data.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Review refund for order #1234',
					'Show refund preview for this order',
				],
				'required_params'     => [ 'order_id' ],
				'param_hints'         => [
					'order_id' => __( 'Use the target order ID for refund review.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order should be reviewed for refund context when no order target is clear.', 'yoro-ai-core' ),
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
				'label'            => __( 'Preview customer email', 'yoro-ai-core' ),
				'description'      => __( 'Preview a customer email generated from an order template without sending it.', 'yoro-ai-core' ),
				'category'         => 'orders',
				'permission'       => 'manage_woocommerce',
				'callback'         => [ __CLASS__, 'email_customer_preview' ],
				'risk_level'       => 'low',
				'write_action'     => false,
				'ai_exposed'       => false,
				'input_schema'     => [
					'order_id' => 'integer',
					'template' => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_email_customer_preview' ],
				'max_items'        => 1,
				'ai_description'   => __( 'Preview a template-based customer email for a specific order without sending it.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Preview the shipping delay template for order #1234',
					'Show a customer email preview for this order',
				],
				'required_params'     => [ 'order_id', 'template' ],
				'param_hints'         => [
					'order_id' => __( 'Use the order ID the template email should reference.', 'yoro-ai-core' ),
					'template' => __( 'Use the template slug or template name the person wants to preview.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order the template preview is for when there is no clear order target.', 'yoro-ai-core' ),
					'template' => __( 'Ask which template should be previewed when the person wants a template email preview but does not name the template.', 'yoro-ai-core' ),
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
				'label'                 => __( 'Send customer email', 'yoro-ai-core' ),
				'description'           => __( 'Send a customer email generated from an order template.', 'yoro-ai-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'email_customer_template' ],
				'requires_confirmation' => true,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'ai_exposed'            => false,
				'input_schema'          => [
					'order_id' => 'integer',
					'template' => 'string',
				],
				'preview_callback'      => [ __CLASS__, 'preview_email_customer_template' ],
				'max_items'             => 1,
				'ai_description'        => __( 'Send a template-based customer email for a specific order after confirmation.', 'yoro-ai-core' ),
				'ai_examples'           => [
					'Send the shipping delay template for order #1234',
					'Send a template email to the customer for this order',
				],
				'required_params'       => [ 'order_id', 'template' ],
				'param_hints'           => [
					'order_id' => __( 'Use the order ID tied to the outgoing template email.', 'yoro-ai-core' ),
					'template' => __( 'Use the template slug or template name that should be sent.', 'yoro-ai-core' ),
				],
				'clarification_hints'   => [
					'order_id' => __( 'Ask which order should receive the template email when no order target is clear.', 'yoro-ai-core' ),
					'template' => __( 'Ask which email template should be sent when the person does not name one.', 'yoro-ai-core' ),
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
				'label'        => __( 'Save email template', 'yoro-ai-core' ),
				'description'  => __( 'Save a reusable customer email template.', 'yoro-ai-core' ),
				'category'     => 'email_templates',
				'permission'   => 'manage_woocommerce',
				'callback'     => [ __CLASS__, 'save_email_template' ],
				'write_action' => true,
				'input_schema' => [
					'name'    => 'string',
					'subject' => 'string',
					'body'    => 'string',
				],
				'preview_callback'    => [ __CLASS__, 'preview_save_email_template' ],
				'ai_description'      => __( 'Save a reusable customer email template with a name, subject, and body.', 'yoro-ai-core' ),
				'ai_examples'         => [
					'Save this as template "shipping delay"',
					'Create an email template named payment follow-up',
				],
				'required_params'     => [ 'name', 'subject', 'body' ],
				'param_hints'         => [
					'name'    => __( 'Use a short reusable template name.', 'yoro-ai-core' ),
					'subject' => __( 'Use the reviewed email subject line.', 'yoro-ai-core' ),
					'body'    => __( 'Use the reviewed email body text.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'name'    => __( 'Ask what the template should be called when the person wants to save a template but gives no name.', 'yoro-ai-core' ),
					'subject' => __( 'Ask for the template subject when it is missing.', 'yoro-ai-core' ),
					'body'    => __( 'Ask for the template body when it is missing.', 'yoro-ai-core' ),
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
				'label'        => __( 'List email templates', 'yoro-ai-core' ),
				'description'  => __( 'Get all reusable customer email templates.', 'yoro-ai-core' ),
				'category'     => 'email_templates',
				'permission'   => 'manage_woocommerce',
				'callback'     => [ __CLASS__, 'list_email_templates' ],
				'write_action' => false,
				'ai_description'      => __( 'List the reusable customer email templates available in the store.', 'yoro-ai-core' ),
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
				'label'                 => __( 'Delete email template', 'yoro-ai-core' ),
				'description'           => __( 'Delete a reusable email template.', 'yoro-ai-core' ),
				'category'              => 'email_templates',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'delete_email_template' ],
				'write_action'          => true,
				'requires_confirmation' => true,
				'input_schema'          => [
					'template_id' => 'string',
				],
				'preview_callback'      => [ __CLASS__, 'preview_delete_email_template' ],
				'ai_description'        => __( 'Delete a reusable customer email template after confirmation.', 'yoro-ai-core' ),
				'ai_examples'           => [
					'Delete the shipping delay template',
					'Remove template payment follow-up',
				],
				'required_params'       => [ 'template_id' ],
				'param_hints'           => [
					'template_id' => __( 'Use the saved template ID or slug.', 'yoro-ai-core' ),
				],
				'clarification_hints'   => [
					'template_id' => __( 'Ask which template should be deleted when the person does not identify one clearly.', 'yoro-ai-core' ),
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
				'label'        => __( 'Use email template', 'yoro-ai-core' ),
				'description'  => __( 'Apply a reusable template to an order email.', 'yoro-ai-core' ),
				'category'     => 'orders',
				'permission'   => 'manage_woocommerce',
				'callback'     => [ __CLASS__, 'use_email_template' ],
				'write_action' => false,
				'ai_exposed'   => false,
				'input_schema' => [
					'order_id'    => 'integer',
					'template_id' => 'string',
				],
				'ai_description'      => __( 'Apply a saved reusable email template to a specific order email context without sending it.', 'yoro-ai-core' ),
				'ai_examples'         => [
					'Use the shipping delay template for order #1234',
					'Apply template payment follow-up to this order',
				],
				'required_params'     => [ 'order_id', 'template_id' ],
				'param_hints'         => [
					'order_id'    => __( 'Use the target order ID.', 'yoro-ai-core' ),
					'template_id' => __( 'Use the saved template ID or slug.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_id'    => __( 'Ask which order should use the template when no order target is clear.', 'yoro-ai-core' ),
					'template_id' => __( 'Ask which saved template should be applied when the person does not identify one.', 'yoro-ai-core' ),
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
				'label'            => __( 'Compose customer email', 'yoro-ai-core' ),
				'description'      => __( 'Use AI to compose a customer email from order context without sending it.', 'yoro-ai-core' ),
				'category'         => 'orders',
				'permission'       => 'manage_woocommerce',
				'callback'         => [ __CLASS__, 'compose_customer_email' ],
				'risk_level'       => 'low',
				'write_action'     => false,
				'input_schema'     => [
					'order_id'     => 'integer',
					'instruction'  => 'string',
					'email_intent' => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_compose_customer_email' ],
				'max_items'        => 1,
				'ai_description'   => __( 'Compose a customer email for a specific order from a natural-language goal, without sending it.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Write an email for order #1234 about a shipping delay',
					'Draft a payment confirmation email for order 1234',
					'Viết email cho đơn #1234 để báo chậm giao hàng',
				],
				'required_params'     => [ 'order_id', 'instruction' ],
				'param_hints'         => [
					'order_id'    => __( 'Use the specific order ID the email is about.', 'yoro-ai-core' ),
					'instruction' => __( 'Use the natural-language goal of the email, such as shipping delay, payment confirmation, or address verification.', 'yoro-ai-core' ),
					'email_intent' => __( 'Optional stable semantic key for validation, such as shipping_update, payment_review, refund_notice, status_update, or general_customer_message.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_id'    => __( 'Ask which order the email is for when no specific order is available.', 'yoro-ai-core' ),
					'instruction' => __( 'Ask what the email should be about when the person names an order but does not provide the email goal.', 'yoro-ai-core' ),
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
				'label'            => __( 'Save customer email draft', 'yoro-ai-core' ),
				'description'      => __( 'Save a reviewed customer email draft to the order.', 'yoro-ai-core' ),
				'category'         => 'orders',
				'permission'       => 'manage_woocommerce',
				'callback'         => [ __CLASS__, 'save_customer_email_draft' ],
				'risk_level'       => 'low',
				'write_action'     => true,
				'input_schema'     => [
					'order_id'     => 'integer',
					'subject'      => 'string',
					'body'         => 'string',
					'email_intent' => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_save_customer_email_draft' ],
				'max_items'        => 1,
				'ai_description'   => __( 'Save a reviewed customer email draft for a specific order.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Save this email draft for order #1234',
					'Save the reviewed customer email draft',
				],
				'required_params'     => [ 'order_id', 'subject', 'body' ],
				'param_hints'         => [
					'order_id' => __( 'Use the order ID tied to the current draft.', 'yoro-ai-core' ),
					'subject'  => __( 'Use the reviewed draft subject line.', 'yoro-ai-core' ),
					'body'     => __( 'Use the reviewed draft body text.', 'yoro-ai-core' ),
					'email_intent' => __( 'Optional stable semantic key carried from the composed email when known.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order the draft belongs to when no draft context is active.', 'yoro-ai-core' ),
					'subject'  => __( 'Ask the person to review or regenerate the draft when subject is missing.', 'yoro-ai-core' ),
					'body'     => __( 'Ask the person to review or regenerate the draft when body is missing.', 'yoro-ai-core' ),
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
				'label'            => __( 'Get customer email drafts', 'yoro-ai-core' ),
				'description'      => __( 'Load saved customer email drafts for an order.', 'yoro-ai-core' ),
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
				'ai_description'   => __( 'Load saved customer email drafts for a specific order.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Show email drafts for order #1234',
					'Load saved customer email drafts for this order',
				],
				'required_params'     => [ 'order_id' ],
				'param_hints'         => [
					'order_id' => __( 'Use the order ID tied to the saved drafts.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order the drafts belong to when no order target is available.', 'yoro-ai-core' ),
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
				'label'                 => __( 'Update customer email draft', 'yoro-ai-core' ),
				'description'           => __( 'Update an existing customer email draft for an order.', 'yoro-ai-core' ),
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
				'ai_description'        => __( 'Update a saved customer email draft for a specific order.', 'yoro-ai-core' ),
				'ai_examples'           => [
					'Update this email draft',
					'Revise the saved draft for order #1234',
				],
				'required_params'       => [ 'order_id', 'draft_id', 'subject', 'body' ],
				'param_hints'           => [
					'order_id' => __( 'Use the target order ID for the draft.', 'yoro-ai-core' ),
					'draft_id' => __( 'Use the saved draft ID.', 'yoro-ai-core' ),
					'subject'  => __( 'Use the revised draft subject line.', 'yoro-ai-core' ),
					'body'     => __( 'Use the revised draft body text.', 'yoro-ai-core' ),
				],
				'clarification_hints'   => [
					'order_id' => __( 'Ask which order the draft belongs to when there is no active draft context.', 'yoro-ai-core' ),
					'draft_id' => __( 'Ask which draft should be updated when more than one draft exists or the draft is unclear.', 'yoro-ai-core' ),
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
				'label'                 => __( 'Delete customer email draft', 'yoro-ai-core' ),
				'description'           => __( 'Delete a saved customer email draft from an order.', 'yoro-ai-core' ),
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
				'ai_description'        => __( 'Delete a saved customer email draft for a specific order after confirmation.', 'yoro-ai-core' ),
				'ai_examples'           => [
					'Delete this email draft',
					'Remove the saved draft for order #1234',
				],
				'required_params'       => [ 'order_id', 'draft_id' ],
				'param_hints'           => [
					'order_id' => __( 'Use the target order ID for the draft.', 'yoro-ai-core' ),
					'draft_id' => __( 'Use the saved draft ID.', 'yoro-ai-core' ),
				],
				'clarification_hints'   => [
					'order_id' => __( 'Ask which order the draft belongs to when there is no active draft context.', 'yoro-ai-core' ),
					'draft_id' => __( 'Ask which draft should be deleted when the draft is unclear.', 'yoro-ai-core' ),
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
				'label'            => __( 'Render customer email preview', 'yoro-ai-core' ),
				'description'      => __( 'Render a store-styled customer email preview from reviewed subject and body.', 'yoro-ai-core' ),
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
				'ai_description'   => __( 'Render a reviewed customer email preview for a specific order without sending it.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Preview this custom email for order #1234',
					'Render the reviewed customer email preview',
				],
				'required_params'     => [ 'order_id', 'subject', 'body' ],
				'param_hints'         => [
					'order_id' => __( 'Use the target order ID for the email preview.', 'yoro-ai-core' ),
					'subject'  => __( 'Use the reviewed email subject line.', 'yoro-ai-core' ),
					'body'     => __( 'Use the reviewed email body text.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_id' => __( 'Ask which order the preview belongs to when there is no active draft or order context.', 'yoro-ai-core' ),
					'subject'  => __( 'Ask for the reviewed subject if it is missing.', 'yoro-ai-core' ),
					'body'     => __( 'Ask for the reviewed body if it is missing.', 'yoro-ai-core' ),
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
				'label'                 => __( 'Send customer email', 'yoro-ai-core' ),
				'description'           => __( 'Send a reviewed custom email to the customer for an order.', 'yoro-ai-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'send_customer_email' ],
				'requires_confirmation' => true,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'concurrency_strategy'  => 'external_delivery',
				'input_schema'          => [
					'order_id'     => 'integer',
					'subject'      => 'string',
					'body'         => 'string',
					'email_intent' => 'string',
				],
				'preview_callback'      => [ __CLASS__, 'preview_send_customer_email' ],
				'max_items'             => 1,
				'ai_description'        => __( 'Send a reviewed custom customer email for a specific order after confirmation.', 'yoro-ai-core' ),
				'ai_examples'           => [
					'Send this email to the customer',
					'Send the reviewed email for order #1234',
					'Gửi email này cho khách',
				],
				'required_params'       => [ 'order_id', 'subject', 'body' ],
				'param_hints'           => [
					'order_id' => __( 'Use the specific order ID for the outgoing email.', 'yoro-ai-core' ),
					'subject'  => __( 'Use the reviewed subject line.', 'yoro-ai-core' ),
					'body'     => __( 'Use the reviewed body text.', 'yoro-ai-core' ),
					'email_intent' => __( 'Optional stable semantic key carried from the composed email when known.', 'yoro-ai-core' ),
				],
				'clarification_hints'   => [
					'order_id' => __( 'Ask which order the email belongs to when no active draft or order context is present.', 'yoro-ai-core' ),
					'subject'  => __( 'Ask the person to preview or review the email before sending when subject is missing.', 'yoro-ai-core' ),
					'body'     => __( 'Ask the person to preview or review the email before sending when body is missing.', 'yoro-ai-core' ),
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
				'label'                 => __( 'Add private note template', 'yoro-ai-core' ),
				'description'           => __( 'Add a predefined internal note to a store order.', 'yoro-ai-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'add_private_note_template' ],
				'requires_confirmation' => false,
				'risk_level'            => 'low',
				'write_action'          => true,
				'ai_exposed'            => false,
				'input_schema'          => [
					'order_id'  => 'integer',
					'order_ids' => 'array<int>',
					'template'  => 'string',
				],
				'preview_callback' => [ __CLASS__, 'preview_add_private_note_template' ],
				'max_items'        => 20,
				'ai_description'   => __( 'Add a predefined internal note template to one or more orders.', 'yoro-ai-core' ),
				'ai_examples'      => [
					'Add the verification note template to order #1234',
					'Use the private note template for these orders',
					'Thêm mẫu ghi chú nội bộ vào đơn #1234',
				],
				'required_params'     => [ 'order_ids', 'template' ],
				'param_hints'         => [
					'order_ids' => __( 'Use one or more target order IDs resolved from the command or current context.', 'yoro-ai-core' ),
					'template'  => __( 'Use the template key or template name that matches the requested private note.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_ids' => __( 'Ask which order should receive the private note template when no order target is clear.', 'yoro-ai-core' ),
					'template'  => __( 'Ask which private note template to use when the person wants a template note but did not name one.', 'yoro-ai-core' ),
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
			'orders.add_note',
			[
				'label'                 => __( 'Add order note', 'yoro-ai-core' ),
				'description'           => __( 'Add an internal note to a store order.', 'yoro-ai-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'add_order_note' ],
				'requires_confirmation' => false,
				'risk_level'            => 'low',
				'write_action'          => true,
				'concurrency_strategy'  => 'append_only',
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
				'ai_description'      => __( 'Add an internal order note for one or more orders.', 'yoro-ai-core' ),
				'ai_examples'         => [
					'Add note to order #1234: customer asked for a callback',
					'Add a note to this order',
					'Thêm ghi chú vào đơn #1234: khách muốn gọi lại',
				],
				'required_params'     => [ 'order_ids', 'note' ],
				'param_hints'         => [
					'order_ids' => __( 'Use one or more target order IDs.', 'yoro-ai-core' ),
					'note'      => __( 'Use the exact internal note text the person wants to save.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_ids' => __( 'Ask which order should receive the note when no target order is clear.', 'yoro-ai-core' ),
					'note'      => __( 'Ask what note should be saved when the person wants to add a note but did not provide the note text.', 'yoro-ai-core' ),
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.remove_note',
			[
				'label'                 => __( 'Remove order note', 'yoro-ai-core' ),
				'description'           => __( 'Remove YoRo-created internal notes from store orders.', 'yoro-ai-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'remove_order_note' ],
				'requires_confirmation' => false,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'input_schema'          => [
					'note_ids' => 'array<int>',
				],
				'preview_callback'      => [ __CLASS__, 'preview_remove_order_note' ],
				'max_items'             => 20,
				'ai_description'        => __( 'Remove one or more YoRo-created internal order notes.', 'yoro-ai-core' ),
				'ai_examples'           => [
					'Remove the last note from this order',
					'Delete these internal notes',
					'Xóa ghi chú nội bộ này',
				],
				'required_params'       => [ 'note_ids' ],
				'param_hints'           => [
					'note_ids' => __( 'Use the exact note IDs from a prior action result or undo context.', 'yoro-ai-core' ),
				],
				'clarification_hints'   => [
					'note_ids' => __( 'Ask which note should be removed when there is no clear prior note target or undo context.', 'yoro-ai-core' ),
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
				'label'                 => __( 'Update order status', 'yoro-ai-core' ),
				'description'           => __( 'Change selected store orders to a specific status.', 'yoro-ai-core' ),
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
					'expected_versions' => 'array<object>',
				],
				'canonical_input_schema' => [
					'order_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'minItems' => 1, 'maxItems' => 20 ],
					'status' => [ 'type' => 'string' ],
					'note' => [ 'type' => 'string' ],
					'expected_versions' => [
						'type' => 'array',
						'minItems' => 1,
						'maxItems' => 20,
						'items' => [
							'type' => 'object',
							'properties' => [
								'order_id' => [ 'type' => 'integer' ],
								'expected_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
							],
							'required' => [ 'order_id', 'expected_version' ],
							'additionalProperties' => false,
						],
					],
				],
				'concurrency_strategy'  => 'per_item_optimistic_version',
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
				'ai_description'      => __( 'Update the status of one or more orders after confirmation.', 'yoro-ai-core' ),
				'ai_examples'         => [
					'Mark order #1234 as completed',
					'Update order #1234 to on hold',
					'Chuyển đơn #1234 sang hoàn tất',
				],
				'required_params'     => [ 'order_ids', 'status', 'expected_versions' ],
				'param_hints'         => [
					'order_ids' => __( 'Use one or more target order IDs.', 'yoro-ai-core' ),
					'status'    => __( 'Use store order status slugs like pending, processing, completed, on-hold, cancelled, refunded, or failed.', 'yoro-ai-core' ),
					'note'      => __( 'Use note only when the person explicitly wants a status update with an internal note.', 'yoro-ai-core' ),
					'expected_versions' => __( 'Copy one opaque version token per matching order from structured active order context returned by the latest order read. Never invent or ask the person for these tokens.', 'yoro-ai-core' ),
				],
				'clarification_hints' => [
					'order_ids' => __( 'Ask which order should change status when no target order is clear.', 'yoro-ai-core' ),
					'status'    => __( 'Ask what status to apply when the person wants to change status but does not name the new status.', 'yoro-ai-core' ),
				],
			]
		);

		YoRo_Action_Registry::register(
			'orders.restore_statuses',
			[
				'label'                 => __( 'Restore order statuses', 'yoro-ai-core' ),
				'description'           => __( 'Restore store orders to their previous statuses.', 'yoro-ai-core' ),
				'category'              => 'orders',
				'permission'            => 'manage_woocommerce',
				'callback'              => [ __CLASS__, 'restore_order_statuses' ],
				'requires_confirmation' => false,
				'risk_level'            => 'medium',
				'write_action'          => true,
				'ai_exposed'            => false,
				'input_schema'          => [
					'statuses'          => 'array',
					'expected_versions' => 'array<object>',
				],
				'canonical_input_schema' => [
					'statuses' => [
						'type' => 'array',
						'minItems' => 1,
						'maxItems' => 20,
						'items' => [
							'type' => 'object',
							'properties' => [
								'order_id' => [ 'type' => 'integer', 'minimum' => 1 ],
								'status' => [ 'type' => 'string' ],
							],
							'required' => [ 'order_id', 'status' ],
							'additionalProperties' => false,
						],
					],
					'expected_versions' => [
						'type' => 'array',
						'minItems' => 1,
						'maxItems' => 20,
						'items' => [
							'type' => 'object',
							'properties' => [
								'order_id' => [ 'type' => 'integer', 'minimum' => 1 ],
								'expected_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
							],
							'required' => [ 'order_id', 'expected_version' ],
							'additionalProperties' => false,
						],
					],
				],
				'concurrency_strategy'  => 'per_item_optimistic_version',
				'required_params'       => [ 'statuses', 'expected_versions' ],
				'preview_callback'      => [ __CLASS__, 'preview_restore_order_statuses' ],
				'max_items'             => 20,
				'capabilities' => [
					'orders',
					'write',
					'bulk',
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

	public static function search_products( $params ) {
		return YoRo_Commerce_Discovery_Service::search_products( $params );
	}

	public static function get_product_details( $params ) {
		return YoRo_Commerce_Discovery_Service::get_product_details( $params );
	}

	public static function get_order_timeline( $params ) {
		return YoRo_Commerce_Discovery_Service::get_order_timeline( $params );
	}

	public static function get_fulfillment_tracking( $params ) {
		return YoRo_Commerce_Discovery_Service::get_fulfillment_tracking( $params );
	}

	public static function update_product_stock( $params ) {
		return YoRo_Store_Inventory_Service::update_product_stock( $params );
	}

	public static function update_product_stock_status( $params ) {
		return YoRo_Store_Inventory_Service::update_product_stock_status( $params );
	}

	public static function restore_product_stock_state( $params ) {
		return YoRo_Store_Inventory_Service::restore_product_stock_state( $params );
	}

	public static function lookup_coupon( $params ) {
		return YoRo_Coupons_Service::lookup_coupon( $params );
	}

	public static function create_coupon( $params ) {
		return YoRo_Coupons_Service::create_coupon( $params );
	}

	public static function get_store_insights( $params ) {
		return YoRo_Orders_Read_Service::get_store_insights( $params );
	}

	public static function get_business_summary( $params ) {
		return YoRo_Orders_Read_Service::get_business_summary( $params );
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

	public static function preview_update_product_stock( $params ) {
		return YoRo_Store_Inventory_Service::preview_update_product_stock( $params );
	}

	public static function preview_update_product_stock_status( $params ) {
		return YoRo_Store_Inventory_Service::preview_update_product_stock_status( $params );
	}

	public static function preview_lookup_coupon( $params ) {
		return YoRo_Coupons_Service::preview_lookup_coupon( $params );
	}

	public static function preview_create_coupon( $params ) {
		return YoRo_Coupons_Service::preview_create_coupon( $params );
	}

	public static function preview_get_store_insights( $params ) {
		return YoRo_Orders_Read_Service::preview_get_store_insights( $params );
	}

	public static function preview_get_business_summary( $params ) {
		return YoRo_Orders_Read_Service::preview_get_business_summary( $params );
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

	public static function preview_save_email_template( $params ) {
		return YoRo_Orders_Email_Service::preview_save_email_template( $params );
	}

	public static function preview_delete_email_template( $params ) {
		return YoRo_Orders_Email_Service::preview_delete_email_template( $params );
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

	public static function preview_add_order_note( $params ) {
		return YoRo_Orders_Write_Service::preview_add_order_note( $params );
	}

	public static function preview_remove_order_note( $params ) {
		return YoRo_Orders_Write_Service::preview_remove_order_note( $params );
	}

	public static function preview_restore_order_statuses( $params ) {
		return YoRo_Orders_Write_Service::preview_restore_order_statuses( $params );
	}

	public static function update_order_status( $params ) {
		return YoRo_Orders_Write_Service::update_order_status( $params );
	}

	public static function restore_order_statuses( $params ) {
		return YoRo_Orders_Write_Service::restore_order_statuses( $params );
	}

	public static function preview_update_order_status( $params ) {
		return YoRo_Orders_Write_Service::preview_update_order_status( $params );
	}

	private static function product_identifier_types() {
		return class_exists( 'YoRo_Product_Helper' )
			? YoRo_Product_Helper::get_product_identifier_types()
			: [];
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
