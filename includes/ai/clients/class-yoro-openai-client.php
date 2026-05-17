<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_OpenAI_Client implements YoRo_AI_Client_Interface {

	public function parse_action( array $payload ) {
		return $this->request(
			$payload,
			60,
			'parse_action',
			'yoro_ai_api_error',
			__( 'AI parser request failed.', 'yoro-agent-core' )
		);
	}

	public function parse_intent( array $payload ) {
		return $this->request(
			$payload,
			60,
			'parse_intent',
			'yoro_ai_intent_api_error',
			__( 'AI intent parser request failed.', 'yoro-agent-core' )
		);
	}

	public function plan_workflow( array $payload ) {
		return $this->request(
			$payload,
			60,
			'plan_workflow',
			'yoro_ai_workflow_api_error',
			__( 'AI workflow planner request failed.', 'yoro-agent-core' )
		);
	}

	public function generate_suggestions( array $payload ) {
		return $this->request(
			$payload,
			30,
			'generate_suggestions',
			'yoro_ai_freeform_api_error',
			__( 'AI suggestion request failed.', 'yoro-agent-core' )
		);
	}

	public function compose_email( array $payload ) {
		return $this->request(
			$payload,
			60,
			'compose_email',
			'yoro_ai_email_api_error',
			__( 'AI email compose request failed.', 'yoro-agent-core' )
		);
	}

	private function request( array $payload, $timeout, $operation, $error_code, $default_message ) {
		$api_key = YoRo_AI_Settings::get_openai_api_key();

		if ( '' === $api_key ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'OpenAI API key is missing.', 'yoro-agent-core' )
			);
		}

		$started_at = microtime( true );
		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			[
				'timeout' => absint( $timeout ),
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
			]
		);
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'YoRo_AI_Request_Tracker' ) ) {
				YoRo_AI_Request_Tracker::record( $operation, $duration_ms, 'error' );
			}

			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			if ( class_exists( 'YoRo_AI_Request_Tracker' ) ) {
				YoRo_AI_Request_Tracker::record( $operation, $duration_ms, 'error' );
			}

			return new WP_Error(
				$error_code,
				$json['error']['message'] ?? $default_message
			);
		}

		if ( class_exists( 'YoRo_AI_Request_Tracker' ) ) {
			YoRo_AI_Request_Tracker::record( $operation, $duration_ms, 'success' );
		}

		return self::extract_output_text( $json );
	}

	public static function extract_output_text( $json ) {
		if ( ! empty( $json['output_text'] ) ) {
			return $json['output_text'];
		}

		if ( empty( $json['output'] ) || ! is_array( $json['output'] ) ) {
			return '';
		}

		foreach ( $json['output'] as $item ) {
			if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}

			foreach ( $item['content'] as $content ) {
				if ( isset( $content['text'] ) ) {
					return $content['text'];
				}
			}
		}

		return '';
	}
}
