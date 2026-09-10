<?php
/**
 * YoRo action catalog and executor REST smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/action-catalog-executor-rest-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
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
$original_ai_api_status = class_exists( 'YoRo_AI_Settings' )
	? get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, null )
	: null;

$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'WooCommerce' ), 'WooCommerce must be active for the YoRo plugin REST smoke contract.' );
$assert( class_exists( 'YoRo_Action_Catalog_Builder' ), 'YoRo_Action_Catalog_Builder is not loaded.' );
$assert( class_exists( 'YoRo_REST_API' ), 'YoRo_REST_API is not loaded.' );
$assert( class_exists( 'WP_REST_Request' ), 'WP_REST_Request is not available.' );

$admin_ids = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;

$assert( $admin_id > 0, 'A WordPress administrator user is required for REST permission smoke checks.' );

if ( $admin_id > 0 ) {
	wp_set_current_user( $admin_id );
	delete_transient( 'yoro_rate_execute_' . $admin_id );
}

if ( class_exists( 'YoRo_AI_Settings' ) ) {
	$installation_uuid = YoRo_AI_Settings::get_installation_uuid();

	YoRo_AI_Settings::update_ai_api_status(
		[
			'account_connection' => [
				'connected' => true,
				'status'    => 'active',
			],
			'organization' => [
				'id'   => 1,
				'name' => 'REST Smoke Organization',
			],
			'site' => [
				'id'     => 1,
				'name'   => 'REST Smoke Site',
				'url'    => home_url(),
				'status' => 'active',
				'plan'   => 'test',
			],
			'connection' => [
				'id'                => 1,
				'installation_uuid' => $installation_uuid,
				'status'            => 'active',
				'plugin_version'    => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
			],
			'credential' => [
				'id'                 => 1,
				'type'               => 'wordpress_connection',
				'site_connection_id' => 1,
				'expires_at'         => gmdate( 'c', time() + HOUR_IN_SECONDS ),
			],
		]
	);
}

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

if ( class_exists( 'YoRo_Action_Catalog_Builder' ) ) {
	$context = class_exists( 'YoRo_Context_Provider' ) ? YoRo_Context_Provider::get_context() : [];
	$catalog = YoRo_Action_Catalog_Builder::build( $context );
	$by_name = [];

	foreach ( $catalog as $item ) {
		if ( is_array( $item ) && ! empty( $item['name'] ) ) {
			$by_name[ $item['name'] ] = $item;
		}
	}

	foreach ( [ 'orders.search', 'orders.add_note', 'orders.compose_customer_email', 'orders.update_status', 'customers.lookup', 'coupons.lookup', 'coupons.create' ] as $action_name ) {
		$assert( isset( $by_name[ $action_name ] ), sprintf( 'Action catalog must expose %s.', $action_name ) );
	}

	$assert( ! isset( $by_name['customers.get_summary'] ), 'Deprecated customers.get_summary action must not be exposed.' );

	if ( isset( $by_name['orders.add_note'] ) ) {
		$assert( true === (bool) ( $by_name['orders.add_note']['write_action'] ?? false ), 'orders.add_note must be a write action in the action catalog.' );
		$assert( true === (bool) ( $by_name['orders.add_note']['requires_confirmation'] ?? false ), 'orders.add_note catalog entry must require confirmation through write_action policy.' );
		$assert( [ 'order_ids', 'note' ] === array_values( (array) ( $by_name['orders.add_note']['required_params'] ?? [] ) ), 'orders.add_note catalog required params must be order_ids and note.' );
	}

	if ( isset( $by_name['orders.compose_customer_email'] ) ) {
		$assert( false === (bool) ( $by_name['orders.compose_customer_email']['write_action'] ?? true ), 'orders.compose_customer_email must remain a read/preview action.' );
		$assert( false === (bool) ( $by_name['orders.compose_customer_email']['requires_confirmation'] ?? true ), 'orders.compose_customer_email must not require approval just to draft a preview.' );
		$assert( in_array( 'instruction', (array) ( $by_name['orders.compose_customer_email']['required_params'] ?? [] ), true ), 'orders.compose_customer_email must require instruction.' );
	}

	if ( isset( $by_name['orders.update_status'] ) ) {
		$assert( true === (bool) ( $by_name['orders.update_status']['write_action'] ?? false ), 'orders.update_status must be a write action in the action catalog.' );
		$assert( true === (bool) ( $by_name['orders.update_status']['requires_confirmation'] ?? false ), 'orders.update_status catalog entry must require confirmation.' );
		$assert( in_array( 'status', (array) ( $by_name['orders.update_status']['required_params'] ?? [] ), true ), 'orders.update_status must require status.' );
	}

	if ( isset( $by_name['coupons.lookup'] ) ) {
		$assert( false === (bool) ( $by_name['coupons.lookup']['write_action'] ?? true ), 'coupons.lookup must remain read-only.' );
		$assert( false === (bool) ( $by_name['coupons.lookup']['requires_confirmation'] ?? true ), 'coupons.lookup must not require confirmation.' );
		$assert( [ 'coupon_code' ] === array_values( (array) ( $by_name['coupons.lookup']['required_params'] ?? [] ) ), 'coupons.lookup catalog required params must be coupon_code.' );
	}

	if ( isset( $by_name['coupons.create'] ) ) {
		$assert( true === (bool) ( $by_name['coupons.create']['write_action'] ?? false ), 'coupons.create must be a write action in the action catalog.' );
		$assert( true === (bool) ( $by_name['coupons.create']['requires_confirmation'] ?? false ), 'coupons.create catalog entry must require confirmation.' );
		$assert( [ 'coupon_code', 'discount_type', 'discount_amount' ] === array_values( (array) ( $by_name['coupons.create']['required_params'] ?? [] ) ), 'coupons.create catalog required params must be coupon_code, discount_type, and discount_amount.' );
		$assert( 'coupon_create' === ( $by_name['coupons.create']['intent_family'] ?? '' ), 'coupons.create must expose coupon_create intent_family.' );
	}
}

$actions_response = $dispatch_rest( 'GET', '/yoro-ai-core/v1/actions' );
$actions_data     = (array) $actions_response['data'];
$actions          = isset( $actions_data['actions'] ) && is_array( $actions_data['actions'] ) ? $actions_data['actions'] : [];

$assert( 200 === (int) $actions_response['status'], 'GET /yoro-ai-core/v1/actions must return HTTP 200.' );
$assert( true === (bool) ( $actions_data['success'] ?? false ), 'GET /yoro-ai-core/v1/actions must return success=true.' );
$assert( isset( $actions['orders.search'] ), 'REST actions endpoint must expose orders.search.' );
$assert( isset( $actions['orders.update_status'] ), 'REST actions endpoint must expose orders.update_status.' );

$context_response = $dispatch_rest( 'GET', '/yoro-ai-core/v1/context' );
$context_data     = (array) $context_response['data'];
$context          = isset( $context_data['context'] ) && is_array( $context_data['context'] ) ? $context_data['context'] : [];

$assert( 200 === (int) $context_response['status'], 'GET /yoro-ai-core/v1/context must return HTTP 200.' );
$assert( true === (bool) ( $context_data['success'] ?? false ), 'GET /yoro-ai-core/v1/context must return success=true.' );
$assert( true === (bool) ( $context['user']['can_manage_woocommerce'] ?? false ), 'REST context must reflect the current executor permission.' );
$assert( 'plugin_executor' === ( $context['store_knowledge']['capabilities']['execution_boundary'] ?? '' ), 'REST context must preserve plugin_executor boundary.' );
$assert( 'write_actions_require_confirmation' === ( $context['store_knowledge']['capabilities']['confirmation_policy'] ?? '' ), 'REST context must expose write_actions_require_confirmation policy.' );

$preview_response = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/execute',
	[
		'action'  => 'orders.search',
		'params'  => [
			'limit' => 1,
		],
		'preview' => true,
		'confirm' => false,
	]
);
$preview_data = (array) $preview_response['data'];

$assert( 200 === (int) $preview_response['status'], 'Preview execute smoke request must return HTTP 200.' );
$assert( true === (bool) ( $preview_data['success'] ?? false ), 'Preview execute smoke request must return success=true.' );
$assert( 'preview' === ( $preview_data['stage'] ?? '' ), 'Read preview execution must return stage=preview.' );
$assert( true === (bool) ( $preview_data['result']['read_only'] ?? false ), 'Read preview execution must be read_only.' );
$assert( false === (bool) ( $preview_data['result']['requires_confirmation'] ?? false ), 'Read preview execution must not require confirmation.' );

$confirm_response = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/execute',
	[
		'action'  => 'orders.update_status',
		'params'  => [
			'order_ids' => [ 987654321 ],
			'status'    => 'processing',
		],
		'preview' => false,
		'confirm' => false,
	]
);
$confirm_data = (array) $confirm_response['data'];

$assert( 200 === (int) $confirm_response['status'], 'Unapproved write execute smoke request must return HTTP 200.' );
$assert( false === (bool) ( $confirm_data['success'] ?? true ), 'Unapproved write execution must not be marked successful.' );
$assert( 'confirm' === ( $confirm_data['stage'] ?? '' ), 'Unapproved write execution must return stage=confirm.' );
$assert( true === (bool) ( $confirm_data['confirm_required'] ?? false ), 'Unapproved write execution must require confirmation.' );
$assert( true === (bool) ( $confirm_data['direct_confirmation_allowed'] ?? false ), 'Direct REST write confirmation must expose direct_confirmation_allowed=true.' );
$assert( true === (bool) ( $confirm_data['result']['requires_confirmation'] ?? false ), 'Confirmation result must expose requires_confirmation=true.' );
$assert( true === (bool) ( $confirm_data['result']['read_only'] ?? false ), 'Confirmation result must be read_only.' );
$assert( 'confirmation' === ( $confirm_data['result']['display_type'] ?? '' ), 'Confirmation result must render as display_type=confirmation.' );
$assert( false !== strpos( (string) ( $confirm_data['message'] ?? '' ), 'Nothing has changed yet' ), 'Unapproved write response must state that nothing has changed yet.' );

if ( class_exists( 'YoRo_AI_Settings' ) ) {
	if ( null === $original_ai_api_status ) {
		delete_option( YoRo_AI_Settings::OPTION_AI_API_STATUS );
	} else {
		update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $original_ai_api_status, false );
	}
}

// B1: only the exact single-order read reaches WooCommerce; fixtures are disposable.
$payment_action = YoRo_Action_Registry::get( 'orders.get_payment_context' );
$assert( 'yoro_view_store_data' === $payment_action['permission'], 'Payment context retains the established store-read permission.' );
$assert( 1 === $payment_action['max_targets'] && ! $payment_action['write_action'], 'Payment context is one bounded read.' );
foreach ( [ [], [ 'order_id' => 0 ], [ 'order_id' => -1 ], [ 'order_id' => '12' ], [ 'order_id' => 1.5 ], [ 'order_id' => true ], [ 'order_id' => [ 1 ] ], [ 'order_id' => 1, 'order_ids' => [ 1 ] ], [ 'order_id' => 1, 'gateway' => 'secret' ], [ 'order_id' => 1, '_yoro_preview' => 'yes' ] ] as $invalid_payment_params ) {
	$invalid_payment_result = YoRo_Orders_Read_Service::get_payment_context( $invalid_payment_params );
	$assert( is_wp_error( $invalid_payment_result ) && 'invalid_params' === $invalid_payment_result->get_error_code(), 'Payment context rejects invalid or extra input before looking up an order.' );
}
wp_set_current_user( 0 );
$payment_denied = YoRo_Action_Executor::execute( 'orders.get_payment_context', [ 'order_id' => 1 ] );
$assert( is_wp_error( $payment_denied ) && 'yoro_permission_denied' === $payment_denied->get_error_code(), 'Payment facts are unavailable without read permission.' );
wp_set_current_user( $admin_id );
$payment_fixture = wc_create_order();
try {
	$payment_fixture->set_currency( 'USD' );
	$payment_fixture->set_total( '100.00' );
	$payment_fixture->set_payment_method( 'bacs' );
	$payment_fixture->set_payment_method_title( '<b>Provider data, not instructions</b>' );
	$payment_fixture->set_transaction_id( 'payment-reference-123' );
	$payment_fixture->update_meta_data( '_gateway_secret', 'B1-SECRET-SENTINEL' );
	$payment_fixture->set_billing_email( 'private-b1@example.test' );
	foreach ( [ 'pending', 'failed', 'processing', 'completed', 'refunded' ] as $payment_status ) {
		$payment_fixture->set_status( $payment_status );
		$payment_fixture->save();
		$payment_result = YoRo_Orders_Read_Service::get_payment_context( [ 'order_id' => $payment_fixture->get_id() ] );
		$assert( ! is_wp_error( $payment_result ), 'Payment context reads each WooCommerce payment-related order status.' );
		$payment_facts = $payment_result['payment_context'];
		$assert( $payment_status === $payment_facts['status'] && $payment_fixture->is_paid() === $payment_facts['is_paid'], 'Paid state uses WooCommerce observed semantics.' );
		$assert( $payment_fixture->needs_payment() === $payment_facts['needs_payment'], 'Payment-needed state uses WooCommerce observed semantics.' );
		$assert( 'not_observed' === $payment_facts['evidence']['gateway_verification'], 'No WooCommerce status establishes independent gateway verification.' );
		$assert( false === strpos( wp_json_encode( $payment_result ), 'B1-SECRET-SENTINEL' ) && false === strpos( wp_json_encode( $payment_result ), 'private-b1@' ), 'Payment read excludes secret metadata and unrelated customer data.' );
	}
	$payment_fixture->set_transaction_id( str_repeat( 'x', 129 ) );
	$payment_fixture->save();
	$payment_facts = YoRo_Order_Presenter::format_payment_context( $payment_fixture );
	$assert( null === $payment_facts['transaction_id'], 'Oversized transaction identity stays unavailable instead of being truncated.' );
} finally {
	$payment_fixture->delete( true );
}
$missing_payment_order = YoRo_Orders_Read_Service::get_payment_context( [ 'order_id' => $payment_fixture->get_id() ?: PHP_INT_MAX ] );
$assert( is_wp_error( $missing_payment_order ), 'Missing payment targets fail closed.' );

$link_action = YoRo_Action_Registry::get( 'orders.get_payment_link' );
$assert( 'yoro_view_store_data' === $link_action['permission'] && 'low' === $link_action['risk_level'], 'B2 retains established B1 read permission and risk policy.' );
$assert( 1 === $link_action['max_targets'] && ! $link_action['write_action'], 'B2 is exactly one bounded read.' );
foreach ( [ [], [ 'order_id' => 0 ], [ 'order_id' => -1 ], [ 'order_id' => '12' ], [ 'order_id' => 1.5 ], [ 'order_id' => true ], [ 'order_id' => [ 1 ] ], [ 'order_id' => 1, 'order_ids' => [ 1 ] ], [ 'order_id' => 1, 'gateway' => 'secret' ], [ 'order_id' => 1, '_yoro_preview' => 'yes' ] ] as $invalid_link_params ) {
	$invalid_link = YoRo_Orders_Read_Service::get_payment_link( $invalid_link_params );
	$assert( is_wp_error( $invalid_link ) && 'invalid_params' === $invalid_link->get_error_code(), 'B2 rejects malformed, extra and bulk parameters.' );
}
wp_set_current_user( 0 );
$link_denied = YoRo_Action_Executor::execute( 'orders.get_payment_link', [ 'order_id' => 1 ] );
$assert( is_wp_error( $link_denied ) && 'yoro_permission_denied' === $link_denied->get_error_code(), 'B2 is unavailable without store read permission.' );
wp_set_current_user( $admin_id );
$link_order = wc_create_order();
$url_calls = 0;
$url_probe = static function ( $url ) use ( &$url_calls ) { ++$url_calls; return $url; };
add_filter( 'woocommerce_get_checkout_payment_url', $url_probe );
try {
	foreach ( [ 'pending', 'failed', 'processing', 'completed', 'on-hold', 'cancelled', 'refunded' ] as $status ) {
		foreach ( [ '0.00', '100.00' ] as $total ) {
			$link_order->set_status( $status );
			$link_order->set_total( $total );
			$link_order->save();
			$url_calls = 0;
			$link_result = YoRo_Orders_Read_Service::get_payment_link( [ 'order_id' => $link_order->get_id() ] );
			$assert( ! is_wp_error( $link_result ), 'B2 reads existing orders regardless of payment eligibility.' );
			$link = $link_result['payment_link'];
			$needs = $link_order->needs_payment();
			$assert( $needs === $link['needs_payment'] && $needs === $link['payment_link_available'], 'B2 uses current WooCommerce eligibility for every status/total pair.' );
			$assert( ( $needs ? 1 : 0 ) === $url_calls, 'B2 asks for a URL only when the owner reports payment is needed.' );
			$assert( ( $needs ? $link_order->get_checkout_payment_url() : null ) === $link['payment_url'], 'B2 returns the exact WooCommerce URL or null.' );
		}
	}
	$link_order->set_status( 'pending' );
	$link_order->set_total( '100.00' );
	$link_order->save();
	$filtered_url = 'https://example.invalid/provider/custom/?key=wc_order_fixture_only&label=provider_data';
	$url_filter = static function () use ( $filtered_url ) { return $filtered_url; };
	add_filter( 'woocommerce_get_checkout_payment_url', $url_filter, 99 );
	try {
		$filtered = YoRo_Orders_Read_Service::get_payment_link( [ 'order_id' => $link_order->get_id() ] );
		$assert( $filtered_url === $filtered['payment_link']['payment_url'], 'Filter-authored URL remains opaque, unchanged data.' );
		$payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $filtered, 'orders.get_payment_link' );
		$assert( $filtered_url === $payload['data']['payment_link']['payment_url'], 'Canonical result preserves the capability URL.' );
		$context = YoRo_Orders_Read_Service::get_payment_context( [ 'order_id' => $link_order->get_id() ] );
		$details = YoRo_Orders_Read_Service::get_order_details( [ 'order_id' => $link_order->get_id() ] );
		$assert( false === strpos( wp_json_encode( [ $context, $details ] ), 'wc_order_fixture_only' ), 'B2 never adds its URL to B1 or generic details.' );
	} finally {
		remove_filter( 'woocommerce_get_checkout_payment_url', $url_filter, 99 );
	}
	foreach ( [ 'javascript:alert(1)', '', str_repeat( 'x', 4097 ) ] as $invalid_url ) {
		$invalid_filter = static function () use ( $invalid_url ) { return $invalid_url; };
		add_filter( 'woocommerce_get_checkout_payment_url', $invalid_filter, 99 );
		try {
			$invalid_result = YoRo_Orders_Read_Service::get_payment_link( [ 'order_id' => $link_order->get_id() ] );
			$assert( is_wp_error( $invalid_result ), 'Malformed filter URL fails closed without reconstruction or truncation.' );
		} finally {
			remove_filter( 'woocommerce_get_checkout_payment_url', $invalid_filter, 99 );
		}
	}
	$not_payable = static function () { return false; };
	add_filter( 'woocommerce_order_needs_payment', $not_payable, 99 );
	try {
		$url_calls = 0;
		$filtered = YoRo_Orders_Read_Service::get_payment_link( [ 'order_id' => $link_order->get_id() ] );
		$assert( null === $filtered['payment_link']['payment_url'] && 0 === $url_calls, 'Owner eligibility filter overrides status without a duplicate payment engine.' );
	} finally {
		remove_filter( 'woocommerce_order_needs_payment', $not_payable, 99 );
	}
} finally {
	remove_filter( 'woocommerce_get_checkout_payment_url', $url_probe );
	$link_id = $link_order->get_id();
	$link_order->delete( true );
}
$assert( is_wp_error( YoRo_Orders_Read_Service::get_payment_link( [ 'order_id' => $link_id ] ) ), 'Missing order cannot expose a payment link.' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo action catalog and executor REST smoke contract passed.\n";
