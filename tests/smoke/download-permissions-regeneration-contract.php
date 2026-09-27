<?php
/** C2 destructive owner-API contract; disposable WooCommerce fixture only. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$failures = [];
$assert = static function ( $ok, $message ) use ( &$failures ) { if ( ! $ok ) { $failures[] = $message; } };
$original_user = get_current_user_id();
$orders = []; $products = []; $users = []; $journal_ops = [];
$action = 'orders.regenerate_download_permissions';
$make_product = static function ( $count = 2, $limit = 4, $expiry = 3 ) use ( &$products ) {
	$product = new WC_Product_Simple(); $product->set_name( 'C2 untrusted secret@example.test' ); $product->set_downloadable( true );
	$product->set_download_limit( $limit ); $product->set_download_expiry( $expiry ); $product->set_regular_price( '10' );
	$files = [];
	for ( $i = 0; $i < $count; $i++ ) { $file = new WC_Product_Download(); $file->set_id( 'file-' . $i ); $file->set_name( 'secret-token' ); $file->set_file( 'https://example.test/private/file-' . $i . '.zip' ); $files[] = $file; }
	$product->set_downloads( $files ); $product->save(); $products[] = $product; return $product;
};
$make_order = static function ( $product, $customer_id = 0 ) use ( &$orders ) {
	$order = wc_create_order( [ 'customer_id' => $customer_id ] ); $orders[] = $order;
	$order->set_billing_email( 'secret@example.test' ); $order->add_product( $product, 2 ); $order->set_status( 'completed' ); $order->save();
	WC_Data_Store::load( 'customer-download' )->delete_by_order_id( $order->get_id() ); return $order;
};
$state = static function ( $order ) { return YoRo_Orders_Read_Service::download_regeneration_state( wc_get_order( $order->get_id() ) ); };
$params_for = static function ( $order ) use ( $state ) { $g = $state( $order )['guard']; return [ 'order_id' => $order->get_id(), 'expected_version' => $g['current_version'], 'expected_download_permissions_state' => $g['state_token'] ]; };
$permit = static function ( $params ) use ( $action ) { $used = false; return static function ( $name, $actual ) use ( $action, $params, &$used ) { if ( $used || $name !== $action || $actual !== $params ) { return false; } $used = true; return true; }; };
$execute = static function ( $params ) use ( $permit, $action ) { return YoRo_Action_Executor::execute( $action, $params, true, false, [], $permit( $params ) ); };
$owner_filter = null;
$original_grant_setting = get_option( 'woocommerce_downloads_grant_access_after_payment', null );
try {
	$user = wp_insert_user( [ 'user_login' => 'yoro-c2-' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] ); $users[] = $user;
	get_user_by( 'id', $user )->add_cap( 'yoro_view_store_data' ); wp_set_current_user( $user );
	$product = $make_product(); $order = $make_order( $product, $user ); $id = $order->get_id();
	$line_items = $order->get_items( 'line_item' ); $line_item = reset( $line_items );
	$assert( in_array( $line_item->get_quantity(), [ 2, 2.0 ], true ), 'Native Woo line-item quantity has the expected integral numeric value.' );
	$state_with_quantity = static function ( $quantity ) use ( $state, $order, $line_item ) {
		$override = static function ( $actual, $item ) use ( $quantity, $line_item ) { return $item->get_id() === $line_item->get_id() ? $quantity : $actual; };
		add_filter( 'woocommerce_order_item_get_quantity', $override, 10, 2 );
		try { return $state( $order ); } finally { remove_filter( 'woocommerce_order_item_get_quantity', $override, 10 ); }
	};
	foreach ( [ 1, 2, 1000000 ] as $valid_quantity ) {
		$integer_state = $state_with_quantity( $valid_quantity );
		$float_state = $state_with_quantity( (float) $valid_quantity );
		$assert( $integer_state['guard']['eligible'] && $float_state['guard']['eligible']
			&& $integer_state['sources'] === $float_state['sources']
			&& $integer_state['guard']['state_token'] === $float_state['guard']['state_token']
			&& is_int( $float_state['sources'][0]['quantity'] ) && $valid_quantity === $float_state['sources'][0]['quantity'],
			'Integral float quantity canonicalizes to the same integer sources and token as native int.' );
	}
	foreach ( [ 0, -1, 1000001, 0.0, -1.0, 1.5, 1000000.5, NAN, INF, -INF, '2', true, false, null, [], new stdClass() ] as $invalid_quantity ) {
		$invalid_state = $state_with_quantity( $invalid_quantity );
		$assert( ! $invalid_state['guard']['eligible'] && null === $invalid_state['guard']['state_token']
			&& 'unsafe_state' === $invalid_state['guard']['blocked_reason'], 'Invalid quantity never mints regeneration authority.' );
	}
	$native_state = $state( $order ); $g = $native_state['guard'];
	$assert( $g['eligible'] && 2 === $native_state['sources'][0]['quantity'] && is_int( $native_state['sources'][0]['quantity'] ), 'Native Woo integral quantity is eligible with an integer source quantity.' );
	$assert( $g['eligible'] && 0 === $g['permission_count'] && 2 === $g['source_file_grant_count'] && 64 === strlen( $g['state_token'] ), 'Zero-row current source state must mint a bounded keyed guard.' );
	$read = YoRo_Orders_Read_Service::get_download_permissions( [ 'order_id' => $id ] );
	$assert( $g === $read['download_permissions']['regeneration_guard'], 'C1 publishes the matching current guard and version.' );
	$params = $params_for( $order );
	$preview = YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $params );
	$assert( ! is_wp_error( $preview ) && false === $preview['will_execute'] && false !== strpos( $preview['summary'], 'historical grants' ), 'Preview states destructive removal/reset consequences without executing.' );
	$assert( is_wp_error( YoRo_Action_Executor::execute( $action, $params, true ) ), 'Bare boolean confirmation cannot authorize C2.' );
	$fake_context = [ 'connector_remote_agent' => true, 'workflow_id' => 'fake', 'operation_id' => 'fake' ];
	$plan = [ 'type' => 'single', 'action' => $action, 'params' => $params, 'context' => $fake_context ];
	$spoof = YoRo_Plan_Executor::execute( $plan, true, false );
	$assert( is_wp_error( $spoof ) || empty( $spoof['success'] ), 'Captured caller context cannot assert remote journal ownership.' );
	$assert( is_wp_error( YoRo_Plan_Executor::execute( [ 'type' => 'sequence', 'steps' => [ $plan ] ], true, false, $permit( $params ) ) ), 'C2 must be a separate confirmation unit.' );
	$mismatch = $params; $mismatch['expected_download_permissions_state'] = str_repeat( 'a', 64 );
	$assert( is_wp_error( YoRo_Action_Executor::execute( $action, $mismatch, true, false, [], $permit( $params ) ) ), 'Mismatched claim cannot authorize any handler.' );
	$claim = $permit( $params );
	$result = YoRo_Action_Executor::execute( $action, $params, true, false, [], $claim );
	$assert( ! is_wp_error( $result ) && ! empty( $result['success'] ), 'A correctly claimed exact current operation must regenerate and verify.' );
	$assert( is_wp_error( YoRo_Action_Executor::execute( $action, $params, true, false, [], $claim ) ), 'One-use claimed closure cannot be replayed.' );
	$after = $state( $order );
	$assert( 2 === count( $after['permissions'] ) && 8 === $after['permissions'][0]['downloads_remaining'] && 0 === $after['permissions'][0]['download_count'], 'Native regeneration resets exact source quotas/counters.' );
	$assert( $g['state_token'] !== $after['guard']['state_token'], 'Changed permission state invalidates the old read.' );
	$assert( is_wp_error( YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $params ) ), 'Stale read cannot be confirmed.' );
	$public = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, $action );
	$assert( true === $public['success'], 'Verified C2 facts survive the closed output guard.' );
	foreach ( [ 'secret@example.test', 'secret-token', 'private/file', '"permission_id"', '"order_key"', '"download_url"', $order->get_order_key() ] as $secret ) { $assert( false === strpos( wp_json_encode( $public ), $secret ), 'No secret, URL, path or row identity enters C2 output.' ); }
	$guest_product = $make_product( 1, -1, -1 ); $guest = $make_order( $guest_product );
	$guest_result = $execute( $params_for( $guest ) );
	$assert( ! is_wp_error( $guest_result ) && ! empty( $guest_result['success'] ), 'Guest order regeneration uses the same exact-order owner boundary.' );
	$guest_state = $state( $guest );
	$assert( true === $guest_state['permissions'][0]['unlimited_downloads'] && null === $guest_state['permissions'][0]['access_expires'], 'Native unlimited/no-expiry grants remain truthful.' );
	// Duplicate product/file line items remain distinct native grants, verified as a multiset.
	$duplicate = $make_order( $guest_product );
	$duplicate->add_product( $guest_product, 1 ); $duplicate->save();
	$dup_result = $execute( $params_for( $duplicate ) );
	$assert( ! is_wp_error( $dup_result ) && ! empty( $dup_result['success'] ) && 2 === $state( $duplicate )['guard']['permission_count'], 'Multiple same-product line items preserve all native source grants.' );
	$cap_product = $make_product( 200, -1, -1 ); $cap_order = $make_order( $cap_product );
	$cap_guard = $state( $cap_order )['guard'];
	$assert( $cap_guard['eligible'] && 200 === $cap_guard['source_file_grant_count'], 'Exactly200 source grants are eligible.' );
	$files = $cap_product->get_downloads(); $extra_file = new WC_Product_Download(); $extra_file->set_id( 'over-cap' ); $extra_file->set_name( 'cap' ); $extra_file->set_file( 'https://example.test/cap.zip' ); $files['over-cap'] = $extra_file;
	$cap_product->set_downloads( $files ); $cap_product->save();
	$cap_guard = $state( $cap_order )['guard'];
	$assert( ! $cap_guard['eligible'] && null === $cap_guard['state_token'] && 'source_cap_exceeded' === $cap_guard['blocked_reason'], 'Source probe201 never mints a token.' );
	$cap_product->set_downloads( array_slice( $files, 0, 1, true ) ); $cap_product->save();
	for ( $i = 0; $i < 501; $i++ ) {
		$permission = new WC_Customer_Download();
		$permission->set_props( [ 'order_id' => $cap_order->get_id(), 'product_id' => $cap_product->get_id(), 'download_id' => 'historical-' . $i, 'downloads_remaining' => '', 'download_count' => 0 ] );
		$permission->save();
		if ( 499 === $i ) { $assert( $state( $cap_order )['guard']['eligible'], 'Exactly500 safe permission rows are eligible.' ); }
	}
	$cap_guard = $state( $cap_order )['guard'];
	$assert( ! $cap_guard['eligible'] && null === $cap_guard['state_token'] && 'permission_cap_exceeded' === $cap_guard['blocked_reason'], 'Permission probe501 never mints an executable token.' );
	$cap_read = YoRo_Orders_Read_Service::get_download_permissions( [ 'order_id' => $cap_order->get_id(), 'limit' => 2 ] );
	$assert( ! is_wp_error( $cap_read ) && 2 === count( $cap_read['download_permissions']['permissions'] ), 'Cap overflow preserves the bounded C1 read.' );
	$stable = $params_for( $order );
	$row = WC_Data_Store::load( 'customer-download' )->get_downloads( [ 'order_id' => $id, 'limit' => 1, 'page' => 1 ] )[0];
	$row->set_download_count( 1 ); $row->save();
	$assert( is_wp_error( YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $stable ) ), 'Download consumption drift invalidates the guard.' );
	$stable = $params_for( $order ); $product->set_download_limit( 9 ); $product->save();
	$assert( is_wp_error( YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $stable ) ), 'Current product quota drift invalidates confirmation.' );
	$cross = $params_for( $order ); $cross['order_id'] = $guest->get_id();
	$assert( is_wp_error( YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $cross ) ), 'Cross-order guards fail closed.' );
	foreach ( [ null, '', str_repeat( 'f', 64 ), str_repeat( 'x', 64 ) ] as $bad ) { $p = $params_for( $order ); $p['expected_download_permissions_state'] = $bad; $assert( is_wp_error( YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $p ) ), 'Fabricated/malformed state is never authority.' ); }
	foreach ( [ 'order_ids', 'user_email', 'customer_id', 'product_id', 'download_id', 'permission_id', 'force', 'quota', 'license_key', 'sql' ] as $key ) { $p = $params_for( $order ); $p[ $key ] = 'forbidden'; $assert( is_wp_error( YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $p ) ), 'Unknown and bypass selectors fail before mutation.' ); }
	$stable = $params_for( $order ); $order->set_status( 'pending' ); $order->save();
	$assert( ! $state( $order )['guard']['eligible'] && is_wp_error( YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $stable ) ), 'Ineligible order status prevents deletion.' );
	$order->set_status( 'completed' ); $order->save();
	foreach ( [ 'pending', 'on-hold', 'failed', 'cancelled', 'refunded' ] as $status ) {
		$order->set_status( $status ); $order->save();
		$assert( ! $state( $order )['guard']['eligible'], 'Non-download-permitted statuses must block before deletion.' );
	}
	$order->set_status( 'processing' ); $order->save();
	update_option( 'woocommerce_downloads_grant_access_after_payment', 'no' );
	$assert( ! $state( $order )['guard']['eligible'], 'Processing with native grant-after-payment=no cannot delete then no-op.' );
	update_option( 'woocommerce_downloads_grant_access_after_payment', 'yes' );
	$assert( $state( $order )['guard']['eligible'], 'Processing with grant-after-payment=yes is the supported native subset.' );
	$order->set_status( 'completed' ); $order->save();
	$expiry_params = $params_for( $order ); $product->set_download_expiry( 8 ); $product->save();
	$assert( is_wp_error( YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $expiry_params ) ), 'Current product expiry drift invalidates the old source guard.' );
	$refund = new WC_Order_Refund(); $refund->set_parent_id( $id ); $refund->save(); $orders[] = $refund;
	$refund_params = $params_for( $order ); $refund_params['order_id'] = $refund->get_id();
	$assert( is_wp_error( YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $refund_params ) ), 'Refund is not a C2 order target.' );
	$product->set_downloads( [] ); $product->save();
	$assert( 'no_source_grants' === $state( $order )['guard']['blocked_reason'], 'Historical-only permissions cannot be destructively removed.' );
	$product->delete( true );
	$assert( ! $state( $order )['guard']['eligible'], 'Deleted current product prevents regeneration.' );
	$variable = new WC_Product_Variable(); $variable->set_name( 'Variable fixture' ); $variable->save(); $products[] = $variable;
	$variation = new WC_Product_Variation(); $variation->set_parent_id( $variable->get_id() ); $variation->set_regular_price( '10' ); $variation->set_downloadable( true );
	$variation->set_downloads( $guest_product->get_downloads() ); $variation->set_download_limit( 2 ); $variation->set_download_expiry( 4 ); $variation->save(); $products[] = $variation;
	$variation_order = $make_order( $variation );
	$variation_result = $execute( $params_for( $variation_order ) );
	$assert( ! is_wp_error( $variation_result ) && ! empty( $variation_result['success'] ), 'Current downloadable variation source is characterized and regenerated.' );
	$mismatch_order = $make_order( $guest_product );
	$alter = static function ( $permission ) { $permission->set_download_count( 9 ); return $permission; };
	add_filter( 'woocommerce_downloadable_file_permission', $alter );
	try { $mismatch_result = $execute( $params_for( $mismatch_order ) ); } finally { remove_filter( 'woocommerce_downloadable_file_permission', $alter ); }
	$assert( ! is_wp_error( $mismatch_result ) && empty( $mismatch_result['success'] ), 'Owner hook changes to recreated consumption facts must fail postcondition verification.' );
	// The native generator is deliberately made to fail after deletion; no rollback or retry claim.
	$partial_params = $params_for( $guest );
	$throw = static function () { throw new RuntimeException( 'secret@example.test private/file' ); };
	add_filter( 'woocommerce_downloadable_file_permission', $throw );
	try { $partial = $execute( $partial_params ); } finally { remove_filter( 'woocommerce_downloadable_file_permission', $throw ); }
	$assert( ! is_wp_error( $partial ) && empty( $partial['success'] ), 'Post-delete owner failure cannot report success.' );
	$partial_public = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $partial, $action );
	$assert( 'reconciliation_required' === ( $partial_public['data']['download_permissions_regeneration']['status'] ?? '' ) && false === strpos( wp_json_encode( $partial_public ), 'secret@example.test' ), 'Partial failure reports only bounded reconciliation instructions.' );
	$assert( 0 === $state( $guest )['guard']['permission_count'], 'Injected partial failure is not secretly rolled back or retried.' );
	// Interleave two real journal acquisitions at the exact prepared INSERT IGNORE query.
	$operation = [ 'envelope_id' => 'c2-' . wp_generate_uuid4(), 'idempotency_key' => 'single-winner' ]; $journal_ops[] = $operation;
	$key = 'yoro_connector_journal_' . substr( hash( 'sha256', $operation['envelope_id'] . '|' . $operation['idempotency_key'] ), 0, 40 );
	$inner = null; $winner = null; $race = null;
	$race = static function ( $sql ) use ( &$race, $key, $operation, &$inner, &$winner ) {
		if ( false !== strpos( $sql, 'INSERT IGNORE' ) && false !== strpos( $sql, $key ) ) {
			remove_filter( 'query', $race );
			$inner = YoRo_Connector_Execution_Journal::begin( $operation );
			$winner = get_option( $key );
		}
		return $sql;
	};
	add_filter( 'query', $race );
	try { $loser = YoRo_Connector_Execution_Journal::begin( $operation ); } finally { remove_filter( 'query', $race ); }
	$assert( 'new' === $inner['state'] && 'executing' === $loser['state'] && $winner === get_option( $key ), 'Actual owner interleaving has exactly one new journal winner and never overwrites it.' );
	$completed = YoRo_Connector_Execution_Journal::complete( $operation, [ 'success' => true ] );
	$assert( $completed === YoRo_Connector_Execution_Journal::begin( $operation ), 'Same-operation completed result is replayed.' );
	foreach ( [ [ 'state' => 'executing', 'expires_at' => time() - 1 ], [ 'state' => 'corrupt', 'expires_at' => time() + 60 ] ] as $invalid ) {
		update_option( $key, $invalid, false );
		$assert( 'executing' === YoRo_Connector_Execution_Journal::begin( $operation )['state'] && $invalid === get_option( $key ), 'Expired/malformed rows fail closed without overwriting or reacquiring.' );
	}
	YoRo_Connector_Execution_Journal::clear( $operation );
	wp_cache_set( 'notoptions', [ $key => true ], 'options' );
	$assert( 'new' === YoRo_Connector_Execution_Journal::begin( $operation )['state'], 'Explicit cleanup permits one new acquisition.' );
	$assert( 'executing' === get_option( $key )['state'], 'Successful insert invalidates negative option cache.' );
	wp_set_current_user( 0 );
	$assert( is_wp_error( YoRo_Orders_Write_Service::preview_regenerate_download_permissions( $partial_params ) ), 'Preview and execution require management permission.' );
} finally {
	wp_set_current_user( $original_user );
	if ( null === $original_grant_setting ) { delete_option( 'woocommerce_downloads_grant_access_after_payment' ); } else { update_option( 'woocommerce_downloads_grant_access_after_payment', $original_grant_setting ); }
	foreach ( $journal_ops as $operation ) { YoRo_Connector_Execution_Journal::clear( $operation ); }
	foreach ( $orders as $order ) { WC_Data_Store::load( 'customer-download' )->delete_by_order_id( $order->get_id() ); $order->delete( true ); }
	foreach ( $products as $product ) { if ( $product->get_id() ) { $product->delete( true ); } }
	require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $user_id ) { wp_delete_user( $user_id ); }
}
echo wp_json_encode( [ 'success' => ! $failures, 'failures' => $failures ], JSON_PRETTY_PRINT ) . PHP_EOL;
if ( $failures ) { exit( 1 ); }
