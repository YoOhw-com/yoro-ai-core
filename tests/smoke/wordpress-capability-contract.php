<?php
/**
 * YoRo WordPress capability contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/wordpress-capability-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( class_exists( 'YoRo_AI_Core' ) && function_exists( 'yoro_ai_core_has_woocommerce' ) && yoro_ai_core_has_woocommerce() ) {
	YoRo_AI_Core::init();
}

$failures = [];

$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'YoRo_Capabilities' ), 'YoRo_Capabilities is not loaded.' );
$assert( class_exists( 'YoRo_Action_Registry' ), 'YoRo_Action_Registry is not loaded.' );
$assert( class_exists( 'YoRo_WordPress_Actor' ), 'YoRo_WordPress_Actor is not loaded.' );

if ( class_exists( 'YoRo_Capabilities' ) ) {
	YoRo_Capabilities::install();

	$administrator = get_role( 'administrator' );
	$shop_manager  = get_role( 'shop_manager' );

	foreach ( YoRo_Capabilities::all() as $capability ) {
		$assert( $administrator instanceof WP_Role && $administrator->has_cap( $capability ), sprintf( 'Administrator is missing %s.', $capability ) );
	}

	foreach ( YoRo_Capabilities::shop_manager() as $capability ) {
		$assert( $shop_manager instanceof WP_Role && $shop_manager->has_cap( $capability ), sprintf( 'Shop Manager is missing %s.', $capability ) );
	}

	foreach ( [ YoRo_Capabilities::MANAGE_CONNECTION, YoRo_Capabilities::MANAGE_ACCESS, YoRo_Capabilities::MANAGE_POLICIES ] as $capability ) {
		$assert( $shop_manager instanceof WP_Role && ! $shop_manager->has_cap( $capability ), sprintf( 'Shop Manager must not receive %s.', $capability ) );
	}

	foreach ( YoRo_Action_Registry::all() as $name => $action ) {
		$expected = ! empty( $action['write_action'] ) ? YoRo_Capabilities::EXECUTE_ACTIONS : YoRo_Capabilities::VIEW_STORE_DATA;
		$assert( $expected === ( $action['permission'] ?? '' ), sprintf( 'Action %s has the wrong YoRo capability.', $name ) );
	}
}

if ( class_exists( 'YoRo_WordPress_Actor' ) ) {
	$administrator_user = get_users(
		[
			'role'   => 'administrator',
			'number' => 1,
		]
	);

	if ( ! empty( $administrator_user[0] ) ) {
		$previous_user_id = get_current_user_id();
		wp_set_current_user( $administrator_user[0]->ID );
		$payload   = YoRo_WordPress_Actor::current_payload();
		$assertion = YoRo_WordPress_Actor::assertion( 'smoke-site-key' );
		$parts     = explode( '.', $assertion );

		$assert( 64 === strlen( (string) ( $payload['subject'] ?? '' ) ), 'WordPress actor subject must be an opaque SHA-256 digest.' );
		$assert( 2 === count( $parts ), 'WordPress actor assertion must contain a payload and signature.' );
		$assert( ! empty( $payload['capabilities'] ), 'WordPress actor assertion must include YoRo capabilities.' );
		wp_set_current_user( $previous_user_id );
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "YoRo WordPress capability contract passed.\n";
