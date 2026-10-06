<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Plan_Semantic_Validator {

	public static function validate( $plan, $parsed = [] ) {
		$plan   = is_array( $plan ) ? $plan : [];
		$parsed = is_array( $parsed ) ? $parsed : [];
		$order_facts = self::index_order_facts( $parsed, $plan );

		if ( 'single' === sanitize_key( $plan['type'] ?? '' ) ) {
			return self::validate_single_plan_semantics( $plan, $parsed, $order_facts );
		}

		if ( 'sequence' !== sanitize_key( $plan['type'] ?? '' ) ) {
			return [
				'semantic' => [
					'passed'   => true,
					'warnings' => [],
				],
			];
		}

		$steps             = isset( $plan['steps'] ) && is_array( $plan['steps'] ) ? array_values( $plan['steps'] ) : [];
		$capture_producers = [];
		$warnings          = [];
		$status_history    = [];

		$recipient_validation = self::validate_customer_email_recipient_coherence( $steps, $order_facts );

		if ( is_wp_error( $recipient_validation ) ) {
			return $recipient_validation;
		}

		foreach ( $steps as $index => $step ) {
			$step       = is_array( $step ) ? $step : [];
			$step_id    = sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) );
			$step_label = sanitize_text_field( $step['label'] ?? $step_id );
			$action     = sanitize_text_field( $step['action'] ?? '' );

			if ( '' === $action ) {
				return new WP_Error(
					'yoro_invalid_action_plan_step',
					__( 'Plan step is missing its action name.', 'yoro-ai-core' ),
					[
						'step_id'    => $step_id,
						'step_index' => $index,
					]
				);
			}

			if ( ! YoRo_Action_Registry::get( $action ) ) {
				return new WP_Error(
					'yoro_invalid_action_plan_step',
					sprintf(
						/* translators: 1: step label, 2: action name */
						__( 'The plan step "%1$s" uses an action YoRo does not support yet: %2$s.', 'yoro-ai-core' ),
						$step_label,
						$action
					),
					[
						'step_id'    => $step_id,
						'step_index' => $index,
						'action'     => $action,
					]
				);
			}

			$dependency_error = self::validate_placeholder_dependencies(
				$step,
				$index,
				$capture_producers,
				$plan,
				$parsed
			);

			if ( is_wp_error( $dependency_error ) ) {
				return $dependency_error;
			}

			$email_validation = self::validate_email_dependencies(
				$step,
				$index,
				$steps,
				$capture_producers,
				$order_facts
			);

			if ( is_wp_error( $email_validation ) ) {
				return $email_validation;
			}

			if ( is_array( $email_validation ) && ! empty( $email_validation['warnings'] ) ) {
				$warnings = array_merge( $warnings, $email_validation['warnings'] );
			}

			$status_validation = self::validate_status_semantics(
				$step,
				$index,
				$status_history,
				$order_facts
			);

			if ( is_wp_error( $status_validation ) ) {
				return $status_validation;
			}

			if ( is_array( $status_validation ) && ! empty( $status_validation['warnings'] ) ) {
				$warnings = array_merge( $warnings, $status_validation['warnings'] );
			}

			$entity_validation = self::validate_order_entity_preconditions(
				$step,
				$index,
				$order_facts
			);

			if ( is_wp_error( $entity_validation ) ) {
				return $entity_validation;
			}

			if ( is_array( $entity_validation ) && ! empty( $entity_validation['warnings'] ) ) {
				$warnings = array_merge( $warnings, $entity_validation['warnings'] );
			}

			$capture = isset( $step['capture'] ) && is_array( $step['capture'] ) ? $step['capture'] : [];

			foreach ( $capture as $key => $path ) {
				$key = sanitize_key( $key );

				if ( '' === $key ) {
					continue;
				}

				if ( isset( $capture_producers[ $key ] ) ) {
					$warnings[] = [
						'code'    => 'capture_key_overwritten',
						'message' => sprintf(
							/* translators: 1: capture key, 2: current step id, 3: previous step id */
							__( 'Capture key "%1$s" is overwritten by step "%2$s" after being produced by "%3$s".', 'yoro-ai-core' ),
							$key,
							$step_id,
							$capture_producers[ $key ]['step_id']
						),
						'step_id' => $step_id,
					];
				}

				$capture_producers[ $key ] = [
					'step_id'    => $step_id,
					'step_index' => $index,
					'action'     => $action,
					'path'       => is_string( $path ) ? trim( $path ) : '',
				];
			}

			$scope_key = self::get_status_scope_key( $step );
			$status    = self::get_step_status_value( $step );

			if ( '' !== $scope_key && '' !== $status ) {
				$status_history[ $scope_key ] = [
					'status'     => $status,
					'step_id'    => $step_id,
					'step_index' => $index,
					'action'     => $action,
				];
			}
		}

		$coherence_validation = self::validate_workflow_goal_coherence( $steps, $order_facts );

		if ( is_wp_error( $coherence_validation ) ) {
			return $coherence_validation;
		}

		if ( is_array( $coherence_validation ) && ! empty( $coherence_validation['warnings'] ) ) {
			$warnings = array_merge( $warnings, $coherence_validation['warnings'] );
		}

		return [
			'semantic' => [
				'passed'   => true,
				'warnings' => array_values( $warnings ),
			],
		];
	}

	private static function validate_customer_email_recipient_coherence( $steps, $order_facts ) {
		$steps             = is_array( $steps ) ? array_values( $steps ) : [];
		$order_facts       = is_array( $order_facts ) ? $order_facts : [];
		$email_order_ids   = [];
		$first_email_step  = null;
		$recipient_emails  = [];

		foreach ( $steps as $index => $step ) {
			$step   = is_array( $step ) ? $step : [];
			$action = sanitize_text_field( $step['action'] ?? '' );

			if ( ! in_array( $action, [ 'orders.compose_customer_email', 'orders.send_customer_email', 'orders.save_customer_email_draft' ], true ) ) {
				continue;
			}

			if ( null === $first_email_step ) {
				$first_email_step = [
					'id'    => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
					'index' => $index,
				];
			}

			foreach ( self::get_step_order_ids( $step ) as $order_id ) {
				$email_order_ids[ $order_id ] = true;
			}
		}

		if ( count( $email_order_ids ) <= 1 ) {
			return true;
		}

		foreach ( array_keys( $email_order_ids ) as $order_id ) {
			$email = sanitize_email( $order_facts[ $order_id ]['email'] ?? '' );

			if ( '' === $email ) {
				continue;
			}

			$recipient_emails[ strtolower( $email ) ] = true;
		}

		if ( count( $recipient_emails ) <= 1 ) {
			return true;
		}

		return new WP_Error(
			'yoro_invalid_action_plan_entity',
			__( 'This customer-email plan targets orders with different recipient emails. Split the plan by customer before composing, drafting, or sending emails.', 'yoro-ai-core' ),
			[
				'step_id'        => $first_email_step['id'] ?? 'step_1',
				'step_index'     => $first_email_step['index'] ?? 0,
				'recipient_count'=> count( $recipient_emails ),
				'order_ids'      => array_map( 'absint', array_keys( $email_order_ids ) ),
			]
		);
	}

	private static function validate_single_plan_semantics( $plan, $parsed, $order_facts ) {
		$plan        = is_array( $plan ) ? $plan : [];
		$parsed      = is_array( $parsed ) ? $parsed : [];
		$action      = sanitize_text_field( $plan['action'] ?? '' );
		$params      = isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : [];
		$warnings    = [];
		$single_step = [
			'id'     => 'single_action',
			'label'  => $action,
			'action' => $action,
			'params' => $params,
		];

		if ( in_array( $action, [ 'orders.compose_customer_email', 'orders.send_customer_email', 'orders.save_customer_email_draft' ], true ) ) {
			$email_validation = self::validate_email_content_semantics(
				$single_step,
				0,
				[ $single_step ],
				$order_facts
			);

			if ( is_wp_error( $email_validation ) ) {
				return $email_validation;
			}

			if ( is_array( $email_validation ) && ! empty( $email_validation['warnings'] ) ) {
				$warnings = array_merge( $warnings, $email_validation['warnings'] );
			}
		}

		$workflow_validation = self::validate_single_workflow_action_semantics(
			$action,
			$params,
			$order_facts
		);

		if ( is_wp_error( $workflow_validation ) ) {
			return $workflow_validation;
		}

		if ( is_array( $workflow_validation ) && ! empty( $workflow_validation['warnings'] ) ) {
			$warnings = array_merge( $warnings, $workflow_validation['warnings'] );
		}

		return [
			'semantic' => [
				'passed'   => true,
				'warnings' => array_values( $warnings ),
			],
		];
	}

	private static function validate_placeholder_dependencies( $step, $index, $capture_producers, $plan, $parsed ) {
		$step                = is_array( $step ) ? $step : [];
		$params              = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];
		$placeholders        = self::collect_param_placeholders( $params );
		$plan_params         = isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : [];
		$parsed_params       = isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [];
		$parsed_entities     = isset( $parsed['entities'] ) && is_array( $parsed['entities'] ) ? $parsed['entities'] : [];
		$allowed_local_alias = [
			'order_id',
			'product_id',
			'product_sku',
			'global_unique_id',
			'status',
		];

		foreach ( $placeholders as $placeholder ) {
			$key = $placeholder;

			if ( 0 === strpos( $key, 'context.' ) ) {
				$key = substr( $key, 8 );
			}

			$key = trim( $key );

			if ( '' === $key ) {
				continue;
			}

			if (
				array_key_exists( $key, $capture_producers ) ||
				array_key_exists( $key, $plan_params ) ||
				array_key_exists( $key, $parsed_params ) ||
				array_key_exists( $key, $parsed_entities ) ||
				in_array( $key, $allowed_local_alias, true )
			) {
				continue;
			}

			return new WP_Error(
				'yoro_invalid_action_plan_dependency',
				sprintf(
					/* translators: 1: placeholder name, 2: step id */
					__( 'Plan step "%2$s" depends on unresolved placeholder "%1$s".', 'yoro-ai-core' ),
					$key,
					sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) )
				),
				[
					'step_id'     => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
					'step_index'  => $index,
					'action'      => sanitize_text_field( $step['action'] ?? '' ),
					'placeholder' => $key,
				]
			);
		}

		return true;
	}

	private static function validate_email_dependencies( $step, $index, $steps, $capture_producers, $order_facts ) {
		$step   = is_array( $step ) ? $step : [];
		$action = sanitize_text_field( $step['action'] ?? '' );

		if ( ! in_array( $action, [ 'orders.compose_customer_email', 'orders.send_customer_email', 'orders.save_customer_email_draft' ], true ) ) {
			return true;
		}

		$params        = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];
		$placeholders  = self::collect_param_placeholders( $params );
		$uses_email_io = false;

		foreach ( $placeholders as $placeholder ) {
			$key = 0 === strpos( $placeholder, 'context.' ) ? substr( $placeholder, 8 ) : $placeholder;

			if ( in_array( $key, [ 'email_subject', 'email_body' ], true ) ) {
				$uses_email_io = true;
				break;
			}
		}

		if ( ! $uses_email_io ) {
			return self::validate_email_content_semantics( $step, $index, $steps, $order_facts );
		}

		$has_prior_compose = false;

		for ( $i = 0; $i < $index; $i++ ) {
			$prior_action = sanitize_text_field( $steps[ $i ]['action'] ?? '' );

			if ( 'orders.compose_customer_email' === $prior_action ) {
				$has_prior_compose = true;
				break;
			}
		}

		if ( $has_prior_compose && isset( $capture_producers['email_subject'], $capture_producers['email_body'] ) ) {
			$email_error = self::validate_email_recipient_requirements( $step, $index, $order_facts );

			if ( is_wp_error( $email_error ) ) {
				return $email_error;
			}

			return self::validate_email_content_semantics( $step, $index, $steps, $order_facts );
		}

		return new WP_Error(
			'yoro_invalid_action_plan_dependency',
			sprintf(
				/* translators: 1: step id */
				__( 'Plan step "%s" sends or saves an email before a compose step has produced the email subject and body.', 'yoro-ai-core' ),
				sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) )
			),
			[
				'step_id'    => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
				'step_index' => $index,
				'action'     => $action,
			]
		);
	}

	private static function validate_email_content_semantics( $step, $index, $steps, $order_facts ) {
		$step       = is_array( $step ) ? $step : [];
		$action     = sanitize_text_field( $step['action'] ?? '' );
		$warnings   = [];

		if ( ! in_array( $action, [ 'orders.compose_customer_email', 'orders.send_customer_email', 'orders.save_customer_email_draft' ], true ) ) {
			return [
				'warnings' => [],
			];
		}

		$goal_families          = self::structured_goal_families_for_step( $step );
		$is_shipping_messaging = in_array( 'fulfillment', $goal_families, true );
		$is_payment_messaging  = in_array( 'payment_review', $goal_families, true );
		$is_refund_messaging   = in_array( 'refund', $goal_families, true );

		if ( ! $is_shipping_messaging && ! $is_payment_messaging && ! $is_refund_messaging ) {
			return [
				'warnings' => [],
			];
		}

		$status_intent = self::infer_latest_status_intent_before_step( $steps, $index, $step );

		foreach ( self::get_step_order_ids( $step ) as $order_id ) {
			$facts = isset( $order_facts[ $order_id ] ) && is_array( $order_facts[ $order_id ] )
				? $order_facts[ $order_id ]
				: [];

			$requires_shipping = array_key_exists( 'requires_shipping', $facts )
				? ! empty( $facts['requires_shipping'] )
				: null;
			$effective_status  = self::get_effective_order_status( $facts, $status_intent );

			if ( $is_shipping_messaging && false === $requires_shipping ) {
				return new WP_Error(
					'yoro_invalid_action_plan_entity',
					sprintf(
						/* translators: 1: step id, 2: order id */
						__( 'Plan step "%1$s" sends shipping or delivery messaging for order #%2$d, but that order does not require shipping.', 'yoro-ai-core' ),
						sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
						$order_id
					),
					[
						'step_id'    => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
						'step_index' => $index,
						'action'     => $action,
						'order_id'   => $order_id,
					]
				);
			}

			if ( $is_shipping_messaging && in_array( $effective_status, [ 'pending', 'on-hold', 'cancelled', 'refunded', 'failed' ], true ) ) {
				return new WP_Error(
					'yoro_invalid_action_plan_contradiction',
					sprintf(
						/* translators: 1: step id, 2: status */
						__( 'Plan step "%1$s" uses shipping or delivery messaging even though the target status intent is "%2$s". Align the email with the order state first.', 'yoro-ai-core' ),
						sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
						$effective_status
					),
					[
						'step_id'       => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
						'step_index'    => $index,
						'action'        => $action,
						'status_intent' => $effective_status,
					]
				);
			}

			$tracking_detected = array_key_exists( 'tracking_detected', $facts )
				? ! empty( $facts['tracking_detected'] )
				: null;

			if ( $is_shipping_messaging && false === $tracking_detected ) {
				$warnings[] = [
					'code'    => 'shipping_message_without_tracking',
					'message' => sprintf(
						/* translators: 1: order id */
						__( 'Order #%1$d does not have a tracking signal yet, so shipping or delivery messaging may overstate fulfillment progress.', 'yoro-ai-core' ),
						$order_id
					),
					'step_id' => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
				];
			}

			if ( $is_shipping_messaging && true === $requires_shipping && '' === trim( (string) ( $facts['shipping_method'] ?? '' ) ) ) {
				$warnings[] = [
					'code'    => 'shipping_message_without_shipping_method',
					'message' => sprintf(
						/* translators: 1: order id */
						__( 'Order #%1$d does not have a shipping method yet, so shipping or delivery messaging may be premature.', 'yoro-ai-core' ),
						$order_id
					),
					'step_id' => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
				];
			}

			if ( $is_payment_messaging ) {
				if ( in_array( $effective_status, [ 'cancelled', 'refunded' ], true ) ) {
					return new WP_Error(
						'yoro_invalid_action_plan_contradiction',
						sprintf(
							/* translators: 1: step id, 2: status */
							__( 'Plan step "%1$s" asks for payment verification even though the order is already "%2$s". Align the payment message with the order lifecycle first.', 'yoro-ai-core' ),
							sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
							$effective_status
						),
						[
							'step_id'       => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
							'step_index'    => $index,
							'action'        => $action,
							'status_intent' => $effective_status,
							'order_id'      => $order_id,
						]
					);
				}

				if ( ! empty( $facts['is_paid'] ) && in_array( $effective_status, [ 'processing', 'completed' ], true ) ) {
					$warnings[] = [
						'code'    => 'payment_review_on_paid_order',
						'message' => sprintf(
							/* translators: 1: order id */
							__( 'Order #%1$d already looks paid and active, so payment-verification messaging may confuse the customer.', 'yoro-ai-core' ),
							$order_id
						),
						'step_id' => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
					];
				}
			}

			if ( $is_refund_messaging ) {
				if ( in_array( $effective_status, [ 'processing', 'completed' ], true ) ) {
					$warnings[] = [
						'code'    => 'refund_message_without_refund_state',
						'message' => sprintf(
							/* translators: 1: order id, 2: status */
							__( 'Order #%1$d is still "%2$s", so refund-oriented messaging may contradict the current lifecycle.', 'yoro-ai-core' ),
							$order_id,
							$effective_status
						),
						'step_id' => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
					];
				}

				if ( empty( $facts['is_paid'] ) && in_array( $effective_status, [ 'pending', 'failed' ], true ) ) {
					$warnings[] = [
						'code'    => 'refund_message_on_unpaid_order',
						'message' => sprintf(
							/* translators: 1: order id */
							__( 'Order #%1$d does not look paid yet, so refund-oriented messaging may be premature.', 'yoro-ai-core' ),
							$order_id
						),
						'step_id' => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
					];
				}

				if ( array_key_exists( 'remaining_refund', $facts ) && null !== $facts['remaining_refund'] && (float) $facts['remaining_refund'] <= 0 ) {
					$warnings[] = [
						'code'    => 'refund_message_without_remaining_refund',
						'message' => sprintf(
							/* translators: 1: order id */
							__( 'Order #%1$d does not appear to have any remaining refundable amount, so refund messaging may misstate what can still be returned.', 'yoro-ai-core' ),
							$order_id
						),
						'step_id' => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
					];
				}
			}
		}

		return [
			'warnings' => $warnings,
		];
	}

	private static function validate_email_recipient_requirements( $step, $index, $order_facts ) {
		$step      = is_array( $step ) ? $step : [];
		$order_ids = self::get_step_order_ids( $step );

		if ( empty( $order_ids ) ) {
			return true;
		}

		foreach ( $order_ids as $order_id ) {
			$facts = isset( $order_facts[ $order_id ] ) && is_array( $order_facts[ $order_id ] )
				? $order_facts[ $order_id ]
				: [];

			$email = sanitize_email( $facts['email'] ?? '' );

			if ( '' !== $email ) {
				continue;
			}

			return new WP_Error(
				'yoro_invalid_action_plan_entity',
				sprintf(
					/* translators: 1: step id, 2: order id */
					__( 'Plan step "%1$s" cannot send or save a customer email because order #%2$d does not have a billing email address.', 'yoro-ai-core' ),
					sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
					$order_id
				),
				[
					'step_id'    => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
					'step_index' => $index,
					'action'     => sanitize_text_field( $step['action'] ?? '' ),
					'order_id'   => $order_id,
				]
			);
		}

		return true;
	}

	private static function collect_param_placeholders( $value ) {
		$placeholders = [];

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$placeholders = array_merge(
					$placeholders,
					self::collect_param_placeholders( $item )
				);
			}

			return array_values( array_unique( $placeholders ) );
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return [];
		}

		if ( preg_match_all( '/\{\{\s*([a-zA-Z0-9_\.\-]+)\s*\}\}/', $value, $matches ) && ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $match ) {
				$placeholders[] = trim( (string) $match );
			}
		}

		return array_values( array_unique( $placeholders ) );
	}

	private static function validate_status_semantics( $step, $index, $status_history, $order_facts ) {
		$step      = is_array( $step ) ? $step : [];
		$scope_key = self::get_status_scope_key( $step );
		$status    = self::get_step_status_value( $step );
		$warnings  = [];

		if ( '' === $scope_key || '' === $status || empty( $status_history[ $scope_key ] ) ) {
			return [
				'warnings' => self::build_reopen_terminal_order_warnings( $step, $index, $order_facts ),
			];
		}

		$previous = $status_history[ $scope_key ];

		if ( $previous['status'] === $status ) {
			return new WP_Error(
				'yoro_invalid_action_plan_contradiction',
				sprintf(
					/* translators: 1: status, 2: current step id, 3: previous step id */
					__( 'Plan step "%2$s" repeats status "%1$s" for the same order scope after "%3$s". Remove the redundant status change.', 'yoro-ai-core' ),
					$status,
					sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
					$previous['step_id']
				),
				[
					'step_id'         => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
					'step_index'      => $index,
					'action'          => sanitize_text_field( $step['action'] ?? '' ),
					'previous_step_id'=> $previous['step_id'],
					'status'          => $status,
				]
			);
		}

		return new WP_Error(
			'yoro_invalid_action_plan_contradiction',
			sprintf(
				/* translators: 1: previous status, 2: new status, 3: current step id, 4: previous step id */
				__( 'Step "%3$s" changes the same order scope from "%1$s" to "%2$s" after "%4$s". Choose one target status before running this plan.', 'yoro-ai-core' ),
				$previous['status'],
				$status,
				sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
				$previous['step_id']
			),
			[
				'step_id'          => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
				'step_index'       => $index,
				'action'           => sanitize_text_field( $step['action'] ?? '' ),
				'previous_step_id' => $previous['step_id'],
				'previous_status'  => $previous['status'],
				'status'           => $status,
			]
		);
	}

	private static function build_reopen_terminal_order_warnings( $step, $index, $order_facts ) {
		$warnings  = [];
		$step      = is_array( $step ) ? $step : [];
		$next_status = self::get_step_status_value( $step );

		if ( '' === $next_status ) {
			return [];
		}

		foreach ( self::get_step_order_ids( $step ) as $order_id ) {
			$facts          = isset( $order_facts[ $order_id ] ) && is_array( $order_facts[ $order_id ] ) ? $order_facts[ $order_id ] : [];
			$current_status = sanitize_key( $facts['status'] ?? '' );

			if ( ! in_array( $current_status, [ 'completed', 'cancelled', 'refunded' ], true ) ) {
				continue;
			}

			if ( $current_status === $next_status ) {
				continue;
			}

			$warnings[] = [
				'code'    => 'reopening_terminal_order',
				'message' => sprintf(
					/* translators: 1: order id, 2: current status, 3: next status */
					__( 'Order #%1$d is currently "%2$s" but this plan changes it to "%3$s". Review whether reopening a terminal order is intentional.', 'yoro-ai-core' ),
					$order_id,
					$current_status,
					$next_status
				),
				'step_id' => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
			];
		}

		return $warnings;
	}

	private static function validate_order_entity_preconditions( $step, $index, $order_facts ) {
		$step                = is_array( $step ) ? $step : [];
		$action              = sanitize_text_field( $step['action'] ?? '' );
		$warnings            = [];
		$goal_families       = self::structured_goal_families_for_step( $step );
		$is_fulfillment_goal = in_array( 'fulfillment', $goal_families, true );

		foreach ( self::get_step_order_ids( $step ) as $order_id ) {
			$facts = isset( $order_facts[ $order_id ] ) && is_array( $order_facts[ $order_id ] )
				? $order_facts[ $order_id ]
				: [];

			if ( empty( $facts ) ) {
				continue;
			}

			$requires_shipping = array_key_exists( 'requires_shipping', $facts )
				? ! empty( $facts['requires_shipping'] )
				: null;
			$current_status     = sanitize_key( $facts['status'] ?? '' );
			$shipping_complete  = array_key_exists( 'shipping_complete', $facts ) ? ! empty( $facts['shipping_complete'] ) : null;
			$ready_to_fulfill   = array_key_exists( 'ready_to_fulfill', $facts ) ? ! empty( $facts['ready_to_fulfill'] ) : null;

			if (
				false === $requires_shipping &&
				$is_fulfillment_goal
			) {
				return new WP_Error(
					'yoro_invalid_action_plan_entity',
					sprintf(
						/* translators: 1: step id, 2: order id */
						__( 'Plan step "%1$s" expects a shippable order, but order #%2$d does not require shipping.', 'yoro-ai-core' ),
						sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
						$order_id
					),
					[
						'step_id'    => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
						'step_index' => $index,
						'action'     => $action,
						'order_id'   => $order_id,
					]
				);
			}

			if (
				$is_fulfillment_goal &&
				in_array( $current_status, [ 'cancelled', 'refunded', 'failed' ], true )
			) {
				return new WP_Error(
					'yoro_invalid_action_plan_contradiction',
					sprintf(
						/* translators: 1: step id, 2: order id, 3: status */
						__( 'Plan step "%1$s" expects active fulfillment work, but order #%2$d is already "%3$s".', 'yoro-ai-core' ),
						sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
						$order_id,
						$current_status
					),
					[
						'step_id'    => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
						'step_index' => $index,
						'action'     => $action,
						'order_id'   => $order_id,
						'status'     => $current_status,
					]
				);
			}

			if ( $is_fulfillment_goal && true === $requires_shipping && false === $shipping_complete ) {
				$warnings[] = [
					'code'    => 'fulfillment_with_incomplete_shipping_address',
					'message' => sprintf(
						/* translators: 1: order id */
						__( 'Order #%1$d still has an incomplete shipping address, so fulfillment work may block until the address is confirmed.', 'yoro-ai-core' ),
						$order_id
					),
					'step_id' => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
				];
			}

			if ( $is_fulfillment_goal && true === $requires_shipping && false === $ready_to_fulfill ) {
				$warnings[] = [
					'code'    => 'fulfillment_on_not_ready_order',
					'message' => sprintf(
						/* translators: 1: order id */
						__( 'Order #%1$d is not fully ready to fulfill yet, so this fulfillment-oriented step may be premature.', 'yoro-ai-core' ),
						$order_id
					),
					'step_id' => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
				];
			}
		}

		return [
			'warnings' => $warnings,
		];
	}

	private static function get_status_scope_key( $step ) {
		$step   = is_array( $step ) ? $step : [];
		$action = sanitize_text_field( $step['action'] ?? '' );

		if ( 'orders.update_status' !== $action ) {
			return '';
		}

		$params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = array_map( 'absint', $params['order_ids'] );
			sort( $order_ids );
			return 'order_ids:' . implode( ',', array_filter( $order_ids ) );
		}

		if ( ! empty( $params['order_id'] ) ) {
			return 'order_id:' . absint( $params['order_id'] );
		}

		return '';
	}

	private static function get_step_status_value( $step ) {
		$step   = is_array( $step ) ? $step : [];
		$action = sanitize_text_field( $step['action'] ?? '' );
		$params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];

		if ( 'orders.update_status' === $action ) {
			return sanitize_key( $params['status'] ?? '' );
		}

		return '';
	}

	private static function index_order_facts( $parsed, $plan ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$plan   = is_array( $plan ) ? $plan : [];
		$orders = [];

		if ( ! empty( $parsed['entities']['graph']['orders'] ) && is_array( $parsed['entities']['graph']['orders'] ) ) {
			foreach ( $parsed['entities']['graph']['orders'] as $order ) {
				$order = is_array( $order ) ? $order : [];
				$order_id = absint( $order['id'] ?? 0 );

				if ( $order_id <= 0 ) {
					continue;
				}

				$orders[ $order_id ] = [
					'status'            => sanitize_key( $order['status'] ?? '' ),
					'email'             => sanitize_email( $order['email'] ?? $order['billing_email'] ?? '' ),
					'requires_shipping' => array_key_exists( 'requires_shipping', $order )
						? ! empty( $order['requires_shipping'] )
						: null,
					'tracking_detected' => array_key_exists( 'tracking_detected', $order )
						? ! empty( $order['tracking_detected'] )
						: null,
					'shipping_method'   => sanitize_text_field( $order['shipping_method'] ?? '' ),
					'shipping_complete' => array_key_exists( 'shipping_complete', $order )
						? ! empty( $order['shipping_complete'] )
						: null,
					'ready_to_fulfill'  => array_key_exists( 'ready_to_fulfill', $order )
						? ! empty( $order['ready_to_fulfill'] )
						: null,
					'payment_method'    => sanitize_text_field( $order['payment_method'] ?? $order['payment_method_title'] ?? '' ),
					'is_paid'           => array_key_exists( 'is_paid', $order )
						? ! empty( $order['is_paid'] )
						: (
							( array_key_exists( 'date_paid', $order ) || array_key_exists( 'date_paid_ts', $order ) )
								? ! empty( $order['date_paid'] ?? $order['date_paid_ts'] ?? '' )
								: null
						),
					'total_refunded'    => array_key_exists( 'total_refunded', $order )
						? (float) $order['total_refunded']
						: null,
					'remaining_refund'  => array_key_exists( 'remaining', $order )
						? (float) $order['remaining']
						: ( array_key_exists( 'remaining_refund', $order ) ? (float) $order['remaining_refund'] : null ),
					'refund_count'      => array_key_exists( 'refund_count', $order )
						? absint( $order['refund_count'] )
						: null,
				];
			}
		}

		$plan_params = isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : [];
		$single_order_id = absint( $plan_params['order_id'] ?? 0 );

		if ( $single_order_id > 0 && empty( $orders[ $single_order_id ] ) ) {
			$orders[ $single_order_id ] = [
				'status'            => '',
				'email'             => '',
				'requires_shipping' => null,
				'tracking_detected' => null,
				'shipping_method'   => '',
				'shipping_complete' => null,
				'ready_to_fulfill'  => null,
				'payment_method'    => '',
				'is_paid'           => null,
				'total_refunded'    => null,
				'remaining_refund'  => null,
				'refund_count'      => null,
			];
		}

		foreach ( array_keys( $orders ) as $order_id ) {
			$orders[ $order_id ] = self::hydrate_order_facts( $order_id, $orders[ $order_id ] );
		}

		return $orders;
	}

	private static function hydrate_order_facts( $order_id, $facts ) {
		$order_id = absint( $order_id );
		$facts    = is_array( $facts ) ? $facts : [];

		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return $facts;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return $facts;
		}

		if ( empty( $facts['status'] ) ) {
			$facts['status'] = sanitize_key( $order->get_status() );
		}

		if ( empty( $facts['email'] ) ) {
			$facts['email'] = sanitize_email( $order->get_billing_email() );
		}

		if ( ! array_key_exists( 'requires_shipping', $facts ) || null === $facts['requires_shipping'] ) {
			$facts['requires_shipping'] = method_exists( $order, 'needs_shipping_address' )
				? (bool) $order->needs_shipping_address()
				: null;
		}

		if ( ! array_key_exists( 'tracking_detected', $facts ) || null === $facts['tracking_detected'] ) {
			$facts['tracking_detected'] = self::detect_order_tracking_signal( $order );
		}

		if ( ! array_key_exists( 'shipping_method', $facts ) || '' === trim( (string) $facts['shipping_method'] ) ) {
			$facts['shipping_method'] = method_exists( $order, 'get_shipping_method' )
				? sanitize_text_field( $order->get_shipping_method() )
				: '';
		}

		if ( ! array_key_exists( 'shipping_complete', $facts ) || null === $facts['shipping_complete'] ) {
			$facts['shipping_complete'] = self::detect_shipping_complete_fact( $order );
		}

		if ( ! array_key_exists( 'ready_to_fulfill', $facts ) || null === $facts['ready_to_fulfill'] ) {
			$facts['ready_to_fulfill'] = self::detect_ready_to_fulfill_fact( $order );
		}

		if ( ! array_key_exists( 'payment_method', $facts ) || '' === trim( (string) $facts['payment_method'] ) ) {
			$payment_method = method_exists( $order, 'get_payment_method_title' )
				? $order->get_payment_method_title()
				: '';

			if ( '' === trim( (string) $payment_method ) && method_exists( $order, 'get_payment_method' ) ) {
				$payment_method = $order->get_payment_method();
			}

			$facts['payment_method'] = sanitize_text_field( $payment_method );
		}

		if ( ! array_key_exists( 'is_paid', $facts ) || null === $facts['is_paid'] ) {
			if ( method_exists( $order, 'is_paid' ) ) {
				$facts['is_paid'] = (bool) $order->is_paid();
			} elseif ( method_exists( $order, 'get_date_paid' ) ) {
				$facts['is_paid'] = (bool) $order->get_date_paid();
			} else {
				$facts['is_paid'] = null;
			}
		}

		if ( ! array_key_exists( 'total_refunded', $facts ) || null === $facts['total_refunded'] ) {
			$facts['total_refunded'] = (float) $order->get_total_refunded();
		}

		if ( ! array_key_exists( 'remaining_refund', $facts ) || null === $facts['remaining_refund'] ) {
			$facts['remaining_refund'] = max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
		}

		if ( ! array_key_exists( 'refund_count', $facts ) || null === $facts['refund_count'] ) {
			$refunds = method_exists( $order, 'get_refunds' ) ? $order->get_refunds() : [];
			$facts['refund_count'] = is_array( $refunds ) ? count( $refunds ) : 0;
		}

		return $facts;
	}

	private static function validate_single_workflow_action_semantics( $action, $params, $order_facts ) {
		$action    = sanitize_text_field( $action );
		$params    = is_array( $params ) ? $params : [];
		$order_ids = [];
		$warnings  = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) );
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ absint( $params['order_id'] ) ];
		}

		if ( empty( $order_ids ) ) {
			return [ 'warnings' => [] ];
		}

		foreach ( $order_ids as $order_id ) {
			$facts          = isset( $order_facts[ $order_id ] ) && is_array( $order_facts[ $order_id ] ) ? $order_facts[ $order_id ] : [];
			$current_status = sanitize_key( $facts['status'] ?? '' );
			$requires_shipping = array_key_exists( 'requires_shipping', $facts ) ? ! empty( $facts['requires_shipping'] ) : null;

			if ( 'orders.refund_preview' === $action ) {
				if ( empty( $facts['is_paid'] ) && in_array( $current_status, [ 'pending', 'failed' ], true ) ) {
					$warnings[] = [
						'code'    => 'refund_review_on_unpaid_order',
						'message' => sprintf(
							/* translators: 1: order id */
							__( 'Order #%1$d does not look paid yet, so refund review may not lead to an actual refundable amount.', 'yoro-ai-core' ),
							$order_id
						),
						'step_id' => 'single_action',
					];
				}

				if ( array_key_exists( 'remaining_refund', $facts ) && null !== $facts['remaining_refund'] && (float) $facts['remaining_refund'] <= 0 ) {
					$warnings[] = [
						'code'    => 'refund_review_without_remaining_refund',
						'message' => sprintf(
							/* translators: 1: order id */
							__( 'Order #%1$d does not appear to have any remaining refundable amount, so refund review may be informational only.', 'yoro-ai-core' ),
							$order_id
						),
						'step_id' => 'single_action',
					];
				}
			}
		}

		return [
			'warnings' => $warnings,
		];
	}

	private static function get_step_order_ids( $step ) {
		$step   = is_array( $step ) ? $step : [];
		$params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];
		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = array_map( 'absint', $params['order_ids'] );
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ absint( $params['order_id'] ) ];
		}

		return array_values( array_filter( array_unique( $order_ids ) ) );
	}

	private static function structured_goal_families_for_step( $step ) {
		$step   = is_array( $step ) ? $step : [];
		$action = sanitize_text_field( $step['action'] ?? '' );
		$params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];
		$values = [];

		foreach ( [ 'email_intent', 'message_intent', 'communication_intent', 'goal_family', 'intent_family' ] as $key ) {
			if ( ! array_key_exists( $key, $params ) ) {
				continue;
			}

			foreach ( (array) $params[ $key ] as $value ) {
				if ( is_scalar( $value ) ) {
					$values[] = sanitize_key( (string) $value );
				}
			}
		}

		if ( ! empty( $params['template'] ) ) {
			$values[] = sanitize_key( $params['template'] );
		}

		$families = [];

		if ( 'orders.refund_preview' === $action ) {
			$families[] = 'refund';
		}

		foreach ( array_filter( $values ) as $value ) {
			if ( in_array( $value, [ 'refund', 'refund_notice', 'refund_update', 'refund_review' ], true ) ) {
				$families[] = 'refund';
			}

			if ( in_array( $value, [ 'shipping_update', 'fulfillment_update', 'delivery_update', 'tracking_update' ], true ) ) {
				$families[] = 'fulfillment';
			}

			if ( in_array( $value, [ 'payment_review', 'payment_confirmation', 'payment_mismatch', 'payment_verification' ], true ) ) {
				$families[] = 'payment_review';
			}
		}

		return array_values( array_unique( $families ) );
	}

	private static function get_effective_order_status( $facts, $status_intent = '' ) {
		$status_intent = sanitize_key( $status_intent );

		if ( '' !== $status_intent ) {
			return $status_intent;
		}

		return sanitize_key( is_array( $facts ) ? ( $facts['status'] ?? '' ) : '' );
	}

	private static function validate_workflow_goal_coherence( $steps, $order_facts ) {
		$steps  = is_array( $steps ) ? array_values( $steps ) : [];
		$goals  = [];
		$warnings = [];

		foreach ( $steps as $index => $step ) {
			$families = self::infer_goal_families_for_step( $step );

			if ( empty( $families ) ) {
				continue;
			}

			foreach ( self::get_step_order_ids( $step ) as $order_id ) {
				if ( ! isset( $goals[ $order_id ] ) ) {
					$goals[ $order_id ] = [];
				}

				foreach ( $families as $family ) {
					if ( ! isset( $goals[ $order_id ][ $family ] ) ) {
						$goals[ $order_id ][ $family ] = [
							'step_id'    => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
							'step_index' => $index,
						];
					}
				}
			}
		}

		foreach ( $goals as $order_id => $families ) {
			if ( isset( $families['refund'], $families['fulfillment'] ) ) {
				return new WP_Error(
					'yoro_invalid_action_plan_contradiction',
					sprintf(
						/* translators: 1: order id */
						__( 'This plan mixes refund-oriented and fulfillment-oriented steps for order #%1$d. Split those goals before running it.', 'yoro-ai-core' ),
						$order_id
					),
					[
						'order_id' => $order_id,
						'refund_step_id' => $families['refund']['step_id'],
						'fulfillment_step_id' => $families['fulfillment']['step_id'],
					]
				);
			}

			if ( isset( $families['payment_review'], $families['fulfillment'] ) ) {
				$warnings[] = [
					'code'    => 'payment_review_mixed_with_fulfillment_goal',
					'message' => sprintf(
						/* translators: 1: order id */
						__( 'Order #%1$d mixes payment-review and fulfillment-oriented goals in one plan. Confirm the step order and customer messaging carefully.', 'yoro-ai-core' ),
						$order_id
					),
					'step_id' => $families['payment_review']['step_id'],
				];
			}
		}

		return [
			'warnings' => $warnings,
		];
	}

	private static function infer_goal_families_for_step( $step ) {
		return self::structured_goal_families_for_step( $step );
	}

	private static function infer_latest_status_intent_before_step( $steps, $index, $fallback_step = [] ) {
		$steps = is_array( $steps ) ? array_values( $steps ) : [];

		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$status = self::get_step_status_value( $steps[ $i ] ?? [] );

			if ( '' !== $status ) {
				return $status;
			}
		}

		return self::get_step_status_value( $fallback_step );
	}

	private static function detect_order_tracking_signal( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$tracking_meta_keys = [
			'_tracking_number',
			'_wc_shipment_tracking_items',
			'_aftership_tracking_number',
			'_aftership_tracking_items',
			'_wcast_tracking_number',
			'_yith_tracking_code',
		];

		foreach ( $tracking_meta_keys as $key ) {
			$value = $order->get_meta( $key, true );

			if ( is_array( $value ) && ! empty( $value ) ) {
				return true;
			}

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private static function detect_shipping_complete_fact( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		if ( ! method_exists( $order, 'needs_shipping_address' ) || ! $order->needs_shipping_address() ) {
			return true;
		}

		$country  = trim( (string) $order->get_shipping_country() );
		$city     = trim( (string) $order->get_shipping_city() );
		$address1 = trim( (string) $order->get_shipping_address_1() );

		if ( '' === $country && '' === $city && '' === $address1 ) {
			$country  = trim( (string) $order->get_billing_country() );
			$city     = trim( (string) $order->get_billing_city() );
			$address1 = trim( (string) $order->get_billing_address_1() );
		}

		return '' !== $country && '' !== $city && '' !== $address1;
	}

	private static function detect_ready_to_fulfill_fact( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		if ( 'processing' !== sanitize_key( $order->get_status() ) ) {
			return false;
		}

		if ( method_exists( $order, 'needs_shipping_address' ) && ! $order->needs_shipping_address() ) {
			return false;
		}

		if ( ! self::detect_order_tracking_signal( $order ) ) {
			return false;
		}

		if ( false === self::detect_shipping_complete_fact( $order ) ) {
			return false;
		}

		$shipping_method = method_exists( $order, 'get_shipping_method' )
			? trim( (string) $order->get_shipping_method() )
			: '';

		if ( '' === $shipping_method ) {
			return false;
		}

		return true;
	}
}
