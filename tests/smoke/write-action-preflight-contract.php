<?php
/**
 * YoRo write-action preflight smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/write-action-preflight-contract.php
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

$assert_blocked_preview_has_no_execution_surface = static function ( array $preview, string $action_name ) use ( $assert ) {
	$assert( false === (bool) ( $preview['will_execute'] ?? true ), "{$action_name} blocked preview must set will_execute=false." );
	$assert( false === (bool) ( $preview['execution_required'] ?? false ), "{$action_name} blocked preview must not expose execution_required=true." );
	$assert( false === (bool) ( $preview['requires_confirmation'] ?? false ), "{$action_name} blocked preview must not expose requires_confirmation=true." );
	$assert( false === (bool) ( $preview['confirm_required'] ?? false ), "{$action_name} blocked preview must not expose confirm_required=true." );
	$assert( false === (bool) ( $preview['pending_operation_active'] ?? false ), "{$action_name} blocked preview must not expose pending_operation_active=true." );
	$assert( '' === sanitize_text_field( (string) ( $preview['pending_operation_token'] ?? '' ) ), "{$action_name} blocked preview must not expose a pending operation token." );
};

$assert( class_exists( 'WooCommerce' ), 'WooCommerce must be active for the YoRo write-action preflight smoke contract.' );
$assert( class_exists( 'YoRo_Action_Registry' ), 'YoRo_Action_Registry is not loaded.' );
$assert( class_exists( 'YoRo_Action_Catalog_Builder' ), 'YoRo_Action_Catalog_Builder is not loaded.' );

$expected = [
	'orders.update_status' => [
		'required_params' => [ 'order_ids', 'status' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'orders.add_note' => [
		'required_params' => [ 'order_ids', 'note' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'orders.compose_customer_email' => [
		'required_params' => [ 'order_id', 'instruction' ],
		'ai_exposed'      => true,
		'write_action'    => false,
		'confirm'         => false,
	],
	'orders.email_customer_template' => [
		'required_params' => [ 'order_id', 'template' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'orders.save_customer_email_draft' => [
		'required_params' => [ 'order_id', 'subject', 'body' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'orders.update_customer_email_draft' => [
		'required_params' => [ 'order_id', 'draft_id', 'subject', 'body' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'orders.delete_customer_email_draft' => [
		'required_params' => [ 'order_id', 'draft_id' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'orders.send_customer_email' => [
		'required_params' => [ 'order_id', 'subject', 'body' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'orders.add_private_note_template' => [
		'required_params' => [ 'order_ids', 'template' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'orders.remove_note' => [
		'required_params' => [ 'note_ids' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'orders.restore_statuses' => [
		'required_params' => [],
		'ai_exposed'      => false,
		'write_action'    => true,
		'confirm'         => true,
	],
	'orders.refund_preview' => [
		'required_params' => [ 'order_id' ],
		'ai_exposed'      => true,
		'write_action'    => false,
		'confirm'         => false,
	],
	'products.update_stock' => [
		'required_params' => [ 'product_id', 'stock_quantity' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'products.update_stock_status' => [
		'required_params' => [ 'product_id', 'stock_status' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'coupons.lookup' => [
		'required_params' => [ 'coupon_code' ],
		'ai_exposed'      => true,
		'write_action'    => false,
		'confirm'         => false,
	],
	'coupons.create' => [
		'required_params' => [ 'coupon_code', 'discount_type', 'discount_amount' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'email_templates.save' => [
		'required_params' => [ 'name', 'subject', 'body' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'email_templates.delete' => [
		'required_params' => [ 'template_id' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'assistant.submit_developer_request' => [
		'required_params' => [ 'request_text' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
];

$registry = class_exists( 'YoRo_Action_Registry' ) ? YoRo_Action_Registry::all() : [];
$catalog = class_exists( 'YoRo_Action_Catalog_Builder' ) ? YoRo_Action_Catalog_Builder::build() : [];
$catalog_by_name = [];

foreach ( $catalog as $item ) {
	if ( is_array( $item ) && ! empty( $item['name'] ) ) {
		$catalog_by_name[ $item['name'] ] = $item;
	}
}

foreach ( $expected as $action_name => $contract ) {
	$registered = $registry[ $action_name ] ?? null;

	$assert( is_array( $registered ), "{$action_name} must be registered." );

	if ( ! is_array( $registered ) ) {
		continue;
	}

	$required_params = array_values( (array) ( $registered['required_params'] ?? [] ) );
	$assert( $contract['required_params'] === $required_params, "{$action_name} registered required_params must match the contract." );
	$assert( (bool) ( $registered['write_action'] ?? false ) === (bool) $contract['write_action'], "{$action_name} registered write_action must match the contract." );
	$assert( ! empty( $registered['preview_callback'] ) && is_callable( $registered['preview_callback'] ), "{$action_name} must expose a callable preview_callback." );

	if ( ! empty( $contract['ai_exposed'] ) ) {
		$catalog_item = $catalog_by_name[ $action_name ] ?? null;

		$assert( is_array( $catalog_item ), "{$action_name} must be exposed in the AI action catalog." );

		if ( is_array( $catalog_item ) ) {
			$assert( $contract['required_params'] === array_values( (array) ( $catalog_item['required_params'] ?? [] ) ), "{$action_name} catalog required_params must match the contract." );
			$assert( (bool) ( $catalog_item['write_action'] ?? false ) === (bool) $contract['write_action'], "{$action_name} catalog write_action must match the contract." );
			$assert( (bool) ( $catalog_item['requires_confirmation'] ?? false ) === (bool) $contract['confirm'], "{$action_name} catalog confirmation policy must match the contract." );
		}
	} else {
		$assert( ! isset( $catalog_by_name[ $action_name ] ), "{$action_name} must stay out of the AI action catalog." );
	}

	$preview = call_user_func( $registered['preview_callback'], [] );

	$assert( is_array( $preview ), "{$action_name} preview_callback must return an array for missing params." );

	if ( is_array( $preview ) ) {
		$assert( true === (bool) ( $preview['blocked'] ?? false ), "{$action_name} missing-param preview must be blocked before approval." );
		$assert( '' !== sanitize_key( $preview['block_reason'] ?? '' ), "{$action_name} blocked preview must expose a block_reason." );
		$assert( false === (bool) ( $preview['changes_store'] ?? true ), "{$action_name} blocked preview must not change the store." );
		$assert_blocked_preview_has_no_execution_surface( $preview, $action_name );
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo write-action preflight smoke contract passed.\n";
