<?php
/**
 * YoRo wave-two canonical manifest and integration adapter smoke contract.
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

if ( ! class_exists( 'YoRo_Integration_Registry' ) ) {
	YoRo_AI_Core::init();
}

if ( 0 === count( YoRo_Action_Registry::all() ) ) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

$shipment_tracking_defined_by_smoke = ! function_exists( 'wc_st_add_tracking_number' );

if ( $shipment_tracking_defined_by_smoke ) {
	function wc_st_add_tracking_number( $order_id, $tracking_number, $provider, $date_shipped, $tracking_url = '' ) {
		$GLOBALS['yoro_wave2_shipment_tracking_call'] = compact( 'order_id', 'tracking_number', 'provider', 'date_shipped', 'tracking_url' );
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$order->update_meta_data(
				'_wc_shipment_tracking_items',
				[
					[
						'tracking_number' => $tracking_number,
						'tracking_provider' => $provider,
						'date_shipped' => $date_shipped,
						'custom_tracking_link' => $tracking_url,
					],
				]
			);
			$order->save();
		}
	}
}

$failures = [];
$assert = static function( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$runtime = [
	'connector_key'           => 'woocommerce',
	'connector_connection_id' => 'connection_woocommerce_wave2_smoke',
	'execution_mode'          => 'remote_agent',
	'protocol_version'        => 1,
];
$manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( $runtime, 2 );
$actions  = [];

foreach ( (array) ( $manifest['actions'] ?? [] ) as $action ) {
	if ( is_array( $action ) && ! empty( $action['name'] ) ) {
		$actions[ $action['name'] ] = $action;
	}
}

$refund = $actions['orders.create_refund'] ?? [];
$refund_schema = $refund['input_schema'] ?? [];
$refund_properties = $refund_schema['properties'] ?? [];
$refund_line_item = $refund_properties['line_items']['items'] ?? [];
$assert( [ 'order_id', 'amount', 'refund_payment' ] === ( $refund_schema['required'] ?? [] ), 'Refund required fields must remain exact and backward compatible.' );
$assert( false === ( $refund_schema['additionalProperties'] ?? null ), 'Refund root schema must reject unknown fields.' );
$assert( [ 'order_item_id', 'quantity', 'amount', 'tax' ] === ( $refund_line_item['required'] ?? [] ), 'Refund line-item schema must require its exact fields.' );
$assert( false === ( $refund_line_item['additionalProperties'] ?? null ), 'Refund line-item schema must reject unknown fields.' );
$assert( [ 'number', 'object' ] === ( $refund_line_item['properties']['tax']['type'] ?? null ), 'Refund tax must support a total number or tax-rate object.' );
$assert( 'number' === ( $refund_properties['shipping_amount']['type'] ?? '' ), 'Refund schema must expose shipping_amount.' );
$assert( 'boolean' === ( $refund_properties['restock_items']['type'] ?? '' ), 'Refund schema must expose restock_items.' );
$assert( 'string' === ( $refund_properties['idempotency_key']['type'] ?? '' ), 'Refund schema must expose idempotency_key.' );

$batch = $actions['products.update_catalog_batch'] ?? [];
$batch_schema = $batch['input_schema'] ?? [];
$batch_items = $batch_schema['properties']['items'] ?? [];
$batch_item = $batch_items['items'] ?? [];
$batch_patch = $batch_item['properties']['patch'] ?? [];
$assert( [ 'items', 'idempotency_key' ] === ( $batch_schema['required'] ?? [] ), 'Catalog batch must require items and idempotency_key.' );
$assert( false === ( $batch_schema['additionalProperties'] ?? null ), 'Catalog batch root schema must reject unknown fields.' );
$assert( 1 === ( $batch_items['minItems'] ?? 0 ) && 20 === ( $batch_items['maxItems'] ?? 0 ), 'Catalog batch schema must publish its 1-20 target bound.' );
$assert( false === ( $batch_item['additionalProperties'] ?? null ), 'Catalog batch item schema must reject unknown fields.' );
$assert( 'integer' === ( $batch_item['properties']['variation_id']['type'] ?? '' ), 'Catalog batch must expose an explicit variation_id target.' );
$assert( in_array( [ 'required' => [ 'variation_id' ] ], $batch_item['anyOf'] ?? [], true ), 'Catalog batch schema must accept variation_id as a target.' );
$assert( false === ( $batch_patch['additionalProperties'] ?? null ), 'Catalog batch patch schema must reject unknown fields.' );
$assert( true === ( $batch['supports_bulk'] ?? false ), 'Catalog batch must publish supports_bulk=true.' );
$assert( 20 === ( $batch['max_targets'] ?? 0 ), 'Catalog batch must publish max_targets=20.' );
$assert( 'medium' === ( $batch['risk_level'] ?? '' ) && 'partial' === ( $batch['reversibility'] ?? '' ), 'Catalog batch safety metadata must be exact.' );
$assert( 'update_product_catalog_batch' === ( $batch['extensions']['yoro.conversation']['intent_family'] ?? '' ), 'Catalog batch manifest intent family must align with the core semantic action definition.' );

$report = $actions['store.get_refund_intelligence'] ?? [];
$reporting = $report['extensions']['yoro.conversation']['reporting_contract'] ?? [];
$assert( 'read' === ( $report['effect'] ?? '' ) && 'low' === ( $report['risk_level'] ?? '' ), 'Refund intelligence must be read-only and low risk.' );
$assert( 'refund_report' === ( $report['extensions']['yoro.conversation']['intent_family'] ?? '' ), 'Refund intelligence intent family must align with the core contract.' );
$assert( 'refunds' === ( $reporting['topic'] ?? '' ), 'Refund intelligence reporting topic must be refunds.' );
$assert( true === ( $reporting['supports_comparison'] ?? false ), 'Refund intelligence must publish comparison support.' );

$adapter_facts = YoRo_Integration_Registry::adapter_facts();
$shipment_adapter = null;

foreach ( $adapter_facts as $adapter ) {
	if ( YoRo_Integration_Registry::SHIPMENT_TRACKING_ADAPTER === ( $adapter['key'] ?? '' ) ) {
		$shipment_adapter = $adapter;
	}

	$assert( ! array_key_exists( 'callbacks', $adapter ), 'Adapter facts must never expose callbacks.' );
}

$assert( is_array( $shipment_adapter ), 'Shipment Tracking adapter must appear when wc_st_add_tracking_number is available.' );
$assert( YoRo_Integration_Registry::supports( 'orders.add_tracking', YoRo_Integration_Registry::SHIPMENT_TRACKING_ADAPTER ), 'Shipment Tracking adapter must support orders.add_tracking.' );

$order_id = 0;

try {
	$order = wc_create_order();
	$order->set_status( 'processing' );
	$order->set_total( 10 );
	$order->save();
	$order_id = $order->get_id();
	$params = [
		'order_id'        => $order_id,
		'tracking_number' => 'YORO-WAVE2-TRACK',
		'carrier'        => 'DHL',
		'tracking_url'   => 'https://example.com/YORO-WAVE2-TRACK',
		'shipped_at'     => '2026-07-22',
		'adapter_key'    => YoRo_Integration_Registry::SHIPMENT_TRACKING_ADAPTER,
	];
	$result = YoRo_Fulfillment_Write_Service::add_tracking( $params );
	$unknown = YoRo_Fulfillment_Write_Service::add_tracking( array_merge( $params, [ 'tracking_number' => 'NO-FALLBACK', 'adapter_key' => 'missing.explicit.adapter' ] ) );
	$order = wc_get_order( $order_id );

	$assert( ! is_wp_error( $result ) && ! empty( $result['success'] ), 'Explicit Shipment Tracking adapter execution must succeed.' );
	$assert( YoRo_Integration_Registry::SHIPMENT_TRACKING_ADAPTER === ( $result['adapter_key'] ?? '' ), 'Tracking result must preserve the explicitly selected adapter.' );
	$assert( '' === (string) $order->get_meta( '_yoro_tracking_number', true ), 'Shipment Tracking adapter must not fall back to the built-in YoRo record.' );
	$assert( is_wp_error( $unknown ) && 'yoro_integration_adapter_unavailable' === $unknown->get_error_code(), 'An unavailable explicit adapter must fail closed without fallback.' );

	if ( $shipment_tracking_defined_by_smoke ) {
		$assert( 'YORO-WAVE2-TRACK' === ( $GLOBALS['yoro_wave2_shipment_tracking_call']['tracking_number'] ?? '' ), 'Adapter must call wc_st_add_tracking_number with the exact tracking number.' );
	}
} finally {
	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$order->delete( true );
		}
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo wave-two integration contract passed.\n";
