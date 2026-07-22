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
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-action-definition-factory.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-action-registry.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-action-catalog-builder.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-order-presenter.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-report-builder.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-operations-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-customer-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-customer-lifecycle-action-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-fulfillment-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-fulfillment-write-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-fulfillment-lifecycle-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-inventory-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-catalog-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-briefing-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-read-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-write-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-order-refund-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-refund-intelligence-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-commerce-intelligence-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-finance-reconciliation-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-order-return-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-coupons-service.php';
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
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-action-executor.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-plan-executor.php';
	}

	private static function include_commerce() {
		require_once YORO_AI_CORE_PATH . 'includes/commerce/class-yoro-commerce-contract-builder.php';
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

		YoRo_Orders_Actions::register();

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

		$content = '<p>' . esc_html__( 'YoRo AI Core connects this site to the configured YoRo AI API service when an authorized store manager sends a chat request, tests the connection, connects a mailbox, syncs mailbox data, drafts an email, or approves an action that reports execution status. The plugin sends the site URL, plugin version, language settings, WooCommerce store context, requested order/product/customer data needed for the request, mailbox connection details entered by an administrator, chat instructions, approved action metadata, and execution results to the configured service. Customer email addresses, names, order details, message snippets, and email body text may be included when they are needed to answer the request or draft a reply.', 'yoro-ai-core' ) . '</p>';
		$content .= '<p>' . esc_html__( 'YoRo AI Core stores service credentials, connection status, local action history, safety settings, email templates, and draft metadata in this WordPress database. The plugin does not contact the YoRo AI API until an administrator configures the service URL and site key or starts the account connection flow.', 'yoro-ai-core' ) . '</p>';

		wp_add_privacy_policy_content( __( 'YoRo AI Core', 'yoro-ai-core' ), wp_kses_post( $content ) );
	}
}
