<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Native_Ability_Execution_Provider implements YoRo_Action_Execution_Provider {

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
				'enabled'            => false,
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
			return $ability->execute( is_array( $params ) ? $params : [] );
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
		// P3-002/A ships no native input/output adapters. Every production map is
		// also inactive, and this second fail-closed boundary prevents accidental
		// activation until a later reviewed tranche implements exact semantics.
		return false;
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
