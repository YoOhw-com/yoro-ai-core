<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Post_Command_Router_Result_Router {

	const RESOLUTION_PATH_AI_PRIMARY = 'ai_primary';

	public static function route( $command, $normalized, $client_context = [], $ai_router_result = null, $callbacks = [] ) {
		if ( is_array( $ai_router_result ) ) {
			return self::with_resolution_path(
				$ai_router_result,
				self::RESOLUTION_PATH_AI_PRIMARY
			);
		}

		return null;
	}

	private static function with_resolution_path( $result, $resolution_path, $fallback_reason = '' ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$sanitized_path = sanitize_key( (string) $resolution_path );
		$result['resolution_path'] = $sanitized_path;
		$sanitized_fallback_reason = sanitize_key( (string) $fallback_reason );

		if ( '' !== $sanitized_fallback_reason && empty( $result['fallback_reason'] ) ) {
			$result['fallback_reason'] = $sanitized_fallback_reason;
		}

		if ( ! empty( $result['result'] ) && is_array( $result['result'] ) && empty( $result['result']['resolution_path'] ) ) {
			$result['result']['resolution_path'] = $sanitized_path;
		}

		if (
			'' !== $sanitized_fallback_reason &&
			! empty( $result['result'] ) &&
			is_array( $result['result'] ) &&
			empty( $result['result']['fallback_reason'] )
		) {
			$result['result']['fallback_reason'] = $sanitized_fallback_reason;
		}

		return $result;
	}
}
