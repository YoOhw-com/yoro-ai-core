<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Connector_Client {

	const R2_ORIGIN = 'https://r2-api.yorohq.com';

    private $early_access_requests = 0;
    private $early_access_remaining = 24;
    private $early_access_metrics = [];
    private $early_access_deadline = null;

    /** Bound the complete wake transport, including slow or unavailable API hops. */
    public function set_early_access_deadline( $seconds = null ) {
        $this->early_access_deadline = null === $seconds ? null : microtime( true ) + max( 0, min( 35, (float) $seconds ) );
    }

    private function early_access_timeout() {
        return null === $this->early_access_deadline ? 20 : max( 0, min( 20, $this->early_access_deadline - microtime( true ) ) );
    }

    public function register_early_access_wake( array $payload ) {
        if ( ! YoRo_Connector_Runtime::early_access_read_allowed() || 'https' !== wp_parse_url( YoRo_AI_Settings::get_ai_api_url(), PHP_URL_SCHEME ) ) {
            return new WP_Error( 'yoro_wake_unavailable', 'Connector wake is unavailable.' );
        }
        $response = wp_remote_request( $this->api_base_url( YoRo_AI_Settings::get_ai_api_url() ) . '/early-access/wake/register', [
            'method' => 'POST', 'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 2048,
            'headers' => [ 'Authorization' => 'Bearer ' . YoRo_AI_Settings::get_ai_api_site_key(), 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
            'body' => wp_json_encode( $payload ),
        ] );
        if ( is_wp_error( $response ) ) { return $response; }
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        return 200 === (int) wp_remote_retrieve_response_code( $response ) && true === ( $json['success'] ?? null )
            ? [ 'success' => true ] : new WP_Error( 'yoro_wake_registration_failed', 'Connector wake registration is unavailable.' );
    }

    /** Reserve a full claim/ack/lease/result pipeline under the shared 30/minute limit. */
    public function early_access_has_budget( $requests = 4 ) {
        return min( 24 - $this->early_access_requests, $this->early_access_remaining ) >= $requests;
    }

    public function early_access_transport_metrics() {
        return [ 'transport' => $this->early_access_metrics ];
    }

    protected function record_early_access_budget( $response, $step = 'unknown', $elapsed_ms = 0 ) {
        $metric = $this->early_access_metrics[ $step ] ?? [ 'requests' => 0, 'elapsed_ms' => 0, 'empty' => 0, 'errors' => 0 ];
        $metric['requests']++;
        $metric['elapsed_ms'] += max( 0, (int) $elapsed_ms );
        if ( is_wp_error( $response ) ) { $metric['errors']++; }
        else {
            $status = (int) wp_remote_retrieve_response_code( $response );
            $metric['empty'] += 204 === $status ? 1 : 0;
            $metric['errors'] += $status < 200 || $status >= 300 ? 1 : 0;
        }
        $this->early_access_metrics[ $step ] = $metric;
        $this->early_access_requests++;
        if ( ! is_wp_error( $response ) ) {
            $remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
            if ( is_numeric( $remaining ) && (float) $remaining >= 0 ) {
                $this->early_access_remaining = min( $this->early_access_remaining, (int) $remaining );
            }
        }
    }

	public function r2_request( $step, $envelope_id = '', array $payload = [], $claim_token = '', $generation = 0 ) {
		if ( ! YoRo_Connector_Runtime::r2_read_allowed() || ! in_array( $step, [ 'claim', 'ack', 'lease', 'result' ], true )
			|| ( 'claim' !== $step && ! preg_match( '/\Aenvelope_[a-f0-9]{64}\z/D', $envelope_id ) ) ) {
			return new WP_Error( 'yoro_r2_unavailable', 'R2 read transport is unavailable.' );
		}
		$path = '/api/v1/r2/product-reads/' . ( 'claim' === $step ? 'claim' : rawurlencode( $envelope_id ) . '/' . $step );
		$body = wp_json_encode( empty( $payload ) ? (object) [] : $payload );
		if ( ! is_string( $body ) || strlen( $body ) > 65536 ) {
			return new WP_Error( 'yoro_r2_invalid_result', 'The R2 payload is outside its safe boundary.' );
		}
		$response = wp_remote_request( self::R2_ORIGIN . $path, [
			'method' => 'POST', 'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
			'limit_response_size' => 65536,
			'headers' => array_merge( [ 'Authorization' => 'Bearer ' . YORO_R2_PRODUCT_READ_KEY,
				'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				'claim' === $step ? [] : $this->lease_headers( $claim_token, $generation ) ),
			'body' => $body,
		] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 204 === $status && 'claim' === $step ) {
			return [ 'success' => true, 'empty' => true ];
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $json ) || true !== ( $json['success'] ?? null ) ) {
			return new WP_Error( 'yoro_r2_http_error', 'R2 read transport was denied.', [ 'status' => $status ] );
		}

		return $json;
	}

	public function early_access_request( $step, $envelope_id = '', array $payload = [], $claim_token = '', $generation = 0 ) {
		if ( ! YoRo_Connector_Runtime::early_access_read_allowed() || ! in_array( $step, [ 'claim', 'next', 'ack', 'lease', 'result' ], true )
			|| ( ! in_array( $step, [ 'claim', 'next' ], true ) && ! preg_match( '/\Aenvelope_[a-f0-9]{64}\z/D', $envelope_id ) ) ) {
			return new WP_Error( 'yoro_early_access_unavailable', 'Early Access read transport is unavailable.' );
		}
		if ( in_array( $step, [ 'claim', 'next' ], true ) && ! $this->early_access_has_budget() ) {
			return [ 'success' => true, 'empty' => true, 'throttled' => true ];
		}
		$path = 'next' === $step ? '/early-access/operations/claim' : '/early-access/product-reads/' . ( 'claim' === $step ? 'claim' : rawurlencode( $envelope_id ) . '/' . $step );
		$body = wp_json_encode( empty( $payload ) ? (object) [] : $payload );
		if ( ! is_string( $body ) || strlen( $body ) > 65536 ) {
			return new WP_Error( 'yoro_early_access_invalid_result', 'The Early Access payload is outside its safe boundary.' );
		}
        $timeout = $this->early_access_timeout();
        if ( $timeout < 1 ) { return new WP_Error( 'yoro_wake_deadline', 'The bounded connector request has ended.' ); }
        $transport_started = hrtime( true );
		$response = wp_remote_request( $this->api_base_url( YoRo_AI_Settings::get_ai_api_url() ) . $path, [
			'method' => 'POST', 'timeout' => $timeout, 'redirection' => 0, 'sslverify' => true,
			'limit_response_size' => 65536,
			'headers' => array_merge( [ 'Authorization' => 'Bearer ' . YoRo_AI_Settings::get_ai_api_site_key(),
				'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				in_array( $step, [ 'claim', 'next' ], true ) ? [] : $this->lease_headers( $claim_token, $generation ) ),
			'body' => $body,
		] );
		$this->record_early_access_budget( $response, 'read.' . $step, ( hrtime( true ) - $transport_started ) / 1e6 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 204 === $status && in_array( $step, [ 'claim', 'next' ], true ) ) {
			return [ 'success' => true, 'empty' => true ];
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $json ) || true !== ( $json['success'] ?? null ) ) {
			return new WP_Error( 'yoro_early_access_http_error', 'Early Access read transport was denied.', [ 'status' => $status ] );
		}

        if ( 'next' === $step ) {
            $lane = wp_remote_retrieve_header( $response, 'x-yoro-delivery-lane' );
            if ( ! in_array( $lane, [ 'read', 'write' ], true ) ) {
                return new WP_Error( 'yoro_early_access_invalid_lane', 'An exact delivery lane is required.' );
            }
            $json['transport_lane'] = $lane;
        }
        if ( 'conditional-v1' === wp_remote_retrieve_header( $response, 'x-yoro-read-lease-policy' ) ) {
            $json['read_lease_policy'] = 'conditional-v1';
        }
		return $json;
	}

	public function early_access_write_request( $step, $envelope_id = '', array $payload = [], $claim_token = '', $generation = 0 ) {
		if ( ! YoRo_Connector_Runtime::early_access_write_allowed() || ! in_array( $step, [ 'claim', 'ack', 'lease', 'result' ], true )
			|| ( 'claim' !== $step && ! preg_match( '/\Aenvelope_[a-f0-9]{64}\z/D', $envelope_id ) ) ) {
			return new WP_Error( 'yoro_early_access_unavailable', 'Confirmed commerce write transport is unavailable.' );
		}
		if ( 'claim' === $step && ! $this->early_access_has_budget() ) {
			return [ 'success' => true, 'empty' => true, 'throttled' => true ];
		}
		$path = '/early-access/commerce-writes/' . ( 'claim' === $step ? 'claim' : rawurlencode( $envelope_id ) . '/' . $step );
		$body = wp_json_encode( empty( $payload ) ? (object) [] : $payload );
		if ( ! is_string( $body ) || strlen( $body ) > 65536 ) {
			return new WP_Error( 'yoro_early_access_invalid_result', 'The Early Access payload is outside its safe boundary.' );
		}
        $timeout = $this->early_access_timeout();
        if ( $timeout < 1 ) { return new WP_Error( 'yoro_wake_deadline', 'The bounded connector request has ended.' ); }
        $transport_started = hrtime( true );
		$response = wp_remote_request( $this->api_base_url( YoRo_AI_Settings::get_ai_api_url() ) . $path, [
			'method' => 'POST', 'timeout' => $timeout, 'redirection' => 0, 'sslverify' => true,
			'limit_response_size' => 65536,
			'headers' => array_merge( [ 'Authorization' => 'Bearer ' . YoRo_AI_Settings::get_ai_api_site_key(),
				'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				'claim' === $step ? [] : $this->lease_headers( $claim_token, $generation ) ),
			'body' => $body,
		] );
		$this->record_early_access_budget( $response, 'write.' . $step, ( hrtime( true ) - $transport_started ) / 1e6 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 204 === $status && 'claim' === $step ) {
			return [ 'success' => true, 'empty' => true ];
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $json ) || true !== ( $json['success'] ?? null ) ) {
			return new WP_Error( 'yoro_early_access_http_error', 'Confirmed commerce write transport was denied.', [ 'status' => $status ] );
		}

		return $json;
	}

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
