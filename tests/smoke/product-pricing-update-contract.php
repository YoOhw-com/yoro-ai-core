<?php
/**
 * Regular-price update and bounded recovery smoke contract.
 *
 * Run through WordPress with WooCommerce loaded. Fixtures are rolled back.
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
$assert( ! empty( $admin_ids ), 'An administrator is required for product pricing smokes.' );
wp_set_current_user( absint( $admin_ids[0] ) );

if ( class_exists( 'YoRo_Action_Registry' ) && 0 === count( YoRo_Action_Registry::all() ) ) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

$registry = YoRo_Action_Registry::all();
$pricing_action = $registry['products.update_pricing'] ?? null;
$assert( is_array( $pricing_action ), 'products.update_pricing must be registered.' );
$assert( [ 'product_id', 'regular_price', 'expected_version' ] === ( $pricing_action['required_params'] ?? [] ), 'Pricing public input must be exact.' );
$assert( ! empty( $pricing_action['write_action'] ) && ! empty( $pricing_action['requires_confirmation'] ), 'Pricing must be a confirmed write.' );
$assert( 'operation_journal' === ( $pricing_action['idempotency_strategy'] ?? '' ), 'Pricing must reuse operation-journal authority.' );
$assert( 'optimistic_version' === ( $pricing_action['concurrency_strategy'] ?? '' ), 'Pricing must publish its version guard.' );

$catalog_by_name = [];
foreach ( YoRo_Action_Catalog_Builder::build() as $item ) {
	if ( is_array( $item ) && ! empty( $item['name'] ) ) {
		$catalog_by_name[ $item['name'] ] = $item;
	}
}
$assert( isset( $catalog_by_name['products.update_pricing'] ), 'The public pricing action must be catalog-visible.' );
$assert( ! isset( $catalog_by_name['products.restore_pricing_state'] ), 'Pricing recovery must remain hidden from AI selection.' );

global $wpdb;
$wpdb->query( 'START TRANSACTION' );

try {
	$product = new WC_Product_Simple();
	$product->set_name( 'YoRo pricing smoke ' . wp_generate_uuid4() );
	$product->set_status( 'draft' );
	$product->set_regular_price( '10' );
	$product->set_price( '10' );
	$product_id = $product->save();
	$product = wc_get_product( $product_id );
	$version = YoRo_Commerce_Discovery_Service::product_version( $product );
	$params = [ 'product_id' => $product_id, 'regular_price' => '12.345', 'expected_version' => $version ];

	$preview = YoRo_Store_Inventory_Service::preview_update_product_pricing( $params );
	$assert( empty( $preview['blocked'] ) && false === $preview['will_execute'], 'Valid pricing preview must remain read-only.' );
	$assert( '10' === $preview['current_regular_price'] && '12.35' === $preview['proposed_regular_price'], 'Preview must show current and store-normalized proposed prices.' );
	$assert( '10' === $preview['current_active_price'] && get_woocommerce_currency() === $preview['currency'], 'Preview must expose bounded active-price and currency facts.' );

	$stale = YoRo_Store_Inventory_Service::update_product_pricing( array_replace( $params, [ 'expected_version' => str_repeat( '0', 64 ) ] ) );
	$assert( is_wp_error( $stale ) && 'product_version_conflict' === $stale->get_error_code(), 'Stale pricing writes must fail before mutation.' );
	$assert( '10' === (string) wc_get_product( $product_id )->get_regular_price( 'edit' ), 'A stale request must not mutate price.' );

	foreach ( [ '', '-1', '+1', '1e2', '1,000', '$10', '0001', '1234567890123', '1.1234567' ] as $invalid_price ) {
		$invalid = YoRo_Store_Inventory_Service::preview_update_product_pricing( array_replace( $params, [ 'regular_price' => $invalid_price ] ) );
		$assert( ! empty( $invalid['blocked'] ), sprintf( 'Invalid price %s must fail closed.', $invalid_price ) );
	}
	$unknown = YoRo_Store_Inventory_Service::preview_update_product_pricing( array_merge( $params, [ 'sale_price' => '1' ] ) );
	$assert( ! empty( $unknown['blocked'] ), 'Unknown or sale-price input must fail closed.' );
	$no_op = YoRo_Store_Inventory_Service::preview_update_product_pricing( array_replace( $params, [ 'regular_price' => '10' ] ) );
	$assert( ! empty( $no_op['blocked'] ) && 'product_pricing_no_change' === $no_op['block_reason'], 'A normalized no-op must fail before confirmation.' );

	$other = new WC_Product_Simple();
	$other->set_name( 'YoRo other pricing smoke ' . wp_generate_uuid4() );
	$other->set_status( 'draft' );
	$other->set_regular_price( '8' );
	$other->set_price( '8' );
	$other_id = $other->save();
	$cross_target = YoRo_Store_Inventory_Service::update_product_pricing( array_replace( $params, [ 'product_id' => $other_id ] ) );
	$assert( is_wp_error( $cross_target ) && 'product_version_conflict' === $cross_target->get_error_code(), 'A version from another product must fail closed.' );

	$bare = YoRo_Action_Executor::execute( 'products.update_pricing', $params, true );
	$assert( is_wp_error( $bare ) && 'pricing_execution_permit_required' === $bare->get_error_code(), 'Bare confirmation must not authorize pricing.' );
	$mismatched_claim = static function () { return false; };
	$mismatch = YoRo_Action_Executor::execute( 'products.update_pricing', $params, true, false, [], $mismatched_claim );
	$assert( is_wp_error( $mismatch ) && 'pricing_execution_permit_required' === $mismatch->get_error_code(), 'A mismatched claim must not authorize pricing.' );
	$spoofed = YoRo_Plan_Executor::execute( [
		'type' => 'single',
		'action' => 'products.update_pricing',
		'params' => $params,
		'context' => [ 'connector_remote_agent' => true, 'workflow_id' => 'captured', 'operation_id' => 'captured' ],
	], true, false );
	$assert( 'pricing_execution_permit_required' === ( $spoofed['error']['code'] ?? '' ), 'Caller or captured plan context must not mint pricing authority.' );

	$claimed = false;
	$claim = static function ( $action, $execution_params ) use ( &$claimed, $params ) {
		if ( $claimed || 'products.update_pricing' !== $action || $execution_params !== $params ) {
			return false;
		}
		$claimed = true;

		return true;
	};
	$result = YoRo_Action_Executor::execute( 'products.update_pricing', $params, true, false, [], $claim );
	$assert( is_array( $result ) && ! empty( $result['success'] ), 'One exact claimed pricing operation must execute.' );
	$current = wc_get_product( $product_id );
	$assert( '12.35' === (string) $current->get_regular_price( 'edit' ) && '12.35' === (string) $current->get_price( 'edit' ), 'WooCommerce must persist coherent regular and active prices.' );
	$assert( '10' === (string) ( $result['previous_regular_price'] ?? '' ), 'Result must retain the captured previous regular price.' );
	$assert( YoRo_Commerce_Discovery_Service::product_version( $current ) === ( $result['current_version'] ?? '' ), 'Result must return the fresh strengthened version.' );
	$replay = YoRo_Action_Executor::execute( 'products.update_pricing', $params, true, false, [], $claim );
	$assert( is_wp_error( $replay ) && 'pricing_execution_permit_required' === $replay->get_error_code(), 'A consumed claim must not execute twice.' );

	$payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, 'products.update_pricing' );
	$schema = YoRo_Commerce_Contract_Builder::execution_output_schema( 'products.update_pricing' );
	$assert( array_keys( $payload['data'] ) === $schema['properties']['data']['required'], 'Pricing result must match the exact canonical data schema.' );
	$undo = $result['undo_plan'][0] ?? [];
	$assert( 'products.restore_pricing_state' === ( $undo['action'] ?? '' ), 'Pricing must emit one hidden recovery step.' );
	$assert( ( $result['current_version'] ?? '' ) === ( $undo['params']['expected_version'] ?? '' ), 'Recovery must bind the post-write version.' );
	$restored = YoRo_Store_Inventory_Service::restore_product_pricing_state( $undo['params'] ?? [] );
	$assert( is_array( $restored ) && ! empty( $restored['success'] ), 'Exact version-bound pricing recovery must succeed.' );
	$assert( '10.00' === (string) wc_get_product( $product_id )->get_regular_price( 'edit' ), 'Recovery must restore only the captured regular price at store precision.' );
	$replayed_restore = YoRo_Store_Inventory_Service::restore_product_pricing_state( $undo['params'] ?? [] );
	$assert( is_wp_error( $replayed_restore ) && 'product_version_conflict' === $replayed_restore->get_error_code(), 'Consumed pricing recovery must fail closed.' );
	$aba_replay = YoRo_Action_Executor::execute( 'products.update_pricing', $params, true, false, [], $claim );
	$assert( is_wp_error( $aba_replay ) && 'pricing_execution_permit_required' === $aba_replay->get_error_code(), 'Returning the price to its prior value must not revive a consumed operation claim.' );

	$save_failure_product = wc_get_product( $product_id );
	$save_failure_params = [
		'product_id' => $product_id,
		'regular_price' => '14',
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $save_failure_product ),
	];
	$throw_on_save = static function ( $saved ) use ( $product_id ) {
		if ( $saved instanceof WC_Product && $saved->get_id() === $product_id ) {
			throw new RuntimeException( 'simulated pricing owner save failure' );
		}
	};
	add_action( 'woocommerce_before_product_object_save', $throw_on_save, 10, 1 );
	$save_failure = YoRo_Store_Inventory_Service::update_product_pricing( $save_failure_params );
	remove_action( 'woocommerce_before_product_object_save', $throw_on_save, 10 );
	$assert( is_wp_error( $save_failure ) && 'product_pricing_reconciliation_required' === $save_failure->get_error_code(), 'Owner save failures must require reconciliation and never claim success.' );

	$ambiguous_product = wc_get_product( $product_id );
	$ambiguous_params = [
		'product_id' => $product_id,
		'regular_price' => '15',
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $ambiguous_product ),
	];
	$diverge_after_save = static function ( $saved ) use ( $product_id ) {
		if ( $saved instanceof WC_Product && $saved->get_id() === $product_id ) {
			update_post_meta( $product_id, '_price', '999' );
			clean_post_cache( $product_id );
		}
	};
	add_action( 'woocommerce_after_product_object_save', $diverge_after_save, 10, 1 );
	$ambiguous = YoRo_Store_Inventory_Service::update_product_pricing( $ambiguous_params );
	remove_action( 'woocommerce_after_product_object_save', $diverge_after_save, 10 );
	$assert( is_wp_error( $ambiguous ) && 'product_pricing_reconciliation_required' === $ambiguous->get_error_code(), 'Ambiguous post-state must require reconciliation and never claim success.' );

	$product = wc_get_product( $product_id );
	$base_version = YoRo_Commerce_Discovery_Service::product_version( $product );
	$product->set_regular_price( '11' );
	$product->set_price( '11' );
	$product->save();
	$regular_version = YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $product_id ) );
	$assert( $base_version !== $regular_version, 'Regular-price changes must change product version.' );
	$product = wc_get_product( $product_id );
	$product->set_sale_price( '9' );
	$product->set_price( '9' );
	$product->save();
	$sale_version = YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $product_id ) );
	$assert( $sale_version !== $regular_version, 'Sale-price state must participate in product version.' );
	$sale_blocked = YoRo_Store_Inventory_Service::preview_update_product_pricing( [ 'product_id' => $product_id, 'regular_price' => '13', 'expected_version' => $sale_version ] );
	$assert( 'product_pricing_sale_state_not_supported' === ( $sale_blocked['block_reason'] ?? '' ), 'Sale-price products must be blocked.' );

	$product->set_sale_price( '' );
	$product->set_price( '11' );
	$product->set_date_on_sale_from( null );
	$product->save();
	$schedule_baseline = YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $product_id ) );
	$product = wc_get_product( $product_id );
	$product->set_date_on_sale_from( time() - HOUR_IN_SECONDS );
	$product->save();
	$date_product = wc_get_product( $product_id );
	$date_version = YoRo_Commerce_Discovery_Service::product_version( $date_product );
	$assert( $date_version !== $schedule_baseline, 'Sale schedule state must participate in product version.' );
	$date_blocked = YoRo_Store_Inventory_Service::preview_update_product_pricing( [ 'product_id' => $product_id, 'regular_price' => '13', 'expected_version' => $date_version ] );
	$assert( 'product_pricing_sale_state_not_supported' === ( $date_blocked['block_reason'] ?? '' ), 'Sale schedules must be blocked even without a sale price.' );

	$product->set_date_on_sale_from( null );
	$product->set_regular_price( '11' );
	$product->set_price( '11' );
	$product->save();
	update_post_meta( $product_id, '_price', '12' );
	clean_post_cache( $product_id );
	$divergent = wc_get_product( $product_id );
	$divergent_blocked = YoRo_Store_Inventory_Service::preview_update_product_pricing( [
		'product_id' => $product_id,
		'regular_price' => '13',
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $divergent ),
	] );
	$assert( 'product_pricing_active_price_not_supported' === ( $divergent_blocked['block_reason'] ?? '' ), 'Raw active-price divergence must fail closed.' );

	$variable = new WC_Product_Variable();
	$variable->set_name( 'YoRo variable pricing smoke ' . wp_generate_uuid4() );
	$variable->set_status( 'draft' );
	$variable_id = $variable->save();
	$variable = wc_get_product( $variable_id );
	$variable_blocked = YoRo_Store_Inventory_Service::preview_update_product_pricing( [
		'product_id' => $variable_id,
		'regular_price' => '20',
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $variable ),
	] );
	$assert( 'product_pricing_target_not_supported' === ( $variable_blocked['block_reason'] ?? '' ), 'Variable parent pricing must be blocked.' );

	$grouped = new WC_Product_Grouped();
	$grouped->set_name( 'YoRo grouped pricing smoke ' . wp_generate_uuid4() );
	$grouped->set_status( 'draft' );
	$grouped_id = $grouped->save();
	$grouped = wc_get_product( $grouped_id );
	$grouped_blocked = YoRo_Store_Inventory_Service::preview_update_product_pricing( [
		'product_id' => $grouped_id,
		'regular_price' => '20',
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $grouped ),
	] );
	$assert( 'product_pricing_target_not_supported' === ( $grouped_blocked['block_reason'] ?? '' ), 'Grouped parent pricing must be blocked.' );

	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable_id );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( '5' );
	$variation->set_price( '5' );
	$variation_id = $variation->save();
	$variation = wc_get_product( $variation_id );
	$variation_result = YoRo_Store_Inventory_Service::update_product_pricing( [
		'product_id' => $variation_id,
		'regular_price' => '0',
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $variation ),
	] );
	$assert( is_array( $variation_result ) && '0.00' === (string) wc_get_product( $variation_id )->get_regular_price( 'edit' ), 'An exact variation and zero price must be supported.' );

	$empty = new WC_Product_Simple();
	$empty->set_name( 'YoRo empty pricing smoke ' . wp_generate_uuid4() );
	$empty->set_status( 'draft' );
	$empty_id = $empty->save();
	$empty = wc_get_product( $empty_id );
	$empty_result = YoRo_Store_Inventory_Service::update_product_pricing( [
		'product_id' => $empty_id,
		'regular_price' => '0',
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $empty ),
	] );
	$assert( is_array( $empty_result ) && '' === ( $empty_result['previous_regular_price'] ?? null ), 'An empty previous regular price must be recoverable.' );

	wp_set_current_user( 0 );
	$denied = YoRo_Store_Inventory_Service::preview_update_product_pricing( [
		'product_id' => $empty_id,
		'regular_price' => '2',
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $empty_id ) ),
	] );
	$assert( 'yoro_permission_denied' === ( $denied['block_reason'] ?? '' ), 'Pricing preview must enforce local permission.' );

	echo "product pricing update contract: PASS\n";
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_set_current_user( absint( $admin_ids[0] ) );
}
