<?php
/**
 * Product content update, strengthened version and bounded recovery smoke.
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
$assert( ! empty( $admin_ids ), 'An administrator is required for product content smokes.' );
wp_set_current_user( absint( $admin_ids[0] ) );
if ( 0 === count( YoRo_Action_Registry::all() ) ) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

$actions = YoRo_Action_Registry::all();
$action = $actions['products.update_content'] ?? [];
$assert( [ 'product_id', 'field', 'value', 'expected_version' ] === ( $action['required_params'] ?? [] ), 'Content public input must be exact.' );
$assert( ! empty( $action['write_action'] ) && ! empty( $action['requires_confirmation'] ), 'Content must be a confirmed write.' );
$assert( 'operation_journal' === ( $action['idempotency_strategy'] ?? '' ), 'Content must reuse operation-journal authority.' );
$assert( 'optimistic_version' === ( $action['concurrency_strategy'] ?? '' ), 'Content must publish optimistic concurrency.' );
$native_decision = ( new YoRo_Native_Ability_Execution_Provider() )->assess( 'products.update_content', $action, [] );
$assert( empty( $native_decision['eligible'] ) && 'native_mapping_missing' === ( $native_decision['reason'] ?? '' ), 'Product content must remain absent from native Ability selection.' );
$catalog_names = array_column( YoRo_Action_Catalog_Builder::build(), 'name' );
$assert( in_array( 'products.update_content', $catalog_names, true ), 'Content action must be public.' );
$assert( ! in_array( 'products.restore_content_state', $catalog_names, true ), 'Content recovery must stay hidden.' );

global $wpdb;
$wpdb->query( 'START TRANSACTION' );

try {
	$product = new WC_Product_Simple();
	$product->set_name( 'YoRo content smoke ' . wp_generate_uuid4() );
	$product->set_description( '<p>Original full description.</p>' );
	$product->set_short_description( '<p>Original short description.</p>' );
	$product->set_status( 'draft' );
	$product->set_regular_price( '10' );
	$product->set_price( '10' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 12 );
	$product_id = $product->save();
	$product = wc_get_product( $product_id );
	$version = YoRo_Commerce_Discovery_Service::product_version( $product );
	$params = [ 'product_id' => $product_id, 'field' => 'name', 'value' => '  Summer <b>Hoodie</b>  ', 'expected_version' => $version ];

	$preview = YoRo_Store_Inventory_Service::preview_update_product_content( $params );
	$assert( empty( $preview['blocked'] ) && false === $preview['will_execute'], 'Valid preview must remain read-only.' );
	$assert( 'Summer Hoodie' === $preview['proposed_value_preview'] && false === strpos( $preview['proposed_value_preview'], '<' ), 'Name preview must be normalized and inert.' );
	$assert( 64 === strlen( $preview['current_value_fingerprint'] ) && 64 === strlen( $preview['proposed_value_fingerprint'] ), 'Preview fingerprints must be bounded.' );

	$stale = YoRo_Store_Inventory_Service::update_product_content( array_replace( $params, [ 'expected_version' => str_repeat( '0', 64 ) ] ) );
	$assert( is_wp_error( $stale ) && 'product_version_conflict' === $stale->get_error_code(), 'Stale content writes must fail closed.' );
	wp_set_current_user( 0 );
	$denied = YoRo_Store_Inventory_Service::preview_update_product_content( $params );
	$assert( 'yoro_permission_denied' === ( $denied['block_reason'] ?? '' ), 'Permission denial must block before mutation.' );
	wp_set_current_user( absint( $admin_ids[0] ) );
	foreach ( [
		[ 'field' => 'unknown' ],
		[ 'field' => 'name', 'value' => '   ' ],
		[ 'field' => 'name', 'value' => str_repeat( 'n', 201 ) ],
		[ 'field' => 'description', 'value' => str_repeat( 'd', 100001 ) ],
		[ 'product_ids' => [ $product_id ] ],
	] as $invalid ) {
		$blocked = YoRo_Store_Inventory_Service::preview_update_product_content( array_replace( $params, $invalid ) );
		$assert( ! empty( $blocked['blocked'] ), 'Malformed or widened content input must fail closed.' );
	}
	$no_op = YoRo_Store_Inventory_Service::preview_update_product_content( [ 'product_id' => $product_id, 'field' => 'description', 'value' => '<p>Original full description.</p>', 'expected_version' => $version ] );
	$assert( 'product_content_no_change' === ( $no_op['block_reason'] ?? '' ), 'Normalized no-op must fail before confirmation.' );
	$other = new WC_Product_Simple();
	$other->set_name( 'YoRo other content smoke ' . wp_generate_uuid4() );
	$other->set_status( 'draft' );
	$other_id = $other->save();
	$cross_target = YoRo_Store_Inventory_Service::update_product_content( array_replace( $params, [ 'product_id' => $other_id ] ) );
	$assert( is_wp_error( $cross_target ) && 'product_version_conflict' === $cross_target->get_error_code(), 'A version from another product must fail closed.' );

	$bare = YoRo_Action_Executor::execute( 'products.update_content', $params, true );
	$assert( is_wp_error( $bare ) && 'product_content_execution_permit_required' === $bare->get_error_code(), 'Bare confirmation must not execute.' );
	$spoofed = YoRo_Plan_Executor::execute( [ 'type' => 'single', 'action' => 'products.update_content', 'params' => $params, 'context' => [ 'connector_remote_agent' => true, 'workflow_id' => 'captured', 'operation_id' => 'captured' ] ], true, false );
	$assert( 'product_content_execution_permit_required' === ( $spoofed['error']['code'] ?? '' ), 'Captured context must not mint authority.' );
	$used = false;
	$claim = static function ( $claimed_action, $claimed_params ) use ( &$used, $params ) {
		if ( $used || 'products.update_content' !== $claimed_action || $claimed_params !== $params ) {
			return false;
		}
		$used = true;
		return true;
	};
	$owner_save_count = 0;
	$count_owner_save = static function ( $saved ) use ( &$owner_save_count, $product_id ) {
		if ( $saved instanceof WC_Product && $saved->get_id() === $product_id ) {
			++$owner_save_count;
		}
	};
	add_action( 'woocommerce_after_product_object_save', $count_owner_save, 10, 1 );
	$result = YoRo_Action_Executor::execute( 'products.update_content', $params, true, false, [], $claim );
	remove_action( 'woocommerce_after_product_object_save', $count_owner_save, 10 );
	$current = wc_get_product( $product_id );
	$assert( ! empty( $result['success'] ) && 'Summer Hoodie' === $current->get_name( 'edit' ), 'One claimed owner-setter write must execute.' );
	$assert( 1 === $owner_save_count, 'A successful content mutation must call owner save exactly once.' );
	$assert( '10' === (string) $current->get_regular_price( 'edit' ) && 12 === (int) $current->get_stock_quantity( 'edit' ), 'Content must not change price or stock.' );
	$replay = YoRo_Action_Executor::execute( 'products.update_content', $params, true, false, [], $claim );
	$assert( is_wp_error( $replay ) && 'product_content_execution_permit_required' === $replay->get_error_code(), 'Consumed claim must not replay.' );

	$payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, 'products.update_content' );
	$schema = YoRo_Commerce_Contract_Builder::execution_output_schema( 'products.update_content' );
	$assert( array_keys( $payload['data'] ) === $schema['properties']['data']['required'], 'Content output must be exact.' );
	$assert( ! isset( $payload['data']['value'], $payload['data']['previous_value'] ), 'Canonical output must not expose raw content.' );
	$assert( [] === ( $payload['extensions']['wordpress.execution']['undo_plan'] ?? null ), 'Canonical output must keep raw recovery content internal.' );
	$undo = $result['undo_plan'][0] ?? [];
	$assert( 'products.restore_content_state' === ( $undo['action'] ?? '' ) && $result['current_version'] === ( $undo['params']['expected_version'] ?? '' ), 'Undo must bind exact field and post-write version.' );
	$assert( false === strpos( wp_json_encode( $payload ), $undo['params']['previous_value'] ), 'Canonical output must not contain captured raw recovery content.' );
	$restored = YoRo_Store_Inventory_Service::restore_product_content_state( $undo['params'] ?? [] );
	$assert( ! empty( $restored['success'] ) && $undo['params']['previous_value'] === wc_get_product( $product_id )->get_name( 'edit' ), 'Exact hidden recovery must restore only the captured field.' );
	$restore_replay = YoRo_Store_Inventory_Service::restore_product_content_state( $undo['params'] ?? [] );
	$assert( is_wp_error( $restore_replay ) && 'product_version_conflict' === $restore_replay->get_error_code(), 'Recovery replay must fail closed.' );

	foreach ( [ 'description', 'short_description' ] as $field ) {
		$product = wc_get_product( $product_id );
		$before = YoRo_Commerce_Discovery_Service::product_version( $product );
		$value = 'description' === $field ? '<p>Safe <strong>content</strong><script>alert(1)</script></p>' : '';
		$field_result = YoRo_Store_Inventory_Service::update_product_content( [ 'product_id' => $product_id, 'field' => $field, 'value' => $value, 'expected_version' => $before ] );
		$product = wc_get_product( $product_id );
		$getter = 'description' === $field ? 'get_description' : 'get_short_description';
		$assert( ! empty( $field_result['success'] ) && YoRo_Commerce_Discovery_Service::product_version( $product ) !== $before, 'Every supported content field must persist and change version.' );
		$assert( false === stripos( $product->$getter( 'edit' ), '<script' ), 'Unsafe active markup must be removed before persistence.' );
	}

	$product = wc_get_product( $product_id );
	$save_failure_params = [ 'product_id' => $product_id, 'field' => 'name', 'value' => 'Save must fail', 'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $product ) ];
	$throw_on_save = static function ( $saved ) use ( $product_id ) {
		if ( $saved instanceof WC_Product && $saved->get_id() === $product_id ) {
			throw new RuntimeException( 'simulated content owner save failure' );
		}
	};
	add_action( 'woocommerce_before_product_object_save', $throw_on_save, 10, 1 );
	$save_failure = YoRo_Store_Inventory_Service::update_product_content( $save_failure_params );
	remove_action( 'woocommerce_before_product_object_save', $throw_on_save, 10 );
	$assert( is_wp_error( $save_failure ) && 'product_content_reconciliation_required' === $save_failure->get_error_code(), 'Owner save failures must require reconciliation.' );

	$product = wc_get_product( $product_id );
	$ambiguous_params = [ 'product_id' => $product_id, 'field' => 'description', 'value' => '<p>Expected persisted value</p>', 'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $product ) ];
	$diverge_after_save = static function ( $saved ) use ( $product_id ) {
		if ( $saved instanceof WC_Product && $saved->get_id() === $product_id ) {
			wp_update_post( [ 'ID' => $product_id, 'post_content' => '<p>Concurrent fixture value</p>' ] );
			clean_post_cache( $product_id );
		}
	};
	add_action( 'woocommerce_after_product_object_save', $diverge_after_save, 10, 1 );
	$ambiguous = YoRo_Store_Inventory_Service::update_product_content( $ambiguous_params );
	remove_action( 'woocommerce_after_product_object_save', $diverge_after_save, 10 );
	$assert( is_wp_error( $ambiguous ) && 'product_content_reconciliation_required' === $ambiguous->get_error_code(), 'Ambiguous post-state must require reconciliation and never claim success.' );

	$variable = new WC_Product_Variable();
	$variable->set_name( 'YoRo variable content smoke ' . wp_generate_uuid4() );
	$variable->set_status( 'draft' );
	$variable_id = $variable->save();
	$variable = wc_get_product( $variable_id );
	$blocked = YoRo_Store_Inventory_Service::preview_update_product_content( [ 'product_id' => $variable_id, 'field' => 'name', 'value' => 'Blocked', 'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $variable ) ] );
	$assert( 'product_content_target_not_supported' === ( $blocked['block_reason'] ?? '' ), 'Non-simple products must be blocked.' );
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable_id );
	$variation->set_status( 'publish' );
	$variation_id = $variation->save();
	$variation = wc_get_product( $variation_id );
	$variation_blocked = YoRo_Store_Inventory_Service::preview_update_product_content( [ 'product_id' => $variation_id, 'field' => 'name', 'value' => 'Blocked variation', 'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $variation ) ] );
	$assert( 'product_content_target_not_supported' === ( $variation_blocked['block_reason'] ?? '' ), 'Exact variations must be blocked.' );
	$grouped = new WC_Product_Grouped();
	$grouped->set_name( 'YoRo grouped content smoke ' . wp_generate_uuid4() );
	$grouped->set_status( 'draft' );
	$grouped_id = $grouped->save();
	$grouped = wc_get_product( $grouped_id );
	$grouped_blocked = YoRo_Store_Inventory_Service::preview_update_product_content( [ 'product_id' => $grouped_id, 'field' => 'description', 'value' => 'Blocked grouped', 'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $grouped ) ] );
	$assert( 'product_content_target_not_supported' === ( $grouped_blocked['block_reason'] ?? '' ), 'Grouped products must be blocked.' );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

echo "Product content update contract passed.\n";
