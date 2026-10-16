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

$assert( 'https://r2-api.yorohq.com' === YoRo_Connector_Client::R2_ORIGIN, 'The isolated R2 lane must pin the exact custom origin.' );
$assert( ! YoRo_Connector_Runtime::r2_read_allowed(), 'The generic smoke must retain R2 default OFF.' );

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
	public $event_error = null;
	public $renewal_error = null;
	public $result_error = null;

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
		if ( is_wp_error( $this->renewal_error ) ) { return $this->renewal_error; }

		return [ 'success' => true ];
	}

	public function publish_event( array $payload, $claim_token = '', $lease_generation = 0 ) {
		$this->events[] = compact( 'payload', 'claim_token', 'lease_generation' );
		if ( is_wp_error( $this->event_error ) ) { return $this->event_error; }

		return [ 'success' => true ];
	}

	public function publish_result( array $payload, $claim_token, $lease_generation ) {
		$this->results[] = compact( 'payload', 'claim_token', 'lease_generation' );
		if ( is_wp_error( $this->result_error ) ) { return $this->result_error; }

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
	$all_batch_claims = $batch_client->claims;
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
	$assert( 0 === ( $poll_result['processed'] ?? -1 ) && [] === $poll_result['deliveries'] && [] === $poll_client->results, 'An initial empty claim must leave later deliveries for the next tick.' );
	$assert( [] === $slept && 1 === $poll_client->claim_calls && 0.0 === $fake_time && 2 === count( $poll_client->claims ), 'An empty claim must exit immediately without sleeping or repolling.' );
	$assert( ! empty( $poll_result['drained'] ), 'A successful initial empty claim reports a drained queue.' );
	$next_tick_result = ( new YoRo_Connector_Runtime( $poll_client, $clock, $sleeper ) )->run_batch();
	$assert( 1 === $next_tick_result['processed'] && 1 === count( $next_tick_result['deliveries'] ) && 1 === count( $poll_client->results ), 'The next tick must process the deferred delivery exactly once.' );
	$assert( ! empty( $next_tick_result['drained'] ) && 3 === $poll_client->claim_calls && [] === $slept, 'An empty claim after a delivery must preserve that delivery and stop without another poll.' );
	$assert( $batch_client->claims[0]['delivery']['delivery_id'] === ( $next_tick_result['deliveries'][0]['delivery_id'] ?? '' ), 'The returned batch must retain the processed delivery identity.' );

	foreach ( [
		[ null, 45 ],
		[ 0, 45 ],
		[ -1, 45 ],
		[ 'malformed', 45 ],
		[ 999, 45 ],
		[ 2, 5 ],
		[ 0.2, 3 ],
		[ INF, 45 ],
	] as $case ) {
		$fake_time = 0.0;
		$slept = [];
		$empty_client = new YoRo_Connector_Smoke_Client();
		$empty_client->claim = [ 'success' => true, 'empty' => true ];
		if ( null !== $case[0] ) { $empty_client->claim['retry_after'] = $case[0]; }
		$empty_result = ( new YoRo_Connector_Runtime( $empty_client, $clock, $sleeper ) )->run_batch( 5, $case[1] );
		$assert( 0 === ( $empty_result['processed'] ?? -1 ) && ! empty( $empty_result['drained'] ), 'A permanently empty queue must terminate normally.' );
		$assert( [] === $slept && 1 === $empty_client->claim_calls && 0.0 === $fake_time, 'A successful empty claim must ignore every retry hint and remaining budget without sleeping or repolling.' );
	}

	$busy_client = new YoRo_Connector_Smoke_Client();
	$busy_client->claims = array_slice( $all_batch_claims, 0, 6 );
	$busy_result = ( new YoRo_Connector_Runtime( $busy_client, $clock, $sleeper ) )->run_batch( 99, 99 );
	$assert( 5 === $busy_result['processed'] && 5 === count( $busy_result['deliveries'] ) && 5 === $busy_client->claim_calls && 5 === count( $busy_client->results ), 'A busy queue must retain the hard five-delivery cap even when a larger limit is requested.' );
	$assert( empty( $busy_result['drained'] ) && 1 === count( $busy_client->claims ) && [] === $slept, 'The delivery cap must defer unclaimed work without sleeping or declaring the queue drained.' );
	$assert( array_column( array_column( array_slice( $all_batch_claims, 0, 5 ), 'delivery' ), 'delivery_id' ) === array_column( $busy_result['deliveries'], 'delivery_id' ), 'The busy batch must return every claimed delivery in order without loss or duplication.' );

	$fake_time = 0.0;
	$timed_client = new YoRo_Connector_Smoke_Client();
	$timed_client->claim_callback = static function() use ( &$fake_time, $batch_client ) {
		$fake_time += 23;
		return $batch_client->claims[0];
	};
	$timed_result = ( new YoRo_Connector_Runtime( $timed_client, $clock, $sleeper ) )->run_batch( 5, 99 );
	$assert( 2 === $timed_result['processed'] && 2 === $timed_client->claim_calls && 46.0 === $fake_time && [] === $slept, 'The busy queue must not start another delivery after the hard 45-second budget expires.' );
	$assert( empty( $timed_result['drained'] ), 'A time-capped busy queue must not be reported as drained.' );

	foreach ( [ 'event_error', 'renewal_error', 'result_error' ] as $error_stage ) {
		$fake_time = 0.0;
		YoRo_Connector_Execution_Journal::clear( $batch_operations[5] );
		$stage_client = new YoRo_Connector_Smoke_Client();
		$stage_client->claims = [ $all_batch_claims[5], $all_batch_claims[0] ];
		$stage_client->$error_stage = new WP_Error( 'simulated_' . $error_stage, 'Transport failed.' );
		$stage_executor_calls = 0;
		$stage_probe = static function() use ( &$stage_executor_calls ) { $stage_executor_calls++; };
		add_action( 'yoro_before_action_execute', $stage_probe );
		try {
			$stage_result = ( new YoRo_Connector_Runtime( $stage_client, $clock, $sleeper ) )->run_batch();
			$assert( is_wp_error( $stage_result ) && 'simulated_' . $error_stage === $stage_result->get_error_code() && 1 === $stage_client->claim_calls && [] === $slept, 'Acknowledgement, lease and result errors must stop the batch without repolling.' );
			$assert( ( 'result_error' === $error_stage ? 1 : 0 ) === $stage_executor_calls, 'Acknowledgement and lease failures must prevent execution; result failures must not lose the execution receipt.' );
			if ( 'result_error' === $error_stage ) {
				$failed_payload = $stage_client->results[0]['payload'];
				$stage_client->result_error = null;
				$stage_client->claims = [ $all_batch_claims[5], [ 'success' => true, 'empty' => true ] ];
				$replay_result = ( new YoRo_Connector_Runtime( $stage_client, $clock, $sleeper ) )->run_batch();
				$assert( ! is_wp_error( $replay_result ) && 1 === $replay_result['processed'] && ! empty( $replay_result['drained'] ) && 1 === $stage_executor_calls && $failed_payload === $stage_client->results[1]['payload'], 'A failed result publication must replay the journaled result without executing the business action again.' );
			}
		} finally {
			remove_action( 'yoro_before_action_execute', $stage_probe );
		}
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

	$fake_time = 0.0;
	$failure_client = new YoRo_Connector_Smoke_Client();
	$failure_client->claim = new WP_Error( 'simulated_claim_failure', 'Claim failed.' );
	$failure_result = ( new YoRo_Connector_Runtime( $failure_client, $clock, $sleeper ) )->run_batch();
	$assert( is_wp_error( $failure_result ) && 1 === $failure_client->claim_calls && [] === $failure_client->results, 'A failed claim must not retry or execute a business action.' );
	$fake_time = 0.0;
	$invalid_empty_client = new YoRo_Connector_Smoke_Client();
	$invalid_empty_client->claim = [ 'success' => false, 'empty' => true, 'retry_after' => 1 ];
	$invalid_empty_result = ( new YoRo_Connector_Runtime( $invalid_empty_client, $clock, $sleeper ) )->run_batch();
	$assert( 0 === $invalid_empty_result['processed'] && empty( $invalid_empty_result['drained'] ) && 1 === $invalid_empty_client->claim_calls && [] === $slept && [] === $invalid_empty_client->results, 'An unsuccessful empty claim must stop without declaring the queue drained or executing work.' );
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
		if ( '' === $original_subject ) {
			delete_user_meta( $admin_id, YoRo_WordPress_Actor::SUBJECT_META_KEY );
		} else {
			update_user_meta( $admin_id, YoRo_WordPress_Actor::SUBJECT_META_KEY, $original_subject );
		}
	}

	wp_set_current_user( $original_user_id );
}

require __DIR__ . '/../unit/connector-worker-session-test.php';

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo canonical connector runtime smoke contract passed.\n";
