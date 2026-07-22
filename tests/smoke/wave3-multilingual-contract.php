<?php
/**
 * YoRo wave-three universal-language and canonical manifest smoke contract.
 *
 * Run through WordPress with wp eval-file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( ! class_exists( 'YoRo_Commerce_Contract_Builder' ) ) {
	YoRo_AI_Core::init();
}

if ( 0 === count( YoRo_Action_Registry::all() ) ) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

$failures = [];
$assert = static function( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$runtime = [
	'connector_key'           => 'woocommerce',
	'connector_connection_id' => 'connection_woocommerce_wave3_language',
	'execution_mode'          => 'remote_agent',
	'protocol_version'        => 1,
];
$manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( $runtime, 3 );
$actions  = [];

foreach ( (array) ( $manifest['actions'] ?? [] ) as $action ) {
	if ( is_array( $action ) && ! empty( $action['name'] ) ) {
		$actions[ $action['name'] ] = $action;
	}
}

foreach ( [
	'store.get_inventory_risk'       => 'inventory',
	'store.get_customer_lifecycle'   => 'customers',
	'store.get_returns_intelligence' => 'returns',
] as $name => $topic ) {
	$action   = $actions[ $name ] ?? [];
	$semantic = $action['extensions']['yoro.conversation'] ?? [];
	$language = $semantic['language_contract'] ?? [];
	$assert( is_array( $action ) && ! empty( $action ), sprintf( '%s must be published.', $name ) );
	$assert( 'read' === ( $action['effect'] ?? '' ), sprintf( '%s must be read-only.', $name ) );
	$assert( $topic === ( $semantic['reporting_contract']['topic'] ?? '' ), sprintf( '%s must publish topic %s.', $name, $topic ) );
	$assert( 'any_bcp47' === ( $language['input_language'] ?? '' ), sprintf( '%s must accept arbitrary BCP-47 input language.', $name ) );
	$assert( 'unicode' === ( $language['input_scripts'] ?? '' ), sprintf( '%s must accept Unicode scripts.', $name ) );
	$assert( false === ( $language['local_keyword_routing'] ?? null ), sprintf( '%s must reject connector keyword routing.', $name ) );
	$assert( true === ( $language['examples_are_non_authoritative'] ?? false ), sprintf( '%s examples must be non-authoritative.', $name ) );
	$assert( true === ( $language['connector_receives_canonical_slots'] ?? false ), sprintf( '%s must consume canonical slots.', $name ) );
}

$return_action = $actions['orders.create_return_request'] ?? [];
$return_schema = $return_action['input_schema'] ?? [];
$return_semantics = $return_action['extensions']['yoro.conversation'] ?? [];
$registered_return_schema = (array) ( YoRo_Action_Registry::get( 'orders.create_return_request' )['input_schema'] ?? [] );
$registered_return_items = (array) ( $registered_return_schema['line_items']['items'] ?? [] );
$assert( [ 'order_id', 'type', 'line_items', 'reason', 'resolution', 'idempotency_key' ] === ( $return_schema['required'] ?? [] ), 'Return request must publish exact required fields.' );
$assert( false === ( $return_schema['additionalProperties'] ?? null ), 'Return request root schema must fail closed on unknown fields.' );
$assert( [ 'type' => 'object' ] === ( $return_schema['properties']['line_items']['items'] ?? [] ), 'Canonical v1 must publish the bounded return item type.' );
$assert( false === ( $registered_return_items['additionalProperties'] ?? null ), 'The connector executor return item schema must fail closed on unknown fields.' );
$assert( 'return_request' === ( $return_semantics['intent_family'] ?? '' ), 'Return request must publish the canonical semantic family.' );
$assert( 'reason' === ( $return_semantics['slot_contract']['user_content_slots'][0]['field'] ?? '' ), 'Return reason must remain a scoped user-content slot.' );
$assert( 'user_literal' === ( $return_semantics['slot_contract']['user_content_slots'][0]['source'] ?? '' ), 'Return reason provenance must remain user_literal.' );

$malformed_filter = static function( $adapters ) {
	$adapters['test.invalid'] = [
		'key'          => 'test.invalid',
		'domain'       => 'test',
		'version'      => 1,
		'active'       => true,
		'capabilities' => [ 'test.read' ],
		'callbacks'    => [],
	];

	return $adapters;
};
add_filter( 'yoro_integration_adapters', $malformed_filter );
$adapters = YoRo_Integration_Registry::adapters();
remove_filter( 'yoro_integration_adapters', $malformed_filter );
$assert( 2 === YoRo_Integration_Registry::REGISTRY_VERSION, 'Integration registry must publish version 2.' );
$assert( ! isset( $adapters['test.invalid'] ), 'Adapter with incomplete callback coverage must fail closed.' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo wave-three multilingual contract passed.\n";
