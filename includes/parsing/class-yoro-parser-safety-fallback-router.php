<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Parser_Safety_Fallback_Router {

	const RESOLUTION_PATH_RECOVERY_FALLBACK = 'recovery_fallback';
	const RESOLUTION_PATH_SAFETY_GUARD      = 'safety_guard';

	public static function maybe_build_ambiguous_response( $command, $normalized ) {
		if ( ! self::is_too_ambiguous( $normalized ) ) {
			return null;
		}

		return self::with_resolution_path(
			self::clarification_response(
				$command,
				$normalized,
				__( 'I need more detail before running this. What would you like me to show or manage?', 'yoro-ai-core' )
			),
			self::RESOLUTION_PATH_SAFETY_GUARD,
			'ambiguous_command'
		);
	}

	public static function build_generic_clarification( $command, $normalized ) {
		return self::with_resolution_path(
			self::clarification_response(
				$command,
				$normalized,
				__( 'I need a little more detail. What would you like me to do?', 'yoro-ai-core' )
			),
			self::RESOLUTION_PATH_SAFETY_GUARD,
			'generic_clarification'
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

	private static function is_too_ambiguous( $normalized ) {
		$normalized = trim( (string) $normalized );

		if ( '' === $normalized ) {
			return true;
		}

		$too_short = [
			'latest',
			'recent',
			'orders',
			'order',
			'show',
			'list',
			'find',
			'search',
		];

		return in_array( $normalized, $too_short, true );
	}

	private static function clarification_response( $command, $normalized, $message, $clarify_reason = 'details' ) {
		$options = class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::get_clarification_options( $normalized )
			: [];

		return [
			'success'            => false,
			'matched'            => false,
			'stage'              => 'clarify',
			'message'            => $message,
			'options'            => $options,
			'parser_source'      => 'guard',
			'parser_rule'        => 'guard.safety_clarification',
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'clarify_reason'     => sanitize_key( $clarify_reason ),
		];
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
