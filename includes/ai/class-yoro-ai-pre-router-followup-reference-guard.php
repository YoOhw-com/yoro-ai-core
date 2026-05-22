<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Pre_Router_Followup_Reference_Guard {

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if ( ! class_exists( 'YoRo_Followup_Command_Parser' ) ) {
			return null;
		}

		if ( self::looks_like_contextual_write_command( $normalized ) ) {
			return null;
		}

		$parsed = YoRo_Followup_Command_Parser::parse_read(
			$command,
			$normalized,
			is_array( $client_context ) ? $client_context : []
		);

		if ( ! is_array( $parsed ) ) {
			return null;
		}

		return self::normalize_guard_result(
			$parsed,
			$command,
			$normalized,
			$client_context,
			$callbacks
		);
	}

	private static function normalize_guard_result( $parsed, $command, $normalized, $client_context = [], $callbacks = [] ) {
		$parsed = is_array( $parsed ) ? $parsed : [];

		if ( empty( $parsed['parser_source'] ) ) {
			$parsed['parser_source'] = 'guard';
		}

		if ( empty( $parsed['confidence'] ) ) {
			$parsed['confidence'] = 0.94;
		}

		if ( ! empty( $parsed['stage'] ) && 'clarify' === sanitize_key( $parsed['stage'] ) ) {
			return $parsed;
		}

		$callbacks = is_array( $callbacks ) ? $callbacks : [];
		$normalized_result = $parsed;

		if ( ! empty( $callbacks['normalize_parsed_result'] ) && is_callable( $callbacks['normalize_parsed_result'] ) ) {
			$normalized_result = call_user_func(
				$callbacks['normalize_parsed_result'],
				$parsed,
				$command,
				$normalized,
				is_array( $client_context ) ? $client_context : []
			);
			$normalized_result = is_array( $normalized_result ) ? $normalized_result : $parsed;
		} elseif ( class_exists( 'YoRo_Parser_Result_Normalizer' ) ) {
			$normalized_result = YoRo_Parser_Result_Normalizer::normalize(
				$parsed,
				$command,
				$normalized,
				is_array( $client_context ) ? $client_context : []
			);
		}

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

	private static function looks_like_contextual_write_command( $normalized ) {
		return (bool) preg_match(
			'/\b(?:change|mark|move|set|update|hold|add\s+note|leave\s+note|with\s+note|note\s*:)\b/i',
			(string) $normalized
		);
	}
}
