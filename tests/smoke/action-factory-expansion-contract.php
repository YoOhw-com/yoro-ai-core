<?php
/**
 * YoRo action factory and commerce expansion smoke contract.
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
$manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest(
	[
		'connector_key'           => 'woocommerce',
		'connector_connection_id' => 'connection_action_factory_expansion',
		'execution_mode'          => 'remote_agent',
		'protocol_version'        => 1,
	],
	4
);
$actions = [];

foreach ( (array) ( $manifest['actions'] ?? [] ) as $action ) {
	if ( is_array( $action ) && ! empty( $action['name'] ) ) {
		$actions[ $action['name'] ] = $action;
	}
}

$contract_keys = [ 'contract_type', 'schema_version', 'capability', 'semantic_schema', 'safety', 'idempotency', 'audit', 'language' ];

foreach ( $actions as $action_name => $action ) {
	$contract = $action['extensions']['yoro.conversation']['action_contract'] ?? [];
	$presentation = $action['extensions']['yoro.presentation'] ?? [];
	$assert( $contract_keys === array_keys( $contract ), sprintf( '%s must publish the exact action factory root schema.', $action_name ) );
	$assert( '' !== ( $contract['semantic_schema']['operation_key'] ?? '' ), sprintf( '%s must publish a canonical operation key.', $action_name ) );
	$assert( '' !== ( $contract['semantic_schema']['intent_family'] ?? '' ), sprintf( '%s must publish a canonical intent family.', $action_name ) );
	$assert( $action_name === ( $contract['capability'] ?? '' ), sprintf( '%s action contract must bind to its manifest capability.', $action_name ) );
	$assert( 'yoro.presentation.result' === ( $presentation['contract_type'] ?? '' ), sprintf( '%s must publish the namespaced result presentation contract.', $action_name ) );
	$assert( 1 === (int) ( $presentation['schema_version'] ?? 0 ), sprintf( '%s must publish presentation schema version 1.', $action_name ) );
	$assert( 'registry' === ( $presentation['compound_rendering'] ?? '' ), sprintf( '%s must publish registry-based compound rendering.', $action_name ) );
	$assert( 'preserve_exact' === ( $presentation['identifier_policy'] ?? '' ), sprintf( '%s must publish exact identifier preservation.', $action_name ) );
}

foreach ( (array) ( $manifest['extensions']['yoro.adapters']['adapters'] ?? [] ) as $adapter ) {
	foreach ( (array) ( $adapter['capabilities'] ?? [] ) as $capability ) {
		$assert( isset( $actions[ $capability ] ), sprintf( 'Adapter capability %s must exist in the same manifest.', $capability ) );
	}
}

foreach ( [
	'orders.get_fulfillment',
	'orders.update_fulfillment',
	'promotions.get_summary',
	'customers.get_segments',
	'customers.create_followup_task',
	'finance.get_reconciliation_summary',
] as $action_name ) {
	$assert( isset( $actions[ $action_name ] ), sprintf( '%s must be published.', $action_name ) );
	$contract = $actions[ $action_name ]['extensions']['yoro.conversation']['action_contract'] ?? [];
	$assert( 'yoro.semantic.action_definition' === ( $contract['contract_type'] ?? '' ), sprintf( '%s must use the action factory contract.', $action_name ) );
	$assert( $action_name === ( $contract['capability'] ?? '' ), sprintf( '%s action factory capability must match.', $action_name ) );
	$assert( false === ( $contract['language']['fixed_allowlist'] ?? true ), sprintf( '%s must not use a language allowlist.', $action_name ) );
	$assert( true === ( $contract['language']['canonical_slots'] ?? false ), sprintf( '%s must execute only canonical semantic slots.', $action_name ) );
	$assert( true === ( $contract['audit']['result_required'] ?? false ), sprintf( '%s must require an auditable result.', $action_name ) );
}

$finance = $actions['finance.get_reconciliation_summary']['extensions']['yoro.conversation']['reporting_contract'] ?? [];
$assert( 'finance' === ( $finance['topic'] ?? '' ), 'Finance report must publish the finance topic.' );
$assert( 'woocommerce_order_ledger' === ( $finance['evidence_mode'] ?? '' ), 'Finance report must publish its exact evidence mode.' );
$assert( true === ( $actions['orders.update_fulfillment']['requires_confirmation'] ?? false ), 'Fulfillment update must require confirmation.' );
$assert( true === ( $actions['customers.create_followup_task']['requires_confirmation'] ?? false ), 'Customer follow-up task must require confirmation.' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo action factory expansion contract passed.\n";
