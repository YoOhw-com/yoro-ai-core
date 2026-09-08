<?php
/** Actual WordPress/WooCommerce providers with isolated in-memory order fixtures; no store writes. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Order' ) ) {
	throw new RuntimeException( 'Run with wp eval-file in a WooCommerce installation.' );
}
$disposable_ci = false;
if ( 'true' === getenv( 'GITHUB_ACTIONS' ) ) {
	foreach ( [ [ getenv( 'RUNNER_TEMP' ), 'wordpress', 'yoro-plugin-smoke.test' ], [ getenv( 'RUNNER_TEMP' ), 'wordpress-woo', 'yoro-woocommerce-exact-pair.test' ], [ getenv( 'GITHUB_WORKSPACE' ), 'wordpress', 'yoro-smoke.test' ] ] as [ $parent, $directory, $host ] ) {
		$expected = $parent ? realpath( $parent . '/' . $directory ) : false;
		if ( false !== $expected && $expected === realpath( ABSPATH ) && $host === wp_parse_url( home_url(), PHP_URL_HOST ) ) { $disposable_ci = true; }
	}
}
if ( ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) && ! $disposable_ci ) {
	throw new RuntimeException( 'Only local/development or disposable CI is supported.' );
}
class YoRo_Queue_Fixture_Order extends WC_Order {
	public $fixture_created;
	public $fixture_meta = [];
	public $fixture_shipping = true;
	public function get_date_created( $context = 'view' ) { return $this->fixture_created; }
	public function get_meta( $key = '', $single = true, $context = 'view' ) { return $this->fixture_meta[ $key ] ?? ''; }
	public function get_items( $types = 'line_item' ) { return $this->fixture_shipping && 'line_item' === $types ? [ new WC_Order_Item_Product() ] : []; }
	public function get_shipping_methods() { $item = new WC_Order_Item_Shipping(); $item->set_method_title( 'Fixture shipping' ); return [ $item ]; }
}
class YoRo_Queue_Fixture_Store extends WC_Order_Data_Store_CPT {
	public static $orders = [];
	public static $calls = [];
	public static $error = false;
	public function query( $args ) {
		self::$calls[] = $args;
		if ( true === self::$error || ( is_int( self::$error ) && count( self::$calls ) === self::$error ) ) { throw new RuntimeException( 'private-provider-diagnostic' ); }
		$orders = array_values( array_filter( self::$orders, static function( $order ) use ( $args ) {
			$statuses = array_map( static function( $status ) { return str_replace( 'wc-', '', $status ); }, (array) $args['status'] );
			if ( $statuses && ! in_array( $order->get_status(), $statuses, true ) ) { return false; }
			$query = $args['date_created'] ?? '';
			if ( ! $query ) { return true; }
			$date = $order->get_date_created();
			if ( ! $date instanceof DateTimeInterface ) { return false; }
			$ts = $date->getTimestamp();
			$parse = static function( $value ) { return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp(); };
			if ( str_starts_with( $query, '<=' ) ) { return $ts <= $parse( substr( $query, 2 ) ); }
			if ( str_contains( $query, '...' ) ) { [ $start, $end ] = explode( '...', $query ); return $ts >= $parse( $start ) && $ts <= $parse( $end ); }
			return true;
		} ) );
		$total = count( $orders );
		$limit = (int) $args['limit'];
		$orders = array_slice( $orders, max( 0, ( (int) ( $args['paged'] ?? $args['page'] ?? 1 ) - 1 ) * max( 1, $limit ) ), $limit > 0 ? $limit : null );
		if ( 'ids' === $args['return'] ) { $orders = array_map( static function( $o ) { return $o->get_id(); }, $orders ); }
		return ! empty( $args['paginate'] ) ? (object) [ 'orders' => $orders, 'total' => $total, 'max_num_pages' => (int) ceil( $total / max( 1, $limit ) ) ] : $orders;
	}
}
class YoRo_Queue_Fixture_Product_Store extends WC_Product_Data_Store_CPT {
	public function query( $args ) { return ! empty( $args['paginate'] ) ? (object) [ 'products' => [], 'total' => 0, 'max_num_pages' => 0 ] : []; }
}
$root = dirname( __DIR__, 2 );
require_once $root . '/includes/rest/class-yoro-rest-execute-handler.php';
$assert = static function( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$fixture = static function( $id, $created = '-80 hours', $status = 'processing' ) {
	$order = new YoRo_Queue_Fixture_Order();
	$order->set_id( $id );
	$order->set_status( $status );
	$order->set_total( '500' );
	$order->set_currency( 'USD' );
	$order->set_billing_first_name( 'Alice <script>fixture</script>' );
	$order->fixture_created = is_string( $created ) ? new WC_DateTime( $created, new DateTimeZone( 'UTC' ) ) : $created;
	$order->fixture_meta = [ '_yoro_customer_email_drafts' => [ [ 'subject' => 'Review only' ] ] ];
	return $order;
};
$store = static function() { return YoRo_Queue_Fixture_Store::class; };
$product_store = static function() { return YoRo_Queue_Fixture_Product_Store::class; };
$no_cache = static function() { return false; };
$shipping = static function() { return ''; };
$permissions = static function( $caps ) { $caps['manage_woocommerce'] = true; $caps['edit_shop_orders'] = true; return $caps; };
$writes = 0;
$sql_guard = static function( $sql ) use ( &$writes ) { if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql ) ) { ++$writes; throw new RuntimeException( 'Queue tests must not write to the store database.' ); } return $sql; };
$http_calls = 0;
$http_guard = static function() use ( &$http_calls ) { ++$http_calls; return new WP_Error( 'unexpected_http', 'No outbound calls in queue tests.' ); };
add_filter( 'woocommerce_order_data_store', $store, PHP_INT_MAX );
add_filter( 'woocommerce_product_data_store', $product_store, PHP_INT_MAX );
add_filter( 'yoro_action_cache_enabled', $no_cache );
add_filter( 'pre_option_woocommerce_ship_to_countries', $shipping );
$previous_user = get_current_user_id();
wp_set_current_user( 1 );
add_filter( 'user_has_cap', $permissions, PHP_INT_MAX );
add_filter( 'query', $sql_guard );
add_filter( 'pre_http_request', $http_guard );
$profile_cache = 'pre_transient_' . YoRo_Store_Knowledge_Provider::CACHE_PREFIX . md5( 'report|' . home_url() . '|' . get_locale() );
$profile = static function() { return []; };
add_filter( $profile_cache, $profile );
$exports = [];
try {
	$providers = [
		'operations.get_attention_queue' => [ YoRo_Orders_Read_Service::class, 'get_operations_attention_queue' ],
		'operations.get_fulfillment_queue' => [ YoRo_Orders_Read_Service::class, 'get_fulfillment_queue' ],
		'customers.get_service_queue' => [ YoRo_Orders_Read_Service::class, 'get_customer_service_queue' ],
	];
	YoRo_Queue_Fixture_Store::$orders = [ $fixture( 900001 ), $fixture( 900002 ) ];
	foreach ( $providers as $action => $provider ) {
		YoRo_Queue_Fixture_Store::$calls = [];
		$result = call_user_func( $provider, [ 'limit' => 1 ] );
		$assert( is_array( $result ) && $result['success'], $action . ' should succeed' );
		$evidence = $result['meta']['queue_evidence'];
		$assert( $evidence['age_basis'] === 'date_created' && $evidence['status_duration'] === 'unknown', 'Created-age must not assert status duration.' );
		$ids = array_column( $result['orders'], 'id' );
		$assert( count( $ids ) === count( array_unique( $ids ) ), 'Aggregate identities must be unique.' );
		$assert( array_column( array_column( $result['case_rankings'], 'order' ), 'id' ) === array_slice( $ids, 0, 10 ), 'Ranking and aggregate order must agree.' );
		$assert( $evidence['visible_unique_orders'] === count( $ids ), 'Unique count is only the returned slice.' );
		$assert( count( YoRo_Queue_Fixture_Store::$calls ) > 0 && count( $ids ) > 0, 'Actual provider must consume isolated fixture rows.' );
		$assert( max( array_column( YoRo_Queue_Fixture_Store::$calls, 'limit' ) ) <= 50, 'Queue queries stay bounded at 50.' );
		$canonical = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, $action );
		$assert( $canonical['success'] === true, 'Actual fresh provider must pass serializer age guard.' );
		if ( 'operations.get_attention_queue' === $action ) {
			$assert( $canonical['data']['items'][0]['extensions']['woocommerce.order']['edit_url'] === $result['orders'][0]['edit_url'], 'New evidence must retain the existing order edit URL extension.' );
		}
		$exports[$action] = [ 'raw' => $result, 'canonical' => $canonical, 'query_count' => count( YoRo_Queue_Fixture_Store::$calls ), 'bytes' => strlen( wp_json_encode( $canonical ) ) ];
	}
	// Exercise the real cache-hit branch using a process-local transient filter.
	$cache_key_method = new ReflectionMethod( YoRo_Action_Cache::class, 'build_key' );
	$context = [ 'limit' => 1, 'high_value_threshold' => 200.0, 'schema_version' => 3 ];
	$key = $cache_key_method->invoke( null, 'operations_attention_queue', $context );
	$legacy_key = $cache_key_method->invoke( null, 'operations_attention_queue', array_replace( $context, [ 'schema_version' => 2 ] ) );
	$assert( $key !== $legacy_key, 'Legacy cache discriminator must not replay zero-filled evidence.' );
	$cached_value = $exports['operations.get_attention_queue']['raw'];
	$cache_value = static function() use ( $cached_value ) { return [ 'value' => $cached_value ]; };
	$cache_enabled = static function() { return true; };
	add_filter( 'pre_transient_' . $key, $cache_value );
	add_filter( 'yoro_action_cache_enabled', $cache_enabled, PHP_INT_MAX );
	$before_calls = count( YoRo_Queue_Fixture_Store::$calls );
	try {
		$cached = YoRo_Orders_Read_Service::get_operations_attention_queue( [ 'limit' => 1 ] );
		$assert( $cached === $cached_value && count( YoRo_Queue_Fixture_Store::$calls ) === $before_calls, 'Cache hit retains original capture and executes zero order queries.' );
	} finally {
		remove_filter( 'pre_transient_' . $key, $cache_value );
		remove_filter( 'yoro_action_cache_enabled', $cache_enabled, PHP_INT_MAX );
	}
	$disabled = static function() { return 'disabled'; };
	add_filter( 'pre_option_woocommerce_ship_to_countries', $disabled, PHP_INT_MAX );
	try {
		$na = YoRo_Orders_Read_Service::get_fulfillment_queue( [] );
		$assert( $na['meta']['queue_evidence']['applicable'] === false && $na['fulfillment_context']['applicable'] === false && count( YoRo_Queue_Fixture_Store::$calls ) === $before_calls, 'Not applicable must be explicit, with zero provider queries.' );
	} finally { remove_filter( 'pre_option_woocommerce_ship_to_countries', $disabled, PHP_INT_MAX ); }
	$timezone = static function() { return 'Asia/Ho_Chi_Minh'; };
	$date_format = static function() { return 'd/m/Y'; };
	add_filter( 'pre_option_timezone_string', $timezone );
	add_filter( 'pre_option_date_format', $date_format );
	try {
		$localized = YoRo_Orders_Read_Service::get_operations_attention_queue( [ 'limit' => 1 ] );
		$assert( YoRo_Commerce_Contract_Builder::canonical_execution_payload( $localized )['success'], 'Timestamp-backed age survives non-UTC and localized date formatting.' );
	} finally {
		remove_filter( 'pre_option_timezone_string', $timezone );
		remove_filter( 'pre_option_date_format', $date_format );
	}

	$first = $exports['operations.get_fulfillment_queue']['raw'];
	$assert( $first['count'] > count( $first['orders'] ), 'Overlapping group memberships must stay distinct from unique orders.' );
	$assert( count( $first['orders'][0]['extensions']['woocommerce.queue_evidence']['reasons'] ) >= 2, 'Overlap preserves all observed reasons.' );
	$assert( $first['orders'][0]['case_priority_score'] === $first['case_rankings'][0]['score'], 'Primary case keeps highest existing score.' );
	$rankings = $first['case_rankings'];
	$duplicate = array_merge( $rankings, $rankings );
	$assert( YoRo_Orders_Read_Service::queue_case_rankings( $duplicate ) === YoRo_Orders_Read_Service::queue_case_rankings( array_reverse( $duplicate ) ), 'Ties and duplicate inputs are deterministic.' );
	$assert( count( YoRo_Orders_Read_Service::queue_case_rankings( $duplicate ) ) === count( $rankings ), 'Duplicate membership cannot create work.' );

	YoRo_Queue_Fixture_Store::$orders = [];
	for ( $i = 0; $i < 60; ++$i ) { YoRo_Queue_Fixture_Store::$orders[] = $fixture( 901000 + $i ); }
	$sample = YoRo_Orders_Read_Service::get_fulfillment_queue( [ 'limit' => 3 ] );
	$sample_evidence = $sample['meta']['queue_evidence']['queues']['processing_without_tracking'];
	$assert( $sample_evidence['sample_size'] === 50 && $sample_evidence['match_count'] === 50 && false === $sample_evidence['complete'] && true === $sample_evidence['visible_truncated'], 'Sample50 does not claim full-store completeness.' );
	$attention = YoRo_Orders_Read_Service::get_operations_attention_queue( [ 'limit' => 3 ] );
	$assert( $attention['meta']['queue_evidence']['queues']['processing_older_than_72h']['match_count'] === 60 && $attention['meta']['queue_evidence']['queues']['high_value_open_orders']['match_count'] === 50, 'Full query and candidate sample counts remain distinct.' );

	foreach ( [ null, new stdClass(), new WC_DateTime( '+30 minutes' ), new WC_DateTime( '+2 days' ) ] as $invalid ) {
		YoRo_Queue_Fixture_Store::$orders = [ $fixture( 902000, $invalid ) ];
		foreach ( $providers + [ 'operations.get_summary' => [ YoRo_Orders_Read_Service::class, 'get_operations_summary' ] ] as $action => $provider ) {
			$error = call_user_func( $provider, [] );
			$assert( is_wp_error( $error ) && 'yoro_queue_age_unavailable' === $error->get_error_code(), $action . ' must fail required unavailable age' );
		}
	}
	YoRo_Queue_Fixture_Store::$orders = [ $fixture( 902010 ), $fixture( 902011, null ) ];
	foreach ( $providers as $action => $provider ) {
		$mixed = call_user_func( $provider, [] );
		$assert( is_wp_error( $mixed ) && $mixed->get_error_code() === 'yoro_queue_age_unavailable', 'Mixed known/unknown slice must fail entirely, not return only known rows.' );
	}
	$irrelevant = $fixture( 902012, null, 'completed' );
	$irrelevant->fixture_meta = [];
	$irrelevant->fixture_shipping = false;
	YoRo_Queue_Fixture_Store::$orders = [ $irrelevant ];
	foreach ( $providers as $provider ) {
		$unaffected = call_user_func( $provider, [] );
		$assert( is_array( $unaffected ) && $unaffected['success'], 'A row that needs no age for this queue must not fail the action.' );
	}

	YoRo_Queue_Fixture_Store::$orders = [ $fixture( 902001, '-30 minutes' ) ];
	$young = YoRo_Orders_Read_Service::get_operations_attention_queue( [] );
	$assert( $young['orders'][0]['age_hours'] === 0, 'Real age below one hour is valid zero.' );
	$assert( YoRo_Commerce_Contract_Builder::canonical_execution_payload( $young )['success'], 'Real zero survives canonical mapping.' );
	$summary = YoRo_Orders_Read_Service::get_operations_summary( [] );
	$exports['operations.get_summary'] = [ 'raw' => $summary, 'canonical' => YoRo_Commerce_Contract_Builder::canonical_execution_payload( $summary ) ];

	$daily = YoRo_Store_Briefing_Service::get_daily_briefing( [] );
	$assert( is_array( $daily ) && $daily['success'], 'Daily briefing preserves successful dependencies.' );
	$daily_canonical = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $daily );
	$assert( $daily_canonical['success'] && isset( $daily_canonical['extensions']['wordpress.execution']['meta']['queue_evidence']['sections']['fulfillment'] ), 'Daily canonical mapping preserves section observation scopes.' );
	$exports['store.get_daily_briefing'] = [ 'raw' => $daily, 'canonical' => $daily_canonical ];

	foreach ( [ null, 'unknown', -1, '0' ] as $bad_age ) {
		$bad = $young; $bad['orders'][0]['age_hours'] = $bad_age;
		$assert( false === YoRo_Commerce_Contract_Builder::canonical_execution_payload( $bad )['success'], 'Direct malformed age cannot zero-fill.' );
	}
	foreach ( [ ['queues' => [ new stdClass() ]], ['orders' => [ new stdClass() ]], ['orders' => [ ['date_created_ts' => time() - 1800, 'age_hours' => 0, 'extensions' => new stdClass() ] ]], ['orders' => [ ['age_hours' => 0, 'created_at' => "2026-09-08\0"] ]] ] as $bad_fields ) {
		$bad = array_replace( $young, $bad_fields );
		$assert( false === YoRo_Commerce_Contract_Builder::canonical_execution_payload( $bad )['success'], 'Malformed row must fail without throwing.' );
	}
	$bad = $young; $bad['orders'][0]['age_hours'] = 49;
	$assert( false === YoRo_Commerce_Contract_Builder::canonical_execution_payload( $bad )['success'], 'Captured age must match source time.' );

	YoRo_Queue_Fixture_Store::$error = true;
	foreach ( $providers as $action => $provider ) {
		$error = call_user_func( $provider, [] );
		$assert( is_wp_error( $error ) && 'yoro_queue_query_failed' === $error->get_error_code() && ! str_contains( $error->get_error_message(), 'private-provider' ), 'Query failure cannot become healthy zero or leak diagnostics.' );
	}
	YoRo_Queue_Fixture_Store::$calls = [];
	YoRo_Queue_Fixture_Store::$error = 2;
	$key_error = YoRo_Orders_Read_Service::get_operations_summary( [] );
	$assert( is_wp_error( $key_error ) && $key_error->get_error_code() === 'yoro_queue_query_failed', 'Current-window status query error cannot be overwritten by another count map.' );

	YoRo_Queue_Fixture_Store::$error = false;
	YoRo_Queue_Fixture_Store::$orders = [ $fixture( 902002, null ) ];
	$plan = [ 'type' => 'single', 'action' => 'operations.get_summary', 'params' => [] ];
	$failed = YoRo_Plan_Executor::execute( $plan, false, false );
	$assert( is_array( $failed ) && ! $failed['success'] && $failed['error']['code'] === 'yoro_queue_age_unavailable', 'Single action preserves failure code: ' . ( $failed['error']['code'] ?? 'unknown' ) );
	$sequence = YoRo_Plan_Executor::execute( [ 'type' => 'sequence', 'steps' => [ [ 'id' => 'age-step', 'action' => 'operations.get_summary', 'params' => [], 'retry' => [ 'max_attempts' => 1 ], 'on_error' => 'stop' ] ] ], false, false );
	$assert( is_array( $sequence ) && ! $sequence['success'] && str_contains( wp_json_encode( $sequence ), 'yoro_queue_age_unavailable' ), 'Sequence keeps the existing failure lifecycle and error code.' );

	$event_builder = new ReflectionMethod( YoRo_REST_Execute_Handler::class, 'build_action_event_payload' );
	$event = $event_builder->invoke( null, 'fixture-conversation', 'fixture-token', $plan['action'], [], $failed, $failed['success'] ? 'executed' : 'failed', $failed['message'], 'op_operations_get_summary', 'fixture-trace' );
	$assert( $event['status'] === 'failed' && ! $event['result']['success'] && $event['result']['extensions']['wordpress.execution']['errors'][0]['code'] === 'yoro_queue_age_unavailable', 'Failed event and canonical payload must agree.' );
	$exports['age_error'] = [ 'event' => $event ];
	$calls = count( YoRo_Queue_Fixture_Store::$calls );
	$preview = YoRo_Plan_Executor::execute( $plan, false, true );
	$assert( ! is_wp_error( $preview ) && count( YoRo_Queue_Fixture_Store::$calls ) === $calls, 'Preview reads no order age.' );
	$daily_error = YoRo_Store_Briefing_Service::get_daily_briefing( [] );
	$assert( is_wp_error( $daily_error ) && $daily_error->get_error_code() === 'yoro_queue_age_unavailable', 'Briefing must propagate age error.' );
	$daily_failed = YoRo_Plan_Executor::execute( [ 'type' => 'single', 'action' => 'store.get_daily_briefing', 'params' => [] ], false, false );
	$daily_event = $event_builder->invoke( null, 'fixture-conversation', 'fixture-token', 'store.get_daily_briefing', [], $daily_failed, 'failed', $daily_failed['message'], 'op_store_get_daily_briefing', 'fixture-trace' );
	$assert( $daily_event['result']['success'] === false && $daily_event['status'] === 'failed', 'Briefing failure is an actual failed event.' );
	$exports['age_error_daily'] = [ 'event' => $daily_event ];

	$assert( $writes === 0 && $http_calls === 0, 'Read/render tests must not mutate store or call a model.' );
	if ( $output = getenv( 'YORO_QUEUE_FIXTURE_OUT' ) ) {
		$assert( str_starts_with( $output, '/' ) && is_dir( dirname( $output ) ) && ! str_starts_with( realpath( dirname( $output ) ), $root ), 'Fixture export must be external.' );
		file_put_contents( $output, wp_json_encode( [ 'providers' => $exports ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}
	echo "PASS operational-queue-evidence-contract: actual providers, bounded samples, dedup, ages, serialization, failed event, preview; zero fixture DB writes and HTTP\n";
} finally {
	remove_filter( $profile_cache, $profile );
	remove_filter( 'woocommerce_order_data_store', $store, PHP_INT_MAX );
	remove_filter( 'woocommerce_product_data_store', $product_store, PHP_INT_MAX );
	remove_filter( 'yoro_action_cache_enabled', $no_cache );
	remove_filter( 'pre_option_woocommerce_ship_to_countries', $shipping );
	remove_filter( 'user_has_cap', $permissions, PHP_INT_MAX );
	wp_set_current_user( $previous_user );
	remove_filter( 'query', $sql_guard );
	remove_filter( 'pre_http_request', $http_guard );
}
