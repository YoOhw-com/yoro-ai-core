<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Connector_Runtime {

	const CRON_HOOK = 'yoro_connector_runtime_tick';
	const CRON_SCHEDULE = 'yoro_connector_minute';
	const CRON_SCHEDULE_OPTION = 'yoro_connector_runtime_schedule_version';
	const CRON_SCHEDULE_VERSION = 2;
	const LOCK_KEY = 'yoro_connector_runtime_lock';
	const LOCK_SECONDS = 120;
	const MAX_DELIVERIES_PER_TICK = 5;
	const MAX_TICK_SECONDS = 45;
	const DEFAULT_RETRY_SECONDS = 5;

	private $client;
	private $clock;
	private $sleeper;
	private static $held_lock = null;
	private static $held_lock_name = null;

	public function __construct( $client = null, $clock = null, $sleeper = null ) {
		$this->client = $client instanceof YoRo_Connector_Client ? $client : new YoRo_Connector_Client();
		$this->clock = is_callable( $clock ) ? $clock : static function() {
			return function_exists( 'hrtime' ) ? hrtime( true ) / 1000000000 : microtime( true );
		};
		$this->sleeper = is_callable( $sleeper ) ? $sleeper : static function( $seconds ) {
			usleep( (int) round( $seconds * 1000000 ) );
		};
	}

	public static function init() {
		add_action( 'yoro_connector_r2_product_read', [ __CLASS__, 'r2_tick' ] );
		add_filter( 'cron_schedules', [ __CLASS__, 'register_cron_schedule' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'tick' ] );
		self::schedule();
	}

	public static function register_cron_schedule( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : [];
		$schedules[ self::CRON_SCHEDULE ] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => 'Every minute for YoRo connector runtime',
		];

		return $schedules;
	}

	public static function schedule() {
		if ( self::CRON_SCHEDULE_VERSION > absint( get_option( self::CRON_SCHEDULE_OPTION, 0 ) ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::CRON_HOOK, [], 'yoro-ai-core' );
			}
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$next_minute = ( (int) floor( time() / MINUTE_IN_SECONDS ) + 1 ) * MINUTE_IN_SECONDS;
			wp_schedule_event( $next_minute, self::CRON_SCHEDULE, self::CRON_HOOK );
		}

		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			update_option( self::CRON_SCHEDULE_OPTION, self::CRON_SCHEDULE_VERSION, false );
		}
	}

	public static function tick() {
		$lock = self::acquire_lock();

		if ( null === $lock ) {
			return;
		}

		try {
			$client = new YoRo_Connector_Client();
			$publisher = new YoRo_Connector_Snapshot_Publisher( $client );
			$publisher->publish_if_due();

			if ( self::live_cutover_allowed() ) {
				( new self( $client ) )->run_batch();
			}
		} finally {
			self::release_lock( $lock );
		}
	}

	private static function acquire_lock() {
		if ( null !== self::$held_lock ) {
			return null;
		}

		global $wpdb;
		$lock_name = 'yoro_connector_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->options );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) ) {
			return null;
		}

		try {
			$lock = wp_generate_uuid4() . '|' . ( time() + self::LOCK_SECONDS );
			if ( ! self::insert_lock( $lock ) ) {
				$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_KEY ) );
				$parts = is_string( $current ) ? explode( '|', $current, 2 ) : [];

				if ( 2 !== count( $parts ) || ! ctype_digit( $parts[1] ) || (int) $parts[1] >= time() ) {
					return null;
				}

				// GET_LOCK excludes a live runner, even if its option expiry has passed.
				if ( ! self::delete_lock_if_owned( $current ) || ! self::insert_lock( $lock ) ) {
					return null;
				}
			}

			self::$held_lock = $lock;
			self::$held_lock_name = $lock_name;
			return $lock;
		} finally {
			if ( null === self::$held_lock ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
	}

	private static function release_lock( $lock ) {
		if ( null === self::$held_lock || ! hash_equals( self::$held_lock, $lock ) ) {
			return;
		}

		global $wpdb;
		try {
			self::delete_lock_if_owned( $lock );
		} finally {
			try {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$held_lock_name ) );
			} finally {
				self::$held_lock = null;
				self::$held_lock_name = null;
			}
		}
	}

	private static function insert_lock( $lock ) {
		global $wpdb;
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::LOCK_KEY, $lock, 'off' ) );

		if ( 1 === $inserted ) {
			self::clear_lock_cache();
			return true;
		}

		return false;
	}

	private static function delete_lock_if_owned( $lock ) {
		global $wpdb;
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_KEY, $lock ) );

		if ( 1 === $deleted ) {
			self::clear_lock_cache();
			return true;
		}

		return false;
	}

	private static function clear_lock_cache() {
		wp_cache_delete( self::LOCK_KEY, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function runner_lock_valid() {
		if ( null === self::$held_lock ) {
			return true;
		}

		global $wpdb;
		$owns_session = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::$held_lock_name ) );
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_KEY ) );

		return 1 === (int) $owns_session && self::$held_lock === $current;
	}

	private static function lock_lost_error() {
		return new WP_Error( 'yoro_connector_runner_lock_lost', 'The connector runner lock was lost.' );
	}

	public function run_batch( $limit = self::MAX_DELIVERIES_PER_TICK, $time_budget = self::MAX_TICK_SECONDS ) {
		$limit       = max( 1, min( self::MAX_DELIVERIES_PER_TICK, absint( $limit ) ) );
		$time_budget = max( 1, min( self::MAX_TICK_SECONDS, absint( $time_budget ) ) );
		$deadline    = ( $this->clock )() + $time_budget;
		$deliveries  = [];
		$drained     = false;

		while ( count( $deliveries ) < $limit && ( $this->clock )() < $deadline ) {
			$result = $this->run_once();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! is_array( $result ) || ! empty( $result['skipped'] ) ) {
				break;
			}

			if ( ! empty( $result['empty'] ) ) {
				if ( empty( $result['success'] ) ) {
					break;
				}

				$drained = true;
				$delay = self::retry_delay( $result['retry_after'] ?? null );
				$before_sleep = ( $this->clock )();
				$remaining = $deadline - $before_sleep;

				if ( $remaining <= 0 || $delay >= $remaining ) {
					break;
				}

				( $this->sleeper )( $delay );

				// A broken sleeper or clock must never turn an empty queue into a spin.
				if ( ( $this->clock )() <= $before_sleep ) {
					break;
				}

				continue;
			}

			$drained = false;
			$deliveries[] = $result;
		}

		return [
			'success'    => true,
			'processed'  => count( $deliveries ),
			'drained'    => $drained,
			'deliveries' => $deliveries,
		];
	}

	private static function retry_delay( $hint ) {
		if ( ! is_numeric( $hint ) || ! is_finite( (float) $hint ) || (float) $hint <= 0 ) {
			return self::DEFAULT_RETRY_SECONDS;
		}

		return max( 1, min( self::DEFAULT_RETRY_SECONDS, (int) ceil( (float) $hint ) ) );
	}

	public function run_once() {
		if ( ! self::live_cutover_allowed() ) {
			return [
				'success' => true,
				'skipped' => true,
				'reason'  => 'live_cutover_not_allowed',
			];
		}

		if ( ! self::runner_lock_valid() ) {
			return self::lock_lost_error();
		}

		$claim = $this->client->claim_delivery();

		if ( is_wp_error( $claim ) || ! empty( $claim['empty'] ) ) {
			return $claim;
		}

		$delivery = is_array( $claim['delivery'] ?? null ) ? $claim['delivery'] : [];
		$authority = $this->delivery_authority( $delivery );

		if ( is_wp_error( $authority ) ) {
			return $authority;
		}

		if ( ! self::runner_lock_valid() ) {
			return self::lock_lost_error();
		}

		$acknowledged = $this->client->publish_event(
			$this->acknowledgement_event( $delivery ),
			$authority['claim_token'],
			$authority['lease_generation']
		);

		if ( is_wp_error( $acknowledged ) ) {
			return $acknowledged;
		}

		$accepted = [];

		foreach ( $authority['operations'] as $operation ) {
			if ( ! self::runner_lock_valid() ) {
				return self::lock_lost_error();
			}

			$renewed = $this->client->renew_lease(
				$authority['delivery_id'],
				$authority['claim_token'],
				$authority['lease_generation']
			);

			if ( is_wp_error( $renewed ) ) {
				return $renewed;
			}

			$result = $this->result_for_operation( $operation );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! self::runner_lock_valid() ) {
				return self::lock_lost_error();
			}

			$published = $this->client->publish_result(
				$result,
				$authority['claim_token'],
				$authority['lease_generation']
			);

			if ( is_wp_error( $published ) ) {
				return $published;
			}

			$accepted[] = $published;

			if ( 'executed' !== sanitize_key( $result['status'] ?? '' ) ) {
				break;
			}
		}

		return [
			'success'     => true,
			'delivery_id' => $authority['delivery_id'],
			'accepted'    => $accepted,
		];
	}

	public static function live_cutover_allowed() {
		$runtime = YoRo_Connector_Snapshot_Publisher::runtime();

		return is_array( $runtime )
			&& ! empty( $runtime['cutover']['cutover_allowed'] )
			&& 'pull_v1' === sanitize_key( $runtime['delivery_protocol'] ?? '' );
	}

	/** Explicit operator action only: this lane is never scheduled by the generic cron. */
	public static function r2_read_allowed() {
		$status = YoRo_AI_Settings::get_ai_api_status();
		$connection = is_array( $status['connection'] ?? null ) ? $status['connection'] : [];

		return defined( 'YORO_R2_PRODUCT_READ_ENABLED' ) && true === YORO_R2_PRODUCT_READ_ENABLED
			&& 'https://wp-canary-us.yorohq.com' === untrailingslashit( home_url() )
			&& defined( 'YORO_R2_PRODUCT_READ_KEY' ) && is_string( YORO_R2_PRODUCT_READ_KEY ) && '' !== YORO_R2_PRODUCT_READ_KEY
			&& defined( 'YORO_R2_INSTALLATION_UUID' ) && is_string( YORO_R2_INSTALLATION_UUID )
			&& YORO_R2_INSTALLATION_UUID === YoRo_AI_Settings::get_installation_uuid()
			&& YORO_R2_INSTALLATION_UUID === ( $connection['installation_uuid'] ?? null )
			&& 'staging' === ( $connection['environment'] ?? null )
			&& YoRo_AI_Settings::is_ai_api_account_connected( $status )
			&& class_exists( 'WooCommerce' );
	}

	public static function r2_tick() {
		if ( ! self::r2_read_allowed() ) {
			return new WP_Error( 'yoro_r2_unavailable', 'R2 read transport is unavailable.' );
		}
		$lock = self::acquire_lock();
		if ( null === $lock ) {
			return new WP_Error( 'yoro_r2_locked', 'A connector runner is already active.' );
		}
		try {
			return ( new self() )->run_r2_once();
		} finally {
			self::release_lock( $lock );
		}
	}

	public function run_r2_once() {
		if ( ! self::r2_read_allowed() || null === self::$held_lock || ! self::runner_lock_valid() ) {
			return new WP_Error( 'yoro_r2_unavailable', 'A locked R2 operator runner is required.' );
		}
		$claim = $this->client->r2_request( 'claim' );
		if ( is_wp_error( $claim ) || ! empty( $claim['empty'] ) ) {
			return $claim;
		}
		$authority = $this->delivery_authority( (array) ( $claim['delivery'] ?? [] ) );
		if ( is_wp_error( $authority ) || 1 !== count( $authority['operations'] ) ) {
			return new WP_Error( 'yoro_r2_invalid_delivery', 'Exactly one R2 operation is required.' );
		}
		$operation = $authority['operations'][0];
		$valid = $this->validate_r2_operation( $operation );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		foreach ( [ 'ack', 'lease' ] as $step ) {
			if ( ! self::runner_lock_valid() || ! self::r2_read_allowed() ) {
				return self::lock_lost_error();
			}
			$response = $this->client->r2_request( $step, $operation['envelope_id'], [], $authority['claim_token'], $authority['lease_generation'] );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}
		$valid = $this->validate_r2_operation( $operation );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$journal = YoRo_Connector_Execution_Journal::begin( $operation );
		if ( 'conflict' === ( $journal['state'] ?? '' ) ) {
			return new WP_Error( 'yoro_r2_journal_conflict', 'The journal is bound to another operation envelope.' );
		}
		if ( 'completed' === ( $journal['state'] ?? '' ) ) {
			$result = $journal['result'] ?? [];
		} elseif ( 'executing' === ( $journal['state'] ?? '' ) ) {
			$result = $this->failed_result( $operation, 'local_execution_outcome_unknown', 'The prior R2 read stopped before its result was journaled.' );
			YoRo_Connector_Execution_Journal::complete( $operation, $result );
		} else {
			$original = get_current_user_id();
			wp_set_current_user( $valid['user_id'] );
			try {
				$products = [];
				$id = wc_get_product_id_by_sku( $operation['params']['sku'] );
				$product = $id ? wc_get_product( $id ) : false;
				if ( $product instanceof WC_Product && 'simple' === $product->get_type() && 'publish' === $product->get_status()
					&& $operation['params']['sku'] === $product->get_sku() ) {
					$products[] = [ 'id' => (int) $product->get_id(), 'name' => sanitize_text_field( $product->get_name() ),
						'sku' => $product->get_sku(), 'status' => 'publish', 'type' => 'simple', 'price' => (string) $product->get_price(),
						'currency' => get_woocommerce_currency(), 'stock_quantity' => $product->get_stock_quantity(),
						'stock_status' => $product->get_stock_status(), 'version' => hash( 'sha256', wp_json_encode( $product->get_data() ) ) ];
				}
				$message = 'Product read completed.';
				$result = array_merge( $this->result_identity( $operation ), [ 'status' => 'executed', 'summary' => $message,
					'result' => [ 'success' => true, 'action' => 'products.search', 'message' => $message, 'summary' => $message,
						'data' => [ 'products' => $products, 'count' => count( $products ), 'page_info' => [ 'has_next_page' => false, 'next_cursor' => null ] ],
						'read_only' => true, 'undoable' => false, 'result_type' => 'products', 'display_type' => 'products_list',
						'assistant_summary' => $message, 'extensions' => (object) [] ], 'occurred_at' => gmdate( 'c' ) ] );
			} finally {
				wp_set_current_user( $original );
			}
			YoRo_Connector_Execution_Journal::complete( $operation, $result );
		}
		if ( ! self::runner_lock_valid() || is_wp_error( $this->validate_r2_operation( $operation ) ) ) {
			return self::lock_lost_error();
		}

		return $this->client->r2_request( 'result', $operation['envelope_id'], $result, $authority['claim_token'], $authority['lease_generation'] );
	}

	private function validate_r2_operation( array $operation ) {
		$params = $operation['params'] ?? [];
		$facts = $operation['extensions']['yoro.mcp_r2_product_read'] ?? [];
		$manifest = YoRo_Connector_Snapshot_Publisher::current_manifest_state();
		$manifest_expiry = strtotime( $manifest['expires_at'] ?? '' );
		$status = YoRo_AI_Settings::get_ai_api_status();
		if ( ! self::r2_read_allowed() || 'products.search' !== ( $operation['action'] ?? null )
			|| false === $manifest_expiry || $manifest_expiry <= time()
			|| [ 'yoro_view_store_data' ] !== ( $operation['actor']['permissions'] ?? null )
			|| ! is_array( $params ) || 4 !== count( $params ) || array_diff( array_keys( $params ), [ 'sku', 'status', 'type', 'limit' ] )
			|| ! is_string( $params['sku'] ?? null ) || ! preg_match( '/\A[A-Za-z0-9._-]{1,128}\z/D', $params['sku'] )
			|| 'publish' !== ( $params['status'] ?? null ) || 'simple' !== ( $params['type'] ?? null ) || 1 !== ( $params['limit'] ?? null )
			|| ! is_array( $facts ) || 'https://r2-api.yorohq.com/mcp/early-access' !== ( $facts['resource'] ?? null )
			|| YORO_R2_INSTALLATION_UUID !== ( $facts['installation_uuid'] ?? null )
			|| ( $status['site']['id'] ?? null ) !== ( $facts['site_id'] ?? null )
			|| ( $status['credential']['id'] ?? null ) !== ( $facts['site_key_id'] ?? null )
			|| hash( 'sha256', $params['sku'] ) !== ( $facts['sku_hash'] ?? null ) ) {
			return new WP_Error( 'yoro_r2_invalid_operation', 'The R2 read authority is invalid.' );
		}

		return $this->validate_operation( $operation );
	}

	private function delivery_authority( array $delivery ) {
		$delivery_id = sanitize_text_field( $delivery['delivery_id'] ?? '' );
		$claim_token = sanitize_text_field( $delivery['claim_token'] ?? '' );
		$lease_generation = absint( $delivery['lease_generation'] ?? 0 );
		$operations = is_array( $delivery['operations'] ?? null ) ? array_values( $delivery['operations'] ) : [];

		if ( '' === $delivery_id || '' === $claim_token || $lease_generation < 1 || empty( $operations ) ) {
			return new WP_Error( 'yoro_connector_invalid_delivery', 'The claimed connector delivery is incomplete.' );
		}

		return compact( 'delivery_id', 'claim_token', 'lease_generation', 'operations' );
	}

	private function acknowledgement_event( array $delivery ) {
		$delivery_id = sanitize_text_field( $delivery['delivery_id'] ?? '' );
		$lease_generation = absint( $delivery['lease_generation'] ?? 0 );
		$identity = hash( 'sha256', $delivery_id . '|' . $lease_generation . '|claimed' );
		$runtime = YoRo_Connector_Snapshot_Publisher::runtime();
		$connection_id = is_array( $runtime ) ? sanitize_text_field( $runtime['connector_connection_id'] ?? '' ) : '';
		$connector_key = is_array( $runtime ) ? sanitize_key( $runtime['connector_key'] ?? '' ) : '';

		return [
			'contract_type'          => 'yoro.commerce.connector_event',
			'schema_version'         => 1,
			'event_id'               => 'event_' . $identity,
			'connector_key'          => in_array( $connector_key, [ 'wordpress', 'woocommerce' ], true ) ? $connector_key : 'wordpress',
			'connector_connection_id' => $connection_id,
			'event_type'             => 'operation.claimed',
			'idempotency_key'        => 'event_' . $identity,
			'occurred_at'            => gmdate( 'c' ),
			'correlation_id'         => $delivery_id,
			'payload'                => [
				'delivery_id'      => $delivery_id,
				'lease_generation' => $lease_generation,
			],
			'trace_id'              => 'trace_' . $identity,
		];
	}

	private function result_for_operation( array $operation ) {
		if ( ! self::runner_lock_valid() ) {
			return self::lock_lost_error();
		}

		$journal = YoRo_Connector_Execution_Journal::begin( $operation );

		if ( 'completed' === ( $journal['state'] ?? '' ) && is_array( $journal['result'] ?? null ) ) {
			return $journal['result'];
		}

		if ( 'executing' === ( $journal['state'] ?? '' ) ) {
			$result = $this->failed_result(
				$operation,
				'local_execution_outcome_unknown',
				'The previous local execution stopped before its outcome was recorded.'
			);
			YoRo_Connector_Execution_Journal::complete( $operation, $result );

			return $result;
		}

		$validation = $this->validate_operation( $operation );

		if ( is_wp_error( $validation ) ) {
			$result = $this->failed_result( $operation, $validation->get_error_code(), $validation->get_error_message() );
			YoRo_Connector_Execution_Journal::complete( $operation, $result );

			return $result;
		}

		$original_user_id = get_current_user_id();
		if ( ! self::runner_lock_valid() ) {
			return self::lock_lost_error();
		}

		wp_set_current_user( $validation['user_id'] );

		try {
			$execution = YoRo_Action_Executor::execute(
				$operation['action'],
				$operation['params'],
				true,
				false,
				[
					'connector_remote_agent' => true,
					'workflow_id' => sanitize_text_field( $operation['workflow_id'] ?? '' ),
					'operation_id' => sanitize_text_field( $operation['operation_id'] ?? '' ),
				]
			);
		} finally {
			wp_set_current_user( $original_user_id );
		}

		$result = is_wp_error( $execution ) || ! is_array( $execution ) || empty( $execution['success'] )
			? $this->failed_result(
				$operation,
				is_wp_error( $execution ) ? $execution->get_error_code() : 'local_execution_failed',
				is_wp_error( $execution ) ? $execution->get_error_message() : sanitize_text_field( $execution['message'] ?? 'The local action failed.' )
			)
			: $this->executed_result( $operation, $execution );
		YoRo_Connector_Execution_Journal::complete( $operation, $result );

		return $result;
	}

	private function validate_operation( array $operation ) {
		$contract_validation = YoRo_Commerce_Contract_Validator::validate_operation_envelope( $operation );

		if ( is_wp_error( $contract_validation ) ) {
			return $contract_validation;
		}

		$runtime = YoRo_Connector_Snapshot_Publisher::runtime();
		$manifest = YoRo_Connector_Snapshot_Publisher::current_manifest_state();
		$action_name = sanitize_text_field( $operation['action'] ?? '' );
		$action = YoRo_Action_Registry::get( $action_name );
		$actor = is_array( $operation['actor'] ?? null ) ? $operation['actor'] : [];
		$wordpress_identity = is_array( $actor['extensions']['wordpress.identity'] ?? null ) ? $actor['extensions']['wordpress.identity'] : [];
		$wordpress_subject = sanitize_text_field( $wordpress_identity['wordpress_subject'] ?? '' );
		$user_id = YoRo_WordPress_Actor::user_id_for_subject( $wordpress_subject );
		$authorization = is_array( $operation['authorization'] ?? null ) ? $operation['authorization'] : [];
		$expires_at = sanitize_text_field( $operation['expires_at'] ?? '' );
		$permit_expires_at = sanitize_text_field( $authorization['permit_expires_at'] ?? '' );
		$expires = '' !== $expires_at ? strtotime( $expires_at ) : false;
		$permit_expires = '' !== $permit_expires_at ? strtotime( $permit_expires_at ) : false;
		$requires_permit = ! empty( $action['write_action'] )
			|| ! empty( $action['requires_confirmation'] )
			|| ! in_array( sanitize_key( $action['effect'] ?? '' ), [ 'read', 'draft' ], true );

		if ( ! is_array( $runtime )
			|| sanitize_key( $operation['connector_key'] ?? '' ) !== sanitize_key( $runtime['connector_key'] ?? '' )
			|| sanitize_text_field( $operation['connector_connection_id'] ?? '' ) !== sanitize_text_field( $runtime['connector_connection_id'] ?? '' )
			|| sanitize_text_field( $operation['manifest_id'] ?? '' ) !== sanitize_text_field( $manifest['manifest_id'] ?? '' )
			|| absint( $operation['manifest_revision'] ?? 0 ) !== absint( $manifest['revision'] ?? 0 )
			|| ! is_array( $action )
			|| empty( $action['ai_exposed'] )
			|| 1 !== absint( $wordpress_identity['subject_version'] ?? 0 )
			|| sanitize_text_field( $actor['connector_connection_id'] ?? '' ) !== sanitize_text_field( $runtime['connector_connection_id'] ?? '' )
			|| false === $expires
			|| $expires <= time()
			|| ( $requires_permit && '' === sanitize_text_field( $authorization['execution_permit'] ?? '' ) )
			|| ( $requires_permit && ( false === $permit_expires || $permit_expires <= time() ) )
			|| $user_id < 1 ) {
			return new WP_Error( 'connector_operation_authority_invalid', 'The connector operation authority is invalid or stale.' );
		}

		$permission = sanitize_key( $action['permission'] ?? '' );
		$actor_permissions = array_values( array_filter( array_map( 'sanitize_key', (array) ( $operation['actor']['permissions'] ?? [] ) ) ) );
		$allowed_params = array_keys( (array) ( $action['input_schema'] ?? [] ) );
		$required_params = array_values( array_filter( array_map( 'sanitize_key', (array) ( $action['required_params'] ?? [] ) ) ) );
		$params = is_array( $operation['params'] ?? null ) ? $operation['params'] : [];

		$original_user_id = get_current_user_id();
		wp_set_current_user( $user_id );
		$has_permission = '' !== $permission
			&& in_array( $permission, $actor_permissions, true )
			&& current_user_can( $permission );
		wp_set_current_user( $original_user_id );

		if ( ! $has_permission
			|| array_diff( array_keys( $params ), $allowed_params )
			|| array_diff( $required_params, array_keys( $params ) ) ) {
			return new WP_Error( 'connector_operation_permission_denied', 'The WordPress actor cannot execute this connector operation.' );
		}

		return [
			'user_id' => $user_id,
			'action'  => $action,
		];
	}

	private function executed_result( array $operation, array $execution ) {
		$summary = sanitize_text_field( $execution['summary'] ?? ( $execution['message'] ?? 'The local operation completed.' ) );

		return array_merge(
			$this->result_identity( $operation ),
			[
				'status'      => 'executed',
				'summary'     => '' !== $summary ? $summary : 'The local operation completed.',
				'result'      => YoRo_Commerce_Contract_Builder::canonical_execution_payload( $execution, $operation['action'] ?? '' ),
				'occurred_at' => gmdate( 'c' ),
			]
		);
	}

	private function failed_result( array $operation, $code, $message ) {
		return array_merge(
			$this->result_identity( $operation ),
			[
				'status'      => 'failed',
				'summary'     => 'The local connector operation failed.',
				'error'       => [
					'code'      => sanitize_key( $code ) ?: 'local_execution_failed',
					'message'   => sanitize_text_field( $message ) ?: 'The local connector operation failed.',
					'retryable' => false,
					'details'   => [],
				],
				'occurred_at' => gmdate( 'c' ),
			]
		);
	}

	private function result_identity( array $operation ) {
		$identity = hash( 'sha256', ( $operation['envelope_id'] ?? '' ) . '|' . ( $operation['idempotency_key'] ?? '' ) );

		return [
			'contract_type'          => 'yoro.commerce.execution_result',
			'schema_version'         => 1,
			'result_id'              => 'result_' . $identity,
			'envelope_id'            => sanitize_text_field( $operation['envelope_id'] ?? '' ),
			'workflow_id'            => sanitize_text_field( $operation['workflow_id'] ?? '' ),
			'operation_id'           => sanitize_text_field( $operation['operation_id'] ?? '' ),
			'connector_connection_id' => sanitize_text_field( $operation['connector_connection_id'] ?? '' ),
			'idempotency_key'        => sanitize_text_field( $operation['idempotency_key'] ?? '' ),
			'trace_id'               => sanitize_text_field( $operation['trace_id'] ?? '' ),
		];
	}
}
