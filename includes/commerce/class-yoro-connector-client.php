<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Connector_Client {

	public function publish_capability_manifest( array $payload ) {
		return $this->request( 'POST', '/connectors/capability-manifests', $payload );
	}

	public function publish_context_pack( array $payload ) {
		return $this->request( 'POST', '/connectors/context-packs', $payload );
	}

	public function claim_delivery() {
		return $this->request( 'POST', '/connectors/operation-deliveries/claim', [], [], true );
	}

	public function renew_lease( $delivery_id, $claim_token, $lease_generation ) {
		return $this->request(
			'POST',
			'/connectors/operation-deliveries/' . rawurlencode( sanitize_text_field( $delivery_id ) ) . '/lease',
			[],
			$this->lease_headers( $claim_token, $lease_generation )
		);
	}

	public function publish_event( array $payload, $claim_token = '', $lease_generation = 0 ) {
		$headers = '' !== $claim_token
			? $this->lease_headers( $claim_token, $lease_generation )
			: [];

		return $this->request( 'POST', '/connectors/events', $payload, $headers );
	}

	public function publish_result( array $payload, $claim_token, $lease_generation ) {
		return $this->request(
			'POST',
			'/connectors/execution-results',
			$payload,
			$this->lease_headers( $claim_token, $lease_generation )
		);
	}

	private function request( $method, $path, array $payload, array $headers = [], $allow_empty = false ) {
		$api_url = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();

		if ( '' === $api_url || '' === $site_key ) {
			return new WP_Error( 'yoro_connector_missing_config', 'The YoRo connector credential is unavailable.' );
		}

		$started_at = microtime( true );
		$response = wp_remote_request(
			$this->api_base_url( $api_url ) . '/' . ltrim( $path, '/' ),
			[
				'method'  => strtoupper( sanitize_key( $method ) ),
				'timeout' => 20,
				'redirection' => 0,
				'headers' => array_merge(
					[
						'Authorization' => 'Bearer ' . $site_key,
						'Accept'        => 'application/json',
						'Content-Type'  => 'application/json',
					],
					$headers
				),
				'body'    => wp_json_encode( $payload ),
			]
		);
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$this->record_request( $path, $duration_ms, 'error' );

			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$this->record_request( $path, $duration_ms, $status >= 200 && $status < 300 ? 'success' : 'error' );

		if ( 204 === $status && $allow_empty ) {
			return [
				'success'     => true,
				'empty'       => true,
				'retry_after' => absint( wp_remote_retrieve_header( $response, 'retry-after' ) ),
			];
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $json ) ? sanitize_text_field( $json['message'] ?? '' ) : '';

			return new WP_Error(
				'yoro_connector_http_error',
				'' !== $message ? $message : 'The YoRo connector request failed.',
				[ 'status' => $status ]
			);
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'yoro_connector_invalid_response', 'The YoRo connector returned an invalid response.' );
		}

		return $json;
	}

	private function api_base_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		return preg_replace(
			'#/(?:sites/(?:handshake|disconnect|credentials/rotate)|conversations(?:/message|/[^/]+)?|actions/(?:execute|confirm|claim)|connectors(?:/.*)?)$#',
			'',
			$api_url
		);
	}

	private function lease_headers( $claim_token, $lease_generation ) {
		return [
			'X-YoRo-Lease-Token'      => sanitize_text_field( $claim_token ),
			'X-YoRo-Lease-Generation' => (string) max( 1, absint( $lease_generation ) ),
		];
	}

	private function record_request( $path, $duration_ms, $status ) {
		if ( ! class_exists( 'YoRo_AI_Request_Tracker' ) ) {
			return;
		}

		YoRo_AI_Request_Tracker::record(
			'yoro_connector_' . sanitize_key( str_replace( '/', '_', trim( $path, '/' ) ) ),
			absint( $duration_ms ),
			sanitize_key( $status )
		);
	}
}
