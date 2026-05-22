<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Runtime_Guard {

	public static function prepare( $plan, $context = [] ) {
		if ( ! is_array( $plan ) || empty( $plan['type'] ) ) {
			return new WP_Error(
				'yoro_invalid_runtime_plan',
				__( 'Invalid runtime plan.', 'yoro-ai-core' )
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

		$risk   = isset( $runtime['risk'] ) && is_array( $runtime['risk'] ) ? $runtime['risk'] : [];
		$policy = isset( $runtime['policy'] ) && is_array( $runtime['policy'] ) ? $runtime['policy'] : [];

		return [
			'success'          => false,
			'stage'            => 'confirm',
			'parsed'           => is_array( $parsed ) ? $parsed : [],
			'plan'             => is_array( $plan ) ? $plan : [],
			'confirm_required' => true,
			'message'          => __( 'This action requires confirmation before running.', 'yoro-ai-core' ),
			'risk'             => $risk,
			'policy'           => $policy,
			'result'           => [
				'success'               => false,
				'requires_confirmation' => true,
				'action'                => $action,
				'risk_level'            => $risk['risk_level'] ?? 'medium',
				'risk_reasons'          => $risk['reasons'] ?? [],
				'policy_reasons'        => $policy['reasons'] ?? [],
				'display_type'          => 'confirmation',
				'result_type'           => 'confirmation',
				'read_only'             => true,
			],
		];
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
