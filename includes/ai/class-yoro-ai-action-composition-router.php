<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Action_Composition_Router {

	public static function maybe_build_validated_candidate( $command, $normalized, $client_context, $parsed, $callbacks = [] ) {
		$candidate = self::maybe_resolve_candidate( $command, $normalized, $client_context, $parsed );

		if ( ! is_array( $candidate ) ) {
			return $candidate;
		}

		return self::normalize_resolve_validate_result( $candidate, $command, $normalized, $client_context, $callbacks );
	}

	public static function maybe_build_validated_candidate_from_hint( $command, $normalized, $client_context, $hint = [], $callbacks = [] ) {
		$candidate = self::maybe_build_candidate_from_hint( $command, $normalized, $client_context, $hint );

		if ( ! is_array( $candidate ) ) {
			return $candidate;
		}

		return self::normalize_resolve_validate_result( $candidate, $command, $normalized, $client_context, $callbacks );
	}

	public static function maybe_build_candidate_from_hint( $command, $normalized, $client_context, $hint = [] ) {
		if ( ! class_exists( 'YoRo_Action_Composition_Planner' ) ) {
			return null;
		}

		return YoRo_Action_Composition_Planner::maybe_build_parsed(
			$command,
			$normalized,
			$client_context,
			is_array( $hint ) ? $hint : []
		);
	}

	public static function maybe_resolve_candidate( $command, $normalized, $client_context, $parsed ) {
		if ( ! class_exists( 'YoRo_Action_Composition_Planner' ) || ! is_array( $parsed ) ) {
			return null;
		}

		$action = sanitize_text_field( $parsed['action'] ?? '' );

		if ( '' === $action || 0 === strpos( $action, 'workflow.' ) ) {
			return null;
		}

		return YoRo_Action_Composition_Planner::maybe_build_parsed(
			$command,
			$normalized,
			$client_context,
			[
				'action'        => $action,
				'params'        => isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [],
				'confidence'    => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0,
				'parser_source' => sanitize_text_field( $parsed['parser_source'] ?? 'rule' ),
			]
		);
	}

	private static function normalize_resolve_validate_result( $parsed, $command, $normalized, $client_context, $callbacks = [] ) {
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

	private static function normalize_parsed_result( $parsed, $command, $normalized, $client_context = [], $callbacks = [] ) {
		$normalized_result = self::invoke(
			$callbacks,
			'normalize_parsed_result',
			[ $parsed, $command, $normalized, $client_context ]
		);

		return is_array( $normalized_result ) ? $normalized_result : $parsed;
	}

	private static function invoke( $callbacks, $name, $args = [], $default = null ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		if ( empty( $callbacks[ $name ] ) || ! is_callable( $callbacks[ $name ] ) ) {
			return $default;
		}

		return call_user_func_array( $callbacks[ $name ], $args );
	}
}
