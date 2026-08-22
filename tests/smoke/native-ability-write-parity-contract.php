<?php
/**
 * Native WooCommerce write parity, permission, and fail-closed smoke contract.
 *
 * Run through WordPress with the plugin worktree loaded. Store fixtures are
 * created inside a database transaction and rolled back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( class_exists( 'YoRo_AI_Core' ) ) {
	YoRo_AI_Core::init();
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

class YoRo_YR0008_Forbidden_Local_Provider implements YoRo_Action_Execution_Provider {
	public $executions = 0;

	public function provider_kind() {
		return 'local';
	}

	public function assess( $action_name, array $action, $params = [], $context = [] ) {
		return [ 'eligible' => true, 'reason' => 'local_default' ];
	}

	public function execute( $action_name, array $action, $params = [], $context = [], array $decision = [] ) {
		$this->executions++;

		return [ 'success' => true, 'source' => 'forbidden_local' ];
	}
}

$assert( function_exists( 'wp_get_ability' ), 'The installed WordPress runtime must provide the Abilities API.' );
$order_ability = wp_get_ability( 'woocommerce/order-update-status' );
$product_ability = wp_get_ability( 'woocommerce/product-update' );
$assert( is_object( $order_ability ) && is_callable( [ $order_ability, 'execute' ] ), 'The installed WooCommerce order status ability is required.' );
$assert( is_object( $product_ability ) && is_callable( [ $product_ability, 'execute' ] ), 'The installed WooCommerce product update ability is required.' );

$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admin_ids ), 'An administrator is required for native write smokes.' );
$original_user_id = get_current_user_id();
$subscriber_id = 0;
$original_manage_stock = get_option( 'woocommerce_manage_stock', '__yoro_missing__' );

global $wpdb;
$wpdb->query( 'START TRANSACTION' );

try {
	wp_set_current_user( absint( $admin_ids[0] ) );
	update_option( 'woocommerce_manage_stock', 'yes', false );

	$order = wc_create_order( [ 'status' => 'processing', 'customer_id' => 0 ] );
	$assert( $order instanceof WC_Order, 'An order fixture is required.' );
	$order_id = $order->get_id();

	$product = new WC_Product_Simple();
	$product->set_name( 'YoRo native quantity parity ' . wp_generate_uuid4() );
	$product->set_status( 'draft' );
	$product->set_regular_price( '10' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 4 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	$status_product = new WC_Product_Simple();
	$status_product->set_name( 'YoRo native status parity ' . wp_generate_uuid4() );
	$status_product->set_status( 'draft' );
	$status_product->set_regular_price( '10' );
	$status_product->set_manage_stock( false );
	$status_product->set_stock_status( 'instock' );
	$status_product_id = $status_product->save();

	$digital_product = new WC_Product_Simple();
	$digital_product->set_name( 'YoRo native digital alias ' . wp_generate_uuid4() );
	$digital_product->set_status( 'draft' );
	$digital_product->set_regular_price( '10' );
	$digital_product->set_virtual( true );
	$digital_product->set_downloadable( true );
	$digital_product->set_manage_stock( true );
	$digital_product->set_stock_quantity( 2 );
	$digital_product->set_stock_status( 'instock' );
	$digital_product_id = $digital_product->save();

	$provider = new YoRo_Native_Ability_Execution_Provider();
	$dummy_action = [ 'permission' => YoRo_Capabilities::EXECUTE_ACTIONS, 'callback' => static function() { return [ 'success' => true ]; } ];

	$order = wc_get_order( $order_id );
	$order_params = [
		'order_ids' => [ $order_id ],
		'status' => 'completed',
		'expected_versions' => [
			[ 'order_id' => $order_id, 'expected_version' => YoRo_Order_Presenter::order_version( $order ) ],
		],
	];
	$local_order = YoRo_Orders_Write_Service::update_order_status( $order_params );
	$assert( is_array( $local_order ) && ! empty( $local_order['success'] ), 'The local order write must succeed for parity setup.' );
	$order = wc_get_order( $order_id );
	$order->set_status( 'processing' );
	$order->save();
	$order = wc_get_order( $order_id );
	$order_params['expected_versions'][0]['expected_version'] = YoRo_Order_Presenter::order_version( $order );
	$order_decision = $provider->assess( 'orders.update_status', $dummy_action, $order_params );
	$assert( ! empty( $order_decision['eligible'] ), 'The exact single versioned order write must select native. Decision: ' . wp_json_encode( $order_decision ) );
	$native_order = $provider->execute( 'orders.update_status', $dummy_action, $order_params, [], $order_decision );
	$assert( is_array( $native_order ) && ! empty( $native_order['success'] ), 'The native order write must succeed.' );
	$assert( array_keys( $local_order ) === array_keys( $native_order ), 'Local and native order writes must preserve the same result shape.' );
	$assert( $local_order['processed'] === $native_order['processed'] && $local_order['status'] === $native_order['status'], 'Local and native order writes must report the same semantic result.' );

	$product = wc_get_product( $product_id );
	$product_params = [
		'product_id'       => $product_id,
		'stock_quantity'   => 7,
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $product ),
	];
	$local_stock = YoRo_Store_Inventory_Service::update_product_stock( $product_params );
	$assert( is_array( $local_stock ) && ! empty( $local_stock['success'] ), 'The local stock quantity write must succeed for parity setup.' );
	$product = wc_get_product( $product_id );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 4 );
	$product->set_stock_status( 'instock' );
	$product->save();
	$product = wc_get_product( $product_id );
	$product_params['expected_version'] = YoRo_Commerce_Discovery_Service::product_version( $product );
	$product_decision = $provider->assess( 'products.update_stock', $dummy_action, $product_params );
	$assert( ! empty( $product_decision['eligible'] ), 'The exact simple-product stock quantity write must select native. Decision: ' . wp_json_encode( $product_decision ) );
	$native_stock = $provider->execute( 'products.update_stock', $dummy_action, $product_params, [], $product_decision );
	$assert( is_array( $native_stock ) && ! empty( $native_stock['success'] ), 'The native stock quantity write must succeed.' );
	$assert( array_keys( $local_stock ) === array_keys( $native_stock ), 'Local and native stock quantity writes must preserve the same result shape.' );
	$assert( 7 === $native_stock['stock_quantity'] && true === (bool) $native_stock['manage_stock'], 'Native stock quantity post-state must be authoritative.' );

	$status_product = wc_get_product( $status_product_id );
	$status_params = [
		'product_id'       => $status_product_id,
		'stock_status'     => 'outofstock',
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $status_product ),
	];
	$local_status = YoRo_Store_Inventory_Service::update_product_stock_status( $status_params );
	$assert( is_array( $local_status ) && ! empty( $local_status['success'] ), 'The local stock status write must succeed for parity setup.' );
	$status_product = wc_get_product( $status_product_id );
	$status_product->set_stock_status( 'instock' );
	$status_product->save();
	$status_product = wc_get_product( $status_product_id );
	$status_params['expected_version'] = YoRo_Commerce_Discovery_Service::product_version( $status_product );
	$status_decision = $provider->assess( 'products.update_stock_status', $dummy_action, $status_params );
	$assert( ! empty( $status_decision['eligible'] ), 'The exact simple-product stock status write must select native. Decision: ' . wp_json_encode( $status_decision ) );
	$native_status = $provider->execute( 'products.update_stock_status', $dummy_action, $status_params, [], $status_decision );
	$assert( is_array( $native_status ) && ! empty( $native_status['success'] ), 'The native stock status write must succeed.' );
	$assert( array_keys( $local_status ) === array_keys( $native_status ), 'Local and native stock status writes must preserve the same result shape.' );
	$assert( 'outofstock' === $native_status['stock_status'], 'Native stock status post-state must be authoritative.' );

	$digital_product = wc_get_product( $digital_product_id );
	$digital_params = [
		'product_id'       => $digital_product_id,
		'stock_quantity'   => 5,
		'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $digital_product ),
	];
	$digital_decision = $provider->assess( 'products.update_stock', $dummy_action, $digital_params );
	$assert( ! empty( $digital_decision['eligible'] ), 'A supported digital simple-product alias must select native.' );
	$digital_result = $provider->execute( 'products.update_stock', $dummy_action, $digital_params, [], $digital_decision );
	$assert( is_array( $digital_result ) && ! empty( $digital_result['success'] ), 'The native digital product stock write must succeed.' );
	$digital_product = wc_get_product( $digital_product_id );
	$assert( 'simple' === $digital_product->get_type() && $digital_product->get_virtual() && $digital_product->get_downloadable(), 'Native alias execution must preserve type, virtual, and downloadable traits.' );

	foreach ( [ $native_order, $native_stock, $native_status ] as $native_result ) {
		$encoded = wp_json_encode( $native_result );
		foreach ( [ 'provider_kind', 'selection_reason', 'candidate_tranche', 'ability_id' ] as $private_key ) {
			$assert( false === strpos( $encoded, $private_key ), sprintf( 'Native result must not expose private provider metadata %s.', $private_key ) );
		}
	}

	$stale_params = $product_params;
	$stale_params['expected_version'] = str_repeat( '0', 64 );
	$stale_decision = $provider->assess( 'products.update_stock', $dummy_action, $stale_params );
	$assert( empty( $stale_decision['eligible'] ) && 'request_outside_native_coverage' === ( $stale_decision['reason'] ?? '' ), 'A stale version must reject native before execution.' );
	$redundant_identity = $product_params;
	$redundant_identity['product_sku'] = 'redundant-sku';
	$redundant_decision = $provider->assess( 'products.update_stock', $dummy_action, $redundant_identity );
	$assert( empty( $redundant_decision['eligible'] ), 'An effective redundant product identity must reject native coverage.' );

	$product = wc_get_product( $product_id );
	$raced_params = $product_params;
	$raced_params['stock_quantity'] = 8;
	$raced_params['expected_version'] = YoRo_Commerce_Discovery_Service::product_version( $product );
	$raced_decision = $provider->assess( 'products.update_stock', $dummy_action, $raced_params );
	$assert( ! empty( $raced_decision['eligible'] ), 'A current product version must be eligible before the race fixture changes it.' );
	$product->set_stock_quantity( 6 );
	$product->save();
	$raced_result = $provider->execute( 'products.update_stock', $dummy_action, $raced_params, [], $raced_decision );
	$assert( is_wp_error( $raced_result ) && 'product_version_conflict' === $raced_result->get_error_code(), 'Native execution must revalidate the version immediately before the primitive.' );
	$assert( 6 === (int) wc_get_product( $product_id )->get_stock_quantity(), 'An adjacent version conflict must execute zero native mutation primitives.' );

	$subscriber_id = wp_insert_user(
		[
			'user_login' => 'yr0008-' . wp_generate_password( 10, false ),
			'user_pass'  => wp_generate_password( 24, true ),
			'role'       => 'subscriber',
		]
	);
	$assert( ! is_wp_error( $subscriber_id ), 'A restricted actor fixture is required.' );
	$restricted_user = get_user_by( 'id', absint( $subscriber_id ) );
	$assert( $restricted_user instanceof WP_User, 'The restricted actor must be reloadable.' );
	$restricted_user->add_cap( 'manage_woocommerce' );
	$restricted_user->add_cap( YoRo_Capabilities::EXECUTE_ACTIONS );
	wp_set_current_user( absint( $subscriber_id ) );
	$assert( current_user_can( YoRo_Capabilities::EXECUTE_ACTIONS ), 'The restricted actor must pass the real outer YoRo action permission.' );
	$assert( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'edit_products' ), 'The restricted actor must fail the native WooCommerce order and product permissions.' );

	$order = wc_get_order( $order_id );
	$order->set_status( 'processing' );
	$order->save();
	$order = wc_get_order( $order_id );
	$denied_order_params = $order_params;
	$denied_order_params['expected_versions'][0]['expected_version'] = YoRo_Order_Presenter::order_version( $order );
	$forbidden_order_local = new YoRo_YR0008_Forbidden_Local_Provider();
	$denied_order_route = ( new YoRo_Action_Execution_Router( [ new YoRo_Native_Ability_Execution_Provider(), $forbidden_order_local ] ) )->execute( 'orders.update_status', $dummy_action, $denied_order_params );
	$assert( is_wp_error( $denied_order_route['result'] ?? null ), 'Native order permission denial must remain terminal.' );
	$assert( 0 === $forbidden_order_local->executions, 'Native order permission denial must not retry local.' );
	$assert( 'processing' === wc_get_order( $order_id )->get_status(), 'Native order permission denial must execute zero mutation primitives.' );
	$normalized_order_denial = YoRo_Action_Executor::execute( 'orders.update_status', $denied_order_params, true );
	$assert( is_array( $normalized_order_denial ) && empty( $normalized_order_denial['success'] ) && ! empty( $normalized_order_denial['error']['code'] ), 'The public executor must normalize the terminal native order permission denial.' );
	$assert( 'processing' === wc_get_order( $order_id )->get_status(), 'Normalized native order permission denial must still execute zero mutation primitives.' );

	$product = wc_get_product( $product_id );
	$denied_product_params = $product_params;
	$denied_product_params['stock_quantity'] = 8;
	$denied_product_params['expected_version'] = YoRo_Commerce_Discovery_Service::product_version( $product );
	$before_quantity = (int) $product->get_stock_quantity();
	$forbidden_product_local = new YoRo_YR0008_Forbidden_Local_Provider();
	$denied_product_route = ( new YoRo_Action_Execution_Router( [ new YoRo_Native_Ability_Execution_Provider(), $forbidden_product_local ] ) )->execute( 'products.update_stock', $dummy_action, $denied_product_params );
	$assert( is_wp_error( $denied_product_route['result'] ?? null ), 'Native product permission denial must remain terminal.' );
	$assert( 0 === $forbidden_product_local->executions, 'Native product permission denial must not retry local.' );
	$assert( $before_quantity === (int) wc_get_product( $product_id )->get_stock_quantity(), 'Native product permission denial must execute zero mutation primitives.' );
	$normalized_product_denial = YoRo_Action_Executor::execute( 'products.update_stock', $denied_product_params, true );
	$assert( is_array( $normalized_product_denial ) && empty( $normalized_product_denial['success'] ) && ! empty( $normalized_product_denial['error']['code'] ), 'The public executor must normalize the terminal native product permission denial.' );
	$assert( $before_quantity === (int) wc_get_product( $product_id )->get_stock_quantity(), 'Normalized native product permission denial must still execute zero mutation primitives.' );
} finally {
	wp_set_current_user( $original_user_id );
	$wpdb->query( 'ROLLBACK' );

	if ( '__yoro_missing__' === $original_manage_stock ) {
		delete_option( 'woocommerce_manage_stock' );
	} else {
		update_option( 'woocommerce_manage_stock', $original_manage_stock, false );
	}

	wp_cache_flush();
}

echo "YoRo native ability write parity: PASS\n";
