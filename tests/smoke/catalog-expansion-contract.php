<?php
/**
 * YoRo product and coupon catalog expansion smoke contract.
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

if ( ! class_exists( 'YoRo_Store_Catalog_Service' ) ) {
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
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;
$product_id = 0;
$coupon_id = 0;

try {
	$assert( $admin_id > 0, 'An administrator is required for catalog write smoke tests.' );

	if ( $admin_id > 0 ) {
		wp_set_current_user( $admin_id );
	}

	$product = new WC_Product_Simple();
	$product->set_name( 'YoRo catalog smoke ' . wp_generate_uuid4() );
	$product->set_status( 'draft' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '100' );
	$product_id = $product->save();

	$details = YoRo_Store_Catalog_Service::get_product_details( [ 'product_id' => $product_id ] );
	$invalid_sale = YoRo_Store_Catalog_Service::preview_update_product_catalog(
		[
			'product_id'    => $product_id,
			'sale_price'    => 120,
		]
	);
	$product_params = [
		'product_id'        => $product_id,
		'regular_price'     => 90,
		'sale_price'        => 80,
		'status'            => 'private',
		'catalog_visibility' => 'hidden',
	];
	$product_preview = YoRo_Store_Catalog_Service::preview_update_product_catalog( $product_params );
	$product_confirmation = YoRo_Action_Executor::execute( 'products.update_catalog', $product_params, false, false );
	$product_result = YoRo_Action_Executor::execute( 'products.update_catalog', $product_params, true, false );
	$product = wc_get_product( $product_id );

	$assert( ! empty( $details['success'] ) && true === ( $details['read_only'] ?? false ), 'Product details must be a successful read-only action.' );
	$assert( 'sale_price_exceeds_regular_price' === ( $invalid_sale['block_reason'] ?? '' ), 'Product preview must block sale prices above regular price.' );
	$assert( empty( $product_preview['blocked'] ), 'Valid product catalog patch must be executable after approval.' );
	$assert( ! empty( $product_confirmation['requires_confirmation'] ), 'Product catalog patch must require confirmation.' );
	$assert( ! empty( $product_result['success'] ), 'Confirmed product catalog patch must succeed.' );
	$assert( '90' === $product->get_regular_price() && '80' === $product->get_sale_price(), 'Product catalog patch must persist regular and sale prices.' );
	$assert( 'private' === $product->get_status() && 'hidden' === $product->get_catalog_visibility(), 'Product catalog patch must persist status and visibility.' );

	$coupon = new WC_Coupon();
	$coupon->set_code( 'YORO-CATALOG-' . wp_generate_password( 8, false, false ) );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( 10 );
	$coupon_id = $coupon->save();
	$coupon_code = $coupon->get_code();

	$missing_patch = YoRo_Coupons_Service::preview_update_coupon( [ 'coupon_code' => $coupon_code ] );
	$coupon_params = [
		'coupon_code'     => $coupon_code,
		'discount_amount' => 15,
		'description'     => 'Updated by YoRo catalog smoke',
		'enabled'         => false,
	];
	$coupon_preview = YoRo_Coupons_Service::preview_update_coupon( $coupon_params );
	$coupon_confirmation = YoRo_Action_Executor::execute( 'coupons.update', $coupon_params, false, false );
	$coupon_result = YoRo_Action_Executor::execute( 'coupons.update', $coupon_params, true, false );
	$coupon = new WC_Coupon( $coupon_id );

	$assert( 'missing_coupon_changes' === ( $missing_patch['block_reason'] ?? '' ), 'Coupon update preview must require at least one patch field.' );
	$assert( empty( $coupon_preview['blocked'] ), 'Valid coupon patch must be executable after approval.' );
	$assert( ! empty( $coupon_confirmation['requires_confirmation'] ), 'Coupon patch must require confirmation.' );
	$assert( ! empty( $coupon_result['success'] ), 'Confirmed coupon patch must succeed.' );
	$assert( 15.0 === (float) $coupon->get_amount(), 'Coupon patch must persist the updated discount amount.' );
	$assert( 'draft' === $coupon->get_status(), 'Coupon enabled=false must move the coupon to draft without deleting it.' );
	$assert( get_post( $coupon_id ) instanceof WP_Post, 'Coupon lifecycle update must preserve the coupon record.' );
} finally {
	if ( $product_id > 0 ) {
		$product = wc_get_product( $product_id );

		if ( $product ) {
			$product->delete( true );
		}
	}

	if ( $coupon_id > 0 ) {
		wp_delete_post( $coupon_id, true );
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo catalog expansion smoke contract passed.\n";
