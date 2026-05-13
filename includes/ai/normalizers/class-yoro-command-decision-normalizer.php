<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Command_Decision_Normalizer {

	public static function schema( $action_catalog = [] ) {
		$param_properties = self::build_param_schema_properties( $action_catalog );

		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'mode' => [
					'type' => 'string',
					'enum' => [ 'task_request', 'clarify', 'chat_only', 'unsupported_request', 'out_of_scope' ],
				],
				'language' => [ 'type' => 'string' ],
				'action' => [ 'type' => 'string' ],
				'params' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $param_properties,
					'required'             => array_keys( $param_properties ),
				],
				'confidence' => [ 'type' => 'number' ],
				'missing' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'ambiguities' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'evidence' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'matched_terms' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'referenced_context' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'notes' => [ 'type' => 'string' ],
					],
					'required'             => [ 'matched_terms', 'referenced_context', 'notes' ],
				],
				'clarification_question' => [ 'type' => 'string' ],
				'summary' => [ 'type' => 'string' ],
				'reasoning_summary' => [ 'type' => 'string' ],
				'suggestions' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'label'   => [ 'type' => 'string' ],
							'command' => [ 'type' => 'string' ],
						],
						'required' => [ 'label', 'command' ],
					],
				],
			],
			'required'             => [ 'mode', 'language', 'action', 'params', 'confidence', 'missing', 'ambiguities', 'evidence', 'clarification_question', 'summary', 'reasoning_summary', 'suggestions' ],
		];
	}

	private static function build_param_schema_properties( $action_catalog ) {
		$properties = [];

		if ( ! is_array( $action_catalog ) ) {
			return $properties;
		}

		foreach ( $action_catalog as $item ) {
			if ( empty( $item['input_schema'] ) || ! is_array( $item['input_schema'] ) ) {
				continue;
			}

			foreach ( $item['input_schema'] as $param => $type ) {
				$param = sanitize_key( $param );

				if ( '' === $param || isset( $properties[ $param ] ) ) {
					continue;
				}

				$properties[ $param ] = self::json_schema_for_param_type( $type );
			}
		}

		return $properties;
	}

	private static function json_schema_for_param_type( $type ) {
		$type = strtolower( trim( (string) $type ) );

		if ( in_array( $type, [ 'integer', 'int' ], true ) ) {
			return [ 'type' => [ 'integer', 'null' ] ];
		}

		if ( in_array( $type, [ 'float', 'number' ], true ) ) {
			return [ 'type' => [ 'number', 'null' ] ];
		}

		if ( in_array( $type, [ 'boolean', 'bool' ], true ) ) {
			return [ 'type' => [ 'boolean', 'null' ] ];
		}

		if ( in_array( $type, [ 'array<int>', 'array<integer>' ], true ) ) {
			return [
				'type'  => [ 'array', 'null' ],
				'items' => [ 'type' => 'integer' ],
			];
		}

		if ( 'array<string>' === $type ) {
			return [
				'type'  => [ 'array', 'null' ],
				'items' => [ 'type' => 'string' ],
			];
		}

		if ( in_array( $type, [ 'string|array', 'array|string' ], true ) ) {
			return [
				'anyOf' => [
					[ 'type' => 'string' ],
					[
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					[ 'type' => 'null' ],
				],
			];
		}

		if ( 'array' === $type ) {
			return [
				'type'  => [ 'array', 'null' ],
				'items' => [ 'type' => [ 'string', 'number', 'boolean', 'null' ] ],
			];
		}

		return [ 'type' => [ 'string', 'null' ] ];
	}

	public static function normalize( $decision, $action_catalog = [] ) {
		if ( ! is_array( $decision ) ) {
			return new WP_Error(
				'yoro_invalid_command_decision',
				__( 'Invalid command decision.', 'yoro-agent-core' )
			);
		}

		$mode = sanitize_key( $decision['mode'] ?? 'clarify' );

		if ( ! in_array( $mode, [ 'task_request', 'clarify', 'chat_only', 'unsupported_request', 'out_of_scope' ], true ) ) {
			$mode = 'clarify';
		}

		$action = sanitize_text_field( $decision['action'] ?? '' );
		$catalog_item = self::find_catalog_item( $action, $action_catalog );

		if ( 'task_request' === $mode && ! $catalog_item ) {
			return new WP_Error(
				'yoro_unknown_command_decision_action',
				__( 'AI selected an action that is not available.', 'yoro-agent-core' )
			);
		}

		$params = isset( $decision['params'] ) && is_array( $decision['params'] )
			? $decision['params']
			: [];

		if ( $catalog_item ) {
			$params = self::filter_params_for_action( $params, $catalog_item );
		}

		return [
			'mode'                   => $mode,
			'language'               => self::normalize_language( $decision['language'] ?? '' ),
			'action'                 => $action,
			'params'                 => $params,
			'confidence'             => self::normalize_confidence( $decision['confidence'] ?? 0 ),
			'missing'                => self::normalize_string_list( $decision['missing'] ?? [] ),
			'ambiguities'            => self::normalize_string_list( $decision['ambiguities'] ?? [] ),
			'evidence'               => isset( $decision['evidence'] ) && is_array( $decision['evidence'] ) ? $decision['evidence'] : [],
			'clarification_question' => self::normalize_response_copy( $decision['clarification_question'] ?? '', $mode ),
			'summary'                => self::normalize_response_copy( $decision['summary'] ?? '', $mode ),
			'reasoning_summary'      => self::normalize_response_copy( $decision['reasoning_summary'] ?? '', $mode ),
			'suggestions'            => self::normalize_suggestions( $decision['suggestions'] ?? [] ),
			'action_catalog_item'    => $catalog_item ?: [],
		];
	}

	private static function normalize_response_copy( $text, $mode = '' ) {
		$text = sanitize_text_field( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		$had_classifier_intro = (bool) preg_match( '/^Chat mode activated(?:\s+for\s+casual\s+conversation)?\.?\s*/i', $text );
		$patterns = [
			'/^Chat mode activated(?:\s+for\s+casual\s+conversation)?\.?\s*/i' => '',
			'/^User asked\b[:\s-]*/i'                => 'You asked ',
			'/^The user asked\b[:\s-]*/i'            => 'You asked ',
			'/^User asks\b[:\s-]*/i'                 => 'You asked ',
			'/^The user asks\b[:\s-]*/i'             => 'You asked ',
			'/^User requested\b[:\s-]*/i'            => 'You asked me to ',
			'/^The user requested\b[:\s-]*/i'        => 'You asked me to ',
			'/^User requests\b[:\s-]*/i'             => 'You asked me to ',
			'/^The user requests\b[:\s-]*/i'         => 'You asked me to ',
			'/^User is asking\b[:\s-]*/i'            => 'You asked ',
			'/^The user is asking\b[:\s-]*/i'        => 'You asked ',
			'/\bthe user\b/i'                        => 'you',
			'/\bUser\b/'                             => 'You',
		];

		foreach ( $patterns as $pattern => $replacement ) {
			$text = preg_replace( $pattern, $replacement, $text );
		}

		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( 'chat_only' === $mode && preg_match( '/\bidentity information\b|\bno WooCommerce task\b/i', $text ) ) {
			return __( 'I don’t know who you are from this chat alone. I can use store context when you ask about orders, customers, or operations.', 'yoro-agent-core' );
		}

		if ( in_array( $mode, [ 'chat_only', 'out_of_scope' ], true ) && preg_match( '/\bcasual conversation\b|\bchat mode\b|\bclassifier\b/i', $text ) ) {
			$text = '';
		}

		if ( 'chat_only' === $mode && $had_classifier_intro && preg_match( '/^I can help with WooCommerce store operations\.?$/i', $text ) ) {
			return __( 'Hi. I can help with WooCommerce store operations.', 'yoro-agent-core' );
		}

		if ( '' === $text ) {
			if ( 'chat_only' === $mode ) {
				return __( 'Hi. I can help with WooCommerce store operations.', 'yoro-agent-core' );
			}

			if ( 'out_of_scope' === $mode ) {
				return __( 'I’m focused on WooCommerce store operations, so I can’t help with that topic here.', 'yoro-agent-core' );
			}

			return '';
		}

		return sanitize_text_field( $text );
	}

	private static function find_catalog_item( $action, $catalog ) {
		if ( '' === $action || ! is_array( $catalog ) ) {
			return null;
		}

		foreach ( $catalog as $item ) {
			if ( is_array( $item ) && sanitize_text_field( $item['name'] ?? '' ) === $action ) {
				return $item;
			}
		}

		return null;
	}

	private static function filter_params_for_action( $params, $catalog_item ) {
		$schema = isset( $catalog_item['input_schema'] ) && is_array( $catalog_item['input_schema'] )
			? $catalog_item['input_schema']
			: [];

		if ( empty( $schema ) ) {
			return [];
		}

		$filtered = [];

		foreach ( $schema as $key => $type ) {
			if ( array_key_exists( $key, $params ) && null !== $params[ $key ] ) {
				$filtered[ $key ] = self::normalize_param_value( $params[ $key ], $type, $key );
			}
		}

		if ( isset( $schema['focus'] ) && empty( $filtered['focus'] ) ) {
			$filtered['focus'] = 'overview';
		}

		return $filtered;
	}

	private static function normalize_param_value( $value, $type, $param = '' ) {
		$type = strtolower( trim( (string) $type ) );
		$param = sanitize_key( $param );

		if ( 'window' === $param ) {
			return self::normalize_window_param( $value );
		}

		if ( 'focus' === $param ) {
			return self::normalize_focus_param( $value );
		}

		if ( in_array( $type, [ 'integer', 'int' ], true ) ) {
			return absint( $value );
		}

		if ( in_array( $type, [ 'float', 'number' ], true ) ) {
			return (float) $value;
		}

		if ( in_array( $type, [ 'boolean', 'bool' ], true ) ) {
			return ! empty( $value );
		}

		if ( in_array( $type, [ 'array<int>', 'array<integer>' ], true ) ) {
			return is_array( $value )
				? array_values( array_filter( array_unique( array_map( 'absint', $value ) ) ) )
				: [];
		}

		if ( in_array( $type, [ 'array<string>', 'string|array', 'array|string' ], true ) ) {
			if ( is_array( $value ) ) {
				return array_values(
					array_filter(
						array_map(
							function ( $item ) {
								return is_scalar( $item ) ? sanitize_text_field( $item ) : '';
							},
							$value
						)
					)
				);
			}

			return sanitize_text_field( $value );
		}

		if ( 'array' === $type ) {
			return is_array( $value ) ? $value : [];
		}

		return is_scalar( $value ) ? sanitize_text_field( $value ) : '';
	}

	private static function normalize_window_param( $value ) {
		$value = strtolower( sanitize_text_field( is_scalar( $value ) ? $value : '' ) );

		if ( '' === $value ) {
			return '';
		}

		$map = [
			'today'        => 'today',
			'this day'     => 'today',
			'yesterday'    => 'yesterday',
			'this week'    => 'last_7_days',
			'this_week'    => 'last_7_days',
			'week'         => 'last_7_days',
			'last week'    => 'last_7_days',
			'past week'    => 'last_7_days',
			'last 7 days'  => 'last_7_days',
			'7 days'       => 'last_7_days',
			'this month'   => 'last_30_days',
			'this_month'   => 'last_30_days',
			'month'        => 'last_30_days',
			'last month'   => 'last_30_days',
			'past month'   => 'last_30_days',
			'last 30 days' => 'last_30_days',
			'30 days'      => 'last_30_days',
		];

		if ( isset( $map[ $value ] ) ) {
			return $map[ $value ];
		}

		return sanitize_key( str_replace( ' ', '_', $value ) );
	}

	private static function normalize_focus_param( $value ) {
		$value = strtolower( sanitize_text_field( is_scalar( $value ) ? $value : '' ) );

		if ( '' === $value ) {
			return 'overview';
		}

		if ( in_array( $value, [ 'business', 'performance', 'overall', 'general', 'summary', 'all', 'everything', 'this_week', 'this_month', 'week', 'month' ], true ) ) {
			return 'overview';
		}

		return sanitize_key( str_replace( ' ', '_', $value ) );
	}

	private static function normalize_language( $language ) {
		$language = strtolower( sanitize_text_field( $language ) );

		return '' !== $language ? substr( $language, 0, 12 ) : 'unknown';
	}

	private static function normalize_confidence( $confidence ) {
		$confidence = (float) $confidence;

		return max( 0, min( $confidence, 1 ) );
	}

	private static function normalize_string_list( $items ) {
		if ( ! is_array( $items ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_unique(
					array_map(
						function ( $item ) {
							return is_scalar( $item ) ? sanitize_text_field( $item ) : '';
						},
						$items
					)
				)
			)
		);
	}

	private static function normalize_suggestions( $items ) {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$suggestions = [];

		foreach ( array_slice( $items, 0, 3 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( $item['label'] ?? '' );
			$command = sanitize_text_field( $item['command'] ?? '' );

			if ( '' === $label || '' === $command ) {
				continue;
			}

			$suggestions[] = [
				'label'   => $label,
				'command' => $command,
			];
		}

		return $suggestions;
	}
}
