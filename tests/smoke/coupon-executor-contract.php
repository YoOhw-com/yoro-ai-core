<?php

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke through WordPress with wp eval-file.\n" );
	exit( 1 );
}

$plugin_root = WP_PLUGIN_DIR . '/yoro-ai-core';

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

if ( function_exists( 'rest_get_server' ) && ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init' );
}

$failures = [];

$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$dispatch_rest = static function ( $method, $route, $params = [] ) {
	$request = new WP_REST_Request( $method, $route );

	foreach ( (array) $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = rest_do_request( $request );

	if ( is_wp_error( $response ) ) {
		return [
			'status' => (int) ( $response->get_error_data()['status'] ?? 500 ),
			'data'   => [
				'success' => false,
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			],
		];
	}

	return [
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	];
};

$admin_ids = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;

$assert( $admin_id > 0, 'A WordPress administrator user is required.' );

if ( $admin_id > 0 ) {
	wp_set_current_user( $admin_id );
	delete_transient( 'yoro_rate_execute_' . $admin_id );
}

$original_ai_api_status = null;

if ( class_exists( 'YoRo_AI_Settings' ) ) {
	$original_ai_api_status = get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, null );
	$installation_uuid      = YoRo_AI_Settings::get_installation_uuid();

	YoRo_AI_Settings::update_ai_api_status(
		[
			'account_connection' => [
				'connected' => true,
				'status'    => 'active',
			],
			'organization' => [
				'id'   => 1,
				'name' => 'Coupon Smoke Organization',
			],
			'site' => [
				'id'     => 1,
				'name'   => 'Coupon Smoke Site',
				'url'    => home_url(),
				'status' => 'active',
				'plan'   => 'test',
			],
			'connection' => [
				'id'                => 1,
				'installation_uuid' => $installation_uuid,
				'status'            => 'active',
				'plugin_version'    => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
			],
			'credential' => [
				'id'                 => 1,
				'type'               => 'wordpress_connection',
				'site_connection_id' => 1,
				'expires_at'         => gmdate( 'c', time() + HOUR_IN_SECONDS ),
			],
		]
	);
}

$assert( class_exists( 'WooCommerce' ), 'WooCommerce must be active.' );
$assert( class_exists( 'YoRo_Action_Registry' ), 'YoRo action registry must be loaded.' );
$assert( function_exists( 'wc_get_coupon_id_by_code' ), 'WooCommerce coupon lookup helper must be available.' );

$coupon_code = 'YORO-SMOKE-' . gmdate( 'YmdHis' );
$expected_coupon_code = function_exists( 'wc_format_coupon_code' )
	? wc_format_coupon_code( $coupon_code )
	: strtolower( $coupon_code );

$preview_response = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/execute',
	[
		'action'  => 'coupons.create',
		'params'  => [
			'coupon_code'     => $coupon_code,
			'discount_type'   => 'percent',
			'discount_amount' => 7,
			'description'     => 'YoRo smoke test coupon',
			'usage_limit'     => 3,
		],
		'preview' => false,
		'confirm' => false,
	]
);
$preview_data = (array) $preview_response['data'];

$assert( 200 === (int) $preview_response['status'], 'Unconfirmed coupon create must return HTTP 200.' );
$assert( false === (bool) ( $preview_data['success'] ?? true ), 'Unconfirmed coupon create must not be successful.' );
$assert( 'confirm' === (string) ( $preview_data['stage'] ?? '' ), 'Unconfirmed coupon create must return stage=confirm.' );
$assert( true === (bool) ( $preview_data['confirm_required'] ?? false ), 'Unconfirmed coupon create must require confirmation.' );
$assert( true === (bool) ( $preview_data['direct_confirmation_allowed'] ?? false ), 'Direct coupon create must expose direct_confirmation_allowed=true.' );
$assert( 'confirmation' === (string) ( $preview_data['result']['display_type'] ?? '' ), 'Coupon create confirmation must render as display_type=confirmation.' );
$assert( ! empty( $preview_data['result']['preview']['coupon']['coupon_code'] ), 'Coupon create confirmation must include coupon preview details.' );

$create_response = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/execute',
	[
		'action'  => 'coupons.create',
		'params'  => [
			'coupon_code'     => $coupon_code,
			'discount_type'   => 'percent',
			'discount_amount' => 7,
			'description'     => 'YoRo smoke test coupon',
			'usage_limit'     => 3,
		],
		'preview' => false,
		'confirm' => true,
	]
);
$create_data = (array) $create_response['data'];
$create_result = isset( $create_data['result'] ) && is_array( $create_data['result'] ) ? $create_data['result'] : [];
$created_coupon = isset( $create_result['coupon'] ) && is_array( $create_result['coupon'] ) ? $create_result['coupon'] : [];
$created_coupon_id = absint( $create_result['coupon_id'] ?? ( $created_coupon['coupon_id'] ?? 0 ) );

$assert( 200 === (int) $create_response['status'], 'Confirmed coupon create must return HTTP 200.' );
$assert( true === (bool) ( $create_data['success'] ?? false ), 'Confirmed coupon create must be successful.' );
$assert( 'done' === (string) ( $create_data['stage'] ?? '' ), 'Confirmed coupon create must return stage=done.' );
$assert( 'coupon' === (string) ( $create_result['display_type'] ?? '' ), 'Confirmed coupon create result must render as display_type=coupon.' );
$assert( $created_coupon_id > 0, 'Confirmed coupon create must return coupon_id.' );
$assert( $expected_coupon_code === (string) ( $create_result['coupon_code'] ?? ( $created_coupon['coupon_code'] ?? '' ) ), 'Confirmed coupon create must return created coupon code.' );
$assert( '7.00% off' === (string) ( $created_coupon['discount_summary'] ?? '' ), 'Confirmed coupon create must return discount summary.' );

$lookup_response = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/execute',
	[
		'action'  => 'coupons.lookup',
		'params'  => [
			'coupon_code' => $coupon_code,
		],
		'preview' => false,
		'confirm' => false,
	]
);
$lookup_data = (array) $lookup_response['data'];
$lookup_result = isset( $lookup_data['result'] ) && is_array( $lookup_data['result'] ) ? $lookup_data['result'] : [];

$assert( 200 === (int) $lookup_response['status'], 'Coupon lookup must return HTTP 200.' );
$assert( true === (bool) ( $lookup_data['success'] ?? false ), 'Coupon lookup must be successful.' );
$assert( 'done' === (string) ( $lookup_data['stage'] ?? '' ), 'Coupon lookup must return stage=done.' );
$assert( 'coupon' === (string) ( $lookup_result['display_type'] ?? '' ), 'Coupon lookup result must render as display_type=coupon.' );
$assert( $created_coupon_id === absint( $lookup_result['coupon_id'] ?? 0 ), 'Coupon lookup must return the created coupon_id.' );
$assert( true === (bool) ( $lookup_result['read_only'] ?? false ), 'Coupon lookup must remain read_only.' );

$duplicate_preview = call_user_func(
	YoRo_Action_Registry::get( 'coupons.create' )['preview_callback'],
	[
		'coupon_code'     => $coupon_code,
		'discount_type'   => 'percent',
		'discount_amount' => 7,
	]
);

$assert( is_array( $duplicate_preview ), 'Duplicate coupon preview must return an array.' );
$assert( true === (bool) ( $duplicate_preview['blocked'] ?? false ), 'Duplicate coupon preview must block before approval.' );
$assert( 'coupon_already_exists' === (string) ( $duplicate_preview['block_reason'] ?? '' ), 'Duplicate coupon preview must expose coupon_already_exists.' );
$assert( false === (bool) ( $duplicate_preview['requires_confirmation'] ?? false ), 'Duplicate coupon preview must not require confirmation.' );
$assert( false === (bool) ( $duplicate_preview['execution_required'] ?? false ), 'Duplicate coupon preview must not expose execution_required.' );

$missing_preview = call_user_func(
	YoRo_Action_Registry::get( 'coupons.create' )['preview_callback'],
	[
		'coupon_code' => $coupon_code . '-MISSING',
	]
);

$assert( is_array( $missing_preview ), 'Missing discount preview must return an array.' );
$assert( true === (bool) ( $missing_preview['blocked'] ?? false ), 'Missing discount preview must block before approval.' );
$assert( in_array( (string) ( $missing_preview['block_reason'] ?? '' ), [ 'invalid_discount_type', 'invalid_discount_amount' ], true ), 'Missing discount preview must expose a discount validation block reason.' );
$assert( false === (bool) ( $missing_preview['requires_confirmation'] ?? false ), 'Missing discount preview must not require confirmation.' );
$assert( false === (bool) ( $missing_preview['execution_required'] ?? false ), 'Missing discount preview must not expose execution_required.' );

$search_fixture_ids = [];
$search_queries = [];
$observe_search = static function ( $query ) use ( &$search_queries ) {
	if ( 'shop_coupon' === $query->get( 'post_type' ) && 'ids' === $query->get( 'fields' ) ) {
		$search_queries[] = $query->query_vars;
	}
};
$unsupported_store = static function () { return 'WC_Product_Data_Store_CPT'; };
$disable_coupons = static function () { return false; };
$change_search_columns = static function () { return [ 'post_content' ]; };
$change_exclusion_prefix = static function () { return '!'; };
$delete_race = null;
try {
	$search_prefix = 'yr0071-' . wp_generate_uuid4();
	for ( $index = 0; $index < 3; ++$index ) {
		$fixture = new WC_Coupon();
		$fixture->set_code( $search_prefix . '-' . $index );
		$fixture->set_discount_type( 'percent' );
		$fixture->set_amount( 7 );
		$fixture->set_description( '<b>safe</b><script>alert(1)</script>' . str_repeat( 'x', 600 ) );
		$fixture->set_email_restrictions( [ 'private-customer@example.test' ] );
		$fixture->set_used_by( [ 'private-customer@example.test' ] );
		$fixture->set_date_created( '2026-01-01 10:00:00' );
		$search_fixture_ids[] = $fixture->save();
	}
	add_action( 'pre_get_posts', $observe_search, PHP_INT_MAX );
	$page_one = YoRo_Coupons_Service::search_coupons( [ 'query' => $search_prefix, 'limit' => 2 ] );
	$page_two = YoRo_Coupons_Service::search_coupons( [ 'query' => $search_prefix, 'limit' => 2, 'page' => 2 ] );
	$assert( ! is_wp_error( $page_one ) && ! is_wp_error( $page_two ), 'Bounded search must succeed.' );
	if ( is_array( $page_one ) && is_array( $page_two ) ) {
		$assert( [ $search_fixture_ids[2], $search_fixture_ids[1] ] === array_column( $page_one['data']['coupons'], 'coupon_id' ), 'Equal-date coupons must have deterministic descending ID order.' );
		$assert( [ $search_fixture_ids[0] ] === array_column( $page_two['data']['coupons'], 'coupon_id' ), 'Pagination must not skip its lookahead row.' );
		$assert( true === $page_one['data']['has_more'] && 2 === $page_one['data']['next_page'], 'Lookahead must establish the next page.' );
		$assert( false === $page_two['data']['has_more'] && null === $page_two['data']['next_page'], 'Last page must not invent another page.' );
		$assert( ! str_contains( wp_json_encode( $page_one ), 'private-customer' ) && ! str_contains( wp_json_encode( $page_one ), '<script' ), 'Search must not expose customer restrictions, usage identities or HTML.' );
		$assert( mb_strlen( $page_one['data']['coupons'][0]['description'] ) <= 500, 'Descriptions must be bounded.' );
		$canonical_search = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $page_one, 'coupons.search' );
		$assert( is_array( $canonical_search ) && true === $canonical_search['read_only'], 'Search must have a strict canonical read-only payload.' );
		$unicode_search = $page_one;
		$unicode_search['data']['coupons'][0]['description'] = str_repeat( 'ệ', 500 );
		$unicode_search['data']['coupons'][0]['discount_amount'] = 7.5;
		$assert( is_array( YoRo_Commerce_Contract_Builder::canonical_execution_payload( $unicode_search, 'coupons.search' ) ), 'Finite numeric amounts and bounded Unicode descriptions must survive canonical validation.' );
		foreach ( [ '7.5', INF, NAN ] as $invalid_amount ) {
			$invalid_search_result = $page_one;
			$invalid_search_result['data']['coupons'][0]['discount_amount'] = $invalid_amount;
			$assert( is_wp_error( YoRo_Commerce_Contract_Builder::canonical_execution_payload( $invalid_search_result, 'coupons.search' ) ), 'Non-numeric or non-finite amounts must fail closed.' );
		}
		$assert( YoRo_Commerce_Contract_Builder::strict_output_validation_ready( 'coupons.search' ), 'Search schema must be closed and ready for strict validation.' );
		$poisoned = $page_one;
		$poisoned['data']['coupons'][0]['used_by'] = [ 'private-customer@example.test' ];
		$assert( is_wp_error( YoRo_Commerce_Contract_Builder::canonical_execution_payload( $poisoned, 'coupons.search' ) ), 'Canonical search must reject undeclared customer data.' );
	}
	$assert( 2 === count( $search_queries ), 'Each search must use one ID query.' );
	foreach ( $search_queries as $observed_query ) {
		$assert( 3 === $observed_query['posts_per_page'] && true === $observed_query['no_found_rows'], 'Search must observe limit+1 IDs and not request a total.' );
		$assert( [ 'post_title' ] === $observed_query['search_columns'], 'Text search must stay in coupon code storage.' );
	}
	$invalid_params = [
		[ 'limit' => 0 ], [ 'limit' => 51 ], [ 'limit' => '2' ], [ 'limit' => 2.5 ], [ 'limit' => null ],
		[ 'page' => 0 ], [ 'page' => 101 ], [ 'page' => true ], [ 'page' => null ], [ 'query' => null ],
		[ 'query' => [] ], [ 'query' => str_repeat( 'x', 101 ) ], [ 'query' => '<script>x</script>' ],
		[ 'query' => '%' ], [ 'query' => '*' ], [ 'query' => '-exclude' ], [ 'query' => "bad\nquery" ],
		[ 'coupon_id' => 1 ], [ 'coupon_code' => 'code' ], [ 'customer_email' => 'x@example.test' ],
		[ 'used_by' => [] ], [ 'discount_amount' => 1 ], [ 'orderby' => 'rand' ],
	];
	$queries_before = count( $search_queries );
	foreach ( $invalid_params as $invalid_param ) {
		$invalid_search = YoRo_Coupons_Service::search_coupons( $invalid_param );
		$assert( is_wp_error( $invalid_search ) && 'invalid_coupon_search' === $invalid_search->get_error_code(), 'Malformed or forbidden search input must fail closed.' );
	}
	$assert( $queries_before === count( $search_queries ), 'Invalid input must not reach the coupon query.' );
	$assert( is_wp_error( YoRo_Coupons_Service::search_coupons( [ '_yoro_confirmed' => false ] ) ), 'The domain search must reject executor flags as public input.' );
	$assert( is_wp_error( YoRo_Orders_Actions::search_coupons( [ '_yoro_confirmed' => false, '_yoro_preview' => false, 'customer_email' => 'private@example.test' ] ) ), 'The executor adapter must not discard unsupported public selectors.' );
	$empty_search = YoRo_Coupons_Service::search_coupons( [ 'query' => $search_prefix . '-no-match' ] );
	$assert( is_array( $empty_search ) && [] === $empty_search['data']['coupons'] && false === $empty_search['data']['has_more'], 'No matches must be a truthful empty live page.' );
	$default_search = YoRo_Coupons_Service::search_coupons( [] );
	$assert( is_array( $default_search ) && 20 === $default_search['data']['limit'] && 1 === $default_search['data']['page'], 'Empty input must use bounded defaults.' );
	add_filter( 'woocommerce_coupons_enabled', $disable_coupons );
	$disabled_search = YoRo_Coupons_Service::search_coupons( [ 'query' => $search_prefix ] );
	remove_filter( 'woocommerce_coupons_enabled', $disable_coupons );
	$assert( is_array( $disabled_search ) && false === $disabled_search['data']['coupons_enabled'], 'Disabled coupon application must remain an explicit observation, not hide existing records.' );
	add_filter( 'woocommerce_coupon_data_store', $unsupported_store );
	$unsupported = YoRo_Coupons_Service::search_coupons( [] );
	remove_filter( 'woocommerce_coupon_data_store', $unsupported_store );
	$assert( is_wp_error( $unsupported ) && 'coupon_search_unsupported_store' === $unsupported->get_error_code(), 'Non-CPT coupon storage must fail closed.' );
	foreach ( [ 'post_search_columns' => $change_search_columns, 'wp_query_search_exclusion_prefix' => $change_exclusion_prefix ] as $filter_name => $query_filter ) {
		add_filter( $filter_name, $query_filter );
		$filtered_search = YoRo_Coupons_Service::search_coupons( [ 'query' => '!sale' ] );
		remove_filter( $filter_name, $query_filter );
		$assert( is_wp_error( $filtered_search ) && 'coupon_search_unsupported_query' === $filtered_search->get_error_code(), 'Unbounded matching semantics from query filters must fail closed.' );
	}
	$before = array_map( static function ( $id ) { return ( new WC_Coupon( $id ) )->get_data(); }, $search_fixture_ids );
	$search_response = $dispatch_rest( 'POST', '/yoro-ai-core/v1/execute', [ 'action' => 'coupons.search', 'params' => [ 'query' => $search_prefix ], 'preview' => false, 'confirm' => false ] );
	if ( true !== ( $search_response['data']['success'] ?? false ) ) {
		fwrite( STDERR, 'SEARCH_RESPONSE=' . wp_json_encode( $search_response ) . "\n" );
	}
	$assert( true === ( $search_response['data']['success'] ?? false ) && 'done' === ( $search_response['data']['stage'] ?? '' ), 'Read-only search must execute without confirmation.' );
	$after = array_map( static function ( $id ) { return ( new WC_Coupon( $id ) )->get_data(); }, $search_fixture_ids );
	$assert( wp_json_encode( $before ) === wp_json_encode( $after ), 'Search must not mutate coupon data.' );
	$delete_race = static function ( $posts, $query ) use ( $search_prefix, $search_fixture_ids ) {
		if ( $search_prefix === $query->get( 's' ) ) {
			$posts = array_reverse( $search_fixture_ids );
			wp_delete_post( $posts[0], true );
		}
		return $posts;
	};
	add_filter( 'posts_pre_query', $delete_race, 10, 2 );
	$raced_search = YoRo_Coupons_Service::search_coupons( [ 'query' => $search_prefix, 'limit' => 2 ] );
	remove_filter( 'posts_pre_query', $delete_race, 10 );
	$assert( is_array( $raced_search ) && 1 === $raced_search['data']['skipped_count'] && 1 === count( $raced_search['data']['coupons'] ) && true === $raced_search['data']['has_more'], 'Deletion after ID observation must yield a truthful partial page without filling from another query.' );
} finally {
	if ( null !== $delete_race ) {
		remove_filter( 'posts_pre_query', $delete_race, 10 );
	}
	remove_action( 'pre_get_posts', $observe_search, PHP_INT_MAX );
	remove_filter( 'woocommerce_coupon_data_store', $unsupported_store );
	remove_filter( 'woocommerce_coupons_enabled', $disable_coupons );
	remove_filter( 'post_search_columns', $change_search_columns );
	remove_filter( 'wp_query_search_exclusion_prefix', $change_exclusion_prefix );
	foreach ( $search_fixture_ids as $search_fixture_id ) {
		wp_delete_post( $search_fixture_id, true );
	}
}

if ( $created_coupon_id > 0 ) {
	wp_delete_post( $created_coupon_id, true );
}

if ( class_exists( 'YoRo_AI_Settings' ) ) {
	if ( null === $original_ai_api_status ) {
		delete_option( YoRo_AI_Settings::OPTION_AI_API_STATUS );
	} else {
		update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $original_ai_api_status, false );
	}
}

if ( $failures ) {
	fwrite( STDERR, "CREATE_RESPONSE=" . wp_json_encode( $create_data ) . "\n" );
	fwrite( STDERR, "LOOKUP_RESPONSE=" . wp_json_encode( $lookup_data ) . "\n" );

	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo coupon executor smoke passed for {$coupon_code}.\n";
