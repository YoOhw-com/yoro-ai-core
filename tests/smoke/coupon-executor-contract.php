<?php

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke through WordPress with wp eval-file.\n" );
	exit( 1 );
}

$plugin_root = WP_PLUGIN_DIR . '/yoro-ai-core';

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

$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

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
	delete_transient( 'yoro_rate_execute_' . $admin_id );
}

$original_ai_api_status = null;

if ( class_exists( 'YoRo_AI_Settings' ) ) {
	$original_ai_api_status = get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, null );
	$installation_uuid      = YoRo_AI_Settings::get_installation_uuid();

	YoRo_AI_Settings::update_ai_api_status(
		[
			'account_connection' => [
				'connected' => true,
				'status'    => 'active',
			],
			'organization' => [
				'id'   => 1,
				'name' => 'Coupon Smoke Organization',
			],
			'site' => [
				'id'     => 1,
				'name'   => 'Coupon Smoke Site',
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

$assert( class_exists( 'WooCommerce' ), 'WooCommerce must be active.' );
$assert( class_exists( 'YoRo_Action_Registry' ), 'YoRo action registry must be loaded.' );
$assert( function_exists( 'wc_get_coupon_id_by_code' ), 'WooCommerce coupon lookup helper must be available.' );

$coupon_code = 'YORO-SMOKE-' . gmdate( 'YmdHis' );
$expected_coupon_code = function_exists( 'wc_format_coupon_code' )
	? wc_format_coupon_code( $coupon_code )
	: strtolower( $coupon_code );

$preview_response = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/execute',
	[
		'action'  => 'coupons.create',
		'params'  => [
			'coupon_code'     => $coupon_code,
			'discount_type'   => 'percent',
			'discount_amount' => 7,
			'description'     => 'YoRo smoke test coupon',
			'usage_limit'     => 3,
		],
		'preview' => false,
		'confirm' => false,
	]
);
$preview_data = (array) $preview_response['data'];

$assert( 200 === (int) $preview_response['status'], 'Unconfirmed coupon create must return HTTP 200.' );
$assert( false === (bool) ( $preview_data['success'] ?? true ), 'Unconfirmed coupon create must not be successful.' );
$assert( 'confirm' === (string) ( $preview_data['stage'] ?? '' ), 'Unconfirmed coupon create must return stage=confirm.' );
$assert( true === (bool) ( $preview_data['confirm_required'] ?? false ), 'Unconfirmed coupon create must require confirmation.' );
$assert( true === (bool) ( $preview_data['direct_confirmation_allowed'] ?? false ), 'Direct coupon create must expose direct_confirmation_allowed=true.' );
$assert( 'confirmation' === (string) ( $preview_data['result']['display_type'] ?? '' ), 'Coupon create confirmation must render as display_type=confirmation.' );
$assert( ! empty( $preview_data['result']['preview']['coupon']['coupon_code'] ), 'Coupon create confirmation must include coupon preview details.' );

$create_response = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/execute',
	[
		'action'  => 'coupons.create',
		'params'  => [
			'coupon_code'     => $coupon_code,
			'discount_type'   => 'percent',
			'discount_amount' => 7,
			'description'     => 'YoRo smoke test coupon',
			'usage_limit'     => 3,
		],
		'preview' => false,
		'confirm' => true,
	]
);
$create_data = (array) $create_response['data'];
$create_result = isset( $create_data['result'] ) && is_array( $create_data['result'] ) ? $create_data['result'] : [];
$created_coupon = isset( $create_result['coupon'] ) && is_array( $create_result['coupon'] ) ? $create_result['coupon'] : [];
$created_coupon_id = absint( $create_result['coupon_id'] ?? ( $created_coupon['coupon_id'] ?? 0 ) );

$assert( 200 === (int) $create_response['status'], 'Confirmed coupon create must return HTTP 200.' );
$assert( true === (bool) ( $create_data['success'] ?? false ), 'Confirmed coupon create must be successful.' );
$assert( 'done' === (string) ( $create_data['stage'] ?? '' ), 'Confirmed coupon create must return stage=done.' );
$assert( 'coupon' === (string) ( $create_result['display_type'] ?? '' ), 'Confirmed coupon create result must render as display_type=coupon.' );
$assert( $created_coupon_id > 0, 'Confirmed coupon create must return coupon_id.' );
$assert( $expected_coupon_code === (string) ( $create_result['coupon_code'] ?? ( $created_coupon['coupon_code'] ?? '' ) ), 'Confirmed coupon create must return created coupon code.' );
$assert( '7.00% off' === (string) ( $created_coupon['discount_summary'] ?? '' ), 'Confirmed coupon create must return discount summary.' );

$lookup_response = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/execute',
	[
		'action'  => 'coupons.lookup',
		'params'  => [
			'coupon_code' => $coupon_code,
		],
		'preview' => false,
		'confirm' => false,
	]
);
$lookup_data = (array) $lookup_response['data'];
$lookup_result = isset( $lookup_data['result'] ) && is_array( $lookup_data['result'] ) ? $lookup_data['result'] : [];

$assert( 200 === (int) $lookup_response['status'], 'Coupon lookup must return HTTP 200.' );
$assert( true === (bool) ( $lookup_data['success'] ?? false ), 'Coupon lookup must be successful.' );
$assert( 'done' === (string) ( $lookup_data['stage'] ?? '' ), 'Coupon lookup must return stage=done.' );
$assert( 'coupon' === (string) ( $lookup_result['display_type'] ?? '' ), 'Coupon lookup result must render as display_type=coupon.' );
$assert( $created_coupon_id === absint( $lookup_result['coupon_id'] ?? 0 ), 'Coupon lookup must return the created coupon_id.' );
$assert( true === (bool) ( $lookup_result['read_only'] ?? false ), 'Coupon lookup must remain read_only.' );

$duplicate_preview = call_user_func(
	YoRo_Action_Registry::get( 'coupons.create' )['preview_callback'],
	[
		'coupon_code'     => $coupon_code,
		'discount_type'   => 'percent',
		'discount_amount' => 7,
	]
);

$assert( is_array( $duplicate_preview ), 'Duplicate coupon preview must return an array.' );
$assert( true === (bool) ( $duplicate_preview['blocked'] ?? false ), 'Duplicate coupon preview must block before approval.' );
$assert( 'coupon_already_exists' === (string) ( $duplicate_preview['block_reason'] ?? '' ), 'Duplicate coupon preview must expose coupon_already_exists.' );
$assert( false === (bool) ( $duplicate_preview['requires_confirmation'] ?? false ), 'Duplicate coupon preview must not require confirmation.' );
$assert( false === (bool) ( $duplicate_preview['execution_required'] ?? false ), 'Duplicate coupon preview must not expose execution_required.' );

$missing_preview = call_user_func(
	YoRo_Action_Registry::get( 'coupons.create' )['preview_callback'],
	[
		'coupon_code' => $coupon_code . '-MISSING',
	]
);

$assert( is_array( $missing_preview ), 'Missing discount preview must return an array.' );
$assert( true === (bool) ( $missing_preview['blocked'] ?? false ), 'Missing discount preview must block before approval.' );
$assert( in_array( (string) ( $missing_preview['block_reason'] ?? '' ), [ 'invalid_discount_type', 'invalid_discount_amount' ], true ), 'Missing discount preview must expose a discount validation block reason.' );
$assert( false === (bool) ( $missing_preview['requires_confirmation'] ?? false ), 'Missing discount preview must not require confirmation.' );
$assert( false === (bool) ( $missing_preview['execution_required'] ?? false ), 'Missing discount preview must not expose execution_required.' );

if ( $created_coupon_id > 0 ) {
	wp_delete_post( $created_coupon_id, true );
}

if ( class_exists( 'YoRo_AI_Settings' ) ) {
	if ( null === $original_ai_api_status ) {
		delete_option( YoRo_AI_Settings::OPTION_AI_API_STATUS );
	} else {
		update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $original_ai_api_status, false );
	}
}

if ( $failures ) {
	fwrite( STDERR, "CREATE_RESPONSE=" . wp_json_encode( $create_data ) . "\n" );
	fwrite( STDERR, "LOOKUP_RESPONSE=" . wp_json_encode( $lookup_data ) . "\n" );

	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo coupon executor smoke passed for {$coupon_code}.\n";
