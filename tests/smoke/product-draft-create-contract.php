<?php
/**
 * Minimal product draft creation and operation-journal authority smoke.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );
if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}
if ( class_exists( 'YoRo_AI_Core' ) && function_exists( 'yoro_ai_core_has_woocommerce' ) && yoro_ai_core_has_woocommerce() ) {
	YoRo_AI_Core::init();
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admin_ids ), 'An administrator is required for product draft smokes.' );
wp_set_current_user( absint( $admin_ids[0] ) );
if ( 0 === count( YoRo_Action_Registry::all() ) ) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

$action = YoRo_Action_Registry::get( 'products.create_draft' );
$assert( is_array( $action ) && [ 'name' ] === ( $action['required_params'] ?? [] ), 'Draft creation must expose exactly name.' );
$assert( ! empty( $action['write_action'] ) && ! empty( $action['requires_confirmation'] ), 'Draft creation must be a confirmed write.' );
$assert( 'none' === ( $action['reversibility'] ?? '' ), 'Draft creation must publish no automatic reversal.' );
$assert( 'operation_journal' === ( $action['idempotency_strategy'] ?? '' ) && 'create_only' === ( $action['concurrency_strategy'] ?? '' ), 'Draft creation must use journal-owned create-only semantics.' );
$native = ( new YoRo_Native_Ability_Execution_Provider() )->assess( 'products.create_draft', $action, [] );
$assert( empty( $native['eligible'] ) && 'native_mapping_missing' === ( $native['reason'] ?? '' ), 'Draft creation must remain absent from native Ability selection.' );
$catalog_names = array_column( YoRo_Action_Catalog_Builder::build(), 'name' );
$assert( in_array( 'products.create_draft', $catalog_names, true ), 'Draft creation must be public through the canonical catalog only.' );

global $wpdb;
$wpdb->query( 'START TRANSACTION' );

try {
	$params = [ 'name' => '  Áo <b>khoác</b> nhẹ  ' ];
	$preview = YoRo_Store_Inventory_Service::preview_create_product_draft( $params );
	$assert( empty( $preview['blocked'] ) && false === $preview['will_execute'], 'A valid preview must be read-only.' );
	$assert( 'Áo khoác nhẹ' === $preview['name'] && 'simple' === $preview['product_type'] && 'draft' === $preview['status'], 'Preview must disclose the normalized Unicode fixed draft shape.' );
	$assert( null === $preview['product_id'] && '' === $preview['current_version'], 'Preview must not invent a product identity or version.' );

	foreach ( [
		[],
		[ 'name' => '' ],
		[ 'name' => '   ' ],
		[ 'name' => "bad\0name" ],
		[ 'name' => "\xC3\x28" ],
		[ 'name' => str_repeat( 'n', 201 ) ],
		[ 'name' => 'Valid', 'status' => 'publish' ],
		[ 'name' => 'Valid', 'type' => 'variable' ],
		[ 'name' => 'Valid', 'product_id' => 123 ],
		[ 'name' => 'Valid', 'expected_version' => str_repeat( '0', 64 ) ],
		[ 'name' => 'Valid', 'regular_price' => '10' ],
		[ 'name' => 'Valid', 'description' => 'Injected content' ],
		[ 'name' => 'Valid', 'sku' => 'INJECTED' ],
		[ 'name' => 'Valid', 'stock_quantity' => 10 ],
		[ 'name' => 'Valid', 'category_ids' => [ 1 ] ],
		[ 'name' => 'Valid', 'image_id' => 1 ],
	] as $invalid ) {
		$blocked = YoRo_Store_Inventory_Service::preview_create_product_draft( $invalid );
		$assert( ! empty( $blocked['blocked'] ), 'Missing, unsafe, overlong, or widened input must fail closed.' );
	}

	wp_set_current_user( 0 );
	$denied = YoRo_Store_Inventory_Service::preview_create_product_draft( [ 'name' => 'Denied' ] );
	$assert( 'yoro_permission_denied' === ( $denied['block_reason'] ?? '' ), 'Permission denial must happen before mutation.' );
	wp_set_current_user( absint( $admin_ids[0] ) );

	$bare = YoRo_Action_Executor::execute( 'products.create_draft', $params, true );
	$assert( is_wp_error( $bare ) && 'product_draft_execution_permit_required' === $bare->get_error_code(), 'Bare confirmation must not create a product.' );
	$spoofed = YoRo_Plan_Executor::execute( [ 'type' => 'single', 'action' => 'products.create_draft', 'params' => $params, 'context' => [ 'connector_remote_agent' => true, 'workflow_id' => 'captured', 'operation_id' => 'captured' ] ], true, false );
	$assert( 'product_draft_execution_permit_required' === ( $spoofed['error']['code'] ?? '' ), 'Captured context must not mint creation authority.' );
	$direct_spoofed = YoRo_Action_Executor::execute( 'products.create_draft', $params, true, false, [ 'connector_remote_agent' => true, 'workflow_id' => 'captured', 'operation_id' => 'captured' ] );
	$assert( is_wp_error( $direct_spoofed ) && 'product_draft_execution_permit_required' === $direct_spoofed->get_error_code(), 'Direct caller-created connector context must not mint journal authority.' );
	$mismatched_claim = static function ( $claimed_action, $claimed_params ) use ( $params ) {
		return 'products.create_draft' === $claimed_action && $claimed_params === $params;
	};
	$mismatched = YoRo_Action_Executor::execute( 'products.create_draft', [ 'name' => 'Different name' ], true, false, [], $mismatched_claim );
	$assert( is_wp_error( $mismatched ) && 'product_draft_execution_permit_required' === $mismatched->get_error_code(), 'A claim for different params must not create.' );

	$used = false;
	$claim = static function ( $claimed_action, $claimed_params ) use ( &$used, $params ) {
		if ( $used || 'products.create_draft' !== $claimed_action || $claimed_params !== $params ) {
			return false;
		}
		$used = true;
		return true;
	};
	$owner_save_count = 0;
	$count_owner_save = static function ( $saved ) use ( &$owner_save_count ) {
		if ( $saved instanceof WC_Product && 0 === $saved->get_id() ) {
			++$owner_save_count;
		}
	};
	add_action( 'woocommerce_before_product_object_save', $count_owner_save, 10, 1 );
	$result = YoRo_Action_Executor::execute( 'products.create_draft', $params, true, false, [], $claim );
	remove_action( 'woocommerce_before_product_object_save', $count_owner_save, 10 );
	$assert( ! empty( $result['success'] ) && 1 === $owner_save_count, 'One claimed operation must call owner save exactly once.' );
	$product_id = absint( $result['product_id'] ?? 0 );
	$product = wc_get_product( $product_id );
	$assert( $product_id > 0 && $product && 'simple' === $product->get_type(), 'Creation must return and freshly load the exact simple product.' );
	$assert( 'draft' === $product->get_status( 'edit' ) && 'Áo khoác nhẹ' === $product->get_name( 'edit' ), 'Only the normalized Unicode draft identity may persist.' );
	$assert( '' === (string) $product->get_regular_price( 'edit' ) && '' === (string) $product->get_description( 'edit' ), 'Creation must not set pricing or content defaults.' );
	$assert( preg_match( '/^[a-f0-9]{64}$/D', (string) ( $result['current_version'] ?? '' ) ) === 1, 'Creation must return the fresh canonical product version.' );
	$follow_up = YoRo_Store_Inventory_Service::update_product_content( [
		'product_id' => $product_id,
		'field' => 'name',
		'value' => 'Summer Hoodie Updated',
		'expected_version' => $result['current_version'],
	] );
	$assert( ! empty( $follow_up['success'] ), 'The returned fresh version must authorize an existing grounded follow-up write.' );
	$replay = YoRo_Action_Executor::execute( 'products.create_draft', $params, true, false, [], $claim );
	$assert( is_wp_error( $replay ) && 'product_draft_execution_permit_required' === $replay->get_error_code(), 'A consumed claim must not create twice.' );

	$payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, 'products.create_draft' );
	$schema = YoRo_Commerce_Contract_Builder::execution_output_schema( 'products.create_draft' );
	$assert( array_keys( $payload['data'] ) === $schema['properties']['data']['required'], 'Draft output must contain only its exact canonical fields.' );
	$assert( [] === ( $payload['extensions']['wordpress.execution']['undo_plan'] ?? null ), 'Draft creation must expose no delete or trash undo.' );
	$assert( [ 'product_id', 'current_version' ] === array_keys( $payload['data'] ), 'Canonical output must expose only the new ID and fresh version.' );

	$other = YoRo_Store_Inventory_Service::create_product_draft( [ 'name' => $params['name'] ] );
	$assert( ! empty( $other['success'] ) && $other['product_id'] !== $product_id, 'Distinct operations may create distinct drafts with the same name.' );

	$fail_on_save = static function ( $saved ) {
		if ( $saved instanceof WC_Product && 0 === $saved->get_id() ) {
			throw new RuntimeException( 'simulated owner save failure' );
		}
	};
	add_action( 'woocommerce_before_product_object_save', $fail_on_save, 10, 1 );
	$save_failure = YoRo_Store_Inventory_Service::create_product_draft( [ 'name' => 'Save failure' ] );
	remove_action( 'woocommerce_before_product_object_save', $fail_on_save, 10 );
	$assert( is_wp_error( $save_failure ) && 'product_draft_creation_reconciliation_required' === $save_failure->get_error_code(), 'Owner save failure must require reconciliation without retry.' );

	$diverge = static function ( $saved ) {
		if ( $saved instanceof WC_Product && 0 === $saved->get_id() ) {
			$saved->set_name( 'Diverged after save' );
		}
	};
	add_action( 'woocommerce_before_product_object_save', $diverge, 10, 1 );
	$ambiguous = YoRo_Store_Inventory_Service::create_product_draft( [ 'name' => 'Expected name' ] );
	remove_action( 'woocommerce_before_product_object_save', $diverge, 10 );
	$assert( is_wp_error( $ambiguous ) && 'product_draft_creation_reconciliation_required' === $ambiguous->get_error_code(), 'Ambiguous persisted state must require reconciliation without retry.' );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

echo "Product draft create contract OK\n";
