<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Command_Router_Pipeline {

	public static function route( $command, $normalized, $client_context = [] ) {
		if ( 'yes' !== get_option( 'yoro_ai_command_router_enabled', 'yes' ) ) {
			return null;
		}

		if ( ! class_exists( 'YoRo_AI_Command_Router' ) ) {
			return null;
		}

		$client_context = is_array( $client_context ) ? $client_context : [];
		$decision = YoRo_AI_Command_Router::route( $command, $client_context );

		if ( is_wp_error( $decision ) ) {
			return self::is_recoverable_router_error( $decision ) ? null : $decision;
		}

		if ( ! is_array( $decision ) ) {
			return null;
		}

		if ( class_exists( 'YoRo_Router_Decision_Validator' ) ) {
			$decision = YoRo_Router_Decision_Validator::normalize(
				$decision,
				$command,
				$normalized,
				$client_context
			);
		}

		if ( class_exists( 'YoRo_Router_Context_Decision_Hydrator' ) ) {
			$decision = YoRo_Router_Context_Decision_Hydrator::hydrate(
				$decision,
				$command,
				$normalized,
				$client_context
			);
		}

		return self::refresh_decision_contract( $decision );
	}

	private static function is_recoverable_router_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$error_code = $error->get_error_code();

		if (
			in_array(
				$error_code,
				[
					'yoro_ai_missing_api_key',
					'yoro_ai_intent_api_error',
					'http_request_failed',
				],
				true
			)
		) {
			return true;
		}

		$error_message = sanitize_text_field( $error->get_error_message() );

		return '' !== $error_message &&
			(bool) preg_match( '/\b(?:cURL error|timed out|timeout|could not resolve host|connection refused)\b/i', $error_message );
	}

	private static function refresh_decision_contract( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];
		$action   = sanitize_text_field( $decision['action'] ?? '' );

		if ( '' === $action ) {
			return $decision;
		}

		$catalog_item = self::build_action_catalog_item( $action );

		if ( ! empty( $catalog_item ) ) {
			$decision['action_catalog_item'] = $catalog_item;
		}

		if ( empty( $decision['intent_family'] ) && ! empty( $catalog_item['intent_family'] ) ) {
			$decision['intent_family'] = sanitize_key( $catalog_item['intent_family'] );
		}

		return $decision;
	}

	private static function build_action_catalog_item( $action ) {
		$action = sanitize_text_field( $action );

		if ( '' === $action || ! class_exists( 'YoRo_Action_Registry' ) ) {
			return [];
		}

		$config = YoRo_Action_Registry::get( $action );

		if ( empty( $config ) || ! is_array( $config ) ) {
			return [];
		}

		$input_schema = isset( $config['input_schema'] ) && is_array( $config['input_schema'] )
			? $config['input_schema']
			: [];
		$required_params = ! empty( $config['required_params'] ) && is_array( $config['required_params'] )
			? array_values( array_map( 'sanitize_key', $config['required_params'] ) )
			: [];

		return [
			'name'                  => $action,
			'label'                 => sanitize_text_field( $config['label'] ?? $action ),
			'description'           => sanitize_text_field( $config['ai_description'] ?? ( $config['description'] ?? '' ) ),
			'category'              => sanitize_key( $config['category'] ?? 'general' ),
			'intent_family'         => sanitize_key( $config['intent_family'] ?? '' ),
			'input_schema'          => is_array( $input_schema ) ? $input_schema : [],
			'required_params'       => $required_params,
			'param_hints'           => isset( $config['param_hints'] ) && is_array( $config['param_hints'] ) ? $config['param_hints'] : [],
			'clarification_hints'   => isset( $config['clarification_hints'] ) && is_array( $config['clarification_hints'] ) ? $config['clarification_hints'] : [],
			'write_action'          => ! empty( $config['write_action'] ),
			'requires_confirmation' => ! empty( $config['requires_confirmation'] ) || ! empty( $config['write_action'] ),
			'risk_level'            => sanitize_key( $config['risk_level'] ?? 'low' ),
		];
	}
}
