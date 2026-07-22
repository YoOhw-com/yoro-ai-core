<?php
/**
 * YoRo chat E2E smoke contract.
 *
 * This smoke hits the real YoRo AI API conversation endpoint through the plugin
 * chat bridge, then executes local plugin actions. It is intentionally opt-in
 * because it uses the configured AI provider and mutates temporary WooCommerce
 * objects.
 *
 * Run from the WordPress root with:
 * YORO_RUN_CHAT_E2E=1 YORO_CHAT_E2E_SCENARIO=all wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/chat-e2e-contract.php
 * YORO_RUN_CHAT_E2E=1 YORO_CHAT_E2E_API_URL=http://127.0.0.1:8010/api/v1 wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/chat-e2e-contract.php
 *
 * Scenarios: coupon, order, inventory, email, multilingual, return_lifecycle, all.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

if ( '1' !== (string) getenv( 'YORO_RUN_CHAT_E2E' ) ) {
	echo "YoRo chat E2E smoke skipped. Set YORO_RUN_CHAT_E2E=1 to run.\n";
	return;
}

$scenario = sanitize_key( (string) ( getenv( 'YORO_CHAT_E2E_SCENARIO' ) ?: 'all' ) );
$allowed_scenarios = [ 'coupon', 'order', 'inventory', 'email', 'multilingual', 'return_lifecycle', 'all' ];

if ( ! in_array( $scenario, $allowed_scenarios, true ) ) {
	fwrite( STDERR, "Invalid YORO_CHAT_E2E_SCENARIO. Use coupon, order, inventory, email, multilingual, return_lifecycle, or all.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if (
	! class_exists( 'YoRo_REST_API' ) &&
	class_exists( 'YoRo_AI_Core' ) &&
	function_exists( 'yoro_ai_core_has_woocommerce' ) &&
	yoro_ai_core_has_woocommerce()
) {
	YoRo_AI_Core::init();
}

if (
	class_exists( 'YoRo_Action_Registry' ) &&
	class_exists( 'YoRo_Orders_Actions' ) &&
	0 === count( YoRo_Action_Registry::all() )
) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

if ( function_exists( 'rest_get_server' ) && ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init' );
}

$failures = [];
$debug = [];
$created_product_ids = [];
$created_order_ids = [];
$created_coupon_ids = [];
$captured_mail = [];
$original_manage_stock = get_option( 'woocommerce_manage_stock', '__yoro_missing__' );
$original_email_templates = get_option( 'yoro_customer_email_templates', '__yoro_missing__' );
$original_ai_api_url = class_exists( 'YoRo_AI_Settings' )
	? get_option( YoRo_AI_Settings::OPTION_AI_API_URL, '__yoro_missing__' )
	: '__yoro_missing__';
$api_url_override = class_exists( 'YoRo_AI_Settings' )
	? esc_url_raw( (string) getenv( 'YORO_CHAT_E2E_API_URL' ) )
	: '';
$api_url_override_applied = false;
$stamp = gmdate( 'YmdHis' );

$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$compact = static function ( array $data ) {
	return [
		'success'                 => $data['success'] ?? null,
		'stage'                   => $data['stage'] ?? null,
		'mode'                    => $data['mode'] ?? null,
		'action'                  => $data['action'] ?? null,
		'params'                  => $data['params'] ?? null,
		'missing'                 => $data['missing'] ?? null,
		'execution_required'      => $data['execution_required'] ?? null,
		'requires_confirmation'   => $data['requires_confirmation'] ?? null,
		'confirm_required'        => $data['confirm_required'] ?? null,
		'pending_operation_token' => $data['pending_operation_token'] ?? null,
		'conversation_id'         => $data['conversation_id'] ?? null,
		'message'                 => $data['message'] ?? null,
		'result'                  => [
			'success'      => $data['result']['success'] ?? null,
			'display_type' => $data['result']['display_type'] ?? null,
			'blocked'      => $data['result']['blocked'] ?? null,
			'block_reason' => $data['result']['block_reason'] ?? null,
			'read_only'    => $data['result']['read_only'] ?? null,
			'coupon_id'    => $data['result']['coupon_id'] ?? null,
			'coupon_code'  => $data['result']['coupon_code'] ?? null,
			'draft'        => $data['result']['draft'] ?? null,
			'email'        => $data['result']['email'] ?? null,
			'note_ids'     => $data['result']['note_ids'] ?? null,
			'order_id'     => $data['result']['order_id'] ?? null,
			'request_id'   => $data['result']['request_id'] ?? null,
			'status'       => $data['result']['status'] ?? null,
			'product_id'   => $data['result']['product_id'] ?? null,
			'stock_quantity' => $data['result']['stock_quantity'] ?? null,
			'stock_status' => $data['result']['stock_status'] ?? null,
			'templates'    => $data['result']['templates'] ?? null,
			'products'     => $data['result']['products'] ?? null,
		],
		'meta'                    => [
			'route_path' => $data['meta']['yoro_ai_api_route_path'] ?? null,
		],
	];
};

if ( '' !== $api_url_override && class_exists( 'YoRo_AI_Settings' ) ) {
	if ( YoRo_AI_Settings::is_ai_api_url_locked() ) {
		$failures[] = 'YORO_CHAT_E2E_API_URL cannot override YORO_AI_API_URL because the API URL is locked by constant.';
	} else {
		update_option( YoRo_AI_Settings::OPTION_AI_API_URL, $api_url_override, false );
		$api_url_override_applied = true;
	}
}

$debug['environment'] = [
	'scenario'                 => $scenario,
	'ai_api_url'               => class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_ai_api_url() : '',
	'ai_api_url_override'      => $api_url_override,
	'ai_api_url_override_used' => $api_url_override_applied,
	'ai_api_url_locked'        => class_exists( 'YoRo_AI_Settings' ) && YoRo_AI_Settings::is_ai_api_url_locked(),
	'ai_api_site_key_source'   => class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_ai_api_site_key_source() : '',
];

$dispatch_rest = static function ( $method, $route, $params = [] ) {
	$request = new WP_REST_Request( $method, $route );

	foreach ( (array) $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = rest_do_request( $request );

	if ( is_wp_error( $response ) ) {
		return [
			'status' => (int) ( $response->get_error_data()['status'] ?? 500 ),
			'data'   => [
				'success' => false,
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			],
		];
	}

	return [
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	];
};

$admin_ids = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;

$assert( $admin_id > 0, 'A WordPress administrator user is required.' );

if ( $admin_id > 0 ) {
	wp_set_current_user( $admin_id );
	delete_transient( 'yoro_rate_chat_' . $admin_id );
	delete_transient( 'yoro_rate_execute_' . $admin_id );
}

$chat = static function ( string $message, string $conversation_id = '' ) use ( $dispatch_rest, $admin_id ) {
	if ( $admin_id > 0 ) {
		delete_transient( 'yoro_rate_chat_' . $admin_id );
	}

	return $dispatch_rest(
		'POST',
		'/yoro-ai-core/v1/chat/message',
		[
			'message'         => $message,
			'conversation_id' => $conversation_id,
			'client_context'  => [
				'conversation_id' => $conversation_id,
			],
		]
	);
};

$execute = static function ( array $chat_data, bool $confirm = false ) use ( $dispatch_rest, $admin_id ) {
	if ( $admin_id > 0 ) {
		delete_transient( 'yoro_rate_execute_' . $admin_id );
	}

	return $dispatch_rest(
		'POST',
		'/yoro-ai-core/v1/execute',
		[
			'action'                  => $chat_data['action'] ?? '',
			'params'                  => is_array( $chat_data['params'] ?? null ) ? $chat_data['params'] : [],
			'conversation_id'         => (string) ( $chat_data['conversation_id'] ?? '' ),
			'pending_operation_token' => (string) ( $chat_data['pending_operation_token'] ?? '' ),
			'preview'                 => false,
			'confirm'                 => $confirm,
		]
	);
};

$preview_action = static function ( string $action, array $params ) use ( $dispatch_rest, $admin_id ) {
	if ( $admin_id > 0 ) {
		delete_transient( 'yoro_rate_execute_' . $admin_id );
	}

	return $dispatch_rest(
		'POST',
		'/yoro-ai-core/v1/execute',
		[
			'action'  => $action,
			'params'  => $params,
			'preview' => true,
			'confirm' => false,
		]
	);
};

$assert_no_execution_surface = static function ( array $data, string $case ) use ( $assert ) {
	$assert( false === (bool) ( $data['execution_required'] ?? true ), "{$case} must not require local execution." );
	$assert( false === (bool) ( $data['requires_confirmation'] ?? true ), "{$case} must not require confirmation." );
	$assert( false === (bool) ( $data['confirm_required'] ?? true ), "{$case} must not set confirm_required=true." );
	$assert( '' === (string) ( $data['pending_operation_token'] ?? '' ), "{$case} must not expose a pending_operation_token." );
};

$assert_read_action_plan = static function ( array $data, string $case, string $expected_action ) use ( $assert ) {
	$assert( 'action_plan' === (string) ( $data['stage'] ?? '' ), "{$case} must return action_plan." );
	$assert( $expected_action === (string) ( $data['action'] ?? '' ), "{$case} must preserve {$expected_action}." );
	$assert( false === (bool) ( $data['requires_confirmation'] ?? true ), "{$case} must not require confirmation." );
	$assert( false === (bool) ( $data['confirm_required'] ?? true ), "{$case} must not set confirm_required=true." );
};

$assert_blocked = static function ( array $data, string $case, string $expected_action, string $expected_reason ) use ( $assert, $assert_no_execution_surface ) {
	$assert( 'blocked' === (string) ( $data['stage'] ?? '' ), "{$case} must block before approval." );
	$assert( $expected_action === (string) ( $data['action'] ?? '' ), "{$case} must preserve {$expected_action}." );
	$assert( true === (bool) ( $data['result']['blocked'] ?? false ), "{$case} result must preserve blocked=true." );
	$assert( $expected_reason === (string) ( $data['result']['block_reason'] ?? '' ), "{$case} must expose {$expected_reason}." );
	$assert_no_execution_surface( $data, $case );
};

$assert_confirm = static function ( array $data, string $case, string $expected_action ) use ( $assert ) {
	$assert( 'confirm' === (string) ( $data['stage'] ?? '' ), "{$case} must require confirmation." );
	$assert( $expected_action === (string) ( $data['action'] ?? '' ), "{$case} must preserve {$expected_action}." );
	$assert( true === (bool) ( $data['execution_required'] ?? false ), "{$case} must require local confirmation execution." );
	$assert( true === (bool) ( $data['requires_confirmation'] ?? false ), "{$case} must set requires_confirmation=true." );
	$assert( true === (bool) ( $data['confirm_required'] ?? false ), "{$case} must set confirm_required=true." );
	$assert( '' !== (string) ( $data['pending_operation_token'] ?? '' ), "{$case} must expose pending_operation_token." );
	$assert( 'confirmation' === (string) ( $data['result']['display_type'] ?? '' ), "{$case} must render confirmation." );
};

$assert_preview_blocked = static function ( array $response, string $case, string $expected_action, string $expected_reason ) use ( $assert ) {
	$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : [];
	$result = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : [];
	$plan = isset( $result['plan'] ) && is_array( $result['plan'] ) ? $result['plan'] : [];

	$assert( 200 === (int) ( $response['status'] ?? 0 ), "{$case} preview must return HTTP 200." );
	$assert( 'preview' === (string) ( $data['stage'] ?? '' ), "{$case} must stay in preview stage." );
	$assert( $expected_action === (string) ( $result['action'] ?? '' ), "{$case} must preview {$expected_action}." );
	$assert( true === (bool) ( $plan['blocked'] ?? false ), "{$case} preview plan must block." );
	$assert( $expected_reason === (string) ( $plan['block_reason'] ?? '' ), "{$case} preview must expose {$expected_reason}." );
};

$create_simple_product = static function ( string $sku, string $name ) use ( &$created_product_ids ) {
	if ( ! class_exists( 'WC_Product_Simple' ) ) {
		return 0;
	}

	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_regular_price( '19.00' );
	$product->set_price( '19.00' );
	$product->set_sku( $sku );
	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
	$product_id = absint( $product->save() );

	if ( $product_id > 0 ) {
		$created_product_ids[] = $product_id;
	}

	return $product_id;
};

$create_variable_product = static function ( string $sku, string $stamp ) use ( &$created_product_ids ) {
	if ( ! class_exists( 'WC_Product_Variable' ) || ! class_exists( 'WC_Product_Variation' ) ) {
		return [ 0, 0 ];
	}

	$variable = new WC_Product_Variable();
	$variable->set_name( 'YoRo chat e2e variable ' . $stamp );
	$variable->set_status( 'publish' );
	$variable->set_regular_price( '29.00' );
	$variable->set_price( '29.00' );
	$variable->set_manage_stock( false );
	$variable->set_stock_status( 'instock' );
	$variable_id = absint( $variable->save() );
	$created_product_ids[] = $variable_id;

	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable_id );
	$variation->set_attributes(
		[
			'attribute_size' => 'small',
		]
	);
	$variation->set_regular_price( '29.00' );
	$variation->set_price( '29.00' );
	$variation->set_sku( $sku );
	$variation->set_manage_stock( false );
	$variation->set_stock_status( 'instock' );
	$variation_id = absint( $variation->save() );
	$created_product_ids[] = $variation_id;

	if ( function_exists( 'wc_delete_product_transients' ) && $variable_id > 0 ) {
		wc_delete_product_transients( $variable_id );
	}

	return [ $variable_id, $variation_id ];
};

$create_order = static function ( int $product_id ) use ( &$created_order_ids ) {
	if ( ! function_exists( 'wc_create_order' ) || $product_id <= 0 ) {
		return 0;
	}

	$product = wc_get_product( $product_id );

	if ( ! $product ) {
		return 0;
	}

	$order = wc_create_order();
	$order->add_product( $product, 1 );
	$order->set_billing_first_name( 'YoRo' );
	$order->set_billing_last_name( 'Smoke' );
	$order->set_billing_email( 'yoro-smoke@example.com' );
	$order->calculate_totals();
	$order->set_status( 'processing' );
	$order->save();
	$order_id = absint( $order->get_id() );

	if ( $order_id > 0 ) {
		$created_order_ids[] = $order_id;
	}

	return $order_id;
};

$custom_stock_status_filter = static function ( $statuses ) {
	$statuses = is_array( $statuses ) ? $statuses : [];
	$statuses['preorder'] = 'Preorder';

	return $statuses;
};

$capture_mail_filter = static function ( $pre, $atts ) use ( &$captured_mail ) {
	$captured_mail[] = is_array( $atts ) ? $atts : [];

	return true;
};

add_filter( 'woocommerce_product_stock_status_options', $custom_stock_status_filter );
add_filter( 'pre_wp_mail', $capture_mail_filter, 10, 2 );

try {
	$assert( class_exists( 'WooCommerce' ), 'WooCommerce must be active.' );
	$assert( class_exists( 'YoRo_REST_Chat_Message_Handler' ), 'YoRo chat handler must be loaded.' );
	$assert( class_exists( 'YoRo_AI_Provider_Resolver' ) && YoRo_AI_Provider_Resolver::supports( 'conversation' ), 'YoRo AI conversation provider must be connected.' );

	if ( in_array( $scenario, [ 'multilingual', 'all' ], true ) ) {
		$multilingual_cases = [
			'arabic inventory risk' => [
				'message' => 'أعطني تقريرًا إجماليًا عن مخاطر المخزون الحالية ومؤشرات انخفاض ونفاد المخزون.',
				'action'  => 'store.get_inventory_risk',
			],
			'traditional chinese customer lifecycle' => [
				'message' => '請顯示本月的客戶生命週期與重複購買客戶。',
				'action'  => 'store.get_customer_lifecycle',
			],
			'hindi returns intelligence' => [
				'message' => 'इस महीने दर्ज की गई वापसी और विनिमय अनुरोधों की रिपोर्ट दिखाएँ।',
				'action'  => 'store.get_returns_intelligence',
			],
			'swahili sales report' => [
				'message' => 'Onyesha ripoti ya mauzo ya duka mwezi huu.',
				'action'  => 'store.get_business_summary',
			],
		];

		foreach ( $multilingual_cases as $case => $expectation ) {
			$response = $chat( $expectation['message'] );
			$data     = (array) ( $response['data'] ?? [] );
			$debug[ 'multilingual_' . sanitize_key( $case ) ] = $compact( $data );

			$assert( 200 === (int) ( $response['status'] ?? 0 ), "{$case} chat must return HTTP 200." );
			$assert_read_action_plan( $data, $case, $expectation['action'] );

			$execution = $execute( $data, false );
			$execution_data = (array) ( $execution['data'] ?? [] );
			$debug[ 'multilingual_' . sanitize_key( $case ) . '_execute' ] = [
				'status' => $execution['status'] ?? 0,
				'data'   => $compact( $execution_data ),
			];
			$assert( 200 === (int) ( $execution['status'] ?? 0 ), "{$case} execution must return HTTP 200." );
			$assert( true === (bool) ( $execution_data['success'] ?? false ), "{$case} execution must complete successfully." );
			$assert( true === (bool) ( $execution_data['result']['read_only'] ?? false ), "{$case} execution must remain read-only." );
		}
	}

	if ( in_array( $scenario, [ 'multilingual', 'return_lifecycle', 'all' ], true ) ) {
		$return_product_id = $create_simple_product( 'YORO-CHAT-E2E-RETURN-' . $stamp, 'YoRo multilingual return ' . $stamp );
		$return_order_id = $create_order( $return_product_id );
		$return_order = wc_get_order( $return_order_id );
		$return_items = $return_order ? $return_order->get_items() : [];
		$return_item = ! empty( $return_items ) ? reset( $return_items ) : null;
		$return_item_id = $return_item instanceof WC_Order_Item_Product ? $return_item->get_id() : 0;
		$return_idempotency = 'chat-e2e-return-' . $stamp;
		$mail_count_before_return = count( $captured_mail );
		$product_before_return = wc_get_product( $return_product_id );
		$stock_before_return = $product_before_return ? $product_before_return->get_stock_quantity() : null;
		$managing_stock_before_return = $product_before_return ? $product_before_return->managing_stock() : null;
		$return_chat = $chat(
			'请为订单 #' . $return_order_id
			. ' 创建退货申请：订单商品项 ID ' . $return_item_id
			. '，数量 1，类型 return，处理方案 review，原因“包装破损”，幂等键 '
			. $return_idempotency
			. '。只记录申请，不退款、不补发、不发邮件、不改库存。'
		);
		$return_chat_data = (array) ( $return_chat['data'] ?? [] );
		$debug['multilingual_return_request_chat'] = $compact( $return_chat_data );

		$assert( $return_product_id > 0 && $return_order_id > 0 && $return_item_id > 0, 'Multilingual return acceptance requires an exact temporary order item.' );
		$assert( 200 === (int) ( $return_chat['status'] ?? 0 ), 'Multilingual return request chat must return HTTP 200.' );
		$assert_confirm( $return_chat_data, 'Multilingual return request chat', 'orders.create_return_request' );

		$return_execution = $execute( $return_chat_data, true );
		$return_execution_data = (array) ( $return_execution['data'] ?? [] );
		$return_result = (array) ( $return_execution_data['result'] ?? [] );
		$request_id = sanitize_text_field( $return_result['request_id'] ?? '' );
		$debug['multilingual_return_request_execute'] = [
			'status' => $return_execution['status'] ?? 0,
			'data'   => $compact( $return_execution_data ),
		];

		$assert( 200 === (int) ( $return_execution['status'] ?? 0 ), 'Multilingual return request execution must return HTTP 200.' );
		$assert( true === (bool) ( $return_execution_data['success'] ?? false ) && '' !== $request_id, 'Multilingual return request must be recorded.' );
		$assert( 0.0 === (float) wc_get_order( $return_order_id )->get_total_refunded(), 'Recording a return request must not refund the order.' );
		$assert( count( $captured_mail ) === $mail_count_before_return, 'Recording a return request must not send email.' );
		$product_after_return = wc_get_product( $return_product_id );
		$assert( $product_after_return && $stock_before_return === $product_after_return->get_stock_quantity(), 'Recording a return request must not change stock quantity.' );
		$assert( $product_after_return && $managing_stock_before_return === $product_after_return->managing_stock(), 'Recording a return request must not change stock management.' );

		$lifecycle_chat = $chat(
			'وافق على طلب الإرجاع ' . $request_id
			. ' للطلب #' . $return_order_id
			. ' بالحالة approved ومفتاح عدم التكرار chat-e2e-approve-' . $stamp
			. '، وأضف ملاحظة داخلية "تمت مراجعة الصور". لا تنفذ استرداداً أو استبدالاً ولا ترسل بريداً ولا تغيّر المخزون.'
		);
		$lifecycle_chat_data = (array) ( $lifecycle_chat['data'] ?? [] );
		$debug['multilingual_return_lifecycle_chat'] = $compact( $lifecycle_chat_data );

		$assert( 200 === (int) ( $lifecycle_chat['status'] ?? 0 ), 'Multilingual return lifecycle chat must return HTTP 200.' );
		$assert_confirm( $lifecycle_chat_data, 'Multilingual return lifecycle chat', 'orders.update_return_request' );

		$lifecycle_execution = $execute( $lifecycle_chat_data, true );
		$lifecycle_execution_data = (array) ( $lifecycle_execution['data'] ?? [] );
		$debug['multilingual_return_lifecycle_execute'] = [
			'status' => $lifecycle_execution['status'] ?? 0,
			'data'   => $compact( $lifecycle_execution_data ),
		];
		$stored_returns = (array) wc_get_order( $return_order_id )->get_meta( YoRo_Order_Return_Service::REQUESTS_META_KEY, true );

		$assert( 200 === (int) ( $lifecycle_execution['status'] ?? 0 ), 'Multilingual return lifecycle execution must return HTTP 200.' );
		$assert( true === (bool) ( $lifecycle_execution_data['success'] ?? false ), 'Multilingual return lifecycle execution must complete successfully.' );
		$assert( 'approved' === ( $stored_returns[0]['status'] ?? '' ), 'Multilingual lifecycle execution must persist the canonical approved status.' );
		$assert( 0.0 === (float) wc_get_order( $return_order_id )->get_total_refunded(), 'Lifecycle approval must not refund the order.' );
		$assert( count( $captured_mail ) === $mail_count_before_return, 'Lifecycle approval must not send email.' );
	}

	if ( in_array( $scenario, [ 'coupon', 'all' ], true ) ) {
		$coupon_code = 'YORO-CHAT-E2E-' . $stamp;
		$create_chat = $chat( 'Create a percent coupon named ' . $coupon_code . ' with a discount amount of 9.' );
		$create_chat_data = (array) $create_chat['data'];
		$debug['coupon_create_chat'] = $compact( $create_chat_data );

		$assert( 200 === (int) $create_chat['status'], 'Coupon create chat must return HTTP 200.' );
		$assert_confirm( $create_chat_data, 'Coupon create chat', 'coupons.create' );

		$create_execute = $execute( $create_chat_data, true );
		$create_execute_data = (array) $create_execute['data'];
		$create_result = isset( $create_execute_data['result'] ) && is_array( $create_execute_data['result'] ) ? $create_execute_data['result'] : [];
		$created_coupon_id = absint( $create_result['coupon_id'] ?? 0 );
		$debug['coupon_create_execute'] = [
			'status' => $create_execute['status'],
			'data'   => $compact( $create_execute_data ),
		];

		$assert( 200 === (int) $create_execute['status'], 'Coupon create execute must return HTTP 200.' );
		$assert( true === (bool) ( $create_execute_data['success'] ?? false ), 'Coupon create execute must be successful.' );
		$assert( $created_coupon_id > 0, 'Coupon create execute must return coupon_id.' );

		if ( $created_coupon_id > 0 ) {
			$created_coupon_ids[] = $created_coupon_id;
		}

		$lookup_chat = $chat( 'Look up coupon ' . $coupon_code );
		$lookup_chat_data = (array) $lookup_chat['data'];
		$debug['coupon_lookup_chat'] = $compact( $lookup_chat_data );

		$assert( 200 === (int) $lookup_chat['status'], 'Coupon lookup chat must return HTTP 200.' );
		$assert( 'action_plan' === (string) ( $lookup_chat_data['stage'] ?? '' ), 'Coupon lookup chat must return action_plan.' );
		$assert( 'coupons.lookup' === (string) ( $lookup_chat_data['action'] ?? '' ), 'Coupon lookup chat must preserve coupons.lookup.' );
		$assert( false === (bool) ( $lookup_chat_data['requires_confirmation'] ?? true ), 'Coupon lookup must not require confirmation.' );
	}

	$simple_sku = 'YORO-CHAT-E2E-SKU-' . $stamp;
	$simple_product_id = $create_simple_product( $simple_sku, 'YoRo chat e2e simple ' . $stamp );
	$assert( $simple_product_id > 0, 'A temporary simple product is required.' );

	if ( in_array( $scenario, [ 'order', 'all' ], true ) ) {
		$order_id = $create_order( $simple_product_id );
		$assert( $order_id > 0, 'A temporary order is required.' );

		$invalid_order_id = 987654321;
		while ( function_exists( 'wc_get_order' ) && wc_get_order( $invalid_order_id ) ) {
			++$invalid_order_id;
		}

		$invalid_status = $chat( 'Update order ' . $invalid_order_id . ' status to completed' );
		$invalid_status_data = (array) $invalid_status['data'];
		$debug['order_invalid_status'] = $compact( $invalid_status_data );

		$assert( 200 === (int) $invalid_status['status'], 'Invalid order status chat must return HTTP 200.' );
		$assert_blocked( $invalid_status_data, 'Invalid order status chat', 'orders.update_status', 'order_not_found' );

		$note_text = 'YoRo chat e2e note ' . $stamp;
		$note_chat = $chat( 'Add note to order ' . $order_id . ': ' . $note_text );
		$note_chat_data = (array) $note_chat['data'];
		$debug['order_note_chat'] = $compact( $note_chat_data );

		$assert( 200 === (int) $note_chat['status'], 'Order note chat must return HTTP 200.' );
		$assert_confirm( $note_chat_data, 'Order note chat', 'orders.add_note' );

		$note_execute = $execute( $note_chat_data, true );
		$note_execute_data = (array) $note_execute['data'];
		$note_result = isset( $note_execute_data['result'] ) && is_array( $note_execute_data['result'] ) ? $note_execute_data['result'] : [];
		$debug['order_note_execute'] = [
			'status' => $note_execute['status'],
			'data'   => $compact( $note_execute_data ),
		];

		$assert( 200 === (int) $note_execute['status'], 'Order note execute must return HTTP 200.' );
		$assert( true === (bool) ( $note_execute_data['success'] ?? false ), 'Order note execute must be successful.' );
		$assert( ! empty( $note_result['note_ids'] ), 'Order note execute must return note_ids.' );

		$status_chat = $chat( 'Update order ' . $order_id . ' status to completed' );
		$status_chat_data = (array) $status_chat['data'];
		$debug['order_status_chat'] = $compact( $status_chat_data );

		$assert( 200 === (int) $status_chat['status'], 'Order status chat must return HTTP 200.' );
		$assert_confirm( $status_chat_data, 'Order status chat', 'orders.update_status' );

		$status_execute = $execute( $status_chat_data, true );
		$status_execute_data = (array) $status_execute['data'];
		$updated_order = wc_get_order( $order_id );
		$debug['order_status_execute'] = [
			'status' => $status_execute['status'],
			'data'   => $compact( $status_execute_data ),
		];

		$assert( 200 === (int) $status_execute['status'], 'Order status execute must return HTTP 200.' );
		$assert( true === (bool) ( $status_execute_data['success'] ?? false ), 'Order status execute must be successful.' );
		$assert( $updated_order && 'completed' === $updated_order->get_status(), 'Order status execute must update status.' );
	}

	if ( in_array( $scenario, [ 'email', 'all' ], true ) ) {
		$order_id = $create_order( $simple_product_id );
		$assert( $order_id > 0, 'A temporary email workflow order is required.' );

		$invalid_order_id = 987654321;
		while ( function_exists( 'wc_get_order' ) && wc_get_order( $invalid_order_id ) ) {
			++$invalid_order_id;
		}

		$invalid_compose_preview = $preview_action(
			'orders.compose_customer_email',
			[
				'order_id'    => $invalid_order_id,
				'instruction' => '',
			]
		);
		$debug['email_invalid_compose_preview'] = $invalid_compose_preview;
		$assert_preview_blocked( $invalid_compose_preview, 'Invalid compose preview with missing instruction', 'orders.compose_customer_email', 'order_not_found' );

		$invalid_draft_preview = $preview_action(
			'orders.save_customer_email_draft',
			[
				'order_id' => $invalid_order_id,
				'subject'  => '',
				'body'     => '',
			]
		);
		$debug['email_invalid_draft_preview'] = $invalid_draft_preview;
		$assert_preview_blocked( $invalid_draft_preview, 'Invalid draft preview with missing content', 'orders.save_customer_email_draft', 'order_not_found' );

		$invalid_send_preview = $preview_action(
			'orders.send_customer_email',
			[
				'order_id' => $invalid_order_id,
				'subject'  => '',
				'body'     => '',
			]
		);
		$debug['email_invalid_send_preview'] = $invalid_send_preview;
		$assert_preview_blocked( $invalid_send_preview, 'Invalid send preview with missing content', 'orders.send_customer_email', 'order_not_found' );

		$invalid_refund_preview = $preview_action(
			'orders.refund_preview',
			[
				'order_id' => $invalid_order_id,
			]
		);
		$debug['email_invalid_refund_preview'] = $invalid_refund_preview;
		$assert_preview_blocked( $invalid_refund_preview, 'Invalid refund preview', 'orders.refund_preview', 'order_not_found' );

		$invalid_send_chat = $chat( 'Send a customer email now for order ' . $invalid_order_id . ' with subject "YoRo smoke" and body "This is a YoRo smoke email." Do not only compose a draft.' );
		$invalid_send_chat_data = (array) $invalid_send_chat['data'];
		$debug['email_invalid_send_chat'] = $compact( $invalid_send_chat_data );

		$assert( 200 === (int) $invalid_send_chat['status'], 'Invalid send email chat must return HTTP 200.' );
		$assert_blocked( $invalid_send_chat_data, 'Invalid send email chat', 'orders.send_customer_email', 'order_not_found' );

		$invalid_refund_chat = $chat( 'Show refund preview for order ' . $invalid_order_id );
		$invalid_refund_chat_data = (array) $invalid_refund_chat['data'];
		$debug['email_invalid_refund_chat'] = $compact( $invalid_refund_chat_data );

		$assert( 200 === (int) $invalid_refund_chat['status'], 'Invalid refund preview chat must return HTTP 200.' );
		$assert_blocked( $invalid_refund_chat_data, 'Invalid refund preview chat', 'orders.refund_preview', 'order_not_found' );

		$refund_chat = $chat( 'Show refund preview for order ' . $order_id );
		$refund_chat_data = (array) $refund_chat['data'];
		$debug['email_refund_chat'] = $compact( $refund_chat_data );

		$assert( 200 === (int) $refund_chat['status'], 'Refund preview chat must return HTTP 200.' );
		$assert_read_action_plan( $refund_chat_data, 'Refund preview chat', 'orders.refund_preview' );

		$refund_execute = $execute( $refund_chat_data, false );
		$refund_execute_data = (array) $refund_execute['data'];
		$debug['email_refund_execute'] = [
			'status' => $refund_execute['status'],
			'data'   => $compact( $refund_execute_data ),
		];

		$assert( 200 === (int) $refund_execute['status'], 'Refund preview execute must return HTTP 200.' );
		$assert( true === (bool) ( $refund_execute_data['success'] ?? false ), 'Refund preview execute must be successful.' );
		$assert( 'refund_preview' === (string) ( $refund_execute_data['result']['display_type'] ?? '' ), 'Refund preview execute must render refund_preview.' );

		$compose_chat = $chat( 'Write an email for order ' . $order_id . ' saying shipment is delayed one day' );
		$compose_chat_data = (array) $compose_chat['data'];
		$debug['email_compose_chat'] = $compact( $compose_chat_data );

		$assert( 200 === (int) $compose_chat['status'], 'Compose email chat must return HTTP 200.' );
		$assert_read_action_plan( $compose_chat_data, 'Compose email chat', 'orders.compose_customer_email' );

		$compose_execute = $execute( $compose_chat_data, false );
		$compose_execute_data = (array) $compose_execute['data'];
		$compose_result = isset( $compose_execute_data['result'] ) && is_array( $compose_execute_data['result'] ) ? $compose_execute_data['result'] : [];
		$debug['email_compose_execute'] = [
			'status' => $compose_execute['status'],
			'data'   => $compact( $compose_execute_data ),
		];

		$assert( 200 === (int) $compose_execute['status'], 'Compose email execute must return HTTP 200.' );
		$assert( true === (bool) ( $compose_execute_data['success'] ?? false ), 'Compose email execute must be successful.' );
		$assert( 'customer_email_preview' === (string) ( $compose_result['display_type'] ?? '' ), 'Compose email execute must render customer_email_preview.' );
		$assert( ! empty( $compose_result['send_payload']['subject'] ), 'Compose email execute must return send payload subject.' );
		$assert( ! empty( $compose_result['send_payload']['body'] ), 'Compose email execute must return send payload body.' );

		$draft_subject = 'YoRo smoke draft ' . $stamp;
		$draft_body = 'This is a YoRo smoke draft body ' . $stamp . '.';
		$draft_chat = $chat( 'Save customer email draft for order ' . $order_id . ' with subject "' . $draft_subject . '" and body "' . $draft_body . '"' );
		$draft_chat_data = (array) $draft_chat['data'];
		$debug['email_draft_chat'] = $compact( $draft_chat_data );

		$assert( 200 === (int) $draft_chat['status'], 'Save draft chat must return HTTP 200.' );
		$assert_confirm( $draft_chat_data, 'Save draft chat', 'orders.save_customer_email_draft' );

		$draft_execute = $execute( $draft_chat_data, true );
		$draft_execute_data = (array) $draft_execute['data'];
		$draft_result = isset( $draft_execute_data['result'] ) && is_array( $draft_execute_data['result'] ) ? $draft_execute_data['result'] : [];
		$debug['email_draft_execute'] = [
			'status' => $draft_execute['status'],
			'data'   => $compact( $draft_execute_data ),
		];

		$assert( 200 === (int) $draft_execute['status'], 'Save draft execute must return HTTP 200.' );
		$assert( true === (bool) ( $draft_execute_data['success'] ?? false ), 'Save draft execute must be successful.' );
		$assert( ! empty( $draft_result['draft']['id'] ), 'Save draft execute must return draft id.' );

		$send_subject = 'YoRo smoke send ' . $stamp;
		$send_body = 'This is a YoRo smoke send body ' . $stamp . '.';
		$mail_count_before = count( $captured_mail );
		$send_chat = $chat( 'Send customer email for order ' . $order_id . ' with subject "' . $send_subject . '" and body "' . $send_body . '"' );
		$send_chat_data = (array) $send_chat['data'];
		$debug['email_send_chat'] = $compact( $send_chat_data );

		$assert( 200 === (int) $send_chat['status'], 'Send email chat must return HTTP 200.' );
		$assert_confirm( $send_chat_data, 'Send email chat', 'orders.send_customer_email' );

		$send_execute = $execute( $send_chat_data, true );
		$send_execute_data = (array) $send_execute['data'];
		$debug['email_send_execute'] = [
			'status' => $send_execute['status'],
			'data'   => $compact( $send_execute_data ),
			'captured_mail_count' => count( $captured_mail ),
		];

		$assert( 200 === (int) $send_execute['status'], 'Send email execute must return HTTP 200.' );
		$assert( true === (bool) ( $send_execute_data['success'] ?? false ), 'Send email execute must be successful.' );
		$assert( count( $captured_mail ) > $mail_count_before, 'Send email execute must invoke wp_mail through the capture filter.' );
	}

	if ( in_array( $scenario, [ 'inventory', 'all' ], true ) ) {
		update_option( 'woocommerce_manage_stock', 'yes', false );

		$invalid_product_id = 987654321;
		while ( function_exists( 'wc_get_product' ) && wc_get_product( $invalid_product_id ) ) {
			++$invalid_product_id;
		}

		$invalid_stock = $chat( 'Set product id ' . $invalid_product_id . ' stock to 8' );
		$invalid_stock_data = (array) $invalid_stock['data'];
		$debug['inventory_invalid_stock'] = $compact( $invalid_stock_data );

		$assert( 200 === (int) $invalid_stock['status'], 'Invalid product stock chat must return HTTP 200.' );
		$assert_blocked( $invalid_stock_data, 'Invalid product stock chat', 'products.update_stock', 'product_not_found' );

		update_option( 'woocommerce_manage_stock', 'no', false );

		$disabled_stock = $chat( 'Set SKU ' . $simple_sku . ' stock to 5' );
		$disabled_stock_data = (array) $disabled_stock['data'];
		$debug['inventory_disabled_stock'] = $compact( $disabled_stock_data );

		$assert( 200 === (int) $disabled_stock['status'], 'Disabled global stock chat must return HTTP 200.' );
		$assert_blocked( $disabled_stock_data, 'Disabled global stock chat', 'products.update_stock', 'stock_management_disabled' );

		update_option( 'woocommerce_manage_stock', 'yes', false );

		$stock_chat = $chat( 'Set SKU ' . $simple_sku . ' stock to 8' );
		$stock_chat_data = (array) $stock_chat['data'];
		$debug['inventory_stock_chat'] = $compact( $stock_chat_data );

		$assert( 200 === (int) $stock_chat['status'], 'Stock update chat must return HTTP 200.' );
		$assert_confirm( $stock_chat_data, 'Stock update chat', 'products.update_stock' );

		$stock_execute = $execute( $stock_chat_data, true );
		$stock_execute_data = (array) $stock_execute['data'];
		$stock_result = isset( $stock_execute_data['result'] ) && is_array( $stock_execute_data['result'] ) ? $stock_execute_data['result'] : [];
		$product = wc_get_product( $simple_product_id );
		$debug['inventory_stock_execute'] = [
			'status' => $stock_execute['status'],
			'data'   => $compact( $stock_execute_data ),
		];

		$assert( 200 === (int) $stock_execute['status'], 'Stock update execute must return HTTP 200.' );
		$assert( true === (bool) ( $stock_execute_data['success'] ?? false ), 'Stock update execute must be successful.' );
		$assert( true === (bool) ( $stock_result['enabled_stock_management'] ?? false ), 'Stock update must disclose enabled stock management.' );
		$assert( $product && $product->managing_stock(), 'Stock update must enable product stock management.' );
		$assert( $product && 8 === (int) $product->get_stock_quantity(), 'Stock update must set quantity.' );

		$managed_status = $chat( 'Set SKU ' . $simple_sku . ' stock status to out of stock' );
		$managed_status_data = (array) $managed_status['data'];
		$debug['inventory_managed_status'] = $compact( $managed_status_data );

		$assert( 200 === (int) $managed_status['status'], 'Managed stock status chat must return HTTP 200.' );
		$assert_blocked( $managed_status_data, 'Managed stock status chat', 'products.update_stock_status', 'stock_status_managed_by_quantity' );

		$product = wc_get_product( $simple_product_id );

		if ( $product ) {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
			$product->save();
		}

		$status_chat = $chat( 'Set SKU ' . $simple_sku . ' stock status to preorder' );
		$status_chat_data = (array) $status_chat['data'];
		$debug['inventory_status_chat'] = $compact( $status_chat_data );

		$assert( 200 === (int) $status_chat['status'], 'Custom stock status chat must return HTTP 200.' );
		$assert_confirm( $status_chat_data, 'Custom stock status chat', 'products.update_stock_status' );
		$assert( 'preorder' === (string) ( $status_chat_data['params']['stock_status'] ?? '' ), 'Custom stock status chat must preserve custom status key.' );

		$status_execute = $execute( $status_chat_data, true );
		$status_execute_data = (array) $status_execute['data'];
		$product = wc_get_product( $simple_product_id );
		$debug['inventory_status_execute'] = [
			'status' => $status_execute['status'],
			'data'   => $compact( $status_execute_data ),
		];

		$assert( 200 === (int) $status_execute['status'], 'Custom stock status execute must return HTTP 200.' );
		$assert( true === (bool) ( $status_execute_data['success'] ?? false ), 'Custom stock status execute must be successful.' );
		$assert( $product && 'preorder' === $product->get_stock_status(), 'Custom stock status execute must update product.' );

		[ $variable_id, $variation_id ] = $create_variable_product( 'YORO-CHAT-E2E-VAR-' . $stamp, $stamp );
		$assert( $variable_id > 0, 'A temporary variable product is required.' );
		$assert( $variation_id > 0, 'A temporary variation is required.' );

		$variable_parent_stock = $chat( 'Set product id ' . $variable_id . ' stock to 6' );
		$variable_parent_stock_data = (array) $variable_parent_stock['data'];
		$debug['inventory_variable_parent'] = $compact( $variable_parent_stock_data );

		$assert( 200 === (int) $variable_parent_stock['status'], 'Variable parent stock chat must return HTTP 200.' );
		$assert_blocked( $variable_parent_stock_data, 'Variable parent stock chat', 'products.update_stock', 'variable_parent_requires_variation_or_shared_stock' );
		$assert( ! empty( $variable_parent_stock_data['result']['products'][0]['variation_targets'] ), 'Variable parent block must expose variation targets.' );

		$variation_stock = $chat( 'Set variation id ' . $variation_id . ' stock to 4' );
		$variation_stock_data = (array) $variation_stock['data'];
		$debug['inventory_variation_chat'] = $compact( $variation_stock_data );

		$assert( 200 === (int) $variation_stock['status'], 'Variation stock chat must return HTTP 200.' );
		$assert_confirm( $variation_stock_data, 'Variation stock chat', 'products.update_stock' );

		$variation_execute = $execute( $variation_stock_data, true );
		$variation_execute_data = (array) $variation_execute['data'];
		$variation = wc_get_product( $variation_id );
		$debug['inventory_variation_execute'] = [
			'status' => $variation_execute['status'],
			'data'   => $compact( $variation_execute_data ),
		];

		$assert( 200 === (int) $variation_execute['status'], 'Variation stock execute must return HTTP 200.' );
		$assert( true === (bool) ( $variation_execute_data['success'] ?? false ), 'Variation stock execute must be successful.' );
		$assert( $variation && $variation->managing_stock(), 'Variation stock execute must enable variation stock management.' );
		$assert( $variation && 4 === (int) $variation->get_stock_quantity(), 'Variation stock execute must set quantity.' );
	}
} finally {
	foreach ( array_reverse( array_filter( array_unique( array_map( 'absint', $created_order_ids ) ) ) ) as $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

		if ( $order ) {
			$order->delete( true );
		}
	}

	foreach ( array_reverse( array_filter( array_unique( array_map( 'absint', $created_product_ids ) ) ) ) as $product_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

		if ( $product ) {
			$product->delete( true );
		} else {
			wp_delete_post( $product_id, true );
		}
	}

	foreach ( array_filter( array_unique( array_map( 'absint', $created_coupon_ids ) ) ) as $coupon_id ) {
		wp_delete_post( $coupon_id, true );
	}

	if ( '__yoro_missing__' === $original_manage_stock ) {
		delete_option( 'woocommerce_manage_stock' );
	} else {
		update_option( 'woocommerce_manage_stock', $original_manage_stock, false );
	}

	if ( $api_url_override_applied && class_exists( 'YoRo_AI_Settings' ) ) {
		if ( '__yoro_missing__' === $original_ai_api_url ) {
			delete_option( YoRo_AI_Settings::OPTION_AI_API_URL );
		} else {
			update_option( YoRo_AI_Settings::OPTION_AI_API_URL, $original_ai_api_url, false );
		}
	}

	if ( '__yoro_missing__' === $original_email_templates ) {
		delete_option( 'yoro_customer_email_templates' );
	} else {
		update_option( 'yoro_customer_email_templates', $original_email_templates, false );
	}

	remove_filter( 'woocommerce_product_stock_status_options', $custom_stock_status_filter );
	remove_filter( 'pre_wp_mail', $capture_mail_filter, 10 );
}

if ( $failures ) {
	if ( '1' === (string) getenv( 'YORO_CHAT_E2E_DEBUG' ) ) {
		fwrite( STDERR, "DEBUG=" . wp_json_encode( $debug ) . "\n" );
	}

	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo chat E2E smoke passed for scenario {$scenario}.\n";
