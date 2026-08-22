<?php
/**
 * YoRo native read ability parity and fail-closed smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file /path/to/yoro-ai-core/tests/smoke/native-ability-read-parity-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( class_exists( 'YoRo_AI_Core' ) && ! class_exists( 'YoRo_Action_Execution_Router' ) ) {
	YoRo_AI_Core::init();
}

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$compatible_input_schema = [
	'type'       => 'object',
	'properties' => [
		'status'             => [ 'type' => 'string', 'enum' => [ 'pending', 'processing', 'completed' ] ],
		'customer_id'        => [ 'type' => 'integer', 'minimum' => 0 ],
		'billing_email'      => [ 'type' => 'string', 'format' => 'email' ],
		'orderby'            => [ 'type' => 'string', 'enum' => [ 'id', 'date', 'total' ] ],
		'order'              => [ 'type' => 'string', 'enum' => [ 'asc', 'desc' ] ],
		'include_line_items' => [ 'type' => 'boolean' ],
		'page'               => [ 'type' => 'integer', 'minimum' => 1 ],
		'per_page'           => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
	],
];
$compatible_output_schema = [
	'type'       => 'object',
	'properties' => [
		'orders'      => [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [ 'id' => [ 'type' => 'integer' ] ],
			],
		],
		'total_pages' => [ 'type' => 'integer' ],
		'page'        => [ 'type' => 'integer' ],
		'per_page'    => [ 'type' => 'integer' ],
	],
];

class YoRo_YR0007_Test_Ability {

	public $executions = 0;
	public $primitive_executions = 0;
	public $last_input = null;
	private $input_schema;
	private $output_schema;
	private $result;
	private $permission;
	private $throws;

	public function __construct( $input_schema, $output_schema, $result, $permission = true, $throws = false ) {
		$this->input_schema = $input_schema;
		$this->output_schema = $output_schema;
		$this->result = $result;
		$this->permission = $permission;
		$this->throws = (bool) $throws;
	}

	public function get_input_schema() {
		return $this->input_schema;
	}

	public function get_output_schema() {
		return $this->output_schema;
	}

	public function execute( $input ) {
		$this->executions++;
		$this->last_input = $input;

		if ( is_wp_error( $this->permission ) ) {
			return $this->permission;
		}

		if ( false === $this->permission ) {
			return new WP_Error( 'ability_permission_denied', 'Test-only native permission denial.' );
		}

		if ( $this->throws ) {
			throw new RuntimeException( 'Test-only native throwable.' );
		}

		$this->primitive_executions++;

		return $this->result;
	}
}

class YoRo_YR0007_Missing_Contract_Ability {

	public $executions = 0;

	public function execute( $input ) {
		$this->executions++;

		return [];
	}
}

class YoRo_YR0007_Test_Native_Provider extends YoRo_Native_Ability_Execution_Provider {

	public $ability_resolutions = 0;
	private $ability;
	private $api_available;
	private $enabled;

	public function __construct( $ability, $api_available = true, $enabled = true ) {
		$this->ability = $ability;
		$this->api_available = (bool) $api_available;
		$this->enabled = (bool) $enabled;
	}

	protected function get_candidate_map() {
		return [
			'orders.search' => [
				'ability_id'         => 'woocommerce/orders-query',
				'candidate_tranche'  => 'B',
				'coverage_predicate' => 'orders_search_subset',
				'enabled'            => $this->enabled,
			],
		];
	}

	protected function abilities_api_is_available() {
		return $this->api_available;
	}

	protected function resolve_ability( $ability_id ) {
		$this->ability_resolutions++;

		return $this->ability;
	}
}

class YoRo_YR0007_Counting_Local_Provider implements YoRo_Action_Execution_Provider {

	public $executions = 0;
	private $result;

	public function __construct( $result = null ) {
		$this->result = null === $result ? [ 'success' => true, 'source' => 'local' ] : $result;
	}

	public function provider_kind() {
		return 'local';
	}

	public function assess( $action_name, array $action, $params = [], $context = [] ) {
		return [ 'eligible' => true, 'reason' => 'local_default' ];
	}

	public function execute( $action_name, array $action, $params = [], $context = [], array $decision = [] ) {
		$this->executions++;

		return $this->result;
	}
}

$dummy_action = [
	'callback' => static function () {
		return [ 'success' => true ];
	},
];
$empty_native_result = [
	'orders'      => [],
	'total_pages' => 0,
	'page'        => 1,
	'per_page'    => 5,
];

$compatible_ability = new YoRo_YR0007_Test_Ability( $compatible_input_schema, $compatible_output_schema, $empty_native_result );
$compatible_provider = new YoRo_YR0007_Test_Native_Provider( $compatible_ability );
$compatible_params = [
	'limit'             => 5,
	'status'            => 'pending',
	'email'             => 'parity@example.test',
	'customer_id'       => 7,
	'response_language' => 'en',
	'original_command'  => 'Show pending orders',
	'_yoro_confirmed'   => false,
	'_yoro_preview'     => false,
];
$compatible_decision = $compatible_provider->assess( 'orders.search', $dummy_action, $compatible_params );
$assert( ! empty( $compatible_decision['eligible'] ), 'A compatible orders-query contract and covered request must select native.' );
$compatible_result = $compatible_provider->execute( 'orders.search', $dummy_action, $compatible_params, [], $compatible_decision );
$assert( ! is_wp_error( $compatible_result ) && ! empty( $compatible_result['success'] ), 'A compatible empty native result must normalize through the YoRo search formatter.' );
$assert(
	[
		'page'               => 1,
		'per_page'           => 5,
		'orderby'            => 'date',
		'order'              => 'desc',
		'include_line_items' => false,
		'status'             => 'pending',
		'billing_email'      => 'parity@example.test',
		'customer_id'        => 7,
	] === $compatible_ability->last_input,
	'Native input adaptation must forward only the exact reviewed fields and no YoRo control metadata.'
);

$additive_input_schema = $compatible_input_schema;
$additive_input_schema['properties']['future_filter'] = [ 'type' => 'string' ];
$additive_output_schema = $compatible_output_schema;
$additive_output_schema['properties']['future_metadata'] = [ 'type' => 'string' ];
$additive_result = array_merge( $empty_native_result, [ 'future_metadata' => 'internal-only' ] );
$additive_ability = new YoRo_YR0007_Test_Ability( $additive_input_schema, $additive_output_schema, $additive_result );
$additive_provider = new YoRo_YR0007_Test_Native_Provider( $additive_ability );
$additive_decision = $additive_provider->assess( 'orders.search', $dummy_action, [ 'limit' => 5 ] );
$assert( ! empty( $additive_decision['eligible'] ), 'Additive compatible schema evolution must remain eligible without an exact-version fingerprint allowlist.' );
$additive_normalized_result = $additive_provider->execute( 'orders.search', $dummy_action, [ 'limit' => 5 ], [], $additive_decision );
$assert( ! is_wp_error( $additive_normalized_result ) && false === strpos( wp_json_encode( $additive_normalized_result ), 'future_metadata' ), 'Declared additive native output may remain eligible but must be ignored by public YoRo normalization.' );

$missing_contract_ability = new YoRo_YR0007_Missing_Contract_Ability();
$missing_contract_provider = new YoRo_YR0007_Test_Native_Provider( $missing_contract_ability );
$missing_contract_decision = $missing_contract_provider->assess( 'orders.search', $dummy_action, [ 'limit' => 5 ] );
$assert( 'native_ability_contract_incompatible' === ( $missing_contract_decision['reason'] ?? '' ), 'An ability ID without readable schemas must select local before execution.' );
$assert( 0 === $missing_contract_ability->executions, 'A missing ability contract must not execute the native primitive.' );

$incompatible_input = $compatible_input_schema;
$incompatible_input['properties']['per_page']['maximum'] = 10;
$incompatible_ability = new YoRo_YR0007_Test_Ability( $incompatible_input, $compatible_output_schema, $empty_native_result );
$incompatible_provider = new YoRo_YR0007_Test_Native_Provider( $incompatible_ability );
$incompatible_decision = $incompatible_provider->assess( 'orders.search', $dummy_action, [ 'limit' => 5 ] );
$assert( 'native_ability_contract_incompatible' === ( $incompatible_decision['reason'] ?? '' ), 'An orders-query contract that cannot accept the YoRo maximum limit must remain local.' );
$assert( 0 === $incompatible_ability->executions, 'An incompatible ability contract must not execute.' );

$incompatible_output = $compatible_output_schema;
$incompatible_output['properties']['orders']['items']['properties']['id']['type'] = 'string';
$incompatible_output_ability = new YoRo_YR0007_Test_Ability( $compatible_input_schema, $incompatible_output, $empty_native_result );
$incompatible_output_decision = ( new YoRo_YR0007_Test_Native_Provider( $incompatible_output_ability ) )->assess( 'orders.search', $dummy_action, [ 'limit' => 5 ] );
$assert( 'native_ability_contract_incompatible' === ( $incompatible_output_decision['reason'] ?? '' ), 'An orders-query output contract without integer order IDs must remain local.' );

$incompatible_status_input = $compatible_input_schema;
$incompatible_status_input['properties']['status']['enum'] = [ 'wc-pending' ];
$incompatible_status_ability = new YoRo_YR0007_Test_Ability( $incompatible_status_input, $compatible_output_schema, $empty_native_result );
$incompatible_status_decision = ( new YoRo_YR0007_Test_Native_Provider( $incompatible_status_ability ) )->assess( 'orders.search', $dummy_action, [ 'status' => 'pending', 'limit' => 5 ] );
$assert( 'request_outside_native_coverage' === ( $incompatible_status_decision['reason'] ?? '' ), 'A request status outside the accepted live ability enum must remain local before execution.' );

$api_missing_provider = new YoRo_YR0007_Test_Native_Provider( $compatible_ability, false );
$api_missing_decision = $api_missing_provider->assess( 'orders.search', $dummy_action, [ 'limit' => 5 ] );
$assert( 'native_api_unavailable' === ( $api_missing_decision['reason'] ?? '' ), 'A no-Abilities environment must select local before resolution.' );
$assert( 0 === $api_missing_provider->ability_resolutions, 'A no-Abilities environment must not resolve an ability.' );

$unsupported_cases = [
	[ 'status' => [ 'pending' ] ],
	[ 'status' => 'not-a-real-status' ],
	[ 'email' => 'invalid-email' ],
	[ 'customer_id' => 0 ],
	[ 'order_id' => 123 ],
	[ 'phone' => '555' ],
	[ 'payment_method' => 'cod' ],
	[ 'date_window' => 'today' ],
	[ 'time_window' => [ 'canonical_key' => 'today' ] ],
	[ 'date_from' => '2026-08-01' ],
	[ 'date_to' => '2026-08-22' ],
	[ 'min_age_hours' => 24 ],
	[ 'customer_name' => 'Ada' ],
	[ 'billing_country' => 'US' ],
	[ 'shipping_state' => 'CA' ],
	[ 'total_min' => 10 ],
	[ 'total_max' => 100 ],
];

foreach ( $unsupported_cases as $unsupported_params ) {
	$ability = new YoRo_YR0007_Test_Ability( $compatible_input_schema, $compatible_output_schema, $empty_native_result );
	$provider = new YoRo_YR0007_Test_Native_Provider( $ability );
	$decision = $provider->assess( 'orders.search', $dummy_action, array_merge( [ 'limit' => 5 ], $unsupported_params ) );
	$assert( 'request_outside_native_coverage' === ( $decision['reason'] ?? '' ), sprintf( 'Unsupported params must select local before execution: %s', wp_json_encode( $unsupported_params ) ) );
	$assert( 0 === $ability->executions, 'An outside-coverage request must not execute native.' );
}

$fallback_local = new YoRo_YR0007_Counting_Local_Provider();
$fallback_route = ( new YoRo_Action_Execution_Router( [ $missing_contract_provider, $fallback_local ] ) )->execute( 'orders.search', $dummy_action, [ 'limit' => 5 ] );
$assert( 1 === $fallback_local->executions, 'Missing/incompatible ability contract must select local exactly once.' );
$assert( 'local' === ( $fallback_route['receipt']['provider_kind'] ?? '' ), 'Pre-execution contract rejection must produce an internal local receipt.' );

$terminal_errors = [
	new WP_Error( 'ability_permission_denied', 'Denied.' ),
	new WP_Error( 'ability_invalid_input', 'Invalid input.' ),
	new WP_Error( 'woocommerce_domain_error', 'Domain error.' ),
];

foreach ( $terminal_errors as $terminal_error ) {
	$ability = new YoRo_YR0007_Test_Ability( $compatible_input_schema, $compatible_output_schema, $terminal_error );
	$native = new YoRo_YR0007_Test_Native_Provider( $ability );
	$local = new YoRo_YR0007_Counting_Local_Provider();
	$route = ( new YoRo_Action_Execution_Router( [ $native, $local ] ) )->execute( 'orders.search', $dummy_action, [ 'limit' => 5 ] );
	$result = $route['result'] ?? null;
	$assert( is_wp_error( $result ) && $terminal_error->get_error_code() === $result->get_error_code(), 'A native permission/validation/domain error must retain its exact terminal identity.' );
	$assert( 0 === $local->executions, 'A native permission/validation/domain error must never retry local.' );
}

$malformed_results = [
	[ 'orders' => [], 'total_pages' => 0, 'page' => 2, 'per_page' => 5 ],
	[ 'orders' => [], 'total_pages' => 0, 'page' => 1, 'per_page' => 4 ],
	[ 'orders' => [ [ 'id' => '123' ] ], 'total_pages' => 1, 'page' => 1, 'per_page' => 5 ],
	[ 'orders' => [ [ 'id' => 987654321 ] ], 'total_pages' => 1, 'page' => 1, 'per_page' => 5 ],
	[ 'orders' => [], 'total_pages' => 0, 'page' => 1, 'per_page' => 5, 'provider' => 'leak' ],
];

foreach ( $malformed_results as $malformed_result ) {
	$ability = new YoRo_YR0007_Test_Ability( $compatible_input_schema, $compatible_output_schema, $malformed_result );
	$native = new YoRo_YR0007_Test_Native_Provider( $ability );
	$local = new YoRo_YR0007_Counting_Local_Provider();
	$route = ( new YoRo_Action_Execution_Router( [ $native, $local ] ) )->execute( 'orders.search', $dummy_action, [ 'limit' => 5 ] );
	$result = $route['result'] ?? null;
	$assert( is_wp_error( $result ) && 'yoro_native_ability_invalid_result' === $result->get_error_code(), 'Malformed or stale native output must fail with the stable terminal YoRo error.' );
	$assert( 0 === $local->executions, 'Malformed or stale native output must never retry local.' );
}

$throwing_ability = new YoRo_YR0007_Test_Ability( $compatible_input_schema, $compatible_output_schema, $empty_native_result, true, true );
$throwing_native = new YoRo_YR0007_Test_Native_Provider( $throwing_ability );
$throwing_local = new YoRo_YR0007_Counting_Local_Provider();
$throwing_route = ( new YoRo_Action_Execution_Router( [ $throwing_native, $throwing_local ] ) )->execute( 'orders.search', $dummy_action, [ 'limit' => 5 ] );
$assert( is_wp_error( $throwing_route['result'] ?? null ) && 'yoro_native_ability_failed' === $throwing_route['result']->get_error_code(), 'A native throwable must become the stable terminal YoRo failure.' );
$assert( 0 === $throwing_local->executions, 'A native throwable must never retry local.' );

$candidate_map = YoRo_Native_Ability_Execution_Provider::candidate_map();
foreach ( [ 'products.search', 'products.get_details', 'orders.get_details', 'orders.update_status', 'orders.add_note', 'products.update_stock', 'products.update_stock_status' ] as $inactive_action ) {
	$assert( empty( $candidate_map[ $inactive_action ]['enabled'] ), sprintf( '%s must remain inactive/local in P3-002/B.', $inactive_action ) );
}
$assert( ! empty( $candidate_map['orders.search']['enabled'] ), 'Only orders.search may be native-enabled in P3-002/B.' );

$original_user_id = get_current_user_id();
$admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ] );
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;
$created_orders = [];

if ( $admin_id > 0 && function_exists( 'wc_create_order' ) && function_exists( 'wp_get_ability' ) ) {
	wp_set_current_user( $admin_id );
	$short_circuit_fixture_mail = static function () {
		return true;
	};
	add_filter( 'pre_wp_mail', $short_circuit_fixture_mail );
	$ability = wp_get_ability( 'woocommerce/orders-query' );
	$production_provider = new YoRo_Native_Ability_Execution_Provider();
	$unique_email = 'yr0007-' . wp_generate_password( 10, false, false ) . '@example.test';
	$fixture_statuses = [ 'pending', 'processing', 'completed' ];

	foreach ( $fixture_statuses as $index => $status ) {
		$order = wc_create_order( [ 'customer_id' => $admin_id ] );

		if ( is_wp_error( $order ) ) {
			$failures[] = 'Disposable WooCommerce order fixture could not be created.';
			break;
		}

		$order->set_billing_email( $unique_email );
		$order->set_status( $status );
		$order->set_total( (string) ( 20 + $index ) );
		$order->set_date_created( time() - ( $index * HOUR_IN_SECONDS ) );
		$order->save();
		$created_orders[] = $order;
	}

	if ( is_object( $ability ) && count( $created_orders ) === count( $fixture_statuses ) ) {
		$parity_cases = [
			[ 'email' => $unique_email, 'limit' => 3 ],
			[ 'email' => $unique_email, 'limit' => 1 ],
			[ 'email' => $unique_email, 'status' => 'pending', 'limit' => 3 ],
			[ 'email' => $unique_email, 'customer_id' => $admin_id, 'limit' => 3 ],
			[ 'email' => 'empty-' . $unique_email, 'limit' => 3 ],
		];

		foreach ( $parity_cases as $parity_params ) {
			$local_result = YoRo_Orders_Read_Service::search_orders( $parity_params );
			$decision = $production_provider->assess( 'orders.search', $dummy_action, $parity_params );
			$assert( ! empty( $decision['eligible'] ), sprintf( 'The inspected live orders-query contract must accept parity case %s.', wp_json_encode( $parity_params ) ) );

			if ( ! empty( $decision['eligible'] ) ) {
				$native_result = $production_provider->execute( 'orders.search', $dummy_action, $parity_params, [], $decision );
				$assert( $local_result === $native_result, sprintf( 'Native/local direct YoRo results must be identical for %s.', wp_json_encode( $parity_params ) ) );
				$assert(
					YoRo_Commerce_Contract_Builder::canonical_execution_payload( $local_result, 'orders.search' )
					=== YoRo_Commerce_Contract_Builder::canonical_execution_payload( $native_result, 'orders.search' ),
					sprintf( 'Native/local canonical connector payloads must be identical for %s.', wp_json_encode( $parity_params ) )
				);
				$assert( false === strpos( wp_json_encode( $native_result ), 'woocommerce/orders-query' ), 'Native ability identity must not leak into direct YoRo results.' );
			}
		}

		$local_callback_calls = 0;
		$native_primitive_results = 0;
		$original_action = YoRo_Action_Registry::get( 'orders.search' );
		$permission_action = $original_action;
		$permission_action['callback'] = static function () use ( &$local_callback_calls ) {
			$local_callback_calls++;

			return [ 'success' => true ];
		};
		YoRo_Action_Registry::register( 'orders.search', $permission_action );

		$deny_native = static function ( $permission, $ability_name ) {
			if ( 'woocommerce/orders-query' === $ability_name ) {
				return false;
			}

			return $permission;
		};
		$count_native_primitive = static function ( $result, $ability_name ) use ( &$native_primitive_results ) {
			if ( 'woocommerce/orders-query' === $ability_name ) {
				$native_primitive_results++;
			}

			return $result;
		};
		add_filter( 'wp_ability_permission_result', $deny_native, 10, 2 );
		add_filter( 'wp_ability_execute_result', $count_native_primitive, 10, 2 );
		$permission_result = YoRo_Action_Executor::execute( 'orders.search', [ 'email' => $unique_email, 'limit' => 1 ] );
		remove_filter( 'wp_ability_permission_result', $deny_native, 10 );
		remove_filter( 'wp_ability_execute_result', $count_native_primitive, 10 );
		$assert( empty( $permission_result['success'] ) && 'ability_invalid_permissions' === ( $permission_result['error']['code'] ?? '' ), 'Native permission denial must remain the terminal normalized outcome after the outer YoRo gate passes.' );
		$assert( 0 === $native_primitive_results, 'Native primitive callback must not run when the native permission callback denies.' );
		$assert( 0 === $local_callback_calls, 'Native permission denial must execute the local callback zero times.' );
	} else {
		$failures[] = 'The exact woocommerce/orders-query ability is required for live differential parity fixtures.';
	}

	remove_filter( 'pre_wp_mail', $short_circuit_fixture_mail );
} else {
	$failures[] = 'An administrator, WooCommerce, and the Abilities API are required for native parity fixtures.';
}

foreach ( $created_orders as $created_order ) {
	if ( $created_order instanceof WC_Order ) {
		$created_order->delete( true );
	}
}

wp_set_current_user( $original_user_id );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo native ability read parity smoke contract passed.\n";
