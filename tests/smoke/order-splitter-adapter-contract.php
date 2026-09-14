<?php
/** Run in an isolated WooCommerce fixture; owner entrypoints are never loaded. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Order' ) || class_exists( 'WooCommerce_Order_Splitter', false ) ) { throw new RuntimeException( 'Isolated WooCommerce fixture without active owner is required.' ); }
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$mode = $args[0] ?? 'supported';
$source = getenv( 'YORO_ORDER_SPLITTER_OWNER_SOURCE' );
$user = get_current_user_id(); $admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admin ), 'Admin fixture required.' ); wp_set_current_user( (int) $admin[0] );
$orders = []; $guarded = false; $writes = 0; $http = 0; $mail = 0; $journal_reads = 0;
$query = static function ( $sql ) use ( &$guarded, &$writes, &$journal_reads ) {
	if ( $guarded && false !== strpos( $sql, 'wcos_mutation_op_' ) ) { ++$journal_reads; throw new RuntimeException( 'Authoritative journal read.' ); }
	if ( $guarded && ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql ) ) { ++$writes; throw new RuntimeException( 'Adapter SQL write.' ); }
	return $sql;
};
$option = static function ( $pre, $name ) use ( &$journal_reads, &$guarded ) { if ( $guarded && str_starts_with( $name, 'wcos_mutation_op_' ) ) { ++$journal_reads; throw new RuntimeException( 'Journal option read.' ); } return $pre; };
$http_guard = static function () use ( &$http ) { ++$http; return new WP_Error( 'no_http', 'Unexpected HTTP.' ); };
$mail_guard = static function () use ( &$mail, &$guarded ) { if ( $guarded ) { ++$mail; } return true; };
add_filter( 'pre_wp_mail', $mail_guard );
try {
	$order = wc_create_order(); $orders[] = $order; $order->set_status( 'processing' ); $order->set_billing_email( 'SECRET@example.test' ); $order->save();
	$refund = new WC_Order_Refund(); $refund->set_parent_id( $order->get_id() ); $refund->save(); $orders[] = $refund;
	$put = static function ( WC_Order $target, $key, $value ) use ( &$guarded ) { $guarded = false; null === $value ? $target->delete_meta_data( $key ) : $target->update_meta_data( $key, $value ); $target->save(); $guarded = true; };
	$read = static fn() => YoRo_Order_Splitter_Service::get_order_activity( [ 'order_id' => $order->get_id() ] );
	add_filter( 'query', $query ); add_filter( 'pre_option', $option, 10, 2 ); add_filter( 'pre_http_request', $http_guard ); $guarded = true;
	$assert( 'absent' === YoRo_Order_Splitter_Service::get_status( [] )['status'] && 'unavailable' === $read()['status'], 'Absent owner must not be inferred from data.' );
	if ( ! class_exists( 'WooCommerce_Order_Splitter', false ) ) { class WooCommerce_Order_Splitter { public function __construct() { throw new RuntimeException( 'No plugin construction.' ); } } }
	$assert( 'incompatible' === YoRo_Order_Splitter_Service::get_status( [] )['status'], 'Missing version fails closed.' );
	define( 'WC_ORDER_SPLITTER_VERSION', 'unsupported' === $mode ? '1.5.1' : '1.5.0' );
	if ( $source ) {
		require_once $source . '/inc/domain/class-wcos-order-relation-repository.php';
		require_once $source . '/inc/domain/class-wcos-operation-journal.php';
		require_once $source . '/inc/domain/class-wcos-split-order-service.php';
		WCOS_Order_Relation_Repository::bootstrap();
	} else {
		if ( ! class_exists( 'WCOS_Order_Relation_Repository', false ) ) {
			class WCOS_Order_Relation_Repository {
				public static $children = []; public static $calls = []; public static $on_read = null;
				public static function find( array $conditions, $limit = -1 ) { self::$calls[] = [ $conditions, $limit ]; if ( self::$on_read ) { call_user_func( self::$on_read ); } return self::$children; }
				public static function tokenize_legacy_query( $args ) { return $args; }
				public static function inject_legacy_query( $args ) { return $args; }
			}
		}
		if ( ! class_exists( 'WCOS_Operation_Journal', false ) ) { class WCOS_Operation_Journal { const SUMMARY_META_KEY = '_wcos_operation_journal'; const MAX_SUMMARY_ENTRIES = 20; public static function get() { throw new RuntimeException( 'Journal forbidden.' ); } } }
		if ( ! class_exists( 'WCOS_Split_Order_Service', false ) ) { class WCOS_Split_Order_Service { const RELATION_PARENT_META = '_wcos_parent_order_id'; const RELATION_CHILDREN_META = '_wcos_child_order_ids'; public function __construct() { throw new RuntimeException( 'Mutation service forbidden.' ); } } }
		add_filter( 'woocommerce_order_query_args', [ 'WCOS_Order_Relation_Repository', 'tokenize_legacy_query' ], 9 );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ 'WCOS_Order_Relation_Repository', 'inject_legacy_query' ], 10, 2 );
	}
	if ( 'unsupported' === $mode ) {
		$assert( 'incompatible' === YoRo_Order_Splitter_Service::get_status( [] )['status'] && 'unavailable' === $read()['status'], 'Unsupported version fails closed.' );
	} else {
		$assert( 'available' === YoRo_Action_Executor::execute( 'order_splitter.get_status', [] )['status'], 'Registered status action is available.' );
		$r = $read(); $assert( 'observed' === $r['status'] && 'none' === $r['lineage']['role'], 'Empty exact-order observation.' );
		$base = [ 'operation_id' => 'SECREToperation', 'fingerprint' => 'SECRETfingerprint', 'type' => 'split', 'status' => 'completed', 'stage' => 'completed', 'revision' => 3,
			'started_at' => '2026-09-14T01:00:00+00:00', 'updated_at' => '2026-09-14T01:01:00+00:00', 'completed_at' => '2026-09-14T01:01:00+00:00' ];
		$put( $order, '_wcos_operation_journal', [ $base ] );
		$r = YoRo_Action_Executor::execute( 'order_splitter.get_order_activity', [ 'order_id' => $order->get_id() ] );
		$wire = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $r, 'order_splitter.get_order_activity' );
		$assert( $r['success'] && $r['read_only'] && empty( $r['requires_confirmation'] ) && 'completed' === $r['activity'][0]['status'], 'Registered read executes without confirmation.' );
		$assert( false === strpos( wp_json_encode( [ $r, $wire ] ), 'SECRET' ) && count( $wire['data']['activity'][0] ) === 7, 'Projection omits operation, fingerprint and customer data.' );
		foreach ( [ 'split', 'duplicate', 'merge', 'return' ] as $type ) { $put( $order, '_wcos_operation_journal', [ array_replace( $base, [ 'type' => $type ] ) ] ); $assert( $type === $read()['activity'][0]['type'], 'Known mutation type is a fact only.' ); }
		$put( $order, '_wcos_operation_journal', [ array_replace( $base, [ 'status' => 'manual_reconciliation', 'stage' => 'manual_reconciliation', 'completed_at' => null ] ) ] );
		$assert( 'present' === $read()['manual_reconciliation'], 'Only retained reconciliation observation.' );
		foreach ( [ [ 'type' => 'refund' ], [ 'stage' => 'unknown' ], [ 'status' => 'unknown' ], [ 'revision' => '3' ], [ 'revision' => 0 ], [ 'operation_id' => [] ], [ 'fingerprint' => false ], [ 'context' => 'SECRET' ], [ 'started_at' => '2026-02-31T00:00:00+00:00' ], [ 'updated_at' => '2020-01-01T00:00:00+00:00' ], [ 'completed_at' => false ] ] as $delta ) { $put( $order, '_wcos_operation_journal', [ array_replace( $base, $delta ) ] ); $assert( 'unavailable' === $read()['status'], 'Malformed or sensitive summary fails closed.' ); }
		$twenty = []; for ( $n = 0; $n < 20; ++$n ) { $twenty[] = array_replace( $base, [ 'operation_id' => 'fixture_' . $n ] ); }
		$put( $order, '_wcos_operation_journal', $twenty ); $assert( 20 === count( $read()['activity'] ), 'Exact owner summary ceiling is supported.' );
		$put( $order, '_wcos_operation_journal', [ $base, $base ] ); $assert( 'unavailable' === $read()['status'], 'Duplicate summary operation fails closed.' );
		$put( $order, '_wcos_operation_journal', array_fill( 0, 21, $base ) ); $assert( 'unavailable' === $read()['status'], 'Summary overflow is unavailable.' );
		$put( $order, '_wcos_operation_journal', [] );
		$guarded = false; $order->add_meta_data( '_wcos_operation_journal', [], false ); $order->save(); $guarded = true;
		$assert( 'unavailable' === $read()['status'], 'Duplicate summary row is unavailable.' ); $put( $order, '_wcos_operation_journal', null );
		$guarded = false; $child = wc_create_order(); $orders[] = $child; $child->save(); $guarded = true;
		$put( $order, '_wcos_child_order_ids', [ $child->get_id() ] ); $put( $child, '_wcos_parent_order_id', $order->get_id() );
		if ( ! $source ) { WCOS_Order_Relation_Repository::$children = [ wc_get_order( $child->get_id() ) ]; }
		$r = $read(); $assert( 'source' === $r['lineage']['role'] && [ $child->get_id() ] === $r['lineage']['child_order_ids'], 'Owner repository discovers exact source children.' );
		$bounded = [ $child ]; $guarded = false;
		for ( $n = 1; $n < 50; ++$n ) { $item = wc_create_order(); $orders[] = $item; $item->update_meta_data( '_wcos_parent_order_id', $order->get_id() ); $item->save(); $bounded[] = $item; }
		$guarded = true; $put( $order, '_wcos_child_order_ids', array_map( static fn( $item ) => $item->get_id(), $bounded ) );
		if ( ! $source ) { WCOS_Order_Relation_Repository::$children = $bounded; }
		$assert( 50 === count( $read()['lineage']['child_order_ids'] ), 'Exactly fifty reciprocal children are supported.' );
		$guarded = false; $overflow = wc_create_order(); $orders[] = $overflow; $overflow->update_meta_data( '_wcos_parent_order_id', $order->get_id() ); $overflow->save(); $guarded = true;
		if ( ! $source ) { WCOS_Order_Relation_Repository::$children[] = $overflow; }
		$assert( 'unavailable' === $read()['status'], 'Real owner discovery detects a fifty-first child.' );
		foreach ( array_merge( array_slice( $bounded, 1 ), [ $overflow ] ) as $extra ) { $put( $extra, '_wcos_parent_order_id', null ); }
		$put( $order, '_wcos_child_order_ids', [ $child->get_id() ] );
		if ( ! $source ) { WCOS_Order_Relation_Repository::$children = [ $child ]; }
		if ( ! $source ) { $assert( 51 === WCOS_Order_Relation_Repository::$calls[0][1], 'Bounded overflow sentinel query.' ); WCOS_Order_Relation_Repository::$children = []; }
		$r = YoRo_Order_Splitter_Service::get_order_activity( [ 'order_id' => $child->get_id() ] ); $assert( 'child' === $r['lineage']['role'] && $order->get_id() === $r['lineage']['parent_order_id'], 'Child requires reciprocal exact parent.' );
		if ( ! $source ) { WCOS_Order_Relation_Repository::$children = [ wc_get_order( $child->get_id() ) ]; }
		$put( $order, '_wcos_child_order_ids', [ $child->get_id(), $child->get_id() ] ); $assert( 'unavailable' === $read()['status'], 'Duplicate declared children fail closed.' );
		$put( $order, '_wcos_child_order_ids', [] ); $assert( 'unavailable' === $read()['status'], 'Unreciprocated discovered child fails closed.' );
		$put( $order, '_wcos_child_order_ids', [ $child->get_id() ] );
		if ( ! $source ) {
			WCOS_Order_Relation_Repository::$children = array_fill( 0, 51, wc_get_order( $child->get_id() ) ); $assert( 'unavailable' === $read()['status'], 'Discovery overflow fails closed.' );
			WCOS_Order_Relation_Repository::$children = [ wc_get_order( $child->get_id() ), wc_get_order( $child->get_id() ) ]; $assert( 'unavailable' === $read()['status'], 'Duplicate discovered children fail closed.' );
			WCOS_Order_Relation_Repository::$children = [ wc_get_order( $refund->get_id() ) ]; $assert( 'unavailable' === $read()['status'], 'Refund relation fails closed.' );
			$blog = get_current_blog_id(); WCOS_Order_Relation_Repository::$on_read = static function () use ( $blog ) { $GLOBALS['blog_id'] = $blog + 1; };
			try { $assert( 'unavailable' === $read()['status'], 'Site switch fails closed.' ); } finally { $GLOBALS['blog_id'] = $blog; WCOS_Order_Relation_Repository::$on_read = null; }
		}
		foreach ( [ [], [ 'order_id' => null ], [ 'order_id' => true ], [ 'order_id' => '91' ], [ 'order_id' => 1.5 ], [ 'order_id' => 0 ], [ 'order_id' => -1 ], [ 'order_id' => PHP_INT_MAX ], [ 'order_id' => $refund->get_id() ], [ 'order_id' => $order->get_id(), 'operation_id' => null ], [ 'order_id' => $order->get_id(), 'split' => false ] ] as $input ) { $assert( is_wp_error( YoRo_Order_Splitter_Service::get_order_activity( $input ) ), 'Invalid or widened target is denied.' ); }
		$assert( is_wp_error( YoRo_Order_Splitter_Service::get_status( [ 'settings' => null ] ) ), 'Status parameters remain closed.' );
		wp_set_current_user( 0 ); $assert( is_wp_error( YoRo_Order_Splitter_Service::get_status( [] ) ) && is_wp_error( $read() ), 'Permission required.' ); wp_set_current_user( (int) $admin[0] );
		remove_filter( 'woocommerce_order_query_args', [ 'WCOS_Order_Relation_Repository', 'tokenize_legacy_query' ], 9 ); $assert( 'incompatible' === YoRo_Order_Splitter_Service::get_status( [] )['status'], 'Unbootstrapped legacy relation reader fails closed.' );
	}
	$assert( 0 === $writes && 0 === $journal_reads && 0 === $http && 0 === $mail, 'No adapter mutation, authoritative journal read, HTTP or mail.' );
} finally {
	$guarded = false; remove_filter( 'query', $query ); remove_filter( 'pre_option', $option, 10 ); remove_filter( 'pre_http_request', $http_guard );
	foreach ( array_reverse( $orders ) as $fixture ) { $fixture->delete( true ); }
	remove_filter( 'pre_wp_mail', $mail_guard ); wp_set_current_user( $user );
}
if ( 'supported' === $mode && ! $source && defined( 'WP_CLI' ) && WP_CLI ) {
	$r = WP_CLI::runcommand( 'eval-file ' . escapeshellarg( __FILE__ ) . ' unsupported', [ 'launch' => true, 'return' => 'all', 'exit_error' => false ] );
	$assert( 0 === $r->return_code, 'Unsupported owner fixture failed: ' . $r->stderr ); echo $r->stdout;
}
echo 'PASS Order Splitter ' . $mode . ': bounded owner reads, privacy, lineage, version, permissions, no mutation, cleanup.' . "\n";
