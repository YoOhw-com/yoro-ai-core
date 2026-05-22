<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Post_Command_Router_Result_Router {

	const RESOLUTION_PATH_AI_PRIMARY = 'ai_primary';

	public static function route( $command, $normalized, $client_context = [], $ai_router_result = null, $callbacks = [] ) {
		if ( is_array( $ai_router_result ) ) {
			if ( self::should_recover_empty_clarification( $ai_router_result ) ) {
				$shape_explicit_write_fallback = class_exists( 'YoRo_AI_Recovery_Fallback_Result_Router' )
					? YoRo_AI_Recovery_Fallback_Result_Router::maybe_build_command_shape_explicit_order_write_fallback(
						$command,
						$normalized,
						$client_context,
						$callbacks
					)
					: null;

				if ( is_array( $shape_explicit_write_fallback ) ) {
					return $shape_explicit_write_fallback;
				}

				$shape_write_missing_target_fallback = class_exists( 'YoRo_AI_Recovery_Fallback_Result_Router' )
					? YoRo_AI_Recovery_Fallback_Result_Router::maybe_build_command_shape_write_missing_target_fallback(
						$command,
						$normalized,
						$client_context,
						$callbacks
					)
					: null;

				if ( is_array( $shape_write_missing_target_fallback ) ) {
					return $shape_write_missing_target_fallback;
				}
			}

			return self::with_resolution_path(
				$ai_router_result,
				self::RESOLUTION_PATH_AI_PRIMARY
			);
		}

		$shape_order_search_fallback = class_exists( 'YoRo_AI_Recovery_Fallback_Result_Router' )
			? YoRo_AI_Recovery_Fallback_Result_Router::maybe_build_command_shape_order_search_fallback(
				$command,
				$normalized,
				$client_context,
				$callbacks
			)
			: null;

		if ( is_array( $shape_order_search_fallback ) ) {
			return $shape_order_search_fallback;
		}

		$shape_write_missing_target_fallback = class_exists( 'YoRo_AI_Recovery_Fallback_Result_Router' )
			? YoRo_AI_Recovery_Fallback_Result_Router::maybe_build_command_shape_write_missing_target_fallback(
				$command,
				$normalized,
				$client_context,
				$callbacks
			)
			: null;

		if ( is_array( $shape_write_missing_target_fallback ) ) {
			return $shape_write_missing_target_fallback;
		}

		$shape_explicit_write_fallback = class_exists( 'YoRo_AI_Recovery_Fallback_Result_Router' )
			? YoRo_AI_Recovery_Fallback_Result_Router::maybe_build_command_shape_explicit_order_write_fallback(
				$command,
				$normalized,
				$client_context,
				$callbacks
			)
			: null;

		if ( is_array( $shape_explicit_write_fallback ) ) {
			return $shape_explicit_write_fallback;
		}

		$shape_query_action_fallback = class_exists( 'YoRo_AI_Recovery_Fallback_Result_Router' )
			? YoRo_AI_Recovery_Fallback_Result_Router::maybe_build_command_shape_query_action_fallback(
				$command,
				$normalized,
				$client_context,
				$callbacks
			)
			: null;

		return is_array( $shape_query_action_fallback ) ? $shape_query_action_fallback : null;
	}

	private static function should_recover_empty_clarification( $ai_router_result ) {
		$ai_router_result = is_array( $ai_router_result ) ? $ai_router_result : [];
		$stage            = sanitize_key( $ai_router_result['stage'] ?? '' );
		$mode             = sanitize_key( $ai_router_result['mode'] ?? ( $ai_router_result['command_decision']['mode'] ?? '' ) );
		$action           = sanitize_text_field( $ai_router_result['action'] ?? ( $ai_router_result['command_decision']['action'] ?? '' ) );

		return '' === $action && ( 'clarify' === $stage || 'clarify' === $mode );
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
