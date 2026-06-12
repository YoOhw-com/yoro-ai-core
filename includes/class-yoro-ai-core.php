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
		self::include_rest();

		if ( is_admin() ) {
			self::include_admin();
		}
	}

	private static function include_core() {
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
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-action-registry.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-action-catalog-builder.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-order-presenter.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-report-builder.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-operations-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-customer-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-fulfillment-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-inventory-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-briefing-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-read-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-write-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-coupons-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-email-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-actions.php';
	}

	private static function include_execution() {
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-plan-semantic-validator.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-action-planner.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-policy-engine.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-plan-risk-engine.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-runtime-guard.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-action-executor.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-plan-executor.php';
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

		YoRo_Action_Registry::init();

		YoRo_Orders_Actions::register();

		do_action( 'yoro_register_actions' );

		YoRo_REST_API::init();

		if ( is_admin() ) {
			YoRo_Admin_Page::init();
		}
	}
}
