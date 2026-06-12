<?php
/**
 * YoRo chat blocked-preview bridge smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/chat-blocked-preview-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if (
	! class_exists( 'YoRo_REST_API' ) &&
	class_exists( 'YoRo_AI_Core' ) &&
	function_exists( 'yoro_ai_core_has_woocommerce' ) &&
	yoro_ai_core_has_woocommerce()
) {
	YoRo_AI_Core::init();
}

if (
	class_exists( 'YoRo_Action_Registry' ) &&
	class_exists( 'YoRo_Orders_Actions' ) &&
	0 === count( YoRo_Action_Registry::all() )
) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
}

$failures = [];

$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'WooCommerce' ), 'WooCommerce must be active for the YoRo chat blocked-preview smoke contract.' );
$assert( class_exists( 'YoRo_REST_Chat_Message_Handler' ), 'YoRo_REST_Chat_Message_Handler is not loaded.' );
$assert( class_exists( 'YoRo_Action_Registry' ), 'YoRo_Action_Registry is not loaded.' );

if ( class_exists( 'YoRo_REST_Chat_Message_Handler' ) ) {
	$format_response = new ReflectionMethod( 'YoRo_REST_Chat_Message_Handler', 'format_response' );
	$format_response->setAccessible( true );

	$blocked = $format_response->invoke(
		null,
		[
			'mode'                    => 'action',
			'stage'                   => 'confirm',
			'action'                  => 'orders.update_status',
			'params'                  => [
				'order_ids' => [ 987654321 ],
				'status'    => 'completed',
			],
			'conversation_id'         => 'smoke-blocked-preview',
			'pending_operation_token' => 'smoke-token',
			'requires_confirmation'   => true,
			'confirm_required'        => true,
			'assistant'               => [
				'message' => 'I can update order #987654321 to Completed.',
			],
			'action_plan'             => [
				'type'  => 'single',
				'steps' => [
					[
						'id'     => 'op_1',
						'action' => 'orders.update_status',
						'params' => [
							'order_ids' => [ 987654321 ],
							'status'    => 'completed',
						],
					],
				],
			],
		],
		'Update order 987654321 status to completed'
	);

	$result = isset( $blocked['result'] ) && is_array( $blocked['result'] ) ? $blocked['result'] : [];

	$assert( 'blocked' === ( $blocked['stage'] ?? '' ), 'Blocked local preview must surface stage=blocked.' );
	$assert( false === (bool) ( $blocked['execution_required'] ?? true ), 'Blocked local preview must set execution_required=false.' );
	$assert( false === (bool) ( $blocked['requires_confirmation'] ?? true ), 'Blocked local preview must set requires_confirmation=false.' );
	$assert( false === (bool) ( $blocked['confirm_required'] ?? true ), 'Blocked local preview must set confirm_required=false.' );
	$assert( false === (bool) ( $blocked['pending_operation_active'] ?? true ), 'Blocked local preview must set pending_operation_active=false.' );
	$assert( 'blocked' === ( $blocked['pending_operation_status'] ?? '' ), 'Blocked local preview must expose pending_operation_status=blocked.' );
	$assert( false === (bool) ( $result['execution_required'] ?? true ), 'Blocked local preview result must set execution_required=false.' );
	$assert( false === (bool) ( $result['requires_confirmation'] ?? true ), 'Blocked local preview result must set requires_confirmation=false.' );
	$assert( false === (bool) ( $result['confirm_required'] ?? true ), 'Blocked local preview result must set confirm_required=false.' );
	$assert( false === (bool) ( $result['pending_operation_active'] ?? true ), 'Blocked local preview result must set pending_operation_active=false.' );
	$assert( true === (bool) ( $result['blocked'] ?? false ), 'Blocked local preview result must preserve blocked=true.' );
	$assert( 'order_not_found' === ( $result['block_reason'] ?? '' ), 'Blocked local preview result must preserve order_not_found.' );
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo chat blocked-preview bridge smoke contract passed.\n";
