<?php
/** Disposable owner-contract fixtures; no live plugin installation or owner mutation. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Order' ) ) { throw new RuntimeException( 'WooCommerce fixture required.' ); }
if ( class_exists( 'WC_Blacklist_Manager', false ) ) { throw new RuntimeException( 'Use an isolated fixture without an active Blacklist Manager plugin.' ); }
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admins ), 'Fixture admin required.' );
$previous_user = get_current_user_id();
wp_set_current_user( (int) $admins[0] );
$guarded = false; $writes = 0; $http = 0; $business_queries = 0; $orders = []; $tables = []; $saved_options = [];
$guard = static function ( $sql ) use ( &$guarded, &$writes, &$business_queries ) {
	if ( $guarded && ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql ) ) { ++$writes; throw new RuntimeException( 'Adapter write forbidden.' ); }
	if ( $guarded && preg_match( '/^\s*SELECT\b/i', $sql ) && preg_match( '/\bFROM\s+`?\w*wc_(?:blacklist|whitelist)/i', $sql ) ) { ++$business_queries; throw new RuntimeException( 'Raw owner business query forbidden.' ); }
	return $sql;
};
$no_http = static function () use ( &$http ) { ++$http; return new WP_Error( 'unexpected_http', 'Adapter must not call remote services.' ); };
$owner_source = getenv( 'YORO_BLACKLIST_OWNER_SOURCE' );
try {
	$order = wc_create_order(); $orders[] = $order;
	$order->set_billing_email( 'private-contact@example.test' );
	$order->update_meta_data( '_yogb_gbl_decision', 'challenge' );
	$order->update_meta_data( '_yogb_gbl_tier', 'pro' );
	$order->update_meta_data( '_yogb_gbl_check_status', 'success' );
	$order->update_meta_data( '_yogb_gbl_decision_at', 1700000000 );
	$order->update_meta_data( '_yogb_gbl_decision_ref', 'gbl_dec_' . str_repeat( 'a', 32 ) );
	$order->update_meta_data( '_yogb_gbl_decision_summary', 'SECRET private-contact@example.test 192.0.2.22' );
	$order->update_meta_data( '_yogb_gbl_raw', [ 'secret' => 'DO-NOT-EXPOSE' ] );
	$order->update_meta_data( '_yogb_gbl_effective_score', 999999 );
	$match = [ 'type' => 'email', 'match_mode' => 'probable', 'requires_corroboration' => true, 'direct' => false, 'confidence' => 0.5, 'value' => 'private-contact@example.test' ];
	$order->update_meta_data( '_yogb_gbl_match_summary_v2', wp_json_encode( [ $match ] ) ); $order->save();
	$empty = wc_create_order(); $orders[] = $empty;
	$refund = new WC_Order_Refund(); $refund->set_parent_id( $order->get_id() ); $refund->save(); $orders[] = $refund;
	add_filter( 'query', $guard ); add_filter( 'pre_http_request', $no_http ); $guarded = true;
	$absent = YoRo_Blacklist_Manager_Service::get_status( [] );
	$assert( 'absent' === $absent['status'] && 'not_observed' === $absent['premium_state'], 'Absent owner is explicit.' );
	$assert( 'unavailable' === YoRo_Blacklist_Manager_Service::get_order_assessment( [ 'order_id' => $order->get_id() ] )['status'], 'Retained metadata alone cannot impersonate an available owner.' );
	if ( ! class_exists( 'WC_Blacklist_Manager', false ) ) { class WC_Blacklist_Manager {} }
	$assert( 'incompatible' === YoRo_Blacklist_Manager_Service::get_status( [] )['status'], 'Class without version/readiness contract is incompatible.' );
	define( 'WC_BLACKLIST_MANAGER_VERSION', '2.3.1.1' ); define( 'WC_BLACKLIST_MANAGER_SCHEMA_CONTRACT_VERSION', 1 );
	$guarded = false;
	if ( $owner_source ) {
		$assert( is_file( $owner_source . '/inc/cores/schema-readiness.php' ), 'Exact owner source required.' );
		require_once $owner_source . '/inc/cores/schema-readiness.php';
		$prefix = 'yr76_' . strtolower( wp_generate_password( 8, false ) ) . '_';
		WC_Blacklist_Manager_Schema_Readiness::set_test_prefix( $prefix );
		global $wpdb;
		foreach ( WC_Blacklist_Manager_Schema_Readiness::contracts() as $contract ) {
			$table = $prefix . $contract['table_suffix']; $tables[] = $table;
			$columns = implode( ', ', array_map( static fn( $column ) => '`' . $column . '` bigint NOT NULL', $contract['columns'] ) );
			$index_columns = implode( ', ', array_map( static fn( $column ) => '`' . $column . '`', $contract['columns'] ) );
			$assert( false !== $wpdb->query( "CREATE TABLE `$table` (id bigint PRIMARY KEY, $columns, KEY `{$contract['index']}` ($index_columns))" ), 'Isolated readiness table creation.' );
		}
		foreach ( [ WC_Blacklist_Manager_Schema_Readiness::VERSION_OPTION, WC_Blacklist_Manager_Schema_Readiness::STATE_OPTION ] as $option ) { $saved_options[ $option ] = get_option( $option, null ); }
		update_option( WC_Blacklist_Manager_Schema_Readiness::VERSION_OPTION, 1, false );
		update_option( WC_Blacklist_Manager_Schema_Readiness::STATE_OPTION, [ 'status' => 'ready' ], false );
	} else {
		if ( ! class_exists( 'WC_Blacklist_Manager_Schema_Readiness', false ) ) {
			class WC_Blacklist_Manager_Schema_Readiness {
				const CONTRACT_VERSION = 1;
				public static $fixture = [ 'ready' => true, 'status' => 'ready', 'message' => 'SECRET OWNER MESSAGE' ];
				public static function get_status() { return self::$fixture; }
			}
		}
	}
	$guarded = true;
	$status = YoRo_Action_Executor::execute( 'blacklist.get_status', [] );
	$assert( $status['success'] && $status['read_only'] && 'available' === $status['status'] && 'not_observed' === $status['premium_state'], 'Free owner safe reads work without Premium.' );
	$assert( [ 'blacklist.get_status', 'blacklist.get_order_assessment' ] === $status['available_reads'], 'Only two declared read capabilities.' );
	$r = YoRo_Action_Executor::execute( 'blacklist.get_order_assessment', [ 'order_id' => $order->get_id() ] );
	$assert( $r['success'] && $r['read_only'] && empty( $r['requires_confirmation'] ) && 'observed' === $r['status'], 'Assessment executor is read-only and needs no confirmation.' );
	$assert( $r['facts']['decision'] === 'challenge' && $r['facts']['tier'] === 'pro' && $r['facts']['reference_present'] && $r['facts']['matches'][0]['requires_corroboration'], 'Owner decision and corroboration retained without reinterpretation.' );
	$wire = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $r, 'blacklist.get_order_assessment' );
	$assert( $wire['data']['facts'] === $r['facts'] && [ 'order_id', 'status', 'facts', 'coverage' ] === array_keys( $wire['data'] ), 'Canonical payload retains exact closed data.' );
	$encoded = wp_json_encode( [ $r, $status, $wire ] );
	foreach ( [ 'private-contact', '192.0.2.22', 'SECRET', 'DO-NOT-EXPOSE', '999999', 'gbl_dec_' ] as $secret ) { $assert( false === strpos( $encoded, $secret ), 'No PII, raw reference, score or secret projection.' ); }
	$assert( 'not_observed' === YoRo_Blacklist_Manager_Service::get_order_assessment( [ 'order_id' => $empty->get_id() ] )['status'], 'Missing decision is not an allow decision.' );
	foreach ( [ [], [ 'order_id' => (string) $order->get_id() ], [ 'order_id' => 0 ], [ 'order_id' => -1 ], [ 'order_id' => $order->get_id(), 'site_id' => 2 ], [ 'order_id' => $order->get_id(), 'recheck' => true ], [ 'email' => 'private-contact@example.test' ] ] as $invalid ) { $assert( is_wp_error( YoRo_Blacklist_Manager_Service::get_order_assessment( $invalid ) ), 'Malformed and widened input fails closed.' ); }
	$assert( is_wp_error( YoRo_Blacklist_Manager_Service::get_status( [ 'repair' => true ] ) ), 'Status cannot repair owner.' );
	$assert( is_wp_error( YoRo_Blacklist_Manager_Service::get_order_assessment( [ 'order_id' => $refund->get_id() ] ) ), 'Refund cannot impersonate exact order.' );
	if ( ! $owner_source ) {
		foreach ( [ 'pending', 'running', 'failed', 'manual_required', 'newer_than_code' ] as $state ) {
			WC_Blacklist_Manager_Schema_Readiness::$fixture = [ 'ready' => false, 'status' => $state ];
			$assert( 'schema_not_ready' === YoRo_Blacklist_Manager_Service::get_status( [] )['status'], 'Unready schema is explicit.' );
			$assert( 'schema_not_ready' === YoRo_Blacklist_Manager_Service::get_order_assessment( [ 'order_id' => $order->get_id() ] )['status'], 'Unready owner cannot expose retained assessment.' );
		}
		WC_Blacklist_Manager_Schema_Readiness::$fixture = [ 'ready' => true, 'status' => 'failed' ];
		$assert( 'incompatible' === YoRo_Blacklist_Manager_Service::get_status( [] )['status'], 'Inconsistent owner readiness fails closed.' );
		WC_Blacklist_Manager_Schema_Readiness::$fixture = [ 'ready' => true, 'status' => 'ready' ];
	}
	if ( ! class_exists( 'WC_Blacklist_Manager_Premium', false ) ) { class WC_Blacklist_Manager_Premium {} }
	$assert( 'incompatible' === YoRo_Blacklist_Manager_Service::get_status( [] )['premium_state'], 'Malformed Premium presence does not block free reads.' );
	define( 'WC_BLACKLIST_MANAGER_PREMIUM_VERSION', '2.7.1' );
	$present = YoRo_Blacklist_Manager_Service::get_status( [] );
	$assert( 'present' === $present['premium_state'] && 'not_observed' === $present['coverage']['premium_facts'] && 'available' === $present['status'], 'Premium presence is not entitlement or evidence.' );
	foreach ( [ '_yogb_gbl_decision' => '<script>block</script>', '_yogb_gbl_tier' => [ 'pro' ], '_yogb_gbl_decision_at' => 'tomorrow', '_yogb_gbl_match_summary_v2' => wp_json_encode( array_fill( 0, 9, $match ) ) ] as $key => $bad ) {
		$guarded = false; $before = $order->get_meta( $key, true, 'edit' ); $order->update_meta_data( $key, $bad ); $order->save(); $guarded = true;
		$bad_result = YoRo_Blacklist_Manager_Service::get_order_assessment( [ 'order_id' => $order->get_id() ] );
		$assert( 'unavailable' === $bad_result['status'] && 'not_observed' === $bad_result['facts']['decision'], 'Unsafe owner state fails closed without partially valid facts.' );
		$guarded = false; $order->update_meta_data( $key, $before ); $order->save(); $guarded = true;
	}
	wp_set_current_user( 0 );
	$assert( is_wp_error( YoRo_Blacklist_Manager_Service::get_status( [] ) ) && is_wp_error( YoRo_Blacklist_Manager_Service::get_order_assessment( [ 'order_id' => $order->get_id() ] ) ), 'Unprivileged reads denied.' );
	$assert( 0 === $writes && 0 === $http && 0 === $business_queries, 'Zero adapter writes, HTTP, or raw owner business queries.' );
} finally {
	$guarded = false; remove_filter( 'query', $guard ); remove_filter( 'pre_http_request', $no_http );
	foreach ( array_reverse( $orders ) as $fixture ) { $fixture->delete( true ); }
	if ( $owner_source ) {
		foreach ( $tables as $table ) { $wpdb->query( "DROP TABLE `$table`" ); }
		foreach ( $saved_options as $key => $value ) { null === $value ? delete_option( $key ) : update_option( $key, $value, false ); }
		WC_Blacklist_Manager_Schema_Readiness::set_test_prefix( '' );
	}
	wp_set_current_user( $previous_user );
}
echo "PASS Blacklist Manager adapter: absent/free/Premium gates, exact order, retained privacy, schema readiness, zero writes/HTTP/business queries, cleanup.\n";
