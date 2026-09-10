<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( class_exists( 'YoRo_AI_Core' ) && function_exists( 'yoro_ai_core_has_woocommerce' ) && yoro_ai_core_has_woocommerce() ) {
	YoRo_AI_Core::init();
}

$fixture_path = getenv( 'YORO_SHARED_COMMERCE_FIXTURES' );

if ( ! is_string( $fixture_path ) || '' === $fixture_path || ! is_readable( $fixture_path ) ) {
	throw new RuntimeException( 'Set YORO_SHARED_COMMERCE_FIXTURES to the canonical YoRo commerce fixture JSON file.' );
}

$fixtures = json_decode( file_get_contents( $fixture_path ), true );

if ( ! is_array( $fixtures ) ) {
	throw new RuntimeException( 'The shared YoRo commerce fixture file is invalid.' );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$expected_contracts = [
	'connector_descriptor' => 'yoro.commerce.connector_descriptor',
	'capability_manifest'  => 'yoro.commerce.capability_manifest',
	'context_pack'         => 'yoro.commerce.context_pack',
	'actor_context'        => 'yoro.commerce.actor_context',
	'operation_envelope'   => 'yoro.commerce.operation_envelope',
	'execution_result'     => 'yoro.commerce.execution_result',
	'connector_event'      => 'yoro.commerce.connector_event',
];

$action_result_contracts     = is_array( $fixtures['action_result_contracts'] ?? null ) ? $fixtures['action_result_contracts'] : [];
$has_action_result_contracts = [] !== $action_result_contracts;
$expected_fixture_count      = count( $expected_contracts ) + ( $has_action_result_contracts ? 1 : 0 );

$assert(
	$expected_fixture_count === count( $fixtures ),
	'The shared fixture set must contain exactly seven wire contracts plus the optional action-result conformance set.'
);
$assert( 1 === YoRo_Commerce_Contract_Builder::SCHEMA_VERSION, 'The connector must remain on canonical commerce schema v1.' );

foreach ( $expected_contracts as $fixture_name => $contract_type ) {
	$fixture = $fixtures[ $fixture_name ] ?? null;

	$assert( is_array( $fixture ), sprintf( 'The %s fixture is missing.', $fixture_name ) );
	$assert( $contract_type === ( $fixture['contract_type'] ?? '' ), sprintf( 'The %s contract type is incompatible.', $fixture_name ) );
	$assert( 1 === ( $fixture['schema_version'] ?? 0 ), sprintf( 'The %s schema version is incompatible.', $fixture_name ) );
}

$assert(
	'woocommerce' === ( $fixtures['connector_descriptor']['connector_key'] ?? '' ),
	'The shared connector descriptor must target WooCommerce.'
);
$assert(
	'remote_agent' === ( $fixtures['operation_envelope']['execution_mode'] ?? '' ),
	'The shared operation fixture must use the pull-v1 remote agent.'
);
$assert(
	isset( $fixtures['operation_envelope']['idempotency_key'] ),
	'The shared operation fixture must carry an idempotency key.'
);
$assert(
	isset( $fixtures['operation_envelope']['authorization']['execution_permit'] ),
	'The shared mutation fixture must carry an execution permit.'
);
$contract_extension = YoRo_Commerce_Contract_Builder::execution_contract_extension();
$assert( 1 === ( $contract_extension['execution_payload_version'] ?? 0 ), 'The connector must publish canonical execution payload version 1.' );
$assert( empty( $contract_extension['strict_output_validation_ready'] ), 'A generic action result schema must never declare strict output validation readiness.' );

if ( $has_action_result_contracts ) {
	$shared_action_name       = sanitize_text_field( $fixtures['capability_manifest']['actions'][0]['name'] ?? '' );
	$shared_contract_extension = $fixtures['capability_manifest']['actions'][0]['extensions']['yoro.contracts'] ?? [];
	$shared_output_schema     = $fixtures['capability_manifest']['actions'][0]['output_schema'] ?? [];
	$shared_execution_payload = $fixtures['execution_result']['result'] ?? [];

	$assert(
		YoRo_Commerce_Contract_Builder::execution_output_schema() === $shared_output_schema,
		'The shared WooCommerce fixture must use the connector canonical execution output schema.'
	);
	$assert(
		array_keys( (array) $shared_execution_payload ) === ( $shared_output_schema['required'] ?? [] ),
		'The shared execution payload root must exactly match its published output schema.'
	);
	$assert(
		YoRo_Commerce_Contract_Builder::execution_contract_extension( $shared_action_name ) === $shared_contract_extension,
		'The shared capability fixture must publish accurate action-level result contract readiness.'
	);
	$assert( 1 === ( $action_result_contracts['execution_payload_version'] ?? 0 ), 'The shared action-result contract version must be supported.' );
	$assert( 3 === ( $action_result_contracts['fixture_revision'] ?? 0 ), 'The connector must pin canonical action-result fixture revision 3.' );

	foreach ( (array) ( $action_result_contracts['actions'] ?? [] ) as $action_name => $fixture ) {
		$schema      = YoRo_Commerce_Contract_Builder::execution_output_schema( $action_name );
		$sample_data = is_array( $fixture['sample_data'] ?? null ) ? $fixture['sample_data'] : [];
		$execution   = array_merge(
			[
				'success'   => true,
				'action'    => $action_name,
				'summary'   => 'Fixture result.',
				'read_only' => true,
			],
			$sample_data
		);
		$payload     = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $execution, $action_name );

		$assert(
			( $fixture['data_required'] ?? [] ) === ( $schema['properties']['data']['required'] ?? [] ),
			sprintf( 'The %s data schema must match the shared fixture.', $action_name )
		);
		$assert(
			array_keys( $payload['data'] ?? [] ) === ( $fixture['data_required'] ?? [] ),
			sprintf( 'The %s sample result must use the exact canonical data roots.', $action_name )
		);
	}
} else {
	$schema  = YoRo_Commerce_Contract_Builder::execution_output_schema( 'orders.search' );
	$payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload(
		[
			'success'   => true,
			'action'    => 'orders.search',
			'summary'   => 'Fixture result.',
			'read_only' => true,
			'orders'    => [],
			'page_info' => [
				'has_next_page' => false,
				'next_cursor'   => null,
			],
			'count'     => 0,
		],
		'orders.search'
	);

	$assert( false === ( $schema['additionalProperties'] ?? true ), 'The connector output schema must remain closed during the fixture cutover.' );
	$assert( array_keys( $payload ) === ( $schema['required'] ?? [] ), 'The local execution payload root must exactly match the connector output schema.' );
	$assert(
		array_keys( $payload['data'] ?? [] ) === ( $schema['properties']['data']['required'] ?? [] ),
		'The local orders.search data must exactly match the connector output schema.'
	);
}

$payment_schema = YoRo_Commerce_Contract_Builder::execution_output_schema( 'orders.get_payment_context' );
$assert( [ 'payment_context' ] === $payment_schema['properties']['data']['required'], 'Payment context uses one bounded canonical data root.' );
$assert( [ 'not_observed' ] === $payment_schema['properties']['data']['properties']['payment_context']['properties']['evidence']['properties']['gateway_verification']['enum'], 'Shared payment evidence never claims independent gateway verification.' );
$assert( isset( $action_result_contracts['connector_actions']['orders.get_payment_context'] ), 'The canonical B1 payment fixture must be present.' );
$payment_fixture = $action_result_contracts['connector_actions']['orders.get_payment_context'];
$payment_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( array_merge( [ 'success' => true ], $payment_fixture['sample_data'] ), 'orders.get_payment_context' );
$assert( true === $payment_payload['success'], 'The API payment fixture is accepted by the actual Core projection.' );
$assert( 'not_observed' === $payment_payload['data']['payment_context']['evidence']['gateway_verification'], 'API/Core fixture evidence semantics agree.' );

$link_fixture = $action_result_contracts['connector_actions']['orders.get_payment_link'];
$link_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( array_merge( [ 'success' => true ], $link_fixture['sample_data'] ), 'orders.get_payment_link' );
$assert( true === $link_payload['success'], 'The canonical B2 fixture is accepted by the Core owner.' );
$assert( $link_fixture['sample_data'] === $link_payload['data'], 'B2 fixture preserves the exact opaque URL and fixed provenance.' );
$assert( YoRo_Commerce_Contract_Builder::strict_output_validation_ready( 'orders.get_payment_link' ), 'B2 output is recursively closed.' );

echo "YoRo shared commerce fixtures: PASS\n";
