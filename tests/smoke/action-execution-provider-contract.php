<?php
/**
 * YoRo action execution provider substrate smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/action-execution-provider-contract.php
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

foreach (
	[
		'YoRo_Action_Execution_Provider',
		'YoRo_Local_Action_Execution_Provider',
		'YoRo_Native_Ability_Execution_Provider',
		'YoRo_Action_Execution_Router',
	] as $required_class
) {
	$loaded = 'YoRo_Action_Execution_Provider' === $required_class
		? interface_exists( $required_class )
		: class_exists( $required_class );
	$assert( $loaded, sprintf( '%s must be loaded by the Core bootstrap.', $required_class ) );
}

class YoRo_YR0005_Test_Ability {

	public $executions = 0;
	private $result;

	public function __construct( $result ) {
		$this->result = $result;
	}

	public function execute( $params ) {
		$this->executions++;

		return $this->result;
	}
}

class YoRo_YR0005_Test_Native_Provider extends YoRo_Native_Ability_Execution_Provider {

	public $ability_resolutions = 0;
	private $test_map;
	private $api_available;
	private $ability;
	private $covered;

	public function __construct( array $test_map, $api_available, $ability, $covered ) {
		$this->test_map = $test_map;
		$this->api_available = (bool) $api_available;
		$this->ability = $ability;
		$this->covered = (bool) $covered;
	}

	protected function get_candidate_map() {
		return $this->test_map;
	}

	protected function abilities_api_is_available() {
		return $this->api_available;
	}

	protected function resolve_ability( $ability_id ) {
		$this->ability_resolutions++;

		return $this->ability;
	}

	protected function request_is_covered( $coverage_predicate, $params, array $action, array $context ) {
		return $this->covered;
	}

	protected function assess_ability_contract( array $mapping, $ability ) {
		return [
			'accepted'    => true,
			'status_enum' => [ 'pending', 'processing', 'completed' ],
		];
	}

	protected function adapt_input( $action_name, $params ) {
		return is_array( $params ) ? $params : [];
	}

	protected function adapt_output( $action_name, $native_result, $params ) {
		return $native_result;
	}
}

class YoRo_YR0005_Test_Provider implements YoRo_Action_Execution_Provider {

	public $assessments = 0;
	public $executions = 0;
	private $kind;
	private $eligible;
	private $reason;
	private $result;

	public function __construct( $kind, $eligible, $reason, $result ) {
		$this->kind = (string) $kind;
		$this->eligible = (bool) $eligible;
		$this->reason = (string) $reason;
		$this->result = $result;
	}

	public function provider_kind() {
		return $this->kind;
	}

	public function assess( $action_name, array $action, $params = [], $context = [] ) {
		$this->assessments++;

		return [
			'eligible' => $this->eligible,
			'reason'   => $this->reason,
		];
	}

	public function execute( $action_name, array $action, $params = [], $context = [], array $decision = [] ) {
		$this->executions++;

		return $this->result;
	}
}

$expected_map = [
	'products.search'              => [ 'woocommerce/products-query', 'B', 'products_search_subset', false ],
	'products.get_details'         => [ 'woocommerce/products-query', 'B', 'single_supported_product', true ],
	'orders.search'                => [ 'woocommerce/orders-query', 'B', 'orders_search_subset', true ],
	'orders.get_details'           => [ 'woocommerce/orders-query', 'B', 'single_order', true ],
	'orders.update_status'         => [ 'woocommerce/order-update-status', 'C', 'single_versioned_order_status_write', true ],
	'orders.add_note'              => [ 'woocommerce/order-add-note', 'C', 'single_provenance_preserving_order_note', false ],
	'products.update_stock'        => [ 'woocommerce/product-update', 'C', 'single_simple_versioned_stock_write', true ],
	'products.update_stock_status' => [ 'woocommerce/product-update', 'C', 'single_simple_versioned_stock_status_write', true ],
];
$candidate_map = YoRo_Native_Ability_Execution_Provider::candidate_map();
$assert( array_keys( $expected_map ) === array_keys( $candidate_map ), 'The production native candidate map must contain only the eight reviewed YoRo actions.' );

foreach ( $expected_map as $action_name => $expected_mapping ) {
	$mapping = $candidate_map[ $action_name ] ?? [];
	$assert( $expected_mapping[0] === ( $mapping['ability_id'] ?? '' ), sprintf( '%s must map to the exact reviewed WooCommerce ability.', $action_name ) );
	$assert( $expected_mapping[1] === ( $mapping['candidate_tranche'] ?? '' ), sprintf( '%s must retain its reviewed future tranche.', $action_name ) );
	$assert( $expected_mapping[2] === ( $mapping['coverage_predicate'] ?? '' ), sprintf( '%s must retain its explicit coverage predicate.', $action_name ) );
	$assert( array_key_exists( 'enabled', $mapping ) && $expected_mapping[3] === $mapping['enabled'], sprintf( '%s must retain its reviewed YR-0016 activation state.', $action_name ) );
}

$registry_before = wp_json_encode( YoRo_Action_Registry::all() );
$catalog_before = wp_json_encode( YoRo_Action_Catalog_Builder::build() );
YoRo_Native_Ability_Execution_Provider::candidate_map();
$registry_after = wp_json_encode( YoRo_Action_Registry::all() );
$catalog_after = wp_json_encode( YoRo_Action_Catalog_Builder::build() );
$assert( $registry_before === $registry_after, 'Reading the internal native map must not mutate the semantic action registry.' );
$assert( $catalog_before === $catalog_after, 'Reading the internal native map must not mutate the action registry or action catalog.' );
$assert( false === strpos( $catalog_after, 'woocommerce/products-query' ), 'Native ability IDs must not enter the public action catalog.' );

$production_native = new YoRo_Native_Ability_Execution_Provider();
$dummy_action = [
	'callback' => static function() {
		return [ 'success' => true ];
	},
];
$missing_mapping = $production_native->assess( 'site.get_info', $dummy_action );
$assert( 'native_mapping_missing' === ( $missing_mapping['reason'] ?? '' ), 'Provider selection must reject an unmapped semantic action before any capability lookup.' );

foreach ( [ 'products.search', 'orders.add_note' ] as $inactive_action ) {
	$inactive_mapping = $production_native->assess( $inactive_action, $dummy_action );
	$assert( 'native_mapping_inactive' === ( $inactive_mapping['reason'] ?? '' ), sprintf( '%s must remain inactive before any capability lookup.', $inactive_action ) );
}

$active_map = [
	'test.native' => [
		'ability_id'         => 'woocommerce/test-native',
		'candidate_tranche'  => 'TEST_ONLY',
		'coverage_predicate' => 'test_only',
		'enabled'            => true,
	],
];
$inactive_test_map = $active_map;
$inactive_test_map['test.native']['enabled'] = false;
$inactive_test_native = new YoRo_YR0005_Test_Native_Provider( $inactive_test_map, false, null, false );
$inactive_test_decision = $inactive_test_native->assess( 'test.native', $dummy_action );
$assert( 'native_mapping_inactive' === ( $inactive_test_decision['reason'] ?? '' ), 'Mapping inactivity must precede Abilities API detection.' );
$assert( 0 === $inactive_test_native->ability_resolutions, 'An inactive mapping must not resolve an ability.' );

$api_missing_native = new YoRo_YR0005_Test_Native_Provider( $active_map, false, null, false );
$api_missing_decision = $api_missing_native->assess( 'test.native', $dummy_action );
$assert( 'native_api_unavailable' === ( $api_missing_decision['reason'] ?? '' ), 'An enabled test-only mapping without the Abilities API must fall back before lookup.' );
$assert( 0 === $api_missing_native->ability_resolutions, 'A missing Abilities API must not resolve an ability.' );

$ability_missing_native = new YoRo_YR0005_Test_Native_Provider( $active_map, true, null, false );
$ability_missing_decision = $ability_missing_native->assess( 'test.native', $dummy_action );
$assert( 'native_ability_unavailable' === ( $ability_missing_decision['reason'] ?? '' ), 'A missing exact mapped ability must be rejected before request coverage.' );
$assert( 1 === $ability_missing_native->ability_resolutions, 'Only the exact mapped ability may be resolved once.' );

$unexecuted_ability = new YoRo_YR0005_Test_Ability( [ 'success' => true ] );
$coverage_native = new YoRo_YR0005_Test_Native_Provider( $active_map, true, $unexecuted_ability, false );
$coverage_decision = $coverage_native->assess( 'test.native', $dummy_action );
$assert( 'request_outside_native_coverage' === ( $coverage_decision['reason'] ?? '' ), 'Request coverage must be decided before native execution.' );
$assert( 0 === $unexecuted_ability->executions, 'A coverage rejection must not execute the native primitive.' );

$local_fallback = new YoRo_YR0005_Test_Provider( 'local', true, 'local_default', [ 'success' => true, 'source' => 'local' ] );
$coverage_route = ( new YoRo_Action_Execution_Router( [ $coverage_native, $local_fallback ] ) )->execute( 'test.native', $dummy_action );
$assert( 1 === $local_fallback->executions, 'A pre-execution coverage rejection must select the local provider exactly once.' );
$assert( 0 === $unexecuted_ability->executions, 'Local selection after coverage rejection must not execute native.' );
$assert( 'local' === ( $coverage_route['receipt']['provider_kind'] ?? '' ), 'The internal receipt must record the selected local provider.' );
$assert( 'request_outside_native_coverage' === ( $coverage_route['receipt']['selection_reason'] ?? '' ), 'The internal receipt must preserve the exact pre-execution fallback reason.' );

$native_success_ability = new YoRo_YR0005_Test_Ability( [ 'success' => true, 'source' => 'native' ] );
$native_success = new YoRo_YR0005_Test_Native_Provider( $active_map, true, $native_success_ability, true );
$unused_local = new YoRo_YR0005_Test_Provider( 'local', true, 'local_default', [ 'success' => true, 'source' => 'local' ] );
$native_route = ( new YoRo_Action_Execution_Router( [ $native_success, $unused_local ] ) )->execute( 'test.native', $dummy_action );
$assert( 1 === $native_success_ability->executions, 'An eligible test-only native ability must execute exactly once.' );
$assert( 0 === $unused_local->executions, 'A selected native execution must not also execute local.' );
$assert( 'native_ability' === ( $native_route['receipt']['provider_kind'] ?? '' ), 'The internal receipt must record native selection.' );
$assert( 'native_selected' === ( $native_route['receipt']['selection_reason'] ?? '' ), 'The internal receipt must record the exact native selection reason.' );
$assert( 'woocommerce/test-native' === ( $native_route['receipt']['ability_id'] ?? '' ), 'The internal receipt must retain only the exact selected ability ID.' );

foreach ( [ 'native_permission_denied', 'native_validation_failed', 'native_domain_failed', 'native_unknown_failed' ] as $native_error_code ) {
	$native_error_ability = new YoRo_YR0005_Test_Ability( new WP_Error( $native_error_code, 'Test-only native error.' ) );
	$native_error_provider = new YoRo_YR0005_Test_Native_Provider( $active_map, true, $native_error_ability, true );
	$forbidden_local = new YoRo_YR0005_Test_Provider( 'local', true, 'local_default', [ 'success' => true ] );
	$native_error_route = ( new YoRo_Action_Execution_Router( [ $native_error_provider, $forbidden_local ] ) )->execute( 'test.native', $dummy_action );
	$native_error_result = $native_error_route['result'] ?? null;
	$assert( is_wp_error( $native_error_result ), sprintf( '%s must remain a terminal native error.', $native_error_code ) );
	$assert( $native_error_code === ( is_wp_error( $native_error_result ) ? $native_error_result->get_error_code() : '' ), sprintf( '%s must retain its exact error identity.', $native_error_code ) );
	$assert( 1 === $native_error_ability->executions, sprintf( '%s must execute the native primitive exactly once.', $native_error_code ) );
	$assert( 0 === $forbidden_local->executions, sprintf( '%s must never retry through local.', $native_error_code ) );
}

$first_primary = new YoRo_YR0005_Test_Provider( 'native_ability', true, 'native_selected', [ 'success' => true ] );
$second_primary = new YoRo_YR0005_Test_Provider( 'composite', true, 'composite_selected', [ 'success' => true ] );
$ambiguous_route = ( new YoRo_Action_Execution_Router( [ $first_primary, $second_primary, new YoRo_Local_Action_Execution_Provider() ] ) )->execute( 'test.native', $dummy_action );
$assert( is_wp_error( $ambiguous_route['result'] ?? null ) && 'yoro_ambiguous_execution_provider' === $ambiguous_route['result']->get_error_code(), 'Ambiguous primary providers must fail closed.' );
$assert( 0 === $first_primary->executions && 0 === $second_primary->executions, 'An ambiguous route must execute no primitive.' );

$unavailable_route = ( new YoRo_Action_Execution_Router( [ new YoRo_YR0005_Test_Provider( 'native_ability', false, 'not_available', null ) ] ) )->execute( 'test.native', $dummy_action );
$assert( is_wp_error( $unavailable_route['result'] ?? null ) && 'yoro_execution_provider_unavailable' === $unavailable_route['result']->get_error_code(), 'A route without one eligible provider must fail closed.' );
$invalid_kind_provider = new YoRo_YR0005_Test_Provider( 'runtime_injected', true, 'injected', [ 'success' => true ] );
$invalid_kind_route = ( new YoRo_Action_Execution_Router( [ $invalid_kind_provider ] ) )->execute( 'test.native', $dummy_action );
$assert( is_wp_error( $invalid_kind_route['result'] ?? null ) && 'yoro_invalid_execution_provider_kind' === $invalid_kind_route['result']->get_error_code(), 'An unreviewed provider kind must fail closed.' );
$assert( 0 === $invalid_kind_provider->assessments && 0 === $invalid_kind_provider->executions, 'An unreviewed provider kind must be rejected before assessment or execution.' );

$admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ] );
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;
$assert( $admin_id > 0, 'An administrator is required for provider execution gate checks.' );
$original_user_id = get_current_user_id();

if ( $admin_id > 0 ) {
	wp_set_current_user( $admin_id );
	$callback_calls = 0;
	YoRo_Action_Registry::register(
		'test.yr0005.local',
		[
			'label'        => 'YR-0005 local provider test',
			'write_action' => false,
			'ai_exposed'   => false,
			'max_items'    => 1,
			'callback'     => static function() use ( &$callback_calls ) {
				$callback_calls++;

				return [
					'success' => true,
					'data'    => [ 'provider_contract' => 'unchanged' ],
					'meta'    => [ 'existing' => 'preserved' ],
				];
			},
		]
	);
	$direct_result = YoRo_Action_Executor::execute( 'test.yr0005.local' );
	$direct_json = wp_json_encode( $direct_result );
	$assert( 1 === $callback_calls, 'The shared action executor must invoke the selected local callback exactly once.' );
	$assert( 'unchanged' === ( $direct_result['data']['provider_contract'] ?? '' ), 'Local callback data must retain its existing normalization.' );
	$assert( 'preserved' === ( $direct_result['meta']['existing'] ?? '' ), 'Existing callback meta must remain unchanged.' );
	foreach ( [ 'execution_provider', 'provider_kind', 'selection_reason', 'candidate_tranche', 'native_mapping_' ] as $private_receipt_key ) {
		$assert( false === strpos( $direct_json, $private_receipt_key ), sprintf( 'Direct action results must not expose internal provider receipt field %s.', $private_receipt_key ) );
	}
	$legacy_context_result = YoRo_Action_Executor::execute( 'test.yr0005.local', [], false, false, 'legacy_non_array_context' );
	$assert( ! empty( $legacy_context_result['success'] ), 'A legacy non-array execution context must retain the existing local callback behavior.' );

	YoRo_Action_Registry::register(
		'test.yr0005.error',
		[
			'label'        => 'YR-0005 local error test',
			'write_action' => false,
			'ai_exposed'   => false,
			'callback'     => static function() {
				return new WP_Error( 'yr0005_local_error', 'Expected local provider error.' );
			},
		]
	);
	$local_error_result = YoRo_Action_Executor::execute( 'test.yr0005.error' );
	$assert( empty( $local_error_result['success'] ), 'Local WP_Error normalization must remain unsuccessful.' );
	$assert( 'yr0005_local_error' === ( $local_error_result['error']['code'] ?? '' ), 'Local WP_Error normalization must retain the exact error code.' );
	$assert( false === strpos( wp_json_encode( $local_error_result ), 'provider_kind' ), 'Normalized local errors must not expose the internal provider receipt.' );

	$store_context_pack = [
		'profile_intelligence' => [
			'schema_version' => 1,
			'test_marker'    => 'yr0005-store-context',
		],
	];
	YoRo_Action_Registry::register(
		'test.yr0005.store_context',
		[
			'label'        => 'YR-0005 store context test',
			'write_action' => false,
			'ai_exposed'   => false,
			'callback'     => static function() {
				$current_context = new ReflectionMethod( 'YoRo_Report_Builder', 'current_store_context_pack' );
				$current_context->setAccessible( true );

				return [
					'success' => true,
					'data'    => [ 'store_context_pack' => $current_context->invoke( null ) ],
				];
			},
		]
	);
	$store_context_result = YoRo_Action_Executor::execute(
		'test.yr0005.store_context',
		[],
		false,
		false,
		[ 'store_context_pack' => $store_context_pack ]
	);
	$assert( $store_context_pack === ( $store_context_result['data']['store_context_pack'] ?? [] ), 'The local provider must preserve the existing report store-context wrapper.' );

	$callback_calls = 0;
	YoRo_Action_Executor::execute( 'test.yr0005.local', [ 'limit' => 2 ] );
	$assert( 0 === $callback_calls, 'Target-limit rejection must occur before provider execution.' );
	YoRo_Action_Executor::execute( 'test.yr0005.local', [], false, true );
	$assert( 0 === $callback_calls, 'Preview must return before provider execution.' );

	$write_calls = 0;
	YoRo_Action_Registry::register(
		'test.yr0005.write',
		[
			'label'        => 'YR-0005 write provider test',
			'write_action' => true,
			'ai_exposed'   => false,
			'callback'     => static function() use ( &$write_calls ) {
				$write_calls++;

				return [ 'success' => true ];
			},
		]
	);
	$confirmation_result = YoRo_Action_Executor::execute( 'test.yr0005.write' );
	$assert( 0 === $write_calls, 'Confirmation rejection must occur before provider execution.' );
	$assert( ! empty( $confirmation_result['requires_confirmation'] ), 'The existing confirmation result must remain unchanged.' );

	wp_set_current_user( 0 );
	YoRo_Action_Executor::execute( 'test.yr0005.local' );
	$assert( 0 === $callback_calls, 'Permission rejection must occur before provider execution.' );
	wp_set_current_user( $admin_id );
}

wp_set_current_user( $original_user_id );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo action execution provider substrate smoke contract passed.\n";
