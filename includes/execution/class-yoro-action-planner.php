<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Planner {

	public static function build_plan( $parsed ) {
		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'yoro_invalid_parsed_action',
				__( 'Cannot build action plan from invalid parsed result.', 'yoro-agent-core' )
			);
		}

		if ( empty( $parsed['action'] ) ) {
			return new WP_Error(
				'yoro_missing_action',
				__( 'Action is missing after intent resolution.', 'yoro-agent-core' )
			);
		}

		if ( ! empty( $parsed['actions'] ) && is_array( $parsed['actions'] ) ) {
			return self::build_sequence_plan( $parsed );
		}

		return self::build_single_plan( $parsed );
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
				__( 'No valid action steps were found.', 'yoro-agent-core' )
			);
		}

		return [
			'type'          => 'sequence',
			'action'        => sanitize_text_field( $parsed['action'] ),
			'steps'         => $steps,
			'params'        => isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [],
			'workflow'      => $parsed['workflow'] ?? [],
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
			'meta'     => isset( $step['meta'] ) && is_array( $step['meta'] ) ? $step['meta'] : [],
		];
	}
}