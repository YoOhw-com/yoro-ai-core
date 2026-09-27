<?php
/**
 * YoRo canonical connector publisher and pull-runtime smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval 'require WP_PLUGIN_DIR . "/yoro-ai-core/tests/smoke/connector-runtime-contract.php";'
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

global $wpdb;


$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( class_exists( 'YoRo_AI_Core' ) && ! class_exists( 'YoRo_Connector_Runtime' ) ) {
	YoRo_AI_Core::init();
}

if ( class_exists( 'YoRo_Action_Registry' ) && 0 === count( YoRo_Action_Registry::all() ) ) {
	YoRo_Action_Registry::init();
	YoRo_Orders_Actions::register();
	YoRo_WordPress_Content_Actions::register();
}

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

foreach (
	[
		'YoRo_Commerce_Contract_Builder',
		'YoRo_Connector_Client',
		'YoRo_Connector_Snapshot_Publisher',
		'YoRo_Connector_Execution_Journal',
		'YoRo_Connector_Runtime',
	]
	as $class_name
) {
	$assert( class_exists( $class_name ), sprintf( '%s is not loaded.', $class_name ) );
}

$runtime_schedules = YoRo_Connector_Runtime::register_cron_schedule( [] );
$assert( 5 === YoRo_Connector_Runtime::MAX_DELIVERIES_PER_TICK && 45 === YoRo_Connector_Runtime::MAX_TICK_SECONDS, 'The runner must retain its five-delivery and 45-second caps.' );
$assert(
	MINUTE_IN_SECONDS === ( $runtime_schedules[ YoRo_Connector_Runtime::CRON_SCHEDULE ]['interval'] ?? 0 ),
	'The connector runtime cron schedule must run every minute.'
);

YoRo_Connector_Runtime::schedule();
$runtime_event = wp_get_scheduled_event( YoRo_Connector_Runtime::CRON_HOOK );
$assert( $runtime_event instanceof stdClass, 'The connector runtime must register a WordPress cron event.' );
$assert(
	YoRo_Connector_Runtime::CRON_SCHEDULE === ( $runtime_event->schedule ?? '' ),
	'The connector runtime must use its dedicated WordPress cron schedule.'
);
$assert(
	0 === (int) ( ( $runtime_event->timestamp ?? 0 ) % MINUTE_IN_SECONDS ),
	'The connector runtime cron event must align with the minute boundary.'
);

if ( function_exists( 'as_next_scheduled_action' ) ) {
	$assert(
	! as_next_scheduled_action( YoRo_Connector_Runtime::CRON_HOOK, [], 'yoro-ai-core' ),
	'The connector runtime must not depend on the Action Scheduler queue runner.'
	);
}

$admin_ids = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;
$original_user_id = get_current_user_id();
$assert( $admin_id > 0, 'A WordPress administrator is required for connector actor mapping.' );

$option_names = [
	YoRo_AI_Settings::OPTION_AI_API_STATUS,
	YoRo_Connector_Snapshot_Publisher::OPTION_MANIFEST_STATE,
	YoRo_Connector_Snapshot_Publisher::OPTION_CONTEXT_STATE,
	YoRo_Connector_Snapshot_Publisher::OPTION_SYNC_STATE,
	YoRo_WordPress_Admin_Actions::POLICY_OPTION,
	YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION,
];
$missing_option = new stdClass();
$original_options = [];

foreach ( $option_names as $option_name ) {
	$original_options[ $option_name ] = get_option( $option_name, $missing_option );
}

delete_option( YoRo_Connector_Snapshot_Publisher::OPTION_MANIFEST_STATE );
delete_option( YoRo_Connector_Snapshot_Publisher::OPTION_CONTEXT_STATE );
delete_option( YoRo_Connector_Snapshot_Publisher::OPTION_SYNC_STATE );
update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'no', false );
delete_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION );

$original_subject = $admin_id > 0
	? get_user_meta( $admin_id, YoRo_WordPress_Actor::SUBJECT_META_KEY, true )
	: '';

$original_access_exists = $admin_id > 0 && metadata_exists( 'user', $admin_id, YoRo_WordPress_Actor::ACCESS_META_KEY );
$original_access = $admin_id > 0 ? get_user_meta( $admin_id, YoRo_WordPress_Actor::ACCESS_META_KEY, true ) : null;

class YoRo_Connector_Smoke_Client extends YoRo_Connector_Client {
	public $manifest = [];
	public $context_pack = [];
	public $manifest_publications = 0;
	public $context_publications = 0;
	public $claim = [];
	public $claims = [];
	public $claim_calls = 0;
	public $claim_callback = null;
	public $events = [];
	public $renewals = [];
	public $results = [];

	public function publish_capability_manifest( array $payload ) {
		$this->manifest = $payload;
		$this->manifest_publications++;

		return [ 'success' => true, 'snapshot' => [ 'contract_id' => $payload['manifest_id'] ] ];
	}

	public function publish_context_pack( array $payload ) {
		$this->context_pack = $payload;
		$this->context_publications++;

		return [ 'success' => true, 'snapshot' => [ 'contract_id' => $payload['context_pack_id'] ] ];
	}

	public function claim_delivery() {
		$this->claim_calls++;

		if ( is_callable( $this->claim_callback ) ) {
			return ( $this->claim_callback )();
		}

		if ( ! empty( $this->claims ) ) {
			return array_shift( $this->claims );
		}

		return $this->claim;
	}

	public function renew_lease( $delivery_id, $claim_token, $lease_generation ) {
		$this->renewals[] = compact( 'delivery_id', 'claim_token', 'lease_generation' );

		return [ 'success' => true ];
	}

	public function publish_event( array $payload, $claim_token = '', $lease_generation = 0 ) {
		$this->events[] = compact( 'payload', 'claim_token', 'lease_generation' );

		return [ 'success' => true ];
	}

	public function publish_result( array $payload, $claim_token, $lease_generation ) {
		$this->results[] = compact( 'payload', 'claim_token', 'lease_generation' );

		return [ 'success' => true, 'result' => $payload ];
	}
}

try {
	if ( $admin_id > 0 ) {
		wp_set_current_user( $admin_id );
	}

	$installation_uuid = YoRo_AI_Settings::get_installation_uuid();
	$connection_id = 'connection_woocommerce_smoke';
	YoRo_AI_Settings::update_ai_api_status(
		[
			'account_connection' => [ 'connected' => true, 'status' => 'active' ],
			'organization'       => [ 'id' => 1, 'name' => 'Smoke Organization' ],
			'site'               => [ 'id' => 1, 'status' => 'active' ],
			'connection'         => [
				'id'                => 501,
				'installation_uuid' => $installation_uuid,
				'status'            => 'active',
			],
			'credential'         => [
				'id'                 => 601,
				'type'               => 'wordpress_connection',
				'site_connection_id' => 501,
				'expires_at'         => gmdate( 'c', time() + DAY_IN_SECONDS ),
			],
			'connector_runtime'   => [
				'connector_key'           => 'woocommerce',
				'connector_connection_id' => $connection_id,
				'execution_mode'          => 'remote_agent',
				'protocol_version'        => 1,
				'connector_version'       => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
				'delivery_protocol'       => 'pull_v1',
				'capability_manifest'     => [
					'current'     => false,
					'manifest_id' => null,
					'revision'    => null,
					'expires_at'  => null,
				],
				'cutover'                 => [
					'cutover_enabled'         => true,
					'connection_live_enabled' => true,
					'conformance_ready'       => true,
					'cutover_allowed'         => true,
					'checks'                  => [
						'active_connection'          => true,
						'remote_agent_mode'          => true,
						'compatible_driver'          => true,
						'operation_execution_feature' => true,
						'current_capability_manifest' => true,
						'current_context_pack'        => true,
						'runtime_credential'          => true,
						'conformance_attestation'     => true,
					],
				],
			],
		]
	);

	YoRo_WordPress_Actor::record_access(
		[
			'assigned'    => true,
			'status'      => 'active',
			'role'        => 'store_admin',
			'permissions' => YoRo_Capabilities::all(),
		]
	);
	$actor_payload = YoRo_WordPress_Actor::current_payload();
	$assert(
		$admin_id === YoRo_WordPress_Actor::user_id_for_subject( $actor_payload['subject'] ?? '' ),
		'The opaque WordPress subject must resolve to exactly one local user.'
	);
	delete_user_meta( $admin_id, YoRo_WordPress_Actor::SUBJECT_META_KEY );
	$assert(
		$admin_id === YoRo_WordPress_Actor::user_id_for_subject( $actor_payload['subject'] ?? '' ),
		'The opaque WordPress subject must recover its deterministic local actor mapping when user meta is missing.'
	);
	$assert(
		( $actor_payload['subject'] ?? '' ) === get_user_meta( $admin_id, YoRo_WordPress_Actor::SUBJECT_META_KEY, true ),
		'Recovered actor mappings must be cached for subsequent connector operations.'
	);
	$assert(
		0 === YoRo_WordPress_Actor::user_id_for_subject( str_repeat( 'f', 64 ) ),
		'An unknown opaque subject must never resolve to a local WordPress user.'
	);

	YoRo_Action_Registry::register(
		'test.list_result_count',
		[
			'label'        => 'List result count test',
			'write_action' => false,
			'callback'     => static function() {
				return [
					'success' => true,
					'data'    => [
						[ 'id' => 1 ],
						[ 'id' => 2 ],
					],
				];
			},
		]
	);
	$list_result = YoRo_Action_Executor::execute( 'test.list_result_count' );
	$assert( 2 === ( $list_result['count'] ?? 0 ), 'Normalized list data must expose an accurate item count.' );
	$list_result_json = wp_json_encode( $list_result );
	foreach ( [ 'execution_provider', 'provider_kind', 'selection_reason', 'candidate_tranche', 'native_mapping_' ] as $private_provider_field ) {
		$assert( false === strpos( $list_result_json, $private_provider_field ), sprintf( 'Direct action results must not expose internal provider field %s.', $private_provider_field ) );
	}

	$client = new YoRo_Connector_Smoke_Client();
	$publisher = new YoRo_Connector_Snapshot_Publisher( $client );
	$published = $publisher->publish();
	$assert( ! is_wp_error( $published ) && ! empty( $published['success'] ), 'Canonical connector snapshots must publish successfully.' );
	$republished = $publisher->publish();
	$assert( ! is_wp_error( $republished ) && ! empty( $republished['success'] ), 'A context refresh with unchanged capabilities must succeed.' );
	$assert( 1 === $client->manifest_publications, 'Unchanged capabilities must retain a stable manifest revision for conformance attestation.' );
	$assert( 2 === $client->context_publications, 'Live context must refresh independently from the stable capability manifest.' );

	$server_reset_status = YoRo_AI_Settings::get_ai_api_status();
	$server_reset_status['connector_runtime']['capability_manifest'] = [
		'current'     => false,
		'manifest_id' => null,
		'revision'    => null,
		'expires_at'  => null,
	];
	YoRo_AI_Settings::update_ai_api_status( $server_reset_status );
	$recovered = $publisher->publish();
	$assert( ! is_wp_error( $recovered ) && ! empty( $recovered['success'] ), 'A connector must recover when the server loses its canonical snapshots.' );
	$assert( 2 === $client->manifest_publications, 'A missing server manifest must override a locally fresh manifest cache.' );
	$assert( 3 === $client->context_publications, 'Snapshot recovery must republish context against the recovered manifest revision.' );
	$assert( 'yoro.commerce.capability_manifest' === ( $client->manifest['contract_type'] ?? '' ), 'Publisher must emit capability manifest v1.' );
	$assert( 'yoro.commerce.context_pack' === ( $client->context_pack['contract_type'] ?? '' ), 'Publisher must emit context pack v1.' );

	$actions = [];
	foreach ( (array) ( $client->manifest['actions'] ?? [] ) as $action ) {
		if ( is_array( $action ) && ! empty( $action['name'] ) ) {
			$actions[ $action['name'] ] = $action;
		}
	}

	$search_schema = $actions['orders.search']['input_schema']['properties'] ?? [];
	$get_details_schema = $actions['orders.get_details']['input_schema']['properties'] ?? [];
	$search_action = YoRo_Action_Registry::get( 'orders.search' );
	$assert( isset( $actions['orders.search'] ), 'Capability manifest must expose orders.search.' );
	foreach ( [ 'orders.search', 'orders.get_details', 'orders.find_by_customer', 'customers.lookup', 'store.get_daily_briefing', 'operations.get_attention_queue', 'products.get_inventory_queue', 'content.search', 'content.get', 'comments.list', 'media.search', 'media.get', 'site.get_info', 'site.get_health', 'site.get_update_status', 'products.search', 'products.get_details', 'orders.get_timeline', 'fulfillment.get_tracking' ] as $canonical_read ) {
		$assert( false === ( $actions[ $canonical_read ]['output_schema']['properties']['data']['additionalProperties'] ?? true ), sprintf( '%s must publish a closed action-level data schema.', $canonical_read ) );
		$assert( 1 === ( $actions[ $canonical_read ]['extensions']['yoro.contracts']['execution_payload_version'] ?? 0 ), sprintf( '%s must publish the canonical execution payload marker.', $canonical_read ) );
		$assert( ! empty( $actions[ $canonical_read ]['extensions']['yoro.contracts']['strict_output_validation_ready'] ), sprintf( '%s must publish accurate action-level strict validation readiness.', $canonical_read ) );
	}
	$assert( 3 === ( $actions['orders.get_details']['extensions']['yoro.contracts']['fixture_revision'] ?? 0 ), 'Order investigation reads must publish fixture revision 3.' );
	$assert( isset( $actions['orders.find_by_customer']['input_schema']['properties']['order_id'] ), 'Customer-order lookup must accept an explicit order reference.' );
	$assert( isset( $actions['customers.lookup']['input_schema']['properties']['order_id'] ), 'Customer lookup must accept an explicit order reference.' );
	$investigation_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload(
		[
			'success'   => true,
			'action'    => 'orders.get_details',
			'summary'   => 'Order investigation fixture.',
			'read_only' => true,
			'orders'    => [
				[
					'id'                => 91,
					'number'            => '91',
					'status'            => 'failed',
					'status_label'      => 'Failed',
					'total'             => '99.00',
					'currency'          => 'USD',
					'date_created'      => '2026-07-21T01:00:00+00:00',
					'age_hours'         => 96,
					'version'           => str_repeat( '1', 64 ),
					'customer_id'       => 7,
					'customer_name'     => 'Ada',
					'billing_email'     => 'a***@example.test',
					'billing_phone'     => '***1234',
					'requires_shipping' => true,
					'line_items'        => [],
					'notes'             => [
						[ 'date' => '2026-07-21T01:30:00+00:00', 'customer' => false, 'content' => 'secret internal note' ],
					],
					'related_orders'    => [
						[
							'id'           => 92,
							'number'       => '92',
							'status'       => 'processing',
							'status_label' => 'Processing',
							'total'        => '99.00',
							'currency'     => 'USD',
							'date_created' => '2026-07-21T01:10:00+00:00',
							'version'      => str_repeat( '2', 64 ),
						],
					],
				],
			],
		],
		'orders.get_details'
	);
	$investigation_order = $investigation_payload['data']['orders'][0] ?? [];
	$investigation_signal_codes = array_column( (array) ( $investigation_order['signals'] ?? [] ), 'code' );
	$assert( in_array( 'failed_payment', $investigation_signal_codes, true ), 'Order investigation must expose deterministic failed-payment evidence.' );
	$assert( in_array( 'duplicate_order_candidate', $investigation_signal_codes, true ), 'Order investigation must expose deterministic duplicate-order evidence.' );
	$assert( 'internal' === ( $investigation_order['timeline'][1]['visibility'] ?? '' ), 'Internal order notes must remain a distinct timeline visibility.' );
	$assert( false === ( $investigation_order['suggested_next_steps'][0]['requires_write'] ?? true ), 'Suggested investigation steps must remain read-only.' );
	$assert( false === strpos( wp_json_encode( $investigation_order ), 'secret internal note' ), 'Canonical investigation data must not expose note content.' );
	$brief_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload(
		[
			'success'          => true,
			'action'           => 'store.get_daily_briefing',
			'summary'          => 'Morning operations fixture.',
			'read_only'        => true,
			'window'           => [
				'key'      => 'today',
				'label'    => 'Today',
				'start'    => '2026-07-21 00:00:00',
				'end'      => '2026-07-21 23:59:59',
				'timezone' => 'UTC',
			],
			'metrics'          => [
				'order_count'         => 1,
				'gross_sales'         => '99.00',
				'average_order_value' => '99.00',
				'currency'            => 'USD',
				'is_complete'         => true,
			],
			'comparison'       => [
				'window'  => [
					'key'      => 'previous_equivalent_window',
					'label'    => 'Previous period',
					'start'    => '2026-07-20 00:00:00',
					'end'      => '2026-07-20 23:59:59',
					'timezone' => 'UTC',
				],
				'metrics' => [
					'order_count'         => [ 'current' => 1, 'previous' => 0, 'trend' => 'up', 'delta' => 'new vs previous' ],
					'gross_sales'         => [ 'current' => '99.00', 'previous' => '0.00', 'trend' => 'up', 'delta' => 'new vs previous' ],
					'average_order_value' => [ 'current' => '99.00', 'previous' => '0.00', 'trend' => 'up', 'delta' => 'new vs previous' ],
				],
			],
			'counts'           => [ 'attention' => 1, 'fulfillment' => 0, 'customer_followups' => 0, 'inventory' => 0 ],
			'attention_items'  => [
				[
					'order_id'       => 91,
					'order_number'   => '91',
					'status'         => 'pending',
					'status_label'   => 'Pending',
					'total'          => '99.00',
					'currency'       => 'USD',
					'created_at'     => '2026-07-19T01:00:00+00:00',
					'age_hours'      => 49,
					'reason'         => 'pending_payment_older_than_24h',
					'severity'       => 'high',
					'priority_score' => 169,
					'version'        => str_repeat( '1', 64 ),
					'extensions'     => [],
				],
			],
			'inventory_items'  => [],
			'priorities'       => [],
		],
		'store.get_daily_briefing'
	);
	$assert( [ 'window', 'metrics', 'comparison', 'counts', 'attention_items', 'inventory_items', 'priorities' ] === array_keys( $brief_payload['data'] ?? [] ), 'Daily briefing runtime data must use the exact canonical roots.' );
	$assert( 91 === ( $brief_payload['data']['attention_items'][0]['order_id'] ?? 0 ), 'Daily briefing runtime data must preserve ranked order attention.' );
	$assert( ! empty( $brief_payload['extensions']['wordpress.execution']['legacy_data'] ), 'Daily briefing legacy detail must remain under wordpress.execution.' );
	$attention_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload(
		[
			'success'          => true,
			'action'           => 'operations.get_attention_queue',
			'summary'          => 'Attention fixture.',
			'read_only'        => true,
			'orders'           => [
				[
					'id'                  => 91,
					'number'              => '91',
					'status'              => 'pending',
					'status_label'        => 'Pending',
					'total'               => '99.00',
					'currency'            => 'USD',
					'date_created'        => '2026-07-19T01:00:00+00:00',
					'age_hours'           => 49,
					'queue_topic'         => 'pending_payment_older_than_24h',
					'case_priority_score' => 169,
					'version'             => str_repeat( '1', 64 ),
				],
			],
			'queue_item_count' => 3,
		],
		'operations.get_attention_queue'
	);
	$assert( 1 === ( $attention_payload['data']['count'] ?? 0 ), 'Attention queue count must represent visible canonical items.' );
	$assert( 3 === ( $attention_payload['data']['total_matches'] ?? 0 ), 'Attention queue must preserve the total number of matching queue entries.' );
	$media_item_schema = $actions['media.get']['output_schema']['properties']['data']['properties']['item'] ?? [];
	$assert( false === ( $media_item_schema['additionalProperties'] ?? true ), 'Canonical media items must reject undeclared root metadata.' );
	$assert( ! isset( $media_item_schema['properties']['metadata'] ), 'Provider-owned media metadata must not remain a canonical root field.' );
	$assert( isset( $media_item_schema['properties']['extensions'] ), 'Canonical media items must expose a provider-owned extension boundary.' );
	$media_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload(
		[
			'success' => true,
			'action'  => 'media.get',
			'data'    => [
				'media_id'   => 44,
				'title'      => 'Product photo',
				'mime_type'  => 'image/jpeg',
				'url'        => 'https://example.test/uploads/product.jpg',
				'extensions' => [
					'wordpress.media' => [
						'metadata' => [ 'width' => 1200, 'height' => 800 ],
					],
				],
			],
		],
		'media.get'
	);
	$assert( ! isset( $media_payload['data']['item']['metadata'] ), 'Canonical media runtime data must not leak provider metadata at the root.' );
	$assert( 1200 === ( $media_payload['data']['item']['extensions']['wordpress.media']['metadata']['width'] ?? 0 ), 'Canonical media runtime data must preserve provider metadata under wordpress.media.' );
	foreach ( [ 'content.search', 'content.get', 'content.create_draft', 'content.update_draft', 'content.preview_changes', 'content.publish', 'comments.list', 'comments.reply', 'comments.update_status', 'media.search', 'media.upload' ] as $managed_action ) {
		$assert( isset( $actions[ $managed_action ] ), sprintf( 'Capability manifest must expose %s.', $managed_action ) );
	}
	$assert( empty( $actions['content.search']['write_action'] ), 'Content reads must remain permitless read operations.' );
	$assert(
		YoRo_WordPress_Content_Actions::allowed_post_types() === ( $actions['content.search']['input_schema']['properties']['post_type']['enum'] ?? [] ),
		'Content search must publish the exact connector-owned post type allowlist as an input enum.'
	);
	$assert(
		! in_array( 'post_type', (array) ( $actions['content.search']['input_schema']['required'] ?? [] ), true ),
		'Content search post_type must remain optional for broad searches.'
	);
	$assert(
		false !== strpos( (string) ( $actions['content.search']['extensions']['yoro.conversation']['param_hints']['post_type'] ?? '' ), 'omit' ),
		'Content search must tell the conversation brain to omit an unspecified post type.'
	);
	$assert( 'mutate' === ( $actions['content.create_draft']['effect'] ?? '' ) && ! empty( $actions['content.create_draft']['write_action'] ), 'Persisted content drafts must remain canonical writes.' );
	$assert( ! empty( $actions['content.publish']['requires_confirmation'] ), 'Publishing must require confirmation.' );
	$assert( false === ( $actions['content.publish']['output_schema']['additionalProperties'] ?? true ), 'Canonical action outputs must fail closed.' );
	$assert( ! empty( $actions['content.publish']['output_schema']['properties']['data'] ), 'Canonical action outputs must publish the shared execution payload schema.' );
	$assert( 'operation_journal' === ( $actions['content.publish']['extensions']['wordpress.action']['idempotency_strategy'] ?? '' ), 'Connector writes must bind to the durable operation journal.' );
	$assert( 'optimistic_version' === ( $actions['content.publish']['extensions']['wordpress.action']['concurrency_strategy'] ?? '' ), 'Versioned connector writes must declare optimistic concurrency.' );
	$assert( isset( $actions['orders.update_status'] ), 'Versioned WooCommerce status writes must be published.' );
	$assert( 'per_item_optimistic_version' === ( $actions['orders.update_status']['extensions']['wordpress.action']['concurrency_strategy'] ?? '' ), 'Order status writes must publish per-item optimistic concurrency.' );
	$assert( in_array( 'expected_versions', (array) ( $actions['orders.update_status']['required_params'] ?? [] ), true ), 'Order status writes must require exact versions for every target.' );
	$assert( isset( $actions['products.update_stock'] ), 'Versioned WooCommerce inventory writes must be published.' );
	$assert( 'optimistic_version' === ( $actions['products.update_stock']['extensions']['wordpress.action']['concurrency_strategy'] ?? '' ), 'Inventory writes must publish optimistic concurrency.' );
	$assert( in_array( 'expected_version', (array) ( $actions['products.update_stock']['required_params'] ?? [] ), true ), 'Inventory writes must require the product version from the latest read.' );
	$assert( isset( $actions['products.update_pricing'] ), 'Versioned WooCommerce regular-price writes must be published.' );
	$assert( 'optimistic_version' === ( $actions['products.update_pricing']['extensions']['wordpress.action']['concurrency_strategy'] ?? '' ), 'Regular-price writes must publish optimistic concurrency.' );
	$assert( [ 'product_id', 'regular_price', 'expected_version' ] === ( $actions['products.update_pricing']['required_params'] ?? [] ), 'Regular-price writes must publish only the exact bounded input.' );
	$assert( isset( $actions['products.update_content'] ), 'Versioned WooCommerce content writes must be published.' );
	$assert( 'optimistic_version' === ( $actions['products.update_content']['extensions']['wordpress.action']['concurrency_strategy'] ?? '' ), 'Content writes must publish optimistic concurrency.' );
	$assert( [ 'product_id', 'field', 'value', 'expected_version' ] === ( $actions['products.update_content']['required_params'] ?? [] ), 'Content writes must publish only the exact bounded input.' );
	$assert( isset( $actions['products.create_draft'] ), 'Minimal WooCommerce product draft creation must be published.' );
	$assert( 'create_only' === ( $actions['products.create_draft']['extensions']['wordpress.action']['concurrency_strategy'] ?? '' ), 'Product draft creation must publish create-only concurrency.' );
	$assert( [ 'name' ] === ( $actions['products.create_draft']['required_params'] ?? [] ), 'Product draft creation must publish only the exact bounded name input.' );
	$assert( isset( $actions['orders.add_note'] ), 'Append-only order notes may be published with journal idempotency.' );
	$assert( 'append_only' === ( $actions['orders.add_note']['extensions']['wordpress.action']['concurrency_strategy'] ?? '' ), 'Append-only order notes must declare their concurrency strategy.' );
	foreach ( [ 'orders.email_customer_preview', 'orders.email_customer_template', 'orders.use_email_template', 'orders.add_private_note_template' ] as $deprecated_alias ) {
		$assert( ! isset( $actions[ $deprecated_alias ] ), sprintf( 'Deprecated alias %s must stay out of the canonical manifest.', $deprecated_alias ) );
	}
	$assert( ! isset( $actions['plugins.activate'] ), 'Administration actions must stay unpublished while local policy or backup attestation is unavailable.' );
	$assert( empty( $client->manifest['extensions']['wordpress.runtime']['admin_actions_ready'] ), 'The manifest must disclose that administration actions are unavailable.' );

	update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'yes', false );
	update_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, time(), false );
	$admin_ready_manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( YoRo_Connector_Snapshot_Publisher::runtime(), time() + 1 );
	$admin_ready_names = wp_list_pluck( (array) ( $admin_ready_manifest['actions'] ?? [] ), 'name' );
	$assert( ! in_array( 'plugins.activate', $admin_ready_names, true ), 'Administration actions must remain local-only even when local policy and backup gates are ready.' );
	$assert( empty( $admin_ready_manifest['extensions']['wordpress.runtime']['admin_actions_ready'] ), 'The connector manifest must not advertise local administration capability readiness.' );
	update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'no', false );
	delete_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION );
	$assert(
		in_array( 'expected_version', (array) ( $actions['content.publish']['required_params'] ?? [] ), true )
		&& in_array( 'expected_preview_hash', (array) ( $actions['content.publish']['required_params'] ?? [] ), true ),
		'Publishing must bind confirmation to the exact version and preview hash.'
	);
	$search_semantics = $actions['orders.search']['extensions']['yoro.conversation'] ?? [];
	$assert( 1 === ( $search_semantics['version'] ?? 0 ), 'Capability manifest actions must publish yoro.conversation v1 semantics.' );
	$assert( 'orders_search' === ( $search_semantics['intent_family'] ?? '' ), 'Canonical conversation semantics must retain the connector-owned intent family.' );
	$assert( is_array( $search_semantics['slot_contract'] ?? null ), 'Canonical conversation semantics must retain a structured slot contract.' );
	$assert( [ 'string', 'array' ] === ( $search_schema['status']['type'] ?? null ), 'Legacy union types must remain unions in canonical JSON Schema.' );
	$assert( 'number' === ( $search_schema['total_min']['type'] ?? '' ), 'Legacy float types must become JSON Schema numbers.' );
	$assert( 'integer' === ( $get_details_schema['order_ids']['items']['type'] ?? '' ), 'Legacy typed arrays must preserve their item type.' );
	$assert(
		[ $search_action['permission'] ?? '' ] === array_values( (array) ( $actions['orders.search']['required_permissions'] ?? [] ) ),
		'Every action must publish an explicit local permission boundary.'
	);
	$assert( [ 'store', 'integrations' ] === wp_list_pluck( (array) ( $client->context_pack['domains'] ?? [] ), 'name' ), 'Context pack must contain only safe store and integration domains.' );
	$context_json = wp_json_encode( $client->context_pack );
	$assert( false === strpos( $context_json, 'admin_email' ), 'Context pack must not publish the WordPress administrator email.' );
	$assert( false === strpos( $context_json, (string) wp_get_current_user()->user_email ), 'Context pack must not publish the current actor email.' );

	$manifest_state = YoRo_Connector_Snapshot_Publisher::current_manifest_state();
	$manifest_identity_before_execution = [
		'manifest_id' => $manifest_state['manifest_id'] ?? '',
		'revision'    => $manifest_state['revision'] ?? 0,
		'actions_hash' => hash( 'sha256', wp_json_encode( $client->manifest['actions'] ?? [] ) ),
	];
	$delivery_id = 'delivery_' . hash( 'sha256', 'woocommerce-runtime-smoke' );
	$claim_token = 'claim_' . hash( 'sha256', 'woocommerce-runtime-smoke-token' );
	$expires_at = gmdate( 'c', time() + ( 10 * MINUTE_IN_SECONDS ) );
	$operation = [
		'contract_type'          => 'yoro.commerce.operation_envelope',
		'schema_version'         => 1,
		'envelope_id'            => 'envelope_' . hash( 'sha256', 'woocommerce-runtime-smoke-envelope' ),
		'workflow_id'            => 'workflow_' . hash( 'sha256', 'woocommerce-runtime-smoke-workflow' ),
		'operation_id'           => 'operation_1',
		'position'               => 1,
		'connector_key'          => 'woocommerce',
		'connector_connection_id' => $connection_id,
		'manifest_id'            => $manifest_state['manifest_id'],
		'manifest_revision'      => $manifest_state['revision'],
		'idempotency_key'        => 'operation_' . hash( 'sha256', 'woocommerce-runtime-smoke-idempotency' ),
		'execution_mode'         => 'remote_agent',
		'action'                 => 'orders.search',
		'params'                 => [ 'limit' => 1 ],
		'actor'                  => [
			'contract_type'          => 'yoro.commerce.actor_context',
			'schema_version'         => 1,
			'actor_type'             => 'user',
			'subject'                => $actor_payload['subject'],
			'operator_id'            => 'operator_smoke_1',
			'workspace_id'           => 'workspace_smoke_1',
			'connector_connection_id' => $connection_id,
			'role'                   => 'store_admin',
			'permissions'             => [ $search_action['permission'] ],
			'authenticated_at'        => gmdate( 'c' ),
			'assertion_id'            => 'assertion_smoke_1',
			'extensions'              => [
				'wordpress.identity' => [
					'subject_version'  => 1,
					'wordpress_subject' => $actor_payload['subject'],
				],
			],
		],
		'plan_hash'              => hash( 'sha256', 'woocommerce-runtime-smoke-plan' ),
		'preview_hash'           => hash( 'sha256', 'woocommerce-runtime-smoke-preview' ),
		'authorization'          => [
			'execution_permit' => 'permit_' . hash( 'sha256', 'woocommerce-runtime-smoke-permit' ),
			'permit_expires_at' => $expires_at,
		],
		'requested_at'           => gmdate( 'c' ),
		'expires_at'             => $expires_at,
		'trace_id'               => 'trace_' . hash( 'sha256', 'woocommerce-runtime-smoke-trace' ),
	];
	$client->claim = [
		'success'  => true,
		'delivery' => [
			'delivery_id'     => $delivery_id,
			'claim_token'     => $claim_token,
			'lease_generation' => 1,
			'operations'      => [ $operation ],
		],
	];

	$shared_executor_calls = 0;
	$shared_executor_probe = static function ( $executed_action ) use ( &$shared_executor_calls ) {
		if ( 'orders.search' === $executed_action ) {
			$shared_executor_calls++;
		}
	};
	add_action( 'yoro_before_action_execute', $shared_executor_probe, 10, 1 );
	$runtime_result = ( new YoRo_Connector_Runtime( $client ) )->run_once();
	remove_action( 'yoro_before_action_execute', $shared_executor_probe, 10 );
	$assert( ! is_wp_error( $runtime_result ) && ! empty( $runtime_result['success'] ), 'Pull runtime must complete one claimed read-only operation.' );
	$assert( 1 === $shared_executor_calls, 'Remote connector execution must pass through the shared action executor exactly once.' );
	$assert( 1 === count( $client->events ), 'Pull runtime must acknowledge the claim once.' );
	$assert( 1 === count( $client->renewals ), 'Pull runtime must renew the lease before local execution.' );
	$assert( 1 === count( $client->results ), 'Pull runtime must publish one canonical execution result.' );
	$assert( 'operation.claimed' === ( $client->events[0]['payload']['event_type'] ?? '' ), 'Pull runtime acknowledgement must use operation.claimed.' );
	$assert( 'executed' === ( $client->results[0]['payload']['status'] ?? '' ), 'The safe read smoke operation must finish as executed.' );
	$canonical_result = $client->results[0]['payload']['result'] ?? [];
	$assert( false === ( $actions['orders.search']['output_schema']['additionalProperties'] ?? true ), 'Read outputs must use the exact shared execution schema.' );
	$assert( array_keys( (array) $canonical_result ) === ( $actions['orders.search']['output_schema']['required'] ?? [] ), 'Runtime result roots must exactly match the published output schema.' );
	$assert( isset( $canonical_result['data']['orders'] ) || isset( $canonical_result['data']['items'] ), 'Canonical results must retain action data under the stable data object.' );
	$canonical_result_json = wp_json_encode( $canonical_result );
	foreach ( [ 'execution_provider', 'provider_kind', 'selection_reason', 'candidate_tranche', 'native_mapping_' ] as $private_provider_field ) {
		$assert( false === strpos( $canonical_result_json, $private_provider_field ), sprintf( 'Canonical connector results must not expose internal provider field %s.', $private_provider_field ) );
	}
	$assert( $claim_token === ( $client->results[0]['claim_token'] ?? '' ), 'Result publication must retain exact lease authority.' );
	$assert( 1 === ( $client->results[0]['lease_generation'] ?? 0 ), 'Result publication must retain the lease generation.' );
	$assert( $admin_id === get_current_user_id(), 'Connector execution must restore the original WordPress user.' );

	update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'yes', false );
	update_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, time(), false );
	$b3_order = wc_create_order( [ 'status' => 'pending', 'customer_id' => 0 ] );
	$b3_operation = $operation;
	try {
		$b3_order->set_shipping_city( 'Before' );
		$b3_order->save();
		$b3_operation['envelope_id'] = 'envelope_' . hash( 'sha256', 'b3-' . wp_generate_uuid4() );
		$b3_operation['idempotency_key'] = 'operation_' . hash( 'sha256', 'b3-' . wp_generate_uuid4() );
		$b3_operation['operation_id'] = 'operation_b3';
		$b3_operation['action'] = 'orders.update_addresses';
		$b3_operation['params'] = [ 'order_id' => $b3_order->get_id(), 'expected_version' => YoRo_Order_Presenter::order_version( wc_get_order( $b3_order->get_id() ) ), 'shipping' => [ 'city' => 'After' ] ];
		$b3_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'orders.update_addresses' )['permission'] ];
		$b3_client = new YoRo_Connector_Smoke_Client();
		$b3_client->claim = $client->claim;
		$b3_client->claim['delivery']['operations'] = [ $b3_operation ];
		$b3_calls = 0;
		$b3_probe = static function ( $executed_action ) use ( &$b3_calls ) {
			if ( 'orders.update_addresses' === $executed_action ) { ++$b3_calls; }
		};
		add_action( 'yoro_before_action_execute', $b3_probe, 10, 1 );
		try {
			( new YoRo_Connector_Runtime( $b3_client ) )->run_once();
			$assert( 1 === $b3_calls, 'B3 remote runtime executes through the shared executor exactly once.' );
			$b3_result = $b3_client->results[0]['payload'] ?? [];
			$assert( 'executed' === ( $b3_result['status'] ?? '' ), 'B3 runtime returns a canonical successful write result.' );
			$assert( [ 'order_id', 'changed_fields', 'current_version' ] === array_keys( $b3_result['result']['data'] ?? [] ), 'B3 runtime output has only bounded canonical data roots.' );
			$assert( 'After' === wc_get_order( $b3_order->get_id() )->get_shipping_city(), 'B3 runtime persists the exact patch.' );
			( new YoRo_Connector_Runtime( $b3_client ) )->run_once();
			$assert( 1 === $b3_calls && 2 === count( $b3_client->results ), 'B3 journal replay republishes the prior result without executing or saving again.' );
			$assert( wp_json_encode( $b3_client->results[0]['payload'] ) === wp_json_encode( $b3_client->results[1]['payload'] ), 'B3 replay retains the exact recorded outcome.' );
		} finally {
			remove_action( 'yoro_before_action_execute', $b3_probe, 10 );
		}
	} finally {
		YoRo_Connector_Execution_Journal::clear( $b3_operation );
		if ( $b3_order instanceof WC_Order ) { $b3_order->delete( true ); }
	}

	// C2 remote authority must originate at this validated journal-owning call site.
	$c2_product = new WC_Product_Simple(); $c2_order = null; $c2_operations = [];
	try {
		$c2_file = new WC_Product_Download(); $c2_file->set_id( 'c2-file' ); $c2_file->set_name( 'Fixture' ); $c2_file->set_file( 'https://example.invalid/fixture.zip' );
		$c2_product->set_name( 'C2 runtime fixture' ); $c2_product->set_downloadable( true ); $c2_product->set_downloads( [ $c2_file ] ); $c2_product->set_download_limit( -1 ); $c2_product->set_download_expiry( -1 ); $c2_product->save();
		foreach ( [ false, true ] as $c2_fail ) {
			$c2_order = wc_create_order(); $c2_order->add_product( $c2_product, 1 ); $c2_order->set_status( 'completed' ); $c2_order->save();
			WC_Data_Store::load( 'customer-download' )->delete_by_order_id( $c2_order->get_id() );
			$c2_items = $c2_order->get_items( 'line_item' ); $c2_item = reset( $c2_items );
			$c2_state = YoRo_Orders_Read_Service::download_regeneration_state( wc_get_order( $c2_order->get_id() ) );
			$c2_guard = $c2_state['guard'];
			$c2_float_quantity = static function ( $actual, $item ) use ( $c2_item ) { return $item->get_id() === $c2_item->get_id() ? 1.0 : $actual; };
			add_filter( 'woocommerce_order_item_get_quantity', $c2_float_quantity, 10, 2 );
			try { $c2_float_state = YoRo_Orders_Read_Service::download_regeneration_state( wc_get_order( $c2_order->get_id() ) ); }
			finally { remove_filter( 'woocommerce_order_item_get_quantity', $c2_float_quantity, 10 ); }
			$assert( in_array( $c2_item->get_quantity(), [ 1, 1.0 ], true )
				&& $c2_guard['eligible'] && 'eligible' === $c2_guard['blocked_reason'] && 64 === strlen( $c2_guard['state_token'] )
				&& $c2_float_state['guard']['eligible'] && $c2_guard['state_token'] === $c2_float_state['guard']['state_token']
				&& $c2_state['sources'] === $c2_float_state['sources']
				&& 1 === $c2_state['sources'][0]['quantity'] && is_int( $c2_state['sources'][0]['quantity'] ),
				'C2 integral-float quantity mints the same eligible guard with integer source material.' );
			$c2_operation = $operation; $c2_operation['envelope_id'] = 'envelope_' . hash( 'sha256', wp_generate_uuid4() );
			$c2_operation['idempotency_key'] = 'operation_' . hash( 'sha256', wp_generate_uuid4() ); $c2_operation['action'] = 'orders.regenerate_download_permissions';
			$c2_operation['params'] = [ 'order_id' => $c2_order->get_id(), 'expected_version' => $c2_guard['current_version'], 'expected_download_permissions_state' => $c2_guard['state_token'] ];
			$c2_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'orders.regenerate_download_permissions' )['permission'] ]; $c2_operations[] = $c2_operation;
			$c2_client = new YoRo_Connector_Smoke_Client(); $c2_client->claim = $client->claim; $c2_client->claim['delivery']['operations'] = [ $c2_operation ];
			$c2_calls = 0; $c2_probe = static function ( $name ) use ( &$c2_calls ) { if ( 'orders.regenerate_download_permissions' === $name ) { $c2_calls++; } };
			$c2_throw = static function () { throw new RuntimeException( 'private fixture failure' ); };
			add_action( 'yoro_before_action_execute', $c2_probe ); if ( $c2_fail ) { add_filter( 'woocommerce_downloadable_file_permission', $c2_throw ); }
			try {
				( new YoRo_Connector_Runtime( $c2_client ) )->run_once();
				$c2_result = $c2_client->results[0]['payload'] ?? [];
				if ( ( $c2_fail ? 'failed' : 'executed' ) !== ( $c2_result['status'] ?? '' ) || 1 !== $c2_calls ) { fwrite( STDERR, wp_json_encode( [ 'c2_calls' => $c2_calls, 'status' => $c2_result['status'] ?? null, 'error' => $c2_result['error'] ?? null ] ) . PHP_EOL ); }
				$assert( ( $c2_fail ? 'failed' : 'executed' ) === ( $c2_result['status'] ?? '' ), 'C2 validated remote runtime preserves verified success or ambiguous failure.' );
				( new YoRo_Connector_Runtime( $c2_client ) )->run_once();
				$assert( 1 === $c2_calls && 2 === count( $c2_client->results ) && wp_json_encode( $c2_result ) === wp_json_encode( $c2_client->results[1]['payload'] ), 'C2 journal replay never repeats destructive execution, including partial failure.' );
			} finally { remove_action( 'yoro_before_action_execute', $c2_probe ); remove_filter( 'woocommerce_downloadable_file_permission', $c2_throw ); }
			WC_Data_Store::load( 'customer-download' )->delete_by_order_id( $c2_order->get_id() ); $c2_order->delete( true ); $c2_order = null;
		}
	} finally {
		foreach ( $c2_operations as $c2_operation ) { YoRo_Connector_Execution_Journal::clear( $c2_operation ); }
		if ( $c2_order instanceof WC_Order ) { WC_Data_Store::load( 'customer-download' )->delete_by_order_id( $c2_order->get_id() ); $c2_order->delete( true ); }
		$c2_product->delete( true );
	}

	// Relative stock uses the validated runtime's journal; replay includes ambiguous outcomes.
	$c3_product = new WC_Product_Simple(); $c3_operations = [];
	$c3_manage = get_option( 'woocommerce_manage_stock', 'yes' );
	try {
		update_option( 'woocommerce_manage_stock', 'yes' );
		$c3_product->set_name( 'C3 runtime fixture' ); $c3_product->set_manage_stock( true ); $c3_product->set_stock_quantity( 20 ); $c3_product->save();
		foreach ( [ false, true ] as $c3_fail ) {
			clean_post_cache( $c3_product->get_id() ); $c3_current = wc_get_product( $c3_product->get_id() );
			$c3_operation = $operation; $c3_operation['envelope_id'] = 'envelope_' . hash( 'sha256', wp_generate_uuid4() );
			$c3_operation['idempotency_key'] = 'operation_' . hash( 'sha256', wp_generate_uuid4() ); $c3_operation['action'] = 'products.adjust_stock';
			$c3_operation['params'] = [ 'product_id' => $c3_product->get_id(), 'stock_delta' => -2, 'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $c3_current ) ];
			$c3_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'products.adjust_stock' )['permission'] ]; $c3_operations[] = $c3_operation;
			$c3_client = new YoRo_Connector_Smoke_Client(); $c3_client->claim = $client->claim; $c3_client->claim['delivery']['operations'] = [ $c3_operation ];
			$c3_calls = 0; $c3_probe = static function ( $name ) use ( &$c3_calls ) { if ( 'products.adjust_stock' === $name ) { $c3_calls++; } };
			$c3_throw = static function () { throw new RuntimeException( 'after stock arithmetic failure' ); };
			add_action( 'yoro_before_action_execute', $c3_probe );
			if ( $c3_fail ) { add_action( 'woocommerce_updated_product_stock', $c3_throw ); }
			try {
				( new YoRo_Connector_Runtime( $c3_client ) )->run_once();
				$c3_result = $c3_client->results[0]['payload'] ?? [];
				if ( 1 !== $c3_calls || ( $c3_fail ? 'failed' : 'executed' ) !== ( $c3_result['status'] ?? '' ) ) { fwrite( STDERR, wp_json_encode( [ 'c3_calls'=>$c3_calls, 'result'=>$c3_result ] ) . PHP_EOL ); }
				$assert( ( $c3_fail ? 'failed' : 'executed' ) === ( $c3_result['status'] ?? '' ), 'C3 validated remote execution must preserve signed success or reconciliation failure.' );
				( new YoRo_Connector_Runtime( $c3_client ) )->run_once();
				$assert( 1 === $c3_calls && 2 === count( $c3_client->results ) && wp_json_encode( $c3_result ) === wp_json_encode( $c3_client->results[1]['payload'] ), 'C3 duplicate delivery must not repeat success or ambiguous arithmetic.' );
				clean_post_cache( $c3_product->get_id() );
				$assert( ( $c3_fail ? 16 : 18 ) === (int) wc_get_product( $c3_product->get_id() )->get_stock_quantity(), 'Each unique C3 operation applies its delta once.' );
			} finally { remove_action( 'yoro_before_action_execute', $c3_probe ); remove_action( 'woocommerce_updated_product_stock', $c3_throw ); }
		}
	} finally {
		foreach ( $c3_operations as $c3_operation ) { YoRo_Connector_Execution_Journal::clear( $c3_operation ); }
		$c3_product->delete( true ); update_option( 'woocommerce_manage_stock', $c3_manage );
	}

	$coupon_fixture = new WC_Coupon();
	$coupon_operation = $operation;
	try {
		$coupon_fixture->set_code( 'yoro-runtime-' . wp_generate_uuid4() );
		$coupon_fixture->set_discount_type( 'fixed_cart' );
		$coupon_fixture->set_amount( '10' );
		$coupon_fixture->save();
		$coupon_operation['envelope_id'] = 'envelope_' . hash( 'sha256', wp_generate_uuid4() );
		$coupon_operation['idempotency_key'] = 'operation_' . hash( 'sha256', wp_generate_uuid4() );
		$coupon_operation['operation_id'] = 'operation_coupon_' . wp_generate_uuid4();
		$coupon_operation['action'] = 'coupons.update';
		$coupon_operation['params'] = [ 'coupon_id' => $coupon_fixture->get_id(), 'discount_amount' => '12.50', 'expected_version' => YoRo_Coupons_Service::coupon_version( new WC_Coupon( $coupon_fixture->get_id() ) ) ];
		$coupon_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'coupons.update' )['permission'] ];
		$coupon_client = new YoRo_Connector_Smoke_Client();
		$coupon_client->claim = $client->claim;
		$coupon_client->claim['delivery']['operations'] = [ $coupon_operation ];
		( new YoRo_Connector_Runtime( $coupon_client ) )->run_once();
		$coupon_result = $coupon_client->results[0]['payload'] ?? [];
		if ( 'executed' !== ( $coupon_result['status'] ?? '' ) ) { fwrite( STDERR, wp_json_encode( $coupon_result ) . PHP_EOL ); }
		$assert( 'executed' === ( $coupon_result['status'] ?? '' ), 'Coupon update must execute with journal-owned remote authority.' );
		$assert( [ 'coupon_id', 'coupon_code', 'discount_type', 'previous_discount_amount', 'discount_amount', 'current_version' ] === array_keys( $coupon_result['result']['data'] ?? [] ), 'Coupon update must return the exact closed result.' );
		$assert( '12.50' === ( $coupon_result['result']['data']['discount_amount'] ?? '' ), 'Coupon amount must preserve owner decimal normalization.' );
		( new YoRo_Connector_Runtime( $coupon_client ) )->run_once();
		$assert( 2 === count( $coupon_client->results ) && wp_json_encode( $coupon_result ) === wp_json_encode( $coupon_client->results[1]['payload'] ), 'Coupon replay must reuse the exact durable result.' );
	} finally {
		YoRo_Connector_Execution_Journal::clear( $coupon_operation );
		if ( $coupon_fixture->get_id() > 0 ) { $coupon_fixture->delete( true ); }
	}

	// Native tracking writes use actual journal-owned execution and durable replay for both outcomes.
	if ( class_exists( \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment::class )
		&& method_exists( \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment::class, 'set_tracking_number' ) ) {
		/** A registered extension store exercises the real native owner without changing site schema or settings. */
		class YoRo_Tracking_Runtime_Smoke_Store extends \Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore {
			public static $records = [];
			public static $updates = 0;
			public static $fault = '';
			public function create( &$data ): void {
				++self::$updates;
				$data->set_id( 749000 + self::$updates );
				$data->set_date_updated( gmdate( 'c' ) );
				self::$records[ $data->get_id() ] = self::capture( $data );
				$data->apply_changes();
				$data->set_object_read( true );
				do_action( 'woocommerce_fulfillment_after_create', $data );
				if ( 'throw' === self::$fault ) { throw new RuntimeException( 'Persisted create outcome unknown.' ); }
			}
			public function read_meta( &$data ): array { return []; }
			public function delete_by_entity( string $entity_type, string $entity_id ): int { self::$records = []; return 0; }
			public function read( &$data ): void {
				$record = self::$records[ $data->get_id() ] ?? null;
				if ( ! $record ) { throw new RuntimeException( 'Native fulfillment not found.' ); }
				$data->set_props_from_storage( $record['props'] );
				$data->set_meta_data( $record['meta'] );
				$data->set_object_read( true );
				$data->apply_changes();
			}
			public static function capture( $data ) {
				$raw = array_merge( $data->get_data(), $data->get_changes() );
				$meta = [];
				foreach ( $data->get_meta_data() as $entry ) { $meta[] = [ 'id' => count( $meta ) + 1, 'key' => $entry->key, 'value' => $entry->value ]; }
				unset( $raw['meta_data'] );
				return [ 'props' => $raw, 'meta' => $meta ];
			}
			public function update( &$data ): void {
				++self::$updates;
				$changes = $data->get_changes();
				$previous = self::$records[ $data->get_id() ]['props']['status'];
				$data->set_date_updated( gmdate( 'c' ) );
				self::$records[ $data->get_id() ] = self::capture( $data );
				$data->apply_changes();
				do_action( 'woocommerce_fulfillment_after_update', $data, $changes, $previous );
				if ( 'throw' === self::$fault ) { throw new RuntimeException( 'Private post-save runtime fixture fault.' ); }
			}
			public function read_fulfillments( string $entity_type, string $entity_id, bool $with_deleted = false ): array {
				$items = [];
				foreach ( self::$records as $id => $record ) {
					if ( $record['props']['entity_type'] === $entity_type && $record['props']['entity_id'] === $entity_id
						&& ( $with_deleted || ! $record['props']['date_deleted'] ) ) { $items[] = new \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment( $id ); }
				}
				return $items;
			}
		}

		$tracking_feature = static function () { return 'yes'; };
		$tracking_store = static function ( $stores ) { $stores['order-fulfillment'] = YoRo_Tracking_Runtime_Smoke_Store::class; return $stores; };
		$tracking_hooks = [];
		foreach ( $GLOBALS['wp_filter'] as $hook => $callbacks ) { $tracking_hooks[ $hook ] = clone $callbacks; }
		$tracking_operations = [];
		$tracking_order = null;
		try {
			add_filter( 'pre_option_woocommerce_feature_fulfillments_enabled', $tracking_feature );
			add_filter( 'woocommerce_data_stores', $tracking_store, 100 );
			$tracking_manager = wc_get_container()->get( \Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentsManager::class );
			$tracking_manager->register();
			foreach ( [ 'fulfillment.add_tracking', 'fulfillment.update_tracking' ] as $tracking_action ) {
				foreach ( [ false, true ] as $tracking_fail ) {
					$tracking_order = wc_create_order();
					$tracking_item = new WC_Order_Item_Product();
					$tracking_item->set_name( 'Disposable native tracking runtime fixture' );
					$tracking_item->set_quantity( 1 );
					$tracking_order->add_item( $tracking_item );
					$tracking_order->save();
					$tracking_target = new \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment();
					$tracking_target->set_id( 739001 );
					$tracking_target->set_entity_type( WC_Order::class );
					$tracking_target->set_entity_id( (string) $tracking_order->get_id() );
					$tracking_target->set_status( 'unfulfilled' );
					$tracking_target->set_items( [ [ 'item_id' => $tracking_item->get_id(), 'qty' => 1 ] ] );
					if ( 'fulfillment.update_tracking' === $tracking_action ) {
						$tracking_target->set_tracking_number( 'PREVIOUS' );
						$tracking_target->set_shipment_provider( 'dhl' );
					}
					YoRo_Tracking_Runtime_Smoke_Store::$records = [ 739001 => YoRo_Tracking_Runtime_Smoke_Store::capture( $tracking_target ) ];
					YoRo_Tracking_Runtime_Smoke_Store::$updates = 0;
					YoRo_Tracking_Runtime_Smoke_Store::$fault = $tracking_fail ? 'throw' : '';
					$tracking_manager->update_order_fulfillment_status_on_fulfillment_update( new \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment( 739001 ) );
					$tracking_read = YoRo_Fulfillment_Service::observations( $tracking_order->get_id() );
					$tracking_operation = $operation;
					$tracking_operation['envelope_id'] = 'envelope_' . hash( 'sha256', wp_generate_uuid4() );
					$tracking_operation['idempotency_key'] = 'operation_' . hash( 'sha256', wp_generate_uuid4() );
					$tracking_operation['operation_id'] = 'operation_tracking_' . wp_generate_uuid4();
					$tracking_operation['action'] = $tracking_action;
					$tracking_operation['params'] = [ 'order_id' => $tracking_order->get_id(), 'fulfillment_id' => 739001,
						'tracking_number' => 'RUNTIME123', 'shipment_provider' => 'dhl', 'expected_version' => $tracking_read['native_fulfillments'][0]['current_version'] ?? '' ];
					$tracking_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( $tracking_action )['permission'] ];
					$tracking_operations[] = $tracking_operation;
					$tracking_client = new YoRo_Connector_Smoke_Client();
					$tracking_client->claim = $client->claim;
					$tracking_client->claim['delivery']['operations'] = [ $tracking_operation ];
					( new YoRo_Connector_Runtime( $tracking_client ) )->run_once();
					$tracking_result = $tracking_client->results[0]['payload'] ?? [];
					$assert( ( $tracking_fail ? 'failed' : 'executed' ) === ( $tracking_result['status'] ?? '' ) && 1 === YoRo_Tracking_Runtime_Smoke_Store::$updates,
						'Native ' . $tracking_action . ' must run once with actual journal-owned authority for success or post-save ambiguity.' );
					if ( $tracking_fail ) {
						$assert( 'local_execution_failed' === ( $tracking_result['error']['code'] ?? '' )
							&& 'The outcome is ambiguous. Read and reconcile; do not retry or roll back this operation.' === ( $tracking_result['error']['message'] ?? '' )
							&& false === ( $tracking_result['error']['retryable'] ?? null ), 'Ambiguous native save must remain non-retryable with its reconciliation instruction.' );
					} else {
						$assert( 'RUNTIME123' === ( $tracking_result['result']['data']['tracking_number'] ?? '' ), 'Native remote success must report the exact owner readback.' );
					}
					( new YoRo_Connector_Runtime( $tracking_client ) )->run_once();
					$assert( 1 === YoRo_Tracking_Runtime_Smoke_Store::$updates && 2 === count( $tracking_client->results )
						&& wp_json_encode( $tracking_result ) === wp_json_encode( $tracking_client->results[1]['payload'] ?? [] ),
						'Native tracking journal replay must preserve exact durable outcome without another owner save.' );
					$assert( 'RUNTIME123' === ( new \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment( 739001 ) )->get_tracking_number(), 'Each unique tracking operation changes the native owner once, including ambiguous outcome.' );
					$tracking_order->delete( true );
					$tracking_order = null;
				}
			}
			foreach ( [ false, true ] as $create_fail ) {
				$tracking_order = wc_create_order();
				$tracking_item = new WC_Order_Item_Product();
				$tracking_item->set_name( 'Disposable native creation runtime fixture' );
				$tracking_item->set_quantity( 2 );
				$tracking_order->add_item( $tracking_item );
				$tracking_order->save();
				YoRo_Tracking_Runtime_Smoke_Store::$records = [];
				YoRo_Tracking_Runtime_Smoke_Store::$updates = 0;
				YoRo_Tracking_Runtime_Smoke_Store::$fault = $create_fail ? 'throw' : '';
				$read = YoRo_Fulfillment_Service::observations( $tracking_order->get_id() );
				$creation_operation = $operation;
				$creation_operation['envelope_id'] = 'envelope_' . hash( 'sha256', wp_generate_uuid4() );
				$creation_operation['idempotency_key'] = 'operation_' . hash( 'sha256', wp_generate_uuid4() );
				$creation_operation['operation_id'] = 'operation_create_' . wp_generate_uuid4();
				$creation_operation['action'] = 'fulfillment.create';
				$creation_operation['params'] = [ 'order_id' => $tracking_order->get_id(),
					'items' => [ [ 'item_id' => $tracking_item->get_id(), 'qty' => 1 ] ], 'expected_version' => $read['allocation_version'] ?? '' ];
				$creation_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'fulfillment.create' )['permission'] ];
				$tracking_operations[] = $creation_operation;
				$creation_client = new YoRo_Connector_Smoke_Client();
				$creation_client->claim = $client->claim;
				$creation_client->claim['delivery']['operations'] = [ $creation_operation ];
				( new YoRo_Connector_Runtime( $creation_client ) )->run_once();
				$created = $creation_client->results[0]['payload'] ?? [];
				$assert( ( $create_fail ? 'failed' : 'executed' ) === ( $created['status'] ?? '' ) && 1 === YoRo_Tracking_Runtime_Smoke_Store::$updates, 'Remote creation uses exactly one owner save.' );
				if ( $create_fail ) {
					$assert( 'fulfillment_creation_reconciliation_required' === ( $created['error']['code'] ?? '' ) && false === ( $created['error']['retryable'] ?? null ), 'Remote creation preserves machine-readable reconciliation and no retry.' );
				} else {
					$assert( 749001 === ( $created['result']['data']['fulfillment_id'] ?? null ) && false === ( $created['result']['data']['is_fulfilled'] ?? null ), 'Remote creation reports exact new unfulfilled identity.' );
				}
				( new YoRo_Connector_Runtime( $creation_client ) )->run_once();
				$assert( 1 === YoRo_Tracking_Runtime_Smoke_Store::$updates && wp_json_encode( $created ) === wp_json_encode( $creation_client->results[1]['payload'] ?? [] ), 'Creation journal replay preserves success or ambiguity without a second save.' );
				$assert( count( YoRo_Tracking_Runtime_Smoke_Store::$records ) === 1, 'Ambiguous creation is never auto-deleted.' );
				$tracking_order->delete( true );
				$tracking_order = null;
			}
		} finally {
			foreach ( $tracking_operations as $tracking_operation ) { YoRo_Connector_Execution_Journal::clear( $tracking_operation ); }
			YoRo_Tracking_Runtime_Smoke_Store::$records = [];
			if ( $tracking_order instanceof WC_Order ) { $tracking_order->delete( true ); }
			// Restore process-only fixture registrations after all owner hooks have run normally.
			$GLOBALS['wp_filter'] = $tracking_hooks;
		}
	} else {
		$assert( ! YoRo_Fulfillment_Service::available(), 'Missing native tracking owner never grants remote write capability.' );
	}

	// Real journal/runtime with isolated gateway: success, ambiguity and a reclaimed executing receipt.
	class YoRo_Runtime_Refund_Fake_Gateway extends WC_Payment_Gateway {
		public static $calls = 0;
		public static $mode = 'success';
		public function __construct() { $this->id = 'yoro_runtime_refund_fixture'; $this->supports = [ 'refunds' ]; }
		public function process_refund( $id, $amount = null, $reason = '' ) {
			++self::$calls;
			if ( 'error' === self::$mode ) { return new WP_Error( 'fake_unknown', 'Injected ambiguous gateway outcome.' ); }
			if ( 'throw' === self::$mode ) { throw new RuntimeException( 'Injected post-effect timeout.' ); }
			return true;
		}
	}
	$refund_gateway = new YoRo_Runtime_Refund_Fake_Gateway();
	$refund_gateways = WC()->payment_gateways()->payment_gateways();
	$refund_registry = new ReflectionProperty( WC_Payment_Gateways::class, 'payment_gateways' );
	$refund_registry->setAccessible( true );
	$refund_registry->setValue( WC()->payment_gateways(), $refund_gateways + [ $refund_gateway->id => $refund_gateway ] );
	$refund_no_http = static function () { throw new RuntimeException( 'Refund runtime fixture must never call a network provider.' ); };
	$refund_no_mail = static function () { return true; };
	add_filter( 'pre_http_request', $refund_no_http, 999 ); add_filter( 'pre_wp_mail', $refund_no_mail, 999 );
	try {
		foreach ( [ 'success', 'error', 'throw', 'executing' ] as $refund_mode ) {
			$refund_order = wc_create_order();
			$refund_operation = $operation;
			try {
				$refund_order->set_status( 'processing' ); $refund_order->set_currency( 'USD' ); $refund_order->set_total( '10.00' );
				$refund_order->set_payment_method( $refund_gateway ); $refund_order->set_transaction_id( 'fake-runtime-transaction' ); $refund_order->save();
				$guard = YoRo_Orders_Read_Service::refund_execution_state( $refund_order->get_id() )['guard'];
				$refund_operation['envelope_id'] = 'envelope_' . hash( 'sha256', wp_generate_uuid4() );
				$refund_operation['idempotency_key'] = 'operation_' . hash( 'sha256', wp_generate_uuid4() );
				$refund_operation['operation_id'] = 'operation_refund_' . wp_generate_uuid4();
				$refund_operation['action'] = 'orders.refund';
				$refund_operation['params'] = [ 'order_id' => $refund_order->get_id(), 'amount' => '1.00', 'expected_version' => $guard['current_version'], 'expected_refund_state' => $guard['state_token'] ];
				$refund_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'orders.refund' )['permission'] ];
				$refund_client = new YoRo_Connector_Smoke_Client(); $refund_client->claim = $client->claim;
				$refund_client->claim['delivery']['operations'] = [ $refund_operation ];
				YoRo_Runtime_Refund_Fake_Gateway::$mode = $refund_mode;
				$before_refund_calls = YoRo_Runtime_Refund_Fake_Gateway::$calls;
				if ( 'executing' === $refund_mode ) {
					$acquired = YoRo_Connector_Execution_Journal::begin( $refund_operation );
					$assert( 'new' === $acquired['state'], 'Fixture must acquire exactly one journal winner.' );
				}
				( new YoRo_Connector_Runtime( $refund_client ) )->run_once();
				$refund_result = $refund_client->results[0]['payload'] ?? [];
				$expected_calls = 'executing' === $refund_mode ? 0 : 1;
				$assert( $before_refund_calls + $expected_calls === YoRo_Runtime_Refund_Fake_Gateway::$calls, 'Refund journal admits at most one gateway call; executing receipt admits none.' );
				$assert( ( 'success' === $refund_mode ? 'executed' : 'failed' ) === ( $refund_result['status'] ?? '' ), 'Refund runtime reports verified success or failed reconciliation: ' . wp_json_encode( $refund_result ) );
				if ( in_array( $refund_mode, [ 'error', 'throw' ], true ) ) {
					$assert( 'refund_outcome_unknown' === ( $refund_result['error']['code'] ?? '' ) && false === ( $refund_result['error']['retryable'] ?? true ), 'Unknown external outcome is durable, explicit and not retryable.' );
				}
				// A subsequent delivery lease retains the same operation and journal identity.
				$refund_client->claim['delivery']['lease_generation']++;
				( new YoRo_Connector_Runtime( $refund_client ) )->run_once();
				$assert( $before_refund_calls + $expected_calls === YoRo_Runtime_Refund_Fake_Gateway::$calls, 'Refund completed/unknown/reclaimed replay cannot transfer again.' );
				$assert( wp_json_encode( $refund_result ) === wp_json_encode( $refund_client->results[1]['payload'] ?? [] ), 'Refund replay preserves the exact durable result.' );
			} finally {
				YoRo_Connector_Execution_Journal::clear( $refund_operation );
				foreach ( wc_get_order( $refund_order->get_id() )->get_refunds() as $fixture_refund ) { $fixture_refund->delete( true ); }
				$refund_order->delete( true );
			}
		}
	} finally {
		$refund_registry->setValue( WC()->payment_gateways(), $refund_gateways );
		remove_filter( 'pre_http_request', $refund_no_http, 999 ); remove_filter( 'pre_wp_mail', $refund_no_mail, 999 );
	}

	// External recording shares the unchanged journal, with no gateway invocation for any outcome.
	$external_gateway = new YoRo_Runtime_Refund_Fake_Gateway(); $external_gateway->supports = [];
	$external_gateways = WC()->payment_gateways()->payment_gateways();
	$refund_registry->setValue( WC()->payment_gateways(), $external_gateways + [ $external_gateway->id => $external_gateway ] );
	add_filter( 'pre_http_request', $refund_no_http, 999 ); add_filter( 'pre_wp_mail', $refund_no_mail, 999 );
	try {
		foreach ( [ 'success', 'throw', 'postcondition', 'executing' ] as $external_mode ) {
			$external_order = wc_create_order(); $external_operation = $operation;
			$external_creates = 0;
			$external_observer = static function ( $refund, $args ) use ( &$external_creates, $external_order, $assert ) {
				if ( $args['order_id'] === $external_order->get_id() ) {
					++$external_creates;
					$assert( false === $args['refund_payment'] && false === $args['restock_items'] && [] === $args['line_items'], 'External journal execution calls only the local owner record API.' );
				}
			};
			$external_failure = static function ( $refund_id ) use ( $external_mode, $external_order ) {
				$refund = wc_get_order( $refund_id );
				if ( $refund->get_parent_id() !== $external_order->get_id() ) { return; }
				if ( 'throw' === $external_mode ) { throw new RuntimeException( 'Injected unknown local recording after save.' ); }
				if ( 'postcondition' === $external_mode ) { $refund->set_currency( 'EUR' ); $refund->save(); }
			};
			add_action( 'woocommerce_create_refund', $external_observer, 10, 2 ); add_action( 'woocommerce_refund_created', $external_failure );
			try {
				$external_order->set_status( 'processing' ); $external_order->set_currency( 'USD' ); $external_order->set_total( '10.00' );
				$external_order->set_payment_method( $external_gateway ); $external_order->set_transaction_id( '' ); $external_order->save();
				$guard = YoRo_Orders_Read_Service::external_refund_recording_state( $external_order->get_id() )['guard'];
				$external_operation['envelope_id'] = 'envelope_' . hash( 'sha256', wp_generate_uuid4() );
				$external_operation['idempotency_key'] = 'operation_' . hash( 'sha256', wp_generate_uuid4() );
				$external_operation['operation_id'] = 'operation_external_refund_' . wp_generate_uuid4();
				$external_operation['action'] = 'orders.record_external_refund';
				$external_operation['params'] = [ 'order_id' => $external_order->get_id(), 'amount' => '1.00', 'expected_version' => $guard['current_version'], 'expected_external_refund_state' => $guard['state_token'] ];
				$external_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'orders.record_external_refund' )['permission'] ];
				$external_client = new YoRo_Connector_Smoke_Client(); $external_client->claim = $client->claim;
				$external_client->claim['delivery']['operations'] = [ $external_operation ];
				$before_gateway_calls = YoRo_Runtime_Refund_Fake_Gateway::$calls;
				if ( 'executing' === $external_mode ) { $assert( 'new' === YoRo_Connector_Execution_Journal::begin( $external_operation )['state'], 'External fixture acquires exactly one journal winner.' ); }
				( new YoRo_Connector_Runtime( $external_client ) )->run_once();
				$external_result = $external_client->results[0]['payload'] ?? [];
				$assert( ( 'executing' === $external_mode ? 0 : 1 ) === $external_creates && $before_gateway_calls === YoRo_Runtime_Refund_Fake_Gateway::$calls, 'External execution creates at most one local record and sends no money.' );
				$assert( ( 'success' === $external_mode ? 'executed' : 'failed' ) === ( $external_result['status'] ?? '' ), 'External runtime truthfully reports local result: ' . wp_json_encode( $external_result ) );
				if ( in_array( $external_mode, [ 'throw', 'postcondition' ], true ) ) { $assert( 'external_refund_outcome_unknown' === ( $external_result['error']['code'] ?? '' ) && false === ( $external_result['error']['retryable'] ?? true ), 'Unknown local outcome is durable and non-retryable.' ); }
				if ( 'success' === $external_mode ) { $assert( 'not_observed' === ( $external_result['result']['data']['external_refund']['external_transfer_verification'] ?? '' ) && false === ( $external_result['result']['data']['external_refund']['payment_transfer_initiated'] ?? true ), 'Remote local record success cannot assert observed external payment.' ); }
				$external_client->claim['delivery']['lease_generation']++;
				( new YoRo_Connector_Runtime( $external_client ) )->run_once();
				$assert( wp_json_encode( $external_result ) === wp_json_encode( $external_client->results[1]['payload'] ?? [] ), 'External record replay returns the exact completed/unknown result.' );
				$assert( ( 'executing' === $external_mode ? 0 : 1 ) === $external_creates && $before_gateway_calls === YoRo_Runtime_Refund_Fake_Gateway::$calls, 'Reclaimed external operation cannot create a second local record or call a gateway.' );
			} finally {
				remove_action( 'woocommerce_create_refund', $external_observer, 10 ); remove_action( 'woocommerce_refund_created', $external_failure );
				YoRo_Connector_Execution_Journal::clear( $external_operation );
				foreach ( wc_get_order( $external_order->get_id() )->get_refunds() as $fixture_refund ) { $fixture_refund->delete( true ); }
				$external_order->delete( true );
			}
		}
	} finally {
		$refund_registry->setValue( WC()->payment_gateways(), $external_gateways );
		remove_filter( 'pre_http_request', $refund_no_http, 999 ); remove_filter( 'pre_wp_mail', $refund_no_mail, 999 );
	}

	// Regular pricing uses only validated remote claim authority and one durable journal result.
	$pricing_product = new WC_Product_Simple();
	$pricing_operation = $operation;
	try {
		$pricing_product->set_name( 'Pricing runtime fixture' );
		$pricing_product->set_status( 'draft' );
		$pricing_product->set_regular_price( '10' );
		$pricing_product->set_price( '10' );
		$pricing_product->save();
		$pricing_product = wc_get_product( $pricing_product->get_id() );
		$pricing_operation['envelope_id'] = 'envelope_' . hash( 'sha256', 'pricing-' . wp_generate_uuid4() );
		$pricing_operation['idempotency_key'] = 'operation_' . hash( 'sha256', 'pricing-' . wp_generate_uuid4() );
		$pricing_operation['operation_id'] = 'operation_pricing_' . wp_generate_uuid4();
		$pricing_operation['action'] = 'products.update_pricing';
		$pricing_operation['params'] = [
			'product_id' => $pricing_product->get_id(),
			'regular_price' => '12.345',
			'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $pricing_product ),
		];
		$pricing_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'products.update_pricing' )['permission'] ];
		$pricing_client = new YoRo_Connector_Smoke_Client();
		$pricing_client->claim = $client->claim;
		$pricing_client->claim['delivery']['operations'] = [ $pricing_operation ];
		$pricing_calls = 0;
		$pricing_probe = static function ( $name ) use ( &$pricing_calls ) {
			if ( 'products.update_pricing' === $name ) { ++$pricing_calls; }
		};
		add_action( 'yoro_before_action_execute', $pricing_probe, 10, 1 );
		try {
			( new YoRo_Connector_Runtime( $pricing_client ) )->run_once();
			$pricing_result = $pricing_client->results[0]['payload'] ?? [];
			$pricing_data = $pricing_result['result']['data'] ?? [];
			$assert( 'executed' === ( $pricing_result['status'] ?? '' ) && 1 === $pricing_calls, 'Pricing must execute once through the validated remote runtime.' );
			$assert( [ 'product_id', 'previous_regular_price', 'regular_price', 'active_price', 'currency', 'current_version' ] === array_keys( $pricing_data ), 'Pricing runtime output must match the exact canonical data schema.' );
			$assert( '10' === (string) ( $pricing_data['previous_regular_price'] ?? '' ) && '12.35' === (string) ( $pricing_data['regular_price'] ?? '' ), 'Pricing runtime must report the normalized owner mutation.' );
			$assert( '12.35' === (string) wc_get_product( $pricing_product->get_id() )->get_regular_price( 'edit' ), 'Pricing runtime must persist the exact normalized regular price.' );
			( new YoRo_Connector_Runtime( $pricing_client ) )->run_once();
			$assert( 1 === $pricing_calls && 2 === count( $pricing_client->results ), 'Pricing journal replay must not repeat the mutation.' );
			$assert( wp_json_encode( $pricing_result ) === wp_json_encode( $pricing_client->results[1]['payload'] ?? [] ), 'Pricing journal replay must republish the exact recorded result.' );
		} finally {
			remove_action( 'yoro_before_action_execute', $pricing_probe, 10 );
		}
	} finally {
		YoRo_Connector_Execution_Journal::clear( $pricing_operation );
		if ( $pricing_product instanceof WC_Product ) { $pricing_product->delete( true ); }
	}

	// Product content uses only validated remote claim authority and one durable journal result.
	$content_product = new WC_Product_Simple();
	$content_operation = $operation;
	try {
		$content_product->set_name( 'Content runtime fixture' );
		$content_product->set_description( '<p>Before</p>' );
		$content_product->set_status( 'draft' );
		$content_product->save();
		$content_product = wc_get_product( $content_product->get_id() );
		$content_operation['envelope_id'] = 'envelope_' . hash( 'sha256', 'content-' . wp_generate_uuid4() );
		$content_operation['idempotency_key'] = 'operation_' . hash( 'sha256', 'content-' . wp_generate_uuid4() );
		$content_operation['operation_id'] = 'operation_content_' . wp_generate_uuid4();
		$content_operation['action'] = 'products.update_content';
		$content_operation['params'] = [
			'product_id' => $content_product->get_id(),
			'field' => 'description',
			'value' => '<p>After <strong>review</strong></p>',
			'expected_version' => YoRo_Commerce_Discovery_Service::product_version( $content_product ),
		];
		$content_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'products.update_content' )['permission'] ];
		$content_client = new YoRo_Connector_Smoke_Client();
		$content_client->claim = $client->claim;
		$content_client->claim['delivery']['operations'] = [ $content_operation ];
		$content_calls = 0;
		$content_probe = static function ( $name ) use ( &$content_calls ) {
			if ( 'products.update_content' === $name ) { ++$content_calls; }
		};
		add_action( 'yoro_before_action_execute', $content_probe, 10, 1 );
		try {
			( new YoRo_Connector_Runtime( $content_client ) )->run_once();
			$content_result = $content_client->results[0]['payload'] ?? [];
			$content_data = $content_result['result']['data'] ?? [];
			$assert( 'executed' === ( $content_result['status'] ?? '' ) && 1 === $content_calls, 'Content must execute once through the validated remote runtime.' );
			$assert( [ 'product_id', 'field', 'previous_value_fingerprint', 'value_fingerprint', 'current_version' ] === array_keys( $content_data ), 'Content runtime output must match the exact canonical data schema.' );
			$assert( 'description' === ( $content_data['field'] ?? '' ) && ! isset( $content_data['value'] ), 'Content runtime output must identify the field without returning raw content.' );
			$assert( '<p>After <strong>review</strong></p>' === wc_get_product( $content_product->get_id() )->get_description( 'edit' ), 'Content runtime must persist the exact normalized field.' );
			( new YoRo_Connector_Runtime( $content_client ) )->run_once();
			$assert( 1 === $content_calls && 2 === count( $content_client->results ), 'Content journal replay must not repeat the mutation.' );
			$assert( wp_json_encode( $content_result ) === wp_json_encode( $content_client->results[1]['payload'] ?? [] ), 'Content journal replay must republish the exact recorded result.' );
		} finally {
			remove_action( 'yoro_before_action_execute', $content_probe, 10 );
		}
	} finally {
		YoRo_Connector_Execution_Journal::clear( $content_operation );
		if ( $content_product instanceof WC_Product ) { $content_product->delete( true ); }
	}

	// Product draft creation requires the validated journal runtime call site and replays without another save.
	$draft_operation = $operation;
	$draft_product_id = 0;
	try {
		$draft_operation['envelope_id'] = 'envelope_' . hash( 'sha256', 'product-draft-' . wp_generate_uuid4() );
		$draft_operation['idempotency_key'] = 'operation_' . hash( 'sha256', 'product-draft-' . wp_generate_uuid4() );
		$draft_operation['operation_id'] = 'operation_product_draft_' . wp_generate_uuid4();
		$draft_operation['action'] = 'products.create_draft';
		$draft_operation['params'] = [ 'name' => 'Runtime Product Draft' ];
		$draft_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'products.create_draft' )['permission'] ];
		$draft_client = new YoRo_Connector_Smoke_Client();
		$draft_client->claim = $client->claim;
		$draft_client->claim['delivery']['operations'] = [ $draft_operation ];
		$draft_calls = 0;
		$draft_probe = static function ( $name ) use ( &$draft_calls ) {
			if ( 'products.create_draft' === $name ) { ++$draft_calls; }
		};
		add_action( 'yoro_before_action_execute', $draft_probe, 10, 1 );
		try {
			( new YoRo_Connector_Runtime( $draft_client ) )->run_once();
			$draft_result = $draft_client->results[0]['payload'] ?? [];
			$draft_data = $draft_result['result']['data'] ?? [];
			$draft_product_id = absint( $draft_data['product_id'] ?? 0 );
			$draft_product = wc_get_product( $draft_product_id );
			$assert( 'executed' === ( $draft_result['status'] ?? '' ) && 1 === $draft_calls, 'Product draft must execute once through the validated journal runtime.' );
			$assert( [ 'product_id', 'current_version' ] === array_keys( $draft_data ) && $draft_product_id > 0, 'Product draft runtime output must contain only the positive ID and fresh version.' );
			$assert( $draft_product && 'simple' === $draft_product->get_type() && 'draft' === $draft_product->get_status( 'edit' ) && 'Runtime Product Draft' === $draft_product->get_name( 'edit' ), 'Product draft runtime must persist the exact fixed owner state.' );
			( new YoRo_Connector_Runtime( $draft_client ) )->run_once();
			$assert( 1 === $draft_calls && 2 === count( $draft_client->results ), 'Product draft journal replay must not create again.' );
			$assert( wp_json_encode( $draft_result ) === wp_json_encode( $draft_client->results[1]['payload'] ?? [] ), 'Product draft journal replay must republish the exact recorded result.' );
		} finally {
			remove_action( 'yoro_before_action_execute', $draft_probe, 10 );
		}
	} finally {
		YoRo_Connector_Execution_Journal::clear( $draft_operation );
		if ( $draft_product_id > 0 ) { wc_get_product( $draft_product_id )->delete( true ); }
	}

	// Native B4 replay tests require a disposable database and a process-level mail sink.
	if ( '1' === getenv( 'YORO_NATIVE_EMAIL_NO_DELIVERY_TEST' ) ) {
		if ( '/usr/bin/true' !== ini_get( 'sendmail_path' ) ) {
			throw new RuntimeException( 'B4 journal tests require the process-level no-delivery sink.' );
		}
		$b4_type = 'customer_processing_order';
		$b4_option = 'woocommerce_' . $b4_type . '_settings';
		$b4_original = get_option( $b4_option, null );
		$b4_settings = is_array( $b4_original ) ? $b4_original : [];
		$b4_settings['enabled'] = 'yes';
		update_option( $b4_option, $b4_settings );
		try {
			foreach ( [ false, true ] as $b4_ambiguous ) {
				$b4_order = new WC_Order();
				$b4_order->set_billing_email( 'journal-recipient@example.invalid' );
				$b4_order->set_status( 'processing' );
				$b4_order->save();
				$b4_operation = $operation;
				$b4_operation['envelope_id'] = 'envelope_' . hash( 'sha256', 'b4-' . wp_generate_uuid4() );
				$b4_operation['idempotency_key'] = 'operation_' . hash( 'sha256', 'b4-' . wp_generate_uuid4() );
				$b4_operation['operation_id'] = $b4_ambiguous ? 'operation_b4_unknown' : 'operation_b4_success';
				$b4_operation['action'] = 'orders.send_transactional_email';
				$b4_operation['params'] = [ 'order_id' => $b4_order->get_id(), 'expected_version' => YoRo_Order_Presenter::order_version( wc_get_order( $b4_order->get_id() ) ), 'email_type' => $b4_type ];
				$b4_operation['actor']['permissions'] = [ YoRo_Action_Registry::get( 'orders.send_transactional_email' )['permission'] ];
				$b4_client = new YoRo_Connector_Smoke_Client();
				$b4_client->claim = $client->claim;
				$b4_client->claim['delivery']['operations'] = [ $b4_operation ];
				$b4_calls = 0;
				$b4_sends = 0;
				$b4_attempts = 0;
				$b4_probe = static function ( $action ) use ( &$b4_calls ) {
					if ( 'orders.send_transactional_email' === $action ) { ++$b4_calls; }
				};
				$b4_sent = static function ( $sent, $type ) use ( &$b4_sends, $b4_type ) {
					if ( $b4_type === $type && true === $sent ) { ++$b4_sends; }
				};
				$b4_callback = static function ( $args, $email ) use ( &$b4_attempts, $b4_type ) {
					if ( $b4_type === $email->id ) {
						++$b4_attempts;
					}
					return $args;
				};
				$b4_corruptor = static function ( $sent, $type ) use ( $b4_type, $b4_ambiguous ) {
					if ( $b4_ambiguous && $b4_type === $type && true === $sent ) {
						throw new RuntimeException( 'Disposable fixture: observation lost after native acceptance.' );
					}
				};
				add_action( 'woocommerce_email_sent', $b4_corruptor, 20, 2 );
				add_action( 'yoro_before_action_execute', $b4_probe, 10, 1 );
				add_action( 'woocommerce_email_sent', $b4_sent, 10, 2 );
				add_filter( 'woocommerce_mail_callback_params', $b4_callback, 10, 2 );
				try {
					( new YoRo_Connector_Runtime( $b4_client ) )->run_once();
					$b4_result = $b4_client->results[0]['payload'] ?? [];
					$assert( 1 === $b4_calls && 1 === $b4_attempts, 'B4 initial runtime invokes one executor and one native callback attempt.' );
					if ( $b4_ambiguous ) {
						$assert( 'failed' === ( $b4_result['status'] ?? '' ) && 'local_execution_failed' === ( $b4_result['error']['code'] ?? '' ) && 'Native email was attempted without proven mailer acceptance. Reconcile before any retry.' === ( $b4_result['error']['message'] ?? '' ), 'B4 lost observation after native acceptance publishes the canonical ambiguous failure.' );
						$assert( false === ( $b4_result['error']['retryable'] ?? null ) && 1 === $b4_sends, 'B4 accepted-but-unobserved outcome is unknown and cannot auto-retry.' );
					} else {
						$assert( 'executed' === ( $b4_result['status'] ?? '' ) && 1 === $b4_sends, 'B4 native sink success records one accepted mailer call.' );
						$b4_data = $b4_result['result']['data'] ?? [];
						$assert( [ 'order_id', 'email_type', 'current_version', 'outcome', 'confirmation_accepted', 'recipient_masked' ] === array_keys( $b4_data ), 'B4 successful runtime result has only bounded canonical data roots.' );
						$assert( 'mailer_accepted' === ( $b4_data['outcome'] ?? '' ) && false === strpos( wp_json_encode( $b4_result ), 'journal-recipient@example.invalid' ), 'B4 success reports acceptance with no raw recipient disclosure.' );
					}
					// Removing the failure injector cannot turn a recorded unknown into a fresh send.
					remove_action( 'woocommerce_email_sent', $b4_corruptor, 20 );
					( new YoRo_Connector_Runtime( $b4_client ) )->run_once();
					$assert( 1 === $b4_calls && 1 === $b4_attempts && 1 === $b4_sends, 'B4 replay performs no executor, callback, or mailer reexecution.' );
					$assert( 2 === count( $b4_client->results ) && wp_json_encode( $b4_result ) === wp_json_encode( $b4_client->results[1]['payload'] ?? [] ), 'B4 journal replay republishes the exact recorded success or ambiguous failure.' );
				} finally {
					remove_action( 'woocommerce_email_sent', $b4_corruptor, 20 );
					remove_action( 'yoro_before_action_execute', $b4_probe, 10 );
					remove_action( 'woocommerce_email_sent', $b4_sent, 10 );
					remove_filter( 'woocommerce_mail_callback_params', $b4_callback, 10 );
					YoRo_Connector_Execution_Journal::clear( $b4_operation );
					$b4_order->delete( true );
				}
			}
		} finally {
			if ( null === $b4_original ) { delete_option( $b4_option ); } else { update_option( $b4_option, $b4_original ); }
		}
	}

	$admin_action = YoRo_Action_Registry::get( 'plugins.activate' );
	$assert( is_array( $admin_action ) && empty( $admin_action['ai_exposed'] ), 'Administration actions must be non-exposable at the registry boundary.' );
	$admin_operation = $operation;
	$admin_operation['envelope_id'] = 'envelope_' . hash( 'sha256', 'woocommerce-runtime-admin-negative-envelope' );
	$admin_operation['workflow_id'] = 'workflow_' . hash( 'sha256', 'woocommerce-runtime-admin-negative-workflow' );
	$admin_operation['operation_id'] = 'operation_admin_negative';
	$admin_operation['idempotency_key'] = 'operation_' . hash( 'sha256', 'woocommerce-runtime-admin-negative-idempotency' );
	$admin_operation['action'] = 'plugins.activate';
	$admin_operation['params'] = [
		'plugin'           => 'akismet/akismet.php',
		'expected_version' => '0.0.0',
	];
	$admin_operation['actor']['permissions'] = [ $admin_action['permission'] ?? 'activate_plugins' ];
	$admin_client = new YoRo_Connector_Smoke_Client();
	$admin_client->claim = [
		'success'  => true,
		'delivery' => [
			'delivery_id'      => 'delivery_' . hash( 'sha256', 'woocommerce-runtime-admin-negative' ),
			'claim_token'      => 'claim_' . hash( 'sha256', 'woocommerce-runtime-admin-negative-token' ),
			'lease_generation' => 1,
			'operations'       => [ $admin_operation ],
		],
	];
	$admin_executor_calls = 0;
	$admin_executor_probe = static function ( $executed_action ) use ( &$admin_executor_calls ) {
		if ( 'plugins.activate' === $executed_action ) {
			$admin_executor_calls++;
		}
	};
	add_action( 'yoro_before_action_execute', $admin_executor_probe, 10, 1 );
	$admin_runtime_result = ( new YoRo_Connector_Runtime( $admin_client ) )->run_once();
	remove_action( 'yoro_before_action_execute', $admin_executor_probe, 10 );
	$assert( ! is_wp_error( $admin_runtime_result ) && ! empty( $admin_runtime_result['success'] ), 'Pull runtime must report the rejected administration delivery deterministically.' );
	$assert( 0 === $admin_executor_calls, 'Remote connector delivery must never invoke a WordPress administration action.' );
	$assert( 1 === count( $admin_client->results ) && 'failed' === ( $admin_client->results[0]['payload']['status'] ?? '' ), 'Remote administration delivery must publish one failed result.' );
	update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'no', false );
	delete_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION );
	YoRo_Connector_Execution_Journal::clear( $admin_operation );

	$journal_after_publish = YoRo_Connector_Execution_Journal::begin( $operation );
	$assert( 'completed' === ( $journal_after_publish['state'] ?? '' ), 'Accepted results must retain the durable local execution receipt.' );
	$manifest_state_after_execution = YoRo_Connector_Snapshot_Publisher::current_manifest_state();
	$assert( $manifest_identity_before_execution['manifest_id'] === ( $manifest_state_after_execution['manifest_id'] ?? '' ), 'Provider routing must not change the capability manifest identity.' );
	$assert( $manifest_identity_before_execution['revision'] === ( $manifest_state_after_execution['revision'] ?? 0 ), 'Provider routing must not change the capability manifest revision.' );
	$assert( $manifest_identity_before_execution['actions_hash'] === hash( 'sha256', wp_json_encode( $client->manifest['actions'] ?? [] ) ), 'Provider routing must not change semantic action identity.' );
	YoRo_Connector_Execution_Journal::clear( $operation );

	$batch_client = new YoRo_Connector_Smoke_Client();
	$batch_operations = [];

	foreach ( range( 1, 6 ) as $batch_position ) {
		$batch_operation = $operation;
		$batch_operation['envelope_id'] = 'envelope_' . hash( 'sha256', 'bounded-drain-envelope-' . $batch_position );
		$batch_operation['workflow_id'] = 'workflow_' . hash( 'sha256', 'bounded-drain-workflow-' . $batch_position );
		$batch_operation['idempotency_key'] = 'operation_' . hash( 'sha256', 'bounded-drain-idempotency-' . $batch_position );
		$batch_operation['trace_id'] = 'trace_' . hash( 'sha256', 'bounded-drain-trace-' . $batch_position );
		$batch_operations[] = $batch_operation;
		$batch_client->claims[] = [
			'success'  => true,
			'delivery' => [
				'delivery_id'      => 'delivery_' . hash( 'sha256', 'bounded-drain-delivery-' . $batch_position ),
				'claim_token'      => 'claim_' . hash( 'sha256', 'bounded-drain-token-' . $batch_position ),
				'lease_generation' => 1,
				'operations'       => [ $batch_operation ],
			],
		];
	}

	$batch_client->claims[] = [ 'success' => true, 'empty' => true ];
	$batch_result = ( new YoRo_Connector_Runtime( $batch_client ) )->run_batch( 3, 45 );
	$assert( ! is_wp_error( $batch_result ) && ! empty( $batch_result['success'] ), 'Bounded runtime drain must complete safely.' );
	$assert( 3 === ( $batch_result['processed'] ?? 0 ), 'Bounded runtime drain must process the requested delivery limit.' );
	$assert( empty( $batch_result['drained'] ), 'A capped runtime drain must not claim the queue is empty.' );
	$assert( 3 === count( $batch_client->results ), 'Bounded runtime drain must publish one result per claimed delivery.' );
	$assert( 4 === count( $batch_client->claims ), 'Bounded runtime drain must leave later deliveries for the next tick.' );

	$fake_time = 0.0;
	$slept = [];
	$clock = static function() use ( &$fake_time ) { return $fake_time; };
	$sleeper = static function( $seconds ) use ( &$fake_time, &$slept ) {
		$slept[] = $seconds;
		$fake_time += $seconds;
	};
	$poll_client = new YoRo_Connector_Smoke_Client();
	$poll_client->claims = [
		[ 'success' => true, 'empty' => true, 'retry_after' => 5 ],
		$batch_client->claims[0],
		[ 'success' => true, 'empty' => true, 'retry_after' => 5 ],
	];
	$poll_client->claim = [ 'success' => true, 'empty' => true, 'retry_after' => 5 ];
	$poll_result = ( new YoRo_Connector_Runtime( $poll_client, $clock, $sleeper ) )->run_batch( 5, 12 );
	$assert( 1 === ( $poll_result['processed'] ?? 0 ) && 1 === count( $poll_client->results ), 'A delivery appearing after an initial 204 must complete in the same batch.' );
	$assert( [ 5, 5 ] === $slept && 4 === $poll_client->claim_calls && 10.0 === $fake_time, 'Empty claims must honor bounded Retry-After without spinning or sleeping past the deadline.' );
	$assert( ! empty( $poll_result['drained'] ), 'A final empty claim reports a drained queue.' );

	foreach ( [
		[ null, 11, [ 5, 5 ], 3 ],
		[ 0, 11, [ 5, 5 ], 3 ],
		[ 'malformed', 11, [ 5, 5 ], 3 ],
		[ 999, 11, [ 5, 5 ], 3 ],
		[ 2, 5, [ 2, 2 ], 3 ],
		[ 0.2, 3, [ 1, 1 ], 3 ],
	] as $case ) {
		$fake_time = 0.0;
		$slept = [];
		$empty_client = new YoRo_Connector_Smoke_Client();
		$empty_client->claim = [ 'success' => true, 'empty' => true ];
		if ( null !== $case[0] ) { $empty_client->claim['retry_after'] = $case[0]; }
		$empty_result = ( new YoRo_Connector_Runtime( $empty_client, $clock, $sleeper ) )->run_batch( 5, $case[1] );
		$assert( 0 === ( $empty_result['processed'] ?? -1 ) && ! empty( $empty_result['drained'] ), 'A permanently empty queue must terminate normally.' );
		$assert( $case[2] === $slept && $case[3] === $empty_client->claim_calls, 'Missing, zero, malformed, excessive and fractional retry hints must normalize to bounded waits.' );
	}

	$fake_time = 0.0;
	$slept = [];
	$deadline_client = new YoRo_Connector_Smoke_Client();
	$deadline_client->claim = [ 'success' => true, 'empty' => true, 'retry_after' => 5 ];
	$deadline_result = ( new YoRo_Connector_Runtime( $deadline_client, $clock, $sleeper ) )->run_batch( 5, 4 );
	$assert( 0 === $deadline_result['processed'] && 1 === $deadline_client->claim_calls && [] === $slept, 'A retry hint beyond the remaining deadline must not sleep or repoll.' );
	$deadline_client->claim_callback = static function() use ( &$fake_time ) {
		$fake_time += 45;
		return [ 'success' => true, 'empty' => true, 'retry_after' => 5 ];
	};
	$fake_time = 0.0;
	$deadline_client->claim_calls = 0;
	$deadline_result = ( new YoRo_Connector_Runtime( $deadline_client, $clock, $sleeper ) )->run_batch();
	$assert( 0 === $deadline_result['processed'] && 1 === $deadline_client->claim_calls && [] === $slept, 'An in-flight claim exhausting the 45-second window must not sleep or repoll.' );

	$failure_client = new YoRo_Connector_Smoke_Client();
	$failure_client->claim = new WP_Error( 'simulated_claim_failure', 'Claim failed.' );
	$failure_result = ( new YoRo_Connector_Runtime( $failure_client, $clock, $sleeper ) )->run_batch();
	$assert( is_wp_error( $failure_result ) && 1 === $failure_client->claim_calls && [] === $failure_client->results, 'A failed claim must not retry or execute a business action.' );
	$fake_time = 0.0;
	$invalid_empty_client = new YoRo_Connector_Smoke_Client();
	$invalid_empty_client->claim = [ 'success' => false, 'empty' => true, 'retry_after' => 1 ];
	$invalid_empty_result = ( new YoRo_Connector_Runtime( $invalid_empty_client, $clock, $sleeper ) )->run_batch();
	$assert( 0 === $invalid_empty_result['processed'] && 1 === $invalid_empty_client->claim_calls && [] === $slept, 'Only a successful empty claim may be polled again.' );
	$cutover_status = YoRo_AI_Settings::get_ai_api_status();
	try {
		$disabled_status = $cutover_status;
		$disabled_status['connector_runtime']['cutover']['cutover_allowed'] = false;
		YoRo_AI_Settings::update_ai_api_status( $disabled_status );
		$disabled_client = new YoRo_Connector_Smoke_Client();
		$disabled_result = ( new YoRo_Connector_Runtime( $disabled_client, $clock, $sleeper ) )->run_batch();
		$assert( 0 === $disabled_result['processed'] && 0 === $disabled_client->claim_calls, 'Disabled live cutover must not poll or execute.' );
	} finally {
		YoRo_AI_Settings::update_ai_api_status( $cutover_status );
	}

	$lock_acquire = new ReflectionMethod( 'YoRo_Connector_Runtime', 'acquire_lock' );
	$lock_release = new ReflectionMethod( 'YoRo_Connector_Runtime', 'release_lock' );
	$prior_lock = get_option( YoRo_Connector_Runtime::LOCK_KEY, null );
	try {
		delete_option( YoRo_Connector_Runtime::LOCK_KEY );
		$owner = $lock_acquire->invoke( null );
		$assert( is_string( $owner ) && null === $lock_acquire->invoke( null ), 'Overlapping runners must not acquire the same local lock.' );
		$lock_release->invoke( null, 'not-the-owner' );
		$assert( $owner === get_option( YoRo_Connector_Runtime::LOCK_KEY ), 'A non-owner must not release the active runner lock.' );
		$lock_release->invoke( null, $owner );
		$assert( false === get_option( YoRo_Connector_Runtime::LOCK_KEY ), 'The owner must release its lock.' );
		$stale_lock = 'stale-owner|' . ( time() - 1 );
		add_option( YoRo_Connector_Runtime::LOCK_KEY, $stale_lock, '', false );
		$recovered = $lock_acquire->invoke( null );
		$assert( is_string( $recovered ) && $recovered !== $owner, 'An expired runner lock must be reclaimed atomically.' );
		$lock_release->invoke( null, $stale_lock );
		$assert( $recovered === get_option( YoRo_Connector_Runtime::LOCK_KEY ), 'A stale owner must not release a recovered runner lock.' );
		$lock_release->invoke( null, $recovered );

		$real_wpdb = $wpdb;
		$unavailable_db = new class( $real_wpdb ) {
			public $options;
			public $calls = 0;
			private $real;

			public function __construct( $real ) {
				$this->real = $real;
				$this->options = $real->options;
			}

			public function prepare( $query, ...$args ) {
				return $this->real->prepare( $query, ...$args );
			}

			public function get_var( $query ) {
				$this->calls++;
				return null;
			}
		};
		try {
			$wpdb = $unavailable_db;
			YoRo_Connector_Runtime::tick();
		} finally {
			$wpdb = $real_wpdb;
		}
		$assert( 1 === $unavailable_db->calls && false === get_option( YoRo_Connector_Runtime::LOCK_KEY ), 'Unavailable GET_LOCK must fail closed before tick work.' );

		$owner = $lock_acquire->invoke( null );
		$other_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other_db->set_prefix( $real_wpdb->prefix );
		$session_client = new YoRo_Connector_Smoke_Client();
		try {
			$wpdb = $other_db;
			$session_result = ( new YoRo_Connector_Runtime( $session_client ) )->run_once();
		} finally {
			$wpdb = $real_wpdb;
			$lock_release->invoke( null, $owner );
		}
		$assert( is_wp_error( $session_result ) && 'yoro_connector_runner_lock_lost' === $session_result->get_error_code() && 0 === $session_client->claim_calls, 'A changed database session must fail closed before claiming work.' );

		$worker_code = <<<'PHP'
require $argv[1];
$acquire = new ReflectionMethod( 'YoRo_Connector_Runtime', 'acquire_lock' );
$release = new ReflectionMethod( 'YoRo_Connector_Runtime', 'release_lock' );
fread( STDIN, 1 );
$owner = $acquire->invoke( null );
fwrite( STDOUT, null === $owner ? "BLOCKED\n" : "OWNER\n" );
fflush( STDOUT );
fread( STDIN, 1 );
if ( null !== $owner ) { $release->invoke( null, $owner ); }
PHP;
		$start_worker = static function() use ( $worker_code ) {
			$pipes = [];
			$process = proc_open(
				[ PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', $worker_code, ABSPATH . 'wp-load.php' ],
				[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
				$pipes
			);
			if ( is_resource( $process ) ) { stream_set_timeout( $pipes[1], 15 ); }
			return [ $process, $pipes ];
		};
		$workers = [ $start_worker(), $start_worker() ];
		try {
			$assert( is_resource( $workers[0][0] ) && is_resource( $workers[1][0] ), 'Two independent runner processes must start.' );
			foreach ( $workers as $worker ) { fwrite( $worker[1][0], 'G' ); fflush( $worker[1][0] ); }
			$outcomes = [];
			foreach ( $workers as $worker ) { $outcomes[] = trim( (string) fgets( $worker[1][1] ) ); }
			sort( $outcomes );
			$assert( [ 'BLOCKED', 'OWNER' ] === $outcomes, 'Simultaneous independent runners must have exactly one lock owner.' );

			$expired_while_active = 'expired-while-active|' . ( time() - 1 );
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $expired_while_active, YoRo_Connector_Runtime::LOCK_KEY ) );
			$assert( 1 === $changed, 'The slow-runner fixture must expire an active option row.' );
			$assert( null === $lock_acquire->invoke( null ), 'An expired option must not admit a new runner while its database owner is active.' );
		} finally {
			foreach ( $workers as $worker ) {
				if ( ! is_resource( $worker[0] ) ) { continue; }
				fwrite( $worker[1][0], 'R' ); fclose( $worker[1][0] );
				fclose( $worker[1][1] ); fclose( $worker[1][2] );
				proc_close( $worker[0] );
			}
		}
		$recovered_after_owner_exit = $lock_acquire->invoke( null );
		$assert( is_string( $recovered_after_owner_exit ), 'A stale option must be recoverable after the database owner exits.' );
		$lock_release->invoke( null, $recovered_after_owner_exit );
	} finally {
		delete_option( YoRo_Connector_Runtime::LOCK_KEY );
		if ( null !== $prior_lock ) { add_option( YoRo_Connector_Runtime::LOCK_KEY, $prior_lock, '', false ); }
	}

	foreach ( $batch_operations as $batch_operation ) {
		YoRo_Connector_Execution_Journal::clear( $batch_operation );
	}
} finally {
	foreach ( $original_options as $option_name => $original_value ) {
		if ( $original_value === $missing_option ) {
			delete_option( $option_name );
		} else {
			update_option( $option_name, $original_value, false );
		}
	}

	if ( $admin_id > 0 ) {
		if ( $original_access_exists ) {
			update_user_meta( $admin_id, YoRo_WordPress_Actor::ACCESS_META_KEY, $original_access );
		} else {
			delete_user_meta( $admin_id, YoRo_WordPress_Actor::ACCESS_META_KEY );
		}
		if ( '' === $original_subject ) {
			delete_user_meta( $admin_id, YoRo_WordPress_Actor::SUBJECT_META_KEY );
		} else {
			update_user_meta( $admin_id, YoRo_WordPress_Actor::SUBJECT_META_KEY, $original_subject );
		}
	}

	wp_set_current_user( $original_user_id );
}

// B1 canonical projection closes the entire envelope, including all legacy extensions.
$payment_schema = YoRo_Commerce_Contract_Builder::execution_output_schema( 'orders.get_payment_context' );
$assert( YoRo_Commerce_Contract_Builder::strict_output_validation_ready( 'orders.get_payment_context' ), 'Payment output is strict at every nested object.' );
$assert( false === $payment_schema['properties']['extensions']['additionalProperties'], 'Payment extensions cannot carry raw gateway or metadata payloads.' );
$payment_raw = [
	'success' => true,
	'payment_context' => [ 'order_id' => 123, 'order_number' => '123', 'status' => 'processing', 'currency' => 'USD', 'total' => '100.00', 'total_refunded' => '25.00', 'is_paid' => true, 'needs_payment' => false, 'raw_gateway' => 'B1-SECRET-SENTINEL', 'evidence' => [ 'gateway_verification' => 'verified' ] ],
	'meta' => [ 'password' => 'B1-SECRET-SENTINEL' ],
	'extensions' => [ 'gateway' => 'B1-SECRET-SENTINEL' ],
	'summary' => 'B1-SECRET-SENTINEL',
];
$payment_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $payment_raw, 'orders.get_payment_context' );
$assert( array_keys( $payment_payload ) === $payment_schema['required'], 'Payment public envelope exactly matches its canonical schema.' );
$assert( '75.00' === $payment_payload['data']['payment_context']['remaining_refundable'], 'Partial refunds retain existing WooCommerce remaining-amount semantics.' );
$assert( false === strpos( wp_json_encode( $payment_payload ), 'B1-SECRET-SENTINEL' ), 'No raw payment metadata, summary or legacy extension escapes the allowlist.' );
$assert( 'not_observed' === $payment_payload['data']['payment_context']['evidence']['gateway_verification'], 'Provider labels cannot overwrite evidence authority.' );
$assert( null === $payment_payload['data']['payment_context']['transaction_id'] && null === $payment_payload['data']['payment_context']['date_paid'], 'Absent evidence remains unknown.' );
$payment_unknown = YoRo_Order_Presenter::normalize_payment_context( [ 'order_id' => 123, 'order_number' => '123', 'total' => 'unknown', 'is_paid' => 'true' ] );
$assert( null === $payment_unknown['total'] && null === $payment_unknown['remaining_refundable'] && null === $payment_unknown['is_paid'], 'Missing or malformed amount and paid facts never become zero or false.' );

$link_url = 'https://example.invalid/custom/?key=wc_order_fixture_only';
$link_raw = [
	'success' => true, 'summary' => 'B2-SECRET-SENTINEL', 'extensions' => [ 'raw_key' => 'B2-SECRET-SENTINEL' ],
	'payment_link' => [ 'order_id' => 91, 'order_number' => '91', 'status' => 'pending', 'needs_payment' => true, 'payment_link_available' => true, 'payment_url' => $link_url, 'order_key' => 'B2-SECRET-SENTINEL', 'query_params' => [ 'key' => 'B2-SECRET-SENTINEL' ] ],
];
$link_payload = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $link_raw, 'orders.get_payment_link' );
$assert( true === $link_payload['success'] && $link_url === $link_payload['data']['payment_link']['payment_url'], 'B2 returns the exact owner URL to the canonical result consumer.' );
$assert( false === strpos( wp_json_encode( $link_payload ), 'B2-SECRET-SENTINEL' ), 'B2 closes raw keys, query parameters, summaries and extensions.' );
$link_raw['payment_link']['needs_payment'] = false;
$assert( null === YoRo_Commerce_Contract_Builder::normalize_payment_link( $link_raw['payment_link'] ), 'Nonpayable output cannot carry an available payment capability.' );

// Exercise refund rendering against hostile data, with the production renderer.
$refund_renderer_test = <<<'JS'
const fs=require('fs'), vm=require('vm'), assert=require('assert');
const context={window:{}}; vm.createContext(context); vm.runInContext(fs.readFileSync(process.argv[1],'utf8'),context);
const escapeHtml=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const renderer=context.window.YoRoResultRenderers.init({escapeHtml,shouldShowDebugJson:()=>false});
const base={success:true,action:'orders.get_refunds',display_type:'order_refund_history',order_id:123,currency:'USD',total_refunded:'10.00',remaining_refundable:'90.00',refunds:[{refund_id:456,amount:'10.00',reason:'<script>SECRET</script>',created_at:'2026-09-14T00:00:00+00:00',refunded_payment:false}],pagination:{limit:20,page:1,has_more:false,snapshot_consistent:false}};
const render=value=>renderer.renderResult({success:true,stage:"done",parsed:{language:"en"},result:value});
let html=render(base); assert(html.includes('&lt;script&gt;SECRET&lt;/script&gt;')&&!html.includes('<script>'));
assert(html.includes('Payment transfer is not verified')&&!/<button|<input|data-action=/.test(html));
assert(render({...base,data:{order_id:123,currency:'USD',total_refunded:'0.00',remaining_refundable:'100.00',refunds:[],pagination:base.pagination}}).includes('Refund history'));
for(const mutate of [v=>v.refunds[0].amount='NaN',v=>v.refunds[0].created_at='invalid',v=>v.refunds[0].refunded_payment=1,v=>v.refunds.push({...v.refunds[0]}),v=>v.pagination.page=101,v=>v.pagination.snapshot_consistent=true,v=>v.order_id='123',v=>v.refunds[0].reason='x'.repeat(501)]){const v=JSON.parse(JSON.stringify(base));mutate(v);assert(render(v).includes('Refund observations are unavailable'));}

const facts={order_id:123,refund_id:456,amount:'1.25',currency:'USD',status:'refunded',outcome_unknown:false,refunded_payment:true,restocked:false,retry_allowed:false,current_version:'a'.repeat(64),next_action:null};
for(const canonical of [false,true]) {
 const execution={success:true,action:'orders.refund',display_type:'generic',provider_secret:'DO_NOT_RENDER_SECRET',...(canonical?{data:{refund:facts}}:{data:[],refund:facts})};
 let card=render(execution); assert(card.includes('Gateway refund verified')&&card.includes('1.25 USD')&&!card.includes('DO_NOT_RENDER_SECRET')&&!/<button|<input/.test(card));
 const unknown={...facts,refund_id:null,current_version:null,status:'reconciliation_required',outcome_unknown:true,refunded_payment:null,next_action:'orders.get_refunds'};
 execution.success=false;if(canonical)execution.data.refund=unknown;else execution.refund=unknown;
 card=render(execution);assert(card.includes('Refund requires reconciliation')&&card.includes('Do not retry')&&!card.includes('Gateway refund verified'));
}
const externalFacts={order_id:123,refund_id:789,amount:'1.25',currency:'USD',status:'recorded',outcome_unknown:false,refunded_payment:false,restocked:false,retry_allowed:false,current_version:'b'.repeat(64),next_action:null,payment_transfer_initiated:false,external_transfer_verification:'not_observed'};
for(const canonical of [false,true]) {
 const execution={success:true,action:'orders.record_external_refund',display_type:'generic',provider_secret:'DO_NOT_RENDER_EXTERNAL_SECRET',...(canonical?{data:{external_refund:externalFacts}}:{data:[],external_refund:externalFacts})};
 let card=render(execution);assert(card.includes('Local refund record verified')&&card.includes('No money was sent')&&card.includes('Human assertion')&&card.includes('did not observe')&&!card.includes('DO_NOT_RENDER_EXTERNAL_SECRET')&&!/<button|<input/.test(card));
 const unknown={...externalFacts,refund_id:null,current_version:null,status:'reconciliation_required',outcome_unknown:true,refunded_payment:null,next_action:'orders.get_refunds'};
 execution.success=false;if(canonical)execution.data.external_refund=unknown;else execution.external_refund=unknown;
 for(const outerSuccess of [false,true]) {card=renderer.renderResult({success:outerSuccess,stage:'done',result:execution});assert(card.includes('Local refund recording requires reconciliation')&&card.includes('Do not retry')&&card.includes('No money was sent')&&!card.includes('Local refund record verified'));}
}
const externalConfirmation={success:true,stage:'confirm',confirm_required:true,pending_operation_active:true,pending_operation_token:'pending-external-fixture',parsed:{action:'orders.record_external_refund',params:{order_id:123,amount:'1.25'}},result:{action:'orders.record_external_refund',order_id:123,amount:'1.25',currency:'USD',remaining_after_refund:'8.75',reason:'<script>do not render</script>',payment_transfer_initiated:false,external_transfer_verification:'not_observed'}};
let approval=renderer.renderResult(externalConfirmation);assert(approval.includes('No money will be sent')&&approval.includes('already refunded outside WooCommerce')&&approval.includes('does not observe or independently verify')&&approval.includes('1.25 USD')&&approval.includes('&lt;script&gt;')&&!approval.includes('<script>')&&approval.includes('Approve change'));
approval=renderer.renderResult({...externalConfirmation,result:{...externalConfirmation.result,blocked:true,block_reason:'stale_guard'}});assert(!approval.includes('Approve change'));
approval=renderer.renderResult({...externalConfirmation,pending_operation_token:'',direct_confirmation_allowed:true});assert(!approval.includes('Approve change'));
console.log('PASS: refund and external-record renderers: raw/canonical, unknown, no false payment proof, escaped confirmation and no direct bypass.');
JS;
$process = proc_open( [ 'node', '-e', $refund_renderer_test, $plugin_root . '/assets/js/yoro-ai-result-renderers.js' ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
$assert( is_resource( $process ), 'Refund renderer test process starts.' );
if ( is_resource( $process ) ) {
	$output = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
	$assert( 0 === proc_close( $process ), 'Refund renderer checks: ' . $errors ); echo $output;
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo canonical connector runtime smoke contract passed.\n";
