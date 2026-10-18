<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if ( class_exists( 'YoRo_AI_Core' ) && function_exists( 'yoro_ai_core_has_woocommerce' ) && yoro_ai_core_has_woocommerce() ) {
	YoRo_AI_Core::init();
}

$catalog_path = getenv( 'YORO_CANONICAL_CAPABILITY_AUTHORITY' );

if ( ! is_string( $catalog_path ) || '' === $catalog_path || ! is_readable( $catalog_path ) ) {
	$fixture_path = getenv( 'YORO_SHARED_COMMERCE_FIXTURES' );
	$catalog_path = is_string( $fixture_path ) && '' !== $fixture_path
		? dirname( $fixture_path ) . '/canonical-capability-authority.json'
		: '';
}

if ( '' === $catalog_path || ! is_readable( $catalog_path ) ) {
	throw new RuntimeException( 'Set YORO_CANONICAL_CAPABILITY_AUTHORITY or YORO_SHARED_COMMERCE_FIXTURES to the exact API contract files.' );
}

$catalog = json_decode( file_get_contents( $catalog_path ), true );

if ( ! is_array( $catalog ) ) {
	throw new RuntimeException( 'The API canonical capability authority catalog is invalid.' );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$runtime = [
	'connector_key'           => 'woocommerce',
	'connector_connection_id' => 'connection_capability_authority_smoke',
	'execution_mode'          => 'remote_agent',
	'protocol_version'        => 1,
];
$manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest( $runtime, 1 );

$assert( is_array( $manifest ), 'The connector must build a capability manifest.' );

$binding = $manifest['extensions']['yoro.capability_authority'] ?? null;
$assert( is_array( $binding ), 'The connector must publish a namespaced capability-authority binding.' );
$assert( 'yoro.capability_authority@v1' === ( $binding['catalog_version'] ?? '' ), 'The connector catalog version must match the API canonical catalog.' );
$assert( 'exact' === ( $binding['binding'] ?? '' ), 'The connector must declare exact canonical catalog binding.' );

$expected_actions = is_array( $catalog['actions'] ?? null ) ? $catalog['actions'] : [];
$expected_by_name = [];

foreach ( $expected_actions as $expected_action ) {
	if ( is_array( $expected_action ) && is_string( $expected_action['action_id'] ?? null ) ) {
		$expected_by_name[ $expected_action['action_id'] ] = $expected_action;
	}
}

$expected_names   = array_values( array_column( $expected_actions, 'action_id' ) );
$actual_actions   = is_array( $manifest['actions'] ?? null ) ? $manifest['actions'] : [];
$actual_names     = array_values( array_column( $actual_actions, 'name' ) );
$missing_names    = array_values( array_diff( $expected_names, $actual_names ) );
$extra_names      = array_values( array_diff( $actual_names, $expected_names ) );

// Native tracking writes are advertised only on stores with the supported WooCommerce storage enabled.
if ( class_exists( 'YoRo_Order_Management' ) && ! YoRo_Order_Management::tracking_available() ) {
	$optional = YoRo_Order_Management::TrackingActions;
	$assert( [] === array_values( array_intersect( $optional, $actual_names ) ), 'Unsupported native tracking must never be advertised.' );
	$marker = $manifest['extensions'][ YoRo_Order_Management::ManifestExtension ] ?? [];
	$assert( false === ( $marker['tracking_supported'] ?? null ), 'The marker must disclose unsupported native tracking.' );
	$missing_names = array_values( array_diff( $missing_names, $optional ) );
}

$assert( [] === $missing_names, 'The connector must support every API canonical action in this exact compatibility tranche.' );
$assert(
	[] === $extra_names,
	'Every externally executable connector action must have an API canonical definition: ' . implode( ', ', $extra_names )
);

$schema_types = static function ( $type ) {
	$types = is_array( $type ) ? $type : [ $type ];

	return array_values(
		array_filter(
			$types,
			static function ( $value ) {
				return is_string( $value ) && '' !== $value;
			}
		)
	);
};

$assert_schema_narrows = null;
$assert_schema_narrows = static function ( $connector, $canonical, $path ) use ( &$assert_schema_narrows, $assert, $schema_types ) {
	$connector_types = $schema_types( $connector['type'] ?? null );
	$canonical_types = $schema_types( $canonical['type'] ?? null );
	$assert( [] !== $connector_types && [] === array_diff( $connector_types, $canonical_types ), $path . ' widens canonical types.' );

	$connector_required = array_map( 'strval', is_array( $connector['required'] ?? null ) ? $connector['required'] : [] );
	$canonical_required = array_map( 'strval', is_array( $canonical['required'] ?? null ) ? $canonical['required'] : [] );
	$assert( [] === array_diff( $canonical_required, $connector_required ), $path . ' weakens canonical required fields.' );

	if ( false === ( $canonical['additionalProperties'] ?? true ) ) {
		$assert( false === ( $connector['additionalProperties'] ?? true ), $path . ' widens additional properties.' );
	}

	$canonical_properties = is_array( $canonical['properties'] ?? null ) ? $canonical['properties'] : [];

	foreach ( is_array( $connector['properties'] ?? null ) ? $connector['properties'] : [] as $property => $definition ) {
		$assert( isset( $canonical_properties[ $property ] ) && is_array( $definition ), $path . '.properties.' . $property . ' is not canonical.' );
		$assert_schema_narrows( $definition, $canonical_properties[ $property ], $path . '.properties.' . $property );
	}

	if ( is_array( $canonical['items'] ?? null ) ) {
		$assert( is_array( $connector['items'] ?? null ), $path . '.items omits the canonical item contract.' );
		$assert_schema_narrows( $connector['items'], $canonical['items'], $path . '.items' );
	} elseif ( isset( $connector['items'] ) ) {
		$assert( is_array( $connector['items'] ), $path . '.items must be a schema object.' );
	}

	if ( isset( $canonical['enum'] ) ) {
		$connector_enum = is_array( $connector['enum'] ?? null ) ? $connector['enum'] : [];
		$assert( [] !== $connector_enum && [] === array_diff( $connector_enum, (array) $canonical['enum'] ), $path . ' widens canonical enum values.' );
	}

	if ( isset( $canonical['pattern'] ) ) {
		$assert( ( $connector['pattern'] ?? null ) === $canonical['pattern'], $path . '.pattern weakens the canonical pattern.' );
	}

	foreach ( [ 'minimum', 'minLength', 'minItems' ] as $minimum ) {
		if ( isset( $canonical[ $minimum ] ) ) {
			$assert( isset( $connector[ $minimum ] ) && $connector[ $minimum ] >= $canonical[ $minimum ], $path . '.' . $minimum . ' weakens the canonical minimum.' );
		}
	}

	foreach ( [ 'maximum', 'maxLength', 'maxItems' ] as $maximum ) {
		if ( isset( $canonical[ $maximum ] ) ) {
			$assert( isset( $connector[ $maximum ] ) && $connector[ $maximum ] <= $canonical[ $maximum ], $path . '.' . $maximum . ' weakens the canonical maximum.' );
		}
	}
};

foreach ( $actual_actions as $actual_action ) {
	$action_id = (string) ( $actual_action['name'] ?? '' );
	$canonical = $expected_by_name[ $action_id ] ?? null;
	$assert( is_array( $canonical ), $action_id . ' must resolve to API canonical semantics.' );

	$expected_semantics = [
		'effect'               => $canonical['effect'],
		'risk_level'           => $canonical['risk_level'],
		'reversibility'        => $canonical['reversibility'],
		'external_side_effect' => in_array( $canonical['external_side_effect_class'] ?? 'none', [ 'communication', 'financial' ], true ),
		'write_action'         => 'commerce.execute' === ( $canonical['authority_scope'] ?? '' ),
		'output_schema'        => $canonical['output_schema'],
	];

	foreach ( $expected_semantics as $field => $expected_value ) {
		$assert( ( $actual_action[ $field ] ?? null ) === $expected_value, $action_id . '.' . $field . ' diverges from API canonical semantics.' );
	}

	$assert(
		(int) ( $actual_action['max_targets'] ?? 0 ) > 0
		&& (int) $actual_action['max_targets'] <= (int) ( $canonical['bounds']['max_targets'] ?? 0 ),
		$action_id . '.max_targets widens the API canonical bound.'
	);
	$assert_schema_narrows(
		is_array( $actual_action['input_schema'] ?? null ) ? $actual_action['input_schema'] : [],
		is_array( $canonical['input_schema'] ?? null ) ? $canonical['input_schema'] : [],
		$action_id . '.input_schema'
	);
}

$canonical_actions = array_values(
	array_filter(
		$actual_actions,
		static function ( $action ) use ( $expected_names ) {
			return is_array( $action ) && in_array( (string) ( $action['name'] ?? '' ), $expected_names, true );
		}
	)
);
$canonical_binding = YoRo_Commerce_Contract_Builder::capability_authority_binding( $canonical_actions );

$assert(
	( $catalog['catalog_digest'] ?? '' ) === ( $canonical_binding['catalog_digest'] ?? '' ),
	'The connector canonical action semantics must hash to the exact API-owned catalog digest.'
);
$assert(
	count( $actual_names ) === count( $canonical_binding['support'] ?? [] ),
	'The connector must publish one provider-neutral support fact for every advertised canonical action.'
);

foreach ( [ 'orders.search', 'orders.update_status', 'products.update_stock', 'products.update_stock_status', 'orders.add_note' ] as $focus_action ) {
	$assert( in_array( $focus_action, $expected_names, true ), sprintf( 'The canonical focus action %s must remain supported.', $focus_action ) );
}

$assert(
	( $catalog['catalog_digest'] ?? '' ) === ( $binding['catalog_digest'] ?? '' ),
	'The connector manifest must bind the exact API-owned catalog digest.'
);

echo "YoRo canonical capability authority smoke contract passed.\n";
