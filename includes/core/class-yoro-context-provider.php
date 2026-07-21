<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Context_Provider {

	public static function get_context() {
		return [
			'store'        => self::get_store_context(),
			'wordpress'    => self::get_wordpress_context(),
			'woocommerce'  => self::get_woocommerce_context(),
			'user'         => self::get_user_context(),
			'plugins'      => self::get_plugin_context(),
			'actions'      => self::get_actions_context(),
			'store_knowledge' => YoRo_Store_Knowledge_Provider::get_context( 'context_endpoint' ),
			'generated_at' => YoRo_Date_Helper::format_mysql( current_time( 'mysql' ) ),
		];
	}

	private static function get_store_context() {
		return [
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'home_url'    => home_url(),
			'site_url'    => site_url(),
			'admin_email' => get_option( 'admin_email' ),
			'timezone'    => wp_timezone_string(),
			'locale'      => get_locale(),
		];
	}

	private static function get_wordpress_context() {
		global $wp_version;

		return [
			'version'        => $wp_version,
			'is_multisite'   => is_multisite(),
			'debug_enabled'  => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'current_theme'  => wp_get_theme()->get( 'Name' ),
			'permalink'      => get_option( 'permalink_structure' ),
		];
	}

	private static function get_woocommerce_context() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'active' => false,
			];
		}

		return [
			'active'           => true,
			'version'          => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'currency'         => get_woocommerce_currency(),
			'currency_symbol'  => self::currency_symbol(),
			'order_statuses'   => self::get_order_statuses(),
			'payment_gateways' => self::get_payment_gateways(),
			'hpos_enabled'     => self::is_hpos_enabled(),
		];
	}

	private static function currency_symbol( $currency = '' ) {
		if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return '';
		}

		$symbol = '' !== $currency
			? get_woocommerce_currency_symbol( $currency )
			: get_woocommerce_currency_symbol();

		return sanitize_text_field(
			wp_strip_all_tags(
				html_entity_decode( (string) $symbol, ENT_QUOTES | ENT_HTML5, 'UTF-8' )
			)
		);
	}

	public static function get_current_order_id() {
		return absint( get_option( 'yoro_last_referenced_order_id', 0 ) );
	}

	private static function get_order_statuses() {
		return array_values( YoRo_Order_Helper::get_order_status_options() );
	}

	private static function get_payment_gateways() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return [];
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$data     = [];

		foreach ( $gateways as $gateway_id => $gateway ) {
			$data[] = [
				'id'      => $gateway_id,
				'title'   => method_exists( $gateway, 'get_title' ) ? $gateway->get_title() : $gateway->title,
				'enabled' => isset( $gateway->enabled ) ? $gateway->enabled : '',
			];
		}

		return $data;
	}

	private static function is_hpos_enabled() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	private static function get_user_context() {
		$user = wp_get_current_user();

		if ( ! $user || ! $user->ID ) {
			return [
				'logged_in' => false,
			];
		}

		return [
			'logged_in'                => true,
			'user_id'                  => $user->ID,
			'display_name'             => $user->display_name,
			'roles'                    => $user->roles,
			'can_manage_options'       => current_user_can( 'manage_options' ),
			'can_manage_woocommerce'   => current_user_can( 'manage_woocommerce' ),
			'can_edit_shop_orders'     => current_user_can( 'edit_shop_orders' ),
			'can_use_yoro_ai'          => current_user_can( YoRo_Capabilities::USE_AI ),
			'can_view_store_data'      => current_user_can( YoRo_Capabilities::VIEW_STORE_DATA ),
			'can_execute_yoro_actions' => current_user_can( YoRo_Capabilities::EXECUTE_ACTIONS ),
			'can_manage_yoro_mailbox'  => current_user_can( YoRo_Capabilities::MANAGE_MAILBOX ),
		];
	}

	private static function get_plugin_context() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$known_plugins = [
			'woocommerce/woocommerce.php'                    => 'Store commerce plugin',
			'wc-blacklist-manager/wc-blacklist-manager.php'  => 'Blacklist Manager',
			'wc-blacklist-manager-premium/wc-blacklist-manager-premium.php' => 'Blacklist Manager Premium',
			'yoro-ai-core/yoro-ai-core.php'            => 'YoRo AI Core',
		];

		$data = [];

		foreach ( $known_plugins as $plugin_file => $label ) {
			$data[] = [
				'name'   => $label,
				'file'   => $plugin_file,
				'active' => is_plugin_active( $plugin_file ),
			];
		}

		return $data;
	}

	private static function get_actions_context() {
		$actions = YoRo_Action_Registry::all();

		$summary = [
			'total'      => count( $actions ),
			'read'       => 0,
			'write'      => 0,
			'categories' => [],
			'available'  => [],
		];

		foreach ( $actions as $name => $action ) {
			$category = $action['category'] ?? 'general';

			if ( ! isset( $summary['categories'][ $category ] ) ) {
				$summary['categories'][ $category ] = 0;
			}

			$summary['categories'][ $category ]++;

			if ( ! empty( $action['write_action'] ) ) {
				$summary['write']++;
			} else {
				$summary['read']++;
			}

			$summary['available'][] = [
				'name'                  => $name,
				'label'                 => $action['label'] ?? '',
				'category'              => $category,
				'risk_level'            => $action['risk_level'] ?? 'low',
				'requires_confirmation' => ! empty( $action['requires_confirmation'] ),
				'write_action'          => ! empty( $action['write_action'] ),
			];
		}

		return $summary;
	}

}
