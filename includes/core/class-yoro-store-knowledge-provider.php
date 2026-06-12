<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Store_Knowledge_Provider {

	const SCHEMA_VERSION = 9;
	const CACHE_PREFIX   = 'yoro_ai_store_knowledge_v9_';

	public static function get_context( $scope = 'conversation' ) {
		$scope = sanitize_key( (string) $scope );

		if ( '' === $scope ) {
			$scope = 'conversation';
		}

		$cache_key = self::CACHE_PREFIX . md5( $scope . '|' . home_url() . '|' . get_locale() );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return apply_filters( 'yoro_ai_store_knowledge_context', $cached, $scope );
		}

		$context = self::build_context( $scope );
		$ttl     = max( 30, absint( apply_filters( 'yoro_ai_store_knowledge_ttl', 180, $scope ) ) );

		set_transient( $cache_key, $context, $ttl );

		return apply_filters( 'yoro_ai_store_knowledge_context', $context, $scope );
	}

	private static function build_context( $scope ) {
		return [
			'schema_version' => self::SCHEMA_VERSION,
			'type'           => 'store_knowledge',
			'scope'          => sanitize_key( $scope ),
			'generated_at'   => YoRo_Date_Helper::format_mysql( current_time( 'mysql' ) ),
			'facts'          => [
				'identity'       => self::identity_facts(),
				'locale'         => self::locale_facts(),
				'commerce_model' => self::commerce_model_facts(),
				'order_model'    => self::order_model_facts(),
				'product_model'  => self::product_model_facts(),
				'payments'       => self::payment_facts(),
				'shipping'       => self::shipping_facts(),
				'integrations'   => self::integration_facts(),
				'settings'       => self::settings_facts(),
			],
			'profiles'       => [
				'catalog'         => self::catalog_profile(),
				'commerce'        => self::commerce_profile(),
				'sales_baselines' => self::sales_baselines_profile(),
				'customers'       => self::customer_profile(),
				'operations'      => self::operations_profile(),
				'inventory'       => self::inventory_profile(),
			],
			'reporting'      => self::reporting_contract(),
			'capabilities'   => self::capability_contract(),
			'live_snapshot'  => self::live_snapshot(),
			'safety'         => self::safety_contract(),
			'sources'        => self::source_contract(),
		];
	}

	private static function identity_facts() {
		return [
			'name'        => sanitize_text_field( get_bloginfo( 'name' ) ),
			'description' => sanitize_text_field( get_bloginfo( 'description' ) ),
			'home_url'    => esc_url_raw( home_url() ),
			'site_url'    => esc_url_raw( site_url() ),
		];
	}

	private static function locale_facts() {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$country  = '';

		if ( function_exists( 'WC' ) && WC()->countries ) {
			$country = sanitize_text_field( WC()->countries->get_base_country() );
		}

		return [
			'timezone'        => sanitize_text_field( wp_timezone_string() ),
			'site_locale'     => sanitize_text_field( get_locale() ),
			'user_locale'     => function_exists( 'get_user_locale' ) ? sanitize_text_field( get_user_locale() ) : sanitize_text_field( get_locale() ),
			'business_country' => $country,
			'currency'        => sanitize_text_field( $currency ),
			'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? wp_strip_all_tags( get_woocommerce_currency_symbol( $currency ) ) : '',
			'price_decimals'  => function_exists( 'wc_get_price_decimals' ) ? absint( wc_get_price_decimals() ) : 2,
		];
	}

	private static function commerce_model_facts() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'platform'           => 'woocommerce',
				'woocommerce_active' => false,
			];
		}

		return [
			'platform'                 => 'woocommerce',
			'woocommerce_active'       => true,
			'woocommerce_version'      => defined( 'WC_VERSION' ) ? sanitize_text_field( WC_VERSION ) : '',
			'hpos_enabled'             => self::is_hpos_enabled(),
			'tax_enabled'              => function_exists( 'wc_tax_enabled' ) ? (bool) wc_tax_enabled() : null,
			'coupons_enabled'          => function_exists( 'wc_coupons_enabled' ) ? (bool) wc_coupons_enabled() : null,
			'prices_include_tax'       => 'yes' === get_option( 'woocommerce_prices_include_tax', 'no' ),
			'stock_management_enabled' => 'yes' === get_option( 'woocommerce_manage_stock', 'no' ),
			'low_stock_threshold'      => absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) ),
			'guest_checkout_enabled'   => 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' ),
			'checkout_login_reminder_enabled' => 'yes' === get_option( 'woocommerce_enable_checkout_login_reminder', 'yes' ),
			'account_creation_on_checkout_enabled' => 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' ),
			'account_creation_on_my_account_enabled' => 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' ),
			'account_username_generation_enabled' => 'yes' === get_option( 'woocommerce_registration_generate_username', 'yes' ),
			'account_password_generation_enabled' => 'yes' === get_option( 'woocommerce_registration_generate_password', 'yes' ),
			'hold_stock_minutes'       => absint( get_option( 'woocommerce_hold_stock_minutes', 60 ) ),
			'no_stock_threshold'       => absint( get_option( 'woocommerce_notify_no_stock_amount', 0 ) ),
			'low_stock_notifications_enabled' => 'yes' === get_option( 'woocommerce_notify_low_stock', 'yes' ),
			'no_stock_notifications_enabled' => 'yes' === get_option( 'woocommerce_notify_no_stock', 'yes' ),
			'hide_out_of_stock_items'  => 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' ),
			'stock_display_format'     => sanitize_key( get_option( 'woocommerce_stock_format', '' ) ),
		];
	}

	private static function order_model_facts() {
		$statuses = [];

		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $status => $label ) {
				$key = preg_replace( '/^wc-/', '', sanitize_key( $status ) );

				if ( '' === $key ) {
					continue;
				}

				$statuses[] = [
					'key'   => $key,
					'label' => sanitize_text_field( $label ),
				];
			}
		}

		return [
			'statuses'         => $statuses,
			'open_statuses'    => [ 'pending', 'processing', 'on-hold' ],
			'revenue_statuses' => class_exists( 'YoRo_Orders_Read_Service' )
				? YoRo_Orders_Read_Service::briefing_get_revenue_order_status_slugs()
				: [ 'processing', 'completed' ],
			'queue_surfaces'   => [
				'operations_attention',
				'fulfillment',
				'customer_service',
				'inventory',
			],
		];
	}

	private static function product_model_facts() {
		return [
			'stock_statuses'     => self::product_stock_status_facts(),
			'identifier_types'   => class_exists( 'YoRo_Product_Helper' )
				? YoRo_Product_Helper::get_product_identifier_types()
				: [],
		];
	}

	private static function product_stock_status_facts() {
		$options = class_exists( 'YoRo_Product_Helper' )
			? YoRo_Product_Helper::get_stock_status_options()
			: [];
		$statuses = [];

		foreach ( $options as $slug => $option ) {
			$slug = class_exists( 'YoRo_Product_Helper' )
				? YoRo_Product_Helper::normalize_stock_status_slug( $slug )
				: sanitize_key( (string) $slug );

			if ( '' === $slug ) {
				continue;
			}

			$statuses[] = [
				'key'            => $slug,
				'slug'           => $slug,
				'label'          => sanitize_text_field( $option['label'] ?? $slug ),
				'core'           => in_array( $slug, [ 'instock', 'outofstock', 'onbackorder' ], true ),
				'manual_allowed' => true,
			];
		}

		return array_values( $statuses );
	}

	private static function payment_facts() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return [
				'available' => false,
				'methods'   => [],
			];
		}

		return [
			'available' => true,
			'methods'   => array_slice( self::payment_method_facts(), 0, 20 ),
		];
	}

	private static function shipping_facts() {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return [
				'available' => false,
				'methods'   => [],
			];
		}

		return [
			'available' => true,
			'methods'   => array_slice( self::shipping_method_type_facts(), 0, 20 ),
			'zones'     => array_slice( self::shipping_zone_facts(), 0, 12 ),
		];
	}

	private static function settings_facts() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'available' => false,
				'platform'  => 'woocommerce',
			];
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? sanitize_text_field( get_woocommerce_currency() ) : '';
		$payment_methods = array_slice( self::payment_method_facts(), 0, 20 );
		$shipping_methods = array_slice( self::shipping_method_type_facts(), 0, 20 );
		$shipping_zones = array_slice( self::shipping_zone_facts(), 0, 12 );
		$general = self::general_settings_facts( $currency );
		$commerce = [
			'woocommerce_version' => defined( 'WC_VERSION' ) ? sanitize_text_field( WC_VERSION ) : '',
			'hpos_enabled'        => self::is_hpos_enabled(),
			'currency'            => $currency,
			'currency_symbol'     => function_exists( 'get_woocommerce_currency_symbol' ) ? wp_strip_all_tags( get_woocommerce_currency_symbol( $currency ) ) : '',
			'price_decimals'      => function_exists( 'wc_get_price_decimals' ) ? absint( wc_get_price_decimals() ) : 2,
			'coupons_enabled'     => function_exists( 'wc_coupons_enabled' ) ? (bool) wc_coupons_enabled() : null,
			'coupon_discounts_sequential' => 'yes' === get_option( 'woocommerce_calc_discounts_sequentially', 'no' ),
		];
		$tax = [
			'tax_enabled'            => function_exists( 'wc_tax_enabled' ) ? (bool) wc_tax_enabled() : null,
			'prices_include_tax'     => 'yes' === get_option( 'woocommerce_prices_include_tax', 'no' ),
			'tax_calculation_based_on' => sanitize_key( get_option( 'woocommerce_tax_based_on', '' ) ),
			'shipping_tax_class'     => sanitize_key( get_option( 'woocommerce_shipping_tax_class', '' ) ),
			'tax_round_at_subtotal'  => 'yes' === get_option( 'woocommerce_tax_round_at_subtotal', 'no' ),
			'tax_display_shop'       => sanitize_key( get_option( 'woocommerce_tax_display_shop', '' ) ),
			'tax_display_cart'       => sanitize_key( get_option( 'woocommerce_tax_display_cart', '' ) ),
			'price_display_suffix'   => sanitize_text_field( get_option( 'woocommerce_price_display_suffix', '' ) ),
			'tax_classes'            => self::tax_class_facts(),
			'tax_total_display'      => sanitize_key( get_option( 'woocommerce_tax_total_display', '' ) ),
		];
		$checkout = [
			'guest_checkout_enabled' => 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' ),
			'checkout_login_reminder_enabled' => 'yes' === get_option( 'woocommerce_enable_checkout_login_reminder', 'yes' ),
			'delayed_account_creation_enabled' => 'yes' === get_option( 'woocommerce_enable_delayed_account_creation', 'no' ),
			'account_creation_on_checkout_enabled' => 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' ),
			'account_creation_on_my_account_enabled' => 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' ),
			'account_username_generation_enabled' => 'yes' === get_option( 'woocommerce_registration_generate_username', 'yes' ),
			'account_password_generation_enabled' => 'yes' === get_option( 'woocommerce_registration_generate_password', 'yes' ),
			'terms_page_configured'  => absint( get_option( 'woocommerce_terms_page_id', 0 ) ) > 0,
			'checkout_page_configured' => absint( get_option( 'woocommerce_checkout_page_id', 0 ) ) > 0,
			'cart_page_configured'   => absint( get_option( 'woocommerce_cart_page_id', 0 ) ) > 0,
			'myaccount_page_configured' => absint( get_option( 'woocommerce_myaccount_page_id', 0 ) ) > 0,
			'privacy'                => self::privacy_settings_facts(),
		];
		$inventory = [
			'stock_management_enabled' => 'yes' === get_option( 'woocommerce_manage_stock', 'no' ),
			'hold_stock_minutes'       => absint( get_option( 'woocommerce_hold_stock_minutes', 60 ) ),
			'low_stock_threshold'      => absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) ),
			'no_stock_threshold'       => absint( get_option( 'woocommerce_notify_no_stock_amount', 0 ) ),
			'low_stock_notifications_enabled' => 'yes' === get_option( 'woocommerce_notify_low_stock', 'yes' ),
			'no_stock_notifications_enabled' => 'yes' === get_option( 'woocommerce_notify_no_stock', 'yes' ),
			'hide_out_of_stock_items'  => 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' ),
			'stock_display_format'     => sanitize_key( get_option( 'woocommerce_stock_format', '' ) ),
			'stock_statuses'           => self::product_stock_status_facts(),
		];
		$ship_to_countries = sanitize_key( get_option( 'woocommerce_ship_to_countries', '' ) );
		$shipping = [
			'ship_to_countries'         => $ship_to_countries,
			'specific_ship_to_countries' => 'specific' === $ship_to_countries ? self::country_setting_list( 'woocommerce_specific_ship_to_countries', 80 ) : [],
			'ship_to_destination'       => sanitize_key( get_option( 'woocommerce_ship_to_destination', '' ) ),
			'shipping_calculator_enabled' => 'yes' === get_option( 'woocommerce_enable_shipping_calc', 'yes' ),
			'shipping_cost_requires_address' => 'yes' === get_option( 'woocommerce_shipping_cost_requires_address', 'no' ),
			'shipping_hide_rates_when_free' => 'yes' === get_option( 'woocommerce_shipping_hide_rates_when_free', 'no' ),
			'shipping_debug_mode_enabled' => 'yes' === get_option( 'woocommerce_shipping_debug_mode', 'no' ),
			'shipping_classes'          => self::shipping_class_facts(),
			'methods'                   => $shipping_methods,
			'zones'                     => $shipping_zones,
		];

		return [
			'available' => true,
			'platform'  => 'woocommerce',
			'general'   => $general,
			'commerce'  => $commerce,
			'tax'       => $tax,
			'checkout'  => $checkout,
			'inventory' => $inventory,
			'products'  => self::product_settings_facts(),
			'payments'  => [
				'methods' => $payment_methods,
			],
			'shipping'      => $shipping,
			'email'         => self::email_settings_facts(),
			'advanced'      => self::advanced_settings_facts(),
			'applicability' => self::settings_applicability( $general, $commerce, $tax, $inventory, $payment_methods, $shipping ),
			'orders'    => self::order_model_facts(),
			'source'    => 'woocommerce_settings',
		];
	}

	private static function integration_facts() {
		if ( ! class_exists( 'YoRo_Integration_Registry' ) ) {
			return [
				'platform'             => 'wordpress',
				'registry_version'     => 0,
				'integrated_adapters'  => [],
				'known'                => [],
				'integration_boundary' => [
					'only_integrated_adapters_are_inspectable_or_actionable' => true,
				],
			];
		}

		return YoRo_Integration_Registry::facts();
	}

	private static function general_settings_facts( $currency ) {
		$currency = sanitize_text_field( (string) $currency );
		$selling_scope = sanitize_key( get_option( 'woocommerce_allowed_countries', 'all' ) );
		$shipping_scope = sanitize_key( get_option( 'woocommerce_ship_to_countries', '' ) );

		return [
			'store_address' => [
				'country'              => function_exists( 'WC' ) && WC()->countries ? sanitize_text_field( WC()->countries->get_base_country() ) : '',
				'state'                => function_exists( 'WC' ) && WC()->countries ? sanitize_text_field( WC()->countries->get_base_state() ) : '',
				'city'                 => sanitize_text_field( get_option( 'woocommerce_store_city', '' ) ),
				'postcode_configured'  => '' !== trim( (string) get_option( 'woocommerce_store_postcode', '' ) ),
				'address_configured'   => '' !== trim( (string) get_option( 'woocommerce_store_address', '' ) ),
			],
			'selling_locations' => array_filter(
				[
					'scope'              => $selling_scope,
					'specific_countries' => 'specific' === $selling_scope ? self::country_setting_list( 'woocommerce_specific_allowed_countries', 120 ) : [],
					'excluded_countries' => 'all_except' === $selling_scope ? self::country_setting_list( 'woocommerce_all_except_countries', 120 ) : [],
				],
				static fn( $value ) => [] !== $value && '' !== $value
			),
			'shipping_locations' => array_filter(
				[
					'scope'              => $shipping_scope,
					'specific_countries' => 'specific' === $shipping_scope ? self::country_setting_list( 'woocommerce_specific_ship_to_countries', 120 ) : [],
				],
				static fn( $value ) => [] !== $value && '' !== $value
			),
			'default_customer_location' => sanitize_key( get_option( 'woocommerce_default_customer_address', 'base' ) ),
			'taxes_enabled'             => 'yes' === get_option( 'woocommerce_calc_taxes', 'no' ),
			'coupons_enabled'           => 'yes' === get_option( 'woocommerce_enable_coupons', 'yes' ),
			'coupon_discounts_sequential' => 'yes' === get_option( 'woocommerce_calc_discounts_sequentially', 'no' ),
			'currency'                  => $currency,
			'currency_symbol'           => function_exists( 'get_woocommerce_currency_symbol' ) ? wp_strip_all_tags( get_woocommerce_currency_symbol( $currency ) ) : '',
			'currency_position'         => sanitize_key( get_option( 'woocommerce_currency_pos', 'left' ) ),
			'price_thousand_separator'  => sanitize_text_field( get_option( 'woocommerce_price_thousand_sep', ',' ) ),
			'price_decimal_separator'   => sanitize_text_field( get_option( 'woocommerce_price_decimal_sep', '.' ) ),
			'price_decimals'            => absint( get_option( 'woocommerce_price_num_decimals', 2 ) ),
			'address_autocomplete_enabled' => 'yes' === get_option( 'woocommerce_address_autocomplete_enabled', 'no' ),
			'address_autocomplete_provider' => sanitize_key( get_option( 'woocommerce_address_autocomplete_provider', '' ) ),
		];
	}

	private static function product_settings_facts() {
		return [
			'shop_page_configured'       => absint( get_option( 'woocommerce_shop_page_id', 0 ) ) > 0,
			'cart_redirect_after_add'    => 'yes' === get_option( 'woocommerce_cart_redirect_after_add', 'no' ),
			'ajax_add_to_cart_enabled'   => 'yes' === get_option( 'woocommerce_enable_ajax_add_to_cart', 'yes' ),
			'placeholder_image_configured' => '' !== trim( (string) get_option( 'woocommerce_placeholder_image', '' ) ),
			'weight_unit'                => sanitize_key( get_option( 'woocommerce_weight_unit', 'kg' ) ),
			'dimension_unit'             => sanitize_key( get_option( 'woocommerce_dimension_unit', 'cm' ) ),
			'reviews_enabled'            => 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ),
			'verified_owner_label_enabled' => 'yes' === get_option( 'woocommerce_review_rating_verification_label', 'yes' ),
			'reviews_require_verified_owner' => 'yes' === get_option( 'woocommerce_review_rating_verification_required', 'no' ),
			'star_ratings_enabled'       => 'yes' === get_option( 'woocommerce_enable_review_rating', 'yes' ),
			'star_ratings_required'      => 'yes' === get_option( 'woocommerce_review_rating_required', 'yes' ),
			'download_method'            => sanitize_key( get_option( 'woocommerce_file_download_method', 'force' ) ),
			'download_redirect_fallback_allowed' => 'yes' === get_option( 'woocommerce_downloads_redirect_fallback_allowed', 'no' ),
			'downloads_require_login'    => 'yes' === get_option( 'woocommerce_downloads_require_login', 'no' ),
			'downloads_grant_access_after_payment' => 'yes' === get_option( 'woocommerce_downloads_grant_access_after_payment', 'yes' ),
			'downloads_deliver_inline'   => 'yes' === get_option( 'woocommerce_downloads_deliver_inline', 'no' ),
			'downloads_add_hash_to_filename' => 'yes' === get_option( 'woocommerce_downloads_add_hash_to_filename', 'no' ),
			'downloads_count_partial'    => 'yes' === get_option( 'woocommerce_downloads_count_partial', 'no' ),
		];
	}

	private static function privacy_settings_facts() {
		return [
			'erasure_removes_order_data' => 'yes' === get_option( 'woocommerce_erasure_request_removes_order_data', 'no' ),
			'erasure_removes_download_data' => 'yes' === get_option( 'woocommerce_erasure_request_removes_download_data', 'no' ),
			'bulk_personal_data_removal_enabled' => 'yes' === get_option( 'woocommerce_allow_bulk_remove_personal_data', 'no' ),
			'registration_privacy_text_configured' => '' !== trim( wp_strip_all_tags( (string) get_option( 'woocommerce_registration_privacy_policy_text', '' ) ) ),
			'checkout_privacy_text_configured' => '' !== trim( wp_strip_all_tags( (string) get_option( 'woocommerce_checkout_privacy_policy_text', '' ) ) ),
			'delete_inactive_accounts'  => sanitize_text_field( get_option( 'woocommerce_delete_inactive_accounts', '' ) ),
			'trash_pending_orders'      => sanitize_text_field( get_option( 'woocommerce_trash_pending_orders', '' ) ),
			'trash_failed_orders'       => sanitize_text_field( get_option( 'woocommerce_trash_failed_orders', '' ) ),
			'trash_cancelled_orders'    => sanitize_text_field( get_option( 'woocommerce_trash_cancelled_orders', '' ) ),
			'anonymize_refunded_orders' => sanitize_text_field( get_option( 'woocommerce_anonymize_refunded_orders', '' ) ),
			'anonymize_completed_orders' => sanitize_text_field( get_option( 'woocommerce_anonymize_completed_orders', '' ) ),
		];
	}

	private static function email_settings_facts() {
		$notifications = [];

		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			foreach ( WC()->mailer()->get_emails() as $email_id => $email ) {
				if ( ! is_object( $email ) ) {
					continue;
				}

				$recipient = isset( $email->recipient ) ? trim( (string) $email->recipient ) : '';
				$notifications[] = [
					'id'                   => sanitize_key( $email_id ),
					'title'                => sanitize_text_field( $email->title ?? $email_id ),
					'enabled'              => method_exists( $email, 'is_enabled' ) ? (bool) $email->is_enabled() : ( 'yes' === ( $email->enabled ?? '' ) ),
					'customer_email'       => method_exists( $email, 'is_customer_email' ) ? (bool) $email->is_customer_email() : false,
					'recipient_configured' => '' !== $recipient,
				];
			}
		}

		$from_address     = sanitize_email( get_option( 'woocommerce_email_from_address', get_option( 'admin_email', '' ) ) );
		$reply_to_address = sanitize_email( get_option( 'woocommerce_email_reply_to_address', '' ) );

		return [
			'from_name'             => sanitize_text_field( get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) ) ),
			'from_address_configured' => '' !== $from_address,
			'from_address_domain'   => self::email_domain( $from_address ),
			'reply_to_enabled'      => 'yes' === get_option( 'woocommerce_email_reply_to_enabled', 'no' ),
			'reply_to_name_configured' => '' !== trim( (string) get_option( 'woocommerce_email_reply_to_name', '' ) ),
			'reply_to_address_domain' => self::email_domain( $reply_to_address ),
			'header_image_configured' => '' !== trim( (string) get_option( 'woocommerce_email_header_image', '' ) ),
			'footer_text_configured' => '' !== trim( wp_strip_all_tags( (string) get_option( 'woocommerce_email_footer_text', '' ) ) ),
			'template_auto_sync_with_theme' => 'yes' === get_option( 'woocommerce_email_auto_sync_with_theme', 'no' ),
			'notifications'         => array_slice( $notifications, 0, 20 ),
		];
	}

	private static function advanced_settings_facts() {
		return [
			'pages' => [
				'cart_page_configured'      => absint( get_option( 'woocommerce_cart_page_id', 0 ) ) > 0,
				'checkout_page_configured'  => absint( get_option( 'woocommerce_checkout_page_id', 0 ) ) > 0,
				'myaccount_page_configured' => absint( get_option( 'woocommerce_myaccount_page_id', 0 ) ) > 0,
				'terms_page_configured'     => absint( get_option( 'woocommerce_terms_page_id', 0 ) ) > 0,
			],
			'checkout_endpoints' => [
				'pay'            => sanitize_title( get_option( 'woocommerce_checkout_pay_endpoint', 'order-pay' ) ),
				'order_received' => sanitize_title( get_option( 'woocommerce_checkout_order_received_endpoint', 'order-received' ) ),
			],
			'myaccount_endpoints' => [
				'orders'          => sanitize_title( get_option( 'woocommerce_myaccount_orders_endpoint', 'orders' ) ),
				'view_order'      => sanitize_title( get_option( 'woocommerce_myaccount_view_order_endpoint', 'view-order' ) ),
				'downloads'       => sanitize_title( get_option( 'woocommerce_myaccount_downloads_endpoint', 'downloads' ) ),
				'edit_account'    => sanitize_title( get_option( 'woocommerce_myaccount_edit_account_endpoint', 'edit-account' ) ),
				'edit_address'    => sanitize_title( get_option( 'woocommerce_myaccount_edit_address_endpoint', 'edit-address' ) ),
				'payment_methods' => sanitize_title( get_option( 'woocommerce_myaccount_payment_methods_endpoint', 'payment-methods' ) ),
				'lost_password'   => sanitize_title( get_option( 'woocommerce_myaccount_lost_password_endpoint', 'lost-password' ) ),
				'logout'          => sanitize_title( get_option( 'woocommerce_logout_endpoint', 'customer-logout' ) ),
			],
			'secure_checkout_forced' => 'yes' === get_option( 'woocommerce_force_ssl_checkout', 'no' ),
			'usage_tracking_allowed' => 'yes' === get_option( 'woocommerce_allow_tracking', 'no' ),
			'marketplace_suggestions_enabled' => 'yes' === get_option( 'woocommerce_show_marketplace_suggestions', 'yes' ),
			'legacy_api_enabled'     => 'yes' === get_option( 'woocommerce_api_enabled', 'no' ),
			'rest_api_backend_caching_enabled' => 'yes' === get_option( 'woocommerce_rest_api_enable_backend_caching', 'yes' ),
			'rest_api_cache_headers_enabled' => 'yes' === get_option( 'woocommerce_rest_api_enable_cache_headers', 'yes' ),
		];
	}

	private static function tax_class_facts() {
		if ( ! class_exists( 'WC_Tax' ) ) {
			return [];
		}

		$classes = [
			[
				'slug'       => 'standard',
				'label'      => __( 'Standard rate', 'woocommerce' ),
				'rate_count' => self::tax_rate_count_for_class( '' ),
			],
		];

		foreach ( WC_Tax::get_tax_classes() as $class_name ) {
			$class_name = sanitize_text_field( (string) $class_name );

			if ( '' === $class_name ) {
				continue;
			}

			$classes[] = [
				'slug'       => sanitize_title( $class_name ),
				'label'      => $class_name,
				'rate_count' => self::tax_rate_count_for_class( sanitize_title( $class_name ) ),
			];
		}

		return array_slice( $classes, 0, 20 );
	}

	private static function tax_rate_count_for_class( $class_slug ) {
		if ( ! class_exists( 'WC_Tax' ) || ! method_exists( 'WC_Tax', 'get_rates_for_tax_class' ) ) {
			return null;
		}

		return count( (array) WC_Tax::get_rates_for_tax_class( (string) $class_slug ) );
	}

	private static function shipping_class_facts() {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() || ! method_exists( WC()->shipping(), 'get_shipping_classes' ) ) {
			return [];
		}

		$classes = [];

		foreach ( WC()->shipping()->get_shipping_classes() as $class ) {
			if ( ! is_object( $class ) ) {
				continue;
			}

			$classes[] = [
				'id'    => absint( $class->term_id ?? 0 ),
				'slug'  => sanitize_title( $class->slug ?? '' ),
				'label' => sanitize_text_field( $class->name ?? '' ),
			];
		}

		return array_slice( array_values( array_filter( $classes ) ), 0, 20 );
	}

	private static function country_setting_list( $option_name, $limit = 80 ) {
		$value = get_option( $option_name, [] );

		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		return self::country_code_list( $value, $limit );
	}

	private static function country_code_list( $codes, $limit = 80 ) {
		$items = [];

		foreach ( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $codes ) ) ) as $code ) {
			if ( count( $items ) >= $limit ) {
				break;
			}

			$items[] = [
				'code'  => strtoupper( $code ),
				'label' => self::country_or_region_label( $code ),
			];
		}

		return $items;
	}

	private static function country_or_region_label( $code ) {
		$code = strtoupper( sanitize_text_field( (string) $code ) );

		if ( '' === $code ) {
			return '';
		}

		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();

			if ( isset( $countries[ $code ] ) ) {
				return sanitize_text_field( $countries[ $code ] );
			}
		}

		return $code;
	}

	private static function email_domain( $email ) {
		$email = sanitize_email( (string) $email );

		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return '';
		}

		return sanitize_text_field( substr( strrchr( $email, '@' ), 1 ) );
	}

	private static function settings_applicability( $general, $commerce, $tax, $inventory, $payment_methods, $shipping ) {
		$general         = is_array( $general ) ? $general : [];
		$commerce        = is_array( $commerce ) ? $commerce : [];
		$tax             = is_array( $tax ) ? $tax : [];
		$inventory       = is_array( $inventory ) ? $inventory : [];
		$payment_methods = is_array( $payment_methods ) ? $payment_methods : [];
		$shipping        = is_array( $shipping ) ? $shipping : [];

		$enabled_payment_methods = self::enabled_method_count( $payment_methods );
		$enabled_shipping_types  = self::enabled_method_count( $shipping['methods'] ?? [] );
		$enabled_shipping_zones  = self::enabled_shipping_zone_count( $shipping['zones'] ?? [] );
		$enabled_shipping_instances = self::enabled_shipping_method_instance_count( $shipping['zones'] ?? [] );
		$ship_to_countries       = sanitize_key( (string) ( $shipping['ship_to_countries'] ?? '' ) );
		$shipping_disabled       = 'disabled' === $ship_to_countries;
		$selling_scope           = sanitize_key( (string) ( $general['selling_locations']['scope'] ?? '' ) );
		$specific_selling_count  = count( (array) ( $general['selling_locations']['specific_countries'] ?? [] ) );
		$excluded_selling_count  = count( (array) ( $general['selling_locations']['excluded_countries'] ?? [] ) );
		$selling_reason          = 'all' === $selling_scope
			? 'sell_to_all_countries'
			: ( 'all_except' === $selling_scope
				? 'sell_to_all_except_selected_countries'
				: ( 'specific' === $selling_scope ? 'sell_to_specific_countries' : 'selling_locations_unknown' ) );

		return [
			'selling'   => self::settings_gate(
				'' !== $selling_scope,
				$selling_reason,
				[
					'selling_scope'          => $selling_scope,
					'specific_country_count' => $specific_selling_count,
					'excluded_country_count' => $excluded_selling_count,
				]
			),
			'shipping'  => self::settings_gate(
				! $shipping_disabled,
				$shipping_disabled ? 'shipping_locations_disabled' : ( $enabled_shipping_instances > 0 ? 'enabled_shipping_methods_configured' : 'shipping_enabled_no_enabled_zone_methods' ),
				[
					'ship_to_countries'        => $ship_to_countries,
					'enabled_method_types'     => $enabled_shipping_types,
					'enabled_method_instances' => $enabled_shipping_instances,
					'enabled_zones'            => $enabled_shipping_zones,
				]
			),
			'tax'       => self::settings_gate(
				! empty( $tax['tax_enabled'] ),
				! empty( $tax['tax_enabled'] ) ? 'tax_calculation_enabled' : 'tax_calculation_disabled'
			),
			'coupons'   => self::settings_gate(
				! empty( $commerce['coupons_enabled'] ),
				! empty( $commerce['coupons_enabled'] ) ? 'coupons_enabled' : 'coupons_disabled'
			),
			'inventory' => self::settings_gate(
				true,
				! empty( $inventory['stock_management_enabled'] ) ? 'inventory_stock_status_and_management_available' : 'inventory_stock_status_available_without_stock_management',
				[
					'stock_management_enabled' => ! empty( $inventory['stock_management_enabled'] ),
				]
			),
			'managed_inventory' => self::settings_gate(
				! empty( $inventory['stock_management_enabled'] ),
				! empty( $inventory['stock_management_enabled'] ) ? 'inventory_stock_management_enabled' : 'inventory_stock_management_disabled'
			),
			'payments'  => self::settings_gate(
				$enabled_payment_methods > 0,
				$enabled_payment_methods > 0 ? 'enabled_payment_methods_configured' : 'no_enabled_payment_methods',
				[
					'enabled_methods' => $enabled_payment_methods,
				]
			),
			'checkout'  => self::settings_gate(
				true,
				'checkout_settings_available'
			),
		];
	}

	private static function settings_gate( $applicable, $reason, $meta = [] ) {
		$clean_meta = [];

		foreach ( is_array( $meta ) ? $meta : [] as $key => $value ) {
			if ( ! is_scalar( $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			$clean_meta[ $key ] = is_numeric( $value ) ? ( $value + 0 ) : sanitize_text_field( (string) $value );
		}

		return array_filter(
			[
				'applicable' => (bool) $applicable,
				'reason'     => sanitize_key( (string) $reason ),
				'meta'       => $clean_meta,
			],
			static fn( $value ) => [] !== $value && '' !== $value
		);
	}

	private static function enabled_method_count( $methods ) {
		$count = 0;

		foreach ( is_array( $methods ) ? $methods : [] as $method ) {
			if ( is_array( $method ) && 'yes' === sanitize_key( (string) ( $method['enabled'] ?? '' ) ) ) {
				$count++;
			}
		}

		return $count;
	}

	private static function enabled_shipping_zone_count( $zones ) {
		$count = 0;

		foreach ( is_array( $zones ) ? $zones : [] as $zone ) {
			if ( ! is_array( $zone ) ) {
				continue;
			}

			if ( self::enabled_method_count( $zone['methods'] ?? [] ) > 0 ) {
				$count++;
			}
		}

		return $count;
	}

	private static function enabled_shipping_method_instance_count( $zones ) {
		$count = 0;

		foreach ( is_array( $zones ) ? $zones : [] as $zone ) {
			if ( ! is_array( $zone ) ) {
				continue;
			}

			$count += self::enabled_method_count( $zone['methods'] ?? [] );
		}

		return $count;
	}

	private static function payment_method_facts() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return [];
		}

		$methods  = [];
		$gateways = WC()->payment_gateways()->payment_gateways();

		foreach ( $gateways as $gateway_id => $gateway ) {
			$supports = [];

			if ( isset( $gateway->supports ) && is_array( $gateway->supports ) ) {
				$supports = array_values( array_filter( array_map( 'sanitize_key', $gateway->supports ) ) );
			}

			$methods[] = [
				'id'       => sanitize_key( $gateway_id ),
				'title'    => sanitize_text_field( method_exists( $gateway, 'get_title' ) ? $gateway->get_title() : ( $gateway->title ?? $gateway_id ) ),
				'enabled'  => isset( $gateway->enabled ) ? sanitize_key( $gateway->enabled ) : '',
				'supports' => array_slice( $supports, 0, 12 ),
				'settings' => self::public_settings_from_form_fields( $gateway, false ),
			];
		}

		return $methods;
	}

	private static function shipping_method_type_facts() {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return [];
		}

		$methods = [];

		foreach ( WC()->shipping()->get_shipping_methods() as $method_id => $method ) {
			$supports = [];

			if ( isset( $method->supports ) && is_array( $method->supports ) ) {
				$supports = array_values( array_filter( array_map( 'sanitize_key', $method->supports ) ) );
			}

			$methods[] = [
				'id'      => sanitize_key( $method_id ),
				'title'   => sanitize_text_field( method_exists( $method, 'get_method_title' ) ? $method->get_method_title() : ( $method->method_title ?? $method_id ) ),
				'enabled' => isset( $method->enabled ) ? sanitize_key( $method->enabled ) : '',
				'supports' => array_slice( $supports, 0, 12 ),
				'settings' => self::public_settings_from_form_fields( $method, false ),
			];
		}

		return $methods;
	}

	private static function shipping_zone_facts() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return [];
		}

		$zones = [];

		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			$zone_id  = absint( $zone['id'] ?? 0 );
			$zone_obj = WC_Shipping_Zones::get_zone( $zone_id );

			if ( $zone_obj ) {
				$zones[] = self::shipping_zone_item( $zone_obj );
			}
		}

		$rest_of_world = WC_Shipping_Zones::get_zone( 0 );

		if ( $rest_of_world ) {
			$zones[] = self::shipping_zone_item( $rest_of_world );
		}

		return array_values( array_filter( $zones ) );
	}

	private static function shipping_zone_item( $zone ) {
		if ( ! is_object( $zone ) || ! method_exists( $zone, 'get_shipping_methods' ) ) {
			return [];
		}

		$methods = [];

		foreach ( $zone->get_shipping_methods( false, 'admin' ) as $method ) {
			if ( ! is_object( $method ) ) {
				continue;
			}

			$method_id   = sanitize_key( $method->id ?? ( $method->method_id ?? '' ) );
			$instance_id = isset( $method->instance_id ) ? absint( $method->instance_id ) : 0;
			$title       = '';

			if ( method_exists( $method, 'get_title' ) ) {
				$title = $method->get_title();
			} elseif ( method_exists( $method, 'get_method_title' ) ) {
				$title = $method->get_method_title();
			} elseif ( isset( $method->title ) ) {
				$title = $method->title;
			}

			$methods[] = [
				'id'          => $method_id,
				'instance_id' => $instance_id,
				'title'       => sanitize_text_field( $title ?: $method_id ),
				'enabled'     => isset( $method->enabled ) ? sanitize_key( $method->enabled ) : '',
				'settings'    => self::shipping_method_instance_settings( $method ),
			];
		}

		$zone_name = method_exists( $zone, 'get_zone_name' ) ? $zone->get_zone_name() : '';
		$zone_id   = method_exists( $zone, 'get_id' ) ? absint( $zone->get_id() ) : 0;

		return [
			'id'      => $zone_id,
			'name'    => sanitize_text_field( $zone_name ?: __( 'Rest of the world', 'yoro-ai-core' ) ),
			'is_rest_of_world' => 0 === $zone_id,
			'order'   => method_exists( $zone, 'get_zone_order' ) ? absint( $zone->get_zone_order() ) : 0,
			'locations' => self::shipping_zone_location_items( $zone ),
			'methods' => array_slice( $methods, 0, 8 ),
		];
	}

	private static function shipping_zone_location_items( $zone ) {
		if ( ! is_object( $zone ) || ! method_exists( $zone, 'get_zone_locations' ) ) {
			return [];
		}

		$items = [];

		foreach ( (array) $zone->get_zone_locations() as $location ) {
			if ( count( $items ) >= 40 || ! is_object( $location ) ) {
				break;
			}

			$type = sanitize_key( $location->type ?? '' );
			$code = sanitize_text_field( $location->code ?? '' );

			if ( '' === $type || '' === $code ) {
				continue;
			}

			$items[] = [
				'type'  => $type,
				'code'  => $code,
				'label' => self::shipping_zone_location_label( $type, $code ),
			];
		}

		return $items;
	}

	private static function shipping_zone_location_label( $type, $code ) {
		$type = sanitize_key( (string) $type );
		$code = sanitize_text_field( (string) $code );

		if ( 'country' === $type ) {
			return self::country_or_region_label( $code );
		}

		if ( 'state' === $type && function_exists( 'WC' ) && WC()->countries ) {
			$parts = explode( ':', $code, 2 );

			if ( 2 === count( $parts ) ) {
				$states = WC()->countries->get_states( strtoupper( $parts[0] ) );

				if ( isset( $states[ $parts[1] ] ) ) {
					return sanitize_text_field( self::country_or_region_label( $parts[0] ) . ' - ' . $states[ $parts[1] ] );
				}
			}
		}

		if ( 'continent' === $type && function_exists( 'WC' ) && WC()->countries ) {
			$continents = WC()->countries->get_continents();
			$key        = strtoupper( $code );

			if ( isset( $continents[ $key ]['name'] ) ) {
				return sanitize_text_field( $continents[ $key ]['name'] );
			}
		}

		return sanitize_text_field( $code );
	}

	private static function shipping_method_instance_settings( $method ) {
		return self::public_settings_from_form_fields( $method, true );
	}

	private static function public_settings_from_form_fields( $owner, $instance = false ) {
		if ( ! is_object( $owner ) ) {
			return [];
		}

		$fields = [];

		if ( $instance && method_exists( $owner, 'get_instance_form_fields' ) ) {
			$fields = (array) $owner->get_instance_form_fields();
		} elseif ( method_exists( $owner, 'get_form_fields' ) ) {
			$fields = (array) $owner->get_form_fields();
		} elseif ( $instance && isset( $owner->instance_form_fields ) && is_array( $owner->instance_form_fields ) ) {
			$fields = $owner->instance_form_fields;
		} elseif ( isset( $owner->form_fields ) && is_array( $owner->form_fields ) ) {
			$fields = $owner->form_fields;
		}

		$settings = [];

		foreach ( $fields as $key => $field ) {
			if ( count( $settings ) >= 20 ) {
				break;
			}

			$key = sanitize_key( (string) $key );

			if ( '' === $key || ! self::is_safe_public_setting_key( $key ) ) {
				continue;
			}

			$value = self::method_option_value( $owner, $key, $instance );

			if ( null === $value || '' === $value || [] === $value ) {
				continue;
			}

			$settings[ $key ] = self::public_setting_value( $value );
		}

		return $settings;
	}

	private static function is_safe_public_setting_key( $key ) {
		$key = sanitize_key( (string) $key );

		if ( preg_match( '/(?:secret|token|key|password|pass|credential|client|merchant|account|signature|cert|private|webhook|api)/i', $key ) ) {
			return false;
		}

		$safe = [
			'enabled',
			'title',
			'description',
			'instructions',
			'testmode',
			'sandbox',
			'debug',
			'tax_status',
			'cost',
			'min_amount',
			'requires',
			'ignore_discounts',
			'enable_for_methods',
			'enable_for_virtual',
			'availability',
			'countries',
			'payment_action',
			'capture',
			'order_button_text',
		];

		return in_array( $key, $safe, true ) || 0 === strpos( $key, 'class_cost_' );
	}

	private static function method_option_value( $owner, $key, $instance ) {
		if ( $instance && method_exists( $owner, 'get_instance_option' ) ) {
			return $owner->get_instance_option( $key, null );
		}

		if ( method_exists( $owner, 'get_option' ) ) {
			return $owner->get_option( $key, null );
		}

		if ( $instance && isset( $owner->instance_settings ) && is_array( $owner->instance_settings ) && array_key_exists( $key, $owner->instance_settings ) ) {
			return $owner->instance_settings[ $key ];
		}

		if ( isset( $owner->settings ) && is_array( $owner->settings ) && array_key_exists( $key, $owner->settings ) ) {
			return $owner->settings[ $key ];
		}

		return null;
	}

	private static function public_setting_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return $value + 0;
		}

		if ( is_array( $value ) ) {
			$clean = [];

			foreach ( array_slice( $value, 0, 20 ) as $item ) {
				if ( is_scalar( $item ) ) {
					$clean[] = sanitize_text_field( (string) $item );
				}
			}

			return $clean;
		}

		return sanitize_text_field( wp_strip_all_tags( (string) $value ) );
	}

	private static function reporting_contract() {
		return [
			'default_window'            => 'last_7_days',
			'comparison_window'         => 'previous_equivalent_window',
			'canonical_window_examples' => [
				'today',
				'yesterday',
				'this_week',
				'last_week',
				'this_month',
				'last_month',
				'last_N_days',
				'last_N_weeks',
				'last_N_months',
				'month_YYYY_MM',
				'quarter_YYYY_qN',
				'year_YYYY',
				'range_YYYY_MM_DD_to_YYYY_MM_DD',
			],
			'metric_domains'            => [
				'sales',
				'orders',
				'customers',
				'products',
				'inventory',
				'operations',
				'fulfillment',
				'customer_service',
			],
			'operational_thresholds'     => [
				'attention_order_age_hours'       => 48,
				'fulfillment_processing_age_hours' => 24,
				'stale_customer_followup_hours'   => 48,
			],
		];
	}

	private static function capability_contract() {
		$actions = class_exists( 'YoRo_Action_Registry' ) ? YoRo_Action_Registry::all() : [];
		$summary = [
			'total'                 => 0,
			'read'                  => 0,
			'write'                 => 0,
			'requires_confirmation' => 0,
			'categories'            => [],
		];
		$surfaces = [];

		foreach ( $actions as $name => $action ) {
			if ( empty( $action['ai_exposed'] ) ) {
				continue;
			}

			$name     = sanitize_text_field( $name );
			$category = sanitize_key( $action['category'] ?? 'general' );
			$is_write = ! empty( $action['write_action'] );
			$requires_confirmation = ! empty( $action['requires_confirmation'] ) || $is_write;

			if ( '' === $name ) {
				continue;
			}

			$summary['total']++;
			$summary[ $is_write ? 'write' : 'read' ]++;

			if ( $requires_confirmation ) {
				$summary['requires_confirmation']++;
			}

			if ( empty( $summary['categories'][ $category ] ) ) {
				$summary['categories'][ $category ] = 0;
			}

			$summary['categories'][ $category ]++;

			if ( empty( $surfaces[ $category ] ) ) {
				$surfaces[ $category ] = [
					'read_actions'                  => [],
					'write_actions'                 => [],
					'confirmation_required_actions' => [],
				];
			}

			$target = $is_write ? 'write_actions' : 'read_actions';
			$surfaces[ $category ][ $target ][] = $name;

			if ( $requires_confirmation ) {
				$surfaces[ $category ]['confirmation_required_actions'][] = $name;
			}
		}

		ksort( $summary['categories'] );
		ksort( $surfaces );

		return [
			'summary'             => $summary,
			'surfaces'            => $surfaces,
			'action_source'       => 'yoro_action_registry',
			'execution_boundary'  => 'plugin_executor',
			'routing_boundary'    => 'laravel_conversation_brain',
			'confirmation_policy' => 'write_actions_require_confirmation',
		];
	}

	private static function catalog_profile() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'available' => false,
			];
		}

		$products = self::product_sample();
		$orders   = self::recent_orders_sample( '-90 days', self::profile_order_sample_limit(), self::revenue_statuses() );
		$mix      = self::product_mix_from_products( $products );
		$demand   = self::product_demand_from_orders( $orders );
		$product_counts = [
			'total'       => self::count_products( [] ),
			'publish'     => self::count_products( [ 'status' => 'publish' ] ),
			'out_of_stock' => self::count_products( [ 'stock_status' => 'outofstock' ] ),
			'on_backorder' => self::count_products( [ 'stock_status' => 'onbackorder' ] ),
		];

		return [
			'available'             => true,
			'product_counts'        => $product_counts,
			'product_count_labels'  => [
				'total'       => __( 'Total', 'yoro-ai-core' ),
				'publish'     => YoRo_Product_Helper::get_product_status_label( 'publish' ),
				'out_of_stock' => YoRo_Product_Helper::get_stock_status_label( 'outofstock' ),
				'on_backorder' => YoRo_Product_Helper::get_stock_status_label( 'onbackorder' ),
			],
			'product_type_counts'   => $mix['product_type_counts'],
			'product_type_count_labels' => YoRo_Product_Helper::map_product_type_count_labels( $mix['product_type_counts'] ),
			'product_mix'           => $mix['product_mix'],
			'top_categories_90d'    => $demand['top_categories'],
			'top_products_90d'      => $demand['top_products'],
			'primary_product_groups' => array_values( array_slice( array_column( $demand['top_categories'], 'name' ), 0, 5 ) ),
			'sample'                => [
				'product_sample_limit' => count( $products ),
				'order_sample_limit'   => count( $orders ),
				'window'               => 'last_90_days',
			],
		];
	}

	private static function commerce_profile() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'available' => false,
			];
		}

		$products = self::product_sample();
		$orders   = self::recent_orders_sample( '-90 days', self::profile_order_sample_limit(), self::revenue_statuses() );
		$mix      = self::product_mix_from_products( $products );
		$order_mix = self::order_mix_from_orders( $orders );
		$physical_ratio = (float) ( $order_mix['shipping_required_order_ratio'] ?? $mix['product_mix']['physical_product_ratio'] ?? 0 );
		$digital_ratio  = (float) ( $mix['product_mix']['digital_product_ratio'] ?? 0 );
		$subscription_ratio = (float) ( $mix['product_mix']['subscription_product_ratio'] ?? 0 );
		$fulfillment_model = 'unknown';
		$confidence = 0.2;

		if ( $subscription_ratio > 0.05 || $mix['subscription_signal'] ) {
			$fulfillment_model = 'subscription_possible';
			$confidence = 0.7;
		} elseif ( $physical_ratio >= 0.75 ) {
			$fulfillment_model = 'physical_goods';
			$confidence = 0.85;
		} elseif ( $digital_ratio >= 0.75 && $physical_ratio < 0.25 ) {
			$fulfillment_model = 'digital_goods';
			$confidence = 0.8;
		} elseif ( $physical_ratio > 0 || $digital_ratio > 0 ) {
			$fulfillment_model = 'mixed_goods';
			$confidence = 0.6;
		}

		return [
			'available'                     => true,
			'fulfillment_model_estimate'    => $fulfillment_model,
			'fulfillment_model_confidence'  => self::round_ratio( $confidence ),
			'physical_order_ratio'          => self::round_ratio( $physical_ratio ),
			'shipping_required_order_ratio' => self::round_ratio( (float) ( $order_mix['shipping_required_order_ratio'] ?? 0 ) ),
			'digital_product_ratio'         => self::round_ratio( $digital_ratio ),
			'subscription_product_ratio'    => self::round_ratio( $subscription_ratio ),
			'subscription_signal'           => (bool) $mix['subscription_signal'],
			'source'                        => 'woocommerce_products_and_orders_90d',
		];
	}

	private static function sales_baselines_profile() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'available' => false,
			];
		}

		return [
			'available' => true,
			'currency'  => function_exists( 'get_woocommerce_currency' ) ? sanitize_text_field( get_woocommerce_currency() ) : '',
			'windows'   => [
				'last_7_days'  => self::sales_baseline_for_days( 7 ),
				'last_30_days' => self::sales_baseline_for_days( 30 ),
				'last_90_days' => self::sales_baseline_for_days( 90 ),
			],
			'source'    => 'woocommerce_orders_sampled_by_revenue_status',
		];
	}

	private static function customer_profile() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'available' => false,
			];
		}

		$orders = self::recent_orders_sample( '-90 days', self::profile_order_sample_limit(), self::order_status_keys() );
		$total = count( $orders );
		$registered_orders = 0;
		$billing_company_orders = 0;
		$item_counts = [];
		$customer_order_counts = [];
		$countries = [];

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) ) {
				continue;
			}

			$customer_id = method_exists( $order, 'get_customer_id' ) ? absint( $order->get_customer_id() ) : 0;

			if ( $customer_id > 0 ) {
				$registered_orders++;
				$customer_order_counts[ $customer_id ] = absint( $customer_order_counts[ $customer_id ] ?? 0 ) + 1;
			}

			if ( method_exists( $order, 'get_billing_company' ) && '' !== trim( (string) $order->get_billing_company() ) ) {
				$billing_company_orders++;
			}

			$item_counts[] = self::order_item_quantity( $order );

			if ( method_exists( $order, 'get_billing_country' ) ) {
				$country = sanitize_text_field( $order->get_billing_country() );

				if ( '' !== $country ) {
					$countries[ $country ] = absint( $countries[ $country ] ?? 0 ) + 1;
				}
			}
		}

		arsort( $countries );
		$repeat_customers = count( array_filter( $customer_order_counts, static fn( $count ) => absint( $count ) > 1 ) );
		$registered_customers = count( $customer_order_counts );
		$billing_company_rate = $total > 0 ? $billing_company_orders / $total : 0;
		$median_items = self::median_number( $item_counts );
		$model = 'unknown';
		$confidence = 0.25;

		if ( $billing_company_rate >= 0.3 || $median_items >= 8 ) {
			$model = 'likely_b2b';
			$confidence = min( 0.9, 0.55 + $billing_company_rate );
		} elseif ( $total >= 10 ) {
			$model = 'likely_b2c';
			$confidence = max( 0.55, 1 - $billing_company_rate );
		}

		return [
			'available'                       => true,
			'window'                          => 'last_90_days',
			'orders_sampled'                  => $total,
			'customer_model_estimate'          => $model,
			'customer_model_confidence'        => self::round_ratio( $confidence ),
			'guest_order_ratio'                => $total > 0 ? self::round_ratio( ( $total - $registered_orders ) / $total ) : 0,
			'billing_company_order_ratio'      => self::round_ratio( $billing_company_rate ),
			'median_items_per_order'           => self::round_decimal( $median_items ),
			'repeat_registered_customer_ratio' => $registered_customers > 0 ? self::round_ratio( $repeat_customers / $registered_customers ) : 0,
			'top_billing_countries'            => self::top_count_map( $countries, 8 ),
			'source'                           => 'woocommerce_order_sample_without_customer_pii',
		];
	}

	private static function operations_profile() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'available' => false,
			];
		}

		return [
			'available'    => true,
			'age_buckets'  => [
				'pending_older_24h'    => self::count_orders( [ 'status' => [ 'pending' ], 'date_created' => '<=' . self::relative_cutoff( '-24 hours' ) ] ),
				'pending_older_48h'    => self::count_orders( [ 'status' => [ 'pending' ], 'date_created' => '<=' . self::relative_cutoff( '-48 hours' ) ] ),
				'processing_older_24h' => self::count_orders( [ 'status' => [ 'processing' ], 'date_created' => '<=' . self::relative_cutoff( '-24 hours' ) ] ),
				'processing_older_48h' => self::count_orders( [ 'status' => [ 'processing' ], 'date_created' => '<=' . self::relative_cutoff( '-48 hours' ) ] ),
				'processing_older_72h' => self::count_orders( [ 'status' => [ 'processing' ], 'date_created' => '<=' . self::relative_cutoff( '-72 hours' ) ] ),
				'on_hold_older_48h'    => self::count_orders( [ 'status' => [ 'on-hold' ], 'date_created' => '<=' . self::relative_cutoff( '-48 hours' ) ] ),
			],
			'default_thresholds' => [
				'attention_order_age_hours'        => 48,
				'fulfillment_processing_age_hours' => 24,
				'stale_customer_followup_hours'    => 48,
			],
			'source'       => 'woocommerce_order_age_counts',
		];
	}

	private static function inventory_profile() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'available' => false,
			];
		}

		$products = self::product_sample();
		$low_stock_threshold = absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
		$low_stock_count = 0;
		$risk_products = [];

		foreach ( $products as $product ) {
			if ( ! is_object( $product ) ) {
				continue;
			}

			$stock_status = method_exists( $product, 'get_stock_status' ) ? sanitize_key( $product->get_stock_status() ) : '';
			$stock_quantity = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
			$is_low_stock = is_numeric( $stock_quantity )
				&& (float) $stock_quantity > 0
				&& (float) $stock_quantity <= $low_stock_threshold;

			if ( $is_low_stock ) {
				$low_stock_count++;
			}

			if ( ! in_array( $stock_status, [ 'outofstock', 'onbackorder' ], true ) && ! $is_low_stock ) {
				continue;
			}

			$risk_products[] = [
				'id'             => method_exists( $product, 'get_id' ) ? absint( $product->get_id() ) : 0,
				'name'           => sanitize_text_field( method_exists( $product, 'get_name' ) ? $product->get_name() : '' ),
				'stock_status'   => $stock_status,
				'stock_status_label' => YoRo_Product_Helper::get_stock_status_label( $stock_status ),
				'stock_quantity' => is_numeric( $stock_quantity ) ? (float) $stock_quantity : null,
			];
		}

		$inventory_counts = [
			'out_of_stock' => self::count_products( [ 'stock_status' => 'outofstock' ] ),
			'on_backorder' => self::count_products( [ 'stock_status' => 'onbackorder' ] ),
			'low_stock_in_sample' => $low_stock_count,
		];

		return [
			'available'           => true,
			'stock_management_enabled' => 'yes' === get_option( 'woocommerce_manage_stock', 'no' ),
			'low_stock_threshold' => $low_stock_threshold,
			'counts'              => $inventory_counts,
			'count_labels'        => [
				'out_of_stock' => YoRo_Product_Helper::get_stock_status_label( 'outofstock' ),
				'on_backorder' => YoRo_Product_Helper::get_stock_status_label( 'onbackorder' ),
				'low_stock_in_sample' => __( 'Low stock in sample', 'yoro-ai-core' ),
			],
			'top_risk_products'   => array_slice( $risk_products, 0, 8 ),
			'sample'              => [
				'product_sample_limit' => count( $products ),
			],
			'source'              => 'woocommerce_product_stock_sample',
		];
	}

	private static function live_snapshot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'available'    => false,
				'generated_at' => YoRo_Date_Helper::format_mysql( current_time( 'mysql' ) ),
			];
		}

		$status_counts = self::order_status_counts();
		$open_total    = 0;

		foreach ( [ 'pending', 'processing', 'on-hold' ] as $status ) {
			$open_total += absint( $status_counts[ $status ] ?? 0 );
		}

		$inventory_counts = [
			'out_of_stock' => self::count_products( [ 'stock_status' => 'outofstock' ] ),
			'on_backorder' => self::count_products( [ 'stock_status' => 'onbackorder' ] ),
		];

		return [
			'available'           => true,
			'generated_at'        => YoRo_Date_Helper::format_mysql( current_time( 'mysql' ) ),
			'order_status_counts' => $status_counts,
			'order_status_count_labels' => self::order_status_count_labels( $status_counts ),
			'open_order_count'    => $open_total,
			'attention_older_48h' => self::count_orders(
				[
					'status'       => [ 'pending', 'processing', 'on-hold' ],
					'date_created' => '<=' . self::relative_cutoff( '-48 hours' ),
				]
			),
			'inventory_counts'    => $inventory_counts,
			'inventory_count_labels' => [
				'out_of_stock' => YoRo_Product_Helper::get_stock_status_label( 'outofstock' ),
				'on_backorder' => YoRo_Product_Helper::get_stock_status_label( 'onbackorder' ),
			],
			'freshness'           => [
				'cache_ttl_seconds' => max( 30, absint( apply_filters( 'yoro_ai_store_knowledge_ttl', 180, 'conversation' ) ) ),
				'counts_are_snapshot' => true,
			],
		];
	}

	private static function safety_contract() {
		return [
			'data_policy' => [
				'prefer_live_action_results_for_exact_counts' => true,
				'store_knowledge_is_context_not_execution_result' => true,
				'do_not_expose_private_customer_data_from_knowledge' => true,
			],
			'action_policy' => [
				'read_actions_may_execute_directly' => true,
				'write_actions_require_confirmation' => true,
				'plugin_enforces_permissions' => true,
			],
		];
	}

	private static function source_contract() {
		return [
			'identity'       => 'wordpress_settings',
			'locale'         => 'wordpress_and_woocommerce_settings',
			'commerce_model' => 'woocommerce_settings',
			'order_model'    => 'woocommerce_order_statuses',
			'settings'       => 'woocommerce_settings',
			'integrations'   => 'wordpress_plugin_inventory_and_yoro_adapters',
			'profiles'       => 'woocommerce_product_order_customer_samples',
			'capabilities'   => 'yoro_action_registry',
			'live_snapshot'  => 'woocommerce_read_queries',
		];
	}

	private static function order_status_counts() {
		$counts = [];

		foreach ( self::order_status_keys() as $status ) {
			$counts[ $status ] = self::count_orders( [ 'status' => [ $status ] ] );
		}

		return $counts;
	}

	private static function order_status_count_labels( $counts ) {
		$labels = [];

		foreach ( is_array( $counts ) ? $counts : [] as $status => $count ) {
			$labels[ $status ] = YoRo_Order_Helper::get_order_status_label( $status );
		}

		return $labels;
	}

	private static function order_status_keys() {
		$keys = [];

		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
				$key = preg_replace( '/^wc-/', '', sanitize_key( $status ) );

				if ( '' !== $key ) {
					$keys[] = $key;
				}
			}
		}

		if ( [] === $keys ) {
			$keys = [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];
		}

		return array_values( array_unique( $keys ) );
	}

	private static function count_orders( $args ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		if ( class_exists( 'YoRo_Orders_Read_Service' ) ) {
			return absint( YoRo_Orders_Read_Service::briefing_count_orders( $args ) );
		}

		$args = array_merge(
			is_array( $args ) ? $args : [],
			[
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			]
		);
		$result = wc_get_orders( $args );

		return is_object( $result ) && isset( $result->total ) ? absint( $result->total ) : 0;
	}

	private static function count_products( $args ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}

		$args = array_merge(
			is_array( $args ) ? $args : [],
			[
				'limit'    => 1,
				'status'   => 'publish',
				'paginate' => true,
				'return'   => 'ids',
			]
		);
		$result = wc_get_products( $args );

		return is_object( $result ) && isset( $result->total ) ? absint( $result->total ) : 0;
	}

	private static function product_sample() {
		static $sample = null;

		if ( null !== $sample ) {
			return $sample;
		}

		if ( ! function_exists( 'wc_get_products' ) ) {
			$sample = [];

			return $sample;
		}

		$limit = max( 20, absint( apply_filters( 'yoro_ai_store_profile_product_sample_limit', 120 ) ) );
		$sample = wc_get_products(
			[
				'limit'   => $limit,
				'status'  => 'publish',
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
			]
		);

		return is_array( $sample ) ? $sample : [];
	}

	private static function recent_orders_sample( $modifier, $limit, $statuses ) {
		static $cache = [];

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$statuses = array_values( array_filter( array_map( 'sanitize_key', is_array( $statuses ) ? $statuses : [] ) ) );
		$key      = md5( (string) $modifier . '|' . absint( $limit ) . '|' . implode( ',', $statuses ) );

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$orders = wc_get_orders(
			[
				'limit'        => max( 20, absint( $limit ) ),
				'status'       => [] !== $statuses ? $statuses : self::order_status_keys(),
				'date_created' => '>=' . self::relative_cutoff( $modifier ),
				'orderby'      => 'date',
				'order'        => 'DESC',
				'return'       => 'objects',
			]
		);

		$cache[ $key ] = is_array( $orders ) ? $orders : [];

		return $cache[ $key ];
	}

	private static function profile_order_sample_limit() {
		return max( 50, absint( apply_filters( 'yoro_ai_store_profile_order_sample_limit', 250 ) ) );
	}

	private static function revenue_statuses() {
		if ( class_exists( 'YoRo_Orders_Read_Service' ) ) {
			$statuses = YoRo_Orders_Read_Service::briefing_get_revenue_order_status_slugs();

			if ( is_array( $statuses ) && [] !== $statuses ) {
				return array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );
			}
		}

		return [ 'processing', 'completed' ];
	}

	private static function product_mix_from_products( $products ) {
		$type_counts = [];
		$total       = 0;
		$physical    = 0;
		$digital     = 0;
		$subscription = 0;
		$subscription_signal = class_exists( 'WC_Subscriptions' );

		foreach ( is_array( $products ) ? $products : [] as $product ) {
			if ( ! is_object( $product ) ) {
				continue;
			}

			$total++;
			$type = sanitize_key( method_exists( $product, 'get_type' ) ? $product->get_type() : 'unknown' );

			if ( '' === $type ) {
				$type = 'unknown';
			}

			$type_counts[ $type ] = absint( $type_counts[ $type ] ?? 0 ) + 1;

			$is_virtual      = method_exists( $product, 'is_virtual' ) && $product->is_virtual();
			$is_downloadable = method_exists( $product, 'is_downloadable' ) && $product->is_downloadable();
			$needs_shipping  = method_exists( $product, 'needs_shipping' ) && $product->needs_shipping();

			if ( $needs_shipping ) {
				$physical++;
			}

			if ( $is_virtual || $is_downloadable ) {
				$digital++;
			}

			if ( false !== strpos( $type, 'subscription' ) ) {
				$subscription++;
				$subscription_signal = true;
			}
		}

		ksort( $type_counts );

		return [
			'product_type_counts' => $type_counts,
			'product_mix'         => [
				'physical_product_ratio'     => $total > 0 ? self::round_ratio( $physical / $total ) : 0,
				'digital_product_ratio'      => $total > 0 ? self::round_ratio( $digital / $total ) : 0,
				'subscription_product_ratio' => $total > 0 ? self::round_ratio( $subscription / $total ) : 0,
			],
			'subscription_signal' => $subscription_signal,
		];
	}

	private static function product_demand_from_orders( $orders ) {
		$products   = [];
		$categories = [];

		foreach ( is_array( $orders ) ? $orders : [] as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( ! is_object( $item ) ) {
					continue;
				}

				$product_id = method_exists( $item, 'get_product_id' ) ? absint( $item->get_product_id() ) : 0;
				$name       = sanitize_text_field( method_exists( $item, 'get_name' ) ? $item->get_name() : '' );
				$quantity   = method_exists( $item, 'get_quantity' ) ? absint( $item->get_quantity() ) : 0;
				$total      = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;

				if ( $product_id <= 0 && '' === $name ) {
					continue;
				}

				$product_key = $product_id > 0 ? (string) $product_id : $name;

				if ( empty( $products[ $product_key ] ) ) {
					$products[ $product_key ] = [
						'id'       => $product_id,
						'name'     => $name,
						'quantity' => 0,
						'gross'    => 0.0,
					];
				}

				$products[ $product_key ]['quantity'] += $quantity;
				$products[ $product_key ]['gross']    += $total;

				if ( $product_id <= 0 ) {
					continue;
				}

				$terms = get_the_terms( $product_id, 'product_cat' );

				if ( ! is_array( $terms ) ) {
					continue;
				}

				foreach ( $terms as $term ) {
					if ( ! is_object( $term ) || empty( $term->name ) ) {
						continue;
					}

					$category_key = sanitize_key( $term->slug ?? $term->name );

					if ( '' === $category_key ) {
						continue;
					}

					if ( empty( $categories[ $category_key ] ) ) {
						$categories[ $category_key ] = [
							'slug'     => $category_key,
							'name'     => sanitize_text_field( $term->name ),
							'quantity' => 0,
							'gross'    => 0.0,
						];
					}

					$categories[ $category_key ]['quantity'] += $quantity;
					$categories[ $category_key ]['gross']    += $total;
				}
			}
		}

		$sort_by_gross = static function ( $left, $right ) {
			return ( $right['gross'] <=> $left['gross'] ) ?: ( $right['quantity'] <=> $left['quantity'] );
		};

		usort( $products, $sort_by_gross );
		usort( $categories, $sort_by_gross );

		return [
			'top_products'   => array_map( [ __CLASS__, 'compact_demand_item' ], array_slice( $products, 0, 8 ) ),
			'top_categories' => array_map( [ __CLASS__, 'compact_demand_item' ], array_slice( $categories, 0, 8 ) ),
		];
	}

	private static function compact_demand_item( $item ) {
		$item = is_array( $item ) ? $item : [];

		return array_filter(
			[
				'id'       => absint( $item['id'] ?? 0 ),
				'slug'     => sanitize_key( $item['slug'] ?? '' ),
				'name'     => sanitize_text_field( $item['name'] ?? '' ),
				'quantity' => absint( $item['quantity'] ?? 0 ),
				'gross'    => self::round_decimal( (float) ( $item['gross'] ?? 0 ) ),
			],
			static fn( $value ) => null !== $value && '' !== $value && [] !== $value && 0 !== $value
		);
	}

	private static function order_mix_from_orders( $orders ) {
		$total = 0;
		$shipping_required = 0;

		foreach ( is_array( $orders ) ? $orders : [] as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
				continue;
			}

			$total++;

			foreach ( $order->get_items() as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
					continue;
				}

				$product = $item->get_product();

				if ( is_object( $product ) && method_exists( $product, 'needs_shipping' ) && $product->needs_shipping() ) {
					$shipping_required++;
					break;
				}
			}
		}

		return [
			'shipping_required_order_ratio' => $total > 0 ? self::round_ratio( $shipping_required / $total ) : 0,
		];
	}

	private static function sales_baseline_for_days( $days ) {
		$days = max( 1, absint( $days ) );
		$orders = self::recent_orders_sample( '-' . $days . ' days', self::profile_order_sample_limit(), self::revenue_statuses() );
		$order_count = count( $orders );
		$gross = 0.0;
		$refunded = 0.0;
		$discounts = 0.0;
		$shipping = 0.0;
		$tax = 0.0;
		$item_quantities = [];

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) ) {
				continue;
			}

			$gross     += method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
			$refunded  += method_exists( $order, 'get_total_refunded' ) ? (float) $order->get_total_refunded() : 0.0;
			$discounts += method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
			$shipping  += method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0;
			$tax       += method_exists( $order, 'get_total_tax' ) ? (float) $order->get_total_tax() : 0.0;
			$item_quantities[] = self::order_item_quantity( $order );
		}

		return [
			'days'             => $days,
			'orders'           => $order_count,
			'gross_sales'      => self::round_decimal( $gross ),
			'net_sales'        => self::round_decimal( max( 0, $gross - $refunded ) ),
			'average_order_value' => $order_count > 0 ? self::round_decimal( $gross / $order_count ) : 0,
			'orders_per_day'   => self::round_decimal( $order_count / $days ),
			'gross_sales_per_day' => self::round_decimal( $gross / $days ),
			'refund_rate'      => $gross > 0 ? self::round_ratio( $refunded / $gross ) : 0,
			'discount_rate'    => $gross > 0 ? self::round_ratio( $discounts / $gross ) : 0,
			'shipping_total'   => self::round_decimal( $shipping ),
			'tax_total'        => self::round_decimal( $tax ),
			'median_items_per_order' => self::round_decimal( self::median_number( $item_quantities ) ),
			'sample_limited'   => $order_count >= self::profile_order_sample_limit(),
		];
	}

	private static function order_item_quantity( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return 0;
		}

		$quantity = 0;

		foreach ( $order->get_items() as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_quantity' ) ) {
				$quantity += absint( $item->get_quantity() );
			}
		}

		return $quantity;
	}

	private static function median_number( $values ) {
		$values = array_values( array_filter( array_map( 'floatval', is_array( $values ) ? $values : [] ), static fn( $value ) => $value >= 0 ) );

		if ( [] === $values ) {
			return 0;
		}

		sort( $values, SORT_NUMERIC );
		$count = count( $values );
		$mid   = intdiv( $count, 2 );

		return 1 === $count % 2
			? (float) $values[ $mid ]
			: ( (float) $values[ $mid - 1 ] + (float) $values[ $mid ] ) / 2;
	}

	private static function top_count_map( $map, $limit ) {
		arsort( $map );
		$output = [];

		foreach ( array_slice( $map, 0, absint( $limit ), true ) as $key => $count ) {
			$output[] = [
				'key'   => sanitize_text_field( (string) $key ),
				'count' => absint( $count ),
			];
		}

		return $output;
	}

	private static function round_ratio( $value ) {
		return round( max( 0, min( 1, (float) $value ) ), 3 );
	}

	private static function round_decimal( $value ) {
		return round( (float) $value, 2 );
	}

	private static function relative_cutoff( $modifier ) {
		if ( class_exists( 'YoRo_Orders_Read_Service' ) ) {
			return YoRo_Orders_Read_Service::briefing_build_relative_cutoff( $modifier );
		}

		return gmdate( 'Y-m-d H:i:s', strtotime( $modifier ) );
	}

	private static function is_hpos_enabled() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
