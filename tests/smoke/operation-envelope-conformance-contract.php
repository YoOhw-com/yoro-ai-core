<?php
/**
 * Shared operation-envelope conformance smoke contract.
 *
 * Run from the WordPress root with:
 * YORO_SHARED_COMMERCE_FIXTURES=/absolute/path/canonical-fixtures.json \
 * YORO_SHARED_OPERATION_ENVELOPE_CASES=/absolute/path/operation-envelope-conformance.json \
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/operation-envelope-conformance-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( class_exists( 'YoRo_AI_Core' ) && ! class_exists( 'YoRo_Commerce_Contract_Validator' ) ) {
	YoRo_AI_Core::init();
}

$fixture_path = getenv( 'YORO_SHARED_COMMERCE_FIXTURES' );
$cases_path = getenv( 'YORO_SHARED_OPERATION_ENVELOPE_CASES' );

if ( ! is_string( $fixture_path ) || '' === $fixture_path || ! is_readable( $fixture_path ) ) {
	throw new RuntimeException( 'Set YORO_SHARED_COMMERCE_FIXTURES to the canonical YoRo commerce fixture JSON file.' );
}

if ( ! is_string( $cases_path ) || '' === $cases_path || ! is_readable( $cases_path ) ) {
	throw new RuntimeException( 'Set YORO_SHARED_OPERATION_ENVELOPE_CASES to the shared conformance case JSON file.' );
}

$fixtures = json_decode( file_get_contents( $fixture_path ), true );
$conformance = json_decode( file_get_contents( $cases_path ), true );

if ( ! is_array( $fixtures ) || ! is_array( $conformance ) || ! isset( $fixtures['operation_envelope'] ) ) {
	throw new RuntimeException( 'The shared operation-envelope fixtures are invalid.' );
}

$operation = $fixtures['operation_envelope'];
$operation['actor']['authenticated_at'] = gmdate( 'c', time() - MINUTE_IN_SECONDS );
$operation['authorization']['confirmed_at'] = gmdate( 'c' );
$operation['authorization']['permit_expires_at'] = gmdate( 'c', time() + ( 5 * MINUTE_IN_SECONDS ) );
$operation['requested_at'] = gmdate( 'c' );
$operation['expires_at'] = gmdate( 'c', time() + ( 5 * MINUTE_IN_SECONDS ) );

$valid = YoRo_Commerce_Contract_Validator::validate_operation_envelope( $operation );

if ( true !== $valid ) {
	throw new RuntimeException( 'The canonical operation envelope must pass the WordPress boundary.' );
}

$mutate = static function ( array $payload, array $mutation ) {
	$segments = explode( '.', $mutation['path'] );
	$leaf = array_pop( $segments );
	$cursor = &$payload;

	foreach ( $segments as $segment ) {
		if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
			$cursor[ $segment ] = [];
		}

		$cursor = &$cursor[ $segment ];
	}

	if ( 'remove' === $mutation['operation'] ) {
		unset( $cursor[ $leaf ] );
	} else {
		$cursor[ $leaf ] = $mutation['value'];
	}

	return $payload;
};

foreach ( $conformance['invalid_cases'] as $case ) {
	$invalid = $mutate( $operation, $case['mutation'] );
	$result = YoRo_Commerce_Contract_Validator::validate_operation_envelope( $invalid );

	if ( ! is_wp_error( $result )
		|| ( $conformance['expected_error'] ?? '' ) !== $result->get_error_code() ) {
		throw new RuntimeException( sprintf( 'The invalid case "%s" crossed the WordPress boundary.', $case['name'] ) );
	}
}

echo "YoRo shared operation-envelope conformance: PASS\n";
