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
				'enabled'            => false,
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
				'enabled'            => false,
			],
			'orders.update_status'        => [
				'ability_id'         => 'woocommerce/order-update-status',
				'candidate_tranche'  => 'C',
				'coverage_predicate' => 'single_versioned_order_status_write',
				'enabled'            => false,
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
				'enabled'            => false,
			],
			'products.update_stock_status' => [
				'ability_id'         => 'woocommerce/product-update',
				'candidate_tranche'  => 'C',
				'coverage_predicate' => 'single_simple_versioned_stock_status_write',
				'enabled'            => false,
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
		if ( 'orders_search_subset' !== (string) $coverage_predicate ) {
			return false;
		}

		return $this->orders_search_request_is_covered( $params );
	}

	protected function assess_ability_contract( array $mapping, $ability ) {
		if ( 'orders_search_subset' !== ( $mapping['coverage_predicate'] ?? '' ) ) {
			return [ 'accepted' => false ];
		}

		if ( ! is_callable( [ $ability, 'get_input_schema' ] ) || ! is_callable( [ $ability, 'get_output_schema' ] ) ) {
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

	protected function adapt_input( $action_name, $params ) {
		if ( 'orders.search' !== $action_name || ! is_array( $params ) ) {
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
		if ( 'orders.search' !== $action_name || ! is_array( $native_result ) ) {
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
