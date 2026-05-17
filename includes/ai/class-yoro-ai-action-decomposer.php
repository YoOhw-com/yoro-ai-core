<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Action_Decomposer {

	public static function decompose( $command, $client_context = [] ) {
		if ( 'yes' !== get_option( 'yoro_ai_action_decomposition_enabled', 'yes' ) ) {
			return null;
		}

		$mock_result = apply_filters(
			'yoro_ai_action_decomposition_mock_result',
			null,
			$command,
			$client_context
		);

		if ( false === $mock_result ) {
			return null;
		}

		if ( is_array( $mock_result ) ) {
			return self::normalize_result( $mock_result );
		}

		if ( ! YoRo_AI_Provider_Resolver::supports( 'workflow_plan' ) ) {
			return null;
		}

		$payload = self::build_payload( $command, $client_context );
		$result  = YoRo_AI_Parser::parse_action_decomposition( $payload );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return null;
		}

		return self::normalize_result( $result );
	}

	private static function build_payload( $command, $client_context ) {
		$client_context = is_array( $client_context ) ? $client_context : [];
		$supported_actions = [];

		foreach ( self::supported_action_names() as $action_name ) {
			$action = YoRo_Action_Registry::get( $action_name );

			if ( empty( $action ) || ! is_array( $action ) ) {
				continue;
			}

			$supported_actions[] = [
				'name'        => $action_name,
				'label'       => sanitize_text_field( $action['label'] ?? $action_name ),
				'description' => sanitize_text_field( $action['description'] ?? '' ),
				'input_schema'=> $action['input_schema'] ?? [],
			];
		}

		return [
			'command'                => (string) $command,
			'supported_actions'      => $supported_actions,
			'known_unsupported_areas'=> [
				[ 'key' => 'shipping_label', 'label' => 'Shipping label or shipment booking' ],
				[ 'key' => 'external_sync', 'label' => 'External sync or third-party integration' ],
				[ 'key' => 'order_authoring', 'label' => 'Create or author a new order' ],
			],
			'context'                => [
				'current_order_id'         => absint( $client_context['current_order_id'] ?? 0 ),
				'last_referenced_order_id' => absint( $client_context['last_referenced_order_id'] ?? 0 ),
				'resolved_order_ids'       => ! empty( $client_context['resolved_order_ids'] ) && is_array( $client_context['resolved_order_ids'] )
					? array_values( array_map( 'absint', $client_context['resolved_order_ids'] ) )
					: [],
			],
		];
	}

	private static function supported_action_names() {
		return [
			'orders.hold',
			'orders.add_note',
			'orders.update_status',
			'orders.bulk_update_status_with_note',
			'orders.compose_customer_email',
		];
	}

	private static function normalize_result( $result ) {
		$result = is_array( $result ) ? $result : [];

		return [
			'matched'              => ! empty( $result['matched'] ),
			'confidence'           => isset( $result['confidence'] ) ? (float) $result['confidence'] : 0,
			'reason'               => sanitize_text_field( $result['reason'] ?? '' ),
			'requested_actions'    => self::normalize_requested_actions( $result['requested_actions'] ?? [] ),
			'unsupported_requests' => self::normalize_unsupported_requests( $result['unsupported_requests'] ?? [] ),
			'missing_params'       => is_array( $result['missing_params'] ?? null )
				? array_values( array_filter( array_map( 'sanitize_key', $result['missing_params'] ) ) )
				: [],
		];
	}

	private static function normalize_requested_actions( $items ) {
		$items = is_array( $items ) ? $items : [];
		$normalized = [];

		foreach ( $items as $index => $item ) {
			$action = sanitize_text_field( $item['action'] ?? '' );

			if ( '' === $action || ! in_array( $action, self::supported_action_names(), true ) ) {
				continue;
			}

			$normalized[] = [
				'id'         => sanitize_key( $item['id'] ?? 'ai_action_' . ( $index + 1 ) ),
				'label'      => sanitize_text_field( $item['label'] ?? $action ),
				'action'     => $action,
				'confidence' => isset( $item['confidence'] ) ? (float) $item['confidence'] : 0,
				'params'     => is_array( $item['params'] ?? null ) ? $item['params'] : [],
			];
		}

		return $normalized;
	}

	private static function normalize_unsupported_requests( $items ) {
		$items = is_array( $items ) ? $items : [];
		$normalized = [];

		foreach ( $items as $item ) {
			$key = sanitize_key( $item['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$normalized[] = [
				'key'    => $key,
				'label'  => sanitize_text_field( $item['label'] ?? $key ),
				'reason' => sanitize_text_field( $item['reason'] ?? '' ),
			];
		}

		return $normalized;
	}
}
