<?php
/**
 * YoRo inventory stock update smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/inventory-stock-update-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if (
	! class_exists( 'YoRo_Store_Inventory_Service' ) &&
	class_exists( 'YoRo_AI_Core' ) &&
	function_exists( 'yoro_ai_core_has_woocommerce' ) &&
	yoro_ai_core_has_woocommerce()
) {
	YoRo_AI_Core::init();
}

$failures = [];

$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'WooCommerce' ), 'WooCommerce must be active for the inventory stock smoke contract.' );
$assert( class_exists( 'WC_Product_Simple' ), 'WC_Product_Simple is not available.' );
$assert( class_exists( 'YoRo_Store_Inventory_Service' ), 'YoRo_Store_Inventory_Service is not loaded.' );

if (
	class_exists( 'YoRo_Action_Registry' ) &&
	class_exists( 'YoRo_Orders_Actions' ) &&
	0 === count( YoRo_Action_Registry::all() )
) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

$original_manage_stock = get_option( 'woocommerce_manage_stock', '__yoro_missing__' );
$product_id            = 0;
$custom_stock_status_filter = static function ( $statuses ) {
	$statuses = is_array( $statuses ) ? $statuses : [];
	$statuses['preorder'] = 'Preorder';

	return $statuses;
};

add_filter( 'woocommerce_product_stock_status_options', $custom_stock_status_filter );

try {
	if ( class_exists( 'WC_Product_Simple' ) && class_exists( 'YoRo_Store_Inventory_Service' ) ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'YoRo stock smoke ' . wp_generate_uuid4() );
		$product->set_status( 'draft' );
		$product->set_regular_price( '10' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product_id = $product->save();
		$product = wc_get_product( $product_id );
		$product_version = YoRo_Commerce_Discovery_Service::product_version( $product );

		update_option( 'woocommerce_manage_stock', 'no', false );

		$blocked_preview = YoRo_Store_Inventory_Service::preview_update_product_stock(
			[
				'product_id'     => $product_id,
				'stock_quantity' => 8,
				'expected_version' => $product_version,
			]
		);
		$blocked_update  = YoRo_Store_Inventory_Service::update_product_stock(
			[
				'product_id'     => $product_id,
				'stock_quantity' => 8,
				'expected_version' => $product_version,
			]
		);

		$assert( ! empty( $blocked_preview['blocked'] ), 'Stock update preview must be blocked when global stock management is disabled.' );
		$assert( false === (bool) ( $blocked_preview['changes_store'] ?? true ), 'Blocked stock update preview must not claim it changes the store.' );
		$assert( 'stock_management_disabled' === ( $blocked_preview['block_reason'] ?? '' ), 'Blocked stock update preview must expose stock_management_disabled.' );
		$assert( is_wp_error( $blocked_update ), 'Stock update execution must return WP_Error when global stock management is disabled.' );
		$assert( is_wp_error( $blocked_update ) && 'stock_management_disabled' === $blocked_update->get_error_code(), 'Blocked stock update must use stock_management_disabled error code.' );

		update_option( 'woocommerce_manage_stock', 'yes', false );

		$preview = YoRo_Store_Inventory_Service::preview_update_product_stock(
			[
				'product_id'     => $product_id,
				'stock_quantity' => 8,
				'expected_version' => $product_version,
			]
		);

		$assert( empty( $preview['blocked'] ), 'Stock update preview must not be blocked when global stock management is enabled.' );
		$assert( ! empty( $preview['products'][0]['will_enable_stock_management'] ), 'Preview must disclose product-level stock management will be enabled.' );
		$assert( '' !== (string) ( $preview['message'] ?? '' ), 'Preview must expose localized user-facing stock update copy.' );

		if ( class_exists( 'YoRo_Runtime_Guard' ) ) {
			$plan    = [
				'type'   => 'single',
				'action' => 'products.update_stock',
				'params' => [
					'product_id'     => $product_id,
					'stock_quantity' => 8,
					'expected_version' => $product_version,
				],
			];
			$runtime = YoRo_Runtime_Guard::prepare(
				$plan,
				[
					'source'  => 'smoke',
					'route'   => 'execute',
					'preview' => false,
					'confirm' => false,
				]
			);
			$confirmation = ! is_wp_error( $runtime )
				? YoRo_Runtime_Guard::build_confirmation_response( $plan, [ 'action' => 'products.update_stock' ], $runtime )
				: [];

			$assert( ! is_wp_error( $runtime ), 'Runtime guard must prepare product stock update confirmation.' );
			$assert( ! empty( $confirmation['result']['preview']['products'][0]['will_enable_stock_management'] ), 'Runtime confirmation preview must expose product-level stock management enablement.' );
		}

		$result = YoRo_Store_Inventory_Service::update_product_stock(
			[
				'product_id'     => $product_id,
				'stock_quantity' => 8,
				'expected_version' => $product_version,
			]
		);

		$product = wc_get_product( $product_id );
		$product_version = YoRo_Commerce_Discovery_Service::product_version( $product );

		$assert( is_array( $result ) && ! empty( $result['success'] ), 'Confirmed stock update must succeed when global stock management is enabled.' );
		$assert( false === (bool) ( $result['previous_manage_stock'] ?? true ), 'Stock update result must expose previous_manage_stock=false.' );
		$assert( true === (bool) ( $result['enabled_stock_management'] ?? false ), 'Stock update result must expose enabled_stock_management=true.' );
		$assert( true === (bool) ( $result['manage_stock'] ?? false ), 'Stock update result must expose manage_stock=true.' );
		$assert( $product && $product->managing_stock(), 'Confirmed stock update must enable product-level stock management.' );
		$assert( $product && 8 === (int) $product->get_stock_quantity(), 'Confirmed stock update must set product stock quantity.' );
		$assert( $product && 'instock' === $product->get_stock_status(), 'Confirmed stock update with positive stock must set product in stock.' );

		$managed_status_update = YoRo_Store_Inventory_Service::update_product_stock_status(
			[
				'product_id'   => $product_id,
				'stock_status' => 'outofstock',
				'expected_version' => $product_version,
			]
		);

		$assert( is_wp_error( $managed_status_update ), 'Stock status update must be blocked for managed stock products.' );
		$assert( is_wp_error( $managed_status_update ) && 'stock_status_managed_by_quantity' === $managed_status_update->get_error_code(), 'Managed stock status update must use stock_status_managed_by_quantity.' );

		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->save();
		$product = wc_get_product( $product_id );
		$product_version = YoRo_Commerce_Discovery_Service::product_version( $product );

		$status_preview = YoRo_Store_Inventory_Service::preview_update_product_stock_status(
			[
				'product_id'   => $product_id,
				'stock_status' => 'outofstock',
				'expected_version' => $product_version,
			]
		);
		$status_result  = YoRo_Store_Inventory_Service::update_product_stock_status(
			[
				'product_id'   => $product_id,
				'stock_status' => 'outofstock',
				'expected_version' => $product_version,
			]
		);

		$product = wc_get_product( $product_id );
		$product_version = YoRo_Commerce_Discovery_Service::product_version( $product );

		$assert( empty( $status_preview['blocked'] ), 'Stock status preview must be allowed for products without stock management.' );
		$assert( 'manual' === ( $status_preview['products'][0]['stock_status_source'] ?? '' ), 'Stock status preview must expose manual source for unmanaged products.' );
		$assert( is_array( $status_result ) && ! empty( $status_result['success'] ), 'Confirmed stock status update must succeed for products without stock management.' );
		$assert( false === (bool) ( $status_result['manage_stock'] ?? true ), 'Stock status update must not enable product-level stock management.' );
		$assert( 'manual' === ( $status_result['stock_status_source'] ?? '' ), 'Stock status update result must expose manual source.' );
		$assert( $product && ! $product->managing_stock(), 'Confirmed stock status update must keep product-level stock management disabled.' );
		$assert( $product && 'outofstock' === $product->get_stock_status(), 'Confirmed stock status update must set the requested status.' );

		$custom_status_preview = YoRo_Store_Inventory_Service::preview_update_product_stock_status(
			[
				'product_id'   => $product_id,
				'stock_status' => 'preorder',
				'expected_version' => $product_version,
			]
		);
		$custom_status_result  = YoRo_Store_Inventory_Service::update_product_stock_status(
			[
				'product_id'   => $product_id,
				'stock_status' => 'preorder',
				'expected_version' => $product_version,
			]
		);

		$product = wc_get_product( $product_id );

		$assert( empty( $custom_status_preview['blocked'] ), 'Custom stock status preview must be allowed for configured stock statuses.' );
		$assert( 'preorder' === ( $custom_status_preview['products'][0]['target_stock_status'] ?? '' ), 'Custom stock status preview must preserve the configured status key.' );
		$assert( 'Preorder' === ( $custom_status_preview['products'][0]['target_stock_status_label'] ?? '' ), 'Custom stock status preview must expose the configured status label.' );
		$assert( is_array( $custom_status_result ) && ! empty( $custom_status_result['success'] ), 'Confirmed custom stock status update must succeed for configured stock statuses.' );
		$assert( 'preorder' === ( $custom_status_result['stock_status'] ?? '' ), 'Custom stock status update result must preserve the configured status key.' );
		$assert( 'Preorder' === ( $custom_status_result['stock_status_label'] ?? '' ), 'Custom stock status update result must expose the configured status label.' );
		$assert( $product && 'preorder' === $product->get_stock_status(), 'Confirmed custom stock status update must set the requested custom status.' );
		$adjustment_prior_user = get_current_user_id();
		$adjustment_admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
		wp_set_current_user( $adjustment_admins[0]->ID ?? 0 );
		update_option( 'woocommerce_manage_stock', 'yes' );
		$product = wc_get_product( $product_id );
		$product->set_manage_stock( true ); $product->set_stock_quantity( 10 ); $product->save();
		$fresh = static function () use ( $product_id ) { clean_post_cache( $product_id ); return wc_get_product( $product_id ); };
		$args = static function ( $delta ) use ( $product_id, $fresh ) { return [ 'product_id' => $product_id, 'stock_delta' => $delta, 'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $fresh() ) ]; };
		$unicode_product = $fresh(); $unicode_product->set_name( str_repeat( 'Việt ', 70 ) ); $unicode_product->save();
		$adjust = $args( 3 );
		$unicode_preview = YoRo_Store_Inventory_Service::preview_adjust_product_stock( $adjust );
		$assert( false !== wp_json_encode( $unicode_preview ) && mb_strlen( $unicode_preview['product_name'] ) <= 200, 'Long Unicode preview identity must stay bounded and JSON safe.' );
		$assert( empty( YoRo_Store_Inventory_Service::preview_adjust_product_stock( $adjust )['blocked'] ), 'Relative preview must allow supported owned stock.' );
		foreach ( [ ['stock_delta'=>0], ['stock_delta'=>1000001], ['stock_delta'=>-11], ['stock_delta'=>'2'], ['stock_quantity'=>2], ['product_sku'=>'x'], ['unexpected'=>null], ['expected_version'=>str_repeat('0',64)] ] as $bad ) {
			$assert( is_wp_error( YoRo_Store_Inventory_Service::prepare_product_stock_adjustment( array_merge( $adjust, $bad ) ) ), 'Malformed/unknown/stale/floor relative input must fail closed.' );
		}
		$prior_user = get_current_user_id();
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
		wp_set_current_user( $admins[0]->ID ?? 0 );
		try {
			$bare = YoRo_Action_Executor::execute( 'products.adjust_stock', $adjust, true );
			$assert( is_wp_error( $bare ) && 'stock_adjustment_execution_permit_required' === $bare->get_error_code(), 'Bare relative confirmation must require a claim.' );
			$used = false;
			$claim = static function ( $action, $params ) use ( $adjust, &$used ) { if ( $used || 'products.adjust_stock' !== $action || $adjust !== $params ) { return false; } $used = true; return true; };
			$ok = YoRo_Action_Executor::execute( 'products.adjust_stock', $adjust, true, false, [], $claim );
			$assert( ! is_wp_error( $ok ) && 13 === (int) $fresh()->get_stock_quantity(), 'One claimed relative adjustment must execute owner increase.' );
			$replay = YoRo_Action_Executor::execute( 'products.adjust_stock', $adjust, true, false, [], $claim );
			$assert( is_wp_error( $replay ) && 13 === (int) $fresh()->get_stock_quantity(), 'Consumed claim replay must not mutate.' );
			$spoof = YoRo_Plan_Executor::execute( [ 'type'=>'single', 'action'=>'products.adjust_stock', 'params'=>$args(1), 'context'=>['connector_remote_agent'=>true,'workflow_id'=>'captured','operation_id'=>'captured'] ], true, false );
			$assert( is_array( $spoof ) && false === ( $spoof['success'] ?? null ) && 'stock_adjustment_execution_permit_required' === ( $spoof['error']['code'] ?? '' ) && 13 === (int) $fresh()->get_stock_quantity(), 'Captured plan context cannot replace a claim.' );
		} finally { wp_set_current_user( $prior_user ); }
		$down = YoRo_Store_Inventory_Service::adjust_product_stock( $args( -2 ) );
		$assert( ! is_wp_error( $down ) && 11 === (int) $fresh()->get_stock_quantity(), 'Owner decrease must apply signed delta.' );
		$undo_result = YoRo_Store_Inventory_Service::restore_product_stock_state( $down['undo_plan'][0]['params'] );
		$assert( ! is_wp_error( $undo_result ) && 13 === (int) $fresh()->get_stock_quantity(), 'Relative success must reuse exact version-bound stock restore.' );
		$assert( is_wp_error( YoRo_Store_Inventory_Service::restore_product_stock_state( $down['undo_plan'][0]['params'] ) ), 'Stale stock restore must fail closed.' );
		$canonical_down = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $down, 'products.adjust_stock' );
		$assert( -2 === ( $canonical_down['data']['stock_delta'] ?? null ), 'Canonical negative delta must remain signed.' );
		$reset = $fresh(); $reset->set_stock_quantity( 11 ); $reset->save();
		wp_set_current_user( 0 );
		$assert( is_wp_error( YoRo_Store_Inventory_Service::prepare_product_stock_adjustment( $args( 1 ) ) ), 'Preview preparation must enforce local permission.' );
		wp_set_current_user( $adjustment_admins[0]->ID ?? 0 );
		$unmanaged = $fresh(); $unmanaged->set_manage_stock( false ); $unmanaged->save();
		$assert( is_wp_error( YoRo_Store_Inventory_Service::prepare_product_stock_adjustment( $args( 1 ) ) ), 'Relative adjustment must not enable unmanaged stock.' );
		$unmanaged->set_manage_stock( true ); $unmanaged->set_stock_quantity( 11 ); $unmanaged->save();
		$sequence_calls = 0;
		$denied_claim = static function () use ( &$sequence_calls ) { $sequence_calls++; return false; };
		YoRo_Plan_Executor::execute( [ 'type'=>'sequence', 'steps'=>[ [ 'action'=>'products.adjust_stock', 'params'=>$args(1), 'retry'=>['max_attempts'=>4] ] ] ], true, false, $denied_claim );
		$assert( 1 === $sequence_calls && 11 === (int) $fresh()->get_stock_quantity(), 'Relative sequence must make at most one claimed execution attempt.' );
		$parent = new WC_Product_Variable(); $variation = new WC_Product_Variation();
		try {
			$parent->set_name( 'C3 parent fixture' ); $parent->set_manage_stock( true ); $parent->set_stock_quantity( 10 ); $parent->save();
			$variation->set_parent_id( $parent->get_id() ); $variation->set_manage_stock( true ); $variation->set_stock_quantity( 5 ); $variation->save();
			$vargs = [ 'product_id'=>$variation->get_id(), 'stock_delta'=>1, 'expected_version'=>YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $variation->get_id() ) ) ];
			$assert( ! is_wp_error( YoRo_Store_Inventory_Service::adjust_product_stock( $vargs ) ), 'Exact own-stock variation must execute.' );
			$vargs['expected_version'] = $args(1)['expected_version'];
			$assert( is_wp_error( YoRo_Store_Inventory_Service::prepare_product_stock_adjustment( $vargs ) ), 'Cross-product version must fail.' );
			$pargs = [ 'product_id'=>$parent->get_id(), 'stock_delta'=>1, 'expected_version'=>YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $parent->get_id() ) ) ];
			$assert( is_wp_error( YoRo_Store_Inventory_Service::prepare_product_stock_adjustment( $pargs ) ), 'Variable parent must fail.' );
			$variation->set_manage_stock( false ); $variation->save();
			$vargs['expected_version'] = YoRo_Commerce_Discovery_Service::product_version( wc_get_product( $variation->get_id() ) );
			$assert( is_wp_error( YoRo_Store_Inventory_Service::prepare_product_stock_adjustment( $vargs ) ), 'Shared-parent variation must fail.' );
		} finally { $variation->delete( true ); $parent->delete( true ); }
		update_option( 'woocommerce_manage_stock', 'no' );
		$assert( is_wp_error( YoRo_Store_Inventory_Service::prepare_product_stock_adjustment( $args(1) ) ), 'Global stock off must fail.' );
		update_option( 'woocommerce_manage_stock', 'yes' );
		$approved = $args(1); $once = false;
		$exact_claim = static function ( $action, $params ) use ( $approved, &$once ) { if ( $once || 'products.adjust_stock' !== $action || $approved !== $params ) { return false; } $once = true; return true; };
		$assert( is_wp_error( YoRo_Action_Executor::execute( 'products.adjust_stock', array_merge( $approved, ['stock_delta'=>2] ), true, false, [], $exact_claim ) ), 'Mismatched claim params must fail.' );
		$assert( false === $once, 'A mismatched action must not consume the exact valid handoff.' );
		$interleaved = false;
		$hook = static function ( $sql, $id, $new, $operation ) use ( $product_id, &$interleaved ) {
			if ( $id === $product_id && ! $interleaved ) { $interleaved = true; wc_update_product_stock( $id, 4, 'increase' ); }
			return $sql;
		};
		add_filter( 'woocommerce_update_product_stock_query', $hook, 10, 4 );
		try { $contended = YoRo_Store_Inventory_Service::adjust_product_stock( $args( 2 ) ); }
		finally { remove_filter( 'woocommerce_update_product_stock_query', $hook, 10 ); }
		$assert( $interleaved && is_wp_error( $contended ) && 'stock_adjustment_reconciliation_required' === $contended->get_error_code(), 'Real owner arithmetic interleave must report reconciliation rather than a false exact success.' );
		$assert( 17 === (int) $fresh()->get_stock_quantity(), 'Concurrent owner arithmetic must retain both deltas.' );
		$sequence_claims = 0; $sequence_writes = 0; $sequence_params = $args(1);
		$sequence_claim = static function ( $action, $params ) use ( &$sequence_claims, $sequence_params ) { $sequence_claims++; return 1 === $sequence_claims && 'products.adjust_stock' === $action && $params === $sequence_params; };
		$after_write = static function ( $id ) use ( $product_id, &$sequence_writes ) { if ( $id === $product_id ) { $sequence_writes++; throw new RuntimeException( 'test unknown after arithmetic' ); } };
		add_action( 'woocommerce_updated_product_stock', $after_write );
		try { $unknown_sequence = YoRo_Plan_Executor::execute( [ 'type'=>'sequence', 'steps'=>[ [ 'action'=>'products.adjust_stock', 'params'=>$sequence_params, 'retry'=>['max_attempts'=>4] ] ] ], true, false, $sequence_claim ); }
		finally { remove_action( 'woocommerce_updated_product_stock', $after_write ); }
		$assert( 1 === $sequence_claims && 1 === $sequence_writes && 18 === (int) $fresh()->get_stock_quantity(), 'Sequence must not retry after relative arithmetic has actually begun.' );
		$assert( empty( $unknown_sequence['undo_plan'] ), 'An unknown relative outcome must never invent stock undo.' );


	}
} finally {
	if ( isset( $adjustment_prior_user ) ) { wp_set_current_user( $adjustment_prior_user ); }
	if ( $product_id > 0 ) {
		$product = wc_get_product( $product_id );

		if ( $product ) {
			$product->delete( true );
		}
	}

	if ( '__yoro_missing__' === $original_manage_stock ) {
		delete_option( 'woocommerce_manage_stock' );
	} else {
		update_option( 'woocommerce_manage_stock', $original_manage_stock, false );
	}

	remove_filter( 'woocommerce_product_stock_status_options', $custom_stock_status_filter );
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo inventory stock update smoke contract passed.\n";
