<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Intent_Router {

	public static function map_intent_name_to_action( $intent ) {
		$intent = sanitize_key( $intent );

		switch ( $intent ) {
			case 'email_customer':
				return 'orders.compose_customer_email';

			case 'add_note':
				return 'orders.add_note';

			case 'hold_order':
				return 'orders.hold';

			case 'update_order_status':
				return 'orders.update_status';

			case 'search_orders':
				return 'orders.search';

			case 'get_order_details':
				return 'orders.get_details';

			case 'find_customer_orders':
				return 'orders.find_by_customer';

			case 'business_summary':
				return 'store.get_business_summary';

			case 'operations_overview':
				return 'operations.get_summary';

			case 'operations_attention':
				return 'operations.get_attention_queue';

			case 'fulfillment_review':
				return 'operations.get_fulfillment_queue';

			case 'inventory_review':
				return 'products.get_inventory_queue';

			case 'customer_service_review':
				return 'customers.get_service_queue';

			case 'store_briefing':
				return 'store.get_daily_briefing';
		}

		return '';
	}

	public static function map_action_to_intent_name( $action ) {
		$action = sanitize_text_field( $action );

		$map = [
			'orders.compose_customer_email'    => 'email_customer',
			'orders.add_note'                  => 'add_note',
			'orders.hold'                      => 'hold_order',
			'orders.update_status'             => 'update_order_status',
			'orders.search'                    => 'search_orders',
			'orders.get_details'               => 'get_order_details',
			'orders.find_by_customer'          => 'find_customer_orders',
			'store.get_business_summary'       => 'business_summary',
			'operations.get_summary'           => 'operations_overview',
			'operations.get_attention_queue'   => 'operations_attention',
			'operations.get_fulfillment_queue' => 'fulfillment_review',
			'products.get_inventory_queue'     => 'inventory_review',
			'customers.get_service_queue'      => 'customer_service_review',
			'store.get_daily_briefing'         => 'store_briefing',
		];

		return $map[ $action ] ?? '';
	}

	public static function resolve_action( $parsed ) {
		if ( empty( $parsed['intent']['name'] ) ) {
			return $parsed;
		}

		$intent = $parsed['intent']['name'];

		$mapped_action = self::map_intent_name_to_action( $intent );

		if ( ! empty( $mapped_action ) ) {
			$parsed['action'] = $mapped_action;
		}

	if ( empty( $parsed['action'] ) ) {
			YoRo_Logger::log(
				[
					'type'    => 'intent_router_miss',
					'command' => $parsed['original_command'] ?? '',
					'intent'  => $intent,
					'status'  => 'unmapped',
				]
			);
		}

		return $parsed;
	}
}
