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
	}
} finally {
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
