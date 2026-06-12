<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Runtime_Guard {

	public static function prepare( $plan, $context = [] ) {
		if ( ! is_array( $plan ) || empty( $plan['type'] ) ) {
			return new WP_Error(
				'yoro_invalid_runtime_plan',
				__( 'I could not prepare this action safely.', 'yoro-ai-core' )
			);
		}

		$context = wp_parse_args(
			is_array( $context ) ? $context : [],
			[
				'source'        => 'rule',
				'parser_source' => '',
				'route'         => '',
				'preview'       => false,
				'confirm'       => false,
			]
		);

		$preview = ! empty( $context['preview'] );
		$confirm = ! empty( $context['confirm'] );

		$policy = YoRo_Policy_Engine::evaluate_plan( $plan, $context );

		if ( is_wp_error( $policy ) ) {
			return $policy;
		}

		$risk = YoRo_Plan_Risk_Engine::evaluate( $plan );

		$runtime_policy = YoRo_Policy_Engine::get_runtime_policy_for_plan(
			$plan,
			$context
		);

		if (
			! $preview &&
			! $confirm &&
			! empty( $runtime_policy['requires_preview'] )
		) {
			$preview = true;
		}

		$policy_requires_confirmation = (
			! $preview &&
			! $confirm &&
			! empty( $runtime_policy['requires_confirmation'] )
		);

		$risk_requires_confirmation = (
			! $preview &&
			! $confirm &&
			! empty( $risk['requires_confirmation'] )
		);

		$requires_confirmation = (
			$policy_requires_confirmation ||
			$risk_requires_confirmation
		);

		$runtime = [
			'success'                      => true,
			'preview'                      => $preview,
			'confirm'                      => $confirm,
			'risk'                         => $risk,
			'policy'                       => $runtime_policy,
			'requires_confirmation'        => $requires_confirmation,
			'policy_requires_confirmation' => $policy_requires_confirmation,
			'risk_requires_confirmation'   => $risk_requires_confirmation,
			'reason'                       => $policy_requires_confirmation ? 'policy_confirmation' : ( $risk_requires_confirmation ? 'plan_risk' : '' ),
		];

		return $runtime;
	}

	public static function build_confirmation_response( $plan, $parsed, $runtime ) {
		$action = self::get_plan_action_label( $plan );
		$confirmation_preview = self::build_confirmation_preview( $plan );

		$risk   = isset( $runtime['risk'] ) && is_array( $runtime['risk'] ) ? $runtime['risk'] : [];
		$policy = isset( $runtime['policy'] ) && is_array( $runtime['policy'] ) ? $runtime['policy'] : [];

		return [
			'success'          => false,
			'stage'            => 'confirm',
			'parsed'           => is_array( $parsed ) ? $parsed : [],
			'plan'             => is_array( $plan ) ? $plan : [],
			'confirm_required' => true,
			'message'          => __( 'Please approve this change when you are ready. Nothing has changed yet.', 'yoro-ai-core' ),
			'risk'             => $risk,
			'policy'           => $policy,
			'result'           => [
				'success'               => false,
				'requires_confirmation' => true,
				'action'                => $action,
				'risk_level'            => $risk['risk_level'] ?? 'medium',
				'risk_reasons'          => $risk['reasons'] ?? [],
				'policy_reasons'        => $policy['reasons'] ?? [],
				'preview'               => $confirmation_preview,
				'display_type'          => 'confirmation',
				'result_type'           => 'confirmation',
				'read_only'             => true,
			],
		];
	}

	private static function build_confirmation_preview( $plan ) {
		if ( ! is_array( $plan ) || 'single' !== ( $plan['type'] ?? '' ) ) {
			return [];
		}

		$action_name = sanitize_text_field( (string) ( $plan['action'] ?? '' ) );
		$params      = isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : [];
		$action      = '' !== $action_name && class_exists( 'YoRo_Action_Registry' )
			? YoRo_Action_Registry::get( $action_name )
			: null;

		if ( ! is_array( $action ) || empty( $action['preview_callback'] ) || ! is_callable( $action['preview_callback'] ) ) {
			return [];
		}

		$preview = call_user_func( $action['preview_callback'], $params );

		if ( is_wp_error( $preview ) ) {
			return [
				'success' => false,
				'code'    => sanitize_key( $preview->get_error_code() ),
				'message' => sanitize_text_field( $preview->get_error_message() ),
			];
		}

		return is_array( $preview ) ? $preview : [];
	}

	private static function get_plan_action_label( $plan ) {
		if ( ! is_array( $plan ) ) {
			return '';
		}

		if ( 'single' === ( $plan['type'] ?? '' ) ) {
			return sanitize_text_field( $plan['action'] ?? '' );
		}

		if ( 'sequence' === ( $plan['type'] ?? '' ) ) {
			return sanitize_text_field( $plan['action'] ?? 'multi_action' );
		}

		return '';
	}
}
