<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Capability_Registry {

	public static function get_all() {
		return [
			'woocommerce_core_operations' => [
				'key'                       => 'woocommerce_core_operations',
				'label'                     => __( 'WooCommerce core operations', 'yoro-agent-core' ),
				'status'                    => 'supported',
				'nearest_supported_surfaces'=> [ 'morning_briefing', 'operations_attention', 'latest_orders' ],
				'developer_handoff_allowed' => false,
			],
			'shipping_extensions' => [
				'key'                       => 'shipping_extensions',
				'label'                     => __( 'Shipping extensions and shipment-related tooling', 'yoro-agent-core' ),
				'status'                    => 'partially_supported',
				'nearest_supported_surfaces'=> [ 'fulfillment_queue', 'order_details', 'operations_summary' ],
				'developer_handoff_allowed' => true,
			],
			'plugin_compatibility_questions' => [
				'key'                       => 'plugin_compatibility_questions',
				'label'                     => __( 'Plugin compatibility questions', 'yoro-agent-core' ),
				'status'                    => 'unknown',
				'nearest_supported_surfaces'=> [ 'none' ],
				'developer_handoff_allowed' => true,
			],
			'third_party_crm_and_team_integrations' => [
				'key'                       => 'third_party_crm_and_team_integrations',
				'label'                     => __( 'CRM, Slack, and external integrations', 'yoro-agent-core' ),
				'status'                    => 'unsupported',
				'nearest_supported_surfaces'=> [ 'operations_attention', 'morning_briefing', 'latest_orders' ],
				'developer_handoff_allowed' => true,
			],
			'order_authoring' => [
				'key'                       => 'order_authoring',
				'label'                     => __( 'Order creation and authoring', 'yoro-agent-core' ),
				'status'                    => 'unsupported',
				'nearest_supported_surfaces'=> [ 'latest_orders', 'customer_summary', 'order_details' ],
				'developer_handoff_allowed' => true,
			],
			'general_world_knowledge' => [
				'key'                       => 'general_world_knowledge',
				'label'                     => __( 'General knowledge outside store operations', 'yoro-agent-core' ),
				'status'                    => 'unsupported',
				'nearest_supported_surfaces'=> [ 'morning_briefing', 'operations_summary' ],
				'developer_handoff_allowed' => false,
			],
		];
	}

	public static function get_family( $key ) {
		$key = sanitize_key( $key );
		$all = self::get_all();

		return isset( $all[ $key ] ) ? $all[ $key ] : [];
	}

	public static function resolve_target_family( $target = '', $target_type = '', $intent_family = '', $parser_family = '' ) {
		$parser_family = sanitize_key( $parser_family );
		$intent_family = sanitize_key( $intent_family );
		$target_type   = sanitize_key( $target_type );
		$target        = strtolower( trim( sanitize_text_field( $target ) ) );

		$map = [
			'core_platform'       => 'woocommerce_core_operations',
			'shipping_extension'  => 'shipping_extensions',
			'tracking_extension'  => 'shipping_extensions',
			'carrier_integration' => 'shipping_extensions',
			'plugin_compatibility'=> 'plugin_compatibility_questions',
			'capability_question' => 'plugin_compatibility_questions',
			'external_integration'=> 'third_party_crm_and_team_integrations',
			'order_creation'      => 'order_authoring',
			'shipping_operation'  => 'shipping_extensions',
			'general_knowledge'   => 'general_world_knowledge',
			'news_weather_sports' => 'general_world_knowledge',
			'person_or_entity_background' => 'general_world_knowledge',
		];

		if ( isset( $map[ $parser_family ] ) ) {
			return $map[ $parser_family ];
		}

		if ( isset( $map[ $intent_family ] ) ) {
			return $map[ $intent_family ];
		}

		if ( 'plugin' === $target_type || 'extension' === $target_type ) {
			return 'plugin_compatibility_questions';
		}

		if ( '' !== $target && preg_match( '/\b(?:slack|hubspot|crm|mailchimp|zapier|google sheets|google drive|notion)\b/i', $target ) ) {
			return 'third_party_crm_and_team_integrations';
		}

		if ( '' !== $target && preg_match( '/\b(?:shipping|shipment|tracking|dhl|fedex|ups|usps)\b/i', $target ) ) {
			return 'shipping_extensions';
		}

		return 'plugin_compatibility_questions';
	}

	public static function build_support_decision( $parser_family, $target_name = '', $plugin_match = [], $overrides = [] ) {
		$parser_family   = sanitize_key( $parser_family );
		$overrides       = is_array( $overrides ) ? $overrides : [];
		$capability_key  = sanitize_key( $overrides['capability_key_override'] ?? '' );
		if ( '' === $capability_key || 'none' === $capability_key ) {
			$capability_key = self::resolve_target_family( $target_name, '', '', $parser_family );
		}
		$record          = self::get_family( $capability_key );
		$target_label    = '' !== $target_name ? sanitize_text_field( $target_name ) : __( 'that tool', 'yoro-agent-core' );
		$plugin_label    = ! empty( $plugin_match['name'] ) ? sanitize_text_field( $plugin_match['name'] ) : $target_label;
		$plugin_active   = ! empty( $plugin_match['active'] );
		$status_override = sanitize_key( $overrides['status_override'] ?? '' );
		$requested_capability = sanitize_text_field( $overrides['requested_capability'] ?? '' );
		$status          = '' !== $status_override && 'none' !== $status_override
			? $status_override
			: sanitize_key( $record['status'] ?? 'unknown' );
		$surface_map     = [
			'morning_briefing'    => 'morning_briefing',
			'operations_attention'=> 'operations_attention',
			'latest_orders'       => 'latest_orders',
			'fulfillment_queue'   => 'fulfillment_queue',
			'order_details'       => 'order_details',
			'operations_summary'  => 'operations_summary',
			'none'                => 'none',
		];
		$nearest_surfaces = isset( $record['nearest_supported_surfaces'] ) && is_array( $record['nearest_supported_surfaces'] )
			? $record['nearest_supported_surfaces']
			: [];
		$primary_surface = ! empty( $nearest_surfaces ) ? sanitize_key( $nearest_surfaces[0] ) : 'none';
		$surface         = isset( $surface_map[ $primary_surface ] ) ? $surface_map[ $primary_surface ] : 'none';

		$decision = [
			'capability_key'         => $capability_key,
			'status'                 => $status,
			'surface'                => $surface,
			'nearest_supported_surfaces' => $nearest_surfaces,
			'developer_handoff_offer'=> ! empty( $record['developer_handoff_allowed'] ),
			'summary'                => '',
			'followup'               => '',
		];

		switch ( $capability_key ) {
			case 'woocommerce_core_operations':
				$decision['summary'] = '' !== $target_name && ! in_array( strtolower( $target_name ), [ 'woocommerce', 'woocommerce core', 'woo commerce' ], true )
					? sprintf( __( 'I support WooCommerce core store operations, but I don’t confirm direct compatibility with %s from here.', 'yoro-agent-core' ), $target_label )
					: __( 'Yes. I support WooCommerce store operations directly.', 'yoro-agent-core' );
				$decision['followup'] = __( 'I can help with briefings, queues, order review, email follow-up, and guided operational actions inside WooCommerce.', 'yoro-agent-core' );
				break;

			case 'shipping_extensions':
				if ( 'carrier_integration' === $parser_family ) {
					$decision['status'] = 'unsupported';
					$decision['summary'] = '' !== $target_label
						? sprintf( __( 'I can’t connect directly to %s yet.', 'yoro-agent-core' ), $target_label )
						: __( 'I can’t connect directly to that carrier flow yet.', 'yoro-agent-core' );
					$decision['followup'] = __( 'I can still review fulfillment and shipment-related store activity. If direct carrier support matters here, I can pass that request to the developers.', 'yoro-agent-core' );
				} elseif ( 'tracking_extension' === $parser_family ) {
					$decision['summary'] = sprintf( __( 'I can read some tracking-related signals around %s, but I can’t confirm full compatibility yet.', 'yoro-agent-core' ), $plugin_label );
					$decision['followup'] = __( 'I can still review tracked versus untracked fulfillment cases. If you need plugin-level compatibility confirmed, I can pass that request to the developers.', 'yoro-agent-core' );
				} else {
					$decision['summary'] = '' !== $requested_capability
						? sprintf( __( 'I can’t confirm direct support for %s in %s yet.', 'yoro-agent-core' ), strtolower( $requested_capability ), $plugin_label )
						: sprintf( __( 'I can’t confirm direct compatibility with %s yet.', 'yoro-agent-core' ), $plugin_label );
					$decision['followup'] = $plugin_active
						? __( 'I can still inspect shipping-related orders, fulfillment pressure, and shipping signals I already understand. If you need confirmed plugin-level support, I can pass that request to the developers.', 'yoro-agent-core' )
						: __( 'I can still inspect shipping-related orders and fulfillment flow. If you need confirmed plugin-level support, I can pass that request to the developers.', 'yoro-agent-core' );
				}
				break;

			case 'third_party_crm_and_team_integrations':
				$decision['summary'] = '' !== $requested_capability && '' !== $target_label
					? sprintf( __( 'I can’t handle %s with %s yet.', 'yoro-agent-core' ), strtolower( $requested_capability ), $target_label )
					: sprintf( __( 'I can’t connect directly to %s yet.', 'yoro-agent-core' ), $target_label );
				$decision['followup'] = __( 'If you want, I can pass that integration request to the developers. For now, I can still help with the store work I already support.', 'yoro-agent-core' );
				break;

			case 'order_authoring':
				$decision['summary'] = '' !== $requested_capability
					? sprintf( __( 'I can’t support %s yet.', 'yoro-agent-core' ), strtolower( $requested_capability ) )
					: __( 'I can’t create new orders yet.', 'yoro-agent-core' );
				$decision['followup'] = __( 'If you want, I can pass that request to the developers. For now, I can help you inspect or manage existing orders.', 'yoro-agent-core' );
				break;

			case 'plugin_compatibility_questions':
			default:
				$decision['summary'] = '' !== $requested_capability
					? sprintf( __( 'I can’t confirm direct support for %s in %s yet.', 'yoro-agent-core' ), strtolower( $requested_capability ), $plugin_label )
					: sprintf( __( 'I can’t confirm direct compatibility with %s yet.', 'yoro-agent-core' ), $plugin_label );
				$decision['followup'] = $plugin_active
					? __( 'I can see the plugin is active, but I still do not confirm deep compatibility from here. If you want, I can pass that request to the developers and help with the closest supported store workflow for now.', 'yoro-agent-core' )
					: __( 'If you want, I can pass that compatibility request to the developers and help with the closest supported store workflow for now.', 'yoro-agent-core' );
				break;
		}

		return $decision;
	}

	public static function build_unsupported_copy( $parser_family = '', $result = [], $capability_key = '' ) {
		$parser_family  = sanitize_key( $parser_family );
		$capability_key = sanitize_key( $capability_key );
		$result         = is_array( $result ) ? $result : [];
		$target_object  = sanitize_text_field( $result['target_object'] ?? '' );
		$requested_capability = sanitize_text_field( $result['requested_capability'] ?? '' );
		$target_label   = '' !== $target_object ? $target_object : $requested_capability;

		if ( '' === $capability_key || 'none' === $capability_key ) {
			$capability_key = self::resolve_target_family( '', '', '', $parser_family );
		}

		switch ( $capability_key ) {
			case 'order_authoring':
				return [
					'summary'  => '' !== $requested_capability
						? sprintf( __( 'I can’t handle %s yet.', 'yoro-agent-core' ), strtolower( $requested_capability ) )
						: __( 'I can’t create a new order yet.', 'yoro-agent-core' ),
					'followup' => __( 'If you want, I can pass that request to the developers. For now, I can help you inspect or manage existing orders.', 'yoro-agent-core' ),
				];
			case 'shipping_extensions':
				return [
					'summary'  => '' !== $requested_capability
						? sprintf( __( 'I can’t handle %s yet.', 'yoro-agent-core' ), strtolower( $requested_capability ) )
						: __( 'I can’t create shipments or shipping labels yet.', 'yoro-agent-core' ),
					'followup' => __( 'If you want, I can pass that request to the developers. For now, I can help you review the order or the fulfillment queue.', 'yoro-agent-core' ),
				];
			case 'third_party_crm_and_team_integrations':
				return [
					'summary'  => '' !== $target_label
						? sprintf( __( 'I can’t connect directly to %s yet.', 'yoro-agent-core' ), $target_label )
						: __( 'I can’t connect to that external tool yet.', 'yoro-agent-core' ),
					'followup' => __( 'If you want, I can pass that integration request to the developers. For now, I can help with the store work I already support.', 'yoro-agent-core' ),
				];
			default:
				$summary  = sanitize_text_field( $result['summary'] ?? '' );
				$followup = sanitize_text_field( $result['followup'] ?? '' );

				return [
					'summary'  => '' !== $summary ? $summary : __( 'I can’t do that yet.', 'yoro-agent-core' ),
					'followup' => '' !== $followup ? $followup : __( 'If you want, I can pass that request to the developers and help you with the closest supported option for now.', 'yoro-agent-core' ),
				];
		}
	}

	public static function build_unsupported_decision( $parser_family = '', $result = [], $capability_key = '', $request_text = '' ) {
		$parser_family  = sanitize_key( $parser_family );
		$capability_key = sanitize_key( $capability_key );
		$request_text   = sanitize_text_field( $request_text );
		$result         = is_array( $result ) ? $result : [];
		$nearest_registry_family = sanitize_key( $result['nearest_registry_family'] ?? '' );
		$support_hypothesis = sanitize_key( $result['support_hypothesis'] ?? '' );
		$target_object = sanitize_text_field( $result['target_object'] ?? '' );
		$requested_capability = sanitize_text_field( $result['requested_capability'] ?? '' );

		if ( ( '' === $capability_key || 'none' === $capability_key ) && '' !== $nearest_registry_family && 'none' !== $nearest_registry_family ) {
			$capability_key = $nearest_registry_family;
		}

		if ( '' === $capability_key || 'none' === $capability_key ) {
			$capability_key = self::resolve_target_family( '', '', '', $parser_family );
		}

		$record  = self::get_family( $capability_key );
		$copy    = self::build_unsupported_copy( $parser_family, $result, $capability_key );
		$surface = '';

		if ( ! empty( $record['nearest_supported_surfaces'] ) && is_array( $record['nearest_supported_surfaces'] ) ) {
			$surface = sanitize_key( (string) reset( $record['nearest_supported_surfaces'] ) );
		}

		return [
			'capability_key'          => $capability_key,
			'status'                  => in_array( $support_hypothesis, [ 'supported', 'partially_supported', 'unknown', 'unsupported' ], true )
				? $support_hypothesis
				: sanitize_key( $record['status'] ?? 'unsupported' ),
			'summary'                 => $copy['summary'] ?? __( 'I can’t do that yet.', 'yoro-agent-core' ),
			'followup'                => $copy['followup'] ?? __( 'If you want, I can pass that request to the developers.', 'yoro-agent-core' ),
			'closest_supported_surface' => $surface,
			'developer_handoff_offer' => ! empty( $record['developer_handoff_allowed'] ),
			'handoff_request_text'    => '' !== $request_text
				? $request_text
				: ( '' !== $requested_capability ? $requested_capability : $target_object ),
			'suggestions'             => ! empty( $record['developer_handoff_allowed'] )
				? [
					[
						'label' => __( 'Yes, send this to developers', 'yoro-agent-core' ),
						'value' => 'Yes, send this to developers',
					],
				]
				: [],
		];
	}

	public static function build_support_suggestions( $decision = [], $client_context = [], $normalized = '', $include_handoff = false ) {
		$decision       = is_array( $decision ) ? $decision : [];
		$capability_key = sanitize_key( $decision['capability_key'] ?? '' );
		$surfaces       = isset( $decision['nearest_supported_surfaces'] ) && is_array( $decision['nearest_supported_surfaces'] )
			? $decision['nearest_supported_surfaces']
			: [];
		$suggestions    = [];

		if ( $include_handoff ) {
			$suggestions[] = [
				'label' => __( 'Yes, send this to developers', 'yoro-agent-core' ),
				'value' => 'Yes, send this to developers',
			];

			if ( 'woocommerce_core_operations' !== $capability_key ) {
				return $suggestions;
			}
		}

		foreach ( $surfaces as $surface ) {
			$command_item = self::map_surface_to_command( $surface, $client_context, $normalized );

			if ( ! is_array( $command_item ) || empty( $command_item['value'] ) ) {
				continue;
			}

			$suggestions[] = $command_item;
		}

		return array_slice( self::dedupe_suggestions( $suggestions ), 0, 3 );
	}

	private static function map_surface_to_command( $surface, $client_context = [], $normalized = '' ) {
		$surface    = sanitize_key( $surface );
		$normalized = trim( (string) $normalized );
		$order_id   = 0;

		if ( ! empty( $client_context['resolved_order_ids'] ) && is_array( $client_context['resolved_order_ids'] ) ) {
			$order_id = absint( reset( $client_context['resolved_order_ids'] ) );
		}

		if ( ! $order_id && ! empty( $client_context['order_id'] ) ) {
			$order_id = absint( $client_context['order_id'] );
		}

		switch ( $surface ) {
			case 'morning_briefing':
				return [
					'label' => __( 'Show morning briefing', 'yoro-agent-core' ),
					'value' => 'Show morning briefing',
				];
			case 'operations_attention':
				return [
					'label' => __( 'Show operations attention queue', 'yoro-agent-core' ),
					'value' => 'Show operations attention queue',
				];
			case 'operations_summary':
				return [
					'label' => __( 'Show operations summary for today', 'yoro-agent-core' ),
					'value' => 'Show operations summary for today',
				];
			case 'latest_orders':
				return [
					'label' => __( 'Show latest 5 orders', 'yoro-agent-core' ),
					'value' => 'Show latest 5 orders',
				];
			case 'fulfillment_queue':
				return [
					'label' => __( 'Show fulfillment queue', 'yoro-agent-core' ),
					'value' => 'Show fulfillment queue',
				];
			case 'order_details':
				if (
					$order_id &&
					preg_match( '/\b(?:this order|order\b|customer\b|shipment\b|tracking\b)\b/i', $normalized )
				) {
					return [
						'label' => sprintf( __( 'Show order #%d details', 'yoro-agent-core' ), $order_id ),
						'value' => 'Show order details for this order',
					];
				}
				return [];
			case 'customer_summary':
				return [
					'label' => __( 'Show customer summary', 'yoro-agent-core' ),
					'value' => 'Show customer summary',
				];
			default:
				return [];
		}
	}

	private static function dedupe_suggestions( $suggestions ) {
		$output = [];
		$seen   = [];

		foreach ( (array) $suggestions as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( $item['label'] ?? '' );
			$value = sanitize_text_field( $item['value'] ?? $item['command'] ?? '' );

			if ( '' === $label || '' === $value ) {
				continue;
			}

			$key = strtolower( $value );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$output[] = [
				'label' => $label,
				'value' => $value,
			];
		}

		return $output;
	}
}
