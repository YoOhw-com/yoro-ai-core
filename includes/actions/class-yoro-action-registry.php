<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Registry {

	private static $actions = [];

	public static function init() {
		self::$actions = [];
	}

	public static function register( $name, $config ) {
		if ( empty( $name ) || ! is_array( $config ) ) {
			return;
		}

		$action = wp_parse_args(
			$config,
			[
				'label'                 => '',
				'description'           => '',
				'category'              => 'general',
				'permission'            => 'manage_options',
				'callback'              => null,
				'preview_callback'      => null,
				'input_schema'          => [],
				'output_schema'         => [],
				'requires_confirmation' => false,
				'risk_level'            => 'low',
				'write_action'          => false,
				'max_items'             => 1,
				'capabilities'          => [],
				'ai_exposed'            => true,
				'ai_description'        => '',
				'ai_examples'           => [],
				'required_params'       => [],
				'param_hints'           => [],
				'clarification_hints'   => [],
			]
		);

		$action['capabilities'] = isset( $action['capabilities'] ) && is_array( $action['capabilities'] )
			? array_values(
				array_filter(
					array_unique(
						array_map( 'sanitize_key', $action['capabilities'] )
					)
				)
			)
			: [];

		$action['ai_exposed'] = ! empty( $action['ai_exposed'] );
		$action['ai_description'] = sanitize_text_field( $action['ai_description'] );
		$action['ai_examples'] = self::normalize_string_list( $action['ai_examples'] );
		$action['required_params'] = self::normalize_param_list( $action['required_params'] );
		$action['param_hints'] = isset( $action['param_hints'] ) && is_array( $action['param_hints'] )
			? self::normalize_assoc_string_map( $action['param_hints'] )
			: [];
		$action['clarification_hints'] = isset( $action['clarification_hints'] ) && is_array( $action['clarification_hints'] )
			? self::normalize_assoc_string_map( $action['clarification_hints'] )
			: [];

		self::$actions[ $name ] = $action;
	}

	public static function get( $name ) {
		return self::$actions[ $name ] ?? null;
	}

	public static function all() {
		return apply_filters( 'yoro_action_registry', self::$actions );
	}

	public static function get_ai_param_schema() {
		$properties = [];

		foreach ( self::all() as $action_name => $config ) {
			$input_schema = isset( $config['input_schema'] ) && is_array( $config['input_schema'] )
				? $config['input_schema']
				: [];

			foreach ( $input_schema as $param => $type ) {
				$param = sanitize_key( $param );

				if ( empty( $param ) || isset( $properties[ $param ] ) ) {
					continue;
				}

				$properties[ $param ] = self::convert_input_type_to_json_schema( $type );
			}
		}

		return [
			'properties' => $properties,
			'required'   => array_keys( $properties ),
		];
	}

	private static function convert_input_type_to_json_schema( $type ) {
		$type = is_string( $type ) ? strtolower( trim( $type ) ) : '';

		switch ( $type ) {
			case 'integer':
			case 'int':
				return [
					'type' => 'integer',
				];

			case 'float':
			case 'number':
				return [
					'type' => 'number',
				];

			case 'boolean':
			case 'bool':
				return [
					'type' => 'boolean',
				];

			case 'array<int>':
			case 'array<integer>':
				return [
					'type'  => 'array',
					'items' => [
						'type' => 'integer',
					],
				];

			case 'array<string>':
				return [
					'type'  => 'array',
					'items' => [
						'type' => 'string',
					],
				];

			case 'array':
				return [
					'type'  => 'array',
					'items' => [
						'type' => 'object',
					],
				];

			case 'string|array':
			case 'array|string':
				return [
					'anyOf' => [
						[
							'type' => 'string',
						],
						[
							'type'  => 'array',
							'items' => [
								'type' => 'string',
							],
						],
					],
				];

			case 'string':
			default:
				return [
					'type' => 'string',
				];
		}
	}

	private static function normalize_string_list( $items ) {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $items as $item ) {
			$item = sanitize_text_field( $item );

			if ( '' !== $item ) {
				$normalized[] = $item;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	private static function normalize_param_list( $items ) {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $items as $item ) {
			$item = sanitize_key( $item );

			if ( '' !== $item ) {
				$normalized[] = $item;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	private static function normalize_assoc_string_map( $map ) {
		$normalized = [];

		foreach ( $map as $key => $value ) {
			$key = sanitize_key( $key );

			if ( '' === $key ) {
				continue;
			}

			$normalized[ $key ] = sanitize_text_field( $value );
		}

		return $normalized;
	}

	public static function has_capability( $action_name, $capability ) {
		$action = self::get( $action_name );

		if ( ! $action ) {
			return false;
		}

		$capabilities = isset( $action['capabilities'] ) && is_array( $action['capabilities'] )
			? $action['capabilities']
			: [];

		return in_array(
			sanitize_key( $capability ),
			$capabilities,
			true
		);
	}

	public static function get_ai_metadata_audit() {
		$audit = [];

		foreach ( self::all() as $name => $action ) {
			if ( empty( $action['ai_exposed'] ) ) {
				continue;
			}

			$missing = self::get_missing_ai_metadata_fields( $action );
			$scope   = self::get_ai_metadata_audit_scope( $name, $action );
			$enforce = 'core' === $scope;

			$audit[ $name ] = [
				'action'                       => sanitize_text_field( $name ),
				'ai_exposed'                   => true,
				'audit_scope'                  => $scope,
				'enforce_metadata'             => $enforce,
				'missing_fields'               => $missing,
				'passed'                       => empty( $missing ) || ! $enforce,
				'uses_inferred_required_params' => empty( $action['required_params'] ),
			];
		}

		return $audit;
	}

	private static function get_ai_metadata_audit_scope( $name, $action ) {
		$name   = is_string( $name ) ? strtolower( trim( $name ) ) : '';
		$action = is_array( $action ) ? $action : [];

		if ( ! empty( $action['ai_metadata_audit_scope'] ) ) {
			return sanitize_key( $action['ai_metadata_audit_scope'] );
		}

		if ( 0 === strpos( $name, 'custom.' ) ) {
			return 'custom';
		}

		return 'core';
	}

	private static function get_missing_ai_metadata_fields( $action ) {
		$action = is_array( $action ) ? $action : [];
		$missing = [];

		if ( '' === trim( (string) ( $action['ai_description'] ?? '' ) ) ) {
			$missing[] = 'ai_description';
		}

		if ( ! array_key_exists( 'required_params', $action ) || ! is_array( $action['required_params'] ) ) {
			$missing[] = 'required_params';
		}

		if ( empty( $action['ai_examples'] ) || ! is_array( $action['ai_examples'] ) ) {
			$missing[] = 'ai_examples';
		}

		if ( ! array_key_exists( 'param_hints', $action ) || ! is_array( $action['param_hints'] ) ) {
			$missing[] = 'param_hints';
		}

		if ( ! array_key_exists( 'clarification_hints', $action ) || ! is_array( $action['clarification_hints'] ) ) {
			$missing[] = 'clarification_hints';
		}

		return $missing;
	}
}
