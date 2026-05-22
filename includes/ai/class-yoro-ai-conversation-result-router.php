<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Conversation_Result_Router {

	const RESOLUTION_PATH_SAFETY_GUARD = 'safety_guard';

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$result = class_exists( 'YoRo_AI_Conversation_Router' )
			? YoRo_AI_Conversation_Router::parse(
				$command,
				$normalized,
				$client_context,
				self::conversation_router_callbacks( $callbacks )
			)
			: null;

		if ( ! is_array( $result ) ) {
			return null;
		}

		return self::with_resolution_path(
			$result,
			self::RESOLUTION_PATH_SAFETY_GUARD,
			'conversation_route'
		);
	}

	public static function should_try_gate( $normalized, $callbacks = [] ) {
		return class_exists( 'YoRo_AI_Conversation_Gate' )
			? YoRo_AI_Conversation_Gate::should_try(
				$normalized,
				self::conversation_gate_callbacks( $callbacks )
			)
			: false;
	}

	public static function parse_gate( $command, $normalized, $client_context = [], $callbacks = [] ) {
		return class_exists( 'YoRo_AI_Conversation_Gate' )
			? YoRo_AI_Conversation_Gate::parse(
				$command,
				$normalized,
				$client_context,
				self::conversation_gate_callbacks( $callbacks )
			)
			: null;
	}

	private static function conversation_router_callbacks( $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		return [
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) use ( $callbacks ) {
				return self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );
			},
		];
	}

	private static function conversation_gate_callbacks( $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		return [
			'route_ai_inferred_supported_action' => static function( $command, $normalized, $client_context = [], $inference = [] ) use ( $callbacks ) {
				return self::route_ai_inferred_supported_action( $command, $normalized, $client_context, $inference, $callbacks );
			},
		];
	}

	private static function grounding_callbacks( $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		return [
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) use ( $callbacks ) {
				return self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );
			},
			'parse_command' => static function( $command, $client_context = [] ) use ( $callbacks ) {
				if ( ! empty( $callbacks['parse_command'] ) && is_callable( $callbacks['parse_command'] ) ) {
					return call_user_func( $callbacks['parse_command'], $command, $client_context );
				}

				return null;
			},
		];
	}

	private static function route_ai_inferred_supported_action( $command, $normalized, $client_context = [], $inference = [], $callbacks = [] ) {
		if ( ! class_exists( 'YoRo_AI_Inferred_Supported_Action_Router' ) ) {
			return null;
		}

		return YoRo_AI_Inferred_Supported_Action_Router::route(
			$command,
			$normalized,
			$client_context,
			$inference,
			YoRo_AI_Inferred_Supported_Action_Router::callbacks( self::grounding_callbacks( $callbacks ) )
		);
	}

	private static function normalize_parsed_result( $result, $command, $normalized, $client_context = [], $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		if ( ! empty( $callbacks['normalize_parsed_result'] ) && is_callable( $callbacks['normalize_parsed_result'] ) ) {
			$normalized_result = call_user_func(
				$callbacks['normalize_parsed_result'],
				$result,
				$command,
				$normalized,
				$client_context
			);

			return is_array( $normalized_result ) ? $normalized_result : $result;
		}

		return class_exists( 'YoRo_Parser_Result_Normalizer' )
			? YoRo_Parser_Result_Normalizer::normalize( $result, $command, $normalized, $client_context )
			: $result;
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
