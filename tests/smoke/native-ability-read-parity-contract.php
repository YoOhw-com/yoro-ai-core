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
$order_detail_input_schema = $compatible_input_schema;
$order_detail_input_schema['properties']['id'] = [ 'type' => 'integer', 'minimum' => 1 ];
$order_detail_output_schema = $compatible_output_schema;
$product_detail_input_schema = [
	'type'       => 'object',
	'properties' => [
		'id'       => [ 'type' => 'integer', 'minimum' => 1 ],
		'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
		'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
	],
];
$product_detail_output_schema = [
	'type'       => 'object',
	'properties' => [
		'products'    => [
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

class YoRo_YR0016_Recording_Ability {

	public $executions = 0;
	public $last_input = null;
	private $ability;

	public function __construct( $ability ) {
		$this->ability = $ability;
	}

	public function get_input_schema() {
		return $this->ability->get_input_schema();
	}

	public function get_output_schema() {
		return $this->ability->get_output_schema();
	}

	public function execute( $input ) {
		$this->executions++;
		$this->last_input = $input;

		return $this->ability->execute( $input );
	}
}

class YoRo_YR0007_Test_Native_Provider extends YoRo_Native_Ability_Execution_Provider {

	public $ability_resolutions = 0;
	private $ability;
	private $api_available;
	private $enabled;
	private $action_name;
	private $ability_id;
	private $coverage_predicate;

	public function __construct( $ability, $api_available = true, $enabled = true, $action_name = 'orders.search', $coverage_predicate = 'orders_search_subset', $ability_id = 'woocommerce/orders-query' ) {
		$this->ability = $ability;
		$this->api_available = (bool) $api_available;
		$this->enabled = (bool) $enabled;
		$this->action_name = (string) $action_name;
		$this->coverage_predicate = (string) $coverage_predicate;
		$this->ability_id = (string) $ability_id;
	}

	protected function get_candidate_map() {
		return [
			$this->action_name => [
				'ability_id'         => $this->ability_id,
				'candidate_tranche'  => 'B',
				'coverage_predicate' => $this->coverage_predicate,
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

$missing_order_detail_ability = new YoRo_YR0007_Test_Ability( $order_detail_input_schema, $order_detail_output_schema, [] );
$missing_order_detail_provider = new YoRo_YR0007_Test_Native_Provider(
	$missing_order_detail_ability,
	true,
	true,
	'orders.get_details',
	'single_order',
	'woocommerce/orders-query'
);
$missing_order_detail_decision = $missing_order_detail_provider->assess( 'orders.get_details', $dummy_action, [ 'order_ids' => [ 987654321 ] ] );
$assert( 'request_outside_native_coverage' === ( $missing_order_detail_decision['reason'] ?? '' ), 'A missing order must remain local before native execution.' );
$assert( 0 === $missing_order_detail_ability->executions, 'A missing order must execute no native primitive.' );

$api_missing_order_detail = new YoRo_YR0007_Test_Native_Provider(
	$missing_order_detail_ability,
	false,
	true,
	'orders.get_details',
	'single_order',
	'woocommerce/orders-query'
);
$api_missing_order_local = new YoRo_YR0007_Counting_Local_Provider();
$api_missing_order_route = ( new YoRo_Action_Execution_Router( [ $api_missing_order_detail, $api_missing_order_local ] ) )->execute( 'orders.get_details', $dummy_action, [ 'order_ids' => [ 123 ] ] );
$assert( 1 === $api_missing_order_local->executions, 'A no-Abilities order detail request must select local exactly once before native resolution.' );
$assert( 0 === $api_missing_order_detail->ability_resolutions, 'A no-Abilities order detail request must resolve no native ability.' );

$drifted_order_detail_input = $order_detail_input_schema;
$drifted_order_detail_input['properties']['id']['type'] = 'string';
$drifted_order_detail_ability = new YoRo_YR0007_Test_Ability( $drifted_order_detail_input, $order_detail_output_schema, [] );
$drifted_order_detail_provider = new YoRo_YR0007_Test_Native_Provider(
	$drifted_order_detail_ability,
	true,
	true,
	'orders.get_details',
	'single_order',
	'woocommerce/orders-query'
);
$drifted_order_detail_decision = $drifted_order_detail_provider->assess( 'orders.get_details', $dummy_action, [ 'order_ids' => [ 123 ] ] );
$assert( 'native_ability_contract_incompatible' === ( $drifted_order_detail_decision['reason'] ?? '' ), 'Order detail ID schema drift must reject native before execution.' );
$assert( 0 === $drifted_order_detail_ability->executions, 'Order detail schema drift must execute no native primitive.' );

$missing_line_items_input = $order_detail_input_schema;
unset( $missing_line_items_input['properties']['include_line_items'] );
$missing_line_items_ability = new YoRo_YR0007_Test_Ability( $missing_line_items_input, $order_detail_output_schema, [] );
$missing_line_items_provider = new YoRo_YR0007_Test_Native_Provider(
	$missing_line_items_ability,
	true,
	true,
	'orders.get_details',
	'single_order',
	'woocommerce/orders-query'
);
$missing_line_items_decision = $missing_line_items_provider->assess( 'orders.get_details', $dummy_action, [ 'order_ids' => [ 123 ] ] );
$assert( 'native_ability_contract_incompatible' === ( $missing_line_items_decision['reason'] ?? '' ), 'Order detail contract without include_line_items boolean must remain local.' );

$true_only_line_items_input = $order_detail_input_schema;
$true_only_line_items_input['properties']['include_line_items']['enum'] = [ true ];
$true_only_line_items_ability = new YoRo_YR0007_Test_Ability( $true_only_line_items_input, $order_detail_output_schema, [] );
$true_only_line_items_provider = new YoRo_YR0007_Test_Native_Provider(
	$true_only_line_items_ability,
	true,
	true,
	'orders.get_details',
	'single_order',
	'woocommerce/orders-query'
);
$true_only_line_items_decision = $true_only_line_items_provider->assess( 'orders.get_details', $dummy_action, [ 'order_ids' => [ 123 ] ] );
$assert( 'request_outside_native_coverage' === ( $true_only_line_items_decision['reason'] ?? '' ), 'An order detail schema that rejects include_line_items=false must select local before execution.' );
$assert( 0 === $true_only_line_items_ability->executions, 'An order detail schema that rejects the exact adapted input must execute no native primitive.' );

$drifted_order_detail_output = $order_detail_output_schema;
$drifted_order_detail_output['properties']['orders']['items']['properties']['id']['type'] = 'string';
$drifted_order_output_ability = new YoRo_YR0007_Test_Ability( $order_detail_input_schema, $drifted_order_detail_output, [] );
$drifted_order_output_provider = new YoRo_YR0007_Test_Native_Provider(
	$drifted_order_output_ability,
	true,
	true,
	'orders.get_details',
	'single_order',
	'woocommerce/orders-query'
);
$drifted_order_output_decision = $drifted_order_output_provider->assess( 'orders.get_details', $dummy_action, [ 'order_ids' => [ 123 ] ] );
$assert( 'native_ability_contract_incompatible' === ( $drifted_order_output_decision['reason'] ?? '' ), 'Order detail output ID schema drift must reject native before execution.' );
$assert( 0 === $drifted_order_output_ability->executions, 'Order detail output schema drift must execute no native primitive.' );

$drifted_order_pagination_output = $order_detail_output_schema;
$drifted_order_pagination_output['properties']['per_page']['enum'] = [ 2 ];
$drifted_order_pagination_ability = new YoRo_YR0007_Test_Ability( $order_detail_input_schema, $drifted_order_pagination_output, [] );
$drifted_order_pagination_provider = new YoRo_YR0007_Test_Native_Provider(
	$drifted_order_pagination_ability,
	true,
	true,
	'orders.get_details',
	'single_order',
	'woocommerce/orders-query'
);
$drifted_order_pagination_decision = $drifted_order_pagination_provider->assess( 'orders.get_details', $dummy_action, [ 'order_ids' => [ 123 ] ] );
$assert( 'native_ability_contract_incompatible' === ( $drifted_order_pagination_decision['reason'] ?? '' ), 'An order detail schema that cannot represent per_page=1 must reject native before execution.' );

$bounded_order_detail_input = $order_detail_input_schema;
$bounded_order_detail_input['properties']['id']['maximum'] = 10;
$bounded_order_detail_ability = new YoRo_YR0007_Test_Ability( $bounded_order_detail_input, $order_detail_output_schema, [] );
$bounded_order_detail_provider = new YoRo_YR0007_Test_Native_Provider(
	$bounded_order_detail_ability,
	true,
	true,
	'orders.get_details',
	'single_order',
	'woocommerce/orders-query'
);
$bounded_order_detail_decision = $bounded_order_detail_provider->assess( 'orders.get_details', $dummy_action, [ 'order_ids' => [ 123 ] ] );
$assert( 'request_outside_native_coverage' === ( $bounded_order_detail_decision['reason'] ?? '' ), 'A live ID schema that rejects the requested order ID must remain local before execution.' );

foreach (
	[
		[ 'order_ids' => [ 1, 2 ] ],
		[ 'order_ids' => [ '1' ] ],
		[ 'order_ids' => [ 0 ] ],
		[ 'order_ids' => [ -1 ] ],
		[ 'order_ids' => [ 1 ], 'status' => 'processing' ],
	] as $outside_order_detail_params
) {
	$outside_ability = new YoRo_YR0007_Test_Ability( $order_detail_input_schema, $order_detail_output_schema, [] );
	$outside_provider = new YoRo_YR0007_Test_Native_Provider(
		$outside_ability,
		true,
		true,
		'orders.get_details',
		'single_order',
		'woocommerce/orders-query'
	);
	$outside_decision = $outside_provider->assess( 'orders.get_details', $dummy_action, $outside_order_detail_params );
	$assert( 'request_outside_native_coverage' === ( $outside_decision['reason'] ?? '' ), sprintf( 'Non-canonical or multi-target order detail params must remain local: %s', wp_json_encode( $outside_order_detail_params ) ) );
	$assert( 0 === $outside_ability->executions, 'An outside-coverage order detail request must not execute native.' );
}

$drifted_product_detail_input = $product_detail_input_schema;
$drifted_product_detail_input['properties']['id']['type'] = 'string';
$drifted_product_detail_ability = new YoRo_YR0007_Test_Ability( $drifted_product_detail_input, $product_detail_output_schema, [] );
$drifted_product_detail_provider = new YoRo_YR0007_Test_Native_Provider(
	$drifted_product_detail_ability,
	true,
	true,
	'products.get_details',
	'single_supported_product',
	'woocommerce/products-query'
);
$drifted_product_detail_decision = $drifted_product_detail_provider->assess( 'products.get_details', $dummy_action, [ 'product_ids' => [ 123 ] ] );
$assert( 'native_ability_contract_incompatible' === ( $drifted_product_detail_decision['reason'] ?? '' ), 'Product detail ID schema drift must reject native before product preflight.' );
$assert( 0 === $drifted_product_detail_ability->executions, 'Product detail schema drift must execute no native primitive.' );

$drifted_product_detail_output = $product_detail_output_schema;
$drifted_product_detail_output['properties']['products']['items']['properties']['id']['type'] = 'string';
$drifted_product_output_ability = new YoRo_YR0007_Test_Ability( $product_detail_input_schema, $drifted_product_detail_output, [] );
$drifted_product_output_provider = new YoRo_YR0007_Test_Native_Provider(
	$drifted_product_output_ability,
	true,
	true,
	'products.get_details',
	'single_supported_product',
	'woocommerce/products-query'
);
$drifted_product_output_decision = $drifted_product_output_provider->assess( 'products.get_details', $dummy_action, [ 'product_ids' => [ 123 ] ] );
$assert( 'native_ability_contract_incompatible' === ( $drifted_product_output_decision['reason'] ?? '' ), 'Product detail output ID schema drift must reject native before product preflight.' );
$assert( 0 === $drifted_product_output_ability->executions, 'Product detail output schema drift must execute no native primitive.' );

$bounded_product_detail_output = $product_detail_output_schema;
$bounded_product_detail_output['properties']['products']['items']['properties']['id']['maximum'] = 10;
$bounded_product_output_ability = new YoRo_YR0007_Test_Ability( $product_detail_input_schema, $bounded_product_detail_output, [] );
$bounded_product_output_provider = new YoRo_YR0007_Test_Native_Provider(
	$bounded_product_output_ability,
	true,
	true,
	'products.get_details',
	'single_supported_product',
	'woocommerce/products-query'
);
$bounded_product_output_decision = $bounded_product_output_provider->assess( 'products.get_details', $dummy_action, [ 'product_ids' => [ 123 ] ] );
$assert( 'request_outside_native_coverage' === ( $bounded_product_output_decision['reason'] ?? '' ), 'A live output ID schema that rejects the requested product ID must remain local before execution.' );
$assert( 0 === $bounded_product_output_ability->executions, 'An output-ID-incompatible product request must execute no native primitive.' );

$outside_product_detail_ability = new YoRo_YR0007_Test_Ability( $product_detail_input_schema, $product_detail_output_schema, [] );
$outside_product_detail_provider = new YoRo_YR0007_Test_Native_Provider(
	$outside_product_detail_ability,
	true,
	true,
	'products.get_details',
	'single_supported_product',
	'woocommerce/products-query'
);

foreach (
	[
		[ 'product_ids' => [ 1, 2 ] ],
		[ 'product_ids' => [ '1' ] ],
		[ 'product_ids' => [ 0 ] ],
		[ 'product_ids' => [ -1 ] ],
		[ 'product_ids' => [ 1 ], 'status' => 'publish' ],
	] as $outside_product_detail_params
) {
	$outside_product_decision = $outside_product_detail_provider->assess( 'products.get_details', $dummy_action, $outside_product_detail_params );
	$assert( 'request_outside_native_coverage' === ( $outside_product_decision['reason'] ?? '' ), sprintf( 'Non-canonical or multi-target product detail params must remain local: %s', wp_json_encode( $outside_product_detail_params ) ) );
}
$assert( 0 === $outside_product_detail_ability->executions, 'Outside-coverage product detail requests must execute no native primitive.' );

$missing_product_ability = new YoRo_YR0007_Test_Ability( $product_detail_input_schema, $product_detail_output_schema, [] );
$missing_product_provider = new YoRo_YR0007_Test_Native_Provider(
	$missing_product_ability,
	true,
	true,
	'products.get_details',
	'single_supported_product',
	'woocommerce/products-query'
);
$missing_product_decision = $missing_product_provider->assess( 'products.get_details', $dummy_action, [ 'product_ids' => [ 987654321 ] ] );
$assert( 'request_outside_native_coverage' === ( $missing_product_decision['reason'] ?? '' ), 'A missing product must remain local before native execution.' );
$assert( 0 === $missing_product_ability->executions, 'A missing product must execute no native primitive.' );

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
foreach ( [ 'products.search', 'orders.add_note' ] as $inactive_action ) {
	$assert( empty( $candidate_map[ $inactive_action ]['enabled'] ), sprintf( '%s must remain inactive/local after YR-0016.', $inactive_action ) );
}
foreach ( [ 'orders.search', 'orders.get_details', 'products.get_details', 'orders.update_status', 'products.update_stock', 'products.update_stock_status' ] as $active_action ) {
	$assert( ! empty( $candidate_map[ $active_action ]['enabled'] ), sprintf( '%s must be native-enabled after YR-0016.', $active_action ) );
}

$original_user_id = get_current_user_id();
$admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ] );
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;
$created_orders = [];
$created_products = [];
$created_variations = [];

if ( $admin_id > 0 && function_exists( 'wc_create_order' ) && class_exists( 'WC_Product_Simple' ) ) {
	wp_set_current_user( $admin_id );
	$short_circuit_fixture_mail = static function () {
		return true;
	};
	add_filter( 'pre_wp_mail', $short_circuit_fixture_mail );
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'woocommerce/orders-query' ) : null;
	$product_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'woocommerce/products-query' ) : null;
	$production_provider = new YoRo_Native_Ability_Execution_Provider();
	$unique_suffix = strtolower( wp_generate_password( 10, false, false ) );
	$unique_email = 'yr0016-' . $unique_suffix . '@example.test';
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

	$simple_product = new WC_Product_Simple();
	$simple_product->set_name( 'YR-0016 native detail parity ' . $unique_suffix );
	$simple_product->set_status( 'publish' );
	$simple_product->set_regular_price( '31.50' );
	$simple_product->set_sku( 'yr0016-' . $unique_suffix );
	$simple_product->set_manage_stock( true );
	$simple_product->set_stock_quantity( 7 );
	$simple_product->set_stock_status( 'instock' );
	$simple_product_id = $simple_product->save();

	if ( $simple_product_id > 0 ) {
		$created_products[] = $simple_product;
	} else {
		$failures[] = 'Disposable WooCommerce simple product fixture could not be created.';
	}

	$variation_product_id = 0;

	if ( class_exists( 'WC_Product_Variable' ) && class_exists( 'WC_Product_Variation' ) ) {
		$variable_product = new WC_Product_Variable();
		$variable_product->set_name( 'YR-0016 variation parent ' . $unique_suffix );
		$variable_product->set_status( 'publish' );
		$variable_product_id = $variable_product->save();

		if ( $variable_product_id > 0 ) {
			$created_products[] = $variable_product;
			$variation_product = new WC_Product_Variation();
			$variation_product->set_parent_id( $variable_product_id );
			$variation_product->set_status( 'publish' );
			$variation_product->set_regular_price( '12.00' );
			$variation_product_id = $variation_product->save();

			if ( $variation_product_id > 0 ) {
				$created_variations[] = $variation_product;
			}
		}
	}

	$fixtures_ready = count( $created_orders ) === count( $fixture_statuses ) && $simple_product_id > 0 && $variation_product_id > 0;

	if ( $fixtures_ready && ! function_exists( 'wp_get_ability' ) ) {
		$order_id = $created_orders[0]->get_id();
		$product_id = $simple_product->get_id();
		$production_provider = new YoRo_Native_Ability_Execution_Provider();

		foreach (
			[
				[ 'orders.get_details', [ 'order_ids' => [ $order_id ] ], YoRo_Orders_Read_Service::get_order_details( [ 'order_ids' => [ $order_id ] ] ) ],
				[ 'products.get_details', [ 'product_ids' => [ $product_id ] ], YoRo_Commerce_Discovery_Service::get_product_details( [ 'product_ids' => [ $product_id ] ] ) ],
			] as $local_fallback_case
		) {
			list( $action_name, $params, $expected_local_result ) = $local_fallback_case;
			$action = YoRo_Action_Registry::get( $action_name );
			$decision = $production_provider->assess( $action_name, $action, $params );
			$route = ( new YoRo_Action_Execution_Router( [ $production_provider, new YoRo_Local_Action_Execution_Provider() ] ) )->execute( $action_name, $action, $params );
			$assert( 'native_api_unavailable' === ( $decision['reason'] ?? '' ), sprintf( '%s must select local when a supported older runtime has no Abilities API.', $action_name ) );
			$assert( $expected_local_result === ( $route['result'] ?? null ), sprintf( '%s must preserve its canonical local result without the Abilities API.', $action_name ) );
			$assert( 'local' === ( $route['receipt']['provider_kind'] ?? '' ), sprintf( '%s must record local selection on an older supported runtime.', $action_name ) );
		}
	} elseif ( is_object( $ability ) && is_object( $product_ability ) && $fixtures_ready ) {
		$duplicate_order_id = $created_orders[0]->get_id();
		$duplicate_ability = new YoRo_YR0007_Test_Ability(
			$compatible_input_schema,
			$compatible_output_schema,
			[
				'orders'      => [ [ 'id' => $duplicate_order_id ], [ 'id' => $duplicate_order_id ] ],
				'total_pages' => 1,
				'page'        => 1,
				'per_page'    => 5,
			]
		);
		$duplicate_native = new YoRo_YR0007_Test_Native_Provider( $duplicate_ability );
		$duplicate_local = new YoRo_YR0007_Counting_Local_Provider();
		$duplicate_route = ( new YoRo_Action_Execution_Router( [ $duplicate_native, $duplicate_local ] ) )->execute( 'orders.search', $dummy_action, [ 'limit' => 5 ] );
		$assert( is_wp_error( $duplicate_route['result'] ?? null ) && 'yoro_native_ability_invalid_result' === $duplicate_route['result']->get_error_code(), 'A duplicate native order identity must fail closed.' );
		$assert( 0 === $duplicate_local->executions, 'A duplicate native order identity must never retry local.' );

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

		$order_detail_parity_cases = [
			[ 'order_ids' => [ $created_orders[0]->get_id() ] ],
			[
				'order_ids'         => [ $created_orders[1]->get_id() ],
				'response_focus'    => 'order_status',
				'requested_fields'  => [ 'status', 'status_label' ],
				'response_language' => 'vi',
			],
		];

		foreach ( $order_detail_parity_cases as $detail_params ) {
			$recording_ability = new YoRo_YR0016_Recording_Ability( $ability );
			$detail_provider = new YoRo_YR0007_Test_Native_Provider(
				$recording_ability,
				true,
				true,
				'orders.get_details',
				'single_order',
				'woocommerce/orders-query'
			);
			$local_result = YoRo_Orders_Read_Service::get_order_details( $detail_params );
			$decision = $detail_provider->assess( 'orders.get_details', $dummy_action, $detail_params );
			$assert( ! empty( $decision['eligible'] ), sprintf( 'The live orders-query contract must accept exact detail case %s.', wp_json_encode( $detail_params ) ) );

			if ( ! empty( $decision['eligible'] ) ) {
				$native_result = $detail_provider->execute( 'orders.get_details', $dummy_action, $detail_params, [], $decision );
				$expected_input = [ 'id' => $detail_params['order_ids'][0], 'include_line_items' => false ];
				$assert( 1 === $recording_ability->executions, 'Order detail native execution must invoke the selected primitive exactly once.' );
				$assert( $expected_input === $recording_ability->last_input, 'Order detail live adaptation must contain exactly id and include_line_items=false.' );
				$assert( $local_result === $native_result, sprintf( 'Order detail native/local direct YoRo results must be identical for %s.', wp_json_encode( $detail_params ) ) );
				$assert(
					YoRo_Commerce_Contract_Builder::canonical_execution_payload( $local_result, 'orders.get_details' )
					=== YoRo_Commerce_Contract_Builder::canonical_execution_payload( $native_result, 'orders.get_details' ),
					'Order detail native/local canonical connector payloads must be identical.'
				);
			}
		}

		$product_detail_params = [ 'product_ids' => [ $simple_product->get_id() ] ];
		$product_recording_ability = new YoRo_YR0016_Recording_Ability( $product_ability );
		$product_detail_provider = new YoRo_YR0007_Test_Native_Provider(
			$product_recording_ability,
			true,
			true,
			'products.get_details',
			'single_supported_product',
			'woocommerce/products-query'
		);
		$product_local_result = YoRo_Commerce_Discovery_Service::get_product_details( $product_detail_params );
		$product_detail_decision = $product_detail_provider->assess( 'products.get_details', $dummy_action, $product_detail_params );
		$assert( ! empty( $product_detail_decision['eligible'] ), 'The live products-query contract must accept one supported non-variation product.' );

		if ( ! empty( $product_detail_decision['eligible'] ) ) {
			$product_native_result = $product_detail_provider->execute( 'products.get_details', $dummy_action, $product_detail_params, [], $product_detail_decision );
			$assert( 1 === $product_recording_ability->executions, 'Product detail native execution must invoke the selected primitive exactly once.' );
			$assert( [ 'id' => $simple_product->get_id() ] === $product_recording_ability->last_input, 'Product detail live adaptation must contain exactly id.' );
			$assert( $product_local_result === $product_native_result, 'Product detail native/local direct YoRo results must be identical.' );
			$assert(
				YoRo_Commerce_Contract_Builder::canonical_execution_payload( $product_local_result, 'products.get_details' )
				=== YoRo_Commerce_Contract_Builder::canonical_execution_payload( $product_native_result, 'products.get_details' ),
				'Product detail native/local canonical connector payloads must be identical.'
			);
		}

		$variation_recording_ability = new YoRo_YR0016_Recording_Ability( $product_ability );
		$variation_provider = new YoRo_YR0007_Test_Native_Provider(
			$variation_recording_ability,
			true,
			true,
			'products.get_details',
			'single_supported_product',
			'woocommerce/products-query'
		);
		$variation_local = new YoRo_YR0007_Counting_Local_Provider( YoRo_Commerce_Discovery_Service::get_product_details( [ 'product_ids' => [ $variation_product_id ] ] ) );
		$variation_route = ( new YoRo_Action_Execution_Router( [ $variation_provider, $variation_local ] ) )->execute( 'products.get_details', $dummy_action, [ 'product_ids' => [ $variation_product_id ] ] );
		$assert( 'request_outside_native_coverage' === ( $variation_route['receipt']['selection_reason'] ?? '' ), 'A variation detail request must select local before native execution.' );
		$assert( 0 === $variation_recording_ability->executions, 'A variation detail request must execute no native primitive.' );
		$assert( 1 === $variation_local->executions, 'A variation detail request must execute local exactly once.' );

		$terminal_detail_cases = [
			[
				'action'     => 'orders.get_details',
				'target_key' => 'order_ids',
				'target_id'  => $created_orders[0]->get_id(),
				'coverage'   => 'single_order',
				'ability_id' => 'woocommerce/orders-query',
				'input'      => $order_detail_input_schema,
				'output'     => $order_detail_output_schema,
			],
			[
				'action'     => 'products.get_details',
				'target_key' => 'product_ids',
				'target_id'  => $simple_product->get_id(),
				'coverage'   => 'single_supported_product',
				'ability_id' => 'woocommerce/products-query',
				'input'      => $product_detail_input_schema,
				'output'     => $product_detail_output_schema,
			],
		];

		foreach ( $terminal_detail_cases as $terminal_case ) {
			$terminal_error = new WP_Error( 'woocommerce_detail_domain_error', 'Test-only detail domain failure.' );
			$terminal_ability = new YoRo_YR0007_Test_Ability( $terminal_case['input'], $terminal_case['output'], $terminal_error );
			$terminal_provider = new YoRo_YR0007_Test_Native_Provider(
				$terminal_ability,
				true,
				true,
				$terminal_case['action'],
				$terminal_case['coverage'],
				$terminal_case['ability_id']
			);
			$terminal_local = new YoRo_YR0007_Counting_Local_Provider();
			$terminal_route = ( new YoRo_Action_Execution_Router( [ $terminal_provider, $terminal_local ] ) )->execute(
				$terminal_case['action'],
				$dummy_action,
				[ $terminal_case['target_key'] => [ $terminal_case['target_id'] ] ]
			);
			$assert( is_wp_error( $terminal_route['result'] ?? null ) && 'woocommerce_detail_domain_error' === $terminal_route['result']->get_error_code(), sprintf( '%s selected native domain failure must retain its terminal identity.', $terminal_case['action'] ) );
			$assert( 1 === $terminal_ability->executions, sprintf( '%s selected native domain failure must execute the primitive exactly once.', $terminal_case['action'] ) );
			$assert( 0 === $terminal_local->executions, sprintf( '%s selected native domain failure must never retry local.', $terminal_case['action'] ) );
		}

		$malformed_detail_cases = [
			[
				'action'     => 'orders.get_details',
				'target_key' => 'order_ids',
				'target_id'  => $created_orders[0]->get_id(),
				'collection' => 'orders',
				'coverage'   => 'single_order',
				'ability_id' => 'woocommerce/orders-query',
				'input'      => $order_detail_input_schema,
				'output'     => $order_detail_output_schema,
			],
			[
				'action'     => 'products.get_details',
				'target_key' => 'product_ids',
				'target_id'  => $simple_product->get_id(),
				'collection' => 'products',
				'coverage'   => 'single_supported_product',
				'ability_id' => 'woocommerce/products-query',
				'input'      => $product_detail_input_schema,
				'output'     => $product_detail_output_schema,
			],
		];

		foreach ( $malformed_detail_cases as $detail_case ) {
			$target_id = $detail_case['target_id'];
			$collection = $detail_case['collection'];
			$invalid_results = [
				[ $collection => [], 'total_pages' => 0, 'page' => 1, 'per_page' => 1 ],
				[ $collection => [ [ 'id' => $target_id ], [ 'id' => $target_id ] ], 'total_pages' => 1, 'page' => 1, 'per_page' => 1 ],
				[ $collection => [ 7 => [ 'id' => $target_id ] ], 'total_pages' => 1, 'page' => 1, 'per_page' => 1 ],
				[ $collection => [ [ 'id' => $target_id ] ], 'total_pages' => 1, 'page' => 2, 'per_page' => 1 ],
				[ $collection => [ [ 'id' => $target_id ] ], 'total_pages' => 1, 'page' => 1, 'per_page' => 2 ],
				[ $collection => [ [ 'id' => (string) $target_id ] ], 'total_pages' => 1, 'page' => 1, 'per_page' => 1 ],
				[ $collection => [ [ 'id' => $target_id + 1 ] ], 'total_pages' => 1, 'page' => 1, 'per_page' => 1 ],
				[ $collection => [ [ 'id' => $target_id ] ], 'total_pages' => 1, 'page' => 1, 'per_page' => 1, 'provider' => 'leak' ],
			];

			foreach ( $invalid_results as $invalid_result ) {
				$invalid_ability = new YoRo_YR0007_Test_Ability( $detail_case['input'], $detail_case['output'], $invalid_result );
				$invalid_provider = new YoRo_YR0007_Test_Native_Provider(
					$invalid_ability,
					true,
					true,
					$detail_case['action'],
					$detail_case['coverage'],
					$detail_case['ability_id']
				);
				$forbidden_local = new YoRo_YR0007_Counting_Local_Provider();
				$route = ( new YoRo_Action_Execution_Router( [ $invalid_provider, $forbidden_local ] ) )->execute(
					$detail_case['action'],
					$dummy_action,
					[ $detail_case['target_key'] => [ $target_id ] ]
				);
				$assert( is_wp_error( $route['result'] ?? null ) && 'yoro_native_ability_invalid_result' === $route['result']->get_error_code(), sprintf( '%s malformed native detail output must fail closed.', $detail_case['action'] ) );
				$assert( 1 === $invalid_ability->executions, sprintf( '%s malformed native detail output must follow exactly one primitive execution.', $detail_case['action'] ) );
				$assert( 0 === $forbidden_local->executions, sprintf( '%s malformed native detail output must never retry local.', $detail_case['action'] ) );
			}
		}

		$local_callback_calls = 0;
		$native_primitive_starts = 0;
		$original_action = YoRo_Action_Registry::get( 'orders.search' );
		$permission_action = $original_action;
		$permission_action['callback'] = static function () use ( &$local_callback_calls ) {
			$local_callback_calls++;

			return [ 'success' => true ];
		};
		YoRo_Action_Registry::register( 'orders.search', $permission_action );

		$deny_native = static function ( $permission, $context, $object_id, $post_type ) {
			if ( 'read' === $context && 'shop_order' === $post_type ) {
				return false;
			}

			return $permission;
		};
		$count_native_primitive = static function ( $ability_name ) use ( &$native_primitive_starts ) {
			if ( 'woocommerce/orders-query' === $ability_name ) {
				$native_primitive_starts++;
			}
		};
		add_filter( 'woocommerce_rest_check_permissions', $deny_native, 10, 4 );
		add_action( 'wp_before_execute_ability', $count_native_primitive, 10, 1 );
		$permission_result = YoRo_Action_Executor::execute( 'orders.search', [ 'email' => $unique_email, 'limit' => 1 ] );
		remove_filter( 'woocommerce_rest_check_permissions', $deny_native, 10 );
		remove_action( 'wp_before_execute_ability', $count_native_primitive, 10 );
		$assert( empty( $permission_result['success'] ) && 'ability_invalid_permissions' === ( $permission_result['error']['code'] ?? '' ), 'Native permission denial must remain the terminal normalized outcome after the outer YoRo gate passes.' );
		$assert( 0 === $native_primitive_starts, 'Native primitive callback must not run when the native permission callback denies.' );
		$assert( 0 === $local_callback_calls, 'Native permission denial must execute the local callback zero times.' );
		YoRo_Action_Registry::register( 'orders.search', $original_action );

		foreach (
			[
				[ 'orders.get_details', 'woocommerce/orders-query', [ 'order_ids' => [ $created_orders[0]->get_id() ] ] ],
				[ 'products.get_details', 'woocommerce/products-query', [ 'product_ids' => [ $simple_product->get_id() ] ] ],
			] as $permission_case
		) {
			list( $action_name, $ability_name, $params ) = $permission_case;
			$local_detail_calls = 0;
			$native_detail_starts = 0;
			$original_detail_action = YoRo_Action_Registry::get( $action_name );
			$permission_detail_action = $original_detail_action;
			$permission_detail_action['callback'] = static function () use ( &$local_detail_calls ) {
				$local_detail_calls++;

				return [ 'success' => true ];
			};
			YoRo_Action_Registry::register( $action_name, $permission_detail_action );

			$denied_post_type = 'woocommerce/orders-query' === $ability_name ? 'shop_order' : 'product';
			$deny_detail_native = static function ( $permission, $context, $object_id, $post_type ) use ( $denied_post_type ) {
				return 'read' === $context && $denied_post_type === $post_type ? false : $permission;
			};
			$count_detail_native = static function ( $observed_ability_name ) use ( $ability_name, &$native_detail_starts ) {
				if ( $ability_name === $observed_ability_name ) {
					$native_detail_starts++;
				}
			};
			add_filter( 'woocommerce_rest_check_permissions', $deny_detail_native, 10, 4 );
			add_action( 'wp_before_execute_ability', $count_detail_native, 10, 1 );
			$permission_detail_result = YoRo_Action_Executor::execute( $action_name, $params );
			remove_filter( 'woocommerce_rest_check_permissions', $deny_detail_native, 10 );
			remove_action( 'wp_before_execute_ability', $count_detail_native, 10 );
			$assert( empty( $permission_detail_result['success'] ) && 'ability_invalid_permissions' === ( $permission_detail_result['error']['code'] ?? '' ), sprintf( '%s native permission denial must remain terminal.', $action_name ) );
			$assert( 0 === $native_detail_starts, sprintf( '%s primitive callback must not run after native permission denial.', $action_name ) );
			$assert( 0 === $local_detail_calls, sprintf( '%s native permission denial must execute local zero times.', $action_name ) );
			YoRo_Action_Registry::register( $action_name, $original_detail_action );
		}
	} else {
		$failures[] = 'The exact WooCommerce order and product query abilities plus disposable detail fixtures are required for live differential parity.';
	}

	remove_filter( 'pre_wp_mail', $short_circuit_fixture_mail );
} else {
	$failures[] = 'An administrator and WooCommerce are required for native parity fixtures.';
}

foreach ( $created_variations as $created_variation ) {
	if ( $created_variation instanceof WC_Product ) {
		$created_variation->delete( true );
	}
}

foreach ( array_reverse( $created_products ) as $created_product ) {
	if ( $created_product instanceof WC_Product ) {
		$created_product->delete( true );
	}
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
