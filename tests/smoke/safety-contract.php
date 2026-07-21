<?php
/**
 * Run with wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/safety-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this contract through WordPress.\n" );
	exit( 1 );
}

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'YoRo_Safety_Contract' ), 'YoRo_Safety_Contract is not loaded.' );
$assert( 2 === YoRo_Safety_Contract::VERSION, 'Safety contract version must be 2.' );
$assert( 'ai_chat' === YoRo_Safety_Contract::trusted_origin( 'pending-token', 'execute' ), 'Pending actions must use ai_chat origin.' );
$assert( 'direct_ui' === YoRo_Safety_Contract::trusted_origin( '', 'execute' ), 'Direct actions must use direct_ui origin.' );
$assert( 'system_rollback' === YoRo_Safety_Contract::trusted_origin( '', 'undo' ), 'Undo must use system_rollback origin.' );

$hash_a = YoRo_Safety_Contract::hash(
	[
		'params' => [ 'status' => 'processing', 'order_ids' => [ 9, 3 ] ],
		'action' => 'orders.update_status',
	]
);
$hash_b = YoRo_Safety_Contract::hash(
	[
		'action' => 'orders.update_status',
		'params' => [ 'order_ids' => [ 9, 3 ], 'status' => 'processing' ],
	]
);
$hash_c = YoRo_Safety_Contract::hash(
	[
		'action' => 'orders.update_status',
		'params' => [ 'order_ids' => [ 3, 9 ], 'status' => 'processing' ],
	]
);

$assert( $hash_a === $hash_b, 'Canonical hash must ignore associative key order.' );
$assert( $hash_a !== $hash_c, 'Canonical hash must preserve list order.' );

if ( class_exists( 'YoRo_Action_Registry' ) ) {
	foreach ( YoRo_Action_Registry::all() as $name => $action ) {
		if ( empty( $action['ai_exposed'] ) ) {
			continue;
		}

		$assert( in_array( $action['effect'] ?? '', [ 'read', 'draft', 'mutate', 'communicate', 'delete', 'financial' ], true ), sprintf( '%s is missing a valid effect.', $name ) );
		$assert( in_array( $action['reversibility'] ?? '', [ 'full', 'partial', 'none' ], true ), sprintf( '%s is missing reversibility.', $name ) );
		$assert( absint( $action['max_targets'] ?? 0 ) >= 1, sprintf( '%s is missing max_targets.', $name ) );
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}

echo "Safety contract passed.\n";
