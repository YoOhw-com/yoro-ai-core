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

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo action catalog and executor REST smoke contract passed.\n";
