<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Policy_Engine {

	public static function evaluate( $action, $params, $context = [] ) {

		$user  = wp_get_current_user();
		$roles = (array) $user->roles;

		$action = sanitize_text_field( $action );

		if ( empty( $action ) ) {
			return new WP_Error(
				'yoro_policy_empty_action',
				__( 'Action is missing for policy evaluation.', 'yoro-ai-core' )
			);
		}

		$registered = YoRo_Action_Registry::get( $action );

		if ( ! $registered ) {
			return new WP_Error(
				'yoro_policy_unknown_action',
				__( 'This action is not registered.', 'yoro-ai-core' )
			);
		}

		$mode = YoRo_Safety_Contract::profile();
		$policy = self::build_preset_policy( $mode, $action, $registered, $roles );

		if ( ! $policy ) {
			return true;
		}

		if ( isset( $policy['allow'] ) && ! absint( $policy['allow'] ) ) {
			return new WP_Error(
				'yoro_policy_blocked',
				__( 'This action is blocked by policy.', 'yoro-ai-core' )
			);
		}

		$source = isset( $context['source'] ) ? sanitize_key( $context['source'] ) : '';

		if ( 'ai_chat' === $source && isset( $policy['allow_ai'] ) && ! absint( $policy['allow_ai'] ) ) {
			return new WP_Error(
				'yoro_policy_ai_blocked',
				__( 'Store policy blocks this action from chat commands.', 'yoro-ai-core' )
			);
		}

		if ( 'direct_ui' === $source && isset( $policy['allow_direct'] ) && ! absint( $policy['allow_direct'] ) ) {
			return new WP_Error(
				'yoro_policy_direct_blocked',
				__( 'Store policy blocks direct execution for this action.', 'yoro-ai-core' )
			);
		}

		if (
			! empty( $registered['write_action'] ) &&
			isset( $policy['allow_write'] ) &&
			! absint( $policy['allow_write'] )
		) {
			return new WP_Error(
				'yoro_policy_write_blocked',
				__( 'Write actions are blocked by policy for your role.', 'yoro-ai-core' )
			);
		}

		$allowed_risk_levels = self::decode_allowed_risk_levels( $policy['allowed_risk_levels'] ?? '' );

		if ( ! empty( $allowed_risk_levels ) ) {
			$risk_level = sanitize_key( $registered['risk_level'] ?? 'low' );

			if ( ! in_array( $risk_level, $allowed_risk_levels, true ) ) {
				return new WP_Error(
					'yoro_policy_risk_blocked',
					sprintf(
						/* translators: %s: Value generated from store data. */
						__( 'Store policy blocks this %s-risk action for your role.', 'yoro-ai-core' ),
						$risk_level
					)
				);
			}
		}

		$max_items = isset( $policy['max_items'] ) ? absint( $policy['max_items'] ) : 0;

		if ( $max_items > 0 ) {
			$count = self::count_items( $params );

			if ( $count > $max_items ) {
				return new WP_Error(
					'yoro_policy_limit',
					sprintf(
						/* translators: %s: Value generated from store data. */
						__( 'Store policy allows at most %d items for your role.', 'yoro-ai-core' ),
						$max_items
					)
				);
			}
		}

		return true;
	}

	public static function evaluate_plan( $plan, $context = [] ) {
		if ( ! is_array( $plan ) || empty( $plan['type'] ) ) {
			return new WP_Error(
				'yoro_invalid_policy_plan',
				__( 'Invalid policy plan.', 'yoro-ai-core' )
			);
		}

		if ( 'single' === $plan['type'] ) {
			return self::evaluate(
				$plan['action'] ?? '',
				$plan['params'] ?? [],
				$context
			);
		}

		if ( 'sequence' === $plan['type'] ) {
			$steps = isset( $plan['steps'] ) && is_array( $plan['steps'] )
				? $plan['steps']
				: [];

			$items_by_action = [];
			$limits_by_action = [];

			foreach ( $steps as $step ) {
				$step_action = sanitize_text_field( $step['action'] ?? '' );
				$items_by_action[ $step_action ] = ( $items_by_action[ $step_action ] ?? 0 )
					+ self::count_items( isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [] );
				$step_policy = self::get_runtime_policy( $step['action'] ?? '', $context );
				$step_limit = isset( $step_policy['max_items'] ) ? absint( $step_policy['max_items'] ) : 0;
				$limits_by_action[ $step_action ] = $step_limit;
				$policy = self::evaluate(
					$step['action'] ?? '',
					$step['params'] ?? [],
					$context
				);

				if ( is_wp_error( $policy ) ) {
					return $policy;
				}
			}

			foreach ( $items_by_action as $step_action => $total_items ) {
				$plan_limit = absint( $limits_by_action[ $step_action ] ?? 0 );

				if ( $plan_limit > 0 && $total_items > $plan_limit ) {
					return new WP_Error(
						'yoro_policy_plan_limit',
						sprintf(
							/* translators: %s: Value generated from store data. */
							__( 'This Safety policy allows at most %d affected targets for one action across the complete plan.', 'yoro-ai-core' ),
							$plan_limit
						)
					);
				}
			}

			return true;
		}

		return new WP_Error(
			'yoro_unknown_policy_plan_type',
			__( 'Unknown policy plan type.', 'yoro-ai-core' )
		);
	}

	private static function get_policy_for_roles( $action, $roles ) {
		global $wpdb;

		$table = YoRo_DB::policies_table_name();

		foreach ( $roles as $role ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB -- Reads plugin-owned custom policy rows for the current user's roles.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE action = %s AND role = %s LIMIT 1",
					$action,
					$role
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB

			if ( $row ) {
				return $row;
			}
		}

		return null;
	}

	public static function get_runtime_policy( $action, $context = [] ) {
		$user  = wp_get_current_user();
		$roles = (array) $user->roles;

		$action = sanitize_text_field( $action );

		$registered = YoRo_Action_Registry::get( $action );

		if ( ! $registered ) {
			return [];
		}

		$mode = YoRo_Safety_Contract::profile();
		$policy = self::build_preset_policy( $mode, $action, $registered, $roles );

		return apply_filters(
			'yoro_runtime_policy',
			$policy,
			$action,
			$context
		);
	}

	public static function get_runtime_policy_for_plan( $plan, $context = [] ) {
		if ( ! is_array( $plan ) || empty( $plan['type'] ) ) {
			return [];
		}

		$runtime = [
			'requires_preview'      => 0,
			'requires_confirmation' => 0,
			'reasons'               => [],
		];

		$actions = [];

		if ( 'single' === $plan['type'] ) {
			$actions[] = $plan['action'] ?? '';
		}

		if ( 'sequence' === $plan['type'] && ! empty( $plan['steps'] ) && is_array( $plan['steps'] ) ) {
			foreach ( $plan['steps'] as $step ) {
				if ( ! empty( $step['action'] ) ) {
					$actions[] = $step['action'];
				}
			}
		}

		foreach ( array_filter( array_unique( $actions ) ) as $action ) {
			$policy = self::get_runtime_policy( $action, $context );

			if ( empty( $policy ) || ! is_array( $policy ) ) {
				continue;
			}

			if ( ! empty( $policy['requires_preview'] ) ) {
				$runtime['requires_preview'] = 1;
				$runtime['reasons'][] = sprintf(
					/* translators: %s: Value generated from store data. */
					__( 'Policy requires preview for action "%s".', 'yoro-ai-core' ),
					$action
				);
			}

			if ( ! empty( $policy['requires_confirmation'] ) ) {
				$runtime['requires_confirmation'] = 1;
				$runtime['reasons'][] = sprintf(
					/* translators: %s: Value generated from store data. */
					__( 'Policy requires confirmation for action "%s".', 'yoro-ai-core' ),
					$action
				);
			}
		}

		$runtime['reasons'] = array_values( array_unique( $runtime['reasons'] ) );

		return $runtime;
	}

	private static function count_items( $params ) {
		if ( isset( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			return count( $params['order_ids'] );
		}

		if ( isset( $params['note_ids'] ) && is_array( $params['note_ids'] ) ) {
			return count( $params['note_ids'] );
		}

		if ( isset( $params['statuses'] ) && is_array( $params['statuses'] ) ) {
			return count( $params['statuses'] );
		}

		if ( isset( $params['limit'] ) ) {
			return absint( $params['limit'] );
		}

		if ( isset( $params['order_id'] ) ) {
			return 1;
		}

		return 1;
	}

	private static function build_preset_policy( $mode, $action, $registered, $roles ) {
		$mode = sanitize_key( $mode );

		if ( ! in_array( $mode, [ 'advisory', 'assisted', 'delegated' ], true ) ) {
			$mode = 'assisted';
		}

		$can_manage_policies = current_user_can( YoRo_Capabilities::MANAGE_POLICIES );

		$risk_level   = sanitize_key( $registered['risk_level'] ?? 'low' );
		$write_action = ! empty( $registered['write_action'] );
		$max_items    = isset( $registered['max_items'] ) ? absint( $registered['max_items'] ) : 1;

		$is_financial   = YoRo_Action_Registry::has_capability( $action, 'financial' );
		$is_destructive = YoRo_Action_Registry::has_capability( $action, 'destructive' );
		$is_bulk        = YoRo_Action_Registry::has_capability( $action, 'bulk' );
		$is_email       = YoRo_Action_Registry::has_capability( $action, 'customer_communication' );
		$is_confirmation_exempt_read = 'orders.refund_preview' === $action;

		$policy = [
			'allow'                 => 1,
			'max_items'             => $max_items,
			'requires_preview'      => 0,
			'requires_confirmation' => ! empty( $registered['requires_confirmation'] ) ? 1 : 0,
			'allow_ai'              => 1,
			'allow_direct'          => 1,
			'allow_write'           => 1,
			'allowed_risk_levels'   => wp_json_encode( [ 'low', 'medium', 'high' ] ),
		];

		/*
		* Capability-level baseline.
		* These apply even in permissive mode, because they represent inherently sensitive actions.
		*/
		if ( ( $is_financial && ! $is_confirmation_exempt_read ) || $is_destructive ) {
			$policy['requires_confirmation'] = 1;
		}

		if ( $is_email && $write_action ) {
			$policy['requires_confirmation'] = 1;
		}

		if ( 'advisory' === $mode ) {
			if ( $write_action ) {
				$policy['allow'] = 0;
				$policy['allow_write'] = 0;
				$policy['allow_ai'] = 0;
				$policy['allow_direct'] = 0;
			}

			return $policy;
		}

		if ( 'assisted' === $mode ) {
			if ( $is_confirmation_exempt_read ) {
				$policy['requires_confirmation'] = 0;
				return $policy;
			}

			if ( $write_action || in_array( $risk_level, [ 'medium', 'high' ], true ) ) {
				$policy['requires_confirmation'] = 1;
			}

			if ( $is_destructive ) {
				$policy['requires_confirmation'] = 1;
			}

			if ( $is_bulk && $max_items > 20 ) {
				$policy['max_items'] = 20;
			}

			if ( ! $can_manage_policies && 'high' === $risk_level ) {
				$policy['allowed_risk_levels'] = wp_json_encode( [ 'low', 'medium' ] );
			}

			return $policy;
		}

		if ( 'delegated' === $mode ) {
			$policy['requires_confirmation'] = $write_action ? 1 : 0;
			$rule = self::get_delegation_rule( $action, $roles );

			if ( is_array( $rule ) && ! empty( $rule['allow'] ) ) {
				$policy['requires_confirmation'] = ! empty( $rule['requires_confirmation'] ) ? 1 : 0;
				$policy['max_items'] = max( 1, absint( $rule['max_targets'] ?? $policy['max_items'] ) );
			}

			return $policy;
		}

		return $policy;
	}

	private static function get_delegation_rule( $action, $roles ) {
		$rules = get_option( 'yoro_safety_delegation_rules', [] );
		$rules = is_array( $rules ) ? $rules : [];

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['enabled'] ) ) {
				continue;
			}

			if ( ! in_array( $rule['action'] ?? '*', [ '*', $action ], true ) ) {
				continue;
			}

			$rule_roles = isset( $rule['roles'] ) && is_array( $rule['roles'] ) ? $rule['roles'] : [];

			if ( ! empty( $rule_roles ) && empty( array_intersect( $rule_roles, $roles ) ) ) {
				continue;
			}

			return $rule;
		}

		return null;
	}

	private static function decode_allowed_risk_levels( $value ) {
		if ( empty( $value ) ) {
			return [];
		}

		$decoded = json_decode( (string) $value, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$allowed = [ 'low', 'medium', 'high', 'unknown' ];

		return array_values(
			array_intersect(
				array_map( 'sanitize_key', $decoded ),
				$allowed
			)
		);
	}
}
