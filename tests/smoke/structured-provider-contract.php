<?php
/**
 * YoRo structured provider smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/structured-provider-contract.php
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
	! class_exists( 'YoRo_Action_Catalog_Builder' ) &&
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

$assert( class_exists( 'WooCommerce' ), 'WooCommerce must be active for the YoRo plugin smoke contract.' );
$assert( class_exists( 'YoRo_Action_Catalog_Builder' ), 'YoRo_Action_Catalog_Builder is not loaded.' );
$assert( class_exists( 'YoRo_Context_Provider' ), 'YoRo_Context_Provider is not loaded.' );
$assert( class_exists( 'YoRo_Store_Knowledge_Provider' ), 'YoRo_Store_Knowledge_Provider is not loaded.' );

$admin_ids = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);

if ( ! empty( $admin_ids ) ) {
	wp_set_current_user( absint( $admin_ids[0] ) );
}

$assert( ! empty( $admin_ids ), 'An administrator is required to build the permission-scoped action catalog.' );

$custom_stock_status_filter = static function ( $statuses ) {
	$statuses = is_array( $statuses ) ? $statuses : [];
	$statuses['preorder'] = 'Preorder';

	return $statuses;
};

add_filter( 'woocommerce_product_stock_status_options', $custom_stock_status_filter );

if ( class_exists( 'YoRo_Action_Catalog_Builder' ) && class_exists( 'YoRo_Context_Provider' ) ) {
	$context = YoRo_Context_Provider::get_context();
	$catalog = YoRo_Action_Catalog_Builder::build( $context );
	$by_name = [];

	foreach ( $catalog as $item ) {
		if ( is_array( $item ) && ! empty( $item['name'] ) ) {
			$by_name[ $item['name'] ] = $item;
		}
	}

	$required_actions = [
		'orders.search',
		'orders.get_details',
		'orders.add_note',
		'orders.compose_customer_email',
		'orders.update_status',
		'operations.get_summary',
		'store.get_daily_briefing',
		'store.get_business_summary',
		'store.get_insights',
		'customers.lookup',
		'customers.get_service_queue',
		'products.get_inventory_queue',
		'products.update_stock',
		'products.update_stock_status',
	];

	foreach ( $required_actions as $action ) {
		$assert( isset( $by_name[ $action ] ), sprintf( 'Missing action catalog item: %s', $action ) );
	}

	$assert( ! isset( $by_name['orders.bulk_update_status_with_note'] ), 'Legacy bundled status+note action must not be exposed.' );
	$assert( ! isset( $by_name['orders.hold'] ), 'Duplicate hold action must not be exposed; use orders.update_status with status=on-hold.' );
	$assert( ! isset( $by_name['customers.get_summary'] ), 'Deprecated customers.get_summary action must not be exposed.' );

	if ( isset( $by_name['orders.add_note'] ) ) {
		$add_note = $by_name['orders.add_note'];
		$note_slot = $add_note['slot_contract']['user_content_slots'][0] ?? [];

		$assert( in_array( 'order_ids', (array) ( $add_note['required_params'] ?? [] ), true ), 'orders.add_note must require order_ids.' );
		$assert( in_array( 'note', (array) ( $add_note['required_params'] ?? [] ), true ), 'orders.add_note must require note.' );
		$assert( 'note' === ( $note_slot['field'] ?? '' ), 'orders.add_note must expose a note user-content slot.' );
		$assert( 'user_literal' === ( $note_slot['source'] ?? '' ), 'orders.add_note note slot must use user_literal provenance.' );
		$assert( 'reject_request_placeholders' === ( $note_slot['placeholder_policy'] ?? '' ), 'orders.add_note note slot must reject request placeholders.' );
	}

	if ( isset( $by_name['orders.compose_customer_email'] ) ) {
		$email = $by_name['orders.compose_customer_email'];
		$email_slot = $email['slot_contract']['user_content_slots'][0] ?? [];

		$assert( in_array( 'order_id', (array) ( $email['required_params'] ?? [] ), true ), 'orders.compose_customer_email must require order_id.' );
		$assert( in_array( 'instruction', (array) ( $email['required_params'] ?? [] ), true ), 'orders.compose_customer_email must require instruction.' );
		$assert( 'instruction' === ( $email_slot['field'] ?? '' ), 'orders.compose_customer_email must expose an instruction user-content slot.' );
		$assert( 'user_goal' === ( $email_slot['source'] ?? '' ), 'orders.compose_customer_email instruction slot must use user_goal provenance.' );
		$assert( array_key_exists( 'email_intent', (array) ( $email['input_schema'] ?? [] ) ), 'orders.compose_customer_email must expose email_intent.' );
	}

	if ( isset( $by_name['orders.update_status'] ) ) {
		$update_status = $by_name['orders.update_status'];

		$assert( ! empty( $update_status['write_action'] ), 'orders.update_status must be marked as a write action.' );
		$assert( ! empty( $update_status['requires_confirmation'] ), 'orders.update_status must require confirmation.' );
		$assert( in_array( 'order_ids', (array) ( $update_status['required_params'] ?? [] ), true ), 'orders.update_status must require order_ids.' );
		$assert( in_array( 'status', (array) ( $update_status['required_params'] ?? [] ), true ), 'orders.update_status must require status.' );
	}

	if ( isset( $by_name['products.update_stock'] ) ) {
		$update_stock = $by_name['products.update_stock'];

		$assert( ! empty( $update_stock['write_action'] ), 'products.update_stock must be marked as a write action.' );
		$assert( ! empty( $update_stock['requires_confirmation'] ), 'products.update_stock must require confirmation.' );
		$assert( in_array( 'product_id', (array) ( $update_stock['required_params'] ?? [] ), true ), 'products.update_stock must require product_id.' );
		$assert( in_array( 'stock_quantity', (array) ( $update_stock['required_params'] ?? [] ), true ), 'products.update_stock must require stock_quantity.' );
		$assert( 'product_target' === ( $update_stock['slot_contract']['target_slots'][0]['kind'] ?? '' ), 'products.update_stock must expose a product target slot.' );
	}

	if ( isset( $by_name['products.update_stock_status'] ) ) {
		$update_stock_status = $by_name['products.update_stock_status'];

		$assert( ! empty( $update_stock_status['write_action'] ), 'products.update_stock_status must be marked as a write action.' );
		$assert( ! empty( $update_stock_status['requires_confirmation'] ), 'products.update_stock_status must require confirmation.' );
		$assert( in_array( 'product_id', (array) ( $update_stock_status['required_params'] ?? [] ), true ), 'products.update_stock_status must require product_id.' );
		$assert( in_array( 'stock_status', (array) ( $update_stock_status['required_params'] ?? [] ), true ), 'products.update_stock_status must require stock_status.' );
		$assert( 'product_target' === ( $update_stock_status['slot_contract']['target_slots'][0]['kind'] ?? '' ), 'products.update_stock_status must expose a product target slot.' );
		$stock_status_catalog = (array) ( $update_stock_status['value_catalogs']['stock_statuses'] ?? [] );
		$stock_status_keys = array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						return is_array( $item ) ? sanitize_key( $item['key'] ?? '' ) : '';
					},
					$stock_status_catalog
				)
			)
		);

		$assert( in_array( 'preorder', $stock_status_keys, true ), 'products.update_stock_status must expose custom stock statuses in value_catalogs.' );
	}

	foreach ( [ 'orders.search', 'operations.get_summary', 'store.get_daily_briefing', 'store.get_business_summary', 'store.get_insights' ] as $windowed_action ) {
		if ( isset( $by_name[ $windowed_action ] ) ) {
			$assert( array_key_exists( 'time_window', (array) ( $by_name[ $windowed_action ]['input_schema'] ?? [] ) ), sprintf( '%s must expose structured time_window.', $windowed_action ) );
		}
	}

	$report_topics = [
		'store.get_daily_briefing'   => 'overview',
		'store.get_business_summary' => 'sales',
		'operations.get_summary'     => 'operations',
		'store.get_insights'         => 'signals',
	];

	foreach ( $report_topics as $action => $topic ) {
		if ( isset( $by_name[ $action ] ) ) {
			$contract = (array) ( $by_name[ $action ]['reporting_contract'] ?? [] );

			$assert( 'store_report' === ( $contract['user_surface'] ?? '' ), sprintf( '%s must expose store_report reporting_contract user_surface.', $action ) );
			$assert( $topic === ( $contract['topic'] ?? '' ), sprintf( '%s must expose reporting_contract topic %s.', $action, $topic ) );
		}
	}

		$hints = YoRo_Action_Catalog_Builder::build_router_hints( 'Can you show store performance?', $context );
		$assert( 'full_catalog' === ( $hints['catalog_scope'] ?? '' ), 'Router hints must keep the plugin as a full structured provider.' );
		$assert( [] === ( $hints['candidate_actions'] ?? null ), 'Plugin router hints must not choose candidate actions locally.' );

		$report_context = array_merge( $context, [ 'action_catalog_scope' => 'report_provider_catalog' ] );
		$report_catalog = YoRo_Action_Catalog_Builder::build( $report_context );
		$report_hints = YoRo_Action_Catalog_Builder::build_router_hints( 'Can you show store performance?', $report_context, $report_catalog );
		$report_actions = array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						return is_array( $item ) ? ( $item['name'] ?? '' ) : '';
					},
					$report_catalog
				)
			)
		);

		sort( $report_actions );

		$assert( [ 'operations.get_summary', 'store.get_business_summary', 'store.get_daily_briefing', 'store.get_insights' ] === $report_actions, 'Report-provider catalog scope must expose only store report providers.' );
		$assert( 'report_provider_catalog' === ( $report_hints['catalog_scope'] ?? '' ), 'Report-provider catalog scope must be exposed in router hints.' );
		$assert( 'provider_catalog' === ( $report_hints['report_topic_selection'] ?? '' ), 'Report-provider catalog hints must delegate report topic selection to YoRo AI.' );
		$assert( true === ( $report_hints['report_provider_catalog'] ?? false ), 'Report-provider catalog hint must be explicit.' );
		$assert( ! isset( $report_hints['report_topic'] ), 'Report-provider catalog hints must not invent a report topic without UI context.' );

		$overview_context = array_merge( $report_context, [ 'report_topic' => 'overview' ] );
		$overview_hints = YoRo_Action_Catalog_Builder::build_router_hints( 'Can you show store performance?', $overview_context, $report_catalog );
		$assert( 'overview' === ( $overview_hints['report_topic'] ?? '' ), 'Report topic hint must preserve explicit UI report context.' );
	}

if ( class_exists( 'YoRo_Store_Knowledge_Provider' ) ) {
	$knowledge = YoRo_Store_Knowledge_Provider::get_context( 'conversation' );

	$assert( 9 === (int) ( $knowledge['schema_version'] ?? 0 ), 'Store knowledge schema version must be 9.' );
	$assert( 'store_knowledge' === ( $knowledge['type'] ?? '' ), 'Store knowledge type must be store_knowledge.' );
	$assert( isset( $knowledge['facts']['settings'] ), 'Store knowledge must include WooCommerce settings facts.' );
	$assert( isset( $knowledge['facts']['product_model']['stock_statuses'] ), 'Store knowledge must include product stock status facts.' );
	$assert( isset( $knowledge['facts']['integrations'] ), 'Store knowledge must include integration facts.' );
	$assert( isset( $knowledge['profiles']['catalog'] ), 'Store knowledge must include catalog profile.' );
	$assert( 'yoro_action_registry' === ( $knowledge['capabilities']['action_source'] ?? '' ), 'Capabilities must come from the YoRo action registry.' );
	$assert( 'plugin_executor' === ( $knowledge['capabilities']['execution_boundary'] ?? '' ), 'Execution boundary must remain plugin_executor.' );
	$assert( 'yoro_ai_conversation_brain' === ( $knowledge['capabilities']['routing_boundary'] ?? '' ), 'Routing boundary must remain yoro_ai_conversation_brain.' );
	$assert( 'woocommerce_settings' === ( $knowledge['sources']['settings'] ?? '' ), 'Settings source must be WooCommerce settings.' );

	$product_stock_statuses = (array) ( $knowledge['facts']['product_model']['stock_statuses'] ?? [] );
	$product_stock_status_keys = array_values(
		array_filter(
			array_map(
				static function ( $item ) {
					return is_array( $item ) ? sanitize_key( $item['key'] ?? '' ) : '';
				},
				$product_stock_statuses
			)
		)
	);

	$assert( in_array( 'preorder', $product_stock_status_keys, true ), 'Store knowledge must preserve custom product stock statuses.' );
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo structured provider smoke contract passed.\n";
