<?php
/**
 * YoRo connector integration expansion smoke contract.
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

$facts = YoRo_Integration_Registry::facts();
$adapter_facts = YoRo_Integration_Registry::adapter_facts();
$core_adapter = null;

foreach ( $adapter_facts as $adapter ) {
	if ( YoRo_Integration_Registry::CORE_TRACKING_ADAPTER === ( $adapter['key'] ?? '' ) ) {
		$core_adapter = $adapter;
	}

	$assert( ! array_key_exists( 'callbacks', $adapter ), 'Public adapter facts must not expose callable implementation details.' );
}

$assert( is_array( $core_adapter ), 'Integration registry must expose the built-in WooCommerce tracking adapter.' );
$assert( in_array( 'orders.add_tracking', (array) ( $core_adapter['capabilities'] ?? [] ), true ), 'Core tracking adapter must advertise orders.add_tracking.' );
$assert( YoRo_Integration_Registry::supports( 'orders.add_tracking' ), 'The default adapter must support orders.add_tracking.' );
$assert( ! YoRo_Integration_Registry::supports( 'orders.add_tracking', 'missing.adapter' ), 'Unknown adapters must fail closed.' );
$assert( ! empty( $facts['integration_boundary']['only_integrated_adapters_are_inspectable_or_actionable'] ), 'Integration facts must preserve the fail-closed adapter boundary.' );

$runtime = [
	'connector_key'           => 'woocommerce',
	'connector_connection_id' => 'connection_woocommerce_expansion_smoke',
	'execution_mode'          => 'remote_agent',
	'protocol_version'        => 1,
];
$manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( $runtime, 1 );
$assert( ! is_wp_error( $manifest ), 'Expanded capability manifest must build successfully.' );

$actions = [];
foreach ( (array) ( $manifest['actions'] ?? [] ) as $action ) {
	if ( is_array( $action ) && ! empty( $action['name'] ) ) {
		$actions[ $action['name'] ] = $action;
	}
}

foreach ( [ 'orders.add_tracking', 'orders.create_refund', 'products.get_details', 'products.update_catalog', 'coupons.update' ] as $name ) {
	$assert( isset( $actions[ $name ] ), sprintf( 'Capability manifest must expose %s.', $name ) );
}

$expected_actions = [
	'orders.add_tracking' => [
		'effect'               => 'mutate',
		'risk_level'           => 'medium',
		'reversibility'        => 'partial',
		'external_side_effect' => false,
		'required'             => [ 'order_id', 'tracking_number' ],
		'properties'           => [
			'order_id'        => 'integer',
			'tracking_number' => 'string',
			'carrier'         => 'string',
			'tracking_url'    => 'string',
			'shipped_at'      => 'string',
			'adapter_key'     => 'string',
		],
	],
	'orders.create_refund' => [
		'effect'               => 'financial',
		'risk_level'           => 'high',
		'reversibility'        => 'none',
		'external_side_effect' => true,
		'required'             => [ 'order_id', 'amount', 'refund_payment' ],
		'properties'           => [
			'order_id'       => 'integer',
			'amount'         => 'number',
			'reason'         => 'string',
			'refund_payment' => 'boolean',
			'line_items'     => 'array',
			'shipping_amount' => 'number',
			'restock_items'   => 'boolean',
			'idempotency_key' => 'string',
		],
	],
	'products.get_details' => [
		'effect'               => 'read',
		'risk_level'           => 'low',
		'reversibility'        => 'full',
		'external_side_effect' => false,
		'required'             => [ 'product_id' ],
		'properties'           => [
			'product_id'       => 'integer',
			'product_sku'      => 'string',
			'global_unique_id' => 'string',
		],
	],
	'products.update_catalog' => [
		'effect'               => 'mutate',
		'risk_level'           => 'medium',
		'reversibility'        => 'partial',
		'external_side_effect' => false,
		'required'             => [ 'product_id' ],
		'properties'           => [
			'product_id'         => 'integer',
			'product_sku'        => 'string',
			'global_unique_id'   => 'string',
			'regular_price'      => 'number',
			'sale_price'         => [ 'number', 'string' ],
			'sale_price_from'    => 'string',
			'sale_price_to'      => 'string',
			'status'             => 'string',
			'catalog_visibility' => 'string',
		],
	],
	'coupons.update' => [
		'effect'               => 'mutate',
		'risk_level'           => 'medium',
		'reversibility'        => 'partial',
		'external_side_effect' => false,
		'required'             => [ 'coupon_code' ],
		'properties'           => [
			'coupon_id'            => 'integer',
			'coupon_code'          => 'string',
			'discount_type'        => 'string',
			'discount_amount'      => 'number',
			'description'          => 'string',
			'expiry_date'          => 'string',
			'usage_limit'          => 'integer',
			'usage_limit_per_user' => 'integer',
			'individual_use'       => 'boolean',
			'free_shipping'        => 'boolean',
			'enabled'              => 'boolean',
		],
	],
];

foreach ( $expected_actions as $name => $expected ) {
	$action = $actions[ $name ] ?? [];
	$schema = $action['input_schema'] ?? [];
	$actual_properties = [];

	foreach ( (array) ( $schema['properties'] ?? [] ) as $property => $definition ) {
		$actual_properties[ $property ] = $definition['type'] ?? null;
	}

	$assert( $expected['effect'] === ( $action['effect'] ?? '' ), sprintf( '%s must publish the exact effect.', $name ) );
	$assert( $expected['risk_level'] === ( $action['risk_level'] ?? '' ), sprintf( '%s must publish the exact risk level.', $name ) );
	$assert( $expected['reversibility'] === ( $action['reversibility'] ?? '' ), sprintf( '%s must publish the exact reversibility.', $name ) );
	$assert( $expected['external_side_effect'] === ( $action['external_side_effect'] ?? null ), sprintf( '%s must publish the exact external-side-effect flag.', $name ) );
	$assert( false === ( $schema['additionalProperties'] ?? null ), sprintf( '%s input schema must reject unknown fields.', $name ) );
	$assert( $expected['required'] === ( $schema['required'] ?? [] ), sprintf( '%s must publish the exact required parameters.', $name ) );
	$assert( $expected['properties'] === $actual_properties, sprintf( '%s must publish the exact property names and types.', $name ) );
}

$assert( 'financial' === ( $actions['orders.create_refund']['effect'] ?? '' ), 'Refund execution must publish effect=financial.' );
$assert( 'high' === ( $actions['orders.create_refund']['risk_level'] ?? '' ), 'Refund execution must publish high risk.' );
$assert( 'none' === ( $actions['orders.create_refund']['reversibility'] ?? '' ), 'Refund execution must publish reversibility=none.' );
$assert( true === ( $actions['orders.create_refund']['external_side_effect'] ?? false ), 'Refund execution must disclose its external side effect.' );
$assert( [ 'order_id', 'amount', 'refund_payment' ] === ( $actions['orders.create_refund']['input_schema']['required'] ?? [] ), 'Refund execution must require an explicit gateway-payment choice.' );
$assert( 'order_tracking' === ( $actions['orders.add_tracking']['extensions']['yoro.conversation']['intent_family'] ?? '' ), 'Tracking action must publish canonical conversation semantics.' );
$assert( ! empty( $manifest['extensions']['woocommerce.integrations']['adapters'] ), 'Manifest must publish namespaced integration adapter facts.' );

$business = $actions['store.get_business_summary']['extensions']['yoro.conversation'] ?? [];
$reporting = $business['reporting_contract'] ?? [];
$assert( isset( $actions['store.get_business_summary']['input_schema']['properties']['answer_shape'] ), 'Sales report schema must expose answer_shape.' );
$assert( true === ( $reporting['supports_comparison'] ?? false ), 'Sales reporting contract must advertise comparison support.' );
$assert( in_array( 'metric_comparison', (array) ( $reporting['answer_shapes'] ?? [] ), true ), 'Sales reporting contract must advertise metric_comparison.' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo integration expansion smoke contract passed.\n";
