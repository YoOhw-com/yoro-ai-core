<?php
/**
 * YoRo wave-two refund and batch catalog write smoke contract.
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

if ( ! class_exists( 'YoRo_Order_Refund_Service' ) ) {
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
$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$admin_id  = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;
$order_id  = 0;
$product_ids = [];
$batch_key = 'yoro-wave2-batch-' . wp_generate_uuid4();
$batch_option = YoRo_Store_Catalog_Service::BATCH_IDEMPOTENCY_OPTION_PREFIX . substr( hash( 'sha256', $batch_key ), 0, 40 );
$refund_lock_option = '';

try {
	$assert( $admin_id > 0, 'An administrator is required for write-action smoke tests.' );

	if ( $admin_id > 0 ) {
		wp_set_current_user( $admin_id );
	}

	$product = new WC_Product_Simple();
	$product->set_name( 'YoRo wave-two refund product' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '100' );
	$product->set_price( '100' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 5 );
	$product->set_sku( 'YORO-WAVE2-A-' . wp_rand( 1000, 9999 ) );
	$product_id = $product->save();
	$product_ids[] = $product_id;

	$product_two = new WC_Product_Simple();
	$product_two->set_name( 'YoRo wave-two batch product' );
	$product_two->set_status( 'publish' );
	$product_two->set_regular_price( '40' );
	$product_two->set_price( '40' );
	$product_two->set_sku( 'YORO-WAVE2-B-' . wp_rand( 1000, 9999 ) );
	$product_two_id = $product_two->save();
	$product_ids[] = $product_two_id;

	$variable_product = new WC_Product_Variable();
	$variable_product->set_name( 'YoRo wave-two variable product' );
	$variable_product->set_status( 'publish' );
	$variable_product_id = $variable_product->save();
	$product_ids[] = $variable_product_id;

	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable_product_id );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( '30' );
	$variation->set_price( '30' );
	$variation_id = $variation->save();
	$product_ids[] = $variation_id;

	$order = wc_create_order();
	$order_item_id = $order->add_product( $product, 2 );
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'YoRo wave-two shipping' );
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_total( 10 );
	$order->add_item( $shipping );
	$order->set_status( 'processing' );
	$order->set_currency( get_woocommerce_currency() );
	$order->set_payment_method( 'cod' );
	$order->calculate_totals();
	$order->save();
	$order_id = $order->get_id();
	$order_item = $order->get_item( $order_item_id, false );
	$expected_remaining_line_amount = wc_format_decimal( (float) $order_item->get_total(), 2 );
	$product_before_refund = wc_get_product( $product_id );
	$stock_before_refund   = (int) $product_before_refund->get_stock_quantity();

	$refund_key = 'yoro-wave2-refund-' . wp_generate_uuid4();
	$refund_params = [
		'order_id'        => $order_id,
		'amount'          => 60,
		'reason'          => 'YoRo wave-two damaged item',
		'refund_payment'  => false,
		'line_items'      => [
			[
				'order_item_id' => $order_item_id,
				'quantity'      => 1,
				'amount'        => 50,
				'tax'           => 0,
			],
		],
		'shipping_amount' => 10,
		'restock_items'   => true,
		'idempotency_key' => $refund_key,
	];
	$refund_preview = YoRo_Order_Refund_Service::preview_create_refund( $refund_params );
	$unknown_tax_preview = YoRo_Order_Refund_Service::preview_create_refund(
		array_replace_recursive( $refund_params, [ 'line_items' => [ 0 => [ 'tax' => [ 999999 => 0 ] ] ] ] )
	);
	$refund_lock_option = YoRo_Order_Refund_Service::IDEMPOTENCY_LOCK_OPTION_PREFIX
		. $order_id;
	add_option( $refund_lock_option, [ 'status' => 'smoke_busy' ], '', false );
	$refund_lock_busy = YoRo_Order_Refund_Service::create_refund( $refund_params );
	delete_option( $refund_lock_option );
	$refund_result  = YoRo_Action_Executor::execute( 'orders.create_refund', $refund_params, true, false );
	$product_after_refund = wc_get_product( $product_id );
	$order_after_refund   = wc_get_order( $order_id );
	$refund_retry         = YoRo_Action_Executor::execute( 'orders.create_refund', $refund_params, true, false );
	$order_after_retry    = wc_get_order( $order_id );
	$product_after_retry  = wc_get_product( $product_id );
	$refund_conflict      = YoRo_Order_Refund_Service::create_refund( array_merge( $refund_params, [ 'amount' => 55, 'shipping_amount' => 5 ] ) );

	$assert( empty( $refund_preview['blocked'] ), 'Valid line-item refund preview must be executable after approval.' );
	$assert( 1 === ( $refund_preview['line_items'][0]['remaining_quantity'] ?? 0 ) - 1, 'Refund preview must expose live remaining quantity before execution.' );
	$assert( $expected_remaining_line_amount === (string) ( $refund_preview['line_items'][0]['remaining_amount'] ?? '' ), 'Refund preview must expose the live remaining amount from the order item.' );
	$assert( '10.00' === (string) ( $refund_preview['shipping_remaining_amount'] ?? '' ), 'Refund preview must expose live remaining shipping amount.' );
	$assert( 1 === ( $refund_preview['inventory_impact'][0]['quantity_change'] ?? 0 ), 'Refund preview must expose restock inventory impact.' );
	$assert( 'refund_tax_rate_unknown' === ( $unknown_tax_preview['block_reason'] ?? '' ), 'Refund preview must fail closed for tax rate IDs not present on the original order item.' );
	$assert( is_wp_error( $refund_lock_busy ) && 'refund_idempotency_in_progress' === $refund_lock_busy->get_error_code(), 'A concurrent refund with the same idempotency key must fail closed while its atomic lock is held.' );
	$assert( false === strpos( $refund_lock_option, $refund_key ), 'Refund idempotency lock names must not contain the plaintext key.' );
	$assert( ! is_wp_error( $refund_result ) && ! empty( $refund_result['success'] ), 'Confirmed line-item refund must succeed.' );
	$assert( 60.0 === (float) $order_after_refund->get_total_refunded(), 'Line-item and shipping refund must update the order refunded total.' );
	$assert( $stock_before_refund + 1 === (int) $product_after_refund->get_stock_quantity(), 'Confirmed restock must return the refunded quantity to inventory.' );
	$assert( ! is_wp_error( $refund_retry ) && ! empty( $refund_retry['idempotent_replay'] ), 'Exact refund retry must return the saved idempotent result.' );
	$assert( 1 === count( $order_after_retry->get_refunds() ), 'Exact refund retry must not create a duplicate refund.' );
	$assert( $stock_before_refund + 1 === (int) $product_after_retry->get_stock_quantity(), 'Exact refund retry must not restock inventory twice.' );
	$assert( is_wp_error( $refund_conflict ) && 'refund_idempotency_key_conflict' === $refund_conflict->get_error_code(), 'Reusing a refund idempotency key with different parameters must fail closed.' );

	$batch_params = [
		'idempotency_key' => $batch_key,
		'items'           => [
			[
				'product_id' => $product_id,
				'patch'      => [ 'regular_price' => 120 ],
			],
			[
				'product_sku' => $product_two->get_sku(),
				'patch'       => [ 'catalog_visibility' => 'hidden' ],
			],
			[
				'variation_id' => $variation_id,
				'patch'        => [ 'regular_price' => 35 ],
			],
		],
	];
	$batch_preview = YoRo_Store_Catalog_Service::preview_update_product_catalog_batch( $batch_params );
	$batch_result  = YoRo_Action_Executor::execute( 'products.update_catalog_batch', $batch_params, true, false );
	$product_after_batch     = wc_get_product( $product_id );
	$product_two_after_batch = wc_get_product( $product_two_id );
	$variation_after_batch   = wc_get_product( $variation_id );
	$batch_retry             = YoRo_Action_Executor::execute( 'products.update_catalog_batch', $batch_params, true, false );
	$batch_conflict          = YoRo_Store_Catalog_Service::update_product_catalog_batch(
		array_replace_recursive( $batch_params, [ 'items' => [ 0 => [ 'patch' => [ 'regular_price' => 130 ] ] ] ] )
	);

	$assert( empty( $batch_preview['blocked'] ) && 3 === ( $batch_preview['count'] ?? 0 ), 'Catalog batch preview must validate all per-target patches.' );
	$assert( ! is_wp_error( $batch_result ) && ! empty( $batch_result['success'] ) && 3 === count( $batch_result['processed'] ?? [] ), 'Confirmed catalog batch must return per-item successful results.' );
	$assert( '120' === (string) $product_after_batch->get_regular_price(), 'Catalog batch must update the first target by product ID.' );
	$assert( 'hidden' === $product_two_after_batch->get_catalog_visibility(), 'Catalog batch must update the second target by SKU.' );
	$assert( '35' === (string) $variation_after_batch->get_regular_price(), 'Catalog batch must update an explicit variation_id target.' );
	$assert( ! is_wp_error( $batch_retry ) && ! empty( $batch_retry['idempotent_replay'] ), 'Exact catalog batch retry must return the saved result.' );
	$assert( is_wp_error( $batch_conflict ) && 'catalog_batch_idempotency_key_conflict' === $batch_conflict->get_error_code(), 'Catalog batch idempotency-key reuse with a changed patch must fail closed.' );
} finally {
	delete_option( $batch_option );

	if ( '' !== $refund_lock_option ) {
		delete_option( $refund_lock_option );
	}

	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$order->delete( true );
		}
	}

	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( $product_id );

		if ( $product ) {
			$product->delete( true );
		}
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo wave-two commerce write contract passed.\n";
