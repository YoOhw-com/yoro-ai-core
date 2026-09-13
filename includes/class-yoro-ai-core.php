<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Core {

	public static function init() {
		self::includes();
		self::boot();
	}

	private static function includes() {
		self::include_core();
		self::include_ai();
		self::include_actions();
		self::include_execution();
		self::include_commerce();
		self::include_rest();

		if ( is_admin() ) {
			self::include_admin();
		}
	}

	private static function include_core() {
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-capabilities.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-wordpress-actor.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-action-cache.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-logger.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-db.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-rate-limiter.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-date-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-response-ux-formatter.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-product-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-order-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-integration-registry.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-store-knowledge-provider.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-context-provider.php';
	}

	private static function include_ai() {
		self::include_ai_cloud_boundary();
		self::include_ai_normalizers();
		self::include_ai_cloud_entrypoints();
	}

	private static function include_ai_cloud_boundary() {
		require_once YORO_AI_CORE_PATH . 'includes/ai/interfaces/interface-yoro-ai-client.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-settings.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-request-tracker.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-provider-resolver.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/clients/class-yoro-ai-api-endpoint-resolver.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/clients/class-yoro-ai-api-connection-client.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/clients/class-yoro-ai-api-client.php';
	}

	private static function include_ai_normalizers() {
		require_once YORO_AI_CORE_PATH . 'includes/ai/normalizers/class-yoro-ai-email-result-normalizer.php';
	}

	private static function include_ai_cloud_entrypoints() {
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-order-email-context-builder.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-email-composer.php';
	}

	private static function include_actions() {
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-action-registry.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-action-catalog-builder.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-wordpress-site-actions.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-wordpress-admin-actions.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-wordpress-content-actions.php';

		if ( ! yoro_ai_core_has_woocommerce() ) {
			return;
		}

		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-order-presenter.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-report-builder.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-operations-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-customer-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-fulfillment-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-inventory-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-commerce-discovery-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-briefing-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-read-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-write-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-coupons-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-fulfillment-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-email-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-actions.php';
	}

	private static function include_execution() {
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-safety-contract.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-plan-semantic-validator.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-action-planner.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-policy-engine.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-plan-risk-engine.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-runtime-guard.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/interface-yoro-action-execution-provider.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-local-action-execution-provider.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-native-ability-execution-provider.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-action-execution-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-action-executor.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-plan-executor.php';
	}

	private static function include_commerce() {
		require_once YORO_AI_CORE_PATH . 'includes/commerce/class-yoro-commerce-contract-builder.php';
		require_once YORO_AI_CORE_PATH . 'includes/commerce/class-yoro-commerce-contract-validator.php';
		require_once YORO_AI_CORE_PATH . 'includes/commerce/interface-yoro-connector-media-client.php';
		require_once YORO_AI_CORE_PATH . 'includes/commerce/class-yoro-connector-media-client.php';
		require_once YORO_AI_CORE_PATH . 'includes/commerce/class-yoro-connector-client.php';
		require_once YORO_AI_CORE_PATH . 'includes/commerce/class-yoro-connector-snapshot-publisher.php';
		require_once YORO_AI_CORE_PATH . 'includes/commerce/class-yoro-connector-execution-journal.php';
		require_once YORO_AI_CORE_PATH . 'includes/commerce/class-yoro-connector-runtime.php';
	}

	private static function include_rest() {
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-response-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-conversation-action-coordinator.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-chat-message-handler.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-runtime-log.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-execute-log-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-execute-handler.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-undo-handler.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-api.php';
	}

	private static function include_admin() {
		require_once YORO_AI_CORE_PATH . 'includes/admin/class-yoro-admin-page.php';
	}

	private static function boot() {
		YoRo_DB::maybe_upgrade();
		YoRo_Safety_Contract::maybe_migrate_legacy_profile();
		YoRo_Capabilities::maybe_install();

		YoRo_Action_Registry::init();

		if ( yoro_ai_core_has_woocommerce() ) {
			YoRo_Orders_Actions::register();
		}

		YoRo_WordPress_Site_Actions::register();
		YoRo_WordPress_Admin_Actions::register();
		YoRo_WordPress_Content_Actions::register();

		do_action( 'yoro_register_actions' );

		YoRo_Connector_Runtime::init();

		YoRo_REST_API::init();

		if ( is_admin() ) {
			YoRo_Admin_Page::init();
			add_action( 'admin_init', [ __CLASS__, 'add_privacy_policy_content' ] );
		}
	}

	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'YoRo Connector for WordPress connects this site to the configured YoRo service when an authorized administrator starts the connection flow or asks an approved AI client to read or manage site content. Depending on the granted capabilities, the plugin may send WordPress site information, update status, content, taxonomies, comments, media metadata, installed plugin and theme metadata, the acting WordPress user permissions, approved action parameters, and execution results. WooCommerce data is included only when WooCommerce is active and the relevant store capability is used.', 'yoro-ai-core' ) . '</p>';
		$content .= '<p>' . esc_html__( 'The connector stores encrypted service credentials, connection and module status, local action history, safety settings, and idempotency records in this WordPress database. Write actions require an authorized WordPress actor and explicit confirmation; high-impact administration actions also require an enabled administration policy and a recent backup attestation. The plugin does not expose arbitrary PHP, SQL, WP-CLI, file editing, or unrestricted WordPress option writes.', 'yoro-ai-core' ) . '</p>';

		wp_add_privacy_policy_content( __( 'YoRo Connector for WordPress', 'yoro-ai-core' ), wp_kses_post( $content ) );
	}
}
