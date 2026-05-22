<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Pre_Router_Guard_Result_Router {

	const RESOLUTION_PATH_DETERMINISTIC_OVERRIDE = 'deterministic_override';

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$current_email_delivery = self::maybe_parse_current_email_delivery(
			$command,
			$normalized,
			$client_context,
			self::callbacks( $callbacks )
		);

		if ( is_array( $current_email_delivery ) ) {
			return $current_email_delivery;
		}

		$refund_guard = class_exists( 'YoRo_AI_Pre_Router_Order_Workflow_Guard' )
			? YoRo_AI_Pre_Router_Order_Workflow_Guard::build_explicit_refund_response(
				$command,
				$normalized,
				$client_context,
				self::callbacks( $callbacks )
			)
			: null;

		if ( is_array( $refund_guard ) ) {
			return self::with_resolution_path(
				$refund_guard,
				self::RESOLUTION_PATH_DETERMINISTIC_OVERRIDE,
				'explicit_refund_guard'
			);
		}

		$verification_guard = class_exists( 'YoRo_AI_Pre_Router_Order_Workflow_Guard' )
			? YoRo_AI_Pre_Router_Order_Workflow_Guard::build_explicit_verification_response(
				$command,
				$normalized,
				$client_context
			)
			: null;

		if ( is_array( $verification_guard ) ) {
			return self::with_resolution_path(
				$verification_guard,
				self::RESOLUTION_PATH_DETERMINISTIC_OVERRIDE,
				'explicit_verification_guard'
			);
		}

		$guided_followup_guard = class_exists( 'YoRo_AI_Pre_Router_Guided_Followup_Guard' )
			? YoRo_AI_Pre_Router_Guided_Followup_Guard::parse(
				$command,
				$normalized,
				$client_context,
				self::callbacks( $callbacks )
			)
			: null;

		if ( is_array( $guided_followup_guard ) ) {
			return self::with_resolution_path(
				$guided_followup_guard,
				self::RESOLUTION_PATH_DETERMINISTIC_OVERRIDE,
				'guided_followup_guard'
			);
		}

		$contextual_order_write_guard = class_exists( 'YoRo_AI_Pre_Router_Contextual_Order_Write_Guard' )
			? YoRo_AI_Pre_Router_Contextual_Order_Write_Guard::parse(
				$command,
				$normalized,
				$client_context,
				self::callbacks( $callbacks )
			)
			: null;

		if ( is_array( $contextual_order_write_guard ) ) {
			return self::with_resolution_path(
				$contextual_order_write_guard,
				self::RESOLUTION_PATH_DETERMINISTIC_OVERRIDE,
				'contextual_order_write_guard'
			);
		}

		$followup_reference_guard = class_exists( 'YoRo_AI_Pre_Router_Followup_Reference_Guard' )
			? YoRo_AI_Pre_Router_Followup_Reference_Guard::parse(
				$command,
				$normalized,
				$client_context,
				self::callbacks( $callbacks )
			)
			: null;

		if ( is_array( $followup_reference_guard ) ) {
			return self::with_resolution_path(
				$followup_reference_guard,
				self::RESOLUTION_PATH_DETERMINISTIC_OVERRIDE,
				'followup_reference_guard'
			);
		}

		$action_composition_guard = class_exists( 'YoRo_AI_Action_Composition_Router' )
			? YoRo_AI_Action_Composition_Router::maybe_build_validated_candidate_from_hint(
				$command,
				$normalized,
				$client_context,
				[
					'parser_source' => 'guard',
				],
				self::callbacks( $callbacks )
			)
			: null;

		if ( is_array( $action_composition_guard ) ) {
			return self::with_resolution_path(
				$action_composition_guard,
				self::RESOLUTION_PATH_DETERMINISTIC_OVERRIDE,
				'action_composition_guard'
			);
		}

		return null;
	}

	private static function maybe_parse_current_email_delivery( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if (
			! in_array(
				$normalized,
				[
					'send email',
					'send the email',
					'send this email',
					'send current email',
					'send reviewed email',
				],
				true
			)
		) {
			return null;
		}

		$workflow = isset( $client_context['current_workflow'] ) && is_array( $client_context['current_workflow'] )
			? $client_context['current_workflow']
			: [];

		if (
			'customer_email' !== sanitize_key( $workflow['type'] ?? '' ) ||
			empty( $workflow['order_id'] ) ||
			empty( $workflow['subject'] ) ||
			empty( $workflow['body'] )
		) {
			return null;
		}

		$result = [
			'success'            => true,
			'matched'            => true,
			'action'             => 'orders.send_customer_email',
			'params'             => [
				'order_id' => absint( $workflow['order_id'] ),
				'subject'  => sanitize_text_field( $workflow['subject'] ),
				'body'     => sanitize_textarea_field( $workflow['body'] ),
			],
			'parser_rule'        => 'guard.follow_up.send_current_email.workflow',
			'parser_source'      => 'guard',
			'confidence'         => 0.96,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];

		return self::with_resolution_path(
			self::normalize_resolve_validate_result( $result, $command, $normalized, $client_context, $callbacks ),
			self::RESOLUTION_PATH_DETERMINISTIC_OVERRIDE,
			'current_email_delivery'
		);
	}

	private static function callbacks( $callbacks = [] ) {
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
