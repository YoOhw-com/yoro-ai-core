<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Connector_Runtime {

	const CRON_HOOK = 'yoro_connector_runtime_tick';
	const CRON_SCHEDULE = 'yoro_connector_minute';
	const LOCK_KEY = 'yoro_connector_runtime_lock';

	private $client;

	public function __construct( $client = null ) {
		$this->client = $client instanceof YoRo_Connector_Client ? $client : new YoRo_Connector_Client();
	}

	public static function init() {
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
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( self::CRON_HOOK, [], 'yoro-ai-core' ) ) {
				as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, self::CRON_HOOK, [], 'yoro-ai-core' );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function tick() {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, 55 );

		try {
			$client = new YoRo_Connector_Client();
			$publisher = new YoRo_Connector_Snapshot_Publisher( $client );
			$publisher->publish_if_due();

			if ( self::live_cutover_allowed() ) {
				( new self( $client ) )->run_once();
			}
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	public function run_once() {
		if ( ! self::live_cutover_allowed() ) {
			return [
				'success' => true,
				'skipped' => true,
				'reason'  => 'live_cutover_not_allowed',
			];
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
			$renewed = $this->client->renew_lease(
				$authority['delivery_id'],
				$authority['claim_token'],
				$authority['lease_generation']
			);

			if ( is_wp_error( $renewed ) ) {
				return $renewed;
			}

			$result = $this->result_for_operation( $operation );
			$published = $this->client->publish_result(
				$result,
				$authority['claim_token'],
				$authority['lease_generation']
			);

			if ( is_wp_error( $published ) ) {
				return $published;
			}

			YoRo_Connector_Execution_Journal::clear( $operation );
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

		return [
			'contract_type'          => 'yoro.commerce.connector_event',
			'schema_version'         => 1,
			'event_id'               => 'event_' . $identity,
			'connector_key'          => 'woocommerce',
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
		wp_set_current_user( $validation['user_id'] );
		$execution_clock = $this->start_execution_clock();

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

		$execution_telemetry = $this->finish_execution_clock( $execution_clock );

		$result = is_wp_error( $execution ) || ! is_array( $execution ) || empty( $execution['success'] )
			? $this->failed_result(
				$operation,
				is_wp_error( $execution ) ? $execution->get_error_code() : 'local_execution_failed',
				is_wp_error( $execution ) ? $execution->get_error_message() : sanitize_text_field( $execution['message'] ?? 'The local action failed.' ),
				$execution_telemetry
			)
			: $this->executed_result( $operation, $execution, $execution_telemetry );
		YoRo_Connector_Execution_Journal::complete( $operation, $result );

		return $result;
	}

	private function validate_operation( array $operation ) {
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

		if ( ! is_array( $runtime )
			|| 'woocommerce' !== sanitize_key( $operation['connector_key'] ?? '' )
			|| sanitize_text_field( $operation['connector_connection_id'] ?? '' ) !== sanitize_text_field( $runtime['connector_connection_id'] ?? '' )
			|| sanitize_text_field( $operation['manifest_id'] ?? '' ) !== sanitize_text_field( $manifest['manifest_id'] ?? '' )
			|| absint( $operation['manifest_revision'] ?? 0 ) !== absint( $manifest['revision'] ?? 0 )
			|| ! is_array( $action )
			|| empty( $action['ai_exposed'] )
			|| 1 !== absint( $wordpress_identity['subject_version'] ?? 0 )
			|| sanitize_text_field( $actor['connector_connection_id'] ?? '' ) !== sanitize_text_field( $runtime['connector_connection_id'] ?? '' )
			|| '' === sanitize_text_field( $authorization['execution_permit'] ?? '' )
			|| false === $expires
			|| $expires <= time()
			|| false === $permit_expires
			|| $permit_expires <= time()
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

	private function executed_result( array $operation, array $execution, array $execution_telemetry ) {
		$summary = sanitize_text_field( $execution['summary'] ?? ( $execution['message'] ?? 'The local operation completed.' ) );

		return array_merge(
			$this->result_identity( $operation ),
			[
				'status'      => 'executed',
				'summary'     => '' !== $summary ? $summary : 'The local operation completed.',
				'result'      => $execution,
				'occurred_at' => $execution_telemetry['completed_at'],
				'extensions'  => [
					'yoro.execution' => $execution_telemetry,
				],
			]
		);
	}

	private function failed_result( array $operation, $code, $message, array $execution_telemetry = [] ) {
		$execution_fields = empty( $execution_telemetry )
			? [ 'occurred_at' => gmdate( 'c' ) ]
			: [
				'occurred_at' => $execution_telemetry['completed_at'],
				'extensions'  => [
					'yoro.execution' => $execution_telemetry,
				],
			];

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
			],
			$execution_fields
		);
	}

	private function start_execution_clock() {
		$started_at = microtime( true );

		return [
			'started_at_unix' => $started_at,
			'started_at'      => $this->format_execution_time( $started_at ),
		];
	}

	private function finish_execution_clock( array $clock ) {
		$completed_at = microtime( true );
		$started_at = isset( $clock['started_at_unix'] ) ? (float) $clock['started_at_unix'] : $completed_at;

		return [
			'version'              => 1,
			'started_at'           => sanitize_text_field( $clock['started_at'] ?? $this->format_execution_time( $started_at ) ),
			'completed_at'         => $this->format_execution_time( $completed_at ),
			'duration_ms'          => max( 0, (int) round( ( $completed_at - $started_at ) * 1000 ) ),
			'action_attempt_count' => 1,
		];
	}

	private function format_execution_time( $timestamp ) {
		$seconds = (int) floor( (float) $timestamp );
		$microseconds = (int) round( ( (float) $timestamp - $seconds ) * 1000000 );

		if ( $microseconds >= 1000000 ) {
			$seconds++;
			$microseconds = 0;
		}

		return gmdate( 'Y-m-d\\TH:i:s', $seconds ) . sprintf( '.%06dZ', $microseconds );
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
