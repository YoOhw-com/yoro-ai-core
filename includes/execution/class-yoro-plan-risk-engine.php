<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Plan_Risk_Engine {

	public static function evaluate( $plan ) {
		if ( ! is_array( $plan ) || empty( $plan['type'] ) ) {
			return [
				'risk_level'            => 'unknown',
				'requires_confirmation' => true,
				'reasons'               => [
					__( 'Invalid or unknown execution plan.', 'yoro-ai-core' ),
				],
			];
		}

		$actions = self::get_plan_actions( $plan );

		$risk_level = 'low';
		$requires_confirmation = false;
		$reasons = [];

		if ( 'sequence' === $plan['type'] ) {
			$risk_level = 'medium';

			if ( self::plan_has_write_action( $plan ) ) {
				$requires_confirmation = true;
				$reasons[] = __( 'Plan contains multiple write steps.', 'yoro-ai-core' );
			} else {
				$reasons[] = __( 'Plan contains multiple read-only steps.', 'yoro-ai-core' );
			}
		}

		foreach ( $actions as $item ) {
			$action_name = $item['action'];
			$params      = $item['params'];

			$registered = YoRo_Action_Registry::get( $action_name );

			if ( ! $registered ) {
				$requires_confirmation = true;
				$risk_level = self::max_risk( $risk_level, 'high' );
				$reasons[] = sprintf(
					__( 'Unknown action "%s".', 'yoro-ai-core' ),
					$action_name
				);
				continue;
			}

			$action_risk = $registered['risk_level'] ?? 'low';

			$risk_level = self::max_risk( $risk_level, $action_risk );

			if ( ! empty( $registered['requires_confirmation'] ) ) {
				$requires_confirmation = true;
				$reasons[] = sprintf(
					__( 'Action "%s" requires confirmation.', 'yoro-ai-core' ),
					$action_name
				);
			}

			if ( ! empty( $registered['write_action'] ) ) {
				$requires_confirmation = true;
				$reasons[] = sprintf(
					__( 'Action "%s" changes store data.', 'yoro-ai-core' ),
					$action_name
				);
			}

			$is_write_action = ! empty( $registered['write_action'] );
			$is_financial   = YoRo_Action_Registry::has_capability( $action_name, 'financial' );
			$is_destructive = YoRo_Action_Registry::has_capability( $action_name, 'destructive' );
			$is_email       = YoRo_Action_Registry::has_capability( $action_name, 'customer_communication' );

			$item_count = self::count_items( $params );

			if ( $is_write_action && $item_count > 1 ) {
				$requires_confirmation = true;
				$reasons[] = sprintf(
					__( 'Action "%1$s" affects %2$d items.', 'yoro-ai-core' ),
					$action_name,
					$item_count
				);
			}

			if ( $is_financial ) {
				$risk_level = self::max_risk( $risk_level, 'medium' );

				if ( ! empty( $registered['write_action'] ) ) {
					$requires_confirmation = true;
					$reasons[] = sprintf(
						__( 'Action "%s" changes financial information.', 'yoro-ai-core' ),
						$action_name
					);
				} else {
					$reasons[] = sprintf(
						__( 'Action "%s" reads financial information.', 'yoro-ai-core' ),
						$action_name
					);
				}
			}

			if ( $is_destructive ) {
				$requires_confirmation = true;
				$risk_level = self::max_risk( $risk_level, 'high' );
				$reasons[] = sprintf(
					__( 'Action "%s" may remove or delete data.', 'yoro-ai-core' ),
					$action_name
				);
			}

			if ( $is_email && ! empty( $registered['write_action'] ) ) {
				$requires_confirmation = true;
				$risk_level = self::max_risk( $risk_level, 'medium' );
				$reasons[] = sprintf(
					__( 'Action "%s" communicates with a customer.', 'yoro-ai-core' ),
					$action_name
				);
			}
		}

		$semantic_warnings = self::extract_semantic_warnings( $plan );

		if ( ! empty( $semantic_warnings ) ) {
			$reasons = array_values( array_unique( array_merge( $reasons, $semantic_warnings ) ) );
		}

		return [
			'risk_level'            => $risk_level,
			'requires_confirmation' => $requires_confirmation,
			'reasons'               => array_values( array_unique( $reasons ) ),
			'semantic_warnings'     => $semantic_warnings,
		];
	}

	private static function extract_semantic_warnings( $plan ) {
		if (
			empty( $plan['validation']['semantic']['warnings'] ) ||
			! is_array( $plan['validation']['semantic']['warnings'] )
		) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static function( $warning ) {
						return is_array( $warning ) ? sanitize_text_field( $warning['message'] ?? '' ) : '';
					},
					$plan['validation']['semantic']['warnings']
				)
			)
		);
	}

	private static function get_plan_actions( $plan ) {
		if ( 'single' === $plan['type'] ) {
			return [
				[
					'action' => $plan['action'] ?? '',
					'params' => isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : [],
				],
			];
		}

		if ( 'sequence' === $plan['type'] ) {
			$steps = isset( $plan['steps'] ) && is_array( $plan['steps'] )
				? $plan['steps']
				: [];

			$actions = [];

			foreach ( $steps as $step ) {
				$actions[] = [
					'action' => $step['action'] ?? '',
					'params' => isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
				];
			}

			return $actions;
		}

		return [];
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

	private static function max_risk( $current, $candidate ) {
		$rank = [
			'low'     => 1,
			'medium'  => 2,
			'high'    => 3,
			'unknown' => 4,
		];

		$current_rank   = $rank[ $current ] ?? 4;
		$candidate_rank = $rank[ $candidate ] ?? 4;

		return $candidate_rank > $current_rank ? $candidate : $current;
	}

	private static function plan_has_write_action( $plan ) {
		$actions = self::get_plan_actions( $plan );

		foreach ( $actions as $item ) {
			$registered = YoRo_Action_Registry::get( $item['action'] ?? '' );

			if ( ! empty( $registered['write_action'] ) ) {
				return true;
			}
		}

		return false;
	}
}
