<?php
/** Isolated WP metadata fixture with loaded-owner identity doubles; never boots an owner plugin. */
if ( ! defined( 'ABSPATH' ) || class_exists( 'YOSWC_Loyalty', false ) || class_exists( 'YOWCL_Loyalty', false ) ) { throw new RuntimeException( 'Isolated fixture required.' ); }
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$original_user = get_current_user_id();
$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$assert( ! empty( $admin ), 'Admin fixture required.' );
wp_set_current_user( (int) $admin[0] );
$guarded = false; $writes = 0; $http = 0; $mail = 0; $keys = []; $override = null; $id = 0;
$query = static function ( $sql ) use ( &$guarded, &$writes ) {
	if ( $guarded && ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql ) ) { ++$writes; throw new RuntimeException( 'Unexpected SQL write.' ); }
	if ( $guarded && preg_match( '/points_log|user_email|user_login|display_name/i', $sql ) ) { throw new RuntimeException( 'Forbidden profile/history read.' ); }
	return $sql;
};
$http_guard = static function () use ( &$http ) { ++$http; return new WP_Error( 'no_http', 'Unexpected remote call.' ); };
$mail_guard = static function () use ( &$mail, &$guarded ) { if ( $guarded ) { ++$mail; } return true; };
add_filter( 'pre_wp_mail', $mail_guard );
$meta_guard = static function ( $value, $user, $key, $single ) use ( &$guarded, &$keys, &$override, &$id, $admin ) {
	if ( ! $guarded ) { return $value; }
	// Multisite checks the calling manager's capability metadata, never the customer's roles.
	global $wpdb;
	if ( (int) $admin[0] === $user && $wpdb->get_blog_prefix() . 'capabilities' === $key && $single ) { return $value; }
	if ( $user !== $id || ! in_array( $key, [ 'user_points', 'user_earning_points' ], true ) || $single ) { throw new RuntimeException( 'Forbidden metadata read.' ); }
	$keys[] = $key;
	return null !== $override && 'user_points' === $key ? $override : $value;
};
try {
	$id = wp_insert_user( [ 'user_login' => 'yr80_' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'SECRET' . wp_generate_password( 10, false ) . '@example.test', 'role' => 'subscriber' ] );
	$assert( is_int( $id ), 'Customer fixture.' );
	update_user_meta( $id, 'private_sentinel', 'SECRET' );
	$read = static fn() => YoRo_Loyalty_Service::get_customer_balance( [ 'customer_id' => $id ] );
	add_filter( 'query', $query ); add_filter( 'pre_http_request', $http_guard ); add_filter( 'pre_wp_mail', $mail_guard ); add_filter( 'get_user_metadata', $meta_guard, 10, 4 ); $guarded = true;
	$assert( 'absent' === YoRo_Loyalty_Service::get_status( [] )['status'] && 'unavailable' === $read()['status'] && [] === $keys, 'Absent owner never reads points.' );
	$premium = 'premium' === getenv( 'YORO_LOYALTY_TEST_OWNER' );
	if ( $premium ) { class YOWCL_Loyalty { public function __construct() { throw new RuntimeException( 'No owner construction.' ); } } }
	else { class YOSWC_Loyalty { public function __construct() { throw new RuntimeException( 'No owner construction.' ); } } }
	$assert( 'incompatible' === YoRo_Loyalty_Service::get_status( [] )['status'], 'Missing version.' );
	define( $premium ? 'WC_LOYALTY_VERSION' : 'YOSWC_LOYALTY_VERSION', getenv( 'YORO_LOYALTY_TEST_VERSION' ) ?: '1.2.2' );
	if ( '1.2.2' !== ( getenv( 'YORO_LOYALTY_TEST_VERSION' ) ?: '1.2.2' ) ) {
		$assert( 'incompatible' === YoRo_Loyalty_Service::get_status( [] )['status'] && 'unavailable' === $read()['status'] && [] === $keys, 'Unsupported version closed.' );
	} else {
		$assert( 'available' === YoRo_Action_Executor::execute( 'loyalty.get_status', [] )['status'], 'Registered status.' );
		$r = $read(); $assert( 'observed' === $r['status'] && 0 === $r['current_points'] && 0 === $r['earning_points'], 'Missing values are zero.' );
		$guarded = false; update_user_meta( $id, 'user_points', '120' ); update_user_meta( $id, 'user_earning_points', '250' ); $guarded = true;
		$r = YoRo_Action_Executor::execute( 'loyalty.get_customer_balance', [ 'customer_id' => $id ] );
		$wire = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $r, 'loyalty.get_customer_balance' );
		$assert( 120 === $r['current_points'] && 250 === $r['earning_points'] && ( $premium ? 'premium' : 'free' ) === $r['owner_kind'], 'Retained values and owner.' );
		$assert( false === strpos( wp_json_encode( [ $r, $wire ] ), 'SECRET' ), 'Closed privacy output.' );
		foreach ( [ [ '0' ], [ '9007199254740991' ], [ 5 ] ] as $override ) { $r = $read(); $assert( ! is_wp_error( $r ) && (int) $override[0] === $r['current_points'], 'Safe integer boundary.' ); }
		foreach ( [ [ -1 ], [ '-1' ], [ true ], [ null ], [ 1.5 ], [ '1.5' ], [ '' ], [ ' 1' ], [ '01' ], [ '+1' ], [ '1e2' ], [ '9007199254740992' ], [ '1', '2' ], [ [] ] ] as $override ) { $assert( is_wp_error( $read() ), 'Malformed points closed.' ); }
		$override = null;
		foreach ( [ [], [ 'customer_id' => '1' ], [ 'customer_id' => true ], [ 'customer_id' => 0 ], [ 'customer_id' => -1 ], [ 'customer_id' => 9007199254740992 ], [ 'customer_id' => $id, 'order_id' => null ], [ 'customer_id' => $id, 'redeem' => false ], [ 'customer_id' => $id, 'email' => 'SECRET' ] ] as $params ) { $assert( is_wp_error( YoRo_Loyalty_Service::get_customer_balance( $params ) ), 'Raw input rejected.' ); }
		$assert( is_wp_error( YoRo_Loyalty_Service::get_customer_balance( [ 'customer_id' => 9007199254740991 ] ) ), 'Unregistered ID.' );
		$assert( is_wp_error( YoRo_Loyalty_Service::get_status( [ 'license' => null ] ) ), 'Status closed.' );
		if ( is_multisite() ) {
			$guarded = false; remove_user_from_blog( $id, get_current_blog_id() ); $guarded = true; $keys = [];
			$assert( is_wp_error( $read() ) && [] === $keys, 'Registered nonmember cannot read points.' );
			$guarded = false; add_user_to_blog( get_current_blog_id(), $id, 'subscriber' ); $guarded = true;
			$assert( 'observed' === $read()['status'], 'Exact member restored.' );
		}
		$blog = get_current_blog_id(); $drift = static function ( $value, $user ) use ( $blog, $id ) { if ( $user === $id ) { $GLOBALS['blog_id'] = $blog + 1; } return $value; };
		add_filter( 'get_user_metadata', $drift, 20, 2 );
		try { $assert( is_wp_error( $read() ), 'Site drift closed.' ); } finally { $GLOBALS['blog_id'] = $blog; remove_filter( 'get_user_metadata', $drift, 20 ); }
		if ( $premium ) { class YOSWC_Loyalty {} } else { class YOWCL_Loyalty {} }
		$keys = []; $assert( 'ambiguous' === YoRo_Loyalty_Service::get_status( [] )['status'] && 'unavailable' === $read()['status'] && [] === $keys, 'Ambiguous owner closed.' );
	}
	wp_set_current_user( 0 ); $assert( is_wp_error( $read() ) && is_wp_error( YoRo_Loyalty_Service::get_status( [] ) ), 'Permission gate.' );
	$assert( 0 === $writes && 0 === $http && 0 === $mail, 'Zero writes/HTTP/mail.' );
	echo "Loyalty adapter retained-points fixture PASS.\n";
} finally {
	$guarded = false; remove_filter( 'query', $query ); remove_filter( 'pre_http_request', $http_guard ); remove_filter( 'get_user_metadata', $meta_guard, 10 );
	if ( $id && ! is_wp_error( $id ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; if ( is_multisite() ) { require_once ABSPATH . 'wp-admin/includes/ms.php'; wpmu_delete_user( $id ); } else { wp_delete_user( $id ); } }
	wp_set_current_user( $original_user ); remove_filter( 'pre_wp_mail', $mail_guard );
}
