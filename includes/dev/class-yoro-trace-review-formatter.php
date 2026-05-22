<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Trace_Review_Formatter {

	public static function format_response( $payload ) {
		$payload   = is_array( $payload ) ? $payload : [];
		$parsed    = isset( $payload['parsed'] ) && is_array( $payload['parsed'] ) ? $payload['parsed'] : [];
		$plan      = isset( $payload['plan'] ) && is_array( $payload['plan'] ) ? $payload['plan'] : [];
		$result    = isset( $payload['result'] ) && is_array( $payload['result'] ) ? $payload['result'] : [];
		$telemetry = isset( $payload['telemetry'] ) && is_array( $payload['telemetry'] ) ? $payload['telemetry'] : [];
		$runtime   = isset( $telemetry['runtime_snapshot'] ) && is_array( $telemetry['runtime_snapshot'] ) ? $telemetry['runtime_snapshot'] : [];
		$shape     = isset( $parsed['command_shape'] ) && is_array( $parsed['command_shape'] )
			? $parsed['command_shape']
			: ( isset( $telemetry['command_shape'] ) && is_array( $telemetry['command_shape'] ) ? $telemetry['command_shape'] : [] );
		$semantic_frame = isset( $parsed['semantic_frame'] ) && is_array( $parsed['semantic_frame'] )
			? $parsed['semantic_frame']
			: ( isset( $telemetry['semantic_frame'] ) && is_array( $telemetry['semantic_frame'] ) ? $telemetry['semantic_frame'] : [] );
		$decision  = isset( $parsed['command_decision'] ) && is_array( $parsed['command_decision'] ) ? $parsed['command_decision'] : [];

		$command = sanitize_text_field(
			$parsed['original_command']
				?? $parsed['params']['original_command']
				?? $plan['params']['original_command']
				?? ''
		);

		return [
			'version' => 1,
			'steps'   => [
				[
					'key'     => 'command',
					'label'   => 'Command',
					'summary' => $command,
				],
				[
					'key'     => 'command_shape',
					'label'   => 'Command shape',
					'summary' => self::format_shape( $shape ),
					'details' => self::compact_shape( $shape ),
				],
				[
					'key'     => 'semantic_frame',
					'label'   => 'Semantic frame',
					'summary' => self::format_semantic_frame( $semantic_frame ),
					'details' => $semantic_frame,
				],
				[
					'key'     => 'ai_decision',
					'label'   => 'AI decision',
					'summary' => self::format_ai_decision( $parsed, $decision ),
					'details' => self::compact_decision( $parsed, $decision ),
				],
				[
					'key'     => 'validator_changes',
					'label'   => 'Validator changes',
					'summary' => self::format_validator_summary( $parsed, $plan ),
					'details' => self::compact_validator( $parsed, $plan ),
				],
				[
					'key'     => 'final_action',
					'label'   => 'Final action',
					'summary' => self::format_final_action( $parsed, $plan, $runtime ),
					'details' => self::compact_runtime( $runtime ),
				],
				[
					'key'     => 'result',
					'label'   => 'Result',
					'summary' => self::format_result_summary( $payload, $result ),
					'details' => self::compact_result( $result ),
				],
			],
		];
	}

	public static function format_eval_case( $case_result ) {
		$case_result = is_array( $case_result ) ? $case_result : [];
		$actual      = isset( $case_result['actual'] ) && is_array( $case_result['actual'] )
			? $case_result['actual']
			: [];
		$parsed      = isset( $actual['parsed'] ) && is_array( $actual['parsed'] )
			? $actual['parsed']
			: [];
		$plan        = isset( $actual['plan'] ) && is_array( $actual['plan'] )
			? $actual['plan']
			: [];
		$result      = isset( $actual['result'] ) && is_array( $actual['result'] )
			? $actual['result']
			: [];
		$shape       = isset( $parsed['command_shape'] ) && is_array( $parsed['command_shape'] )
			? $parsed['command_shape']
			: [];

		$lines = [
			'Command: ' . sanitize_text_field( $case_result['command'] ?? '' ),
			'Verdict: ' . ( ! empty( $case_result['passed'] ) ? 'PASS' : 'FAIL' ),
			'Shape: ' . self::format_shape( $shape ),
			'Semantic Frame: ' . self::format_semantic_frame( $parsed['semantic_frame'] ?? [] ),
			'AI Decision: action=' . sanitize_text_field( $parsed['action'] ?? '' ) .
				' intent=' . sanitize_key( $parsed['intent_family'] ?? '' ) .
				' confidence=' . sanitize_text_field( (string) ( $parsed['confidence'] ?? '' ) ),
			'Validator: missing=' . self::format_list( $parsed['missing'] ?? [] ) .
				' ambiguities=' . self::format_list( $parsed['ambiguities'] ?? [] ) .
				' params=' . self::format_params( $parsed['params'] ?? [] ),
			'Plan: type=' . sanitize_key( $plan['type'] ?? '' ) .
				' action=' . sanitize_text_field( $plan['action'] ?? '' ),
			'Execution: display_type=' . sanitize_key( $result['display_type'] ?? '' ) .
				' count=' . sanitize_text_field( (string) ( $result['count'] ?? '' ) ) .
				' result_type=' . sanitize_key( $result['result_type'] ?? '' ),
		];

		$failed_assertions = self::format_failed_assertions( $case_result['assertions'] ?? [] );

		if ( '' !== $failed_assertions ) {
			$lines[] = 'Failed: ' . $failed_assertions;
		}

		return implode( "\n", $lines );
	}

	private static function format_shape( $shape ) {
		$shape = is_array( $shape ) ? $shape : [];
		$parts = [
			'surface=' . sanitize_key( $shape['surface'] ?? '' ),
			'target_type=' . sanitize_key( $shape['target_type'] ?? '' ),
			'operation_hint=' . sanitize_key( $shape['operation_hint'] ?? '' ),
		];

		if ( ! empty( $shape['status'] ) ) {
			$parts[] = 'status=' . sanitize_key( $shape['status'] );
		}

		if ( ! empty( $shape['quantity'] ) ) {
			$parts[] = 'quantity=' . absint( $shape['quantity'] );
		}

		if ( ! empty( $shape['age_filter'] ) && is_array( $shape['age_filter'] ) ) {
			$age_filter = $shape['age_filter'];
			$parts[] = 'age_filter=' . sanitize_key( $age_filter['operator'] ?? '' ) .
				' ' . absint( $age_filter['value'] ?? 0 ) .
				' ' . sanitize_key( $age_filter['unit'] ?? '' ) .
				'/' . absint( $age_filter['hours'] ?? 0 ) . 'h';
		}

		return implode( ' ', array_filter( $parts ) );
	}

	private static function format_semantic_frame( $frame ) {
		$frame = is_array( $frame ) ? $frame : [];
		$constraints = isset( $frame['constraints'] ) && is_array( $frame['constraints'] ) ? $frame['constraints'] : [];
		$parts = [
			'surface=' . sanitize_key( $frame['surface'] ?? '' ),
			'operation=' . sanitize_key( $frame['operation'] ?? '' ),
			'target_type=' . sanitize_key( $frame['target_type'] ?? '' ),
		];

		if ( null !== ( $constraints['quantity'] ?? null ) ) {
			$parts[] = 'quantity=' . absint( $constraints['quantity'] );
		}

		if ( ! empty( $constraints['order_ids'] ) && is_array( $constraints['order_ids'] ) ) {
			$parts[] = 'order_ids=' . implode( ',', array_values( array_filter( array_map( 'absint', $constraints['order_ids'] ) ) ) );
		} elseif ( ! empty( $constraints['order_id'] ) ) {
			$parts[] = 'order_id=' . absint( $constraints['order_id'] );
		}

		if ( ! empty( $constraints['status'] ) ) {
			$parts[] = 'status=' . sanitize_key( $constraints['status'] );
		}

		if ( ! empty( $constraints['date'] ) && is_array( $constraints['date'] ) && ! empty( $constraints['date']['window'] ) ) {
			$parts[] = 'date_window=' . sanitize_key( $constraints['date']['window'] );
		}

		return implode( ' | ', array_filter( $parts ) );
	}

	private static function format_params( $params ) {
		$params = is_array( $params ) ? $params : [];

		unset( $params['original_command'] );

		if ( empty( $params ) ) {
			return '{}';
		}

		return wp_json_encode( $params );
	}

	private static function format_list( $items ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			return '[]';
		}

		return '[' . implode( ',', array_values( array_filter( array_map( 'sanitize_text_field', $items ) ) ) ) . ']';
	}

	private static function format_failed_assertions( $assertions ) {
		if ( empty( $assertions ) || ! is_array( $assertions ) ) {
			return '';
		}

		$failed = [];

		foreach ( $assertions as $path => $assertion ) {
			if ( ! is_array( $assertion ) || ! empty( $assertion['passed'] ) ) {
				continue;
			}

			$failed[] = sanitize_text_field( (string) $path ) .
				' expected=' . self::stringify_value( $assertion['expected'] ?? null ) .
				' actual=' . self::stringify_value( $assertion['actual'] ?? null );
		}

		return implode( '; ', $failed );
	}

	private static function stringify_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		return sanitize_text_field( (string) $value );
	}

	private static function stringify_warning( $warning ) {
		if ( is_array( $warning ) ) {
			if ( ! empty( $warning['message'] ) ) {
				return sanitize_text_field( $warning['message'] );
			}

			if ( ! empty( $warning['code'] ) ) {
				return sanitize_key( $warning['code'] );
			}

			return sanitize_text_field( wp_json_encode( $warning ) );
		}

		return sanitize_text_field( (string) $warning );
	}

	private static function format_ai_decision( $parsed, $decision ) {
		$action = sanitize_text_field( $decision['action'] ?? $parsed['action'] ?? '' );
		$intent = sanitize_key( $decision['intent_family'] ?? $parsed['intent_family'] ?? '' );
		$mode   = sanitize_key( $decision['mode'] ?? '' );
		$confidence = isset( $decision['confidence'] ) ? (float) $decision['confidence'] : ( isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : null );
		$parts = array_filter(
			[
				$mode ? 'mode=' . $mode : '',
				$intent ? 'intent=' . $intent : '',
				$action ? 'action=' . $action : '',
				null !== $confidence ? 'confidence=' . round( $confidence, 2 ) : '',
			]
		);

		return implode( ' ', $parts );
	}

	private static function format_validator_summary( $parsed, $plan ) {
		$semantic = isset( $plan['validation']['semantic'] ) && is_array( $plan['validation']['semantic'] )
			? $plan['validation']['semantic']
			: [];
		$passed = array_key_exists( 'passed', $semantic ) ? ( ! empty( $semantic['passed'] ) ? 'passed' : 'failed' ) : 'not_run';
		$parts = [
			'missing=' . self::format_list( $parsed['missing'] ?? [] ),
			'ambiguities=' . self::format_list( $parsed['ambiguities'] ?? [] ),
			'dropped=' . self::format_list( $parsed['dropped_param_keys'] ?? [] ),
			'semantic=' . $passed,
		];

		return implode( ' ', $parts );
	}

	private static function format_final_action( $parsed, $plan, $runtime ) {
		$action = sanitize_text_field( $plan['action'] ?? $parsed['action'] ?? '' );
		$type   = sanitize_key( $plan['type'] ?? '' );
		$flags  = [];

		foreach ( [ 'preview', 'confirm', 'requires_confirmation' ] as $key ) {
			if ( ! empty( $runtime[ $key ] ) ) {
				$flags[] = $key;
			}
		}

		return trim( ( $type ? 'plan=' . $type . ' ' : '' ) . ( $action ? 'action=' . $action : '' ) . ( $flags ? ' flags=' . implode( ',', $flags ) : '' ) );
	}

	private static function format_result_summary( $payload, $result ) {
		$stage = sanitize_key( $payload['stage'] ?? '' );
		$success = array_key_exists( 'success', $payload ) ? ( ! empty( $payload['success'] ) ? 'success' : 'failed' ) : '';
		$summary = sanitize_text_field( $result['summary'] ?? $result['message'] ?? $payload['message'] ?? '' );
		$count = isset( $result['count'] ) ? ' count=' . absint( $result['count'] ) : '';
		$display = sanitize_key( $result['display_type'] ?? '' );
		$parts = array_filter(
			[
				$stage ? 'stage=' . $stage : '',
				$success,
				$display ? 'display=' . $display : '',
				$count ? trim( $count ) : '',
				$summary,
			]
		);

		return implode( ' ', $parts );
	}

	private static function compact_shape( $shape ) {
		$shape = is_array( $shape ) ? $shape : [];

		return array_filter(
			[
				'surface'            => sanitize_key( $shape['surface'] ?? '' ),
				'target_type'        => sanitize_key( $shape['target_type'] ?? '' ),
				'operation_hint'     => sanitize_key( $shape['operation_hint'] ?? '' ),
				'quantity'           => isset( $shape['quantity'] ) ? absint( $shape['quantity'] ) : null,
				'quantity_scope'     => self::compact_assoc( $shape['quantity_scope'] ?? [] ),
				'status'             => sanitize_key( $shape['status'] ?? '' ),
				'age_filter'         => self::compact_assoc( $shape['age_filter'] ?? [] ),
				'date_filter'        => self::compact_assoc( $shape['date_filter'] ?? [] ),
				'money_filter'       => self::compact_assoc( $shape['money_filter'] ?? [] ),
				'customer_identity'  => self::compact_assoc( $shape['customer_identity'] ?? [] ),
				'payment_method'     => self::compact_assoc( $shape['payment_method'] ?? [] ),
				'shipping_state'     => self::compact_assoc( $shape['shipping_state'] ?? [] ),
				'explicit_order_ids' => isset( $shape['explicit_order_ids'] ) && is_array( $shape['explicit_order_ids'] ) ? array_map( 'absint', $shape['explicit_order_ids'] ) : [],
				'write_missing_target' => self::compact_assoc( $shape['write_missing_target'] ?? [] ),
				'signals'            => isset( $shape['signals'] ) && is_array( $shape['signals'] ) ? array_values( array_map( 'sanitize_key', $shape['signals'] ) ) : [],
			],
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function compact_decision( $parsed, $decision ) {
		return array_filter(
			[
				'mode'                 => sanitize_key( $decision['mode'] ?? '' ),
				'intent_family'        => sanitize_key( $decision['intent_family'] ?? $parsed['intent_family'] ?? '' ),
				'action'               => sanitize_text_field( $decision['action'] ?? $parsed['action'] ?? '' ),
				'confidence'           => isset( $decision['confidence'] ) ? (float) $decision['confidence'] : ( isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : null ),
				'parser_source'        => sanitize_key( $parsed['parser_source'] ?? '' ),
				'parser_rule'          => sanitize_text_field( $parsed['parser_rule'] ?? '' ),
				'resolution_path'      => sanitize_key( $parsed['resolution_path'] ?? '' ),
				'catalog_action_count' => isset( $decision['catalog_action_count'] ) ? absint( $decision['catalog_action_count'] ) : null,
			],
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function compact_validator( $parsed, $plan ) {
		$semantic = isset( $plan['validation']['semantic'] ) && is_array( $plan['validation']['semantic'] )
			? $plan['validation']['semantic']
			: [];

		return array_filter(
			[
				'missing'              => isset( $parsed['missing'] ) && is_array( $parsed['missing'] ) ? array_values( array_map( 'sanitize_text_field', $parsed['missing'] ) ) : [],
				'ambiguities'          => isset( $parsed['ambiguities'] ) && is_array( $parsed['ambiguities'] ) ? array_values( array_map( 'sanitize_text_field', $parsed['ambiguities'] ) ) : [],
				'raw_param_keys'       => isset( $parsed['raw_param_keys'] ) && is_array( $parsed['raw_param_keys'] ) ? array_values( array_map( 'sanitize_key', $parsed['raw_param_keys'] ) ) : [],
				'dropped_param_keys'   => isset( $parsed['dropped_param_keys'] ) && is_array( $parsed['dropped_param_keys'] ) ? array_values( array_map( 'sanitize_key', $parsed['dropped_param_keys'] ) ) : [],
				'params_trimmed_count' => isset( $parsed['params_trimmed_count'] ) ? absint( $parsed['params_trimmed_count'] ) : null,
				'semantic'             => array_filter(
					[
						'passed'   => array_key_exists( 'passed', $semantic ) ? (bool) $semantic['passed'] : null,
						'warnings' => isset( $semantic['warnings'] ) && is_array( $semantic['warnings'] ) ? array_values( array_map( [ __CLASS__, 'stringify_warning' ], $semantic['warnings'] ) ) : [],
					],
					static function ( $value ) {
						return null !== $value && '' !== $value && [] !== $value;
					}
				),
			],
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function compact_runtime( $runtime ) {
		$runtime = is_array( $runtime ) ? $runtime : [];

		return array_filter(
			[
				'preview'                      => ! empty( $runtime['preview'] ),
				'confirm'                      => ! empty( $runtime['confirm'] ),
				'requires_confirmation'        => ! empty( $runtime['requires_confirmation'] ),
				'policy_requires_confirmation' => ! empty( $runtime['policy_requires_confirmation'] ),
				'risk_requires_confirmation'   => ! empty( $runtime['risk_requires_confirmation'] ),
				'reason'                       => sanitize_key( $runtime['reason'] ?? '' ),
			],
			static function ( $value ) {
				return false !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function compact_result( $result ) {
		$result = is_array( $result ) ? $result : [];

		return array_filter(
			[
				'success'      => array_key_exists( 'success', $result ) ? (bool) $result['success'] : null,
				'action'       => sanitize_text_field( $result['action'] ?? '' ),
				'result_type'  => sanitize_key( $result['result_type'] ?? '' ),
				'display_type' => sanitize_key( $result['display_type'] ?? '' ),
				'count'        => isset( $result['count'] ) ? absint( $result['count'] ) : null,
				'read_only'    => array_key_exists( 'read_only', $result ) ? (bool) $result['read_only'] : null,
				'undoable'     => ! empty( $result['undoable'] ),
				'summary'      => sanitize_text_field( $result['summary'] ?? $result['message'] ?? '' ),
			],
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function compact_assoc( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return [];
		}

		$compact = [];

		foreach ( $value as $key => $item ) {
			$key = sanitize_key( (string) $key );

			if ( is_array( $item ) ) {
				$item = self::compact_assoc( $item );
			} elseif ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
				// Keep scalar machine values as-is for compact review details.
			} else {
				$item = sanitize_text_field( (string) $item );
			}

			if ( '' !== $key && '' !== $item && [] !== $item && null !== $item ) {
				$compact[ $key ] = $item;
			}
		}

		return $compact;
	}
}
