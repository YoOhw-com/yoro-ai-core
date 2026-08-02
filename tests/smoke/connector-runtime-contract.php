<?php
/**
 * YoRo canonical connector publisher and pull-runtime smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/connector-runtime-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

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
	foreach ( [ 'orders.search', 'orders.get_details', 'orders.find_by_customer', 'customers.lookup', 'products.get_inventory_queue', 'content.search', 'content.get', 'comments.list', 'media.search', 'media.get', 'site.get_info', 'site.get_health', 'site.get_update_status', 'products.search', 'products.get_details', 'orders.get_timeline', 'fulfillment.get_tracking' ] as $canonical_read ) {
		$assert( false === ( $actions[ $canonical_read ]['output_schema']['properties']['data']['additionalProperties'] ?? true ), sprintf( '%s must publish a closed action-level data schema.', $canonical_read ) );
		$assert( 1 === ( $actions[ $canonical_read ]['extensions']['yoro.contracts']['execution_payload_version'] ?? 0 ), sprintf( '%s must publish the canonical execution payload marker.', $canonical_read ) );
		$assert( ! empty( $actions[ $canonical_read ]['extensions']['yoro.contracts']['strict_output_validation_ready'] ), sprintf( '%s must publish accurate action-level strict validation readiness.', $canonical_read ) );
	}
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
	$assert( in_array( 'plugins.activate', $admin_ready_names, true ), 'Administration actions may publish only after policy and backup gates are ready.' );
	$assert( ! empty( $admin_ready_manifest['extensions']['wordpress.runtime']['admin_actions_ready'] ), 'The ready manifest must disclose administration capability readiness.' );
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

	$runtime_result = ( new YoRo_Connector_Runtime( $client ) )->run_once();
	$assert( ! is_wp_error( $runtime_result ) && ! empty( $runtime_result['success'] ), 'Pull runtime must complete one claimed read-only operation.' );
	$assert( 1 === count( $client->events ), 'Pull runtime must acknowledge the claim once.' );
	$assert( 1 === count( $client->renewals ), 'Pull runtime must renew the lease before local execution.' );
	$assert( 1 === count( $client->results ), 'Pull runtime must publish one canonical execution result.' );
	$assert( 'operation.claimed' === ( $client->events[0]['payload']['event_type'] ?? '' ), 'Pull runtime acknowledgement must use operation.claimed.' );
	$assert( 'executed' === ( $client->results[0]['payload']['status'] ?? '' ), 'The safe read smoke operation must finish as executed.' );
	$canonical_result = $client->results[0]['payload']['result'] ?? [];
	$assert( false === ( $actions['orders.search']['output_schema']['additionalProperties'] ?? true ), 'Read outputs must use the exact shared execution schema.' );
	$assert( array_keys( (array) $canonical_result ) === ( $actions['orders.search']['output_schema']['required'] ?? [] ), 'Runtime result roots must exactly match the published output schema.' );
	$assert( isset( $canonical_result['data']['orders'] ) || isset( $canonical_result['data']['items'] ), 'Canonical results must retain action data under the stable data object.' );
	$assert( $claim_token === ( $client->results[0]['claim_token'] ?? '' ), 'Result publication must retain exact lease authority.' );
	$assert( 1 === ( $client->results[0]['lease_generation'] ?? 0 ), 'Result publication must retain the lease generation.' );
	$assert( $admin_id === get_current_user_id(), 'Connector execution must restore the original WordPress user.' );

	$journal_after_publish = YoRo_Connector_Execution_Journal::begin( $operation );
	$assert( 'completed' === ( $journal_after_publish['state'] ?? '' ), 'Accepted results must retain the durable local execution receipt.' );
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

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo canonical connector runtime smoke contract passed.\n";
