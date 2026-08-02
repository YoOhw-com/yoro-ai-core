<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( class_exists( 'YoRo_AI_Core' ) ) {
	YoRo_AI_Core::init();
}

$administrators = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
	]
);

if ( ! empty( $administrators[0] ) ) {
	wp_set_current_user( $administrators[0]->ID );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$schema_violations = null;
$schema_violations = static function ( $schema, $path = 'output_schema', $property_name = '' ) use ( &$schema_violations ) {
	if ( ! is_array( $schema ) ) {
		return [ $path . ' must be a schema object.' ];
	}

	$types = is_array( $schema['type'] ?? null )
		? array_values( $schema['type'] )
		: [ $schema['type'] ?? '' ];

	if ( empty( array_filter( $types, 'is_string' ) ) ) {
		return [ $path . '.type must declare at least one schema type.' ];
	}

	if ( 'extensions' === $property_name ) {
		return in_array( 'object', $types, true )
			? []
			: [ $path . ' must remain an object connector-extension boundary.' ];
	}

	$violations = [];

	if ( in_array( 'object', $types, true ) ) {
		$properties = $schema['properties'] ?? null;
		$required   = $schema['required'] ?? null;

		if ( ! is_array( $properties ) ) {
			$violations[] = $path . '.properties must explicitly declare contract fields.';
		}

		if ( ! is_array( $required ) ) {
			$violations[] = $path . '.required must be explicitly declared.';
		}

		if ( false !== ( $schema['additionalProperties'] ?? null ) ) {
			$violations[] = $path . '.additionalProperties must be false.';
		}

		if ( is_array( $properties ) && is_array( $required ) ) {
			foreach ( array_diff( $required, array_keys( $properties ) ) as $missing_property ) {
				$violations[] = sprintf( '%s.required references undeclared property %s.', $path, $missing_property );
			}

			foreach ( $properties as $name => $definition ) {
				$violations = array_merge(
					$violations,
					$schema_violations( $definition, $path . '.properties.' . $name, sanitize_key( $name ) )
				);
			}
		}
	}

	if ( in_array( 'array', $types, true ) ) {
		if ( ! is_array( $schema['items'] ?? null ) ) {
			$violations[] = $path . '.items must explicitly declare the item schema.';
		} else {
			$violations = array_merge( $violations, $schema_violations( $schema['items'], $path . '.items' ) );
		}
	}

	return $violations;
};

$catalog = YoRo_Action_Catalog_Builder::build();
$runtime = [
	'connector_key'           => yoro_ai_core_has_woocommerce() ? 'woocommerce' : 'wordpress',
	'connector_connection_id' => 'strict_output_validation_readiness_smoke',
	'execution_mode'          => 'remote_agent',
	'protocol_version'        => 1,
];
$manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( $runtime, 1 );

$assert( ! is_wp_error( $manifest ), 'The capability manifest must be available for strict readiness verification.' );

$sources = [
	'action_catalog'      => $catalog,
	'capability_manifest' => $manifest['actions'] ?? [],
];

foreach ( $sources as $source => $actions ) {
	$strict_actions     = [];
	$non_strict_actions = [];
	$violations         = [];

	foreach ( (array) $actions as $action ) {
		if ( ! is_array( $action ) || empty( $action['name'] ) ) {
			continue;
		}

		$action_name  = sanitize_text_field( $action['name'] );
		$strict_ready = $action['extensions']['yoro.contracts']['strict_output_validation_ready'] ?? null;

		if ( true !== $strict_ready ) {
			$non_strict_actions[] = $action_name;
			continue;
		}

		$strict_actions[] = $action_name;
		$output_schema     = $action['output_schema'] ?? null;
		$data_schema       = is_array( $output_schema ) ? ( $output_schema['properties']['data'] ?? null ) : null;
		$action_violations = $schema_violations( $output_schema );

		if ( 'object' !== ( $data_schema['type'] ?? null ) ) {
			$action_violations[] = 'output_schema.properties.data must declare an object contract.';
		}

		if ( [] !== $action_violations ) {
			$violations[ $action_name ] = $action_violations;
		}
	}

	$assert( [] !== $strict_actions, sprintf( '%s must publish at least one strict-ready action.', $source ) );
	$assert( [] !== $non_strict_actions, sprintf( '%s must retain non-strict actions for incomplete output schemas.', $source ) );
	$assert(
		[] === $violations,
		sprintf( '%s strict-ready action schema violations: %s', $source, wp_json_encode( $violations ) )
	);
}

echo "YoRo strict output validation readiness: PASS\n";
