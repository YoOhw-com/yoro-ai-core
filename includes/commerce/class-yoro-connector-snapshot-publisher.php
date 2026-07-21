<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Connector_Snapshot_Publisher {

	const OPTION_MANIFEST_STATE = 'yoro_connector_manifest_state';
	const OPTION_CONTEXT_STATE = 'yoro_connector_context_state';
	const OPTION_SYNC_STATE = 'yoro_connector_snapshot_sync_state';
	const PUBLISH_INTERVAL = 10 * MINUTE_IN_SECONDS;
	const MANIFEST_REFRESH_MARGIN = DAY_IN_SECONDS;

	private $client;

	public function __construct( $client = null ) {
		$this->client = $client instanceof YoRo_Connector_Client ? $client : new YoRo_Connector_Client();
	}

	public function publish_if_due() {
		$state = get_option( self::OPTION_SYNC_STATE, [] );
		$published_at = is_array( $state ) ? absint( $state['published_at'] ?? 0 ) : 0;

		if ( $published_at > time() - self::PUBLISH_INTERVAL ) {
			return [
				'success'      => true,
				'skipped'      => true,
				'published_at' => $published_at,
			];
		}

		return $this->publish();
	}

	public function publish() {
		$runtime = self::runtime();

		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}

		$manifest_state = self::current_manifest_state();
		$server_manifest = is_array( $runtime['capability_manifest'] ?? null ) ? $runtime['capability_manifest'] : [];
		$manifest_revision = max( 1, absint( $manifest_state['revision'] ?? 0 ) );
		$manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( $runtime, $manifest_revision );

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$manifest_fingerprint = self::manifest_fingerprint( $manifest );
		$manifest_expires = strtotime( sanitize_text_field( $manifest_state['expires_at'] ?? '' ) );
		$publish_manifest = sanitize_text_field( $manifest_state['manifest_id'] ?? '' ) !== $manifest['manifest_id']
			|| ! hash_equals( sanitize_text_field( $manifest_state['fingerprint'] ?? '' ), $manifest_fingerprint )
			|| false === $manifest_expires
			|| $manifest_expires <= time() + self::MANIFEST_REFRESH_MARGIN
			|| empty( $server_manifest['current'] )
			|| sanitize_text_field( $server_manifest['manifest_id'] ?? '' ) !== $manifest['manifest_id']
			|| absint( $server_manifest['revision'] ?? 0 ) !== absint( $manifest_state['revision'] ?? 0 );

		if ( $publish_manifest ) {
			$manifest_revision = self::next_revision( self::OPTION_MANIFEST_STATE );
			$manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( $runtime, $manifest_revision );
			$manifest_fingerprint = self::manifest_fingerprint( $manifest );
			$manifest_response = $this->client->publish_capability_manifest( $manifest );

			if ( is_wp_error( $manifest_response ) ) {
				return $manifest_response;
			}

			self::record_server_manifest_attestation( $manifest );

			update_option(
				self::OPTION_MANIFEST_STATE,
				[
					'manifest_id' => $manifest['manifest_id'],
					'revision'    => $manifest['revision'],
					'fingerprint' => $manifest_fingerprint,
					'expires_at'  => $manifest['expires_at'],
					'published_at' => time(),
				],
				false
			);
		} else {
			$manifest['revision'] = absint( $manifest_state['revision'] );
			$manifest_response = [
				'success' => true,
				'skipped' => true,
				'reason'  => 'capabilities_unchanged',
			];
		}

		$context_revision = self::next_revision( self::OPTION_CONTEXT_STATE );
		$context_pack = YoRo_Commerce_Contract_Builder::build_context_pack( $runtime, $manifest, $context_revision );

		if ( is_wp_error( $context_pack ) ) {
			return $context_pack;
		}

		$context_response = $this->client->publish_context_pack( $context_pack );

		if ( is_wp_error( $context_response ) ) {
			return $context_response;
		}

		$published_at = time();
		update_option(
			self::OPTION_CONTEXT_STATE,
			[
				'context_pack_id'  => $context_pack['context_pack_id'],
				'revision'         => $context_pack['revision'],
				'manifest_id'      => $manifest['manifest_id'],
				'manifest_revision' => $manifest['revision'],
				'published_at'     => $published_at,
			],
			false
		);
		update_option(
			self::OPTION_SYNC_STATE,
			[
				'published_at'     => $published_at,
				'manifest_revision' => $manifest['revision'],
				'context_revision' => $context_pack['revision'],
			],
			false
		);

		return [
			'success'             => true,
			'skipped'             => false,
			'manifest'            => $manifest_response,
			'context'             => $context_response,
			'manifest_revision'   => $manifest['revision'],
			'context_revision'    => $context_pack['revision'],
		];
	}

	public static function runtime() {
		$status = YoRo_AI_Settings::get_ai_api_status();
		$runtime = is_array( $status['connector_runtime'] ?? null ) ? $status['connector_runtime'] : [];

		if ( ! YoRo_AI_Settings::is_ai_api_account_connected( $status ) || empty( $runtime ) ) {
			return new WP_Error( 'yoro_connector_runtime_unavailable', 'The active YoRo connector runtime is unavailable.' );
		}

		return $runtime;
	}

	public static function current_manifest_state() {
		$state = get_option( self::OPTION_MANIFEST_STATE, [] );

		return is_array( $state ) ? $state : [];
	}

	private static function next_revision( $option_name ) {
		$state = get_option( $option_name, [] );
		$previous = is_array( $state ) ? absint( $state['revision'] ?? 0 ) : 0;

		return max( time(), $previous + 1 );
	}

	private static function record_server_manifest_attestation( array $manifest ) {
		$status = YoRo_AI_Settings::get_ai_api_status();

		if ( ! is_array( $status['connector_runtime'] ?? null ) ) {
			return;
		}

		$status['connector_runtime']['capability_manifest'] = [
			'current'     => true,
			'manifest_id' => sanitize_text_field( $manifest['manifest_id'] ?? '' ),
			'revision'    => absint( $manifest['revision'] ?? 0 ),
			'expires_at'  => sanitize_text_field( $manifest['expires_at'] ?? '' ),
		];

		YoRo_AI_Settings::update_ai_api_status( $status );
	}

	private static function manifest_fingerprint( array $manifest ) {
		$capabilities = [
			'contract_type'           => $manifest['contract_type'] ?? '',
			'schema_version'          => $manifest['schema_version'] ?? 0,
			'manifest_id'             => $manifest['manifest_id'] ?? '',
			'connector_key'           => $manifest['connector_key'] ?? '',
			'connector_connection_id' => $manifest['connector_connection_id'] ?? '',
			'actions'                 => $manifest['actions'] ?? [],
			'extensions'              => $manifest['extensions'] ?? [],
		];

		return hash( 'sha256', wp_json_encode( $capabilities ) );
	}
}
