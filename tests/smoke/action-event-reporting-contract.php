<?php
/**
 * YoRo action event reporting smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/action-event-reporting-contract.php
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

$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'YoRo_Action_Registry' ), 'YoRo_Action_Registry is not loaded.' );
$assert( class_exists( 'YoRo_REST_API' ), 'YoRo_REST_API is not loaded.' );
$assert( class_exists( 'WP_REST_Request' ), 'WP_REST_Request is not available.' );
$assert( class_exists( 'YoRo_AI_Settings' ), 'YoRo_AI_Settings is not loaded.' );

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

if ( class_exists( 'YoRo_Action_Registry' ) ) {
	YoRo_Action_Registry::register(
		'yoro_test.step_one',
		[
			'label'       => 'YoRo test step one',
			'description' => 'Smoke-test action event reporting step one.',
			'permission'  => 'manage_woocommerce',
			'risk_level'  => 'low',
			'max_items'   => 1,
			'callback'    => static function ( $params ) {
				return [
					'success'      => true,
					'action'       => 'yoro_test.step_one',
					'message'      => 'Step one done.',
					'processed'    => [ sanitize_text_field( $params['item'] ?? 'one' ) ],
					'count'        => 1,
					'read_only'    => false,
					'result_type'  => 'generic',
					'display_type' => 'generic',
				];
			},
		]
	);

	YoRo_Action_Registry::register(
		'yoro_test.step_two',
		[
			'label'       => 'YoRo test step two',
			'description' => 'Smoke-test action event reporting step two.',
			'permission'  => 'manage_woocommerce',
			'risk_level'  => 'low',
			'max_items'   => 1,
			'callback'    => static function ( $params ) {
				return [
					'success'      => true,
					'action'       => 'yoro_test.step_two',
					'message'      => 'Step two done.',
					'processed'    => [ sanitize_text_field( $params['item'] ?? 'two' ) ],
					'count'        => 1,
					'read_only'    => false,
					'result_type'  => 'generic',
					'display_type' => 'generic',
				];
			},
		]
	);
}

$conversation_id = 'conv-step-smoke';
$pending_token   = 'pending-step-smoke';
$api_url         = 'http://yoro-ai-smoke.local/api/v1';
$captured_http   = [];

if ( ! defined( 'YORO_AI_API_SITE_KEY' ) ) {
	define( 'YORO_AI_API_SITE_KEY', 'yoro_ai_smoke_site_key' );
}

$old_options = [
	YoRo_AI_Settings::OPTION_AI_API_URL      => get_option( YoRo_AI_Settings::OPTION_AI_API_URL, null ),
	YoRo_AI_Settings::OPTION_AI_API_SITE_KEY => get_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, null ),
	YoRo_AI_Settings::OPTION_AI_API_ENABLED  => get_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, null ),
	YoRo_AI_Settings::OPTION_AI_API_STATUS   => get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, null ),
];

update_option( YoRo_AI_Settings::OPTION_AI_API_URL, $api_url, false );
update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, 'yoro_ai_smoke_site_key', false );
update_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, 'yes', false );

$installation_uuid = YoRo_AI_Settings::get_installation_uuid();

YoRo_AI_Settings::update_ai_api_status(
	[
		'account_connection' => [
			'connected' => true,
			'status'    => 'active',
		],
		'organization' => [
			'id'   => 1,
			'name' => 'Action Event Smoke Organization',
		],
		'site' => [
			'id'     => 1,
			'name'   => 'Action Event Smoke Site',
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

$http_filter = static function ( $preempt, $args, $url ) use ( &$captured_http, $conversation_id, $pending_token ) {
	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$body   = [];

	if ( ! empty( $args['body'] ) && is_string( $args['body'] ) ) {
		$decoded = json_decode( $args['body'], true );
		$body    = is_array( $decoded ) ? $decoded : [];
	}

	$captured_http[] = [
		'method' => $method,
		'url'    => $url,
		'body'   => $body,
	];

	$response = static function ( $body ) {
		return [
			'headers'  => [],
			'body'     => wp_json_encode( $body ),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	};

	if ( false !== strpos( $url, '/conversations/' . rawurlencode( $conversation_id ) ) ) {
		return $response(
			[
				'success'      => true,
				'conversation' => [
					'conversation_id'           => $conversation_id,
					'pending_operation_graphs'  => [
						[
							'token'           => $pending_token,
							'status'          => 'confirmation_required',
							'action'          => 'multi_action',
							'operation_graph' => [
								'version'    => 1,
								'operations' => [
									[
										'id'        => 'op_step_one',
										'operation' => 'test_step_one',
										'action'    => 'yoro_test.step_one',
										'params'    => [
											'item' => 'one',
										],
										'user_content_slots' => [
											[
												'operation_id' => 'op_step_one',
												'operation_key' => 'test_step_one',
												'action' => 'yoro_test.step_one',
												'field' => 'note',
												'value' => 'Preserve this internal note',
												'content_scope' => 'internal_note',
												'provenance' => 'user_literal',
												'source_text_from_latest_user_message' => 'Preserve this internal note',
												'confidence' => 0.97,
											],
										],
										'position'  => 1,
									],
									[
										'id'        => 'op_step_two',
										'operation' => 'test_step_two',
										'action'    => 'yoro_test.step_two',
										'params'    => [
											'item' => 'two',
										],
										'user_content_slots' => [
											[
												'operation_id' => 'op_step_two',
												'operation_key' => 'test_step_two',
												'action' => 'yoro_test.step_two',
												'field' => 'instruction',
												'value' => 'Preserve this customer email goal',
												'content_scope' => 'customer_email',
												'provenance' => 'user_goal',
												'source_text_from_latest_user_message' => 'Preserve this customer email goal',
												'confidence' => 0.93,
											],
										],
										'position'  => 2,
									],
								],
							],
							'action_plan'     => [
								'type'   => 'sequence',
								'action' => 'multi_action',
								'steps'  => [
									[
										'id'       => 'op_step_one',
										'label'    => 'Step one',
										'action'   => 'yoro_test.step_one',
										'params'   => [
											'item' => 'one',
										],
										'on_error' => 'stop',
										'retry'    => [
											'max_attempts' => 1,
										],
									],
									[
										'id'       => 'op_step_two',
										'label'    => 'Step two',
										'action'   => 'yoro_test.step_two',
										'params'   => [
											'item' => 'two',
										],
										'on_error' => 'stop',
										'retry'    => [
											'max_attempts' => 1,
										],
									],
								],
							],
							'context'         => [],
						],
					],
				],
			]
		);
	}

	if ( false !== strpos( $url, '/actions/confirm' ) ) {
		return $response(
			[
				'success'                 => true,
				'stage'                   => 'confirmed',
				'conversation_id'         => $conversation_id,
				'pending_operation_token' => $pending_token,
			]
		);
	}

	if ( false !== strpos( $url, '/actions/execute' ) ) {
		return $response(
			[
				'success'                 => true,
				'conversation_id'         => $conversation_id,
				'pending_operation_token' => $pending_token,
				'pending_operation_status' => $body['status'] ?? 'executed',
				'action_event'            => [
					'id'         => count( $captured_http ),
					'event_type' => $body['event_type'] ?? 'executed',
					'status'     => $body['status'] ?? 'executed',
					'action'     => $body['action'] ?? '',
				],
			]
		);
	}

	return $preempt;
};

add_filter( 'pre_http_request', $http_filter, 10, 3 );

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

$execute_response = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/execute',
	[
		'confirm'                 => true,
		'preview'                 => false,
		'conversation_id'         => $conversation_id,
		'pending_operation_token' => $pending_token,
	]
);

remove_filter( 'pre_http_request', $http_filter, 10 );

foreach ( $old_options as $option_name => $option_value ) {
	if ( null === $option_value ) {
		delete_option( $option_name );
	} else {
		update_option( $option_name, $option_value, false );
	}
}

$execute_data = (array) $execute_response['data'];
$trace_id     = sanitize_text_field( (string) ( $execute_data['trace_id'] ?? '' ) );

$execution_payloads = [];

foreach ( $captured_http as $request ) {
	if ( false !== strpos( $request['url'], '/actions/execute' ) ) {
		$execution_payloads[] = $request['body'];
	}
}

$assert( 200 === (int) $execute_response['status'], 'Confirmed sequence execute smoke request must return HTTP 200.' );
$assert( true === (bool) ( $execute_data['success'] ?? false ), 'Confirmed sequence execute smoke request must return success=true.' );
$assert( 'done' === ( $execute_data['stage'] ?? '' ), 'Confirmed sequence execute smoke request must return stage=done.' );
$assert( true === (bool) ( $execute_data['result']['multi_action'] ?? false ), 'Confirmed sequence result must be multi_action.' );
$assert( 2 === count( $execution_payloads ), 'Confirmed sequence must report one action execution event per operation step.' );

if ( 2 === count( $execution_payloads ) ) {
	$first_payload  = $execution_payloads[0];
	$second_payload = $execution_payloads[1];

	$assert( 'op_step_one' === ( $first_payload['operation_id'] ?? '' ), 'First execution event must include operation_id=op_step_one.' );
	$assert( 'op_step_two' === ( $second_payload['operation_id'] ?? '' ), 'Second execution event must include operation_id=op_step_two.' );
	$assert( 'yoro_test.step_one' === ( $first_payload['action'] ?? '' ), 'First execution event must report the first step action.' );
	$assert( 'yoro_test.step_two' === ( $second_payload['action'] ?? '' ), 'Second execution event must report the second step action.' );
	$assert( 'executed' === ( $first_payload['event_type'] ?? '' ), 'First execution event must use event_type=executed.' );
	$assert( 'executed' === ( $second_payload['event_type'] ?? '' ), 'Second execution event must use event_type=executed.' );
	$assert( 'executed' === ( $first_payload['status'] ?? '' ), 'First execution event must use status=executed.' );
	$assert( 'executed' === ( $second_payload['status'] ?? '' ), 'Second execution event must use status=executed.' );
	$assert( $pending_token === ( $first_payload['pending_operation_token'] ?? '' ), 'First execution event must include the pending operation token.' );
	$assert( $pending_token === ( $second_payload['pending_operation_token'] ?? '' ), 'Second execution event must include the pending operation token.' );
	$assert( 'Step one done.' === ( $first_payload['summary'] ?? '' ), 'First execution event summary must come from the first step result.' );
	$assert( 'Step two done.' === ( $second_payload['summary'] ?? '' ), 'Second execution event summary must come from the second step result.' );
	$assert( 'one' === ( $first_payload['params']['item'] ?? '' ), 'First execution event params must come from the first step.' );
	$assert( 'two' === ( $second_payload['params']['item'] ?? '' ), 'Second execution event params must come from the second step.' );
	$assert( 'note' === ( $first_payload['user_content_slots'][0]['field'] ?? '' ), 'First execution event must preserve the operation user-content slot field.' );
	$assert( 'Preserve this internal note' === ( $first_payload['user_content_slots'][0]['value'] ?? '' ), 'First execution event must preserve the operation user-content slot value.' );
	$assert( 'internal_note' === ( $first_payload['user_content_slots'][0]['content_scope'] ?? '' ), 'First execution event must preserve internal note slot scope.' );
	$assert( 'user_literal' === ( $first_payload['user_content_slots'][0]['provenance'] ?? '' ), 'First execution event must preserve user_literal provenance.' );
	$assert( 'instruction' === ( $second_payload['user_content_slots'][0]['field'] ?? '' ), 'Second execution event must preserve the customer email slot field.' );
	$assert( 'customer_email' === ( $second_payload['user_content_slots'][0]['content_scope'] ?? '' ), 'Second execution event must preserve customer email slot scope.' );
	$assert( 'user_goal' === ( $second_payload['user_content_slots'][0]['provenance'] ?? '' ), 'Second execution event must preserve user_goal provenance.' );
	$assert( true === (bool) ( $first_payload['result']['success'] ?? false ), 'First execution event must include the first step result.' );
	$assert( true === (bool) ( $second_payload['result']['success'] ?? false ), 'Second execution event must include the second step result.' );

	if ( '' !== $trace_id ) {
		$assert( $trace_id . '_step_1' === ( $first_payload['trace_id'] ?? '' ), 'First execution event trace_id must be scoped to step 1.' );
		$assert( $trace_id . '_step_2' === ( $second_payload['trace_id'] ?? '' ), 'Second execution event trace_id must be scoped to step 2.' );
	}

	foreach ( $execution_payloads as $payload ) {
		$assert( 'multi_action' !== ( $payload['action'] ?? '' ), 'Sequence reporting must not send one aggregate multi_action execution event.' );
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo action event reporting smoke contract passed.\n";
