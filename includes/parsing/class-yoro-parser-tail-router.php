<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Parser_Tail_Router {

	const RESOLUTION_PATH_SAFETY_GUARD = 'safety_guard';

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$semantic_read_only_fallback = class_exists( 'YoRo_AI_Recovery_Fallback_Result_Router' )
			? YoRo_AI_Recovery_Fallback_Result_Router::maybe_build_semantic_read_only_fallback(
				$command,
				$normalized,
				$client_context,
				self::normalizer_callbacks( $callbacks )
			)
			: null;

		if ( is_array( $semantic_read_only_fallback ) ) {
			return $semantic_read_only_fallback;
		}

		$hardening_result = class_exists( 'YoRo_AI_Hardening_Guard_Result_Router' )
			? YoRo_AI_Hardening_Guard_Result_Router::parse(
				$command,
				$normalized,
				$client_context,
				self::hardening_callbacks( $callbacks )
			)
			: null;

		if ( is_array( $hardening_result ) ) {
			return $hardening_result;
		}

		$ambiguous_guard = class_exists( 'YoRo_Parser_Safety_Fallback_Router' )
			? YoRo_Parser_Safety_Fallback_Router::maybe_build_ambiguous_response( $command, $normalized )
			: null;

		if ( is_array( $ambiguous_guard ) ) {
			return $ambiguous_guard;
		}

		return class_exists( 'YoRo_Parser_Safety_Fallback_Router' )
			? YoRo_Parser_Safety_Fallback_Router::build_generic_clarification( $command, $normalized )
			: self::generic_clarification( $command, $normalized );
	}

	private static function normalizer_callbacks( $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		return [
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) use ( $callbacks ) {
				return self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );
			},
		];
	}

	private static function hardening_callbacks( $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		return [
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) use ( $callbacks ) {
				return self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );
			},
			'hydrate_result_intent_family_from_action' => static function( $result ) use ( $callbacks ) {
				return self::hydrate_result_intent_family_from_action( $result, $callbacks );
			},
			'parse_command' => static function( $command, $client_context = [] ) use ( $callbacks ) {
				if ( ! empty( $callbacks['parse_command'] ) && is_callable( $callbacks['parse_command'] ) ) {
					return call_user_func( $callbacks['parse_command'], $command, $client_context );
				}

				return null;
			},
		];
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

	private static function hydrate_result_intent_family_from_action( $result, $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		if ( ! empty( $callbacks['hydrate_result_intent_family_from_action'] ) && is_callable( $callbacks['hydrate_result_intent_family_from_action'] ) ) {
			$hydrated = call_user_func( $callbacks['hydrate_result_intent_family_from_action'], $result );

			return is_array( $hydrated ) ? $hydrated : $result;
		}

		return class_exists( 'YoRo_Parser_Result_Normalizer' )
			? YoRo_Parser_Result_Normalizer::hydrate_intent_family_from_action( $result )
			: $result;
	}

	private static function generic_clarification( $command, $normalized ) {
		return self::with_resolution_path(
			[
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'message'            => __( 'I need a little more detail. What would you like me to do?', 'yoro-ai-core' ),
				'options'            => [],
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'clarify_reason'     => 'details',
			],
			self::RESOLUTION_PATH_SAFETY_GUARD,
			'generic_clarification'
		);
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
