<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Pre_Router_Guided_Followup_Guard {

	public static function parse( $command, $normalized, $client_context = [], $callbacks = [] ) {
		$command        = is_string( $command ) ? trim( $command ) : '';
		$normalized     = is_string( $normalized ) ? trim( $normalized ) : '';
		$client_context = is_array( $client_context ) ? $client_context : [];

		if ( '' === $normalized ) {
			return null;
		}

		foreach ( self::definitions() as $action => $definition ) {
			if ( ! self::matches_definition( $normalized, $definition ) ) {
				continue;
			}

			$last_followup = isset( $client_context[ $definition['context_key'] ] ) && is_array( $client_context[ $definition['context_key'] ] )
				? $client_context[ $definition['context_key'] ]
				: [];

			if ( ! self::has_required_context( $definition, $last_followup ) ) {
				return null;
			}

			$result = [
				'success'            => true,
				'matched'            => true,
				'action'             => $action,
				'params'             => [
					'original_command' => $command,
				],
				'parser_rule'        => $definition['guard_rule'],
				'parser_source'      => 'guard',
				'confidence'         => 0.94,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];

			return self::normalize_resolve_validate_result( $result, $command, $normalized, $client_context, $callbacks );
		}

		return null;
	}

	private static function definitions() {
		return [
			'workflow.fulfillment_followup_preview' => [
				'context_key' => 'last_fulfillment_followup',
				'guard_rule'  => 'guard.workflow.fulfillment_followup_preview',
				'needles'     => [
					'prepare the next fulfillment follow up',
					'prepare the next fulfillment follow-up',
					'prepare the next delay email',
					'draft the next delay email',
					'draft next delay email',
					'prepare next delay email',
					'handle the first fulfillment issue',
					'handle the next fulfillment recommendation',
					'prepare the next fulfillment recommendation',
				],
			],
			'workflow.customer_followup_preview' => [
				'context_key' => 'last_service_followup',
				'guard_rule'  => 'guard.workflow.customer_followup_preview',
				'needles'     => [
					'prepare the next customer follow up',
					'prepare the next customer follow-up',
					'draft the next customer follow up',
					'draft the next customer follow-up',
					'draft the next customer reply',
					'prepare the next customer reply',
					'prepare the next support reply',
					'prepare next support reply',
					'handle the next customer recommendation',
					'prepare the next customer recommendation',
				],
			],
			'workflow.operations_attention_preview' => [
				'context_key' => 'last_attention_followup',
				'guard_rule'  => 'guard.workflow.operations_attention_preview',
				'needles'     => [
					'prepare the next attention follow up',
					'prepare the next attention follow-up',
					'handle the next urgent operations issue',
					'review the next attention item',
					'prepare the next attention recommendation',
					'prepare next attention item',
				],
			],
			'workflow.inventory_pressure_preview' => [
				'context_key' => 'last_inventory_followup',
				'guard_rule'  => 'guard.workflow.inventory_pressure_preview',
				'needles'     => [
					'prepare the next inventory review',
					'show me the next stock issue to handle',
					'review the next inventory recommendation',
					'prepare the next inventory follow up',
					'prepare the next inventory follow-up',
					'prepare next inventory review',
				],
			],
			'workflow.business_summary_preview' => [
				'context_key' => 'last_business_followup',
				'guard_rule'  => 'guard.workflow.business_summary_preview',
				'context'     => 'business',
				'needles'     => [
					'prepare the next business review',
					'prepare next business review',
					'review the next business recommendation',
					'prepare the next business recommendation',
				],
			],
		];
	}

	private static function matches_definition( $normalized, $definition ) {
		$needles = isset( $definition['needles'] ) && is_array( $definition['needles'] )
			? $definition['needles']
			: [];

		foreach ( $needles as $needle ) {
			$needle = is_string( $needle ) ? trim( $needle ) : '';

			if ( '' === $needle ) {
				continue;
			}

			$pattern = '/^(?:please\s+|can you\s+|could you\s+)?' . preg_quote( $needle, '/' ) . '(?:\s+please)?[\.\!\?]*$/u';

			if ( preg_match( $pattern, $normalized ) ) {
				return true;
			}
		}

		return false;
	}

	private static function has_required_context( $definition, $last_followup ) {
		$definition    = is_array( $definition ) ? $definition : [];
		$last_followup = is_array( $last_followup ) ? $last_followup : [];

		if ( 'business' === sanitize_key( $definition['context'] ?? '' ) ) {
			return ! empty( $last_followup['priority_items'] ) ||
				! empty( $last_followup['recommended_sequence'] ) ||
				! empty( $last_followup['business_context'] );
		}

		return ! empty( $last_followup['queues'] ) && is_array( $last_followup['queues'] );
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
}
