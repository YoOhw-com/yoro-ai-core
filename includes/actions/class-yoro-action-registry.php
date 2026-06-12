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
				'product_identifier_types' => [],
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
}
