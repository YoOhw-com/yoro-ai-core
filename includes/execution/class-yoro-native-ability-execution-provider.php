<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Native_Ability_Execution_Provider implements YoRo_Action_Execution_Provider {

	private $accepted_ability_contract = [];

	public function provider_kind() {
		return 'native_ability';
	}

	public static function candidate_map() {
		return [
			'products.search'             => [
				'ability_id'         => 'woocommerce/products-query',
				'candidate_tranche'  => 'B',
				'coverage_predicate' => 'products_search_subset',
				'enabled'            => false,
			],
			'products.get_details'        => [
				'ability_id'         => 'woocommerce/products-query',
				'candidate_tranche'  => 'B',
				'coverage_predicate' => 'single_supported_product',
				'enabled'            => true,
			],
			'orders.search'               => [
				'ability_id'         => 'woocommerce/orders-query',
				'candidate_tranche'  => 'B',
				'coverage_predicate' => 'orders_search_subset',
				'enabled'            => true,
			],
			'orders.get_details'          => [
				'ability_id'         => 'woocommerce/orders-query',
				'candidate_tranche'  => 'B',
				'coverage_predicate' => 'single_order',
				'enabled'            => true,
			],
			'orders.update_status'        => [
				'ability_id'         => 'woocommerce/order-update-status',
				'candidate_tranche'  => 'C',
				'coverage_predicate' => 'single_versioned_order_status_write',
				'enabled'            => true,
			],
			'orders.add_note'             => [
				'ability_id'         => 'woocommerce/order-add-note',
				'candidate_tranche'  => 'C',
				'coverage_predicate' => 'single_provenance_preserving_order_note',
				'enabled'            => false,
			],
			'products.update_stock'       => [
				'ability_id'         => 'woocommerce/product-update',
				'candidate_tranche'  => 'C',
				'coverage_predicate' => 'single_simple_versioned_stock_write',
				'enabled'            => true,
			],
			'products.update_stock_status' => [
				'ability_id'         => 'woocommerce/product-update',
				'candidate_tranche'  => 'C',
				'coverage_predicate' => 'single_simple_versioned_stock_status_write',
				'enabled'            => true,
			],
		];
	}

	public function assess( $action_name, array $action, $params = [], $context = [] ) {
		$context = is_array( $context ) ? $context : [];
		$map = $this->get_candidate_map();
		$this->accepted_ability_contract = [];

		if ( ! isset( $map[ $action_name ] ) || ! is_array( $map[ $action_name ] ) ) {
			return $this->decision( false, 'native_mapping_missing' );
		}

		$mapping = $map[ $action_name ];
		$ability_id = isset( $mapping['ability_id'] ) ? (string) $mapping['ability_id'] : '';
		$candidate_tranche = isset( $mapping['candidate_tranche'] ) ? (string) $mapping['candidate_tranche'] : '';

		if ( empty( $mapping['enabled'] ) ) {
			return $this->decision( false, 'native_mapping_inactive', $ability_id, $candidate_tranche );
		}

		if ( ! $this->abilities_api_is_available() ) {
			return $this->decision( false, 'native_api_unavailable', $ability_id, $candidate_tranche );
		}

		$ability = $this->resolve_ability( $ability_id );

		if ( ! is_object( $ability ) || ! is_callable( [ $ability, 'execute' ] ) ) {
			return $this->decision( false, 'native_ability_unavailable', $ability_id, $candidate_tranche );
		}

		$contract_assessment = $this->assess_ability_contract( $mapping, $ability );

		if ( empty( $contract_assessment['accepted'] ) ) {
			return $this->decision( false, 'native_ability_contract_incompatible', $ability_id, $candidate_tranche );
		}

		$this->accepted_ability_contract = $contract_assessment;

		if ( ! $this->request_is_covered( $mapping['coverage_predicate'] ?? '', $params, $action, $context ) ) {
			return $this->decision( false, 'request_outside_native_coverage', $ability_id, $candidate_tranche );
		}

		$decision = $this->decision( true, 'native_selected', $ability_id, $candidate_tranche );
		$decision['_ability'] = $ability;

		return $decision;
	}

	public function execute( $action_name, array $action, $params = [], $context = [], array $decision = [] ) {
		$ability = $decision['_ability'] ?? null;

		if ( ! is_object( $ability ) || ! is_callable( [ $ability, 'execute' ] ) ) {
			return new WP_Error( 'yoro_native_ability_unavailable', __( 'The selected native store capability is unavailable.', 'yoro-ai-core' ) );
		}

		try {
			if ( in_array( $action_name, [ 'orders.update_status', 'products.update_stock', 'products.update_stock_status' ], true ) ) {
				return $this->execute_native_write( $action_name, $ability, $params );
			}

			$native_input = $this->adapt_input( $action_name, $params );

			if ( is_wp_error( $native_input ) ) {
				return $native_input;
			}

			$native_result = $ability->execute( $native_input );

			if ( is_wp_error( $native_result ) ) {
				return $native_result;
			}

			return $this->adapt_output( $action_name, $native_result, $params );
		} catch ( Throwable $error ) {
			return new WP_Error( 'yoro_native_ability_failed', __( 'The selected native store capability could not be executed.', 'yoro-ai-core' ) );
		}
	}

	protected function get_candidate_map() {
		return self::candidate_map();
	}

	protected function abilities_api_is_available() {
		return function_exists( 'wp_get_ability' );
	}

	protected function resolve_ability( $ability_id ) {
		return wp_get_ability( $ability_id );
	}

	protected function request_is_covered( $coverage_predicate, $params, array $action, array $context ) {
		switch ( (string) $coverage_predicate ) {
			case 'orders_search_subset':
				return $this->orders_search_request_is_covered( $params );
			case 'single_order':
				return $this->order_details_request_is_covered( $params );
			case 'single_supported_product':
				return $this->product_details_request_is_covered( $params );
			case 'single_versioned_order_status_write':
				return $this->order_status_write_request_is_covered( $params );
			case 'single_simple_versioned_stock_write':
				return $this->product_stock_write_request_is_covered( $params );
			case 'single_simple_versioned_stock_status_write':
				return $this->product_stock_status_write_request_is_covered( $params );
			default:
				return false;
		}
	}

	protected function assess_ability_contract( array $mapping, $ability ) {
		if ( ! is_callable( [ $ability, 'get_input_schema' ] ) || ! is_callable( [ $ability, 'get_output_schema' ] ) ) {
			return [ 'accepted' => false ];
		}

		if (
			in_array(
				(string) ( $mapping['coverage_predicate'] ?? '' ),
				[ 'single_versioned_order_status_write', 'single_simple_versioned_stock_write', 'single_simple_versioned_stock_status_write' ],
				true
			)
			&& ! is_callable( [ $ability, 'check_permissions' ] )
		) {
			return [ 'accepted' => false ];
		}

		try {
			$input_schema = $ability->get_input_schema();
			$output_schema = $ability->get_output_schema();
		} catch ( Throwable $error ) {
			return [ 'accepted' => false ];
		}

		if ( ! is_array( $input_schema ) || ! is_array( $output_schema ) ) {
			return [ 'accepted' => false ];
		}

		switch ( (string) ( $mapping['coverage_predicate'] ?? '' ) ) {
			case 'orders_search_subset':
				return $this->assess_orders_search_contract( $input_schema, $output_schema );
			case 'single_order':
				return $this->assess_detail_query_contract( $input_schema, $output_schema, 'orders', true );
			case 'single_supported_product':
				return $this->assess_detail_query_contract( $input_schema, $output_schema, 'products', false );
			case 'single_versioned_order_status_write':
				return $this->assess_order_status_write_contract( $input_schema, $output_schema );
			case 'single_simple_versioned_stock_write':
				return $this->assess_product_write_contract( $input_schema, $output_schema, true );
			case 'single_simple_versioned_stock_status_write':
				return $this->assess_product_write_contract( $input_schema, $output_schema, false );
			default:
				return [ 'accepted' => false ];
		}
	}

	private function assess_orders_search_contract( array $input_schema, array $output_schema ) {

		$input_properties = $input_schema['properties'] ?? [];
		$output_properties = $output_schema['properties'] ?? [];

		if ( ! is_array( $input_properties ) || ! is_array( $output_properties ) ) {
			return [ 'accepted' => false ];
		}

		$orders_schema = $output_properties['orders'] ?? [];
		$order_schema = is_array( $orders_schema ) ? ( $orders_schema['items'] ?? [] ) : [];
		$order_items = is_array( $order_schema ) ? ( $order_schema['properties'] ?? [] ) : [];

		if ( ! is_array( $order_items ) ) {
			return [ 'accepted' => false ];
		}

		if (
			! $this->schema_has_type( $input_schema, 'object' )
			|| ! $this->schema_has_type( $input_properties['status'] ?? [], 'string' )
			|| ! $this->schema_has_string_enum( $input_properties['status'] ?? [] )
			|| ! $this->schema_has_type( $input_properties['customer_id'] ?? [], 'integer' )
			|| (
				isset( $input_properties['customer_id']['minimum'] )
				&& ( ! is_numeric( $input_properties['customer_id']['minimum'] ) || (int) $input_properties['customer_id']['minimum'] > 1 )
			)
			|| ! $this->schema_has_type( $input_properties['billing_email'] ?? [], 'string' )
			|| 'email' !== ( $input_properties['billing_email']['format'] ?? '' )
			|| ! $this->schema_enum_contains( $input_properties['orderby'] ?? [], 'date' )
			|| ! $this->schema_enum_contains( $input_properties['order'] ?? [], 'desc' )
			|| ! $this->schema_has_type( $input_properties['include_line_items'] ?? [], 'boolean' )
			|| ! $this->integer_schema_accepts( $input_properties['page'] ?? [], 1 )
			|| ! $this->integer_schema_accepts( $input_properties['per_page'] ?? [], 50 )
			|| ! $this->schema_has_type( $output_schema, 'object' )
			|| ! $this->schema_has_type( $output_properties['orders'] ?? [], 'array' )
			|| ! $this->schema_has_type( $order_schema, 'object' )
			|| ! $this->schema_has_type( $order_items['id'] ?? [], 'integer' )
			|| ! $this->schema_has_type( $output_properties['total_pages'] ?? [], 'integer' )
			|| ! $this->schema_has_type( $output_properties['page'] ?? [], 'integer' )
			|| ! $this->schema_has_type( $output_properties['per_page'] ?? [], 'integer' )
		) {
			return [ 'accepted' => false ];
		}

		return [
			'accepted'    => true,
			'output_keys' => array_keys( $output_properties ),
			'status_enum' => array_values( array_map( 'sanitize_key', $input_properties['status']['enum'] ) ),
		];
	}

	private function assess_detail_query_contract( array $input_schema, array $output_schema, $collection_key, $requires_line_items_flag ) {
		$input_properties = $input_schema['properties'] ?? [];
		$output_properties = $output_schema['properties'] ?? [];

		if ( ! is_array( $input_properties ) || ! is_array( $output_properties ) ) {
			return [ 'accepted' => false ];
		}

		$collection_schema = $output_properties[ $collection_key ] ?? [];
		$item_schema = is_array( $collection_schema ) ? ( $collection_schema['items'] ?? [] ) : [];
		$item_properties = is_array( $item_schema ) ? ( $item_schema['properties'] ?? [] ) : [];

		if (
			! is_array( $item_properties )
			|| ! $this->schema_has_type( $input_schema, 'object' )
			|| ! $this->integer_schema_accepts( $input_properties['id'] ?? [], 1 )
			|| ( $requires_line_items_flag && ! $this->schema_has_type( $input_properties['include_line_items'] ?? [], 'boolean' ) )
			|| ! $this->schema_has_type( $output_schema, 'object' )
			|| ! $this->schema_has_type( $collection_schema, 'array' )
			|| ! $this->array_schema_accepts_count( $collection_schema, 1 )
			|| ! $this->schema_has_type( $item_schema, 'object' )
			|| ! $this->schema_has_type( $item_properties['id'] ?? [], 'integer' )
			|| ! $this->schema_has_type( $output_properties['total_pages'] ?? [], 'integer' )
			|| ! $this->schema_has_type( $output_properties['page'] ?? [], 'integer' )
			|| ! $this->schema_has_type( $output_properties['per_page'] ?? [], 'integer' )
			|| ! $this->schema_accepts_value( $output_properties['total_pages'] ?? [], 1, 'total_pages' )
			|| ! $this->schema_accepts_value( $output_properties['page'] ?? [], 1, 'page' )
			|| ! $this->schema_accepts_value( $output_properties['per_page'] ?? [], 1, 'per_page' )
		) {
			return [ 'accepted' => false ];
		}

		return [
			'accepted'         => true,
			'collection_key'   => $collection_key,
			'id_schema'        => $input_properties['id'],
			'input_schema'     => $input_schema,
			'output_id_schema' => $item_properties['id'],
			'output_keys'      => array_keys( $output_properties ),
		];
	}

	private function assess_order_status_write_contract( array $input_schema, array $output_schema ) {
		$input_properties = $input_schema['properties'] ?? [];
		$output_properties = $output_schema['properties'] ?? [];
		$order_schema = is_array( $output_properties ) ? ( $output_properties['order'] ?? [] ) : [];
		$order_properties = is_array( $order_schema ) ? ( $order_schema['properties'] ?? [] ) : [];

		if (
			! is_array( $input_properties )
			|| ! is_array( $output_properties )
			|| ! $this->schema_has_type( $input_schema, 'object' )
			|| ! $this->schema_required_contains( $input_schema, [ 'id', 'status' ] )
			|| ! $this->integer_schema_accepts( $input_properties['id'] ?? [], 1 )
			|| ! $this->schema_has_string_enum( $input_properties['status'] ?? [] )
			|| ! $this->schema_has_type( $input_properties['note'] ?? [], 'string' )
			|| ! is_array( $order_properties )
			|| ! $this->schema_has_type( $output_schema, 'object' )
			|| ! $this->schema_has_type( $order_schema, 'object' )
			|| ! $this->schema_has_type( $order_properties['id'] ?? [], 'integer' )
			|| ! $this->schema_has_type( $order_properties['status'] ?? [], 'string' )
		) {
			return [ 'accepted' => false ];
		}

		return [
			'accepted'    => true,
			'status_enum' => array_values( array_unique( array_map( 'sanitize_key', $input_properties['status']['enum'] ) ) ),
		];
	}

	private function assess_product_write_contract( array $input_schema, array $output_schema, $requires_quantity ) {
		$branches = isset( $input_schema['oneOf'] ) && is_array( $input_schema['oneOf'] ) ? $input_schema['oneOf'] : [];
		$output_properties = $output_schema['properties'] ?? [];
		$product_schema = is_array( $output_properties ) ? ( $output_properties['product'] ?? [] ) : [];
		$product_properties = is_array( $product_schema ) ? ( $product_schema['properties'] ?? [] ) : [];

		if (
			empty( $branches )
			|| ! is_array( $product_properties )
			|| ! $this->schema_has_type( $output_schema, 'object' )
			|| ! $this->schema_has_type( $product_schema, 'object' )
			|| ! $this->schema_has_type( $product_properties['id'] ?? [], 'integer' )
			|| ! $this->schema_has_type( $product_properties['type'] ?? [], 'string' )
		) {
			return [ 'accepted' => false ];
		}

		$accepted_aliases = [];
		$quantity_schemas = [];

		foreach ( $branches as $branch ) {
			$properties = is_array( $branch ) ? ( $branch['properties'] ?? [] ) : [];
			$alias_schema = is_array( $properties ) ? ( $properties['product_type_alias'] ?? [] ) : [];
			$aliases = is_array( $alias_schema['enum'] ?? null ) ? $alias_schema['enum'] : [];

			if (
				! is_array( $properties )
				|| ! $this->schema_has_type( $branch, 'object' )
				|| ! $this->schema_required_contains( $branch, [ 'id', 'product_type_alias' ] )
				|| ! $this->integer_schema_accepts( $properties['id'] ?? [], 1 )
				|| 1 !== count( $aliases )
				|| ! is_string( reset( $aliases ) )
			) {
				continue;
			}

			$alias = sanitize_key( reset( $aliases ) );

			if ( ! in_array( $alias, [ 'physical', 'virtual', 'digital' ], true ) ) {
				continue;
			}

			if ( $requires_quantity ) {
				if (
					! $this->schema_has_type( $properties['manage_stock'] ?? [], 'boolean' )
					|| (
						! $this->schema_has_type( $properties['stock_quantity'] ?? [], 'integer' )
						&& ! $this->schema_has_type( $properties['stock_quantity'] ?? [], 'number' )
					)
					|| ! $this->schema_has_string_enum( $properties['stock_status'] ?? [] )
				) {
					continue;
				}

				$quantity_schemas[ $alias ] = $properties['stock_quantity'];
			} elseif ( ! $this->schema_has_string_enum( $properties['stock_status'] ?? [] ) ) {
				continue;
			}

			$accepted_aliases[ $alias ] = array_values( array_unique( array_map( 'sanitize_key', $properties['stock_status']['enum'] ) ) );
		}

		if ( empty( $accepted_aliases ) ) {
			return [ 'accepted' => false ];
		}

		return [
			'accepted'         => true,
			'aliases'          => $accepted_aliases,
			'quantity_schemas' => $quantity_schemas,
		];
	}

	private function order_status_write_request_is_covered( $params ) {
		if ( ! $this->params_only_use_supported_keys( $params, [ 'order_ids', 'status', 'note', 'expected_versions' ] ) ) {
			return false;
		}

		$prepared = YoRo_Orders_Write_Service::prepare_order_status_write(
			$params,
			[
				'canonical_order_ids_only' => true,
				'single_target'            => true,
				'status_must_change'       => true,
				'allowed_statuses'         => $this->accepted_ability_contract['status_enum'] ?? [],
			]
		);

		return ! is_wp_error( $prepared );
	}

	private function product_stock_write_request_is_covered( $params ) {
		if ( ! $this->params_only_use_supported_keys( $params, [ 'product_id', 'stock_quantity', 'expected_version', 'product_sku', 'global_unique_id' ] ) ) {
			return false;
		}

		$prepared = YoRo_Store_Inventory_Service::prepare_product_stock_write(
			$params,
			[
				'explicit_product_id_only'                => true,
				'supported_simple_only'                   => true,
				'canonical_nonnegative_integer_quantity' => true,
				'allowed_aliases'                         => array_keys( $this->accepted_ability_contract['aliases'] ?? [] ),
			]
		);

		if ( is_wp_error( $prepared ) ) {
			return false;
		}

		$statuses        = $this->accepted_ability_contract['aliases'][ $prepared['product_type_alias'] ] ?? [];
		$quantity_schema = $this->accepted_ability_contract['quantity_schemas'][ $prepared['product_type_alias'] ] ?? [];

		return $this->schema_accepts_value( $quantity_schema, $prepared['stock_quantity'], 'stock_quantity' )
			&& in_array( $prepared['stock_quantity'] > 0 ? 'instock' : 'outofstock', $statuses, true );
	}

	private function product_stock_status_write_request_is_covered( $params ) {
		if ( ! $this->params_only_use_supported_keys( $params, [ 'product_id', 'stock_status', 'expected_version', 'product_sku', 'global_unique_id' ] ) ) {
			return false;
		}

		$aliases = $this->accepted_ability_contract['aliases'] ?? [];
		$prepared = YoRo_Store_Inventory_Service::prepare_product_stock_status_write(
			$params,
			[
				'explicit_product_id_only' => true,
				'supported_simple_only'    => true,
				'allowed_aliases'          => array_keys( $aliases ),
			]
		);

		if ( is_wp_error( $prepared ) ) {
			return false;
		}

		return in_array( $prepared['stock_status'], $aliases[ $prepared['product_type_alias'] ] ?? [], true );
	}

	private function order_details_request_is_covered( $params ) {
		$order_id = $this->single_detail_target_id( $params, 'order_ids', [ 'response_focus', 'requested_fields' ] );

		if (
			$order_id <= 0
			|| ! $this->detail_query_contract_covers_input_and_identity(
				[ 'id' => $order_id, 'include_line_items' => false ],
				$order_id
			)
			|| ! function_exists( 'wc_get_order' )
		) {
			return false;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order
			&& $order_id === (int) $order->get_id();
	}

	private function product_details_request_is_covered( $params ) {
		$product_id = $this->single_detail_target_id( $params, 'product_ids' );

		if (
			$product_id <= 0
			|| ! $this->detail_query_contract_covers_input_and_identity( [ 'id' => $product_id ], $product_id )
			|| ! function_exists( 'wc_get_product' )
		) {
			return false;
		}

		$product = wc_get_product( $product_id );

		return $product instanceof WC_Product
			&& $product_id === (int) $product->get_id()
			&& 'variation' !== sanitize_key( $product->get_type() );
	}

	private function detail_query_contract_covers_input_and_identity( array $native_input, $target_id ) {
		return $this->schema_accepts_value( $this->accepted_ability_contract['input_schema'] ?? [], $native_input, 'input' )
			&& $this->schema_accepts_value( $this->accepted_ability_contract['id_schema'] ?? [], $target_id, 'id' )
			&& $this->schema_accepts_value( $this->accepted_ability_contract['output_id_schema'] ?? [], $target_id, 'output_id' );
	}

	private function single_detail_target_id( $params, $target_key, array $additional_supported_keys = [] ) {
		if ( ! $this->params_only_use_supported_keys( $params, array_merge( [ $target_key ], $additional_supported_keys ) ) ) {
			return 0;
		}

		$targets = is_array( $params[ $target_key ] ?? null ) ? array_values( $params[ $target_key ] ) : [];

		if ( 1 !== count( $targets ) || ! is_int( $targets[0] ) || $targets[0] <= 0 ) {
			return 0;
		}

		return $targets[0];
	}

	private function orders_search_request_is_covered( $params ) {
		if ( ! is_array( $params ) ) {
			return false;
		}

		$supported = [ 'limit', 'status', 'email', 'customer_id' ];
		$control = [ 'response_language', 'original_command', '_yoro_confirmed', '_yoro_preview' ];

		foreach ( $params as $key => $value ) {
			if ( in_array( $key, $supported, true ) || in_array( $key, $control, true ) ) {
				continue;
			}

			if ( $this->value_is_effective( $value ) ) {
				return false;
			}
		}

		if ( array_key_exists( 'status', $params ) ) {
			if ( is_array( $params['status'] ) ) {
				if ( ! empty( $params['status'] ) ) {
					return false;
				}
			} elseif ( ! is_string( $params['status'] ) ) {
				if ( $this->value_is_effective( $params['status'] ) ) {
					return false;
				}
			} elseif ( '' !== trim( $params['status'] ) ) {
				$status = sanitize_key( $params['status'] );
				$status_enum = $this->accepted_ability_contract['status_enum'] ?? [];

				if ( '' === $status || ! in_array( $status, $status_enum, true ) ) {
					return false;
				}
			}
		}

		if ( array_key_exists( 'email', $params ) ) {
			if ( ! is_string( $params['email'] ) ) {
				if ( $this->value_is_effective( $params['email'] ) ) {
					return false;
				}
			} elseif ( '' !== trim( $params['email'] ) ) {
				$email = trim( $params['email'] );

				if ( sanitize_email( $email ) !== $email || ! is_email( $email ) ) {
					return false;
				}
			}
		}

		if ( array_key_exists( 'customer_id', $params ) && $this->value_is_effective( $params['customer_id'] ) ) {
			if ( ! is_scalar( $params['customer_id'] ) || absint( $params['customer_id'] ) <= 0 ) {
				return false;
			}
		}

		if ( isset( $params['limit'] ) && ! is_scalar( $params['limit'] ) ) {
			return false;
		}

		return true;
	}

	private function execute_native_write( $action_name, $ability, $params ) {
		if ( 'orders.update_status' === $action_name ) {
			$prepared = YoRo_Orders_Write_Service::prepare_order_status_write(
				$params,
				[
					'canonical_order_ids_only' => true,
					'single_target'            => true,
					'status_must_change'       => true,
					'allowed_statuses'         => $this->accepted_ability_contract['status_enum'] ?? [],
				]
			);

			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$order_id = $prepared['order_ids'][0];
			$native_input = [
				'id'     => $order_id,
				'status' => $prepared['status'],
				'note'   => $prepared['note'],
			];
			$permission = $this->check_native_write_permission( $ability, $native_input );

			if ( is_wp_error( $permission ) ) {
				return $permission;
			}

			$native_result = $ability->execute( $native_input );

			if ( is_wp_error( $native_result ) ) {
				return $native_result;
			}

			if ( ! $this->native_result_has_identity( $native_result, 'order', $order_id ) ) {
				return $this->invalid_native_result();
			}

			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

			if ( ! $order instanceof WC_Order || $prepared['status'] !== sanitize_key( $order->get_status() ) ) {
				return $this->invalid_native_result();
			}

			return YoRo_Orders_Write_Service::build_order_status_write_result( $prepared, [ $order_id ] );
		}

		$aliases = $this->accepted_ability_contract['aliases'] ?? [];
		$is_quantity_write = 'products.update_stock' === $action_name;
		$constraints = [
			'explicit_product_id_only' => true,
			'supported_simple_only'    => true,
			'allowed_aliases'          => array_keys( $aliases ),
		];

		if ( $is_quantity_write ) {
			$constraints['canonical_nonnegative_integer_quantity'] = true;
			$prepared = YoRo_Store_Inventory_Service::prepare_product_stock_write( $params, $constraints );
		} else {
			$prepared = YoRo_Store_Inventory_Service::prepare_product_stock_status_write( $params, $constraints );
		}

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$product_id = $prepared['product_id'];
		$product_alias = $prepared['product_type_alias'];
		$stock_status = $is_quantity_write
			? ( $prepared['stock_quantity'] > 0 ? 'instock' : 'outofstock' )
			: $prepared['stock_status'];

		if ( ! in_array( $stock_status, $aliases[ $product_alias ] ?? [], true ) ) {
			return new WP_Error( 'yoro_native_ability_contract_incompatible', __( 'The selected native store capability no longer covers this stock status.', 'yoro-ai-core' ) );
		}

		$native_input = [
			'id'                 => $product_id,
			'product_type_alias' => $product_alias,
			'stock_status'       => $stock_status,
		];

		if ( $is_quantity_write ) {
			$native_input['manage_stock'] = true;
			$native_input['stock_quantity'] = $prepared['stock_quantity'];
		}

		$permission = $this->check_native_write_permission( $ability, $native_input );

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$native_result = $ability->execute( $native_input );

		if ( is_wp_error( $native_result ) ) {
			return $native_result;
		}

		if ( ! $this->native_result_has_identity( $native_result, 'product', $product_id ) ) {
			return $this->invalid_native_result();
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

		if (
			! $product instanceof WC_Product
			|| sanitize_key( $product->get_type() ) !== $prepared['product_type']
			|| (bool) $product->get_virtual() !== $prepared['product_virtual']
			|| (bool) $product->get_downloadable() !== $prepared['product_downloadable']
			|| sanitize_key( $product->get_stock_status() ) !== $stock_status
		) {
			return $this->invalid_native_result();
		}

		if ( $is_quantity_write ) {
			if ( ! $product->get_manage_stock() || ! is_numeric( $product->get_stock_quantity() ) || (int) $product->get_stock_quantity() !== $prepared['stock_quantity'] ) {
				return $this->invalid_native_result();
			}

			return YoRo_Store_Inventory_Service::build_product_stock_write_result( $prepared, $product );
		}

		if (
			$product->get_manage_stock() !== $prepared['previous_manage_stock']
			|| $product->get_stock_quantity() !== $prepared['previous_stock_quantity']
		) {
			return $this->invalid_native_result();
		}

		return YoRo_Store_Inventory_Service::build_product_stock_status_write_result( $prepared, $product );
	}

	private function check_native_write_permission( $ability, array $native_input ) {
		if ( ! is_object( $ability ) || ! is_callable( [ $ability, 'check_permissions' ] ) ) {
			return $this->invalid_native_permission();
		}

		try {
			$permission = $ability->check_permissions( $native_input );
		} catch ( Throwable $error ) {
			return $this->invalid_native_permission();
		}

		return true === $permission ? true : $this->invalid_native_permission();
	}

	private function invalid_native_permission() {
		return new WP_Error( 'ability_invalid_permissions', __( 'The selected native store capability does not have necessary permission.', 'yoro-ai-core' ) );
	}

	private function native_result_has_identity( $native_result, $envelope_key, $expected_id ) {
		return is_array( $native_result )
			&& isset( $native_result[ $envelope_key ] )
			&& is_array( $native_result[ $envelope_key ] )
			&& isset( $native_result[ $envelope_key ]['id'] )
			&& is_int( $native_result[ $envelope_key ]['id'] )
			&& $expected_id === $native_result[ $envelope_key ]['id'];
	}

	private function params_only_use_supported_keys( $params, array $supported ) {
		if ( ! is_array( $params ) ) {
			return false;
		}

		$control = [ 'response_language', 'original_command', '_yoro_confirmed', '_yoro_preview' ];

		foreach ( $params as $key => $value ) {
			if ( in_array( $key, $supported, true ) || in_array( $key, $control, true ) ) {
				continue;
			}

			if ( $this->value_is_effective( $value ) ) {
				return false;
			}
		}

		return true;
	}

	protected function adapt_input( $action_name, $params ) {
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'yoro_native_input_adapter_unavailable', __( 'The selected native store capability has no accepted input adapter.', 'yoro-ai-core' ) );
		}

		if ( 'orders.get_details' === $action_name ) {
			$order_id = $this->single_detail_target_id( $params, 'order_ids', [ 'response_focus', 'requested_fields' ] );

			return $order_id > 0
				? [ 'id' => $order_id, 'include_line_items' => false ]
				: new WP_Error( 'yoro_native_input_adapter_unavailable', __( 'The selected native store capability has no accepted input adapter.', 'yoro-ai-core' ) );
		}

		if ( 'products.get_details' === $action_name ) {
			$product_id = $this->single_detail_target_id( $params, 'product_ids' );

			return $product_id > 0
				? [ 'id' => $product_id ]
				: new WP_Error( 'yoro_native_input_adapter_unavailable', __( 'The selected native store capability has no accepted input adapter.', 'yoro-ai-core' ) );
		}

		if ( 'orders.search' !== $action_name ) {
			return new WP_Error( 'yoro_native_input_adapter_unavailable', __( 'The selected native store capability has no accepted input adapter.', 'yoro-ai-core' ) );
		}

		$input = [
			'page'               => 1,
			'per_page'           => YoRo_Orders_Read_Service::get_search_result_limit( $params ),
			'orderby'            => 'date',
			'order'              => 'desc',
			'include_line_items' => false,
		];

		if ( ! empty( $params['status'] ) ) {
			$input['status'] = sanitize_key( $params['status'] );
		}

		if ( ! empty( $params['email'] ) ) {
			$input['billing_email'] = sanitize_email( $params['email'] );
		}

		if ( ! empty( $params['customer_id'] ) ) {
			$input['customer_id'] = absint( $params['customer_id'] );
		}

		return $input;
	}

	protected function adapt_output( $action_name, $native_result, $params ) {
		if ( ! is_array( $native_result ) ) {
			return $this->invalid_native_result();
		}

		if ( 'orders.get_details' === $action_name ) {
			return $this->adapt_detail_output( $action_name, $native_result, $params, 'orders' );
		}

		if ( 'products.get_details' === $action_name ) {
			return $this->adapt_detail_output( $action_name, $native_result, $params, 'products' );
		}

		if ( 'orders.search' !== $action_name ) {
			return $this->invalid_native_result();
		}

		$allowed_keys = $this->accepted_ability_contract['output_keys'] ?? [];

		if (
			! is_array( $allowed_keys )
			|| empty( $allowed_keys )
			|| array_diff( array_keys( $native_result ), $allowed_keys )
			|| ! isset( $native_result['orders'] )
			|| ! is_array( $native_result['orders'] )
			|| ! isset( $native_result['total_pages'], $native_result['page'], $native_result['per_page'] )
			|| ! is_int( $native_result['total_pages'] )
			|| ! is_int( $native_result['page'] )
			|| ! is_int( $native_result['per_page'] )
			|| $native_result['total_pages'] < 0
			|| 1 !== $native_result['page']
			|| YoRo_Orders_Read_Service::get_search_result_limit( $params ) !== $native_result['per_page']
			|| count( $native_result['orders'] ) > $native_result['per_page']
		) {
			return $this->invalid_native_result();
		}

		$orders = [];
		$seen_ids = [];

		foreach ( $native_result['orders'] as $native_order ) {
			if ( ! is_array( $native_order ) || ! isset( $native_order['id'] ) || ! is_int( $native_order['id'] ) || $native_order['id'] <= 0 ) {
				return $this->invalid_native_result();
			}

			$order_id = $native_order['id'];

			if ( isset( $seen_ids[ $order_id ] ) || ! function_exists( 'wc_get_order' ) ) {
				return $this->invalid_native_result();
			}

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return $this->invalid_native_result();
			}

			$seen_ids[ $order_id ] = true;
			$orders[] = $order;
		}

		return YoRo_Orders_Read_Service::format_search_result( $orders, $params );
	}

	private function adapt_detail_output( $action_name, array $native_result, $params, $collection_key ) {
		$allowed_keys = $this->accepted_ability_contract['output_keys'] ?? [];
		$target_key = 'orders.get_details' === $action_name ? 'order_ids' : 'product_ids';
		$additional_supported_keys = 'orders.get_details' === $action_name ? [ 'response_focus', 'requested_fields' ] : [];
		$target_id = $this->single_detail_target_id( $params, $target_key, $additional_supported_keys );
		$items = $native_result[ $collection_key ] ?? null;

		if (
			$target_id <= 0
			|| ! is_array( $allowed_keys )
			|| empty( $allowed_keys )
			|| $collection_key !== ( $this->accepted_ability_contract['collection_key'] ?? '' )
			|| array_diff( array_keys( $native_result ), $allowed_keys )
			|| ! is_array( $items )
			|| 1 !== count( $items )
			|| [ 0 ] !== array_keys( $items )
			|| ! isset( $native_result['total_pages'], $native_result['page'], $native_result['per_page'] )
			|| ! is_int( $native_result['total_pages'] )
			|| ! is_int( $native_result['page'] )
			|| ! is_int( $native_result['per_page'] )
			|| 1 !== $native_result['total_pages']
			|| 1 !== $native_result['page']
			|| 1 !== $native_result['per_page']
			|| ! is_array( $items[0] )
			|| ! isset( $items[0]['id'] )
			|| ! is_int( $items[0]['id'] )
			|| $target_id !== $items[0]['id']
		) {
			return $this->invalid_native_result();
		}

		if ( 'orders.get_details' === $action_name ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $target_id ) : false;

			if ( ! $order instanceof WC_Order || $target_id !== (int) $order->get_id() ) {
				return $this->invalid_native_result();
			}

			return YoRo_Orders_Read_Service::get_order_details( $params );
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $target_id ) : false;

		if (
			! $product instanceof WC_Product
			|| $target_id !== (int) $product->get_id()
			|| 'variation' === sanitize_key( $product->get_type() )
		) {
			return $this->invalid_native_result();
		}

		return YoRo_Commerce_Discovery_Service::get_product_details( $params );
	}

	private function invalid_native_result() {
		return new WP_Error( 'yoro_native_ability_invalid_result', __( 'The selected native store capability returned an incompatible result.', 'yoro-ai-core' ) );
	}

	private function schema_has_type( $schema, $expected ) {
		$types = is_array( $schema ) ? ( $schema['type'] ?? [] ) : [];
		$types = is_array( $types ) ? $types : [ $types ];

		return in_array( $expected, $types, true );
	}

	private function schema_enum_contains( $schema, $expected ) {
		return $this->schema_has_type( $schema, 'string' )
			&& is_array( $schema['enum'] ?? null )
			&& in_array( $expected, $schema['enum'], true );
	}

	private function schema_has_string_enum( $schema ) {
		if ( ! is_array( $schema ) || ! is_array( $schema['enum'] ?? null ) || empty( $schema['enum'] ) ) {
			return false;
		}

		foreach ( $schema['enum'] as $value ) {
			if ( ! is_string( $value ) || '' === $value || $value !== sanitize_key( $value ) ) {
				return false;
			}
		}

		return true;
	}

	private function integer_schema_accepts( $schema, $value ) {
		if ( ! $this->schema_has_type( $schema, 'integer' ) ) {
			return false;
		}

		if ( isset( $schema['minimum'] ) && ( ! is_numeric( $schema['minimum'] ) || $value < (int) $schema['minimum'] ) ) {
			return false;
		}

		return ! isset( $schema['maximum'] ) || ( is_numeric( $schema['maximum'] ) && $value <= (int) $schema['maximum'] );
	}

	private function array_schema_accepts_count( $schema, $count ) {
		if ( ! $this->schema_has_type( $schema, 'array' ) ) {
			return false;
		}

		if ( isset( $schema['minItems'] ) && ( ! is_numeric( $schema['minItems'] ) || $count < (int) $schema['minItems'] ) ) {
			return false;
		}

		return ! isset( $schema['maxItems'] ) || ( is_numeric( $schema['maxItems'] ) && $count <= (int) $schema['maxItems'] );
	}

	private function schema_accepts_value( $schema, $value, $parameter ) {
		if ( ! is_array( $schema ) || ! function_exists( 'rest_validate_value_from_schema' ) ) {
			return false;
		}

		return ! is_wp_error( rest_validate_value_from_schema( $value, $schema, $parameter ) );
	}

	private function schema_required_contains( $schema, array $required ) {
		$declared = is_array( $schema ) && is_array( $schema['required'] ?? null ) ? $schema['required'] : [];

		return empty( array_diff( $required, $declared ) );
	}

	private function value_is_effective( $value ) {
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}

		if ( is_object( $value ) || is_resource( $value ) ) {
			return true;
		}

		return null !== $value && false !== $value && '' !== trim( (string) $value );
	}

	private function decision( $eligible, $reason, $ability_id = '', $candidate_tranche = '' ) {
		return [
			'eligible'          => (bool) $eligible,
			'reason'            => (string) $reason,
			'ability_id'        => (string) $ability_id,
			'candidate_tranche' => (string) $candidate_tranche,
		];
	}
}
