<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

$assert( count( $expected_contracts ) === count( $fixtures ), 'The shared fixture set must contain exactly seven contracts.' );
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

echo "YoRo shared commerce fixtures: PASS\n";
