<?php
/**
 * YoRo refund intelligence report smoke contract.
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

if ( ! class_exists( 'YoRo_Refund_Intelligence_Service' ) ) {
	YoRo_AI_Core::init();
}

$failures = [];
$assert = static function( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$order_id = 0;
$product_id = 0;

try {
	$product = new WC_Product_Simple();
	$product->set_name( 'YoRo refund intelligence product' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '25' );
	$product->set_price( '25' );
	$product_id = $product->save();

	$order = wc_create_order();
	$order_item_id = $order->add_product( $product, 1 );
	$order->set_status( 'processing' );
	$order->set_currency( get_woocommerce_currency() );
	$order->set_date_created( new WC_DateTime( '2020-01-15 12:00:00', wp_timezone() ) );
	$order->calculate_totals();
	$order->save();
	$order_id = $order->get_id();

	$refund = wc_create_refund(
		[
			'amount'         => 10,
			'reason'         => 'YoRo intelligence damaged item',
			'order_id'       => $order_id,
			'line_items'     => [
				$order_item_id => [
					'qty'          => 1,
					'refund_total' => 10,
					'refund_tax'   => [],
				],
			],
			'refund_payment' => false,
			'restock_items'  => false,
		]
	);
	$assert( $refund instanceof WC_Order_Refund, 'Refund intelligence fixture refund must be created.' );

	$result = YoRo_Refund_Intelligence_Service::get_refund_intelligence(
		[
			'window'       => 'today',
			'time_window'  => [ 'canonical_key' => 'today' ],
			'limit'        => 20,
			'answer_shape' => 'report',
		]
	);
	$invalid_comparison = YoRo_Refund_Intelligence_Service::get_refund_intelligence(
		[
			'window'      => 'today',
			'time_window' => [
				'canonical_key' => 'today',
				'comparison'    => [ 'start' => 'invalid' ],
			],
		]
	);

	$refund_ids = array_map( 'absint', array_column( is_array( $result ) ? ( $result['refunds'] ?? [] ) : [], 'refund_id' ) );
	$product_ids = array_map( 'absint', array_column( is_array( $result ) ? ( $result['top_products'] ?? [] ) : [], 'product_id' ) );
	$metrics = is_array( $result ) ? ( $result['metrics'] ?? [] ) : [];
	$rate = (float) ( $metrics['refunded_order_rate'] ?? 0 );

	$assert( ! is_wp_error( $result ) && ! empty( $result['success'] ), 'Refund intelligence report must execute successfully.' );
	$assert( in_array( $refund->get_id(), $refund_ids, true ), 'Safe refund drilldowns must include the current refund event for an older parent order.' );
	$assert( in_array( $product_id, $product_ids, true ), 'Refund intelligence must aggregate top refunded products.' );
	$assert( (float) ( $metrics['refund_amount'] ?? 0 ) >= 10, 'Refund intelligence must aggregate refund amount.' );
	$assert( (int) ( $metrics['refund_count'] ?? 0 ) >= 1, 'Refund intelligence must aggregate refund count.' );
	$assert( (int) ( $metrics['refunded_revenue_orders'] ?? 0 ) <= (int) ( $metrics['revenue_order_count'] ?? 0 ), 'Refunded-order-rate numerator must be the intersection with same-window revenue orders.' );
	$assert( $rate >= 0 && $rate <= 100, 'Refunded order rate must remain within 0-100% even when a current refund belongs to an older order.' );
	$assert( ! empty( $result['comparison']['metrics']['refund_amount'] ), 'Refund intelligence must include previous-period comparison metrics.' );
	$assert( 'refund_intelligence' === ( $result['report']['type'] ?? '' ), 'Refund intelligence must return the canonical report type.' );
	$assert( is_wp_error( $invalid_comparison ) && 'invalid_refund_report_comparison' === $invalid_comparison->get_error_code(), 'An explicitly supplied invalid comparison window must fail closed.' );
} finally {
	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$order->delete( true );
		}
	}

	if ( $product_id > 0 ) {
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

echo "YoRo refund intelligence contract passed.\n";
