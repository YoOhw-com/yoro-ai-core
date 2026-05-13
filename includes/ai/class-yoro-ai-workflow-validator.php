<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Workflow_Validator {

	public static function validate( $parsed ) {

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'yoro_ai_invalid_workflow',
				__( 'Invalid AI workflow.', 'yoro-agent-core' )
			);
		}

		$steps = $parsed['actions'] ?? [];

		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return new WP_Error(
				'yoro_ai_empty_workflow',
				__( 'AI workflow has no steps.', 'yoro-agent-core' )
			);
		}

		$max_steps = 6;

		if ( count( $steps ) > $max_steps ) {
			return new WP_Error(
				'yoro_ai_workflow_too_large',
				sprintf(
					__( 'AI workflow exceeds maximum step limit (%d).', 'yoro-agent-core' ),
					$max_steps
				)
			);
		}

		$write_steps = 0;

		foreach ( $steps as $index => $step ) {

			$action = sanitize_text_field(
				$step['action'] ?? ''
			);

			if ( empty( $action ) ) {
				return new WP_Error(
					'yoro_ai_missing_action',
					__( 'AI workflow contains an empty action.', 'yoro-agent-core' )
				);
			}

			$registered = YoRo_Action_Registry::get( $action );

			if ( ! $registered ) {
				return new WP_Error(
					'yoro_ai_unknown_action',
					sprintf(
						__( 'AI workflow uses unknown action "%s".', 'yoro-agent-core' ),
						$action
					)
				);
			}

			$required_params = self::get_required_params_for_action( $action );

			$params = isset( $step['params'] ) && is_array( $step['params'] )
				? $step['params']
				: [];

			foreach ( $required_params as $param ) {
				if (
					! array_key_exists( $param, $params ) ||
					'' === $params[ $param ] ||
					[] === $params[ $param ]
				) {
					return new WP_Error(
						'yoro_ai_missing_required_param',
						sprintf(
							__( 'AI workflow action "%1$s" is missing required parameter "%2$s".', 'yoro-agent-core' ),
							$action,
							$param
						)
					);
				}
			}

			/*
			 * Block dangerous actions.
			 */
			$forbidden_actions = [
				'orders.delete',
				'orders.force_delete',
			];

			if ( in_array( $action, $forbidden_actions, true ) ) {
				return new WP_Error(
					'yoro_ai_forbidden_action',
					sprintf(
						__( 'AI workflow attempted forbidden action "%s".', 'yoro-agent-core' ),
						$action
					)
				);
			}

			/*
			 * Count writes.
			 */
			if ( ! empty( $registered['write_action'] ) ) {
				$write_steps++;
			}

			/*
			 * Retry limits.
			 */
			$max_attempts = absint(
				$step['retry']['max_attempts'] ?? 1
			);

			if ( $max_attempts > 3 ) {
				return new WP_Error(
					'yoro_ai_retry_limit',
					sprintf(
						__( 'AI workflow retry limit exceeded for "%s".', 'yoro-agent-core' ),
						$action
					)
				);
			}

			/*
			 * on_error validation.
			 */
			$on_error = sanitize_key(
				$step['on_error'] ?? 'stop'
			);

			if ( ! in_array(
				$on_error,
				[ 'stop', 'continue', 'rollback', 'skip_remaining' ],
				true
			) ) {
				return new WP_Error(
					'yoro_ai_invalid_on_error',
					sprintf(
						__( 'AI workflow uses invalid on_error policy for "%s".', 'yoro-agent-core' ),
						$action
					)
				);
			}
		}

		/*
		 * Too many writes.
		 */
		if ( $write_steps > 3 ) {
			return new WP_Error(
				'yoro_ai_too_many_writes',
				__( 'AI workflow contains too many write actions.', 'yoro-agent-core' )
			);
		}

		$dependency_validation = self::validate_step_dependencies( $steps );

		if ( is_wp_error( $dependency_validation ) ) {
			return $dependency_validation;
		}

		return true;
	}

	private static function get_required_params_for_action( $action ) {
		$map = [
			'orders.get_details'                  => [ 'order_ids' ],
			'orders.compose_customer_email'       => [ 'order_id', 'instruction' ],
			'orders.render_customer_email_preview'=> [ 'order_id' ],
			'orders.save_customer_email_draft'    => [ 'order_id', 'subject', 'body' ],
			'orders.update_status'                => [ 'order_ids', 'status' ],
			'orders.hold'                         => [ 'order_ids' ],
			'orders.add_note'                     => [ 'order_ids', 'note' ],
			'orders.refund_preview'               => [ 'order_id' ],
		];

		return $map[ $action ] ?? [];
	}

	private static function validate_step_dependencies( $steps ) {

		$completed_actions = [];

		foreach ( $steps as $step ) {

			$action = sanitize_text_field(
				$step['action'] ?? ''
			);

			if ( empty( $action ) ) {
				continue;
			}

			/*
			* Save draft requires compose first.
			*/
			if (
				'orders.save_customer_email_draft' === $action &&
				! in_array(
					'orders.compose_customer_email',
					$completed_actions,
					true
				)
			) {
				return new WP_Error(
					'yoro_ai_missing_dependency',
					__( 'Save draft requires compose email step first.', 'yoro-agent-core' )
				);
			}

			/*
			* Preview requires compose first.
			*/
			if (
				'orders.render_customer_email_preview' === $action &&
				! in_array(
					'orders.compose_customer_email',
					$completed_actions,
					true
				)
			) {
				return new WP_Error(
					'yoro_ai_missing_dependency',
					__( 'Email preview requires compose email step first.', 'yoro-agent-core' )
				);
			}

			$completed_actions[] = $action;
		}

		return true;
	}
}