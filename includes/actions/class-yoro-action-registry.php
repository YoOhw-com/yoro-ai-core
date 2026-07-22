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
				'effect'                => '',
				'reversibility'         => '',
				'external_side_effect'  => false,
				'target_type'           => '',
				'supports_bulk'         => false,
				'max_targets'           => 0,
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
		$action['permission'] = YoRo_Capabilities::permission_for_action( $action );
		$action['effect'] = self::normalize_effect( $action );
		$action['reversibility'] = self::normalize_reversibility( $action );
		$action['external_side_effect'] = ! empty( $action['external_side_effect'] )
			|| in_array( $action['effect'], [ 'communicate', 'financial' ], true );
		$action['target_type'] = sanitize_key( $action['target_type'] );
		$action['supports_bulk'] = ! empty( $action['supports_bulk'] ) || self::has_bulk_schema( $action['input_schema'] );
		$action['max_targets'] = max( 1, absint( $action['max_targets'] ?: $action['max_items'] ) );
		$action = YoRo_Action_Definition_Factory::prepare( $name, $action );

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

	public static function available_for_current_user() {
		return array_filter(
			self::all(),
			function ( $action ) {
				$locally_available = is_array( $action )
					&& ! empty( $action['permission'] )
					&& current_user_can( $action['permission'] );

				if ( ! $locally_available ) {
					return false;
				}

				$actor_access = class_exists( 'YoRo_WordPress_Actor' ) ? YoRo_WordPress_Actor::current_access() : [];

				if ( [] === $actor_access ) {
					return true;
				}

				return ! empty( $actor_access['assigned'] )
					&& 'active' === sanitize_key( $actor_access['status'] ?? '' )
					&& in_array( $action['permission'], (array) ( $actor_access['permissions'] ?? [] ), true );
			}
		);
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

	private static function normalize_effect( $action ) {
		$effect = sanitize_key( $action['effect'] ?? '' );

		if ( in_array( $effect, [ 'read', 'draft', 'mutate', 'communicate', 'delete', 'financial' ], true ) ) {
			return $effect;
		}

		$capabilities = isset( $action['capabilities'] ) && is_array( $action['capabilities'] ) ? $action['capabilities'] : [];

		if ( in_array( 'destructive', $capabilities, true ) ) {
			return 'delete';
		}

		if ( in_array( 'customer_communication', $capabilities, true ) && ! empty( $action['write_action'] ) ) {
			return 'communicate';
		}

		if ( in_array( 'financial', $capabilities, true ) && ! empty( $action['write_action'] ) ) {
			return 'financial';
		}

		return ! empty( $action['write_action'] ) ? 'mutate' : 'read';
	}

	private static function normalize_reversibility( $action ) {
		$value = sanitize_key( $action['reversibility'] ?? '' );

		if ( in_array( $value, [ 'full', 'partial', 'none' ], true ) ) {
			return $value;
		}

		return ! empty( $action['write_action'] ) ? 'partial' : 'full';
	}

	private static function has_bulk_schema( $schema ) {
		if ( ! is_array( $schema ) ) {
			return false;
		}

		foreach ( array_keys( $schema ) as $key ) {
			if ( preg_match( '/_ids$|^items$/', (string) $key ) ) {
				return true;
			}
		}

		return false;
	}
}
