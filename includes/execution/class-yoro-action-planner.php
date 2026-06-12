<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Planner {

	public static function build_plan( $parsed ) {
		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'yoro_invalid_parsed_action',
				__( 'Cannot build action plan from invalid parsed result.', 'yoro-ai-core' )
			);
		}

		$parsed = self::apply_action_plan_contract( $parsed );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( empty( $parsed['action'] ) ) {
			return new WP_Error(
				'yoro_missing_action',
				__( 'Action is missing after intent resolution.', 'yoro-ai-core' )
			);
		}

		if ( ! empty( $parsed['actions'] ) && is_array( $parsed['actions'] ) ) {
			$plan = self::build_sequence_plan( $parsed );
		} else {
			$plan = self::build_single_plan( $parsed );
		}

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$validation = YoRo_Plan_Semantic_Validator::validate( $plan, $parsed );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( is_array( $validation ) && ! empty( $validation ) ) {
			$plan['validation'] = $validation;
		}

		return $plan;
	}

	private static function apply_action_plan_contract( $parsed ) {
		if ( empty( $parsed['action_plan'] ) || ! is_array( $parsed['action_plan'] ) ) {
			return $parsed;
		}

		$action_plan = $parsed['action_plan'];
		$type = sanitize_key( $action_plan['type'] ?? '' );
		$plan_action = sanitize_text_field( $action_plan['action'] ?? '' );
		$parsed_action = sanitize_text_field( $parsed['action'] ?? '' );

		if ( '' !== $plan_action && '' !== $parsed_action && $plan_action !== $parsed_action && 'sequence' !== $type ) {
			return new WP_Error(
				'yoro_action_plan_mismatch',
				__( 'The AI action plan does not match the routed action.', 'yoro-ai-core' )
			);
		}

		if ( 'sequence' === $type && ! empty( $action_plan['steps'] ) && is_array( $action_plan['steps'] ) ) {
			$steps = [];

			foreach ( $action_plan['steps'] as $index => $step ) {
				if ( empty( $step['action'] ) ) {
					continue;
				}

				$step_action = sanitize_text_field( $step['action'] );

				if ( empty( YoRo_Action_Registry::get( $step_action ) ) ) {
					return new WP_Error(
						'yoro_unknown_action_plan_step',
						__( 'The AI action plan included an action YoRo does not support.', 'yoro-ai-core' )
					);
				}

				$steps[] = self::normalize_sequence_step( $step, $index );
			}

			if ( ! empty( $steps ) ) {
				$parsed['actions'] = $steps;
			}
		}

		if ( 'single' === $type && '' !== $plan_action ) {
			$parsed['action'] = $plan_action;

			if ( isset( $action_plan['params'] ) && is_array( $action_plan['params'] ) ) {
				$parsed_params = isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [];
				$plan_params   = $action_plan['params'];
				$merged_params = array_merge( $parsed_params, $plan_params );

				$merged_params = YoRo_Order_Helper::apply_order_ids_to_params( $merged_params );

				$parsed['params'] = $merged_params;
				$parsed['action_plan']['params'] = $merged_params;
			}
		}

		return $parsed;
	}

	private static function build_single_plan( $parsed ) {
		return [
			'type'          => 'single',
			'action'        => sanitize_text_field( $parsed['action'] ),
			'params'        => isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [],
			'intent'        => $parsed['intent'] ?? [],
			'entities'      => $parsed['entities'] ?? [],
			'parsed'        => $parsed,
			'parser_rule'   => $parsed['parser_rule'] ?? '',
			'parser_source' => $parsed['parser_source'] ?? '',
			'confidence'    => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0,
		];
	}

	private static function build_sequence_plan( $parsed ) {
		$steps = [];

		foreach ( $parsed['actions'] as $index => $step ) {
			if ( empty( $step['action'] ) ) {
				continue;
			}

			$steps[] = self::normalize_sequence_step( $step, $index );
		}

		if ( empty( $steps ) ) {
			return new WP_Error(
				'yoro_empty_action_plan',
				__( 'No valid action steps were found.', 'yoro-ai-core' )
			);
		}

		return [
			'type'          => 'sequence',
			'action'        => sanitize_text_field( $parsed['action'] ),
			'steps'         => $steps,
			'params'        => isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [],
			'intent'        => $parsed['intent'] ?? [],
			'entities'      => $parsed['entities'] ?? [],
			'parsed'        => $parsed,
			'parser_rule'   => $parsed['parser_rule'] ?? '',
			'parser_source' => $parsed['parser_source'] ?? '',
			'confidence'    => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0,
		];
	}

	private static function normalize_sequence_step( $step, $index = 0 ) {
		$action = sanitize_text_field( $step['action'] ?? '' );
		$meta = isset( $step['meta'] ) && is_array( $step['meta'] ) ? $step['meta'] : [];

		return [
			'id'       => ! empty( $step['id'] )
				? sanitize_key( $step['id'] )
				: 'step_' . ( absint( $index ) + 1 ),
			'label'    => ! empty( $step['label'] )
				? sanitize_text_field( $step['label'] )
				: $action,
			'action'   => $action,
			'params'   => isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
			'when'     => isset( $step['when'] ) && is_array( $step['when'] ) ? $step['when'] : [],
			'capture'  => isset( $step['capture'] ) && is_array( $step['capture'] ) ? $step['capture'] : [],
			'on_error' => ! empty( $step['on_error'] ) ? sanitize_key( $step['on_error'] ) : 'stop',
			'retry'    => isset( $step['retry'] ) && is_array( $step['retry'] )
				? [
					'max_attempts' => isset( $step['retry']['max_attempts'] ) ? max( 1, absint( $step['retry']['max_attempts'] ) ) : 1,
				]
				: [
					'max_attempts' => 1,
			],
			'rollback' => isset( $step['rollback'] ) && is_array( $step['rollback'] ) ? $step['rollback'] : [],
			'meta'     => $meta,
			'user_content_slots' => self::normalize_user_content_slots( $step['user_content_slots'] ?? ( $meta['user_content_slots'] ?? [] ) ),
		];
	}

	private static function normalize_user_content_slots( $slots ) {
		if ( empty( $slots ) || ! is_array( $slots ) ) {
			return [];
		}

		$normalized = [];

		foreach ( array_values( $slots ) as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$value = array_key_exists( 'value', $slot ) && null !== $slot['value']
				? sanitize_textarea_field( (string) $slot['value'] )
				: null;
			$field = sanitize_key( $slot['field'] ?? '' );

			if ( '' === $field && null === $value ) {
				continue;
			}

			$item = [
				'operation_id' => sanitize_key( $slot['operation_id'] ?? '' ),
				'operation_key' => sanitize_key( $slot['operation_key'] ?? '' ),
				'action' => sanitize_text_field( $slot['action'] ?? '' ),
				'field' => $field,
				'value' => $value,
				'content_scope' => sanitize_key( $slot['content_scope'] ?? '' ),
				'provenance' => sanitize_key( $slot['provenance'] ?? '' ),
				'source_text_from_latest_user_message' => sanitize_textarea_field( $slot['source_text_from_latest_user_message'] ?? '' ),
				'source_text' => sanitize_textarea_field( $slot['source_text'] ?? '' ),
				'confidence' => isset( $slot['confidence'] ) ? max( 0, min( 1, (float) $slot['confidence'] ) ) : null,
			];

			$normalized[] = array_filter(
				$item,
				static function ( $value ) {
					return null !== $value && '' !== $value && [] !== $value;
				}
			);
		}

		return $normalized;
	}
}
