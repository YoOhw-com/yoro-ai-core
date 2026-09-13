<?php

use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;
use Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentsManager;
use Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

require_once WP_PLUGIN_DIR . '/yoro-ai-core/includes/actions/class-yoro-fulfillment-service.php';

if ( ! class_exists( Fulfillment::class ) || ! method_exists( Fulfillment::class, 'set_tracking_number' ) ) {
	if ( YoRo_Fulfillment_Service::available() ) { throw new RuntimeException( 'Missing native owner must fail closed.' ); }
	echo "PASS: unavailable native fulfillment capability fails closed.\n";
	return;
}

/** A registered extension store exercises the real native owner without changing site schema or settings. */
class YoRo_Tracking_Smoke_Store extends FulfillmentsDataStore {
	public static $records = [];
	public static $updates = 0;
	public static $fault = '';
	public static $omit_after_reads = 0;
	public static $set_reads = 0;
	public function create( &$data ): void { throw new RuntimeException( 'Fixture identity must be seeded explicitly.' ); }
	public function delete_by_entity( string $entity_type, string $entity_id ): int { self::$records = []; return 0; }
	public function read( &$data ): void {
		$record = self::$records[ $data->get_id() ] ?? null;
		if ( ! $record ) { throw new RuntimeException( 'Native fulfillment not found.' ); }
		$data->set_props_from_storage( $record['props'] );
		$data->set_meta_data( $record['meta'] );
		$data->set_object_read( true );
		$data->apply_changes();
	}
	public static function capture( $data ) {
		$raw = array_merge( $data->get_data(), $data->get_changes() );
		$meta = [];
		foreach ( $data->get_meta_data() as $entry ) { $meta[] = [ 'id' => count( $meta ) + 1, 'key' => $entry->key, 'value' => $entry->value ]; }
		unset( $raw['meta_data'] );
		return [ 'props' => $raw, 'meta' => $meta ];
	}
	public function update( &$data ): void {
		++self::$updates;
		if ( 'throw' === self::$fault ) { throw new RuntimeException( 'Owner save fault.' ); }
		$changes = $data->get_changes();
		$previous = self::$records[ $data->get_id() ]['props']['status'];
		$data->set_date_updated( gmdate( 'c' ) );
		self::$records[ $data->get_id() ] = self::capture( $data );
		$data->apply_changes();
		do_action( 'woocommerce_fulfillment_after_update', $data, $changes, $previous );
		if ( 'readback' === self::$fault ) { unset( self::$records[ $data->get_id() ] ); }
	}
	public function read_fulfillments( string $entity_type, string $entity_id, bool $with_deleted = false ): array {
		++self::$set_reads;
		if ( self::$omit_after_reads && self::$set_reads >= self::$omit_after_reads ) { return []; }
		$items = [];
		foreach ( self::$records as $id => $record ) {
			if ( $record['props']['entity_type'] === $entity_type && $record['props']['entity_id'] === $entity_id
				&& ( $with_deleted || ! $record['props']['date_deleted'] ) ) { $items[] = new Fulfillment( $id ); }
		}
		return $items;
	}
}

$failures = [];
$assert = static function ( $ok, $label ) use ( &$failures ) { if ( ! $ok ) { $failures[] = $label; } };
$old_user = get_current_user_id();
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
if ( ! $admins ) { throw new RuntimeException( 'Fixture requires administrator.' ); }
wp_set_current_user( (int) $admins[0] );
$feature = static function () { return 'yes'; };
$store_filter = static function ( $stores ) { $stores['order-fulfillment'] = YoRo_Tracking_Smoke_Store::class; return $stores; };
$notifications = 0;
$notification = static function () use ( &$notifications ) { ++$notifications; };
$order = null;
try {
	$assert( ! YoRo_Fulfillment_Service::available() || 'yes' === get_option( 'woocommerce_feature_fulfillments_enabled' ), 'Capability must respect feature state.' );
	add_filter( 'pre_option_woocommerce_feature_fulfillments_enabled', $feature );
	add_filter( 'woocommerce_data_stores', $store_filter, 100 );
	$manager = wc_get_container()->get( FulfillmentsManager::class );
	$manager->register();
	foreach ( [ 'woocommerce_fulfillment_created_notification', 'woocommerce_fulfillment_updated_notification', 'woocommerce_fulfillment_after_fulfill' ] as $hook ) { add_action( $hook, $notification ); }
	$order = wc_create_order();
	$item = new WC_Order_Item_Product();
	$item->set_name( 'Disposable native tracking fixture' );
	$item->set_quantity( 1 );
	$order->add_item( $item );
	$order->save();
	$target = new Fulfillment();
	$target->set_id( 730001 );
	$target->set_entity_type( WC_Order::class );
	$target->set_entity_id( (string) $order->get_id() );
	$target->set_status( 'unfulfilled' );
	$target->set_items( [ [ 'item_id' => $item->get_id(), 'qty' => 1 ] ] );
	YoRo_Tracking_Smoke_Store::$records[730001] = YoRo_Tracking_Smoke_Store::capture( $target );
	$manager->update_order_fulfillment_status_on_fulfillment_update( new Fulfillment( 730001 ) );
	try { ( new ReflectionMethod( YoRo_Fulfillment_Service::class, 'owner_snapshot' ) )->invoke( null, $order->get_id() ); }
	catch ( Throwable $exception ) { $assert( false, 'Owner snapshot: ' . $exception->getMessage() ); }
	try { ( new ReflectionMethod( YoRo_Fulfillment_Service::class, 'version' ) )->invoke( null, new Fulfillment( 730001 ) ); }
	catch ( Throwable $exception ) { $assert( false, 'Owner version: ' . $exception->getMessage() ); }
	$observations = YoRo_Fulfillment_Service::observations( $order->get_id() );
	$assert( 'available' === $observations['native_status'] && count( $observations['native_fulfillments'] ) === 1, 'Authenticated read projection must contain exact native target.' );
	$version = $observations['native_fulfillments'][0]['current_version'] ?? str_repeat( '0', 64 );
	$params = [ 'order_id' => $order->get_id(), 'fulfillment_id' => 730001, 'tracking_number' => 'YR73TRACK001', 'shipment_provider' => 'dhl', 'expected_version' => $version ];
	YoRo_Orders_Actions::register();
	$bare = YoRo_Action_Executor::execute( 'fulfillment.add_tracking', $params, true );
	$assert( is_wp_error( $bare ) && 'fulfillment_tracking_execution_permit_required' === $bare->get_error_code(), 'Bare confirmation cannot authorize a tracking write.' );
	$spoof = YoRo_Action_Executor::execute( 'fulfillment.add_tracking', $params, true, false, [ 'connector_remote_agent' => true, 'workflow_id' => 'spoof', 'operation_id' => 'spoof' ] );
	$assert( is_wp_error( $spoof ) && 'fulfillment_tracking_execution_permit_required' === $spoof->get_error_code(), 'Caller-shaped remote context cannot authorize tracking.' );
	$wrong_claim = static function () { return false; };
	$denied = YoRo_Action_Executor::execute( 'fulfillment.add_tracking', $params, true, false, [], $wrong_claim );
	$assert( is_wp_error( $denied ) && 'fulfillment_tracking_execution_permit_required' === $denied->get_error_code(), 'Rejected exact claim cannot authorize tracking.' );
	$prepared = YoRo_Fulfillment_Service::prepare( 'fulfillment.add_tracking', $params );
	$assert( ! is_wp_error( $prepared ), 'Valid native add must prepare: ' . ( is_wp_error( $prepared ) ? $prepared->get_error_message() : '' ) );
	$preview = YoRo_Fulfillment_Service::preview_add_tracking( $params );
	$assert( empty( $preview['blocked'] ) && 0 === YoRo_Tracking_Smoke_Store::$updates, 'Preview performs zero owner saves.' );
	foreach ( [ [ 'expected_version' => str_repeat( 'f', 64 ) ], [ 'tracking_url' => 'https://caller.invalid' ], [ 'tracking_number' => "bad\nvalue" ], [ 'tracking_number' => str_repeat( 'a', 201 ) ], [ 'shipment_provider' => 'not-a-provider' ], [ 'order_id' => $order->get_id() + 100000 ], [ 'fulfillment_id' => 730002 ] ] as $bad ) {
		$assert( is_wp_error( YoRo_Fulfillment_Service::prepare( 'fulfillment.add_tracking', array_replace( $params, $bad ) ) ), 'Malformed/stale/unowned/provider request fails closed.' );
	}
	$assert( is_wp_error( YoRo_Fulfillment_Service::prepare( 'fulfillment.update_tracking', $params ) ), 'Update requires prior tracking.' );
	$notes_before = count( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
	$result = YoRo_Fulfillment_Service::add_tracking( $params );
	if ( is_wp_error( $result ) ) { $assert( false, 'Add diagnostic: ' . $result->get_error_code() ); }
	$assert( is_array( $result ) && true === $result['success'] && 1 === YoRo_Tracking_Smoke_Store::$updates, 'Add uses exactly one native owner save and readback.' );
	$assert( count( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) ) > $notes_before, 'Native internal audit note must run.' );
	foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) as $note ) { $assert( ! $note->customer_note, 'Native update note is not a customer note.' ); }
	$assert( 0 === $notifications, 'Tracking does not invoke customer notifications or implicit fulfill.' );
	$native_read = YoRo_Commerce_Discovery_Service::get_fulfillment_tracking( [ 'order_id' => $order->get_id() ] );
	$assert( is_array( $native_read ) && ! str_contains( $native_read['summary'], 'No tracking number' ) && 'available' === $native_read['status']
		&& [] === $native_read['shipments'] && 'YR73TRACK001' === $native_read['native_fulfillments'][0]['tracking_number'], 'Native-only tracking read preserves legacy shape without a contradictory no-tracking summary.' );
	$assert( is_wp_error( YoRo_Fulfillment_Service::add_tracking( $params ) ) && 1 === YoRo_Tracking_Smoke_Store::$updates, 'Old version replay does not save again.' );
	$params['expected_version'] = is_array( $result ) ? $result['current_version'] : $version;
	$params['tracking_number'] = 'YR73TRACK002';
	$assert( is_wp_error( YoRo_Fulfillment_Service::prepare( 'fulfillment.add_tracking', $params ) ), 'Add rejects existing tracking.' );
	$updated = YoRo_Fulfillment_Service::update_tracking( $params );
	if ( is_wp_error( $updated ) ) { $assert( false, 'Update diagnostic: ' . $updated->get_error_code() ); }
	$assert( is_array( $updated ) && 'YR73TRACK001' === $updated['previous_tracking_number'] && 2 === YoRo_Tracking_Smoke_Store::$updates, 'Update preserves previous tuple and saves once.' );
	$params['expected_version'] = is_array( $updated ) ? $updated['current_version'] : $version;
	$rest_plan = [ 'type' => 'single', 'action' => 'fulfillment.update_tracking', 'params' => $params ];
	$binding = [ 'token' => str_repeat( 't', 64 ), 'plan_hash' => hash( 'sha256', wp_json_encode( $rest_plan ) ), 'preview_hash' => hash( 'sha256', 'tracking fixture preview' ), 'policy_version' => 4 ];
	$claimed = false;
	$mode = 'mismatch';
	$status = [ 'account_connection' => [ 'connected' => true, 'status' => 'active' ], 'organization' => [ 'id' => 1, 'name' => 'Fixture' ],
		'site' => [ 'id' => 1, 'name' => 'Fixture', 'url' => home_url(), 'status' => 'active', 'plan' => 'test' ],
		'connection' => [ 'id' => 1, 'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(), 'status' => 'active', 'plugin_version' => YORO_AI_CORE_VERSION ],
		'credential' => [ 'id' => 1, 'type' => 'wordpress_connection', 'site_connection_id' => 1, 'expires_at' => gmdate( 'c', time() + HOUR_IN_SECONDS ) ] ];
	$status_filter = static function () use ( $status ) { return $status; };
	$url_filter = static function () { return 'https://tracking-smoke.example.invalid/api/v1'; };
	$http = static function ( $pre, $args, $url ) use ( $status, $rest_plan, $binding, &$claimed, &$mode ) {
		$response = $status + [ 'success' => true ];
		if ( str_contains( $url, '/actions/confirm' ) ) { $response['execution_permit'] = $binding; }
		elseif ( str_contains( $url, '/actions/claim' ) ) {
			$response += [ 'stage' => 'claimed', 'pending_operation_status' => 'executing', 'conversation_id' => 'tracking-fixture', 'pending_operation_token' => 'tracking-pending', 'plan_hash' => $binding['plan_hash'], 'preview_hash' => $binding['preview_hash'], 'policy_version' => 4 ];
			if ( $claimed ) { $response = [ 'success' => false ]; }
			elseif ( 'mismatch' === $mode ) { $response['plan_hash'] = str_repeat( '0', 64 ); }
			else { $claimed = true; }
		} elseif ( 'GET' === ( $args['method'] ?? 'GET' ) ) {
			$response['conversation'] = [ 'pending_operation_graphs' => [ [ 'token' => 'tracking-pending', 'status' => 'awaiting_confirmation', 'action' => 'fulfillment.update_tracking', 'action_plan' => $rest_plan ] ] ];
		}
		return [ 'headers' => [], 'body' => wp_json_encode( $response ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ];
	};
	$invoke = static function () use ( $rest_plan ) {
		$request = new WP_REST_Request( 'POST', '/yoro-ai-core/v1/execute' );
		foreach ( [ 'action' => $rest_plan['action'], 'params' => $rest_plan['params'], 'confirm' => true, 'conversation_id' => 'tracking-fixture', 'pending_operation_token' => 'tracking-pending' ] as $key => $value ) { $request->set_param( $key, $value ); }
		$response = YoRo_REST_Execute_Handler::handle( $request );
		return $response instanceof WP_REST_Response ? $response->get_data() : $response;
	};
	if ( ! defined( 'YORO_AI_API_SITE_KEY' ) ) { define( 'YORO_AI_API_SITE_KEY', 'tracking-fixture-key' ); }
	add_filter( 'pre_option_' . YoRo_AI_Settings::OPTION_AI_API_STATUS, $status_filter );
	add_filter( 'pre_option_' . YoRo_AI_Settings::OPTION_AI_API_URL, $url_filter );
	add_filter( 'pre_http_request', $http, 10, 3 );
	try {
		$before_claim = YoRo_Tracking_Smoke_Store::$updates;
		$mismatch = $invoke();
		$assert( ( is_wp_error( $mismatch ) || empty( $mismatch['success'] ) ) && $before_claim === YoRo_Tracking_Smoke_Store::$updates, 'REST mismatched claim rejects before native save.' );
		$mode = 'success';
		$success = $invoke();
		$assert( is_array( $success ) && ! empty( $success['success'] ) && $claimed && $before_claim + 1 === YoRo_Tracking_Smoke_Store::$updates, 'REST exact confirmed one-use claim reaches native executor.' );
		$replayed = $invoke();
		$assert( ( is_wp_error( $replayed ) || empty( $replayed['success'] ) ) && $before_claim + 1 === YoRo_Tracking_Smoke_Store::$updates, 'REST consumed claim cannot replay native save.' );
	} finally {
		remove_filter( 'pre_http_request', $http, 10 );
		remove_filter( 'pre_option_' . YoRo_AI_Settings::OPTION_AI_API_STATUS, $status_filter );
		remove_filter( 'pre_option_' . YoRo_AI_Settings::OPTION_AI_API_URL, $url_filter );
	}
	$good = YoRo_Tracking_Smoke_Store::$records;
	// Keep the owner aggregate coherent while the extension store omits the target.
	// The exact-ID read still succeeds: membership must be checked independently.
	$aggregate_filter = static function () { return 'unfulfilled'; };
	add_filter( 'woocommerce_fulfillment_calculate_order_fulfillment_status', $aggregate_filter );
	try {
	foreach ( [ 2, 3 ] as $omit_at ) {
		YoRo_Tracking_Smoke_Store::$set_reads = 0;
		YoRo_Tracking_Smoke_Store::$omit_after_reads = $omit_at;
		$before_omission = YoRo_Tracking_Smoke_Store::$updates;
		$omitted = YoRo_Fulfillment_Service::update_tracking( $params );
		$assert( is_wp_error( $omitted ), 'Missing target membership cannot return success.' );
		if ( 2 === $omit_at ) { $assert( $before_omission === YoRo_Tracking_Smoke_Store::$updates, 'Pre-save missing member performs no save.' ); }
		else { $assert( $before_omission + 1 === YoRo_Tracking_Smoke_Store::$updates && 'fulfillment_tracking_reconciliation_required' === $omitted->get_error_code(), 'Post-save missing member requires reconciliation after exactly one save.' ); }
		YoRo_Tracking_Smoke_Store::$omit_after_reads = 0;
		YoRo_Tracking_Smoke_Store::$records = $good;
	}
	} finally {
		remove_filter( 'woocommerce_fulfillment_calculate_order_fulfillment_status', $aggregate_filter );
		YoRo_Tracking_Smoke_Store::$omit_after_reads = 0;
	}
	$changed = new Fulfillment( 730001 );
	$changed->set_locked( true );
	YoRo_Tracking_Smoke_Store::$records[730001] = YoRo_Tracking_Smoke_Store::capture( $changed );
	$assert( is_wp_error( YoRo_Fulfillment_Service::prepare( 'fulfillment.update_tracking', $params ) ), 'Locked target fails closed.' );
	YoRo_Tracking_Smoke_Store::$records = $good;
	YoRo_Tracking_Smoke_Store::$records[730001]['props']['date_deleted'] = '2026-01-01 00:00:00';
	$assert( is_wp_error( YoRo_Fulfillment_Service::prepare( 'fulfillment.update_tracking', $params ) ), 'Deleted target fails closed.' );
	YoRo_Tracking_Smoke_Store::$records = $good;
	YoRo_Tracking_Smoke_Store::$records[730001]['props']['is_fulfilled'] = true;
	$assert( is_wp_error( YoRo_Fulfillment_Service::prepare( 'fulfillment.update_tracking', $params ) ), 'Fulfilled without date cannot become implicit fulfill.' );
	YoRo_Tracking_Smoke_Store::$records = $good;
	$order->update_meta_data( '_fulfillment_status', 'incoherent' );
	$order->save();
	$assert( is_wp_error( YoRo_Fulfillment_Service::prepare( 'fulfillment.update_tracking', $params ) ), 'Incoherent aggregate is rejected before save.' );
	$manager->update_order_fulfillment_status_on_fulfillment_update( new Fulfillment( 730001 ) );
	$callback = static function ( $fulfillment ) {
		$changed = new Fulfillment( $fulfillment->get_id() );
		$changed->set_locked( true );
		YoRo_Tracking_Smoke_Store::$records[ $changed->get_id() ] = YoRo_Tracking_Smoke_Store::capture( $changed );
	};
	add_action( 'woocommerce_fulfillment_after_update', $callback, 99 );
	try {
		$ambiguous = YoRo_Fulfillment_Service::update_tracking( $params );
		$assert( is_wp_error( $ambiguous ) && 'fulfillment_tracking_reconciliation_required' === $ambiguous->get_error_code(), 'Owner callback drift is detected without hook suppression or rollback.' );
	} finally { remove_action( 'woocommerce_fulfillment_after_update', $callback, 99 ); }
	YoRo_Tracking_Smoke_Store::$records = $good;
	YoRo_Tracking_Smoke_Store::$fault = 'throw';
	$ambiguous = YoRo_Fulfillment_Service::update_tracking( $params );
	$assert( is_wp_error( $ambiguous ) && 'fulfillment_tracking_reconciliation_required' === $ambiguous->get_error_code(), 'Owner exception is reconciliation-required.' );
	YoRo_Tracking_Smoke_Store::$fault = 'readback';
	$ambiguous = YoRo_Fulfillment_Service::update_tracking( $params );
	$assert( is_wp_error( $ambiguous ) && 'fulfillment_tracking_reconciliation_required' === $ambiguous->get_error_code(), 'Missing readback is reconciliation-required.' );
	remove_filter( 'pre_option_woocommerce_feature_fulfillments_enabled', $feature );
	$disabled = static function () { return 'no'; };
	add_filter( 'pre_option_woocommerce_feature_fulfillments_enabled', $disabled );
	try { $assert( ! YoRo_Fulfillment_Service::available() && 'unavailable' === YoRo_Fulfillment_Service::observations( $order->get_id() )['native_status'], 'Disabled native feature grants no read/write authority.' ); }
	finally { remove_filter( 'pre_option_woocommerce_feature_fulfillments_enabled', $disabled ); }
} finally {
	YoRo_Tracking_Smoke_Store::$records = [];
	if ( $order instanceof WC_Order ) { $order->delete( true ); }
	remove_filter( 'pre_option_woocommerce_feature_fulfillments_enabled', $feature );
	remove_filter( 'woocommerce_data_stores', $store_filter, 100 );
	foreach ( [ 'woocommerce_fulfillment_created_notification', 'woocommerce_fulfillment_updated_notification', 'woocommerce_fulfillment_after_fulfill' ] as $hook ) { remove_action( $hook, $notification ); }
	wp_set_current_user( $old_user );
}

if ( $failures ) { throw new RuntimeException( implode( "\n", $failures ) ); }
echo "PASS: native fulfillment tracking owner, coherence, audit, validation and readback contract.\n";
