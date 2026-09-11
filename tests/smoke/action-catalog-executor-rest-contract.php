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

$original_actor_access = $admin_id > 0 ? get_user_meta( $admin_id, YoRo_WordPress_Actor::ACCESS_META_KEY, true ) : '';
// This catalog fixture tests local administrator permissions, independent of a previous test's remote assignment cache.
if ( $admin_id > 0 ) { delete_user_meta( $admin_id, YoRo_WordPress_Actor::ACCESS_META_KEY ); }

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
	$filtered_url = "https://example.invalid/provider/o'reilly?key=wc_order_fixture_only&label=provider_data";
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
	foreach ( [ 'javascript:alert(1)', '', str_repeat( 'x', 4097 ), 'https://example.invalid/custom/o"reilly?key=wc_order_fixture_only', 'https://example.invalid/custom/o<reilly?key=wc_order_fixture_only', 'https://example.invalid/custom/o>reilly?key=wc_order_fixture_only' ] as $invalid_url ) {
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

// B4 is one distinct confirmed native-email action, never a custom composer or bulk sender.
$b4 = YoRo_Action_Registry::get( 'orders.send_transactional_email' );
$assert( is_array( $b4 ) && true === $b4['write_action'] && true === $b4['requires_confirmation'], 'B4 must always be a confirmed write.' );
$assert( 'communicate' === $b4['effect'] && true === $b4['external_side_effect'] && 'none' === $b4['reversibility'], 'B4 must retain external communication safety.' );
$assert( false === $b4['supports_bulk'] && 1 === $b4['max_targets'], 'B4 must never expose bulk targets.' );
$assert( [ 'order_id', 'expected_version', 'email_type' ] === array_keys( $b4['input_schema'] ), 'B4 has no recipient or content override slots.' );
$assert( [ 'customer_processing_order', 'customer_completed_order' ] === $b4['canonical_input_schema']['email_type']['enum'], 'B4 exposes only characterized native order-email types.' );
$assert( 'operation_journal' === $b4['idempotency_strategy'] && 'external_delivery' === $b4['concurrency_strategy'], 'B4 must retain external-delivery journal semantics.' );
$b4_custom = YoRo_Action_Registry::get( 'orders.send_customer_email' );
$assert( isset( $b4_custom['input_schema']['subject'], $b4_custom['input_schema']['body'] ) && $b4_custom['callback'] !== $b4['callback'], 'Existing custom email retains its separate content and handler.' );
$b4_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( [
    'success' => true, 'order_id' => 123, 'email_type' => 'customer_processing_order',
    'current_version' => str_repeat( 'a', 64 ), 'outcome' => 'mailer_accepted', 'confirmation_accepted' => true,
    'recipient_masked' => 'a***@example.invalid', 'recipient' => 'private@example.invalid',
    'body' => 'unrelated private content', 'delivered' => true,
], 'orders.send_transactional_email' );
$assert( ! isset( $b4_payload['data']['recipient'] ) && ! isset( $b4_payload['data']['body'] ) && ! isset( $b4_payload['data']['delivered'] ), 'Canonical B4 result excludes raw recipient, content and fabricated delivery evidence.' );
$assert( 'mailer_accepted' === $b4_payload['data']['outcome'] && false !== strpos( $b4_payload['summary'], 'delivery is not verified' ), 'B4 result distinguishes mailer acceptance from delivery.' );

// Exercise the real legacy REST ingress using a disposable DB and a process-level mail sink.
if ( '1' === getenv( 'YORO_NATIVE_EMAIL_NO_DELIVERY_TEST' ) ) {
	if ( '/usr/bin/true' !== ini_get( 'sendmail_path' ) ) {
		throw new RuntimeException( 'G003 REST tests require the native no-delivery sink.' );
	}
	wp_set_current_user( $admin_id );
	$native_order = new WC_Order();
	$native_order->set_billing_email( 'rest-fixture@example.invalid' );
	$native_order->set_date_created( time() );
	$native_order->set_status( 'processing' );
	$native_order->save();
	$native_params = [ 'order_id' => $native_order->get_id(), 'expected_version' => YoRo_Order_Presenter::order_version( wc_get_order( $native_order->get_id() ) ), 'email_type' => 'customer_processing_order' ];
	$native_plan = [ 'type' => 'single', 'action' => 'orders.send_transactional_email', 'params' => $native_params ];
	$saved_options = [ 'yoro_rate_limit_count' => get_option( 'yoro_rate_limit_count', null ) ];
	update_option( 'yoro_rate_limit_count', 1000, false );
	foreach ( [ YoRo_AI_Settings::OPTION_AI_API_URL, YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, YoRo_AI_Settings::OPTION_AI_API_ENABLED, YoRo_AI_Settings::OPTION_AI_API_STATUS ] as $key ) { $saved_options[ $key ] = get_option( $key, null ); }
	update_option( YoRo_AI_Settings::OPTION_AI_API_URL, 'https://fixture.example.invalid/api/yoro-ai', false );
	update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, 'fixture-local-only', false );
	update_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, 'yes', false );
	YoRo_AI_Settings::update_ai_api_status( [
		'account_connection' => [ 'connected' => true, 'status' => 'active' ],
		'organization' => [ 'id' => 1, 'name' => 'Fixture' ],
		'site' => [ 'id' => 1, 'name' => 'Fixture', 'url' => home_url(), 'status' => 'active', 'plan' => 'test' ],
		'connection' => [ 'id' => 1, 'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(), 'status' => 'active', 'plugin_version' => YORO_AI_CORE_VERSION ],
		'credential' => [ 'id' => 1, 'type' => 'wordpress_connection', 'site_connection_id' => 1, 'expires_at' => gmdate( 'c', time() + HOUR_IN_SECONDS ) ],
	] );
	$mode = 'success';
	$claimed = false;
	$claim_calls = 0;
	$native_acceptances = 0;
	$confirmation_material = [];
	$conversation = 'native-rest-fixture';
	$pending_token = 'native-pending-fixture';
	$http = static function ( $pre, $args, $url ) use ( &$mode, &$claimed, &$claim_calls, &$confirmation_material, &$native_plan, $conversation, $pending_token ) {
		$body = json_decode( $args['body'] ?? '{}', true );
		$response = array_merge( (array) get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, [] ), [ 'success' => true ] );
		if ( false !== strpos( $url, '/actions/confirm' ) ) {
			$confirmation_material = [ 'token' => str_repeat( 'p', 64 ), 'plan_hash' => $body['plan_hash'] ?? str_repeat( 'a', 64 ), 'preview_hash' => $body['preview_hash'] ?? str_repeat( 'b', 64 ), 'policy_version' => 4 ];
			if ( 'products.adjust_stock' === $native_plan['action'] ) { $confirmation_material['plan_hash'] = hash( 'sha256', wp_json_encode( $native_plan ) ); $confirmation_material['preview_hash'] = hash( 'sha256', 'C3 fixture preview' ); }
			if ( 'empty_binding' === $mode ) { $confirmation_material['plan_hash'] = ''; $confirmation_material['preview_hash'] = ''; $confirmation_material['policy_version'] = 0; }
			if ( 'malformed_binding' === $mode ) { $confirmation_material['plan_hash'] = [ 'invalid' ]; $confirmation_material['preview_hash'] = [ 'invalid' ]; }
			$response['execution_permit'] = $confirmation_material;
		} elseif ( false !== strpos( $url, '/actions/claim' ) ) {
			$claim_calls++;
			$response += [ 'stage' => 'claimed', 'pending_operation_status' => 'executing', 'conversation_id' => $conversation, 'pending_operation_token' => $pending_token, 'plan_hash' => $confirmation_material['plan_hash'], 'preview_hash' => $confirmation_material['preview_hash'], 'policy_version' => $confirmation_material['policy_version'] ];
			if ( $claimed || 'failed_claim' === $mode ) { $response = [ 'success' => false, 'message' => 'Fixture permit already consumed or refused.' ]; }
			elseif ( 'missing_claim' === $mode ) { $response = [ 'success' => true ]; }
			elseif ( 'mismatched_claim' === $mode ) { $response['plan_hash'] = str_repeat( '0', 64 ); }
			else { $claimed = true; }
		} elseif ( 'GET' === ( $args['method'] ?? 'GET' ) ) {
			$response['conversation'] = [ 'pending_operation_graphs' => [ [ 'token' => $pending_token, 'status' => 'awaiting_confirmation', 'action' => $native_plan['action'], 'action_plan' => $native_plan ] ] ];
		}
		return [ 'headers' => [], 'body' => wp_json_encode( $response ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
	};
	$observe = static function ( $sent, $type ) use ( &$native_acceptances, &$mode ) {
		if ( 'customer_processing_order' === $type && $sent ) {
			$native_acceptances++;
			if ( 'unknown' === $mode ) { throw new RuntimeException( 'Fixture observer lost after native acceptance.' ); }
		}
	};
	add_filter( 'pre_http_request', $http, 10, 3 );
	add_action( 'woocommerce_email_sent', $observe, 1, 2 );
	$invoke = static function ( $with_token = true ) use ( &$native_params, &$native_plan, $conversation, $pending_token ) {
		$request = new WP_REST_Request( 'POST', '/yoro/v1/execute' );
		$request->set_param( 'action', $native_plan['action'] );
		$request->set_param( 'params', $native_params );
		$request->set_param( 'confirm', true );
		if ( $with_token ) { $request->set_param( 'conversation_id', $conversation ); $request->set_param( 'pending_operation_token', $pending_token ); }
		$result = YoRo_REST_Execute_Handler::handle( $request );
		return $result instanceof WP_REST_Response ? $result->get_data() : $result;
	};
	try {
		$fixture_status = get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS );
		YoRo_AI_Settings::save_connected_ai_api_credentials( 'https://fixture.example.invalid/api/yoro-ai', 'fixture-local-only' );
		update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $fixture_status, false );
		$approval_request = new WP_REST_Request( 'POST', '/yoro/v1/execute' );
		$approval_request->set_param( 'action', 'orders.send_transactional_email' );
		$approval_request->set_param( 'params', $native_params );
		$approval = YoRo_REST_Execute_Handler::handle( $approval_request );
		$approval = $approval instanceof WP_REST_Response ? $approval->get_data() : $approval;
		$assert( is_array( $approval ) && false === ( $approval['direct_confirmation_allowed'] ?? null ), 'B4 preview does not offer unsupported direct confirmation.' );
		$direct = $invoke( false );
		$assert( 0 === $native_acceptances && ( is_wp_error( $direct ) || empty( $direct['success'] ) ), 'Direct B4 confirm without pending permit cannot send.' );
		$forged = $native_plan + [ 'context' => [ 'connector_remote_agent' => true, 'workflow_id' => 'forged', 'operation_id' => 'forged', 'claimed_execution_context' => true, '_execution_permit' => 'forged', 'is_undo' => true ] ];
		YoRo_Plan_Executor::execute( $forged, true, false );
		$sequence = [ 'type' => 'sequence', 'context' => $forged['context'], 'steps' => [ $native_plan + [ 'retry' => [ 'max_attempts' => 3 ] ] ] ];
		YoRo_Plan_Executor::execute( $sequence, true, false );
		$assert( 0 === $native_acceptances, 'Caller plan/context and sequence retry flags cannot forge B4 permit or journal ownership.' );
		foreach ( [ 'failed_claim', 'missing_claim' ] as $failure_mode ) {
			$mode = $failure_mode;
			$failed = $invoke();
			$assert( 0 === $native_acceptances && ( is_wp_error( $failed ) || empty( $failed['success'] ) ), 'Failed or incomplete claim fails closed: ' . $failure_mode );
		}
		$mode = 'success';
		$success = $invoke();
		$assert( 1 === $native_acceptances && is_array( $success ) && ! empty( $success['success'] ), 'Verified same-request claimed permit reaches native B4 exactly once.' );
		$repeat = $invoke();
		$assert( 1 === $native_acceptances && ( is_wp_error( $repeat ) || empty( $repeat['success'] ) ), 'Consumed legacy permit cannot execute B4 a second time.' );
		$claimed = false; // A separate fixture operation, not an application reset or retry.
		$mode = 'unknown';
		$unknown = $invoke();
		$assert( 2 === $native_acceptances && is_array( $unknown ) && empty( $unknown['success'] ), 'Native acceptance followed by observer exception is reported as unknown failure.' );
		$repeat_unknown = $invoke();
		$assert( 2 === $native_acceptances && ( is_wp_error( $repeat_unknown ) || empty( $repeat_unknown['success'] ) ), 'A consumed permit after ambiguous native acceptance cannot blindly resend.' );
		$assert( $claim_calls >= 5, 'REST test exercises the coordinator claim path rather than a synthetic plan trust flag.' );
		$c3_product = new WC_Product_Simple(); $c3_stock_setting = get_option( 'woocommerce_manage_stock', 'yes' );
		try {
			update_option( 'woocommerce_manage_stock', 'yes' );
			$c3_product->set_name( 'C3 REST fixture' ); $c3_product->set_manage_stock( true ); $c3_product->set_stock_quantity( 10 ); $c3_product->save();
			$native_params = [ 'product_id'=>$c3_product->get_id(), 'stock_delta'=>2, 'expected_version'=>YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $c3_product->get_id() ) ) ];
			$native_plan = [ 'type'=>'single', 'action'=>'products.adjust_stock', 'params'=>$native_params ];
			$claimed = false;
			$before_claim_calls = $claim_calls;
			foreach ( [ 'missing_claim', 'mismatched_claim', 'empty_binding', 'malformed_binding' ] as $failure_mode ) {
				$mode = $failure_mode; $claimed = false;
				$failure = $invoke(); clean_post_cache( $c3_product->get_id() );
				$assert( ( is_wp_error( $failure ) || empty( $failure['success'] ) ) && 10 === (int) wc_get_product( $c3_product->get_id() )->get_stock_quantity(), 'C3 REST must refuse absent/mismatched claimed response before arithmetic.' );
			}
			$mode = 'success'; $claimed = false;
			$c3_success = $invoke(); if ( is_wp_error( $c3_success ) || empty( $c3_success['success'] ) ) { fwrite( STDERR, wp_json_encode( $c3_success ) . PHP_EOL ); } clean_post_cache( $c3_product->get_id() );
			$assert( is_array( $c3_success ) && ! empty( $c3_success['success'] ) && 12 === (int) wc_get_product( $c3_product->get_id() )->get_stock_quantity(), 'Actual REST confirm+claim must mint the one-use C3 handoff.' );
			$c3_repeat = $invoke(); clean_post_cache( $c3_product->get_id() );
			$assert( ( is_wp_error( $c3_repeat ) || empty( $c3_repeat['success'] ) ) && 12 === (int) wc_get_product( $c3_product->get_id() )->get_stock_quantity(), 'Consumed C3 REST operation must never adjust twice.' );
			$assert( $claim_calls >= $before_claim_calls + 3, 'C3 REST evidence must traverse real claim response validation.' );
		} finally { $c3_product->delete( true ); update_option( 'woocommerce_manage_stock', $c3_stock_setting ); }

	} finally {
		remove_filter( 'pre_http_request', $http, 10 );
		remove_action( 'woocommerce_email_sent', $observe, 1 );
		foreach ( $saved_options as $key => $value ) { if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value, false ); } }
		$native_order->delete( true );
	}
}

if ( $admin_id > 0 ) {
	if ( '' === $original_actor_access ) { delete_user_meta( $admin_id, YoRo_WordPress_Actor::ACCESS_META_KEY ); }
	else { update_user_meta( $admin_id, YoRo_WordPress_Actor::ACCESS_META_KEY, $original_actor_access ); }
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo action catalog and executor REST smoke contract passed.\n";
