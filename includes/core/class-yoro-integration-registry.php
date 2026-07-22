<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Integration_Registry {

	const REGISTRY_VERSION = 2;
	const CORE_TRACKING_ADAPTER = 'woocommerce.core_tracking';
	const SHIPMENT_TRACKING_ADAPTER = 'woocommerce.shipment_tracking';
	const WOOCOMMERCE_SUBSCRIPTIONS_ADAPTER = 'woocommerce.subscriptions';

	public static function adapters() {
		$builtin_adapters = [
			self::CORE_TRACKING_ADAPTER => [
					'key'          => self::CORE_TRACKING_ADAPTER,
					'label'        => 'WooCommerce core tracking record',
					'domain'       => 'fulfillment',
					'version'      => 1,
					'active'       => class_exists( 'WooCommerce' ),
					'capabilities' => [ 'orders.add_tracking' ],
					'callbacks'    => [
						'orders.add_tracking' => [ __CLASS__, 'write_core_tracking' ],
					],
					'source'       => 'yoro_connector_builtin',
			],
		];

		if ( function_exists( 'wc_st_add_tracking_number' ) ) {
			$builtin_adapters[ self::SHIPMENT_TRACKING_ADAPTER ] = [
				'key'          => self::SHIPMENT_TRACKING_ADAPTER,
				'label'        => 'WooCommerce Shipment Tracking',
				'domain'       => 'fulfillment',
				'version'      => 1,
				'active'       => true,
				'capabilities' => [ 'orders.add_tracking' ],
				'callbacks'    => [
					'orders.add_tracking' => [ __CLASS__, 'write_shipment_tracking' ],
				],
				'source'       => 'woocommerce_extension',
			];
		}

		if ( function_exists( 'wcs_get_subscriptions' ) ) {
			$builtin_adapters[ self::WOOCOMMERCE_SUBSCRIPTIONS_ADAPTER ] = [
				'key'          => self::WOOCOMMERCE_SUBSCRIPTIONS_ADAPTER,
				'label'        => 'WooCommerce Subscriptions',
				'domain'       => 'subscriptions',
				'version'      => 1,
				'active'       => true,
				'capabilities' => [ 'subscriptions.get_summary' ],
				'callbacks'    => [
					'subscriptions.get_summary' => [ __CLASS__, 'read_subscription_summary' ],
				],
				'source'       => 'woocommerce_extension',
			];
		}

		$adapters = apply_filters( 'yoro_integration_adapters', $builtin_adapters );

		$normalized   = [];
		$duplicate_keys = [];

		foreach ( is_array( $adapters ) ? $adapters : [] as $key => $adapter ) {
			$adapter = self::normalize_adapter( $key, $adapter );

			if ( ! empty( $adapter['key'] ) && ! isset( $duplicate_keys[ $adapter['key'] ] ) ) {
				if ( isset( $normalized[ $adapter['key'] ] ) ) {
					unset( $normalized[ $adapter['key'] ] );
					$duplicate_keys[ $adapter['key'] ] = true;
					continue;
				}

				$normalized[ $adapter['key'] ] = $adapter;
			}
		}

		return $normalized;
	}

	public static function adapter_facts() {
		return array_values(
			array_map(
				static function( $adapter ) {
					unset( $adapter['callbacks'] );

					return $adapter;
				},
				self::adapters()
			)
		);
	}

	public static function execute( $capability, $params = [], $adapter_key = '' ) {
		$capability = sanitize_text_field( (string) $capability );
		$adapter_key = sanitize_text_field( (string) $adapter_key );

		if ( '' === $adapter_key && 'orders.add_tracking' === $capability ) {
			$adapter_key = self::CORE_TRACKING_ADAPTER;
		}

		$adapters = self::adapters();
		$adapter  = $adapters[ $adapter_key ] ?? null;

		if ( ! is_array( $adapter ) || empty( $adapter['active'] ) ) {
			return new WP_Error(
				'yoro_integration_adapter_unavailable',
				__( 'The requested integration adapter is not available.', 'yoro-ai-core' )
			);
		}

		if ( ! in_array( $capability, $adapter['capabilities'], true ) ) {
			return new WP_Error(
				'yoro_integration_capability_unavailable',
				__( 'That integration adapter does not support the requested action.', 'yoro-ai-core' )
			);
		}

		$callback = $adapter['callbacks'][ $capability ] ?? null;

		if ( ! is_callable( $callback ) ) {
			return new WP_Error(
				'yoro_integration_adapter_invalid',
				__( 'The integration adapter cannot execute this action.', 'yoro-ai-core' )
			);
		}

		$result = call_user_func( $callback, is_array( $params ) ? $params : [] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return is_array( $result )
			? array_merge( $result, [ 'adapter_key' => $adapter_key ] )
			: [
				'success'     => true,
				'adapter_key' => $adapter_key,
			];
	}

	public static function supports( $capability, $adapter_key = '' ) {
		$capability = sanitize_text_field( (string) $capability );
		$adapter_key = sanitize_text_field( (string) $adapter_key );

		if ( '' === $adapter_key && 'orders.add_tracking' === $capability ) {
			$adapter_key = self::CORE_TRACKING_ADAPTER;
		}

		$adapter = self::adapters()[ $adapter_key ] ?? null;

		return is_array( $adapter )
			&& ! empty( $adapter['active'] )
			&& in_array( $capability, $adapter['capabilities'], true )
			&& is_callable( $adapter['callbacks'][ $capability ] ?? null );
	}

	public static function write_core_tracking( $params ) {
		$order = $params['order'] ?? null;
		$tracking = isset( $params['tracking'] ) && is_array( $params['tracking'] ) ? $params['tracking'] : [];
		$tracking_number = sanitize_text_field( $tracking['tracking_number'] ?? '' );

		if ( ! $order instanceof WC_Order || '' === $tracking_number ) {
			return new WP_Error(
				'yoro_invalid_tracking_contract',
				__( 'A valid order and tracking number are required.', 'yoro-ai-core' )
			);
		}

		$order->update_meta_data( '_yoro_tracking_number', $tracking_number );
		$order->update_meta_data( '_yoro_tracking_carrier', sanitize_text_field( $tracking['carrier'] ?? '' ) );
		$order->update_meta_data( '_yoro_tracking_url', esc_url_raw( $tracking['tracking_url'] ?? '' ) );
		$order->update_meta_data( '_yoro_tracking_shipped_at', sanitize_text_field( $tracking['shipped_at'] ?? '' ) );
		$order->update_meta_data( '_yoro_tracking_adapter', self::CORE_TRACKING_ADAPTER );
		$order->save();
		$carrier = sanitize_text_field( $tracking['carrier'] ?? '' );

		if ( '' === $carrier ) {
			$carrier = __( 'Tracking', 'yoro-ai-core' );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: shipping carrier, 2: tracking number */
				__( '[YoRo] Added shipment tracking: %1$s %2$s.', 'yoro-ai-core' ),
				$carrier,
				$tracking_number
			),
			0,
			true
		);

		return [
			'success'  => true,
			'tracking' => [
				'tracking_number' => $tracking_number,
				'carrier'         => sanitize_text_field( $tracking['carrier'] ?? '' ),
				'tracking_url'    => esc_url_raw( $tracking['tracking_url'] ?? '' ),
				'shipped_at'      => sanitize_text_field( $tracking['shipped_at'] ?? '' ),
			],
		];
	}

	public static function write_shipment_tracking( $params ) {
		$order           = $params['order'] ?? null;
		$tracking        = isset( $params['tracking'] ) && is_array( $params['tracking'] ) ? $params['tracking'] : [];
		$tracking_number = sanitize_text_field( $tracking['tracking_number'] ?? '' );

		if ( ! function_exists( 'wc_st_add_tracking_number' ) ) {
			return new WP_Error(
				'yoro_integration_adapter_unavailable',
				__( 'WooCommerce Shipment Tracking is not available.', 'yoro-ai-core' )
			);
		}

		if ( ! $order instanceof WC_Order || '' === $tracking_number ) {
			return new WP_Error(
				'yoro_invalid_tracking_contract',
				__( 'A valid order and tracking number are required.', 'yoro-ai-core' )
			);
		}

		$provider    = sanitize_text_field( $tracking['carrier'] ?? '' );
		$tracking_url = esc_url_raw( $tracking['tracking_url'] ?? '' );
		$date_shipped = self::shipment_tracking_timestamp( $tracking['shipped_at'] ?? '' );
		$reflection  = new ReflectionFunction( 'wc_st_add_tracking_number' );

		if ( $reflection->getNumberOfParameters() >= 5 ) {
			wc_st_add_tracking_number( $order->get_id(), $tracking_number, $provider, $date_shipped, $tracking_url );
		} else {
			wc_st_add_tracking_number( $order->get_id(), $tracking_number, $provider, $date_shipped );
		}

		return [
			'success'  => true,
			'tracking' => [
				'tracking_number' => $tracking_number,
				'carrier'         => $provider,
				'tracking_url'    => $tracking_url,
				'shipped_at'      => sanitize_text_field( $tracking['shipped_at'] ?? '' ),
			],
		];
	}

	public static function read_subscription_summary( $params ) {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return new WP_Error(
				'yoro_integration_adapter_unavailable',
				__( 'WooCommerce Subscriptions is not available.', 'yoro-ai-core' )
			);
		}

		$params     = is_array( $params ) ? $params : [];
		$window_key = YoRo_Date_Helper::window_key_from_params( $params, 'window', 'today' );
		$requested_window = YoRo_Date_Helper::resolve_window( $window_key );

		if ( empty( $requested_window ) ) {
			return new WP_Error(
				'yoro_invalid_subscription_window',
				__( 'Please provide a valid subscription summary time window.', 'yoro-ai-core' )
			);
		}

		$subscriptions = wcs_get_subscriptions(
			[
				'subscriptions_per_page' => 500,
				'subscription_status'    => 'any',
				'orderby'                => 'date',
				'order'                  => 'DESC',
			]
		);
		$status_counts = [];
		$currency_totals = [];

		foreach ( is_array( $subscriptions ) ? $subscriptions : [] as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_status' ) ) {
				continue;
			}

			$status   = sanitize_key( $subscription->get_status() );
			$currency = method_exists( $subscription, 'get_currency' ) ? sanitize_text_field( $subscription->get_currency() ) : '';
			$total    = method_exists( $subscription, 'get_total' ) ? (float) $subscription->get_total() : 0.0;
			$status_counts[ $status ] = ( $status_counts[ $status ] ?? 0 ) + 1;
			$currency_totals[ $currency ] = ( $currency_totals[ $currency ] ?? 0.0 ) + $total;
		}

		return [
			'success'         => true,
			'action'          => 'subscriptions.get_summary',
			'summary'         => sprintf(
				/* translators: %d: subscription count */
				__( 'Read %d subscriptions through the registered WooCommerce Subscriptions adapter.', 'yoro-ai-core' ),
				array_sum( $status_counts )
			),
			'count'           => array_sum( $status_counts ),
			'status_counts'   => $status_counts,
			'currency_totals' => array_map( static fn( $total ) => wc_format_decimal( $total, 2 ), $currency_totals ),
			'coverage_contract' => [
				'requested_window'   => YoRo_Date_Helper::build_window_payload( $requested_window ),
				'observation_kind'   => 'live_snapshot',
				'historical_evidence' => false,
				'observed_at'        => gmdate( DATE_ATOM ),
			],
			'read_only'       => true,
			'changes_store'   => false,
		];
	}

	public static function facts() {
		$plugins             = self::plugin_inventory();
		$adapter_facts       = self::adapter_facts();
		$active_plugins      = array_values(
			array_filter(
				$plugins,
				static function( $plugin ) {
					return ! empty( $plugin['active'] );
				}
			)
		);
		$shipping_extensions = array_values(
			array_filter(
				$plugins,
				static function( $plugin ) {
					return ! empty( $plugin['active'] ) && 'shipping' === sanitize_key( $plugin['domain'] ?? '' );
				}
			)
		);

		$known = [
			'woocommerce'               => self::woocommerce_adapter_status(),
			'woocommerce_subscriptions' => self::presence_only_status(
				$plugins,
				'WooCommerce Subscriptions',
				[ 'woocommerce subscriptions' ],
				[ 'woocommerce-subscriptions', 'woocommerce_subscriptions' ],
				'subscriptions'
			),
			'flexible_shipping'         => self::presence_only_status(
				$plugins,
				'Flexible Shipping',
				[ 'flexible shipping', 'flexibleshipping' ],
				[ 'flexible-shipping', 'flexible_shipping' ],
				'shipping'
			),
		];
		$integrated_adapters = array_values(
			array_filter(
				array_map(
					static function( $adapter ) {
						return ! empty( $adapter['active'] ) ? sanitize_text_field( $adapter['key'] ?? '' ) : '';
					},
					$adapter_facts
				)
			)
		);

		foreach ( $known as $key => $integration ) {
			if ( ! empty( $integration['yoro_adapter'] ) && ! in_array( $key, $integrated_adapters, true ) ) {
				$integrated_adapters[] = sanitize_key( $key );
			}
		}

		return [
			'platform'              => 'wordpress',
			'registry_version'      => self::REGISTRY_VERSION,
			'integrated_adapters'   => $integrated_adapters,
			'adapters'              => $adapter_facts,
			'installed_plugins'     => array_slice( $plugins, 0, 60 ),
			'active_plugins'        => array_slice( $active_plugins, 0, 30 ),
			'shipping_extensions'   => array_slice( $shipping_extensions, 0, 12 ),
			'known'                 => $known,
			'integration_boundary'  => [
				'only_integrated_adapters_are_inspectable_or_actionable' => true,
				'detected_plugin_without_adapter_is_presence_only'       => true,
				'capability_matrix_is_authoritative'                     => true,
				'general_product_knowledge_must_be_labeled_general_not_store_specific' => true,
			],
			'capability_schema'     => [
				'store_specific_supported' => 'True only when YoRo has a working adapter for this integration.',
				'read_settings'            => 'YoRo can inspect current settings for this integration.',
				'inspect_rules'            => 'YoRo can inspect configured rules or runtime logic for this integration.',
				'execute_actions'          => 'YoRo can execute registered live actions for this integration, subject to confirmation policy.',
				'modify_settings'          => 'YoRo can write or change settings for this integration.',
				'general_guidance'         => 'YoRo may give general guidance, clearly labeled as not based on live rule inspection.',
			],
		];
	}

	private static function normalize_adapter( $fallback_key, $adapter ) {
		if ( ! is_array( $adapter )
			|| ! array_key_exists( 'key', $adapter )
			|| ! array_key_exists( 'domain', $adapter )
			|| ! array_key_exists( 'version', $adapter )
			|| ! array_key_exists( 'capabilities', $adapter )
			|| ! array_key_exists( 'callbacks', $adapter ) ) {
			return [];
		}

		$key = sanitize_text_field( $adapter['key'] );

		if ( is_string( $fallback_key ) && sanitize_text_field( $fallback_key ) !== $key ) {
			return [];
		}

		$domain  = sanitize_key( $adapter['domain'] );
		$version = absint( $adapter['version'] );
		$capabilities = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_text_field', (array) ( $adapter['capabilities'] ?? [] ) )
				)
			)
		);

		if ( '' === $key || '' === $domain || $version < 1 || empty( $capabilities ) ) {
			return [];
		}

		$callbacks = [];

		foreach ( is_array( $adapter['callbacks'] ?? null ) ? $adapter['callbacks'] : [] as $capability => $callback ) {
			$capability = sanitize_text_field( $capability );

			if ( in_array( $capability, $capabilities, true ) && is_callable( $callback ) ) {
				$callbacks[ $capability ] = $callback;
			}
		}

		if ( count( $callbacks ) !== count( $capabilities )
			|| array_diff( $capabilities, array_keys( $callbacks ) )
			|| array_diff( array_keys( $callbacks ), $capabilities ) ) {
			return [];
		}

		return [
			'key'          => $key,
			'label'        => sanitize_text_field( $adapter['label'] ?? $key ),
			'domain'       => $domain,
			'version'      => $version,
			'active'       => ! empty( $adapter['active'] ),
			'capabilities' => $capabilities,
			'callbacks'    => $callbacks,
			'source'       => sanitize_key( $adapter['source'] ?? 'connector_extension' ),
		];
	}

	private static function shipment_tracking_timestamp( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return time();
		}

		try {
			$date = new DateTimeImmutable( $value, wp_timezone() );

			return $date->getTimestamp();
		} catch ( Exception $exception ) {
			return time();
		}
	}

	private static function woocommerce_adapter_status() {
		$active = class_exists( 'WooCommerce' );

		return [
			'label'                    => 'WooCommerce',
			'category'                 => 'commerce_platform',
			'detected'                 => $active,
			'installed'                => $active,
			'active'                   => $active,
			'yoro_adapter'             => true,
			'store_specific_supported' => $active,
			'integration_level'        => $active ? 'integrated' : 'not_detected',
			'capabilities'             => [
				'read_settings'      => $active,
				'read_schema'        => $active,
				'read_orders'        => $active,
				'read_products'      => $active,
				'read_reports'       => $active,
				'inspect_rules'      => $active,
				'execute_actions'    => $active,
				'modify_settings'    => false,
				'modify_rules'       => false,
				'general_guidance'   => true,
			],
			'can_inspect_rules'        => $active,
			'can_modify_rules'         => false,
			'source'                   => $active ? 'yoro_integrated_adapter_registry' : 'not_detected',
		];
	}

	private static function presence_only_status( $plugins, $label, $name_needles, $file_needles, $category ) {
		$matches = self::plugin_matches( $plugins, $name_needles, $file_needles );
		$installed = ! empty( $matches );
		$active    = ! empty(
			array_filter(
				$matches,
				static function( $plugin ) {
					return ! empty( $plugin['active'] );
				}
			)
		);

		return [
			'label'                    => sanitize_text_field( $label ),
			'category'                 => sanitize_key( $category ),
			'detected'                 => $installed,
			'installed'                => $installed,
			'active'                   => $active,
			'matches'                  => array_slice( $matches, 0, 5 ),
			'yoro_adapter'             => false,
			'store_specific_supported' => false,
			'integration_level'        => $active ? 'detected_not_integrated' : ( $installed ? 'installed_inactive_not_integrated' : 'not_detected' ),
			'capabilities'             => [
				'read_settings'      => false,
				'read_schema'        => false,
				'inspect_rules'      => false,
				'execute_actions'    => false,
				'modify_settings'    => false,
				'modify_rules'       => false,
				'general_guidance'   => true,
			],
			'can_inspect_rules'        => false,
			'can_modify_rules'         => false,
			'source'                   => $installed ? 'wordpress_plugin_inventory_without_yoro_adapter' : 'not_detected_in_wordpress_plugin_inventory',
		];
	}

	private static function plugin_matches( $plugins, $name_needles, $file_needles ) {
		$matches = [];

		foreach ( is_array( $plugins ) ? $plugins : [] as $plugin ) {
			$name    = strtolower( sanitize_text_field( $plugin['name'] ?? '' ) );
			$file    = strtolower( sanitize_text_field( $plugin['file'] ?? '' ) );
			$matched = false;

			foreach ( (array) $name_needles as $needle ) {
				if ( '' !== $needle && false !== strpos( $name, strtolower( $needle ) ) ) {
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				foreach ( (array) $file_needles as $needle ) {
					if ( '' !== $needle && false !== strpos( $file, strtolower( $needle ) ) ) {
						$matched = true;
						break;
					}
				}
			}

			if ( $matched ) {
				$matches[] = $plugin;
			}
		}

		return $matches;
	}

	private static function plugin_inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = function_exists( 'get_plugins' ) ? get_plugins() : [];
		$items     = [];

		foreach ( $installed as $file => $data ) {
			$name = sanitize_text_field( $data['Name'] ?? $file );
			$file = sanitize_text_field( (string) $file );

			if ( '' === $file || '' === $name ) {
				continue;
			}

			$items[] = [
				'slug'    => self::plugin_slug_from_file( $file ),
				'name'    => $name,
				'file'    => $file,
				'version' => sanitize_text_field( $data['Version'] ?? '' ),
				'active'  => function_exists( 'is_plugin_active' ) ? (bool) is_plugin_active( $file ) : in_array( $file, (array) get_option( 'active_plugins', [] ), true ),
				'domain'  => self::plugin_domain_hint( $name, $file ),
			];
		}

		return $items;
	}

	private static function plugin_slug_from_file( $file ) {
		$parts = explode( '/', sanitize_text_field( (string) $file ) );
		$slug  = $parts[0] ?? $file;

		return sanitize_key( $slug );
	}

	private static function plugin_domain_hint( $name, $file ) {
		$text = strtolower( sanitize_text_field( (string) $name . ' ' . (string) $file ) );

		if ( preg_match( '/\b(?:shipping|shipment|delivery|rate|rates|carrier|fulfillment)\b/', $text ) ) {
			return 'shipping';
		}

		if ( preg_match( '/\b(?:payment|gateway|stripe|paypal|checkout)\b/', $text ) ) {
			return 'payments';
		}

		if ( preg_match( '/\b(?:inventory|stock|warehouse)\b/', $text ) ) {
			return 'inventory';
		}

		if ( preg_match( '/\b(?:email|mail|smtp|newsletter)\b/', $text ) ) {
			return 'email';
		}

		if ( preg_match( '/\b(?:subscription|subscriptions|recurring|renewal)\b/', $text ) ) {
			return 'subscriptions';
		}

		return 'general';
	}
}
