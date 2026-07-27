<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Result_Presentation_Contract {

	const CONTRACT_TYPE  = 'yoro.presentation.result';
	const SCHEMA_VERSION = 1;

	public static function for_action( $action_name, $action = [] ) {
		$action_name = sanitize_text_field( (string) $action_name );
		$action      = is_array( $action ) ? $action : [];
		$configured  = is_array( $action['result_contract'] ?? null ) ? $action['result_contract'] : [];
		$defaults    = self::defaults_for_action( $action_name );

		$result_type = sanitize_key( $configured['result_type'] ?? $defaults['result_type'] );
		$display_type = sanitize_key( $configured['display_type'] ?? $defaults['display_type'] );
		$primary_entity = sanitize_key( $configured['primary_entity'] ?? $defaults['primary_entity'] );

		return [
			'contract_type'       => self::CONTRACT_TYPE,
			'schema_version'      => self::SCHEMA_VERSION,
			'result_type'         => '' !== $result_type ? $result_type : 'generic',
			'display_type'        => '' !== $display_type ? $display_type : 'generic',
			'primary_entity'      => $primary_entity,
			'compound_rendering'  => 'registry',
			'identifier_policy'   => 'preserve_exact',
			'structured_fallback' => true,
		];
	}

	public static function attach_to_result( $action_name, $result, $action = [] ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$contract = self::for_action( $action_name, $action );
		$result_type = sanitize_key( $result['result_type'] ?? '' );
		$display_type = sanitize_key( $result['display_type'] ?? '' );

		if ( '' === $result_type ) {
			$result_type = $contract['result_type'];
			$result['result_type'] = $result_type;
		}

		if ( '' === $display_type ) {
			$display_type = $contract['display_type'];
			$result['display_type'] = $display_type;
		}

		$renderer_key = 'generic' !== $display_type ? $display_type : $result_type;

		$result['presentation'] = [
			'contract_type'      => self::CONTRACT_TYPE,
			'schema_version'     => self::SCHEMA_VERSION,
			'action'             => sanitize_text_field( (string) $action_name ),
			'result_type'        => $result_type,
			'display_type'       => $display_type,
			'renderer_key'       => '' !== $renderer_key ? $renderer_key : 'generic',
			'primary_entity'     => $contract['primary_entity'],
			'compound_rendering' => $contract['compound_rendering'],
			'identifier_policy'  => $contract['identifier_policy'],
		];

		return $result;
	}

	private static function defaults_for_action( $action_name ) {
		$contracts = [
			'orders.search'                        => [ 'orders', 'orders_list', 'order' ],
			'orders.get_details'                   => [ 'orders', 'order_details', 'order' ],
			'orders.find_by_identity'               => [ 'orders', 'orders_list', 'order' ],
			'orders.find_by_customer'               => [ 'orders', 'orders_list', 'order' ],
			'orders.refund_preview'                 => [ 'refund', 'refund_preview', 'order' ],
			'orders.create_refund'                  => [ 'refund', 'refund', 'order' ],
			'orders.add_tracking'                   => [ 'tracking', 'tracking', 'fulfillment' ],
			'orders.get_fulfillment'                => [ 'fulfillment_lifecycle', 'fulfillment_lifecycle', 'fulfillment' ],
			'orders.update_fulfillment'             => [ 'fulfillment_lifecycle', 'fulfillment_lifecycle', 'fulfillment' ],
			'customers.lookup'                      => [ 'customer_summary', 'customer_summary', 'customer' ],
			'customers.get_service_queue'           => [ 'customer_service_queue', 'customer_service_queue', 'customer' ],
			'products.get_details'                  => [ 'product', 'product', 'product' ],
			'products.update_catalog'               => [ 'product', 'product', 'product' ],
			'products.update_catalog_batch'         => [ 'products', 'products', 'product' ],
			'products.update_stock'                 => [ 'product', 'product', 'product' ],
			'products.update_stock_status'          => [ 'product', 'product', 'product' ],
			'products.get_inventory_queue'          => [ 'inventory_queue', 'inventory_queue', 'product' ],
			'operations.get_attention_queue'        => [ 'operations_attention_queue', 'operations_attention_queue', 'order' ],
			'operations.get_fulfillment_queue'      => [ 'fulfillment_queue', 'fulfillment_queue', 'order' ],
			'orders.compose_customer_email'         => [ 'customer_email', 'customer_email_preview', 'email' ],
			'orders.render_customer_email_preview'  => [ 'customer_email', 'customer_email_preview', 'email' ],
			'orders.get_customer_email_drafts'      => [ 'customer_email_drafts', 'customer_email_drafts', 'email' ],
			'email_templates.list'                  => [ 'email_templates', 'email_templates_list', 'email_template' ],
			'coupons.lookup'                        => [ 'coupon', 'coupon', 'coupon' ],
			'coupons.create'                        => [ 'coupon', 'coupon', 'coupon' ],
			'coupons.update'                        => [ 'coupon', 'coupon', 'coupon' ],
			'operations.get_summary'                => [ 'report', 'report', 'report' ],
			'store.get_business_summary'            => [ 'business_summary', 'business_summary', 'report' ],
			'store.get_daily_briefing'              => [ 'daily_briefing', 'daily_briefing', 'report' ],
			'store.get_insights'                    => [ 'store_insights', 'store_insights', 'report' ],
		];
		$contract = $contracts[ $action_name ] ?? null;

		if ( is_array( $contract ) ) {
			return [
				'result_type'    => $contract[0],
				'display_type'   => $contract[1],
				'primary_entity' => $contract[2],
			];
		}

		if ( 0 === strpos( $action_name, 'store.' ) || 0 === strpos( $action_name, 'finance.' ) || 0 === strpos( $action_name, 'subscriptions.get_summary' ) ) {
			return [
				'result_type'    => 'report',
				'display_type'   => 'report',
				'primary_entity' => 'report',
			];
		}

		return [
			'result_type'    => 'generic',
			'display_type'   => 'generic',
			'primary_entity' => '',
		];
	}
}
