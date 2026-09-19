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

$previous_user_id = get_current_user_id();
$admin_ids         = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);
$admin_id          = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;

$assert( $admin_id > 0, 'A WordPress administrator user is required for action-catalog capability checks.' );

if ( $admin_id > 0 ) {
	wp_set_current_user( $admin_id );
}

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
	'orders.update_items' => [
		'required_params' => [ 'order_id', 'line_items' ],
		'ai_exposed' => true, 'write_action' => true, 'confirm' => true,
	],
	'fulfillment.create' => [
		'required_params' => [ 'order_id', 'items', 'expected_version' ],
		'ai_exposed' => true, 'write_action' => true, 'confirm' => true,
	],
	'coupons.update' => [
		'required_params' => [ 'coupon_id', 'discount_amount', 'expected_version' ],
		'ai_exposed' => true, 'write_action' => true, 'confirm' => true,
	],
	'orders.update_addresses' => [
		'required_params' => [ 'order_id', 'expected_version', 'shipping' ],
		'ai_exposed' => true, 'write_action' => true, 'confirm' => true,
	],
	'orders.update_status' => [
		'required_params' => [ 'order_ids', 'status', 'expected_versions' ],
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
		'ai_exposed'      => false,
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
		'ai_exposed'      => false,
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
		'required_params' => [ 'statuses', 'expected_versions' ],
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
		'required_params' => [ 'product_id', 'stock_quantity', 'expected_version' ],
		'ai_exposed'      => true,
		'write_action'    => true,
		'confirm'         => true,
	],
	'products.update_stock_status' => [
		'required_params' => [ 'product_id', 'stock_status', 'expected_version' ],
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

// Native send characterization is opt-in and requires a process-level no-delivery sink.
// Run only in a disposable WordPress database with YORO_NATIVE_EMAIL_NO_DELIVERY_TEST=1
// and PHP -d sendmail_path=/usr/bin/true. No transport replacement hooks are used.
if ( '1' === getenv( 'YORO_NATIVE_EMAIL_NO_DELIVERY_TEST' ) ) {
	if ( '/usr/bin/true' !== ini_get( 'sendmail_path' ) ) {
		throw new RuntimeException( 'Native transactional email tests require the process-level no-delivery sink.' );
	}
	$fixture = new WC_Order();
	$fixture->set_billing_email( 'recipient-a@example.invalid' );
	$fixture->set_status( 'processing' );
	$fixture->set_date_created( time() );
	$fixture->save();
	$type = 'customer_processing_order';
	$option_key = 'woocommerce_' . $type . '_settings';
	$original_settings = get_option( $option_key, null );
	$settings = is_array( $original_settings ) ? $original_settings : [];
	$settings['enabled'] = 'yes';
	update_option( $option_key, $settings );
	$make_params = static function () use ( $fixture, $type ) {
		$current = wc_get_order( $fixture->get_id() );
		return [ 'order_id' => $current->get_id(), 'expected_version' => YoRo_Order_Presenter::order_version( $current ), 'email_type' => $type ];
	};
	$is_error = static function ( $value, $code ) { return is_wp_error( $value ) && $value->get_error_code() === $code; };
	$hooks = [];
	$attach = static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks ) {
		add_filter( $hook, $callback, $priority, $args );
		$hooks[] = [ $hook, $callback, $priority ];
	};
	$clear = static function () use ( &$hooks ) {
		foreach ( $hooks as $hook ) { remove_filter( $hook[0], $hook[1], $hook[2] ); }
		$hooks = [];
	};
	try {
		$completed = wc_get_order( $fixture->get_id() );
			$completed->set_status( 'completed' );
			$completed->save();
			$completed_params = [ 'order_id' => $completed->get_id(), 'expected_version' => YoRo_Order_Presenter::order_version( $completed ), 'email_type' => 'customer_completed_order' ];
			$assert( is_array( YoRo_Orders_Email_Service::preview_send_transactional_email( $completed_params ) ), 'Native completed email is eligible for completed order context.' );
			$assert( is_array( YoRo_Orders_Email_Service::send_transactional_email( $completed_params + [ '_yoro_confirmed' => true ] ) ), 'Native completed email uses its own trigger and mailer observation.' );
			$completed->set_status( 'processing' );
			$completed->save();
		$params = $make_params();
		$preview = YoRo_Orders_Email_Service::preview_send_transactional_email( $params );
		$assert( is_array( $preview ) && true === $preview['read_only'], 'Native processing email has a read-only preview.' );
		$assert( $is_error( YoRo_Orders_Email_Service::send_transactional_email( $params ), 'confirmation_required' ), 'Native email cannot bypass confirmation.' );
		foreach ( [ 'to', 'subject', 'body', 'class', 'order_ids' ] as $extra ) {
			$assert( $is_error( YoRo_Orders_Email_Service::preview_send_transactional_email( $params + [ $extra => 'override' ] ), 'invalid_params' ), 'Native email rejects caller override: ' . $extra );
		}
		foreach ( [ 'new_order', 'customer_invoice', 'plugin_notice', '' ] as $unsupported ) {
			$assert( $is_error( YoRo_Orders_Email_Service::preview_send_transactional_email( array_replace( $params, [ 'email_type' => $unsupported ] ) ), 'invalid_params' ), 'Native email rejects unsupported type: ' . $unsupported );
		}
		$before = wc_get_order( $fixture->get_id() );
		$old_modified = $before->get_date_modified();
		$before->set_billing_email( 'recipient-b@example.invalid' );
		$before->set_date_modified( $old_modified );
		$before->save();
		$assert( wc_get_order( $fixture->get_id() )->get_date_modified()->getTimestamp() === $old_modified->getTimestamp(), 'Recipient mutation fixture preserves the exact modified second.' );
		$assert( $is_error( YoRo_Orders_Email_Service::preview_send_transactional_email( $params ), 'stale_version' ), 'Same-second billing recipient mutation invalidates the canonical version.' );
		$params = $make_params();
		$attach( 'woocommerce_email_recipient_' . $type, static function () { return 'filtered@example.invalid'; } );
		$assert( $is_error( YoRo_Orders_Email_Service::preview_send_transactional_email( $params ), 'stale_version' ), 'Native recipient filter change invalidates the current-read version.' );
		$clear();
		$recipient_calls = 0;
		$attach( 'woocommerce_email_recipient_' . $type, static function () use ( &$recipient_calls ) { return 1 === ++$recipient_calls ? 'read@example.invalid' : 'changed@example.invalid'; } );
		$assert( $is_error( YoRo_Orders_Email_Service::preview_send_transactional_email( $make_params() ), 'stale_version' ), 'Stateful recipient filter cannot select a recipient separately from the shared version snapshot.' );
		$clear();
		$settings['enabled'] = 'no';
		update_option( $option_key, $settings );
		$assert( $is_error( YoRo_Orders_Email_Service::preview_send_transactional_email( $params ), 'stale_version' ), 'Native settings change invalidates the canonical version.' );
		$assert( $is_error( YoRo_Orders_Email_Service::preview_send_transactional_email( $make_params() ), 'transactional_email_ineligible' ), 'Disabled native email fails closed after a fresh read.' );
		$settings['enabled'] = 'yes';
		update_option( $option_key, $settings );
		$params = $make_params();
		$attach( 'woocommerce_email_headers', static function ( $headers ) { return $headers . "Bcc: hidden@example.invalid\r\n"; } );
		$assert( $is_error( YoRo_Orders_Email_Service::preview_send_transactional_email( $make_params() ), 'invalid_recipient' ), 'Native hidden additional recipient fails closed.' );
		$clear();
		$sent = YoRo_Orders_Email_Service::send_transactional_email( $make_params() + [ '_yoro_confirmed' => true ] );
		$assert( is_array( $sent ) && 'mailer_accepted' === $sent['outcome'] && ! isset( $sent['delivered'] ), 'Native sink send reports mailer acceptance, never delivery.' );
		$attach( 'woocommerce_mail_callback_params', static function ( $args ) { $args[0] = 'raced@example.invalid'; return $args; }, 10, 2 );
		$assert( $is_error( YoRo_Orders_Email_Service::send_transactional_email( $make_params() + [ '_yoro_confirmed' => true ] ), 'transactional_email_outcome_unknown' ), 'Native callback recipient race cannot report success or auto-retry.' );
		$clear();
		$attach( 'wp_mail', static function ( $atts ) { $atts['to'] = 'wp-raced@example.invalid'; return $atts; } );
		$assert( $is_error( YoRo_Orders_Email_Service::send_transactional_email( $make_params() + [ '_yoro_confirmed' => true ] ), 'transactional_email_outcome_unknown' ), 'Downstream WordPress recipient race fails closed.' );
		$clear();
		$attach( 'phpmailer_init', static function ( $mailer ) { $mailer->addBCC( 'hidden@example.invalid' ); } );
		$assert( $is_error( YoRo_Orders_Email_Service::send_transactional_email( $make_params() + [ '_yoro_confirmed' => true ] ), 'transactional_email_outcome_unknown' ), 'Final native transport guard detects added Bcc.' );
		$clear();
		$late_transport_called = false;
		$attach( 'woocommerce_mail_content', static function ( $content ) use ( $attach, &$late_transport_called ) {
			$attach( 'phpmailer_init', static function ( $mailer ) use ( &$late_transport_called ) {
				$late_transport_called = true;
				$mailer->clearAllRecipients();
				$mailer->addAddress( 'late-race@example.invalid' );
			}, PHP_INT_MAX );
			return $content;
		} );
		$assert( $is_error( YoRo_Orders_Email_Service::send_transactional_email( $make_params() + [ '_yoro_confirmed' => true ] ), 'transactional_email_outcome_unknown' ) && ! $late_transport_called, 'A PHPMailer callback registered after the final guard cannot change the approved recipient.' );
		$clear();
		$late_short_circuit_called = false;
		$attach( 'woocommerce_mail_callback_params', static function ( $args ) use ( $attach, &$late_short_circuit_called ) {
			$attach( 'wp_mail', static function ( $atts ) use ( $attach, &$late_short_circuit_called ) {
				$attach( 'pre_wp_mail', static function () use ( &$late_short_circuit_called ) { $late_short_circuit_called = true; return true; } );
				return $atts;
			}, PHP_INT_MAX );
			return $args;
		} );
		$assert( $is_error( YoRo_Orders_Email_Service::send_transactional_email( $make_params() + [ '_yoro_confirmed' => true ] ), 'transactional_email_outcome_unknown' ) && ! $late_short_circuit_called, 'A dynamically added short-circuit transport is blocked before it can send.' );
		$clear();
		$attach( 'pre_wp_mail', static function () { return true; } );
		$assert( $is_error( YoRo_Orders_Email_Service::send_transactional_email( $make_params() + [ '_yoro_confirmed' => true ] ), 'transactional_email_transport_unsupported' ), 'Short-circuit transport is rejected without claiming mailer success.' );
		$clear();
		$assert( ! has_filter( 'pre_wp_mail' ), 'Native action removes its temporary short-circuit guard.' );
		$assert( is_array( YoRo_Orders_Email_Service::send_transactional_email( $make_params() + [ '_yoro_confirmed' => true ] ) ), 'Temporary guard cleanup permits a later independently confirmed native send.' );
	} finally {
		$clear();
		if ( null === $original_settings ) { delete_option( $option_key ); } else { update_option( $option_key, $original_settings ); }
		$fixture->delete( true );
	}
}

wp_set_current_user( $previous_user_id );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo write-action preflight smoke contract passed.\n";
