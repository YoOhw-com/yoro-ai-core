<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Active_Workflow_Continue_Router {

	const RESOLUTION_PATH_DETERMINISTIC_OVERRIDE = 'deterministic_override';

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if ( ! class_exists( 'YoRo_Email_Command_Parser' ) ) {
			return null;
		}

		$parsed = YoRo_Email_Command_Parser::parse_priority_workflow_continue(
			$command,
			$normalized,
			$client_context
		);

		if ( ! is_array( $parsed ) ) {
			return null;
		}

		if (
			! empty( $parsed['stage'] ) &&
			'clarify' === $parsed['stage']
		) {
			return self::with_resolution_path(
				$parsed,
				self::RESOLUTION_PATH_DETERMINISTIC_OVERRIDE,
				'active_workflow'
			);
		}

		return self::with_resolution_path(
			self::normalize_resolve_validate_result( $parsed, $command, $normalized, $client_context, $callbacks ),
			self::RESOLUTION_PATH_DETERMINISTIC_OVERRIDE,
			'active_workflow'
		);
	}

	private static function normalize_resolve_validate_result( $parsed, $command, $normalized, $client_context = [], $callbacks = [] ) {
		$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );

		if (
			class_exists( 'YoRo_Entity_Resolver' ) &&
			class_exists( 'YoRo_Intent_Router' ) &&
			class_exists( 'YoRo_Entity_Validator' )
		) {
			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		return $normalized_result;
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
