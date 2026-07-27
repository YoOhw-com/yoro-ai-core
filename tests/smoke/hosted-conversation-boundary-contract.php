<?php
/**
 * YoRo hosted conversation boundary smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/hosted-conversation-boundary-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'YoRo_AI_Settings' ), 'YoRo_AI_Settings is not loaded.' );
$assert( class_exists( 'YoRo_AI_API_Client' ), 'YoRo_AI_API_Client is not loaded.' );

if ( ! class_exists( 'YoRo_AI_Settings' ) || ! class_exists( 'YoRo_AI_API_Client' ) ) {
	echo wp_json_encode( [ 'success' => false, 'failures' => $failures ], JSON_PRETTY_PRINT ) . PHP_EOL;
	exit( 1 );
}

$option_names = [
	YoRo_AI_Settings::OPTION_AI_API_URL,
	YoRo_AI_Settings::OPTION_AI_API_SITE_KEY,
	YoRo_AI_Settings::OPTION_AI_API_ENABLED,
	YoRo_AI_Settings::OPTION_AI_API_STATUS,
	YoRo_AI_Settings::OPTION_INSTALLATION_UUID,
];
$old_options = [];

foreach ( $option_names as $option_name ) {
	$old_options[ $option_name ] = get_option( $option_name, null );
}

$api_url = 'http://yoro-conversation-boundary.test/api/v1';
$plain_key = 'yoro_ai_sk_conversation_boundary_smoke';
$response_mode = 'success';
$conversation_request_count = 0;
$conversation_show_request_count = 0;

$http_filter = static function ( $preempt, $args, $url ) use ( &$response_mode, &$conversation_request_count, &$conversation_show_request_count ) {
	if ( false !== strpos( (string) $url, '/sites/handshake' ) ) {
		$connection_id = 17;

		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'success' => true,
					'site' => [ 'id' => 17, 'name' => 'Boundary Smoke', 'url' => home_url(), 'status' => 'active', 'plan' => 'trial' ],
					'organization' => [ 'id' => 17, 'name' => 'Boundary Smoke' ],
					'account_connection' => [ 'connected' => true, 'status' => 'active' ],
					'connection' => [
						'id' => $connection_id,
						'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(),
						'status' => 'active',
					],
					'credential' => [
						'id' => 17,
						'type' => 'wordpress_connection',
						'site_connection_id' => $connection_id,
						'expires_at' => gmdate( 'c', time() + DAY_IN_SECONDS ),
					],
					'entitlements' => [ 'conversation' => true, 'usage_plan' => 'trial' ],
					'usage' => [ 'credit_limit' => 100, 'credits_used' => 0, 'credits_remaining' => 100 ],
				]
			),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	if ( false !== strpos( (string) $url, '/conversations/conversation_boundary_smoke' ) ) {
		++$conversation_show_request_count;

		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'success'      => true,
					'conversation' => [
						'id'                       => 'conversation_boundary_smoke',
						'status'                   => 'active',
						'title'                    => 'Order 7199',
						'last_message_at'          => gmdate( 'c' ),
						'pending_operation_graphs' => [],
						'messages'                 => [
							[
								'id'      => 1,
								'role'    => 'user',
								'content' => 'Open order 7199',
								'payload' => [
									'schema_version' => 1,
									'type'           => 'user_message',
									'stage'          => 'received',
								],
							],
							[
								'id'      => 2,
								'role'    => 'assistant',
								'content' => 'I will open order 7199.',
								'payload' => [
									'schema_version'         => 1,
									'type'                   => 'assistant_message',
									'stage'                  => 'action_plan',
									'mode'                   => 'task_request',
									'action'                 => 'orders.get_details',
									'language'               => 'en',
									'execution_required'     => true,
									'requires_confirmation'  => false,
									'pending_operation_token' => 'pending_boundary_smoke',
									'assistant'              => [
										'language' => 'en',
										'message'  => 'I will open order 7199.',
									],
									'action_plan'            => [
										'type'            => 'single',
										'step_count'      => 1,
										'actions'         => [ 'orders.get_details' ],
										'operation_ids'   => [ 'request_1' ],
									],
								],
							],
							[
								'id'      => 3,
								'role'    => 'assistant',
								'content' => 'Order 7199 loaded.',
								'payload' => [
									'stage'       => 'action_event',
									'event_type'  => 'succeeded',
									'status'      => 'succeeded',
									'action'      => 'orders.get_details',
									'result'      => [
										'success'      => true,
										'display_type' => 'order_details',
										'orders'       => [
											[
												'id'     => 7199,
												'number' => '7199',
												'status' => 'processing',
											],
										],
									],
								],
							],
						],
					],
				]
			),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	if ( false === strpos( (string) $url, '/conversations/message' ) ) {
		return $preempt;
	}

	++$conversation_request_count;

	if ( 'system_error' === $response_mode ) {
		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'conversation_id' => 'conversation_boundary_smoke',
					'stage' => 'error',
					'mode' => 'system_error',
					'success' => false,
					'language' => 'en',
					'action' => null,
					'params' => [],
					'missing' => [],
					'ambiguities' => [],
					'operation_graph' => [
						'version' => 2,
						'operations' => [],
					],
					'action_plan' => [
						'type' => 'none',
						'action' => null,
						'params' => [],
						'steps' => [],
					],
					'response_outcome' => [
						'contract_type' => 'yoro.response_outcome',
						'schema_version' => 1,
						'outcome' => 'temporarily_unavailable',
						'status' => 'failed',
						'speech_act' => 'error_notice',
						'changed' => false,
						'execution_required' => false,
						'requires_confirmation' => false,
						'retryable' => true,
						'language' => 'en',
						'intent_class' => 'infrastructure_failure',
						'response_goal' => '',
						'source_response' => 'The AI service is temporarily unavailable. Nothing was changed; please try again.',
						'reason_codes' => [ 'semantic_provider_unavailable' ],
					],
					'assistant' => [
						'language' => 'en',
						'response_type' => 'error',
						'needs_input' => false,
						'message' => 'The AI service is temporarily unavailable. Nothing was changed; please try again.',
					],
				]
			),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	if ( 'error' === $response_mode ) {
		return [
			'headers'  => [
				'retry-after' => '7',
				'x-request-id' => 'req_boundary_503',
			],
			'body'     => wp_json_encode(
				[
					'error' => 'provider_temporarily_unavailable',
					'message' => 'The conversation service is temporarily unavailable.',
				]
			),
			'response' => [ 'code' => 503, 'message' => 'Service Unavailable' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	return [
		'headers'  => [],
		'body'     => wp_json_encode(
			[
				'conversation_id' => 'conversation_boundary_smoke',
				'stage' => 'action_plan',
				'mode' => 'task_request',
				'language' => 'en',
				'action' => 'orders.get_details',
				'params' => [ 'order_id' => 7199 ],
				'operation_graph' => [
					'version' => 2,
					'operations' => [[
						'id' => 'request_1',
						'action' => 'orders.get_details',
						'params' => [ 'order_id' => 7199 ],
					]],
				],
				'action_plan' => [
					'type' => 'single',
					'action' => 'orders.get_details',
					'params' => [ 'order_id' => 7199 ],
					'steps' => [[ 'id' => 'request_1', 'action' => 'orders.get_details', 'params' => [ 'order_id' => 7199 ] ]],
				],
				'semantic_request_set' => [
					'contract_type' => 'yoro.semantic_request_set',
					'schema_version' => 2,
					'mode' => 'task',
					'language' => [ 'bcp47' => 'en', 'script' => 'Latn' ],
					'conversation_relation' => 'new_task',
					'conversation_act' => 'none',
					'conversation_subject' => 'none',
					'question_scope' => 'none',
					'semantic_units' => [[
						'id' => 'request_1_operation_get_details_1',
						'kind' => 'operation',
						'request_id' => 'request_1',
						'canonical_key' => 'get_details',
						'context_source' => 'latest_user_message',
					]],
					'requests' => [[ 'id' => 'request_1', 'surface' => 'orders', 'operation' => 'get_details', 'target_type' => 'single_order' ]],
					'entity_references' => [],
					'context_dependencies' => [],
					'ambiguities' => [],
					'response_goal' => 'Open the requested order.',
					'confidence' => 0.98,
				],
				'response_outcome' => [
					'contract_type' => 'yoro.response_outcome',
					'schema_version' => 1,
					'outcome' => 'planned',
					'status' => 'pending',
					'speech_act' => 'future_plan',
					'changed' => false,
					'execution_required' => true,
					'requires_confirmation' => false,
					'language' => 'en',
					'intent_class' => 'executable_task',
					'response_goal' => 'Open the requested order.',
					'source_response' => '',
					'reason_codes' => [],
				],
				'assistant' => [ 'language' => 'en', 'message' => 'I will open order 7199.' ],
				'usage' => [
					'period' => '2026-07',
					'credit_limit' => 100,
					'credits_used' => 4,
					'credits_remaining' => 96,
				],
			]
		),
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'cookies'  => [],
		'filename' => null,
	];
};

add_filter( 'pre_http_request', $http_filter, 10, 3 );

try {
	update_option( YoRo_AI_Settings::OPTION_AI_API_URL, $api_url, false );
	update_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, 'yes', false );
	delete_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY );

	$status = YoRo_AI_Settings::save_connected_ai_api_credentials( $api_url, $plain_key );
	$assert( ! is_wp_error( $status ), 'The smoke credential could not be connected.' );

	$status['usage']['remaining'] = 0;
	YoRo_AI_Settings::update_ai_api_status( $status );
	$assert( ! YoRo_AI_Settings::ai_api_has_quota_remaining(), 'The smoke precondition did not store an exhausted local quota snapshot.' );

	$client = new YoRo_AI_API_Client();
	$result = $client->send_conversation_message( [
		'command' => 'Open order 7199',
		'action_catalog' => [],
	] );
	$decoded = is_string( $result ) ? json_decode( $result, true ) : null;

	$assert( ! is_wp_error( $result ), 'A stale zero-quota snapshot incorrectly blocked the authoritative conversation request.' );
	$assert( 1 === $conversation_request_count, 'The conversation request did not reach the hosted API exactly once.' );
	$assert( 'planned' === ( $decoded['response_outcome']['outcome'] ?? '' ), 'Conversation normalization dropped the unified response outcome.' );
	$assert( 2 === ( $decoded['semantic_request_set']['schema_version'] ?? 0 ), 'Conversation normalization did not preserve semantic request set v2.' );
	$assert( 'orders' === ( $decoded['semantic_request_set']['requests'][0]['surface'] ?? '' ), 'Conversation normalization dropped the semantic request set.' );
	$assert( 'latest_user_message' === ( $decoded['semantic_request_set']['semantic_units'][0]['context_source'] ?? '' ), 'Conversation normalization dropped semantic provenance.' );
	$assert( 96.0 === (float) ( YoRo_AI_Settings::get_ai_api_usage()['remaining'] ?? -1 ), 'Credit-shaped usage did not refresh the cached quota snapshot.' );

	$conversation_request = new WP_REST_Request( 'GET', '/yoro-ai-core/v1/chat/conversation' );
	$conversation_request->set_param( 'conversation_id', 'conversation_boundary_smoke' );
	$conversation_response = YoRo_REST_Chat_Message_Handler::get_conversation( $conversation_request );
	$conversation_data = $conversation_response instanceof WP_REST_Response
		? $conversation_response->get_data()
		: [];
	$assistant_messages = array_values(
		array_filter(
			(array) ( $conversation_data['messages'] ?? [] ),
			static function( $message ) {
				return is_array( $message ) && 'assistant' === ( $message['role'] ?? '' );
			}
		)
	);

	$assert( 1 === $conversation_show_request_count, 'Conversation hydration did not reach the hosted API exactly once.' );
	$assert( 3 === count( (array) ( $conversation_data['messages'] ?? [] ) ), 'Conversation hydration did not preserve the full transcript.' );
	$assert( 2 === count( $assistant_messages ), 'Conversation hydration did not preserve assistant and action-event messages.' );
	$assert( ! empty( $assistant_messages[0]['ui'] ), 'Flattened assistant_message payload did not produce hydration UI.' );
	$assert( 'orders.get_details' === ( $assistant_messages[0]['ui']['action'] ?? '' ), 'Hydrated assistant plan lost its canonical action.' );
	$assert( 'done' === ( $assistant_messages[0]['ui']['stage'] ?? '' ), 'A completed assistant plan retained its historical action_plan stage.' );
	$assert( 'assistant_message' === ( $assistant_messages[0]['ui']['result']['display_type'] ?? '' ), 'A completed assistant plan rendered as a false confirmation.' );
	$assert( empty( $assistant_messages[0]['ui']['requires_confirmation'] ), 'A completed assistant plan incorrectly required confirmation after hydration.' );
	$assert( ! empty( $assistant_messages[1]['ui'] ), 'Flattened action_event payload did not produce hydration UI.' );
	$assert( 'order_details' === ( $assistant_messages[1]['ui']['result']['display_type'] ?? '' ), 'Hydrated action result lost its display contract.' );

	$response_mode = 'system_error';
	$system_error = $client->send_conversation_message( [ 'command' => 'Try again' ] );
	$system_error_decoded = is_string( $system_error ) ? json_decode( $system_error, true ) : null;

	$assert( ! is_wp_error( $system_error ), 'A structured system error was incorrectly converted to WP_Error.' );
	$assert( 'system_error' === ( $system_error_decoded['mode'] ?? '' ), 'Conversation normalization changed the system error into another mode.' );
	$assert( 'error' === ( $system_error_decoded['assistant']['response_type'] ?? '' ), 'Conversation normalization dropped the assistant error response type.' );
	$assert( false === ( $system_error_decoded['assistant']['needs_input'] ?? true ), 'A system error incorrectly requested more user input.' );
	$assert( 'temporarily_unavailable' === ( $system_error_decoded['response_outcome']['outcome'] ?? '' ), 'Conversation normalization dropped the temporary-unavailability outcome.' );
	$assert( true === ( $system_error_decoded['response_outcome']['retryable'] ?? false ), 'Conversation normalization dropped the retryable system error flag.' );

	$response_mode = 'error';
	$error = $client->send_conversation_message( [ 'command' => 'Try again' ] );
	$error_data = is_wp_error( $error ) ? $error->get_error_data() : [];

	$assert( is_wp_error( $error ), 'A hosted 503 response was not preserved as WP_Error.' );
	$assert( 503 === ( $error_data['status'] ?? 0 ), 'The hosted error status was lost.' );
	$assert( 'provider_temporarily_unavailable' === ( $error_data['error'] ?? '' ), 'The hosted error code was lost.' );
	$assert( 7 === ( $error_data['retry_after'] ?? 0 ), 'The hosted Retry-After value was lost.' );
	$assert( 'req_boundary_503' === ( $error_data['request_id'] ?? '' ), 'The hosted request id was lost.' );
} finally {
	remove_filter( 'pre_http_request', $http_filter, 10 );

	foreach ( $old_options as $option_name => $old_value ) {
		if ( null === $old_value ) {
			delete_option( $option_name );
		} else {
			update_option( $option_name, $old_value, false );
		}
	}
}

echo wp_json_encode(
	[
		'success' => [] === $failures,
		'failures' => $failures,
	],
	JSON_PRETTY_PRINT
) . PHP_EOL;

exit( [] === $failures ? 0 : 1 );
