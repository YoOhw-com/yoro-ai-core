<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Integration_Registry {

	const REGISTRY_VERSION = 1;

	public static function facts() {
		$plugins             = self::plugin_inventory();
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

		return [
			'platform'              => 'wordpress',
			'registry_version'      => self::REGISTRY_VERSION,
			'integrated_adapters'   => array_values(
				array_filter(
					array_keys( $known ),
					static function( $key ) use ( $known ) {
						return ! empty( $known[ $key ]['yoro_adapter'] );
					}
				)
			),
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
