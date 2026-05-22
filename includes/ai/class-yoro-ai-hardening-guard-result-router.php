<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Hardening_Guard_Result_Router {

	const RESOLUTION_PATH_RECOVERY_FALLBACK = 'recovery_fallback';
	const RESOLUTION_PATH_SAFETY_GUARD      = 'safety_guard';

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$result = self::parse_with_hardening_guards( $command, $normalized, $client_context, $callbacks );

		if ( ! is_array( $result ) ) {
			return null;
		}

		$composition_candidate = class_exists( 'YoRo_AI_Action_Composition_Router' )
			? YoRo_AI_Action_Composition_Router::maybe_build_validated_candidate(
				$command,
				$normalized,
				$client_context,
				$result,
				self::candidate_router_callbacks( $callbacks )
			)
			: null;

		if ( is_array( $composition_candidate ) ) {
			return self::with_resolution_path(
				$composition_candidate,
				self::RESOLUTION_PATH_RECOVERY_FALLBACK,
				'action_composition'
			);
		}

		$workflow_candidate = class_exists( 'YoRo_AI_Workflow_Candidate_Router' )
			? YoRo_AI_Workflow_Candidate_Router::maybe_build_validated_workflow_candidate(
				$command,
				$normalized,
				$client_context,
				$result,
				self::candidate_router_callbacks( $callbacks )
			)
			: null;

		if ( is_array( $workflow_candidate ) ) {
			return self::with_resolution_path(
				$workflow_candidate,
				self::RESOLUTION_PATH_RECOVERY_FALLBACK,
				'workflow_candidate'
			);
		}

		return self::with_resolution_path(
			$result,
			self::RESOLUTION_PATH_SAFETY_GUARD,
			'hardening_guard'
		);
	}

	private static function parse_with_hardening_guards( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$grounded_last_orders_reference = class_exists( 'YoRo_AI_Inferred_Action_Grounding' )
			? YoRo_AI_Inferred_Action_Grounding::build_last_orders_reference_response(
				$command,
				$normalized,
				$client_context,
				self::grounding_callbacks( $callbacks )
			)
			: null;

		if ( is_array( $grounded_last_orders_reference ) ) {
			return $grounded_last_orders_reference;
		}

		$email_template_guard = class_exists( 'YoRo_AI_Hardening_Guard_Router' )
			? YoRo_AI_Hardening_Guard_Router::parse_email_template_list(
				$command,
				$normalized,
				$client_context,
				self::normalizer_callbacks( $callbacks )
			)
			: null;

		if ( is_array( $email_template_guard ) ) {
			return $email_template_guard;
		}

		$explicit_capability_gap = class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::build_explicit_capability_gap_response( $command, $normalized, $client_context )
			: null;

		if ( is_array( $explicit_capability_gap ) ) {
			return $explicit_capability_gap;
		}

		$operations_guard = class_exists( 'YoRo_AI_Hardening_Guard_Router' )
			? YoRo_AI_Hardening_Guard_Router::parse_operations_hardening(
				$command,
				$normalized,
				$client_context,
				self::normalizer_callbacks( $callbacks )
			)
			: null;

		if ( is_array( $operations_guard ) ) {
			return $operations_guard;
		}

		return null;
	}

	private static function normalizer_callbacks( $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		return [
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) use ( $callbacks ) {
				return self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );
			},
		];
	}

	private static function pre_router_callbacks( $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		return [
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) use ( $callbacks ) {
				return self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );
			},
			'hydrate_result_intent_family_from_action' => static function( $result ) use ( $callbacks ) {
				return self::hydrate_result_intent_family_from_action( $result, $callbacks );
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

	private static function candidate_router_callbacks( $callbacks = [] ) {
		return self::normalizer_callbacks( $callbacks );
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
