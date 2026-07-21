<?php
/**
 * Run with wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/execution-permit-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this contract through WordPress.\n" );
	exit( 1 );
}

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$api_url = 'http://yoro-safety.local/api/v1';
$old_options = [
	YoRo_AI_Settings::OPTION_AI_API_URL => get_option( YoRo_AI_Settings::OPTION_AI_API_URL, null ),
	YoRo_AI_Settings::OPTION_AI_API_SITE_KEY => get_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, null ),
	YoRo_AI_Settings::OPTION_AI_API_ENABLED => get_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, null ),
];

update_option( YoRo_AI_Settings::OPTION_AI_API_URL, $api_url, false );
update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, 'permit-contract-key', false );
update_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, 'yes', false );

$captured = [];
$http_filter = static function ( $preempt, $args, $url ) use ( &$captured ) {
	$body = json_decode( (string) ( $args['body'] ?? '' ), true );
	$body = is_array( $body ) ? $body : [];
	$captured[] = [ 'url' => $url, 'body' => $body ];
	$response_body = [ 'success' => true ];

	if ( false !== strpos( $url, '/actions/confirm' ) ) {
		$response_body['execution_permit'] = [
			'token' => str_repeat( 'p', 64 ),
			'plan_hash' => str_repeat( 'a', 64 ),
			'preview_hash' => str_repeat( 'b', 64 ),
			'policy_version' => 4,
		];
	} elseif ( false !== strpos( $url, '/actions/claim' ) ) {
		$response_body['plan_hash'] = str_repeat( 'a', 64 );
		$response_body['preview_hash'] = str_repeat( 'b', 64 );
		$response_body['policy_version'] = 4;
	}

	return [
		'headers' => [],
		'body' => wp_json_encode( $response_body ),
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'cookies' => [],
		'filename' => null,
	];
};
add_filter( 'pre_http_request', $http_filter, 10, 3 );

$client = new YoRo_AI_API_Client();
$confirmation = $client->confirm_action(
	[
		'conversation_id' => 'permit-contract-conversation',
		'pending_operation_token' => 'permit-contract-pending',
		'plan_hash' => str_repeat( 'a', 64 ),
		'preview_hash' => str_repeat( 'b', 64 ),
		'resolved_impact' => [ 'target_count' => 2 ],
		'approved_groups' => [ 'store_mutation' ],
	]
);
$parsed = [
	'meta' => [
		'conversation_id' => 'permit-contract-conversation',
		'pending_operation_token' => 'permit-contract-pending',
	],
];
$claim = YoRo_REST_Conversation_Action_Coordinator::claim_pending_operation( $parsed, $confirmation );
$client->record_action_execution(
	[
		'conversation_id' => 'permit-contract-conversation',
		'pending_operation_token' => 'permit-contract-pending',
		'execution_permit' => is_array( $claim ) ? ( $claim['_execution_permit'] ?? '' ) : '',
		'action' => 'orders.add_note',
		'operation_id' => 'op_note',
		'event_type' => 'executed',
		'status' => 'executed',
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

$assert( 3 === count( $captured ), 'The permit flow must make confirm, claim, then execute requests.' );
$assert( false !== strpos( $captured[0]['url'] ?? '', '/actions/confirm' ), 'First request must confirm.' );
$assert( false !== strpos( $captured[1]['url'] ?? '', '/actions/claim' ), 'Second request must claim before execution.' );
$assert( false !== strpos( $captured[2]['url'] ?? '', '/actions/execute' ), 'Third request must record the execution receipt.' );
$assert( str_repeat( 'b', 64 ) === ( $captured[0]['body']['preview_hash'] ?? '' ), 'Confirmation must bind the exact preview hash.' );
$assert( [ 'store_mutation' ] === ( $captured[0]['body']['approved_groups'] ?? [] ), 'Confirmation must send approval groups.' );
$assert( str_repeat( 'p', 64 ) === ( $captured[1]['body']['execution_permit'] ?? '' ), 'Claim must carry the permit secret.' );
$assert( str_repeat( 'p', 64 ) === ( $captured[2]['body']['execution_permit'] ?? '' ), 'Execution receipt must remain bound to the claimed permit.' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}

echo "Execution permit contract passed.\n";
