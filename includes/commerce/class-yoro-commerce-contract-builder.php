<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Commerce_Contract_Builder {

	const CONNECTOR_KEY = 'woocommerce';
	const SCHEMA_VERSION = 1;
	const MANIFEST_TTL = 30 * DAY_IN_SECONDS;
	const CONTEXT_TTL = 20 * MINUTE_IN_SECONDS;

	public static function build_capability_manifest( array $runtime, $revision ) {
		$connection_id = self::connection_id( $runtime );

		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		$captured_at = time();
		$actions = [];

		foreach ( YoRo_Action_Registry::all() as $name => $action ) {
			if ( ! is_array( $action ) || empty( $action['ai_exposed'] ) ) {
				continue;
			}

			$canonical = self::canonical_action( $name, $action );

			if ( is_array( $canonical ) ) {
				$actions[] = $canonical;
			}
		}

		usort(
			$actions,
			static function ( $left, $right ) {
				return strcmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) );
			}
		);

		return [
			'contract_type'          => 'yoro.commerce.capability_manifest',
			'schema_version'         => self::SCHEMA_VERSION,
			'manifest_id'            => self::manifest_id( $connection_id ),
			'revision'               => max( 1, absint( $revision ) ),
			'connector_key'          => self::CONNECTOR_KEY,
			'connector_connection_id' => $connection_id,
			'generated_at'           => gmdate( 'c', $captured_at ),
			'expires_at'             => gmdate( 'c', $captured_at + self::MANIFEST_TTL ),
			'actions'                => $actions,
			'extensions'             => [
				'woocommerce.runtime' => [
					'plugin_version' => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
					'execution_boundary' => 'plugin_executor',
				],
			],
		];
	}

	public static function build_context_pack( array $runtime, array $manifest, $revision ) {
		$connection_id = self::connection_id( $runtime );

		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		$captured_at = time();
		$context = YoRo_Context_Provider::get_context();
		$store = is_array( $context['store'] ?? null ) ? $context['store'] : [];
		$woocommerce = is_array( $context['woocommerce'] ?? null ) ? $context['woocommerce'] : [];
		$wordpress = is_array( $context['wordpress'] ?? null ) ? $context['wordpress'] : [];

		return [
			'contract_type'          => 'yoro.commerce.context_pack',
			'schema_version'         => self::SCHEMA_VERSION,
			'context_pack_id'        => self::context_pack_id( $connection_id ),
			'revision'               => max( 1, absint( $revision ) ),
			'connector_key'          => self::CONNECTOR_KEY,
			'connector_connection_id' => $connection_id,
			'manifest_id'            => sanitize_text_field( $manifest['manifest_id'] ?? '' ),
			'manifest_revision'      => max( 1, absint( $manifest['revision'] ?? 0 ) ),
			'captured_at'            => gmdate( 'c', $captured_at ),
			'expires_at'             => gmdate( 'c', $captured_at + self::CONTEXT_TTL ),
			'domains'                => [
				[
					'name'        => 'store',
					'source'      => 'woocommerce_live_settings',
					'captured_at' => gmdate( 'c', $captured_at ),
					'data'        => [
						'name'              => sanitize_text_field( $store['name'] ?? get_bloginfo( 'name' ) ),
						'timezone'          => sanitize_text_field( $store['timezone'] ?? wp_timezone_string() ),
						'locale'            => sanitize_text_field( $store['locale'] ?? get_locale() ),
						'currency'          => sanitize_text_field( $woocommerce['currency'] ?? '' ),
						'currency_symbol'   => sanitize_text_field( $woocommerce['currency_symbol'] ?? '' ),
						'order_statuses'    => self::safe_list( $woocommerce['order_statuses'] ?? [] ),
						'payment_methods'   => self::safe_list( $woocommerce['payment_gateways'] ?? [] ),
					],
					'extensions'  => [
						'woocommerce.settings' => [
							'source_version' => 1,
							'hpos_enabled'   => ! empty( $woocommerce['hpos_enabled'] ),
						],
					],
				],
				[
					'name'        => 'integrations',
					'source'      => 'connector_runtime',
					'captured_at' => gmdate( 'c', $captured_at ),
					'data'        => [
						'connector_version'        => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
						'platform_version'         => sanitize_text_field( $wordpress['version'] ?? '' ),
						'commerce_platform_active' => ! empty( $woocommerce['active'] ),
						'commerce_platform_version' => sanitize_text_field( $woocommerce['version'] ?? '' ),
					],
					'extensions'  => [
						'wordpress.runtime' => [
							'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(),
						],
						'woocommerce.runtime' => [
							'hpos_enabled' => ! empty( $woocommerce['hpos_enabled'] ),
						],
					],
				],
			],
		];
	}

	public static function manifest_id( $connection_id ) {
		return 'manifest_woocommerce_' . substr( hash( 'sha256', (string) $connection_id ), 0, 40 );
	}

	public static function context_pack_id( $connection_id ) {
		return 'context_woocommerce_' . substr( hash( 'sha256', (string) $connection_id ), 0, 40 );
	}

	private static function canonical_action( $name, array $action ) {
		$name = sanitize_text_field( $name );
		$input_schema = self::object_schema(
			$action['input_schema'] ?? [],
			$action['required_params'] ?? []
		);

		if ( '' === $name || is_wp_error( $input_schema ) ) {
			return null;
		}

		$effect = sanitize_key( $action['effect'] ?? ( ! empty( $action['write_action'] ) ? 'mutate' : 'read' ) );
		$risk_level = sanitize_key( $action['risk_level'] ?? 'low' );
		$reversibility = sanitize_key( $action['reversibility'] ?? ( ! empty( $action['write_action'] ) ? 'partial' : 'full' ) );
		$permission = sanitize_key( $action['permission'] ?? '' );

		if ( ! in_array( $effect, [ 'read', 'draft', 'mutate', 'communicate', 'delete', 'financial' ], true )
			|| ! in_array( $risk_level, [ 'low', 'medium', 'high', 'critical' ], true )
			|| ! in_array( $reversibility, [ 'full', 'partial', 'none' ], true )
			|| '' === $permission ) {
			return null;
		}

		return [
			'name'                  => $name,
			'label'                 => sanitize_text_field( $action['label'] ?? $name ) ?: $name,
			'description'           => sanitize_text_field( $action['ai_description'] ?? ( $action['description'] ?? $name ) ) ?: $name,
			'effect'                => $effect,
			'risk_level'            => $risk_level,
			'reversibility'         => $reversibility,
			'external_side_effect'  => ! empty( $action['external_side_effect'] ),
			'write_action'          => ! in_array( $effect, [ 'read', 'draft' ], true ),
			'requires_confirmation' => ! empty( $action['requires_confirmation'] ) || ! empty( $action['write_action'] ),
			'max_targets'           => max( 1, absint( $action['max_targets'] ?? ( $action['max_items'] ?? 1 ) ) ),
			'input_schema'          => $input_schema,
			'output_schema'         => self::object_schema( $action['output_schema'] ?? [], [] ),
			'required_params'       => $input_schema['required'],
			'required_permissions'  => [ $permission ],
			'extensions'            => [
				'woocommerce.action' => [
					'registry_action' => $name,
				],
			],
		];
	}

	private static function object_schema( $schema, $required ) {
		$properties = [];

		foreach ( (array) $schema as $field => $definition ) {
			$field = sanitize_key( $field );

			if ( '' === $field ) {
				continue;
			}

			if ( is_array( $definition ) ) {
				$type = $definition['type'] ?? 'string';
				$properties[ $field ] = array_merge( $definition, self::schema_definition( $type ) );
			} else {
				$properties[ $field ] = self::schema_definition( $definition );
			}
		}

		$required = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $required ) ) ) );

		if ( array_diff( $required, array_keys( $properties ) ) ) {
			return new WP_Error( 'yoro_connector_invalid_action_schema', 'Required action parameters must exist in the input schema.' );
		}

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		];
	}

	private static function schema_definition( $legacy_type ) {
		$legacy_type = strtolower( trim( (string) $legacy_type ) );
		$types = array_values( array_filter( array_map( 'trim', explode( '|', $legacy_type ) ) ) );
		$canonical_types = [];
		$array_item_type = '';

		foreach ( $types as $type ) {
			if ( preg_match( '/^array(?:<|:)([a-z]+)>?$/', $type, $matches ) ) {
				$canonical_types[] = 'array';
				$array_item_type = self::canonical_schema_type( $matches[1] );
				continue;
			}

			$canonical_types[] = self::canonical_schema_type( $type );
		}

		$canonical_types = array_values( array_unique( array_filter( $canonical_types ) ) );

		if ( empty( $canonical_types ) ) {
			$canonical_types = [ 'string' ];
		}

		$definition = [
			'type' => 1 === count( $canonical_types ) ? $canonical_types[0] : $canonical_types,
		];

		if ( 'array' === ( $definition['type'] ?? '' ) && '' !== $array_item_type ) {
			$definition['items'] = [ 'type' => $array_item_type ];
		}

		return $definition;
	}

	private static function canonical_schema_type( $type ) {
		$type = sanitize_key( (string) $type );

		if ( in_array( $type, [ 'float', 'decimal', 'double' ], true ) ) {
			return 'number';
		}

		if ( 'bool' === $type ) {
			return 'boolean';
		}

		if ( 'int' === $type ) {
			return 'integer';
		}

		return in_array( $type, [ 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ], true )
			? $type
			: 'string';
	}

	private static function connection_id( array $runtime ) {
		$connection_id = sanitize_text_field( $runtime['connector_connection_id'] ?? '' );

		if ( 'woocommerce' !== sanitize_key( $runtime['connector_key'] ?? '' )
			|| 'remote_agent' !== sanitize_key( $runtime['execution_mode'] ?? '' )
			|| 1 !== absint( $runtime['protocol_version'] ?? 0 )
			|| '' === $connection_id ) {
			return new WP_Error( 'yoro_connector_runtime_unavailable', 'The YoRo connector runtime identity is unavailable.' );
		}

		return $connection_id;
	}

	private static function safe_list( $items ) {
		return json_decode( wp_json_encode( is_array( $items ) ? array_values( $items ) : [] ), true );
	}
}
