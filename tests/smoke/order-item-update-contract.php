<?php
/** Bounded pending-order item update: local-only registry, closed inputs and receipt binding. */
use Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentsManager;
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WooCommerce' ) ) { throw new RuntimeException( 'Disposable WordPress/WooCommerce fixture required.' ); }
$assertions = 0;
$assert = static function ( $value, $message ) use ( &$assertions ) { ++$assertions; if ( ! $value ) { throw new RuntimeException( $message ); } };
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admins ), 'Administrator fixture required.' ); wp_set_current_user( $admins[0] );
YoRo_Orders_Actions::register();
$action = YoRo_Action_Registry::get( 'orders.update_items' );
$assert( is_array( $action ) && true === $action['write_action'] && true === $action['requires_confirmation'] && false === $action['supports_bulk'], 'Item update is one confirmed non-bulk write.' );
$assert( [ 'order_id', 'line_items' ] === $action['required_params'], 'Item update has the closed canonical parameter set.' );
foreach ( [ [], [ 'order_id' => 1 ], [ 'order_id' => '1', 'line_items' => [] ], [ 'order_id' => 9007199254740992, 'line_items' => [ [ 'product_id' => 1, 'quantity' => 1 ] ] ], [ 'order_id' => 1, 'line_items' => [ [ 'product_id' => 9007199254740992, 'quantity' => 1 ] ] ], [ 'order_id' => 1, 'line_items' => [ [ 'product_id' => 1, 'quantity' => 1 ], [ 'product_id' => 1, 'quantity' => 2 ] ] ], [ 'order_id' => 1, 'line_items' => [ [ 'product_id' => 1, 'quantity' => 0 ] ] ], [ 'order_id' => 1, 'line_items' => [ [ 'product_id' => 1, 'quantity' => 1 ] ], 'status' => 'pending' ] ] as $invalid ) { $assert( is_wp_error( YoRo_Orders_Write_Service::update_items_params( $invalid ) ), 'Closed item-update input rejects invalid, duplicate, coerced and extra fields.' ); }
$params = [ 'order_id' => 1, 'line_items' => [ [ 'product_id' => 1, 'quantity' => 1 ] ] ];
$plan = [ 'type' => 'single', 'action' => 'orders.update_items', 'params' => $params ];
$parsed = [ 'meta' => [ 'pending_operation_token' => 'yr0085-pending', 'conversation_id' => 'yr0085-conversation' ] ];
$preflight = [ 'preview_hash' => str_repeat( 'a', 64 ), 'preview' => [ 'operations' => [ [ 'preview' => [ 'type' => 'write', 'blocked' => false, 'changes_store' => true, 'estimate' => true, 'final' => false ] ] ] ] ];
$receipt = YoRo_Runtime_Guard::creation_receipt( $plan, $parsed, $preflight );
$assert( '' !== $receipt && YoRo_Runtime_Guard::verify_creation_receipt( $receipt, $plan, $parsed, $preflight ), 'Update receipt binds the exact local plan.' );
$other = $plan; $other['action'] = 'orders.create';
$assert( ! YoRo_Runtime_Guard::verify_creation_receipt( $receipt, $other, $parsed, $preflight ), 'Update receipt cannot cross to order creation.' );
$blocked_preflight = [ 'preview_hash' => str_repeat( 'b', 64 ), 'preview' => [ 'operations' => [ [ 'preview' => [ 'type' => 'write', 'blocked' => true, 'changes_store' => false, 'estimate' => false, 'final' => false ] ] ] ] ];
$assert( '' === YoRo_Runtime_Guard::creation_receipt( $plan, $parsed, $blocked_preflight ), 'Blocked update preview cannot receive a receipt.' );
$products = []; $update_order = null; $fulfillments_enabled = static function () { return 'yes'; };
try {
	add_filter( 'pre_option_woocommerce_feature_fulfillments_enabled', $fulfillments_enabled );
	wc_get_container()->get( FulfillmentsManager::class )->register();
	foreach ( [ 'YR0085 retained line', 'YR0085 removable line' ] as $name ) { $product = new WC_Product_Simple(); $product->set_name( $name ); $product->set_status( 'publish' ); $product->set_regular_price( '11.00' ); $product->save(); $products[] = $product; }
	$update_order = wc_create_order(); $update_order->set_status( 'pending' ); $update_order->add_product( $products[0], 1 ); $update_order->add_product( $products[1], 1 ); $update_order->calculate_totals(); $update_order->save();
	$runtime_params = [ 'order_id' => $update_order->get_id(), 'line_items' => [ [ 'product_id' => $products[0]->get_id(), 'quantity' => 1 ] ] ];
	$runtime_plan = [ 'type' => 'single', 'action' => 'orders.update_items', 'params' => $runtime_params ];
	$runtime_preflight = YoRo_Safety_Contract::preflight( $runtime_plan );
	$assert( false === ( $runtime_preflight['preview']['operations'][0]['preview']['blocked'] ?? true ), 'Disposable pending guest fixture has a positive update preview.' );
	$hide_current_lines = static function ( $items, $order, $types ) use ( $update_order ) { return $order->get_id() === $update_order->get_id() && in_array( 'line_item', $types, true ) ? [] : $items; };
	add_filter( 'woocommerce_order_get_items', $hide_current_lines, 10, 3 );
	try { $filtered_preflight = YoRo_Safety_Contract::preflight( $runtime_plan ); }
	finally { remove_filter( 'woocommerce_order_get_items', $hide_current_lines, 10 ); }
	$assert( true === ( $filtered_preflight['preview']['operations'][0]['preview']['blocked'] ?? false ), 'Filtered current line view cannot become an approval preview.' );
	$after_remove = static function () { throw new RuntimeException( 'Injected calculation failure after item removal.' ); };
	add_action( 'woocommerce_order_before_calculate_totals', $after_remove, 10, 2 );
	try { $partial = YoRo_Orders_Write_Service::update_items( $runtime_params, [ 'plan' => $runtime_plan, 'preview_hash' => $runtime_preflight['preview_hash'] ] ); }
	finally { remove_action( 'woocommerce_order_before_calculate_totals', $after_remove, 10 ); }
	$assert( is_array( $partial ) && false === $partial['success'] && true === $partial['order_item_update']['outcome_unknown'] && 'reconciliation_required' === $partial['order_item_update']['status'] && $runtime_params['order_id'] === $partial['order_item_update']['order_id'], 'Injected post-removal failure is reconciliation-required and never reported as not applied.' );
} finally {
	if ( $update_order instanceof WC_Order ) { $update_order->delete( true ); }
	foreach ( $products as $product ) { wp_delete_post( $product->get_id(), true ); }
	remove_filter( 'pre_option_woocommerce_feature_fulfillments_enabled', $fulfillments_enabled );
}
$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/actions/class-yoro-orders-write-service.php' );
$assert( is_string( $source ) && ! str_contains( $source, 'set_subtotal(' ) && ! str_contains( $source, 'set_total(' ), 'Item update has no YoRo custom repricing path.' );
$assert( is_string( $source ) && str_contains( $source, "'current_subtotal'" ) && str_contains( $source, "'current_tax'" ) && str_contains( $source, '$stored_money' ) && str_contains( $source, "'subtotal' => \$final_money['subtotal']" ), 'Preview shows persisted money and execution verifies every final owner money fact.' );
$assert( is_string( $source ) && str_contains( $source, '$wanted_product_ids = array_column' ) && str_contains( $source, 'remove_item( $change[\'old_line_item_id\'] ); $durable = true;' ) && str_contains( $source, 'get_data_store(); $stored_items = $store->read_items' ) && str_contains( $source, "'native_status' => \$observation['native_status']" ), 'Remove-only persistence is reconciliation-bound, final facts use owner data-store reads, and the complete native observation is preview-bound.' );
$assert( is_string( $source ) && str_contains( $source, 'update_preview_payload' ) && str_contains( $source, 'YoRo_Safety_Contract::hash( self::update_preview_payload( $snapshot, $params ) )' ), 'The full displayed owner preview is recomputed and compared again immediately before the first item write.' );
echo "YoRo order item update smoke contract passed ({$assertions} assertions).\n";
